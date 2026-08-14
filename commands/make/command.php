<?php

/**
 * Devtool command: `wp zt make command <name>`.
 *
 * Generates a new WP-CLI command stub into a project already set up with
 * `wp zt init`.
 */

declare( strict_types=1 );

use Zestry\WPToolkit\DevTools\Abstracts\MakeCommand;

return new class() extends MakeCommand {

	/**
	 * Generate a new WP-CLI command.
	 *
	 * The CLI module discovers it. At boot it walks your `commands/` directory
	 * at any depth, requires every file in it, and registers the `Command` each
	 * one returns under your plugin's slug -- so `commands/greet.php` becomes
	 * `wp {slug} greet`, and nested directories become nested command
	 * namespaces. Writing the file is the whole registration.
	 *
	 * Needs the `cli` module, so run `wp zt add cli` first if you have
	 * not already.
	 *
	 * ## OPTIONS
	 *
	 * <name>
	 * : The local name, e.g. 'greet'. Becomes the filename (`{name}.php`)
	 * under `commands/`. May include `/` to nest it under a command
	 * namespace, e.g. 'cache/clear' -- but one name can be a leaf command or a
	 * command namespace, never both, because WP-CLI cannot attach subcommands
	 * to a command. `commands/cache.php` and `commands/cache/` therefore
	 * exclude each other, and this command refuses to write the second.
	 *
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
	 *     # Generate a WP-CLI command at commands/greet.php.
	 *     $ wp zt make command greet
	 *     Success: Created commands/greet.php
	 *
	 * @param array $args
	 * @param array $assoc_args
	 * @return void
	 */
	public function handle( array $args, array $assoc_args ): void {
		parent::handle( $args, $assoc_args );
	}

	public function get_base_class(): ?string {
		return 'Modules\CLI\Command';
	}

	protected function get_stub(): string {
		return 'command.php.stub';
	}

	protected function get_default_dir( array $config ): string {
		return 'commands';
	}

	/**
	 * Add CLI's own leaf/namespace naming rule on top of the generic fs checks.
	 *
	 * A name can be organized under a subdirectory (a command namespace) or be
	 * a leaf command file, but not both at once -- WP-CLI's Subcommand can
	 * never have subcommands attached (see {@see \Zestry\WPToolkit\Modules\CLI\CLI}'s own
	 * collision guard, which enforces this the same way at discovery time).
	 * Unlike the generic checks in the parent, this collision is NOT a
	 * filesystem conflict: `commands/test-1.php` and `commands/test-1/` can
	 * coexist fine on disk, so it has to be checked for explicitly here rather
	 * than falling out of `is_dir()`/`is_file()`.
	 *
	 * @param string $plugin_root   Absolute path to the consuming plugin's root.
	 * @param string $relative_path The plugin-relative destination path, for error messages.
	 * @param string $destination   Absolute path the new file would be written to.
	 * @return void
	 * @throws \InvalidArgumentException When the destination conflicts with an existing command or command namespace.
	 */
	protected function detect_collision( string $plugin_root, string $relative_path, string $destination ): void {
		parent::detect_collision( $plugin_root, $relative_path, $destination );

		// A leaf command file already claiming one of the destination's own
		// namespace segments -- e.g. writing "test-1/test-2.php" while
		// "test-1.php" already exists as a command. Bounded by the plugin
		// root: nothing above it is part of this project's command tree.
		$root      = rtrim( $plugin_root, '/\\' );
		$directory = dirname( $destination );
		while ( str_starts_with( $directory, $root ) && $directory !== $root ) {
			$leaf_sibling = $directory . '.php';
			if ( is_file( $leaf_sibling ) ) {
				throw new \InvalidArgumentException(
					'Cannot create "' . $relative_path . '" -- "' . $leaf_sibling . '" is already registered as a command '
						. 'and cannot also be used as a command namespace.'
				);
			}

			$directory = dirname( $directory );
		}

		// The destination itself already claimed as a namespace -- e.g.
		// writing "test-1.php" while "test-1/" already holds command files.
		$namespace_sibling = substr( $destination, 0, -strlen( '.php' ) );
		if ( is_dir( $namespace_sibling ) ) {
			throw new \InvalidArgumentException(
				'Cannot create "' . $relative_path . '" -- "' . $namespace_sibling . '" already exists as a command '
					. 'namespace and cannot also be used as a command name.'
			);
		}
	}

	protected static function get_type(): string {
		return 'command';
	}
};
