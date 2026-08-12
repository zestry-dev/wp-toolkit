<?php

declare( strict_types=1 );

namespace Zestry\WPToolkit\Tests\Integration\DevTools;

use Zestry\WPToolkit\DevTools\Copier;
use Zestry\WPToolkit\Tests\Support\TestCase;

/**
 * Recursive copy, per-file copy, dependency resolution, and namespace
 * rewriting.
 *
 * @covers \Zestry\WPToolkit\DevTools\Copier
 */
final class CopierTest extends TestCase {

	private function copier(): Copier {
		return $this->plugin->get( Copier::class );
	}

	public function test_copy_file_rewrites_the_namespace_declaration(): void {
		$source = $this->write_plugin_file(
			'source/Widget.php',
			"<?php\n\ndeclare( strict_types=1 );\n\nnamespace Zestry\\WPToolkit\\Modules;\n\nclass Widget {}\n"
		);
		$destination = $this->plugin_dir . '/dest/Widget.php';

		$this->copier()->copy_file( $source, $destination, 'Acme\\Plugin\\Vendor\\Core' );

		$this->assertStringContainsString( 'namespace Acme\\Plugin\\Vendor\\Core\\Modules;', file_get_contents( $destination ) );
	}

	public function test_copy_file_rewrites_use_imports(): void {
		$source = $this->write_plugin_file(
			'source/RestApi.php',
			"<?php\n\nnamespace Zestry\\WPToolkit\\Modules\\RestApi;\n\nuse Zestry\\WPToolkit\\Kernel\\Abstracts\\Module;\nuse Zestry\\WPToolkit\\Services\\Path;\n\nclass RestApi extends Module {}\n"
		);
		$destination = $this->plugin_dir . '/dest/RestApi.php';

		$this->copier()->copy_file( $source, $destination, 'Acme\\Plugin\\Vendor\\Core' );

		$contents = file_get_contents( $destination );
		$this->assertStringContainsString( 'namespace Acme\\Plugin\\Vendor\\Core\\Modules\\RestApi;', $contents );
		$this->assertStringContainsString( 'use Acme\\Plugin\\Vendor\\Core\\Kernel\\Abstracts\\Module;', $contents );
		$this->assertStringContainsString( 'use Acme\\Plugin\\Vendor\\Core\\Services\\Path;', $contents );
	}

	/**
	 * A name written out where it is used, rather than imported, is still a name
	 * — and a copied file naming `\Zestry\WPToolkit\...` names a class the plugin does not
	 * have, which fails only when that line runs.
	 */
	public function test_copy_file_rewrites_a_name_written_out_in_full(): void {
		$source = $this->write_plugin_file(
			'source/Ability.php',
			"<?php\n\nnamespace Zestry\\WPToolkit\\Modules\\Abilities;\n\nclass Ability {\n"
				. "    public function schema(): array {\n"
				. "        return \$this->get_plugin()->get( \\Zestry\\WPToolkit\\Services\\Request\\Request::class )->get_schema( \$this );\n"
				. "    }\n\n"
				. "    private function type(): \\Zestry\\WPToolkit\\Modules\\Fields\\MetaType {\n"
				. "        return \\Zestry\\WPToolkit\\Modules\\Fields\\MetaType::Post;\n"
				. "    }\n}\n"
		);
		$destination = $this->plugin_dir . '/dest/Ability.php';

		$this->copier()->copy_file( $source, $destination, 'Acme\\Plugin\\Core' );

		$contents = (string) file_get_contents( $destination );

		$this->assertStringContainsString( 'get( \\Acme\\Plugin\\Core\\Services\\Request\\Request::class )', $contents );
		$this->assertStringContainsString( '): \\Acme\\Plugin\\Core\\Modules\\Fields\\MetaType {', $contents );
		$this->assertStringContainsString( 'return \\Acme\\Plugin\\Core\\Modules\\Fields\\MetaType::Post;', $contents );
		$this->assertStringNotContainsString( 'Zestry\\WPToolkit\\', $contents, 'Nothing naming the toolkit survives the copy.' );
	}

	/**
	 * A name belonging to someone else keeps its own leading separator and its
	 * own namespace.
	 */
	public function test_copy_file_leaves_a_name_outside_the_toolkit_alone(): void {
		$source = $this->write_plugin_file(
			'source/Route.php',
			"<?php\n\nnamespace Zestry\\WPToolkit\\Modules\\RestApi;\n\nclass Route {\n"
				. "    public function handle( \\WP_REST_Request \$request ): \\WP_REST_Response {\n"
				. "        return new \\WP_REST_Response( array() );\n"
				. "    }\n}\n"
		);
		$destination = $this->plugin_dir . '/dest/Route.php';

		$this->copier()->copy_file( $source, $destination, 'Acme\\Plugin\\Core' );

		$contents = (string) file_get_contents( $destination );

		$this->assertStringContainsString( 'handle( \\WP_REST_Request $request ): \\WP_REST_Response {', $contents );
		$this->assertStringContainsString( 'new \\WP_REST_Response( array() )', $contents );
	}

