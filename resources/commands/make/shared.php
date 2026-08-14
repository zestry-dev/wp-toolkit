<?php

/**
 * Devtool command: `wp zt make shared <name>`.
 */

declare( strict_types=1 );

use Zestry\WPToolkit\DevTools\Abstracts\MakeCommand;
use Zestry\WPToolkit\DevTools\ConsumerPlugin;
use Zestry\WPToolkit\DevTools\RuntimePlugin;
use Zestry\WPToolkit\DevTools\StubRenderer;

return new class() extends MakeCommand {

	/**
	 * The resolved `--kind` value, once resolved.
	 *
	 * @var string|null
	 */
	private ?string $kind = null;

	/**
	 * Generate a shared JavaScript package.
	 *
	 * Writes an npm workspace under `src/shared/`, imported by name from
	 * anywhere in the plugin — an admin entry, a block, another shared package:
	 *
	 *     import { greet } from '@acme-plugin/formatting';
	 *
	 * Nothing that imports it bundles a copy. The generated `webpack.config.js`
	 * reads the `wordpress` block in the package's own `package.json`, builds it
	 * once into `build/shared/`, and makes every importer declare it as a
	 * dependency instead — the same treatment `@wordpress/element` already gets.
	 *
	 * The scope is your plugin slug. A script package registers as
	 * `{slug}-shared-{name}` and a module keeps the npm name it is imported by;
	 * either way the build composes it, so there is nothing here to keep in step.
	 * A package name is an npm one and takes no capitals or spaces, so a name
	 * holding either is written as the one npm accepts and the command says what
	 * it wrote.
	 *
	 * Run `npm install` afterwards: npm is what links the new directory into
	 * `node_modules/`, and until it has, the import resolves to nothing.
	 *
	 * Add the `assets` module to register what the build produces:
	 * `wp zt add assets`.
	 *
	 * ## OPTIONS
	 *
	 * <name>
	 * : The package's name, in kebab-case, e.g. `formatting`.
	 *
	 * [--kind=<kind>]
	 * : How WordPress loads it. `script` registers a handle other scripts depend
	 * on, and works everywhere. `module` registers an ES module, which needs
	 * WordPress 6.5 or newer and importers that are modules themselves. Asked for
	 * when omitted.
	 * ---
	 * options:
	 *   - script
	 *   - module
	 * ---
	 *
	 *
	 * [--yes]
	 * : Overwrite an existing file without asking, and take the default for
	 * `--kind` rather than asking -- a `script` package, which works everywhere.
	 *
	 * ## EXAMPLES
	 *
	 *     # A package other scripts depend on by handle.
	 *     $ wp zt make shared formatting --kind=script
	 *     Success: Created src/shared/formatting (2 files)
	 *
	 *     # An ES module, imported by name at run time.
	 *     $ wp zt make shared runtime --kind=module
	 *     Success: Created src/shared/runtime (2 files)
	 *
	 * @param array $args
	 * @param array $assoc_args
	 * @return void
	 */
	public function handle( array $args, array $assoc_args ): void {
		parent::handle( $args, $assoc_args );
	}

	/**
	 * Values the two stubs need beyond the name.
	 *
	 * @param string $name       The package's local name.
	 * @param array  $assoc_args WP-CLI's named arguments.
	 * @return array<string, string>
	 */
	protected function get_extra_values( string $name, array $assoc_args ): array {
		$slug = $this->get_scope();
		$kind = $this->resolve_kind( $assoc_args );

		/*
		 * Only `kind`. A script package's handle and the global it publishes are
		 * composed by the generated `webpack.config.js`, from this same slug and
		 * the directory name -- and the handle it composes is the string it
		 * writes into every importer's own `.asset.php`. Writing either here too
		 * would be a second opinion that can disagree with the one that counts.
		 */
		$wordpress = sprintf( "{\n\t\t\"kind\": \"%s\"\n\t}", $kind );

		$loading = 'script' === $kind
			? sprintf( 'WordPress loads it as the `%s-shared-%s` script handle.', $slug, $name )
			: sprintf( 'WordPress loads it as the `@%s/%s` module.', $slug, $name );

		return array(
			'slug'            => $slug,
			'export_name'     => 'greet',
			'wordpress_block' => $wordpress,
			'loading_note'    => $loading,
		);
	}

	/**
	 * A package is a directory, not a file, so the `.php` default does not apply.
	 *
	 * @param string $dir  The resolved destination directory.
	 * @param string $name The local name given on the command line.
	 * @return string
	 */
	protected function get_destination_path( string $dir, string $name ): string {
		return trim( $dir, '/\\' ) . '/' . $name;
	}

	protected function get_stub(): string {
		return 'shared';
	}

	/**
	 * The name WordPress will accept, which is not always the one given.
	 *
	 * @param string $name The local name given on the command line.
	 * @return string
	 */
	protected function normalize_name( string $name ): string {
		return $this->with( StubRenderer::class )->to_slug( $name );
	}

	/**
	 * @return string
	 */
	protected function get_name_constraint(): string {
		return 'a shared package is an npm workspace, and an npm package name takes no capitals or spaces.';
	}

	protected function get_default_dir( array $config ): string {
		return 'src/shared';
	}

	/**
	 * The npm scope this plugin's shared packages sit under.
	 *
	 * The plugin's own slug, which the generated `webpack.config.js` also carries
	 * -- so the name a package is imported by and the handle the build registers
	 * it under are composed from one string. `wp zt make block` takes a block
	 * namespace from the same place, and `add assets` writes the build
	 * configuration from it, so all three agree without any of them reading the
	 * others.
	 *
	 * Not the text domain: that names a translation catalogue, and is free to
	 * differ from the slug.
	 *
	 * @return string
	 */
	private function get_scope(): string {
		return $this->with( StubRenderer::class )->to_slug(
			$this->with( RuntimePlugin::class )->get_slug_or_default( $this->with( ConsumerPlugin::class )->get_plugin_root() )
		);
	}

	/**
	 * How WordPress should load this package, asking when `--kind` was not given.
	 *
	 * @param array<string, mixed> $assoc_args WP-CLI's named arguments.
	 * @return string
	 */
	private function resolve_kind( array $assoc_args ): string {
		if ( null !== $this->kind ) {
			return $this->kind;
		}

		$given = $assoc_args['kind'] ?? null;

		if ( 'script' === $given || 'module' === $given ) {
			$this->kind = $given;

			return $this->kind;
		}

		/*
		 * Not confirm()'s own --yes handling: that answers yes, which would make
		 * an unattended run produce a module. An omitted --kind takes the
		 * documented default instead, the way every other generator does.
		 */
		if ( ! empty( $assoc_args['yes'] ) ) {
			$this->kind = 'script';

			return $this->kind;
		}

		$this->kind = $this->confirm( 'Load it as an ES module? (script handle otherwise)', false )
			? 'module'
			: 'script';

		return $this->kind;
	}

	protected static function get_type(): string {
		return 'shared';
	}
};
