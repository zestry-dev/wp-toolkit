<?php

/**
 * Queue API: Queue module
 */

declare( strict_types=1 );

namespace Zestry\WPToolkit\Modules\Queue;

// Loaded by WordPress, never requested directly.
\defined( 'ABSPATH' ) || exit;

use Zestry\WPToolkit\Kernel\Contracts\Bootable;
use Zestry\WPToolkit\Kernel\Abstracts\Module;
use Zestry\WPToolkit\Kernel\Exceptions\DiscoveryException;
use Zestry\WPToolkit\Kernel\Traits\WithFolderWalker;
use Zestry\WPToolkit\Modules\CLI\CLI;
use Zestry\WPToolkit\Modules\DB;
use Zestry\WPToolkit\Modules\Path;

/**
 * Runs work outside the request that asked for it: a durable job queue backed
 * by a table of its own.
 *
 * A jobs directory contains PHP files, one per kind of work. Each file returns
 * a {@see Job} instance -- the *handler*, discovered by filename exactly like a
 * schedule or a route. The *work* is a row: `dispatch()` writes the job's name
 * and a payload to the queue table and returns immediately, and some later
 * process picks the row up and calls `handle()` with that payload.
 *
 * ```
 * // Anywhere in the request -- a form handler, a REST route, a webhook.
 * $this->with( Queue::class )->dispatch( 'send-receipt', array( 'order_id' => 42 ) );
 * ```
 *
 * > [!IMPORTANT]
 * > **Nothing here runs on its own. You decide when.** Booting this module only
 * > registers the `wp {slug} queue` commands. Call {@see process()} from a
 * > schedule, a real crontab, or a worker command -- see *Draining the queue*
 * > below.
 *
 * ## Named queues
 *
 * Every job belongs to a queue, named by its own {@see Job::queue()} and
 * defaulting to `default`. A queue is not a separate table or a separate
 * anything -- it is a label you drain independently, which is the point:
 * receipts that must go out within the minute and a nightly report rebuild want
 * different intervals, and one drained queue must not be held up behind the
 * other's slow work.
 *
 * ```
 * // resources/schedules/drain-mail.php  -- every five minutes
 * $this->with( Queue::class )->process( 'mail' );
 *
 * // resources/schedules/drain-reports.php  -- nightly
 * $this->with( Queue::class )->process( 'reports' );
 * ```
 *
 * `process()` with no argument drains every queue together, which is the right
 * answer until one kind of work starts starving another.
 *
 * ## Draining the queue
 *
 * `process()` claims and runs jobs until the queue is empty or a budget runs
 * out -- {@see set_batch_size()}, {@see set_time_limit()} and
 * {@see set_memory_limit_fraction()} -- and returns what it did, so a caller
 * can tell "nothing left" from "ran out of time". It is safe to call from
 * anywhere; the three usual triggers are:
 *
 * - **A schedule.** `resources/schedules/drain.php` calling `process()`. Easiest,
 *   and inherits WP-Cron's own limitation: an event fires only when a page load
 *   notices it is due, so on a quiet site it fires late.
 * - **A real crontab** running `wp {slug} queue work`, which is the same thing
 *   without the pseudo-cron.
 * - **Your own code**, immediately after dispatching, when you happen to know the
 *   work is short and the request can carry it.
 *
 * > [!NOTE]
 * > **Two workers running at once is fine, and needs no lock.** A job is claimed
 * > with a conditional `UPDATE` that only one worker can win, so overlapping
 * > runs divide the queue rather than duplicating it. That is the whole reason
 * > the claim is a row update rather than a read followed by a write.
 *
 * ## Failure
 *
 * **`handle()` returns `true` when the work is done, and anything else asks to
 * be retried** -- `false`, a `WP_Error`, or a thrown exception, which are all
 * turned into one `WP_Error` by {@see get_error_for()}. Returning the
 * `WP_Error` WordPress already handed you is usually the whole of a job's error
 * handling.
 *
 * Nothing propagates: one bad job does not stop the rest of the batch. The row
 * goes back to `pending` with its attempt counted and a delay from
 * {@see Job::get_retry_delay()}, until {@see Job::get_max_attempts()} is
 * reached; then it is marked `failed`, the error message is recorded, and
 * {@see Job::failed()} is called so you can react. Nothing deletes a failed
 * row: it stays for `wp {slug} queue list --status=failed` to show and
 * `wp {slug} queue retry` to put back.
 *
 * The attempt is counted **as the job is claimed**, not after it returns, which
 * is what makes a job that kills the whole PHP process -- a fatal error, a
 * timeout, an OOM -- survivable. Nothing can catch that, so the row is left
 * `reserved` with no worker behind it; {@see release_stale_reservations()}
 * reclaims it on the next run, and because the attempt was already counted it
 * eventually reaches `failed` instead of crashing every worker forever.
 *
 * ## The table
 *
 * One table, `{$wpdb->prefix}{plugin_prefix}_queue`, created by the migration
 * `wp zt add queue` writes into your `resources/migrations/` directory. Nothing
 * creates it behind your back, so **run your migrations before dispatching
 * anything** -- until then every call here throws
 * {@see MissingQueueTableException}, which names the command to run.
 *
 * A payload is stored as JSON, so it holds what JSON holds: scalars, arrays and
 * nested arrays. Objects are deliberately not serialized into it -- a queue row
 * can outlive the code that wrote it, and a `serialize()`d object whose class
 * has since changed comes back as something that fails far away from here. Put
 * an id in the payload and load the thing in `handle()`.
 *
 * @setup
 * Every budget `process()` runs under. All four are optional; the defaults suit
 * a five-minute schedule on ordinary hosting.
 *
 * ```
 * // bootstrap.php
 * return array(
 *     'acme_plugin_loaded' => array(
 *         Queue::class => static function ( Queue $queue ): void {
 *             $queue->set_batch_size( 50 );
 *             $queue->set_time_limit( 20 );
 *         },
 *     ),
 * );
 * ```
 */
