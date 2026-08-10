<?php

/**
 * Blocks API: Blocks module
 */

declare( strict_types=1 );

namespace Zestry\WPToolkit\Modules\Blocks;

// Loaded by WordPress, never requested directly.
\defined( 'ABSPATH' ) || exit;

use Zestry\WPToolkit\Kernel\Abstracts\Module;
use Zestry\WPToolkit\Kernel\Exceptions\DiscoveryException;
use Zestry\WPToolkit\Kernel\Traits\WithFolderWalker;
use Zestry\WPToolkit\Services\Path;
use WP_Block_Type_Registry;

/**
 * Discovers plugin editor blocks and registers them with WordPress.
 *
 * > [!NOTE]
 * > **This module registers blocks; it does not help you write one.** The PHP
 * > half is what it takes care of -- discovery, registration, wiring,
 * > rendering -- and that is the smaller half of a block. The editor half is
 * > React against `@wordpress/block-editor`: WordPress's API, documented by
 * > WordPress. `wp zestry make block` hands you a working `edit.tsx` and stops
 * > there on purpose.
 * >
 * > So a plugin whose interface *is* blocks is mostly a JavaScript project,
 * > with this toolkit looking after its PHP end. Plan for that.
 *
 * A blocks directory contains one subdirectory per block, each holding the
 * `block.json` that `@wordpress/scripts` compiled there. The module registers
 * every one it finds, so adding a block is a matter of building it rather than
 * writing another `register_block_type()` call.
 *
 * The root is the *built* directory (`build/blocks` by default), not the source
 * one: `block.json`'s own `file:` paths are relative to wherever it sits, so
 * pointing WordPress at the build output is what makes them resolve without any
 * rewriting here.
 *
 * A block declares its PHP with `"supports": { "{plugin-slug}-php":
 * "file:./block.php" }`, and that file returns a {@see Block} instance -- loaded the first time the block
 * renders, wired, and called, so its PHP has the plugin's own modules injected.
 * A file returning anything else raises a DiscoveryException.
 *
 * A block declaring WordPress's own `render` field instead is left alone
 * entirely, and a block declaring neither is static. Both are still registered.
 *
 * Registration reads `blocks-manifest.php` when one is present (see
 * `wp-scripts build --blocks-manifest`), which spares WordPress a `block.json`
 * read and decode per block, and walks the blocks directory when there is not.
 *
 * @setup
 * Register an initializer only to point the module at a non-default directory,
 * or to declare a block category of the plugin's own.
 *
 * An initializer runs while the plugin file loads, which is before `init` and
 * so before a text domain may be touched. {@see Module::run_at_init()} moves
 * whatever needs the later point -- here the translated headings -- without the
 * caller having to know whether `init` has already passed.
 *
 * ```
 * // bootstrap.php
 * return array(
 *     Blocks::class => static function ( Blocks $blocks ): void {
 *         $blocks->set_blocks_root( 'build/editor-blocks' );
 *
 *         $blocks->run_at_init( function ( Blocks $module ) {
 *             $module->add_categories( [
 *                 'reports' => __( 'Reports', 'my-plugin' ),
 *                 'charts'  => [
 *                     'title' => __( 'Charts', 'my-plugin' ),
 *                     'icon'  => 'chart-bar',
 *                 ],
 *             ] );
 *         } );
 *     },
 * );
 * ```
 */
class Blocks extends Module {

	use WithFolderWalker;

	/**
	 * Default plugin-relative directory of built block directories.
	 */
	const DEFAULT_BLOCKS_ROOT = 'build/blocks';

	/**
	 * Filename `wp-scripts build --blocks-manifest` writes into the build root.
	 */
	const MANIFEST_FILENAME = 'blocks-manifest.php';

	/**
	 * Path module injected by the plugin to resolve the blocks directory.
	 *
	 * @var Path
	 */
	public Path $path;

	/**
	 * Plugin-relative directory of built block directories.
	 *
	 * @var string
	 */
	private string $blocks_root = self::DEFAULT_BLOCKS_ROOT;

