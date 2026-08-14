<!--
    Generated from src/Modules/PostTypes/PostType.php.
    Do not edit by hand: run `composer docs` after changing the source.
-->

# PostType

[Generated starting point](#generated-starting-point) &nbsp;·&nbsp; [You must implement](#you-must-implement) &nbsp;·&nbsp; [Methods you can use](#methods-you-can-use)

Base class for a file-based custom post type registration.

A post type file returns a subclass instance; the PostTypes module wires it (assigning the shared plugin, so `with()` reaches every module) and calls `get_args()` to build the array passed to WordPress core's own `register_post_type()`. The post type's name is not derived from this class at all — it comes from the file's own name within the post types directory (`resources/post-types/book.php` registers as `book`), matching the `slug()`-from-filename convention every other file-based module in this toolkit uses.

Only `singular_name()`/`plural_name()` are required: WordPress core itself only derives `name`/`singular_name`/`menu_name`/`all_items`/`archives` from those two, and otherwise falls back to generic, literally-worded defaults ('Add Post', 'Search Posts', ...) meant for the built-in Post/Page types — wrong for any custom post type. `get_args()` below builds the commonly-needed label set by interpolating `singular_name()`/`plural_name()` into the label keys most "register custom post type" tutorials hand-write, so a post type gets correctly-worded labels everywhere without repeating that boilerplate. Keys outside that set — `name_admin_bar`, `parent_item_colon`, `items_list`, the `item_*` status strings — keep WordPress's own defaults unless `labels()` supplies them. Override `labels()` to replace or add specific labels beyond that.

A post type name is not auto-namespaced to the plugin slug, for the same reason a taxonomy, a meta key and a block name are not: WordPress caps a post type name at 20 characters (enforced by the `wp_posts.post_type` column and `register_post_type()` itself), which leaves little to no room once a realistic plugin slug prefix is added. Community convention (core itself, WooCommerce's `product`) is to pick a short, plain, globally unique name — the same responsibility you already have when naming a database table or an option key directly.

A file at `resources/post-types/book.php` registers as `book`. `wp zt make post-type <name>` generates a starting point.

## Generated starting point

[`wp zt make post-type <name>`](../../commands/make-post-type.md) writes this file:

> [!IMPORTANT]
> **This name is not prefixed with your plugin slug, so choose it as though every plugin on the site can see it — because they can.** WordPress caps a post type name at 20 characters, which is why the slug is not added for you.
>
> Two plugins registering `book` are the same post type, and whichever registers second loses. Put your own prefix in the filename: `resources/post-types/acme-book.php`.

```php
<?php
/**
 * Example post type.
 */

declare( strict_types=1 );

// Loaded by WordPress, never requested directly.
\defined( 'ABSPATH' ) || exit;

use Acme\Plugin\Core\Modules\PostTypes\PostType;

return new class() extends PostType {

	// The post type name is this file's name -- example, with no plugin prefix
	// (WordPress caps these at 20 characters). Renaming the file orphans every
	// post already saved under the old name: they stay in the database and stop
	// appearing anywhere.

	// e.g. 'Book'. Used to build every label WordPress needs (Add New Book,
	// Edit Book, ...) via get_default_labels().
	public function singular_name(): string {
		return 'Book';
	}

	// e.g. 'Books'.
	public function plural_name(): string {
		return 'Books';
	}

	// Empty array (default) means "just use the labels built from
	// singular/plural above" -- it does NOT disable labels. Return specific
	// keys here only to override or add to that default set, e.g.
	// array( 'parent_item_colon' => __( 'Parent Book:', 'text-domain' ) ).
	public function labels(): array {
		return array();
	}

	// Which core editor features this post type has: 'title', 'editor',
	// 'thumbnail', 'excerpt', 'custom-fields', etc.
	public function supports(): array {
		return array( 'title', 'editor' );
	}

	// A bundle: WordPress derives publicly_queryable, show_ui, show_in_menu,
	// show_in_nav_menus, show_in_admin_bar and exclude_from_search from it.
	public function is_public(): bool {
		return true;
	}

	// Each argument in that bundle also has a method of its own --
	// is_shown_in_ui(), is_shown_in_menu(), is_shown_in_nav_menus(),
	// is_shown_in_admin_bar(), is_publicly_queryable() and
	// is_excluded_from_search(). They are left out here because null (their
	// default) means "derive from is_public()", which is usually right. Add
	// the ones you need for a mix is_public() alone can't express -- most
	// often a post type kept off the front end but still editable in the
	// admin: is_public() false, is_shown_in_ui() true.
	public function is_shown_in_ui(): ?bool {
		return null;
	}

	// true = archive at the post type's own default slug; a string = custom
	// archive slug; false = no archive page at all.
	public function has_archive(): bool|string {
		return true;
	}

	// false (default) = flat, like Posts. true = parent/child relationships
	// allowed, like Pages.
	public function is_hierarchical(): bool {
		return false;
	}

	// Whether this post type is exposed via the REST API and usable in the
	// block editor.
	public function is_shown_in_rest(): bool {
		return true;
	}

	// The base WordPress derives this post type's capabilities from
	// (edit_{type}, delete_{type}s, ...). 'post' reuses the built-in Post
	// capability set with no extra setup. A distinct name gets its own
	// capability set, which then needs to be granted to a role explicitly --
	// otherwise only an administrator can manage it.
	public function capability_type(): string {
		return 'post';
	}

	// Permalink structure for this post type's URLs, or false to disable pretty
	// permalinks for it entirely. Takes WordPress's own `rewrite` keys.
	public function rewrite(): array|false {
		return array( 'slug' => $this->get_post_type() );
	}

	// Admin menu icon: a dashicon class name or a custom icon URL.
	public function menu_icon(): string {
		return 'dashicons-admin-post';
	}

	// null (default): WordPress's own default menu ordering.
	public function menu_position(): ?int {
		return null;
	}
};
```

## You must implement

These 2 methods are abstract: a subclass that does not declare all of them will not load.

### `singular_name()`

The singular display name, e.g. 'Book'.

```php
abstract public function singular_name(): string
```

|  | Details |
|---|---|
| **Parameters** | — |
| **Return** | `string` |
| **Throws** | — |

Used both to derive every label WordPress core generates by default (`Add New Book`, `Edit Book`, ...) and, lowercased, as the default `labels()['singular_name']` and `menu_name` inputs.

<br>

### `plural_name()`

The plural display name, e.g. 'Books'.

```php
abstract public function plural_name(): string
```

|  | Details |
|---|---|
| **Parameters** | — |
| **Return** | `string` |
| **Throws** | — |

## Methods you can use

### `labels()`

Additional or overriding labels beyond `get_args()`'s defaults.

```php
public function labels(): array
```

|  | Details |
|---|---|
| **Parameters** | — |
| **Return** | `array` |
| **Throws** | — |

The base label set built from `singular_name()`/`plural_name()` covers every commonly-needed key already; override this only to replace a specific one (for example, a `parent_item_colon` on a hierarchical post type, or a domain-specific `search_items` string) or add a key this base does not set.

<br>

### `supports()`

The features this post type supports in the editor.

```php
public function supports(): array
```

|  | Details |
|---|---|
| **Parameters** | — |
| **Return** | Any of WordPress's post-type `supports` keys, e.g. 'title', 'editor', 'thumbnail', 'excerpt', 'custom-fields' |
| **Throws** | — |

<br>

### `is_public()`

Whether the post type is intended for public display on the front end.

```php
public function is_public(): bool
```

|  | Details |
|---|---|
| **Parameters** | — |
| **Return** | `bool` |
| **Throws** | — |

This is a bundle: WordPress derives `publicly_queryable`, `show_ui`, `show_in_menu`, `show_in_nav_menus`, `show_in_admin_bar` and `exclude_from_search` from it. Each of those has its own method below to break out of the bundle one argument at a time.

<br>

### `is_shown_in_ui()`

Whether the post type gets a management UI in the admin.

```php
public function is_shown_in_ui(): ?bool
```

|  | Details |
|---|---|
| **Parameters** | — |
| **Return** | `?bool` |
| **Throws** | — |

Null (the default) lets WordPress derive it from `is_public()`, as it does for the five methods below. Return a bool for a combination `public` alone cannot express — the common one being a post type kept off the front end but still editable in the admin: `is_public()` false and this true.

<br>

### `is_shown_in_menu()`

Where the post type appears in the admin menu.

```php
public function is_shown_in_menu(): bool|string|null
```

|  | Details |
|---|---|
| **Parameters** | — |
| **Return** | True for a top-level menu of its own, false for none at all, or the slug of an existing top-level menu (`'tools.php'`, `'edit.php?post_type=book'`) to nest it there as a submenu |
| **Throws** | — |

Requires `is_shown_in_ui()` to be on, and defaults to it.

<br>

### `is_shown_in_nav_menus()`

Whether the post type is offered when building a nav menu.

```php
public function is_shown_in_nav_menus(): ?bool
```

|  | Details |
|---|---|
| **Parameters** | — |
| **Return** | `?bool` |
| **Throws** | — |

Null (the default) derives it from `is_public()`.

<br>

### `is_shown_in_admin_bar()`

Whether the post type appears in the admin bar's "+ New" menu.

```php
public function is_shown_in_admin_bar(): ?bool
```

|  | Details |
|---|---|
| **Parameters** | — |
| **Return** | `?bool` |
| **Throws** | — |

Null (the default) derives it from `is_shown_in_menu()`.

<br>

### `is_publicly_queryable()`

Whether front-end queries may request this post type.

```php
public function is_publicly_queryable(): ?bool
```

|  | Details |
|---|---|
| **Parameters** | — |
| **Return** | `?bool` |
| **Throws** | — |

Null (the default) derives it from `is_public()`. Turning this off while leaving `is_shown_in_ui()` on is how a post type becomes admin-only without also losing its editing screens.

<br>

### `is_excluded_from_search()`

Whether the post type is kept out of front-end search results.

```php
public function is_excluded_from_search(): ?bool
```

|  | Details |
|---|---|
| **Parameters** | — |
| **Return** | `?bool` |
| **Throws** | — |

Null (the default) derives it from the *inverse* of `is_public()` — a public post type is searchable, a non-public one is not.

<br>

### `has_archive()`

Whether this post type has an archive page.

```php
public function has_archive(): bool|string
```

|  | Details |
|---|---|
| **Parameters** | — |
| **Return** | True for the default archive slug (the post type name), a string for a custom archive slug, or false to disable the archive entirely |
| **Throws** | — |

<br>

### `is_hierarchical()`

Whether child pages can be created (a hierarchical post type, like Pages).

```php
public function is_hierarchical(): bool
```

|  | Details |
|---|---|
| **Parameters** | — |
| **Return** | `bool` |
| **Throws** | — |

<br>

### `is_shown_in_rest()`

Whether this post type is exposed through the REST API and block editor.

```php
public function is_shown_in_rest(): bool
```

|  | Details |
|---|---|
| **Parameters** | — |
| **Return** | `bool` |
| **Throws** | — |

<br>

### `capability_type()`

The base capability name WordPress derives this post type's full capability set from (`edit_{type}`, `delete_{type}s`, ...).

```php
public function capability_type(): string
```

|  | Details |
|---|---|
| **Parameters** | — |
| **Return** | `string` |
| **Throws** | — |

Return `'post'` to reuse the built-in Post capabilities, which leaves nothing extra to manage. Return a distinct name to give this post type a capability set of its own.

> [!CAUTION]
> **A custom capability type has to be granted before anyone can use it.** WordPress derives the capabilities but assigns them to no role, so until a role is granted them, only an administrator can manage the post type.

<br>

### `rewrite()`

The permalink structure, or false to disable pretty permalinks for this post type entirely.

```php
public function rewrite(): array|false
```

|  | Details |
|---|---|
| **Parameters** | — |
| **Return** | `array\|false` |
| **Throws** | — |

Becomes WordPress's own `rewrite` argument, so it takes the same keys: `slug`, `with_front`, `feeds`, `pages` and `ep_mask`.

<br>

### `menu_icon()`

The dashicon or custom icon shown in the admin menu.

```php
public function menu_icon(): string
```

|  | Details |
|---|---|
| **Parameters** | — |
| **Return** | `string` |
| **Throws** | — |

<br>

### `menu_position()`

The admin menu position, or null for the default ordering.

```php
public function menu_position(): ?int
```

|  | Details |
|---|---|
| **Parameters** | — |
| **Return** | `?int` |
| **Throws** | — |

<br>

### `get_post_type()`

This post type's own name, as registered.

```php
final public function get_post_type(): string
```

|  | Details |
|---|---|
| **Parameters** | — |
| **Return** | `string` |
| **Throws** | — |

Resolved from the PostTypes module's registry, which derives it from the file's own name within the post types directory. The post type itself stores no name state.

<br>

### `get_args()`

Build the full argument array passed to `register_post_type()`.

```php
public function get_args(): array
```

|  | Details |
|---|---|
| **Parameters** | — |
| **Return** | `array` |
| **Throws** | — |

Merges `get_default_labels()` with this post type's own `labels()` overrides, then every other declared option. Override this directly only when a `register_post_type()` argument has no dedicated method above.

<br>

### `post_types()`

The PostTypes module that manages this post type.

```php
final protected function post_types(): PostTypes
```

|  | Details |
|---|---|
| **Parameters** | — |
| **Return** | `PostTypes` |
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

<br>

### `is_enabled()`

*Inherited from [`WithEnablement`](../../kernel/with-enablement.md).*

Whether this should be registered at all.

```php
public function is_enabled(): bool
```

|  | Details |
|---|---|
| **Parameters** | — |
| **Return** | `bool` |
| **Throws** | — |

Called once, after the instance is wired and before anything is registered. Return false and nothing happens: no hook is bound and no WordPress registration is made.

The default is true, so a file that says nothing registers — being on disk is the convention, and this is the exception to it.

It registers nothing either way.
