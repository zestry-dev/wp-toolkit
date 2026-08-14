<?php

/**
 * Transients API: Transients module
 */

declare( strict_types=1 );

namespace Zestry\WPToolkit\Modules;

// Loaded by WordPress, never requested directly.
\defined( 'ABSPATH' ) || exit;

use Zestry\WPToolkit\Kernel\Abstracts\Module;

/**
 * Reads and writes transients, under keys namespaced to your plugin.
 *
 * WordPress decides where a transient actually lives: an external object cache
 * when the site has one, the options table when it does not. You get the same
 * API either way.
 *
 * Keys are prefixed with your plugin slug, which matters more here than it
 * looks — every plugin's transients share one namespace, so an unprefixed
 * `config` is a collision waiting to happen.
 *
 * Values round-trip exactly as you stored them, `false` and `null` included, so
 * storing "we asked and there was nothing" works — {@see has()} is what tells
 * that apart from never having asked.
 *
 * **Treat every value as optional.** A transient can disappear before its
 * expiry: an object cache evicts under memory pressure, and a deploy may clear
 * it entirely. Anything you cannot recompute belongs in {@see Options}, not
 * here. Anything that only needs to survive the current request is cheaper in
 * {@see Globals}.
 *
 * @example Storing something expensive to work out
 * ```
 * public Transients $transients;
 *
 * public function get_summary(): array {
 *     if ( ! $this->with( Transients::class )->has( 'summary' ) ) {
 *         $this->with( Transients::class )->set( 'summary', $this->build_summary(), HOUR_IN_SECONDS );
 *     }
 *
 *     return $this->with( Transients::class )->get( 'summary' );
 * }
 * ```
 *
 * @example Reading and writing directly
 * ```
 * $this->with( Transients::class )->set( 'rates', $rates, 15 * MINUTE_IN_SECONDS );
 *
 * $rates = $this->with( Transients::class )->get( 'rates', array() );
 *
 * $this->with( Transients::class )->delete( 'rates' );
 * ```
 */
class Transients extends Module {

	/**
	 * The longest a key may be once prefixed.
	 *
	 * Without an object cache a transient is two options, `_transient_{key}` and
	 * `_transient_timeout_{key}`, and `option_name` is `varchar(191)`. The
	 * longer prefix is 19 characters, so 172 is what remains.
	 */
	public const MAX_KEY_LENGTH = 172;

	/**
	 * The key every value is wrapped under before storing.
	 *
	 * WordPress returns `false` from `get_transient()` for a key that is not
	 * there, so a stored `false` would be indistinguishable from a miss — and,
	 * worse, indistinguishable *differently* on different sites: an object cache
	 * returns the `false` you stored, while the options table turns it into
	 * `''`. Wrapping means the presence of this key is the answer, and the value
	 * beneath it is whatever you put there.
	 *
	 * @internal Storage detail. Read values through {@see get()}, not by calling
	 * `get_transient()` yourself.
	 */
	protected const VALUE_KEY = 'v';

	/**
	 * Read a stored value.
	 *
	 * @param string $key     Your own key, unprefixed.
	 * @param mixed  $fallback Returned when nothing is stored. Defaults to null.
	 * @return mixed The stored value, or `$fallback`.
	 * @throws \InvalidArgumentException When the key is empty or too long.
	 */
	public function get( string $key, mixed $fallback = null ): mixed {
		$stored = \get_transient( $this->get_prefixed_key( $key ) );

		return $this->is_stored( $stored ) ? $stored[ self::VALUE_KEY ] : $fallback;
	}

	/**
	 * Store a value.
	 *
	 * Any serializable value, `false` and `null` included.
	 *
	 * @param string $key   Your own key, unprefixed.
	 * @param mixed  $value Anything serializable.
	 * @param int    $ttl   Seconds to keep it. 0 means no expiry, which still does not make it permanent.
	 * @return void
	 * @throws \InvalidArgumentException When the key is empty or too long.
	 */
	public function set( string $key, mixed $value, int $ttl = 0 ): void {
		\set_transient(
			$this->get_prefixed_key( $key ),
			array( self::VALUE_KEY => $value ),
			$ttl
		);
	}

	/**
	 * Whether a value is stored under this key.
	 *
	 * Distinct from `null !== get()`, which cannot tell a stored `null` from a
	 * missing key.
	 *
	 * @param string $key Your own key, unprefixed.
	 * @return bool True when something is stored, whatever its value.
	 * @throws \InvalidArgumentException When the key is empty or too long.
	 */
	public function has( string $key ): bool {
		return $this->is_stored( \get_transient( $this->get_prefixed_key( $key ) ) );
	}

	/**
	 * Delete a stored value.
	 *
	 * Deleting something that was never there is not an error; it just returns
	 * false.
	 *
	 * There is deliberately no "delete everything" companion. Every plugin's
	 * transients share one object-cache group, and that cache offers no way to
	 * list keys — so such a method could only either miss everything on a site
	 * with an object cache, or delete other plugins' entries along with yours.
	 * Track the keys you need to clear, or give them a short enough `$ttl` that
	 * clearing is unnecessary.
	 *
	 * @param string $key Your own key, unprefixed.
	 * @return void
	 * @throws \InvalidArgumentException When the key is empty or too long.
	 */
	public function delete( string $key ): void {
		\delete_transient( $this->get_prefixed_key( $key ) );
	}

	/**
	 * Whether what came back from WordPress is one of ours.
	 *
	 * @param mixed $stored Whatever `get_transient()` returned.
	 * @return bool True when it is a stored value rather than a miss.
	 */
	protected function is_stored( mixed $stored ): bool {
		return \is_array( $stored ) && \array_key_exists( self::VALUE_KEY, $stored );
	}

	/**
	 * Your key with the plugin's prefix, checked for length.
	 *
	 * Loud rather than silent: an over-long transient key is truncated by the
	 * database, so two different keys can quietly become one and start
	 * returning each other's values.
	 *
	 * @param string $key Your own key, unprefixed.
	 * @return string The prefixed key WordPress will store under.
	 * @throws \InvalidArgumentException When the key is empty or too long.
	 */
	protected function get_prefixed_key( string $key ): string {
		if ( '' === \trim( $key ) ) {
			throw new \InvalidArgumentException( 'A transient key cannot be empty.' );
		}

		$prefixed = $this->get_plugin()->get_namespaced_name( $key );

		if ( \strlen( $prefixed ) > self::MAX_KEY_LENGTH ) {
			throw new \InvalidArgumentException(
				\sprintf(
					'The transient key "%1$s" becomes %2$d characters once prefixed, and the limit is %3$d. Shorten it, or hash it with md5().',
					$key,
					\strlen( $prefixed ),
					self::MAX_KEY_LENGTH
				)
			);
		}

		return $prefixed;
	}
}
