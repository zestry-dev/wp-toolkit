<?php

/**
 * Options API: Options module
 */

declare( strict_types=1 );

namespace Zestry\WPToolkit\Modules;

// Loaded by WordPress, never requested directly.
\defined( 'ABSPATH' ) || exit;

use Zestry\WPToolkit\Kernel\Abstracts\Module;
use Zestry\WPToolkit\Kernel\Helpers\Arr;

/**
 * Persists plugin configuration in the WordPress options table.
 *
 * The whole plugin shares one `wp_options` row, so adding a setting costs no
 * extra database row and no extra query. The row is read the first time you
 * touch the group and not before, so a request that never asks for a setting
 * never queries for one.
 *
 * **Nothing is written until you call `save()`.** `set()` and `delete()` change
 * the copy in memory; `save()` is the only thing that reaches the database. Call
 * it once, at the point the work is finished and correct -- after a form has
 * validated, at the end of a migration step -- so a request that dies halfway
 * leaves the stored settings exactly as they were.
 *
 * A key is a dotted path, so a group holds structure rather than a flat list:
 * `set( 'mail.from.name', 'Acme' )` writes `['mail']['from']['name']`. A key
 * with a dot already in it still *reads* back, since `get()` and `has()` try the
 * whole string as a literal key before splitting it.
 *
 * @example Reading and writing settings
 * `save()` writes; without it nothing leaves memory.
 *
 * ```
 * $options = $plugin->get( Options::class );
 *
 * $options->set( 'api_key', $key );
 * $options->set( 'mail.from.name', 'Acme' );
 * $options->save();
 *
 * $key     = $options->get( 'api_key' );
 * $name    = $options->get( 'mail.from.name' );
 * $timeout = $options->get( 'timeout', 15 );  // with fallback
 *
 * if ( $options->has( 'api_key' ) ) { ... }
 * ```
 *
 * @example Isolating settings in a group
 * `group( 'api' )` returns a separate instance backed by its own option row,
 * for settings worth isolating from the plugin's main blob. Each group is saved
 * on its own.
 *
 * ```
 * $api = $options->group( 'api' );
 * $api->set( 'endpoint', 'https://example.test' );
 * $api->save();
 * ```
 *
 * @setup
 * **The plugin's own settings autoload; a group does not.** The default
 * (ungrouped) row is what a plugin reads on ordinary requests, so it is loaded
 * with the rest of WordPress's autoloaded options and costs no query. A `group()`
 * is the opposite by construction -- worth isolating means read by fewer
 * requests -- so it is written not-autoloaded and read on demand.
 *
 * Name a group that *is* read on most requests through `add_autoloaded_groups()`
 * — a static, per-request registry `save()` consults live at write time, so it
 * can be declared from more than one place (a module declaring its own group, a
 * consumer's own `configure( Options::class, ... )` declaring further groups)
 * without either caller needing to know about the other's list.
 *
 * ```
 * // bootstrap.php
 * return array(
 *     Options::class => static function ( Options $options ): void {
 *         $options->add_autoloaded_groups( array( 'my_frequently_read_group' ) );
 *     },
 * );
 * ```
 *
 * Both answers are written as WordPress's `auto-on`/`auto-off` rather than
 * `on`/`off`: this module is choosing on your behalf from where the settings
 * live, not stating a decision you made about this particular row, and the
 * `auto-` values are the ones core is allowed to reconsider under its own
 * autoloaded-size limits.
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
	 * Whether configuration has been modified since it was read.
	 *
	 * @var bool
	 */
	private bool $is_dirty = false;

	/**
	 * Whether this group's row has been read from the database yet.
	 *
	 * @var bool
	 */
	private bool $is_loaded = false;

	/**
	 * Set a configuration value.
	 *
	 * Changes the copy in memory and marks the group dirty. Nothing reaches the
	 * database until {@see save()}.
	 *
	 * `$key` is a dotted path, so `set( 'mail.from.name', 'Acme' )` writes
	 * `['mail']['from']['name']`. Unlike {@see get()}, a path is always split:
	 * there is no existing key to prefer, so the nesting you wrote is the
	 * nesting you get.
	 *
	 * @param string $key   The configuration key, or a dotted path.
	 * @param mixed  $value The value to store.
	 * @return void
	 */
	public function set( string $key, $value ): void {
		$this->load();

		Arr::set( $this->value, $key, $value );

		$this->is_dirty = true;
	}

	/**
	 * Get a configuration value.
	 *
	 * Reads the group's row on the first call and keeps it for the rest of the
	 * request.
	 *
	 * @param string $key      The configuration key, or a dotted path.
	 * @param mixed  $fallback Returned when the path does not resolve.
	 * @return mixed The stored value, or `$fallback`.
	 */
	public function get( string $key, $fallback = null ): mixed {
		$this->load();

		return Arr::get( $this->value, $key, $fallback );
	}

	/**
	 * Check whether a key is present.
	 *
	 * Distinct from `null !== get( ... )`: a key stored as `null` answers true
	 * here, so a setting deliberately set to nothing is not mistaken for one
	 * that was never set.
	 *
	 * @param string $key The configuration key, or a dotted path.
	 * @return bool True when the path resolves, whatever the value is.
	 */
	public function has( string $key ): bool {
		$this->load();

		return Arr::has( $this->value, $key );
	}

	/**
	 * Remove a key.
	 *
	 * Removing something that was never there is not an error, and leaves the
	 * group clean rather than queueing a write that would change nothing. Like
	 * {@see set()}, this only reaches the database through {@see save()}.
	 *
	 * @param string $key The configuration key, or a dotted path.
	 * @return void
	 */
	public function delete( string $key ): void {
		$this->load();

		if ( ! Arr::has( $this->value, $key ) ) {
			return;
		}

		Arr::forget( $this->value, $key );

		$this->is_dirty = true;
	}

	/**
	 * Set the group namespace before the group is first read.
	 *
	 * Used by {@see group()} through `make()`'s configurator, so the instance
	 * knows which option it is before anything asks it for a value. Setting it
	 * after a read has happened does not re-read: the values already in memory
	 * belong to the previous name, and {@see save()} would write them under the
	 * new one.
	 *
	 * @param string $group_name The namespace identifier.
	 * @return void
	 */
	public function set_group_name( string $group_name ): void {
		$this->group_name = $group_name;
	}

	/**
	 * Declare group names that autoload, for the whole plugin.
	 *
	 * Only groups: the default (ungrouped) row always autoloads, since it is the
	 * one a plugin reads on ordinary requests. A `group()` is the deliberate
	 * exception, so it does not autoload unless it is named here.
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
	 * it is the safe answer for a group and this is the deliberate exception.
	 *
	 * @param string[] $group_names Group names that should autoload.
	 * @return void
	 */
	public function add_autoloaded_groups( array $group_names ): void {
		self::$autoloaded_groups = \array_unique( \array_merge( self::$autoloaded_groups, $group_names ) );
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

		// Configure the group name as the instance is built, so the first read
		// loads the group's option rather than the ungrouped one.
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
	 * Write this group's pending changes to the database.
	 *
	 * **The only thing that writes.**
	 *
	 * Each group saves on its own; saving the default instance does not save a
	 * `group()` reached from it.
	 *
	 * Does nothing when there is nothing to write, so calling it on every path
	 * out of a handler costs at most one `get_option()` against the object cache.
	 *
	 * @throws \RuntimeException When the write fails for a value that is actually changing.
	 */
	public function save(): void {
		if ( ! $this->is_dirty ) {
			return;
		}

		$name = $this->get_option_name();

		// update_option() returns false both for a genuine failure AND for a
		// value identical to what is stored, so the no-op has to be recognized
		// before calling it -- otherwise a harmless re-save throws. See
		// rules.md; this regressed once already.
		if ( \get_option( $name, array() ) === $this->value ) {
			$this->is_dirty = false;
			return;
		}

		if ( ! $this->write( $name ) ) {
			// Leave is_dirty set so a later save() can retry persisting the
			// change, and tell the caller now rather than let it vanish unnoticed.
			throw new \RuntimeException( \sprintf( 'Options::save() failed to persist option "%s".', $name ) );
		}

		$this->is_dirty = false;
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
	 * Whether this group's row is written to autoload.
	 *
	 * The default (ungrouped) row always does: it holds the plugin's own
	 * settings, which is what a request reads if it reads anything. A `group()`
	 * exists to be read by fewer requests than that, so it does not unless
	 * {@see add_autoloaded_groups()} named it.
	 *
	 * @return bool
	 */
	private function autoloads(): bool {
		if ( self::DEFAULT_GROUP_NAME === $this->group_name ) {
			return true;
		}

		// Read live rather than snapshotted, so a name registered after this
		// instance was built still applies to its next save.
		return \in_array( $this->group_name, self::$autoloaded_groups, true );
	}

	/**
	 * Read this group's row, once per instance.
	 *
	 * Called by every accessor rather than at boot, so a request that never asks
	 * this group for anything never queries for it. A missing or non-array option
	 * is treated as an empty configuration set, so a row corrupted or overwritten
	 * from outside cannot break a read.
	 *
	 * The flag is set before the read, not after, so a `get_option()` filter that
	 * calls back into this group cannot recurse.
	 *
	 * @return void
	 */
	private function load(): void {
		if ( $this->is_loaded ) {
			return;
		}

		$this->is_loaded = true;

		$stored      = \get_option( $this->get_option_name(), array() );
		$this->value = \is_array( $stored ) ? $stored : array();
	}

	/**
	 * Hand the row to WordPress with the autoload value this module intends.
	 *
	 * `update_option()` does not accept `auto-on`/`auto-off` as arguments --
	 * {@see https://developer.wordpress.org/reference/functions/wp_determine_option_autoload_value/}
	 * maps only booleans and `on`/`off`/`yes`/`no`, and anything else falls
	 * through to plain `auto`. The `auto-` values are reachable only by passing
	 * no preference and answering the filter core consults instead, which is what
	 * this does, scoped to this one option name for the duration of the write.
	 *
	 * @param string $name The option name to write.
	 * @return bool Whether WordPress reported the write.
	 */
	private function write( string $name ): bool {
		$autoloads = $this->autoloads();

		$decide = static function ( $decided, string $option ) use ( $name, $autoloads ) {
			// Scoped to this option: the filter fires for every option core
			// writes, and answering for someone else's would move their row.
			return $option === $name ? $autoloads : $decided;
		};

		\add_filter( 'wp_default_autoload_value', $decide, 10, 2 );

		try {
			return \update_option( $name, $this->value, null );
		} finally {
			\remove_filter( 'wp_default_autoload_value', $decide, 10 );
		}
	}
}
