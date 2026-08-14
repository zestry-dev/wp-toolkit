<?php

/**
 * Migrations API: `wp {slug} migrations run` command
 */

declare( strict_types=1 );

namespace Zestry\WPToolkit\Modules\Migrations;

// Loaded by WordPress, never requested directly.
\defined( 'ABSPATH' ) || exit;

use Zestry\WPToolkit\Modules\CLI\Command;

/**
 * WP-CLI command: run every pending migration.
 *
 * Registered directly by {@see Migrations::on_boot()} via
 * {@see \Zestry\WPToolkit\Modules\CLI\CLI::register_command_for()} -- not discovered from a
 * file in the consumer's own `resources/commands/` directory, since this command
 * exists the moment the Migrations module is added to a project, with
 * nothing for the consumer to generate or maintain.
 */
class RunMigrationsCommand extends Command {

	/**
	 * Run every pending migration.
	 *
	 * Runs nothing at all when a pending migration looks like a rename of one
	 * that has already run -- same timestamp prefix, different description --
	 * and exits non-zero naming both. A migration's identity is its filename,
	 * so a renamed one reads as never having run, and running it again is the
	 * damage rather than a symptom of it.
	 *
	 * One suspicious migration stops the whole batch, unrelated pending
	 * migrations included. A batch is usually a release, and running half of it
	 * because the other half is suspicious is worse than running none.
	 *
	 * ## OPTIONS
	 *
	 * [--force]
	 * : Run every pending migration even when one looks like a rename. The
	 * rename runs as the new migration it now looks like, and the old
	 * identifier stays recorded -- `migrations list` keeps showing it as
	 * `orphaned`, because the ran-list is a ledger of what happened.
	 *
	 * ## EXAMPLES
	 *
	 *     # Run every pending migration.
	 *     $ wp acme-plugin migrations run
	 *     Success: Ran 2 pending migrations.
	 *
	 *     # A migration was renamed, so nothing runs.
	 *     $ wp acme-plugin migrations run
	 *     Error: 20260804152603-create-submissions-tables looks like a rename of
	 *     20260804152603-create-submissions-table, which has already run.
	 *
	 * @param array $args
	 * @param array $assoc_args
	 * @return void
	 */
	public function handle( array $args, array $assoc_args ): void {
		$before = $this->migrations()->get_ran_migrations();
		$force  = (bool) \WP_CLI\Utils\get_flag_value( $assoc_args, 'force', false );

		try {
			$this->migrations()->run_pending( $force );
		} catch ( \Throwable $exception ) {
			$this->error( $exception->getMessage() );
			return;
		}

		$ran_count = \count( $this->migrations()->get_ran_migrations() ) - \count( $before );

		if ( 0 === $ran_count ) {
			$this->success( 'Already up to date -- nothing to run.' );
			return;
		}

		$this->success(
			\sprintf(
				'Ran %d pending migration%s.',
				$ran_count,
				1 === $ran_count ? '' : 's'
			)
		);
	}

	/**
	 * The module that registered this command.
	 *
	 * Not a property: building a module boots it, and a declaration would hide
	 * that behind a type name.
	 *
	 * @return Migrations
	 */
	private function migrations(): Migrations {
		return $this->with( Migrations::class );
	}
}
