<?php

declare( strict_types=1 );

namespace Zestry\WPToolkit\Tests\Integration\DevTools;

use Zestry\WPToolkit\DevTools\ConsumerPlugin;
use Zestry\WPToolkit\Tests\Support\TestCase;

/**
 * Resolving the target plugin's root directory from a given path.
 *
 * @covers \Zestry\WPToolkit\DevTools\ConsumerPlugin
 */
final class ConsumerPluginTest extends TestCase {

	private function consumer_plugin(): ConsumerPlugin {
		return $this->plugin->get( ConsumerPlugin::class );
	}

	public function test_resolves_the_plugin_root_when_cwd_is_the_plugin_root_itself(): void {
		$plugin_dir = untrailingslashit( WP_PLUGIN_DIR ) . '/example-plugin';

		$this->assertSame(
			wp_normalize_path( $plugin_dir ),
			$this->consumer_plugin()->get_plugin_root_for( $plugin_dir )
		);
	}

	public function test_resolves_the_plugin_root_when_cwd_is_a_subdirectory(): void {
		$cwd = untrailingslashit( WP_PLUGIN_DIR ) . '/example-plugin/vendor/zestry-dev/wp-toolkit';

		$this->assertSame(
			wp_normalize_path( untrailingslashit( WP_PLUGIN_DIR ) . '/example-plugin' ),
			$this->consumer_plugin()->get_plugin_root_for( $cwd )
		);
	}

	public function test_throws_when_cwd_is_not_inside_the_plugins_directory(): void {
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'Run `wp zestry` from inside the plugin you want to set up' );

		$this->consumer_plugin()->get_plugin_root_for( untrailingslashit( WP_CONTENT_DIR ) );
	}

	public function test_throws_when_cwd_is_the_plugins_directory_itself(): void {
		// The plugins directory itself is not inside any one plugin.
		$this->expectException( \RuntimeException::class );

		$this->consumer_plugin()->get_plugin_root_for( untrailingslashit( WP_PLUGIN_DIR ) );
	}

	public function test_get_plugin_root_uses_the_real_current_working_directory(): void {
		$previous_cwd = (string) getcwd();
		$plugin_dir   = untrailingslashit( WP_PLUGIN_DIR ) . '/example-plugin';
		mkdir( $plugin_dir, 0777, true );

		try {
			chdir( $plugin_dir );
			$this->assertSame( wp_normalize_path( $plugin_dir ), $this->consumer_plugin()->get_plugin_root() );
		} finally {
			chdir( $previous_cwd );
			rmdir( $plugin_dir );
		}
	}
}
