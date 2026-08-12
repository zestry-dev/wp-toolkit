<?php

/**
 * Blocks API: Block base class
 */

declare( strict_types=1 );

namespace Zestry\WPToolkit\Modules\Blocks;

// Loaded by WordPress, never requested directly.
\defined( 'ABSPATH' ) || exit;

use Zestry\WPToolkit\Kernel\Contracts\PluginAware;
use Zestry\WPToolkit\Kernel\Plugin;
use Zestry\WPToolkit\Kernel\Traits\WithPlugin;
use WP_Block;

/**
 * Base class for the server-side render of a dynamic block.
 *
 * A block's `block.php` returns a subclass instance. The Blocks module wires
 * it (assigning the shared plugin and injecting typed module properties) and
 * calls `render()` whenever WordPress renders the block, so the markup a block
 * produces has the plugin's own modules available to it without a constructor
 * or a `get()` call.
 *
 * Only a *dynamic* block needs one of these. A static block — markup saved into
 * the post content by the editor — is discovered and registered by the module
 * exactly the same way, it simply has no `block.php` and no class.
 *
 * The block's metadata stays in `block.json`, which is also what the editor
 * build reads, so a name, title, category or attribute is declared once rather
 * than in two places that can disagree. This class covers only what
 * `block.json` cannot express: the PHP that runs at render time.
 *
 * **One instance per occurrence.** The block file is required once and the
 * class it returned kept, but every occurrence of the block on the page gets
 * its own instance -- so anything stored on `$this` during a render belongs to
 * that occurrence alone. Anything genuinely shared across occurrences belongs
 * on a module, and a `static` *property* here would defeat the guarantee (a
 * `static` method or constant is fine).
 *
 * Because the file is required once, a helper function or a second class
 * declared alongside the block is declared exactly once however many times the
 * block appears -- unlike WordPress's own `render.php`, which loads per
 * occurrence. Extra PHP files next to the block can just be `require`d:
 * `--webpack-copy-php` copies every `.php` under `src/` into the build, and
 * only `block.php` needs naming in `block.json`.
 *
 * **Children arrive rendered.** WordPress renders a block's inner blocks into
 * its saved markup before calling `render()`, so `$content` is them, ready to
 * place. A block that would rather decide when that happens -- to render its
 * children once per item in a loop, say -- overrides {@see skips_inner_blocks()}
 * and calls {@see render_inner_blocks()} itself.
 *
 * @stub block/block.json.stub  The metadata
 * WordPress reads everything it knows about the block from here: its name, the
 * attributes it parses before `render()` sees them, and which editor features
 * it supports. Every other file is reached from this one, by a `file:` path.
 *
 * Those paths name the source files. `@wordpress/scripts` rewrites them when
 * it copies this into the build, so the shipped file names `index.js` where
 * this one names `index.tsx`.
 *
 * > [!IMPORTANT]
 * > **`render` wins over `supports.{plugin-slug}-php` when a block declares both.**
 * > `render` is WordPress's own field, and a block using it is rendered by
 * > WordPress -- this module leaves that block alone entirely, including its
 * > `supports.{plugin-slug}-php` field if one is there. Declare `supports.{plugin-slug}-php`
 * > alone to render through the class its file returns. Declare neither and
 * > the block is static. All four cases register normally, since WordPress
 * > ignores a field that is not one of its own.
 *
 * @stub block/block.php.stub  The server-side render
 * Written only with `--dynamic`, and named by the `supports.{plugin-slug}-php` entry
 * above. A block without one is static: the editor saves its markup into the
 * post content, and nothing runs at render time.
 *
 * @stub block/index.tsx.stub  The editor registration
 * Runs in the editor, not on the front end. It imports `block.json` rather than
 * repeating the block's name, so the two cannot drift apart.
 *
 * @stub block/edit.tsx.stub  The editor component
 * What the block looks like while it is being edited, which is often not what
 * it looks like on the front end -- a dynamic block renders in PHP there, and
 * this component never runs outside the editor.
 */
abstract class Block implements PluginAware {

	use WithPlugin;

	/**
	 * Prevent direct construction from bypassing plugin initialization.
	 *
	 * @return void
	 */
	final public function __construct() {}

	/**
	 * Produce the block's front-end markup.
	 *
	 * Returns the markup rather than echoing it, so the module hands WordPress
	 * exactly what this returns. Anything echoed here lands outside the block's
	 * own output, ahead of it in the page.
	 *
	 * @param array<string, mixed> $attributes Attributes parsed and defaulted against `block.json`.
	 * @param string               $content    This block's children, already rendered and ready to place
	 *                                         -- empty when {@see skips_inner_blocks()} is overridden, in
	 *                                         which case {@see render_inner_blocks()} produces them.
	 * @param WP_Block             $block      For `$block->context` and `$block->inner_blocks`.
	 * @return string The block's markup.
	 */
	abstract public function render( array $attributes, string $content, WP_Block $block ): string;

