<?php

/**
 * Devtool command: `wp zt make meta-box <name>`.
 */

declare( strict_types=1 );

use Zestry\WPToolkit\DevTools\Abstracts\MakeCommand;

return new class() extends MakeCommand {

	/**
	 * Generate a post edit screen meta box.
	 *
	 * Writes a file into the plugin's `resources/meta-boxes/` directory, where the
	 * MetaBoxes module discovers it. The filename becomes the box's identifier,
	 * prefixed with your plugin slug.
	 *
	 * ## OPTIONS
	 *
	 * <name>
	 * : The box's local name, in kebab-case, e.g. `book-details`.
	 *
	 *
	 * [--extends=<class>]
	 * : Extend one of your own abstracts instead of the toolkit base. A bare name
	 * is looked for under your Abstracts\ namespace; the generated file stubs the
	 * methods that class leaves abstract, and nothing it has already settled.
	 *
	 * [--yes]
	 * : Answer both prompts without reading input: overwrite an existing file,
	 * and add the `meta-boxes` module when this plugin has none.
	 *
	 * ## EXAMPLES
	 *
	 *     # Generate resources/meta-boxes/book-details.php.
	 *     $ wp zt make meta-box book-details
	 *     Success: Created resources/meta-boxes/book-details.php
	 *
	 * @param array $args
	 * @param array $assoc_args
	 * @return void
	 */
	public function handle( array $args, array $assoc_args ): void {
		parent::handle( $args, $assoc_args );
	}

	public function get_base_class(): ?string {
		return 'Modules\MetaBoxes\MetaBox';
	}

	protected function get_stub(): string {
		return 'meta-box.php.stub';
	}

	protected function get_default_dir( array $config ): string {
		return 'resources/meta-boxes';
	}

	protected static function get_type(): string {
		return 'meta-box';
	}
};
