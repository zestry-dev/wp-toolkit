<?php

/**
 * Devtool command: `wp zestry make taxonomy <name>`.
 *
 * Generates a new custom taxonomy stub into a project already set up with
 * `wp zestry init`.
 */

declare( strict_types=1 );

use Zestry\WPToolkit\DevTools\Abstracts\MakeCommand;

return new class() extends MakeCommand {

	/**
	 * Generate a new custom taxonomy.
	 *
	 * The PostTypes module discovers it. On `init` it walks your `taxonomies/`
	 * directory, requires every file in it, and hands the `Taxonomy` each one
	 * returns to `register_taxonomy()`, attached to the post types the file
	 * names. Writing the file is the whole registration; nothing has to be
	 * declared anywhere.
	 *
	 * Needs the `post-types` module -- the same one that registers post types --
	 * so run `wp zestry add module post-types` first if you have not already.
	 *
	 * ## OPTIONS
	 *
	 * <name>
	 * : The local name, e.g. 'genre'. Becomes both the filename
	 * (`{name}.php`) under `taxonomies/` and the registered taxonomy itself
	 * -- unlike every other `make` type, this name is NOT namespaced to the
	 * plugin slug (WordPress caps a taxonomy name at 32 characters), so pick
	 * something short and globally unique.
	 *
	 * [--dir=<dir>]
	 * : Write into this plugin-relative directory instead of `taxonomies` --
	 * pass it when you have pointed PostTypes's taxonomies root somewhere other
	 * than its default.
	 *
	 * [--singular=<singular>]
	 * : The singular display name, e.g. 'Genre'. Defaults to the title-cased
	 * name without prompting.
	 *
	 * [--plural=<plural>]
	 * : The plural display name, e.g. 'Genres'. Prompted for when not given,
	 * since pluralization cannot be guessed reliably from the singular name.
	 *
	 * [--object-type=<object_type>]
	 * : The post type this taxonomy attaches to, e.g. 'book' (or WordPress's
	 * own built-in 'post'). Prompted for when not given.
	 *
	 * [--yes]
	 * : Overwrite an existing file without asking, and take the default for
	 * every prompt below rather than asking, for an unattended run.
	 *
	 * ## EXAMPLES
	 *
	 *     # Generate a taxonomy, prompting for the plural name and object type.
	 *     $ wp zestry make taxonomy genre
	 *     Plural name: (default: Genres)
	 *     Post type this taxonomy attaches to: (default: post)
	 *     Success: Created taxonomies/genre.php
	 *
	 *     # Generate one with every value given explicitly.
	 *     $ wp zestry make taxonomy genre --singular=Genre --plural=Genres --object-type=book
	 *     Success: Created taxonomies/genre.php
	 *
	 * @param array $args
	 * @param array $assoc_args
	 * @return void
	 */
	public function handle( array $args, array $assoc_args ): void {
		parent::handle( $args, $assoc_args );
	}

	/**
	 * Derive the singular/plural display names and object type for the
	 * stub's placeholders.
	 *
	 * @param string $name       The local name given on the command line.
	 * @param array  $assoc_args WP-CLI's named arguments, checked before prompting.
	 * @return array{singular: string, plural: string, object_type: string}
	 */
	protected function get_extra_values( string $name, array $assoc_args ): array {
		$default_singular = $this->stub_renderer->to_title( $name );

		$singular = $this->get_flag( $assoc_args, 'singular', null ) ?? $default_singular;
		$plural   = $this->get_flag( $assoc_args, 'plural', null )
			?? $this->ask( 'Plural name:', $default_singular . 's' );

		$object_type = $this->get_flag( $assoc_args, 'object-type', null )
			?? $this->ask( 'Post type this taxonomy attaches to:', 'post' );

		return array(
			'singular'    => $singular,
			'plural'      => $plural,
			'object_type' => $object_type,
		);
	}

	protected function get_stub(): string {
		return 'taxonomy.php.stub';
	}

	protected function get_default_dir( array $config ): string {
		return 'taxonomies';
	}

	protected static function get_type(): string {
		return 'taxonomy';
	}
};
