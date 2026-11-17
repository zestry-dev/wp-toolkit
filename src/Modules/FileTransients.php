<?php

/**
 * Transients API: FileTransients module
 */

declare( strict_types=1 );

namespace Zestry\WPToolkit\Modules;

// Loaded by WordPress, never requested directly.
\defined( 'ABSPATH' ) || exit;

use Zestry\WPToolkit\Kernel\Abstracts\Module;
use Zestry\WPToolkit\Kernel\Contracts\Bootable;
use Zestry\WPToolkit\Kernel\Helpers\Str;

/**
 * Keeps big, read-often values in PHP files that OPcache holds in memory.
 *
 * The same four verbs as {@see Transients} — `get`, `set`, `has`, `delete` —
 * for a value too large to unserialize on every request, such as an index of
 * a remote catalog. Each value is a PHP file returning an array; with OPcache
 * that array already sits in shared memory, so reading it takes a fraction of
 * a millisecond and no memory of the request's own, where a transient of the
 * same size is read from the database and unserialized every time.
 *
 * **Files or nothing.** Without OPcache, each file is compiled again on every
 * request, which is slower than the transient it replaces. So the files are
 * used only when OPcache is on and the folder is writable, and otherwise
 * nothing is stored at all: {@see set()} does nothing, {@see get()} returns
 * your fallback, and {@see has()} is false. Rebuild the value, as for any
 * cache miss. {@see is_available()} says which case you are in, and
 * {@see get_unavailable_reason()} says why, in a sentence you can show an
 * administrator. The `{slug}_file_transients_enabled` filter (with the slug's
 * dashes as underscores) can turn the files off; it is asked once per request.
 *
 * **Values:** arrays, strings, numbers, booleans and null, nested. They come
 * back exactly as stored, `false` and `null` included, and {@see has()} tells
 * a stored `null` from a missing key. An object, a closure or a resource is
 * refused with a `_doing_it_wrong()` notice and not stored, since a file can
 * only hold what `var_export()` writes. An empty key throws, as it does for
 * {@see Transients}.
 *
 * **No transient hooks.** {@see Transients} goes through `set_transient()`, so
 * WordPress's own `pre_transient_{$key}`, `set_transient_{$key}` and the rest
 * fire, and an object-cache plugin sees every call. These never reach that
 * API, so none of those hooks fire and no object cache is involved.
 *
 * **Where:** `wp-content/cache/{slug}/{blog id}/`, outside uploads, where
 * security scanners flag PHP files and backups copy everything. Each site of
 * a multisite network gets its own folder.
 *
 * **Treat every value as optional**, as with any transient: {@see flush()} or
 * a deploy can remove it. Expired files are deleted by {@see prune()}, which
 * runs with WordPress's own daily cleanup of expired transients.
 *
 * @example Caching a large index
 * ```
 * $files = $this->with( FileTransients::class );
 *
 * $index = $files->get( 'catalog' );
 *
 * if ( null === $index ) {
 *     $index = $this->build_index();
 *
 *     // Where the files are off this stores nothing, and the index is rebuilt next time.
 *     $files->set( 'catalog', $index, DAY_IN_SECONDS );
 * }
 * ```
 *
 * @rationale Measured on PHP 8.3 / Apache with a 1,000-item array: 0.07–0.17 ms
 * and 0 MB per request with OPcache warm, against 2–6 ms and 2.1 MB for a
 * regular transient. Without OPcache the same file costs about 17 ms to compile,
 * which is why there is no fallback to files without it -- and none to the
 * database either, since a caller that wanted a transient has one. A full
 * `opcache.interned_strings_buffer` makes OPcache copy the array per request
 * (+2 MB, ~7 ms), still no worse than a transient. A file's source is only ever
 * `var_export()` of the value, never a concatenation of it, written to a temp
 * file and renamed into place so a reader never sees half a file. A lock would
 * not do: `include` ignores flock(), and OPcache may not open the file at all.
 * The expiry is repeated in the first line so prune() never includes a file,
 * which from WP-CLI (OPcache usually off) would compile and build every value
 * in one process. Not the file's mtime: OPcache refuses to cache a file
 * modified within `opcache.file_update_protection` seconds of now, and a
 * future mtime would never leave that window.
 */
