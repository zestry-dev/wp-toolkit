<?php

declare( strict_types=1 );

namespace Zestry\WPToolkit\Tests\Integration\Modules;

use Zestry\WPToolkit\Modules\Ajax\Ajax;
use Zestry\WPToolkit\Tests\Support\TestCase;

/**
 * End-to-end AJAX dispatch order: capability -> nonce -> handle (review #19, #21).
 *
 * Extends the shared TestCase (transaction-safe) rather than WP_Ajax_UnitTestCase,
 * which cannot share a process with the transaction-rollback tests. wp_send_json_*
 * is intercepted here by installing a wp_die_ajax_handler that throws, and the JSON
 * is captured via ob_start(), so the request is driven without terminating.
 *
 * @covers \Zestry\WPToolkit\Modules\Ajax\Ajax
 */
final class AjaxDispatchTest extends TestCase {

	public function set_up(): void {
		parent::set_up();

		// Look like an admin-ajax request so the module discovers and registers actions.
		add_filter( 'wp_doing_ajax', '__return_true' );

		// Authenticate so a created nonce and its verification share a session token.
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		// A posted body is only read on a method that carries one, exactly as
		// WP_REST_Request decides it -- and PHP only fills $_POST on a real POST,
		// so a test writing to it has to say so too.
		$_SERVER['REQUEST_METHOD'] = 'POST';

		mkdir( $this->plugin_dir . '/actions', 0777, true );
	}

	public function tear_down(): void {
		remove_filter( 'wp_doing_ajax', '__return_true' );
		unset( $_REQUEST['_wpnonce'], $_POST['_wpnonce'], $_GET['_wpnonce'] );
		unset( $_POST['post_id'], $_POST['name'], $_GET['post_id'], $_COOKIE['post_id'] );
		unset( $_SERVER['REQUEST_METHOD'], $_SERVER['CONTENT_TYPE'], $GLOBALS['HTTP_RAW_POST_DATA'] );
		parent::tear_down();
	}

	private function ajax(): Ajax {
		return $this->plugin->get( Ajax::class );
	}

	private function set_request_nonce( string $nonce ): void {
		$_POST['_wpnonce']    = $nonce;
		$_GET['_wpnonce']     = $nonce;
		$_REQUEST['_wpnonce'] = $nonce;
	}

	/**
	 * Write an action file, boot the module to register its hooks, and return the slug.
	 *
	 * @param string $name       Action file base name.
	 * @param string $class_body PHP body of the anonymous AjaxAction subclass.
	 * @return string The registered action slug.
	 */
	private function register_action( string $name, string $class_body ): string {
		$this->write_plugin_file(
			"actions/{$name}.php",
			"<?php\nuse Zestry\\WPToolkit\\Modules\\Ajax\\AjaxAction;\nreturn new class extends AjaxAction {\n{$class_body}\n};\n"
		);

		// Resolving the module boots it, and init has already fired, so the file
		// written above is discovered and registered immediately.
		$ajax = $this->ajax();

		return $ajax->get_action_slug( $name );
	}

	/**
	 * Fire wp_ajax_{slug} and return the decoded JSON the handler emitted.
	 *
	 * wp_send_json_* echoes JSON then calls wp_die(); a throwing die handler stops
	 * execution so the buffered output can be read.
	 *
	 * @param string $slug   The action slug.
	 * @param string $prefix Hook prefix: 'wp_ajax_' (default) or 'wp_ajax_nopriv_'.
	 * @return array{success:bool,data:mixed}
	 */
	private function dispatch( string $slug, string $prefix = 'wp_ajax_' ): array {
		$die_handler = static function (): callable {
			return static function (): void {
				throw new \RuntimeException( '__ajax_die__' );
			};
		};
		add_filter( 'wp_die_ajax_handler', $die_handler );

		ob_start();
		try {
			do_action( $prefix . $slug );
		} catch ( \RuntimeException $e ) {
			if ( '__ajax_die__' !== $e->getMessage() ) {
				throw $e;
			}
		} finally {
			$json = ob_get_clean();
			remove_filter( 'wp_die_ajax_handler', $die_handler );
		}

		return json_decode( (string) $json, true );
	}

