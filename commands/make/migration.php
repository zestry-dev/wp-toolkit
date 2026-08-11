<?php

/**
 * Devtool command: `wp zestry make migration <name>`.
 *
 * Generates a new, timestamp-prefixed database migration stub into a project
 * already set up with `wp zestry init`.
 */

declare( strict_types=1 );

use Zestry\WPToolkit\DevTools\Abstracts\MakeCommand;

return new class() extends MakeCommand {

	/**
	 * Generate a new database migration.
	 *
	 * The Migrations module discovers it, but unlike every other discovery type
	 * this one does not run itself. The module reads your `migrations/`
	 * directory in filename order and runs each `Migration` at most once per
	 * site, when something asks it to: `wp {slug} migrations run`, or a
	 * `run_pending()` call from whatever trigger fits your release process.
	 *
	 * Needs the `migrations` module, so run `wp zestry add module migrations` first
	 * if you have not already.
	 *
	 * ## OPTIONS
	 *
	 * <name>
	 * : The local description, e.g. 'create-books-table'. The file is written
	 * to `migrations/{timestamp}-{name}.php`, and that timestamp prefix is
	 * what makes migrations run in the order they were created. Never rename
	 * a migration that may already have run somewhere -- the whole filename is
	 * its identity, so a renamed one reads as never having run. `migrations
	 * run` refuses when it spots that, but only once it has already happened.
	 *
	 * [--dir=<dir>]
	 * : Write into this plugin-relative directory instead of `migrations` --
	 * pass it when you have pointed Migrations's migrations root somewhere
	 * other than its default.
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
	 *     $ wp zestry make migration create-books-table
	 *     Success: Created migrations/20260115120000-create-books-table.php
	 *
	 * @param array $args
	 * @param array $assoc_args
	 * @return void
	 */
	public function handle( array $args, array $assoc_args ): void {
		parent::handle( $args, $assoc_args );
	}

	public function get_base_class(): ?string {
		return 'Modules\Migrations\Migration';
	}

	/**
	 * Prefix the filename with the current timestamp so migrations sort and
	 * run in generation order.
	 *
	 * Uses the same `YmdHis` fixed-width format {@see \Zestry\WPToolkit\Modules\Migrations\Migrations}
	 * documents as the required prefix shape, so a generated file's ordering
	 * is always correct relative to any other migration, regardless of
	 * whichever second it happens to be created.
	 *
	 * @param string $dir  The resolved destination directory.
	 * @param string $name The local description given on the command line.
	 * @return string The plugin-relative path, e.g. `migrations/20260115120000-create-books-table.php`.
	 */
	protected function get_destination_path( string $dir, string $name ): string {
		return trim( $dir, '/\\' ) . '/' . gmdate( 'YmdHis' ) . '-' . $name . '.php';
	}

	protected function get_stub(): string {
		return 'migration.php.stub';
	}

	protected function get_default_dir( array $config ): string {
		return 'migrations';
	}

	protected static function get_type(): string {
		return 'migration';
	}
};