	/**
	 * Render a block's children into its own saved markup.
	 *
	 * Only needed by a block that overrides {@see skips_inner_blocks()}: without
	 * that, WordPress has already done this and `$content` carries the result.
	 *
	 * Hands the work back to `WP_Block::render()` with `dynamic` set to false,
	 * the way core's own `core/post-template` renders its children. That is the
	 * same path core takes for a block that does not skip its inner blocks, so
	 * the result is exactly what `$content` would have been: each child back
	 * where the editor put it, the markup saved around them kept, every
	 * `render_block*` filter applied, and each child's own scripts and styles
	 * enqueued. `dynamic => false` is what stops it re-entering this block's
	 * own render.
	 *
	 * The block is rebuilt with no name, so nothing renders this block's own
	 * wrapper attributes or block supports a second time -- `render()` has
	 * already produced those, and this is only being asked for the children.
	 *
	 * Safe to call more than once, which is what a loop block does: each call
	 * builds the children afresh, so a `render_block_context` filter added
	 * around the call reaches them. That is how core's own post-template puts
	 * a different post into each pass.
	 *
	 * @example A block that renders its own children
	 * ```
	 * public static function skips_inner_blocks(): bool {
	 *     return true;
	 * }
	 *
	 * public function render( array $attributes, string $content, WP_Block $block ): string {
	 *     return sprintf(
	 *         '<section %s>%s</section>',
	 *         get_block_wrapper_attributes(),
	 *         $this->render_inner_blocks( $block )
	 *     );
	 * }
	 * ```
	 *
	 * @param WP_Block $block The block whose children to render.
	 * @return string Every child's rendered markup.
	 */
	final public function render_inner_blocks( WP_Block $block ): string {
		$parsed_block = $block->parsed_block;

		// A name no block type is registered under, so this renders as the
		// children alone -- core's own post-template block does the same.
		$parsed_block['blockName'] = 'core/null';

		return ( new WP_Block( $parsed_block, $block->context ) )->render( array( 'dynamic' => false ) );
	}

	/**
	 * Enqueue a third-party dependency this block needs.
	 *
	 * Called once per request, the first time this block renders on the page,
	 * so a library only loads on the pages that actually use it — a slider, an
	 * animation library, a charting script.
	 *
	 * `static`, because it is a decision about the block, not about any one
	 * occurrence of it. It runs once however many times the block appears, so
	 * there is no occurrence whose attributes it could read. Reach a module
	 * through the plugin instead, as the example shows.
	 *
	 * > [!NOTE]
	 * > **For enqueueing only, and not for the block's own assets.** Register
	 * > the handle at boot from the plugin's `Assets` initializer, then enqueue
	 * > it by name here -- this runs mid-render, too late to declare
	 * > dependencies and versions. A block's own scripts and styles go in
	 * > `block.json`, which WordPress registers *and* enqueues wherever the
	 * > block appears.
	 *
	 * ## Getting data to your `viewScript`
	 *
	 * Not through this method, and not with `wp_localize_script()`. Put it in the
	 * markup {@see render()} returns, as `data-` attributes, and read it back in
	 * the browser:
	 *
	 * ```php
	 * // render()
	 * return sprintf(
	 *     '<form %s data-endpoint="%s" data-nonce="%s"></form>',
	 *     get_block_wrapper_attributes(),
	 *     esc_url( rest_url( 'acme-plugin/v1/submit' ) ),
	 *     esc_attr( wp_create_nonce( 'wp_rest' ) )
	 * );
	 * ```
	 *
	 * ```js
	 * const form = document.querySelector( '[data-endpoint]' );
	 * fetch( form.dataset.endpoint, { headers: { 'X-WP-Nonce': form.dataset.nonce } } );
	 * ```
	 *
	 * The reason is not style. A block appears any number of times on a page,
	 * each occurrence with its own attributes, and a localized script object is
	 * one global for the whole page -- so the second occurrence overwrites the
	 * first, or reads the first's data. An attribute belongs to the element it
	 * is on, which is the only place per-occurrence data can correctly live.
	 * A nonce is the exception: it is tied to a user and a window,
	 * so a full-page cache serves a stale one whatever carries it. Verify it when
	 * you have it and fall back to a capability check when you do not, or keep the
	 * endpoint public and defend it another way.
	 *
	 * An attribute also survives full-page caching, where a localized `<script>` written
	 * at render time does not.
	 *
	 * If you do need a page-wide value, WordPress registers a `viewScript` under
	 * a handle it generates: `{namespace}-{name}-view-script`, so
	 * `acme-plugin/card` gives `acme-plugin-card-view-script`. Pass that to
	 * `wp_add_inline_script()`, the same call any other handle takes.
	 *
	 * For an Interactivity API block, `wp_interactivity_state()` is the right
	 * answer to all of this and none of the above applies.
	 *
	 * @example Enqueueing a library registered at boot
	 * ```
	 * public static function enqueue_assets( Plugin $plugin ): void {
	 *     $plugin->get( Assets::class )->enqueue_entry( 'slider' );
	 * }
	 * ```
	 *
	 * @param Plugin $plugin The plugin, for reaching a module without an instance.
	 * @return void
	 */
	public static function enqueue_assets( Plugin $plugin ): void {}

	/**
	 * Whether WordPress should leave this block's children entirely to it.
	 *
	 * False by default, which is WordPress's own behaviour: it renders the
	 * children and hands them to `render()` as `$content`, ready to place.
	 * Return true for a block that renders its children itself -- a loop block
	 * repeating one child per item, say -- so WordPress does not do that work
	 * first and throw it away. {@see render_inner_blocks()} then produces them
	 * on demand.
	 *
	 * Read on `pre_render_block`, the first time the block is about to render,
	 * and written onto the registered block type from there -- so overriding it
	 * costs nothing on `init`. `static`, because the answer is settled once per
	 * request for the whole block type, before any occurrence of the block
	 * exists to ask.
	 *
	 * @return bool
	 */
	public static function skips_inner_blocks(): bool {
		return false;
	}
}