	/**
	 * Whether the directory above was named deliberately.
	 *
	 * A missing directory means two different things. Named by
	 * {@see set_blocks_root()} and absent: a typo, and registering nothing
	 * silently would hide it. Never named, and the default is absent: this
	 * plugin has none of these yet, which is ordinary -- adding the module
	 * before writing the first file should not take the site down.
	 *
	 * @var bool
	 */
	private bool $blocks_root_was_set = false;

	/**
	 * Block categories registered via add_categories(), in declaration order.
	 *
	 * @var array<int, array{slug: string, title: string|callable():string, icon: string|null}>
	 */
	private array $categories = array();

	/**
	 * Block names already prepared this request.
	 *
	 * A block is prepared the first time it is about to render: its assets are
	 * enqueued and, if it asks, its inner blocks are left to it. Both are
	 * per-block rather than per-occurrence, so this keeps a block appearing
	 * three times on a page from repeating either.
	 *
	 * @var array<string, true>
	 */
	private array $prepared = array();

	/**
	 * What each of this plugin's blocks needs to find its render file, keyed by
	 * the block's namespaced name.
	 *
	 * Recorded as blocks register, since `pre_render_block` is handed a parsed
	 * block that names the block but says nothing about where its PHP lives --
	 * a key being present is also what identifies a block as ours.
	 *
	 * Holds `[ metadata file, render field, false ]` until the block first
	 * renders, and `[ metadata file, resolved path, true ]` once
	 * {@see get_render_file()} has built the path.
	 *
	 * @var array<string, array{0: string, 1: string, 2: bool}>
	 */
	private array $render_files = array();

	/**
	 * Each render file's class name, keyed by the file's own path.
	 *
	 * An anonymous class still has a real name -- recording it the first time a
	 * file is required means every later occurrence of that block is a `new`
	 * rather than another `require`.
	 *
	 * @var array<string, class-string<Block>>
	 */
	private array $renderer_classes = array();

	/**
	 * Set the plugin-relative directory that contains built block directories.
	 *
	 * Call this from the module initializer before the plugin boots the module
	 * to override the default `build/blocks` directory.
	 *
	 * @param string $blocks_root Plugin-relative directory of built block directories.
	 * @return void
	 */
	public function set_blocks_root( string $blocks_root ): void {
		$this->blocks_root         = $blocks_root;
		$this->blocks_root_was_set = true;
	}

	/**
	 * Declare the block categories this plugin's blocks sit in.
	 *
	 * Call this from the module initializer. A block claims a category by naming
	 * it in its own `block.json` "category" field; declaring it here only makes
	 * the inserter show it as a group with a title of its own.
	 *
	 * Keyed by slug, the same shape `bootstrap.php` uses for modules, so the
	 * groups read as data. A plain string (or a callable returning one) is the
	 * title; an array carries an `icon` alongside it:
	 *
	 *     // bootstrap.php
	 *     $blocks->add_categories(
	 *         array(
	 *             'reports' => __( 'Reports', 'my-plugin' ),
	 *             'charts'  => array(
	 *                 'title' => __( 'Charts', 'my-plugin' ),
	 *                 'icon'  => 'chart-bar',
	 *             ),
	 *         )
	 *     );
	 *
	 *     // src/blocks/sales/block.json
	 *     { "name": "my-plugin/sales", "category": "reports" }
	 *
	 * The category and the block that claims it live in two files, and only the
	 * block.json half is checked by anything -- a block naming a category that
	 * was never declared is filed under Uncategorized rather than erroring, so
	 * the two have to be kept in step by hand.
	 *
	 * A slug is registered exactly as given and is not namespaced to the plugin
	 * slug the way a hook or an option name is: it has
	 * to match what a hand-written `block.json` says verbatim, and namespacing
	 * would register `{plugin-slug}-reports` while every block still asked for
	 * `reports`. Choose slugs distinctive enough not to collide -- reusing one
	 * of WordPress's own (`text`, `media`, `design`, `widgets`, `theme`,
	 * `embed`) adds a second entry rather than renaming the first.
	 *
	 * A heading is user-visible, so it usually wants translating -- and an
	 * initializer runs while the plugin file loads, where a `__()` loads the text
	 * domain early enough that WordPress reports
	 * `_load_textdomain_just_in_time` on every request. Wrap the call in
	 * {@see Module::run_at_init()}, as the example above does, and ordinary
	 * `__()` is correct.
	 *
	 * A title may also be given as a callable, resolved when the editor asks for
	 * its categories. That covers a heading genuinely expensive to compute; for
	 * translation alone, deferring the whole call reads better than making each
	 * value lazy.
	 *
	 * Order is kept: categories appear in the inserter after WordPress's own, in
	 * the order declared here, and a later call appends to an earlier one.
	 *
	 * @param array<string, string|callable():string|array{title: string|callable():string, icon?: string|null}> $categories Titles or configuration, keyed by slug.
	 * @return void
	 * @throws \InvalidArgumentException When an entry is an array without a title.
	 */
	public function add_categories( array $categories ): void {
		foreach ( $categories as $slug => $category ) {
			/*
			 * `is_callable` first, since a callable is legitimately an array --
			 * `array( $object, 'method' )` -- and would otherwise be read as a
			 * configuration array with no title.
			 */
			$is_config = \is_array( $category ) && ! \is_callable( $category );

			if ( $is_config && ! isset( $category['title'] ) ) {
				throw new \InvalidArgumentException(
					\sprintf( 'Block category "%s" needs a title.', (string) $slug )
				);
			}

			$this->categories[] = array(
				'slug'  => (string) $slug,
				'title' => $is_config ? $category['title'] : $category,
				'icon'  => $is_config ? ( $category['icon'] ?? null ) : null,
			);
		}
	}

