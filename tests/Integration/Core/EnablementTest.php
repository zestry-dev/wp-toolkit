<?php

declare( strict_types=1 );

namespace Zestry\WPToolkit\Tests\Integration\Core;

use Zestry\WPToolkit\Modules\Abilities\Abilities;
use Zestry\WPToolkit\Modules\AdminPages\AdminPages;
use Zestry\WPToolkit\Modules\Ajax\Ajax;
use Zestry\WPToolkit\Modules\Cron\Cron;
use Zestry\WPToolkit\Modules\Fields\Fields;
use Zestry\WPToolkit\Modules\PostTypes\PostTypes;
use Zestry\WPToolkit\Modules\RestApi\RestApi;
use Zestry\WPToolkit\Tests\Support\TestCase;

/**
 * `is_enabled()` across the modules that honour it.
 *
 * One test per module rather than one shared assertion, because each registers
 * with a different WordPress function and "did not register" means something
 * different in each: absent from `get_post_types()`, absent from the admin menu,
 * no hook bound. A shared helper would assert the module's own bookkeeping, which
 * is the half that is easy to get right.
 *
 * @covers \Zestry\WPToolkit\Kernel\Traits\WithEnablement
 */
final class EnablementTest extends TestCase {

	public function tear_down(): void {
		$GLOBALS['admin_page_hooks'] = array();
		unset( $_GET['page'] );
		set_current_screen( 'front' );

		parent::tear_down();
	}

	/**
	 * The default has to be true, or adding the trait would have switched off
	 * every file already on disk in every plugin using the toolkit.
	 *
	 * @return void
	 */
	public function test_a_file_that_says_nothing_is_enabled(): void {
		$this->write_plugin_file(
			'post-types/thing.php',
			"<?php\nuse Zestry\\WPToolkit\\Modules\\PostTypes\\PostType;\nreturn new class extends PostType {\n"
				. "public function singular_name(): string { return 'Thing'; }\n"
				. "public function plural_name(): string { return 'Things'; }\n};\n"
		);

		$this->plugin->get( PostTypes::class )->boot();
		do_action( 'init' );

		$this->assertTrue( post_type_exists( 'thing' ) );
	}

	/**
	 * @return void
	 */
	public function test_a_disabled_post_type_is_never_registered(): void {
		$this->write_plugin_file(
			'post-types/switched-off.php',
			"<?php\nuse Zestry\\WPToolkit\\Modules\\PostTypes\\PostType;\nreturn new class extends PostType {\n"
				. "public function is_enabled(): bool { return false; }\n"
				. "public function singular_name(): string { return 'Off'; }\n"
				. "public function plural_name(): string { return 'Offs'; }\n};\n"
		);

		$this->plugin->get( PostTypes::class )->boot();
		do_action( 'init' );

		$this->assertFalse( post_type_exists( 'switched-off' ) );
	}

	/**
	 * @return void
	 */
	public function test_a_disabled_action_binds_no_hook(): void {
		mkdir( $this->plugin_dir . '/actions', 0777, true );
		add_filter( 'wp_doing_ajax', '__return_true' );

		$this->write_plugin_file(
			'actions/off.php',
			"<?php\nuse Zestry\\WPToolkit\\Modules\\Ajax\\AjaxAction;\nreturn new class extends AjaxAction {\n"
				. "public function is_enabled(): bool { return false; }\n"
				. "public function capability_check(): bool { return true; }\n"
				. "public function handle(): void {}\n};\n"
		);

		$ajax = $this->plugin->get( Ajax::class );
		$ajax->boot();

		$this->assertFalse(
			has_action( 'wp_ajax_' . $ajax->get_action_slug( 'off' ) ),
			'Nothing is listening, so the action is not merely refused -- it is absent.'
		);

		remove_filter( 'wp_doing_ajax', '__return_true' );
	}

