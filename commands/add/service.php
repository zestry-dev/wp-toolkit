<?php

/**
 * Devtool command: `wp zestry add service <service>...`.
 *
 * Copies one or more services into a project already set up with `wp zestry init`,
 * resolving each one's transitive dependencies against the registry first (see
 * registry.php). Every `namespace Zestry\WPToolkit\...;`/`use Zestry\WPToolkit\...;` in each copied file
 * is rewritten to the namespace zestry.json recorded during init. Never overwrites
 * a service already present on disk -- use `wp zestry overwrite service` for that.
 */

declare( strict_types=1 );

use Zestry\WPToolkit\DevTools\Abstracts\AddCommand;

return new class() extends AddCommand {

	/**
	 * Copy one or more services into an initialized plugin.
	 *
	 * Requires `wp zestry init` to have already run in this plugin (it reads
	 * zestry.json for the namespace and destination directory chosen there). Every
	 * `namespace Zestry\WPToolkit\...;` declaration and `use Zestry\WPToolkit\...;` import in each copied
	 * file is rewritten to the project's own namespace. A service already
	 * present at its destination is left untouched and logged as skipped -- run
	 * `wp zestry overwrite service <service>` to replace it deliberately.
	 *
	 * Nothing is written to `bootstrap.php`. A service is built the first time
	 * something asks for it -- a `$plugin->get()`, or another class declaring a
	 * property of its type -- so an entry naming one would do nothing. List it
	 * yourself only when you want to configure it, and the
	 * entry's value is the initializer that does so.
	 *
	 * Most services arrive on their own as module dependencies, `path` above
	 * all: every module but `log` and `options` needs it. Naming one here is for
	 * the case where you want it without the module that would have brought it.
	 *
	 * ## OPTIONS
	 *
	 * <service>...
	 * : One or more service names to copy in.
	 * Available services: path, request, cookie, globals, transients, db, views.
	 *
	 * ## EXAMPLES
	 *
	 *     # Copy a service on its own.
	 *     $ wp zestry add service views
	 *     Also adding required dependencies: path
	 *     Added path
	 *     Added views
	 *     Success: Done.
	 *
	 *     # Copy several in one call.
	 *     $ wp zestry add service db globals
	 *     Success: Done.
	 *
	 *     # Naming a module here says where to find it.
	 *     $ wp zestry add service cli
	 *     Error: "cli" is a module, not a service. Run `wp zestry add module cli`.
	 *
	 * @param array $args
	 * @param array $assoc_args
	 * @return void
	 */
	public function handle( array $args, array $assoc_args ): void {
		parent::handle( $args, $assoc_args );
	}

	protected function filter_existing_modules( array $existing_names, array $destinations, array &$to_copy ): bool {
		foreach ( $existing_names as $name ) {
			$this->log( 'Skipped ' . $name . ' (already present)' );
			$to_copy = array_diff( $to_copy, array( $name ) );
		}

		return false;
	}

	protected static function get_word(): string {
		return 'add';
	}

	protected static function get_past_tense(): string {
		return 'Added';
	}

	protected static function get_kind(): string {
		return 'services';
	}
};
