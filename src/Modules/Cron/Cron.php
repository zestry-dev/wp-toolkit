<?php

/**
 * Cron API: Cron module
 */

declare( strict_types=1 );

namespace Zestry\WPToolkit\Modules\Cron;

// Loaded by WordPress, never requested directly.
\defined( 'ABSPATH' ) || exit;

use Zestry\WPToolkit\Kernel\Abstracts\Module;
use Zestry\WPToolkit\Kernel\Exceptions\DiscoveryException;
use Zestry\WPToolkit\Kernel\Traits\WithFolderWalker;
use Zestry\WPToolkit\Services\Path;

/**
 * Discovers plugin WP-Cron schedules and keeps them registered.
 *
 * A schedules directory contains PHP files, one per recurring event. Each
 * file returns a {@see Schedule} instance; a file named `cleanup-logs.php`
 * registers under the hook `{plugin-slug}-cleanup-logs` (see
 * {@see get_schedule_slug()}). The module binds that hook to the schedule's
 * `run()` on every request, since WP-Cron dispatches through a fresh request
 * with no memory of any other. It calls `wp_schedule_event()` only when the
 * event is not already scheduled, so re-running discovery on every `init`
 * never stacks duplicates.
 *
 * > [!WARNING]
 * > **WP-Cron has no background process.** An event fires only when some
 * > ordinary page load notices it is due. On a low-traffic site it may fire
 * > late, or never. For anything time-sensitive, disable the pseudo-cron with
 * > `define( 'DISABLE_WP_CRON', true );` and drive it from a real crontab
 * > running `wp cron event run --due-now`.
 *
 * `wp cron event list` and `wp cron schedule list` show what this module has
 * scheduled, under the hook names `get_schedule_slug()` produces. Both are
 * built into WP-CLI, so this module adds no commands of its own.
 *
 * Changing `recurrence()` in a later release does take effect: every request
 * compares the scheduled recurrence against what the method now returns and
 * re-schedules the event when they differ. `initial_run_at()` is read only
 * when an occurrence is actually created -- on first registration, and again
 * on the re-schedule a changed `recurrence()` forces. Change it on its own and
 * nothing moves on a site that already has the event.
 *
 * @setup
 * Register an initializer to point the module at a non-default directory, or
 * to declare a custom interval schedules can then ask for by name.
 *
 * ```
 * // bootstrap.php
 * return array(
 *     Cron::class => static function ( Cron $cron ): void {
 *         $cron->set_schedules_root( 'cron/schedules' );
 *         $cron->add_custom_interval( 'every_15_minutes', 15 * MINUTE_IN_SECONDS, 'Every 15 Minutes' );
 *     },
 * );
 * ```
 */
class Cron extends Module {

	use WithFolderWalker;

	/**
	 * Default plugin-relative directory of schedule files.
	 */
	const DEFAULT_SCHEDULES_ROOT = 'schedules';

	private const BUILTIN_INTERVALS = array( 'hourly', 'twicedaily', 'daily' );

	/**
	 * Path module injected by the plugin to resolve the schedules directory.
	 *
	 * @var Path
	 */
	public Path $path;

	/**
	 * Plugin-relative directory of schedule files.
	 *
	 * @var string
	 */
	private string $schedules_root = self::DEFAULT_SCHEDULES_ROOT;

	/**
	 * Whether the directory above was named deliberately.
	 *
	 * A missing directory means two different things. Named by
	 * {@see set_schedules_root()} and absent: a typo, and registering nothing
	 * silently would hide it. Never named, and the default is absent: this
	 * plugin has none of these yet, which is ordinary -- adding the module
	 * before writing the first file should not take the site down.
	 *
	 * @var bool
	 */
	private bool $schedules_root_was_set = false;

	/**
	 * Custom interval definitions registered via add_custom_interval(), keyed
	 * by their namespaced slug.
	 *
	 * @var array<string, array{interval: int, display: string}>
	 */
	private array $custom_intervals = array();

