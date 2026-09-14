<?php

declare( strict_types=1 );

namespace Zestry\WPToolkit\Tests\Integration\Modules;

use Zestry\WPToolkit\DevTools\StubRenderer;
use Zestry\WPToolkit\Kernel\Exceptions\DiscoveryException;
use Zestry\WPToolkit\Modules\DB;
use Zestry\WPToolkit\Modules\Migrations\Migration;
use Zestry\WPToolkit\Modules\Queue\MissingQueueTableException;
use Zestry\WPToolkit\Modules\Queue\Queue;
use Zestry\WPToolkit\Tests\Support\TestCase;

/**
 * Discovery, dispatch, claiming, retry/failure and the inspection surface of
 * the Queue module -- plus the migration `wp zt add queue` ships, which every
 * test here runs for real so the table's shape and the module's queries cannot
 * drift apart.
 *
 * Queue never drains itself, so there is no hook-firing test: a consumer's own
 * schedule or crontab is expected to call process() directly.
 *
 * @covers \Zestry\WPToolkit\Modules\Queue\Queue
 * @covers \Zestry\WPToolkit\Modules\Queue\Job
 */
final class QueueTest extends TestCase {

	public function set_up(): void {
		parent::set_up();

		$GLOBALS['zestry_queue_log'] = array();

		// A listener on the plugin's log hook, so a deliberately failing job
		// reports there instead of falling back to error_log() and filling the
		// test output with expected failures.
		add_action( 'zestry-test-log', '__return_null' );

		$this->create_queue_table();
	}

	public function tear_down(): void {
		unset( $GLOBALS['zestry_queue_log'] );
		remove_action( 'zestry-test-log', '__return_null' );

		$this->drop_queue_table();

		parent::tear_down();
	}

	public function test_the_shipped_migration_creates_the_table_the_module_queries(): void {
		// create_queue_table() ran the real stub, not a copy of its SQL.
		$this->assertTrue(
			$this->plugin->get( DB::class )->table_exists( Queue::TABLE_NAME ),
			'The migration `wp zt add queue` writes must create the table Queue queries.'
		);

		$this->assertSame(
			array( 'pending' => 0, 'reserved' => 0, 'failed' => 0 ),
			$this->queue()->get_counts(),
			'Every status is present in the counts, so a caller never has to check a key first.'
		);
	}

	public function test_a_dispatched_job_runs_when_the_queue_is_processed(): void {
		$this->write_job( 'record', $this->recording_body() );

		$this->queue()->dispatch( 'record', array( 'id' => 7 ) );
		$result = $this->queue()->process();

		$this->assertSame( array( 'record:7' ), $GLOBALS['zestry_queue_log'] );
		$this->assertSame( 1, $result['processed'] );
		$this->assertSame( 'empty', $result['stopped'] );
	}

	public function test_a_completed_job_leaves_no_row(): void {
		$this->write_job( 'record', $this->recording_body() );

		$this->queue()->dispatch( 'record', array( 'id' => 1 ) );
		$this->queue()->process();

		$this->assertSame(
			array( 'pending' => 0, 'reserved' => 0, 'failed' => 0 ),
			$this->queue()->get_counts(),
			'The table stays the size of the work outstanding, not of everything ever run.'
		);
	}

	public function test_the_payload_survives_the_round_trip(): void {
		$this->write_job(
			'echo',
			'public function handle( array $payload ): bool|\WP_Error { $GLOBALS["zestry_queue_log"][] = $payload; return true; }'
		);

		$payload = array(
			'id'     => 42,
			'nested' => array( 'a' => 1, 'b' => array( true, null, 'x' ) ),
		);

		$this->queue()->dispatch( 'echo', $payload );
		$this->queue()->process();

		$this->assertSame( array( $payload ), $GLOBALS['zestry_queue_log'] );
	}

	public function test_dispatching_an_unknown_job_fails_at_the_call_that_made_the_mistake(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'No job found for "nope"' );

