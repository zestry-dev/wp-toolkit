<?php

declare( strict_types=1 );

namespace Zestry\WPToolkit\Tests\Integration\DevTools;

use Zestry\WPToolkit\Kernel\Plugin;
use Zestry\WPToolkit\Modules\CLI\Command;
use Zestry\WPToolkit\Tests\Support\TestCase;

/**
 * `wp zestry describe`: what a plugin has, where each module looks, and what a
 * file dropped there must return.
 *
 * Everything it reports is derived -- from registry.php, the project's
 * zestry.json, its bootstrap.php and the files on disk -- so the assertions here
 * are mostly that a fact still comes out of the source it is derived from, and
 * that nothing is restated in a second place that could disagree.
 *
 * @coversNothing
 */
final class DescribeCommandTest extends TestCase {

	private string $target_plugin_dir = '';

	public function set_up(): void {
		parent::set_up();

		$this->target_plugin_dir = untrailingslashit( WP_PLUGIN_DIR ) . '/zestry-describe-test-' . uniqid();
		mkdir( $this->target_plugin_dir, 0777, true );

		file_put_contents(
			$this->target_plugin_dir . '/zestry.json',
			(string) json_encode(
				array(
					'namespace'   => 'Acme\\Plugin',
					'root'        => 'lib',
					'text_domain' => 'acme-plugin',
				)
			)
		);
	}

	public function tear_down(): void {
		$this->remove_dir( $this->target_plugin_dir );
		parent::tear_down();
	}

	public function test_reports_the_project_identity_from_zestry_json(): void {
		$this->run_describe();

		$this->assertStringContainsString( 'Acme\\Plugin -> lib/', $this->logged_messages()[0] );
		$this->assertStringContainsString( 'acme-plugin', $this->logged_messages()[0] );
	}

	/**
	 * Every discovery module names its own directory with a `DEFAULT_*_ROOT`
	 * constant, so this is read from the module rather than listed anywhere
	 * here. A module with two roots reports both without being a special case.
	 */
	public function test_reports_each_module_default_directory_and_base_class(): void {
		$rows = $this->describe_rows();

		$this->assertSame( 'actions/', $rows['ajax']['reads'] );
		$this->assertSame( 'AjaxAction', $rows['ajax']['returns'] );

		$this->assertSame( 'commands/', $rows['cli']['reads'] );
		$this->assertSame( 'Command', $rows['cli']['returns'] );

		// Two roots, one module, no special case.
		$this->assertSame( 'post-types/, taxonomies/', $rows['post-types']['reads'] );
		$this->assertSame( 'PostType, Taxonomy', $rows['post-types']['returns'] );
	}

	/**
	 * A module that discovers nothing has nothing to report, rather than a made
	 * up directory.
	 */
	public function test_a_module_that_discovers_nothing_reports_no_directory(): void {
		$rows = $this->describe_rows();

		$this->assertSame( '', $rows['options']['reads'] );
		$this->assertSame( '', $rows['log']['reads'] );
	}

	/**
	 * The generator column is read off the loaded command classes, not a list
	 * kept here -- so a `make` type added later appears without this being
	 * touched.
	 */
	public function test_reports_the_make_command_that_writes_a_file_for_each_module(): void {
		$rows = $this->describe_rows();

		$this->assertSame( 'action', $rows['ajax']['make'] );
		$this->assertSame( 'schedule', $rows['cron']['make'] );
		$this->assertSame( 'page', $rows['admin-pages']['make'] );
	}

	public function test_reports_what_is_installed_and_what_is_not(): void {
		$this->install( 'lib/Core/Modules/Cron/Cron.php' );

		$rows = $this->describe_rows();

		$this->assertTrue( $rows['cron']['installed'] );
		$this->assertFalse( $rows['ajax']['installed'] );
	}

	/**
	 * The one silent failure the Service/Module split leaves: on disk, and
	 * never built because nothing lists it.
	 */
	public function test_reports_an_installed_module_that_nothing_declares(): void {
		$this->install( 'lib/Core/Modules/Cron/Cron.php' );

		$rows = $this->describe_rows();

		$this->assertFalse( $rows['cron']['declared'] );

		file_put_contents(
			$this->target_plugin_dir . '/bootstrap.php',
			"<?php\nuse Acme\\Plugin\\Core\\Modules\\Cron\\Cron;\nreturn array( Cron::class );\n"
		);

		$this->assertTrue( $this->describe_rows()['cron']['declared'] );
	}

