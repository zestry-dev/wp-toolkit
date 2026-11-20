<!--
    Generated from src/Modules/Request/Request.php.
    Do not edit by hand: run `composer docs` after changing the source.
-->

# Request

Dependencies [`validation`](../validation/)

Reads a request into the properties a route or an action declares.

The declarations themselves are `Validation`'s: a typed property with `Property` or a rule. This module is the part only an HTTP request has — the parameters WordPress registers for a route, the order a value is looked up in, and uploads.

You rarely call this. Declare the properties, and the module that discovered your route or action does the rest.

[Adding it](#adding-it) &nbsp;·&nbsp; [Changing the defaults](#changing-the-defaults) &nbsp;·&nbsp; [Related classes](#related-classes) &nbsp;·&nbsp; [Methods](#methods) &nbsp;·&nbsp; [See also](#see-also)

## Adding it

```bash
wp zt add request
```

> [!IMPORTANT]
> **A module is built because `bootstrap.php` lists it.** `Request` does nothing until something asks, so it goes at the top level — which `wp zt add` writes for you. Left out, the first `with( Request::class )` throws rather than building it, which [`wp zt doctor`](../../commands/doctor.md) catches before a request does.

```php
// bootstrap.php
return array(
    Request::class,
);
```

## Changing the defaults

`Request` takes no configuration. The entry above is all it needs — reach it with `$this->with( Request::class )` from any module or discovered file, or `$plugin->get( Request::class )` from your entry file.

## Related classes

Shipped with this module, and written against directly:

- [`UploadedFile`](uploaded-file.md) — class, a file the request carried, as an object rather than five array keys

## Methods

### `get_submitted_values( $target )`

Read an object's declared properties out of the current request.

```php
public function get_submitted_values( object $target ): array
```

|  | Details |
|---|---|
| **Parameters** | `$target` — The object declaring the properties |
| **Return** | The values present, unslashed and otherwise raw |
| **Throws** | `InvalidArgumentException` — When a declaration cannot be described |

For a caller whose platform hands it no parameters of its own. A route gets them from `WP_REST_Request::get_param()` and an ability is passed them outright; an AJAX action is a plain hook, so this is the equivalent for it, resolving each name the same way a route does:

1. the **JSON body**, when the `Content-Type` says the body is one
2. the **form body** — `$_POST`, on a method that carries one
3. the **query string** — `$_GET`

An upload is read from `$_FILES`. First source holding the name wins, and a name no source holds is left out, so the property keeps its default. A **cookie and a header are never parameters**.

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

- [`validation`](../validation/) — copied in alongside this one
- [`Module`](../module.md) — what every module inherits
- [`wp zt add request`](../../commands/add.md) — the command that copies it