	/**
	 * An action can name itself, which is what a log line or an error response
	 * needs -- the slug is what JavaScript sent, and the action never sees its
	 * own filename otherwise.
	 */
	public function test_an_action_can_ask_what_it_is_registered_as(): void {
		$slug = $this->register_action(
			'whoami',
			'public function capability_check(): bool { return true; }
			 public function is_nonce_required(): bool { return false; }
			 public function handle(): void { wp_send_json_success( array( "slug" => $this->get_slug() ) ); }'
		);

		$response = $this->dispatch( $slug );

		$this->assertSame( 'zestry-test-whoami', $response['data']['slug'] );
	}

	/**
	 * An action declares what it takes the same way a route and an ability do,
	 * and never reads a superglobal itself.
	 */
	public function test_declared_arguments_are_checked_and_bound(): void {
		$slug = $this->register_action(
			'save-draft',
			"#[\\Zestry\\WPToolkit\\Modules\\Request\\Attributes\\RequestArgument( 'Which post.' )]\n"
				. "public int \$post_id;\n"
				. "#[\\Zestry\\WPToolkit\\Modules\\Request\\Attributes\\RequestArgument( 'Whether to notify.' )]\n"
				. "public bool \$notify = false;\n"
				. 'public function capability_check(): bool { return true; }
			 public function is_nonce_required(): bool { return false; }
			 public function handle(): void { wp_send_json_success( array( "id" => $this->post_id, "notify" => $this->notify ) ); }'
		);

		$_POST['post_id'] = '42';

		$response = $this->dispatch( $slug );

		$this->assertTrue( $response['success'] );
		$this->assertSame( 42, $response['data']['id'], 'A request carries strings; the declaration says what it is.' );
		$this->assertFalse( $response['data']['notify'], 'An argument left out keeps its default.' );
	}

	/**
	 * WordPress slashes the superglobals on the way in and never undoes it for an AJAX
	 * hook, unlike a REST route. Forgetting that turns O'Brien into O\'Brien.
	 */
	public function test_a_bound_value_is_unslashed(): void {
		$slug = $this->register_action(
			'save-name',
			"#[\\Zestry\\WPToolkit\\Modules\\Request\\Attributes\\RequestArgument( 'A name.' )]\n"
				. "public string \$name;\n"
				. 'public function capability_check(): bool { return true; }
			 public function is_nonce_required(): bool { return false; }
			 public function handle(): void { wp_send_json_success( array( "name" => $this->name ) ); }'
		);

		// As WordPress leaves it for the hook.
		$_POST['name'] = addslashes( "O'Brien" );

		$response = $this->dispatch( $slug );

		$this->assertSame( "O'Brien", $response['data']['name'] );
	}

	/**
	 * The reason the values go through a WP_REST_Request rather than `$_REQUEST`.
	 * PHP builds that superglobal from `request_order`, which is empty on a stock
	 * build and falls back to `variables_order` -- `EGPCS`, cookies included and
	 * merged last, so a cookie of the same name would beat the posted value. A
	 * route has never been able to read one, and now neither can an action.
	 */
	public function test_a_cookie_cannot_supply_a_declared_argument(): void {
		$slug = $this->register_action(
			'cookie-proof',
			"#[\\Zestry\\WPToolkit\\Modules\\Request\\Attributes\\RequestArgument( 'Which post.' )]\n"
				. "public int \$post_id;\n"
				. 'public function capability_check(): bool { return true; }
			 public function is_nonce_required(): bool { return false; }
			 public function handle(): void { wp_send_json_success( array( "id" => $this->post_id ) ); }'
		);

		$_POST['post_id']   = '42';
		$_COOKIE['post_id'] = '999';

		$response = $this->dispatch( $slug );

		$this->assertSame( 42, $response['data']['id'], 'The posted value wins; the cookie is never read.' );
	}

