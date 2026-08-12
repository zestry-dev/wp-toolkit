<!--
    Generated from src/Modules/Blocks/Block.php.
    Do not edit by hand: run `composer docs` after changing the source.
-->

# Block

[Generated starting point](#generated-starting-point) &nbsp;·&nbsp; [You must implement](#you-must-implement) &nbsp;·&nbsp; [Methods you can use](#methods-you-can-use)

Base class for the server-side render of a dynamic block.

A block's `block.php` returns a subclass instance. The Blocks module wires it (assigning the shared plugin and injecting typed module properties) and calls `render()` whenever WordPress renders the block, so the markup a block produces has the plugin's own modules available to it without a constructor or a `get()` call.

Only a *dynamic* block needs one of these. A static block — markup saved into the post content by the editor — is discovered and registered by the module exactly the same way, it simply has no `block.php` and no class.

The block's metadata stays in `block.json`, which is also what the editor build reads, so a name, title, category or attribute is declared once rather than in two places that can disagree. This class covers only what `block.json` cannot express: the PHP that runs at render time.

**One instance per occurrence.** The block file is required once and the class it returned kept, but every occurrence of the block on the page gets its own instance — so anything stored on `$this` during a render belongs to that occurrence alone. Anything genuinely shared across occurrences belongs on a module, and a `static` *property* here would defeat the guarantee (a `static` method or constant is fine).

Because the file is required once, a helper function or a second class declared alongside the block is declared exactly once however many times the block appears — unlike WordPress's own `render.php`, which loads per occurrence. Extra PHP files next to the block can just be `require`d: `--webpack-copy-php` copies every `.php` under `src/` into the build, and only `block.php` needs naming in `block.json`.

**Children arrive rendered.** WordPress renders a block's inner blocks into its saved markup before calling `render()`, so `$content` is them, ready to place. A block that would rather decide when that happens — to render its children once per item in a loop, say — overrides `skips_inner_blocks()` and calls `render_inner_blocks()` itself.

## Generated starting point

[`wp zt make block <name>`](../../commands/make-block.md) writes these files:

### The metadata

WordPress reads everything it knows about the block from here: its name, the attributes it parses before `render()` sees them, and which editor features it supports. Every other file is reached from this one, by a `file:` path.

Those paths name the source files. `@wordpress/scripts` rewrites them when it copies this into the build, so the shipped file names `index.js` where this one names `index.tsx`.

> [!IMPORTANT]
> **`render` wins over `supports.{plugin-slug}-php` when a block declares both.** `render` is WordPress's own field, and a block using it is rendered by WordPress — this module leaves that block alone entirely, including its `supports.{plugin-slug}-php` field if one is there. Declare `supports.{plugin-slug}-php` alone to render through the class its file returns. Declare neither and the block is static. All four cases register normally, since WordPress ignores a field that is not one of its own.

```json
{
	"$schema": "https://schemas.wp.org/trunk/block.json",
	"apiVersion": 3,
	"name": "acme-plugin/example",
	"title": "Example",
	"category": "widgets",
	"icon": "block-default",
	"description": "",
	"textdomain": "acme-plugin",
	"attributes": {
		"title": {
			"type": "string",
			"default": ""
		}
	},
	"supports": {
		"html": false,
		"acme-plugin-php": "file:./block.php"
	},
	"editorScript": "file:./index.tsx",
	"editorStyle": "file:./index.css",
	"style": "file:./style-index.css"
}
```

### The server-side render

Written only with `--dynamic`, and named by the `supports.{plugin-slug}-php` entry above. A block without one is static: the editor saves its markup into the post content, and nothing runs at render time.

```php
<?php
/**
 * Example block: server-side render.
 */

declare( strict_types=1 );

// Loaded by WordPress, never requested directly.
defined( 'ABSPATH' ) || exit;

use Acme\Plugin\Core\Modules\Blocks\Block;

return new class() extends Block {

	// Declare any module this block needs as a public typed property and the
	// plugin injects it before render() runs -- no constructor, no get().
	// Then render through it, e.g. `return $this->views->get( 'blocks/example',
	// array( 'title' => $attributes['title'] ?? '' ) );`
	//
	// public Views $views;

	/**
	 * Return this block's front-end markup.
	 *
	 * Returns rather than echoes: anything echoed here lands ahead of the
	 * block, outside its own output.
	 *
	 * @param array     $attributes Parsed and defaulted against block.json, so a
	 *                              declared attribute is always present.
	 * @param string    $content    This block's children, already rendered and
	 *                              ready to place -- return it somewhere to wrap
	 *                              them, ignore it to render without them.
	 * @param \WP_Block $block      ->context for values an ancestor passes down,
	 *                              ->inner_blocks to reach the children directly.
	 * @return string The markup, which WordPress outputs unchanged.
	 */
	public function render( array $attributes, string $content, \WP_Block $block ): string {
		return sprintf(
			'<div %s>%s</div>',
			get_block_wrapper_attributes(),
			esc_html( $attributes['title'] ?? '' )
		);
	}

	// Override to render this block's children yourself -- once per item in a
	// loop, say, or wrapped individually. WordPress then leaves $content empty
	// and $this->render_inner_blocks( $block ) produces them on demand.
	public static function skips_inner_blocks(): bool {
		return false;
	}

	/**
	 * Enqueue a third-party dependency this block needs.
	 *
	 * Optional. Runs once, the first time this block renders on the page, so a
	 * library (a slider, a charting script) loads only where the block is used.
	 *
	 * Register the handle at boot, from the plugin's own Assets initializer,
	 * and enqueue it by name here: this runs mid-render, which is too late to
	 * be declaring dependencies and versions.
	 *
	 * This block's own scripts and styles belong in block.json instead, which
	 * WordPress both registers and enqueues wherever the block appears.
	 *
	 * @param \Acme\Plugin\Core\Kernel\Plugin $plugin
	 * @return void
	 */
	public static function enqueue_assets( \Acme\Plugin\Core\Kernel\Plugin $plugin ): void {
		// $plugin->get( Assets::class )->enqueue_entry( 'slider' );
	}
};
```

### The editor registration

Runs in the editor, not on the front end. It imports `block.json` rather than repeating the block's name, so the two cannot drift apart.

```tsx
import { registerBlockType } from '@wordpress/blocks';
import { InnerBlocks } from '@wordpress/block-editor';

import metadata from './block.json';
import Edit from './edit';
import './style.css';
import './editor.css';

export interface ExampleAttributes {
	title: string;
	// `BlockEditProps< T >` constrains T to `Record< string, unknown >`, which an
	// interface satisfies only with an index signature.
	[ key: string ]: unknown;
}

// The name comes from block.json rather than being repeated here, so the two
// can never disagree. `...metadata` carries the rest of it: `@types/wordpress__blocks`
// predates block.json and asks for `title`, `category` and `attributes` in this
// object, and spreading them satisfies that without writing them twice.
registerBlockType< ExampleAttributes >( metadata.name, {
	...metadata,
	edit: Edit,
	// Rendered in PHP by block.php. Only the inner blocks are saved,
	// and they arrive there as the render() method's $content.
	save: () => <InnerBlocks.Content />,
} );
```

### The editor component

What the block looks like while it is being edited, which is often not what it looks like on the front end — a dynamic block renders in PHP there, and this component never runs outside the editor.

```tsx
import { useBlockProps } from '@wordpress/block-editor';
import type { BlockEditProps } from '@wordpress/blocks';
import { __ } from '@wordpress/i18n';

import type { ExampleAttributes } from './index';

// WordPress's own props type, so `setAttributes` and everything the editor passes
// is typed the way the editor passes it. A hand-written shape here is what
// `registerBlockType()` then refuses.
type EditProps = BlockEditProps< ExampleAttributes >;

export default function Edit( { attributes }: EditProps ) {
	return (
		<div { ...useBlockProps() }>
			{ attributes.title || __( 'Example', 'acme-plugin' ) }
		</div>
	);
}
```

## You must implement

This one method is abstract: a subclass that does not declare it will not load.

### `render( $attributes, $content, $block )`

Produce the block's front-end markup.

```php
abstract public function render( array $attributes, string $content, WP_Block $block ): string
```

|  | Details |
|---|---|
| **Parameters** | `$attributes` — Attributes parsed and defaulted against `block.json`<br>`$content` — This block's children, already rendered and ready to place — empty when `skips_inner_blocks()` is overridden, in which case `render_inner_blocks()` produces them<br>`$block` — For `$block->context` and `$block->inner_blocks` |
| **Return** | The block's markup |
| **Throws** | — |

Returns the markup rather than echoing it, so the module hands WordPress exactly what this returns. Anything echoed here lands outside the block's own output, ahead of it in the page.

## Methods you can use

### `render_inner_blocks( $block )`

Render a block's children into its own saved markup.

```php
final public function render_inner_blocks( WP_Block $block ): string
```

|  | Details |
|---|---|
| **Parameters** | `$block` — The block whose children to render |
| **Return** | Every child's rendered markup |
| **Throws** | — |

Only needed by a block that overrides `skips_inner_blocks()`: without that, WordPress has already done this and `$content` carries the result.

Hands the work back to `WP_Block::render()` with `dynamic` set to false, the way core's own `core/post-template` renders its children. That is the same path core takes for a block that does not skip its inner blocks, so the result is exactly what `$content` would have been: each child back where the editor put it, the markup saved around them kept, every `render_block*` filter applied, and each child's own scripts and styles enqueued. `dynamic => false` is what stops it re-entering this block's own render.

The block is rebuilt with no name, so nothing renders this block's own wrapper attributes or block supports a second time — `render()` has already produced those, and this is only being asked for the children.

Safe to call more than once, which is what a loop block does: each call builds the children afresh, so a `render_block_context` filter added around the call reaches them. That is how core's own post-template puts a different post into each pass.

<br>

### `enqueue_assets( $plugin )`

Enqueue a third-party dependency this block needs.

```php
public static function enqueue_assets( Plugin $plugin ): void
```

|  | Details |
|---|---|
| **Parameters** | `$plugin` — The plugin, for reaching a module without an instance |
| **Return** | — |
| **Throws** | — |

Called once per request, the first time this block renders on the page, so a library only loads on the pages that actually use it — a slider, an animation library, a charting script.

`static`, because it is a decision about the block, not about any one occurrence of it. It runs once however many times the block appears, so there is no occurrence whose attributes it could read. Reach a module through the plugin instead, as the example shows.

> [!NOTE]
> **For enqueueing only, and not for the block's own assets.** Register the handle at boot from the plugin's `Assets` initializer, then enqueue it by name here — this runs mid-render, too late to declare dependencies and versions. A block's own scripts and styles go in `block.json`, which WordPress registers *and* enqueues wherever the block appears.

## Getting data to your `viewScript`

Not through this method, and not with `wp_localize_script()`. Put it in the markup `render()` returns, as `data-` attributes, and read it back in the browser:

```php
// render()
return sprintf(
    '<form %s data-endpoint="%s" data-nonce="%s"></form>',
    get_block_wrapper_attributes(),
    esc_url( rest_url( 'acme-plugin/v1/submit' ) ),
    esc_attr( wp_create_nonce( 'wp_rest' ) )
);
```

```js
const form = document.querySelector( '[data-endpoint]' );
fetch( form.dataset.endpoint, { headers: { 'X-WP-Nonce': form.dataset.nonce } } );
```

The reason is not style. A block appears any number of times on a page, each occurrence with its own attributes, and a localized script object is one global for the whole page — so the second occurrence overwrites the first, or reads the first's data. An attribute belongs to the element it is on, which is the only place per-occurrence data can correctly live. A nonce is the exception: it is tied to a user and a window, so a full-page cache serves a stale one whatever carries it. Verify it when you have it and fall back to a capability check when you do not, or keep the endpoint public and defend it another way.

An attribute also survives full-page caching, where a localized `<script>` written at render time does not.

If you do need a page-wide value, WordPress registers a `viewScript` under a handle it generates: `{namespace}-{name}-view-script`, so `acme-plugin/card` gives `acme-plugin-card-view-script`. Pass that to `wp_add_inline_script()`, the same call any other handle takes.

For an Interactivity API block, `wp_interactivity_state()` is the right answer to all of this and none of the above applies.

<br>

### `skips_inner_blocks()`

Whether WordPress should leave this block's children entirely to it.

```php
public static function skips_inner_blocks(): bool
```

|  | Details |
|---|---|
| **Parameters** | — |
| **Return** | `bool` |
| **Throws** | — |

False by default, which is WordPress's own behaviour: it renders the children and hands them to `render()` as `$content`, ready to place. Return true for a block that renders its children itself — a loop block repeating one child per item, say — so WordPress does not do that work first and throw it away. `render_inner_blocks()` then produces them on demand.

Read on `pre_render_block`, the first time the block is about to render, and written onto the registered block type from there — so overriding it costs nothing on `init`. `static`, because the answer is settled once per request for the whole block type, before any occurrence of the block exists to ask.

<br>

### `get_plugin()`

Get the plugin this class belongs to.

```php
final public function get_plugin(): Plugin
```

|  | Details |
|---|---|
| **Parameters** | — |
| **Return** | The plugin instance |
| **Throws** | — |

Use it to reach something you did not declare a property for — a module you need in one method only, or one you look up by a name computed at runtime. For anything you use throughout the class, declare a typed property instead and let it be injected.

```php
$this->get_plugin()->get( Options::class )->get( 'api_key' );
```