class FileTransients extends Module implements Bootable {

	/**
	 * The folder inside wp-content that holds the plugin's own folder.
	 */
	public const CACHE_FOLDER = 'cache';

	/**
	 * The longest readable part of a file name, before its hash.
	 */
	private const NAME_LENGTH = 40;

	/**
	 * The header on a file's first line that repeats its expiry.
	 *
	 * The expiry is in the file's header as well as in its array, so prune()
	 * can read it without loading the value.
	 */
	private const EXPIRES_HEADER = 'Expires';

	/**
	 * How old a temp file has to be before prune() takes it for one a crashed write left behind.
	 */
	private const STALE_TEMP_SECONDS = HOUR_IN_SECONDS;

	/**
	 * Whether the files are used on this request; null until asked.
	 */
	private ?bool $available = null;

	/**
	 * The folder of each site, by blog id, since switch_to_blog() changes it.
	 *
	 * @var array<int, string>
	 */
	private array $folders = array();

	/**
	 * Delete expired files with WordPress's daily cleanup of expired transients.
	 *
	 * @return void
	 */
	public function on_boot(): void {
		\add_action( 'delete_expired_transients', array( $this, 'prune' ) );
	}

	/**
	 * Read a stored value.
	 *
	 * @param string $key      Your own key. Anything goes; it is hashed into the file name.
	 * @param mixed  $fallback Returned when nothing is stored, it expired, or the files are off.
	 * @return mixed The stored value, or `$fallback`.
	 * @throws \InvalidArgumentException When the key is empty.
	 */
	public function get( string $key, mixed $fallback = null ): mixed {
		$entry = $this->read( $key );

		return null === $entry ? $fallback : $entry['value'];
	}

	/**
	 * Store a value.
	 *
	 * @param string $key   Your own key.
	 * @param mixed  $value Arrays, strings, numbers, booleans and null, nested.
	 * @param int    $ttl   Seconds to keep it. 0 means no expiry; a negative number is already expired.
	 *
	 * Nothing is stored where the files are off ({@see is_available()}), and a
	 * write the file system refuses is reported by PHP's own warning.
	 *
	 * @return void
	 * @throws \InvalidArgumentException When the key is empty.
	 */
	public function set( string $key, mixed $value, int $ttl = 0 ): void {
		$this->check_key( $key );

		if ( ! $this->is_storable( $value ) ) {
			\_doing_it_wrong(
				__METHOD__,
				\esc_html__( 'File transients hold arrays, strings, numbers, booleans and null only. The value was not stored.', 'zestry-toolkit' ),
				'1.0.0'
			);

			return;
		}

		if ( $this->is_available() ) {
			$this->write( $key, $value, $ttl );
		}
	}

	/**
	 * Whether a value is stored under this key.
	 *
	 * Distinct from `null !== get()`, which cannot tell a stored `null` from a
	 * missing key.
	 *
	 * @param string $key Your own key.
	 * @return bool True when something is stored and has not expired, whatever its value.
	 * @throws \InvalidArgumentException When the key is empty.
	 */
	public function has( string $key ): bool {
		return null !== $this->read( $key );
	}

	/**
	 * Delete a stored value. Deleting what was never there is not an error.
	 *
	 * @param string $key Your own key.
	 * @return void
	 * @throws \InvalidArgumentException When the key is empty.
	 */
	public function delete( string $key ): void {
		$this->check_key( $key );

		try {
			$this->remove( $this->get_file( $key ) );
		} catch ( \RuntimeException $error ) {
			// No folder, so no file to delete.
			return;
		}
	}

	/**
	 * Delete every value of the current site.
	 *
	 * Unlike {@see Transients}, this one can: the files are in a folder of
	 * their own, so nothing of another plugin's is anywhere near them.
	 *
	 * @return void
	 */
	public function flush(): void {
		foreach ( $this->get_stored_files() as $file ) {
			$this->remove( $file );
		}
	}

