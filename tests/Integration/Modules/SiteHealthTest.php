<?php

declare( strict_types=1 );

namespace Zestry\WPToolkit\Tests\Integration\Modules;

use Zestry\WPToolkit\Kernel\Exceptions\DiscoveryException;
use Zestry\WPToolkit\Modules\SiteHealth\BadgeColor;
use Zestry\WPToolkit\Modules\SiteHealth\SiteHealth;
use Zestry\WPToolkit\Tests\Support\TestCase;

/**
 * Discovery, identifier namespacing, and the `test` key WordPress requires the
 * result to carry.
 *
 * @covers \Zestry\WPToolkit\Modules\SiteHealth\SiteHealth
 * @covers \Zestry\WPToolkit\Modules\SiteHealth\HealthCheck
 * @covers \Zestry\WPToolkit\Modules\SiteHealth\DebugSection
 */
final class SiteHealthTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		mkdir( $this->plugin_dir . '/health-checks', 0777, true );
		mkdir( $this->plugin_dir . '/debug-sections', 0777, true );
	}

	public function test_a_discovered_check_is_added_to_the_site_status_tests(): void {
		$this->write_check( 'api-key', "return \$this->good( 'Fine.' );" );

		$tests = $this->boot()->filter_site_status_tests( array() );

		$this->assertArrayHasKey( 'zestry-test-api-key', $tests['direct'] );
		$this->assertSame( 'A check', $tests['direct']['zestry-test-api-key']['label'] );
	}

	/**
	 * `site_status_tests` is one array shared by every plugin on the site, so an
	 * unprefixed `api_key` would collide the way an unprefixed hook would.
	 */
	public function test_an_identifier_is_namespaced_and_underscored(): void {
		$this->assertSame( 'zestry-test-api-key', $this->boot()->get_check_id( 'api-key' ) );
	}

	/**
	 * WordPress attributes a result by its `test` key, which has to match the
	 * key the check was registered under. A check never sees its own filename,
	 * so the module stamps it -- and if that stopped happening the result would
	 * silently detach from its row on the screen.
	 */
	public function test_a_result_carries_the_identifier_it_was_registered_under(): void {
		$this->write_check( 'api-key', "return \$this->critical( 'Missing.' );" );

		$tests  = $this->boot()->filter_site_status_tests( array() );
		$result = call_user_func( $tests['direct']['zestry-test-api-key']['test'] );

		$this->assertSame( 'zestry-test-api-key', $result['test'] );
		$this->assertSame( 'critical', $result['status'] );
		$this->assertStringContainsString( 'Missing.', $result['description'] );
	}

	/**
	 * @dataProvider provide_statuses
	 * @param string $helper The result helper to call.
	 * @param string $status The status it should produce.
	 * @return void
	 */
	public function test_each_helper_produces_its_status( string $helper, string $status ): void {
		$this->write_check( 'thing', "return \$this->{$helper}( 'Some detail.' );" );

		$tests  = $this->boot()->filter_site_status_tests( array() );
		$result = call_user_func( $tests['direct']['zestry-test-thing']['test'] );

		$this->assertSame( $status, $result['status'] );
	}

	/**
	 * @return array<string, array{string, string}>
	 */
	public function provide_statuses(): array {
		return array(
			'good'        => array( 'good', 'good' ),
			'recommended' => array( 'recommended', 'recommended' ),
			'critical'    => array( 'critical', 'critical' ),
		);
	}

	public function test_actions_html_is_passed_through(): void {
		$this->write_check( 'thing', "return \$this->critical( 'Broken.', '<a href=\"#\">Fix it</a>' );" );

		$tests  = $this->boot()->filter_site_status_tests( array() );
		$result = call_user_func( $tests['direct']['zestry-test-thing']['test'] );

		$this->assertSame( '<a href="#">Fix it</a>', $result['actions'] );
	}

	/**
	 * A plugin that has not written its first check yet must still boot -- the
	 * default directory being absent is not a misconfiguration.
	 */
	public function test_a_missing_default_directory_is_not_an_error(): void {
		$this->remove_dir( $this->plugin_dir . '/health-checks' );

		$this->assertSame( array(), $this->boot()->filter_site_status_tests( array() ) );
	}

	public function test_a_file_returning_the_wrong_type_throws(): void {
		file_put_contents( $this->plugin_dir . '/health-checks/bad.php', "<?php\nreturn 'nope';\n" );

		$this->expectException( DiscoveryException::class );
		$this->expectExceptionMessage( 'must return an instance of' );

		$this->boot()->get_discovered_checks();
	}

	/**
	 * Checks are ordinary wired objects, so a check reads real state rather than
	 * guessing at it.
	 */
	public function test_a_check_can_reach_a_module(): void {
		$this->write_check(
			'paths',
			"return \$this->good( \$this->with( \\Zestry\\WPToolkit\\Modules\\Path::class )->get_plugin_path( 'x' ) );"
		);

		$tests  = $this->boot()->filter_site_status_tests( array() );
		$result = call_user_func( $tests['direct']['zestry-test-paths']['test'] );

		$this->assertStringContainsString( $this->plugin_dir, $result['description'] );
	}

	/**
	 * WordPress renders the colour straight into a class name, and styles only
	 * six. A string would let a typo through to an unstyled badge that looks
	 * like a bug in the plugin; the enum makes that unrepresentable.
	 */
	public function test_the_badge_carries_the_enums_value_not_the_enum(): void {
		$this->write_check(
			'thing',
			"return \$this->good( 'Fine.' );",
			"public function badge_color(): BadgeColor { return BadgeColor::Purple; }\n"
		);

		$tests  = $this->boot()->filter_site_status_tests( array() );
		$result = call_user_func( $tests['direct']['zestry-test-thing']['test'] );

		$this->assertSame( 'purple', $result['badge']['color'] );
	}

	public function test_the_default_badge_is_blue_and_names_the_plugin(): void {
		$this->write_check( 'thing', "return \$this->good( 'Fine.' );" );

		$tests  = $this->boot()->filter_site_status_tests( array() );
		$result = call_user_func( $tests['direct']['zestry-test-thing']['test'] );

		$this->assertSame( BadgeColor::Blue->value, $result['badge']['color'] );
	}

	/**
	 * A check can name itself, which is what a log line needs -- the identifier
	 * is what the Site Health report shows, and the check never sees its own
	 * filename otherwise.
	 */
	public function test_a_check_can_ask_what_it_is_registered_as(): void {
		$this->write_check( 'api-key', "return \$this->good( \$this->get_id() );" );

		$tests  = $this->boot()->filter_site_status_tests( array() );
		$result = call_user_func( $tests['direct']['zestry-test-api-key']['test'] );

		$this->assertStringContainsString( 'zestry-test-api-key', $result['description'] );
	}

	public function test_a_discovered_section_is_added_to_the_debug_information(): void {
		$this->write_section( 'status', "return array( 'mode' => array( 'label' => 'Mode', 'value' => 'Live' ) );" );

		$info = $this->boot()->filter_debug_information( array() );

		$this->assertArrayHasKey( 'zestry-test-status', $info );
		$this->assertSame( 'A section', $info['zestry-test-status']['label'] );
		$this->assertSame( 'Live', $info['zestry-test-status']['fields']['mode']['value'] );
	}

	/**
	 * `debug_information` is one array shared by every plugin, the same way
	 * `site_status_tests` is.
	 */
	public function test_a_section_identifier_is_namespaced_and_underscored(): void {
		$this->assertSame( 'zestry-test-api-status', $this->boot()->get_section_id( 'api-status' ) );
	}

	/**
	 * Every key WordPress reads is optional, so a section that overrides none of
	 * them still has to arrive with each one filled in.
	 */
	public function test_a_section_carries_its_display_defaults(): void {
		$this->write_section( 'status', 'return array();' );

		$info = $this->boot()->filter_debug_information( array() );

		$this->assertSame( '', $info['zestry-test-status']['description'] );
		$this->assertFalse( $info['zestry-test-status']['show_count'] );
		$this->assertFalse( $info['zestry-test-status']['private'] );
	}

	public function test_a_section_can_keep_itself_out_of_the_copied_text(): void {
		$this->write_section(
			'status',
			'return array();',
			"public function is_private(): bool { return true; }\n"
				. "public function show_count(): bool { return true; }\n"
				. "public function description(): string { return 'What Acme is doing.'; }\n"
		);

		$info = $this->boot()->filter_debug_information( array() );

		$this->assertTrue( $info['zestry-test-status']['private'] );
		$this->assertTrue( $info['zestry-test-status']['show_count'] );
		$this->assertSame( 'What Acme is doing.', $info['zestry-test-status']['description'] );
	}

	/**
	 * A section can name itself for the same reason a check can: the identifier
	 * comes from the filename, which the instance never sees.
	 */
	public function test_a_section_can_ask_what_it_is_registered_as(): void {
		$this->write_section( 'status', "return array( 'id' => array( 'label' => 'Id', 'value' => \$this->get_id() ) );" );

		$info = $this->boot()->filter_debug_information( array() );

		$this->assertSame( 'zestry-test-status', $info['zestry-test-status']['fields']['id']['value'] );
	}

	/**
	 * Both directories are walked by one method, so the wrong return type has to
	 * fail the same way in either.
	 */
	public function test_a_section_file_returning_the_wrong_type_throws(): void {
		file_put_contents( $this->plugin_dir . '/debug-sections/status.php', "<?php\nreturn 'not a section';\n" );

		$this->expectException( DiscoveryException::class );
		$this->expectExceptionMessage( 'must return an instance of' );
		$this->boot()->filter_debug_information( array() );
	}

	/**
	 * Boot the module and hand it back.
	 *
	 * @return SiteHealth
	 */
	private function boot(): SiteHealth {
		return $this->plugin->get( SiteHealth::class );
	}

	/**
	 * Drop a debug section file into the plugin's debug-sections directory.
	 *
	 * @param string $name        The section's local name.
	 * @param string $fields_body The body of fields().
	 * @param string $extra       Extra class body to place before the methods.
	 * @return void
	 */
	private function write_section( string $name, string $fields_body, string $extra = '' ): void {
		file_put_contents(
			$this->plugin_dir . '/debug-sections/' . $name . '.php',
			"<?php\n"
				. "return new class extends \\Zestry\\WPToolkit\\Modules\\SiteHealth\\DebugSection {\n"
				. $extra
				. "public function label(): string { return 'A section'; }\n"
				. "public function fields(): array { {$fields_body} }\n"
				. "};\n"
		);
	}

	/**
	 * Drop a check file into the plugin's health-checks directory.
	 *
	 * @param string $name       The check's local name.
	 * @param string $run_body   The body of run().
	 * @param string $properties Extra class body to place before the methods.
	 * @return void
	 */
	private function write_check( string $name, string $run_body, string $properties = '' ): void {
		file_put_contents(
			$this->plugin_dir . '/health-checks/' . $name . '.php',
			"<?php\n"
				. "use Zestry\\WPToolkit\\Modules\\SiteHealth\\BadgeColor;\n"
				. "return new class extends \\Zestry\\WPToolkit\\Modules\\SiteHealth\\HealthCheck {\n"
				. $properties
				. "public function label(): string { return 'A check'; }\n"
				. "public function run(): array { {$run_body} }\n"
				. "};\n"
		);
	}
}