	/**
	 * Discovered schedules by local name, once the directory has been walked.
	 *
	 * Kept rather than rebuilt, so {@see get_slug_of()} compares against the
	 * same instances a caller is holding.
	 *
	 * @var array<string, Schedule>|null
	 */
	private ?array $discovered = null;

	/**
	 * Set the plugin-relative directory that contains schedule files.
	 *
	 * Call this from the module initializer before the plugin boots the module
	 * to override the default `schedules` directory.
	 *
	 * Naming a directory is what makes its absence fatal. Discovery runs at
	 * `init` on every request, and if the directory you name here is not there
	 * it throws a `DiscoveryException` then -- so a typo in your initializer
	 * takes the site down rather than scheduling nothing and leaving you to
	 * wonder why your events never fire. The *default* `schedules` directory
	 * being absent is deliberately not an error: a plugin that has not written
	 * its first schedule yet should still boot.
	 *
	 * @param string $schedules_root Plugin-relative directory of schedule files.
	 * @return void
	 * @throws DiscoveryException When the directory named here does not exist at boot, or a file beneath it returns something other than a Schedule instance.
	 */
	public function set_schedules_root( string $schedules_root ): void {
		// Anything already read came from the old directory.
		$this->discovered             = null;
		$this->schedules_root         = $schedules_root;
		$this->schedules_root_was_set = true;
	}

	/**
	 * Register a custom WP-Cron interval.
	 *
	 * WordPress has no built-in interval shorter than `'daily'` besides
	 * `'hourly'`/`'twicedaily'` — anything else must be added to the
	 * `cron_schedules` filter before `wp_schedule_event()` will accept it.
	 * Call this from the module initializer, then reference it from a
	 * schedule's `recurrence()` via {@see get_custom_interval_slug()}, so the
	 * name a schedule refers to can never drift out of sync with what was
	 * actually registered here.
	 *
	 * @param string $name    Local interval name, e.g. 'every_15_minutes'.
	 * @param int    $seconds Seconds between occurrences.
	 * @param string $display Human-readable label shown in wp-admin.
	 * @return void
	 */
	public function add_custom_interval( string $name, int $seconds, string $display ): void {
		$this->custom_intervals[ $this->get_custom_interval_slug( $name ) ] = array(
			'interval' => $seconds,
			'display'  => $display,
		);
	}

	/**
	 * Build the globally namespaced custom interval slug.
	 *
	 * @param string $name The local interval name.
	 * @return string The namespaced interval slug.
	 */
	public function get_custom_interval_slug( string $name ): string {
		return $this->get_plugin()->get_namespaced_name( $name );
	}

	/**
	 * Build the globally namespaced WordPress cron hook.
	 *
	 * @param string $name The local schedule name.
	 * @return string The namespaced hook name.
	 */
	public function get_schedule_slug( string $name ): string {
		return $this->get_plugin()->get_namespaced_name( $name );
	}

	/**
	 * Ensure a discovered schedule is scheduled with WP-Cron.
	 *
	 * Loads the schedule the same way discovery does, then applies the same
	 * already-scheduled/recurrence-drift check register_schedules() applies
	 * to every file it discovers — useful to re-arm a schedule that was
	 * previously cleared, without waiting for the next full discovery pass.
	 *
	 * @param string $name The local schedule name (its filename without `.php`).
	 * @return void
	 * @throws \InvalidArgumentException When no schedule file matches $name, or its recurrence is not registered.
	 * @throws DiscoveryException When the file returns something other than a Schedule instance.
	 */
	public function schedule( string $name ): void {
		$this->ensure_scheduled( $name, $this->load_schedule( $name ) );
	}