	/**
	 * Delete the expired values of the current site, and any file that is not one of them.
	 *
	 * Reads only the first line of each file, where the expiry is, so a
	 * large value is never loaded just to find out it can be deleted. Also
	 * deletes a temp file an interrupted write left behind, once it is an
	 * hour old.
	 *
	 * @return int How many files were deleted.
	 */
	public function prune(): int {
		$deleted = 0;
		$now     = \time();

		foreach ( $this->get_stored_files() as $file ) {
			$expires = $this->read_expiry( $file );

			if ( null === $expires || ( 0 !== $expires && $expires <= $now ) ) {
				$this->remove( $file );
				++$deleted;
			}
		}

		foreach ( $this->get_temp_files() as $file ) {
			$modified = \filemtime( $file );

			if ( false !== $modified && $modified <= $now - self::STALE_TEMP_SECONDS ) {
				\wp_delete_file( $file );
				++$deleted;
			}
		}

		return $deleted;
	}

	/**
	 * Whether the files are used on this request.
	 *
	 * True when OPcache is on and the folder is writable, unless the
	 * `{slug}_file_transients_enabled` filter turned them off. Decided once,
	 * on the first call.
	 *
	 * @return bool True when values can be stored.
	 */
	public function is_available(): bool {
		if ( null === $this->available ) {
			$this->available = (bool) \apply_filters(
				$this->get_filter_name(),
				$this->is_opcache_enabled() && $this->prepare_folder()
			);
		}

		return $this->available;
	}

	/**
	 * Why the files are off, as a sentence for an administrator; empty when they are on.
	 *
	 * @return string The reason, or an empty string.
	 */
	public function get_unavailable_reason(): string {
		if ( $this->is_available() ) {
			return '';
		}

		if ( ! $this->is_opcache_enabled() ) {
			return \__( 'OPcache is turned off for this PHP. Turn on opcache.enable, then reload the page.', 'zestry-toolkit' );
		}

		if ( ! $this->prepare_folder() ) {
			try {
				$folder = $this->get_folder();
			} catch ( \RuntimeException $error ) {
				return \__( 'The cache folder could not be created. Give the web server write access to wp-content/cache.', 'zestry-toolkit' );
			}

			return \sprintf(
				/* translators: %s: a folder path, such as /var/www/wp-content/cache/acme-plugin/1. */
				\__( 'The cache folder is not writable: %s. Give the web server write access to it.', 'zestry-toolkit' ),
				$folder
			);
		}

		return \sprintf(
			/* translators: %s: a filter name, such as acme_plugin_file_transients_enabled. */
			\__( 'The %s filter turned the files off.', 'zestry-toolkit' ),
			$this->get_filter_name()
		);
	}

	/**
	 * The folder of the current site's files.
	 *
	 * @return string Absolute path, without a trailing slash.
	 * @throws \RuntimeException When the plugin's cache folder cannot be created.
	 */
	public function get_folder(): string {
		$blog = \get_current_blog_id();

		if ( ! isset( $this->folders[ $blog ] ) ) {
			// The plugin's content folder is shared by every site of a network,
			// so each one gets a folder of its own inside it.
			$this->folders[ $blog ] = Str::join_path(
				$this->with( Path::class )->get_plugin_content_dir( self::CACHE_FOLDER ),
				(string) $blog
			);
		}

		return $this->folders[ $blog ];
	}

	/**
	 * The file a key is stored in.
	 *
	 * A readable part of the key, then a short hash of all of it, so keys that
	 * read alike (`a b` and `a-b`) stay two files.
	 *
	 * @param string $key Your own key.
	 * @return string Absolute path.
	 * @throws \InvalidArgumentException When the key is empty.
	 * @throws \RuntimeException When the plugin's cache folder cannot be created.
	 */
	public function get_file( string $key ): string {
		$this->check_key( $key );

		$readable = \trim( (string) \preg_replace( '/[^a-z0-9_-]+/', '-', \strtolower( $key ) ), '-' );
		$readable = \substr( $readable, 0, self::NAME_LENGTH );
		$hash     = \substr( \md5( $key ), 0, 16 );

		return Str::join_path( $this->get_folder(), ( '' === $readable ? '' : $readable . '-' ) . $hash . '.php' );
	}

