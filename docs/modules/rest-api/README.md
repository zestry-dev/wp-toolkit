<!--
    Generated from src/Modules/RestApi/RestApi.php.
    Do not edit by hand: run `composer docs` after changing the source.
-->

# RestApi

Discovers `resources/routes/` &nbsp;·&nbsp; Each file returns [`Route`](route.md) &nbsp;·&nbsp; Dependencies [`path`](../path/), [`request`](../request/)

Discovers plugin REST API routes and registers them with WordPress.

A routes directory contains PHP files, one per route — the same file-based convention Ajax uses for actions and AdminPages for pages. Unlike those two, the directory structure here is purely organizational.

A route file returns a `Route` value, built with the static constructor matching its HTTP method. It declares its own namespace version and URL pattern as plain strings, so nothing about a route comes from its file's name or location.

[Adding it](#adding-it) &nbsp;·&nbsp; [A minimal route file](#a-minimal-route-file) &nbsp;·&nbsp; [Narrowing what a route accepts](#narrowing-what-a-route-accepts) &nbsp;·&nbsp; [Changing the defaults](#changing-the-defaults) &nbsp;·&nbsp; [Writing a Route](#writing-a-route) &nbsp;·&nbsp; [Related classes](#related-classes) &nbsp;·&nbsp; [Constants](#constants) &nbsp;·&nbsp; [Methods](#methods) &nbsp;·&nbsp; [See also](#see-also)

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

// resources/routes/widgets/get-one.php use Acme\Plugin\Core\Modules\Request\Attributes\RequestArgument; use Acme\Plugin\Core\Modules\RestApi\Route; use Acme\Plugin\Core\Modules\RestApi\RestRoute;

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
use Acme\Plugin\Core\Modules\Request\Attributes\RequestArgument;
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

`RestApi` takes no configuration. The entry above is all it needs — reach it with `$this->with( RestApi::class )` from any module or discovered file, or `$plugin->get( RestApi::class )` from your entry file.

## Writing a Route

A file in `resources/routes/` returns a [`Route`](route.md) instance, which `wp zt make route <name>` generates.

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

Reach for this wherever a plain `add_action( 'init', ... )` would go: that callback never runs once `init` has passed, and a module can be built on either side of it.

The callback receives the module, so a closure declared elsewhere needs no `use` to reach it:

```php
public function on_boot(): void {
    $this->on_wp_init( function ( self $module ): void {
        $module->register_widgets();
    } );
}
```

`$priority` is WordPress's own, for ordering against something else on `init` — another plugin's registration, or a post type a taxonomy of yours attaches to. **It applies only while `init` is still ahead**: a module built after `init` has fired runs its callback immediately, in registration order, whatever priority it asked for. Ordering that has to hold either way belongs inside one callback.

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

## See also

- [`Route`](route.md) — what a file in `resources/routes/` returns
- [`path`](../path/) — copied in alongside this one
- [`request`](../request/) — copied in alongside this one
- [`Module`](../module.md) — what every module inherits
- [`wp zt add rest-api`](../../commands/add.md) — the command that copies it
