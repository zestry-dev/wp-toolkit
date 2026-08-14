<!--
    Generated from src/Modules/PostTypes/PostTypes.php.
    Do not edit by hand: run `composer docs` after changing the source.
-->

# PostTypes

Discovers `post-types/`, `taxonomies/` &nbsp;·&nbsp; Each file returns [`PostType`](post-type.md), [`Taxonomy`](taxonomy.md) &nbsp;·&nbsp; Dependencies [`path`](../path/)

Discovers plugin custom post types and taxonomies and registers them with WordPress.

A post types directory contains PHP files, one per post type; each file returns a `PostType` instance and registers under the file's own name (`post-types/book.php` registers as `book`). A taxonomies directory works the same way with `Taxonomy` instances. Neither name is auto-namespaced to the plugin slug, the way a meta key and a block name are not either — see `PostType` for why, and prefix them yourself in the filename.

Taxonomies are always registered after every post type, regardless of file discovery order, so a taxonomy's `Taxonomy::object_types()` can safely name any post type this same plugin discovers.

`get_discovered_post_types()` and `get_discovered_taxonomies()` hand back everything the two directories declare, switched on or off, so a plugin can build a screen over its own post types without keeping a second list of them somewhere. `is_enabled()` is read at registration instead: a file that switches itself off is one you can list and WordPress never hears about.

Both roots behave the same way, which is what lets a plugin with post types but no taxonomies skip the `taxonomies/` directory entirely. Name one with `post-types/` or `taxonomies/` and it must exist — asking for a directory by name and getting nothing is a typo worth hearing about. Leave one at its default and let it be absent, and your plugin simply has none of those files yet.

[Adding it](#adding-it) &nbsp;·&nbsp; [Changing the defaults](#changing-the-defaults) &nbsp;·&nbsp; [Writing a PostType](#writing-a-posttype) &nbsp;·&nbsp; [Writing a Taxonomy](#writing-a-taxonomy) &nbsp;·&nbsp; [Constants](#constants) &nbsp;·&nbsp; [Methods](#methods) &nbsp;·&nbsp; [See also](#see-also)

## Adding it

```bash
wp zt add post-types
```

> [!IMPORTANT]
> **A module is built because `bootstrap.php` lists it.** `PostTypes` binds its hooks when the plugin builds it, so it has to be listed there — which `wp zt add` writes for you. Left out, nothing is discovered and nothing reports why; [`wp zt doctor`](../../commands/doctor.md) is what catches it.

```php
// bootstrap.php
return array(
    PostTypes::class,
);
```

## Changing the defaults

`PostTypes` takes no configuration. The bare `modules` entry above is all it needs — reach it with `$plugin->get( PostTypes::class )`, or declare a property of its type and have it injected.

## Writing a PostType

A file in `post-types/` returns a [`PostType`](post-type.md) instance, which `wp zt make post-type <name>` generates.

## Writing a Taxonomy

A file in `taxonomies/` returns a [`Taxonomy`](taxonomy.md) instance, which `wp zt make taxonomy <name>` generates.

## Constants

### `POST_TYPES_ROOT`

```php
const POST_TYPES_ROOT = 'post-types';
```

Default plugin-relative directory of post type files.

### `TAXONOMIES_ROOT`

```php
const TAXONOMIES_ROOT = 'taxonomies';
```

Default plugin-relative directory of taxonomy files.

## Methods

### `get_post_type_of( $post_type )`

This post type's registered name.

```php
public function get_post_type_of( PostType $post_type ): string
```

|  | Details |
|---|---|
| **Parameters** | `$post_type` — The instance to look up |
| **Return** | `string` |
| **Throws** | `InvalidArgumentException` — When the instance was not discovered by this module<br>`DiscoveryException` — When discovery fails |

<br>

### `get_taxonomy_of( $taxonomy )`

This taxonomy's registered name.

```php
public function get_taxonomy_of( Taxonomy $taxonomy ): string
```

|  | Details |
|---|---|
| **Parameters** | `$taxonomy` — The instance to look up |
| **Return** | `string` |
| **Throws** | `InvalidArgumentException` — When the instance was not discovered by this module<br>`DiscoveryException` — When discovery fails |

<br>

### `get_discovered_post_types()`

Every post type this plugin declares, by registered name.

```php
public function get_discovered_post_types(): array
```

|  | Details |
|---|---|
| **Parameters** | — |
| **Return** | Wired instances keyed by registered name |
| **Throws** | `DiscoveryException` — When a file returns the wrong value |

Everything the directory holds, including any file whose `is_enabled()` returns false — so a screen offering to switch features on can list the ones currently switched off, which is the only case such a screen exists for. Ask an instance yourself when you need to tell them apart; only `register_all()` acts on the answer.

The directory is walked once and the instances kept, so two calls hand back the same objects and `get_post_type_of()` recognises one you were given earlier.

<br>

### `get_discovered_taxonomies()`

Every taxonomy this plugin declares, by registered name.

```php
public function get_discovered_taxonomies(): array
```

|  | Details |
|---|---|
| **Parameters** | — |
| **Return** | Wired instances keyed by registered name |
| **Throws** | `DiscoveryException` — When a named taxonomies directory does not exist, or a file returns the wrong value |

Everything the directory holds, on the same terms as `get_discovered_post_types()`: a file whose `is_enabled()` returns false is listed here and registered nowhere.

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

- [`PostType`](post-type.md) — what a file in `post-types/` returns
- [`Taxonomy`](taxonomy.md) — what a file in `taxonomies/` returns
- [`path`](../path/) — copied in alongside this one
- [`Module`](../module.md) — what every module inherits
- [`wp zt add post-types`](../../commands/add.md) — the command that copies it
