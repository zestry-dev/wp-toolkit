<?php

/**
 * Core API: Module base class
 */

declare( strict_types=1 );

namespace Zestry\WPToolkit\Kernel\Abstracts;

// Loaded by WordPress, never requested directly.
\defined( 'ABSPATH' ) || exit;

use Zestry\WPToolkit\Kernel\Contracts\Bootable;
use Zestry\WPToolkit\Kernel\Contracts\PluginAware;
use Zestry\WPToolkit\Kernel\Traits\WithPlugin;

/**
 * Base class for everything a plugin is made of.
 *
 * One kind of thing, listed in `bootstrap.php`. `Path` resolves paths, `Ajax`
 * binds hooks, `Options` does both -- all three are modules, built by the plugin
 * and reached the same way.
 *
 * **Listing it in `bootstrap.php` is what makes it exist.** Nothing else builds
 * a module, and asking for one that is not listed throws rather than quietly
 * constructing it -- so that file is the whole inventory of what a plugin is
 * made of, and reading it tells you what the plugin has.
 *
 * ```
 * $path = $this->with( Path::class );
 * ```
 *
 * {@see \Zestry\WPToolkit\Kernel\Traits\WithPlugin::with()} is how a module reaches another,
 * and how a discovered file reaches any of them. There is nothing to construct
 * and nothing to declare in advance.
 *
 * **Implement {@see Bootable} to do something without being called.** That is
 * the only difference between one module and another, and it is on the line that
 * names the class: a `Bootable` module binds hooks, registers a post type or
 * walks a directory when the plugin builds it, and one that is not sits there
 * until something calls it.
 *
 * **Your class may not declare a constructor.** `__construct()` is `final` here
 * and takes no arguments, so every module is built as `new YourModule()`.
 * Configuration comes from the `configure` in its `bootstrap.php` entry, and
 * dependencies from `with()`. A class that genuinely needs constructor arguments
 * is a value object rather than a module: write it as a plain class, and if it
 * also needs the plugin, have it `use WithPlugin` and pass it through
 * `$plugin->wire( $object )`.
 *
 * @example One that only works when called
 * No {@see Bootable}, so nothing happens until something calls it.
 *
 * ```
 * namespace Acme\Plugin\Modules;
 *
 * use Acme\Plugin\Core\Kernel\Abstracts\Module;
 * use Acme\Plugin\Core\Modules\Path;
 *
 * class Cache extends Module {
 *
 *     public function remember( string $key, callable $compute ): mixed {
 *         $file = $this->with( Path::class )->get_plugin_path( 'cache/' . $key );
 *
 *         // ...
 *     }
 * }
 * ```
 *
 * ```
 * // bootstrap.php
 * return array(
 *     Cache::class,
 * );
 * ```
 *
 * @example One that acts on its own
 * `on_boot()` runs once, when the plugin builds the module -- which is what
 * being listed causes.
 *
 * ```
 * use Acme\Plugin\Core\Kernel\Abstracts\Module;
 * use Acme\Plugin\Core\Kernel\Contracts\Bootable;
 *
 * class Shortcode extends Module implements Bootable {
 *
 *     public function on_boot(): void {
 *         add_shortcode( 'acme_form', array( $this, 'render' ) );
 *     }
 * }
 * ```
 *
 * @example One that takes configuration
 * A configured entry is an array, whose `configure` runs after the module is
 * built and before `on_boot()` -- so `on_boot()` can rely on whatever it set.
 * A module needing no configuration stays bare, as `CLI::class` does here.
 *
 * ```
 * // bootstrap.php
 * return array(
 *     Cron::class => array(
 *         'configure' => static function ( Cron $cron ): void {
 *             $cron->add_custom_interval( 'every_15_minutes', 900, 'Every 15 Minutes' );
 *         },
 *     ),
 *     CLI::class,
 * );
 * ```
 *
 * @example Doing something on `init`
 * Almost everything WordPress wants registered -- a post type, a block, a
 * taxonomy -- has to be registered on `init`, and a module can be built on
 * either side of it: an entry file that runs the plugin as it loads is ahead of
 * `init`, one that runs it from a later hook is behind. {@see on_wp_init()}
 * behaves the same either way, so a module never has to care which, and a plain
 * `add_action( 'init', ... )` would silently never run in the second case.
 *
 * It is also the answer to `_load_textdomain_just_in_time`: a `__()` at plugin
 * load asks WordPress for translations before it is ready to give them.
 *
 * ```
 * public function on_boot(): void {
 *     $this->on_wp_init( function ( self $module ): void {
 *         register_post_type( 'acme_report', array(
 *             'label' => __( 'Reports', 'acme-plugin' ),
 *         ) );
 *     } );
 * }
 * ```
 */
abstract class Module implements PluginAware {

	use WithPlugin;

	/**
	 * Build with no arguments, and stop a subclass declaring a constructor.
	 *
	 * The repository always constructs with no arguments and assigns the plugin
	 * afterwards. `final` is what holds that: a subclass declaring its own
	 * constructor is a fatal error, so none can take constructor arguments or
	 * run setup before the plugin is there. Anything that needs to run after
	 * that goes in the `configure` from its `bootstrap.php` entry, or -- if it
	 * should run without being asked -- in a {@see Bootable} `on_boot()`.
	 *
	 * @return void
	 */
	// @codeCoverageIgnoreStart
	final public function __construct() {
	}
	// @codeCoverageIgnoreEnd

	/**
	 * Run a callback on `init`, or immediately if `init` has already fired.
	 *
	 * Almost everything a module registers -- a post type, a block, a WP-CLI
	 * command -- has to happen on `init`, and a plain `add_action( 'init', ... )`
	 * is a callback that never runs once `init` has passed. A module can be
	 * built on either side of it: {@see \Zestry\WPToolkit\Kernel\Plugin::run()} is
	 * synchronous, so an entry file that calls it at plugin load is ahead of
	 * `init`, while one that calls it from a later hook is behind. This behaves
	 * the same either way, so a module never has to care which.
	 *
	 * The callback receives the module, so a closure declared elsewhere needs no
	 * `use` to reach it:
	 *
	 * ```
	 * public function on_boot(): void {
	 *     $this->on_wp_init( function ( self $module ): void {
	 *         $module->register_widgets();
	 *     } );
	 * }
	 * ```
	 *
	 * `$priority` is WordPress's own, for ordering against something else on
	 * `init` -- another plugin's registration, or a post type a taxonomy of
	 * yours attaches to. **It applies only while `init` is still ahead**: a
	 * module built after `init` has fired runs its callback immediately, in
	 * registration order, whatever priority it asked for. Ordering that has to
	 * hold either way belongs inside one callback.
	 *
	 * @param callable(static $module): void $callback What to run.
	 * @param int                            $priority WordPress hook priority, honoured only while `init` is still ahead.
	 * @return void
	 */
	final public function on_wp_init( callable $callback, int $priority = 10 ): void {
		/*
		 * Wrapped rather than hooked directly: `do_action( 'init' )` passes no
		 * arguments, which WordPress turns into a single empty string for a
		 * callback accepting one -- so a callback expecting the module would get
		 * `''` instead. The wrapper also keeps both branches calling it the same
		 * way.
		 */
		$run = function () use ( $callback ): void {
			$callback( $this );
		};

		if ( \did_action( 'init' ) ) {
			// Nothing to order against: the hook has been and gone, so running it
			// now is the only thing left that runs it at all.
			$run();

			return;
		}

		\add_action( 'init', $run, $priority );
	}
}
