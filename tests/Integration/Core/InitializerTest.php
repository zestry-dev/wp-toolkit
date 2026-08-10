<?php

declare( strict_types=1 );

namespace Zestry\WPToolkit\Tests\Integration\Core;

use Zestry\WPToolkit\Services\Globals;
use Zestry\WPToolkit\Tests\Support\TestCase;

/**
 * A registered initializer is stored and runs once, before the module boots.
 *
 * @covers \Zestry\WPToolkit\Kernel\ServicesRepository
 */
final class InitializerTest extends TestCase {

	public function test_a_registered_initializer_runs_with_the_module_and_plugin(): void {
		$seen = array();

		$this->plugin->configure(
			Globals::class,
			function ( $module, $plugin ) use ( &$seen ): void {
				$seen[] = array( $module, $plugin );
				$module->set( 'from_initializer', true );
			}
		);

		$globals = $this->plugin->get( Globals::class );

		$this->assertCount( 1, $seen, 'The initializer runs exactly once.' );
		$this->assertSame( $globals, $seen[0][0], 'It receives the module instance.' );
		$this->assertSame( $this->plugin, $seen[0][1], 'It receives the plugin.' );
		$this->assertTrue( $globals->get( 'from_initializer' ), 'Its side effects apply to the module.' );

		// A second resolution returns the cached instance without re-running it.
		$this->plugin->get( Globals::class );
		$this->assertCount( 1, $seen );
	}
}
