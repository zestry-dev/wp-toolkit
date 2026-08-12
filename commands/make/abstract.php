<?php

/**
 * Devtool command: `wp zt make abstract <name>`.
 *
 * Generates the intermediate abstract a plugin grows once it has more than a
 * handful of files of one kind -- the class holding what every one of them
 * shares. `wp zt make <type> --extends=` is what then points a generated
 * file at it.
 */

declare( strict_types=1 );

use Zestry\WPToolkit\DevTools\Abstracts\MakeCommand;
use Zestry\WPToolkit\DevTools\Copier;

return new class() extends MakeCommand {

	/**
	 * Generate an intermediate abstract of your own.
	 *
	 * Requires `wp zt init` to have already run in this plugin. The file
	 * lands in `{zestry.json root}/Abstracts/`, which is the first place
	 * `--extends=` looks -- so `make field acme-rating --extends=EntityField`
	 * finds it by the bare name afterwards.
	 *
	 * ## WHAT TO EXTEND
	 *
	 * `--for=<type>` extends that `make` type's own base, so an abstract for
	 * fields is written `--for=field` rather than by naming
	 * `Core\Modules\Fields\Field` -- the `Core` segment is the toolkit's to
	 * know, not yours to type.
	 *
	 * `--extends=<class>` extends one of your own classes instead, which is how
	 * a second abstract is layered onto the first.
	 *
	 * Neither one extends nothing, which is a plain abstract class: useful for
	 * something shared that is not a discovered file at all.
	 *
	 * ## OPTIONS
	 *
	 * <name>
	 * : The class name, e.g. 'EntityField'. Becomes both the filename
	 * (`{name}.php`) and the class name -- give it exactly as it should appear
	 * after `abstract class`. Qualify it to group: `Fields/EntityField` writes
	 * `Abstracts/Fields/EntityField.php`. There is no `--dir`, since PSR-4 ties
	 * a namespace to one directory and the name decides both.
	 *
	 * [--for=<type>]
	 * : Extend the base class files of this `make` type return, e.g. `field`,
	 * `post-type`, `ability`.
	 *
	 * [--extends=<class>]
	 * : Extend one of your own classes instead. A bare name is looked for under
	 * your Abstracts\ namespace.
	 *
	 * [--yes]
	 * : Overwrite an existing file without asking, for an unattended run.
	 *
	 * ## EXAMPLES
	 *
	 *     # An abstract every post type file will extend.
	 *     $ wp zt make abstract EntityPostType --for=post-type
	 *     Success: Created lib/Abstracts/EntityPostType.php
	 *
	 *     # Layered onto that one.
	 *     $ wp zt make abstract CuratedPostType --extends=EntityPostType
	 *     Success: Created lib/Abstracts/CuratedPostType.php
	 *
	 *     # Shared by something that is not a discovered file.
	 *     $ wp zt make abstract Importer
	 *     Success: Created lib/Abstracts/Importer.php
	 *
	 * @param array $args
	 * @param array $assoc_args
	 * @return void
	 */
	public function handle( array $args, array $assoc_args ): void {
		parent::handle( $args, $assoc_args );
	}

	/**
	 * An abstract extends whatever it is told to, so there is no fixed base.
	 *
	 * Null also keeps `ensure_base_installed()` quiet, which is right: what this
	 * file needs installed depends on `--for`, and that is checked there instead.
	 *
	 * @return string|null
	 */
	public function get_base_class(): ?string {
		return null;
	}

	/**
	 * Fill in the class name, and whatever it is being told to extend.
	 *
	 * @param string $name       The class name given on the command line.
	 * @param array  $assoc_args WP-CLI's named arguments.
	 * @return array<string, string>
	 */
	protected function get_extra_values( string $name, array $assoc_args ): array {
		$segments = $this->get_name_segments( $name );

		$values = array(
			'class_name'      => (string) array_pop( $segments ),
			'class_namespace' => $this->get_abstract_namespace( $segments ),
			'type'            => (string) $this->get_flag( $assoc_args, 'for', '<type>' ),
			'parent_import'   => '',
			'extends'         => '',
		);

		return array_merge( $values, $this->get_inheritance( $assoc_args ) );
	}

	protected function get_stub(): string {
		return 'abstract.php.stub';
	}

	/**
	 * The project's own `Abstracts` directory, read from zestry.json.
	 *
	 * The same place `--extends=` looks first, so a generated abstract is found
	 * by its bare name without anyone being told where to put it.
	 *
	 * @param array{namespace: string, root: string} $config The project's zestry.json.
	 * @return string
	 */
	protected function get_default_dir( array $config ): string {
		return trim( $config['root'], '/\\' ) . '/Abstracts';
	}

	protected function allows_custom_dir(): bool {
		return false;
	}

	/**
	 * `--extends` means something of this command's own here.
	 *
	 * Everywhere else it points a discovered file at a subclass of that type's
	 * base, and the shared `extends.php.stub` renders the result. An abstract
	 * may extend anything and is a named class, so it reads the flag itself in
	 * {@see get_extra_values()}.
	 *
	 * @return bool
	 */
	protected function takes_shared_extends(): bool {
		return false;
	}

	/**
	 * Nothing to declare: an abstract is reached by being extended.
	 *
	 * @param string                                                          $name        The class name given on the command line.
	 * @param string                                                          $plugin_root Absolute path to the consuming plugin's root.
	 * @param array{namespace: string, root: string, text_domain: string|null} $config      The project's zestry.json.
	 * @return void
	 */
	protected function after_write( string $name, string $plugin_root, array $config ): void {
		$this->log(
			sprintf( 'Point a file at it with `wp zt make <type> <name> --extends=%s`.', basename( str_replace( '\\', '/', $name ) ) )
		);
	}

	/**
	 * What this abstract extends, as the import line and the `extends` clause.
	 *
	 * `--for` names a `make` type and asks that command for its own base, so the
	 * type-to-base map stays where it already is rather than being copied here.
	 * `--extends` names a class directly and goes through the same resolution
	 * every other type's `--extends` uses.
	 *
	 * @param array $assoc_args WP-CLI's named arguments.
	 * @return array<string, string>
	 */
	private function get_inheritance( array $assoc_args ): array {
		$for     = $this->get_flag( $assoc_args, 'for', null );
		$extends = $this->get_flag( $assoc_args, 'extends', null );

		if ( null !== $for && null !== $extends ) {
			$this->error( 'Give --for or --extends, not both: they are two ways of naming one parent.' );

			return array();
		}

		if ( null === $for && null === $extends ) {
			// A plain abstract class. Nothing to import, nothing to extend.
			return array();
		}

		$root = rtrim( $this->zestry_config->read( $this->consumer_plugin->get_plugin_root() )['namespace'], '\\' );

		$parent = null !== $for
			? $this->get_base_of_type( $for, $root )
			: $this->resolve_own_class( (string) $extends, $root );

		if ( null === $parent ) {
			return array();
		}

		return array(
			'parent_import' => "\nuse " . $parent . ";\n",
			'extends'       => ' extends ' . substr( (string) strrchr( '\\' . $parent, '\\' ), 1 ),
		);
	}

	/**
	 * The copied base class files of a `make` type return.
	 *
	 * @param string $type The `make` type, e.g. `field`.
	 * @param string $root The plugin's namespace root.
	 * @return string|null The class name, or null once the reason has been reported.
	 */
	private function get_base_of_type( string $type, string $root ): ?string {
		$file = $this->path->get_plugin_path( 'commands/make/' . $type . '.php' );

		if ( ! is_file( $file ) ) {
			$this->error( sprintf( 'There is no `make %s`, so it has no base class to extend.', $type ) );

			return null;
		}

		$command = require $file;
		$base    = $command instanceof MakeCommand ? $command->get_base_class() : null;

		if ( null === $base ) {
			$this->error(
				sprintf( '`make %s` does not generate a class that extends anything, so there is nothing to extend.', $type )
			);

			return null;
		}

		return Copier::get_target_namespace( $root ) . '\\' . $base;
	}

	/**
	 * One of the plugin's own classes, named by `--extends`.
	 *
	 * @param string $requested The value given for --extends.
	 * @param string $root    The plugin's namespace root.
	 * @return string|null The class name, or null once the reason has been reported.
	 */
	private function resolve_own_class( string $requested, string $root ): ?string {
		try {
			$parent = $this->parent_class->resolve( $requested, $root );

			// No base to hold it to -- an abstract may extend anything -- so this
			// checks only what would be a fatal in the file about to be written.
			$this->parent_class->assert_usable( $parent, $parent );
		} catch ( \InvalidArgumentException $exception ) {
			$this->error( $exception->getMessage() );

			return null;
		}

		return $parent;
	}

	/**
	 * The namespace an abstract belongs to, given its leading name segments.
	 *
	 * @param string[] $segments Leading segments of the given name, without the class name.
	 * @return string
	 */
	private function get_abstract_namespace( array $segments ): string {
		$config    = $this->zestry_config->read( $this->consumer_plugin->get_plugin_root() );
		$namespace = rtrim( $config['namespace'], '\\' ) . '\\Abstracts';

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
		return 'abstract';
	}
};
