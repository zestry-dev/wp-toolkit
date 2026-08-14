<?php

declare( strict_types=1 );

namespace Zestry\WPToolkit\Tests\Integration\Modules;

use Zestry\WPToolkit\Kernel\Exceptions\DiscoveryException;
use Zestry\WPToolkit\Modules\CLI\CLI;
use Zestry\WPToolkit\Tests\Support\TestCase;

/**
 * WP-CLI command discovery and registration.
 *
 * Runs against the WP_CLI double from tests/Support/wp-cli-stubs.php. The WP_CLI
 * constant is process-global and irreversible, so the "not under WP-CLI" branch
 * is asserted in the FIRST test (before any test defines the constant), and the
 * discovery tests define it thereafter. Tests run in declaration order.
 *
 * @covers \Zestry\WPToolkit\Modules\CLI\CLI
 */
final class CliTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		\WP_CLI::reset();
	}

	public function test_does_nothing_when_not_running_under_wp_cli(): void {
		$this->assertFalse( defined( 'WP_CLI' ), 'Precondition: WP_CLI must be undefined for this branch.' );

		$this->write_plugin_file( 'resources/commands/greet.php', $this->command_file() );

		$cli = $this->plugin->get( CLI::class );

		$this->assertNull( \WP_CLI::last( 'add_command' ), 'No commands are registered outside WP-CLI.' );
	}

	public function test_registers_a_command_from_the_root_directory(): void {
		$this->define_wp_cli();
		$this->write_plugin_file( 'resources/commands/greet.php', $this->command_file() );

		$cli = $this->plugin->get( CLI::class );

		$registered = \WP_CLI::last( 'add_command' );
		$this->assertNotNull( $registered );
		$this->assertSame( 'zestry-test greet', $registered[0], 'Command name is "{slug} {file}".' );
		$this->assertIsCallable( $registered[1] );
	}




	public function test_nested_directories_become_command_namespaces(): void {
		$this->define_wp_cli();
		$this->write_plugin_file( 'resources/commands/cache/clear.php', $this->command_file() );

		$cli = $this->plugin->get( CLI::class );

		$this->assertSame(
			'zestry-test cache clear',
			\WP_CLI::last( 'add_command' )[0],
			'A file at commands/cache/clear.php registers as "{slug} cache clear".'
		);
	}

	public function test_a_command_is_wired_and_can_reach_a_module(): void {
		$this->define_wp_cli();
		// A discovered command is wired, so it can reach any declared module.
		$this->write_plugin_file(
			'resources/commands/needs-path.php',
			"<?php\nuse Zestry\\WPToolkit\\Modules\\CLI\\Command;\nuse Zestry\\WPToolkit\\Modules\\Path;\n"
				. "return new class extends Command {\n"
				. "    public function handle( array \$args, array \$assoc_args ): void {}\n"
				. "};\n"
		);

		$cli = $this->plugin->get( CLI::class );

		// The registered callable is a closure bound to the command (see
		// CLI::register_command_for()), so the instance comes off the binding.
		$command = ( new \ReflectionFunction( \WP_CLI::last( 'add_command' )[1] ) )->getClosureThis();
		$this->assertInstanceOf(
			\Zestry\WPToolkit\Modules\Path::class,
			$command->with( \Zestry\WPToolkit\Modules\Path::class ),
			'The command was wired, so with() reaches the plugin.'
		);
	}

	/**
	 * Adding the module before writing the first command is ordinary, so an
	 * absent default directory registers nothing rather than taking the site
	 * down.
	 */
	public function test_an_absent_default_commands_directory_registers_nothing(): void {
		$this->define_wp_cli();

		$this->plugin->get( CLI::class );
		do_action( 'init' );

		$this->assertNull(
			\WP_CLI::last( 'add_command' ),
			'Nothing is registered, and nothing throws.'
		);
	}

	public function test_a_command_name_reused_as_a_subdirectory_throws(): void {
		$this->define_wp_cli();

		// commands/test-1.php registers a leaf "test-1" command; WP-CLI's
		// Subcommand::can_have_subcommands() is hardcoded false, so a sibling
		// commands/test-1/test-2.php trying to nest beneath it would otherwise
		// only fail once WP-CLI itself tries to register the second command.
		$this->write_plugin_file( 'resources/commands/test-1.php', $this->command_file() );
		$this->write_plugin_file( 'resources/commands/test-1/test-2.php', $this->command_file() );

		// get() resolves and auto-boots the module against the default
		// 'commands' directory, same as test_missing_commands_directory_throws().
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessageMatches( '/Command name collision/' );
		$this->plugin->get( CLI::class );
	}

	public function test_a_command_name_reused_as_a_subdirectory_registers_nothing(): void {
		$this->define_wp_cli();

		$this->write_plugin_file( 'resources/commands/test-1.php', $this->command_file() );
		$this->write_plugin_file( 'resources/commands/test-1/test-2.php', $this->command_file() );

		try {
			$this->plugin->get( CLI::class );
		} catch ( \InvalidArgumentException $exception ) {
			// Expected; asserted in the previous test.
		}

		$this->assertNull( \WP_CLI::last( 'add_command' ), 'No command is registered once a collision is detected.' );
	}

	public function test_a_command_file_returning_the_wrong_type_throws(): void {
		$this->define_wp_cli();

		// An object that is not a Command: it passes register_command()'s own
		// `object` parameter type, so without the guard in load_command() it
		// would register unwired and only fail later inside handle().
		$this->write_plugin_file( 'resources/commands/bad.php', "<?php\nreturn new \\stdClass();\n" );

		$this->expectException( DiscoveryException::class );
		$this->expectExceptionMessage( 'must return an instance of' );

		$this->plugin->get( CLI::class );
	}

	public function test_register_command_still_accepts_a_non_command_object(): void {
		$this->define_wp_cli();
		$this->write_plugin_file( 'resources/commands/greet.php', $this->command_file() );

		$cli = $this->plugin->get( CLI::class );

		// The documented PHP-side escape hatch stays lenient: only file
		// discovery is strict. Migrations relies on this.
		$duck = new class() {
			public function handle( array $args, array $assoc_args ): void {}
		};
		$cli->register_command( 'duck', $duck );

		$this->assertSame( 'zestry-test duck', \WP_CLI::last( 'add_command' )[0] );
	}

	/**
	 * Define the process-global WP_CLI constant that gates discovery.
	 */
	private function define_wp_cli(): void {
		if ( ! defined( 'WP_CLI' ) ) {
			define( 'WP_CLI', true );
		}
	}

	/**
	 * A command file body returning an anonymous Command subclass.
	 */
	private function command_file(): string {
		return "<?php\nuse Zestry\\WPToolkit\\Modules\\CLI\\Command;\n"
			. "return new class extends Command {\n"
			. "    public function handle( array \$args, array \$assoc_args ): void {}\n"
			. "};\n";
	}
}
