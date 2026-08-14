<?php

/**
 * Devtool command: `wp zt make block <name>`.
 *
 * Generates a new editor block into a project already set up with `wp zt init`.
 * Unlike every other `make` type this writes a directory rather than a single
 * file, since a block is a `block.json` plus the scripts, styles and optional
 * PHP it points at.
 */

declare( strict_types=1 );

use Zestry\WPToolkit\DevTools\Abstracts\MakeCommand;
use Zestry\WPToolkit\DevTools\RuntimePlugin;
use Zestry\WPToolkit\DevTools\ConsumerPlugin;
use Zestry\WPToolkit\DevTools\StubRenderer;
use Zestry\WPToolkit\DevTools\ZestryConfig;

return new class() extends MakeCommand {

	/**
	 * Whether the block renders in PHP, once resolved.
	 *
	 * Memoised because it is read once per generated file, and a prompt asked
	 * repeatedly would be a different question every time.
	 *
	 * @var bool|null
	 */
	private ?bool $dynamic = null;

	/**
	 * The resolved `--view` value, once resolved.
	 *
	 * @var string|null
	 */
	private ?string $view = null;

	/**
	 * Whether `--js` was given, once read.
	 *
	 * @var bool|null
	 */
	private ?bool $javascript = null;

	/**
	 * Generate a new editor block.
	 *
	 * The Blocks module discovers it, but only after a build. What this command
	 * writes is source: a `block.json` and the scripts, styles and optional PHP
	 * it points at, under `src/blocks/{name}/`. `npm run build` compiles that
	 * into `build/blocks/`, which is the directory the module walks and registers
	 * from -- so a block that has never been built registers nothing.
	 *
	 * Needs the `blocks` module, so run `wp zt add blocks` first if you
	 * have not already; that is also what writes the npm scripts this build
	 * runs through.
	 *
	 * WordPress matches both halves of a block name against `^[a-z0-9-]+$`, so a
	 * name holding anything else is written as the one it accepts and the command
	 * says what it wrote.
	 *
	 * ## OPTIONS
	 *
	 * <name>
	 * : The local name, e.g. 'hero'. Becomes the directory (`src/blocks/{name}/`)
	 * and the second half of the block's own name, `{plugin-slug}/{name}`.
	 *
	 * **The slug, not the text domain.** Your slug is the plugin's directory
	 * name; the text domain is what you answered at `wp zt init`. They are often
	 * equal, and `wp zt describe` reports the slug if you are unsure. It matters
	 * because the module decides a block is yours by comparing the namespace in
	 * its name against your slug, and looks for its PHP under a
	 * `supports.{plugin-slug}-php` entry. A block namespaced anything else
	 * registers, works in the editor, and renders nothing on the front end.
	 *
	 *
	 * [--dynamic]
	 * : Render the block in PHP. Adds a `block.php` returning a Block subclass,
	 * and the `supports.{plugin-slug}-php` entry pointing at it. Without this the block is
	 * static: its markup is whatever the editor saved. Prompted for when omitted.
	 *
	 * Two questions settle it. Does the output depend on anything outside the
	 * block's own attributes -- a query, an option, the current user, another
	 * post? Then it has to be dynamic. Otherwise, is the markup settled? Static
	 * markup is saved into `post_content`, so it survives the plugin being
	 * deactivated, but changing it later means owing a `deprecated` entry and a
	 * migration or every saved post shows "This block contains unexpected or
	 * invalid content". Dynamic markup is free to change forever, and renders
	 * nothing at all once the plugin is gone.
	 *
	 * [--view=<kind>]
	 * : Give the block front-end JavaScript. `module` writes an Interactivity
	 * API store and registers it as a script module; `script` writes a classic
	 * script that runs against the rendered markup; `none` writes neither.
	 * Prompted for when omitted -- as two questions, since the choice between
	 * `script` and `module` only arises once you have said you want JavaScript
	 * at all.
	 *
	 * The two are not interchangeable source: the Interactivity API is itself a
	 * script module, and a classic script cannot depend on one -- so each mode
	 * generates the code its registration can actually load.
	 * ---
	 * options:
	 *   - none
	 *   - script
	 *   - module
	 * ---
	 *
	 * [--js]
	 * : Generate plain JavaScript instead of TypeScript.
	 *
	 * [--yes]
	 * : Overwrite an existing file without asking, and take the default for
	 * `--dynamic` and `--view` rather than asking -- a static block with no
	 * front-end script. Pass those flags to choose otherwise.
	 *
	 * ## EXAMPLES
	 *
	 *     # Neither flag given, so both are asked for. A run with no terminal --
	 *     # CI, a script, an agent -- must pass both, or it will hang here.
	 *     $ wp zt make block hero
	 *     Render this block in PHP (dynamic)? [y/N] n
	 *     Give this block front-end JavaScript? [y/N] n
	 *     Success: Created src/blocks/hero (5 files)
	 *
	 *     # Saying yes to the JavaScript asks which kind, since `--view` was
	 *     # not given. Answering here is the same as passing --view=module.
	 *     $ wp zt make block hero
	 *     Render this block in PHP (dynamic)? [y/N] n
	 *     Give this block front-end JavaScript? [y/N] y
	 *     Use the Interactivity API? [Y/n] y
	 *     Success: Created src/blocks/hero (6 files)
	 *
	 *     # The same block, non-interactively. `--yes` is what takes the default
	 *     # for the prompts a flag has not already answered.
	 *     $ wp zt make block hero --view=none --yes
	 *     Success: Created src/blocks/hero (5 files)
	 *
	 *     # A server-rendered block, with an Interactivity API front end. Both
	 *     # prompts are answered by flags, so no --yes is needed.
	 *     $ wp zt make block toggle --dynamic --view=module
	 *     Success: Created src/blocks/toggle (7 files)
	 *
	 *     # Nothing registers until the block is built: the Blocks module reads
	 *     # `build/blocks`, not the `src/blocks` written here.
	 *     $ npm run build
	 *
	 * @param array $args
	 * @param array $assoc_args
	 * @return void
	 */
	public function handle( array $args, array $assoc_args ): void {
		parent::handle( $args, $assoc_args );
	}

	/**
	 * Ask for anything not given as a flag, then derive the metadata that
	 * depends on the answers.
	 *
	 * @param string $name       The local name given on the command line.
	 * @param array  $assoc_args WP-CLI's named arguments.
	 * @return array<string, string>
	 */
	protected function get_extra_values( string $name, array $assoc_args ): array {
		$config = $this->with( ZestryConfig::class )->read( $this->with( ConsumerPlugin::class )->get_plugin_root() );

		$dynamic = $this->resolve_dynamic( $assoc_args );
		$view    = $this->resolve_view( $assoc_args );

		$pascal = str_replace( ' ', '', $this->with( StubRenderer::class )->to_title( $name ) );

		return array(
			// WordPress validates a block name against /^[a-z0-9-]+\/[a-z0-9-]+$/,
			// which a text domain need not satisfy, so it is normalised the same
			// way Blocks::get_block_namespace() normalises the plugin slug --
			// otherwise the two would disagree about which blocks are ours.
			'slug'             => $this->get_block_namespace( $config ),
			'pascal'           => $pascal,
			// `index` holds JSX, so under TypeScript it must be `.tsx` -- `tsc`
			// rejects JSX in a `.ts` file. `view` has none, so it stays `.ts`.
			'editor_extension' => $this->uses_typescript( $assoc_args ) ? 'tsx' : 'js',
			'script_extension' => $this->uses_typescript( $assoc_args ) ? 'ts' : 'js',
			'extra_supports'   => $this->get_extra_supports( $dynamic, $view ),
			'extra_metadata'   => $this->get_extra_metadata( $dynamic, $view, $assoc_args ),
			'save_import'      => sprintf(
				"import { %s } from '@wordpress/block-editor';\n",
				$dynamic ? 'InnerBlocks' : 'useBlockProps'
			),
			'save'             => $this->get_save( $dynamic, $pascal ),
		);
	}

	/**
	 * Skip the files the answers did not ask for.
	 *
	 * A `block.json` field is only ever written alongside the file it names (see
	 * get_extra_metadata()), so a block never points at something absent -- a
	 * missing target registers a dead handle without any warning from WordPress.
	 *
	 * @param string $relative_stub The stub file's path relative to the stub directory.
	 * @param array  $assoc_args    WP-CLI's named arguments.
	 * @return bool
	 */
	protected function should_write( string $relative_stub, array $assoc_args ): bool {
		if ( 'block.php.stub' === $relative_stub ) {
			return $this->resolve_dynamic( $assoc_args );
		}

		/*
		 * Two view stubs, one written file. They are not interchangeable: the
		 * Interactivity API arrives as a script module, and a classic script
		 * naming `wp-interactivity` as a dependency is one WordPress refuses to
		 * load at all. Writing the wrong one produces a block that builds,
		 * registers and renders, with front-end JavaScript that never runs.
		 */
		if ( 'view.ts.stub' === $relative_stub ) {
			return 'module' === $this->resolve_view( $assoc_args );
		}

		if ( 'view-script.ts.stub' === $relative_stub ) {
			return 'script' === $this->resolve_view( $assoc_args );
		}

		return true;
	}

	/**
	 * Give each stub the name it is written under.
	 *
	 * Both view stubs become `view.ts`, since only one of them is ever written
	 * and `block.json` names one file either way.
	 *
	 * Under `--js` the `.tsx` component becomes `.js` rather than `.jsx`:
	 * `wp-scripts` resolves JSX in a plain `.js` file, and `create-block`'s own
	 * JavaScript variant names it that way too.
	 *
	 * @param string $relative_stub The stub file's path relative to the stub directory.
	 * @return string
	 */
	protected function get_written_name( string $relative_stub ): string {
		// Whichever view stub was chosen lands as the one file block.json names.
		$name = \str_replace( 'view-script.ts', 'view.ts', parent::get_written_name( $relative_stub ) );

		if ( ! $this->javascript ) {
			return $name;
		}

		return str_replace(
			array( 'index.tsx', 'edit.tsx', 'view.ts' ),
			array( 'index.js', 'edit.js', 'view.js' ),
			$name
		);
	}

	/**
	 * A block is a directory, not a file, so the `.php` default does not apply.
	 *
	 * @param string $dir  The resolved destination directory.
	 * @param string $name The local name given on the command line.
	 * @return string
	 */
	protected function get_destination_path( string $dir, string $name ): string {
		return trim( $dir, '/\\' ) . '/' . $name;
	}

	protected function get_stub(): string {
		return 'block';
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
		return 'a block name is `{namespace}/{name}`, and WordPress matches both halves against `^[a-z0-9-]+$`.';
	}

	protected function get_default_dir( array $config ): string {
		return 'src/blocks';
	}

	/**
	 * The plugin's block namespace, normalized from its slug.
	 *
	 * WordPress validates a block name against `/^[a-z0-9-]+\/[a-z0-9-]+$/`,
	 * which a slug need not satisfy -- an underscore is ordinary in one and
	 * illegal in a block namespace. Normalized exactly as
	 * `Blocks::get_block_namespace()` does, because the module decides a block
	 * is the plugin's own by comparing the two: it looks for a `supports.{namespace}-php`
	 * field, and ignores any block whose name is not `{namespace}/...`.
	 *
	 * **From the slug, not the text domain.** This read the text domain until a
	 * tester noticed the two are different things, and every example in the docs
	 * uses a plugin where they happen to be equal. Where they are not, the block
	 * registers, the editor works, and the front end renders nothing at all: the
	 * module never recognises the block as its own and never finds the field
	 * naming its PHP.
	 *
	 * The slug comes from the running plugin, which is the only thing that knows
	 * it -- `zestry.json` does not record one, and it is the entry file's second
	 * constructor argument. A plugin that is not running falls back to its
	 * directory name, which is exactly what `Plugin` itself defaults to.
	 *
	 * @param array{namespace: string, root: string, text_domain?: string} $config The project's zestry.json.
	 * @return string
	 */
	private function get_block_namespace( array $config ): string {
		$slug = $this->with( RuntimePlugin::class )->get_slug_or_default( $this->with( ConsumerPlugin::class )->get_plugin_root() );

		return (string) preg_replace(
			'/[^a-z0-9-]/',
			'',
			str_replace( '_', '-', strtolower( $slug ) )
		);
	}

	/**
	 * The `block.json` field naming this block's PHP file.
	 *
	 * A field of the plugin's own rather than WordPress's `render`, so the two
	 * never contend for the same block.
	 *
	 * @return string
	 */
	private function get_php_field(): string {
		return $this->get_block_namespace( $this->with( ZestryConfig::class )->read( $this->with( ConsumerPlugin::class )->get_plugin_root() ) ) . '-php';
	}

	/**
	 * Whether the block renders in PHP, asking when `--dynamic` was not given.
	 *
	 * Memoised because the answer is read once per generated file, and a prompt
	 * asked several times would be a different question each time.
	 *
	 * @param array $assoc_args WP-CLI's named arguments.
	 * @return bool
	 */
	private function resolve_dynamic( array $assoc_args ): bool {
		if ( null === $this->dynamic ) {
			// Not confirm()'s --yes handling: that answers yes, while an
			// unattended run wants the documented default. These prompts choose
			// what gets generated rather than confirming an action, so "yes to
			// everything" would build a block nobody asked for.
			$this->dynamic = isset( $assoc_args['dynamic'] ) || ! empty( $assoc_args['yes'] )
				? (bool) ( $assoc_args['dynamic'] ?? false )
				: $this->confirm( 'Render this block in PHP (dynamic)?', false );
		}

		return $this->dynamic;
	}

	/**
	 * Which kind of front-end script the block gets, asking when not given.
	 *
	 * @param array $assoc_args WP-CLI's named arguments.
	 * @return string One of `none`, `script` or `module`.
	 */
	private function resolve_view( array $assoc_args ): string {
		if ( null !== $this->view ) {
			return $this->view;
		}

		$view = $this->get_flag( $assoc_args, 'view', null );

		// See resolve_dynamic(): --yes takes the default rather than answering
		// yes, since this picks what is generated rather than confirming it.
		if ( null === $view && ! empty( $assoc_args['yes'] ) ) {
			$view = 'none';
		}

		if ( null === $view ) {
			$view = $this->confirm( 'Give this block front-end JavaScript?', false )
				? ( $this->confirm( 'Use the Interactivity API?', true ) ? 'module' : 'script' )
				: 'none';
		}

		if ( ! in_array( $view, array( 'none', 'script', 'module' ), true ) ) {
			$this->warning( 'Unknown --view value "' . $view . '", defaulting to "none".' );
			$view = 'none';
		}

		$this->view = $view;

		return $this->view;
	}

	/**
	 * Whether the block is generated in TypeScript.
	 *
	 * TypeScript unless `--js` says otherwise, and never asked for: the choice
	 * belongs to the project rather than to one block, and answering it per
	 * block is how a plugin ends up with both in the same tree.
	 *
	 * @param array $assoc_args WP-CLI's named arguments.
	 * @return bool
	 */
	private function uses_typescript( array $assoc_args ): bool {
		$this->javascript ??= (bool) ( $assoc_args['js'] ?? false );

		return ! $this->javascript;
	}

	/**
	 * The `supports` entries this block adds, as a JSON fragment.
	 *
	 * Rendered inside the `supports` object the stub already declares, so each
	 * entry brings its own leading comma.
	 *
	 * **The PHP pointer lives here rather than at the top level**, because
	 * `https://schemas.wp.org/trunk/block.json` sets `additionalProperties: false`
	 * on the root and offers no vendor-extension key -- so a root
	 * `{namespace}-php` is flagged by every editor that reads the `$schema` line
	 * the stub declares. `supports` is `additionalProperties: true`, which is the
	 * one object in that schema built to be extended. Composing a local schema
	 * around the official one cannot help: `additionalProperties` is evaluated
	 * against the properties of the object declaring it, so an `allOf` `$ref`
	 * still rejects the extra key.
	 *
	 * @param bool   $dynamic Whether a block.php is being written.
	 * @param string $view    The resolved `--view` value.
	 * @return string A JSON fragment, or an empty string.
	 */
	private function get_extra_supports( bool $dynamic, string $view ): string {
		$entries = array();

		if ( 'module' === $view ) {
			$entries[] = "\t\t\"interactivity\": true";
		}

		if ( $dynamic ) {
			// Written only alongside the file it names, so a block.json never
			// points at a block.php that was not generated.
			$entries[] = "\t\t\"" . $this->get_php_field() . '": "file:./block.php"';
		}

		return array() === $entries ? '' : ",\n" . implode( ",\n", $entries );
	}

	/**
	 * The block.json fields that only exist when their file does.
	 *
	 * Rendered into the stub's trailing `{{extra_metadata}}` placeholder, which
	 * sits after the last fixed field so each addition brings its own comma.
	 *
	 * @param bool  $dynamic    Whether a block.php is being written.
	 * @param string $view      The resolved `--view` value.
	 * @param array  $assoc_args WP-CLI's named arguments.
	 * @return string A JSON fragment, or an empty string.
	 */
	private function get_extra_metadata( bool $dynamic, string $view, array $assoc_args ): string {
		$extension = $this->uses_typescript( $assoc_args ) ? 'ts' : 'js';
		$fields    = array();

		if ( 'module' === $view ) {
			// `supports.interactivity` goes in via {{extra_supports}}, inside the
			// supports object the stub already declares -- a second "supports"
			// key here would silently replace the first.
			$fields[] = "\t\"viewScriptModule\": \"file:./view." . $extension . '"';
		} elseif ( 'script' === $view ) {
			$fields[] = "\t\"viewScript\": \"file:./view." . $extension . '"';
		}

		return array() === $fields ? '' : ",\n" . implode( ",\n", $fields );
	}

	/**
	 * The `save` the block registers with, which differs by render strategy.
	 *
	 * A dynamic block's markup comes from PHP on every request, so its `save`
	 * persists only `InnerBlocks.Content` -- whatever the editor nested inside
	 * it. That is what reaches the PHP `render()` as `$content`; returning
	 * nothing at all would mean a block that can never wrap other blocks.
	 *
	 * A static block has no PHP, so its `save` *is* the front end: what it
	 * returns is written into the post content and served as-is. It mirrors
	 * `edit.tsx` here, since the two are expected to agree -- a mismatch is
	 * what triggers WordPress's block validation error.
	 *
	 * @param bool   $dynamic Whether the block renders in PHP.
	 * @param string $pascal  The block's PascalCase name, for the attributes type.
	 * @return string The `save` property, indented for the stub.
	 */
	private function get_save( bool $dynamic, string $pascal ): string {
		if ( $dynamic ) {
			return "\t// Rendered in PHP by block.php. Only the inner blocks are saved,\n"
				. "\t// and they arrive there as the render() method's \$content.\n"
				. "\tsave: () => <InnerBlocks.Content />,";
		}

		return "\t// No PHP: what this returns is written into the post content and\n"
			. "\t// served as-is, so it must agree with edit.tsx or WordPress\n"
			. "\t// reports a block validation error.\n"
			. "\tsave: ( { attributes }: { attributes: " . $pascal . "Attributes } ) => (\n"
			. "\t\t<div { ...useBlockProps.save() }>{ attributes.title }</div>\n"
			. "\t),";
	}

	protected static function get_type(): string {
		return 'block';
	}
};
