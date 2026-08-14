<?php

declare( strict_types=1 );

namespace Zestry\WPToolkit\Tests\Integration\DevTools;

use Zestry\WPToolkit\DevTools\ZestryConfig;
use Zestry\WPToolkit\Tests\Support\TestCase;

/**
 * zestry.json existence checks, reads, and writes.
 *
 * @covers \Zestry\WPToolkit\DevTools\ZestryConfig
 */
final class ZestryConfigTest extends TestCase {

	private function zestry_config(): ZestryConfig {
		return $this->plugin->get( ZestryConfig::class );
	}

	public function test_exists_is_false_when_no_zestry_json_is_present(): void {
		$this->assertFalse( $this->zestry_config()->exists( $this->plugin_dir ) );
	}

	public function test_write_then_read_round_trips(): void {
		$config = $this->zestry_config();
		$config->write( $this->plugin_dir, 'Acme\\Plugin\\Vendor\\Core', 'lib/core', 'acme-plugin' );

		$this->assertTrue( $config->exists( $this->plugin_dir ) );
		$this->assertSame(
			array(
				'namespace'   => 'Acme\\Plugin\\Vendor\\Core',
				'root'        => 'lib/core',
				'text_domain' => 'acme-plugin',
			),
			$config->read( $this->plugin_dir )
		);
	}

	public function test_write_without_a_text_domain_round_trips_null(): void {
		$config = $this->zestry_config();
		$config->write( $this->plugin_dir, 'Acme\\Plugin\\Vendor\\Core', 'lib/core' );

		$this->assertNull( $config->read( $this->plugin_dir )['text_domain'] );
	}

	public function test_read_defaults_a_missing_text_domain_to_null(): void {
		// A project initialized before zestry.json tracked text_domain at all.
		$this->write_plugin_file(
			'zestry.json',
			json_encode( array( 'namespace' => 'Acme\\Core', 'root' => 'lib' ) )
		);

		$this->assertNull( $this->zestry_config()->read( $this->plugin_dir )['text_domain'] );
	}

	public function test_write_produces_pretty_printed_unescaped_json(): void {
		$this->zestry_config()->write( $this->plugin_dir, 'Acme\\Core', 'lib/core' );

		$contents = file_get_contents( $this->plugin_dir . '/zestry.json' );
		$this->assertStringContainsString( "\n", $contents, 'pretty-printed, not single-line' );
		$this->assertStringNotContainsString( '\\/', $contents, 'slashes are not escaped' );
	}

	public function test_read_throws_when_the_file_does_not_exist(): void {
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'Run `wp zt init` first' );

		$this->zestry_config()->read( $this->plugin_dir );
	}

	/**
	 * `root` is a fixed convention, so a file without it is complete.
	 *
	 * `init` stopped asking where the source goes and always writes `lib`, so
	 * the key records the layout rather than choosing it. A config that omits
	 * it reads back as `lib` instead of being rejected.
	 */
	public function test_read_defaults_the_root_when_it_is_absent(): void {
		$this->write_plugin_file( 'zestry.json', '{"namespace": "Acme\\\\Core"}' );

		$this->assertSame( 'lib', $this->zestry_config()->read( $this->plugin_dir )['root'] );
	}

	/**
	 * A root written by hand is still honoured, so a plugin that moved its own
	 * classes is read correctly rather than by the convention it opted out of.
	 */
	public function test_read_honours_a_root_the_file_does_name(): void {
		$this->write_plugin_file( 'zestry.json', '{"namespace": "Acme\\\\Core", "root": "app"}' );

		$this->assertSame( 'app', $this->zestry_config()->read( $this->plugin_dir )['root'] );
	}

	public function test_read_throws_when_the_namespace_is_missing(): void {
		$this->write_plugin_file( 'zestry.json', '{"root": "lib"}' );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'malformed' );

		$this->zestry_config()->read( $this->plugin_dir );
	}

	public function test_read_throws_when_the_file_is_not_valid_json(): void {
		$this->write_plugin_file( 'zestry.json', 'not json at all' );

		$this->expectException( \RuntimeException::class );

		$this->zestry_config()->read( $this->plugin_dir );
	}
}
