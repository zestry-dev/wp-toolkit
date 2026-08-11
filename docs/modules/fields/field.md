<!--
    Generated from src/Modules/Fields/Field.php.
    Do not edit by hand: run `composer docs` after changing the source.
-->

# Field

[A field](#a-field) &nbsp;·&nbsp; [Generated starting point](#generated-starting-point) &nbsp;·&nbsp; [Methods](#methods)

One piece of post meta, registered with a type and a schema.

A file in `fields/` returns one of these. It names what it attaches to, so it works the same for a post type you registered and for one you did not — and the same again for term, user and comment meta.

Registering meta rather than just calling `update_post_meta()` is what gives it a type, a sanitiser, a permission check and a place in the REST API. The block editor reads and writes meta over REST, so a registered field is one your editor JavaScript can bind to; an unregistered one is invisible to it.

**A field holds one value per post.** For several, return `array` from `type()` and store them in one row — the shape REST and the block editor expect. To *find* posts by one of those values, use a taxonomy: meta stored as an array cannot be searched by its individual entries.

## A field

```php
// fields/acme_rating.php
namespace Acme\Plugin\Fields;

use Acme\Plugin\Core\Modules\Fields\Field;

return new class extends Field {

    public function subtypes(): array {
        return array( 'book' );
    }

    public function type(): string {
        return 'integer';
    }

    public function sanitize( mixed $value ): mixed {
        return max( 1, min( 5, (int) $value ) );
    }
};
```

> [!IMPORTANT]
> **This name is not prefixed with your plugin slug, so choose it as though every plugin on the site can see it — because they can.** A meta key is part of your REST responses, so adding a prefix for you would change your own API.
>
> Two plugins registering `rating` are the same meta key on the same post, and whichever registers second loses. Put your own prefix in the filename: `fields/acme_rating.php`.

## Generated starting point

[`wp zestry make field <name>`](../../commands/make-field.md) writes this file:

```php
<?php
/**
 * example post meta field.
 */

declare( strict_types=1 );

// Loaded by WordPress, never requested directly.
\defined( 'ABSPATH' ) || exit;

use Acme\Plugin\Core\Modules\Fields\Field;
// use Acme\Plugin\Core\Modules\Fields\MetaType;

return new class() extends Field {

	// The meta key is this file's name -- example. Post meta keys are shared
	// across every plugin on a post, so name the file with a prefix when the
	// field attaches to a post type you do not own.
	//
	// WordPress marks meta protected by a leading underscore in the key, so
	// naming the file `_example.php` is one way to say it. This is the other,
	// and it works for a key of any spelling: protected means no Custom Fields
	// panel entry and no block able to bind to it. Who may *write* it is
	// can_edit() below, either way.
	//
	// public function is_protected(): ?bool {
	//     return true;
	// }

	// What this attaches to within its object type: post type names for post
	// meta, taxonomy names for term meta. An empty list means every subtype.
	public function subtypes(): array {
		return array( 'post' );
	}

	// Post meta by default. Term, User and Comment are the others.
	//
	// public function object_type(): MetaType {
	//     return MetaType::Term;
	// }

	// string, boolean, integer, number, array or object. An array or object
	// shown in REST needs a schema; see is_shown_in_rest().
	public function type(): string {
		return 'string';
	}

	// Runs on every write, including through REST.
	public function sanitize( mixed $value ): mixed {
		return \sanitize_text_field( (string) $value );
	}

	// A field holds one value per post. For several, return 'array' from
	// type() and store them in one row -- and if you need to FIND posts by
	// one of the values, use a taxonomy instead, which is indexed.

	// On by default, because the block editor reads and writes meta over
	// REST -- a field kept out of it cannot be edited there. Turn it off for
	// anything a reader of the post should not see.
	//
	// public function is_shown_in_rest(): bool|array {
	//     return false;
	// }

	// Checked on top of the post's own edit permission, never instead of it.
	//
	// public function can_edit( int $post_id ): bool {
	//     return \current_user_can( 'manage_options' );
	// }

	// Save the value with each revision, so it reverts with the post. The post
	// type must support 'revisions' or the field does not register at all --
	// a PostType here supports only title and editor by default.
	//
	// public function has_revisions(): bool {
	//     return true;
	// }
};
```

## Methods

### `key()`

The meta key this field is stored under.

```php
public function key(): string
```

|  | Details |
|---|---|
| **Parameters** | — |
| **Return** | `string` |
| **Throws** | — |

Your filename, verbatim: `fields/acme_rating.php` stores under `acme_rating`. Post meta keys are shared across every plugin on a post, so name the file with a prefix when the field attaches to a post type you do not own.

A leading underscore works, so `fields/_acme_secret.php` stores under `_acme_secret` — WordPress's mark for protected meta. The filename is the key, exactly as written, because the key is what stored rows are found by. `is_protected()` is the other way to say the same thing, for a key whose spelling you would rather choose freely. Override this only for a key a filename genuinely cannot hold.

<br>

### `object_type()`

What kind of object this field is stored against.

```php
public function object_type(): MetaType
```

|  | Details |
|---|---|
| **Parameters** | — |
| **Return** | `MetaType` |
| **Throws** | — |

<br>

### `subtypes()`

The subtypes this field attaches to, within its object type.

```php
public function subtypes(): array
```

|  | Details |
|---|---|
| **Parameters** | — |
| **Return** | `array` |
| **Throws** | — |

Post type names for post meta, taxonomy names for term meta, comment types for comment meta. Users have no subtypes.

An empty list attaches the field to **every** subtype — every post type, every taxonomy — which is what you want for user meta and rarely what you want for post meta.

<br>

### `type()`

The value's type.

```php
public function type(): string
```

|  | Details |
|---|---|
| **Parameters** | — |
| **Return** | `string` |
| **Throws** | — |

One of `string`, `boolean`, `integer`, `number`, `array` or `object`. An `array` or `object` shown in REST needs a schema — see `is_shown_in_rest()`.

This describes the REST schema; it does not cast anything on the PHP side. `get_post_meta()` still hands back a string for a field typed `integer`, so cast at the point you read it.

Use `array` to hold several values. A field is always one value per post here, so many values means one array in one row rather than many rows — which is also what REST wants, given a schema.

<br>

### `description()`

What this field is for, shown in the REST schema.

```php
public function description(): string
```

|  | Details |
|---|---|
| **Parameters** | — |
| **Return** | `string` |
| **Throws** | — |

<br>

### `default_value()`

The value returned when nothing is stored.

```php
public function default_value(): mixed
```

|  | Details |
|---|---|
| **Parameters** | — |
| **Return** | `mixed` |
| **Throws** | — |

Null means no default, which is not the same as an empty string: with no default, reading an unset key gives `''` for a single value.

<br>

### `is_shown_in_rest()`

Whether the field appears in the REST API.

```php
public function is_shown_in_rest(): bool|array
```

|  | Details |
|---|---|
| **Parameters** | — |
| **Return** | True, false, or an array carrying `schema` |
| **Throws** | — |

**On by default, where WordPress defaults it off.** The block editor reads and writes meta over REST, so a field kept out of it cannot be edited there at all. Turn it off for anything a reader of the post should not see: a field in REST is readable by anyone who can read the post.

Two things that make a field silently invisible rather than erroring:

- **The post type must be in REST too.** A `PostType` here is by default;
`post` and `page` are; another plugin's may not be.
- **An `array` or `object` type needs a schema**, given as an array here
with a `schema` key, or WordPress refuses to register it.

<br>

### `is_protected()`

Whether WordPress should treat this field as protected meta.

```php
public function is_protected(): ?bool
```

|  | Details |
|---|---|
| **Parameters** | — |
| **Return** | True or false to decide, null to let the key's name decide |
| **Throws** | — |

WordPress answers this by looking for a leading underscore and nothing else, which makes a property of a filename. This says it out loud instead, so `submission-payload` and `_submission_payload` can be the same decision spelled the way you prefer.

**It does not decide who may write the field.** That is `can_edit()`, and every field registers with an `auth_callback` of its own that calls it — so the underscore's most dangerous effect, where an unprotected key defaults to writable by anyone who can edit the object, never applies to a field written this way. What protection still decides:

- **The Custom Fields panel**, which lists unprotected keys only. A
field you render yourself does not want a second, raw editor for the same value sitting under it.
- **Block bindings**, which refuse a protected key as a source. Mark a
field protected and no block can bind to it.

> [!NOTE]
> **The answer is per object type, not per subtype.** WordPress hands the filter `post`, `term`, `user` or `comment` and never `page` or `product` — so a field declared on `page` alone still answers for that key across every post type. A key is one key; only the subtypes it is *registered* against are narrower.

`null`, the default, defers to WordPress — so a key already named with a leading underscore stays protected without saying so twice.

<br>

### `has_revisions()`

Whether the value is saved with each revision of the post.

```php
public function has_revisions(): bool
```

|  | Details |
|---|---|
| **Parameters** | — |
| **Return** | `bool` |
| **Throws** | — |

Off by default. Turn it on for a field that is part of the content — a subtitle, a summary — so it reverts with everything else when someone restores a revision. Leave it off for anything incidental to the post: a view count saved into every revision is noise.

**The post type must support `revisions`, or the field does not register at all.** Not "revisions quietly do nothing" — `register_post_meta()` returns false and the key is never registered, so nothing about it works. A `PostType` here supports `title` and `editor` by default, so add `revisions` to its `supports()` before turning this on.

<br>

### `validate( $value )`

Whether a value is acceptable at all.

```php
public function validate( mixed $value ): bool
```

|  | Details |
|---|---|
| **Parameters** | `$value` — The incoming value |
| **Return** | True to accept it |
| **Throws** | — |

Returning false stops the write. This applies to **every** write — `update_post_meta()`, the REST API, a meta box — because it runs on the filter WordPress offers for exactly this, not inside one accessor.

```php
public function validate( mixed $value ): bool {
    return is_numeric( $value ) && (int) $value >= 1 && (int) $value <= 5;
}
```

**The value has already been through `sanitize()` by the time this sees it.** That is WordPress's order for meta, and it is the reverse of how it treats a REST parameter: a request argument is validated and then sanitised, meta is sanitised and then offered for a veto.

So a sanitiser that coerces leaves nothing to reject: clamp 99 to 5 in `sanitize()` and this is asked about 5. Decide which job each does — coerce a value into shape, or refuse it — rather than both.

<br>

### `sanitize( $value )`

Clean a value on its way into the database.

```php
public function sanitize( mixed $value ): mixed
```

|  | Details |
|---|---|
| **Parameters** | `$value` — The incoming value |
| **Return** | The value to store |
| **Throws** | — |

Runs for every write, including through REST, and before `validate()` — WordPress calls it itself, from inside the write. The default returns the value untouched.

<br>

### `can_edit( $post_id )`

Whether the current user may write this field on a given post.

```php
public function can_edit( int $post_id ): bool
```

|  | Details |
|---|---|
| **Parameters** | `$post_id` — The post being edited |
| **Return** | `bool` |
| **Throws** | — |

Checked on top of the post's own edit permission, never instead of it.

The default asks whether they can edit that post, which is almost always the right answer — and is deliberately not what WordPress does. Core decides from the key's name: a key starting with `_` is refused to everyone, so prefixing a key to hide it from the classic custom-fields box also stops the block editor saving it, with nothing to say why.

<br>

### `fields()`

The module that discovered this field.

```php
final protected function fields(): Fields
```

|  | Details |
|---|---|
| **Parameters** | — |
| **Return** | `Fields` |
| **Throws** | — |

<br>

### `get_plugin()`

Get the plugin this class belongs to.

```php
final public function get_plugin(): Plugin
```

|  | Details |
|---|---|
| **Parameters** | — |
| **Return** | The plugin instance |
| **Throws** | — |

Use it to reach something you did not declare a property for — a module you need in one method only, or one you look up by a name computed at runtime. For anything you use throughout the class, declare a typed property instead and let it be injected.

```php
$this->get_plugin()->get( Options::class )->get( 'api_key' );
```

<br>

### `is_enabled()`

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

Most modules ask at discovery and drop the file there. `post-types` and `fields` ask at registration instead, so that a switched-off file still appears in what they list — a screen offering to switch a feature on can only offer what it can see. It registers nothing either way.