class Queue extends Module implements Bootable {

	use WithFolderWalker;

	/**
	 * Default plugin-relative directory of job files.
	 */
	const JOBS_ROOT = 'resources/jobs';

	/**
	 * The local name of this module's table, as {@see DB::get_table()} takes it.
	 *
	 * Named here rather than written out in the migration, so the file that
	 * creates the table and the queries that read it cannot disagree.
	 */
	const TABLE_NAME = 'queue';

	/**
	 * The queue a job belongs to when {@see Job::queue()} says nothing.
	 */
	const DEFAULT_QUEUE = 'default';

	/**
	 * Waiting to be claimed, once `available_at` has passed.
	 */
	const STATUS_PENDING = 'pending';

	/**
	 * Claimed by a worker that has not yet finished with it.
	 */
	const STATUS_RESERVED = 'reserved';

	/**
	 * Out of attempts. Nothing will run it again until you retry it.
	 */
	const STATUS_FAILED = 'failed';

	/**
	 * The `WP_Error` code for a job that threw rather than returning.
	 *
	 * The original is under the `exception` key of the error's data, so nothing
	 * about it is lost on the way to {@see Job::failed()}.
	 */
	const ERROR_EXCEPTION = 'job_exception';

	/**
	 * The `WP_Error` code for a job that returned a bare `false`.
	 *
	 * There is nothing to say beyond "it did not work", which is the argument
	 * for returning a `WP_Error` of your own instead.
	 */
	const ERROR_FAILED = 'job_failed';

	/**
	 * The `WP_Error` code for a job whose worker died mid-run.
	 *
	 * Nothing caught anything -- a fatal error, a timeout, a killed process --
	 * so this is written by {@see release_stale_reservations()} when it takes
	 * the reservation back.
	 */
	const ERROR_INTERRUPTED = 'job_interrupted';

	/**
	 * The job finished, and its row is gone.
	 */
	private const OUTCOME_DONE = 'done';

	/**
	 * The job threw and has been put back for another attempt.
	 */
	private const OUTCOME_RETRY = 'retry';

	/**
	 * The job threw and has no attempts left.
	 */
	private const OUTCOME_FAILED = 'failed';

	/**
	 * How many times a claim is re-attempted when another worker wins the row.
	 *
	 * Only bounds a race, not the queue: losing every one of these means that
	 * many rows were taken by someone else in the meantime, which is a queue
	 * being drained rather than a queue that is stuck.
	 */
	private const CLAIM_ATTEMPTS = 10;

	/**
	 * Jobs run in one `process()` call before it stops, or 0 for no limit.
	 *
	 * @var int
	 */
	private int $batch_size = 50;

	/**
	 * Seconds `process()` may spend before it stops between jobs.
	 *
	 * @var int
	 */
	private int $time_limit = 20;

	/**
	 * Fraction of PHP's memory limit `process()` may reach before it stops.
	 *
	 * @var float
	 */
	private float $memory_limit_fraction = 0.9;

	/**
	 * Seconds a claimed job is trusted to still be running.
	 *
	 * @var int
	 */
	private int $stale_reservation_timeout = 300;

	/**
	 * Discovered jobs by local name, once the directory has been walked.
	 *
	 * @var array<string, Job>|null
	 */
	private ?array $discovered = null;

	/**
	 * How many jobs one `process()` call runs before stopping.
	 *
	 * The batch exists so a run ends predictably rather than when the work does:
	 * a queue that is filled faster than it drains would otherwise keep one
	 * worker busy indefinitely. Pass 0 to drain until the queue is empty or
	 * another budget stops it.
	 *
	 * @param int $jobs Jobs per run; 0 for no limit.
	 * @return void
	 */
	public function set_batch_size( int $jobs ): void {
		$this->batch_size = \max( 0, $jobs );
	}

	/**
	 * How long one `process()` call may spend before stopping.
	 *
	 * Checked between jobs, never during one -- a single job that runs longer
	 * than this is not interrupted, because there is no safe point to interrupt
	 * it at. The default is deliberately well under a typical PHP time limit,
	 * so the run ends by choice rather than by being killed halfway through a
	 * job.
	 *
	 * Capped at 80% of `max_execution_time` when PHP has one, which is why a
	 * generous value here is harmless on a web request and still useful under
	 * WP-CLI, where there is no limit to cap against.
	 *
	 * @param int $seconds Seconds per run; 0 for no limit.
	 * @return void
	 */
	public function set_time_limit( int $seconds ): void {
		$this->time_limit = \max( 0, $seconds );
	}

	/**
	 * The share of PHP's memory limit a run may reach before stopping.
	 *
	 * Checked between jobs, like the time limit. A worker that exhausts memory
	 * is killed mid-job, which costs an attempt and leaves a reserved row for
	 * {@see release_stale_reservations()} to clean up -- stopping short is
	 * cheaper.
	 *
	 * @param float $fraction Between 0 and 1; 0.9 unless you change it.
	 * @return void
	 * @throws \InvalidArgumentException When the fraction is not between 0 and 1.
	 */
	public function set_memory_limit_fraction( float $fraction ): void {
		if ( $fraction <= 0 || $fraction > 1 ) {
			throw new \InvalidArgumentException(
				\sprintf( 'The memory limit fraction must be greater than 0 and at most 1. Got: %s', $fraction )
			);
		}

		$this->memory_limit_fraction = $fraction;
	}