	/**
	 * @return void
	 */
	public function test_a_disabled_page_reaches_no_menu(): void {
		mkdir( $this->plugin_dir . '/admin-pages', 0777, true );
		set_current_screen( 'dashboard' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$this->write_plugin_file(
			'admin-pages/off.php',
			"<?php\nuse Zestry\\WPToolkit\\Modules\\AdminPages\\AdminPage;\nreturn new class extends AdminPage {\n"
				. "public function is_enabled(): bool { return false; }\n"
				. "public function title(): string { return 'Off'; }\n"
				. "public function capability(): string { return 'manage_options'; }\n"
				. "public function render(): void {}\n};\n"
		);

		$pages = $this->plugin->get( AdminPages::class );
		do_action( 'admin_menu' );

		global $menu;
		$this->assertNotContains( 'zestry-test-off', array_column( (array) $menu, 2 ) );
		$this->assertArrayNotHasKey( 'zestry-test-off', $pages->get_pages(), 'Not discovered, not just unregistered.' );
	}

	/**
	 * @return void
	 */
	public function test_a_disabled_route_is_not_registered(): void {
		mkdir( $this->plugin_dir . '/routes', 0777, true );

		$this->write_plugin_file(
			'routes/off.php',
			"<?php\nuse Zestry\\WPToolkit\\Modules\\RestApi\\Route;\nuse Zestry\\WPToolkit\\Modules\\RestApi\\RestRoute;\n"
				. "return Route::get( 'v1', '/off', new class extends RestRoute {\n"
				. "public function is_enabled(): bool { return false; }\n"
				. "public function handle( \\WP_REST_Request \$request ): \\WP_REST_Response { return new \\WP_REST_Response( array() ); }\n"
				. "public function permission_check( \\WP_REST_Request \$request ): bool { return true; }\n"
				. "public function schema(): ?array { return null; }\n} );\n"
		);

		$this->plugin->get( RestApi::class )->boot();
		do_action( 'rest_api_init', rest_get_server() );

		$routes = rest_get_server()->get_routes();

		$this->assertArrayNotHasKey( '/zestry-test/v1/off', $routes );
	}

	/**
	 * @return void
	 */
	public function test_a_disabled_field_registers_no_meta(): void {
		mkdir( $this->plugin_dir . '/fields', 0777, true );
		register_post_type( 'zestry_conditional', array( 'public' => true ) );

		$this->write_plugin_file(
			'fields/off_key.php',
			"<?php\nuse Zestry\\WPToolkit\\Modules\\Fields\\Field;\nreturn new class extends Field {\n"
				. "public function is_enabled(): bool { return false; }\n"
				. "public function object_types(): array { return array( 'zestry_conditional' ); }\n"
				. "public function type(): string { return 'string'; }\n};\n"
		);

		$this->plugin->get( Fields::class )->boot();
		do_action( 'init' );

		$registered = get_registered_meta_keys( 'post', 'zestry_conditional' );

		$this->assertArrayNotHasKey( 'off_key', $registered );
	}

	/**
	 * @return void
	 */
	public function test_a_disabled_schedule_is_not_discovered(): void {
		mkdir( $this->plugin_dir . '/schedules', 0777, true );

		$this->write_plugin_file(
			'schedules/off.php',
			"<?php\nuse Zestry\\WPToolkit\\Modules\\Cron\\Schedule;\nreturn new class extends Schedule {\n"
				. "public function is_enabled(): bool { return false; }\n"
				. "public function recurrence(): string { return 'hourly'; }\n"
				. "public function run(): void {}\n};\n"
		);

		$cron = $this->plugin->get( Cron::class );
		$cron->boot();
		do_action( 'init' );

		$this->assertFalse(
			wp_next_scheduled( $cron->get_schedule_slug( 'off' ) ),
			'Nothing was scheduled, so WordPress will never call it.'
		);
	}

	/**
	 * The switch is read after wiring, which is the whole reason it can be a
	 * stored setting rather than only a constant -- an injected service is
	 * available by the time it is asked.
	 *
	 * @return void
	 */
	public function test_the_switch_may_depend_on_an_injected_service(): void {
		$this->write_plugin_file(
			'post-types/conditional.php',
			"<?php\nuse Zestry\\WPToolkit\\Modules\\PostTypes\\PostType;\nuse Zestry\\WPToolkit\\Services\\Globals;\n"
				. "return new class extends PostType {\n"
				. "public Globals \$globals;\n"
				. "public function is_enabled(): bool { return (bool) \$this->globals->get( 'feature_on' ); }\n"
				. "public function singular_name(): string { return 'Conditional'; }\n"
				. "public function plural_name(): string { return 'Conditionals'; }\n};\n"
		);

		// Off: the property is injected, read, and answers false.
		$this->plugin->get( PostTypes::class )->boot();
		do_action( 'init' );

		$this->assertFalse( post_type_exists( 'conditional' ), 'The injected service decided.' );

		// And on, from a second plugin over the same file -- otherwise the
		// assertion above would pass just as well if the switch were stuck off, or
		// if the file had failed to load at all.
		$second = new \Zestry\WPToolkit\Kernel\Plugin( $this->entry_file, 'zestry-second' );
		$second->get( \Zestry\WPToolkit\Services\Globals::class )->set( 'feature_on', true );
		$second->get( PostTypes::class )->boot();
		do_action( 'init' );

		$this->assertTrue( post_type_exists( 'conditional' ), 'The same file registers when the service says so.' );
	}

	/**
	 * A migration deliberately has no switch: skipping one leaves a permanent gap,
	 * and enabling it later would run it after migrations that assumed it had.
	 *
	 * @return void
	 */
	public function test_a_migration_has_no_switch(): void {
		$this->assertFalse(
			method_exists( \Zestry\WPToolkit\Modules\Migrations\Migration::class, 'is_enabled' ),
			'Migrations run once ever, in order, so there is nothing safe to switch off.'
		);
	}
}