	/**
	 * Merge this plugin's declared categories into the editor's own.
	 *
	 * @param array<int, array<string, mixed>> $categories WordPress's own registered categories.
	 * @return array<int, array<string, mixed>> The merged categories.
	 *
	 * @internal
	 */
	public function filter_block_categories( array $categories ): array {
		$own = array();

		foreach ( $this->categories as $category ) {
			// Resolved here rather than at registration: this is the first point
			// after `init`, so a title given as a callable can translate.
			$category['title'] = \is_callable( $category['title'] )
				? (string) ( $category['title'] )()
				: $category['title'];

			$own[] = $category;
		}

		return \array_merge( $categories, $own );
	}

	/**
	 * Prepare one of this plugin's blocks just before WordPress renders it.
	 *
	 * Two things have to happen after `init` but before a block renders, and
	 * this is the only hook that fits both.
	 *
	 * `skip_inner_blocks` is one: `WP_Block::render()` reads it off the block
	 * type moments after this filter returns, so setting it here works and
	 * costs nothing for a block that never renders. Setting it at registration
	 * instead would mean loading every block's PHP on `init` just to ask a
	 * question most pages never need answered.
	 *
	 * The other is `enqueue_assets()`, which wants the same timing for the same
	 * reason -- and firing it here rather than inside the render callback means
	 * a block whose render some other filter short-circuits still gets the
	 * library it needs.
	 *
	 * Returns its first argument untouched: this reads the block, it does not
	 * replace it.
	 *
	 * @param string|null          $pre_render   Whatever an earlier filter decided to render instead, or null.
	 * @param array<string, mixed> $parsed_block The block about to render.
	 * @return string|null $pre_render, unchanged.
	 * @throws DiscoveryException When the render file does not return a Block instance.
	 *
	 * @internal
	 */
	public function filter_pre_render_block( $pre_render, array $parsed_block ) {
		$name = (string) ( $parsed_block['blockName'] ?? '' );

		if ( ! isset( $this->render_files[ $name ] ) || isset( $this->prepared[ $name ] ) ) {
			return $pre_render;
		}

		$this->prepared[ $name ] = true;

		$class = $this->get_renderer_class( $this->get_render_file( $name ) );
		$class::enqueue_assets( $this->get_plugin() );

		if ( $class::skips_inner_blocks() ) {
			$block_type = WP_Block_Type_Registry::get_instance()->get_registered( $name );

			if ( null !== $block_type ) {
				$block_type->skip_inner_blocks = true;
			}
		}

		return $pre_render;
	}

