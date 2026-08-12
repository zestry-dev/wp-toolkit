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

	public function test_read_throws_when_the_file_is_malformed(): void {
		$this->write_plugin_file( 'zestry.json', '{"namespace": "Acme\\\\Core"}' );

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