	/**
	 * Run a schedule's work immediately, bypassing WP-Cron entirely.
	 *
	 * Loads and wires the schedule the same way discovery does, then calls
	 * its run() synchronously in the current request — the same concurrency
	 * lock and error handling that a real cron dispatch gets still applies.
	 *
	 * @param string $name The local schedule name (its filename without `.php`).
	 * @return void
	 * @throws \InvalidArgumentException When no schedule file matches $name.
	 * @throws DiscoveryException When the file returns something other than a Schedule instance.
	 */
	public function run_now( string $name ): void {
		$this->run_schedule( $name, $this->load_schedule( $name ) );
	}

	/**
	 * Clear every discovered schedule's WP-Cron events.
	 *
	 * Exposed for a consuming plugin's own ActivationHandler subclass to call from
	 * deactivate() — Cron does not implement ActivationHandler itself, so nothing
	 * clears scheduled events automatically; a plugin that schedules events
	 * is responsible for unscheduling them.
	 *
	 * Clearing runs over the schedules discovery finds, so it fails the same
	 * way discovery does on a broken schedules directory.
	 *
	 * @return void
	 * @throws DiscoveryException When a schedules directory named by set_schedules_root() does not exist, or a file returns something other than a Schedule instance.
	 */
	public function unschedule_all(): void {
		foreach ( $this->get_discovered_schedules() as $name => $instance ) {
			\wp_clear_scheduled_hook( $this->get_schedule_slug( $name ) );
		}
	}

	/**
	 * Every event this plugin has scheduled that no schedule file registers.
	 *
	 * A schedule's hook is its filename -- `schedules/sync.php` is
	 * `{slug}-sync` -- so renaming the file schedules a new event and abandons
	 * the old one. Nothing cleans it up: booting only schedules what discovery
	 * finds, and {@see unschedule_all()} clears the same set, so an event whose
	 * file is gone is in neither list. WordPress keeps firing it, on time,
	 * forever, with nothing listening.
	 *
	 * Reporting rather than pruning, and never called automatically, for the
	 * reason `Migrations` never triggers itself: a `{slug}-` event this module
	 * did not create is indistinguishable from one it did. A plugin is free to
	 * `wp_schedule_event()` under its own prefix by hand, and deleting that
	 * because no file claims it would be this module destroying something it
	 * does not own. {@see unschedule_orphaned()} does the clearing, when a
	 * consumer decides.
	 *
	 * Every occurrence of a hook is one orphan, so a hook due several times
	 * reports once, at the first.
	 *
	 * @return array<string, int> Hook name => the timestamp it next fires, earliest first.
	 * @throws DiscoveryException When a schedules directory named by set_schedules_root() does not exist, or a file returns something other than a Schedule instance.
	 */
	public function get_orphaned_events(): array {
		$registered = array();

		foreach ( \array_keys( $this->get_discovered_schedules() ) as $name ) {
			$registered[ $this->get_schedule_slug( (string) $name ) ] = true;
		}

		$prefix   = $this->get_plugin()->get_namespaced_name( '' );
		$orphaned = array();

		foreach ( $this->get_cron_events() as $timestamp => $hooks ) {
			foreach ( \array_keys( $hooks ) as $hook ) {
				$hook = (string) $hook;

				// Separate checks: `isset( $a, $b )` is true only when both are,
				// so combining them skipped nothing a registered hook could hit.
				if ( ! \str_starts_with( $hook, $prefix ) || isset( $registered[ $hook ] ) || isset( $orphaned[ $hook ] ) ) {
					continue;
				}

				$orphaned[ $hook ] = (int) $timestamp;
			}
		}

		\asort( $orphaned );

		return $orphaned;
	}

	/**
	 * Clear every event {@see get_orphaned_events()} reports, and say which.
	 *
	 * A separate call, and never made by this module: a `{slug}-` event this
	 * module did not create is indistinguishable from one it did, so clearing
	 * automatically could delete an event you scheduled by hand. Run it from
	 * wherever you decide -- a deploy step, a reviewed admin action, an
	 * activation handler -- once you have looked at the list.
	 *
	 * @return string[] The hooks cleared, in the order they would next have fired.
	 * @throws DiscoveryException When a schedules directory named by set_schedules_root() does not exist, or a file returns something other than a Schedule instance.
	 */
	public function unschedule_orphaned(): array {
		$orphaned = \array_keys( $this->get_orphaned_events() );

		foreach ( $orphaned as $hook ) {
			\wp_clear_scheduled_hook( $hook );
		}

		return $orphaned;
	}

