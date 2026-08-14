<?php

declare( strict_types=1 );

namespace Zestry\WPToolkit\Tests\Integration\DevTools;

use Zestry\WPToolkit\Kernel\Plugin;
use Zestry\WPToolkit\Modules\CLI\Command;
use Zestry\WPToolkit\Tests\Support\TestCase;

/**
 * `wp zt debug`: the per-plugin debug constant.
 *
 * The writing itself is WP-CLI's `wp config`, so what is pinned here is the
 * constant name -- which has to be the one `Plugin::is_plugin_debug()` reads, or
 * the command turns on something nothing looks at -- and that reporting never
 * writes.
 *
 * @covers \Zestry\WPToolkit\Kernel\Plugin
 */
final class DebugCommandTest extends TestCase {

	private string $target_plugin_dir = '';

	public function set_up(): void {
		parent::set_up();

		$this->target_plugin_dir = untrailingslashit( WP_PLUGIN_DIR ) . '/zestry-debug-test-' . uniqid();
		mkdir( $this->target_plugin_dir, 0777, true );
	}

	public function tear_down(): void {
		$this->remove_dir( $this->target_plugin_dir );
		parent::tear_down();
	}

	/**
	 * The command names the constant from the plugin slug, and `is_plugin_debug()`
	 * reads it from the same rule -- so a command that spelled it its own way
	 * would turn on a constant nothing looks at.
	 */
	public function test_the_constant_is_the_one_is_plugin_debug_reads(): void {
		$this->assertSame( 'ACME_CRM_DEBUG', Plugin::get_debug_constant( 'acme-crm' ) );

		define( 'ACME_CRM_DEBUG', true );

		$this->assertTrue( ( new Plugin( $this->target_plugin_dir . '/acme.php', 'acme-crm' ) )->is_plugin_debug() );
	}

	public function test_turning_it_on_writes_the_constant(): void {
		$this->run_debug( array( 'on' ) );

		$command = (string) \WP_CLI::last( 'runcommand' )[0];

		$this->assertStringContainsString( 'config set', $command );
		$this->assertStringContainsString( '_DEBUG true --raw', $command );
	}

	/**
	 * Deleted rather than set false: the constant is absent by default, so a line
	 * saying so is one more thing to read past in wp-config.php.
	 */
	public function test_turning_it_off_removes_the_constant(): void {
		$this->run_debug( array( 'off' ) );

		// Already off in this process, so there is nothing to remove and the
		// command says so rather than writing.
		$this->assertNull( \WP_CLI::last( 'runcommand' ) );
		$this->assertStringContainsString( 'already off', (string) \WP_CLI::last( 'success' )[0] );
	}

	public function test_reporting_writes_nothing(): void {
		$this->run_debug( array() );

		$this->assertNull( \WP_CLI::last( 'runcommand' ) );
		$this->assertStringContainsString( 'is off.', (string) \WP_CLI::last( 'log' )[0] );
	}

	public function test_a_state_that_is_neither_is_refused(): void {
		$this->run_debug( array( 'maybe' ) );

		$this->assertNull( \WP_CLI::last( 'runcommand' ) );
		$this->assertStringContainsString( 'Say `on` or `off`', (string) \WP_CLI::last( 'error' )[0] );
	}

	/**
	 * Wire and run the real commands/debug.php against the throwaway plugin.
	 *
	 * @param string[] $args Positional arguments.
	 * @return void
	 */
	private function run_debug( array $args ): void {
		\WP_CLI::reset();

		$package_plugin = ( new Plugin( dirname( __DIR__, 3 ) . '/plugin.php', 'zestry-debug-test' ) )->declare_modules( $this->get_toolkit_modules() );

		/** @var Command $command */
		$command = require dirname( __DIR__, 3 ) . '/commands/debug.php';

		$package_plugin->wire( $command );

		$previous_cwd = (string) getcwd();
		chdir( $this->target_plugin_dir );

		try {
			$command->handle( $args, array() );
		} finally {
			chdir( $previous_cwd );
		}
	}
}
