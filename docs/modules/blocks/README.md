<!--
    Generated from src/Modules/Blocks/Blocks.php.
    Do not edit by hand: run `composer docs` after changing the source.
-->

# Blocks

Discovers `build/blocks/` &nbsp;·&nbsp; Each file returns [`Block`](block.md) &nbsp;·&nbsp; Dependencies [`path`](../../services/path/)

Discovers plugin editor blocks and registers them with WordPress.

> [!NOTE]
> **This module registers blocks; it does not help you write one.** The PHP half is what it takes care of — discovery, registration, wiring, rendering — and that is the smaller half of a block. The editor half is React against `@wordpress/block-editor`: WordPress's API, documented by WordPress. `wp zt make block` hands you a working `edit.tsx` and stops there on purpose.
>
> So a plugin whose interface *is* blocks is mostly a JavaScript project, with this toolkit looking after its PHP end. Plan for that.

A blocks directory contains one subdirectory per block, each holding the `block.json` that `@wordpress/scripts` compiled there. The module registers every one it finds, so adding a block is a matter of building it rather than writing another `register_block_type()` call.

The root is the *built* directory (`build/blocks` by default), not the source one: `block.json`'s own `file:` paths are relative to wherever it sits, so pointing WordPress at the build output is what makes them resolve without any rewriting here.

A block declares its PHP with `"supports": { "{plugin-slug}-php": "file:./block.php" }`, and that file returns a `Block` instance — loaded the first time the block renders, wired, and called, so its PHP has the plugin's own modules injected. A file returning anything else raises a DiscoveryException.

A block declaring WordPress's own `render` field instead is left alone entirely, and a block declaring neither is static. Both are still registered.

## Static or dynamic

`wp zt make block` asks, and defaults to static. Three questions settle it, in this order.

**Does the output depend on anything outside the block's own attributes?** A query, an option, the current user, another post — then it is dynamic, and there is nothing left to weigh.

**Is the markup settled?** Then static. It is saved into `post_content`, so it survives the plugin being deactivated, as plain HTML that still reads as the content it was. Changing it afterwards means owing a `deprecated` entry and a migration, or every post already saved shows "This block contains unexpected or invalid content".

**Is the markup still moving?** Then dynamic, which is free to change forever. What it costs is that the content is not in `post_content`: deactivate the plugin and the block renders nothing at all.

**Performance is not the deciding factor, and it is the usual reason given.** A dynamic block costs one PHP call per instance while `the_content` is assembled, which is not measurable next to the rest of a page load — full page caching applies either way. What costs is the work *inside* the render: a `WP_Query` per block on a page listing forty of them is the thing to avoid, and it is equally avoidable in a dynamic block.

So the trade is maintenance against content ownership, not speed. A plugin that renders everything dynamically to keep its markup free is a deliberate and defensible choice; one that does it by default has usually not been asked the first question.

Registration reads `blocks-manifest.php` when one is present (see `wp-scripts build --blocks-manifest`), which spares WordPress a `block.json` read and decode per block, and walks the blocks directory when there is not.

