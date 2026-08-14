<?php

/**
 * Cookie API: Cookie module
 */

declare( strict_types=1 );

namespace Zestry\WPToolkit\Modules;

// Loaded by WordPress, never requested directly.
\defined( 'ABSPATH' ) || exit;

use Zestry\WPToolkit\Kernel\Abstracts\Module;

/**
 * Reads and writes this plugin's cookies, encrypted when you want them to be.
 *
 * Every name is prefixed with your plugin slug, so `set( 'seen_tour', '1' )`
 * writes `acme_plugin_seen_tour` and cannot collide with WordPress's own cookies
 * or another plugin's. {@see get_cookie_name()} hands back the full name for the
 * places you need it verbatim -- JavaScript, or a caching plugin's exclusion list.
 *
 * The defaults are the safe ones: `HttpOnly`, `Secure` whenever the site is on
 * HTTPS, and `SameSite=Lax`, which is what lets a cookie survive the redirect
 * that follows a form post.
 *
 * Three pairs, in order of how much they do:
 *
 * | Write | Read | Holds |
 * |---|---|---|
 * | {@see set()} | {@see get()} | a string, as-is |
 * | {@see set_encrypted()} | {@see get_encrypted()} | any value, unreadable to the browser |
 * | {@see set_flash()} | {@see get_flash()} | the same, for exactly one more request |
 *
 * @example Reading and writing
 * A lifetime of `0` is a session cookie, gone when the browser closes.
 *
 * ```
 * $cookies = $plugin->get( Cookie::class );
 *
 * $cookies->set( 'seen_tour', '1', WEEK_IN_SECONDS );
 *
 * if ( '1' === $cookies->get( 'seen_tour' ) ) {
 *     return;
 * }
 *
 * $cookies->forget( 'seen_tour' );
 * ```
 *
 * @example Storing something structured
 * Serialized and encrypted on the way out, decrypted and restored on the way
 * back, so an array survives the round trip and the browser sees ciphertext.
 *
 * ```
 * $cookies->set_encrypted( 'cart', array( 'items' => array( 12, 40 ) ), DAY_IN_SECONDS );
 *
 * $cart = $cookies->get_encrypted( 'cart', array( 'items' => array() ) );
 * ```
 *
 * @example Carrying a notice across a redirect
 * A form handler redirects because the browser's current request is still the
 * POST -- a refresh resubmits it. But the redirect throws away everything the
 * handler knew, which is why a saved page so often comes back with `?updated=1`
 * in the URL: a query argument is the crude way to say one thing survived. It is
 * also bookmarkable, so the notice reappears on every refresh, for a save that
 * happened once.
 *
 * {@see set_flash()} is the less crude way. The value survives exactly one
 * request, is read once, and never reaches the URL.
 *
 * ```
 * public function handle_submit(): void {
 *     $this->with( Options::class )->set( 'threshold', $this->threshold );
 *
 *     $this->with( Cookie::class )->set_flash( array( 'saved' => __( 'Settings saved.', 'acme-plugin' ) ) );
 *
 *     wp_safe_redirect( $this->get_page_url() );
 *     exit;
 * }
 *
 * public function render(): void {
 *     $this->view( 'admin-pages/settings', array(
 *         'notice' => $this->with( Cookie::class )->get_flash( array() )['saved'] ?? '',
 *     ) );
 * }
 * ```
 *
 * {@see \Zestry\WPToolkit\Modules\AdminPages\AdminPage::set_flash()} and `get_flash()`
 * are the two-line version of this for a page, and need no wiring at all.
 *
 * > [!IMPORTANT]
 * > Encryption stops the browser reading or forging the contents, and does
 * > nothing about size: browsers cap a cookie near 4 KB and drop a longer one
 * > without saying so. {@see set_flash()} handles that for you by moving a large
 * > payload into a transient. {@see set_encrypted()} cannot -- a cookie's
 * > lifetime is yours to choose and a transient could not honour it -- so it
 * > refuses and says so, past {@see MAX_COOKIE_BYTES}.
 *
 * @rationale
 * The payload lives in the cookie, keyed by the cookie rather than by the
 * current user -- so a visitor who is not logged in, the case with no admin
 * notice API at all, is served the same way, and no table grows from traffic
 * that never comes back for its notice. A flash past {@see MAX_COOKIE_BYTES}
 * falls back to a transient with an unguessable id in the cookie; the
 * discriminator is a single leading character, so which of the two a cookie
 * holds is readable without a second cookie or a length heuristic.
 *
 * `sodium_crypto_secretbox()` does the work: authenticated encryption, so one
 * primitive covers both secrecy and tampering. It is always available --
 * WordPress loads `sodium_compat` from `compat.php` when the extension is not
 * -- which is what makes it preferable to an OpenSSL path that would need a
 * fallback anyway. Signing alone is not enough: a serialized payload the browser
 * can read is one a consumer will eventually put something private in.
 *
 * The key is derived per plugin, so two plugins built with this toolkit on one
 * site cannot read each other's cookies, and rotating the site's salts
 * invalidates every outstanding value.
 *
 * `maybe_serialize()` rather than `json_encode()`, so an object survives instead
 * of flattening to an array. `maybe_unserialize()` on untrusted input would be
 * an object-injection hole; the authentication is what makes it safe here, since
 * a payload that did not come from this key never reaches it.
 */