	/**
	 * The stored entry, unless the files are off or it is missing or expired.
	 *
	 * @param string $key Your own key.
	 * @return array{expires: int, value: mixed}|null The entry, or null.
	 */
	private function read( string $key ): ?array {
		$this->check_key( $key );

		if ( ! $this->is_available() ) {
			return null;
		}

		try {
			$file = $this->get_file( $key );
		} catch ( \RuntimeException $error ) {
			// The filter turned the files on, but there is no folder to hold them.
			return null;
		}

		$entry = $this->include_entry( $file );

		if ( null === $entry ) {
			return null;
		}

		if ( $this->is_expired( $entry, \time() ) ) {
			$this->remove( $file );

			return null;
		}

		return $entry;
	}

	/**
	 * Include a file and check it is one of ours.
	 *
	 * A file that is missing, does not parse, or returns anything else counts
	 * as missing.
	 *
	 * @param string $file Absolute path.
	 * @return array{expires: int, value: mixed}|null The entry, or null.
	 */
	private function include_entry( string $file ): ?array {
		if ( ! \is_file( $file ) ) {
			return null;
		}

		try {
			$entry = include $file;
		} catch ( \Throwable $error ) {
			return null;
		}

		if ( ! \is_array( $entry ) || ! isset( $entry['expires'] ) || ! \is_int( $entry['expires'] ) || ! \array_key_exists( 'value', $entry ) ) {
			return null;
		}

		return $entry;
	}

	/**
	 * The expiry in a file's header, without including the file.
	 *
	 * @param string $file Absolute path.
	 * @return int|null The timestamp, 0 for never, or null when the file is not one of ours.
	 */
	private function read_expiry( string $file ): ?int {
		$expires = $this->with( Path::class )->read_file_header( $file, self::EXPIRES_HEADER );

		return null !== $expires && \ctype_digit( $expires ) ? (int) $expires : null;
	}

	/**
	 * Whether an entry is past its expiry.
	 *
	 * @param array{expires: int, value: mixed} $entry An included file's array.
	 * @param int                               $now   The current time.
	 * @return bool True when it has expired.
	 */
	private function is_expired( array $entry, int $now ): bool {
		return 0 !== $entry['expires'] && $entry['expires'] <= $now;
	}

