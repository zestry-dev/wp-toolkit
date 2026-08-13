<!--
    Generated from src/Services/Request/Request.php.
    Do not edit by hand: run `composer docs` after changing the source.
-->

# Request

Turns declared arguments into schemas, and incoming values into properties.

The machinery behind `RequestArgument`, shared by every part of a plugin that takes arguments from somewhere it does not control — a `RestRoute`, an `Ability`, an `AjaxAction` and an `AdminPage`. That is why the attribute is one attribute: they ask the same question, and answering it four times would mean four vocabularies for one idea.

You rarely call this. Declare the properties, and the module that discovered your route, ability, action or page does the rest.

[Adding it](#adding-it) &nbsp;·&nbsp; [What it does for you](#what-it-does-for-you) &nbsp;·&nbsp; [Changing the defaults](#changing-the-defaults) &nbsp;·&nbsp; [Related classes](#related-classes) &nbsp;·&nbsp; [Methods](#methods) &nbsp;·&nbsp; [See also](#see-also)

## Adding it

```bash
wp zt add service request
```

## What it does for you

```php
#[RequestArgument( 'The order to cancel.' )]
public int $order_id;

#[RequestArgument( 'Whether to email the customer.' )]
public bool $notify = true;

becomes this schema, published to whoever is calling:

array(
    'type'       => 'object',
    'properties' => array(
        'order_id' => array( 'type' => 'integer', 'description' => 'The order to cancel.' ),
        'notify'   => array( 'type' => 'boolean', 'description' => 'Whether to email the customer.', 'default' => true ),
    ),
    'required'   => array( 'order_id' ),
);
```

and the values arrive on `$this->order_id` and `$this->notify`.

## Changing the defaults

`Request` takes no configuration, so it needs no `bootstrap.php` entry at all. It is built the first time something asks for it:

```php
$request = $plugin->get( Request::class );

// Or, from any service, module, command or action:
public Request $request;   // injected before your code runs
```

## Related classes

Shipped with this module, and written against directly:

- [`RequestArgument`](request-argument.md) — attribute, declares a property as one of the arguments its class accepts
- [`UploadedFile`](uploaded-file.md) — class, a file the request carried, as an object rather than five array keys

## Methods

### `get_arguments( $target )`

The arguments an object declares, keyed by property name.

```php
public function get_arguments( object|string $target ): array
```

|  | Details |
|---|---|
| **Parameters** | `$target` — The object, or the class name of a structure |
| **Return** | `array` |
| **Throws** | — |

Public and protected only, the same rule module injection uses: reflection cannot reliably reach a private property declared on an ancestor.

<br>

### `get_schema( $target, $overrides )`

The JSON Schema object describing everything an object accepts.

```php
public function get_schema( object|string $target, array $overrides = array() ): array
```

|  | Details |
|---|---|
| **Parameters** | `$target` — The object, or the class name of a structure<br>`$overrides` — A partial schema stated over the derived one |
| **Return** | A schema, or an empty array when nothing is declared |
| **Throws** | `InvalidArgumentException` — When an argument cannot be described |

`$overrides` is a partial schema stated on top of the derived one, for the parts an attribute cannot carry. PHP allows only constant expressions in an attribute argument, so `__()` — and anything else worked out while the request runs — has to be said here instead. Anything you state wins; everything you leave out keeps whatever the declarations gave it, including the binding and the validation they wired.

A keyed map is merged into, so naming one property's `description` leaves the rest of that property alone:

```php
$request->get_schema(
    $ability,
    array(
        'properties' => array(
            'order_id' => array( 'description' => __( 'The order to cancel.', 'acme-plugin' ) ),
        ),
    )
);
```

A **list is replaced whole** — `required`, an `enum`, a nullable `type`. Stating `enum => array( 'web' )` gives you exactly that, rather than your entry laid over the first of the derived ones. That is `Arr::replace_recursive()`, where the rule and its reason are written out.

<br>

### `get_submitted_values( $target )`

Read a target's declared arguments out of the current request.

```php
public function get_submitted_values( object $target ): array
```

|  | Details |
|---|---|
| **Parameters** | `$target` — The object declaring the arguments |
| **Return** | The values present, unslashed and otherwise raw |
| **Throws** | `InvalidArgumentException` — When an argument cannot be described |

For a caller whose platform hands it no parameters of its own. A route gets them from `WP_REST_Request::get_param()` and an ability is passed them outright; an admin page and an AJAX action are plain hooks, so this is the equivalent for them, resolving each name the same way a route does:

1. the **JSON body**, when the `Content-Type` says the body is one
2. the **form body** — `$_POST`, on a method that carries one
3. the **query string** — `$_GET`

First source holding the name wins, and a name no source holds is left out rather than nulled, so the property keeps its default. A **cookie and a header are never parameters**: a header is a separate accessor and a cookie is not on the request at all.

<br>

### `get_validated_values( $target, $values, $error_code )`

Check raw values against the schema, then apply each argument's own rules.

```php
public function get_validated_values( object $target, array $values, string $error_code )
```

|  | Details |
|---|---|
| **Parameters** | `$target` — The object declaring the arguments<br>`$values` — The values as they arrived<br>`$error_code` — The code a refusal carries |
| **Return** | The values checked, cast and sanitized, or the refusal |
| **Throws** | `InvalidArgumentException` — When an argument cannot be described |

For a caller whose platform checks nothing: an AJAX action is a hook, and WordPress hands it the superglobals exactly as they arrived. A route's parameters are checked by WordPress against the args it was registered with, and an ability's input against its schema, so neither needs this.

An argument the caller left out is left out, unless it has no default — that one is missing rather than absent, and is refused the way WordPress refuses a missing required parameter.

<br>

### `get_prepared_values( $target, $values, $error_code )`

Run each argument's own validate and sanitize callbacks.

```php
public function get_prepared_values( object $target, array $values, string $error_code )
```

|  | Details |
|---|---|
| **Parameters** | `$target` — The object declaring the arguments<br>`$values` — The values, already checked against the schema<br>`$error_code` — The code a refusal carries. Each platform has its own for input it rejected — `ability_invalid_input` is what WordPress itself returns when an ability's schema says no — and a caller should not have to handle two for one idea |
| **Return** | The values with each sanitized in place, or the refusal |
| **Throws** | — |

For a caller with nowhere to hang them — an ability, whose input WordPress validates against the schema and no further. A route wires the same callbacks into WordPress's own slots instead, through `get_rest_args()`, so neither runs them twice.

The schema's cast happens here too, and only here: an ability's input is validated and never sanitised, so this is where a value becomes the type its property was declared as.

<br>

### `reset( $target )`

Return an object's declared arguments to what a first call would see.

```php
public function reset( object $target ): void
```

|  | Details |
|---|---|
| **Parameters** | `$target` — The object whose arguments to clear |
| **Return** | — |
| **Throws** | `InvalidArgumentException` — When an argument cannot be described |

A route, an ability, an action and a page are each built once and answer many calls. Without this, an argument left out of the second call still holds what the first one sent — so a nullable argument meaning "not supplied", which is how one is made optional, quietly reports the previous caller's value instead.

An argument with a default goes back to it. One without goes back to uninitialized, so a required argument that is missing fails as it would on a first call rather than reusing a stale value.

`bind()` calls this before it assigns anything, so binding is enough on its own; this is public for a caller that assigns arguments by hand.

<br>

### `bind( $target, $values )`

Assign incoming values onto an object's declared arguments.

```php
public function bind( object $target, array $values ): void
```

|  | Details |
|---|---|
| **Parameters** | `$target` — The object to populate<br>`$values` — The validated values |
| **Return** | — |
| **Throws** | `InvalidArgumentException` — When an argument cannot be described |

A value for a structure is built into an instance of it first, so the handler reads `$this->address->city` rather than an array. An argument the caller left out goes back to its declared default, and one with no default goes back to uninitialized — see `reset()`, which runs first.

<br>

### `hydrate( $structure, $values )`

Build an instance of a structure from an array of values.

```php
public function hydrate( string $structure, array $values ): object
```

|  | Details |
|---|---|
| **Parameters** | `$structure` — The structure's class name<br>`$values` — The values for it |
| **Return** | `object` |
| **Throws** | `InvalidArgumentException` — When one of its arguments cannot be described |

Built without calling a constructor, so a structure needs no particular shape and its property defaults still apply.

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

Use it to reach something you did not declare a property for — a module you need in one method only, or one you look up by a name computed at runtime. For anything you use throughout the class, declare a typed property instead and let it be injected.

```php
$this->get_plugin()->get( Options::class )->get( 'api_key' );
```

## See also

- [`Service`](../service.md) — what every service inherits
- [`wp zt add service request`](../../commands/add-service.md) — the command that copies it
