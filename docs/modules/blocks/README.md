<!--
    Generated from src/Modules/Blocks/Blocks.php.
    Do not edit by hand: run `composer docs` after changing the source.
-->

# Blocks

Discovers `build/blocks/` &nbsp;·&nbsp; Each file returns [`Block`](block.md) &nbsp;·&nbsp; Dependencies [`path`](../path/)

Discovers plugin editor blocks and registers them with WordPress.

> [!NOTE]
> **This module registers blocks; it does not help you write one.** The PHP half is what it takes care of — discovery, registration, wiring, rendering — and that is the smaller half of a block. The editor half is React against `@wordpress/block-editor`: WordPress's API, documented by WordPress. `wp zt make block` hands you a working `edit.tsx` and stops there on purpose.
>
> So a plugin whose interface *is* blocks is mostly a JavaScript project, with this toolkit looking after its PHP end. Plan for that.

A blocks directory contains one subdirectory per block, each holding the `block.json` that `@wordpress/scripts` compiled there. The module registers every one it finds, so adding a block is a matter of building it rather than writing another `register_block_type()` call.

The root is the *built* directory (`build/blocks` by default), not the source one: `block.json`'s own `file:` paths are relative to wherever it sits, so pointing WordPress at the build output is what makes them resolve without any rewriting here.

A block declares its PHP with `"supports": { "{plugin-slug}-php": "file:./block.php" }`, and that file returns a `Block` instance — loaded the first time the block renders, wired, and called, so its PHP reaches the plugin's own modules with `with()`. A file returning anything else raises a DiscoveryException.

A block declaring WordPress's own `render` field instead is left alone entirely, and a block declaring neither is static. Both are still registered.

A block is dynamic when it declares PHP and static when it does not: `wp zt make block --dynamic` writes the `block.php`, and asks when you leave the flag out. `Block` covers what that file returns.

Registration reads `blocks-manifest.php` when one is present (see `wp-scripts build --blocks-manifest`), which spares WordPress a `block.json` read and decode per block, and walks the blocks directory when there is not.

[Adding it](#adding-it) &nbsp;·&nbsp; [Changing the defaults](#changing-the-defaults) &nbsp;·&nbsp; [Writing a Block](#writing-a-block) &nbsp;·&nbsp; [Constants](#constants) &nbsp;·&nbsp; [Methods](#methods) &nbsp;·&nbsp; [See also](#see-also)

## Adding it

```bash
wp zt add blocks
```

> [!IMPORTANT]
> **A module is built because `bootstrap.php` lists it, and the heading says when.** `Blocks` acts the moment it is built, so it goes under the hook it acts on — which `wp zt add` writes for you. Left at the top level it throws; left out entirely, nothing is discovered and nothing reports why, which is what [`wp zt doctor`](../../commands/doctor.md) catches.

```php
// bootstrap.php
return array(
    'init' => array(
        Blocks::class,
    ),
);
```

## Changing the defaults

Group them in the inserter

```php
// bootstrap.php
return array(
    'init' => array(
        Blocks::class => static function ( Blocks $blocks ): void {
            $blocks->add_categories(
                array(
                    'reports' => __( 'Reports', 'acme-plugin' ),
                    'charts'  => array(
                        'title' => __( 'Charts', 'acme-plugin' ),
                        'icon'  => 'chart-bar',
                    ),
                )
            );
        },
    ),
);
```

The callback runs on that hook, right before the module registers anything. That is what makes the `__()` calls safe: a callback running at plugin load is before `init`, and touching a text domain there reports `_load_textdomain_just_in_time` on every request.

## Writing a Block

A file in `build/blocks/` returns a [`Block`](block.md) instance, which `wp zt make block <name>` generates.

You write it in `src/blocks/` — that is what `wp zt make block <name>` creates and what you edit. `build/blocks/` holds the compiled output `npm run build` produces, and is the directory this module reads.

## Constants

### `BLOCKS_ROOT`

```php
const BLOCKS_ROOT = 'build/blocks';
```

Default plugin-relative directory of built block directories.

### `MANIFEST_FILENAME`

```php
const MANIFEST_FILENAME = 'blocks-manifest.php';
```

Filename `wp-scripts build --blocks-manifest` writes into the build root.

## Methods

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
return array(
    'init' => array(
        Blocks::class => static function ( Blocks $blocks ): void {
            $blocks->add_categories(
                array(
                    'reports' => __( 'Reports', 'acme-plugin' ),
                    'charts'  => array(
                        'title' => __( 'Charts', 'acme-plugin' ),
                        'icon'  => 'chart-bar',
                    ),
                )
            );
        },
    ),
);

// src/blocks/sales/block.json
{ "name": "acme-plugin/sales", "category": "reports" }
```

The category and the block that claims it live in two files, and only the block.json half is checked by anything — a block naming a category that was never declared is filed under Uncategorized rather than erroring, so the two have to be kept in step by hand.

A slug is registered exactly as given and is not namespaced to the plugin slug the way a hook or an option name is: it has to match what a hand-written `block.json` says verbatim, and namespacing would register `{plugin-slug}-reports` while every block still asked for `reports`. Choose slugs distinctive enough not to collide — reusing one of WordPress's own (`text`, `media`, `design`, `widgets`, `theme`, `embed`) adds a second entry rather than renaming the first.

**Call it from the entry's callback, as the example does.** A title is user-visible, so it usually wants translating, and that callback runs when the module is built — which, listed under `'init'`, is on `init`, where ordinary `__()` is correct. That is why a title is a plain string and nothing here is lazy. Calling it from somewhere that runs while the plugin file loads asks for a text domain before WordPress is ready, and reports `_load_textdomain_just_in_time` on every request.

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
| **Throws** | — |

<br>

### `on_wp_init( $callback, $priority )`

*Inherited from [`Module`](../module.md).*

Run a callback on `init`, or immediately if `init` has already fired.

```php
final public function on_wp_init( callable $callback, int $priority = 10 ): void
```

|  | Details |
|---|---|
| **Parameters** | `$callback` — What to run<br>`$priority` — WordPress hook priority, honoured only while `init` is still ahead |
| **Return** | — |
| **Throws** | — |

<br>

### `get_plugin()`

*Inherited from [`WithPlugin`](../../kernel/with-plugin.md).*

Get the plugin this class belongs to.

```php
final public function get_plugin(): Plugin
```

|  | Details |
|---|---|
| **Parameters** | — |
| **Return** | The plugin instance |
| **Throws** | — |

<br>

### `with( $name )`

*Inherited from [`WithPlugin`](../../kernel/with-plugin.md).*

Reach another module.

```php
final public function with( string $name ): object
```

|  | Details |
|---|---|
| **Parameters** | `$name` — The module class to reach |
| **Return** | The shared instance |
| **Throws** | `ModuleException` — If it is not declared, or has not booted yet |

## See also

- [`Block`](block.md) — what a file in `build/blocks/` returns
- [`path`](../path/) — copied in alongside this one
- [`Module`](../module.md) — what every module inherits
- [`wp zt add blocks`](../../commands/add.md) — the command that copies it