	/**
	 * How long a claimed job is trusted to still be running.
	 *
	 * A worker that dies without catching anything -- a fatal error, a timeout,
	 * a killed process -- leaves its row `reserved` forever. After this long,
	 * {@see release_stale_reservations()} takes it back.
	 *
	 * Make it comfortably longer than your slowest job: reclaiming a job that is
	 * genuinely still running lets a second worker run it concurrently with the
	 * first.
	 *
	 * @param int $seconds Seconds; five minutes unless you change it.
	 * @return void
	 */
	public function set_stale_reservation_timeout( int $seconds ): void {
		$this->stale_reservation_timeout = \max( 1, $seconds );
	}

	/**
	 * Put a job on its queue, to run the next time that queue is drained.
	 *
	 * The name is the job file's own, without `.php` --
	 * `resources/jobs/send-receipt.php` is `send-receipt`. It is resolved here
	 * rather than when the row is claimed, so a name with a typo in it fails at
	 * the call that made the mistake instead of sitting in the table unclaimed.
	 *
	 * Returns the new row's id, which {@see cancel()} takes.
	 *
	 * @param string               $job     The job's local name.
	 * @param array<string, mixed> $payload What `handle()` is given. Must be JSON-encodable.
	 * @param int                  $delay   Seconds to wait before it may run.
	 * @return int The queued row's id.
	 * @throws \InvalidArgumentException When no job file matches, or the payload will not encode.
	 * @throws MissingQueueTableException When the queue table does not exist.
	 * @throws DiscoveryException When the job file returns something other than a Job.
	 */
	public function dispatch( string $job, array $payload = array(), int $delay = 0 ): int {
		return $this->dispatch_at( $job, $payload, \time() + \max( 0, $delay ) );
	}

