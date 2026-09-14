<?php

/**
 * Queue API: `wp {slug} queue list` command
 */

declare( strict_types=1 );

namespace Zestry\WPToolkit\Modules\Queue;

// Loaded by WordPress, never requested directly.
\defined( 'ABSPATH' ) || exit;

use Zestry\WPToolkit\Modules\CLI\Command;

/**
 * WP-CLI command: show what is on the queue.
 *
 * Registered directly by {@see Queue::on_boot()} via
 * {@see \Zestry\WPToolkit\Modules\CLI\CLI::register_command_for()} -- not discovered from a
 * file in your own `resources/commands/` directory, since this command exists
 * the moment the Queue module is added, with nothing to generate or maintain.
 */
class ListQueueCommand extends Command {

	/**
	 * Show queued jobs, newest first.
	 *
	 * Three statuses, all answering the same question -- what will happen to
	 * this row?
	 *
	 * - `pending` -- waiting. The next drain of its queue runs it, once
	 *   `available_at` has passed.
	 * - `reserved` -- claimed by a worker right now. One still claimed long
	 *   after it should have finished belongs to a worker that died; the next
	 *   drain takes it back.
	 * - `failed` -- out of attempts. Nothing will run it again until
	 *   `queue retry` puts it back.
	 *
	 * `last_error` is the last exception message, and is filled in on a retry as
	 * well as a failure -- a `pending` row with one in it has already failed at
	 * least once.
	 *
	 * Warns when work is waiting on a job with no handler on disk. A job's
	 * identity is its filename, so deleting or renaming a job file abandons
	 * everything already dispatched under the old name: those rows are never
	 * claimed and never fail, they simply wait.
	 *
	 * ## OPTIONS
	 *
	 * [--status=<status>]
	 * : Show only rows with this status.
	 * ---
	 * options:
	 *   - pending
	 *   - reserved
	 *   - failed
	 * ---
	 *
	 * [--queue=<queue>]
	 * : Show only rows on this queue.
	 *
	 * [--limit=<rows>]
	 * : How many rows at most.
	 * ---
	 * default: 100
	 * ---
	 *
	 * [--format=<format>]
	 * : Render output in a particular format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - csv
	 *   - json
	 *   - yaml
	 *   - count
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     # Everything waiting or wrong.
	 *     $ wp acme-plugin queue list
	 *
	 *     # Just the failures, with their exception messages.
	 *     $ wp acme-plugin queue list --status=failed
	 *
	 *     # How much is waiting on one queue.
	 *     $ wp acme-plugin queue list --queue=mail --status=pending --format=count
	 *     412
	 *
	 * @param array $args
	 * @param array $assoc_args
	 * @return void
	 */
	public function handle( array $args, array $assoc_args ): void {
		$status = \WP_CLI\Utils\get_flag_value( $assoc_args, 'status', null );
		$queue  = \WP_CLI\Utils\get_flag_value( $assoc_args, 'queue', null );
		$limit  = (int) \WP_CLI\Utils\get_flag_value( $assoc_args, 'limit', 100 );
		$format = \WP_CLI\Utils\get_flag_value( $assoc_args, 'format', 'table' );

		try {
			$rows     = $this->queue()->get_rows(
				null === $status ? null : (string) $status,
				null === $queue ? null : (string) $queue,
				$limit
			);
			$orphaned = $this->queue()->get_orphaned_jobs();
		} catch ( \Throwable $exception ) {
			$this->error( $exception->getMessage() );

			return;
		}

		foreach ( $orphaned as $job => $waiting ) {
			$this->warning(
				\sprintf(
					'%d job%s waiting on "%s", which no file in your jobs directory returns. Restore the file, or purge those rows.',
					$waiting,
					1 === $waiting ? ' is' : 's are',
					$job
				)
			);
		}

		$items = \array_map(
			static function ( array $row ): array {
				return array(
					'id'           => $row['id'],
					'queue'        => $row['queue'],
					'job'          => $row['job'],
					'status'       => $row['status'],
					'attempts'     => $row['attempts'],
					'available_at' => $row['available_at'],
					'last_error'   => $row['last_error'] ?? '',
				);
			},
			$rows
		);

		\WP_CLI\Utils\format_items(
			$format,
			$items,
			array( 'id', 'queue', 'job', 'status', 'attempts', 'available_at', 'last_error' )
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