	/**
	 * Merge registered custom intervals into WordPress's cron schedules.
	 *
	 * @param array<string, array{interval: int, display: string}> $schedules WordPress's own registered intervals.
	 * @return array<string, array{interval: int, display: string}> The merged intervals.
	 *
	 * @internal
	 */
	public function filter_cron_schedules( array $schedules ): array {
		return \array_merge( $schedules, $this->custom_intervals );
	}

	/**
	 * Discover every schedule file, bind its hook, and ensure it is scheduled.
	 *
	 * @return void
	 *
	 * @internal
	 */
	public function register_schedules(): void {
		foreach ( $this->get_discovered_schedules() as $name => $instance ) {
			$this->bind_hook( $name, $instance );
			$this->ensure_scheduled( $name, $instance );
		}
	}

	/**
	 * This schedule's hook name, from the file it was discovered in.
	 *
	 * @param Schedule $schedule The instance to look up.
	 * @return string The `{plugin-slug}-{name}` hook it is scheduled under.
	 * @throws \InvalidArgumentException When the instance was not discovered by this module.
	 */
	public function get_slug_of( Schedule $schedule ): string {
		$name = \array_search( $schedule, $this->get_discovered_schedules(), true );

		if ( false === $name ) {
			throw new \InvalidArgumentException(
				\sprintf( 'The given %s instance was not discovered by this Cron module.', Schedule::class )
			);
		}

		return $this->get_schedule_slug( $name );
	}

	/**
	 * Resolve the schedules directory and schedule discovery on every request.
	 *
	 * Deferred to `init` for the same reason as the Ajax module: a stable
	 * point regardless of when the plugin's run() happens to execute. Unlike
	 * Ajax, discovery is not limited to a particular request type — WP-Cron's
	 * pseudo-cron dispatch is itself an ordinary request, so the hook must be
	 * bound (and the cron_schedules filter attached) on every request for a
	 * due event to have anything listening when it fires.
	 *
	 * @return void
	 *
	 * @internal
	 */
	protected function on_boot(): void {
		\add_filter( 'cron_schedules', array( $this, 'filter_cron_schedules' ) ); // phpcs:ignore WordPress.WP.CronInterval.ChangeDetected

		$this->run_at_init(
			static function ( self $module ): void {
				$module->register_schedules();
			}
		);
	}

	/**
	 * WordPress's own cron store, keyed by timestamp.
	 *
	 * `_get_cron_array()` is core's only way to enumerate what is scheduled --
	 * every public alternative wants a hook name, which is the thing being
	 * looked for. Named in one place so the underscore is explained once: it
	 * strips the store's `version` key and is otherwise a plain read.
	 *
	 * @return array<int, array<string, mixed>> Timestamp => hooks due then.
	 */
	private function get_cron_events(): array {
		$events = \_get_cron_array();

		return \is_array( $events ) ? $events : array();
	}

