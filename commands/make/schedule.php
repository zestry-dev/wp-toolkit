<?php

/**
 * Devtool command: `wp zt make schedule <name>`.
 *
 * Generates a new cron schedule stub into a project already set up with
 * `wp zt init`.
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
	 * Needs the `cron` module, so run `wp zt add module cron` first if you have
	 * not already.
	 *
	 * ## OPTIONS
	 *
	 * <name>
	 * : The local name, e.g. 'cleanup'. Becomes the filename (`{name}.php`)
	 * under `schedules/`.
	 *
	 *
	 * [--recurrence=<recurrence>]
	 * : The WP-Cron recurrence, e.g. 'daily'. Defaults to 'daily' without prompting.
	 *
	 * [--yes]
	 * : Overwrite an existing file without asking, for an unattended run.
	 *
	 * [--extends=<class>]
	 * : Extend one of your own abstracts instead of the toolkit base. A bare name
	 * is looked for under your Abstracts\ namespace; the generated file stubs the
	 * methods that class leaves abstract, and nothing it has already settled.
	 *
	 * ## EXAMPLES
	 *
	 *     # Generate a daily cron schedule at schedules/cleanup.php.
	 *     $ wp zt make schedule cleanup
	 *     Success: Created schedules/cleanup.php
	 *
	 *     # Generate a schedule with an explicit recurrence.
	 *     $ wp zt make schedule cleanup --recurrence=hourly
	 *     Success: Created schedules/cleanup.php
	 *
	 * @param array $args
	 * @param array $assoc_args
	 * @return void
	 */
	public function handle( array $args, array $assoc_args ): void {
		parent::handle( $args, $assoc_args );
	}

	public function get_base_class(): ?string {
		return 'Modules\Cron\Schedule';
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
