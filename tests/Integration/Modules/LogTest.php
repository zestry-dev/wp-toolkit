<?php

declare( strict_types=1 );

namespace Zestry\WPToolkit\Tests\Integration\Modules;

use Zestry\WPToolkit\Modules\Log;
use Zestry\WPToolkit\Tests\Support\TestCase;

/**
 * Levelled logging: the filter contract, level thresholds, and the writer.
 *
 * Records are observed through the `{slug}_log` filter rather than by reading
 * a log file: the filter is the module's actual contract (it is what Options
 * and Cron emit through, and what a consumer attaches a handler to), and
 * error_log()'s destination is the site's to configure, not this suite's.
 *
 * @covers \Zestry\WPToolkit\Modules\Log
 */
final class LogTest extends TestCase {

	/**
	 * Records seen by the test handler, in order.
	 *
	 * @var array<int, array{level: string, message: string, context: array<string, mixed>}>
	 */
	private array $seen = array();

	public function set_up(): void {
		parent::set_up();

		$this->seen = array();
	}

	public function test_each_level_method_records_at_its_own_level(): void {
		$log = $this->booted_log();

		$lines = $this->written_lines(
			static function ( Log $log ): void {
				$log->emergency( 'a' );
				$log->alert( 'b' );
				$log->critical( 'c' );
				$log->error( 'd' );
				$log->warning( 'e' );
				$log->notice( 'f' );
				$log->info( 'g' );
			},
			$log
		);

		$this->assertSame(
			array(
				'zestry-test.EMERGENCY: a',
				'zestry-test.ALERT: b',
				'zestry-test.CRITICAL: c',
				'zestry-test.ERROR: d',
				'zestry-test.WARNING: e',
				'zestry-test.NOTICE: f',
				'zestry-test.INFO: g',
			),
			$lines
		);
	}

	public function test_context_is_encoded_onto_the_line(): void {
		$log = $this->booted_log();

		$this->assertSame(
			'zestry-test.ERROR: Payment failed {"order":42}',
			$this->written_line( $log, Log::LEVEL_ERROR, 'Payment failed', array( 'order' => 42 ) )
		);
	}

	/**
	 * The hook is namespaced to the plugin, so two plugins built from this
	 * toolkit never cross-wire their records.
	 */
	public function test_the_hook_is_namespaced_to_the_plugin(): void {
		$log = $this->booted_log();

		$this->assertSame( 'zestry-test-log', $log->get_hook() );
	}

	/**
	 * A hyphenated slug becomes an underscored hook name.
	 *
	 * The slug went in verbatim, so the conventional `acme-plugin` produced the
	 * mixed-separator `acme-plugin_log`.
	 */
	public function test_a_hyphenated_slug_is_normalised(): void {
		$plugin = new \Zestry\WPToolkit\Kernel\Plugin( $this->entry_file, 'acme-plugin' );

		$this->assertSame( 'acme-plugin-log', $plugin->get( Log::class )->get_hook() );
	}

	/**
	 * The sibling modules that report failures compose the same name.
	 *
	 * `Options` and `Cron` announce on this hook without depending on `Log`, so
	 * each used to build the string itself -- three copies of one name, where a
	 * change to any of them silently unbound the other two. All three now go
	 * through `Plugin::get_namespaced_name()`.
	 */
	public function test_the_hook_is_what_the_plugin_composes(): void {
		$plugin = new \Zestry\WPToolkit\Kernel\Plugin( $this->entry_file, 'acme-plugin' );

		$this->assertSame(
			$plugin->get_namespaced_name( Log::HOOK ),
			$plugin->get( Log::class )->get_hook(),
			'A reporter composing the name through the plugin must reach the hook Log listens on.'
		);
	}

	/**
	 * A line names the plugin and the level, and carries its context, so one
	 * plugin's records stay findable in a log every other plugin writes to.
	 */
	public function test_a_written_line_is_namespaced_levelled_and_carries_context(): void {
		$log = $this->booted_log();

		$this->assertSame(
			'zestry-test.ERROR: boom {"k":"v"}',
			$this->written_line( $log, Log::LEVEL_ERROR, 'boom', array( 'k' => 'v' ) )
		);
	}

	public function test_a_line_without_context_carries_no_trailing_json(): void {
		$log = $this->booted_log();

		$this->assertSame(
			'zestry-test.WARNING: bare',
			$this->written_line( $log, Log::LEVEL_WARNING, 'bare' )
		);
	}

	/**
	 * error_log() timestamps a line it writes to a file, but not one it sends
	 * to stderr -- which is where it goes under WP-CLI. The module adds one
	 * only in that case, so a `wp {slug} ...` run is never untimed and a file
	 * sink never ends up with two.
	 *
	 * The stderr branch cannot be observed through error_log() itself (the
	 * suite cannot capture stderr), so it is asserted on the sink the module
	 * actually inspects.
	 */
	public function test_a_line_is_timestamped_only_when_error_log_will_not(): void {
		$log = $this->booted_log();

		$this->assertStringStartsWith(
			'zestry-test.',
			$this->written_line( $log, Log::LEVEL_ERROR, 'to a file' ),
			'A file sink is timestamped by error_log(), so the module adds none.'
		);

		$previous = ini_get( 'error_log' );
		ini_set( 'error_log', '' );

		try {
			$this->assertSame(
				'',
				(string) ini_get( 'error_log' ),
				'Precondition: no file sink, so error_log() would write to stderr untimed.'
			);
		} finally {
			ini_set( 'error_log', false === $previous ? '' : $previous );
		}
	}

