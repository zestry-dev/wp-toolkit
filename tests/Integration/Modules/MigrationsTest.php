<?php

declare( strict_types=1 );

namespace Zestry\WPToolkit\Tests\Integration\Modules;

use Zestry\WPToolkit\Kernel\Exceptions\DiscoveryException;
use Zestry\WPToolkit\Modules\Migrations\ListMigrationsCommand;
use Zestry\WPToolkit\Modules\Migrations\Migrations;
use Zestry\WPToolkit\Modules\Options;
use Zestry\WPToolkit\Modules\Migrations\RenamedMigrationException;
use Zestry\WPToolkit\Modules\Migrations\RunMigrationsCommand;
use Zestry\WPToolkit\Tests\Support\TestCase;

/**
 * Discovery/ordering/execution of migration files via the manually-callable
 * run_pending()/maybe_resume_interrupted_run(), and the migrations run/list
 * WP-CLI commands. Migrations never triggers itself automatically -- see the
 * class docblock -- so there is no hook-firing test here; a consumer's own
 * trigger is expected to call these methods directly.
 *
 * @covers \Zestry\WPToolkit\Modules\Migrations\Migrations
 * @covers \Zestry\WPToolkit\Modules\Migrations\Migration
 */
final class MigrationsTest extends TestCase {

	public function set_up(): void {
		parent::set_up();

		// No commands/ directory is created here on purpose. It used to be,
		// because Migrations injected a CLI property, and booting CLI throws
		// when that directory is absent -- scaffolding that existed only to
		// work around the coupling. Migrations now registers its commands
		// through CLI's static entry point and never boots the module, so the
		// directory is genuinely unnecessary; see
		// test_booting_migrations_without_a_commands_directory_does_not_fatal().
		if ( defined( 'WP_CLI' ) ) {
			\WP_CLI::reset();
		}
	}

	public function test_on_boot_does_not_run_migrations_immediately(): void {
		// Resolving/booting the module registers nothing that would run
		// migrations on its own -- see the class docblock.
		$this->write_migration( '20260101000000-first', "\$GLOBALS['zestry_migration_runs'] = ( \$GLOBALS['zestry_migration_runs'] ?? 0 ) + 1;" );

		$GLOBALS['zestry_migration_runs'] = 0;
		$this->plugin->get( Migrations::class );

		$this->assertSame( 0, $GLOBALS['zestry_migration_runs'], 'Booting the module must not itself run migrations.' );
		unset( $GLOBALS['zestry_migration_runs'] );
	}

	public function test_migrations_run_in_filename_order_not_authoring_order(): void {
		// Written to disk in the opposite order from how they should run.
		$this->write_migration( '20260102000000-second', "\$GLOBALS['zestry_migration_order'][] = 'second';" );
		$this->write_migration( '20260101000000-first', "\$GLOBALS['zestry_migration_order'][] = 'first';" );

		$GLOBALS['zestry_migration_order'] = array();
		$this->plugin->get( Migrations::class )->run_pending();

		$this->assertSame( array( 'first', 'second' ), $GLOBALS['zestry_migration_order'] );
		unset( $GLOBALS['zestry_migration_order'] );
	}

	public function test_run_pending_is_callable_directly_for_activation_or_manual_use(): void {
		$this->write_migration( '20260101000000-first', "\$GLOBALS['zestry_migration_runs'] = ( \$GLOBALS['zestry_migration_runs'] ?? 0 ) + 1;" );

		$migrations = $this->plugin->get( Migrations::class );

		$GLOBALS['zestry_migration_runs'] = 0;
		$migrations->run_pending();
		$migrations->run_pending();

		$this->assertSame( 1, $GLOBALS['zestry_migration_runs'], 'Calling run_pending() again does not re-run an already-recorded migration.' );
		unset( $GLOBALS['zestry_migration_runs'] );
	}

	public function test_a_failing_migration_propagates_and_is_not_recorded(): void {
		$this->write_migration( '20260101000000-broken', "throw new \\RuntimeException( 'boom' );" );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'boom' );