class Cookie extends Module {

	/**
	 * How long a flashed value waits to be read, in seconds.
	 *
	 * Long enough for the redirect that follows a form post, short enough that an
	 * abandoned one is not still arriving on every request an hour later.
	 *
	 * @var int
	 */
	public const FLASH_TTL = 30;

	/**
	 * The cookie a flashed value travels in.
	 *
	 * @var string
	 */
	public const FLASH_COOKIE = 'flash';

	/**
	 * The largest value this will ask a browser to hold, in bytes.
	 *
	 * Measured on the encoded cookie value, after the nonce, the authentication tag
	 * and Base64 have all had their share, so it is the number the browser is
	 * actually asked to store. The real cap is near 4096 including the name and the
	 * attributes; the rest is headroom.
	 *
	 * {@see set_flash()} treats this as the point where it moves the payload into a
	 * transient instead. {@see set_encrypted()} treats it as a limit and says so,
	 * since a cookie's lifetime is the caller's and a transient could not honour it.
	 *
	 * @var int
	 */
	public const MAX_COOKIE_BYTES = 3072;

	/**
	 * Marks a flash cookie carrying its own payload.
	 *
	 * @var string
	 */
	private const INLINE_PREFIX = 'v';

	/**
	 * Marks a flash cookie carrying the id of a payload held in a transient.
	 *
	 * @var string
	 */
	private const STORED_PREFIX = 't';

	/**
	 * Read one of this plugin's cookies.
	 *
	 * @param string      $name     The local name, without the plugin prefix.
	 * @param string|null $fallback Returned when the browser sent no such cookie.
	 * @return string|null The value, or the fallback.
	 */
	public function get( string $name, ?string $fallback = null ): ?string {
		$full = $this->get_cookie_name( $name );

		if ( ! isset( $_COOKIE[ $full ] ) ) {
			return $fallback;
		}

		/*
		 * Slashed on the way in like every other superglobal. Deliberately not
		 * sanitised beyond that: an encrypted value has to arrive byte for byte or
		 * it will not authenticate, so sanitising here would turn every tampered
		 * cookie and every intact one into the same unreadable thing. What the
		 * caller does with it is the caller's half -- get_encrypted() authenticates,
		 * and a raw string reaches a template through esc_*() like any other.
		 */
		// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		return \wp_unslash( $_COOKIE[ $full ] );
		// phpcs:enable
	}

	/**
	 * Whether the browser sent one of this plugin's cookies.
	 *
	 * The way to tell an empty string apart from an absent cookie, which
	 * {@see get()} reports the same when its fallback is null.
	 *
	 * @param string $name The local name, without the plugin prefix.
	 * @return bool
	 */
	public function has( string $name ): bool {
		return isset( $_COOKIE[ $this->get_cookie_name( $name ) ] );
	}

	/**
	 * Write one of this plugin's cookies.
	 *
	 * The value is also put into `$_COOKIE`, so the rest of *this* request reads it
	 * back -- PHP's own `setcookie()` does not, which is a reliable half hour of
	 * debugging for anyone who has not met it before.
	 *
	 * @param string $name     The local name, without the plugin prefix.
	 * @param string $value    What to store.
	 * @param int    $lifetime Seconds from now; 0 for a session cookie.
	 * @return bool Whether the header was sent.
	 */
	public function set( string $name, string $value, int $lifetime = 0 ): bool {
		$full = $this->get_cookie_name( $name );

		$_COOKIE[ $full ] = $value;

		return $this->send( $full, $value, 0 === $lifetime ? 0 : \time() + $lifetime );
	}

	/**
	 * Delete one of this plugin's cookies.
	 *
	 * @param string $name The local name, without the plugin prefix.
	 * @return bool Whether the header was sent.
	 */
	public function forget( string $name ): bool {
		$full = $this->get_cookie_name( $name );

		unset( $_COOKIE[ $full ] );

		// A cookie is deleted by being re-sent with a time already past.
		return $this->send( $full, '', \time() - \YEAR_IN_SECONDS );
	}

