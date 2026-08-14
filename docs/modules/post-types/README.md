<!--
    Generated from src/Modules/PostTypes/PostTypes.php.
    Do not edit by hand: run `composer docs` after changing the source.
-->

# PostTypes

Discovers `resources/post-types/`, `resources/taxonomies/` &nbsp;·&nbsp; Each file returns [`PostType`](post-type.md), [`Taxonomy`](taxonomy.md) &nbsp;·&nbsp; Dependencies [`path`](../path/)

Discovers plugin custom post types and taxonomies and registers them with WordPress.

A post types directory contains PHP files, one per post type; each file returns a `PostType` instance and registers under the file's own name (`resources/post-types/book.php` registers as `book`). A taxonomies directory works the same way with `Taxonomy` instances. Neither name is auto-namespaced to the plugin slug, the way a meta key and a block name are not either — see `PostType` for why, and prefix them yourself in the filename.

Taxonomies are always registered after every post type, regardless of file discovery order, so a taxonomy's `Taxonomy::object_types()` can safely name any post type this same plugin discovers.

`get_discovered_post_types()` and `get_discovered_taxonomies()` hand back everything the two directories declare, switched on or off, so a plugin can build a screen over its own post types without keeping a second list of them somewhere. `is_enabled()` is read at registration instead: a file that switches itself off is one you can list and WordPress never hears about.

Both roots behave the same way, which is what lets a plugin with post types but no taxonomies skip `resources/taxonomies/` entirely. A directory that is not there is not an error: your plugin simply has none of those files yet.

[Adding it](#adding-it) &nbsp;·&nbsp; [Changing the defaults](#changing-the-defaults) &nbsp;·&nbsp; [Writing a PostType](#writing-a-posttype) &nbsp;·&nbsp; [Writing a Taxonomy](#writing-a-taxonomy) &nbsp;·&nbsp; [Constants](#constants) &nbsp;·&nbsp; [Methods](#methods) &nbsp;·&nbsp; [See also](#see-also)

## Adding it

```bash
wp zt add post-types
```

> [!IMPORTANT]
> **A module is built because `bootstrap.php` lists it, and the heading says when.** `PostTypes` acts the moment it is built, so it goes under the hook it acts on — which `wp zt add` writes for you. Left at the top level it throws; left out entirely, nothing is discovered and nothing reports why, which is what [`wp zt doctor`](../../commands/doctor.md) catches.

```php
// bootstrap.php
return array(
    'init' => array(
        PostTypes::class,
    ),
);
```

## Changing the defaults

`PostTypes` takes no configuration. The entry above is all it needs — reach it with `$this->with( PostTypes::class )` from any module or discovered file, or `$plugin->get( PostTypes::class )` from your entry file.

## Writing a PostType

A file in `resources/post-types/` returns a [`PostType`](post-type.md) instance, which `wp zt make post-type <name>` generates.

## Writing a Taxonomy

A file in `resources/taxonomies/` returns a [`Taxonomy`](taxonomy.md) instance, which `wp zt make taxonomy <name>` generates.

## Constants

### `POST_TYPES_ROOT`

```php
const POST_TYPES_ROOT = 'resources/post-types';
```

Default plugin-relative directory of post type files.

### `TAXONOMIES_ROOT`

```php
const TAXONOMIES_ROOT = 'resources/taxonomies';
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

- [`PostType`](post-type.md) — what a file in `resources/post-types/` returns
- [`Taxonomy`](taxonomy.md) — what a file in `resources/taxonomies/` returns
- [`path`](../path/) — copied in alongside this one
- [`Module`](../module.md) — what every module inherits
- [`wp zt add post-types`](../../commands/add.md) — the command that copies it
