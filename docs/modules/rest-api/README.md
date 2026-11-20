<!--
    Generated from src/Modules/RestApi/RestApi.php.
    Do not edit by hand: run `composer docs` after changing the source.
-->

# RestApi

Discovers `resources/routes/` &nbsp;·&nbsp; Each file returns [`Route`](route.md) &nbsp;·&nbsp; Dependencies [`path`](../path/), [`request`](../request/), [`validation`](../validation/)

Discovers plugin REST API routes and registers them with WordPress.

A routes directory contains PHP files, one per route — the same file-based convention Ajax uses for actions and AdminPages for pages. Unlike those two, the directory structure here is purely organizational.

A route file returns a `Route` value, built with the static constructor matching its HTTP method. It declares its own namespace version and URL pattern as plain strings, so nothing about a route comes from its file's name or location.

Each file's RestRoute instance handles exactly one HTTP method. The module wires it, assigning the plugin so `with()` reaches every module, then hands it to `Request` to turn its declared properties into WordPress's args schema. `handle()` is wrapped, so each validated and sanitized request value is bound onto its property before the method runs. The route's response schema (see `RestRoute::schema()`) is published only when the route returns one.

Each `{name}` token in a pattern becomes a named regex capture group, matching any run of non-slash characters. Declaring the matching property with a [`#[Property]`](../validation/property.md) or a [rule](../validation/rule.md) is what narrows that — to an integer, say — and the module throws at registration if a token has no property to bind to.

> [!NOTE]
> **A property bound to a pattern token is always required**, whatever its declaration says. WordPress rejects a request whose URL does not provide it before routing even begins. Every other argument is required only when its property has no default.

**To accept `null`, give the property a default**: `?string $note = null`. WordPress counts a parameter sent as `null` as not sent, so `?string $note` with no default refuses `{"note": null}` as missing. A `null` sent for a property that can hold one is bound as `null`, even over a non-null default; for one that cannot, it is treated as not sent, and the property keeps its default.

A `{id}` token is a plain string in PHP, never a filename, so it escapes WordPress.org's Plugin Check rules on special characters. Those reject any file or folder name containing `[`, `]`, `(`, `)`, `{` or `}` as `badly_named_files`.