	/**
	 * A service is built the moment something asks for it, so one that is not
	 * declared is doing exactly what it should. Reporting it as undeclared
	 * would be reporting every service in the registry as a problem.
	 */
	public function test_a_service_is_never_reported_as_undeclared(): void {
		$rows = $this->describe_rows();

		$this->assertTrue( $rows['path']['declared'] );
		$this->assertTrue( $rows['views']['declared'] );
	}

	/**
	 * The directory reported is the default, and an initializer can point the
	 * module somewhere else. Running that closure would mean building the
	 * consumer's modules, which this command does not do -- so it says an
	 * initializer exists and leaves the reader to look.
	 */
	public function test_marks_a_module_whose_entry_carries_an_initializer(): void {
		file_put_contents(
			$this->target_plugin_dir . '/bootstrap.php',
			"<?php\nuse Acme\\Plugin\\Core\\Modules\\Cron\\Cron;\n"
				. "return array( Cron::class => static function ( Cron \$cron ): void { \$cron->set_schedules_root( 'jobs' ); } );\n"
		);

		$rows = $this->describe_rows();

		$this->assertTrue( $rows['cron']['configured'] );
		$this->assertSame( 'schedules/', $rows['cron']['reads'], 'The default, since the closure is never run.' );
	}

	public function test_installed_flag_limits_the_report_to_what_is_there(): void {
		$this->install( 'lib/Core/Modules/Cron/Cron.php' );

		$rows = $this->describe_rows( array( 'installed' => true ) );

		$this->assertArrayHasKey( 'cron', $rows );
		$this->assertArrayNotHasKey( 'ajax', $rows );
	}

	public function test_kind_flag_limits_the_report_to_one_side(): void {
		$rows = $this->describe_rows( array( 'kind' => 'services' ) );

		$this->assertArrayHasKey( 'path', $rows );
		$this->assertArrayNotHasKey( 'cli', $rows );
	}

	public function test_requires_an_initialized_plugin(): void {
		unlink( $this->target_plugin_dir . '/zestry.json' );

		$this->run_describe();

		$this->assertSame( 'error', $this->last_call()[0] );
		$this->assertStringContainsString( 'wp zestry init', $this->last_call()[1] );
	}

	/**
	 * The one thing someone opening a repository cold cannot find out any other
	 * way: that every file in a directory goes through a class of the plugin's
	 * own before it reaches the toolkit's.
	 *
	 * @return void
	 */
	public function test_names_the_intermediate_every_file_in_a_directory_extends(): void {
		$this->write_discovered( 'fields/acme_rating.php', 'EntityField', 'Acme\\Plugin\\Abstracts\\EntityField' );
		$this->write_discovered( 'fields/acme_note.php', 'EntityField', 'Acme\\Plugin\\Abstracts\\EntityField' );

		$rows = $this->describe_rows();

		$this->assertSame( 'fields/ 2 files via Acme\\Plugin\\Abstracts\\EntityField', $rows['fields']['via'] );
	}

	/**
	 * One directory of the two-root module can have an intermediate while the
	 * other has none, so each is answered separately.
	 *
	 * @return void
	 */
	public function test_each_root_of_a_two_root_module_answers_for_itself(): void {
		$this->write_discovered( 'post-types/acme_book.php', 'EntityPostType', 'Acme\\Plugin\\Abstracts\\EntityPostType' );
		$this->write_discovered( 'taxonomies/acme_genre.php', 'Taxonomy', 'Acme\\Plugin\\Core\\Modules\\PostTypes\\Taxonomy' );

		$rows = $this->describe_rows();

		$this->assertStringContainsString( 'post-types/ 1 file via Acme\\Plugin\\Abstracts\\EntityPostType', $rows['post-types']['via'] );
		$this->assertStringNotContainsString(
			'taxonomies/',
			$rows['post-types']['via'],
			'Extending the toolkit base is not going via anything.'
		);
	}

