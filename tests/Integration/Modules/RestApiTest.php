<?php

declare( strict_types=1 );

namespace Zestry\WPToolkit\Tests\Integration\Modules;

use Zestry\WPToolkit\Kernel\Exceptions\DiscoveryException;
use Zestry\WPToolkit\Modules\RestApi\RestApi;
use Zestry\WPToolkit\Tests\Support\TestCase;
use WP_REST_Request;
use WP_REST_Server;

/**
 * Route discovery/registration, pattern-to-regex translation, dispatch, and
 * RequestArgument binding.
 *
 * @covers \Zestry\WPToolkit\Modules\RestApi\RestApi
 * @covers \Zestry\WPToolkit\Modules\RestApi\Route
 * @covers \Zestry\WPToolkit\Modules\Request\Request
 */
final class RestApiTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		mkdir( $this->plugin_dir . '/routes', 0777, true );

		// A fresh REST server per test, mirroring how WordPress itself resets it
		// on the rest_api_init hook for each request.
		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();
	}

	public function test_a_route_registers_under_the_plugin_slug_and_version_namespace(): void {
		// An ordinary, unattributed property alongside the route's methods: it
		// is caller-owned state RestApi must not touch, not a bound parameter.
		$this->write_route(
			'get',
			'widgets',
			'v1',
			'/widgets',
			"public string \$note = 'unbound';\n" . $this->open_get()
		);

		$this->boot_and_discover();

		$this->assertArrayHasKey( '/zestry-test/v1/widgets', $GLOBALS['wp_rest_server']->get_routes() );
	}

	public function test_a_pattern_token_becomes_a_non_slash_capture_group(): void {
		$this->write_route( 'get', 'widgets', 'v1', '/widgets/{id}', $this->bound_id() . $this->open_get() );

		$this->boot_and_discover();

		$this->assertArrayHasKey( '/zestry-test/v1/widgets/(?P<id>[^/]+)', $GLOBALS['wp_rest_server']->get_routes() );
	}

	public function test_sibling_verb_files_register_independent_methods_on_the_same_pattern(): void {
		$this->write_route( 'get', 'widgets-get', 'v1', '/widgets/{id}', $this->bound_id() . $this->open_get() );
		$this->write_route(
			'delete',
			'widgets-delete',
			'v1',
			'/widgets/{id}',
			$this->bound_id()
				. "public function permission_check( WP_REST_Request \$request ): bool { return current_user_can( 'manage_options' ); }\n"
				. "public function handle( WP_REST_Request \$request ): WP_REST_Response { return new WP_REST_Response( null, 204 ); }\n"
				. 'public function schema(): ?array { return null; }'
		);

		$this->boot_and_discover();

		$methods = array();
		foreach ( $GLOBALS['wp_rest_server']->get_routes()['/zestry-test/v1/widgets/(?P<id>[^/]+)'] as $handler ) {
			$methods += $handler['methods'];
		}

		$this->assertArrayHasKey( 'GET', $methods );
		$this->assertArrayHasKey( 'DELETE', $methods );
	}

	public function test_get_dispatches_to_handle_when_permission_check_allows(): void {
		$this->write_route(
			'get',
			'widgets',
			'v1',
			'/widgets',
			"public function permission_check( WP_REST_Request \$request ): bool { return true; }\n"
				. "public function handle( WP_REST_Request \$request ): WP_REST_Response { return new WP_REST_Response( [ 'widgets' => [] ] ); }\n"
				. 'public function schema(): ?array { return null; }'
		);
		$this->boot_and_discover();

		$request  = new WP_REST_Request( 'GET', '/zestry-test/v1/widgets' );
		$response = $GLOBALS['wp_rest_server']->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( 'widgets' => array() ), $response->get_data() );
	}

	public function test_permission_check_rejecting_short_circuits_before_handle(): void {
		$this->write_route(
			'get',
			'widgets',
			'v1',
			'/widgets',
			"public function permission_check( WP_REST_Request \$request ): bool { return false; }\n"
				. "public function handle( WP_REST_Request \$request ): WP_REST_Response { \$GLOBALS['zestry_handle_ran'] = true; return new WP_REST_Response( [] ); }\n"
				. 'public function schema(): ?array { return null; }'
		);
		$this->boot_and_discover();

		$GLOBALS['zestry_handle_ran'] = false;
		$request                   = new WP_REST_Request( 'GET', '/zestry-test/v1/widgets' );
		$response                  = $GLOBALS['wp_rest_server']->dispatch( $request );

		// WordPress itself picks 401 vs 403 based on login state (rest_authorization_required_code());
		// what this module is responsible for is rejecting the request at all, before handle() runs.
		$this->assertGreaterThanOrEqual( 400, $response->get_status() );
		$this->assertFalse( $GLOBALS['zestry_handle_ran'], 'handle() did not run when permission_check() rejected.' );
		unset( $GLOBALS['zestry_handle_ran'] );
	}

	public function test_a_rest_argument_property_is_bound_from_the_request_before_handle_runs(): void {
		$this->write_route(
			'get',
			'widgets',
			'v1',
			'/widgets/{id}',
			"#[RequestArgument( 'The widget to return.' )]\npublic int \$id;\n"
				. "public function permission_check( WP_REST_Request \$request ): bool { return true; }\n"
				. "public function handle( WP_REST_Request \$request ): WP_REST_Response { return new WP_REST_Response( [ 'id' => \$this->id ] ); }\n"
				. 'public function schema(): ?array { return null; }'
		);
		$this->boot_and_discover();

		$request  = new WP_REST_Request( 'GET', '/zestry-test/v1/widgets/42' );
		$response = $GLOBALS['wp_rest_server']->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( 'id' => 42 ), $response->get_data(), 'The declared type cast it, and the value was bound.' );
	}

	/**
	 * The published example is `#[RequestArgument( ..., sanitize: 'intval' )]`, and
	 * a bare builtin is the case the arity adapter exists for.
	 *
	 * `intval` is declared `intval( $value, $base = 10 )`, so forwarding its
	 * *declared* count hands the request to `$base` and fatals at
	 * `rest_api_init` -- taking down every route, not just this one. The route
	 * above cannot catch that: its callbacks are class methods whose declared
	 * and required arity agree.
	 */
	public function test_a_bare_builtin_sanitizer_is_called_with_the_value_alone(): void {
		$this->write_route(
			'get',
			'widgets',
			'v1',
			'/widgets/{id}',
			"#[RequestArgument( 'A widget id.', sanitize: 'intval' )]\npublic int \$id;\n"
				. "public function permission_check( WP_REST_Request \$request ): bool { return true; }\n"
				. "public function handle( WP_REST_Request \$request ): WP_REST_Response { return new WP_REST_Response( [ 'id' => \$this->id ] ); }\n"
				. 'public function schema(): ?array { return null; }'
		);
		$this->boot_and_discover();

		$request  = new WP_REST_Request( 'GET', '/zestry-test/v1/widgets/42' );
		$response = $GLOBALS['wp_rest_server']->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( 'id' => 42 ), $response->get_data() );
	}

	/**
	 * A callback declaring an optional second parameter is the quiet version of
	 * the same defect: `trim` would receive the request as its character list
	 * and return a wrong value rather than throwing.
	 */
	public function test_a_builtin_with_an_optional_second_parameter_gets_only_the_value(): void {
		$this->write_route(
			'get',
			'widgets',
			'v1',
			'/widgets/{name}',
			"#[RequestArgument( 'A widget name.', sanitize: 'trim' )]\npublic string \$name;\n"
				. "public function permission_check( WP_REST_Request \$request ): bool { return true; }\n"
				. "public function handle( WP_REST_Request \$request ): WP_REST_Response { return new WP_REST_Response( [ 'name' => \$this->name ] ); }\n"
				. 'public function schema(): ?array { return null; }'
		);
		$this->boot_and_discover();

		$request  = new WP_REST_Request( 'GET', '/zestry-test/v1/widgets/hello' );
		$response = $GLOBALS['wp_rest_server']->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( 'name' => 'hello' ), $response->get_data() );
	}

	/**
	 * A callback declaring more parameters than it is given fails rather than
	 * silently receiving something unexpected: everything is called with the
	 * value alone, so a signature promising a request is a signature that lies.
	 * Every WordPress validator and sanitizer requires exactly one.
	 */
	public function test_a_callback_expecting_a_second_argument_fails_loudly(): void {
		$this->write_route(
			'get',
			'widgets',
			'v1',
			'/widgets/{id}',
			"#[RequestArgument( 'A widget id.', validate: [ self::class, 'needs_two' ] )]\npublic int \$id;\n"
				. "public static function needs_two( \$value, \$request ): bool { return true; }\n"
				. "public function permission_check( WP_REST_Request \$request ): bool { return true; }\n"
				. "public function handle( WP_REST_Request \$request ): WP_REST_Response { return new WP_REST_Response( [ 'id' => \$this->id ] ); }\n"
				. 'public function schema(): ?array { return null; }'
		);
		$this->boot_and_discover();

		// Still loud, and now it says which argument declared the callback that
		// promised an argument nothing passes it.
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'The validate callback for "id"' );

		$request = new WP_REST_Request( 'GET', '/zestry-test/v1/widgets/42' );
		$GLOBALS['wp_rest_server']->dispatch( $request );
	}

	public function test_a_rest_argument_validate_callback_rejects_an_invalid_value_before_handle_runs(): void {
		$this->write_route(
			'get',
			'widgets',
			'v1',
			'/widgets/{id}',
			"#[RequestArgument( 'An even widget id.', validate: [ self::class, 'is_even' ] )]\npublic int \$id;\n"
				. "public static function is_even( \$value ): bool { return 0 === \$value % 2; }\n"
				. "public function permission_check( WP_REST_Request \$request ): bool { return true; }\n"
				. "public function handle( WP_REST_Request \$request ): WP_REST_Response { \$GLOBALS['zestry_handle_ran'] = true; return new WP_REST_Response( [ 'id' => \$this->id ] ); }\n"
				. 'public function schema(): ?array { return null; }'
		);
		$this->boot_and_discover();

		$GLOBALS['zestry_handle_ran'] = false;
		$request                   = new WP_REST_Request( 'GET', '/zestry-test/v1/widgets/43' );
		$response                  = $GLOBALS['wp_rest_server']->dispatch( $request );

		$this->assertSame( 400, $response->get_status() );
		$this->assertFalse( $GLOBALS['zestry_handle_ran'], 'handle() did not run when validate_callback rejected the value.' );
		unset( $GLOBALS['zestry_handle_ran'] );
	}

	public function test_an_argument_with_no_default_rejects_a_missing_value_before_handle_runs(): void {
		$this->write_route(
			'post',
			'widgets',
			'v1',
			'/widgets',
			"#[RequestArgument( 'The widget name.' )]\npublic string \$name;\n"
				. "public function permission_check( WP_REST_Request \$request ): bool { return true; }\n"
				. "public function handle( WP_REST_Request \$request ): WP_REST_Response { \$GLOBALS['zestry_handle_ran'] = true; return new WP_REST_Response( [] ); }\n"
				. 'public function schema(): ?array { return null; }'
		);
		$this->boot_and_discover();

		$GLOBALS['zestry_handle_ran'] = false;
		$request                   = new WP_REST_Request( 'POST', '/zestry-test/v1/widgets' );
		$response                  = $GLOBALS['wp_rest_server']->dispatch( $request );

		$this->assertSame( 400, $response->get_status() );
		$this->assertFalse( $GLOBALS['zestry_handle_ran'], 'handle() did not run when a required argument was missing.' );
		unset( $GLOBALS['zestry_handle_ran'] );
	}

	public function test_a_property_bound_to_a_pattern_token_is_required_even_with_a_default(): void {
		// A URL token is required whatever the property declares.
		$this->write_route( 'get', 'widgets', 'v1', '/widgets/{id}', $this->bound_id() . $this->open_get() );
		$this->write_route(
			'post',
			'gizmos',
			'v1',
			'/gizmos',
			"#[RequestArgument( 'A gizmo name.' )]\npublic string \$name = '';\n" . $this->open_get()
		);

		$this->boot_and_discover();

		$routes = $GLOBALS['wp_rest_server']->get_routes();

		$this->assertTrue(
			$routes['/zestry-test/v1/widgets/(?P<id>[^/]+)'][0]['args']['id']['required'],
			'a property bound to a {id} pattern token is required even though it has a default.'
		);
		$this->assertArrayNotHasKey(
			'required',
			$routes['/zestry-test/v1/gizmos'][0]['args']['name'],
			'a body-bound property with a default is not marked required.'
		);
	}

	/**
	 * `__()` cannot go inside an attribute, and a route's descriptions are
	 * published — a client reads them back with an `OPTIONS` request. args() is
	 * where that sentence gets finished, and stating one part of an argument must
	 * leave the rest of the declaration standing.
	 */
	public function test_args_states_what_the_attribute_could_not_hold(): void {
		$this->write_route(
			'get',
			'widgets',
			'v1',
			'/widgets/{id}',
			"#[RequestArgument( 'The widget to return.' )]\npublic int \$id;\n"
				. "#[RequestArgument( 'How to sort.', schema: array( 'enum' => array( 'date', 'title', 'slug' ) ) )]\n"
				. "public string \$order_by = 'date';\n"
				. "public function args(): array {\n"
				. "    return array(\n"
				. "        'id'       => array( 'description' => 'Widgetul cerut.' ),\n"
				. "        'order_by' => array( 'enum' => array( 'date' ) ),\n"
				. "    );\n"
				. "}\n"
				. $this->open_get_returning_id()
		);
		$this->boot_and_discover();

		$args = $GLOBALS['wp_rest_server']->get_routes()['/zestry-test/v1/widgets/(?P<id>[^/]+)'][0]['args'];

		$this->assertSame( 'Widgetul cerut.', $args['id']['description'] );
		$this->assertSame( 'integer', $args['id']['type'], 'The declaration still says the type.' );
		$this->assertTrue( $args['id']['required'], 'And a URL token is still required.' );
		$this->assertSame( array( 'date' ), $args['order_by']['enum'], 'A list is taken whole.' );
		$this->assertSame( 'date', $args['order_by']['default'], 'The rest of that argument is left alone.' );

		$request  = new WP_REST_Request( 'GET', '/zestry-test/v1/widgets/42' );
		$response = $GLOBALS['wp_rest_server']->dispatch( $request );

		$this->assertSame( array( 'id' => 42 ), $response->get_data(), 'And the value still binds.' );
	}

	/**
	 * A name args() describes but no property declares is still a parameter
	 * WordPress validates — it simply binds nowhere, so the route reads it off
	 * the request.
	 */
	public function test_args_may_describe_a_parameter_no_property_declares(): void {
		$this->write_route(
			'get',
			'gizmos',
			'v1',
			'/gizmos',
			"public function args(): array { return array( 'page' => array( 'type' => 'integer', 'minimum' => 1 ) ); }\n"
				. "public function permission_check( WP_REST_Request \$request ): bool { return true; }\n"
				. "public function handle( WP_REST_Request \$request ): WP_REST_Response { return new WP_REST_Response( [ 'page' => \$request->get_param( 'page' ) ] ); }\n"
				. 'public function schema(): ?array { return null; }'
		);
		$this->boot_and_discover();

		$this->assertSame(
			1,
			$GLOBALS['wp_rest_server']->get_routes()['/zestry-test/v1/gizmos'][0]['args']['page']['minimum']
		);

		$request = new WP_REST_Request( 'GET', '/zestry-test/v1/gizmos' );
		$request->set_query_params( array( 'page' => '0' ) );

		$this->assertGreaterThanOrEqual(
			400,
			$GLOBALS['wp_rest_server']->dispatch( $request )->get_status(),
			'WordPress enforces it like any other arg.'
		);
	}

	public function test_a_string_builtin_callable_is_passed_directly_without_argument_count_error(): void {
		// 'is_numeric' and 'absint' each declare exactly one parameter --
		// RestApi must forward only the value, not WordPress's full 3-tuple.
		$this->write_route(
			'get',
			'widgets',
			'v1',
			'/widgets/{id}',
			"#[RequestArgument( 'A widget id.', validate: 'is_numeric', sanitize: 'absint' )]\npublic int \$id;\n" . $this->open_get_returning_id()
		);
		$this->boot_and_discover();

		$request  = new WP_REST_Request( 'GET', '/zestry-test/v1/widgets/42' );
		$response = $GLOBALS['wp_rest_server']->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( 'id' => 42 ), $response->get_data() );
	}

	public function test_an_uploaded_file_is_bound_from_the_request(): void {
		$this->write_route(
			'post',
			'uploads',
			'v1',
			'/uploads',
			"#[RequestArgument( 'The image to attach.' )]\npublic \\Zestry\\WPToolkit\\Modules\\Request\\UploadedFile \$image;\n"
				. "public function permission_check( WP_REST_Request \$request ): bool { return true; }\n"
				. "public function handle( WP_REST_Request \$request ): WP_REST_Response { return new WP_REST_Response( [ 'name' => \$this->image->name, 'ok' => \$this->image->is_ok() ] ); }\n"
				. 'public function schema(): ?array { return null; }'
		);
		$this->boot_and_discover();

		$request = new WP_REST_Request( 'POST', '/zestry-test/v1/uploads' );
		$request->set_file_params(
			array(
				'image' => array(
					'name'     => 'logo.png',
					'type'     => 'image/png',
					'tmp_name' => '/tmp/php123',
					'error'    => UPLOAD_ERR_OK,
					'size'     => 1024,
				),
			)
		);

		$response = $GLOBALS['wp_rest_server']->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( 'name' => 'logo.png', 'ok' => true ), $response->get_data() );
	}

	/**
	 * WordPress cannot check this one: an upload is not a parameter, so nothing
	 * in `args` could have declared it required.
	 */
	public function test_a_missing_required_file_is_refused_before_handle_runs(): void {
		$this->write_route(
			'post',
			'uploads',
			'v1',
			'/uploads',
			"#[RequestArgument( 'The image to attach.' )]\npublic \\Zestry\\WPToolkit\\Modules\\Request\\UploadedFile \$image;\n"
				. "public function permission_check( WP_REST_Request \$request ): bool { return true; }\n"
				. "public function handle( WP_REST_Request \$request ): WP_REST_Response { \$GLOBALS['zestry_handle_ran'] = true; return new WP_REST_Response( [] ); }\n"
				. 'public function schema(): ?array { return null; }'
		);
		$this->boot_and_discover();

		$GLOBALS['zestry_handle_ran'] = false;
		$response                  = $GLOBALS['wp_rest_server']->dispatch( new WP_REST_Request( 'POST', '/zestry-test/v1/uploads' ) );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'rest_missing_callback_param', $response->get_data()['code'] );
		$this->assertFalse( $GLOBALS['zestry_handle_ran'] );
		unset( $GLOBALS['zestry_handle_ran'] );
	}

	/**
	 * A `schema:` key is not decoration: WordPress enforces every keyword it
	 * recognises, and a pattern is checked before handle() runs.
	 */
	public function test_a_schema_pattern_is_enforced_on_a_route(): void {
		$this->write_route(
			'get',
			'shipping',
			'v1',
			'/shipping',
			"#[RequestArgument( 'Two-letter country code.', schema: array( 'pattern' => '^[A-Z]{2}\$' ) )]\n"
				. "public string \$country = 'GB';\n"
				. "public function permission_check( WP_REST_Request \$request ): bool { return true; }\n"
				. "public function handle( WP_REST_Request \$request ): WP_REST_Response { \$GLOBALS['zestry_handle_ran'] = true; return new WP_REST_Response( [ 'country' => \$this->country ] ); }\n"
				. 'public function schema(): ?array { return null; }'
		);
		$this->boot_and_discover();

		$this->assertSame(
			'^[A-Z]{2}$',
			$GLOBALS['wp_rest_server']->get_routes()['/zestry-test/v1/shipping'][0]['args']['country']['pattern'],
			'The pattern reaches the published args, so a client can read it.'
		);

		$GLOBALS['zestry_handle_ran'] = false;

		$refused = new WP_REST_Request( 'GET', '/zestry-test/v1/shipping' );
		$refused->set_param( 'country', 'gb' );
		$response = $GLOBALS['wp_rest_server']->dispatch( $refused );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'rest_invalid_param', $response->get_data()['code'] );
		$this->assertFalse( $GLOBALS['zestry_handle_ran'], 'handle() did not run.' );

		$accepted = new WP_REST_Request( 'GET', '/zestry-test/v1/shipping' );
		$accepted->set_param( 'country', 'FR' );

		$this->assertSame( array( 'country' => 'FR' ), $GLOBALS['wp_rest_server']->dispatch( $accepted )->get_data() );

		unset( $GLOBALS['zestry_handle_ran'] );
	}

	/**
	 * The schema is still checked when an argument declares only a sanitizer,
	 * which is the case where declaring one takes the check away.
	 */
	public function test_a_schema_is_still_enforced_alongside_a_sanitize_callback(): void {
		$this->write_route(
			'get',
			'shipping',
			'v1',
			'/shipping',
			"#[RequestArgument( 'Country code.', sanitize: 'strtoupper', schema: array( 'pattern' => '^[A-Za-z]{2}\$' ) )]\n"
				. "public string \$country = 'GB';\n"
				. "public function permission_check( WP_REST_Request \$request ): bool { return true; }\n"
				. "public function handle( WP_REST_Request \$request ): WP_REST_Response { return new WP_REST_Response( [ 'country' => \$this->country ] ); }\n"
				. 'public function schema(): ?array { return null; }'
		);
		$this->boot_and_discover();

		$refused = new WP_REST_Request( 'GET', '/zestry-test/v1/shipping' );
		$refused->set_param( 'country', 'britain' );

		$this->assertSame( 400, $GLOBALS['wp_rest_server']->dispatch( $refused )->get_status() );

		$accepted = new WP_REST_Request( 'GET', '/zestry-test/v1/shipping' );
		$accepted->set_param( 'country', 'fr' );

		$this->assertSame(
			array( 'country' => 'FR' ),
			$GLOBALS['wp_rest_server']->dispatch( $accepted )->get_data(),
			'And the sanitizer still ran.'
		);
	}

	public function test_an_open_object_argument_is_bound_from_a_json_body(): void {
		$this->write_route(
			'post',
			'settings',
			'v1',
			'/settings',
			"#[RequestArgument( 'Whatever the caller keeps here.' )]\npublic \\stdClass \$params;\n"
				. "public function permission_check( WP_REST_Request \$request ): bool { return true; }\n"
				. "public function handle( WP_REST_Request \$request ): WP_REST_Response { return new WP_REST_Response( [ 'colour' => \$this->params->colour, 'deep' => \$this->params->nested->level ] ); }\n"
				. 'public function schema(): ?array { return null; }'
		);
		$this->boot_and_discover();

		$this->assertSame(
			'object',
			$GLOBALS['wp_rest_server']->get_routes()['/zestry-test/v1/settings'][0]['args']['params']['type']
		);

		$request = new WP_REST_Request( 'POST', '/zestry-test/v1/settings' );
		$request->set_body_params(
			array(
				'params' => array(
					'colour' => 'blue',
					'nested' => array( 'level' => 'deep' ),
				),
			)
		);

		$response = $GLOBALS['wp_rest_server']->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( 'colour' => 'blue', 'deep' => 'deep' ), $response->get_data() );
	}

	public function test_schema_is_published_only_when_the_route_returns_one(): void {
		$this->write_route( 'get', 'widgets', 'v1', '/widgets', $this->open_get() );
		$this->write_route(
			'get',
			'gizmos',
			'v1',
			'/gizmos',
			"public function permission_check( WP_REST_Request \$request ): bool { return true; }\n"
				. "public function handle( WP_REST_Request \$request ): WP_REST_Response { return new WP_REST_Response( [] ); }\n"
				. "public function schema(): ?array { return [ 'title' => 'gizmo', 'type' => 'object' ]; }"
		);

		$this->boot_and_discover();

		$routes = $GLOBALS['wp_rest_server']->get_routes();

		$this->assertArrayNotHasKey( 'schema', $routes['/zestry-test/v1/widgets'][0] );
		$this->assertSame(
			array(
				'title' => 'gizmo',
				'type'  => 'object',
			),
			( $routes['/zestry-test/v1/gizmos'][0]['schema'] )()
		);
	}

	public function test_two_versions_can_share_the_same_pattern(): void {
		// The version comes entirely from the Route:: call, not the folder --
		// two files can declare the same URL under different namespace versions.
		$this->write_route( 'get', 'widgets-v1', 'v1', '/widgets', $this->open_get() );
		$this->write_route( 'get', 'widgets-v2', 'v2', '/widgets', $this->open_get() );

		$this->boot_and_discover();

		$routes = $GLOBALS['wp_rest_server']->get_routes();
		$this->assertArrayHasKey( '/zestry-test/v1/widgets', $routes );
		$this->assertArrayHasKey( '/zestry-test/v2/widgets', $routes );
	}

	/**
	 * The namespace accessor every other namespacing module already exposes.
	 *
	 * Without it the `{plugin-slug}/{version}` join was built inline inside
	 * register_routes(), so a consumer writing a JS fetch() or a rest_url()
	 * call had to hardcode the slug and reproduce the join by hand.
	 */
	public function test_get_rest_namespace_prefixes_the_version_with_the_plugin_slug(): void {
		$rest_api = $this->plugin->get( RestApi::class );

		$this->assertSame( 'zestry-test/v1', $rest_api->get_rest_namespace( 'v1' ) );
		$this->assertSame( 'zestry-test/v2', $rest_api->get_rest_namespace( 'v2' ) );
	}

	public function test_get_route_url_builds_a_full_url_through_the_namespace(): void {
		$rest_api = $this->plugin->get( RestApi::class );

		$url = $rest_api->get_route_url( 'v1', '/widgets/42' );

		$this->assertStringContainsString( 'zestry-test/v1/widgets/42', $url );
		$this->assertSame(
			$url,
			$rest_api->get_route_url( 'v1', 'widgets/42' ),
			'A leading slash on the pattern is optional.'
		);

		$with_args = $rest_api->get_route_url( 'v1', '/widgets', array( 'per_page' => 5 ) );
		$this->assertStringContainsString( 'per_page=5', $with_args );
	}

	/**
	 * The registered route must use the same namespace the accessor reports,
	 * so the accessor cannot drift from what register_routes() actually
	 * registers.
	 */
	public function test_registered_namespace_matches_the_accessor(): void {
		$this->write_route( 'get', 'widgets', 'v1', '/widgets', $this->open_get() );

		$rest_api = $this->plugin->get( RestApi::class );
		do_action( 'rest_api_init', $GLOBALS['wp_rest_server'] );

		$routes = rest_get_server()->get_routes();
		$this->assertArrayHasKey( '/' . $rest_api->get_rest_namespace( 'v1' ) . '/widgets', $routes );
	}

	public function test_a_route_file_returning_the_wrong_type_throws(): void {
		$this->write_plugin_file( 'routes/bad.php', "<?php\nreturn 42;\n" );
		$this->plugin->get( RestApi::class );

		$this->expectException( DiscoveryException::class );
		$this->expectExceptionMessage( 'must return an instance of' );
		do_action( 'rest_api_init', $GLOBALS['wp_rest_server'] );
	}

	public function test_an_unbound_placeholder_throws(): void {
		// {id} appears in the pattern, but no #[RequestArgument] property named
		// "id" binds to it -- the value would silently go nowhere.
		$this->write_route( 'get', 'widgets', 'v1', '/widgets/{id}', $this->open_get() );
		$this->plugin->get( RestApi::class );

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'no matching' );
		do_action( 'rest_api_init', $GLOBALS['wp_rest_server'] );
	}

	/**
	 * Resolve the module (running its on_boot(), which attaches the
	 * rest_api_init listener) and then fire that hook to trigger discovery.
	 *
	 * @return RestApi
	 */
	private function boot_and_discover(): RestApi {
		$rest_api = $this->plugin->get( RestApi::class );
		do_action( 'rest_api_init', $GLOBALS['wp_rest_server'] );

		return $rest_api;
	}

	/**
	 * Write a route file returning a Route value wrapping an anonymous
	 * RestRoute subclass.
	 *
	 * @param string $http_method    One of get/post/put/patch/delete -- the Route:: static constructor to use.
	 * @param string $relative_name  Route file name relative to routes/, without extension (organizational only).
	 * @param string $version        The REST namespace version passed to Route::.
	 * @param string $pattern        The URL pattern passed to Route::.
	 * @param string $class_body     PHP body of the anonymous RestRoute subclass.
	 * @return void
	 */
	private function write_route( string $http_method, string $relative_name, string $version, string $pattern, string $class_body ): void {
		$this->write_plugin_file(
			"routes/{$relative_name}.php",
			// WP_REST_Request/WP_REST_Response are already global names, so a "use"
			// for them here would be a no-op PHP warns about; only the toolkit's
			// own classes need one.
			"<?php\nuse Zestry\\WPToolkit\\Modules\\RestApi\\Route;\nuse Zestry\\WPToolkit\\Modules\\RestApi\\RestRoute;\n"
				. "use Zestry\\WPToolkit\\Modules\\Request\\Attributes\\RequestArgument;\n"
				. "return Route::{$http_method}( '{$version}', '{$pattern}', new class extends RestRoute {\n{$class_body}\n} );\n"
		);
	}

	private function open_get(): string {
		return "public function permission_check( WP_REST_Request \$request ): bool { return true; }\n"
			. "public function handle( WP_REST_Request \$request ): WP_REST_Response { return new WP_REST_Response( [ 'ok' => true ] ); }\n"
			. 'public function schema(): ?array { return null; }';
	}

	private function open_get_returning_id(): string {
		return "public function permission_check( WP_REST_Request \$request ): bool { return true; }\n"
			. "public function handle( WP_REST_Request \$request ): WP_REST_Response { return new WP_REST_Response( [ 'id' => \$this->id ] ); }\n"
			. 'public function schema(): ?array { return null; }';
	}

	/**
	 * An `$id` argument carrying a default, so that anything asserting it is
	 * required is asserting the URL-token rule rather than the property's own.
	 *
	 * @return string
	 */
	private function bound_id(): string {
		return "#[RequestArgument( 'A widget id.' )]\npublic string \$id = '';\n";
	}

	/**
	 * The default is core's own code, unprefixed: a client written to handle
	 * `rest_forbidden` generically keeps working against a route built here.
	 * Prefixing it with the plugin slug would have broken exactly that.
	 */
	public function test_a_refusal_defaults_to_cores_own_forbidden_code(): void {
		$this->write_route(
			'get',
			'reports',
			'v1',
			'/reports',
			"public function permission_check( WP_REST_Request \$request ): bool|\\WP_Error { return \$this->deny( 'Nope.' ); }\n"
				. "public function handle( WP_REST_Request \$request ): WP_REST_Response { return new WP_REST_Response( [] ); }\n"
				. 'public function schema(): ?array { return null; }'
		);
		$this->boot_and_discover();

		$response = $GLOBALS['wp_rest_server']->dispatch( new WP_REST_Request( 'GET', '/zestry-test/v1/reports' ) );

		$this->assertSame( 'rest_forbidden', $response->get_data()['code'] );
		$this->assertSame( 'Nope.', $response->get_data()['message'] );
	}

	/**
	 * A client branches on `code`, never on `message` -- messages are
	 * translated. So two refusals a caller must tell apart need two codes, and
	 * they pass through verbatim: the route already sits under the plugin's own
	 * namespace, so the caller knows whose code it is reading.
	 */
	public function test_a_refusal_can_carry_its_own_code(): void {
		$this->write_route(
			'get',
			'reports',
			'v1',
			'/reports',
			"public function permission_check( WP_REST_Request \$request ): bool|\\WP_Error { return \$this->deny( 'Trial over.', 'trial_expired' ); }\n"
				. "public function handle( WP_REST_Request \$request ): WP_REST_Response { return new WP_REST_Response( [] ); }\n"
				. 'public function schema(): ?array { return null; }'
		);
		$this->boot_and_discover();

		$response = $GLOBALS['wp_rest_server']->dispatch( new WP_REST_Request( 'GET', '/zestry-test/v1/reports' ) );

		$this->assertSame( 'trial_expired', $response->get_data()['code'] );
	}

	/**
	 * The same refusal, at both statuses. Getting this wrong is the reason to
	 * call deny() rather than build the WP_Error by hand.
	 */
	public function test_a_refusal_is_401_logged_out_and_403_logged_in(): void {
		$this->write_route(
			'get',
			'reports',
			'v1',
			'/reports',
			"public function permission_check( WP_REST_Request \$request ): bool|\\WP_Error { return \$this->deny( 'Nope.' ); }\n"
				. "public function handle( WP_REST_Request \$request ): WP_REST_Response { return new WP_REST_Response( [] ); }\n"
				. 'public function schema(): ?array { return null; }'
		);
		$this->boot_and_discover();

		wp_set_current_user( 0 );
		$logged_out = $GLOBALS['wp_rest_server']->dispatch( new WP_REST_Request( 'GET', '/zestry-test/v1/reports' ) );
		$this->assertSame( 401, $logged_out->get_status(), 'Authenticate and retry.' );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$logged_in = $GLOBALS['wp_rest_server']->dispatch( new WP_REST_Request( 'GET', '/zestry-test/v1/reports' ) );
		$this->assertSame( 403, $logged_in->get_status(), 'Authenticated already; retrying will not help.' );
	}

	/**
	 * An ordinary request used no application password, and the helper has to
	 * say so rather than throwing on the absent global.
	 */
	public function test_a_request_without_an_application_password_reports_none(): void {
		$this->write_route(
			'get',
			'reports',
			'v1',
			'/reports',
			"public function permission_check( WP_REST_Request \$request ): bool|\\WP_Error { \$GLOBALS['zestry_app_password'] = \$this->is_application_password(); return true; }\n"
				. "public function handle( WP_REST_Request \$request ): WP_REST_Response { return new WP_REST_Response( [] ); }\n"
				. 'public function schema(): ?array { return null; }'
		);
		$this->boot_and_discover();

		$GLOBALS['zestry_app_password'] = null;
		$GLOBALS['wp_rest_server']->dispatch( new WP_REST_Request( 'GET', '/zestry-test/v1/reports' ) );

		$this->assertFalse( $GLOBALS['zestry_app_password'] );
	}

}
