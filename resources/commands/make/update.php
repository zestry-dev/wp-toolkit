<?php

/**
 * Devtool command: `wp zt make update <name>`.
 */

declare( strict_types=1 );

use Zestry\WPToolkit\DevTools\Abstracts\MakeCommand;

return new class() extends MakeCommand {

	/**
	 * Generate an update handler.
	 *
	 * Writes a class extending `UpdateHandler`, which runs once after your
	 * plugin's code changes, and declares it in `bootstrap.php` so the plugin
	 * builds it. A plugin usually has one, alongside its activation handler.
	 *
	 * It exists because WordPress has no update hook. `activate_{plugin}` does
	 * not fire on an update -- the plugin stays active throughout -- and
	 * `upgrader_process_complete` runs while the previous release is still in
	 * memory, and never fires at all for a file copied in over FTP. So the
	 * handler compares your `Version:` header against the version it recorded
	 * last time, which catches every way an update can arrive.
	 *
	 * ## OPTIONS
	 *
	 * <name>
	 * : The class name, in PascalCase, e.g. `Update`.
	 *
	 *
	 * [--yes]
	 * : Overwrite an existing file without asking, for an unattended run.
	 *
	 * ## EXAMPLES
	 *
	 *     # Generate lib/Modules/Update.php and declare it.
	 *     $ wp zt make update Update
	 *     Success: Created lib/Modules/Update.php
	 *     Declared Update in bootstrap.php, under admin_init.
	 *
	 * @param array $args
	 * @param array $assoc_args
	 * @return void
	 */
	public function handle( array $args, array $assoc_args ): void {
		parent::handle( $args, $assoc_args );
	}

	/**
	 * Declare the handler under `admin_init`.
	 *
	 * The heading is the timing. `UpdateHandler` has no hook setting of its own:
	 * it is built when this heading fires, and being built is what runs the
	 * version check -- so the line written here is the whole answer to when, and
	 * it is in the consumer's own file where they can see and change it.
	 *
	 * `admin_init` because it is late enough that `init` has finished, and narrow
	 * enough that no front-end visitor carries the work. Every updater-driven
	 * update redirects into wp-admin, so it is the next request in practice.
	 *
	 * @param string                                                          $name        The class name given on the command line.
	 * @param string                                                          $plugin_root Absolute path to the consuming plugin's root.
	 * @param array{namespace: string, root: string, text_domain: string|null} $config      The project's zestry.json.
	 * @return void
	 */
	protected function after_write( string $name, string $plugin_root, array $config ): void {
		$this->declare_generated_module( $name, $plugin_root, 'admin_init' );
	}

	/**
	 * Supply the class name placeholder in place of the usual kebab-case name.
	 *
	 * @param string $name       The class name given on the command line.
	 * @param array  $assoc_args WP-CLI's named arguments.
	 * @return array{class_name: string, class_namespace: string}
	 */
	protected function get_extra_values( string $name, array $assoc_args ): array {
		$segments = $this->get_name_segments( $name );

		return array(
			'class_name'      => (string) array_pop( $segments ),
			'class_namespace' => $this->get_generated_namespace( $segments ),
		);
	}

	protected function get_stub(): string {
		return 'update.php.stub';
	}

	protected function get_default_dir( array $config ): string {
		return trim( $config['root'], '/\\' ) . '/Modules';
	}

	protected static function get_type(): string {
		return 'update';
	}
};
