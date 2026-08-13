<?php

/**
 * REST API: RestApi module
 */

declare( strict_types=1 );

namespace Zestry\WPToolkit\Modules\RestApi;

// Loaded by WordPress, never requested directly.
\defined( 'ABSPATH' ) || exit;

use Zestry\WPToolkit\Kernel\Abstracts\Module;
use Zestry\WPToolkit\Kernel\Exceptions\DiscoveryException;
use Zestry\WPToolkit\Kernel\Traits\WithFolderWalker;
use Zestry\WPToolkit\Services\Path;
use Zestry\WPToolkit\Services\Request\Attributes\RequestArgument;
use Zestry\WPToolkit\Services\Request\Request;

/**
 * Discovers plugin REST API routes and registers them with WordPress.
 *
 * A routes directory contains PHP files, one per route -- the same file-based
 * convention Ajax uses for actions and AdminPages for pages. Unlike those two,
 * the directory structure here is purely organizational.
 *
 * A route file returns a {@see Route} value, built with the static constructor
 * matching its HTTP method. It declares its own namespace version and URL
 * pattern as plain strings, so nothing about a route comes from its file's
 * name or location.
 *
 * @example A minimal route file
 * // routes/widgets/get-one.php
 * use Acme\Plugin\Core\Services\Request\Attributes\RequestArgument;
 * use Acme\Plugin\Core\Modules\RestApi\Route;
 * use Acme\Plugin\Core\Modules\RestApi\RestRoute;
 *
 * return Route::get( 'v1', '/widgets/{id}', new class extends RestRoute {
 * ```
 * #[RequestArgument( 'The widget to return.' )]
 * public int $id;
 *
 * public function permission_check( WP_REST_Request $request ): bool {
 *     return true;
 * }
 *
 * public function handle( WP_REST_Request $request ): WP_REST_Response {
 *     return new WP_REST_Response( array( 'id' => $this->id ) );
 * }
 *
 * public function schema(): ?array {
 *     return null;
 * }
 * ```
 * } );
 *
 * @example Narrowing what a route accepts
 * The property's type is already a rule -- `int $id` rejects `abc` with a 400
 * before `handle()` runs -- and `schema:` states the rest. A client can read
 * every one of those before calling, which is why they are worth preferring to
 * a callback:
 *
 * ```
 * use Acme\Plugin\Core\Services\Request\Attributes\RequestArgument;
 * use Acme\Plugin\Core\Modules\RestApi\Route;
 * use Acme\Plugin\Core\Modules\RestApi\RestRoute;
 *
 * return Route::get( 'v1', '/widgets/{id}', new class extends RestRoute {
 *     #[RequestArgument( 'The widget to return.', schema: array( 'minimum' => 1 ) )]
 *     public int $id;
 *
 *     #[RequestArgument( 'How to render it.', schema: array( 'enum' => array( 'json', 'csv' ) ) )]
 *     public string $format = 'json';
 *
 *     #[RequestArgument( 'Only widgets this account owns.', validate: array( self::class, 'is_own_account' ) )]
 *     public int $account_id = 0;
 *
 *     public static function is_own_account( $value ): bool {
 *         return 0 === $value || acme_account_belongs_to_user( $value, get_current_user_id() );
 *     }
 *
 *     // ... permission_check(), handle(), schema()
 * } );
 * ```
 *
 * `validate` is for what JSON Schema cannot say -- that a row exists, that a
 * slug is free. Returning false rejects the request with a 400 before
 * `handle()` runs.
 *
 * A check spanning two fields belongs in `handle()` rather than here: a
 * callback sees one value in isolation, while by `handle()` every property is
 * bound and an error can name the combination that was wrong.
 *
 * @example One route per HTTP method
 * A sibling `routes/widgets/delete-one.php` can declare that same
 * `/widgets/{id}` pattern under `Route::delete()`, with a stricter
 * `permission_check()` of its own. One file per HTTP method (see
 * {@see RestRoute} for why), grouped into folders however suits the plugin.
 *
 * Each `{name}` token in a pattern becomes a named regex capture group,
 * matching any run of non-slash characters. Declaring the matching property
 * with a {@see RequestArgument} is what narrows that -- to an integer, say --
 * and the module throws at registration if a token has no property to bind to.
 *
 * > [!NOTE]
 * > **A property bound to a pattern token is always required**, whatever its
 * > declaration says. WordPress rejects a request whose URL does not provide it
 * > before routing even begins. Every other argument is required only when its
 * > property has no default.
 *
 * A `{id}` token is a plain string in PHP, never a filename, so it escapes
 * WordPress.org's Plugin Check rules on special characters. Those reject any
 * file or folder name containing `[`, `]`, `(`, `)`, `{` or `}` as
 * `badly_named_files`.
 *
 * Each file's RestRoute instance handles exactly one HTTP method (see
 * {@see RestRoute} for why). The module wires it, assigning the plugin and
 * injecting typed module dependencies, then hands it to
 * {@see \Zestry\WPToolkit\Services\Request\Request} to turn its declared properties into
 * WordPress's args schema.
 *
 * `handle()` is wrapped, so each validated and sanitized request value is
 * bound onto its property before the method runs. The route's response schema
 * (see {@see RestRoute::schema()}) is published only when the route returns
 * one.
 *
 */