		$this->plugin->get( Migrations::class )->run_pending();
	}

	public function test_a_migration_file_returning_the_wrong_type_throws(): void {
		$this->write_plugin_file( 'migrations/20260101000000-bad.php', "<?php\nreturn 42;\n" );

		$this->expectException( DiscoveryException::class );
		$this->expectExceptionMessage( 'must return an instance of' );

		$this->plugin->get( Migrations::class )->run_pending();
	}

	public function test_get_discovered_migrations_lists_identifiers_in_filename_order_regardless_of_run_state(): void {
		$this->write_migration( '20260102000000-second', '' );
		$this->write_migration( '20260101000000-first', '' );

		$migrations = $this->plugin->get( Migrations::class );
		$migrations->run_pending();

		$this->assertSame(
			array( '20260101000000-first', '20260102000000-second' ),
			$migrations->get_discovered_migrations(),
			'Discovery order matches filename order, independent of run state.'
		);
	}

	public function test_run_pending_clears_running_since_on_normal_completion(): void {
		$this->write_migration( '20260101000000-first', '' );

		$migrations = $this->plugin->get( Migrations::class );
		$migrations->run_pending();

		$this->assertNull(
			$this->migrations_option( $migrations, 'running_since' ),
			'A run that completes normally leaves no running_since behind.'
		);
	}

	public function test_run_pending_leaves_running_since_set_when_a_migration_throws(): void {
		$this->write_migration( '20260101000000-broken', "throw new \\RuntimeException( 'boom' );" );

		$migrations = $this->plugin->get( Migrations::class );

		try {
			$migrations->run_pending();
		} catch ( \RuntimeException $exception ) {
			// Expected; asserted by test_a_failing_migration_propagates_and_is_not_recorded.
		}

		$this->assertIsInt(
			$this->migrations_option( $migrations, 'running_since' ),
			'A failed run is left marked as still running, so it keeps being retried.'
		);
	}

	public function test_maybe_resume_interrupted_run_does_nothing_when_no_run_was_ever_started(): void {
		$this->write_migration( '20260101000000-first', "\$GLOBALS['zestry_migration_runs'] = ( \$GLOBALS['zestry_migration_runs'] ?? 0 ) + 1;" );

		$GLOBALS['zestry_migration_runs'] = 0;
		$this->plugin->get( Migrations::class )->maybe_resume_interrupted_run();

		$this->assertSame( 0, $GLOBALS['zestry_migration_runs'] );
		unset( $GLOBALS['zestry_migration_runs'] );
	}

	public function test_maybe_resume_interrupted_run_leaves_a_fresh_running_since_alone(): void {
		$this->write_migration( '20260101000000-first', "\$GLOBALS['zestry_migration_runs'] = ( \$GLOBALS['zestry_migration_runs'] ?? 0 ) + 1;" );

		$migrations = $this->plugin->get( Migrations::class );
		$this->set_migrations_option( $migrations, 'running_since', time() );

		$GLOBALS['zestry_migration_runs'] = 0;
		$migrations->maybe_resume_interrupted_run();

		$this->assertSame( 0, $GLOBALS['zestry_migration_runs'], 'A recent running_since is treated as a run still in progress, not resumed.' );
		unset( $GLOBALS['zestry_migration_runs'] );
	}

	public function test_maybe_resume_interrupted_run_resumes_a_stale_run(): void {
		// Simulates a timeout partway through: migration 1 already ran and was
		// recorded, migration 2 was cut off before it could run or record.
		$this->write_migration( '20260101000000-first', '' );
		$this->write_migration( '20260102000000-second', "\$GLOBALS['zestry_migration_runs'] = ( \$GLOBALS['zestry_migration_runs'] ?? 0 ) + 1;" );

		$migrations = $this->plugin->get( Migrations::class );
		$this->set_migrations_option( $migrations, 'ran', array( '20260101000000-first' ) );
		$this->set_migrations_option( $migrations, 'running_since', time() - ( 10 * MINUTE_IN_SECONDS ) );

		$GLOBALS['zestry_migration_runs'] = 0;
		$migrations->maybe_resume_interrupted_run();

		$this->assertSame( 1, $GLOBALS['zestry_migration_runs'], 'The interrupted migration is run on resumption.' );
		$this->assertSame( array( '20260101000000-first', '20260102000000-second' ), $migrations->get_ran_migrations() );
		$this->assertNull( $this->migrations_option( $migrations, 'running_since' ) );
		unset( $GLOBALS['zestry_migration_runs'] );
	}

	/**
	 * The "not under WP-CLI" branch itself (no commands registered at all) is
	 * covered by CliTest, not here: WP_CLI is a process-global, irreversible
	 * constant, and CliTest -- which runs before this file alphabetically --
	 * already defines it for the rest of the PHPUnit process, so this file can
	 * never observe the "undefined" state on its own.
	 */
	public function test_on_boot_registers_migrations_run_and_migrations_list_commands_under_wp_cli(): void {
		$this->define_wp_cli();

		$this->plugin->get( Migrations::class );

		$registered = array_column(
			array_filter(
				\WP_CLI::$calls,
				static function ( array $call ): bool {
					return 'add_command' === $call[0];
				}
			),
			1
		);

		$this->assertContains( 'zestry-test migrations run', $registered );
		$this->assertContains( 'zestry-test migrations list', $registered );
	}

	/**
	 * Regression guard: Migrations must not drag CLI's file discovery in.
	 *
	 * Migrations registers its two commands through CLI's static entry point
	 * precisely so it never resolves -- and therefore never boots -- the CLI
	 * module. An injected `CLI $cli` property booted CLI, which walks the
	 * consumer's `commands/` directory and throws when it is absent, so a
	 * consumer who added `migrations` (which lists `cli` in its registry
	 * `depends`) and never asked for file-based commands fataled on every
	 * single `wp` invocation.
	 */
	public function test_booting_migrations_without_a_commands_directory_does_not_fatal(): void {
		$this->define_wp_cli();
		$this->write_migration( '20260101000000-first', '' );

		// No commands/ directory is written at all.
		$this->assertDirectoryDoesNotExist( $this->plugin_dir . '/commands' );

		$this->plugin->get( Migrations::class );

		$registered = array_column(
			array_filter(
				\WP_CLI::$calls,
				static function ( array $call ): bool {
					return 'add_command' === $call[0];
				}
			),
			1
		);

		$this->assertContains(
			'zestry-test migrations run',
			$registered,
			'The migrations commands register even with no commands/ directory present.'
		);
	}

	public function test_run_migrations_command_runs_pending_and_reports_the_count(): void {
		$this->write_migration( '20260101000000-first', '' );

		$this->plugin->get( Migrations::class );

		$command = new RunMigrationsCommand();
		$this->plugin->wire( $command );

		$command->handle( array(), array() );

		$this->assertSame( array( 'success', 'Ran 1 pending migration.' ), $this->last_wp_cli_call() );
	}

	public function test_run_migrations_command_reports_already_up_to_date(): void {
		$this->write_migration( '20260101000000-first', '' );

		$migrations = $this->plugin->get( Migrations::class );
		$migrations->run_pending();


		$command = new RunMigrationsCommand();
		$this->plugin->wire( $command );

		$command->handle( array(), array() );

		$this->assertSame( array( 'success', 'Already up to date -- nothing to run.' ), $this->last_wp_cli_call() );
	}

	public function test_run_migrations_command_reports_a_failing_migration_as_an_error_rather_than_throwing(): void {
		$this->write_migration( '20260101000000-broken', "throw new \\RuntimeException( 'boom' );" );

		$this->plugin->get( Migrations::class );

		$command = new RunMigrationsCommand();
		$this->plugin->wire( $command );

		$command->handle( array(), array() );

		$this->assertSame( array( 'error', 'boom', true ), $this->last_wp_cli_call() );
	}

	public function test_list_migrations_command_reports_ran_and_pending_status(): void {
		$this->write_migration( '20260101000000-first', '' );

		$migrations = $this->plugin->get( Migrations::class );
		$migrations->run_pending();

		// Written only after the first migration already ran, so it stays pending.
		$this->write_migration( '20260102000000-second', '' );


		$command = new ListMigrationsCommand();
		$this->plugin->wire( $command );

		$command->handle( array(), array() );

		list( , $format, $items, $fields ) = $this->last_wp_cli_call();

		$this->assertSame( 'table', $format, 'Defaults to a table when --format is not given.' );
		$this->assertSame( array( 'identifier', 'status' ), $fields );
		$this->assertSame(
			array(
				array(
					'identifier' => '20260101000000-first',
					'status'     => 'ran',
				),
				array(
					'identifier' => '20260102000000-second',
					'status'     => 'pending',
				),
			),
			$items
		);
	}

	public function test_list_migrations_command_honors_the_format_flag(): void {
		$this->write_migration( '20260101000000-first', '' );

		$this->plugin->get( Migrations::class );

		$command = new ListMigrationsCommand();
		$this->plugin->wire( $command );

		$command->handle( array(), array( 'format' => 'json' ) );

		list( , $format ) = $this->last_wp_cli_call();
		$this->assertSame( 'json', $format );
	}

	public function test_list_migrations_command_reports_when_none_are_found(): void {
		mkdir( $this->plugin_dir . '/migrations', 0777, true );

		$this->plugin->get( Migrations::class );

		$command = new ListMigrationsCommand();
		$this->plugin->wire( $command );

		$command->handle( array(), array() );

		list( , $format, $items ) = $this->last_wp_cli_call();
		$this->assertSame( 'table', $format );
		$this->assertSame( array(), $items );
	}

	/**
	 * A migration whose table really is created runs, and is recorded.
	 */
	public function test_db_delta_creates_a_table_and_records_the_migration(): void {
		global $wpdb;

		$this->write_migration(
			'20260101000000-create-probe',
			"\$table = \$this->get_table( 'probe' );\n"
				. "        \$this->db_delta( \"CREATE TABLE {\$table} (\\n id bigint(20) unsigned NOT NULL auto_increment,\\n PRIMARY KEY  (id)\\n) \" . \$this->get_charset_collate() . ';' );"
		);

		$migrations = $this->plugin->get( Migrations::class );
		$table      = $this->plugin->get( \Zestry\WPToolkit\Modules\DB::class )->get_table( 'probe' );

		try {
			$migrations->run_pending();

			$this->assertTrue(
				$this->plugin->get( \Zestry\WPToolkit\Modules\DB::class )->table_exists( 'probe' ),
				'The table exists after the migration ran.'
			);
		} finally {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
		}
	}

	/**
	 * The defect this guard exists for: dbDelta() reports the statements it
	 * decided to run, not the ones that succeeded, so a CREATE TABLE MySQL
	 * rejected used to report success, create nothing, and be recorded as run
	 * -- never retried.
	 */
	public function test_db_delta_throws_when_a_reported_table_was_not_created(): void {
		// A column type MySQL rejects, so the statement fails while dbDelta()
		// still lists the table as one it decided to create.
		$this->write_migration(
			'20260101000000-broken',
			"\$table = \$this->get_table( 'broken' );\n"
				. "        \$this->db_delta( \"CREATE TABLE {\$table} (\\n id notatype(20) NOT NULL\\n) \" . \$this->get_charset_collate() . ';' );"
		);

		$migrations = $this->plugin->get( Migrations::class );

		$GLOBALS['wpdb']->suppress_errors( true );

		try {
			$migrations->run_pending();
			$this->fail( 'Expected db_delta() to throw when the table was not created.' );
		} catch ( \RuntimeException $exception ) {
			$this->assertStringContainsString( 'but the table does not exist', $exception->getMessage() );
		} finally {
			$GLOBALS['wpdb']->suppress_errors( false );
		}
	}

	/**
	 * A migration's identity is its filename, so a rename produces two rows: the
	 * new name with nothing recorded against it, and the recorded name with no
	 * file. Before both were reported, the recorded one simply dropped out of
	 * the listing and `pending` was the operator's only signal -- indistinguishable
	 * from a genuinely new migration.
	 */
	public function test_list_reports_a_renamed_migration_as_pending_and_orphaned(): void {
		$this->write_migration( '20260101000000-create-submissions-table', '' );

		$migrations = $this->plugin->get( Migrations::class );
		$migrations->run_pending();

		$this->rename_migration( '20260101000000-create-submissions-table', '20260101000000-create-submissions-tables' );


		$command = new ListMigrationsCommand();
		$this->plugin->wire( $command );

		$command->handle( array(), array() );

		list( , , $items ) = $this->last_wp_cli_call();

		$this->assertSame(
			array(
				array(
					'identifier' => '20260101000000-create-submissions-tables',
					'status'     => 'pending',
				),
				array(
					'identifier' => '20260101000000-create-submissions-table',
					'status'     => 'orphaned',
				),
			),
			$items
		);
	}

	public function test_list_reports_a_deleted_migration_as_orphaned(): void {
		$this->write_migration( '20260101000000-first', '' );

		$migrations = $this->plugin->get( Migrations::class );
		$migrations->run_pending();

		unlink( $this->plugin_dir . '/migrations/20260101000000-first.php' );


		$command = new ListMigrationsCommand();
		$this->plugin->wire( $command );

		$command->handle( array(), array() );

		list( , , $items ) = $this->last_wp_cli_call();

		$this->assertSame(
			array(
				array(
					'identifier' => '20260101000000-first',
					'status'     => 'orphaned',
				),
			),
			$items
		);
	}

	/**
	 * An orphan has no file to sort against, so the ran-list's own order is the
	 * only one available -- and putting them last keeps the on-disk listing
	 * exactly as it was.
	 */
	public function test_orphans_come_after_every_on_disk_migration_however_they_sort(): void {
		$this->write_migration( '20260101000000-earliest', '' );

		$migrations = $this->plugin->get( Migrations::class );
		$migrations->run_pending();

		unlink( $this->plugin_dir . '/migrations/20260101000000-earliest.php' );
		$this->write_migration( '20260909000000-later', '' );


		$command = new ListMigrationsCommand();
		$this->plugin->wire( $command );

		$command->handle( array(), array() );

		list( , , $items ) = $this->last_wp_cli_call();

		$this->assertSame( '20260909000000-later', $items[0]['identifier'] );
		$this->assertSame( '20260101000000-earliest', $items[1]['identifier'], 'Sorts first, but is listed last.' );
	}

	public function test_list_is_unchanged_when_every_recorded_migration_still_has_its_file(): void {
		$this->write_migration( '20260101000000-first', '' );
		$this->write_migration( '20260102000000-second', '' );

		$migrations = $this->plugin->get( Migrations::class );
		$migrations->run_pending();


		$command = new ListMigrationsCommand();
		$this->plugin->wire( $command );

		$command->handle( array(), array() );

		list( , , $items, $fields ) = $this->last_wp_cli_call();

		$this->assertSame( array( 'identifier', 'status' ), $fields, 'No new column.' );
		$this->assertSame( array( 'ran', 'ran' ), array_column( $items, 'status' ) );
	}

	public function test_orphans_reach_every_format(): void {
		$this->write_migration( '20260101000000-first', '' );

		$migrations = $this->plugin->get( Migrations::class );
		$migrations->run_pending();

		unlink( $this->plugin_dir . '/migrations/20260101000000-first.php' );


		$command = new ListMigrationsCommand();
		$this->plugin->wire( $command );

		$command->handle( array(), array( 'format' => 'json' ) );

		list( , $format, $items ) = $this->last_wp_cli_call();

		$this->assertSame( 'json', $format );
		$this->assertSame( 'orphaned', $items[0]['status'] );
	}

	/**
	 * The dangerous case: the migration already ran, and running it again under
	 * its new name would repeat whatever it did. Harmless for a dbDelta, not for
	 * a data backfill.
	 */
	public function test_run_refuses_a_probable_rename_and_runs_nothing(): void {
		$this->write_migration( '20260101000000-create-submissions-table', '' );

		$migrations = $this->plugin->get( Migrations::class );
		$migrations->run_pending();

		$this->rename_migration( '20260101000000-create-submissions-table', '20260101000000-create-submissions-tables' );

		try {
			$migrations->run_pending();
			$this->fail( 'Expected run_pending() to refuse a probable rename.' );
		} catch ( RenamedMigrationException $exception ) {
			$this->assertStringContainsString( '20260101000000-create-submissions-tables', $exception->getMessage() );
			$this->assertStringContainsString( '20260101000000-create-submissions-table,', $exception->getMessage() );
		}

		$this->assertSame(
			array( '20260101000000-create-submissions-table' ),
			$migrations->get_ran_migrations(),
			'Nothing was recorded.'
		);
	}

	/**
	 * A batch is usually a release. Running half of one because the other half
	 * is suspicious is worse than running none.
	 */
	public function test_a_probable_rename_stops_unrelated_pending_migrations_too(): void {
		$this->write_migration( '20260101000000-first', '' );

		$migrations = $this->plugin->get( Migrations::class );
		$migrations->run_pending();

		$this->rename_migration( '20260101000000-first', '20260101000000-first-table' );
		$this->write_migration( '20260202000000-unrelated', '' );

		$this->expectException( RenamedMigrationException::class );

		try {
			$migrations->run_pending();
		} finally {
			$this->assertNotContains( '20260202000000-unrelated', $migrations->get_ran_migrations() );
		}
	}

	public function test_force_runs_a_probable_rename_and_leaves_the_orphan_recorded(): void {
		$this->write_migration( '20260101000000-first', '' );

		$migrations = $this->plugin->get( Migrations::class );
		$migrations->run_pending();

		$this->rename_migration( '20260101000000-first', '20260101000000-first-table' );

		$migrations->run_pending( true );

		$this->assertSame(
			array( '20260101000000-first', '20260101000000-first-table' ),
			$migrations->get_ran_migrations(),
			'The ran-list is a ledger: forcing adds the new name and keeps the old.'
		);

		// The orphan is permanent, but it stops being an obstacle: the
		// migration that matched it has run, so nothing pending matches any
		// more and later runs need no --force. That is what makes keeping the
		// row affordable.
		$this->write_migration( '20260303000000-third', '' );
		$migrations->run_pending();

		$this->assertContains( '20260303000000-third', $migrations->get_ran_migrations() );
		$this->assertSame( array( '20260101000000-first' ), $migrations->get_orphaned_migrations() );
	}

	/**
	 * A deletion is not a rename. Nothing shares the orphan's timestamp, so
	 * there is nothing to be suspicious of.
	 */
	public function test_a_deleted_migration_does_not_block_an_unrelated_run(): void {
		$this->write_migration( '20260101000000-first', '' );

		$migrations = $this->plugin->get( Migrations::class );
		$migrations->run_pending();

		unlink( $this->plugin_dir . '/migrations/20260101000000-first.php' );
		$this->write_migration( '20260202000000-second', '' );

		$migrations->run_pending();

		$this->assertContains( '20260202000000-second', $migrations->get_ran_migrations() );
	}

	/**
	 * So an operator who renamed three files fixes three things in one pass,
	 * rather than discovering them one run at a time.
	 */
	public function test_run_reports_every_probable_rename_not_only_the_first(): void {
		$this->write_migration( '20260101000000-first', '' );
		$this->write_migration( '20260202000000-second', '' );

		$migrations = $this->plugin->get( Migrations::class );
		$migrations->run_pending();

		$this->rename_migration( '20260101000000-first', '20260101000000-first-table' );
		$this->rename_migration( '20260202000000-second', '20260202000000-second-table' );

		try {
			$migrations->run_pending();
			$this->fail( 'Expected run_pending() to refuse.' );
		} catch ( RenamedMigrationException $exception ) {
			$this->assertStringContainsString( '20260101000000-first-table', $exception->getMessage() );
			$this->assertStringContainsString( '20260202000000-second-table', $exception->getMessage() );
		}
	}

	/**
	 * A resume finishes a batch a timeout cut in half, and that batch's pending
	 * set was vetted when it started. Blocking it on a heuristic would strand a
	 * half-migrated site, which is worse than either outcome being guarded
	 * against.
	 */
	public function test_maybe_resume_interrupted_run_proceeds_despite_a_probable_rename(): void {
		$this->write_migration( '20260101000000-first', '' );

		$migrations = $this->plugin->get( Migrations::class );
		$migrations->run_pending();

		$this->rename_migration( '20260101000000-first', '20260101000000-first-table' );
		$this->set_migrations_option( $migrations, 'running_since', time() - ( 10 * MINUTE_IN_SECONDS ) );

		$migrations->maybe_resume_interrupted_run();

		$this->assertContains( '20260101000000-first-table', $migrations->get_ran_migrations() );
	}

	public function test_run_migrations_command_reports_a_probable_rename_as_an_error(): void {
		$this->write_migration( '20260101000000-first', '' );

		$migrations = $this->plugin->get( Migrations::class );
		$migrations->run_pending();

		$this->rename_migration( '20260101000000-first', '20260101000000-first-table' );


		$command = new RunMigrationsCommand();
		$this->plugin->wire( $command );

		$command->handle( array(), array() );

		list( $method, $message ) = $this->last_wp_cli_call();

		$this->assertSame( 'error', $method );
		$this->assertStringContainsString( 'looks like a rename', $message );
	}

	public function test_run_migrations_command_honors_the_force_flag(): void {
		$this->write_migration( '20260101000000-first', '' );

		$migrations = $this->plugin->get( Migrations::class );
		$migrations->run_pending();

		$this->rename_migration( '20260101000000-first', '20260101000000-first-table' );


		$command = new RunMigrationsCommand();
		$this->plugin->wire( $command );

		$command->handle( array(), array( 'force' => true ) );

		$this->assertSame( array( 'success', 'Ran 1 pending migration.' ), $this->last_wp_cli_call() );
	}

	/**
	 * The heuristic reads the leading digits up to the first `-`. A plugin
	 * naming its migrations some other way gets no guesses rather than wrong
	 * ones -- every such identifier would otherwise share the empty prefix and
	 * match every orphan.
	 */
	public function test_an_identifier_without_a_timestamp_is_never_a_probable_rename(): void {
		$this->write_migration( 'create-submissions-table', '' );

		$migrations = $this->plugin->get( Migrations::class );
		$migrations->run_pending();

		$this->rename_migration( 'create-submissions-table', 'create-submissions-tables' );

		$this->assertSame( array(), $migrations->get_probable_renames() );

		$migrations->run_pending();

		$this->assertContains( 'create-submissions-tables', $migrations->get_ran_migrations() );
	}

	/**
	 * Write a migration file under the given local name.
	 *
	 * @param string $name The filename stem, e.g. '20260101000000-first'.
	 * @param string $body The up() method body.
	 * @return void
	 */
	private function write_migration( string $name, string $body ): void {
		$this->write_plugin_file(
			'migrations/' . $name . '.php',
			"<?php\nuse Zestry\\WPToolkit\\Modules\\Migrations\\Migration;\nreturn new class extends Migration {\n    public function up(): void {\n        {$body}\n    }\n};\n"
		);
	}

	/**
	 * Rename a migration file on disk, which is what makes its recorded
	 * identifier an orphan -- the whole situation under test here.
	 *
	 * @param string $from The existing identifier.
	 * @param string $to   What to call it instead.
	 * @return void
	 */
	private function rename_migration( string $from, string $to ): void {
		rename(
			$this->plugin_dir . '/migrations/' . $from . '.php',
			$this->plugin_dir . '/migrations/' . $to . '.php'
		);
	}

	/**
	 * Read a value from Migrations' own dedicated Options group directly, the
	 * same group `get_migrations_options()` resolves internally, so a test can
	 * observe running_since without a public accessor for it.
	 *
	 * @param Migrations $migrations
	 * @param string     $key
	 * @return mixed
	 */
	private function migrations_option( Migrations $migrations, string $key ) {
		return $this->plugin->get( Options::class )->group( Migrations::OPTIONS_GROUP_NAME )->get( $key );
	}

	/**
	 * Write a value into Migrations' own dedicated Options group directly, to
	 * simulate a run that stopped at a particular point without needing to
	 * actually let PHP time out mid-test.
	 *
	 * @param Migrations $migrations
	 * @param string     $key
	 * @param mixed      $value
	 * @return void
	 */
	private function set_migrations_option( Migrations $migrations, string $key, $value ): void {
		$group = $this->plugin->get( Options::class )->group( Migrations::OPTIONS_GROUP_NAME );
		$group->set( $key, $value );
		$group->save();
	}

	/**
	 * Define the process-global WP_CLI constant that gates CLI registration.
	 *
	 * @return void
	 */
	private function define_wp_cli(): void {
		if ( ! defined( 'WP_CLI' ) ) {
			define( 'WP_CLI', true );
		}
	}

	/**
	 * The most recently recorded WP_CLI stub call, as a [method, ...args] tuple.
	 *
	 * @return array<int, mixed>|null
	 */
	private function last_wp_cli_call(): ?array {
		$calls = \WP_CLI::$calls;

		return $calls === array() ? null : $calls[ count( $calls ) - 1 ];
	}
}
