<!--
    Generated from src/Modules/MetaBoxes/MetaBoxes.php.
    Do not edit by hand: run `composer docs` after changing the source.
-->

# MetaBoxes

Discovers `resources/meta-boxes/` &nbsp;·&nbsp; Each file returns [`MetaBox`](meta-box.md) &nbsp;·&nbsp; Dependencies [`path`](../path/), [`fields`](../fields/)

Puts panels on the post and comment edit screens, and owns the part that is easy to get wrong.

A file in `resources/meta-boxes/` returns a `MetaBox`. Its filename is the box's identifier, prefixed with your plugin slug: `resources/meta-boxes/details.php` becomes `{plugin-slug}-details`.

## What this exists for

The markup is the easy half, and this module does not touch it. The save is the half worth owning, because `save_post` fires far more often than a user pressing Update, and every guard you would write by hand is a bug when omitted:

- **An autosave carries none of your fields.** Reading them anyway stores
empty values over what was there.
- **A revision is saved as its own post.** Writing meta then attaches it to
the revision rather than the post.
- **Without a nonce, any page can submit that form on a user's behalf.**
- **Without a capability check, anyone who can reach the screen can write.**

All four are checked before your `save()` runs, and the nonce field is printed for you before your `render()` does.

## The two screens that have boxes

A box declares an `MetaBoxType` — `Post` or `Comment` — and those are the only two WordPress offers. Terms and users take custom fields through action hooks that emit table rows rather than panels, so they are not a meta-box concern at all; register their meta with `Fields` and render it on the term or profile form yourself.

The comment screen has neither autosaves nor revisions, so a comment box is guarded by its nonce and capability alone.

## The block editor

The block editor still shows classic boxes, and a post type that excludes `editor` support has nothing else. But for a type using the block editor, the modern equivalent is a sidebar panel written in JavaScript against meta you registered with `Fields` — a box is not the only answer, just the one that needs no build step.

[Adding it](#adding-it) &nbsp;·&nbsp; [A box](#a-box) &nbsp;·&nbsp; [Changing the defaults](#changing-the-defaults) &nbsp;·&nbsp; [Writing a MetaBox](#writing-a-metabox) &nbsp;·&nbsp; [Related classes](#related-classes) &nbsp;·&nbsp; [Constants](#constants) &nbsp;·&nbsp; [Methods](#methods) &nbsp;·&nbsp; [See also](#see-also)

## Adding it

```bash
wp zt add meta-boxes
```

> [!IMPORTANT]
> **A module is built because `bootstrap.php` lists it, and the heading says when.** `MetaBoxes` acts the moment it is built, so it goes under the hook it acts on — which `wp zt add` writes for you. Left at the top level it throws; left out entirely, nothing is discovered and nothing reports why, which is what [`wp zt doctor`](../../commands/doctor.md) catches.

```php
// bootstrap.php
return array(
    'acme_plugin_loaded' => array(
        MetaBoxes::class,
    ),
);
```

`acme_plugin_loaded` is your plugin's own action, fired at the end of `run()` once every module is built — `{slug}_loaded`, so a plugin slugged `acme-crm` spells it `acme_crm_loaded`. It is the earliest heading that still has the whole plugin behind it.

## A box

```php
// resources/meta-boxes/details.php
return new class extends MetaBox {

    public function title(): string {
        return __( 'Book details', 'acme-plugin' );
    }

    public function screens(): array {
        return array( 'book' );
    }

    public function render( object $post ): void {
        // your markup
    }

    public function save( object $post ): void {
        // reached only on a real save, by a user allowed to make it
    }
};
```

## Changing the defaults

`MetaBoxes` takes no configuration. The entry above is all it needs — reach it with `$this->with( MetaBoxes::class )` from any module or discovered file, or `$plugin->get( MetaBoxes::class )` from your entry file.

## Writing a MetaBox

A file in `resources/meta-boxes/` returns a [`MetaBox`](meta-box.md) instance, which `wp zt make meta-box <name>` generates.

## Related classes

Shipped with this module, and written against directly:

- [`Context`](context.md) — enum, where on the edit screen a box appears
- [`MetaBoxType`](meta-box-type.md) — enum, what kind of screen a box appears on
- [`Priority`](priority.md) — enum, where a box sits among the others sharing its context

## Constants

### `BOXES_ROOT`

```php
const BOXES_ROOT = 'resources/meta-boxes';
```

Where boxes are discovered, relative to the plugin root.

## Methods

### `get_discovered_boxes()`

Every discovered box, by screen type and then by identifier.

```php
public function get_discovered_boxes(): array
```

|  | Details |
|---|---|
| **Parameters** | — |
| **Return** | Screen type => identifier => instance |
| **Throws** | `DiscoveryException` — When a file returns the wrong value |

<br>

### `get_boxes_of( $type )`

Every box belonging to one kind of screen, by identifier.

```php
public function get_boxes_of( MetaBoxType $type ): array
```

|  | Details |
|---|---|
| **Parameters** | `$type` — The screen type |
| **Return** | `array` |
| **Throws** | `DiscoveryException` — When discovery fails |

<br>

### `get_box_id( $name )`

The identifier a box file registers under.

```php
public function get_box_id( string $name ): string
```

|  | Details |
|---|---|
| **Parameters** | `$name` — The box's local name — its filename without `.php` |
| **Return** | `string` |
| **Throws** | — |

Prefixed with the plugin slug, since a box's id becomes an element id on a screen every plugin can add panels to.

<br>

### `get_id_of( $box )`

This box's identifier, from the file it was discovered in.

```php
public function get_id_of( MetaBox $box ): string
```

|  | Details |
|---|---|
| **Parameters** | `$box` — The instance to look up |
| **Return** | `string` |
| **Throws** | `InvalidArgumentException` — When the instance was not discovered by this module |

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

A module listed under a heading also throws when asked for before that hook has fired, since building it early would bind it on the wrong side of whatever it was declared to follow.

## See also

- [`MetaBox`](meta-box.md) — what a file in `resources/meta-boxes/` returns
- [`path`](../path/) — copied in alongside this one
- [`fields`](../fields/) — copied in alongside this one
- [`Module`](../module.md) — what every module inherits
- [`wp zt add meta-boxes`](../../commands/add.md) — the command that copies it
