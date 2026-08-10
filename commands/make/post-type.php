<?php

/**
 * Devtool command: `wp zestry make post-type <name>`.
 *
 * Generates a new custom post type stub into a project already set up with
 * `wp zestry init`.
 */

declare( strict_types=1 );

use Zestry\WPToolkit\DevTools\Abstracts\MakeCommand;

return new class() extends MakeCommand {

	/**
	 * Generate a new custom post type.
	 *
	 * The PostTypes module discovers it. On `init` it walks your `post-types/`
	 * directory, requires every file in it, and hands the `PostType` each one
	 * returns to `register_post_type()`, with a full `labels` array built from
	 * the singular and plural names below. Writing the file is the whole
	 * registration; nothing has to be declared anywhere.
	 *
	 * Needs the `post-types` module, so run `wp zestry add module post-types` first
	 * if you have not already.
	 *
	 * ## OPTIONS
	 *
	 * <name>
	 * : The local name, e.g. 'book'. Becomes both the filename (`{name}.php`)
	 * under `post-types/` and the registered post type itself -- unlike
	 * every other `make` type, this name is NOT namespaced to the plugin
	 * slug (WordPress caps a post type name at 20 characters), so pick
	 * something short and globally unique.
	 *
	 * [--dir=<dir>]
	 * : Write into this plugin-relative directory instead of `post-types` --
	 * pass it when you have pointed PostTypes's post types root somewhere other
	 * than its default.
	 *
	 * [--singular=<singular>]
	 * : The singular display name, e.g. 'Book'. Defaults to the title-cased
	 * name without prompting.
	 *
	 * [--plural=<plural>]
	 * : The plural display name, e.g. 'Books'. Prompted for when not given,
	 * since pluralization cannot be guessed reliably from the singular name.
	 *
	 * [--yes]
	 * : Overwrite an existing file without asking, and take the default for
	 * every prompt below rather than asking, for an unattended run.
	 *
	 * ## EXAMPLES
	 *
	 *     # Generate a post type, prompting only for the plural name.
	 *     $ wp zestry make post-type book
	 *     Plural name: (default: Books)
	 *     Success: Created post-types/book.php
	 *
	 *     # Generate one with both names given explicitly.
	 *     $ wp zestry make post-type book --singular=Book --plural=Books
	 *     Success: Created post-types/book.php
	 *
	 * @param array $args
	 * @param array $assoc_args
	 * @return void
	 */
	public function handle( array $args, array $assoc_args ): void {
		parent::handle( $args, $assoc_args );
	}

	/**
	 * Derive the singular/plural display names for the stub's placeholders.
	 *
	 * @param string $name       The local name given on the command line.
	 * @param array  $assoc_args WP-CLI's named arguments, checked before prompting.
	 * @return array{singular: string, plural: string}
	 */
	protected function get_extra_values( string $name, array $assoc_args ): array {
		$default_singular = $this->stub_renderer->to_title( $name );

		$singular = $this->get_flag( $assoc_args, 'singular', null ) ?? $default_singular;
		$plural   = $this->get_flag( $assoc_args, 'plural', null )
			?? $this->ask( 'Plural name:', $default_singular . 's' );

		return array(
			'singular' => $singular,
			'plural'   => $plural,
		);
	}

	protected function get_stub(): string {
		return 'post-type.php.stub';
	}

	protected function get_default_dir( array $config ): string {
		return 'post-types';
	}

	protected static function get_type(): string {
		return 'post-type';
	}
};
