<?php

/**
 * Core API: ActivationHandler base class
 */

declare( strict_types=1 );

namespace Zestry\WPToolkit\Kernel\Abstracts;

// Loaded by WordPress, never requested directly.
\defined( 'ABSPATH' ) || exit;

use Zestry\WPToolkit\Kernel\Contracts\Bootable;

/**
 * Base class for plugin activation and deactivation lifecycle callbacks.
 *
 * Extend this for installation or cleanup work -- creating tables, seeding
 * options, flushing rewrite rules on activation, and their teardown on
 * deactivation -- rather than for ordinary per-request bootstrapping. It is a
 * {@see Module}, so it is declared in `bootstrap.php` like any
 * other, and `on_boot()` is already written: it registers your `activate()` and
 * `deactivate()` with WordPress.
 *
 * **The timing constraint is about `run()`, not about how you declare it.**
 * WordPress fires `activate_{plugin}` during the activation request, right after
 * the plugin file loads. `register_activation_hook()` has to have been called by
 * then, and WordPress does not re-fire a past action for a late subscriber -- so
 * a plugin whose entry file defers `run()` to `plugins_loaded` or `init` has
 * already missed the window, and `activate()` can never run for that request.
 *
 * There is no error to notice, so `on_boot()` detects the late boot and emits
 * `_doing_it_wrong()` rather than binding a hook that will silently never fire.
 * Deactivation is still registered, since that hook fires on a later request.
 *
 * The fix is always the same: call `run()` as the entry file loads.
 *
 * @example Writing one
 * `activate()` and `deactivate()` are both abstract, so neither can be
 * forgotten. Declare it like any other module.
 *
 * ```
 * namespace Acme\Plugin\Modules;
 *
 * use Acme\Plugin\Core\Kernel\Abstracts\ActivationHandler;
 * use Acme\Plugin\Core\Modules\Migrations\Migrations;
 *
 * class Activation extends ActivationHandler {
 *
 *     public function activate( bool $network_wide ): void {
 *         $this->with( Migrations::class )->run_pending();
 *         flush_rewrite_rules();
 *     }
 *
 *     public function deactivate( bool $network_wide ): void {
 *         wp_clear_scheduled_hook( 'acme-plugin-daily' );
 *         flush_rewrite_rules();
 *     }
 * }
 * ```
 *
 * ```
 * // bootstrap.php
 * return array(
 *     Activation::class,
 * );
 * ```
 *
 * @example Getting the timing right
 * The entry file has to run the plugin as it loads. This is the shape
 * `wp zt init` documents, and the only one that works for activation:
 *
 * ```
 * // acme-plugin.php
 * function acme_plugin(): Plugin {
 *     static $plugin = null;
 *
 *     $plugin ??= ( new Plugin( __FILE__ ) )->bootstrap()->run();
 *
 *     return $plugin;
 * }
 *
 * acme_plugin();   // <- runs now, ahead of activate_{plugin}
 *
 * // Deferring that call is what breaks it. By `plugins_loaded` the
 * // activate_{plugin} action has already fired, so activate() never runs and
 * // on_boot() reports it with _doing_it_wrong():
 * //
 * //     add_action( 'plugins_loaded', 'acme_plugin' );
 * ```
 *
 * @example Deactivation must not drop data
 * Deactivation runs whenever the plugin is switched off, and that includes
 * every update -- so anything it removes is removed from a site that is about
 * to carry on running. Undo what costs nothing to rebuild; leave anything a
 * user would miss. Deleting a plugin for good is a separate WordPress
 * lifecycle, and not this class's.
 *
 * ```
 * public function deactivate( bool $network_wide ): void {
 *     wp_clear_scheduled_hook( 'acme-plugin-daily' );   // yes
 *     // $wpdb->query( 'DROP TABLE ...' );              // no -- an update would wipe it
 * }
 * ```
 */
abstract class ActivationHandler extends Module implements Bootable {

	/**
	 * Run plugin activation tasks for one site.
	 *
	 * Always called with one site active, so it never has to think about
	 * networks: create your tables, seed your options, and this toolkit sees
	 * that it happens everywhere it should. On a network activation it runs once
	 * per existing site, and again for each site created afterwards.
	 *
	 * `$network_wide` is context, not instruction: it says the plugin was
	 * activated for the whole network. Use it to seed something once rather than
	 * per site. The per-site work is the same either way.
	 *
	 * @param bool $network_wide Whether the plugin was activated network-wide.
	 * @return void
	 */
	abstract public function activate( bool $network_wide ): void;

	/**
	 * Run plugin deactivation tasks for one site.
	 *
	 * Called under the same rules as {@see activate()}: once per site on a
	 * network deactivation, with that site active.
	 *
	 * @param bool $network_wide Whether the plugin was deactivated network-wide.
	 * @return void
	 */
	abstract public function deactivate( bool $network_wide ): void;

	/**
	 * Run activation for one site by ID.
	 *
	 * The escape hatch for a network too large to loop, and what a WP-CLI command
	 * would call to set a site up by hand. Switches into the site, runs
	 * {@see activate()}, and switches back.
	 *
	 * @param int $site_id The site to set up.
	 * @return void
	 */
	public function activate_site( int $site_id ): void {
		\switch_to_blog( $site_id );

		try {
			$this->activate( true );
		} finally {
			\restore_current_blog();
		}
	}