	/**
	 * Discover every built block and register it with WordPress.
	 *
	 * @return void
	 * @throws DiscoveryException When a blocks directory named by set_blocks_root() does not exist, or a render file returns the wrong value.
	 *
	 * @internal
	 */
	public function register_blocks(): void {
		$root_dir = $this->path->get_plugin_path( $this->blocks_root );

		if ( ! $this->path->is_plugin_dir( $this->blocks_root ) ) {
			// Never named, and the default is absent: this plugin has none of
			// these yet. Only a directory asked for by name is missing in the
			// sense worth throwing over.
			if ( ! $this->blocks_root_was_set ) {
				return;
			}

			throw DiscoveryException::missing_root( 'Blocks', $root_dir, 'set_blocks_root()' );
		}

		// Bound before any registration: it fires while a block registers, and
		// is what supplies each dynamic block's render callback.
		\add_filter( 'block_type_metadata_settings', array( $this, 'filter_block_settings' ), 10, 2 );
		\add_filter( 'pre_render_block', array( $this, 'filter_pre_render_block' ), 10, 2 );

		/*
		 * `wp-scripts build --blocks-manifest` writes the manifest beside the
		 * blocks directory, not inside it -- for `build/blocks/*` that means
		 * `build/blocks-manifest.php`. Core resolves each manifest key as
		 * `{collection path}/{key}/block.json`, so the collection path is the
		 * blocks root while the manifest sits one level above it.
		 */
		$manifest_path = \rtrim( \dirname( $this->blocks_root ), '/\\.' ) . '/' . self::MANIFEST_FILENAME;
		$manifest_path = \ltrim( $manifest_path, '/' );
		$manifest      = $this->path->get_plugin_path( $manifest_path );
		$has_manifest  = $this->path->plugin_file_exists( $manifest_path );

		// The manifest lists every block, so it is checked before the filesystem
		// is walked at all: one call registers the lot, reading no block.json.
		if ( $has_manifest ) {
			\wp_register_block_types_from_metadata_collection( $root_dir, $manifest );
			return;
		}

		foreach ( $this->get_discovered_directories() as $directory ) {
			// Returns false when the directory holds no readable `block.json`,
			// which would otherwise leave a built block simply absent from the
			// inserter.
			if ( false === \register_block_type( $directory ) ) {
				throw DiscoveryException::registration_refused( 'block', \basename( $directory ) );
			}
		}
	}

	/**
	 * Point a block's render at the Block instance its block.php returns.
	 *
	 * Installing a `render_callback` is what makes WordPress treat the block as
	 * dynamic. This filter fires immediately before the block type is
	 * registered, whether the block came from a manifest or its own
	 * `block.json`, and is handed the block's metadata -- so the file is
	 * recorded here and read only once the block actually renders.
	 *
	 * @param array<string, mixed> $settings The settings the block is about to be registered with.
	 * @param array<string, mixed> $metadata The block's own metadata.
	 * @return array<string, mixed> The settings, with this module's renderer bound in when it has one.
	 * @throws DiscoveryException When the render file returns a value that is neither a Block nor nothing.
	 *
	 * @internal
	 */
	public function filter_block_settings( array $settings, array $metadata ): array {
		$name = (string) ( $metadata['name'] ?? '' );

		if ( ! $this->owns( $name ) ) {
			return $settings;
		}

		// A block declaring WordPress's own `render` has asked for WordPress's
		// handling, and gets exactly that.
		if ( isset( $metadata['render'] ) ) {
			return $settings;
		}

		/*
		 * Read out of `supports`, not off the root. The official block schema
		 * (`https://schemas.wp.org/trunk/block.json`) sets
		 * `additionalProperties: false` on the root and defines no vendor key, so a
		 * top-level `{namespace}-php` is flagged by every editor that reads the
		 * `$schema` line a generated block.json carries. `supports` is
		 * `additionalProperties: true` -- the one object in that schema meant to be
		 * extended -- and WordPress passes an entry it does not recognise through
		 * untouched.
		 */
		$field    = $this->get_block_namespace() . '-php';
		$supports = $metadata['supports'] ?? array();
		$declared = \is_array( $supports ) ? ( $supports[ $field ] ?? null ) : null;

		if ( ! \is_string( $declared ) || '' === $declared ) {
			return $settings;
		}

		/*
		 * The two strings the path is built from rather than the path itself:
		 * this runs at registration for every block the plugin has, and the path
		 * is not needed until one of them renders. Recording the pair is also
		 * what tells filter_pre_render_block() the block is ours.
		 *
		 * `skip_inner_blocks` is deliberately not settled here. WP_Block::render()
		 * reads it live off the block type and `pre_render_block` fires first,
		 * so the answer can wait until the block is about to render rather than
		 * loading every block's PHP on `init` to ask.
		 */
		$this->render_files[ $name ] = array( (string) $metadata['file'], $declared, false );

		// A fresh instance per occurrence, so anything a block stores on `$this`
		// during a render belongs to that occurrence alone.
		$settings['render_callback'] = function ( $attributes, $content, $block ) use ( $name ): string {
			return $this->load_renderer( $this->get_render_file( $name ) )
				->render( (array) $attributes, (string) $content, $block );
		};

		return $settings;
	}