	/**
	 * Put a job on its queue, to run no earlier than a given moment.
	 *
	 * The same as {@see dispatch()} with a delay, for when you have the time
	 * rather than the interval. A timestamp already past means "as soon as the
	 * queue is next drained", not "now" -- nothing here runs a job itself.
	 *
	 * @param string               $job       The job's local name.
	 * @param array<string, mixed> $payload   What `handle()` is given. Must be JSON-encodable.
	 * @param int                  $timestamp Unix timestamp before which it will not run.
	 * @return int The queued row's id.
	 * @throws \InvalidArgumentException When no job file matches, or the payload will not encode.
	 * @throws MissingQueueTableException When the queue table does not exist.
	 * @throws DiscoveryException When the job file returns something other than a Job.
	 */
	public function dispatch_at( string $job, array $payload, int $timestamp ): int {
		$instance = $this->get_job( $job );
		$encoded  = \wp_json_encode( $payload );

		if ( false === $encoded ) {
			throw new \InvalidArgumentException(
				\sprintf(
					'The payload for job "%s" could not be encoded as JSON: %s. A payload holds what JSON holds --'
						. ' put an id in it and load the object in handle().',
					$job,
					\json_last_error_msg()
				)
			);
		}

		$wpdb = $this->get_wpdb();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$inserted = $wpdb->insert(
			$this->get_table(),
			array(
				'queue'        => $instance->queue(),
				'job'          => $job,
				'payload'      => $encoded,
				'status'       => self::STATUS_PENDING,
				'attempts'     => 0,
				'available_at' => \gmdate( 'Y-m-d H:i:s', $timestamp ),
				'created_at'   => \gmdate( 'Y-m-d H:i:s' ),
			),
			array( '%s', '%s', '%s', '%s', '%d', '%s', '%s' )
		);

		if ( false === $inserted ) {
			throw new \RuntimeException(
				\sprintf(
					'Could not queue job "%s". %s',
					$job,
					'' !== $wpdb->last_error ? 'MySQL said: ' . $wpdb->last_error : 'MySQL reported no error.'
				)
			);
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Claim and run queued jobs until the queue is empty or a budget runs out.
	 *
	 * Pass a queue name to drain only that one, which is how two kinds of work
	 * get two intervals; pass nothing to drain them all together.
	 *
	 * Reclaims abandoned reservations first, so a worker that died takes at most
	 * one drain to recover from rather than needing anything called by hand.
	 *
	 * Never throws on account of a job: a `handle()` that throws is caught,
	 * retried or failed, and the run continues. What it does throw is a problem
	 * with the queue itself -- a missing table, a job file returning the wrong
	 * type.
	 *
	 * The three counts are of *attempts*, not of rows: a job that throws and is
	 * put back counts once under `retried` and counts again when it next runs.
	 * `failed` is only the terminal kind -- a job that has run out of attempts
	 * and will not be tried again.
	 *
	 * The `stopped` key says why the run ended: `empty` (nothing left to claim),
	 * `batch`, `time` or `memory`. Anything but `empty` means there is more
	 * waiting, so a worker loop knows to come straight back.
	 *
	 * @param string|null $queue The queue to drain, or null for every queue.
	 * @return array{processed: int, retried: int, failed: int, released: int, stopped: string} What the run did.
	 * @throws MissingQueueTableException When the queue table does not exist.
	 * @throws DiscoveryException When a job file returns something other than a Job.
	 */
	public function process( ?string $queue = null ): array {
		$released  = $this->release_stale_reservations( $queue );
		$started   = \microtime( true );
		$processed = 0;
		$retried   = 0;
		$failed    = 0;
		$stopped   = 'empty';

		while ( true ) {
			// Attempts, not rows: a job retried with no delay is claimable again
			// immediately, and counting only completions would let one such job
			// hold a worker for as long as it keeps throwing.
			if ( 0 !== $this->batch_size && $processed + $retried + $failed >= $this->batch_size ) {
				$stopped = 'batch';
				break;
			}

			if ( $this->is_out_of_time( $started ) ) {
				$stopped = 'time';
				break;
			}

			if ( $this->is_out_of_memory() ) {
				$stopped = 'memory';
				break;
			}

			$claimed = $this->claim_next( $queue );

			if ( null === $claimed ) {
				break;
			}

			$outcome = $this->run_claimed( $claimed );

			if ( self::OUTCOME_DONE === $outcome ) {
				++$processed;

				continue;
			}

			if ( self::OUTCOME_RETRY === $outcome ) {
				++$retried;

				continue;
			}

			++$failed;
		}

		return array(
			'processed' => $processed,
			'retried'   => $retried,
			'failed'    => $failed,
			'released'  => $released,
			'stopped'   => $stopped,
		);
	}

	/**
	 * Take back every job claimed by a worker that never finished with it.
	 *
	 * Called by {@see process()} before it claims anything, so this is rarely
	 * worth calling yourself -- it is public because a row stuck `reserved` is
	 * the one state you cannot see your way out of from the outside.
	 *
	 * A reclaimed job goes back to `pending` and runs again. Its attempt was
	 * already counted when it was claimed, so a job that kills the process every
	 * time it runs reaches `failed` after {@see Job::get_max_attempts()} rather
	 * than being retried forever.
	 *
	 * @param string|null $queue The queue to reclaim within, or null for every queue.
	 * @return int How many reservations were taken back.
	 * @throws MissingQueueTableException When the queue table does not exist.
	 * @throws DiscoveryException When a job file returns something other than a Job.
	 */
	public function release_stale_reservations( ?string $queue = null ): int {
		$cutoff = \gmdate( 'Y-m-d H:i:s', \time() - $this->stale_reservation_timeout );
		$wpdb   = $this->get_wpdb();

		$sql    = 'SELECT id, job, payload, attempts FROM %i WHERE status = %s AND reserved_at < %s';
		$params = array( $this->get_table(), self::STATUS_RESERVED, $cutoff );

		if ( null !== $queue ) {
			$sql     .= ' AND queue = %s';
			$params[] = $queue;
		}

		// $sql is built above from literals and placeholders only; every value is in $params.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );

		if ( ! \is_array( $rows ) || array() === $rows ) {
			return 0;
		}

		foreach ( $rows as $row ) {
			$this->give_up_or_retry(
				$row,
				new \WP_Error(
					self::ERROR_INTERRUPTED,
					\sprintf(
						'The worker that claimed this job did not finish within %d seconds. It was most likely'
							. ' interrupted by a fatal error or a timeout.',
						$this->stale_reservation_timeout
					)
				)
			);
		}

		return \count( $rows );
	}

	/**
	 * Remove a queued job that has not been claimed yet.
	 *
	 * Only a `pending` row can be cancelled: one already claimed is running, and
	 * deleting it from under its worker would not stop it.
	 *
	 * @param int $id The row id {@see dispatch()} returned.
	 * @return bool True when a pending row was found and deleted.
	 * @throws MissingQueueTableException When the queue table does not exist.
	 */
	public function cancel( int $id ): bool {
		$wpdb = $this->get_wpdb();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$deleted = $wpdb->delete(
			$this->get_table(),
			array(
				'id'     => $id,
				'status' => self::STATUS_PENDING,
			),
			array( '%d', '%s' )
		);

		return 1 === (int) $deleted;
	}

	/**
	 * Put failed jobs back on their queue.
	 *
	 * Their attempt count is reset, so a retried job gets the same number of
	 * attempts it started with. Pass an id to retry one, a queue to retry that
	 * queue's failures, or neither to retry all of them.
	 *
	 * @param int|null    $id    One row to retry, or null for every failure.
	 * @param string|null $queue Restrict to one queue. Ignored when $id is given.
	 * @return int How many rows went back to pending.
	 * @throws MissingQueueTableException When the queue table does not exist.
	 */
	public function retry( ?int $id = null, ?string $queue = null ): int {
		$wpdb = $this->get_wpdb();
		$now  = \gmdate( 'Y-m-d H:i:s' );

		$sql    = 'UPDATE %i SET status = %s, attempts = 0, available_at = %s, reserved_at = NULL, last_error = NULL'
			. ' WHERE status = %s';
		$params = array( $this->get_table(), self::STATUS_PENDING, $now, self::STATUS_FAILED );

		if ( null !== $id ) {
			$sql     .= ' AND id = %d';
			$params[] = $id;
		} elseif ( null !== $queue ) {
			$sql     .= ' AND queue = %s';
			$params[] = $queue;
		}

		// $sql is built above from literals and placeholders only; every value is in $params.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		return (int) $wpdb->query( $wpdb->prepare( $sql, $params ) );
	}

	/**
	 * Delete every row with a given status.
	 *
	 * For clearing out failures you have read and dealt with, or a queue of
	 * pending work a release made meaningless. There is no "delete everything":
	 * a status is always named, so this cannot quietly take the running ones
	 * with it.
	 *
	 * @param string      $status One of the `STATUS_*` constants.
	 * @param string|null $queue  Restrict to one queue, or null for every queue.
	 * @return int How many rows were deleted.
	 * @throws \InvalidArgumentException When the status is not one this module uses.
	 * @throws MissingQueueTableException When the queue table does not exist.
	 */
	public function purge( string $status, ?string $queue = null ): int {
		$this->assert_known_status( $status );

		$wpdb = $this->get_wpdb();

		$sql    = 'DELETE FROM %i WHERE status = %s';
		$params = array( $this->get_table(), $status );

		if ( null !== $queue ) {
			$sql     .= ' AND queue = %s';
			$params[] = $queue;
		}

		// $sql is built above from literals and placeholders only; every value is in $params.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		return (int) $wpdb->query( $wpdb->prepare( $sql, $params ) );
	}

	/**
	 * How many rows a queue holds, by status.
	 *
	 * Every status this module uses is present in the return, zeroes included,
	 * so a caller never has to check a key before reading it.
	 *
	 * @param string|null $queue Restrict to one queue, or null for every queue.
	 * @return array<string, int> Status => row count.
	 * @throws MissingQueueTableException When the queue table does not exist.
	 */
	public function get_counts( ?string $queue = null ): array {
		$wpdb = $this->get_wpdb();

		$sql    = 'SELECT status, COUNT(*) AS total FROM %i';
		$params = array( $this->get_table() );

		if ( null !== $queue ) {
			$sql     .= ' WHERE queue = %s';
			$params[] = $queue;
		}

		$sql .= ' GROUP BY status';

		// $sql is built above from literals and placeholders only; every value is in $params.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );

		$counts = \array_fill_keys( self::get_statuses(), 0 );

		foreach ( \is_array( $rows ) ? $rows : array() as $row ) {
			$counts[ (string) $row['status'] ] = (int) $row['total'];
		}

		return $counts;
	}

	/**
	 * Read queued rows, newest first.
	 *
	 * The payload comes back decoded, so a row reads the way it was dispatched.
	 * For inspection -- an admin screen, `wp {slug} queue list`, a Site Health
	 * check counting failures -- rather than for running anything.
	 *
	 * @param string|null $status Restrict to one status, or null for every status.
	 * @param string|null $queue  Restrict to one queue, or null for every queue.
	 * @param int         $limit  How many rows at most.
	 * @return array<int, array{id: int, queue: string, job: string, payload: array<string, mixed>, status: string, attempts: int, available_at: string, reserved_at: string|null, created_at: string, last_error: string|null}>
	 * @throws \InvalidArgumentException When the status is not one this module uses.
	 * @throws MissingQueueTableException When the queue table does not exist.
	 */
	public function get_rows( ?string $status = null, ?string $queue = null, int $limit = 100 ): array {
		if ( null !== $status ) {
			$this->assert_known_status( $status );
		}

		$wpdb = $this->get_wpdb();

		$sql    = 'SELECT * FROM %i WHERE 1 = 1';
		$params = array( $this->get_table() );

		if ( null !== $status ) {
			$sql     .= ' AND status = %s';
			$params[] = $status;
		}

		if ( null !== $queue ) {
			$sql     .= ' AND queue = %s';
			$params[] = $queue;
		}

		$sql     .= ' ORDER BY id DESC LIMIT %d';
		$params[] = \max( 1, $limit );

		// $sql is built above from literals and placeholders only; every value is in $params.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );

		return \array_map( array( $this, 'hydrate_row' ), \is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Every queue name the discovered jobs put work on.
	 *
	 * Read off the job files rather than off the table, so a queue that is
	 * currently empty is still listed -- which is what makes this the right
	 * thing to write a schedule against.
	 *
	 * @return string[] Queue names, in alphabetical order.
	 * @throws DiscoveryException When a job file returns something other than a Job.
	 */
	public function get_queues(): array {
		$queues = array();

		foreach ( $this->get_discovered_jobs() as $instance ) {
			$queues[ $instance->queue() ] = true;
		}

		$names = \array_keys( $queues );
		\sort( $names );

		return $names;
	}

	/**
	 * Every job name with work waiting that nothing on disk can run.
	 *
	 * A job's identity is its filename, so deleting or renaming a job file
	 * abandons every row already dispatched under the old name. Those rows are
	 * never claimed -- a worker only claims work it has a handler for, which is
	 * what stops a missing handler from failing rows a deploy is about to
	 * restore -- so they simply wait, and this is what says so.
	 *
	 * A job switched off with `is_enabled()` is reported here too, for the same
	 * reason: its rows are waiting on something that is not going to happen
	 * until you change your mind.
	 *
	 * This reports; nothing acts on it. Restore the file, or
	 * {@see purge()} the rows.
	 *
	 * @return array<string, int> Job name => rows waiting, most first.
	 * @throws MissingQueueTableException When the queue table does not exist.
	 * @throws DiscoveryException When a job file returns something other than a Job.
	 */
	public function get_orphaned_jobs(): array {
		$wpdb     = $this->get_wpdb();
		$runnable = \array_keys( $this->get_discovered_jobs() );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT job, COUNT(*) AS total FROM %i WHERE status = %s GROUP BY job',
				$this->get_table(),
				self::STATUS_PENDING
			),
			ARRAY_A
		);

		$orphaned = array();

		foreach ( \is_array( $rows ) ? $rows : array() as $row ) {
			$job = (string) $row['job'];

			if ( \in_array( $job, $runnable, true ) ) {
				continue;
			}

			$orphaned[ $job ] = (int) $row['total'];
		}

		\arsort( $orphaned );

		return $orphaned;
	}

	/**
	 * Every job file, wired and ready to run, keyed by its local name.
	 *
	 * A job whose `is_enabled()` returns false is not here, which is what keeps
	 * its rows unclaimed.
	 *
	 * @return array<string, Job> Wired instances keyed by local job name.
	 * @throws DiscoveryException When a job file returns something other than a Job.
	 */
	public function get_discovered_jobs(): array {
		if ( null !== $this->discovered ) {
			return $this->discovered;
		}

		$root_dir = $this->with( Path::class )->get_plugin_path( self::JOBS_ROOT );

		if ( ! \is_dir( $root_dir ) ) {
			// Never named, and the default is absent: this plugin has none of
			// these yet. Only a directory asked for by name is missing in the
			// sense worth throwing over.
			$this->discovered = array();

			return $this->discovered;
		}

		$instances = array();

		foreach ( $this->walk_folder( $root_dir, array( 'php' ), 1 ) as $file ) {
			$name     = \basename( $file, '.php' );
			$instance = $this->wire_job_file( $root_dir . '/' . $file );

			// Wired first, so is_enabled() can reach a module with `with()`. A job
			// switched off is never claimed, and rows already dispatched under its
			// name wait -- get_orphaned_jobs() reports them.
			if ( ! $instance->is_enabled() ) {
				continue;
			}

			$instances[ $name ] = $instance;
		}

		$this->discovered = $instances;

		return $this->discovered;
	}

	/**
	 * This job's name, from the file it was discovered in.
	 *
	 * @param Job $job The instance to look up.
	 * @return string The local job name {@see dispatch()} takes.
	 * @throws \InvalidArgumentException When the instance was not discovered by this module.
	 * @throws DiscoveryException When a job file returns something other than a Job.
	 */
	public function get_name_of( Job $job ): string {
		$name = \array_search( $job, $this->get_discovered_jobs(), true );

		if ( false === $name ) {
			throw new \InvalidArgumentException(
				\sprintf( 'The given %s instance was not discovered by this Queue module.', Job::class )
			);
		}

		return (string) $name;
	}

	/**
	 * This module's table, named the way everything else names one.
	 *
	 * @return string The full `{$wpdb->prefix}{plugin_prefix}_queue` table name.
	 * @throws MissingQueueTableException When the table does not exist.
	 */
	public function get_table(): string {
		$db = $this->with( DB::class );

		if ( ! $db->table_exists( self::TABLE_NAME ) ) {
			throw MissingQueueTableException::for_table( $db->get_table( self::TABLE_NAME ), $this->get_plugin()->get_slug() );
		}

		return $db->get_table( self::TABLE_NAME );
	}

	/**
	 * Register the queue commands, and nothing else.
	 *
	 * Deliberately the only thing this method does: this module never decides on
	 * its own when the queue is drained, and the commands are themselves invoked
	 * by hand or by a crontab you wrote.
	 *
	 * Registration goes through CLI's `static` entry point, which needs no CLI
	 * instance -- so a plugin using the queue without file-based commands never
	 * builds the CLI module, while the command names stay namespaced the same
	 * way a discovered command's would be.
	 *
	 * @return void
	 *
	 * @internal
	 */
	public function on_boot(): void {
		if ( $this->get_plugin()->is_wp_cli() ) {
			CLI::register_command_for( $this->get_plugin(), 'queue work', new WorkQueueCommand() );
			CLI::register_command_for( $this->get_plugin(), 'queue list', new ListQueueCommand() );
			CLI::register_command_for( $this->get_plugin(), 'queue retry', new RetryQueueCommand() );
		}
	}

	/**
	 * Whether this run has spent its time budget.
	 *
	 * Held to 80% of PHP's own `max_execution_time` when there is one, so a run
	 * configured generously for WP-CLI (where there is no limit) still stops
	 * before the web server kills it mid-job.
	 *
	 * @param float $started The `microtime( true )` the run began at.
	 * @return bool
	 */
	private function is_out_of_time( float $started ): bool {
		if ( 0 === $this->time_limit ) {
			return false;
		}

		$limit     = $this->time_limit;
		$execution = (int) \ini_get( 'max_execution_time' );

		if ( $execution > 0 ) {
			$limit = \min( $limit, (int) \floor( $execution * 0.8 ) );
		}

		return ( \microtime( true ) - $started ) >= \max( 1, $limit );
	}

	/**
	 * Whether this run is close enough to PHP's memory limit to stop.
	 *
	 * @return bool
	 */
	private function is_out_of_memory(): bool {
		$limit = \wp_convert_hr_to_bytes( (string) \ini_get( 'memory_limit' ) );

		if ( $limit <= 0 ) {
			return false;
		}

		return \memory_get_usage( true ) >= $limit * $this->memory_limit_fraction;
	}

	/**
	 * Claim the next runnable job, or null when there is nothing to claim.
	 *
	 * Two statements rather than one: a row is read, then updated with its own
	 * previous status in the `WHERE`. Only one worker's update can match, so the
	 * loser sees zero rows affected and goes round for the next row instead of
	 * running the same job twice. That is what makes overlapping workers safe
	 * without a lock of any kind.
	 *
	 * Only jobs with a handler on disk are considered. A row whose job file is
	 * gone or switched off is never claimed, so a deploy that briefly removes a
	 * file does not burn that job's attempts -- {@see get_orphaned_jobs()}
	 * reports what is waiting.
	 *
	 * @param string|null $queue The queue to claim within, or null for every queue.
	 * @return array<string, mixed>|null The claimed row, with its attempt already counted.
	 * @throws MissingQueueTableException When the queue table does not exist.
	 * @throws DiscoveryException When a job file returns something other than a Job.
	 */
	private function claim_next( ?string $queue ): ?array {
		$runnable = \array_keys( $this->get_discovered_jobs() );

		if ( array() === $runnable ) {
			return null;
		}

		$wpdb  = $this->get_wpdb();
		$table = $this->get_table();

		// phpcs:ignore Generic.CodeAnalysis.ForLoopWithTestFunctionCall
		for ( $attempt = 0; $attempt < self::CLAIM_ATTEMPTS; $attempt++ ) {
			$now          = \gmdate( 'Y-m-d H:i:s' );
			$placeholders = \implode( ', ', \array_fill( 0, \count( $runnable ), '%s' ) );

			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$sql    = 'SELECT id, job, payload, attempts FROM %i WHERE status = %s AND available_at <= %s'
				. ' AND job IN (' . $placeholders . ')';
			$params = \array_merge( array( $table, self::STATUS_PENDING, $now ), $runnable );

			if ( null !== $queue ) {
				$sql     .= ' AND queue = %s';
				$params[] = $queue;
			}

			$sql .= ' ORDER BY available_at ASC, id ASC LIMIT 1';

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
			$row = $wpdb->get_row( $wpdb->prepare( $sql, $params ), ARRAY_A );

			if ( ! \is_array( $row ) ) {
				return null;
			}

			// The status in the WHERE is the whole claim: whoever's UPDATE lands
			// first changes it, and every other worker's matches nothing.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$claimed = $wpdb->query(
				$wpdb->prepare(
					'UPDATE %i SET status = %s, reserved_at = %s, attempts = attempts + 1 WHERE id = %d AND status = %s',
					$table,
					self::STATUS_RESERVED,
					$now,
					(int) $row['id'],
					self::STATUS_PENDING
				)
			);

			if ( 1 === (int) $claimed ) {
				// Counted here, not after handle() returns, so work that kills
				// the process still spends an attempt.
				$row['attempts'] = (int) $row['attempts'] + 1;

				return $row;
			}
		}

		return null;
	}

	/**
	 * Run one claimed row, and dispose of it according to how it went.
	 *
	 * @param array<string, mixed> $row The claimed row.
	 * @return string One of the `OUTCOME_*` constants.
	 * @throws MissingQueueTableException When the queue table does not exist.
	 * @throws DiscoveryException When a job file returns something other than a Job.
	 */
	private function run_claimed( array $row ): string {
		$wpdb = $this->get_wpdb();

		try {
			$result = $this->get_job( (string) $row['job'] )->handle( $this->decode_payload( $row ) );
		} catch ( \Throwable $exception ) {
			return $this->give_up_or_retry( $row, self::get_error_for( $exception ) );
		}

		// True is the only thing that means done. `false` and a returned
		// WP_Error are the same request as a thrown exception -- try again --
		// and reach the same path.
		if ( true !== $result ) {
			return $this->give_up_or_retry( $row, self::get_error_for( $result ) );
		}

		// Done means gone. A completed job leaves no row, so the table stays the
		// size of the work outstanding rather than the work ever done.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( $this->get_table(), array( 'id' => (int) $row['id'] ), array( '%d' ) );

		return self::OUTCOME_DONE;
	}

	/**
	 * Fail a job for good, or put it back for another attempt.
	 *
	 * @param array<string, mixed> $row   The claimed row, with its attempt counted.
	 * @param \WP_Error            $error What went wrong.
	 * @return string OUTCOME_RETRY when it goes back on the queue, OUTCOME_FAILED when it does not.
	 * @throws MissingQueueTableException When the queue table does not exist.
	 * @throws DiscoveryException When a job file returns something other than a Job.
	 */
	private function give_up_or_retry( array $row, \WP_Error $error ): string {
		$wpdb     = $this->get_wpdb();
		$id       = (int) $row['id'];
		$name     = (string) $row['job'];
		$attempts = (int) $row['attempts'];
		$instance = $this->get_discovered_jobs()[ $name ] ?? null;

		// No handler means no opinion about retrying, and the row was claimed
		// before the file went away. One attempt is all it gets.
		$max_attempts = null === $instance ? 1 : $instance->get_max_attempts();

		if ( $attempts < $max_attempts ) {
			$delay = null === $instance ? \MINUTE_IN_SECONDS : $instance->get_retry_delay( $attempts );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update(
				$this->get_table(),
				array(
					'status'       => self::STATUS_PENDING,
					'reserved_at'  => null,
					'available_at' => \gmdate( 'Y-m-d H:i:s', \time() + \max( 0, $delay ) ),
					'last_error'   => $error->get_error_message(),
				),
				array( 'id' => $id ),
				array( '%s', '%s', '%s', '%s' ),
				array( '%d' )
			);

			return self::OUTCOME_RETRY;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$this->get_table(),
			array(
				'status'      => self::STATUS_FAILED,
				'reserved_at' => null,
				'last_error'  => $error->get_error_message(),
			),
			array( 'id' => $id ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);

		$this->report_failure( $name, $id, $error );

		if ( null === $instance ) {
			return self::OUTCOME_FAILED;
		}

		try {
			$instance->failed( $this->decode_payload( $row ), $error );
		} catch ( \Throwable $from_handler ) {
			// The job has already been recorded as failed. A failed() that
			// throws as well must not take the rest of the batch with it.
			$this->report_failure( $name, $id, self::get_error_for( $from_handler ) );
		}

		return self::OUTCOME_FAILED;
	}

	/**
	 * Report a job that has run out of attempts, without depending on a logger.
	 *
	 * Announced on the plugin's `{slug}-log` action, which is where a Log module
	 * -- or a handler of your own -- picks it up. Nothing listening means the
	 * message still reaches `error_log()`, since a queue that fails silently is
	 * the worst outcome here.
	 *
	 * The action rather than the Log module itself: this module must keep
	 * working for a plugin that never added one, and a hook has no class or
	 * method signature to depend on.
	 *
	 * @param string    $job   The job's local name.
	 * @param int       $id    The queued row's id.
	 * @param \WP_Error $error What went wrong.
	 * @return void
	 */
	private function report_failure( string $job, int $id, \WP_Error $error ): void {
		$hook    = $this->get_plugin()->get_namespaced_name( 'log' );
		$message = \sprintf( 'Queued job "%s" (#%d) failed: %s', $job, $id, $error->get_error_message() );

		if ( ! \has_action( $hook ) ) {
			\error_log( $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log

			return;
		}

		\do_action(
			$hook,
			'error',
			$message,
			array(
				'job'  => $job,
				'id'   => $id,
				'code' => $error->get_error_code(),
			)
		);
	}

	/**
	 * A stored payload, back as the array it was dispatched as.
	 *
	 * A payload that will not decode is a failure rather than an empty array:
	 * handing `handle()` nothing would run the job against a payload it never
	 * agreed to, which is worse than not running it.
	 *
	 * @param array<string, mixed> $row The queued row.
	 * @return array<string, mixed>
	 * @throws \RuntimeException When the stored payload is not decodable JSON.
	 */
	private function decode_payload( array $row ): array {
		$decoded = \json_decode( (string) $row['payload'], true );

		if ( ! \is_array( $decoded ) ) {
			throw new \RuntimeException(
				\sprintf(
					'The stored payload for job "%s" (#%d) is not valid JSON: %s',
					(string) $row['job'],
					(int) $row['id'],
					\json_last_error_msg()
				)
			);
		}

		return $decoded;
	}

	/**
	 * One database row, with its columns typed and its payload decoded.
	 *
	 * @param array<string, mixed> $row A raw row.
	 * @return array{id: int, queue: string, job: string, payload: array<string, mixed>, status: string, attempts: int, available_at: string, reserved_at: string|null, created_at: string, last_error: string|null}
	 */
	private function hydrate_row( array $row ): array {
		$payload = \json_decode( (string) $row['payload'], true );

		return array(
			'id'           => (int) $row['id'],
			'queue'        => (string) $row['queue'],
			'job'          => (string) $row['job'],
			// Reporting, not running: a payload too broken to decode still has
			// a row worth showing, and decode_payload() is what refuses to run it.
			'payload'      => \is_array( $payload ) ? $payload : array(),
			'status'       => (string) $row['status'],
			'attempts'     => (int) $row['attempts'],
			'available_at' => (string) $row['available_at'],
			'reserved_at'  => null === $row['reserved_at'] ? null : (string) $row['reserved_at'],
			'created_at'   => (string) $row['created_at'],
			'last_error'   => null === $row['last_error'] ? null : (string) $row['last_error'],
		);
	}

	/**
	 * One discovered job by name.
	 *
	 * @param string $name The job's local name.
	 * @return Job The wired instance.
	 * @throws \InvalidArgumentException When no enabled job file matches the name.
	 * @throws DiscoveryException When a job file returns something other than a Job.
	 */
	private function get_job( string $name ): Job {
		$jobs = $this->get_discovered_jobs();

		if ( ! isset( $jobs[ $name ] ) ) {
			throw new \InvalidArgumentException(
				\sprintf(
					'No job found for "%s". Expected %s to return a %s, and its is_enabled() to be true.',
					$name,
					$this->with( Path::class )->get_plugin_path( self::JOBS_ROOT ) . '/' . $name . '.php',
					Job::class
				)
			);
		}

		return $jobs[ $name ];
	}

	/**
	 * Require a job file and wire the instance it returns.
	 *
	 * @param string $file Absolute path to the job file.
	 * @return Job
	 * @throws DiscoveryException When the file does not return a Job instance.
	 */
	private function wire_job_file( string $file ): Job {
		/** @var Job $instance */
		$instance = require $file;

		if ( ! $instance instanceof Job ) {
			throw new DiscoveryException(
				\sprintf(
					'The file "%s" must return an instance of %s. Got: %s',
					$file,
					Job::class,
					\is_object( $instance ) ? $instance::class : \gettype( $instance )
				)
			);
		}

		$this->get_plugin()->wire( $instance );

		return $instance;
	}

	/**
	 * Refuse a status this module does not use.
	 *
	 * @param string $status The status to check.
	 * @return void
	 * @throws \InvalidArgumentException When the status is not one of the STATUS_* constants.
	 */
	private function assert_known_status( string $status ): void {
		if ( \in_array( $status, self::get_statuses(), true ) ) {
			return;
		}

		throw new \InvalidArgumentException(
			\sprintf(
				'Unknown queue status "%s". Use one of: %s.',
				$status,
				\implode( ', ', self::get_statuses() )
			)
		);
	}

	/**
	 * WordPress's own `$wpdb`, assigned rather than chained.
	 *
	 * @return \wpdb
	 */
	private function get_wpdb(): \wpdb {
		return $this->with( DB::class )->get_wpdb();
	}

	/**
	 * However a job reported failure, as one `WP_Error`.
	 *
	 * Three things can arrive here and they are all the same request -- try
	 * again -- so they are made one type before anything acts on them: your
	 * {@see Job::failed()} branches on a code rather than on whichever of the
	 * three the job happened to use, and `last_error` is filled the same way
	 * either way.
	 *
	 * A thrown exception keeps everything: it goes under the `exception` key of
	 * the error's data, so the stack trace is still there for whoever wants it.
	 *
	 * @param \Throwable|\WP_Error|bool $failure What the job threw or returned. Only `false` ever reaches here.
	 * @return \WP_Error
	 */
	public static function get_error_for( \Throwable|\WP_Error|bool $failure ): \WP_Error {
		if ( $failure instanceof \WP_Error ) {
			return $failure;
		}

		if ( $failure instanceof \Throwable ) {
			return new \WP_Error(
				self::ERROR_EXCEPTION,
				$failure->getMessage(),
				array( 'exception' => $failure )
			);
		}

		return new \WP_Error( self::ERROR_FAILED, 'The job returned false.' );
	}

	/**
	 * Every status a queued row can hold.
	 *
	 * @return string[]
	 */
	public static function get_statuses(): array {
		return array( self::STATUS_PENDING, self::STATUS_RESERVED, self::STATUS_FAILED );
	}
}