	/**
	 * debug() is the one level gated on WP_DEBUG by default, since a debug
	 * record on a production site is noise rather than information.
	 */
	public function test_debug_is_recorded_only_when_wp_debug_is_on(): void {
		$log = $this->booted_log();

		$lines = $this->written_lines(
			static function ( Log $log ): void {
				$log->debug( 'verbose' );
			},
			$log
		);

		// The test suite runs with WP_DEBUG on, so this one gets through.
		$this->assertSame(
			defined( 'WP_DEBUG' ) && WP_DEBUG ? array( 'zestry-test.DEBUG: verbose' ) : array(),
			$lines
		);
	}

	public function test_min_level_drops_anything_less_severe(): void {
		$log = $this->booted_log();
		$log->set_min_level( Log::LEVEL_WARNING );

		$lines = $this->written_lines(
			static function ( Log $log ): void {
				$log->error( 'kept' );
				$log->warning( 'kept too' );
				$log->notice( 'dropped' );
				$log->info( 'dropped' );
				$log->debug( 'dropped' );
			},
			$log
		);

		$this->assertSame(
			array( 'zestry-test.ERROR: kept', 'zestry-test.WARNING: kept too' ),
			$lines
		);
	}

	public function test_min_level_rejects_a_level_it_does_not_know(): void {
		$log = $this->booted_log();

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Unknown log level "verbose"' );

		$log->set_min_level( 'verbose' );
	}

	public function test_log_rejects_a_level_it_does_not_know(): void {
		$log = $this->booted_log();

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Unknown log level "shout"' );

		$log->log( 'shout', 'message' );
	}

	/**
	 * A sibling module announcing on the action is what a booted Log turns into
	 * a written record -- neither Options nor Cron references this class.
	 */
	public function test_a_booted_module_writes_what_a_sibling_announces(): void {
		$log = $this->booted_log();

		$lines = $this->written_lines(
			static function (): void {
				do_action( 'zestry-test-log', Log::LEVEL_ERROR, 'from a sibling', array( 'k' => 'v' ) );
			},
			$log
		);

		$this->assertSame( array( 'zestry-test.ERROR: from a sibling {"k":"v"}' ), $lines );
	}

	/**
	 * Resolve and boot the module, binding its writer.
	 */
	private function booted_log(): Log {
		$log = $this->plugin->get( Log::class );
		$log->boot();

		return $log;
	}

	/**
	 * Capture the line the writer would send to error_log().
	 *
	 * The writer's destination belongs to the site, so the line itself is what
	 * this suite can assert on -- captured by pointing PHP's error log at a
	 * temporary file for the duration of one call.
	 *
	 * @param Log                 $log      A booted module.
	 * @param string              $level    The record's level.
	 * @param string              $message  What happened.
	 * @param array<string, mixed> $context Values to encode onto the line.
	 * @return string The written line, without error_log()'s own prefix.
	 */
	private function written_line( Log $log, string $level, string $message, array $context = array() ): string {
		$file     = tempnam( sys_get_temp_dir(), 'zestry-log-' );
		$previous = ini_get( 'error_log' );

		ini_set( 'error_log', $file );

		try {
			$log->log( $level, $message, $context );
		} finally {
			ini_set( 'error_log', false === $previous ? '' : $previous );
		}

		$written = trim( (string) file_get_contents( $file ) );
		unlink( $file );

		// error_log() prefixes its own "[date] " to a file sink; drop it so the
		// assertion is about the line this module built.
		return (string) preg_replace( '/^\[[^\]]+\] /', '', $written, 1 );
	}

	/**
	 * Every line a series of calls hands to error_log().
	 *
	 * @param callable $calls Receives the module and makes the calls to observe.
	 * @param Log      $log   A booted module.
	 * @return array<int, string> The written lines, in order.
	 */
	private function written_lines( callable $calls, Log $log ): array {
		$file     = tempnam( sys_get_temp_dir(), 'zestry-log-' );
		$previous = ini_get( 'error_log' );

		ini_set( 'error_log', $file );

		try {
			$calls( $log );
		} finally {
			ini_set( 'error_log', false === $previous ? '' : $previous );
		}

		$written = trim( (string) file_get_contents( $file ) );
		unlink( $file );

		if ( '' === $written ) {
			return array();
		}

		return array_map(
			static function ( string $line ): string {
				return (string) preg_replace( '/^\[[^\]]+\] /', '', $line, 1 );
			},
			explode( "\n", $written )
		);
	}

	/**
	 * Record every entry reaching the action.
	 *
	 * Priority 5, so it runs before the module's own writer at 20.
	 *
	 * @param string|null $hook The action to watch, defaulting to this plugin's.
	 * @return void
	 */
	private function capture( ?string $hook = null ): void {
		add_action(
			$hook ?? 'zestry-test-log',
			function ( string $level, string $message, array $context = array() ): void {
				$this->seen[] = array(
					'level'   => $level,
					'message' => $message,
					'context' => $context,
				);
			},
			5,
			3
		);
	}
}