	public function test_copy_file_does_not_touch_zestry_appearing_in_a_string_or_comment(): void {
		$source = $this->write_plugin_file(
			'source/Notice.php',
			"<?php\n\nnamespace Zestry\\WPToolkit\\Modules;\n\n// Zestry is mentioned here in a comment.\nclass Notice {\n    public string \$label = 'Zestry Notice';\n}\n"
		);
		$destination = $this->plugin_dir . '/dest/Notice.php';

		$this->copier()->copy_file( $source, $destination, 'Acme\\Core' );

		$contents = file_get_contents( $destination );
		$this->assertStringContainsString( '// Zestry is mentioned here in a comment.', $contents );
		$this->assertStringContainsString( "'Zestry Notice'", $contents );
		$this->assertStringContainsString( 'namespace Acme\\Core\\Modules;', $contents );
	}

	public function test_copy_file_rewrites_the_text_domain_when_given(): void {
		$source = $this->write_plugin_file(
			'source/Notice.php',
			"<?php\nnamespace Zestry\\WPToolkit\\Modules;\nclass Notice {\n    public function label(): string {\n        return __( 'Hello', 'zestry-toolkit' );\n    }\n}\n"
		);
		$destination = $this->plugin_dir . '/dest/Notice.php';

		$this->copier()->copy_file( $source, $destination, 'Acme\\Core', 'acme-plugin' );

		$contents = file_get_contents( $destination );
		$this->assertStringContainsString( "__( 'Hello', 'acme-plugin' )", $contents );
		$this->assertStringNotContainsString( 'zestry-toolkit', $contents );
	}

	public function test_copy_file_rewrites_a_double_quoted_text_domain(): void {
		$source = $this->write_plugin_file(
			'source/Notice.php',
			"<?php\nnamespace Zestry\\WPToolkit\\Modules;\nclass Notice {\n    public function label(): string {\n        return __( \"Hello\", \"zestry-toolkit\" );\n    }\n}\n"
		);
		$destination = $this->plugin_dir . '/dest/Notice.php';

		$this->copier()->copy_file( $source, $destination, 'Acme\\Core', 'acme-plugin' );

		$this->assertStringContainsString( '"acme-plugin"', file_get_contents( $destination ) );
	}

	public function test_copy_file_leaves_the_text_domain_untouched_when_none_is_given(): void {
		$source = $this->write_plugin_file(
			'source/Notice.php',
			"<?php\nnamespace Zestry\\WPToolkit\\Modules;\nclass Notice {\n    public function label(): string {\n        return __( 'Hello', 'zestry-toolkit' );\n    }\n}\n"
		);
		$destination = $this->plugin_dir . '/dest/Notice.php';

		$this->copier()->copy_file( $source, $destination, 'Acme\\Core' );

		$this->assertStringContainsString( "'zestry-toolkit'", file_get_contents( $destination ) );
	}

	public function test_copy_file_does_not_touch_an_unrelated_string_containing_the_text_domain_as_a_substring(): void {
		$source = $this->write_plugin_file(
			'source/Notice.php',
			"<?php\nnamespace Zestry\\WPToolkit\\Modules;\nclass Notice {\n    public string \$label = 'this is not-wp-toolkit-exactly';\n}\n"
		);
		$destination = $this->plugin_dir . '/dest/Notice.php';

		$this->copier()->copy_file( $source, $destination, 'Acme\\Core', 'acme-plugin' );

		$this->assertStringContainsString( "'this is not-wp-toolkit-exactly'", file_get_contents( $destination ) );
	}

	public function test_copy_directory_propagates_the_text_domain_to_every_php_file(): void {
		$this->write_plugin_file(
			'source/Modules/One.php',
			"<?php\nnamespace Zestry\\WPToolkit\\Modules;\nclass One {\n    public function x(): string { return __( 'One', 'zestry-toolkit' ); }\n}\n"
		);
		$this->write_plugin_file(
			'source/Modules/Sub/Two.php',
			"<?php\nnamespace Zestry\\WPToolkit\\Modules\\Sub;\nclass Two {\n    public function x(): string { return __( 'Two', 'zestry-toolkit' ); }\n}\n"
		);

		$source      = $this->plugin_dir . '/source/Modules';
		$destination = $this->plugin_dir . '/dest/Modules';

		$this->copier()->copy_directory( $source, $destination, 'Acme\\Core', 'acme-plugin' );

		$this->assertStringContainsString( "'acme-plugin'", file_get_contents( $destination . '/One.php' ) );
		$this->assertStringContainsString( "'acme-plugin'", file_get_contents( $destination . '/Sub/Two.php' ) );
	}

