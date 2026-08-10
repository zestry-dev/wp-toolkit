<?php

/**
 * Devtool command: `wp zestry make meta-box <name>`.
 */

declare( strict_types=1 );

use Zestry\WPToolkit\DevTools\Abstracts\MakeCommand;

return new class() extends MakeCommand {

	/**
	 * Generate a post edit screen meta box.
	 *
	 * Writes a file into the plugin's `meta-boxes/` directory, where the
	 * MetaBoxes module discovers it. The filename becomes the box's identifier,
	 * prefixed with your plugin slug.
	 *
	 * ## OPTIONS
	 *
	 * <name>
	 * : The box's local name, in kebab-case, e.g. `book-details`.
	 *
	 * [--dir=<dir>]
	 * : Write somewhere other than `meta-boxes/`, relative to the plugin root.
	 *
	 * ## EXAMPLES
	 *
	 *     # Generate meta-boxes/book-details.php.
	 *     $ wp zestry make meta-box book-details
	 *     Success: Created meta-boxes/book-details.php
	 *
	 * @param array $args
	 * @param array $assoc_args
	 * @return void
	 */
	public function handle( array $args, array $assoc_args ): void {
		parent::handle( $args, $assoc_args );
	}

	protected function get_stub(): string {
		return 'meta-box.php.stub';
	}

	protected function get_default_dir( array $config ): string {
		return 'meta-boxes';
	}

	protected static function get_type(): string {
		return 'meta-box';
	}
};
