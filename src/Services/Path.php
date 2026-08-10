<?php

/**
 * Path API: Path service
 */

declare( strict_types=1 );

namespace Zestry\WPToolkit\Services;

// Loaded by WordPress, never requested directly.
\defined( 'ABSPATH' ) || exit;

use Zestry\WPToolkit\Kernel\Abstracts\Service;

/**
 * Provides utilities for accessing plugin files, directories, and URLs.
 *
 * It derives all locations from the entry file held by the plugin.
 *
 * Resource paths are contained within the plugin root: a leading separator is
 * trimmed, any parent-directory (`..`) segment and any NUL byte is rejected,
 * and for a file that exists the real (symlink-resolved) target must still sit
 * inside the plugin -- so you cannot resolve a path outside the plugin, even
 * through a symlink. A rejected path throws rather than answering falsy; pass
 * `$allow_escape = true` to opt out of containment for a deliberate case, on
 * the methods that offer it.
 *
 * @example Resolving paths and URLs
 * ```
 * $path = $plugin->get( Path::class );
 *
 * // Absolute filesystem path to a file inside the plugin:
 * require $path->get_plugin_path( 'views/email.php' );
 *
 * // Browser URL for the same plugin:
 * wp_enqueue_script( 'app', $path->get_plugin_url( 'assets/app.js' ) );
 *
 * // The plugin's own directory inside wp-content/uploads:
 * $dir = $path->get_plugin_uploads_dir();
 * ```
 */
class Path extends Service {

	/**
	 * Cached plugin directory path.
	 *
	 * @var string|null
	 */
	private ?string $plugin_dir = null;

	/**
	 * Cached plugin URL.
	 *
	 * @var string|null
	 */
	private ?string $url = null;

	/**
	 * Cached real (symlink-resolved) plugin base directory.
	 *
	 * @var string|false|null
	 */
	private string|false|null $real_base_dir = null;

	/**
	 * Get a plugin resource URL.
	 *
	 * Constructs a full URL to a plugin resource with optional query arguments.
	 * Path components are automatically URL-encoded. The resource path is
	 * contained within the plugin root unless containment is explicitly waived. The
	 * returned URL must still pass through esc_url()/esc_url_raw() at output time.
	 *
	 * @param string $path         The resource path relative to the plugin directory.
	 * @param array  $query_args   Optional query arguments to append to the URL.
	 * @param bool   $allow_escape When true, skip the containment checks.
	 * @return string The full resource URL.
	 * @throws \InvalidArgumentException When the path escapes the plugin root and escape is not allowed.
	 */
	public function get_plugin_url( string $path = '', array $query_args = array(), bool $allow_escape = false ): string {
		$path = \ltrim( $path, '/\\' );

		if ( $path === '' ) {
			$url = $this->get_plugin_base_url();
		} else {
			if ( ! $allow_escape && $this->is_escaping_root( $path ) ) {
				throw new \InvalidArgumentException( 'Resource path must stay within the plugin directory.' );
			}

			$path_parts = \explode( '/', $path );
			$path_parts = \array_map( 'rawurlencode', $path_parts );
			$url        = $this->get_plugin_base_url() . '/' . \implode( '/', $path_parts );
		}

		return \add_query_arg( $query_args, $url );
	}

	/**
	 * Get a plugin resource file path.
	 *
	 * Constructs a full file system path to a plugin resource. The resource path
	 * is contained within the plugin root unless containment is explicitly waived.
	 *
	 * @param string $path         The resource path relative to the plugin directory.
	 * @param bool   $allow_escape When true, skip the containment checks.
	 * @return string The full file system path.
	 * @throws \InvalidArgumentException When the path escapes the plugin root and escape is not allowed.
	 */
	public function get_plugin_path( string $path = '', bool $allow_escape = false ): string {
		$path = \ltrim( $path, '/\\' );

		if ( $path === '' ) {
			return $this->get_plugin_base_dir();
		}

		$full = $this->get_plugin_base_dir() . '/' . $path;

		if ( ! $allow_escape ) {
			if ( $this->is_escaping_root( $path ) ) {
				// A parent-directory segment would resolve outside the plugin root.
				throw new \InvalidArgumentException( 'Resource path must stay within the plugin directory.' );
			}

			if ( $this->is_resolved_escaping_root( $full ) ) {
				// The path exists but its real target (via a symlink) is outside the root.
				throw new \InvalidArgumentException( 'Resource path must stay within the plugin directory.' );
			}
		}

		return $full;
	}