class RestApi extends Module {

	use WithFolderWalker;

	/**
	 * Default plugin-relative directory of route files.
	 */
	const ROUTES_ROOT = 'routes';

	/**
	 * Path module injected by the plugin to resolve the routes directory.
	 *
	 * @var Path
	 */
	public Path $path;

	/**
	 * Builds each route's args from its declared properties, and binds the
	 * values onto them.
	 *
	 * @var Request
	 */
	public Request $request;

	/**
	 * Discover route files, wire them, and register each with WordPress.
	 *
	 * Runs on `rest_api_init`, the hook WordPress itself uses to collect every
	 * route before answering a REST request or a route-discovery request (the
	 * REST index, an OPTIONS request), so — unlike admin_menu or an AJAX
	 * dispatch — this is not gated to a particular request type: it must run on
	 * every request that reaches `rest_api_init`.
	 *
	 * @return void
	 * @throws DiscoveryException When a file returns the wrong value.
	 * @throws \InvalidArgumentException When a route's placeholder-to-property binding is invalid.
	 *
	 * @internal
	 */
	public function register_routes(): void {
		$root_dir = $this->path->get_plugin_path( self::ROUTES_ROOT );

		if ( ! \is_dir( $root_dir ) ) {
			// Never named, and the default is absent: this plugin has none of
			// these yet. Only a directory asked for by name is missing in the
			// sense worth throwing over.
			return;
		}

		$files = $this->walk_folder( $root_dir, array( 'php' ), 0 );

		foreach ( $files as $file ) {
			$route_file = $root_dir . '/' . $file;
			$definition = require $route_file;

			if ( ! $definition instanceof Route ) {
				throw new DiscoveryException(
					\sprintf(
						'The file "%s" must return an instance of %s. Got: %s',
						$route_file,
						Route::class,
						\is_object( $definition ) ? $definition::class : \gettype( $definition )
					)
				);
			}

			$instance = $definition->route;
			$this->get_plugin()->wire( $instance );

			// Discovered but switched off: wired first, so is_enabled() can read an
			// injected service, then nothing about it is registered.
			if ( ! $instance->is_enabled() ) {
				continue;
			}

			$rest_namespace = $this->get_rest_namespace( $definition->version );
			$placeholders   = $this->get_placeholders( $definition->pattern );
			$pattern        = $this->get_regex_pattern( $definition->pattern );
			$this->assert_placeholders_are_bound( $route_file, $placeholders, $instance );

			$options = array(
				'methods'             => $definition->method,
				'callback'            => $this->get_handle_callback( $instance ),
				'permission_callback' => array( $instance, 'permission_check' ),
				// A URL token is always present on a matching request -- there is
				// no optional path segment -- so it is required whatever its
				// property declares. And args() states whatever the attribute
				// could not hold: a translated description, or anything else
				// this request works out rather than declares.
				'args'                => $this->request->get_rest_args( $instance, $placeholders, $instance->args() ),
			);

			// Only publish a schema callback when the route explicitly returns one
			// from schema(); null means "not published", not "empty schema".
			$schema = $instance->schema();
			if ( null !== $schema ) {
				$options['schema'] = static function () use ( $schema ) {
					return $schema;
				};
			}

			// The return is deliberately not checked: every refusal
			// `register_rest_route()` can make calls `_doing_it_wrong()` first,
			// so WordPress has already reported it.
			\register_rest_route( $rest_namespace, $pattern, $options );
		}
	}

	/**
	 * Build the REST namespace for a version, namespaced under the plugin slug.
	 *
	 * The single source of truth for the `{plugin-slug}/{version}` shape that
	 * `register_routes()` registers under, matching the accessor every other
	 * namespacing module exposes ({@see \Zestry\WPToolkit\Modules\Ajax\Ajax::get_action_slug()},
	 * `Cron::get_schedule_slug()`, `AdminPages::get_page_slug()`,
	 * `Assets::get_asset_slug()`). Ask for it rather than hardcoding the slug in
	 * a JS `fetch()` or a `rest_url()` call and reproducing the join.
	 *
	 * @param string $version The route's own namespace version, e.g. `'v1'`.
	 * @return string The full REST namespace, e.g. `'acme-plugin/v1'`.
	 */
	public function get_rest_namespace( string $version ): string {
		return $this->get_plugin()->get_namespaced_name( $version, '/' );
	}