	/**
	 * What routing the values through a WP_REST_Request buys that hand-reading the
	 * superglobals did not: PHP fills `$_POST` for form encodings only, so a
	 * `fetch()` sending `application/json` -- the ordinary way to call
	 * `admin-ajax.php` from a block or a modern script -- used to arrive as nothing
	 * at all. Core's own parser reads it, and the declared type still applies.
	 */
	public function test_a_json_body_supplies_declared_arguments(): void {
		$slug = $this->register_action(
			'json-body',
			"#[\\Zestry\\WPToolkit\\Modules\\Request\\Attributes\\RequestArgument( 'Which post.' )]\n"
				. "public int \$post_id;\n"
				. 'public function capability_check(): bool { return true; }
			 public function is_nonce_required(): bool { return false; }
			 public function handle(): void { wp_send_json_success( array( "id" => $this->post_id ) ); }'
		);

		// WP_REST_Server::get_raw_data() reads php://input once and caches it here,
		// which is how core's own tests stand a body up.
		$_SERVER['CONTENT_TYPE']       = 'application/json';
		$GLOBALS['HTTP_RAW_POST_DATA'] = wp_json_encode( array( 'post_id' => 42 ) );

		$response = $this->dispatch( $slug );

		$this->assertSame( 42, $response['data']['id'] );
	}

	/**
	 * The order a route resolves a parameter in, minus the sources a form POST
	 * cannot carry: the body first, then the query string.
	 */
	public function test_the_body_wins_over_the_query_string(): void {
		$slug = $this->register_action(
			'body-first',
			"#[\\Zestry\\WPToolkit\\Modules\\Request\\Attributes\\RequestArgument( 'Which post.' )]\n"
				. "public int \$post_id;\n"
				. 'public function capability_check(): bool { return true; }
			 public function is_nonce_required(): bool { return false; }
			 public function handle(): void { wp_send_json_success( array( "id" => $this->post_id ) ); }'
		);

		$_GET['post_id']  = '7';
		$_POST['post_id'] = '42';

		$response = $this->dispatch( $slug );

		$this->assertSame( 42, $response['data']['id'] );
	}

	/**
	 * And the query string still answers when the body does not, which is what a
	 * link-shaped AJAX call sends.
	 */
	public function test_the_query_string_answers_when_the_body_does_not(): void {
		$slug = $this->register_action(
			'query-only',
			"#[\\Zestry\\WPToolkit\\Modules\\Request\\Attributes\\RequestArgument( 'Which post.' )]\n"
				. "public int \$post_id;\n"
				. 'public function capability_check(): bool { return true; }
			 public function is_nonce_required(): bool { return false; }
			 public function handle(): void { wp_send_json_success( array( "id" => $this->post_id ) ); }'
		);

		$_GET['post_id'] = '7';

		$response = $this->dispatch( $slug );

		$this->assertSame( 7, $response['data']['id'] );
	}

	public function test_an_argument_that_does_not_fit_is_refused_before_handle_runs(): void {
		$slug = $this->register_action(
			'typed',
			"#[\\Zestry\\WPToolkit\\Modules\\Request\\Attributes\\RequestArgument( 'Which post.' )]\n"
				. "public int \$post_id;\n"
				. 'public function capability_check(): bool { return true; }
			 public function is_nonce_required(): bool { return false; }
			 public function handle(): void { $GLOBALS["zestry_handle_ran"] = true; wp_send_json_success( array() ); }'
		);

		$GLOBALS['zestry_handle_ran'] = false;
		$_POST['post_id']       = 'not-a-number';

		$response = $this->dispatch( $slug );

		$this->assertFalse( $response['success'] );
		$this->assertSame( array( 'post_id' ), $response['data']['params'] );
		$this->assertFalse( $GLOBALS['zestry_handle_ran'] );

		unset( $GLOBALS['zestry_handle_ran'] );
	}

