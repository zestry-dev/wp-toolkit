<?php

declare( strict_types=1 );

namespace Zestry\WPToolkit\Tests\Integration\Modules;

use Zestry\WPToolkit\Kernel\Exceptions\DiscoveryException;
use Zestry\WPToolkit\Modules\Cron\Cron;
use Zestry\WPToolkit\Tests\Support\TestCase;

/**
 * Discovery, scheduling, recurrence-drift correction, concurrency locking,
 * error handling, manual scheduling/running, and unscheduling of the Cron
 * module.
 *
 * @covers \Zestry\WPToolkit\Modules\Cron\Cron
 * @covers \Zestry\WPToolkit\Modules\Cron\Schedule
 */
final class CronTest extends TestCase {

	public function tear_down(): void {
		foreach ( array( 'cleanup', 'custom', 'flaky', 'concurrent', 'bad-recurrence' ) as $name ) {
			wp_clear_scheduled_hook( 'zestry-test_' . $name );
			delete_transient( 'zestry-test-' . $name . '-running' );
		}
		parent::tear_down();
	}

	/**
	 * Register Cron with an initializer that points it at $root (and runs
	 * $configure, if given), then resolve it. Resolution wires the module,
	 * runs the initializer, and boots it, so on_boot()'s deferred-to-init
	 * registration is queued by this call.
	 *
	 * @param string        $root      Plugin-relative schedules directory.
	 * @param callable|null $configure Optional extra configuration, e.g. add_custom_interval().
	 * @return Cron The resolved module.
	 */
	private function boot_cron_with_root( string $root, ?callable $configure = null ): Cron {
		$this->plugin->configure(
			Cron::class,
			static function ( Cron $cron ) use ( $root, $configure ): void {
				if ( null !== $configure ) {
					$configure( $cron );
				}
			}
		);

		$cron = $this->plugin->get( Cron::class );
		do_action( 'init' );

		return $cron;
	}

	private function write_schedule( string $name, string $body ): void {
		$this->write_plugin_file(
			'schedules/' . $name . '.php',
			"<?php\nuse Zestry\\WPToolkit\\Modules\\Cron\\Schedule;\nreturn new class extends Schedule {\n{$body}\n};\n"
		);
	}

	public function test_a_schedule_hook_is_underscored(): void {
		// Files are named with hyphens and a WordPress hook is not, so the
		// separator suits the destination rather than the filename.
		$this->assertSame(
			'zestry-test-sync-orders',
			$this->plugin->get( Cron::class )->get_schedule_slug( 'sync-orders' )
		);
	}

	public function test_discovered_schedule_is_scheduled_with_wordpress(): void {
		$this->write_schedule(
			'cleanup',
			"public function recurrence(): string { return 'daily'; }\n"
				. 'public function run(): void {}'
		);

		$cron = $this->boot_cron_with_root( 'schedules' );
		$slug = $cron->get_schedule_slug( 'cleanup' );

		$this->assertNotFalse( wp_next_scheduled( $slug ) );
		$this->assertSame( 'daily', wp_get_schedule( $slug ) );
	}

	public function test_hook_is_bound_so_a_due_dispatch_calls_run(): void {
		$this->write_schedule(
			'cleanup',
			"public function recurrence(): string { return 'daily'; }\n"
				. "public function run(): void { \$GLOBALS['zestry_cron_ran'] = true; }"
		);

		$cron = $this->boot_cron_with_root( 'schedules' );
		$slug = $cron->get_schedule_slug( 'cleanup' );

		$GLOBALS['zestry_cron_ran'] = false;
		do_action( $slug );

		$this->assertTrue( $GLOBALS['zestry_cron_ran'], 'The bound hook must call run().' );
		unset( $GLOBALS['zestry_cron_ran'] );
	}

	public function test_registration_is_deferred_to_init_when_init_has_not_fired(): void {
		$this->write_schedule(
			'cleanup',
			"public function recurrence(): string { return 'daily'; }\n"
				. 'public function run(): void {}'
		);

		global $wp_actions;
		$saved = $wp_actions['init'] ?? null;
		unset( $wp_actions['init'] );

		try {
			$this->plugin->configure(
				Cron::class,
				static function ( Cron $cron ): void {
				}
			);
			$cron = $this->plugin->get( Cron::class );
			$slug = $cron->get_schedule_slug( 'cleanup' );

			$this->assertFalse( wp_next_scheduled( $slug ), 'Registration is deferred, not immediate.' );

			do_action( 'init' );
			$this->assertNotFalse( wp_next_scheduled( $slug ), 'The init hook registers the schedule.' );
		} finally {
			if ( null !== $saved ) {
				$wp_actions['init'] = $saved;
			}
		}
	}