	/**
	 * Register this module's activation and deactivation callbacks.
	 *
	 * WordPress associates both callbacks with the entry file held by the
	 * plugin, ensuring the hooks run only for this plugin. If this runs after
	 * the plugin's `activate_{plugin}` hook has already fired, the activation
	 * callback cannot bind in time, so a developer warning is emitted instead of
	 * failing silently. Deactivation is still registered because that hook fires on
	 * a later request.
	 *
	 * `register_activation_hook()` binds the callback to the action named
	 * `'activate_' . plugin_basename( $entry_file )`. That action is a normal
	 * WordPress hook: if it has already fired by the time this method calls
	 * `register_activation_hook()`, the call still succeeds and returns nothing
	 * to indicate failure, but the callback is bound too late to ever run for
	 * this request — WordPress does not re-fire past actions for late
	 * subscribers. This is exactly why ActivationHandler subclasses must be resolved
	 * synchronously at plugin load, as described on the class: on_boot() must
	 * run before that action fires, or activation logic silently never executes.
	 *
	 * @return void
	 */
	public function on_boot(): void {
		$entry_file = $this->get_plugin()->get_entry_file();

		if ( \did_action( 'activate_' . \plugin_basename( $entry_file ) ) ) {
			$this->report_late_boot();
		} else {
			\register_activation_hook( $entry_file, $this->run_activation( ... ) );
		}

		\register_deactivation_hook( $entry_file, $this->run_deactivation( ... ) );

		// A site created later has to be set up too, or it is the only site on
		// the network without the plugin's tables. Late, so core has finished
		// populating the new site before anything is written into it.
		\add_action( 'wp_initialize_site', $this->initialize_new_site( ... ), 100, 1 );
	}

	/**
	 * Run activation for every site it applies to.
	 *
	 * WordPress fires the activation hook **once**, on whichever site the network
	 * admin happened to be on — so a network activation that just called
	 * `activate()` would set up one site and leave every other one without the
	 * plugin's tables. This loops instead.
	 *
	 * A network large enough for `wp_is_large_network()` is not looped: the
	 * request would time out part-way and leave the network half-configured,
	 * which is worse than not starting. Those sites are set up by
	 * {@see activate_site()}, on demand or from a command.
	 *
	 * @param bool $network_wide Whether the plugin was activated network-wide.
	 * @return void
	 *
	 * @internal
	 */
	protected function run_activation( bool $network_wide = false ): void {
		if ( ! $network_wide || ! \is_multisite() ) {
			$this->activate( $network_wide );

			return;
		}

		if ( \wp_is_large_network() ) {
			return;
		}

		foreach ( $this->get_network_site_ids() as $site_id ) {
			$this->activate_site( $site_id );
		}
	}

	/**
	 * Run deactivation for every site it applies to.
	 *
	 * @param bool $network_wide Whether the plugin was deactivated network-wide.
	 * @return void
	 *
	 * @internal
	 */
	protected function run_deactivation( bool $network_wide = false ): void {
		if ( ! $network_wide || ! \is_multisite() ) {
			$this->deactivate( $network_wide );

			return;
		}

		if ( \wp_is_large_network() ) {
			return;
		}

		foreach ( $this->get_network_site_ids() as $site_id ) {
			\switch_to_blog( $site_id );

			try {
				$this->deactivate( true );
			} finally {
				\restore_current_blog();
			}
		}
	}

	/**
	 * Set up a site created after the plugin was network-activated.
	 *
	 * @param \WP_Site $site The new site.
	 * @return void
	 *
	 * @internal
	 */
	protected function initialize_new_site( \WP_Site $site ): void {
		if ( ! $this->is_network_active() ) {
			return;
		}

		$this->activate_site( (int) $site->blog_id );
	}

	/**
	 * Whether this plugin is active across the whole network.
	 *
	 * @return bool
	 */
	protected function is_network_active(): bool {
		if ( ! \is_multisite() ) {
			return false;
		}

		if ( ! \function_exists( 'is_plugin_active_for_network' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		return \is_plugin_active_for_network( \plugin_basename( $this->get_plugin()->get_entry_file() ) );
	}

	/**
	 * Every site ID on the current network.
	 *
	 * @return int[]
	 */
	private function get_network_site_ids(): array {
		return \array_map(
			'intval',
			\get_sites(
				array(
					'fields'     => 'ids',
					'network_id' => \get_current_network_id(),
					'number'     => 0,
				)
			)
		);
	}

	/**
	 * Say that activation was missed, since nothing else will.
	 *
	 * `register_activation_hook()` reports nothing when it binds too late, so
	 * without this an entry file that defers `run()` looks like it worked.
	 *
	 * @return void
	 */
	private function report_late_boot(): void {
		\_doing_it_wrong(
			// Named for what ran late, not for the method reporting it.
			__CLASS__ . '::on_boot',
			\esc_html(
				\sprintf(
					// Deliberately not translated: this runs at plugin load,
					// before `init`, where a __() call would itself trigger
					// _load_textdomain_just_in_time.
					'%s was booted after the plugin activation hook already fired. Declare ActivationHandler subclasses in bootstrap.php and call run() as the entry file loads, so activate() can run.',
					static::class
				)
			),
			'1.0.0'
		);
	}
}