	/**
	 * Every discovered block directory, keyed by its own directory name.
	 *
	 * @return array<string, string> Absolute directory paths keyed by directory name.
	 * @throws DiscoveryException When a blocks directory named by set_blocks_root() does not exist.
	 */
	public function get_discovered_blocks(): array {
		return $this->get_discovered_directories();
	}

	/**
	 * Resolve the blocks directory and register every block on every request.
	 *
	 * Deferred to `init` because that is where WordPress expects block types to
	 * be registered: earlier and the block registry is not ready, later and the
	 * editor has already asked for the list.
	 *
	 * @return void
	 *
	 * @internal
	 */
	protected function on_boot(): void {
		\add_filter( 'block_categories_all', array( $this, 'filter_block_categories' ) );

		$this->run_at_init(
			static function ( self $module ): void {
				$module->register_blocks();
			}
		);
	}

	/**
	 * Find every directory under the root that holds a `block.json`.
	 *
	 * @return array<string, string> Absolute directory paths keyed by directory name.
	 * @throws DiscoveryException When a blocks directory named by set_blocks_root() does not exist.
	 */
	private function get_discovered_directories(): array {
		$root_dir = $this->path->get_plugin_path( $this->blocks_root );

		if ( ! $this->path->is_plugin_dir( $this->blocks_root ) ) {
			// Never named, and the default is absent: this plugin has none of
			// these yet. Only a directory asked for by name is missing in the
			// sense worth throwing over.
			if ( ! $this->blocks_root_was_set ) {
				return array();
			}

			throw DiscoveryException::missing_root( 'Blocks', $root_dir, 'set_blocks_root()' );
		}

		$directories = array();

		/*
		 * The walker yields files, and a block is a directory, so `block.json`
		 * is what is matched and its parent is what is wanted. Depth 2 reaches
		 * one level below the root, which is where a built block sits.
		 */
		foreach ( $this->walk_folder( $root_dir, array( 'json' ), 2 ) as $file ) {
			if ( 'block.json' !== \basename( $file ) ) {
				continue;
			}

			$name = \dirname( $file );

			if ( '.' === $name ) {
				// A block.json directly in the root: the root is the block.
				$name = \basename( $root_dir );
			}

			$directories[ $name ] = $root_dir . '/' . \dirname( $file );
		}

		return $directories;
	}

	/**
	 * The class a render file returns, requiring the file once to find out.
	 *
	 * Recording it the first time means every later occurrence of the block --
	 * and every static call on it -- skips the require.
	 *
	 * @param string $file Absolute path to a render file.
	 * @return class-string<Block>
	 * @throws DiscoveryException When the render file does not return a Block instance.
	 */
	private function get_renderer_class( string $file ): string {
		if ( ! isset( $this->renderer_classes[ $file ] ) ) {
			$this->load_renderer( $file );
		}

		return $this->renderer_classes[ $file ];
	}