	/**
	 * Discover every schedule file and wire an instance for each.
	 *
	 * @return array<string, Schedule> Wired instances keyed by local schedule name.
	 * @throws DiscoveryException When a schedules directory named by set_schedules_root() does not exist, or a file returns the wrong value.
	 */
	private function get_discovered_schedules(): array {
		if ( null !== $this->discovered ) {
			return $this->discovered;
		}

		$root_dir = $this->path->get_plugin_path( $this->schedules_root );

		if ( ! \is_dir( $root_dir ) ) {
			// Never named, and the default is absent: this plugin has none of
			// these yet. Only a directory asked for by name is missing in the
			// sense worth throwing over.
			if ( ! $this->schedules_root_was_set ) {
				$this->discovered = array();

				return $this->discovered;
			}

			throw DiscoveryException::missing_root( 'Schedules', $root_dir, 'set_schedules_root()' );
		}

		$instances = array();

		foreach ( $this->walk_folder( $root_dir, array( 'php' ), 1 ) as $file ) {
			$name     = \basename( $file, '.php' );
			$instance = $this->wire_schedule_file( $root_dir . '/' . $file );

			// Wired first, so is_enabled() can read an injected service. A schedule
			// switched off is never registered, so nothing schedules its event.
			if ( ! $instance->is_enabled() ) {
				continue;
			}

			$instances[ $name ] = $instance;
		}

		$this->discovered = $instances;

		return $this->discovered;
	}

	/**
	 * Load a single schedule file by name, without discovering the rest.
	 *
	 * @param string $name The local schedule name (its filename without `.php`).
	 * @return Schedule The wired instance.
	 * @throws \InvalidArgumentException When no schedule file matches $name.
	 */
	private function load_schedule( string $name ): Schedule {
		$root_dir = $this->path->get_plugin_path( $this->schedules_root );
		$file     = $root_dir . '/' . $name . '.php';

		if ( ! \is_file( $file ) ) {
			throw new \InvalidArgumentException(
				\sprintf( 'No schedule file found for "%s": %s does not exist.', $name, $file )
			);
		}

		return $this->wire_schedule_file( $file );
	}

	/**
	 * Require a schedule file and wire the instance it returns.
	 *
	 * @param string $file Absolute path to the schedule file.
	 * @return Schedule
	 * @throws DiscoveryException When the file does not return a Schedule instance.
	 */
	private function wire_schedule_file( string $file ): Schedule {
		/** @var Schedule $instance */
		$instance = require $file;

		if ( ! $instance instanceof Schedule ) {
			throw new DiscoveryException(
				\sprintf(
					'The file "%s" must return an instance of %s. Got: %s',
					$file,
					Schedule::class,
					\is_object( $instance ) ? $instance::class : \gettype( $instance )
				)
			);
		}

		$this->get_plugin()->wire( $instance );

		return $instance;
	}

	/**
	 * Bind a schedule's hook so a due WP-Cron dispatch has something to call.
	 *
	 * Wraps run() with the concurrency lock (unless the schedule opts out via
	 * allow_concurrent_runs()) and with error handling: an exception is
	 * caught and logged rather than propagating, so one failed occurrence
	 * does not stop other due hooks from running in the same pseudo-cron
	 * dispatch.
	 *
	 * @param string   $name     The local schedule name.
	 * @param Schedule $instance The wired schedule instance.
	 * @return void
	 */
	private function bind_hook( string $name, Schedule $instance ): void {
		$slug = $this->get_schedule_slug( $name );

		\add_action(
			$slug,
			function () use ( $slug, $instance ) {
				$this->run_schedule_safely( $slug, $instance );
			}
		);
	}

	/**
	 * Run a schedule's work, applying its concurrency lock and catching any exception.
	 *
	 * @param string   $slug     The namespaced hook name, used as the lock key.
	 * @param Schedule $instance The wired schedule instance.
	 * @return void
	 */
	private function run_schedule_safely( string $slug, Schedule $instance ): void {
		if ( ! $instance->allow_concurrent_runs() ) {
			$lock_key = $slug . '-running';

			if ( \get_transient( $lock_key ) ) {
				return;
			}

			// The finally block clears this, so the expiry is only a safety net
			// for an interruption a catch() cannot trap (fatal error, timeout).
			// Sized to the recurrence so a fast interval is never stuck long.
			\set_transient( $lock_key, true, $this->get_lock_duration( $instance ) );
		}

		try {
			$instance->run();
		} catch ( \Throwable $exception ) {
			$this->report_failed_run( $slug, $exception );
		} finally {
			if ( ! $instance->allow_concurrent_runs() ) {
				\delete_transient( $slug . '-running' );
			}
		}
	}

