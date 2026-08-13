<!--
    Generated from src/Modules/RestApi/Route.php.
    Do not edit by hand: run `composer docs` after changing the source.
-->

# Route

Declares a route's namespace version, URL pattern, and HTTP method, paired with the RestRoute instance that handles it.

A route file returns one of these, built with the static constructor matching its HTTP method, rather than a bare RestRoute instance. That makes the file the single source of truth for its own URL — nothing about where the route lives comes from the file's path or name.

RestApi discovers route files the way Ajax discovers actions and AdminPages discovers pages: one file per route, in a configured directory. The directory structure is purely organizational, so grouping `widgets/get-one.php` beside `widgets/delete-one.php` is for a human's benefit, not RestApi's.

```php
use Acme\Plugin\Core\Services\Request\Attributes\RequestArgument;
use Acme\Plugin\Core\Modules\RestApi\Route;
use Acme\Plugin\Core\Modules\RestApi\RestRoute;

return Route::get( 'v1', '/widgets/{id}', new class extends RestRoute {
    #[RequestArgument(
        'The widget to return.',
        validate: [ self::class, 'is_valid_id' ],
        sanitize: [ self::class, 'to_id' ]
    )]
    public int $id;

    public static function is_valid_id( $value ): bool {
        return is_numeric( $value );
    }

    public static function to_id( $value ): int {
        return (int) $value;
    }

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

`{id}` in the pattern is a plain string literal here, not a filename — so, unlike a folder or file name, it is never subject to WordPress.org's Plugin Check restrictions on special characters. RestApi turns each `{name}` token in the pattern into a named regex capture group matching any run of non-slash characters (`(?P<name>[^/]+)`); a `RequestArgument`-attributed property on the route named `name` binds to it (and can narrow the match, for example to digits only, via its validate callback).

`$version` is combined with the plugin's own slug to form WordPress's actual REST namespace (`{plugin-slug}/{version}`) — the same `{plugin-slug}/v1` shape `Ajax` and `AdminPages` use for their own namespacing.

## Generated starting point

[`wp zt make route <name>`](../../commands/make-route.md) writes this file:

```php
<?php
/**
 * GET /books
 */

declare( strict_types=1 );

// Loaded by WordPress, never requested directly.
\defined( 'ABSPATH' ) || exit;

use Acme\Plugin\Core\Modules\RestApi\Route;
use Acme\Plugin\Core\Modules\RestApi\RestRoute;
// use Acme\Plugin\Core\Services\Request\Attributes\RequestArgument;

return Route::get(
	'v1',
	'/books',
	new class() extends RestRoute {

		// A {token} in the pattern above (or a query-string/body parameter of the
		// same name) binds onto a typed property instead of being pulled out of
		// $request by hand. The property states the type and, by having no
		// default, that it is required; `schema:` states anything else JSON Schema
		// can express, which a client can read before calling. Uncomment to use:
		//
		// #[RequestArgument( 'The widget to return.', schema: array( 'minimum' => 1 ) )]
		// public int $id;
		//
		// `validate:` is for what the schema cannot say -- that a row exists, that
		// a slug is free:
		//
		// #[RequestArgument( 'The widget to return.', validate: array( self::class, 'is_known' ) )]
		// public int $id;
		//
		// public static function is_known( $value ): bool {
		//     return null !== acme_find_widget( $value );
		// }
		//
		// `__()` cannot go inside an attribute -- PHP allows only constant
		// expressions there. Say a translated description in args() instead,
		// which is stated over the schema the declarations already give, so the
		// property keeps its type, its required-ness and its binding. Drop the
		// description from the attribute when you do, so it stays in one place:
		//
		// #[RequestArgument]
		// public int $id;
		//
		// public function args(): array {
		//     return array(
		//         'id' => array( 'description' => \__( 'The widget to return.', 'acme-plugin' ) ),
		//     );
		// }

		// Authorization check, run before handle(). Return false or a WP_Error
		// to reject the request. Replace 'manage_options' with whatever
		// capability (optionally scoped to a specific resource, e.g.
		// current_user_can( 'edit_post', $post_id )) this route actually
		// requires -- or `return true;` to make it deliberately public, which
		// for a REST route means callable by anyone on the internet, including
		// logged-out visitors.
		public function permission_check( WP_REST_Request $request ): bool|\WP_Error {
			return \current_user_can( 'manage_options' );
		}

		// Runs only after permission_check() passes and any #[RequestArgument]
		// properties above have been validated/bound (read them as $this->id,
		// not $request->get_param( 'id' )).
		public function handle( WP_REST_Request $request ): WP_REST_Response {
			return new WP_REST_Response( array() );
		}

		// Describes the shape of the response handle() returns, as a JSON
		// Schema array -- unrelated to #[RequestArgument]'s request-side validation
		// above. null means "publish no schema" -- return it explicitly, the
		// same way permission_check() has to; a non-null array becomes
		// discoverable via an OPTIONS request, same as core's own REST routes.
		public function schema(): ?array {
			return null;
		}
	}
);
```

## Methods

### `get( $version, $pattern, $route )`

Declare a GET route.

```php
public static function get( string $version, string $pattern, RestRoute $route ): self
```

|  | Details |
|---|---|
| **Parameters** | `$version` — The REST namespace version (`v1`, `v2`, ...)<br>`$pattern` — The URL pattern relative to the namespace, e.g. `/widgets/{id}`<br>`$route` — The route instance that handles the request |
| **Return** | `self` |
| **Throws** | — |

<br>

### `post( $version, $pattern, $route )`

Declare a POST route.

```php
public static function post( string $version, string $pattern, RestRoute $route ): self
```

|  | Details |
|---|---|
| **Parameters** | `$version` — The REST namespace version (`v1`, `v2`, ...)<br>`$pattern` — The URL pattern relative to the namespace, e.g. `/widgets`<br>`$route` — The route instance that handles the request |
| **Return** | `self` |
| **Throws** | — |

<br>

### `put( $version, $pattern, $route )`

Declare a PUT route.

```php
public static function put( string $version, string $pattern, RestRoute $route ): self
```

|  | Details |
|---|---|
| **Parameters** | `$version` — The REST namespace version (`v1`, `v2`, ...)<br>`$pattern` — The URL pattern relative to the namespace, e.g. `/widgets/{id}`<br>`$route` — The route instance that handles the request |
| **Return** | `self` |
| **Throws** | — |

Registers the method as a plain string, not `WP_REST_Server::EDITABLE`. That constant means `'POST, PUT, PATCH'`, which core uses to accept any of the three through one combined handler. Here a PUT route registers PUT alone, independent of any sibling PATCH route on the same pattern.

<br>

### `patch( $version, $pattern, $route )`

Declare a PATCH route.

```php
public static function patch( string $version, string $pattern, RestRoute $route ): self
```

|  | Details |
|---|---|
| **Parameters** | `$version` — The REST namespace version (`v1`, `v2`, ...)<br>`$pattern` — The URL pattern relative to the namespace, e.g. `/widgets/{id}`<br>`$route` — The route instance that handles the request |
| **Return** | `self` |
| **Throws** | — |

<br>

### `delete( $version, $pattern, $route )`

Declare a DELETE route.

```php
public static function delete( string $version, string $pattern, RestRoute $route ): self
```

|  | Details |
|---|---|
| **Parameters** | `$version` — The REST namespace version (`v1`, `v2`, ...)<br>`$pattern` — The URL pattern relative to the namespace, e.g. `/widgets/{id}`<br>`$route` — The route instance that handles the request |
| **Return** | `self` |
| **Throws** | — |
