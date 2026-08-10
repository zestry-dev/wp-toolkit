<?php

/**
 * Options API: Options module
 */

declare( strict_types=1 );

namespace Zestry\WPToolkit\Modules;

// Loaded by WordPress, never requested directly.
\defined( 'ABSPATH' ) || exit;

use Zestry\WPToolkit\Kernel\Abstracts\Module;

/**
 * Persists plugin configuration in the WordPress options table.
 *
 * The whole plugin shares one `wp_options` row, so adding a setting costs no
 * extra database row and no extra query.
 *
 * Writes are deferred: `set()` only marks the value dirty, and everything is
 * persisted once on `shutdown`. Call `save()` to force an early write before a
 * redirect or a long-running task.
 *
 * @example Reading and writing settings
 *
 * ```
 * $options = $plugin->get( Options::class );
 *
 * $options->set( 'api_key', $key );
 *
 * $key     = $options->get( 'api_key' );
 * $timeout = $options->get( 'timeout', 15 );  // with fallback
 *
 * if ( $options->has( 'api_key' ) ) { ... }
 * ```
 *
 * @example Isolating settings in a group
 * `group( 'api' )` returns a separate instance backed by its own option row,
 * for settings worth isolating from the plugin's main blob.
 *
 * ```
 * $api = $options->group( 'api' );
 * $api->set( 'endpoint', 'https://example.test' );
 * ```
 *
 * @setup
 * Every group defaults to autoload disabled. To autoload a specific group,
 * declare it by name via `add_autoloaded_groups()` — a static, per-request
 * registry `save()` consults live at write time, so it can be declared from
 * more than one place (a module declaring its own group, a consumer's own
 * `configure( Options::class, ... )` declaring further groups of its
 * own) without either caller needing to know about the other's list.
 *
 * ```
 * // bootstrap.php
 * return array(
 *     Options::class => static function ( Options $options ): void {
 *         $options->add_autoloaded_groups( array( 'my_frequently_read_group' ) );
 *
 *         // Or, for the default (ungrouped) instance's own option:
 *         $options->autoload_default_group();
 *     },
 * );
 * ```
 */
class Options extends Module {

	/**
	 * Group name the default (ungrouped) instance uses.
	 *
	 * Surrounded by underscores so it can never collide with a user-defined
	 * group literally named `options`; stores under `{slug}__options_`.
	 */
	const DEFAULT_GROUP_NAME = '_options_';

	/**
	 * Group names that autoload, declared via `add_autoloaded_groups()`.
	 *
	 * Deliberately `static`, not per-instance: `group()` is only ever called
	 * on the plugin's default (ungrouped) Options instance, but a `static`
	 * registry means every instance's `save()` consults the exact same list
	 * regardless of which Options instance either runs on — a plain instance
	 * property would only be visible through that exact object. Read live by
	 * `save()` at write time rather than snapshotted into an instance property
	 * at creation, so a name added to the registry later still takes effect on
	 * that group's very next save.
	 *
	 * @var string[]
	 */
	private static array $autoloaded_groups = array();

	/**
	 * Configuration values.
	 *
	 * @var array<string, mixed>
	 */
	private array $value = array();

	/**
	 * Group namespace. Defaults to `self::DEFAULT_GROUP_NAME`.
	 *
	 * @var string
	 */
	private string $group_name = self::DEFAULT_GROUP_NAME;

	/**
	 * Cached group instances indexed by group name.
	 *
	 * @var array<string, Options>
	 */
	private array $groups_instances = array();

	/**
	 * Whether configuration has been modified.
	 *
	 * @var bool
	 */
	private bool $is_dirty = false;

	/**
	 * Set a configuration value.
	 *
	 * Marks the group dirty, so the change is written at shutdown.
	 *
	 * @param string $key   The configuration key.
	 * @param mixed  $value The value to store.
	 * @return void
	 */
	public function set( string $key, $value ): void {
		$this->value[ $key ] = $value;
		$this->is_dirty      = true;
	}

	/**
	 * Get a configuration value.
	 *
	 * @param string $key      The configuration key.
	 * @param mixed  $fallback Returned when the key is not present.
	 * @return mixed The stored value, or `$fallback`.
	 */
	public function get( string $key, $fallback = null ): mixed {
		return \array_key_exists( $key, $this->value ) ? $this->value[ $key ] : $fallback;
	}

