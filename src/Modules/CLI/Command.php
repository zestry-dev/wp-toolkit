<?php

/**
 * CLI API: Command base class
 */

declare( strict_types=1 );

namespace Zestry\WPToolkit\Modules\CLI;

// Loaded by WordPress, never requested directly.
\defined( 'ABSPATH' ) || exit;

use Zestry\WPToolkit\Kernel\Contracts\PluginAware;
use Zestry\WPToolkit\Kernel\Traits\WithPlugin;
use Zestry\WPToolkit\Kernel\Traits\WithEnablement;

/**
 * Command class.
 *
 * Base class for WP-CLI commands, providing helper methods for common output
 * and interaction patterns (log/success/error/warning/debug messages, plus
 * interactive confirm/ask prompts) so individual commands do not each re-wrap
 * the underlying `\WP_CLI` static calls.
 *
 * A command file returns an instance of a Command subclass. When that file is
 * discovered by {@see CLI}, the plugin is assigned before WP-CLI invokes
 * `handle()` -- so `$this->with( Path::class )` reaches any declared module,
 * without the command touching global state.
 *
 * A file at `resources/commands/greet.php` registers as `wp {plugin-slug} greet <name>`
 * (see {@see CLI} for how subdirectories become nested command namespaces).
 * `wp zt make command <name>` generates a starting point.
 *
 * Give `handle()` its own docblock: WP-CLI parses the `## OPTIONS` and
 * `## EXAMPLES` sections for `wp {plugin-slug} greet --help`, so it is not just
 * a comment.
 *
 * @stub command.php.stub
 */
abstract class Command extends \WP_CLI_Command implements PluginAware {

	use WithPlugin;
	use WithEnablement;

	/**
	 * The positional arguments this invocation was called with.
	 *
	 * @var array<int, string>
	 */
	private array $args = array();

	/**
	 * The named arguments this invocation was called with.
	 *
	 * @var array<string, mixed>
	 */
	private array $assoc_args = array();

	/**
	 * Prevent direct construction from bypassing plugin initialization.
	 *
	 * Declared `final` and otherwise a plain pass-through to the parent
	 * constructor so that every command's construction stays consistent with
	 * the plugin's wiring convention: a command is built with no arguments and
	 * given the plugin afterwards, not handed dependencies through constructor
	 * parameters a subclass might add.
	 *
	 * @return void
	 */
	final public function __construct() {
		parent::__construct();
	}

	/**
	 * Execute the command with positional and associative arguments.
	 *
	 * This is the entry point WP-CLI invokes to run the command; a subclass
	 * implements it with the command's actual behavior. For a command registered
	 * as `plugin cache clear`, `$args` contains unnamed values and `$assoc_args`
	 * contains values supplied as `--option=value`.
	 *
	 * @param array $args       Positional command arguments.
	 * @param array $assoc_args Named command arguments.
	 * @return void
	 */
	abstract public function handle( array $args, array $assoc_args ): void;

	/**
	 * Record the arguments this invocation was called with.
	 *
	 * Called by {@see CLI::register_command_for()} immediately before
	 * `handle()`, so a helper anywhere below it can read the invocation
	 * without every caller in between passing the arguments along. `handle()`
	 * still receives both, since that is the signature WP-CLI documents and
	 * the one a command reads its own input from.
	 *
	 * @param array<int, string>   $args       Positional command arguments.
	 * @param array<string, mixed> $assoc_args Named command arguments.
	 * @return void
	 * @internal
	 */
	final public function set_arguments( array $args, array $assoc_args ): void {
		$this->args       = $args;
		$this->assoc_args = $assoc_args;
	}

	/**
	 * The positional arguments this invocation was called with.
	 *
	 * Empty for a command invoked outside {@see CLI::register_command_for()},
	 * which is the only caller that records them.
	 *
	 * @return array<int, string>
	 */
	final public function get_args(): array {
		return $this->args;
	}

