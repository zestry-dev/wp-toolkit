<?php

/**
 * Queue API: `wp {slug} queue retry` command
 */

declare( strict_types=1 );

namespace Zestry\WPToolkit\Modules\Queue;

// Loaded by WordPress, never requested directly.
\defined( 'ABSPATH' ) || exit;

use Zestry\WPToolkit\Modules\CLI\Command;

/**
 * WP-CLI command: put failed jobs back on their queue.
 *
 * Registered directly by {@see Queue::on_boot()} via
 * {@see \Zestry\WPToolkit\Modules\CLI\CLI::register_command_for()} -- not discovered from a
 * file in your own `resources/commands/` directory, since this command exists
 * the moment the Queue module is added, with nothing to generate or maintain.
 */
class RetryQueueCommand extends Command {

	/**
	 * Put failed jobs back on their queue.
	 *
	 * A retried job starts over with its full attempt count, and runs at the
	 * next drain of its queue. Nothing about the failure is undone -- if the
	 * job got halfway before throwing, it does that half again.
	 *
	 * Retrying everything asks first, since a queue of failures is usually one
	 * cause, and putting them all back before fixing it just fails them all
	 * again.
	 *
	 * ## OPTIONS
	 *
	 * [<id>]
	 * : Retry one job, by the id `queue list` shows. Without it, every failed
	 * job is retried.
	 *
	 * [--queue=<queue>]
	 * : Retry only failures on this queue. Ignored when an id is given.
	 *
	 * [--yes]
	 * : Retry every failure without asking, for an unattended run.
	 *
	 * ## EXAMPLES
	 *
	 *     # Retry one job.
	 *     $ wp acme-plugin queue retry 481
	 *     Success: Put 1 job back on its queue.
	 *
	 *     # Retry every failure on one queue.
	 *     $ wp acme-plugin queue retry --queue=mail --yes
	 *     Success: Put 12 jobs back on their queue.
	 *
	 * @param array $args
	 * @param array $assoc_args
	 * @return void
	 */
	public function handle( array $args, array $assoc_args ): void {
		$id    = isset( $args[0] ) ? (int) $args[0] : null;
		$queue = \WP_CLI\Utils\get_flag_value( $assoc_args, 'queue', null );
		$queue = null === $queue ? null : (string) $queue;

		if ( null === $id ) {
			$this->confirm(
				null === $queue
					? 'Retry every failed job?'
					: \sprintf( 'Retry every failed job on the "%s" queue?', $queue )
			);
		}

		try {
			$retried = $this->queue()->retry( $id, $queue );
		} catch ( \Throwable $exception ) {
			$this->error( $exception->getMessage() );

			return;
		}

		if ( 0 === $retried ) {
			$this->success( 'Nothing to retry.' );

			return;
		}

		$this->success(
			\sprintf(
				'Put %d job%s back on %s queue.',
				$retried,
				1 === $retried ? '' : 's',
				1 === $retried ? 'its' : 'their'
			)
		);
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
