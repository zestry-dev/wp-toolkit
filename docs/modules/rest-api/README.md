<!--
    Generated from src/Modules/RestApi/RestApi.php.
    Do not edit by hand: run `composer docs` after changing the source.
-->

# RestApi

Discovers `routes/` &nbsp;·&nbsp; Each file returns [`Route`](route.md) &nbsp;·&nbsp; Dependencies [`path`](../../services/path/), [`request`](../../services/request/)

Discovers plugin REST API routes and registers them with WordPress.

A routes directory contains PHP files, one per route — the same file-based convention Ajax uses for actions and AdminPages for pages. Unlike those two, the directory structure here is purely organizational.

A route file returns a `Route` value, built with the static constructor matching its HTTP method. It declares its own namespace version and URL pattern as plain strings, so nothing about a route comes from its file's name or location.

[Adding it](#adding-it) &nbsp;·&nbsp; [A minimal route file](#a-minimal-route-file) &nbsp;·&nbsp; [Narrowing what a route accepts](#narrowing-what-a-route-accepts) &nbsp;·&nbsp; [Changing the defaults](#changing-the-defaults) &nbsp;·&nbsp; [Writing a Route](#writing-a-route) &nbsp;·&nbsp; [Related classes](#related-classes) &nbsp;·&nbsp; [Constants](#constants) &nbsp;·&nbsp; [Methods](#methods) &nbsp;·&nbsp; [See also](#see-also)

## Adding it

```bash
wp zt add module rest-api
```

> [!IMPORTANT]
> **A module is built because `bootstrap.php` lists it.** `RestApi` binds its hooks when the plugin builds it, so it has to be listed there — which `wp zt add` writes for you. Left out, nothing is discovered and nothing reports why; [`wp zt doctor`](../../commands/doctor.md) is what catches it.

```php
// bootstrap.php
return array(
    RestApi::class,
);
```

## A minimal route file

// routes/widgets/get-one.php use Acme\Plugin\Core\Services\Request\Attributes\RequestArgument; use Acme\Plugin\Core\Modules\RestApi\Route; use Acme\Plugin\Core\Modules\RestApi\RestRoute;

return Route::get( 'v1', '/widgets/{id}', new class extends RestRoute {

```php
#[RequestArgument( 'The widget to return.' )]
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
```

} );

## Narrowing what a route accepts

The property's type is already a rule — `int $id` rejects `abc` with a 400 before `handle()` runs — and `schema:` states the rest. A client can read every one of those before calling, which is why they are worth preferring to a callback:

```php
use Acme\Plugin\Core\Services\Request\Attributes\RequestArgument;
use Acme\Plugin\Core\Modules\RestApi\Route;
use Acme\Plugin\Core\Modules\RestApi\RestRoute;

return Route::get( 'v1', '/widgets/{id}', new class extends RestRoute {
    #[RequestArgument( 'The widget to return.', schema: array( 'minimum' => 1 ) )]
    public int $id;

    #[RequestArgument( 'How to render it.', schema: array( 'enum' => array( 'json', 'csv' ) ) )]
    public string $format = 'json';

    #[RequestArgument( 'Only widgets this account owns.', validate: array( self::class, 'is_own_account' ) )]
    public int $account_id = 0;

    public static function is_own_account( $value ): bool {
        return 0 === $value || acme_account_belongs_to_user( $value, get_current_user_id() );
    }

    // ... permission_check(), handle(), schema()
} );
```

`validate` is for what JSON Schema cannot say — that a row exists, that a slug is free. Returning false rejects the request with a 400 before `handle()` runs.

A check spanning two fields belongs in `handle()` rather than here: a callback sees one value in isolation, while by `handle()` every property is bound and an error can name the combination that was wrong.

## Changing the defaults

// bootstrap.php return array(

```php
RestApi::class => static function ( RestApi $api ): void {
    $api->set_routes_root( 'routes' );
},
```

);

## Writing a Route

A file in `routes/` returns a [`Route`](route.md) instance, which `wp zt make route <name>` generates.

## Related classes

Shipped with this module, and written against directly:

- [`RestRoute`](rest-route.md) — abstract class, base class for a file-based WordPress REST API route handling one HTTP method

## Constants

### `DEFAULT_ROUTES_ROOT`

```php
const DEFAULT_ROUTES_ROOT = 'routes';
```

Default plugin-relative directory of route files.

## Methods

### `set_routes_root( $routes_root )`

Set the plugin-relative directory that contains route files.

```php
public function set_routes_root( string $routes_root ): void
```

|  | Details |
|---|---|
| **Parameters** | `$routes_root` — Plugin-relative directory of route files |
| **Return** | — |
| **Throws** | — |

Call this from the module initializer before the plugin boots the module to override the default `routes` directory. The root is read inside the `rest_api_init` callback, so a call made after that hook has run has no effect on the routes already registered.

<br>

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

Almost everything a module registers — a post type, a block, a WP-CLI command — has to happen on `init`, and a plain `add_action( 'init', ... )` is a callback that never runs once `init` has passed. A module can be resolved on either side of it: `Plugin::run()` is synchronous, so an entry file that calls it at plugin load is ahead of `init`, while one that calls it from a later hook — or a `get()` during a request — is behind. This behaves the same either way, so a module never has to care which.

The callback receives the module, matching the initializer signature, so a closure declared elsewhere needs no `use` to reach it:

```php
protected function on_boot(): void {
    $this->on_wp_init( function ( self $module ): void {
        $module->register_widgets();
    } );
}
```

`$priority` is WordPress's own, for ordering against something else on `init` — another plugin's registration, or a post type a taxonomy of yours attaches to. **It applies only when `init` is still ahead**, which is the case for the documented entry file, since `run()` at plugin load is well before `init`. A module resolved *after* `init` has fired runs its callback immediately, because there is no longer a queue to be ordered in — so two callbacks registered then run in the order they were registered, whatever priority each asked for. Ordering that has to hold in both cases belongs inside one callback.

## See also

- [`Route`](route.md) — what a file in `routes/` returns
- [`path`](../../services/path/) — copied in alongside this one
- [`request`](../../services/request/) — copied in alongside this one
- [`Module`](../module.md) — what every module inherits
- [`wp zt add module rest-api`](../../commands/add-module.md) — the command that copies it