	/**
	 * Store any value, serialized and encrypted.
	 *
	 * The browser holds ciphertext: it cannot read the value and cannot change it
	 * without the change being detected on the way back. An array or an object
	 * arrives as itself rather than as a string.
	 *
	 * @param mixed  $value    Anything `maybe_serialize()` can represent.
	 * @param string $name     The local name, without the plugin prefix.
	 * @param int    $lifetime Seconds from now; 0 for a session cookie.
	 * @return bool Whether the header was sent.
	 */
	public function set_encrypted( string $name, mixed $value, int $lifetime = 0 ): bool {
		$sealed = $this->encrypt( (string) \maybe_serialize( $value ) );

		if ( \strlen( $sealed ) > self::MAX_COOKIE_BYTES ) {
			\_doing_it_wrong(
				__METHOD__,
				\esc_html(
					\sprintf(
						/* translators: 1: encrypted size in bytes, 2: the maximum in bytes. */
						\__( 'The encrypted value is %1$d bytes, over the %2$d a cookie can carry, and the browser would drop it without saying so. Store an identifier and read the rest back from where it already lives.', 'zestry-toolkit' ),
						\strlen( $sealed ),
						self::MAX_COOKIE_BYTES
					)
				),
				'1.0.0'
			);

			return false;
		}

		return $this->set( $name, $sealed, $lifetime );
	}

	/**
	 * Read a value written by {@see set_encrypted()}.
	 *
	 * A value that does not authenticate is discarded exactly as an absent one is,
	 * and silently: a cookie the browser truncated, a cookie left over from before
	 * the salts were rotated, and a cookie somebody edited are one event from here,
	 * and none of them is a developer's mistake to warn about.
	 *
	 * @param string $name     The local name, without the plugin prefix.
	 * @param mixed  $fallback Returned when there is nothing to read.
	 * @return mixed The stored value, or the fallback.
	 */
	public function get_encrypted( string $name, mixed $fallback = null ): mixed {
		$sealed = $this->get( $name );

		if ( null === $sealed || '' === $sealed ) {
			return $fallback;
		}

		$plain = $this->decrypt( $sealed );

		return null === $plain ? $fallback : \maybe_unserialize( $plain );
	}

	/**
	 * Store a value for the next request only.
	 *
	 * Survives exactly one redirect, encrypted like {@see set_encrypted()}, and
	 * nothing of it reaches the URL.
	 *
	 * @param mixed  $value Anything `maybe_serialize()` can represent.
	 * @param string $name  The cookie to travel in, when one flash is not enough.
	 * @return bool Whether the header was sent.
	 */
	public function set_flash( mixed $value, string $name = self::FLASH_COOKIE ): bool {
		$sealed = $this->encrypt( (string) \maybe_serialize( $value ) );

		// Small enough to travel in the cookie, which is almost every flash: a
		// notice, a count, a handful of names. No database, no cleanup.
		if ( \strlen( $sealed ) + 1 <= self::MAX_COOKIE_BYTES ) {
			return $this->set( $name, self::INLINE_PREFIX . $sealed, self::FLASH_TTL );
		}

		/*
		 * Too big for a cookie, so the cookie carries an unguessable id and the
		 * payload waits in a transient -- still sealed, so the row is opaque too.
		 * The transient expires on its own, which is why this is worth doing for a
		 * flash and not for set_encrypted(): a flash already has a lifetime measured
		 * in seconds, where a cookie's is the caller's to choose and a transient
		 * could not honour it.
		 */
		$id = \wp_generate_password( 20, false );

		$this->with( Transients::class )->set( self::STORED_PREFIX . $id, $sealed, self::FLASH_TTL );

		return $this->set( $name, self::STORED_PREFIX . $id, self::FLASH_TTL );
	}

	/**
	 * Take a flashed value, which can be read only once.
	 *
	 * The second call returns the fallback: reading deletes the cookie, so a
	 * refresh does not show a notice again for something that already happened.
	 * WordPress's own `get_settings_errors()` consumes its transient the same way.
	 *
	 * @param mixed  $fallback Returned when nothing was flashed.
	 * @param string $name     The cookie it travelled in.
	 * @return mixed The flashed value, or the fallback.
	 */
	public function get_flash( mixed $fallback = null, string $name = self::FLASH_COOKIE ): mixed {
		if ( ! $this->has( $name ) ) {
			return $fallback;
		}

		$carried = (string) $this->get( $name );

		// Read once whatever the outcome, so a value that fails below is not
		// re-checked on every request until it expires.
		$this->forget( $name );

		$sealed = \substr( $carried, 1 );

		if ( \str_starts_with( $carried, self::STORED_PREFIX ) ) {
			$key    = $carried;
			$sealed = (string) $this->with( Transients::class )->get( $key, '' );

			$this->with( Transients::class )->delete( $key );
		} elseif ( ! \str_starts_with( $carried, self::INLINE_PREFIX ) ) {
			// Neither shape: a cookie from before this scheme, or one somebody wrote.
			return $fallback;
		}

		if ( '' === $sealed ) {
			return $fallback;
		}

		$plain = $this->decrypt( $sealed );

		return null === $plain ? $fallback : \maybe_unserialize( $plain );
	}

