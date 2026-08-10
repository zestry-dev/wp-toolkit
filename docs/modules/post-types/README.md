<!--
    Generated from src/Modules/PostTypes/PostTypes.php.
    Do not edit by hand: run `composer docs` after changing the source.
-->

# PostTypes

Discovers `post-types/`, `taxonomies/` &nbsp;·&nbsp; Each file returns [`PostType`](post-type.md), [`Taxonomy`](taxonomy.md) &nbsp;·&nbsp; Dependencies [`path`](../../services/path/)

Discovers plugin custom post types and taxonomies and registers them with WordPress.

A post types directory contains PHP files, one per post type; each file returns a `PostType` instance and registers under the file's own name (`post-types/book.php` registers as `book`). A taxonomies directory works the same way with `Taxonomy` instances. Neither name is auto-namespaced to the plugin slug, the way a meta key and a block name are not either — see `PostType` for why, and prefix them yourself in the filename.

Taxonomies are always registered after every post type, regardless of file discovery order, so a taxonomy's `Taxonomy::object_types()` can safely name any post type this same plugin discovers.

Both roots behave the same way, which is what lets a plugin with post types but no taxonomies skip the `taxonomies/` directory entirely. Name one with `set_post_types_root()` or `set_taxonomies_root()` and it must exist — asking for a directory by name and getting nothing is a typo worth hearing about. Leave one at its default and let it be absent, and your plugin simply has none of those files yet.

[Adding it](#adding-it) &nbsp;·&nbsp; [Changing the defaults](#changing-the-defaults) &nbsp;·&nbsp; [Writing a PostType](#writing-a-posttype) &nbsp;·&nbsp; [Writing a Taxonomy](#writing-a-taxonomy) &nbsp;·&nbsp; [Constants](#constants) &nbsp;·&nbsp; [You must implement](#you-must-implement) &nbsp;·&nbsp; [Methods you can use](#methods-you-can-use) &nbsp;·&nbsp; [See also](#see-also)

## Adding it

```bash
wp zestry add module post-types
```

> [!IMPORTANT]
> **A module is built because `bootstrap.php` lists it.** `PostTypes` binds its hooks when the plugin builds it, so it has to be listed there — which `wp zestry add` writes for you. Left out, nothing is discovered and nothing reports why; [`wp zestry doctor`](../../commands/doctor.md) is what catches it.

```php
// bootstrap.php
return array(
    PostTypes::class,
);
```

## Changing the defaults

Register an initializer only to point the module at non-default directories.

```php
// bootstrap.php
return array(
    PostTypes::class => static function ( PostTypes $post_types ): void {
        $post_types->set_post_types_root( 'cpt/post-types' );
        $post_types->set_taxonomies_root( 'cpt/taxonomies' );
    },
);
```

## Writing a PostType

A file in `post-types/` returns a [`PostType`](post-type.md) instance, which `wp zestry make post-type <name>` generates.

## Writing a Taxonomy

A file in `taxonomies/` returns a [`Taxonomy`](taxonomy.md) instance, which `wp zestry make taxonomy <name>` generates.

## Constants

### `DEFAULT_POST_TYPES_ROOT`

```php
const DEFAULT_POST_TYPES_ROOT = 'post-types';
```

Default plugin-relative directory of post type files.

### `DEFAULT_TAXONOMIES_ROOT`

```php
const DEFAULT_TAXONOMIES_ROOT = 'taxonomies';
```

Default plugin-relative directory of taxonomy files.

## You must implement

This one method is abstract: a subclass that does not declare it will not load.

### `on_boot()`

What this module does on its own.

```php
abstract protected function on_boot(): void
```

Runs once, when the plugin builds the module. Abstract rather than optional: a module with nothing to do here is a `Service`.

**Bind hooks here; do the work in them.** An entry file that calls `run()` as it loads — which is the documented shape, and what `ActivationHandler` requires — reaches this before WordPress has required `pluggable.php`, so there is no current user yet: `current_user_can()`, `wp_mail()` and the nonce functions are not defined and calling one is a fatal. It is also before `init`, so `__()` here asks for a text domain nothing has loaded. `$wpdb` *is* up, so a query works — but it runs on every request, including the ones that never needed it.

`run_at_init()` is the way out of all three, and where anything a module registers belongs.

## Methods you can use

### `set_post_types_root( $post_types_root )`

Set the plugin-relative directory that contains post type files.

```php
public function set_post_types_root( string $post_types_root ): void
```

|  | Details |
|---|---|
| **Parameters** | `$post_types_root` — Plugin-relative directory of post type files |
| **Return** | — |
| **Throws** | — |

Call this from the module initializer before the plugin boots the module to override the default `post-types` directory.

<br>

### `set_taxonomies_root( $taxonomies_root )`

Set the plugin-relative directory that contains taxonomy files.

```php
public function set_taxonomies_root( string $taxonomies_root ): void
```

|  | Details |
|---|---|
| **Parameters** | `$taxonomies_root` — Plugin-relative directory of taxonomy files |
| **Return** | — |
| **Throws** | — |

Call this from the module initializer before the plugin boots the module to override the default `taxonomies` directory.

<br>

### `get_post_type_of( $post_type )`

This post type's registered name.

```php
public function get_post_type_of( PostType $post_type ): string
```

|  | Details |
|---|---|
| **Parameters** | `$post_type` — The instance to look up |
| **Return** | `string` |
| **Throws** | `InvalidArgumentException` — When the instance was not discovered by this module |

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
| **Throws** | `InvalidArgumentException` — When the instance was not discovered by this module |

<br>

### `run_at_init( $callback )`

Run a callback on `init`, or immediately if `init` has already fired.

```php
final public function run_at_init( callable $callback ): void
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
    $this->run_at_init( function ( self $module ): void {
        $module->register_widgets();
    } );
}
```

## See also

- [`PostType`](post-type.md) — what a file in `post-types/` returns
- [`Taxonomy`](taxonomy.md) — what a file in `taxonomies/` returns
- [`path`](../../services/path/) — copied in alongside this one
- [`Module`](../module.md) — what every module inherits
- [`wp zestry add module post-types`](../../commands/add-module.md) — the command that copies it
