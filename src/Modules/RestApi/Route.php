<?php

/**
 * REST API: Route value object
 */

declare( strict_types=1 );

namespace Zestry\WPToolkit\Modules\RestApi;

// Loaded by WordPress, never requested directly.
\defined( 'ABSPATH' ) || exit;

/**
 * Declares a route's namespace version, URL pattern, and HTTP method,
 * paired with the RestRoute instance that handles it.
 *
 * A route file returns one of these, built with the static constructor matching
 * its HTTP method, rather than a bare RestRoute instance. That makes the file
 * the single source of truth for its own URL -- nothing about where the route
 * lives comes from the file's path or name.
 *
 * RestApi discovers route files the way Ajax discovers actions and AdminPages
 * discovers pages: one file per route, in a configured directory. The directory
 * structure is purely organizational, so grouping `widgets/get-one.php` beside
 * `widgets/delete-one.php` is for a human's benefit, not RestApi's.
 *
 * ```
 * use Acme\Plugin\Core\Services\Request\Attributes\RequestArgument;
 * use Acme\Plugin\Core\Modules\RestApi\Route;
 * use Acme\Plugin\Core\Modules\RestApi\RestRoute;
 *
 * return Route::get( 'v1', '/widgets/{id}', new class extends RestRoute {
 *     #[RequestArgument(
 *         'The widget to return.',
 *         validate: [ self::class, 'is_valid_id' ],
 *         sanitize: [ self::class, 'to_id' ]
 *     )]
 *     public int $id;
 *
 *     public static function is_valid_id( $value ): bool {
 *         return is_numeric( $value );
 *     }
 *
 *     public static function to_id( $value ): int {
 *         return (int) $value;
 *     }
 *
 *     public function permission_check( WP_REST_Request $request ): bool {
 *         return true;
 *     }
 *
 *     public function handle( WP_REST_Request $request ): WP_REST_Response {
 *         return new WP_REST_Response( [ 'id' => $this->id ] );
 *     }
 *
 *     public function schema(): ?array {
 *         return null;
 *     }
 * } );
 * ```
 *
 * `{id}` in the pattern is a plain string literal here, not a filename — so,
 * unlike a folder or file name, it is never subject to WordPress.org's Plugin
 * Check restrictions on special characters. RestApi turns each `{name}` token
 * in the pattern into a named regex capture group matching any run of
 * non-slash characters (`(?P<name>[^/]+)`); a {@see RequestArgument}-attributed
 * property on the route named `name` binds to it (and can narrow the match,
 * for example to digits only, via its validate callback).
 *
 * `$version` is combined with the plugin's own slug to form WordPress's
 * actual REST namespace (`{plugin-slug}/{version}`) — the same
 * `{plugin-slug}/v1` shape {@see \Zestry\WPToolkit\Modules\Ajax\Ajax} and
 * {@see \Zestry\WPToolkit\Modules\AdminPages\AdminPages} use for their own namespacing.
 *
 * @stub route.php.stub
 */
final class Route {

	/**
	 * @param string    $method  The WordPress REST `methods` string this route registers under.
	 * @param string    $version The REST namespace version (`v1`, `v2`, ...), combined with the plugin slug.
	 * @param string    $pattern The URL pattern relative to the namespace, e.g. `/widgets/{id}`.
	 * @param RestRoute $route   The wired route instance that handles the request.
	 */
	private function __construct(
		public readonly string $method,
		public readonly string $version,
		public readonly string $pattern,
		public readonly RestRoute $route
	) {}

	/**
	 * Declare a GET route.
	 *
	 * @param string    $version The REST namespace version (`v1`, `v2`, ...).
	 * @param string    $pattern The URL pattern relative to the namespace, e.g. `/widgets/{id}`.
	 * @param RestRoute $route   The route instance that handles the request.
	 * @return self
	 */
	public static function get( string $version, string $pattern, RestRoute $route ): self {
		return new self( \WP_REST_Server::READABLE, $version, $pattern, $route );
	}

	/**
	 * Declare a POST route.
	 *
	 * @param string    $version The REST namespace version (`v1`, `v2`, ...).
	 * @param string    $pattern The URL pattern relative to the namespace, e.g. `/widgets`.
	 * @param RestRoute $route   The route instance that handles the request.
	 * @return self
	 */
	public static function post( string $version, string $pattern, RestRoute $route ): self {
		return new self( \WP_REST_Server::CREATABLE, $version, $pattern, $route );
	}

	/**
	 * Declare a PUT route.
	 *
	 * Registers the method as a plain string, not `WP_REST_Server::EDITABLE`.
	 * That constant means `'POST, PUT, PATCH'`, which core uses to accept any of
	 * the three through one combined handler. Here a PUT route registers PUT
	 * alone, independent of any sibling PATCH route on the same pattern.
	 *
	 * @param string    $version The REST namespace version (`v1`, `v2`, ...).
	 * @param string    $pattern The URL pattern relative to the namespace, e.g. `/widgets/{id}`.
	 * @param RestRoute $route   The route instance that handles the request.
	 * @return self
	 */
	public static function put( string $version, string $pattern, RestRoute $route ): self {
		return new self( 'PUT', $version, $pattern, $route );
	}

	/**
	 * Declare a PATCH route.
	 *
	 * @param string    $version The REST namespace version (`v1`, `v2`, ...).
	 * @param string    $pattern The URL pattern relative to the namespace, e.g. `/widgets/{id}`.
	 * @param RestRoute $route   The route instance that handles the request.
	 * @return self
	 */
	public static function patch( string $version, string $pattern, RestRoute $route ): self {
		return new self( 'PATCH', $version, $pattern, $route );
	}

	/**
	 * Declare a DELETE route.
	 *
	 * @param string    $version The REST namespace version (`v1`, `v2`, ...).
	 * @param string    $pattern The URL pattern relative to the namespace, e.g. `/widgets/{id}`.
	 * @param RestRoute $route   The route instance that handles the request.
	 * @return self
	 */
	public static function delete( string $version, string $pattern, RestRoute $route ): self {
		return new self( \WP_REST_Server::DELETABLE, $version, $pattern, $route );
	}
}