	/**
	 * The named arguments this invocation was called with.
	 *
	 * Reading them here rather than from `handle()`'s own parameter is what
	 * lets a helper check a flag without the arguments being passed down to
	 * it. {@see confirm()} and {@see ask()} read `--yes` this way.
	 *
	 * @example Reading a flag away from handle()
	 *
	 * ```
	 * public function handle( array $args, array $assoc_args ): void {
	 *     $this->purge();
	 * }
	 *
	 * private function purge(): void {
	 *     if ( ! empty( $this->get_assoc_args()['dry-run'] ) ) {
	 *         $this->log( 'Would purge 12 entries.' );
	 *         return;
	 *     }
	 *
	 *     // ...
	 * }
	 * ```
	 *
	 * @return array<string, mixed>
	 */
	final public function get_assoc_args(): array {
		return $this->assoc_args;
	}

	/**
	 * Log a message.
	 *
	 * @param string $message The message to log.
	 * @return void
	 */
	final public function log( string $message ): void {
		\WP_CLI::log( $message );
	}

	/**
	 * Display a success message.
	 *
	 * @param string $message The success message to display.
	 * @return void
	 */
	final public function success( string $message ): void {
		\WP_CLI::success( $message );
	}

	/**
	 * Display an error message.
	 *
	 * @param string $message       The error message to display.
	 * @param bool   $exit_on_error Whether to exit execution (default true).
	 * @return void
	 */
	final public function error( string $message, bool $exit_on_error = true ): void {
		\WP_CLI::error( $message, $exit_on_error );
	}

	/**
	 * Display an error box with multiple lines.
	 *
	 * Accepts a single message or a collection of them; a plain string is split
	 * on `\n` into separate lines, and anything else is normalized to an array
	 * before being handed to WP-CLI's multi-line error display.
	 *
	 * @param string|\WP_Error|\Exception|\Throwable|\WP_Error[]|\Exception[]|\Throwable[]|string[] $message The error message(s) to display.
	 * @param bool|int $exit_code If true, exits with code 1; if an integer, exits with that code; if false, does not exit.
	 * @return void
	 */
	final public function error_box( $message, bool|int $exit_code = true ): void {
		$message = \is_string( $message ) ? \explode( "\n", $message ) : $message;
		$message = \is_array( $message ) ? $message : array( $message );
		\WP_CLI::error_multi_line( $message );

		$exit_code = $exit_code === true ? 1 : $exit_code;
		if ( \is_int( $exit_code ) ) {
			\WP_CLI::halt( $exit_code );
		}
	}

	/**
	 * Display a warning message.
	 *
	 * @param string $message The warning message to display.
	 * @return void
	 */
	final public function warning( string $message ): void {
		\WP_CLI::warning( $message );
	}

	/**
	 * Display a debug message.
	 *
	 * The plugin slug is always prepended to `$group` (or used alone when
	 * `$group` is omitted), so this plugin's debug output can be isolated with
	 * WP-CLI's `--debug=<group>` flag independently of other plugins' output.
	 *
	 * @param string       $message The debug message to display.
	 * @param string|false $group   Optional group name for organizing debug output.
	 * @return void
	 */
	final public function debug( string $message, string|false $group = false ): void {
		// Prefix groups so this plugin's debugging output can be filtered together.
		$group_prefix = $this->get_plugin()->get_slug();
		$group        = $group === false ? $group_prefix : $group_prefix . ':' . $group;

		\WP_CLI::debug( $message, $group );
	}

