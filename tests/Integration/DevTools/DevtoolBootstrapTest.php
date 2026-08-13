<?php

declare( strict_types=1 );

namespace Zestry\WPToolkit\Tests\Integration\DevTools;

use Zestry\WPToolkit\Tests\Support\TestCase;

/**
 * `devtool.php`: the second Plugin instance every `wp zt` command runs inside.
 *
 * The file is executed here rather than reproduced, because reproducing it is
 * exactly what would not have caught the bug this test exists for: it kept
 * calling `set_commands_root()` for a release after that method was removed, and
 * nothing noticed. This package declares no `Plugin Name:` header, so WordPress
 * never loads it as a plugin and `wp zt` is unreachable in the test install --
 * which left this file with no coverage and no smoke path at all.
 *
 * @covers \Zestry\WPToolkit\Kernel\Plugin
 */
final class DevtoolBootstrapTest extends TestCase {

	/**
	 * Deliberately without defining `WP_CLI`. The module gates *registration* on
	 * that constant, but the initializer runs before the gate -- which is where
	 * the removed setter was called -- so the whole construction path is
	 * exercised either way. Defining it here would also define it for the rest of
	 * the suite, since a constant cannot be undefined, and `CliTest` asserts the
	 * not-under-WP-CLI branch.
	 */
	public function test_the_devtool_plugin_builds(): void {
		// Guarded by function_exists(), so requiring it twice in one process is
		// safe -- and it calls zestry_devtool() itself, which is the whole path.
		require_once dirname( __DIR__, 3 ) . '/devtool.php';

		$this->assertSame( 'zt', zestry_devtool()->get_slug() );
	}

	/**
	 * Under the fixed slug `zt`, which is what makes them `wp zt <command>`
	 * rather than commands named after whatever plugin the developer is in.
	 */
	public function test_the_commands_register_under_the_zt_namespace(): void {
		require_once dirname( __DIR__, 3 ) . '/devtool.php';

		$this->assertStringStartsWith( 'zt ', (string) zestry_devtool()->get_namespaced_name( 'doctor', ' ' ) );
	}
}
