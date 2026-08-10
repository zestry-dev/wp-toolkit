<?php

/**
 * DevTools bootstrap.
 *
 * Required directly by `devtool-autoload.php` (never by Composer's own
 * `autoload.files`, which would collide across every plugin installing this
 * package — see that file for why), this builds a second, independent
 * Plugin instance — separate from whatever Plugin the *consuming* project
 * builds for its own runtime — under the slug `zestry`, so its commands are
 * discovered under the {@see \Zestry\WPToolkit\Modules\CLI\CLI} module's existing
 * file-based convention and register as `wp zestry <command>` (the CLI module
 * always names commands after the plugin slug, so no separate prefix
 * configuration is needed).
 *
 * This file's own directory is this package's root — the same directory
 * containing `src/`, `commands/`, and `composer.json` — so a Path module
 * resolved against it (via Plugin's entry-file convention) naturally
 * resolves paths relative to the package itself, not the consuming project.
 */

declare( strict_types=1 );

use Zestry\WPToolkit\Kernel\Plugin;
use Zestry\WPToolkit\Modules\CLI\CLI;

if ( ! function_exists( 'zestry_devtool' ) ) {

	/**
	 * Get (and lazily build) the DevTools plugin instance.
	 *
	 * @return Plugin
	 */
	function zestry_devtool(): Plugin {
		static $plugin = null;

		if ( null === $plugin ) {
			$plugin = ( new Plugin( __FILE__, 'zestry' ) )
				->configure(
					CLI::class,
					function ( CLI $cli ) {
						$cli->set_commands_root( 'commands' );
					}
				)
				->autoload( array( CLI::class ) )
				->run();
		}

		return $plugin;
	}

	zestry_devtool();
}