	/**
	 * Prompt the user for confirmation.
	 *
	 * Writes the prompt to STDOUT with a `[Y/n]`/`[y/N]` hint reflecting
	 * `$default_yes`, then reads one line from STDIN. Any answer other than an
	 * explicit `y` or `n` (including empty input) falls back to `$default_yes`.
	 *
	 * `--yes` on the invocation answers the prompt affirmatively without
	 * reading STDIN -- WP-CLI's own convention for running a confirming command
	 * unattended ({@see \WP_CLI::confirm()}). Note the difference from core's
	 * version, which exits the process on any other answer: this returns the
	 * answer, so the caller decides what a "no" means.
	 *
	 * @param string $message     The confirmation prompt message.
	 * @param bool   $default_yes Default to yes if no input provided (default false).
	 * @return bool True if user confirms, false otherwise.
	 */
	final public function confirm( string $message, bool $default_yes = false ): bool {
		// --yes is an answer, not a default: it stands in for the input rather
		// than for the prompt, so nothing is written and nothing is read.
		if ( ! empty( $this->assoc_args['yes'] ) ) {
			return true;
		}

		// The uppercase choice communicates which answer is selected on empty input.
		$yn       = $default_yes ? 'Y/n' : 'y/N';
		$response = $default_yes ? true : false;

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		\fwrite( STDOUT, \sprintf( '%s [%s] ', $message, $yn ) );

		// See read_line() for why the false-at-EOF result is guarded here.
		$input  = $this->read_line();
		$answer = \strtolower( \trim( $input === false ? '' : $input ) );
		if ( $answer === 'y' ) {
			$response = true;
		} elseif ( $answer === 'n' ) {
			$response = false;
		}

		return $response;
	}

	/**
	 * Prompt the user for input.
	 *
	 * Writes the prompt to STDOUT, appending `$fallback` to the displayed message
	 * when one is given, then reads one line from STDIN. Empty or whitespace-only
	 * input returns `$fallback` rather than an empty string.
	 *
	 * `--yes` on the invocation takes `$fallback` without reading STDIN.
	 * WP-CLI has no free-text counterpart to {@see \WP_CLI::confirm()}, so this
	 * reads `--yes` as "do not ask me anything" -- the flag a caller running
	 * unattended already passes. A prompt whose fallback is an empty string has
	 * no answer to assume, so it is the caller's job to reject that rather than
	 * re-ask, which under `--yes` would never return a different value.
	 *
	 * @param string $message  The input prompt message.
	 * @param string $fallback Default value if user provides no input.
	 * @return string The user input or default value.
	 */
	final public function ask( string $message, string $fallback = '' ): string {
		if ( ! empty( $this->assoc_args['yes'] ) ) {
			return $fallback;
		}

		// Include the fallback in the prompt so interactive use is self-documenting.
		$message = $fallback ? \sprintf( '%s (default: %s)', $message, $fallback ) : $message;

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		\fwrite( STDOUT, \sprintf( '%s ', $message ) );

		// See read_line() for why the false-at-EOF result is guarded here.
		$input  = $this->read_line();
		$answer = \trim( $input === false ? '' : $input );
		if ( empty( $answer ) ) {
			return $fallback;
		}

		return $answer;
	}

	/**
	 * Halt the command with a return code.
	 *
	 * @param int $return_code The exit code (0 for success, non-zero for error).
	 * @return void
	 */
	final public function halt( int $return_code = 0 ): void {
		\WP_CLI::halt( $return_code );
	}

	/**
	 * Run another WP-CLI command.
	 *
	 * For reaching a command WP-CLI already ships rather than reimplementing what
	 * it does -- `wp config set` knows where `wp-config.php` is, where in it a
	 * constant belongs, and how to quote one, none of which is worth writing
	 * twice.
	 *
	 * Runs in this process rather than launching a second WordPress, so it costs
	 * no bootstrap and its output lands in this run's.
	 *
	 * @param string $command The command line, without the leading `wp`.
	 * @return void
	 */
	final public function run_command( string $command ): void {
		\WP_CLI::runcommand( $command, array( 'launch' => false ) );
	}

	/**
	 * Read a single line from standard input.
	 *
	 * Isolated so interactive prompts can be exercised in tests by overriding this
	 * seam; production reads from STDIN.
	 *
	 * Callers must not pass the result straight to `trim()`: this method returns
	 * `false` at end of input (matching `fgets()`'s own contract), and under
	 * `declare( strict_types=1 )` `trim()` rejects a non-string argument rather
	 * than coercing it. Every caller is expected to guard for `false` first, for
	 * example `$input === false ? '' : $input`, before trimming.
	 *
	 * @return string|false The raw input line, or false on EOF.
	 */
	protected function read_line() {
		return \fgets( STDIN ); // @codeCoverageIgnore
	}
}
