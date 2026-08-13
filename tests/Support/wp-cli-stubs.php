<?php
/**
 * Minimal WP-CLI doubles for the PHPUnit context.
 *
 * The WordPress test suite runs under the web/CLI SAPI without the WP-CLI phar,
 * so `WP_CLI_Command` (the Command base) and the static `WP_CLI` facade do not
 * exist. These guarded doubles let the CLI module load and record its output so
 * tests can assert against it. `WP_CLI::halt()` and `error()` deliberately do NOT
 * terminate the process — they record instead — so tests can inspect the result.
 *
 * Guarded with class_exists()/function_exists() so a real WP-CLI runtime is
 * never shadowed. Bracketed namespace syntax throughout (rather than the usual
 * unbracketed style) since this file needs to declare both a global-namespace
 * class and functions under WP_CLI\Utils.
 */

declare( strict_types=1 );

namespace {

	// Use the no-autoload form so these doubles claim the class names BEFORE the
	// real wp-cli/wp-cli dev dependency can be autoloaded on first reference. The
	// real WP_CLI runner is not initialised outside a CLI context, so the
	// toolkit's CLI module must run against these recording doubles in tests.
	if ( ! class_exists( 'WP_CLI_Command', false ) ) {
		// Command::__construct() calls parent::__construct(), so the base needs one.
		class WP_CLI_Command { // @codeCoverageIgnore
			public function __construct() {}
		}
	}

	if ( ! class_exists( 'WP_CLI', false ) ) {
		/**
		 * Records every static call so tests can assert on CLI output and registration.
		 */
		class WP_CLI { // @codeCoverageIgnore

			/**
			 * Recorded calls as [ method, ...args ] tuples.
			 *
			 * @var array<int, array<int, mixed>>
			 */
			public static array $calls = array();

			public static function reset(): void {
				self::$calls = array();
			}

			/**
			 * Return the arguments of the last recorded call to a given method.
			 *
			 * @param string $method The method name.
			 * @return array<int, mixed>|null
			 */
			public static function last( string $method ): ?array {
				for ( $i = count( self::$calls ) - 1; $i >= 0; $i-- ) {
					if ( self::$calls[ $i ][0] === $method ) {
						return array_slice( self::$calls[ $i ], 1 );
					}
				}
				return null;
			}

			public static function add_command( $name, $callable ): void {
				self::$calls[] = array( 'add_command', $name, $callable );
			}

			public static function log( $message ): void {
				self::$calls[] = array( 'log', $message );
			}

			public static function success( $message ): void {
				self::$calls[] = array( 'success', $message );
			}

			public static function warning( $message ): void {
				self::$calls[] = array( 'warning', $message );
			}

			public static function error( $message, $exit = true ): void {
				self::$calls[] = array( 'error', $message, $exit );
			}

			public static function error_multi_line( $messages ): void {
				self::$calls[] = array( 'error_multi_line', $messages );
			}

			public static function debug( $message, $group = false ): void {
				self::$calls[] = array( 'debug', $message, $group );
			}

			public static function runcommand( $command, $options = array() ): void {
				self::$calls[] = array( 'runcommand', $command, $options );
			}

			public static function halt( $code ): void {
				self::$calls[] = array( 'halt', $code );
			}
		}
	}
}

namespace WP_CLI\Utils {

	if ( ! function_exists( __NAMESPACE__ . '\get_flag_value' ) ) {
		/**
		 * @codeCoverageIgnore
		 */
		function get_flag_value( $assoc_args, $flag, $default = null ) {
			return $assoc_args[ $flag ] ?? $default;
		}
	}

	if ( ! function_exists( __NAMESPACE__ . '\format_items' ) ) {
		/**
		 * Records the call instead of rendering, so tests can assert on the
		 * items/fields/format a command asked to be displayed.
		 *
		 * @codeCoverageIgnore
		 */
		function format_items( $format, $items, $fields ): void {
			\WP_CLI::$calls[] = array( 'format_items', $format, $items, $fields );
		}
	}
}