	public function test_re_registering_does_not_stack_duplicate_events(): void {
		$this->write_schedule(
			'cleanup',
			"public function recurrence(): string { return 'daily'; }\n"
				. 'public function run(): void {}'
		);

		// Resolving the module already discovers and schedules 'cleanup' once
		// (see test_schedule_re_arms_a_previously_cleared_event for why this
		// is the immediate-registration branch, not the deferred one).
		$cron  = $this->boot_cron_with_root( 'schedules' );
		$slug  = $cron->get_schedule_slug( 'cleanup' );
		$first = wp_next_scheduled( $slug );

		// Re-running the same already-scheduled/recurrence-drift check
		// register_schedules() applies (schedule() applies the identical
		// logic for one file) must not reschedule an already-correctly
		// scheduled event.
		$cron->schedule( 'cleanup' );

		$this->assertSame( $first, wp_next_scheduled( $slug ) );
	}

	public function test_recurrence_drift_reschedules_the_event(): void {
		$this->write_schedule(
			'cleanup',
			"public function recurrence(): string { return 'daily'; }\n"
				. 'public function run(): void {}'
		);
		$cron = $this->boot_cron_with_root( 'schedules' );
		$slug = $cron->get_schedule_slug( 'cleanup' );
		$this->assertSame( 'daily', wp_get_schedule( $slug ) );

		// Simulate a later plugin version changing the recurrence. Re-firing
		// `init` would not re-trigger discovery here: the WP test suite has
		// already fired `init` globally before this test ever runs, so
		// on_boot() already took its immediate-registration branch once and
		// never bound a listener to fire again on a later `init`. schedule()
		// applies the same drift check register_schedules() would have,
		// without needing a fresh discovery pass.
		$this->write_schedule(
			'cleanup',
			"public function recurrence(): string { return 'hourly'; }\n"
				. 'public function run(): void {}'
		);

		$cron->schedule( 'cleanup' );

		$this->assertSame( 'hourly', wp_get_schedule( $slug ), 'A recurrence mismatch must be corrected.' );
	}

	public function test_an_unregistered_recurrence_throws(): void {
		$this->write_schedule(
			'bad-recurrence',
			"public function recurrence(): string { return 'zestry-test-never-registered'; }\n"
				. 'public function run(): void {}'
		);

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'unregistered recurrence' );