	/**
	 * Report a schedule that threw, without depending on a logger.
	 *
	 * Announced on the plugin's `{slug}-log` action, which is where a Log
	 * module -- or a consumer's own handler -- picks it up. Nothing listening
	 * means the message still reaches `error_log()`, since a schedule that
	 * fails silently every run is the worst outcome here.
	 *
	 * The action rather than the Log module itself: this module must keep
	 * working for a plugin that never added one, and a hook has no class or
	 * method signature to depend on.
	 *
	 * @param string     $slug      The schedule's namespaced hook name.
	 * @param \Throwable $exception What run() threw.
	 * @return void
	 */
	private function report_failed_run( string $slug, \Throwable $exception ): void {
		// Composed through the plugin, not through Log, so this works whether or
		// not the plugin added that module. Log listens on the same name.
		$hook    = $this->get_plugin()->get_namespaced_name( 'log' );
		$message = \sprintf( 'Cron schedule "%s" failed: %s', $slug, $exception->getMessage() );

		if ( ! \has_action( $hook ) ) {
			\error_log( $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log

			return;
		}

		\do_action( $hook, 'error', $message, array( 'schedule' => $slug ) );
	}

	/**
	 * Determine how long the concurrency lock should last if never cleared
	 * explicitly.
	 *
	 * Capped at an hour: a schedule with a long recurrence (weekly, monthly)
	 * still only risks being blocked for at most an hour after an
	 * interruption, rather than for as long as its own interval.
	 *
	 * @param Schedule $instance The wired schedule instance.
	 * @return int Lock duration in seconds.
	 */
	private function get_lock_duration( Schedule $instance ): int {
		$schedules = \wp_get_schedules();
		$interval  = $schedules[ $instance->recurrence() ]['interval'] ?? HOUR_IN_SECONDS;

		return \min( $interval, HOUR_IN_SECONDS );
	}

	/**
	 * Run a schedule immediately, without WP-Cron, but with the same
	 * concurrency lock and error handling a real dispatch gets.
	 *
	 * @param string   $name     The local schedule name.
	 * @param Schedule $instance The wired schedule instance.
	 * @return void
	 */
	private function run_schedule( string $name, Schedule $instance ): void {
		$this->run_schedule_safely( $this->get_schedule_slug( $name ), $instance );
	}

	/**
	 * Schedule a discovered event with WordPress if it is not already
	 * scheduled, and correct its recurrence if it has drifted.
	 *
	 * @param string   $name     The local schedule name.
	 * @param Schedule $instance The wired schedule instance.
	 * @return void
	 * @throws \InvalidArgumentException When recurrence() does not resolve to a built-in or registered custom interval.
	 */
	private function ensure_scheduled( string $name, Schedule $instance ): void {
		$slug       = $this->get_schedule_slug( $name );
		$recurrence = $instance->recurrence();

		if ( ! \in_array( $recurrence, self::BUILTIN_INTERVALS, true ) && ! isset( $this->custom_intervals[ $recurrence ] ) ) {
			throw new \InvalidArgumentException(
				\sprintf(
					'Schedule "%s" has an unregistered recurrence "%s". Register it with Cron::add_custom_interval() first, or use a WordPress built-in (%s).',
					$name,
					$recurrence,
					\implode( ', ', self::BUILTIN_INTERVALS )
				)
			);
		}

		$scheduled = \wp_get_schedule( $slug );

		if ( false !== $scheduled && $scheduled !== $recurrence ) {
			// The recurrence changed since this event was first scheduled;
			// wp_schedule_event() will not update an existing occurrence in
			// place, so the old one is cleared and replaced.
			\wp_clear_scheduled_hook( $slug );
			$scheduled = false;
		}

		if ( false === $scheduled ) {
			\wp_schedule_event( $instance->initial_run_at(), $recurrence, $slug );
		}
	}
}
