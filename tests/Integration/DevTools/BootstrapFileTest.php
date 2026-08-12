<?php

declare( strict_types=1 );

namespace Zestry\WPToolkit\Tests\Integration\DevTools;

use Zestry\WPToolkit\DevTools\BootstrapFile;
use Zestry\WPToolkit\DevTools\RuntimePlugin;
use Zestry\WPToolkit\Kernel\Plugin;
use Zestry\WPToolkit\Tests\Support\TestCase;

/**
 * Which file the devtools read module declarations from, and write them to.
 *
 * `bootstrap.php` beside the entry file is the default and very nearly always
 * the answer. `Plugin::bootstrap()` takes any path, though, and nothing on disk
 * records which was used -- so this was assumed, and assuming it wrong is not
 * cosmetic: doctor reports every module as undeclared, and `wp zt add`
 * appends a declaration to a file the plugin never reads, copying a module in
 * and leaving it inert with no error anywhere.
 *
 * @covers \Zestry\WPToolkit\DevTools\BootstrapFile
 */
final class BootstrapFileTest extends TestCase {

	private BootstrapFile $bootstrap_file;

	public function set_up(): void {
		parent::set_up();

		$this->bootstrap_file = $this->plugin->get( BootstrapFile::class );

		unset( $GLOBALS[ RuntimePlugin::REGISTRY ] );
	}

	public function tear_down(): void {
		unset( $GLOBALS[ RuntimePlugin::REGISTRY ] );
		parent::tear_down();
	}

	public function test_reads_the_file_the_running_plugin_was_pointed_at(): void {
		mkdir( $this->plugin_dir . '/config', 0777, true );
		file_put_contents(
			$this->plugin_dir . '/config/modules.php',
			"<?php\nuse Acme\\Plugin\\Core\\Modules\\Cron\\Cron;\nreturn array( Cron::class );\n"
		);

		$this->publish( $this->plugin_dir . '/config/modules.php' );

		$this->assertSame(
			array( 'Acme\Plugin\Core\Modules\Cron\Cron' ),
			array_keys( $this->bootstrap_file->read_declarations( $this->plugin_dir ) )
		);
	}

	public function test_reports_the_file_it_reads(): void {
		$this->publish( $this->plugin_dir . '/config/modules.php' );

		$this->assertSame( 'config/modules.php', $this->bootstrap_file->get_display_path( $this->plugin_dir ) );
	}

	/**
	 * `exists()` has to follow too, or a plugin using a custom path is reported
	 * as having no declarations file at all.
	 */
	public function test_exists_follows_the_custom_path(): void {
		mkdir( $this->plugin_dir . '/config', 0777, true );
		file_put_contents( $this->plugin_dir . '/config/modules.php', "<?php\nreturn array();\n" );

		$this->publish( $this->plugin_dir . '/config/modules.php' );

		$this->assertTrue( $this->bootstrap_file->exists( $this->plugin_dir ) );
	}

	/**
	 * And a declaration has to be appended where the plugin will read it.
	 */
	public function test_declares_into_the_custom_path(): void {
		mkdir( $this->plugin_dir . '/config', 0777, true );
		file_put_contents( $this->plugin_dir . '/config/modules.php', "<?php\nreturn array();\n" );

		$this->publish( $this->plugin_dir . '/config/modules.php' );

		$this->bootstrap_file->declare_module( $this->plugin_dir, 'Acme\Plugin\Core\Modules\Cron\Cron' );

		$this->assertStringContainsString(
			'Cron::class',
			(string) file_get_contents( $this->plugin_dir . '/config/modules.php' )
		);
		$this->assertFileDoesNotExist( $this->plugin_dir . '/bootstrap.php' );
	}

	/**
	 * The default, and the right answer for a plugin that is not running or that
	 * declares its modules in the entry file instead.
	 */
	public function test_falls_back_to_bootstrap_php(): void {
		$this->assertSame( 'bootstrap.php', $this->bootstrap_file->get_display_path( $this->plugin_dir ) );
	}

	/**
	 * A running plugin that never called `bootstrap()` has no answer to give,
	 * which is different from having one this cannot see.
	 */
	public function test_falls_back_when_the_plugin_never_bootstrapped(): void {
		$GLOBALS[ RuntimePlugin::REGISTRY ][ $this->plugin_dir ] = new Plugin(
			$this->plugin_dir . '/acme-plugin.php',
			'acme-plugin'
		);

		$this->assertSame( 'bootstrap.php', $this->bootstrap_file->get_display_path( $this->plugin_dir ) );
	}

	/**
	 * A path outside the plugin is still what that plugin reads, so it is used
	 * -- and shown in full, since shortening it against a root it does not sit
	 * under would be nonsense.
	 */
	public function test_shows_a_path_outside_the_plugin_in_full(): void {
		$this->publish( '/srv/shared/modules.php' );

		$this->assertSame( '/srv/shared/modules.php', $this->bootstrap_file->get_display_path( $this->plugin_dir ) );
	}

	/**
	 * Stand in for a plugin WordPress has loaded, whose entry file called
	 * `bootstrap( $path )`.
	 *
	 * @param string $path What it was pointed at.
	 * @return void
	 */
	private function publish( string $path ): void {
		$running = new Plugin( $this->plugin_dir . '/acme-plugin.php', 'acme-plugin' );
		$running->bootstrap( $path );

		$GLOBALS[ RuntimePlugin::REGISTRY ][ $this->plugin_dir ] = $running;
	}
}