	public function test_copy_file_leaves_a_non_php_file_untouched(): void {
		$source      = $this->write_plugin_file( 'source/readme.txt', 'Zestry is a toolkit.' );
		$destination = $this->plugin_dir . '/dest/readme.txt';

		$this->copier()->copy_file( $source, $destination, 'Acme\\Core' );

		$this->assertSame( 'Zestry is a toolkit.', file_get_contents( $destination ) );
	}

	public function test_copy_file_throws_when_the_source_does_not_exist(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->copier()->copy_file( $this->plugin_dir . '/missing.php', $this->plugin_dir . '/dest.php', 'Acme\\Core' );
	}

	public function test_copy_directory_recursively_copies_and_rewrites_every_php_file(): void {
		$this->write_plugin_file( 'source/Modules/RestApi/RestApi.php', "<?php\nnamespace Zestry\\WPToolkit\\Modules\\RestApi;\nclass RestApi {}\n" );
		$this->write_plugin_file( 'source/Modules/RestApi/Attributes/RestThing.php', "<?php\nnamespace Zestry\\WPToolkit\\Modules\\RestApi\\Attributes;\nclass RestThing {}\n" );
		$this->write_plugin_file( 'source/Modules/RestApi/notes.md', '# Notes' );

		$source      = $this->plugin_dir . '/source/Modules/RestApi';
		$destination = $this->plugin_dir . '/dest/RestApi';

		$this->copier()->copy_directory( $source, $destination, 'Acme\\Core' );

		$this->assertStringContainsString(
			'namespace Acme\\Core\\Modules\\RestApi;',
			file_get_contents( $destination . '/RestApi.php' )
		);
		$this->assertStringContainsString(
			'namespace Acme\\Core\\Modules\\RestApi\\Attributes;',
			file_get_contents( $destination . '/Attributes/RestThing.php' )
		);
		$this->assertSame( '# Notes', file_get_contents( $destination . '/notes.md' ) );
	}

	public function test_copy_directory_throws_when_the_source_does_not_exist(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->copier()->copy_directory( $this->plugin_dir . '/missing', $this->plugin_dir . '/dest', 'Acme\\Core' );
	}

	public function test_resolve_dependencies_includes_transitive_dependencies_once(): void {
		$registry = array(
			'path'     => array(
				'source'  => 'Path.php',
				'depends' => array(),
			),
			'rest-api' => array(
				'source'  => 'RestApi',
				'depends' => array( 'path' ),
			),
		);

		$resolved = $this->copier()->resolve_dependencies( array( 'rest-api' ), $registry );

		$this->assertSame( array( 'path', 'rest-api' ), $resolved );
	}

	public function test_resolve_dependencies_deduplicates_a_shared_dependency(): void {
		$registry = array(
			'path'   => array(
				'source'  => 'Path.php',
				'depends' => array(),
			),
			'views'  => array(
				'source'  => 'Views.php',
				'depends' => array( 'path' ),
			),
			'assets' => array(
				'source'  => 'Assets.php',
				'depends' => array( 'path' ),
			),
		);

		$resolved = $this->copier()->resolve_dependencies( array( 'views', 'assets' ), $registry );

		$this->assertSame( array( 'path', 'views', 'assets' ), $resolved );
	}

	public function test_resolve_dependencies_throws_for_an_unknown_module(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->copier()->resolve_dependencies( array( 'not-a-real-module' ), array() );
	}

	/**
	 * Each section reads correctly on its own, so a name in both looks fine in
	 * the file while making `wp zt add <name>` install whichever section was
	 * read last. A single array could not hide this; two can.
	 */
	public function test_flatten_registry_refuses_a_name_declared_in_both_sections(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'declared in both' );

		Copier::flatten_registry(
			array(
				'services' => array( 'cache' => array( 'source' => 'A', 'depends' => array() ) ),
				'modules'  => array( 'cache' => array( 'source' => 'B', 'depends' => array() ) ),
			)
		);
	}

	public function test_flatten_registry_carries_the_section_each_entry_was_filed_under(): void {
		$flat = Copier::flatten_registry( require dirname( __DIR__, 3 ) . '/src/DevTools/registry.php' );

		$this->assertSame( 'services', $flat['path']['section'] );
		$this->assertSame( 'modules', $flat['ajax']['section'] );
		// depends comes back merged: the closure crosses the two freely.
		$this->assertContains( 'path', $flat['migrations']['depends'] );
		$this->assertContains( 'cli', $flat['migrations']['depends'] );
	}
}