	/**
	 * Check whether a key is present.
	 *
	 * Uses `array_key_exists()` rather than `isset()`, so a key stored as `null`
	 * reports `true` instead of being indistinguishable from one never set.
	 *
	 * @param string $key The configuration key.
	 * @return bool True when the key exists, whatever its value.
	 */
	public function has( string $key ): bool {
		return \array_key_exists( $key, $this->value );
	}

	/**
	 * Remove a key.
	 *
	 * Removing something that was never there is not an error. Like {@see set()},
	 * this is written at shutdown rather than immediately.
	 *
	 * @param string $key The configuration key.
	 * @return void
	 */
	public function delete( string $key ): void {
		if ( ! \array_key_exists( $key, $this->value ) ) {
			return;
		}

		unset( $this->value[ $key ] );
		$this->is_dirty = true;
	}

	/**
	 * Set the group namespace before the instance boots.
	 *
	 * Used by group() through the plugin's configurator so the correct option
	 * is loaded when boot() runs. Setting it after boot has no effect on the
	 * already-loaded values.
	 *
	 * @param string $group_name The namespace identifier.
	 * @return void
	 */
	public function set_group_name( string $group_name ): void {
		$this->group_name = $group_name;
	}

	/**
	 * Declare additional group names that autoload, for the whole plugin.
	 *
	 * Adds to the registry rather than replacing it, since more than one caller
	 * may need to declare a group autoloaded independently. A module can name a
	 * group of its own worth autoloading -- `Migrations` documents
	 * `Migrations::OPTIONS_GROUP_NAME` for that, leaving the call to you --
	 * while your own `configure( Options::class, ... )` declares further groups,
	 * neither call needing to know about the other's list. `save()` checks the
	 * registry live at write time (see there), so call order relative to
	 * `group()` does not matter -- only that a name is registered before the
	 * save it should apply to.
	 *
	 * ## Whether a group is worth autoloading
	 *
	 * Autoloading trades memory on *every* request for a query on the requests
	 * that read the group, so the question is what fraction of requests read it:
	 *
	 * - **Read on most requests** -- a setting the front end consults on every
	 *   page -- is worth autoloading. One row in the autoloaded bundle beats one
	 *   query per request.
	 * - **Read on a few** -- a setting only a form submission or an admin screen
	 *   looks at -- is not. It loads on every request including the ones that
	 *   never touch it, and a query on the rare request that does is cheaper.
	 * - **Large either way** is not, whatever reads it. The autoloaded bundle is
	 *   fetched and unserialized whole, so a big group taxes every request.
	 *
	 * Not autoloading costs nothing but a query when the group is first read, so
	 * the default is the safe answer and this is the deliberate exception.
	 *
	 * @param string[] $group_names Group names that should autoload.
	 * @return void
	 */
	public function add_autoloaded_groups( array $group_names ): void {
		self::$autoloaded_groups = \array_unique( \array_merge( self::$autoloaded_groups, $group_names ) );
	}

	/**
	 * Declare the default (ungrouped) instance's own option autoloaded.
	 *
	 * A thin convenience over `add_autoloaded_groups()` for the one group name
	 * that has no explicit `group()` call of its own — the plugin's default
	 * Options instance, storing under `{slug}__options_`. Equivalent to
	 * `add_autoloaded_groups( array( self::DEFAULT_GROUP_NAME ) )`.
	 *
	 * @return void
	 */
	public function autoload_default_group(): void {
		$this->add_autoloaded_groups( array( self::DEFAULT_GROUP_NAME ) );
	}

	/**
	 * Returns a separate configuration instance for the specified options group,
	 * allowing logical separation of configuration groups.
	 *
	 * @param string $group_name The namespace identifier.
	 * @return Options A separate Options instance backed by the group's own option row.
	 */
	public function group( string $group_name ): self {
		if ( isset( $this->groups_instances[ $group_name ] ) ) {
			// Reuse the group instance so it keeps its in-memory dirty state.
			return $this->groups_instances[ $group_name ];
		}

		// Configure the group name before the plugin boots the instance, so
		// on_boot() loads the group's option rather than the ungrouped one.
		$instance = $this->get_plugin()->make(
			self::class,
			function ( self $group ) use ( $group_name ) {
				$group->set_group_name( $group_name );
			}
		);

		$this->groups_instances[ $group_name ] = $instance;
		return $instance;
	}

