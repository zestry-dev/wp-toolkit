<!--
    Generated from src/Modules/PostTypes/PostTypes.php.
    Do not edit by hand: run `composer docs` after changing the source.
-->

# PostTypes

Discovers `post-types/`, `taxonomies/` &nbsp;·&nbsp; Each file returns [`PostType`](post-type.md), [`Taxonomy`](taxonomy.md) &nbsp;·&nbsp; Dependencies [`path`](../../services/path/)

Discovers plugin custom post types and taxonomies and registers them with WordPress.

A post types directory contains PHP files, one per post type; each file returns a `PostType` instance and registers under the file's own name (`post-types/book.php` registers as `book`). A taxonomies directory works the same way with `Taxonomy` instances. Neither name is auto-namespaced to the plugin slug, the way a meta key and a block name are not either — see `PostType` for why, and prefix them yourself in the filename.

Taxonomies are always registered after every post type, regardless of file discovery order, so a taxonomy's `Taxonomy::object_types()` can safely name any post type this same plugin discovers.

`get_discovered_post_types()` and `get_discovered_taxonomies()` hand back everything the two directories declare, switched on or off, so a plugin can build a screen over its own post types without keeping a second list of them somewhere. `is_enabled()` is read at registration instead: a file that switches itself off is one you can list and WordPress never hears about.

Both roots behave the same way, which is what lets a plugin with post types but no taxonomies skip the `taxonomies/` directory entirely. Name one with `post-types/` or `taxonomies/` and it must exist — asking for a directory by name and getting nothing is a typo worth hearing about. Leave one at its default and let it be absent, and your plugin simply has none of those files yet.

[Adding it](#adding-it) &nbsp;·&nbsp; [Changing the defaults](#changing-the-defaults) &nbsp;·&nbsp; [Writing a PostType](#writing-a-posttype) &nbsp;·&nbsp; [Writing a Taxonomy](#writing-a-taxonomy) &nbsp;·&nbsp; [Constants](#constants) &nbsp;·&nbsp; [Methods](#methods) &nbsp;·&nbsp; [See also](#see-also)

## Adding it

```bash
wp zt add module post-types
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

Almost everything a module registers — a post type, a block, a WP-CLI command — has to happen on `init`, and a plain `add_action( 'init', ... )` is a callback that never runs once `init` has passed. A module can be resolved on either side of it: `Plugin::run()` is synchronous, so an entry file that calls it at plugin load is ahead of `init`, while one that calls it from a later hook — or a `get()` during a request — is behind. This behaves the same either way, so a module never has to care which.

The callback receives the module, matching the initializer signature, so a closure declared elsewhere needs no `use` to reach it:

```php
protected function on_boot(): void {
    $this->on_wp_init( function ( self $module ): void {
        $module->register_widgets();
    } );
}
```

`$priority` is WordPress's own, for ordering against something else on `init` — another plugin's registration, or a post type a taxonomy of yours attaches to. **It applies only when `init` is still ahead**, which is the case for the documented entry file, since `run()` at plugin load is well before `init`. A module resolved *after* `init` has fired runs its callback immediately, because there is no longer a queue to be ordered in — so two callbacks registered then run in the order they were registered, whatever priority each asked for. Ordering that has to hold in both cases belongs inside one callback.

## See also

- [`PostType`](post-type.md) — what a file in `post-types/` returns
- [`Taxonomy`](taxonomy.md) — what a file in `taxonomies/` returns
- [`path`](../../services/path/) — copied in alongside this one
- [`Module`](../module.md) — what every module inherits
- [`wp zt add module post-types`](../../commands/add-module.md) — the command that copies it