	/**
	 * Check if a plugin resource exists.
	 *
	 * A path that escapes the plugin root throws rather than answering false,
	 * and there is no `$allow_escape` opt-out here -- so validate anything that
	 * came from request input before passing it in.
	 *
	 * @param string $path The resource path relative to the plugin directory.
	 * @return bool True if the resource exists, false otherwise.
	 * @throws \InvalidArgumentException When the path escapes the plugin root.
	 */
	public function plugin_file_exists( string $path = '' ): bool {
		return \file_exists( $this->get_plugin_path( $path ) );
	}

	/**
	 * Check if a plugin resource is a directory.
	 *
	 * A path that escapes the plugin root throws rather than answering false,
	 * and there is no `$allow_escape` opt-out here -- so validate anything that
	 * came from request input before passing it in.
	 *
	 * @param string $path The resource path relative to the plugin directory.
	 * @return bool True if the resource exists and is a directory, false otherwise.
	 * @throws \InvalidArgumentException When the path escapes the plugin root.
	 */
	public function is_plugin_dir( string $path = '' ): bool {
		$dir = $this->get_plugin_path( $path );
		return \is_dir( $dir );
	}

	/**
	 * Get the WordPress uploads directory.
	 *
	 * @return string The uploads directory path without trailing slash.
	 */
	public function get_uploads_dir(): string {
		$upload_dir = \wp_upload_dir();
		return \untrailingslashit( $upload_dir['basedir'] );
	}

	/**
	 * Get the plugin-specific uploads directory.
	 *
	 * Creates the directory if it does not exist.
	 *
	 * @return string The plugin uploads directory path.
	 * @throws \RuntimeException When the directory cannot be created.
	 */
	public function get_plugin_uploads_dir(): string {
		$base_dir = $this->get_uploads_dir() . '/' . $this->get_plugin()->get_slug();

		if ( ! \wp_mkdir_p( $base_dir ) ) {
			// Do not return a path that callers cannot safely write to.
			throw new \RuntimeException( 'Could not create upload directory: ' . $base_dir );
		}

		return $base_dir;
	}

	/**
	 * Get a plugin-specific upload URL.
	 *
	 * Constructs a URL to a resource in the plugin uploads directory. Path
	 * components are URL-encoded, matching get_plugin_url(). The resource path is
	 * contained within that directory unless containment is explicitly waived. The
	 * returned URL must still pass through esc_url()/esc_url_raw() at output time.
	 *
	 * @param string $path         The resource path relative to the plugin uploads directory.
	 * @param array  $query_args   Optional query arguments to append to the URL.
	 * @param bool   $allow_escape When true, skip the containment checks.
	 * @return string The full upload resource URL.
	 * @throws \InvalidArgumentException When the path escapes the uploads directory and escape is not allowed.
	 */
	public function get_plugin_upload_url( string $path = '', array $query_args = array(), bool $allow_escape = false ): string {
		$path = \ltrim( $path, '/\\' );

		if ( ! $allow_escape && $this->is_escaping_root( $path ) ) {
			throw new \InvalidArgumentException( 'Resource path must stay within the plugin uploads directory.' );
		}

		$upload_dir = \wp_upload_dir();
		$base_url   = \untrailingslashit( $upload_dir['baseurl'] ) . '/' . $this->get_plugin()->get_slug();

		if ( $path !== '' ) {
			// Encode each segment like get_plugin_url() so spaces or reserved
			// characters in a (possibly user-derived) name cannot break the URL.
			$path      = \implode( '/', \array_map( 'rawurlencode', \explode( '/', $path ) ) );
			$base_url .= '/' . $path;
		}

		return \add_query_arg( $query_args, $base_url );
	}

