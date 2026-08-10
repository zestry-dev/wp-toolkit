<?php

/**
 * Core API: Module base class
 */

declare( strict_types=1 );

namespace Zestry\WPToolkit\Kernel\Abstracts;

// Loaded by WordPress, never requested directly.
\defined( 'ABSPATH' ) || exit;

/**
 * Base class for something that acts on its own.
 *
 * A module does something without being called: it binds a hook, registers a
 * post type, walks a directory, schedules a job. That is the whole distinction
 * from {@see Service}, which sits there until something asks it for something.
 *
 * Because it acts on its own, it has to be built for that to happen -- so every
 * module is listed in `bootstrap.php`, and the plugin resolves
 * each one as it loads. {@see on_boot()} then runs, once, and is where the
 * acting-on-its-own goes. Nothing has to be said about *when*: being a module is
 * the declaration.
 *
 * `Options` is the case worth understanding. It is something you call --
 * `$options->get( 'key' )` -- which makes it look like a service. But it also
 * loads its persisted values and binds `shutdown` to flush deferred writes,
 * without being asked. That is acting on its own, so it is a module.
 *
 * Everything {@see Service} says about construction applies here too: your
 * class may not declare a constructor, since `__construct()` is `final` and
 * takes no arguments. Dependencies arrive as injected typed properties, and
 * configuration from the initializer in `bootstrap.php`.
 *
 * @example A module
 * `on_boot()` is abstract, so a module cannot be written without saying what it
 * does at boot. Listing it in `bootstrap.php` is what builds it.
 *
 * ```
 * namespace Acme\Plugin\Modules;
 *
 * use Acme\Plugin\Core\Kernel\Abstracts\Module;
 *
 * class Shortcode extends Module {
 *
 *     protected function on_boot(): void {
 *         add_shortcode( 'acme_form', array( $this, 'render' ) );
 *     }
 * }
 * ```
 *
 * ```
 * // bootstrap.php
 * return array(
 *     Shortcode::class,
 * );
 * ```
 *
 * @example One that takes configuration
 * The entry's value is the initializer, which runs after wiring and before
 * `on_boot()` -- so `on_boot()` can rely on whatever it set.
 *
 * ```
 * // bootstrap.php
 * return array(
 *     Ajax::class => static function ( Ajax $ajax ): void {
 *         $ajax->set_actions_root( 'actions' );
 *     },
 *     CLI::class,
 * );
 * ```
 *
 * @example Doing something on `init`
 * Almost everything WordPress wants registered -- a post type, a block, a
 * taxonomy -- has to be registered on `init`, and a module can be built on
 * either side of it: an entry file that runs the plugin as it loads is ahead of
 * `init`, one that runs it from a later hook is behind. {@see run_at_init()}
 * behaves the same either way, so a module never has to care which, and a plain
 * `add_action( 'init', ... )` would silently never run in the second case.
 *
 * It is also the answer to `_load_textdomain_just_in_time`: a `__()` at plugin
 * load asks WordPress for translations before it is ready to give them.
 *
 * ```
 * protected function on_boot(): void {
 *     $this->run_at_init( function ( self $module ): void {
 *         register_post_type( 'acme_report', array(
 *             'label' => __( 'Reports', 'acme-plugin' ),
 *         ) );
 *     } );
 * }
 * ```
 */
abstract class Module extends Service {

	/**
	 * Whether boot() has already run for this instance.
	 *
	 * @var bool
	 */
	private bool $booted = false;

	/**
	 * Run the module's one-time setup.
	 *
	 * Guarded and idempotent, because a module can reach here by more than one
	 * path in the same request: an initializer may boot it explicitly to pass
	 * custom configuration, and the repository boots any module it resolves.
	 * Without the guard, hooks would bind twice, files would be discovered
	 * twice, or persisted state would load twice -- silently, since both callers
	 * believe they are the first. Guarding it lets either call freely without
	 * coordinating with the other.
	 *
	 * @return void
	 *
	 * @internal
	 */
	final public function boot(): void {
		if ( $this->booted ) {
			return;
		}

		$this->booted = true;

		$this->on_boot();
	}

	/**
	 * Whether boot() has already run for this instance.
	 *
	 * @return bool
	 *
	 *  @internal
	 */
	final public function is_booted(): bool {
		return $this->booted;
	}

	/**
	 * Run a callback on `init`, or immediately if `init` has already fired.
	 *
	 * Almost everything a module registers -- a post type, a block, a WP-CLI
	 * command -- has to happen on `init`, and a plain `add_action( 'init', ... )`
	 * is a callback that never runs once `init` has passed. A module can be
	 * resolved on either side of it: {@see \Zestry\WPToolkit\Kernel\Plugin::run()} is
	 * synchronous, so an entry file that calls it at plugin load is ahead of
	 * `init`, while one that calls it from a later hook -- or a `get()` during a
	 * request -- is behind. This behaves the same either way, so a module never
	 * has to care which.
	 *
	 * The callback receives the module, matching the initializer signature, so a
	 * closure declared elsewhere needs no `use` to reach it:
	 *
	 *     protected function on_boot(): void {
	 *         $this->run_at_init( function ( self $module ): void {
	 *             $module->register_widgets();
	 *         } );
	 *     }
	 *
	 * @param callable(static $module): void $callback What to run.
	 * @return void
	 */
	final public function run_at_init( callable $callback ): void {
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
			$run();

			return;
		}

		\add_action( 'init', $run );
	}

	/**
	 * What this module does on its own.
	 *
	 * Runs once, when the plugin builds the module. Abstract rather than
	 * optional: a module with nothing to do here is a {@see Service}.
	 *
	 * **Bind hooks here; do the work in them.** An entry file that calls `run()`
	 * as it loads -- which is the documented shape, and what
	 * {@see \Zestry\WPToolkit\Kernel\Abstracts\ActivationHandler} requires -- reaches this
	 * before WordPress has required `pluggable.php`, so there is no current user
	 * yet: `current_user_can()`, `wp_mail()` and the nonce functions are not
	 * defined and calling one is a fatal. It is also before `init`, so `__()` here
	 * asks for a text domain nothing has loaded. `$wpdb` *is* up, so a query works
	 * -- but it runs on every request, including the ones that never needed it.
	 *
	 * {@see run_at_init()} is the way out of all three, and where anything a
	 * module registers belongs.
	 *
	 * @return void
	 */
	abstract protected function on_boot(): void;
}
