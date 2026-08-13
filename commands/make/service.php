<?php

/**
 * Devtool command: `wp zt make service <name>`.
 *
 * Generates a plain consumer Service -- something the plugin builds when
 * asked for it, in the spirit of Path/Views/Globals. Its counterpart is
 * `wp zt make module`, for a class that has to act without being called.
 */

declare( strict_types=1 );

use Zestry\WPToolkit\DevTools\Abstracts\MakeCommand;

return new class() extends MakeCommand {

	/**
	 * Generate a new Service subclass.
	 *
	 * Requires `wp zt init` to have already run in this plugin. Like
	 * `make module`, there is no fixed conventional directory to default to, so
	 * it goes in your own `{zestry.json root}/Services/` directory -- beside the
	 * copied `Core/Services/` rather than inside it, since `Core/` is what
	 * `wp zt update` may replace and nothing you write belongs there.
	 *
	 * ## SERVICE OR MODULE?
	 *
	 * Ask whether the class does anything *without being called*.
	 *
	 * A **service** does not. It is built the first time something asks for it
	 * -- a `$plugin->get()` call, or another class declaring a property of its
	 * type -- and works only when called. `Path` resolves a path when asked;
	 * `Views` renders when asked. Nothing happens until then, so it needs no
	 * `bootstrap.php` entry at all -- that file is modules only. One that takes
	 * configuration gets it from `$plugin->configure()` in the entry file.
	 *
	 * A **module** does. It binds a hook, registers a post type, walks a
	 * directory. Because it acts on its own it has to be built for that to
	 * happen, so it is listed in `bootstrap.php` and the plugin builds it as the
	 * plugin loads. `wp zt make module` generates that shape, with the
	 * `on_boot()` the base class requires.
	 *
	 * The line is not "is it a thing I call?" -- `Options` is something you
	 * call, and it is a module, because it also loads its persisted values and
	 * binds `shutdown` without being asked. Getting it wrong is cheap to fix:
	 * change what the class extends, and move the file.
	 *
	 * ## OPTIONS
	 *
	 * <name>
	 * : The class name, e.g. 'Cache'. Becomes both the filename (`{name}.php`)
	 * and the class name itself -- unlike the discovery types, this is NOT a
	 * kebab-case local name; give it exactly as it should appear after `class`.
	 * Group related services by qualifying the name: `Billing/Invoices` writes
	 * `Services/Billing/Invoices.php` declaring `{namespace}\Services\Billing`.
	 * The destination is fixed, since PSR-4 ties a namespace to one directory and
	 * the name decides both.
	 *
	 * [--yes]
	 * : Overwrite an existing file without asking, for an unattended run.
	 *
	 * ## EXAMPLES
	 *
	 *     # Generate a service at lib/Services/Cache.php (given a project
	 *     # initialized with root "lib").
	 *     $ wp zt make service Cache
	 *     Success: Created lib/Services/Cache.php
	 *
	 *     # Grouped: the directory and the namespace come from the same name.
	 *     $ wp zt make service Billing/Invoices
	 *     Success: Created lib/Services/Billing/Invoices.php
	 *
	 * @param array $args
	 * @param array $assoc_args
	 * @return void
	 */
	public function handle( array $args, array $assoc_args ): void {
		parent::handle( $args, $assoc_args );
	}

	/**
	 * Supply the class name placeholder in place of the usual kebab-case name/title.
	 *
	 * @param string $name       The class name given on the command line.
	 * @param array  $assoc_args WP-CLI's named arguments.
	 * @return array{class_name: string, class_namespace: string}
	 */
	protected function get_extra_values( string $name, array $assoc_args ): array {
		$segments = $this->get_segments( $name );

		return array(
			'class_name'      => (string) array_pop( $segments ),
			'class_namespace' => $this->get_service_namespace( $segments ),
		);
	}

	protected function get_stub(): string {
		return 'service.php.stub';
	}

	/**
	 * The project's own `Services` directory, read from zestry.json.
	 *
	 * @param array{namespace: string, root: string} $config The project's zestry.json.
	 * @return string
	 */
	protected function get_default_dir( array $config ): string {
		return trim( $config['root'], '/\\' ) . '/Services';
	}

	/**
	 * Nothing to declare.
	 *
	 * A service is built the moment something asks for it, so an entry naming
	 * it would do nothing. It belongs in `bootstrap.php` only once the consumer
	 * wants to configure it, which is their edit to make rather than ours --
	 * unlike `make module`, which must declare, since being listed is the only
	 * thing that builds a module.
	 *
	 * @param string                                                          $name        The class name given on the command line.
	 * @param string                                                          $plugin_root Absolute path to the consuming plugin's root.
	 * @param array{namespace: string, root: string, text_domain: string|null} $config      The project's zestry.json.
	 * @return void
	 */
	protected function after_write( string $name, string $plugin_root, array $config ): void {
		$this->log( 'Reach it with `$plugin->get( ' . $name . '::class )`, or by declaring a property of its type.' );
		$this->log( 'Needs configuration? Pass it through `$plugin->configure( ' . $name . '::class, ... )` in your entry file.' );
	}

	/**
	 * Split a name into its namespace segments and class name.
	 *
	 * @param string $name The name given on the command line.
	 * @return string[] Namespace segments, the class name last.
	 */
	private function get_segments( string $name ): array {
		$segments = preg_split( '#[/\\\\]+#', trim( $name, '/\\\\' ) );

		return false === $segments ? array() : array_values( array_filter( $segments, 'strlen' ) );
	}

	/**
	 * The namespace a service belongs to, given its leading name segments.
	 *
	 * Always under `{namespace}\Services`, since PSR-4 ties a namespace to one
	 * directory and every service lives in one.
	 *
	 * @param string[] $segments Leading segments of the given name, without the class name.
	 * @return string
	 * @throws \InvalidArgumentException Never; invalid segments are reported through the command's own error handling.
	 */
	private function get_service_namespace( array $segments ): string {
		$config    = $this->zestry_config->read( $this->consumer_plugin->get_plugin_root() );
		$namespace = rtrim( $config['namespace'], '\\' ) . '\\Services';

		foreach ( $segments as $segment ) {
			if ( 1 !== preg_match( '/^[A-Za-z_][A-Za-z0-9_]*$/', $segment ) ) {
				$this->error(
					sprintf( '"%s" is not a valid namespace segment, so PSR-4 cannot map it to a directory.', $segment )
				);
			}

			$namespace .= '\\' . $segment;
		}

		return $namespace;
	}

	protected static function get_type(): string {
		return 'service';
	}
};