[Adding it](#adding-it) &nbsp;·&nbsp; [Changing the defaults](#changing-the-defaults) &nbsp;·&nbsp; [Writing a Block](#writing-a-block) &nbsp;·&nbsp; [Constants](#constants) &nbsp;·&nbsp; [You must implement](#you-must-implement) &nbsp;·&nbsp; [Methods you can use](#methods-you-can-use) &nbsp;·&nbsp; [See also](#see-also)

## Adding it

```bash
wp zt add module blocks
```

> [!IMPORTANT]
> **A module is built because `bootstrap.php` lists it.** `Blocks` binds its hooks when the plugin builds it, so it has to be listed there — which `wp zt add` writes for you. Left out, nothing is discovered and nothing reports why; [`wp zt doctor`](../../commands/doctor.md) is what catches it.

```php
// bootstrap.php
return array(
    Blocks::class,
);
```

## Changing the defaults

Register an initializer only to point the module at a non-default directory, or to declare a block category of the plugin's own.

An initializer runs while the plugin file loads, which is before `init` and so before a text domain may be touched. `Module::on_wp_init()` moves whatever needs the later point — here the translated headings — without the caller having to know whether `init` has already passed.

```php
// bootstrap.php
return array(
    Blocks::class => static function ( Blocks $blocks ): void {
        $blocks->set_blocks_root( 'build/editor-blocks' );

        $blocks->on_wp_init( function ( Blocks $module ) {
            $module->add_categories( [
                'reports' => __( 'Reports', 'my-plugin' ),
                'charts'  => [
                    'title' => __( 'Charts', 'my-plugin' ),
                    'icon'  => 'chart-bar',
                ],
            ] );
        } );
    },
);
```

## Writing a Block

A file in `build/blocks/` returns a [`Block`](block.md) instance, which `wp zt make block <name>` generates.

You write it in `src/blocks/` — that is what `wp zt make block <name>` creates and what you edit. `build/blocks/` holds the compiled output `npm run build` produces, and is the directory this module reads.

## Constants

### `DEFAULT_BLOCKS_ROOT`

```php
const DEFAULT_BLOCKS_ROOT = 'build/blocks';
```

Default plugin-relative directory of built block directories.

### `MANIFEST_FILENAME`

```php
const MANIFEST_FILENAME = 'blocks-manifest.php';
```

Filename `wp-scripts build --blocks-manifest` writes into the build root.

## You must implement

This one method is abstract: a subclass that does not declare it will not load.

### `on_boot()`

What this module does on its own.

```php
abstract protected function on_boot(): void
```

Runs once, when the plugin builds the module. Abstract rather than optional: a module with nothing to do here is a `Service`.

**Bind hooks here; do the work in them.** An entry file that calls `run()` as it loads — which is the documented shape, and what `ActivationHandler` requires — reaches this before WordPress has required `pluggable.php`, so there is no current user yet: `current_user_can()`, `wp_mail()` and the nonce functions are not defined and calling one is a fatal. It is also before `init`, so `__()` here asks for a text domain nothing has loaded. `$wpdb` *is* up, so a query works — but it runs on every request, including the ones that never needed it.

`on_wp_init()` is the way out of all three, and where anything a module registers belongs.

## Methods you can use

### `set_blocks_root( $blocks_root )`

Set the plugin-relative directory that contains built block directories.

```php
public function set_blocks_root( string $blocks_root ): void
```

|  | Details |
|---|---|
| **Parameters** | `$blocks_root` — Plugin-relative directory of built block directories |
| **Return** | — |
| **Throws** | — |

Call this from the module initializer before the plugin boots the module to override the default `build/blocks` directory.

<br>

### `add_categories( $categories )`

Declare the block categories this plugin's blocks sit in.

```php
public function add_categories( array $categories ): void
```

|  | Details |
|---|---|
| **Parameters** | `$categories` — Titles or configuration, keyed by slug |
| **Return** | — |
| **Throws** | `InvalidArgumentException` — When an entry is an array without a title |

Call this from the module initializer. A block claims a category by naming it in its own `block.json` "category" field; declaring it here only makes the inserter show it as a group with a title of its own.

Keyed by slug, the same shape `bootstrap.php` uses for modules, so the groups read as data. A plain string is the title; an array carries an `icon` alongside it:

```php
// bootstrap.php
$blocks->on_wp_init(
    static function ( Blocks $blocks ): void {
        $blocks->add_categories(
            array(
                'reports' => __( 'Reports', 'my-plugin' ),
                'charts'  => array(
                    'title' => __( 'Charts', 'my-plugin' ),
                    'icon'  => 'chart-bar',
                ),
            )
        );
    }
);

// src/blocks/sales/block.json
{ "name": "my-plugin/sales", "category": "reports" }
```

The category and the block that claims it live in two files, and only the block.json half is checked by anything — a block naming a category that was never declared is filed under Uncategorized rather than erroring, so the two have to be kept in step by hand.

A slug is registered exactly as given and is not namespaced to the plugin slug the way a hook or an option name is: it has to match what a hand-written `block.json` says verbatim, and namespacing would register `{plugin-slug}-reports` while every block still asked for `reports`. Choose slugs distinctive enough not to collide — reusing one of WordPress's own (`text`, `media`, `design`, `widgets`, `theme`, `embed`) adds a second entry rather than renaming the first.

**Call it from `Module::on_wp_init()`, as the example does.** A title is user-visible, so it usually wants translating, and an initializer runs while the plugin file loads — early enough that a `__()` there loads the text domain before WordPress is ready and reports `_load_textdomain_just_in_time` on every request. Inside `on_wp_init()` ordinary `__()` is correct, which is why a title is a plain string and nothing here is lazy.

Order is kept: categories appear in the inserter after WordPress's own, in the order declared here, and a later call appends to an earlier one.

<br>

### `get_discovered_blocks()`

Every discovered block directory, keyed by its own directory name.

```php
public function get_discovered_blocks(): array
```

|  | Details |
|---|---|
| **Parameters** | — |
| **Return** | Absolute directory paths keyed by directory name |
| **Throws** | `DiscoveryException` — When a blocks directory named by set_blocks_root() does not exist |

<br>

### `on_wp_init( $callback )`

Run a callback on `init`, or immediately if `init` has already fired.

```php
final public function on_wp_init( callable $callback ): void
```

|  | Details |
|---|---|
| **Parameters** | `$callback` — What to run |
| **Return** | — |
| **Throws** | — |

Almost everything a module registers — a post type, a block, a WP-CLI command — has to happen on `init`, and a plain `add_action( 'init', ... )` is a callback that never runs once `init` has passed. A module can be resolved on either side of it: `Plugin::run()` is synchronous, so an entry file that calls it at plugin load is ahead of `init`, while one that calls it from a later hook — or a `get()` during a request — is behind. This behaves the same either way, so a module never has to care which.

The callback receives the module, matching the initializer signature, so a closure declared elsewhere needs no `use` to reach it:

```php
protected function on_boot(): void {
    $this->on_wp_init( function ( self $module ): void {
        $module->register_widgets();
    } );
}
```

## See also

- [`Block`](block.md) — what a file in `build/blocks/` returns
- [`path`](../../services/path/) — copied in alongside this one
- [`Module`](../module.md) — what every module inherits
- [`wp zt add module blocks`](../../commands/add-module.md) — the command that copies it
