<!--
    Generated from src/Modules/CLI/Command.php.
    Do not edit by hand: run `composer docs` after changing the source.
-->

# Command

[Generated starting point](#generated-starting-point) &nbsp;·&nbsp; [You must implement](#you-must-implement) &nbsp;·&nbsp; [Methods you can use](#methods-you-can-use)

Base class for file-based WP-CLI commands.

Provides helper methods for common output and interaction patterns (log/success/error/warning/debug messages, plus interactive confirm/ask prompts) so individual commands do not each re-wrap the underlying `\WP_CLI` static calls.

A command file returns an instance of a Command subclass. When that file is discovered by `CLI`, the plugin is assigned before WP-CLI invokes `handle()` — so `$this->with( Path::class )` reaches any declared module, without the command touching global state.

A file at `resources/commands/greet.php` registers as `wp {plugin-slug} greet <name>` (see `CLI` for how subdirectories become nested command namespaces). `wp zt make command <name>` generates a starting point.

Give `handle()` its own docblock: WP-CLI parses the `## OPTIONS` and `## EXAMPLES` sections for `wp {plugin-slug} greet --help`, so it is not just a comment.

## Generated starting point

[`wp zt make command <name>`](../../commands/make-command.md) writes this file:

```php
<?php
/**
 * example WP-CLI command.
 */

declare( strict_types=1 );

// Loaded by WordPress, never requested directly.
\defined( 'ABSPATH' ) || exit;

use Acme\Plugin\Core\Modules\CLI\Command;

return new class() extends Command {

	// The command is this file's name -- wp {plugin-slug} example. Renaming
	// the file renames the command, so a deploy script or a runbook calling the
	// old one fails.

	/**
	 * Example.
	 *
	 * Keep this docblock's ## OPTIONS/## EXAMPLES sections filled in --
	 * WP-CLI parses them for this command's own `--help` output, so this is
	 * not just a comment:
	 *
	 * ## OPTIONS
	 *
	 * <name>
	 * : An example positional argument.
	 *
	 * ## EXAMPLES
	 *
	 *     wp {plugin-slug} example Alice
	 *
	 * @param array $args       Positional arguments -- e.g. [ $name ] for the example above.
	 * @param array $assoc_args Named arguments supplied as --option=value.
	 * @return void
	 */
	public function handle( array $args, array $assoc_args ): void {
		$this->success( 'Done.' );
	}
};
```

## You must implement

This one method is abstract: a subclass that does not declare it will not load.

### `handle( $args, $assoc_args )`

Execute the command with positional and associative arguments.

```php
abstract public function handle( array $args, array $assoc_args ): void
```

|  | Details |
|---|---|
| **Parameters** | `$args` — Positional command arguments<br>`$assoc_args` — Named command arguments |
| **Return** | — |
| **Throws** | — |

This is the entry point WP-CLI invokes to run the command; a subclass implements it with the command's actual behavior. For a command registered as `plugin cache clear`, `$args` contains unnamed values and `$assoc_args` contains values supplied as `--option=value`.

## Methods you can use

### `get_args()`

The positional arguments this invocation was called with.

```php
final public function get_args(): array
```

|  | Details |
|---|---|
| **Parameters** | — |
| **Return** | `array` |
| **Throws** | — |

The same values `handle()` is given, reachable from a helper that was never passed them. `get_assoc_args()` is the named half.

<br>

### `get_assoc_args()`

The named arguments this invocation was called with.

```php
final public function get_assoc_args(): array
```

|  | Details |
|---|---|
| **Parameters** | — |
| **Return** | `array` |
| **Throws** | — |

Reading them here rather than from `handle()`'s own parameter is what lets a helper check a flag without the arguments being passed down to it. `confirm()` and `ask()` read `--yes` this way.

<br>

### `log( $message )`

Log a message.

```php
final public function log( string $message ): void
```

|  | Details |
|---|---|
| **Parameters** | `$message` — The message to log |
| **Return** | — |
| **Throws** | — |

<br>

### `success( $message )`

Display a success message.

```php
final public function success( string $message ): void
```

|  | Details |
|---|---|
| **Parameters** | `$message` — The success message to display |
| **Return** | — |
| **Throws** | — |

<br>

### `error( $message, $exit_on_error )`

Display an error message.

```php
final public function error( string $message, bool $exit_on_error = true ): void
```

|  | Details |
|---|---|
| **Parameters** | `$message` — The error message to display<br>`$exit_on_error` — Whether to exit execution (default true) |
| **Return** | — |
| **Throws** | — |

<br>

### `error_box( $message, $exit_code )`

Display an error box with multiple lines.

```php
final public function error_box( $message, bool|int $exit_code = true ): void
```

|  | Details |
|---|---|
| **Parameters** | `$message` — The error message(s) to display<br>`$exit_code` — If true, exits with code 1; if an integer, exits with that code; if false, does not exit |
| **Return** | — |
| **Throws** | — |

Accepts a single message or a collection of them; a plain string is split on `\n` into separate lines, and anything else is normalized to an array before being handed to WP-CLI's multi-line error display.

<br>

### `warning( $message )`

Display a warning message.

```php
final public function warning( string $message ): void
```

|  | Details |
|---|---|
| **Parameters** | `$message` — The warning message to display |
| **Return** | — |
| **Throws** | — |

<br>

### `debug( $message, $group )`

Display a debug message.

```php
final public function debug( string $message, string|false $group = false ): void
```

|  | Details |
|---|---|
| **Parameters** | `$message` — The debug message to display<br>`$group` — Optional group name for organizing debug output |
| **Return** | — |
| **Throws** | — |

The plugin slug is always prepended to `$group` (or used alone when `$group` is omitted), so this plugin's debug output can be isolated with WP-CLI's `--debug=<group>` flag independently of other plugins' output.

<br>

### `confirm( $message, $default_yes )`

Prompt the user for confirmation.

```php
final public function confirm( string $message, bool $default_yes = false ): bool
```

|  | Details |
|---|---|
| **Parameters** | `$message` — The confirmation prompt message<br>`$default_yes` — Default to yes if no input provided (default false) |
| **Return** | True if user confirms, false otherwise |
| **Throws** | — |

Writes the prompt to STDOUT with a `[Y/n]`/`[y/N]` hint reflecting `$default_yes`, then reads one line from STDIN. Any answer other than an explicit `y` or `n` (including empty input) falls back to `$default_yes`.

`--yes` on the invocation answers the prompt affirmatively without reading STDIN — WP-CLI's own convention for running a confirming command unattended (`WP_CLI::confirm()`). Note the difference from core's version, which exits the process on any other answer: this returns the answer, so the caller decides what a "no" means.

<br>

### `ask( $message, $fallback )`

Prompt the user for input.

```php
final public function ask( string $message, string $fallback = '' ): string
```

|  | Details |
|---|---|
| **Parameters** | `$message` — The input prompt message<br>`$fallback` — Default value if user provides no input |
| **Return** | The user input or default value |
| **Throws** | — |

Writes the prompt to STDOUT, appending `$fallback` to the displayed message when one is given, then reads one line from STDIN. Empty or whitespace-only input returns `$fallback` rather than an empty string.

`--yes` on the invocation takes `$fallback` without reading STDIN. WP-CLI has no free-text counterpart to `WP_CLI::confirm()`, so this reads `--yes` as "do not ask me anything" — the flag a caller running unattended already passes. A prompt whose fallback is an empty string has no answer to assume, so it is the caller's job to reject that rather than re-ask, which under `--yes` would never return a different value.

<br>

### `halt( $return_code )`

Halt the command with a return code.

```php
final public function halt( int $return_code = 0 ): void
```

|  | Details |
|---|---|
| **Parameters** | `$return_code` — The exit code (0 for success, non-zero for error) |
| **Return** | — |
| **Throws** | — |

<br>

### `run_command( $command )`

Run another WP-CLI command.

```php
final public function run_command( string $command ): void
```

|  | Details |
|---|---|
| **Parameters** | `$command` — The command line, without the leading `wp` |
| **Return** | — |
| **Throws** | — |

For reaching a command WP-CLI already ships rather than reimplementing what it does — `wp config set` knows where `wp-config.php` is, where in it a constant belongs, and how to quote one, none of which is worth writing twice.

Runs in this process rather than launching a second WordPress, so it costs no bootstrap and its output lands in this run's.

<br>

### `read_line()`

Read a single line from standard input.

```php
protected function read_line()
```

|  | Details |
|---|---|
| **Parameters** | — |
| **Return** | The raw input line, or false on EOF |
| **Throws** | — |

Isolated so interactive prompts can be exercised in tests by overriding this seam; production reads from STDIN.

Callers must not pass the result straight to `trim()`: this method returns `false` at end of input (matching `fgets()`'s own contract), and under `declare( strict_types=1 )` `trim()` rejects a non-string argument rather than coercing it. Every caller is expected to guard for `false` first, for example `$input === false ? '' : $input`, before trimming.

<br>

### `get_plugin()`

*Inherited from [`WithPlugin`](../../kernel/with-plugin.md).*

Get the plugin this class belongs to.

```php
final public function get_plugin(): Plugin
```

|  | Details |
|---|---|
| **Parameters** | — |
| **Return** | The plugin instance |
| **Throws** | — |

<br>

### `with( $name )`

*Inherited from [`WithPlugin`](../../kernel/with-plugin.md).*

Reach another module.

```php
final public function with( string $name ): object
```

|  | Details |
|---|---|
| **Parameters** | `$name` — The module class to reach |
| **Return** | The shared instance |
| **Throws** | `ModuleException` — If it is not declared, or has not booted yet |

<br>

### `is_enabled()`

*Inherited from [`WithEnablement`](../../kernel/with-enablement.md).*

Whether this should be registered at all.

```php
public function is_enabled(): bool
```

|  | Details |
|---|---|
| **Parameters** | — |
| **Return** | `bool` |
| **Throws** | — |