	/**
	 * Determine whether a plugin-relative path contains a NUL byte or a
	 * parent-directory segment.
	 *
	 * A NUL byte is rejected outright, since it truncates the path for every
	 * filesystem call underneath. Otherwise this splits on both separators and
	 * checks each segment, so `..`, `a/../b`, and `a\..\b` are all detected
	 * regardless of platform, while a name that merely contains dots (such as
	 * `a..b`) is allowed. This is a lexical check, so it works for paths that do
	 * not exist on disk yet.
	 *
	 * @param string $path The plugin-relative path to inspect.
	 * @return bool True when the path would escape the plugin root.
	 */
	private function is_escaping_root( string $path ): bool {
		if ( \strpos( $path, "\0" ) !== false ) {
			return true;
		}

		foreach ( \preg_split( '#[/\\\\]#', $path ) as $segment ) {
			if ( $segment === '..' ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Determine whether an existing absolute path resolves outside the plugin root.
	 *
	 * Complements the lexical check by resolving symlinks: a link inside the plugin
	 * that points elsewhere is caught here. A path that does not exist yet cannot be
	 * resolved and is treated as contained, since the lexical check already covered
	 * its textual form.
	 *
	 * Note: the `$root === false` branch below is defensive and marked uncoverable.
	 * An existing `$real` located under the plugin already implies the plugin base
	 * itself resolves, so that branch only guards the edge case of a plugin whose
	 * base directory vanished mid-request (e.g. deleted from disk while running).
	 *
	 * @param string $full_path The absolute candidate path.
	 * @return bool True when the resolved path lies outside the plugin root.
	 */
	private function is_resolved_escaping_root( string $full_path ): bool {
		$real = \realpath( $full_path );
		if ( $real === false ) {
			// Does not exist yet: nothing to resolve, lexical check already applied.
			return false;
		}

		$root = $this->get_real_base_dir();
		// @codeCoverageIgnoreStart
		if ( $root === false ) {
			return false;
		}
		// @codeCoverageIgnoreEnd

		return $real !== $root && \strpos( $real, $root . DIRECTORY_SEPARATOR ) !== 0;
	}

	/**
	 * Resolve and cache the real (symlink-resolved) plugin base directory.
	 *
	 * Note: the `false` branch below is defensive and marked uncoverable. The
	 * plugin base directory always exists for a plugin that WordPress has
	 * successfully loaded, so `realpath()` failing here would indicate the
	 * directory was removed from disk after the plugin was already running.
	 *
	 * @return string|false The resolved base directory, or false if it cannot be resolved.
	 */
	private function get_real_base_dir() {
		if ( $this->real_base_dir === null ) {
			$resolved = \realpath( $this->get_plugin_base_dir() );
			// @codeCoverageIgnoreStart
			if ( $resolved === false ) {
				return false;
			}
			// @codeCoverageIgnoreEnd
			$this->real_base_dir = $resolved;
		}

		return $this->real_base_dir;
	}

	/**
	 * Get the plugin directory path.
	 *
	 * Retrieves and caches the plugin directory path without trailing slash.
	 *
	 * @return string The plugin directory path.
	 */
	private function get_plugin_base_dir(): string {
		if ( $this->plugin_dir === null ) {
			// Cache this immutable value because many modules request it per request.
			$this->plugin_dir = \untrailingslashit(
				\plugin_dir_path( $this->get_plugin()->get_entry_file() )
			);
		}

		return $this->plugin_dir;
	}

	/**
	 * Get the plugin URL.
	 *
	 * Retrieves and caches the plugin URL without trailing slash.
	 *
	 * @return string The plugin URL.
	 */
	private function get_plugin_base_url(): string {
		if ( $this->url === null ) {
			$this->url = \untrailingslashit(
				\plugin_dir_url( $this->get_plugin()->get_entry_file() )
			);
		}

		return $this->url;
	}
}