	/**
	 * Build the full URL for a route, the counterpart to `Ajax::get_action_url()`.
	 *
	 * `$pattern` is the route's own pattern exactly as the route file declares
	 * it. Placeholder tokens are not substituted -- a caller that needs a
	 * concrete URL replaces them itself, since only the caller knows the values:
	 *
	 * ```
	 * $api->get_route_url( 'v1', '/widgets/42' );
	 * ```
	 *
	 * @param string              $version The route's namespace version, e.g. `'v1'`.
	 * @param string              $pattern The route path, e.g. `'/widgets/42'`.
	 * @param array<string,mixed> $args    Optional query arguments.
	 * @return string The full REST URL.
	 */
	public function get_route_url( string $version, string $pattern, array $args = array() ): string {
		$url = \rest_url( $this->get_rest_namespace( $version ) . '/' . \ltrim( $pattern, '/' ) );

		return empty( $args ) ? $url : \add_query_arg( $args, $url );
	}

	/**
	 * Register the WordPress rest_api_init hook.
	 *
	 * @return void
	 *
	 * @internal
	 */
	protected function on_boot(): void {
		\add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Find every `{name}` token in a route pattern.
	 *
	 * @param string $pattern The URL pattern as declared in the route file, e.g. `/widgets/{id}`.
	 * @return string[] The placeholder names found, e.g. `['id']`.
	 */
	private function get_placeholders( string $pattern ): array {
		\preg_match_all( '/\{(?P<name>[a-zA-Z][a-zA-Z0-9_]*)\}/', $pattern, $matches );

		return $matches['name'];
	}

	/**
	 * Translate a route pattern's `{name}` tokens into named regex capture
	 * groups.
	 *
	 * Each token becomes a group matching any run of non-slash characters by
	 * default (`(?P<name>[^/]+)`), covering both a numeric ID and a string
	 * slug without requiring a {@see RequestArgument} override for the common
	 * case; a bound property narrows the match (for example to `\d+`) when a
	 * route needs to.
	 *
	 * @param string $pattern The URL pattern as declared in the route file, e.g. `/widgets/{id}`.
	 * @return string The regex pattern `register_rest_route()` expects, e.g. `/widgets/(?P<id>[^/]+)`.
	 */
	private function get_regex_pattern( string $pattern ): string {
		return (string) \preg_replace( '/\{([a-zA-Z][a-zA-Z0-9_]*)\}/', '(?P<$1>[^/]+)', $pattern );
	}

	/**
	 * Refuse a route whose pattern names something no property binds.
	 *
	 * A `{token}` with no matching property is not a smaller route, it is a
	 * broken one: WordPress captures the value and nothing reads it, so the
	 * route silently answers every URL the same way.
	 *
	 * @param string    $route_file   Absolute path to the route file, for the message.
	 * @param string[]  $placeholders The pattern's tokens.
	 * @param RestRoute $route        The wired route instance.
	 * @return void
	 * @throws \InvalidArgumentException When a token has no property to bind to.
	 */
	private function assert_placeholders_are_bound( string $route_file, array $placeholders, RestRoute $route ): void {
		$unbound = \array_diff( $placeholders, \array_keys( $this->request->get_arguments( $route ) ) );

		if ( array() === $unbound ) {
			return;
		}

		throw new \InvalidArgumentException(
			\sprintf(
				'The file "%s" has a pattern placeholder with no matching #[%s] property: %s.',
				$route_file,
				RequestArgument::class,
				\implode( ', ', $unbound )
			)
		);
	}

	/**
	 * Build the callback WordPress invokes for this route: bind each declared
	 * property from the (already validated and sanitized) request, then call the
	 * route's own handle().
	 *
	 * Binding happens here, inside the per-request callback, rather than at
	 * registration time in register_routes(): register_routes() runs once, at
	 * rest_api_init, before any specific request exists to read values from,
	 * while this callback runs once per matching request, after WordPress has
	 * validated and sanitized its parameters against the args built above.
	 *
	 * @param RestRoute $route The wired route instance.
	 * @return callable(\WP_REST_Request): (\WP_REST_Response|\WP_Error) The callback to register.
	 */
	private function get_handle_callback( RestRoute $route ): callable {
		return function ( \WP_REST_Request $request ) use ( $route ) {
			$values = array();

			foreach ( \array_keys( $this->request->get_arguments( $route ) ) as $name ) {
				$value = $request->get_param( $name );

				if ( null !== $value ) {
					$values[ $name ] = $value;
				}
			}

			/*
			 * Uploads are not parameters: WordPress leaves them out of a request's
			 * parameter order entirely, so nothing above finds them and no arg
			 * declared for one could have been validated. They are read from
			 * their own place, and their presence is checked here because
			 * WordPress cannot check it there.
			 */
			$files = $request->get_file_params();

			foreach ( $this->request->get_file_arguments( $route ) as $name => $is_required ) {
				if ( isset( $files[ $name ] ) ) {
					$values[ $name ] = $files[ $name ];
					continue;
				}

				if ( $is_required ) {
					return new \WP_Error(
						'rest_missing_callback_param',
						\sprintf(
							/* translators: %s: file parameter name. */
							\__( 'Missing file parameter: %s', 'zestry-toolkit' ),
							$name
						),
						array(
							'status' => 400,
							'params' => array( $name ),
						)
					);
				}
			}

			$this->request->bind( $route, $values );

			return $route->handle( $request );
		};
	}
}
