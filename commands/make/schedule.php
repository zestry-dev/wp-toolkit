<?php

/**
 * Devtool command: `wp zestry make schedule <name>`.
 *
 * Generates a new cron schedule stub into a project already set up with `wp
 * zestry init`.
 */

declare( strict_types=1 );

use Zestry\WPToolkit\DevTools\Abstracts\MakeCommand;

return new class() extends MakeCommand {

	/**
	 * Generate a new cron schedule.
	 *
	 * The Cron module discovers it. At boot it walks your `schedules/`
	 * directory, requires every file in it, binds the `Schedule` each one
	 * returns to its own hook, and calls `wp_schedule_event()` for it when the
	 * event is not already on the calendar. Writing the file is the whole
	 * registration; nothing has to be declared anywhere.
	 *
	 * Needs the `cron` module, so run `wp zestry add module cron` first if you have
	 * not already.
	 *
	 * ## OPTIONS
	 *
	 * <name>
	 * : The local name, e.g. 'cleanup'. Becomes the filename (`{name}.php`)
	 * under `schedules/`.
	 *
	 * [--dir=<dir>]
	 * : Write into this plugin-relative directory instead of `schedules` --
	 * pass it when you have pointed Cron's schedules root somewhere other than
	 * its default.
	 *
	 * [--recurrence=<recurrence>]
	 * : The WP-Cron recurrence, e.g. 'daily'. Defaults to 'daily' without prompting.
	 *
	 * [--yes]
	 * : Overwrite an existing file without asking, for an unattended run.
	 *
	 * ## EXAMPLES
	 *
	 *     # Generate a daily cron schedule at schedules/cleanup.php.
	 *     $ wp zestry make schedule cleanup
	 *     Success: Created schedules/cleanup.php
	 *
	 *     # Generate a schedule with an explicit recurrence.
	 *     $ wp zestry make schedule cleanup --recurrence=hourly
	 *     Success: Created schedules/cleanup.php
	 *
	 * @param array $args
	 * @param array $assoc_args
	 * @return void
	 */
	public function handle( array $args, array $assoc_args ): void {
		parent::handle( $args, $assoc_args );
	}

	protected function get_extra_values( string $name, array $assoc_args ): array {
		return array(
			'recurrence' => $this->get_flag( $assoc_args, 'recurrence', 'daily' ),
		);
	}

	protected function get_stub(): string {
		return 'schedule.php.stub';
	}

	protected function get_default_dir( array $config ): string {
		return 'schedules';
	}

	protected static function get_type(): string {
		return 'schedule';
	}
};
