<?php

/**
 * DevTools bootstrap.
 *
 * Required directly by `devtool-autoload.php` (never by Composer's own
 * `autoload.files`, which would collide across every plugin installing this
 * package — see that file for why), this builds a second, independent
 * Plugin instance — separate from whatever Plugin the *consuming* project
 * builds for its own runtime — under the slug `zt`, so its commands are
 * discovered under the {@see \Zestry\WPToolkit\Modules\CLI\CLI} module's existing
 * file-based convention and register as `wp zt <command>` (the CLI module
 * always names commands after the plugin slug, so no separate prefix
 * configuration is needed).
 *
 * This file's own directory is this package's root — the same directory
 * containing `src/`, `resources/`, and `composer.json` — so a Path module
 * resolved against it (via Plugin's entry-file convention) naturally
 * resolves paths relative to the package itself, not the consuming project.
 *
 * The modules are declared here rather than in a `bootstrap.php` of this
 * package's own: this file is required from an autoloader, so it runs before a
 * consuming plugin's own entry file, and a `bootstrap.php` sitting in this
 * package's root would be one more file to keep in step with the list a
 * `wp zt` command actually needs.
 */

declare( strict_types=1 );

use Zestry\WPToolkit\DevTools\AgentInstructions;
use Zestry\WPToolkit\DevTools\BootstrapFile;
use Zestry\WPToolkit\DevTools\ConsumerPlugin;
use Zestry\WPToolkit\DevTools\Copier;
use Zestry\WPToolkit\DevTools\Formatter;
use Zestry\WPToolkit\DevTools\GitIgnore;
use Zestry\WPToolkit\DevTools\Manifest;
use Zestry\WPToolkit\DevTools\ParentClass;
use Zestry\WPToolkit\DevTools\RuntimePlugin;
use Zestry\WPToolkit\DevTools\StubRenderer;
use Zestry\WPToolkit\DevTools\Tooling;
use Zestry\WPToolkit\DevTools\ZestryConfig;
use Zestry\WPToolkit\Kernel\Plugin;
use Zestry\WPToolkit\Modules\CLI\CLI;
use Zestry\WPToolkit\Modules\Path;

if ( ! function_exists( 'zestry_devtool' ) ) {

	/**
	 * Get (and lazily build) the DevTools plugin instance.
	 *
	 * @return Plugin
	 */
	function zestry_devtool(): Plugin {
		static $plugin = null;

		if ( null === $plugin ) {
			$plugin = ( new Plugin( __FILE__, 'zt' ) )
				->declare_multiple(
					array(
						Path::class,
						AgentInstructions::class,
						BootstrapFile::class,
						ConsumerPlugin::class,
						Copier::class,
						Formatter::class,
						GitIgnore::class,
						Manifest::class,
						ParentClass::class,
						RuntimePlugin::class,
						StubRenderer::class,
						Tooling::class,
						ZestryConfig::class,

						// On `init`, like every plugin CLI is added to: this file
						// is required from an autoloader, long before WP-CLI is
						// ready to be given commands.
						'init' => array(
							CLI::class,
						),
					)
				)
				->run();
		}

		return $plugin;
	}

	zestry_devtool();
}