	/**
	 * Write a value's file: a temp file in the same folder, then a rename over the real one.
	 *
	 * @param string $key   Your own key.
	 * @param mixed  $value A storable value.
	 * @param int    $ttl   Seconds to keep it.
	 * @return void
	 */
	private function write( string $key, mixed $value, int $ttl ): void {
		if ( ! $this->prepare_folder() ) {
			return;
		}

		$expires = 0 === $ttl ? 0 : \time() + $ttl;

		$template = \implode(
			"\n",
			array(
				'<?php // %1$s: %2$d',
				"defined( 'ABSPATH' ) || exit;",
				'return array( \'expires\' => %2$d, \'value\' => %3$s );',
				'',
			)
		);

		// The expiry is an int printed by %2$d; the value reaches the source only through var_export().
		$source = \sprintf(
			$template,
			self::EXPIRES_HEADER,
			$expires,
			\var_export( $value, true ) // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export
		);

		$file = $this->get_file( $key );
		$temp = Str::join_path( $this->get_folder(), '.tmp-' . \bin2hex( \random_bytes( 6 ) ) );

		if ( false === \file_put_contents( $temp, $source, LOCK_EX ) ) {
			return;
		}

		\chmod( $temp, 0644 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod

		if ( ! \rename( $temp, $file ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
			\wp_delete_file( $temp );

			return;
		}

		$this->invalidate( $file );
	}

	/**
	 * Delete a file and drop it from OPcache.
	 *
	 * @param string $file Absolute path.
	 * @return void
	 */
	private function remove( string $file ): void {
		if ( \is_file( $file ) ) {
			\wp_delete_file( $file );
		}

		$this->invalidate( $file );
	}

	/**
	 * Tell OPcache a file changed.
	 *
	 * @param string $file Absolute path.
	 * @return void
	 */
	private function invalidate( string $file ): void {
		if ( \function_exists( 'opcache_invalidate' ) && $this->can_call_opcache() ) {
			\opcache_invalidate( $file, true );
		}
	}

	/**
	 * Every value file of the current site, without the `index.php` guard.
	 *
	 * @return string[] Absolute paths.
	 */
	private function get_stored_files(): array {
		try {
			$files = \glob( Str::join_path( $this->get_folder(), '*.php' ) );
		} catch ( \RuntimeException $error ) {
			return array();
		}

		$stored = array();

		foreach ( false === $files ? array() : $files as $file ) {
			if ( 'index.php' !== \basename( $file ) ) {
				$stored[] = $file;
			}
		}

		return $stored;
	}

	/**
	 * The temp files of the current site's folder, finished or not.
	 *
	 * @return string[] Absolute paths.
	 */
	private function get_temp_files(): array {
		try {
			$files = \glob( Str::join_path( $this->get_folder(), '.tmp-*' ) );
		} catch ( \RuntimeException $error ) {
			return array();
		}

		return false === $files ? array() : $files;
	}

	/**
	 * Create the folder with an empty `index.php` in it and its parent, and say whether it is writable.
	 *
	 * @return bool True when the folder exists and can be written to.
	 */
	private function prepare_folder(): bool {
		try {
			$folder = $this->get_folder();
		} catch ( \RuntimeException $error ) {
			return false;
		}

		if ( ! \wp_mkdir_p( $folder ) || ! \wp_is_writable( $folder ) ) {
			return false;
		}

		foreach ( array( \dirname( $folder ), $folder ) as $dir ) {
			$guard = Str::join_path( $dir, 'index.php' );

			if ( ! \is_file( $guard ) ) {
				\file_put_contents( $guard, "<?php\n// Silence is golden.\n" );
			}
		}

		return true;
	}

	/**
	 * Whether OPcache is on for this PHP. The function can be missing or restricted.
	 *
	 * @return bool True when OPcache is enabled.
	 */
	private function is_opcache_enabled(): bool {
		if ( ! \function_exists( 'opcache_get_status' ) || ! $this->can_call_opcache() ) {
			return false;
		}

		$status = \opcache_get_status( false );

		return \is_array( $status ) && ! empty( $status['opcache_enabled'] );
	}

	/**
	 * Whether this script may call OPcache's functions.
	 *
	 * `opcache.restrict_api` limits them to scripts under one path, and any
	 * other script that calls one gets a warning. That is the same test PHP
	 * makes, asked first so the warning never happens.
	 *
	 * @return bool True when the API is unrestricted, or this script is under the allowed path.
	 */
	private function can_call_opcache(): bool {
		$allowed = (string) \ini_get( 'opcache.restrict_api' );

		if ( '' === $allowed ) {
			return true;
		}

		$script = isset( $_SERVER['SCRIPT_FILENAME'] ) ? \sanitize_text_field( \wp_unslash( $_SERVER['SCRIPT_FILENAME'] ) ) : '';

		return '' !== $script && \str_starts_with( $script, $allowed );
	}

	/**
	 * Whether `var_export()` can write a value back as it was.
	 *
	 * @param mixed $value Any value.
	 * @return bool True for arrays, strings, numbers, booleans and null, nested.
	 */
	private function is_storable( mixed $value ): bool {
		if ( \is_array( $value ) ) {
			foreach ( $value as $item ) {
				if ( ! $this->is_storable( $item ) ) {
					return false;
				}
			}

			return true;
		}

		return null === $value || \is_scalar( $value );
	}

	/**
	 * Refuse an empty key, as Transients does.
	 *
	 * @param string $key Your own key.
	 * @return void
	 * @throws \InvalidArgumentException When the key is empty.
	 */
	private function check_key( string $key ): void {
		if ( '' === \trim( $key ) ) {
			throw new \InvalidArgumentException( 'A file transient key cannot be empty.' );
		}
	}

	/**
	 * The filter that can turn the files off: `{slug}_file_transients_enabled`.
	 *
	 * @return string The filter name.
	 */
	private function get_filter_name(): string {
		// The same conversion as Plugin::get_loaded_hook(): a slug's only
		// separator is a single dash.
		return \str_replace( '-', '_', $this->get_plugin()->get_slug() ) . '_file_transients_enabled';
	}
}
