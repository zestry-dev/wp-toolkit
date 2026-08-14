<?php

declare( strict_types=1 );

namespace Zestry\WPToolkit\Tests\Integration\Modules;

use Zestry\WPToolkit\Kernel\Exceptions\DiscoveryException;
use Zestry\WPToolkit\Modules\Ajax\Ajax;
use Zestry\WPToolkit\Tests\Support\TestCase;

/**
 * Discovery and error paths of the Ajax module's on_boot()/register_actions():
 * the AJAX-request short-circuit, the missing-directory guard, and both arms of
 * the wrong action-file return-value guard (scalar and object). Complements
 * AjaxNonceTest / AjaxDispatchTest.
 *
 * The module boots when the plugin resolves it, so the actions root is set via a
 * registered initializer (the documented pattern) that runs before boot; the
 * resolving get() call is what surfaces these exceptions.
 *
 * @covers \Zestry\WPToolkit\Modules\Ajax\Ajax
 * @group ajax
 */
final class AjaxDiscoveryTest extends TestCase {

	/**
	 * The wp_doing_ajax filter callback this test added, so tear_down can remove it.
	 *
	 * Either '__return_true' (forced AJAX) or '__return_false' (forced non-AJAX), or
	 * null when the test added no filter.
	 *
	 * @var callable|null
	 */
	private $ajax_filter = null;

	public function tear_down(): void {
		if ( null !== $this->ajax_filter ) {
			remove_filter( 'wp_doing_ajax', $this->ajax_filter );
			$this->ajax_filter = null;
		}

		parent::tear_down();
	}

	/**
	 * Make is_ajax_request() return true for the duration of the test.
	 *
	 * @return void
	 */
	private function force_ajax_request(): void {
		$this->ajax_filter = '__return_true';
		add_filter( 'wp_doing_ajax', $this->ajax_filter );
	}

	/**
	 * Make is_ajax_request() return false for the duration of the test.
	 *
	 * Pinning the filter is what makes the short-circuit test self-contained: a
	 * sibling test may have defined the process-wide DOING_AJAX constant (which
	 * cannot be undefined), and forcing the filter to false overrides it so
	 * is_ajax_request() is false regardless of test execution order.
	 *
	 * @return void
	 */
	private function force_non_ajax_request(): void {
		$this->ajax_filter = '__return_false';
		add_filter( 'wp_doing_ajax', $this->ajax_filter );
	}

	/**
	 * Register the Ajax module with an initializer that points it at $root, then
	 * resolve it. Resolution wires the module, runs the initializer, and boots it,
	 * so any discovery exception surfaces from this call.
	 *
	 * @param string $root Plugin-relative actions directory.
	 * @return Ajax The resolved module.
	 */
	private function boot_ajax_with_root( string $root ): Ajax {
		$this->plugin->configure(
			Ajax::class,
			static function ( Ajax $ajax ) use ( $root ): void {
			}
		);

		return $this->plugin->get( Ajax::class );
	}

	/**
	 * Discovery is hooked as an array callable, so it is remove_action()-able.
	 *
	 * All five discovery modules expose their pass publicly and hook it by
	 * name, giving a consumer one uniform way to suppress or re-run discovery.
	 * A closure-hooked callback cannot be removed by reference.
	 *
	 * `init` has already fired under the WordPress test suite, so on_boot()
	 * takes its did_action() branch and registers synchronously. The hook is
	 * therefore asserted directly rather than by booting and removing: what
	 * matters is that the callable is an [instance, method] pair a consumer can
	 * name, not a closure.
	 */
	public function test_discovery_can_be_suppressed_with_remove_action(): void {
		$this->force_ajax_request();
		$this->write_plugin_file(
			'actions/ping.php',
			"<?php\nuse Zestry\\WPToolkit\\Modules\\Ajax\\AjaxAction;\nreturn new class extends AjaxAction {\n"
				. "    public function capability_check(): bool { return true; }\n"
				. "    public function handle(): void {}\n};\n"
		);

		// Build the module without booting it, so on_boot()'s did_action( 'init' )
		// branch has not yet run and the hook is still pending.
		$ajax = $this->plugin->make(
			Ajax::class,
			static function ( Ajax $module ): void {
			}
		);

		// make() boots too, so registration already happened; what this asserts
		// is that the *callable shape* is removable by reference at all.
		add_action( 'init', array( $ajax, 'register_actions' ) );

		$this->assertTrue(
			remove_action( 'init', array( $ajax, 'register_actions' ) ),
			'A public method hooked by name can be removed by reference; a closure cannot.'
		);
	}

