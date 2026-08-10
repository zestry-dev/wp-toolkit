<?php

declare( strict_types=1 );

namespace Zestry\WPToolkit\Tests\Integration\Modules;

use Zestry\WPToolkit\Modules\CLI\Command;
use Zestry\WPToolkit\Tests\Support\TestCase;

/**
 * The Command base class helpers, asserted against the WP_CLI double.
 *
 * @covers \Zestry\WPToolkit\Modules\CLI\Command
 */
final class CommandTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		\WP_CLI::reset();
	}

	/**
	 * Build a wired command whose read_line() returns a scripted input.
	 *
	 * @param string|false $input What read_line() should return.
	 * @return Command
	 */
	private function command( $input = false ): Command {
		// Command::__construct() is final, so the scripted input is set after
		// construction rather than through a constructor.
		$command                 = new class() extends Command {
			/** @var string|false */
			public $scripted_input = false;

			public function handle( array $args, array $assoc_args ): void {}

			protected function read_line() {
				return $this->scripted_input;
			}
		};
		$command->scripted_input = $input;

		// Wire it so debug() has the plugin slug available.
		$this->plugin->wire( $command );

		return $command;
	}

	public function test_log_success_and_warning_forward_the_message(): void {
		$command = $this->command();

		$command->log( 'logged' );
		$command->success( 'done' );
		$command->warning( 'careful' );

		$this->assertSame( array( 'logged' ), \WP_CLI::last( 'log' ) );
		$this->assertSame( array( 'done' ), \WP_CLI::last( 'success' ) );
		$this->assertSame( array( 'careful' ), \WP_CLI::last( 'warning' ) );
	}

	public function test_error_forwards_the_message_and_exit_flag(): void {
		$this->command()->error( 'boom', false );

		$this->assertSame( array( 'boom', false ), \WP_CLI::last( 'error' ) );
	}

	public function test_error_box_normalises_a_string_and_halts_with_code_one(): void {
		$this->command()->error_box( "line 1\nline 2" );

		$this->assertSame( array( array( 'line 1', 'line 2' ) ), \WP_CLI::last( 'error_multi_line' ) );
		$this->assertSame( array( 1 ), \WP_CLI::last( 'halt' ), 'exit_code true maps to halt(1).' );
	}

	public function test_error_box_accepts_an_array_and_a_custom_int_code(): void {
		$this->command()->error_box( array( 'a', 'b' ), 7 );

		$this->assertSame( array( array( 'a', 'b' ) ), \WP_CLI::last( 'error_multi_line' ) );
		$this->assertSame( array( 7 ), \WP_CLI::last( 'halt' ) );
	}

	public function test_error_box_wraps_a_single_object_and_can_skip_halting(): void {
		$error = new \WP_Error( 'code', 'message' );

		$this->command()->error_box( $error, false );

		$this->assertSame( array( array( $error ) ), \WP_CLI::last( 'error_multi_line' ), 'A single non-string/array is wrapped in an array.' );
		$this->assertNull( \WP_CLI::last( 'halt' ), 'exit_code false must not halt.' );
	}

	public function test_debug_uses_the_plugin_slug_as_the_default_group(): void {
		$this->command()->debug( 'trace' );

		$this->assertSame( array( 'trace', 'zestry-test' ), \WP_CLI::last( 'debug' ) );
	}

	public function test_debug_prefixes_a_named_group_with_the_slug(): void {
		$this->command()->debug( 'trace', 'cache' );

		$this->assertSame( array( 'trace', 'zestry-test:cache' ), \WP_CLI::last( 'debug' ) );
	}

	public function test_halt_forwards_the_return_code(): void {
		$this->command()->halt( 3 );

		$this->assertSame( array( 3 ), \WP_CLI::last( 'halt' ) );
	}

	/**
	 * @dataProvider confirm_inputs
	 */
	public function test_confirm_maps_input_to_a_boolean( $input, bool $default_yes, bool $expected ): void {
		$this->assertSame( $expected, $this->command( $input )->confirm( 'Sure?', $default_yes ) );
	}

	public function confirm_inputs(): array {
		return array(
			'y confirms'                => array( "y\n", false, true ),
			'n declines'                => array( "n\n", true, false ),
			'empty keeps default (no)'  => array( "\n", false, false ),
			'empty keeps default (yes)' => array( "\n", true, true ),
			'eof keeps default (yes)'   => array( false, true, true ),
			'other input keeps default' => array( "maybe\n", false, false ),
		);
	}

	public function test_ask_returns_the_typed_answer(): void {
		$this->assertSame( 'Ada', $this->command( "Ada\n" )->ask( 'Name?' ) );
	}

	public function test_ask_returns_the_fallback_on_empty_input(): void {
		$this->assertSame( 'anon', $this->command( "\n" )->ask( 'Name?', 'anon' ) );
	}

	/**
	 * --yes stands in for the answer, not for the prompt: nothing is read, so a
	 * scripted "n" that would otherwise decline is never consulted.
	 */
	public function test_confirm_is_answered_by_the_yes_flag(): void {
		$command = $this->command( "n\n" );
		$command->set_arguments( array(), array( 'yes' => true ) );

		$this->assertTrue( $command->confirm( 'Sure?', false ) );
	}

	/**
	 * The free-text counterpart: WP-CLI has no --yes for ask(), so the flag is
	 * read as "do not ask me anything" and the fallback stands in.
	 */
	public function test_ask_returns_the_fallback_under_the_yes_flag(): void {
		$command = $this->command( "Ada\n" );
		$command->set_arguments( array(), array( 'yes' => true ) );

		$this->assertSame( 'anon', $command->ask( 'Name?', 'anon' ) );
	}

	public function test_the_prompts_are_unaffected_without_the_flag(): void {
		$command = $this->command( "n\n" );
		$command->set_arguments( array( 'positional' ), array( 'other' => 1 ) );

		$this->assertFalse( $command->confirm( 'Sure?', true ) );
	}

	public function test_the_recorded_arguments_are_readable(): void {
		$command = $this->command();
		$command->set_arguments( array( 'one' ), array( 'flag' => 'value' ) );

		$this->assertSame( array( 'one' ), $command->get_args() );
		$this->assertSame( array( 'flag' => 'value' ), $command->get_assoc_args() );
	}

	/**
	 * A command built outside CLI::register_command_for() has no invocation to
	 * report, so the accessors answer empty rather than being undefined.
	 */
	public function test_the_arguments_default_to_empty(): void {
		$command = $this->command();

		$this->assertSame( array(), $command->get_args() );
		$this->assertSame( array(), $command->get_assoc_args() );
	}

	public function test_ask_returns_the_fallback_on_eof(): void {
		$this->assertSame( 'anon', $this->command( false )->ask( 'Name?', 'anon' ) );
	}
}
