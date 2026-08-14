<!--
    Generated from src/Modules/MetaBoxes/MetaBox.php.
    Do not edit by hand: run `composer docs` after changing the source.
-->

# MetaBox

[A box](#a-box) &nbsp;·&nbsp; [Generated starting point](#generated-starting-point) &nbsp;·&nbsp; [You must implement](#you-must-implement) &nbsp;·&nbsp; [Methods you can use](#methods-you-can-use)

One panel on the post edit screen.

A file in `meta-boxes/` returns one of these. You write the markup and decide what to store; everything between the two is handled — the nonce, and the guards that decide whether a `save_post` is even a save worth acting on.

## A box

`render()` writes the form. A nonce field is already printed, so the only escaping to think about is your own.

```php
namespace Acme\Plugin\MetaBoxes;

use Acme\Plugin\Core\Modules\Fields\Fields;
use Acme\Plugin\Core\Modules\MetaBoxes\MetaBox;
use WP_Post;

return new class extends MetaBox {

    public function title(): string {
        return __( 'Book details', 'acme-plugin' );
    }

    public function screens(): array {
        return array( 'book' );
    }

    public function render( object $post ): void {
        $fields = $this->get_plugin()->get( Fields::class );

        printf(
            '<label for="acme_rating">%s</label>
             <input type="number" id="acme_rating" name="acme_rating" value="%s" min="1" max="5">',
            esc_html__( 'Rating', 'acme-plugin' ),
            esc_attr( (string) $fields->get( $post->ID, 'acme_rating', '' ) )
        );
    }

    public function fields(): array {
        return array( 'acme_rating' );
    }

    public function save( object $post ): void {
        // The field above is already stored. Nothing else to do here.
    }
};
```

## Generated starting point

[`wp zt make meta-box <name>`](../../commands/make-meta-box.md) writes this file:

```php
<?php
/**
 * example meta box.
 */

declare( strict_types=1 );

// Loaded by WordPress, never requested directly.
\defined( 'ABSPATH' ) || exit;

use Acme\Plugin\Core\Modules\MetaBoxes\MetaBox;
// use Acme\Plugin\Core\Modules\MetaBoxes\Context;
// use Acme\Plugin\Core\Modules\MetaBoxes\MetaBoxType;
// use Acme\Plugin\Core\Modules\MetaBoxes\Priority;

return new class() extends MetaBox {

	// The box id is this file's name -- {plugin-slug}-example. WordPress
	// stores each user's collapsed and hidden preferences against that id, so
	// renaming the file quietly resets them for everyone.

	// Declare a public typed property to have a service injected. A module is
	// asked for instead: `$this->get_plugin()->get( Fields::class )`.

	public function title(): string {
		return 'Example';
	}

	// The post types this panel appears on. Defaults to the built-in 'post',
	// and a comment box needs none -- there is only one comment screen.
	public function screens(): array {
		return array( 'post' );
	}

	// Post or Comment -- the only two screens WordPress renders boxes on.
	//
	// public function object_type(): MetaBoxType {
	//     return MetaBoxType::Comment;
	// }

	// Your markup. Everything you print is yours to escape -- esc_attr() for a
	// value in an attribute, esc_html() for one in text. The nonce field is
	// already printed before this runs.
	public function render( object $post ): void {
		\printf(
			'<input type="text" name="example" value="%s" class="widefat">',
			\esc_attr( (string) \get_post_meta( $post->ID, 'example', true ) )
		);
	}

	// Meta keys this form submits, named after your fields/ files. Each one
	// present in the request is read, unslashed and written through the Fields
	// module, so its validate() and sanitize() apply.
	//
	// public function fields(): array {
	//     return array( 'example' );
	// }

	// Reached only on a real save: past autosave, past revisions, with a valid
	// nonce and a user allowed to edit this post. Runs after the keys above are
	// stored, so this is for anything that list cannot express.
	public function save( object $post ): void {
	}

	// Which column, and where in it. Both are closed sets: a box registered
	// under anything else is registered and then never drawn.
	//
	// public function context(): Context   { return Context::Side; }
	// public function priority(): Priority { return Priority::High; }
};
```

## You must implement

These 3 methods are abstract: a subclass that does not declare all of them will not load.

### `title()`

The heading shown on the panel.

```php
abstract public function title(): string
```

|  | Details |
|---|---|
| **Parameters** | — |
| **Return** | A short, translated title |
| **Throws** | — |

<br>

### `render( $edited )`

Write the panel's markup.

```php
abstract public function render( object $edited ): void
```

|  | Details |
|---|---|
| **Parameters** | `$edited` — The object being edited |
| **Return** | — |
| **Throws** | — |

Everything you print is your own to escape — `esc_attr()` around a value in an attribute, `esc_html()` around one in text. The nonce field is printed for you before this runs.

<br>

### `save( $edited )`

Store what the form submitted.

```php
abstract public function save( object $edited ): void
```

|  | Details |
|---|---|
| **Parameters** | `$edited` — The object being saved |
| **Return** | — |
| **Throws** | — |

**Reached only when this is a real save by a permitted user.** For a post, `save_post` fires for autosaves and for revisions as well, and an autosave carries none of your fields — so a handler that does not check would read them as empty and wipe what was stored. A comment has neither, and arrives on `edit_comment`. Whichever applies, plus the nonce and `can_edit()`, is checked before this runs.

`$edited` is the `WP_Post` or `WP_Comment` being saved, matching `object_type()`.

Runs after every key named by `fields()` has been stored, so this is for whatever that list cannot express. Leave it empty when the list covers everything.

What arrives in `$_POST` is raw and unslashed by nothing. Writing through `Fields::set()` applies the field's `validate()`; WordPress applies its `sanitize()` on any write, including a bare `update_post_meta()`.

## Methods you can use

### `screens()`

The screens this box appears on.

```php
public function screens(): array
```

|  | Details |
|---|---|
| **Parameters** | — |
| **Return** | `array` |
| **Throws** | — |

Post type names for a post box — override this for a custom type, since the default is the built-in `post`.

A comment box needs nothing here. WordPress renders one comment edit screen and offers no way to target a single comment type, so `comment` is both the default and the only value that draws anything.

<br>

### `object_type()`

Which kind of screen this box belongs to.

```php
public function object_type(): MetaBoxType
```

|  | Details |
|---|---|
| **Parameters** | — |
| **Return** | `MetaBoxType` |
| **Throws** | — |

<br>

### `fields()`

The meta keys this box's form submits.

```php
public function fields(): array
```

|  | Details |
|---|---|
| **Parameters** | — |
| **Return** | Meta keys declared by your `fields/` files |
| **Throws** | — |

Name them and the module does the reading: for each key present in the request it unslashes the value and writes it through `Fields::set()`, so the field's `validate()` and `sanitize()` both apply. A key the form did not submit is left alone rather than written empty.

```php
public function fields(): array {
    return array( 'acme_rating', 'acme_blurb' );
}
```

This covers a form whose inputs are named after the fields they edit. `save()` runs afterwards for anything else — a value assembled from several inputs, a taxonomy term, something that is not meta at all.

<br>

### `context()`

Which column of the edit screen the panel sits in.

```php
public function context(): Context
```

|  | Details |
|---|---|
| **Parameters** | — |
| **Return** | `Context` |
| **Throws** | — |

<br>

### `priority()`

Where the panel sits among the others in its column.

```php
public function priority(): Priority
```

|  | Details |
|---|---|
| **Parameters** | — |
| **Return** | `Priority` |
| **Throws** | — |

<br>

### `can_edit( $object_id )`

Whether the current user may save this box on a given post.

```php
public function can_edit( int $object_id ): bool
```

|  | Details |
|---|---|
| **Parameters** | `$object_id` — The post or comment being saved |
| **Return** | `bool` |
| **Throws** | — |

Checked before `save()`, on top of the nonce. The default asks whether they can edit the object, which is what the screen itself required to show them the box.

<br>

### `get_id()`

The identifier this box is registered under.

```php
final public function get_id(): string
```

|  | Details |
|---|---|
| **Parameters** | — |
| **Return** | `string` |
| **Throws** | — |

Your filename with the plugin slug prefixed, since a box's id is an element id on a screen every plugin can add to. `meta-boxes/details.php` gives `{plugin-slug}-details`.

<br>

### `meta_boxes()`

The module that discovered this box.

```php
final protected function meta_boxes(): MetaBoxes
```

|  | Details |
|---|---|
| **Parameters** | — |
| **Return** | `MetaBoxes` |
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

How you reach a module, always: building one boots it, so the cost belongs at the call rather than hidden in a property declaration. Also how you reach a service you look up by a name computed at runtime.

```php
$this->get_plugin()->get( Options::class )->get( 'api_key' );
```

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
