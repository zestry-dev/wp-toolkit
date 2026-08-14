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

A block declares its PHP with `"supports": { "{plugin-slug}-php": "file:./block.php" }`, and that file returns a `Block` instance — loaded the first time the block renders, wired, and called, so its PHP has the plugin's own modules injected. A file returning anything else raises a DiscoveryException.

A block declaring WordPress's own `render` field instead is left alone entirely, and a block declaring neither is static. Both are still registered.

A block is dynamic when it declares PHP and static when it does not: `wp zt make block --dynamic` writes the `block.php`, and asks when you leave the flag out. `Block` covers what that file returns.

Registration reads `blocks-manifest.php` when one is present (see `wp-scripts build --blocks-manifest`), which spares WordPress a `block.json` read and decode per block, and walks the blocks directory when there is not.

[Adding it](#adding-it) &nbsp;·&nbsp; [Changing the defaults](#changing-the-defaults) &nbsp;·&nbsp; [Writing a Block](#writing-a-block) &nbsp;·&nbsp; [Constants](#constants) &nbsp;·&nbsp; [Methods](#methods) &nbsp;·&nbsp; [See also](#see-also)

## Adding it

```bash
wp zt add blocks
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

Group them in the inserter

```php
// bootstrap.php
return array(
    Blocks::class => array(
        'boots_on'    => 'init',
        'configure' => static function ( Blocks $blocks ): void {
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

`configure` runs on the hook, right before the module registers anything. That is what makes the `__()` calls safe: an initializer running at plugin load is before `init`, and touching a text domain there reports `_load_textdomain_just_in_time` on every request.

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
$blocks->on_wp_init(
    static function ( Blocks $blocks ): void {
        $blocks->add_categories(
            array(
                'reports' => __( 'Reports', 'acme-plugin' ),
                'charts'  => array(
                    'title' => __( 'Charts', 'acme-plugin' ),
                    'icon'  => 'chart-bar',
                ),
            )
        );
    }
);

// src/blocks/sales/block.json
{ "name": "acme-plugin/sales", "category": "reports" }
```

The category and the block that claims it live in two files, and only the block.json half is checked by anything — a block naming a category that was never declared is filed under Uncategorized rather than erroring, so the two have to be kept in step by hand.

A slug is registered exactly as given and is not namespaced to the plugin slug the way a hook or an option name is: it has to match what a hand-written `block.json` says verbatim, and namespacing would register `{plugin-slug}-reports` while every block still asked for `reports`. Choose slugs distinctive enough not to collide — reusing one of WordPress's own (`text`, `media`, `design`, `widgets`, `theme`, `embed`) adds a second entry rather than renaming the first.

**Call it from the entry's `configure`, as the example does.** A title is user-visible, so it usually wants translating, and an initializer runs while the plugin file loads — early enough that a `__()` there loads the text domain before WordPress is ready and reports `_load_textdomain_just_in_time` on every request. Inside `on_wp_init()` ordinary `__()` is correct, which is why a title is a plain string and nothing here is lazy.

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

Almost everything a module registers — a post type, a block, a WP-CLI command — has to happen on `init`, and a plain `add_action( 'init', ... )` is a callback that never runs once `init` has passed. A module can be built on either side of it: `Plugin::run()` is synchronous, so an entry file that calls it at plugin load is ahead of `init`, while one that calls it from a later hook is behind. This behaves the same either way, so a module never has to care which.

The callback receives the module, so a closure declared elsewhere needs no `use` to reach it:

```php
public function on_boot(): void {
    $this->on_wp_init( function ( self $module ): void {
        $module->register_widgets();
    } );
}
```

`$priority` is WordPress's own, for ordering against something else on `init` — another plugin's registration, or a post type a taxonomy of yours attaches to. **It applies only while `init` is still ahead**: a module built after `init` has fired runs its callback immediately, in registration order, whatever priority it asked for. Ordering that has to hold either way belongs inside one callback.

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

For the plugin's own answers — its slug, its entry file, the headers it declares. To reach another module, `with()` is shorter and says what it is doing.

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

The one way anything in a plugin reaches anything else. Returns the same instance every time, so two callers asking for `Options` share its state:

```php
$this->with( Options::class )->get( 'api_key' );
```

**The module has to be listed in `bootstrap.php`.** Asking for one that is not throws, naming the class and the file to add it to — nothing is built because something asked for it, so that file stays the whole inventory of what the plugin is made of.

A module that names a `boots_on` also throws when asked for before that hook has fired, since building it early would bind it on the wrong side of whatever it was declared to follow.

## See also

- [`Block`](block.md) — what a file in `build/blocks/` returns
- [`path`](../path/) — copied in alongside this one
- [`Module`](../module.md) — what every module inherits
- [`wp zt add blocks`](../../commands/add.md) — the command that copies it
