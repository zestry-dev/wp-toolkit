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

	/**
	 * WP-CLI reads help off the callable it is handed, and what it is handed is
	 * a closure with no docblock of its own -- so without the third argument
	 * every documented command still printed a bare name.
	 */
	public function test_a_commands_help_comes_from_its_handle_docblock(): void {
		$this->define_wp_cli();
		$this->write_plugin_file( 'resources/commands/greet.php', $this->documented_command_file() );

		$this->plugin->get( CLI::class );

		$help = \WP_CLI::last( 'add_command' )[2];

		$this->assertSame( 'Greets somebody.', $help['shortdesc'] );
		$this->assertStringContainsString( '## OPTIONS', $help['longdesc'] );
		$this->assertStringNotContainsString(
			'@param',
			$help['longdesc'],
			'The longdesc stops at the first tag, as DocParser does.'
		);
	}

	/**
	 * The synopsis is composed from the OPTIONS section rather than declared a
	 * second time, so the two lists cannot disagree. The `---` fences around a
	 * list of accepted values are not arguments and must not reach it: WP-CLI
	 * enforces a synopsis it can parse, so a stray token refuses the command
	 * with "Parameter errors" before it runs.
	 */
	public function test_the_synopsis_is_composed_from_the_options_section(): void {
		$this->define_wp_cli();
		$this->write_plugin_file( 'resources/commands/greet.php', $this->documented_command_file() );

		$this->plugin->get( CLI::class );

		$this->assertSame(
			'<who> [--loud] [--format=<format>] [--<field>=<value>]',
			\WP_CLI::last( 'add_command' )[2]['synopsis']
		);
	}

	/**
	 * A command that states its own synopsis keeps it: composing one is the
	 * fallback, not an override.
	 */
	public function test_an_explicit_synopsis_tag_is_kept(): void {
		$this->define_wp_cli();
		$this->write_plugin_file(
			'resources/commands/greet.php',
			"<?php\nuse Zestry\\WPToolkit\\Modules\\CLI\\Command;\n"
				. "return new class extends Command {\n"
				. "    /**\n"
				. "     * Greets somebody.\n"
				. "     *\n"
				. "     * @synopsis <who> [--loud]\n"
				. "     */\n"
				. "    public function handle( array \$args, array \$assoc_args ): void {}\n"
				. "};\n"
		);

		$this->plugin->get( CLI::class );

		$this->assertSame( '<who> [--loud]', \WP_CLI::last( 'add_command' )[2]['synopsis'] );
	}

	/**
	 * An undocumented command is registered exactly as before: nothing empty is
	 * passed, so WP-CLI's own defaults still apply.
	 */
	public function test_an_undocumented_command_passes_no_help(): void {
		$this->define_wp_cli();
		$this->write_plugin_file( 'resources/commands/greet.php', $this->command_file() );

		$this->plugin->get( CLI::class );

		$this->assertSame( array(), \WP_CLI::last( 'add_command' )[2] );
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
	 * A command file whose handle() carries the docblock WP-CLI reads help from.
	 *
	 * The `---` fenced list of accepted values is deliberate: it is the shape
	 * that a naive synopsis regex mistakes for an argument.
	 */
	private function documented_command_file(): string {
		return "<?php\nuse Zestry\\WPToolkit\\Modules\\CLI\\Command;\n"
			. "return new class extends Command {\n"
			. "    /**\n"
			. "     * Greets somebody.\n"
			. "     *\n"
			. "     * ## OPTIONS\n"
			. "     *\n"
			. "     * <who>\n"
			. "     * : Who to greet.\n"
			. "     *\n"
			. "     * [--loud]\n"
			. "     * : Shout it.\n"
			. "     *\n"
			. "     * [--format=<format>]\n"
			. "     * : Output format.\n"
			. "     * ---\n"
			. "     * default: table\n"
			. "     * options:\n"
			. "     *   - table\n"
			. "     *   - json\n"
			. "     * ---\n"
			. "     *\n"
			. "     * [--<field>=<value>]\n"
			. "     * : Any further fields.\n"
			. "     *\n"
			. "     * @param array \$args       Positional arguments.\n"
			. "     * @param array \$assoc_args Flags.\n"
			. "     * @return void\n"
			. "     */\n"
			. "    public function handle( array \$args, array \$assoc_args ): void {}\n"
			. "};\n";
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