		$this->boot_cron_with_root( 'schedules' );
	}

	public function test_add_custom_interval_registers_it_on_the_cron_schedules_filter(): void {
		$this->plugin->configure(
			Cron::class,
			static function ( Cron $cron ): void {
				$cron->add_custom_interval( 'every_15_minutes', 15 * MINUTE_IN_SECONDS, 'Every 15 Minutes' );
			}
		);
		mkdir( $this->plugin_dir . '/schedules', 0777, true );
		$cron = $this->plugin->get( Cron::class );

		$slug      = $cron->get_custom_interval_slug( 'every_15_minutes' );
		$schedules = wp_get_schedules();

		$this->assertArrayHasKey( $slug, $schedules );
		$this->assertSame( 15 * MINUTE_IN_SECONDS, $schedules[ $slug ]['interval'] );
		$this->assertSame( 'Every 15 Minutes', $schedules[ $slug ]['display'] );
	}

	public function test_a_schedule_can_reference_a_registered_custom_interval(): void {
		$this->write_schedule(
			'custom',
			"public function recurrence(): string { return \$this->get_plugin()->get( \\Zestry\\WPToolkit\\Modules\\Cron\\Cron::class )->get_custom_interval_slug( 'every_15_minutes' ); }\n"
				. 'public function run(): void {}'
		);

		$cron = $this->boot_cron_with_root(
			'schedules',
			static function ( Cron $cron ): void {
				$cron->add_custom_interval( 'every_15_minutes', 15 * MINUTE_IN_SECONDS, 'Every 15 Minutes' );
			}
		);

		$slug = $cron->get_schedule_slug( 'custom' );
		$this->assertSame( $cron->get_custom_interval_slug( 'every_15_minutes' ), wp_get_schedule( $slug ) );
	}

	public function test_run_now_executes_immediately_without_scheduling(): void {
		$this->write_schedule(
			'cleanup',
			"public function recurrence(): string { return 'daily'; }\n"
				. "public function run(): void { \$GLOBALS['zestry_cron_ran'] = true; }"
		);

		$this->plugin->configure(
			Cron::class,
			static function ( Cron $cron ): void {
			}
		);
		$cron = $this->plugin->get( Cron::class );

		$GLOBALS['zestry_cron_ran'] = false;
		$cron->run_now( 'cleanup' );

		$this->assertTrue( $GLOBALS['zestry_cron_ran'] );
		unset( $GLOBALS['zestry_cron_ran'] );
	}

	public function test_run_now_throws_for_an_unknown_schedule_name(): void {
		$this->plugin->configure(
			Cron::class,
			static function ( Cron $cron ): void {
			}
		);
		mkdir( $this->plugin_dir . '/schedules', 0777, true );

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'No schedule file found' );

		$this->plugin->get( Cron::class )->run_now( 'does-not-exist' );
	}

	public function test_schedule_re_arms_a_previously_cleared_event(): void {
		$this->write_schedule(
			'cleanup',
			"public function recurrence(): string { return 'daily'; }\n"
				. 'public function run(): void {}'
		);

		// Resolving the module already discovers and arms every schedule (the
		// WP test suite has already fired `init` globally by the time any
		// individual test runs, so on_boot() takes its immediate-registration
		// branch, not the deferred one -- see
		// test_registration_is_deferred_to_init_when_init_has_not_fired for
		// the case that explicitly simulates init not having fired yet).
		$cron = $this->boot_cron_with_root( 'schedules' );
		$slug = $cron->get_schedule_slug( 'cleanup' );
		$this->assertNotFalse( wp_next_scheduled( $slug ) );

		// Something external clears it -- schedule() re-arms just that one
		// event without needing a fresh discovery pass to notice it is gone.
		wp_clear_scheduled_hook( $slug );
		$this->assertFalse( wp_next_scheduled( $slug ) );

		$cron->schedule( 'cleanup' );
		$this->assertNotFalse( wp_next_scheduled( $slug ) );
	}

	public function test_unschedule_all_clears_every_discovered_event(): void {
		$this->write_schedule(
			'cleanup',
			"public function recurrence(): string { return 'daily'; }\n"
				. 'public function run(): void {}'
		);

		$cron = $this->boot_cron_with_root( 'schedules' );
		$slug = $cron->get_schedule_slug( 'cleanup' );
		$this->assertNotFalse( wp_next_scheduled( $slug ) );

		$cron->unschedule_all();

		$this->assertFalse( wp_next_scheduled( $slug ) );
	}

	public function test_a_failing_run_is_caught_and_logged_not_propagated(): void {
		$this->write_schedule(
			'flaky',
			"public function recurrence(): string { return 'daily'; }\n"
				. "public function run(): void { throw new \\RuntimeException( 'boom' ); }"
		);

		$cron = $this->boot_cron_with_root( 'schedules' );
		$slug = $cron->get_schedule_slug( 'flaky' );

		// The failure is announced on the plugin's log action, which is how a Log
		// module -- or a consumer's own handler -- picks it up without this
		// module depending on either. A listener also means error_log() is
		// skipped, keeping the message out of the suite's output.
		$seen = array();

		add_action(
			'zestry-test-log',
			static function ( string $level, string $message, array $context = array() ) use ( &$seen ): void {
				$seen = compact( 'level', 'message', 'context' );
			},
			10,
			3
		);

		// Firing the hook must not let the schedule's exception escape.
		do_action( $slug );

		$this->assertSame( 'error', $seen['level'] );
		$this->assertStringContainsString( 'boom', $seen['message'] );
		$this->assertSame( array( 'schedule' => $slug ), $seen['context'] );
	}

	public function test_concurrent_runs_are_prevented_by_default(): void {
		$this->write_schedule(
			'concurrent',
			"public function recurrence(): string { return 'daily'; }\n"
				. "public function run(): void { \$GLOBALS['zestry_cron_run_count'] = ( \$GLOBALS['zestry_cron_run_count'] ?? 0 ) + 1; }"
		);

		$cron = $this->boot_cron_with_root( 'schedules' );
		$slug = $cron->get_schedule_slug( 'concurrent' );

		$GLOBALS['zestry_cron_run_count'] = 0;
		set_transient( $slug . '-running', true, HOUR_IN_SECONDS );

		do_action( $slug );

		$this->assertSame( 0, $GLOBALS['zestry_cron_run_count'], 'run() must be skipped while the lock is held.' );
		unset( $GLOBALS['zestry_cron_run_count'] );
	}

	public function test_the_lock_is_released_after_a_successful_run(): void {
		$this->write_schedule(
			'cleanup',
			"public function recurrence(): string { return 'daily'; }\n"
				. 'public function run(): void {}'
		);

		$cron = $this->boot_cron_with_root( 'schedules' );
		$slug = $cron->get_schedule_slug( 'cleanup' );

		do_action( $slug );

		$this->assertFalse( get_transient( $slug . '-running' ), 'The lock must be released once run() returns.' );
	}

	public function test_allow_concurrent_runs_skips_the_lock_entirely(): void {
		$this->write_schedule(
			'concurrent',
			"public function recurrence(): string { return 'daily'; }\n"
				. "public function allow_concurrent_runs(): bool { return true; }\n"
				. "public function run(): void { \$GLOBALS['zestry_cron_run_count'] = ( \$GLOBALS['zestry_cron_run_count'] ?? 0 ) + 1; }"
		);

		$cron = $this->boot_cron_with_root( 'schedules' );
		$slug = $cron->get_schedule_slug( 'concurrent' );

		// Even with a stale "running" transient present, a concurrency-exempt
		// schedule must still run.
		set_transient( $slug . '-running', true, HOUR_IN_SECONDS );

		$GLOBALS['zestry_cron_run_count'] = 0;
		do_action( $slug );

		$this->assertSame( 1, $GLOBALS['zestry_cron_run_count'] );
		unset( $GLOBALS['zestry_cron_run_count'] );
	}

	public function test_a_schedule_file_returning_the_wrong_type_throws(): void {
		$this->write_plugin_file( 'schedules/bad.php', "<?php\nreturn 42;\n" );

		$this->expectException( DiscoveryException::class );
		$this->expectExceptionMessage( 'must return an instance of' );

		$this->boot_cron_with_root( 'schedules' );
	}

	public function test_initial_run_at_defaults_to_now(): void {
		$this->write_schedule(
			'cleanup',
			"public function recurrence(): string { return 'daily'; }\n"
				. 'public function run(): void {}'
		);

		$before = time();
		$cron   = $this->boot_cron_with_root( 'schedules' );
		$after  = time();
		$slug   = $cron->get_schedule_slug( 'cleanup' );

		$next = wp_next_scheduled( $slug );
		$this->assertGreaterThanOrEqual( $before, $next );
		$this->assertLessThanOrEqual( $after, $next );
	}

	public function test_initial_run_at_can_be_overridden(): void {
		$anchor = time() + DAY_IN_SECONDS;
		$this->write_schedule(
			'cleanup',
			"public function recurrence(): string { return 'daily'; }\n"
				. "public function initial_run_at(): int { return {$anchor}; }\n"
				. 'public function run(): void {}'
		);

		$cron = $this->boot_cron_with_root( 'schedules' );
		$slug = $cron->get_schedule_slug( 'cleanup' );

		$this->assertSame( $anchor, wp_next_scheduled( $slug ) );
	}
	/**
	 * A schedule can name its own hook, which is what clearing or inspecting it
	 * needs -- wp_clear_scheduled_hook() takes the prefixed name, and the
	 * schedule never sees its own filename otherwise.
	 */
	public function test_a_schedule_can_ask_for_its_own_hook(): void {
		$this->write_plugin_file(
			'schedules/cleanup.php',
			"<?php\nreturn new class extends \\Zestry\\WPToolkit\\Modules\\Cron\\Schedule {\n"
				. "public function recurrence(): string { return 'daily'; }\n"
				. "public function run(): void { \$GLOBALS['zestry_hook'] = \$this->get_hook(); }\n"
				. "};\n"
		);

		$cron = $this->plugin->get( Cron::class );
		$cron->register_schedules();

		do_action( 'zestry-test-cleanup' );

		$this->assertSame( 'zestry-test-cleanup', $GLOBALS['zestry_hook'] );

		unset( $GLOBALS['zestry_hook'] );
		wp_clear_scheduled_hook( 'zestry-test-cleanup' );
	}

	/**
	 * The failure this exists for. A schedule's hook is its filename, so
	 * renaming the file schedules a new event and abandons the old one --
	 * booting only schedules what discovery finds, and unschedule_all() clears
	 * the same set, so the abandoned event is in neither list. WordPress fires
	 * it on time, forever, with nothing listening, and nothing says so.
	 */
	public function test_reports_an_event_whose_schedule_file_is_gone(): void {
		$this->write_schedule(
			'sync',
			"public function recurrence(): string { return 'daily'; }\n"
				. 'public function run(): void {}'
		);

		$cron = $this->boot_cron_with_root( 'schedules' );
		$this->assertSame( array(), $cron->get_orphaned_events(), 'Nothing is orphaned while the file is there.' );

		rename(
			$this->plugin_dir . '/schedules/sync.php',
			$this->plugin_dir . '/schedules/synchronise.php'
		);

		$orphaned = $this->fresh_cron()->get_orphaned_events();

		$this->assertSame( array( $cron->get_schedule_slug( 'sync' ) ), array_keys( $orphaned ) );
	}

	public function test_reports_when_a_schedule_file_is_deleted(): void {
		$this->write_schedule(
			'sync',
			"public function recurrence(): string { return 'daily'; }\n"
				. 'public function run(): void {}'
		);

		$cron = $this->boot_cron_with_root( 'schedules' );
		unlink( $this->plugin_dir . '/schedules/sync.php' );

		$this->assertSame(
			array( $cron->get_schedule_slug( 'sync' ) ),
			array_keys( $this->fresh_cron()->get_orphaned_events() )
		);
	}

	/**
	 * Another plugin's events are not this plugin's to report on, and every
	 * name this module registers carries the slug.
	 */
	public function test_ignores_an_event_belonging_to_something_else(): void {
		$this->write_schedule(
			'sync',
			"public function recurrence(): string { return 'daily'; }\n"
				. 'public function run(): void {}'
		);

		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'some_other_plugin_sync' );

		$this->boot_cron_with_root( 'schedules' );

		$this->assertSame( array(), $this->fresh_cron()->get_orphaned_events() );

		wp_clear_scheduled_hook( 'some_other_plugin_sync' );
	}

	/**
	 * Reporting, never pruning, and never automatic: a `{slug}_` event this
	 * module did not create looks exactly like one it did, so clearing on its
	 * own would mean deleting something a plugin scheduled by hand.
	 */
	public function test_clears_orphaned_events_only_when_asked(): void {
		$this->write_schedule(
			'sync',
			"public function recurrence(): string { return 'daily'; }\n"
				. 'public function run(): void {}'
		);

		$cron = $this->boot_cron_with_root( 'schedules' );
		$hook = $cron->get_schedule_slug( 'sync' );
		unlink( $this->plugin_dir . '/schedules/sync.php' );

		$fresh = $this->fresh_cron();
		$this->assertNotFalse( wp_next_scheduled( $hook ), 'Still scheduled until something clears it.' );

		$this->assertSame( array( $hook ), $fresh->unschedule_orphaned() );
		$this->assertFalse( wp_next_scheduled( $hook ) );
	}

	public function test_a_live_schedule_is_never_reported_or_cleared(): void {
		$this->write_schedule(
			'sync',
			"public function recurrence(): string { return 'daily'; }\n"
				. 'public function run(): void {}'
		);

		$cron = $this->boot_cron_with_root( 'schedules' );

		$this->assertSame( array(), $cron->unschedule_orphaned() );
		$this->assertNotFalse( wp_next_scheduled( $cron->get_schedule_slug( 'sync' ) ) );
	}

	/**
	 * A second module over the same directory, so discovery re-reads what is on
	 * disk now rather than what it cached at boot.
	 *
	 * @return Cron
	 */
	private function fresh_cron(): Cron {
		$cron = $this->plugin->make(
			Cron::class,
			function ( Cron $module ): void {
			}
		);

		return $cron;
	}
}
