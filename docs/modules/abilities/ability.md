<!--
    Generated from src/Modules/Abilities/Ability.php.
    Do not edit by hand: run `composer docs` after changing the source.
-->

# Ability

[An ability](#an-ability) &nbsp;·&nbsp; [Generated starting point](#generated-starting-point) &nbsp;·&nbsp; [You must implement](#you-must-implement) &nbsp;·&nbsp; [Methods you can use](#methods-you-can-use)

One thing your plugin can do, described well enough for something else to call it.

A file in `resources/abilities/` returns one of these, and its filename is the name it registers under: `create-order.php` becomes `{plugin-slug}/create-order`.

The audience is not a person reading your code. WordPress puts every ability on a REST endpoint, and an MCP adapter turns the same registration into a tool an AI agent can call — from your `description()` and your schemas alone, without a line of protocol code on your side. That is what makes this different from a `Route`: a route is a URL you document for developers you can talk to, an ability is a contract something reads on its own.

Write for that reader. `description()` is the entire brief an agent gets for deciding whether to call this at all; `input_schema()` is how it knows what to send. Both are worth more care than they look.

## An ability

```php
use Acme\Plugin\Core\Modules\Abilities\Ability;
use Acme\Plugin\Core\Modules\Request\Attributes\RequestArgument;
use Acme\Plugin\Core\Modules\Abilities\Effect;

return new class extends Ability {

    public function label(): string {
        return __( 'Cancel an order', 'acme-plugin' );
    }

    public function description(): string {
        return __(
            'Cancels an order that has not shipped yet and restocks its items. Refunds are not issued.',
            'acme-plugin'
        );
    }

    public function effect(): Effect {
        return Effect::Delete;
    }

    #[RequestArgument( 'The order to cancel.' )]
    public int $order_id;

    public function permission_check( mixed $input ): bool {
        return current_user_can( 'edit_shop_orders', $this->order_id );
    }

    public function handle( mixed $input ): mixed {
        return array( 'cancelled' => acme_cancel_order( $this->order_id ) );
    }
};
```

## Generated starting point

[`wp zt make ability <name>`](../../commands/make-ability.md) writes this file:

```php
<?php
/**
 * example ability.
 */

declare( strict_types=1 );

// Loaded by WordPress, never requested directly.
\defined( 'ABSPATH' ) || exit;

use Acme\Plugin\Core\Modules\Abilities\Ability;
use Acme\Plugin\Core\Modules\Request\Attributes\RequestArgument;
use Acme\Plugin\Core\Modules\Abilities\Effect;

return new class() extends Ability {

	// The ability's name is this file's name -- {plugin-slug}/example. Rename
	// the file and this is a different ability: an agent's saved tool call, and
	// any run( '...' ) of your own, stop finding it.

	// Each input, described once. The property says the type and whether it is
	// required -- no default means required -- and the value is bound before
	// the methods below run, so read $this->id, not $input['id'].
	//
	// Say as much as you can in `schema:` rather than in a validate callback:
	// a rule in the schema is one the caller can satisfy before calling.
	//
	// #[RequestArgument( 'How to sort.', schema: array( 'enum' => array( 'date', 'title' ) ) )]
	// public string $order_by = 'date';
	//
	// `__()` cannot go inside an attribute -- PHP allows only constant
	// expressions there. Say a translated description in input_schema()
	// instead, which is stated over the schema these declarations already give,
	// so the property keeps its type, its required-ness and its binding. Drop
	// the description from the attribute when you do, so it stays in one place:
	//
	// #[RequestArgument]
	// public int $id;
	//
	// public function input_schema(): array {
	//     return array(
	//         'properties' => array(
	//             'id' => array( 'description' => \__( 'Which one to act on.', 'acme-plugin' ) ),
	//         ),
	//     );
	// }
	#[RequestArgument( 'Which one to act on.' )]
	public int $id;

	// A short name, shown wherever abilities are listed.
	public function label(): string {
		return \__( 'Example', 'acme-plugin' );
	}

	// The whole brief an AI agent gets for deciding whether to call this. Say
	// what it does, what it does not do, and anything a reader would guess
	// wrong from the label alone.
	public function description(): string {
		return \__( 'Describe what this does, and what it deliberately does not.', 'acme-plugin' );
	}

	// What running this does to the site. WordPress turns it into the HTTP
	// method the REST endpoint demands, so an inaccurate answer here is a 405
	// for whoever calls you: Read -> GET, Create and Update -> POST,
	// Delete -> DELETE.
	public function effect(): Effect {
		return Effect::Read;
	}

	// Whether anything outside your own PHP may call this. Registering an
	// ability is useful either way -- run() reaches it from your own code --
	// and true is what puts it on the REST API and offers it to an MCP adapter.
	//
	// Turn it on once permission_check() below is right for a stranger:
	// WordPress's run endpoint has no authentication of its own, so that check
	// is the only thing between an anonymous request and handle().
	public function is_public(): bool {
		return false;
	}

	// What this returns. Validated after handle(), so a result that does not
	// match is an error rather than something the caller has to guess at.
	public function output_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'ok' => array(
					'type'        => 'boolean',
					'description' => 'Whether it worked.',
				),
			),
		);
	}

	// The gate, checked on every way in -- REST, MCP and your own PHP. Returns
	// a plain bool: WordPress replaces a refusal with a message of its own, so
	// that a check cannot leak why it said no.
	public function permission_check( mixed $input ): bool {
		return \current_user_can( 'edit_posts' );
	}

	// Reached only once the input validated and the check above passed. Return
	// a WP_Error for a failure the caller should see; its message is what they
	// read.
	public function handle( mixed $input ): mixed {
		return array( 'ok' => true );
	}

	// Reach any declared module with `$this->with( Options::class )`.

	// File this under a category other than your plugin's own -- one of
	// WordPress's ('site', 'user'), or one you declared with
	// $abilities->add_categories() in bootstrap.php.
	//
	// public function category(): string {
	//     return 'site';
	// }
};
```

## You must implement

These 5 methods are abstract: a subclass that does not declare all of them will not load.

### `label()`

A short name for this ability.

```php
abstract public function label(): string
```

|  | Details |
|---|---|
| **Parameters** | — |
| **Return** | `string` |
| **Throws** | — |

Shown wherever abilities are listed. A few words, translated.

<br>

### `description()`

What this ability does, in prose.

```php
abstract public function description(): string
```

|  | Details |
|---|---|
| **Parameters** | — |
| **Return** | `string` |
| **Throws** | — |

The most important method here. An agent reads only this to decide whether your ability is the right one to call, so write it as an instruction to a capable stranger: what it does, what it does *not* do, and anything that would surprise someone who guessed from the label. "Cancels an order that has not shipped yet and restocks its items. Refunds are not issued."

<br>

### `effect()`

What running this does to the site.

```php
abstract public function effect(): Effect
```

|  | Details |
|---|---|
| **Parameters** | — |
| **Return** | `Effect` |
| **Throws** | — |

Required rather than defaulted, because WordPress turns it into the HTTP method the REST endpoint demands — and every other method gets `405`. An unstated effect is not "unknown" to WordPress, it is "no", which would make a read-only ability `POST`-only. `Effect`.

<br>

### `permission_check( $input )`

Whether the current user may run this.

```php
abstract public function permission_check( mixed $input ): bool
```

|  | Details |
|---|---|
| **Parameters** | `$input` — The validated input, in the shape input_schema() describes |
| **Return** | `bool` |
| **Throws** | — |

Checked before `handle()`, on every way into the ability — REST, MCP, and your own PHP alike. This is the gate, so a capability check belongs here rather than in `handle()`.

Any `RequestArgument` properties are already bound, so a check can name the thing being acted on: `current_user_can( 'edit_post', $this->id )`.

Unlike `RestRoute::permission_check()` this returns a plain `bool`. WordPress replaces a refusal with a message of its own before the caller sees it — deliberately, so a check cannot leak why it said no to someone who is not allowed — and treats a returned `WP_Error` as a mistake worth reporting with `_doing_it_wrong()`.

<br>

### `handle( $input )`

Do the thing.

```php
abstract public function handle( mixed $input ): mixed
```

|  | Details |
|---|---|
| **Parameters** | `$input` — The validated input |
| **Return** | The result, or a `WP_Error` |
| **Throws** | — |

Reached only once the input has been validated against `input_schema()` and `permission_check()` has passed. Whatever you return is validated against `output_schema()` in turn, so a shape that disagrees with the schema fails loudly rather than reaching the caller.

Return a `WP_Error` for a failure the caller should see; its message is read by whatever called you, so make it a sentence rather than a code.

Any `RequestArgument` properties are bound by the time this runs, so read `$this->order_id` rather than `$input['order_id']`.

## Methods you can use

### `get_name()`

The name this ability is registered under.

```php
final public function get_name(): string
```

|  | Details |
|---|---|
| **Parameters** | — |
| **Return** | `string` |
| **Throws** | — |

Your filename under your plugin's namespace, since abilities share one registry with every other plugin: `create-order.php` gives `{plugin-slug}/create-order`. This is the name a client calls, and the one that appears in `wp-json/wp-abilities/v1/abilities`.

The filename is used exactly as written. WordPress accepts only lowercase letters, digits and dashes in either half of the name, and a file it would refuse is refused here first — `create_order.php` throws a `DiscoveryException` naming the file, at boot, rather than registering under a name you did not type. Spell it with dashes.

<br>

### `input_schema()`

JSON Schema for what this ability accepts.

```php
public function input_schema(): array
```

|  | Details |
|---|---|
| **Parameters** | — |
| **Return** | `array` |
| **Throws** | — |

WordPress validates against it before your code runs, so `handle()` never sees input that does not fit.

The schema is built for you from your `RequestArgument` properties, which is the shorter way to say the same thing and binds the values onto the object as well. What you return here is stated *over* that rather than instead of it, so a declaration you say nothing about keeps everything it had — its type, its required-ness, its `validate:` rule, and its binding.

That is what makes an argument's description translatable. PHP allows only constant expressions in an attribute argument, so `__()` cannot go inside one — leave the description off the attribute and name the property here instead, so it is still written exactly once:

```php
// Still the declaration: the type, the required-ness and the binding are
// all still coming from here. Only the description moved.
#[RequestArgument]
public int $order_id;

public function input_schema(): array {
    return array(
        'properties' => array(
            'order_id' => array( 'description' => __( 'The order to cancel.', 'acme-plugin' ) ),
        ),
    );
}
```

A keyed map is merged into, so the rest of that property is left alone; a list — `required`, an `enum` — is replaced whole. Describe a property you never declared and it is published and validated like any other, but nothing binds it, so read that one from `$input`.

Declare no properties at all and what you return here is the entire schema, written by hand. An ability that declares nothing and returns nothing takes no input.

<br>

### `output_schema()`

JSON Schema for what this ability returns.

```php
public function output_schema(): array
```

|  | Details |
|---|---|
| **Parameters** | — |
| **Return** | `array` |
| **Throws** | — |

Validated after `handle()`, so a result that does not match is an error rather than something the caller has to guess at. Worth writing even when the shape feels obvious to you: it is not obvious to the thing calling.

A wide result is worth letting the caller narrow, which WordPress's own abilities do with an optional `fields`. Declare it like any other argument, and an agent that needs two of your twenty properties reads two:

```php
#[RequestArgument(
    'Which properties to return. All of them, if you leave it out.',
    schema: array( 'items' => array( 'type' => 'string', 'enum' => array( 'id', 'title', 'status' ) ) )
)]
public array $fields = array( 'id', 'title', 'status' );
```

The `enum` is what refuses a name you do not have, so `handle()` can filter on `$this->fields` without checking it first.

<br>

### `category()`

The category this ability is filed under.

```php
public function category(): string
```

|  | Details |
|---|---|
| **Parameters** | — |
| **Return** | `string` |
| **Throws** | — |

Defaults to one named after your plugin, registered for you. Return `'site'` or `'user'` for WordPress's own, or another slug you registered yourself — the category has to exist by the time abilities register, or WordPress refuses this one.

<br>

### `is_public()`

Whether anything outside your own PHP may call this.

```php
public function is_public(): bool
```

|  | Details |
|---|---|
| **Parameters** | — |
| **Return** | `bool` |
| **Throws** | — |

False by default, matching WordPress: an ability is a registry entry first and an endpoint second, and plenty of them exist only to be composed by code you wrote. Return true to put this one on the REST API and offer it to any MCP adapter installed on the site.

Being public is not the same as being unguarded — `permission_check()` still runs. But it is the *only* thing that runs: WordPress's run endpoint checks that the ability is public, validates the input against the schema, and calls your check, with no authentication of its own anywhere in it. An anonymous request reaches `permission_check()` directly, and whatever it returns is the answer. Listing abilities does require a logged-in user; running one does not.

So read `permission_check()` again with a stranger in mind before returning true here.

<br>

### `is_shown_in_rest()`

Whether this ability is exposed through the REST API.

```php
public function is_shown_in_rest(): bool
```

|  | Details |
|---|---|
| **Parameters** | — |
| **Return** | `bool` |
| **Throws** | — |

Follows `is_public()`, since an ability offered to outside callers is normally offered over HTTP as well.

Return false from a public ability to separate the two: it stays available to any MCP adapter installed on the site and disappears from `wp-json/wp-abilities/v1/abilities`.

<br>

### `meta()`

Anything else WordPress or an adapter should record about this ability.

```php
public function meta(): array
```

|  | Details |
|---|---|
| **Parameters** | — |
| **Return** | `array` |
| **Throws** | — |

An escape hatch for the parts of `meta` that have no method of their own, merged underneath the ones that do — `annotations` comes from `effect()`, `public` from `is_public()` and `show_in_rest` from `is_shown_in_rest()`.

What you put here is queryable from WordPress 7.1 on, which filters on meta: `wp_get_abilities( array( 'meta' => array( 'group' => 'billing' ) ) )` returns the abilities that declared it.

<br>

### `abilities()`

The module that discovered this ability.

```php
final protected function abilities(): Abilities
```

|  | Details |
|---|---|
| **Parameters** | — |
| **Return** | `Abilities` |
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
