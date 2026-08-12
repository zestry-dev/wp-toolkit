<?php

/**
 * Devtool command: `wp zt make field <name>`.
 */

declare( strict_types=1 );

use Zestry\WPToolkit\DevTools\Abstracts\MakeCommand;

return new class() extends MakeCommand {

	/**
	 * Generate a post meta field.
	 *
	 * Writes a file into the plugin's `fields/` directory, where the Fields
	 * module discovers it. The name becomes the meta key, which you should
	 * prefix if the field attaches to a post type you do not own.
	 *
	 * ## OPTIONS
	 *
	 * <name>
	 * : The meta key, e.g. `acme-rating`. Written exactly as given -- a meta key
	 * is the `meta_key` column and appears in your REST responses, so nothing
	 * respells it. To mark the field protected whatever it is called, uncomment
	 * `is_protected()` in the generated file and return true.
	 *
	 * [--dir=<dir>]
	 * : Write somewhere other than `fields/`, relative to the plugin root.
	 *
	 * [--extends=<class>]
	 * : Extend one of your own abstracts instead of the toolkit base. A bare name
	 * is looked for under your Abstracts\ namespace; the generated file stubs the
	 * methods that class leaves abstract, and nothing it has already settled.
	 *
	 * [--yes]
	 * : Answer both prompts without reading input: overwrite an existing file,
	 * and add the `fields` module when this plugin has none.
	 *
	 * ## EXAMPLES
	 *
	 *     # Generate fields/acme-rating.php.
	 *     $ wp zt make field acme-rating
	 *     Success: Created fields/acme-rating.php
	 *
	 * @param array $args
	 * @param array $assoc_args
	 * @return void
	 */
	public function handle( array $args, array $assoc_args ): void {
		parent::handle( $args, $assoc_args );
	}

	public function get_base_class(): ?string {
		return 'Modules\Fields\Field';
	}

	protected function get_stub(): string {
		return 'field.php.stub';
	}

	protected function get_default_dir( array $config ): string {
		return 'fields';
	}

	protected static function get_type(): string {
		return 'field';
	}
};