	/**
	 * "Mostly" is worse than nothing: a directory where one file goes direct has
	 * no shared parent to name, and the report says nothing rather than
	 * something almost true.
	 *
	 * @return void
	 */
	public function test_a_mixed_directory_names_no_intermediate(): void {
		$this->write_discovered( 'fields/acme_rating.php', 'EntityField', 'Acme\\Plugin\\Abstracts\\EntityField' );
		$this->write_discovered( 'fields/acme_note.php', 'Field', 'Acme\\Plugin\\Core\\Modules\\Fields\\Field' );

		$rows = $this->describe_rows();

		$this->assertSame( '', $rows['fields']['via'] );
	}

	/**
	 * Nothing here loads a consumer class, so a file whose parent does not exist
	 * is still reported -- which is the plugin most in need of describing.
	 *
	 * @return void
	 */
	public function test_the_intermediate_is_read_rather_than_loaded(): void {
		$this->write_discovered( 'fields/acme_rating.php', 'NeverDefined', 'Acme\\Plugin\\Abstracts\\NeverDefined' );

		$rows = $this->describe_rows();

		$this->assertStringContainsString( 'Acme\\Plugin\\Abstracts\\NeverDefined', $rows['fields']['via'] );
		$this->assertFalse( class_exists( 'Acme\\Plugin\\Abstracts\\NeverDefined' ), 'And it was never loaded.' );
	}

	/**
	 * Write a discovered file extending a named class, as a consumer's would be.
	 *
	 * @param string $relative Path within the plugin.
	 * @param string $short    The class it extends, as written.
	 * @param string $import   The `use` line that qualifies it.
	 * @return void
	 */
	private function write_discovered( string $relative, string $short, string $import ): void {
		$path = $this->target_plugin_dir . '/' . $relative;

		if ( ! is_dir( dirname( $path ) ) ) {
			mkdir( dirname( $path ), 0777, true );
		}

		file_put_contents(
			$path,
			"<?php\n\nuse " . $import . ";\n\nreturn new class() extends " . $short . " {\n};\n"
		);
	}

	/**
	 * Put a file where a copied module's would be. The content does not matter:
	 * `describe` reports what is on disk, and never loads a consumer's copy.
	 *
	 * @param string $relative Path within the plugin.
	 * @return void
	 */
	private function install( string $relative ): void {
		$path = $this->target_plugin_dir . '/' . $relative;

		if ( ! is_dir( dirname( $path ) ) ) {
			mkdir( dirname( $path ), 0777, true );
		}

		file_put_contents( $path, '<?php // copied' );
	}

	/**
	 * The described entries, keyed by name, as the structured formats emit them.
	 *
	 * @param array<string, mixed> $assoc_args Flags for the command.
	 * @return array<string, array<string, mixed>>
	 */
	private function describe_rows( array $assoc_args = array() ): array {
		$this->run_describe( array_merge( array( 'format' => 'json' ), $assoc_args ) );

		list( , , $items ) = $this->last_call();

		$rows = array();

		foreach ( $items as $item ) {
			$rows[ $item['name'] ] = $item;
		}

		return $rows;
	}

	/**
	 * @param array<string, mixed> $assoc_args Flags for the command.
	 * @return void
	 */
	private function run_describe( array $assoc_args = array() ): void {
		\WP_CLI::reset();

		$package_plugin = new Plugin( dirname( __DIR__, 3 ) . '/plugin.php', 'zestry-describe-test' );

		/** @var Command $command */
		$command = require dirname( __DIR__, 3 ) . '/commands/describe.php';
		$package_plugin->wire( $command );

		$previous_cwd = (string) getcwd();
		chdir( $this->target_plugin_dir );

		try {
			$command->handle( array(), $assoc_args );
		} finally {
			chdir( $previous_cwd );
		}
	}

	/**
	 * @return array<int, mixed>
	 */
	private function last_call(): array {
		$calls = \WP_CLI::$calls;

		return $calls[ count( $calls ) - 1 ];
	}

	/**
	 * @return string[]
	 */
	private function logged_messages(): array {
		$messages = array();

		foreach ( \WP_CLI::$calls as $call ) {
			if ( 'log' === $call[0] ) {
				$messages[] = (string) $call[1];
			}
		}

		return $messages;
	}
}
