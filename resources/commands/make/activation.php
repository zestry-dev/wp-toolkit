<?php

/**
 * Devtool command: `wp zt make activation <name>`.
 */

declare( strict_types=1 );

use Zestry\WPToolkit\DevTools\Abstracts\MakeCommand;
use Zestry\WPToolkit\DevTools\RuntimePlugin;

return new class() extends MakeCommand {

	/**
	 * Generate an activation handler.
	 *
	 * Writes a class extending `ActivationHandler`, which runs when your plugin
	 * is activated and deactivated, and declares it in `bootstrap.php` so the
	 * plugin builds it. A plugin usually has one.
	 *
	 * Being declared matters more here than for other modules: WordPress fires
	 * the activation hook immediately after your plugin file loads, so the class
	 * has to be built at load for `activate()` to bind in time. Listing it in
	 * `bootstrap.php` and calling `run()` from your entry file does that.
	 *
	 * ## OPTIONS
	 *
	 * <name>
	 * : The class name, in PascalCase, e.g. `Activation`.
	 *
	 *
	 * [--yes]
	 * : Overwrite an existing file without asking, for an unattended run.
	 *
	 * ## EXAMPLES
	 *
	 *     # Generate lib/Modules/Activation.php and declare it.
	 *     $ wp zt make activation Activation
	 *     Success: Created lib/Modules/Activation.php
	 *     Declared Activation in bootstrap.php.
	 *
	 * @param array $args
	 * @param array $assoc_args
	 * @return void
	 */
	public function handle( array $args, array $assoc_args ): void {
		parent::handle( $args, $assoc_args );
	}

	/**
	 * Declare the handler, booting as the plugin loads.
	 *
	 * The one generated module whose timing is not a preference. WordPress
	 * fires `activate_{plugin}` immediately after the plugin file loads, so the
	 * handler has to have registered its callback by then. The plugin's own
	 * loaded action is the last moment that still is: `run()` fires it as its
	 * final act, which is inside the entry file and so ahead of anything
	 * WordPress does with the plugin afterwards. `init` would be too late.
	 *
	 * @param string                                                          $name        The class name given on the command line.
	 * @param string                                                          $plugin_root Absolute path to the consuming plugin's root.
	 * @param array{namespace: string, root: string, text_domain: string|null} $config      The project's zestry.json.
	 * @return void
	 */
	protected function after_write( string $name, string $plugin_root, array $config ): void {
		$this->declare_generated_module(
			$name,
			$plugin_root,
			$this->with( RuntimePlugin::class )->get_loaded_hook( $plugin_root )
		);
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
		return 'activation.php.stub';
	}

	protected function get_default_dir( array $config ): string {
		return trim( $config['root'], '/\\' ) . '/Modules';
	}

	protected static function get_type(): string {
		return 'activation';
	}
};
