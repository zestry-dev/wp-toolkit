<?php

/**
 * Queue API: `wp {slug} queue work` command
 */

declare( strict_types=1 );

namespace Zestry\WPToolkit\Modules\Queue;

// Loaded by WordPress, never requested directly.
\defined( 'ABSPATH' ) || exit;

use Zestry\WPToolkit\Modules\CLI\Command;

/**
 * WP-CLI command: drain queued jobs.
 *
 * Registered directly by {@see Queue::on_boot()} via
 * {@see \Zestry\WPToolkit\Modules\CLI\CLI::register_command_for()} -- not discovered from a
 * file in your own `resources/commands/` directory, since this command exists
 * the moment the Queue module is added, with nothing to generate or maintain.
 */
class WorkQueueCommand extends Command {

	/**
	 * Claim and run queued jobs until the queue is empty or a budget runs out.
	 *
	 * The command a real crontab runs, and the reason a queue does not have to
	 * depend on WP-Cron noticing anything. One invocation is one batch: it
	 * reports why it stopped, and `empty` is the only reason that means there
	 * is nothing more to do.
	 *
	 * Running two of these at once is safe. Jobs are claimed one row at a time
	 * with a conditional update, so a second worker takes the next job rather
	 * than the same one.
	 *
	 * ## OPTIONS
	 *
	 * [--queue=<queue>]
	 * : Drain only this queue. Defaults to draining every queue together, which
	 * is right until one kind of work starts holding up another.
	 *
	 * [--batch=<jobs>]
	 * : Run at most this many jobs, overriding the module's configured batch
	 * size. 0 drains until the queue is empty or another budget stops it.
	 *
	 * [--max-seconds=<seconds>]
	 * : Spend at most this long, overriding the module's configured time limit.
	 * Checked between jobs, so a single long job is never interrupted. Still
	 * held to 80% of PHP's own max_execution_time when there is one.
	 *
	 * ## EXAMPLES
	 *
	 *     # Drain every queue once, under the configured budgets.
	 *     $ wp acme-plugin queue work
	 *     Success: Ran 12 jobs. Queue empty.
	 *
	 *     # Drain one queue, from a crontab that runs every minute.
	 *     $ wp acme-plugin queue work --queue=mail --max-seconds=50
	 *     Success: Ran 40 jobs, 1 failed for good. Stopped on the time limit -- more is waiting.
	 *
	 * @param array $args
	 * @param array $assoc_args
	 * @return void
	 */
	public function handle( array $args, array $assoc_args ): void {
		$queue = \WP_CLI\Utils\get_flag_value( $assoc_args, 'queue', null );
		$batch = \WP_CLI\Utils\get_flag_value( $assoc_args, 'batch', null );
		$limit = \WP_CLI\Utils\get_flag_value( $assoc_args, 'max-seconds', null );

		if ( null !== $batch ) {
			$this->queue()->set_batch_size( (int) $batch );
		}

		if ( null !== $limit ) {
			$this->queue()->set_time_limit( (int) $limit );
		}

		try {
			$result = $this->queue()->process( null === $queue ? null : (string) $queue );
		} catch ( \Throwable $exception ) {
			$this->error( $exception->getMessage() );

			return;
		}

		if ( 0 < $result['released'] ) {
			$this->warning(
				\sprintf(
					'Took back %d job%s left claimed by a worker that never finished.',
					$result['released'],
					1 === $result['released'] ? '' : 's'
				)
			);
		}

		$this->success( $this->describe( $result ) );
	}

	/**
	 * One sentence saying what the run did and whether more is waiting.
	 *
	 * @param array{processed: int, retried: int, failed: int, released: int, stopped: string} $result What process() returned.
	 * @return string
	 */
	private function describe( array $result ): string {
		if ( 0 === $result['processed'] && 0 === $result['retried'] && 0 === $result['failed'] ) {
			return 'Nothing to run.';
		}

		$ran = \sprintf(
			'Ran %d job%s',
			$result['processed'],
			1 === $result['processed'] ? '' : 's'
		);

		// Attempts rather than rows, and worth saying apart: a job put back for
		// another try is not a job that failed, and reporting it as one makes a
		// healthy queue look broken.
		if ( 0 < $result['retried'] ) {
			$ran .= \sprintf( ', %d put back to retry', $result['retried'] );
		}

		if ( 0 < $result['failed'] ) {
			$ran .= \sprintf( ', %d failed for good', $result['failed'] );
		}

		$reasons = array(
			'empty'  => 'Queue empty.',
			'batch'  => 'Stopped on the batch size -- more is waiting.',
			'time'   => 'Stopped on the time limit -- more is waiting.',
			'memory' => 'Stopped on the memory limit -- more is waiting.',
		);

		return $ran . '. ' . ( $reasons[ $result['stopped'] ] ?? 'Stopped.' );
	}

	/**
	 * The module that registered this command.
	 *
	 * Not a property: building a module boots it, and a declaration would hide
	 * that behind a type name.
	 *
	 * @return Queue
	 */
	private function queue(): Queue {
		return $this->with( Queue::class );
	}
}
