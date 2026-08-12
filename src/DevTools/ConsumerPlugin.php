<?php

/**
 * DevTools: consuming-plugin path resolution
 */

declare( strict_types=1 );

namespace Zestry\WPToolkit\DevTools;

// Loaded by WordPress, never requested directly.
\defined( 'ABSPATH' ) || exit;

use Zestry\WPToolkit\Kernel\Abstracts\Service;

/**
 * Locates the plugin `wp zt` should operate on.
 *
 * `wp zt init`/`wp zt add` run inside the DevTools plugin's own,
 * independent Plugin instance (see devtool.php), whose own install location
 * is this package's directory — not necessarily the plugin the developer is
 * actually working in. This resolves the intended target from the current
 * working directory WP-CLI was invoked from instead: a developer runs
 * `wp zt init`/`add` from inside (or below) the plugin they are working on,
 * exactly as they would run `composer require` there, so the nearest
 * ancestor directory of the CWD that sits directly under `WP_PLUGIN_DIR` is
 * the plugin to target.
 *
 * This is also what makes `wp zt` work correctly when more than one active
 * plugin has installed `wp-toolkit`: {@see devtool-autoload.php} already
 * resolves and requires the specific `devtool.php` belonging to whichever
 * plugin the CWD is inside, so this class only ever runs in the context of
 * that one plugin's own DevTools instance to begin with.
 */
class ConsumerPlugin extends Service {

	/**
	 * Resolve the absolute root directory of the plugin to operate on.
	 *
	 * @return string Absolute path to the target plugin's root directory.
	 * @throws \RuntimeException When the current working directory is not inside WP_PLUGIN_DIR.
	 */
	public function get_plugin_root(): string {
		return $this->get_plugin_root_for( (string) \getcwd() );
	}

	/**
	 * Resolve the absolute root directory of the plugin a given path is inside.
	 *
	 * Split out from get_plugin_root() so the CWD-dependent behavior itself
	 * stays a one-line seam, testable by passing an arbitrary path rather
	 * than needing to chdir() the whole test process.
	 *
	 * @param string $cwd The directory to resolve a plugin root from.
	 * @return string Absolute path to the target plugin's root directory.
	 * @throws \RuntimeException When $cwd is not inside WP_PLUGIN_DIR.
	 */
	public function get_plugin_root_for( string $cwd ): string {
		$cwd         = \wp_normalize_path( $cwd );
		$plugins_dir = \trailingslashit( \wp_normalize_path( WP_PLUGIN_DIR ) );

		$relative      = \str_starts_with( \trailingslashit( $cwd ), $plugins_dir )
			? \substr( \trailingslashit( $cwd ), \strlen( $plugins_dir ) )
			: '';
		$plugin_folder = \explode( '/', $relative )[0];

		// Either $cwd sits outside the plugins directory entirely, or it is
		// the plugins directory itself (empty $relative) -- neither is
		// inside any one plugin, so there is nothing valid to resolve.
		if ( '' === $plugin_folder ) {
			throw new \RuntimeException(
				\sprintf(
					'The current directory ("%s") is not inside a plugin directory under "%s". Run `wp zt` from inside the plugin you want to set up.',
					$cwd,
					$plugins_dir
				)
			);
		}

		return $plugins_dir . $plugin_folder;
	}
}
