<!--
    Generated from src/Modules/PostTypes/Taxonomy.php.
    Do not edit by hand: run `composer docs` after changing the source.
-->

# Taxonomy

[Generated starting point](#generated-starting-point) &nbsp;·&nbsp; [You must implement](#you-must-implement) &nbsp;·&nbsp; [Methods you can use](#methods-you-can-use)

Base class for a file-based custom taxonomy registration.

A taxonomy file returns a subclass instance. The PostTypes module wires it and calls `get_args()` to build the array it passes to WordPress core's `register_taxonomy()`.

Only `singular_name()`, `plural_name()` and `object_types()` are required — unlike a `PostType`, a taxonomy also has to say what it attaches to. `get_args()` builds a full label set from the first two, rather than leaving core's own defaults to fall back on generic 'Tags'/'Categories' wording meant for the built-in taxonomies.

> [!NOTE]
> **The taxonomy's name comes from its filename, not from this class.** `resources/taxonomies/genre.php` registers as `genre`. Like post type names, it is *not* namespaced to the plugin slug: WordPress caps a taxonomy name at 32 characters, and the convention is a short, plain, globally unique name.

Taxonomies are registered after every post type has been (see `PostTypes`), so `object_types()` can safely name any post type this same plugin discovers, in either directory, regardless of file ordering.

A file at `resources/taxonomies/genre.php` attaches to a `book` post type discovered from `resources/post-types/book.php`. `wp zt make taxonomy <name>` generates a starting point.

## Generated starting point

[`wp zt make taxonomy <name>`](../../commands/make-taxonomy.md) writes this file:

> [!IMPORTANT]
> **This name is not prefixed with your plugin slug, so choose it as though every plugin on the site can see it — because they can.** WordPress caps a taxonomy name at 32 characters, which is why the slug is not added for you.
>
> Two plugins registering `genre` are the same taxonomy, and whichever registers second loses. Put your own prefix in the filename: `resources/taxonomies/acme-genre.php`.

```php
<?php
/**
 * Example taxonomy.
 */

declare( strict_types=1 );

// Loaded by WordPress, never requested directly.
\defined( 'ABSPATH' ) || exit;

use Acme\Plugin\Core\Modules\PostTypes\Taxonomy;

return new class() extends Taxonomy {

	// The taxonomy name is this file's name -- example, with no plugin prefix
	// (WordPress caps these at 32 characters). Renaming the file orphans every
	// term already assigned under the old name.

	// e.g. 'Genre'. Used to build every label WordPress needs.
	public function singular_name(): string {
		return 'Genre';
	}

	// e.g. 'Genres'.
	public function plural_name(): string {
		return 'Genres';
	}

	// Post type name(s) this taxonomy attaches to -- a name discovered from
	// this same plugin's resources/post-types/ directory, or any other already
	// registered post type (including WordPress's own built-in 'post').
	public function object_types(): array {
		return array( 'book' );
	}

	// Empty array (default) means "just use the labels built from
	// singular/plural above" -- it does NOT disable labels. If
	// is_hierarchical() is true, add 'parent_item'/'parent_item_colon' here,
	// since those are deliberately left out of the generated defaults
	// (matching WordPress core's own behavior for a non-hierarchical taxonomy).
	public function labels(): array {
		return array();
	}

	// false (default) = flat, comma-separated tag input, like Tags. true =
	// parent/child terms with a checkbox-tree UI, like Categories.
	public function is_hierarchical(): bool {
		return false;
	}

	// Whether this taxonomy is exposed via the REST API and usable in the
	// block editor.
	public function is_shown_in_rest(): bool {
		return true;
	}

	// A bundle: WordPress derives publicly_queryable, show_ui, show_in_menu,
	// show_in_nav_menus, show_tagcloud and show_in_quick_edit from it.
	public function is_public(): bool {
		return true;
	}

	// Each argument in that bundle also has a method of its own --
	// is_shown_in_ui(), is_shown_in_menu(), is_shown_in_nav_menus(),
	// is_publicly_queryable(), is_shown_in_tagcloud() and
	// is_shown_in_quick_edit(). They are left out here because null (their
	// default) means "derive from is_public()", which is usually right. Add
	// the ones you need for a mix is_public() alone can't express -- most
	// often a taxonomy kept off the front end but still editable in the
	// admin: is_public() false, is_shown_in_ui() true.
	public function is_shown_in_ui(): ?bool {
		return null;
	}

	// Permalink structure for this taxonomy's term archive URLs (e.g.
	// /genre/sci-fi/), or false to disable pretty permalinks for it entirely.
	// Takes WordPress's own `rewrite` keys.
	public function rewrite(): array|false {
		return array( 'slug' => $this->get_taxonomy() );
	}
};
```

## You must implement

These 3 methods are abstract: a subclass that does not declare all of them will not load.

### `singular_name()`

The singular display name, e.g. 'Genre'.

```php
abstract public function singular_name(): string
```

|  | Details |
|---|---|
| **Parameters** | — |
| **Return** | `string` |
| **Throws** | — |

<br>

### `plural_name()`

The plural display name, e.g. 'Genres'.

```php
abstract public function plural_name(): string
```

|  | Details |
|---|---|
| **Parameters** | — |
| **Return** | `string` |
| **Throws** | — |

<br>

### `object_types()`

The post type name(s) this taxonomy attaches to.

```php
abstract public function object_types(): array
```

|  | Details |
|---|---|
| **Parameters** | — |
| **Return** | `array` |
| **Throws** | — |

Each entry may be a post type discovered from this same plugin's `resources/post-types/` directory (see `PostType`) or the name of any other already-registered post type, including WordPress's own built-in `post`.

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

See `PostType::labels()` for the same reasoning: the base label set already covers every commonly-needed key, so override this only to replace a specific one (for example, `parent_item`/`parent_item_colon` on a hierarchical taxonomy) or add a key this base does not set.

<br>

### `is_hierarchical()`

Whether this taxonomy behaves like categories (true) or tags (false).

```php
public function is_hierarchical(): bool
```

|  | Details |
|---|---|
| **Parameters** | — |
| **Return** | `bool` |
| **Throws** | — |

A hierarchical taxonomy supports parent/child terms and is edited with a checkbox tree in the admin, matching WordPress's own Category taxonomy. A non-hierarchical taxonomy is flat and edited with a comma-separated tag input, matching WordPress's own Tag taxonomy.

<br>

### `is_shown_in_rest()`

Whether this taxonomy is exposed through the REST API and block editor.

```php
public function is_shown_in_rest(): bool
```

|  | Details |
|---|---|
| **Parameters** | — |
| **Return** | `bool` |
| **Throws** | — |

<br>

### `is_public()`

Whether this taxonomy is intended for public display on the front end.

```php
public function is_public(): bool
```

|  | Details |
|---|---|
| **Parameters** | — |
| **Return** | `bool` |
| **Throws** | — |

This is a bundle: WordPress derives `publicly_queryable`, `show_ui`, `show_in_menu`, `show_in_nav_menus`, `show_tagcloud` and `show_in_quick_edit` from it. Each of those has its own method below to break out of the bundle one argument at a time.

<br>

### `is_shown_in_ui()`

Whether the taxonomy gets a management UI in the admin.

```php
public function is_shown_in_ui(): ?bool
```

|  | Details |
|---|---|
| **Parameters** | — |
| **Return** | `?bool` |
| **Throws** | — |

Null (the default) lets WordPress derive it from `is_public()`, as it does for the four methods below. Return a bool for a combination `public` alone cannot express — the common one being a taxonomy kept off the front end but still editable in the admin: `is_public()` false and this true.

<br>

### `is_shown_in_menu()`

Whether the taxonomy gets a submenu entry under its post type's menu.

```php
public function is_shown_in_menu(): ?bool
```

|  | Details |
|---|---|
| **Parameters** | — |
| **Return** | `?bool` |
| **Throws** | — |

Requires `is_shown_in_ui()` to be on, and defaults to it.

<br>

### `is_shown_in_nav_menus()`

Whether the taxonomy's terms are offered when building a nav menu.

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

### `is_publicly_queryable()`

Whether front-end queries may request this taxonomy's term archives.

```php
public function is_publicly_queryable(): ?bool
```

|  | Details |
|---|---|
| **Parameters** | — |
| **Return** | `?bool` |
| **Throws** | — |

Null (the default) derives it from `is_public()`.

<br>

### `is_shown_in_tagcloud()`

Whether the taxonomy is offered to the Tag Cloud widget.

```php
public function is_shown_in_tagcloud(): ?bool
```

|  | Details |
|---|---|
| **Parameters** | — |
| **Return** | `?bool` |
| **Throws** | — |

Null (the default) derives it from `is_shown_in_ui()`.

<br>

### `is_shown_in_quick_edit()`

Whether the taxonomy is editable from the post list's Quick Edit.

```php
public function is_shown_in_quick_edit(): ?bool
```

|  | Details |
|---|---|
| **Parameters** | — |
| **Return** | `?bool` |
| **Throws** | — |

Null (the default) derives it from `is_shown_in_ui()`.

<br>

### `rewrite()`

The permalink structure, or false to disable pretty permalinks for this taxonomy entirely.

```php
public function rewrite(): array|false
```

|  | Details |
|---|---|
| **Parameters** | — |
| **Return** | `array\|false` |
| **Throws** | — |

Becomes WordPress's own `rewrite` argument, so it takes the same keys: `slug`, `with_front`, `hierarchical` and `ep_mask`.

<br>

### `get_taxonomy()`

This taxonomy's own name, as registered.

```php
final public function get_taxonomy(): string
```

|  | Details |
|---|---|
| **Parameters** | — |
| **Return** | `string` |
| **Throws** | — |

Resolved from the PostTypes module's registry, which derives it from the file's own name within the taxonomies directory. The taxonomy itself stores no name state.

<br>

### `get_args()`

Build the full argument array passed to `register_taxonomy()`.

```php
public function get_args(): array
```

|  | Details |
|---|---|
| **Parameters** | — |
| **Return** | `array` |
| **Throws** | — |

Merges `get_default_labels()` with this taxonomy's own `labels()` overrides, then every other declared option. Override this directly only when a `register_taxonomy()` argument has no dedicated method above.

<br>

### `post_types()`

The PostTypes module that manages this taxonomy.

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

A module listed under a heading also throws when asked for before that hook has fired, since building it early would bind it on the wrong side of whatever it was declared to follow.

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