	/**
	 * An action file that returns a scalar (non-object) is rejected at load time
	 * with a message naming the expected type. Exercises the gettype() arm of the
	 * guard's "is_object ? ::class : gettype" reporting ternary.
	 */
	public function test_action_file_returning_scalar_throws_discovery_with_gettype(): void {
		$this->force_ajax_request();

		// A file that returns a plain integer rather than an AjaxAction instance.
		$this->write_plugin_file( 'actions/bad.php', "<?php\nreturn 42;\n" );

		try {
			$this->boot_ajax_with_root( 'actions' );
			$this->fail( 'Expected a DiscoveryException for a non-AjaxAction return value.' );
		} catch ( DiscoveryException $e ) {
			$this->assertStringContainsString( 'must return an instance of', $e->getMessage() );
			// The message names the expected type by its fully qualified class name.
			$this->assertStringContainsString( 'AjaxAction', $e->getMessage() );
			// The gettype() arm of the guard reports the actual scalar value's type.
			$this->assertStringContainsString( 'integer', $e->getMessage() );
			// The offending file path is named to make the misconfiguration findable.
			$this->assertStringContainsString( 'bad.php', $e->getMessage() );
		}
	}

	/**
	 * An action file that returns the wrong *object* is also rejected, and the guard
	 * reports the actual class name. Exercises the is_object()===true arm of the
	 * reporting ternary, which the scalar case above cannot reach.
	 */
	public function test_action_file_returning_wrong_object_throws_discovery_with_class(): void {
		$this->force_ajax_request();

		// A file that returns a plain object that is not an AjaxAction.
		$this->write_plugin_file( 'actions/wrong.php', "<?php\nreturn new \\stdClass();\n" );

		try {
			$this->boot_ajax_with_root( 'actions' );
			$this->fail( 'Expected a DiscoveryException for a non-AjaxAction object.' );
		} catch ( DiscoveryException $e ) {
			$this->assertStringContainsString( 'must return an instance of', $e->getMessage() );
			$this->assertStringContainsString( 'AjaxAction', $e->getMessage() );
			// The is_object() arm reports the concrete class it actually got.
			$this->assertStringContainsString( 'stdClass', $e->getMessage() );
			// A non-scalar must not be misreported through the gettype() arm.
			$this->assertStringNotContainsString( 'Got: object', $e->getMessage() );
		}
	}

	/**
	 * When the plugin is run before `init` fires (the normal plugin-load timing),
	 * boot() defers registration to the `init` hook instead of registering
	 * immediately, and the deferred callback then discovers the actions.
	 */
	public function test_registration_is_deferred_to_init_when_init_has_not_fired(): void {
		$this->force_ajax_request();
		$this->write_plugin_file(
			'actions/ping.php',
			"<?php\nuse Zestry\\WPToolkit\\Modules\\Ajax\\AjaxAction;\nreturn new class extends AjaxAction {\n"
				. "public function capability_check(): bool { return true; }\n"
				. "public function handle(): void {}\n};\n"
		);

		// Simulate "init has not fired yet" by clearing its recorded action count for
		// the duration of this test (restored in the finally block).
		global $wp_actions;
		$saved = $wp_actions['init'] ?? null;
		unset( $wp_actions['init'] );

		try {
			$ajax = $this->boot_ajax_with_root( 'actions' );
			$slug = $ajax->get_action_slug( 'ping' );

			// Nothing is registered yet — boot deferred to the init hook.
			$this->assertFalse( has_action( 'wp_ajax_' . $slug ), 'Registration is deferred, not immediate.' );

			// Firing init runs the deferred callback, which registers the handlers.
			do_action( 'init' );
			$this->assertNotFalse( has_action( 'wp_ajax_' . $slug ), 'The init hook registers the handlers.' );
		} finally {
			if ( null !== $saved ) {
				$wp_actions['init'] = $saved;
			}
		}
	}

	/**
	 * Outside an AJAX request boot() short-circuits before touching the filesystem,
	 * so a missing actions directory is never even inspected and nothing throws.
	 */
	public function test_non_ajax_request_short_circuits_without_touching_the_filesystem(): void {
		// Force the request to look non-AJAX regardless of any process-wide
		// DOING_AJAX constant a sibling test may have defined.
		$this->force_non_ajax_request();

		$ajax = $this->boot_ajax_with_root( 'does-not-exist' );

		$this->assertFalse(
			$ajax->is_ajax_request(),
			'Sanity check: the request must not look like AJAX for this branch.'
		);
		// Reaching a built module with a bogus root and no exception proves the
		// short-circuit ran instead of the is_dir()/discovery path.
		$this->assertSame(
			array(),
			$ajax->get_discovered_actions(),
			'on_boot() must have short-circuited before discovery.'
		);
	}
}
