<?php

declare( strict_types=1 );

namespace Zestry\WPToolkit\Tests\Integration\Core;

use Zestry\WPToolkit\Kernel\Abstracts\Module;
use Zestry\WPToolkit\Tests\Support\TestCase;

/**
 * Module::on_wp_init(), both sides of the branch.
 *
 * `Plugin::run()` does its work on `init`, so a module resolved through it is
 * already inside the action and a plain add_action() would never fire. One
 * resolved with get() from the entry file is ahead of it, and running
 * immediately would be too early. Every discovery module registers through this.
 *
 * @covers \Zestry\WPToolkit\Kernel\Abstracts\Module::on_wp_init
 */
final class OnWpInitTest extends TestCase {

	public function test_it_runs_immediately_when_init_has_fired(): void {
		$module = $this->plugin->get( RunAtInitProbe::class );
		$ran    = 0;

		// The test suite boots WordPress fully, so init is behind us here --
		// the same position a module resolved by Plugin::run() is in.
		$this->assertGreaterThan( 0, did_action( 'init' ) );

		$passed = null;

		$module->on_wp_init(
			static function ( $received ) use ( &$ran, &$passed ): void {
				++$ran;
				$passed = $received;
			}
		);

		$this->assertSame( 1, $ran, 'Called inline rather than hooked to an action already past.' );
		$this->assertSame( $module, $passed, 'The module is handed to the callback.' );
	}

	/**
	 * The pre-init branch, which the suite cannot reach naturally: `init` is
	 * long gone by the time a test runs, so the counter is reset to stand in
	 * for a module resolved from the entry file.
	 */
	public function test_it_hooks_init_when_init_has_not_fired(): void {
		$module   = $this->plugin->get( RunAtInitProbe::class );
		$ran      = 0;
		$previous = $GLOBALS['wp_actions']['init'] ?? 0;

		unset( $GLOBALS['wp_actions']['init'] );

		try {
			$passed = null;

			$module->on_wp_init(
				static function ( $received ) use ( &$ran, &$passed ): void {
					++$ran;
					$passed = $received;
				}
			);

			$this->assertSame( 0, $ran, 'Deferred, not run.' );
			$this->assertGreaterThan( 0, (int) has_action( 'init' ) );

			do_action( 'init' );

			$this->assertSame( 1, $ran );

			// Not the empty string WordPress hands a no-argument action's
			// callback, which is what hooking the callable directly would give.
			$this->assertSame( $module, $passed );
		} finally {
			$GLOBALS['wp_actions']['init'] = $previous;
		}
	}
}

/**
 * A module with no behaviour of its own, for reaching the helper.
 */
final class RunAtInitProbe extends Module {

	protected function on_boot(): void {}
}