	/**
	 * Persist pending changes for the current group to the database.
	 *
	 * Called automatically on `shutdown`. Call it directly to force an early
	 * write at a safe point — before a redirect, a long-running WP-CLI task, or
	 * `fastcgi_finish_request()` — where waiting for shutdown risks losing the
	 * changes. A group autoloads only if `add_autoloaded_groups()` named it.
	 *
	 * @throws \RuntimeException When the write fails for a value that is actually changing.
	 */
	public function save(): void {
		if ( ! $this->is_dirty ) {
			return;
		}

		$name     = $this->get_option_name();
		$autoload = \in_array( $this->group_name, self::$autoloaded_groups, true );

		// update_option() returns false both for a genuine failure AND for a
		// value identical to what is stored, so the no-op has to be recognized
		// before calling it -- otherwise a harmless re-save throws. See
		// rules.md; this regressed once already.
		if ( \get_option( $name, array() ) === $this->value ) {
			$this->is_dirty = false;
			return;
		}

		if ( ! \update_option( $name, $this->value, $autoload ) ) {
			// Leave is_dirty set so a later save() (the next shutdown, or an
			// explicit call) can retry persisting the change, and tell the
			// caller now rather than let the change vanish unnoticed.
			throw new \RuntimeException( \sprintf( 'Options::save() failed to persist option "%s".', $name ) );
		}

		$this->is_dirty = false;
	}

	/**
	 * Load the group's persisted values and schedule a deferred save.
	 *
	 * Saving on `shutdown` lets several calls to `set()` result in one database
	 * write. A group instance must be booted independently because it uses a
	 * separate option name.
	 *
	 * Nothing downstream of the `shutdown` hook can act on a thrown exception —
	 * WordPress does not catch it, so it would surface as an uncaught error
	 * during request teardown instead of the save failure it actually is. The
	 * callback catches it and logs instead, which is the most a shutdown-time
	 * failure can do; a caller that needs to know synchronously should call
	 * save() directly instead of relying on the deferred write.
	 *
	 * @return void
	 *
	 * @internal
	 */
	protected function on_boot(): void {
		// Load persisted values first, then consolidate all writes at shutdown.
		$this->db_retrieve();
		\add_action(
			'shutdown',
			function () {
				try {
					$this->save();
				} catch ( \RuntimeException $exception ) {
					$this->report_failed_save( $exception );
				}
			}
		);
	}

	/**
	 * Build the WordPress option name for the active group.
	 *
	 * Every instance is namespaced by group, so the ungrouped default stores under
	 * `{slug}__options_` and a group named `api` under `{slug}_api`, keeping each
	 * configuration array separate and the option name plugin-prefixed.
	 *
	 * @return string The option name.
	 */
	private function get_option_name(): string {
		// Joined with `_` rather than the hyphen a registered name takes: this is
		// a storage key, so its spelling is what already-written rows are found
		// by, and DEFAULT_GROUP_NAME (`_options_`) is a sentinel chosen so it
		// cannot be a group someone names themselves.
		return $this->get_plugin()->get_namespaced_name( $this->group_name, '_' );
	}

	/**
	 * Report a save that failed at shutdown, without depending on a logger.
	 *
	 * Announced on the plugin's `{slug}-log` action, which is where a Log
	 * module -- or a consumer's own handler -- picks it up. Nothing listening
	 * means the message still reaches `error_log()` rather than being lost,
	 * since a silently unsaved option is the worst outcome here.
	 *
	 * The action rather than the Log module itself: this module must keep
	 * working for a plugin that never added one, and a hook has no class or
	 * method signature to depend on.
	 *
	 * @param \RuntimeException $exception The failure save() threw.
	 * @return void
	 */
	private function report_failed_save( \RuntimeException $exception ): void {
		// Composed through the plugin, not through Log, so this works whether or
		// not the plugin added that module. Log listens on the same name.
		$hook    = $this->get_plugin()->get_namespaced_name( 'log' );
		$message = $exception->getMessage();

		if ( ! \has_action( $hook ) ) {
			\error_log( $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log

			return;
		}

		\do_action( $hook, 'error', $message, array( 'option' => $this->get_option_name() ) );
	}

	/**
	 * Retrieve the current group's option array, defaulting to an empty array.
	 *
	 * A missing or non-array option is treated as an empty configuration set, so
	 * a corrupted or externally overwritten option cannot break a read.
	 *
	 * @return void
	 */
	private function db_retrieve(): void {
		$stored      = \get_option( $this->get_option_name(), array() );
		$this->value = \is_array( $stored ) ? $stored : array();
	}
}