	/**
	 * The absolute path of a block's render file.
	 *
	 * Resolved on demand, from the two strings recorded at registration, and
	 * kept once built. A block that never renders never has its path built, and
	 * one that renders repeatedly builds it once.
	 *
	 * The filesystem is not consulted: `require` reports a missing file
	 * perfectly well at the point it actually matters.
	 *
	 * @param string $name The block's namespaced name.
	 * @return string The render file's absolute path.
	 */
	private function get_render_file( string $name ): string {
		[ $metadata_file, $render, $resolved ] = $this->render_files[ $name ];

		if ( $resolved ) {
			return $render;
		}

		/*
		 * A third element rather than testing for a `file:` prefix: core's own
		 * remove_block_asset_path_prefix() treats the prefix as optional, so a
		 * bare "block.php" is valid and would otherwise look like a path that
		 * had already been resolved.
		 */
		$file = \wp_normalize_path(
			\dirname( $metadata_file ) . '/' . \preg_replace( '/^file:\.?\/?/', '', $render )
		);

		$this->render_files[ $name ] = array( $metadata_file, $file, true );

		return $file;
	}

	/**
	 * Whether a block belongs to this plugin, by its namespace.
	 *
	 * A block's name is `{namespace}/{name}`, and `wp zestry make block` writes the
	 * plugin's own slug as that namespace, so this is one prefix comparison --
	 * which matters on a filter every registered block on the site reaches.
	 *
	 * The namespace rather than the file path: it does not care where the plugin
	 * is installed, whether a symlink stands between the two paths, or what the
	 * blocks root was configured to. A consumer choosing a different namespace
	 * in `block.json` is opting out of this module's rendering.
	 *
	 * @param string $block_name The block's namespaced name.
	 * @return bool True when the block is this plugin's.
	 */
	private function owns( string $block_name ): bool {
		return \str_starts_with( $block_name, $this->get_block_namespace() . '/' );
	}

	/**
	 * This plugin's slug as a block namespace.
	 *
	 * Your slug unchanged. WordPress validates a block name against
	 * `/^[a-z0-9-]+\/[a-z0-9-]+$/` (`WP_Block_Type_Registry::register()`), and
	 * `Plugin` accepts only a slug that satisfies the namespace half, so there is
	 * nothing to convert -- this is the same string `wp zestry make block` writes
	 * into a generated `block.json`.
	 *
	 * @return string
	 */
	private function get_block_namespace(): string {
		return $this->get_plugin()->get_slug();
	}

	/**
	 * Build a wired Block instance for one occurrence of a block.
	 *
	 * The render file is required once and its class name kept: an anonymous
	 * class still has a real name, so every later occurrence is a `new` on that
	 * name rather than another `require`. Built rather than cloned, since a
	 * clone is shallow and would share any object the block holds with every
	 * other occurrence.
	 *
	 * @param string $file Absolute path to a render file.
	 * @return Block A freshly wired instance.
	 * @throws DiscoveryException When the render file does not return a Block instance.
	 */
	private function load_renderer( string $file ): Block {
		if ( isset( $this->renderer_classes[ $file ] ) ) {
			$instance = new $this->renderer_classes[ $file ]();
			$this->get_plugin()->wire( $instance );

			return $instance;
		}

		// Buffered so a file that echoes instead of returning cannot print at
		// load time; it is reported as a wrong-typed discovery below either way.
		\ob_start();
		$instance = require $file;
		\ob_end_clean();

		// `require` yields int(1) for a file that returns nothing at all.
		if ( 1 === $instance ) {
			$instance = null;
		}

		if ( ! $instance instanceof Block ) {
			throw new DiscoveryException(
				\sprintf(
					'The file "%s" must return an instance of %s. Got: %s',
					$file,
					Block::class,
					\is_object( $instance ) ? $instance::class : \gettype( $instance )
				)
			);
		}

		// Kept so every later occurrence skips the require entirely.
		$this->renderer_classes[ $file ] = $instance::class;

		$this->get_plugin()->wire( $instance );

		return $instance;
	}
}