	/**
	 * The full name a cookie is stored under.
	 *
	 * Your slug joined to the local name with `_`, the separator WordPress uses for
	 * its own cookies. Reach for this wherever the name is needed outside PHP --
	 * reading it in JavaScript, or naming it in a caching plugin's exclusion list.
	 *
	 * @param string $name The local name.
	 * @return string The full cookie name.
	 */
	public function get_cookie_name( string $name ): string {
		return $this->get_plugin()->get_namespaced_name( $name, '_' );
	}

	/**
	 * Seal a string so the browser can neither read nor alter it.
	 *
	 * @param string $plain What to encrypt.
	 * @return string The nonce and ciphertext, Base64 encoded.
	 */
	private function encrypt( string $plain ): string {
		$key   = $this->get_key();
		$nonce = \random_bytes( \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );

		// The nonce is not a secret and has to come back to decrypt, so it rides in
		// front of the ciphertext. A fresh one per write is the whole requirement.
		$sealed = $nonce . \sodium_crypto_secretbox( $plain, $nonce, $key );

		\sodium_memzero( $key );

		return \base64_encode( $sealed );
	}

	/**
	 * Open a string sealed by {@see encrypt()}.
	 *
	 * @param string $sealed The Base64 value from the cookie.
	 * @return string|null The plaintext, or null when it does not authenticate.
	 */
	private function decrypt( string $sealed ): ?string {
		$raw = \base64_decode( $sealed, true );

		if ( false === $raw || \strlen( $raw ) <= \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return null;
		}

		$key   = $this->get_key();
		$nonce = \substr( $raw, 0, \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );

		// False on any tampering, truncation or a key that no longer matches --
		// sodium makes no distinction between them, and neither does the caller.
		$plain = \sodium_crypto_secretbox_open(
			\substr( $raw, \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ),
			$nonce,
			$key
		);

		\sodium_memzero( $key );

		return false === $plain ? null : $plain;
	}

	/**
	 * The encryption key for this plugin's cookies.
	 *
	 * Derived from the site's own salts and this plugin's slug, so nothing has to
	 * be generated, stored or rotated by hand -- and two plugins built with this
	 * toolkit on one site cannot read each other's cookies. Rotating the salts
	 * invalidates every outstanding value, which is what rotating them is for.
	 *
	 * @return string A 32-byte key.
	 */
	private function get_key(): string {
		return \sodium_crypto_generichash(
			$this->get_plugin()->get_slug(),
			\substr( \wp_salt( 'secure_auth' ), 0, \SODIUM_CRYPTO_GENERICHASH_KEYBYTES_MAX ),
			\SODIUM_CRYPTO_SECRETBOX_KEYBYTES
		);
	}

	/**
	 * Send one cookie header, or say why it could not be sent.
	 *
	 * @param string $full    The full cookie name.
	 * @param string $value   What to store.
	 * @param int    $expires A Unix timestamp, or 0 for a session cookie.
	 * @return bool Whether the header was sent.
	 */
	private function send( string $full, string $value, int $expires ): bool {
		if ( \headers_sent() ) {
			/*
			 * Not thrown: the value is already in $_COOKIE, so this request behaves,
			 * and taking a site down over a notice would be worse than the notice
			 * going missing. But it is a mistake with no other symptom -- the cookie
			 * silently never reaches the browser -- so it says so where a developer
			 * will see it.
			 */
			\_doing_it_wrong(
				__METHOD__,
				\esc_html(
					\sprintf(
						/* translators: %s: cookie name. */
						\__( 'The cookie "%s" cannot be sent because output has already started. Write cookies before anything is echoed -- on an admin page that means handle_submit(), not render().', 'zestry-toolkit' ),
						$full
					)
				),
				'1.0.0'
			);

			return false;
		}

		return \setcookie(
			$full,
			$value,
			array(
				'expires'  => $expires,
				'path'     => \COOKIEPATH ? \COOKIEPATH : '/',
				'domain'   => \COOKIE_DOMAIN ? \COOKIE_DOMAIN : '',
				'secure'   => \is_ssl(),
				'httponly' => true,
				// Lax rather than Strict: Strict withholds the cookie on a navigation
				// that started off-site, which is exactly the redirect back from a
				// payment gateway or an identity provider.
				'samesite' => 'Lax',
			)
		);
	}
}