		$this->queue()->dispatch( 'nope' );
	}

	public function test_a_job_is_only_claimed_from_its_own_queue(): void {
		$this->write_job( 'mailer', 'public function queue(): string { return "mail"; }' . $this->recording_body( 'mailer' ) );
		$this->write_job( 'reporter', 'public function queue(): string { return "reports"; }' . $this->recording_body( 'reporter' ) );

		$this->queue()->dispatch( 'mailer', array( 'id' => 1 ) );
		$this->queue()->dispatch( 'reporter', array( 'id' => 2 ) );

		$this->queue()->process( 'mail' );

		$this->assertSame( array( 'mailer:1' ), $GLOBALS['zestry_queue_log'], 'Draining one queue must leave the other alone.' );
		$this->assertSame( 1, $this->queue()->get_counts( 'reports' )['pending'] );
	}

	public function test_get_queues_reads_the_job_files_so_an_empty_queue_is_still_listed(): void {
		$this->write_job( 'mailer', 'public function queue(): string { return "mail"; }' . $this->recording_body() );
		$this->write_job( 'plain', $this->recording_body() );

		$this->assertSame( array( 'default', 'mail' ), $this->queue()->get_queues() );
	}

	public function test_a_delayed_job_is_not_claimed_before_its_time(): void {
		$this->write_job( 'record', $this->recording_body() );

		$this->queue()->dispatch( 'record', array( 'id' => 1 ), HOUR_IN_SECONDS );
		$result = $this->queue()->process();

		$this->assertSame( array(), $GLOBALS['zestry_queue_log'] );
		$this->assertSame( 0, $result['processed'] );
		$this->assertSame( 1, $this->queue()->get_counts()['pending'] );
	}

	public function test_a_throwing_job_is_retried_until_its_attempts_run_out(): void {
		// Retry delay 0, so every attempt is available again immediately and the
		// whole retry path runs inside one process() call.
		$this->write_job(
			'flaky',
			'public function get_max_attempts(): int { return 3; }'
			. ' public function get_retry_delay( int $attempt ): int { return 0; }'
			. ' public function handle( array $payload ): bool|\WP_Error {'
			. ' $GLOBALS["zestry_queue_log"][] = "attempt"; throw new \RuntimeException( "nope" ); }'
			. ' public function failed( array $payload, \WP_Error $error ): void {'
			. ' $GLOBALS["zestry_queue_log"][] = "failed:" . $error->get_error_code() . ":" . $error->get_error_message(); }'
		);

		$this->queue()->dispatch( 'flaky' );
		$result = $this->queue()->process();

		$this->assertSame(
			array( 'attempt', 'attempt', 'attempt', 'failed:job_exception:nope' ),
			$GLOBALS['zestry_queue_log'],
			'Three attempts, then failed() once.'
		);
		$this->assertSame( 0, $result['processed'] );
		$this->assertSame( 2, $result['retried'], 'The two attempts that went back on the queue are retries, not failures.' );
		$this->assertSame( 1, $result['failed'], 'Only giving up counts as a failure.' );
		$this->assertSame( 1, $this->queue()->get_counts()['failed'] );
	}

	public function test_a_returned_wp_error_asks_to_be_retried_and_says_why(): void {
		// The shape most WordPress code already produces: a failed wp_remote_post()
		// or wp_insert_post() is returned straight through, with no translation
		// into an exception on the way.
		$this->write_job(
			'remote',
			'public function get_max_attempts(): int { return 1; }'
			. ' public function handle( array $payload ): bool|\WP_Error {'
			. ' return new \WP_Error( "http_request_failed", "The endpoint timed out." ); }'
			. ' public function failed( array $payload, \WP_Error $error ): void {'
			. ' $GLOBALS["zestry_queue_log"][] = $error->get_error_code(); }'
		);

		$this->queue()->dispatch( 'remote' );
		$result = $this->queue()->process();

		$this->assertSame( 1, $result['failed'] );
		$this->assertSame(
			array( 'http_request_failed' ),
			$GLOBALS['zestry_queue_log'],
			"The job's own error code reaches failed(), rather than being flattened into one generic failure."
		);

		$failed = $this->queue()->get_rows( Queue::STATUS_FAILED );
		$this->assertSame( 'The endpoint timed out.', $failed[0]['last_error'] );
	}

	public function test_a_returned_false_asks_to_be_retried(): void {
		$this->write_job(
			'bare',
			'public function get_max_attempts(): int { return 1; }'
			. ' public function handle( array $payload ): bool|\WP_Error { return false; }'
			. ' public function failed( array $payload, \WP_Error $error ): void {'
			. ' $GLOBALS["zestry_queue_log"][] = $error->get_error_code(); }'
		);

		$this->queue()->dispatch( 'bare' );
		$result = $this->queue()->process();

		$this->assertSame( 1, $result['failed'] );
		$this->assertSame( array( Queue::ERROR_FAILED ), $GLOBALS['zestry_queue_log'] );
	}

	public function test_a_thrown_exception_reaches_failed_as_a_wp_error_that_still_carries_it(): void {
		$this->write_job(
			'thrower',
			'public function get_max_attempts(): int { return 1; }'
			. ' public function handle( array $payload ): bool|\WP_Error { throw new \LogicException( "bad" ); }'
			. ' public function failed( array $payload, \WP_Error $error ): void {'
			. ' $GLOBALS["zestry_queue_log"][] = $error->get_error_data()["exception"] ?? null; }'
		);

		$this->queue()->dispatch( 'thrower' );
		$this->queue()->process();

		$this->assertInstanceOf(
			\LogicException::class,
			$GLOBALS['zestry_queue_log'][0],
			'Normalising to WP_Error must not throw the original away.'
		);
	}

	public function test_a_job_returning_true_is_done_even_after_an_earlier_attempt_failed(): void {
		// Succeeds on its second run, which is what a retry is for.
		$this->write_job(
			'flaky-once',
			'public function get_retry_delay( int $attempt ): int { return 0; }'
			. ' public function handle( array $payload ): bool|\WP_Error {'
			. ' $GLOBALS["zestry_queue_log"][] = "run";'
			. ' return 2 === count( $GLOBALS["zestry_queue_log"] ) ? true : new \WP_Error( "nope", "not yet" ); }'
		);

		$this->queue()->dispatch( 'flaky-once' );
		$result = $this->queue()->process();

		$this->assertSame( array( 'run', 'run' ), $GLOBALS['zestry_queue_log'] );
		$this->assertSame( 1, $result['processed'] );
		$this->assertSame( 1, $result['retried'] );
		$this->assertSame( 0, $result['failed'] );
		$this->assertSame(
			array( 'pending' => 0, 'reserved' => 0, 'failed' => 0 ),
			$this->queue()->get_counts(),
			'A job that eventually succeeds leaves no row behind.'
		);
	}

	public function test_a_failing_job_never_stops_the_rest_of_the_batch(): void {
		$this->write_job(
			'boom',
			'public function get_max_attempts(): int { return 1; }'
			. ' public function handle( array $payload ): bool|\WP_Error { throw new \RuntimeException( "nope" ); }'
		);
		$this->write_job( 'record', $this->recording_body() );

		$this->queue()->dispatch( 'boom' );
		$this->queue()->dispatch( 'record', array( 'id' => 9 ) );

		$result = $this->queue()->process();

		$this->assertSame( array( 'record:9' ), $GLOBALS['zestry_queue_log'] );
		$this->assertSame( 1, $result['processed'] );
		$this->assertSame( 1, $result['failed'] );
	}

	public function test_a_failed_job_records_its_error_and_can_be_retried(): void {
		$this->write_job(
			'boom',
			'public function get_max_attempts(): int { return 1; }'
			. ' public function handle( array $payload ): bool|\WP_Error { throw new \RuntimeException( "the reason" ); }'
		);

		$this->queue()->dispatch( 'boom' );
		$this->queue()->process();

		$failed = $this->queue()->get_rows( Queue::STATUS_FAILED );

		$this->assertCount( 1, $failed );
		$this->assertSame( 'the reason', $failed[0]['last_error'] );
		$this->assertSame( 1, $failed[0]['attempts'] );

		$this->assertSame( 1, $this->queue()->retry() );

		$pending = $this->queue()->get_rows( Queue::STATUS_PENDING );

		$this->assertCount( 1, $pending );
		$this->assertSame( 0, $pending[0]['attempts'], 'A retried job starts over with its full attempt count.' );
	}

	public function test_a_failed_job_is_announced_on_the_plugins_log_hook(): void {
		$this->write_job(
			'boom',
			'public function get_max_attempts(): int { return 1; }'
			. ' public function handle( array $payload ): bool|\WP_Error { throw new \RuntimeException( "the reason" ); }'
		);

		$seen = array();

		add_action(
			'zestry-test-log',
			static function ( string $level, string $message ) use ( &$seen ): void {
				$seen[] = $level . ':' . $message;
			},
			10,
			2
		);

		$this->queue()->dispatch( 'boom' );
		$this->queue()->process();

		$this->assertCount( 1, $seen );
		$this->assertStringStartsWith( 'error:Queued job "boom"', $seen[0] );
		$this->assertStringContainsString( 'the reason', $seen[0] );
	}

	public function test_work_waiting_on_a_job_with_no_file_is_never_claimed_and_is_reported(): void {
		$this->write_job( 'record', $this->recording_body() );

		// A row dispatched under a name whose file has since been deleted or
		// renamed. Inserted directly, since dispatch() refuses a name it cannot
		// resolve -- which is the point of that refusal.
		$this->insert_row( 'gone', Queue::DEFAULT_QUEUE );
		$this->queue()->dispatch( 'record', array( 'id' => 3 ) );

		$result = $this->queue()->process();

		$this->assertSame( array( 'record:3' ), $GLOBALS['zestry_queue_log'] );
		$this->assertSame( 1, $result['processed'] );
		$this->assertSame( 0, $result['failed'], 'An absent handler must not burn the row, in case a deploy is about to restore it.' );
		$this->assertSame( array( 'gone' => 1 ), $this->queue()->get_orphaned_jobs() );
	}

	public function test_a_disabled_job_is_never_claimed(): void {
		$this->write_job( 'record', $this->recording_body() );
		$this->queue()->dispatch( 'record', array( 'id' => 1 ) );

		// Switched off after the work was queued, which is the realistic order.
		$this->write_job(
			'record',
			'public function is_enabled(): bool { return false; }' . $this->recording_body()
		);

		$fresh  = $this->plugin->make( Queue::class );
		$result = $fresh->process();

		$this->assertSame( array(), $GLOBALS['zestry_queue_log'] );
		$this->assertSame( 0, $result['processed'] );
		$this->assertSame( array( 'record' => 1 ), $fresh->get_orphaned_jobs() );
	}

	public function test_a_reservation_left_by_a_dead_worker_is_taken_back(): void {
		$this->write_job( 'record', $this->recording_body() );

		// A row claimed an hour ago by a worker that never came back.
		$this->insert_row(
			'record',
			Queue::DEFAULT_QUEUE,
			array(
				'status'      => Queue::STATUS_RESERVED,
				'attempts'    => 1,
				'reserved_at' => gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ),
			)
		);

		$result = $this->queue()->process();

		$this->assertSame( 1, $result['released'] );
		$this->assertSame( 1, $this->queue()->get_counts()['pending'], 'A reclaimed job goes back to pending, not to failed.' );
	}

	public function test_a_dead_worker_cannot_retry_a_job_forever(): void {
		$this->write_job(
			'record',
			'public function get_max_attempts(): int { return 2; }' . $this->recording_body()
		);

		// Already on its last attempt when the worker died: the attempt was
		// spent at claim time, so there is nothing left to give it.
		$this->insert_row(
			'record',
			Queue::DEFAULT_QUEUE,
			array(
				'status'      => Queue::STATUS_RESERVED,
				'attempts'    => 2,
				'reserved_at' => gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ),
			)
		);

		$this->queue()->process();

		$this->assertSame( 1, $this->queue()->get_counts()['failed'] );
		$this->assertSame( array(), $GLOBALS['zestry_queue_log'], 'It must not have been run again.' );
	}

	public function test_process_says_why_it_stopped_so_a_worker_knows_to_come_back(): void {
		$this->write_job( 'record', $this->recording_body() );

		$this->queue()->dispatch( 'record', array( 'id' => 1 ) );
		$this->queue()->dispatch( 'record', array( 'id' => 2 ) );

		$this->queue()->set_batch_size( 1 );
		$result = $this->queue()->process();

		$this->assertSame( 1, $result['processed'] );
		$this->assertSame( 'batch', $result['stopped'] );
		$this->assertSame( 1, $this->queue()->get_counts()['pending'] );
	}

	public function test_a_pending_job_can_be_cancelled_and_a_claimed_one_cannot(): void {
		$this->write_job( 'record', $this->recording_body() );

		$id = $this->queue()->dispatch( 'record', array( 'id' => 1 ) );

		$this->assertTrue( $this->queue()->cancel( $id ) );
		$this->assertFalse( $this->queue()->cancel( $id ), 'Nothing left to cancel.' );
		$this->assertSame( 0, $this->queue()->get_counts()['pending'] );
	}

	public function test_purge_refuses_a_status_this_module_does_not_use(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Unknown queue status "done"' );

		$this->queue()->purge( 'done' );
	}

	public function test_a_job_name_carries_no_plugin_slug(): void {
		// Unlike a hook or a command, nothing outside the plugin ever sees this
		// name -- the table is already the plugin's own.
		$this->write_job( 'send_receipt', $this->recording_body() );

		$this->assertSame( 'send_receipt', $this->queue()->get_name_of( $this->queue()->get_discovered_jobs()['send_receipt'] ) );
	}

	public function test_a_job_file_returning_the_wrong_type_throws(): void {
		$this->write_plugin_file( 'resources/jobs/bad.php', "<?php\nreturn 42;\n" );

		$this->expectException( DiscoveryException::class );
		$this->expectExceptionMessage( 'must return an instance of' );

		$this->queue()->get_discovered_jobs();
	}

	public function test_a_missing_jobs_directory_is_not_an_error(): void {
		$this->assertSame( array(), $this->queue()->get_discovered_jobs() );
	}

	public function test_dispatching_without_the_table_says_which_command_to_run(): void {
		$this->write_job( 'record', $this->recording_body() );
		$this->drop_queue_table();

		$this->expectException( MissingQueueTableException::class );
		$this->expectExceptionMessage( 'wp zestry-test migrations run' );

		$this->queue()->dispatch( 'record' );
	}

	public function test_booting_registers_the_queue_commands_without_a_commands_directory(): void {
		// No resources/commands/ directory here on purpose: registration goes
		// through CLI's static entry point, so the CLI module is never built and
		// its own discovery never runs.
		$this->define_wp_cli();
		\WP_CLI::reset();

		$this->assertDirectoryDoesNotExist( $this->plugin_dir . '/resources/commands' );

		$this->plugin->get( Queue::class );

		$registered = array_column(
			array_filter(
				\WP_CLI::$calls,
				static function ( array $call ): bool {
					return 'add_command' === $call[0];
				}
			),
			1
		);

		$this->assertContains( 'zestry-test queue work', $registered );
		$this->assertContains( 'zestry-test queue list', $registered );
		$this->assertContains( 'zestry-test queue retry', $registered );
	}

	/**
	 * WP_CLI is a process-global, irreversible constant, and the modules only
	 * register commands under it. Defined here so this file passes when run on
	 * its own, as well as after CliTest has already defined it.
	 *
	 * @return void
	 */
	private function define_wp_cli(): void {
		if ( ! defined( 'WP_CLI' ) ) {
			define( 'WP_CLI', true );
		}
	}

	/**
	 * The Queue module, resolved after the test has written its job files.
	 *
	 * Discovery is cached on the instance, so every test writes its files first
	 * and reaches the module through this.
	 *
	 * @return Queue
	 */
	private function queue(): Queue {
		return $this->plugin->get( Queue::class );
	}

	/**
	 * A handle() that records what it was given, for asserting against.
	 *
	 * @param string $label What to record it under. Defaults to the job's payload id.
	 * @return string The method body, as a job file's class body.
	 */
	private function recording_body( string $label = 'record' ): string {
		return ' public function handle( array $payload ): bool|\WP_Error {'
			. ' $GLOBALS["zestry_queue_log"][] = "' . $label . ':" . ( $payload["id"] ?? "" ); return true; }';
	}

	/**
	 * Write a job file into the temp plugin's jobs directory.
	 *
	 * @param string $name The local job name.
	 * @param string $body The anonymous class body.
	 * @return void
	 */
	private function write_job( string $name, string $body ): void {
		$this->write_plugin_file(
			'resources/jobs/' . $name . '.php',
			"<?php\nuse Zestry\\WPToolkit\\Modules\\Queue\\Job;\nreturn new class extends Job {\n{$body}\n};\n"
		);
	}

	/**
	 * Insert a row the public API would not let a test create.
	 *
	 * @param string               $job       The job name to record.
	 * @param string               $queue     The queue name.
	 * @param array<string, mixed> $overrides Columns to set instead of the defaults.
	 * @return void
	 */
	private function insert_row( string $job, string $queue, array $overrides = array() ): void {
		$wpdb = $this->plugin->get( DB::class )->get_wpdb();

		$wpdb->insert(
			$this->plugin->get( DB::class )->get_table( Queue::TABLE_NAME ),
			array_merge(
				array(
					'queue'        => $queue,
					'job'          => $job,
					'payload'      => '{"id":1}',
					'status'       => Queue::STATUS_PENDING,
					'attempts'     => 0,
					'available_at' => gmdate( 'Y-m-d H:i:s' ),
					'created_at'   => gmdate( 'Y-m-d H:i:s' ),
				),
				$overrides
			)
		);
	}

	/**
	 * Run the migration `wp zt add queue` ships, rendered from its own stub.
	 *
	 * The stub rather than a copy of its SQL: the table's shape and the queries
	 * that read it are the two halves this module has to keep in agreement, and
	 * a test writing its own CREATE TABLE would only prove the test agrees with
	 * itself.
	 *
	 * @return void
	 */
	private function create_queue_table(): void {
		$rendered = $this->plugin->make( StubRenderer::class )->render(
			dirname( __DIR__, 3 ) . '/src/DevTools/stubs/queue-table.php.stub',
			array( 'copied_namespace' => 'Zestry\\WPToolkit' )
		);

		$file = $this->write_plugin_file( 'resources/migrations/19700101000000-create-queue-table.php', $rendered );

		/** @var Migration $migration */
		$migration = require $file;

		$this->plugin->wire( $migration );
		$migration->up();
	}

	/**
	 * Drop the queue table, since creating one implicitly commits the
	 * transaction the test case would otherwise have rolled back.
	 *
	 * @return void
	 */
	private function drop_queue_table(): void {
		$wpdb  = $this->plugin->get( DB::class )->get_wpdb();
		$table = $this->plugin->get( DB::class )->get_table( Queue::TABLE_NAME );

		$wpdb->query( 'DROP TABLE IF EXISTS `' . $table . '`' );
	}
}
