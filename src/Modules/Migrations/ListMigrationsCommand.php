<?php

/**
 * Migrations API: `wp {slug} migrations list` command
 */

declare( strict_types=1 );

namespace Zestry\WPToolkit\Modules\Migrations;

// Loaded by WordPress, never requested directly.
\defined( 'ABSPATH' ) || exit;

use Zestry\WPToolkit\Modules\CLI\Command;

/**
 * WP-CLI command: list every discovered migration and whether it has run.
 *
 * Registered directly by {@see Migrations::on_boot()} via
 * {@see \Zestry\WPToolkit\Modules\CLI\CLI::register_command_for()} -- not discovered from a
 * file in the consumer's own `commands/` directory, since this command
 * exists the moment the Migrations module is added to a project, with
 * nothing for the consumer to generate or maintain.
 */
class ListMigrationsCommand extends Command {

	/**
	 * @var Migrations
	 */
	public Migrations $migrations;

	/**
	 * List every discovered migration and whether it has run.
	 *
	 * Three statuses, all answering the same question -- will `migrations run`
	 * execute this?
	 *
	 * - `ran` -- recorded as run, and its file is still there.
	 * - `pending` -- on disk, never recorded. The next run executes it.
	 * - `orphaned` -- recorded as run, and its file is gone. Nothing will run
	 *   it, because there is nothing left to run.
	 *
	 * An orphan is either a deleted migration or a renamed one, and this does
	 * not try to tell them apart. It matters because a rename produces *two*
	 * rows -- the new filename as `pending`, the recorded name as `orphaned` --
	 * sharing a timestamp prefix, and that adjacency is the signal. Renaming a
	 * migration makes it one the site has never run, so the next run runs it
	 * again; before this reported orphans at all, the recorded identifier
	 * simply dropped out of the listing and `pending` was the only sign.
	 *
	 * Orphans come after every on-disk migration, in the order they ran. Exit
	 * status is 0 either way: this is a report, and an orphan is a normal
	 * steady state for a plugin that has deliberately deleted an old migration.
	 *
	 * ## OPTIONS
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
	 * @param array $args
	 * @param array $assoc_args
	 * @return void
	 */
	public function handle( array $args, array $assoc_args ): void {
		$ran = $this->migrations->get_ran_migrations();

		$items = \array_map(
			static function ( string $identifier ) use ( $ran ): array {
				return array(
					'identifier' => $identifier,
					'status'     => \in_array( $identifier, $ran, true ) ? 'ran' : 'pending',
				);
			},
			$this->migrations->get_discovered_migrations()
		);

		// Appended rather than merged and re-sorted: an orphan has no file to
		// sort against, and keeping them in the order they ran is the only
		// ordering the ran-list can supply.
		foreach ( $this->migrations->get_orphaned_migrations() as $identifier ) {
			$items[] = array(
				'identifier' => $identifier,
				'status'     => 'orphaned',
			);
		}

		$format = \WP_CLI\Utils\get_flag_value( $assoc_args, 'format', 'table' );

		\WP_CLI\Utils\format_items( $format, $items, array( 'identifier', 'status' ) );
	}
}