	public function test_a_missing_required_argument_is_refused(): void {
		$slug = $this->register_action(
			'needs-one',
			"#[\\Zestry\\WPToolkit\\Modules\\Request\\Attributes\\RequestArgument( 'Which post.' )]\n"
				. "public int \$post_id;\n"
				. 'public function capability_check(): bool { return true; }
			 public function is_nonce_required(): bool { return false; }
			 public function handle(): void { wp_send_json_success( array() ); }'
		);

		$response = $this->dispatch( $slug );

		$this->assertFalse( $response['success'] );
		$this->assertSame( 'rest_missing_callback_param', $response['data']['code'] );
	}

	/**
	 * Bound before the capability check, so a check can name what it is deciding
	 * about rather than reaching into the request for it.
	 */
	public function test_arguments_are_bound_before_the_capability_check(): void {
		$slug = $this->register_action(
			'guarded-by-arg',
			"#[\\Zestry\\WPToolkit\\Modules\\Request\\Attributes\\RequestArgument( 'Which post.' )]\n"
				. "public int \$post_id;\n"
				. 'public function capability_check(): bool { return 1 === $this->post_id; }
			 public function is_nonce_required(): bool { return false; }
			 public function handle(): void { wp_send_json_success( array( "ok" => true ) ); }'
		);

		$_POST['post_id'] = '2';
		$this->assertFalse( $this->dispatch( $slug )['success'] );

		$_POST['post_id'] = '1';
		$this->assertTrue( $this->dispatch( $slug )['success'] );
	}

	public function test_denied_capability_returns_403_before_handle_runs(): void {
		$slug = $this->register_action(
			'guarded',
			'public function capability_check(): bool { return false; }
			 public function is_nonce_required(): bool { return false; }
			 public function handle(): void { wp_send_json_success( array( "ran" => true ) ); }'
		);

		$response = $this->dispatch( $slug );

		$this->assertFalse( $response['success'], 'Unauthorized request must fail.' );
		$this->assertArrayNotHasKey( 'ran', (array) $response['data'], 'handle() must not run.' );
	}

	public function test_capability_passes_but_bad_nonce_returns_403(): void {
		$slug = $this->register_action(
			'nonced',
			'public function capability_check(): bool { return true; }
			 public function handle(): void { wp_send_json_success( array( "ran" => true ) ); }'
		);

		$this->set_request_nonce( 'not-a-valid-nonce' );
		$response = $this->dispatch( $slug );

		$this->assertFalse( $response['success'], 'A bad nonce must be rejected.' );
	}

	public function test_capability_and_nonce_pass_then_handle_runs(): void {
		$slug = $this->register_action(
			'ok',
			'public function capability_check(): bool { return true; }
			 public function handle(): void { wp_send_json_success( array( "ran" => true ) ); }'
		);

		$this->set_request_nonce( $this->ajax()->create_action_nonce( 'ok' ) );
		$response = $this->dispatch( $slug );

		$this->assertTrue( $response['success'], 'Authorized, valid-nonce request runs.' );
		$this->assertTrue( $response['data']['ran'] );
	}

	public function test_nopriv_request_is_forbidden_by_default(): void {
		$slug = $this->register_action(
			'members-only',
			'public function capability_check(): bool { return true; }
			 public function handle(): void { wp_send_json_success(); }'
		);

		// The unauthenticated hook rejects the request (allow_not_privileged() is false).
		$response = $this->dispatch( $slug, 'wp_ajax_nopriv_' );

		$this->assertFalse( $response['success'] );
		$this->assertSame( 'forbidden', $response['data'][0]['code'] );
	}

	public function test_nopriv_request_is_allowed_when_the_action_opts_in(): void {
		$slug = $this->register_action(
			'public-ping',
			'public function capability_check(): bool { return true; }
			 public function is_nonce_required(): bool { return false; }
			 public function allow_not_privileged(): bool { return true; }
			 public function handle(): void { wp_send_json_success( array( "public" => true ) ); }'
		);

		$response = $this->dispatch( $slug, 'wp_ajax_nopriv_' );

		$this->assertTrue( $response['success'] );
		$this->assertTrue( $response['data']['public'] );
	}
}