[Adding it](#adding-it) &nbsp;·&nbsp; [A minimal route file](#a-minimal-route-file) &nbsp;·&nbsp; [Narrowing what a route accepts](#narrowing-what-a-route-accepts) &nbsp;·&nbsp; [One route per HTTP method](#one-route-per-http-method) &nbsp;·&nbsp; [Changing the defaults](#changing-the-defaults) &nbsp;·&nbsp; [Writing a Route](#writing-a-route) &nbsp;·&nbsp; [Related classes](#related-classes) &nbsp;·&nbsp; [Constants](#constants) &nbsp;·&nbsp; [Methods](#methods) &nbsp;·&nbsp; [See also](#see-also)

## Adding it

```bash
wp zt add rest-api
```

> [!IMPORTANT]
> **A module is built because `bootstrap.php` lists it, and the heading says when.** `RestApi` acts the moment it is built, so it goes under the hook it acts on — which `wp zt add` writes for you. Left at the top level it throws; left out entirely, nothing is discovered and nothing reports why, which is what [`wp zt doctor`](../../commands/doctor.md) catches.

```php
// bootstrap.php
return array(
    'acme_plugin_loaded' => array(
        RestApi::class,
    ),
);
```

`acme_plugin_loaded` is your plugin's own action, fired at the end of `run()` once every module is built — `{slug}_loaded`, so a plugin slugged `acme-crm` spells it `acme_crm_loaded`. It is the earliest heading that still has the whole plugin behind it.

## A minimal route file

```php
// resources/routes/widgets/get-one.php
use Acme\Plugin\Core\Modules\RestApi\Route;
use Acme\Plugin\Core\Modules\RestApi\RestRoute;
use Acme\Plugin\Core\Modules\Validation\Property;

return Route::get( 'v1', '/widgets/{id}', new class extends RestRoute {
    #[Property( 'The widget to return.' )]
    public int $id;

    public function permission_check( WP_REST_Request $request ): bool {
        return true;
    }

    public function handle( WP_REST_Request $request ): WP_REST_Response {
        return new WP_REST_Response( array( 'id' => $this->id ) );
    }

    public function schema(): ?array {
        return null;
    }
} );
```

## Narrowing what a route accepts

The property's type is already a rule — `int $id` rejects `abc` with a 400 before `handle()` runs — and a rule states the rest. A client can read every limit a rule publishes before calling, which is why those are worth preferring to a check:

```php
use Acme\Plugin\Core\Modules\RestApi\Route;
use Acme\Plugin\Core\Modules\RestApi\RestRoute;
use Acme\Plugin\Core\Modules\Validation\Property;
use Acme\Plugin\Core\Modules\Validation\Rule;
use Acme\Plugin\Core\Modules\Validation\Rules\OneOf;
use Acme\Plugin\Core\Modules\Validation\Rules\Range;

return Route::get( 'v1', '/widgets/{id}', new class extends RestRoute {
    #[Property( 'The widget to return.' ), Range( min: 1 )]
    public int $id;

    #[Property( 'How to render it.' ), OneOf( 'json', 'csv' )]
    public string $format = 'json';

    #[Property( 'Only widgets this account owns.' )]
    #[Rule( check: array( self::class, 'is_own_account' ) )]
    public int $account_id = 0;

    public static function is_own_account( int $value ): ?string {
        return 0 === $value || acme_account_belongs_to_user( $value, get_current_user_id() )
            ? null
            : __( 'That account is not yours.', 'acme' );
    }

    // ... permission_check(), handle(), schema()
} );
```

A `check` is for what JSON Schema cannot say — that a row exists, that a slug is free. Returning a message rejects the request with a 400 before `handle()` runs, and the message is what the caller reads.

A check spanning two fields cannot be a rule on the route itself: a route, an ability or an action carrying a class rule throws on its first call. Put those fields in a `Structure` with the rule on it and declare one property of that type, or compare them in `handle()`, where every property is bound.

## One route per HTTP method

A sibling file can declare that same `/widgets/{id}` pattern under a different method, with a stricter `permission_check()` of its own. Group them into folders however suits the plugin — the directory is organizational, and the pattern lives in the file.

```php
// resources/routes/widgets/delete-one.php
return Route::delete( 'v1', '/widgets/{id}', new class extends RestRoute {
    #[Property( 'The widget to delete.' )]
    public int $id;

    public function permission_check( WP_REST_Request $request ): bool {
        return current_user_can( 'delete_posts' );
    }

    // ... handle(), schema()
} );
```

## Changing the defaults

`RestApi` takes no configuration. The entry above is all it needs — reach it with `$this->with( RestApi::class )` from any module or discovered file, or `$plugin->get( RestApi::class )` from your entry file.

## Writing a Route

A file in `resources/routes/` returns a [`Route`](route.md) instance, which [`wp zt make route <name>`](../../commands/make-route.md) generates.

## Related classes

Shipped with this module, and written against directly:

- [`RestRoute`](rest-route.md) — abstract class, base class for a file-based WordPress REST API route handling one HTTP method

## Constants

### `ROUTES_ROOT`

```php
const ROUTES_ROOT = 'resources/routes';
```

Default plugin-relative directory of route files.

## Methods

### `get_rest_namespace( $version )`

Build the REST namespace for a version, namespaced under the plugin slug.

```php
public function get_rest_namespace( string $version ): string
```

|  | Details |
|---|---|
| **Parameters** | `$version` — The route's own namespace version, e.g. `'v1'` |
| **Return** | The full REST namespace, e.g. `'acme-plugin/v1'` |
| **Throws** | — |

The single source of truth for the `{plugin-slug}/{version}` shape that `register_routes()` registers under, matching the accessor every other namespacing module exposes (`Ajax::get_action_slug()`, `Cron::get_schedule_slug()`, `AdminPages::get_page_slug()`, `Assets::get_asset_slug()`). Ask for it rather than hardcoding the slug in a JS `fetch()` or a `rest_url()` call and reproducing the join.

<br>

### `get_route_url( $version, $pattern, $args )`

Build the full URL for a route, the counterpart to `Ajax::get_action_url()`.

```php
public function get_route_url( string $version, string $pattern, array $args = array() ): string
```

|  | Details |
|---|---|
| **Parameters** | `$version` — The route's namespace version, e.g. `'v1'`<br>`$pattern` — The route path, e.g. `'/widgets/42'`<br>`$args` — Optional query arguments |
| **Return** | The full REST URL |
| **Throws** | — |

`$pattern` is the route's own pattern exactly as the route file declares it. Placeholder tokens are not substituted — a caller that needs a concrete URL replaces them itself, since only the caller knows the values:

```php
$api->get_route_url( 'v1', '/widgets/42' );
```

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

- [`Route`](route.md) — what a file in `resources/routes/` returns
- [`path`](../path/) — copied in alongside this one
- [`request`](../request/) — copied in alongside this one
- [`validation`](../validation/) — copied in alongside this one
- [`Module`](../module.md) — what every module inherits
- [`wp zt add rest-api`](../../commands/add.md) — the command that copies it
