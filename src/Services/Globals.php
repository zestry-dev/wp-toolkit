<?php

/**
 * Globals API: Globals service
 */

declare( strict_types=1 );

namespace Zestry\WPToolkit\Services;

// Loaded by WordPress, never requested directly.
\defined( 'ABSPATH' ) || exit;

use Zestry\WPToolkit\Kernel\Abstracts\Service;

/**
 * Provides an in-memory registry for values shared during one request.
 *
 * Unlike Options, values are never written to the database. This is useful for
 * request-scoped coordination: one module stores something another needs later
 * in the same request, without a global or a database round trip.
 *
 * @example Sharing a value across one request
 * `has()` is the way to tell a stored `null` from an absent key -- `get()`
 * returns `null` for both.
 *
 * ```
 * $globals = $plugin->get( Globals::class );
 *
 * $globals->set( 'current_job', $job );
 *
 * // Elsewhere in the same request:
 * if ( $globals->has( 'current_job' ) ) {
 *     $job = $globals->get( 'current_job' );
 * }
 *
 * // get() takes a fallback for a key that was never set:
 * $mode = $globals->get( 'render_mode', 'default' );
 * ```
 */
class Globals extends Service {

	/**
	 * Global values registry.
	 *
	 * @var array<string, mixed>
	 */
	private array $registry = array();

	/**
	 * Set a global value.
	 *
	 * Returns void, like every other setter here, so calls do not chain. Only
	 * `Plugin`'s builder methods are fluent.
	 *
	 * @rationale
	 * This returned `$this` once, which made `$globals->set(...)->set(...)`
	 * work while the identical-looking call on the sibling `Options` store was
	 * a fatal. Keep every setter void; chaining belongs to `Plugin` alone.
	 *
	 * @param string $key   The registry key.
	 * @param mixed  $value The value to store.
	 * @return void
	 */
	public function set( string $key, $value ): void {
		$this->registry[ $key ] = $value;
	}

	/**
	 * Get a global value.
	 *
	 * Existence is checked with has() rather than a null comparison, so a value
	 * that was explicitly stored as null is returned as null and is not confused
	 * with a key that was never set.
	 *
	 * @param string $key     The registry key.
	 * @param mixed  $fallback The default value if key does not exist.
	 * @return mixed The stored value or default.
	 */
	public function get( string $key, $fallback = null ): mixed {
		if ( $this->has( $key ) ) {
			return $this->registry[ $key ];
		}

		return $fallback;
	}

	/**
	 * Check if a global value exists.
	 *
	 * @param string $key The registry key.
	 * @return bool True if the key exists, false otherwise.
	 */
	public function has( string $key ): bool {
		return \array_key_exists( $key, $this->registry );
	}

	/**
	 * Remove a value.
	 *
	 * Removing something that was never there is not an error.
	 *
	 * @param string $key The key to remove.
	 * @return void
	 */
	public function delete( string $key ): void {
		unset( $this->registry[ $key ] );
	}
}
