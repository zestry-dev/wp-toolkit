<?php

/**
 * Devtool command: `wp zestry make entry <name>`.
 */

declare( strict_types=1 );

use Zestry\WPToolkit\DevTools\Abstracts\MakeCommand;

return new class() extends MakeCommand {

	/**
	 * Generate a script entry of this plugin's own.
	 *
	 * Writes `src/entries/<name>/`, which the build compiles to
	 * `build/entries/<name>` and the `assets` module registers on `init`. Using
	 * it is then one call, from an admin page, a shortcode, anywhere:
	 *
	 *     $this->assets->enqueue_entry( 'settings' );
	 *
	 * This exists because `@wordpress/scripts` has no answer for it.  It decides
	 * entry points three mutually exclusive ways — files listed on the command
	 * line, `block.json` scanning, or the `src/index` fallback — so adding a
	 * single block silently stops `src/index` being built, and there is no
	 * supported way to have both. The generated `webpack.config.js` merges them.
	 *
	 * The stylesheet beside `index.ts` is imported by it, which is what gets it
	 * built; it is registered under the same handle, so enqueuing the script
	 * brings it along.
	 *
	 * Needs the `assets` module, which brings the build configuration with it:
	 * `wp zestry add module assets`.
	 *
	 * ## OPTIONS
	 *
	 * <name>
	 * : The entry's name, in kebab-case, e.g. `settings`.
	 *
	 * [--kind=<kind>]
	 * : How WordPress loads it. `script` registers a classic handle and works
	 * everywhere. `module` registers an ES module, which can import only what
	 * WordPress ships as one -- `@wordpress/interactivity` is,
	 * `@wordpress/element` is not. Defaults to `script`.
	 * ---
	 * default: script
	 * options:
	 *   - script
	 *   - module
	 * ---
	 *
	 * [--dir=<dir>]
	 * : Write somewhere other than `src/entries/`, relative to the plugin root.
	 * The module reads `{build}/entries/`, so an entry written elsewhere is
	 * yours to register.
	 *
	 * ## EXAMPLES
	 *
	 *     # A script for an admin screen.
	 *     $ wp zestry make entry settings
	 *     Success: Created src/entries/settings (2 files)
	 *
	 *     # An ES module, for Interactivity API code outside a block.
	 *     $ wp zestry make entry cart --kind=module
	 *     Success: Created src/entries/cart (3 files)
	 *
	 * @param array $args
	 * @param array $assoc_args
	 * @return void
	 */
	public function handle( array $args, array $assoc_args ): void {
		parent::handle( $args, $assoc_args );
	}

	/**
	 * The plugin's own slug, for the import example and the CSS class.
	 *
	 * @param string $name       The entry's local name.
	 * @param array  $assoc_args WP-CLI's named arguments.
	 * @return array<string, string>
	 */
	protected function get_extra_values( string $name, array $assoc_args ): array {
		$plugin_root = $this->consumer_plugin->get_plugin_root();
		$slug        = $this->stub_renderer->to_slug(
			$this->runtime->get_slug_or_default( $plugin_root )
		);

		// Deliberately not the registered handle: `Assets::get_asset_slug()`
		// builds that from the runtime plugin slug, which an entry file can set
		// explicitly, and a guess written into a comment would be a wrong
		// instruction rather than a missing one. The local name is what a
		// caller passes, and that is what the stubs show.
		return array( 'slug' => $slug );
	}

	/**
	 * A classic script needs no `package.json`; only a module declares itself.
	 *
	 * @param string $relative_stub The stub file's path relative to the stub directory.
	 * @param array  $assoc_args    WP-CLI's named arguments.
	 * @return bool
	 */
	protected function should_write( string $relative_stub, array $assoc_args ): bool {
		if ( 'package.json.stub' === $relative_stub ) {
			return 'module' === ( $assoc_args['kind'] ?? 'script' );
		}

		return true;
	}

	/**
	 * An entry is a directory, not a file, so the `.php` default does not apply.
	 *
	 * @param string $dir  The resolved destination directory.
	 * @param string $name The local name given on the command line.
	 * @return string
	 */
	protected function get_destination_path( string $dir, string $name ): string {
		return trim( $dir, '/\\' ) . '/' . $name;
	}

	protected function get_stub(): string {
		return 'entry';
	}

	protected function get_default_dir( array $config ): string {
		return 'src/entries';
	}

	protected static function get_type(): string {
		return 'entry';
	}
};
