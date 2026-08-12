<?php

declare( strict_types=1 );

namespace Zestry\WPToolkit\Tests\Integration\Modules;

use Zestry\WPToolkit\Kernel\Exceptions\DiscoveryException;
use Zestry\WPToolkit\Modules\Assets\Assets;
use Zestry\WPToolkit\Tests\Support\TestCase;

/**
 * Asset URL composition, handle namespacing, and the register/enqueue/inline
 * wrappers around WordPress's script and style APIs.
 *
 * @covers \Zestry\WPToolkit\Modules\Assets\Assets
 */
final class AssetsTest extends TestCase {

	/**
	 * The handle prefix the build composes for this test plugin's own things.
	 */
	private const PREFIX = 'zestry-test-';

	public function tear_down(): void {
		// wp_deregister_*() only unsets the handle from the registered list --
		// it does not remove it from the queue -- so an enqueue in one test
		// would otherwise still show up in wp_scripts()->queue during the next.
		$handles = array(
			'app',
			'widgets',
			'theme',
			'settings',
			'cart',
			'admin-theme',
			'index',
			'collections',
			'shared-formatting',
			'shared-styled',
			'shared-collections',
		);

		foreach ( $handles as $handle ) {
			$handle = self::PREFIX . $handle;

			wp_dequeue_script( $handle );
			wp_deregister_script( $handle );
			wp_dequeue_style( $handle );
			wp_deregister_style( $handle );
		}

		wp_deregister_script_module( '@zestry-test/runtime' );
		wp_deregister_script_module( 'zestry-test-cart' );

		parent::tear_down();
	}

	public function test_registers_a_shared_package_under_the_handle_the_build_composed(): void {
		$this->write_manifest(
			array(
				'zestry-test-shared-formatting' => array(
					'source'       => 'shared',
					'dependencies' => array( 'wp-element' ),
					'version'      => 'abc123',
				),
			)
		);

		$this->boot_assets();

		$this->assertTrue( wp_script_is( 'zestry-test-shared-formatting', 'registered' ) );

		$script = wp_scripts()->registered['zestry-test-shared-formatting'];

		// Read, not composed: this is the handle the build already wrote into
		// every importer's own .asset.php.
		$this->assertSame( array( 'wp-element' ), $script->deps );
		$this->assertSame( 'abc123', $script->ver );
		$this->assertStringEndsWith( 'build/shared/formatting.js', $script->src );
	}

	/**
	 * The `shared` segment is what keeps the two namespaces apart. Without it
	 * both compose `zestry-test-collections`, and WordPress keeps whichever
	 * registered first and discards the other without a word.
	 */
	public function test_an_entry_and_a_package_of_one_name_get_distinct_handles(): void {
		$this->write_manifest(
			array(
				'zestry-test-collections'        => array(),
				'zestry-test-shared-collections' => array( 'source' => 'shared' ),
			)
		);

		$assets = $this->boot_assets();

		$this->assertTrue( wp_script_is( 'zestry-test-collections', 'registered' ) );
		$this->assertTrue( wp_script_is( 'zestry-test-shared-collections', 'registered' ) );

		// Each still answers to the same local name, in its own set.
		$this->assertSame( 'zestry-test-collections', $assets->enqueue_entry( 'collections' ) );
		$this->assertSame( 'zestry-test-shared-collections', $assets->get_shared_handle( 'collections' ) );

		$this->assertStringEndsWith(
			'build/entries/collections.js',
			wp_scripts()->registered['zestry-test-collections']->src
		);
		$this->assertStringEndsWith(
			'build/shared/collections.js',
			wp_scripts()->registered['zestry-test-shared-collections']->src
		);
	}

	/**
	 * `--experimental-modules` runs two compilations into one directory, so each
	 * writes its own manifest. Reading only the first loses every module entry.
	 */
	public function test_registers_a_module_package_from_the_second_manifest(): void {
		$this->write_manifest( array( 'zestry-test-index' => array() ) );
		$this->write_manifest(
			array( '@zestry-test/runtime' => array( 'source' => 'shared', 'kind' => 'module' ) ),
			'build',
			'assets-module-manifest.php'
		);

		$this->boot_assets();

		// There is no wp_script_module_is(), so this asserts through what the
		// module actually prints -- which also proves the src resolved.
		wp_enqueue_script_module( '@zestry-test/runtime' );
		$printed = get_echo( array( wp_script_modules(), 'print_enqueued_script_modules' ) );

		// The id is the npm name because that is the specifier the built
		// JavaScript imports, and what an importer's own .asset.php names.
		$this->assertStringContainsString( 'id="@zestry-test/runtime-js-module"', $printed );
		$this->assertStringContainsString( 'build/shared/runtime.js', $printed );
	}

	public function test_registers_a_package_stylesheet_when_the_build_produced_one(): void {
		$this->write_manifest(
			array(
				'zestry-test-shared-styled' => array(
					'source' => 'shared',
					'css'    => 'shared/styled.css',
				),
			)
		);

		$this->boot_assets();

		$this->assertTrue( wp_style_is( 'zestry-test-shared-styled', 'registered' ) );
		$this->assertStringEndsWith(
			'build/shared/styled.css',
			wp_styles()->registered['zestry-test-shared-styled']->src
		);
	}

	/**
	 * One setting decides both ends: every path in the manifest is relative to
	 * the build root, so moving it moves all of them.
	 */
	public function test_a_package_follows_a_moved_build_directory(): void {
		$this->write_manifest(
			array( 'zestry-test-shared-formatting' => array( 'source' => 'shared' ) ),
			'dist'
		);

		$this->boot_assets(
			static function ( Assets $assets ): void {
				$assets->set_build_root( 'dist' );
			}
		);

		$this->assertStringEndsWith(
			'dist/shared/formatting.js',
			wp_scripts()->registered['zestry-test-shared-formatting']->src
		);
	}

	public function test_keys_shared_packages_by_their_local_name(): void {
		$this->write_manifest(
			array(
				'zestry-test-index'             => array(),
				'zestry-test-shared-formatting' => array( 'source' => 'shared' ),
			)
		);

		$assets = $this->boot_assets();

		// The manifest is keyed by handle; each set is keyed by local name.
		$this->assertSame(
			array( 'zestry-test-index', 'zestry-test-shared-formatting' ),
			array_keys( $assets->get_build_manifest() )
		);
		$this->assertSame( array( 'formatting' ), array_keys( $assets->get_shared_packages() ) );
		$this->assertSame( array( 'index' ), array_keys( $assets->get_entries() ) );
	}

	public function test_get_shared_handle_returns_the_handle_for_a_script_and_the_id_for_a_module(): void {
		$this->write_manifest(
			array(
				'zestry-test-shared-formatting' => array( 'source' => 'shared' ),
				'@zestry-test/runtime'          => array( 'source' => 'shared', 'kind' => 'module' ),
			)
		);

		$assets = $this->boot_assets();

		$this->assertSame( 'zestry-test-shared-formatting', $assets->get_shared_handle( 'formatting' ) );
		$this->assertSame( '@zestry-test/runtime', $assets->get_shared_handle( 'runtime' ) );
		$this->assertFalse( $assets->is_shared_module( 'formatting' ) );
		$this->assertTrue( $assets->is_shared_module( 'runtime' ) );
	}

	public function test_get_shared_handle_names_what_was_built_when_asked_for_something_that_was_not(): void {
		$this->write_manifest(
			array( 'zestry-test-shared-formatting' => array( 'source' => 'shared' ) )
		);

		$assets = $this->boot_assets();

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Built: formatting' );

		$assets->get_shared_handle( 'missing' );
	}

	public function test_enqueue_shared_takes_the_stylesheet_with_it(): void {
		$this->write_manifest(
			array(
				'zestry-test-shared-styled' => array(
					'source' => 'shared',
					'css'    => 'shared/styled.css',
				),
			)
		);

		$this->boot_assets()->enqueue_shared( 'styled' );

		$this->assertTrue( wp_script_is( 'zestry-test-shared-styled', 'enqueued' ) );
		$this->assertTrue( wp_style_is( 'zestry-test-shared-styled', 'enqueued' ) );
	}

	public function test_registers_this_plugins_own_entries_under_the_handle_the_build_composed(): void {
		$this->write_manifest( array( 'zestry-test-settings' => array() ) );

		$this->boot_assets();

		$this->assertTrue( wp_script_is( 'zestry-test-settings', 'registered' ) );
		$this->assertStringEndsWith(
			'build/entries/settings.js',
			wp_scripts()->registered['zestry-test-settings']->src
		);
	}

	public function test_keys_entries_by_their_local_name(): void {
		$this->write_manifest(
			array(
				'zestry-test-settings'          => array(),
				'zestry-test-shared-formatting' => array( 'source' => 'shared' ),
			)
		);

		$this->assertSame( array( 'settings' ), array_keys( $this->boot_assets()->get_entries() ) );
	}

	/**
	 * An entry can be an ES module too. The constraint is not the entry, it is
	 * which WordPress packages exist as script modules -- so the two kinds are
	 * built by different compilations and registered through different APIs.
	 */
	public function test_registers_a_module_entry_as_a_script_module(): void {
		$this->write_manifest( array( 'zestry-test-cart' => array( 'kind' => 'module' ) ) );

		$assets = $this->boot_assets();

		$this->assertFalse( wp_script_is( 'zestry-test-cart', 'registered' ) );

		$assets->enqueue_entry( 'cart' );
		$printed = get_echo( array( wp_script_modules(), 'print_enqueued_script_modules' ) );

		$this->assertStringContainsString( 'id="zestry-test-cart-js-module"', $printed );
		$this->assertStringContainsString( 'build/entries/cart.js', $printed );
	}

	public function test_enqueue_entry_takes_the_stylesheet_with_a_classic_entry(): void {
		$this->write_manifest(
			array( 'zestry-test-settings' => array( 'css' => 'entries/style-settings.css' ) )
		);

		$this->boot_assets()->enqueue_entry( 'settings' );

		$this->assertTrue( wp_script_is( 'zestry-test-settings', 'enqueued' ) );
		$this->assertTrue( wp_style_is( 'zestry-test-settings', 'enqueued' ) );
	}

	public function test_enqueue_entry_names_what_was_built_when_asked_for_something_that_was_not(): void {
		$this->write_manifest( array( 'zestry-test-settings' => array() ) );

		$assets = $this->boot_assets();

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Built: settings' );

		$assets->enqueue_entry( 'missing' );
	}

	/**
	 * A stylesheet that compiles to nothing is deleted by the build, so the
	 * manifest records no `css` and nothing registers a `<link>` to an empty
	 * file. The generated stylesheet is exactly that until it is edited.
	 */
	public function test_registers_no_stylesheet_when_the_build_dropped_an_empty_one(): void {
		$this->write_manifest( array( 'zestry-test-settings' => array() ) );
		// On disk but unrecorded: the manifest is what decides.
		$this->write_plugin_file( 'build/entries/settings.css', '' );

		$this->boot_assets();

		$this->assertTrue( wp_script_is( 'zestry-test-settings', 'registered' ) );
		$this->assertFalse( wp_style_is( 'zestry-test-settings', 'registered' ) );
	}

	/**
	 * An entry that is only a stylesheet has its generated JavaScript -- pure
	 * webpack runtime -- deleted by the build, so there is no `js` to read
	 * and no script to register.
	 */
	public function test_registers_a_style_only_entry_without_a_script(): void {
		$this->write_manifest(
			array(
				'zestry-test-admin-theme' => array(
					'css' => 'entries/style-admin-theme.css',
					'rtl' => 'entries/style-admin-theme-rtl.css',
					'js'  => null,
				),
			)
		);

		$this->boot_assets();

		$this->assertFalse( wp_script_is( 'zestry-test-admin-theme', 'registered' ) );
		$this->assertTrue( wp_style_is( 'zestry-test-admin-theme', 'registered' ) );
		$this->assertSame( 'replace', wp_styles()->get_data( 'zestry-test-admin-theme', 'rtl' ) );
	}

	/**
	 * Enqueuing one must not ask WordPress for the script that was dropped --
	 * that is a "dependency is not registered" notice for something deliberate.
	 */
	public function test_enqueue_entry_on_a_style_only_entry_asks_for_no_script(): void {
		$this->write_manifest(
			array(
				'zestry-test-admin-theme' => array(
					'css' => 'entries/style-admin-theme.css',
					'js'  => null,
				),
			)
		);

		$this->boot_assets()->enqueue_entry( 'admin-theme' );

		$this->assertTrue( wp_style_is( 'zestry-test-admin-theme', 'enqueued' ) );
		$this->assertFalse( wp_script_is( 'zestry-test-admin-theme', 'enqueued' ) );
	}

	/**
	 * `style.scss` is split into a chunk of its own and written as
	 * `style-{entry}.css`, so the name cannot be derived from the entry. The
	 * build records what it emitted; guessing registered nothing at all.
	 */
	public function test_registers_the_stylesheet_the_build_actually_named(): void {
		$this->write_manifest(
			array(
				'zestry-test-settings' => array(
					'css' => 'entries/style-settings.css',
					'rtl' => 'entries/style-settings-rtl.css',
				),
			)
		);

		$this->boot_assets();

		$this->assertTrue( wp_style_is( 'zestry-test-settings', 'registered' ) );
		$this->assertStringEndsWith(
			'build/entries/style-settings.css',
			wp_styles()->registered['zestry-test-settings']->src
		);
		$this->assertSame( 'replace', wp_styles()->get_data( 'zestry-test-settings', 'rtl' ) );
	}

	public function test_registers_no_stylesheet_when_the_entry_produced_none(): void {
		$this->write_manifest( array( 'zestry-test-settings' => array() ) );

		$this->boot_assets();

		$this->assertTrue( wp_script_is( 'zestry-test-settings', 'registered' ) );
		$this->assertFalse( wp_style_is( 'zestry-test-settings', 'registered' ) );
	}

	/**
	 * Before there was a manifest, the stylesheet was found by looking for
	 * `{entry}.css`. A build that writes no manifest still gets that.
	 */
	public function test_falls_back_to_probing_for_a_stylesheet(): void {
		$this->write_plugin_file( 'build/index.js', '// built' );
		$this->write_plugin_file( 'build/index.css', '.a{}' );
		$this->write_plugin_file( 'build/index-rtl.css', '.a{}' );
		$this->write_plugin_file(
			'build/index.asset.php',
			"<?php return array( 'dependencies' => array(), 'version' => 'v1' );"
		);

		$handle = $this->boot_assets()->register_script_from_manifest( 'app', 'index' );

		$this->assertTrue( wp_style_is( $handle, 'registered' ) );
		$this->assertSame( 'replace', wp_styles()->get_data( $handle, 'rtl' ) );
	}

	/**
	 * Naming a build entry by hand reads the per-entry `.asset.php` -- the file
	 * WordPress itself reads for a block. The build manifest is keyed by handle
	 * rather than by build path, so it cannot answer for a path, and it does not
	 * have to: everything it describes is registered from its own row.
	 */
	public function test_registering_an_entry_by_hand_reads_the_per_entry_asset_file(): void {
		$this->write_plugin_file( 'build/index.js', '// built' );
		$this->write_plugin_file(
			'build/index.asset.php',
			"<?php return array( 'dependencies' => array( 'wp-i18n' ), 'version' => 'byhand' );"
		);

		$handle = $this->boot_assets()->register_script_from_manifest( 'app', 'index' );

		$this->assertSame( array( 'wp-i18n' ), wp_scripts()->registered[ $handle ]->deps );
		$this->assertSame( 'byhand', wp_scripts()->registered[ $handle ]->ver );
	}

	/**
	 * Nothing built yet is the ordinary state of a fresh plugin, and the
	 * manifest is derived rather than named -- there is nothing to misspell.
	 */
	public function test_an_unbuilt_plugin_registers_nothing_and_says_nothing(): void {
		$assets = $this->boot_assets();

		$this->assertSame( array(), $assets->get_build_manifest() );
		$this->assertSame( array(), $assets->get_shared_packages() );
	}

	/**
	 * `wp zt update` refreshes the copied PHP but leaves `webpack.config.js`
	 * alone -- it is generated once and yours to edit -- so an updated plugin can
	 * meet a manifest written to the older shape. Read as-is it would register
	 * nothing and look like a plugin with no JavaScript.
	 */
	public function test_a_manifest_from_an_older_build_configuration_says_so(): void {
		$this->write_plugin_file(
			'build/assets-manifest.php',
			"<?php return array( 'entries/settings' => array( 'asset' => array( 'dependencies' => array(), 'version' => 'v1' ) ) );"
		);

		$this->expectException( DiscoveryException::class );
		$this->expectExceptionMessage( 'older build configuration' );

		$this->boot_assets()->get_build_manifest();
	}

	public function test_a_manifest_row_that_does_not_say_what_it_is_called_throws(): void {
		$this->write_plugin_file(
			'build/assets-manifest.php',
			"<?php return array( 'zestry-test-settings' => array( 'source' => 'entry' ) );"
		);

		$this->expectException( DiscoveryException::class );
		$this->expectExceptionMessage( 'no "name"' );

		$this->boot_assets()->get_build_manifest();
	}

	public function test_a_manifest_that_returns_no_array_names_the_file(): void {
		$this->write_plugin_file( 'build/assets-manifest.php', '<?php return "nope";' );

		$this->expectException( DiscoveryException::class );
		$this->expectExceptionMessage( 'assets-manifest.php' );

		$this->boot_assets()->get_build_manifest();
	}

	private function assets(): Assets {
		return $this->plugin->get( Assets::class );
	}

	public function test_get_asset_url_resolves_relative_to_the_assets_directory(): void {
		$url = $this->assets()->get_asset_url( 'app.js' );

		$this->assertStringEndsWith( '/assets/app.js', $url );
	}

	public function test_set_assets_root_changes_where_get_asset_url_resolves(): void {
		$assets = $this->assets();
		$assets->set_assets_root( 'dist' );

		$this->assertStringEndsWith( '/dist/app.js', $assets->get_asset_url( 'app.js' ) );
	}

	public function test_get_asset_slug_namespaces_by_the_plugin_slug(): void {
		$this->assertSame( 'zestry-test-app', $this->assets()->get_asset_slug( 'app' ) );
	}

	public function test_register_script_registers_under_the_namespaced_handle_without_enqueueing(): void {
		$slug = $this->assets()->register_script( 'app', 'app.js' );

		$this->assertSame( 'zestry-test-app', $slug );
		$this->assertArrayHasKey( $slug, wp_scripts()->registered );
		$this->assertNotContains( $slug, wp_scripts()->queue );
	}

	/**
	 * WordPress substitutes its *own* version for a false one, so passing the
	 * default straight through would ship a changed script behind an unchanged
	 * cache key -- invisible locally, where caches are off.
	 */
	public function test_a_default_version_is_the_plugins_own_not_wordpress(): void {
		file_put_contents( $this->entry_file, "<?php\n/**\n * Plugin Name: Zestry Test\n * Version: 2.4.0\n */\n" );

		$slug = $this->assets()->register_script( 'app', 'app.js' );

		$this->assertSame( '2.4.0', wp_scripts()->registered[ $slug ]->ver );
		$this->assertNotSame( get_bloginfo( 'version' ), wp_scripts()->registered[ $slug ]->ver );
	}

	public function test_a_default_style_version_is_the_plugins_own(): void {
		file_put_contents( $this->entry_file, "<?php\n/**\n * Plugin Name: Zestry Test\n * Version: 2.4.0\n */\n" );

		$slug = $this->assets()->register_style( 'app', 'app.css' );

		$this->assertSame( '2.4.0', wp_styles()->registered[ $slug ]->ver );
	}

	public function test_an_explicit_version_is_used_as_given(): void {
		$slug = $this->assets()->register_script( 'app', 'app.js', array(), '9.9.9' );

		$this->assertSame( '9.9.9', wp_scripts()->registered[ $slug ]->ver );
	}

	/**
	 * null still means "no version at all" -- only false is reinterpreted.
	 */
	public function test_a_null_version_stays_unversioned(): void {
		$slug = $this->assets()->register_script( 'app', 'app.js', array(), null );

		$this->assertNull( wp_scripts()->registered[ $slug ]->ver );
	}

	/**
	 * A plugin declaring no Version: header has nothing to fall back to, so the
	 * asset registers unversioned rather than carrying a meaningless one.
	 */
	public function test_a_plugin_without_a_version_header_registers_unversioned(): void {
		$slug = $this->assets()->register_script( 'app', 'app.js' );

		$this->assertNull( wp_scripts()->registered[ $slug ]->ver );
	}

	public function test_register_script_resolves_src_relative_to_the_assets_directory(): void {
		$assets = $this->assets();
		$assets->set_assets_root( 'dist' );
		$assets->register_script( 'app', 'app.js' );

		$this->assertSame( $assets->get_asset_url( 'app.js' ), wp_scripts()->registered['zestry-test-app']->src );
		$this->assertStringEndsWith( '/dist/app.js', wp_scripts()->registered['zestry-test-app']->src );
	}

	public function test_register_style_registers_under_the_namespaced_handle_without_enqueueing(): void {
		$slug = $this->assets()->register_style( 'app', 'app.css' );

		$this->assertSame( 'zestry-test-app', $slug );
		$this->assertArrayHasKey( $slug, wp_styles()->registered );
		$this->assertNotContains( $slug, wp_styles()->queue );
	}

	public function test_a_dependent_asset_uses_the_returned_namespaced_handle(): void {
		$assets = $this->assets();
		$app    = $assets->register_script( 'app', 'app.js' );
		$assets->register_script( 'widgets', 'widgets.js', array( $app ) );

		$this->assertSame( array( $app ), wp_scripts()->registered['zestry-test-widgets']->deps );
	}

	/**
	 * Every handle handed back is one WordPress already knows, so attaching to
	 * it is WordPress's own function and nothing re-derives the name. The
	 * wrappers that used to sit here took a *local* name, so passing them a
	 * returned handle prefixed it twice and attached to nothing.
	 */
	public function test_a_returned_handle_is_ready_for_wordpress_own_functions(): void {
		$this->write_manifest( array( 'zestry-test-settings' => array() ) );

		$handle = $this->boot_assets()->enqueue_entry( 'settings' );

		$this->assertTrue( wp_add_inline_script( $handle, 'window.settings = {};' ) );
		$this->assertContains( 'window.settings = {};', wp_scripts()->get_data( $handle, 'after' ) );
	}

	public function test_get_build_url_resolves_relative_to_the_build_directory(): void {
		$this->assertStringEndsWith( '/build/app.js', $this->assets()->get_build_url( 'app.js' ) );
	}

	public function test_set_build_root_changes_where_get_build_url_resolves(): void {
		$assets = $this->assets();
		$assets->set_build_root( 'dist' );

		$this->assertStringEndsWith( '/dist/app.js', $assets->get_build_url( 'app.js' ) );
	}

	public function test_build_root_is_independent_of_assets_root(): void {
		// Changing assets_root must not move where build files are resolved,
		// and vice versa -- the two directories are configured separately.
		$assets = $this->assets();
		$assets->set_assets_root( 'assets-changed' );

		$this->assertStringEndsWith( '/build/app.js', $assets->get_build_url( 'app.js' ) );
	}

	public function test_register_script_from_manifest_uses_the_manifests_dependencies_and_version(): void {
		$this->write_plugin_file(
			'build/app.asset.php',
			"<?php return array( 'dependencies' => array( 'wp-element', 'wp-api-fetch' ), 'version' => 'abc123' );\n"
		);

		$slug = $this->assets()->register_script_from_manifest( 'app', 'app' );

		$this->assertSame( 'zestry-test-app', $slug );
		$registered = wp_scripts()->registered['zestry-test-app'];
		$this->assertStringEndsWith( '/build/app.js', $registered->src );
		$this->assertSame( array( 'wp-element', 'wp-api-fetch' ), $registered->deps );
		$this->assertSame( 'abc123', $registered->ver );
		$this->assertNotContains( $slug, wp_scripts()->queue );
	}

	public function test_register_script_from_manifest_defaults_to_in_footer(): void {
		$this->write_plugin_file(
			'build/app.asset.php',
			"<?php return array( 'dependencies' => array(), 'version' => 'abc123' );\n"
		);

		$this->assets()->register_script_from_manifest( 'app', 'app' );

		// wp_register_script() records in_footer as the 'group' data entry
		// (group 1 = footer) at registration time; WP_Scripts::$in_footer
		// itself is only populated later, during dependency resolution/output.
		$this->assertSame( 1, wp_scripts()->get_data( 'zestry-test-app', 'group' ) );
	}

	public function test_register_script_from_manifest_honors_an_explicit_args_override(): void {
		$this->write_plugin_file(
			'build/app.asset.php',
			"<?php return array( 'dependencies' => array(), 'version' => 'abc123' );\n"
		);

		$this->assets()->register_script_from_manifest( 'app', 'app', array(), array( 'in_footer' => false ) );

		$this->assertFalse( wp_scripts()->get_data( 'zestry-test-app', 'group' ) );
	}

	public function test_register_script_from_manifest_also_registers_a_sibling_stylesheet_under_the_same_handle(): void {
		$this->write_plugin_file(
			'build/app.asset.php',
			"<?php return array( 'dependencies' => array(), 'version' => 'abc123' );\n"
		);
		$this->write_plugin_file( 'build/app.css', 'body{color:red;}' );

		$slug = $this->assets()->register_script_from_manifest( 'app', 'app' );

		$this->assertSame( 'zestry-test-app', $slug );
		$this->assertArrayHasKey( $slug, wp_styles()->registered );
		$this->assertStringEndsWith( '/build/app.css', wp_styles()->registered[ $slug ]->src );
		$this->assertSame( 'abc123', wp_styles()->registered[ $slug ]->ver );
		$this->assertNotContains( $slug, wp_styles()->queue );
	}

	public function test_register_script_from_manifest_registers_no_style_when_no_css_was_built(): void {
		$this->write_plugin_file(
			'build/app.asset.php',
			"<?php return array( 'dependencies' => array(), 'version' => 'abc123' );\n"
		);

		$this->assets()->register_script_from_manifest( 'app', 'app' );

		$this->assertArrayNotHasKey( 'zestry-test-app', wp_styles()->registered );
	}

	public function test_register_script_from_manifest_enables_rtl_swap_when_an_rtl_stylesheet_was_built(): void {
		$this->write_plugin_file(
			'build/app.asset.php',
			"<?php return array( 'dependencies' => array(), 'version' => 'abc123' );\n"
		);
		$this->write_plugin_file( 'build/app.css', 'body{color:red;}' );
		$this->write_plugin_file( 'build/app-rtl.css', 'body{color:blue;}' );

		$this->assets()->register_script_from_manifest( 'app', 'app' );

		$this->assertSame( 'replace', wp_styles()->get_data( 'zestry-test-app', 'rtl' ) );
	}

	public function test_register_script_from_manifest_does_not_enable_rtl_swap_without_an_rtl_stylesheet(): void {
		$this->write_plugin_file(
			'build/app.asset.php',
			"<?php return array( 'dependencies' => array(), 'version' => 'abc123' );\n"
		);
		$this->write_plugin_file( 'build/app.css', 'body{color:red;}' );

		$this->assets()->register_script_from_manifest( 'app', 'app' );

		$this->assertFalse( wp_styles()->get_data( 'zestry-test-app', 'rtl' ) );
	}

	public function test_register_script_from_manifest_merges_extra_deps_after_the_manifests_own(): void {
		$this->write_plugin_file(
			'build/app.asset.php',
			"<?php return array( 'dependencies' => array( 'wp-element' ), 'version' => 'abc123' );\n"
		);

		$this->assets()->register_script_from_manifest( 'app', 'app', array( 'zestry-test-theme' ) );

		$this->assertSame(
			array( 'wp-element', 'zestry-test-theme' ),
			wp_scripts()->registered['zestry-test-app']->deps
		);
	}

	public function test_a_manifest_registered_script_can_then_be_enqueued_by_handle(): void {
		$this->write_plugin_file(
			'build/app.asset.php',
			"<?php return array( 'dependencies' => array(), 'version' => 'abc123' );\n"
		);
		$slug = $this->assets()->register_script_from_manifest( 'app', 'app' );

		wp_enqueue_script( $slug );

		$this->assertArrayHasKey( $slug, wp_scripts()->registered );
		$this->assertContains( $slug, wp_scripts()->queue );
	}

	public function test_register_script_from_manifest_throws_when_the_manifest_is_missing(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Asset manifest does not exist' );

		$this->assets()->register_script_from_manifest( 'app', 'missing-entry' );
	}

	public function test_register_script_from_manifest_throws_when_the_manifest_is_malformed(): void {
		$this->write_plugin_file( 'build/broken.asset.php', "<?php return array( 'dependencies' => array() );\n" );

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'must return an array with' );

		$this->assets()->register_script_from_manifest( 'app', 'broken' );
	}

	/**
	 * Resolve and boot the module, then fire the `init` its registration waits on.
	 *
	 * @param callable|null $configure Optional configuration, run before boot.
	 * @return Assets The resolved module.
	 */
	private function boot_assets( ?callable $configure = null ): Assets {
		if ( null !== $configure ) {
			$this->plugin->configure( Assets::class, $configure );
		}

		$assets = $this->plugin->get( Assets::class );
		do_action( 'init' );

		return $assets;
	}

	/**
	 * Write what a build leaves behind: the JavaScript, and the one manifest
	 * naming every entry, what it depends on, and how to load it.
	 *
	 * @param array<string, array<string, mixed>> $entries  Entry name => manifest fields; a null `asset` means style-only.
	 * @param string                              $build    Plugin-relative build root.
	 * @param string                              $filename Which manifest to write.
	 * @return void
	 */
	private function write_manifest( array $entries, string $build = 'build', string $filename = 'assets-manifest.php' ): void {
		$rows = array();

		foreach ( $entries as $handle => $fields ) {
			$source = $fields['source'] ?? 'entry';
			$name   = $fields['name'] ?? $this->local_name( $handle, $source );

			$row = $fields + array(
				'source' => $source,
				'name'   => $name,
				'kind'   => 'script',
				'js'     => ( 'shared' === $source ? 'shared/' : 'entries/' ) . $name . '.js',
			);

			// An explicit null `js` is a style-only entry: the build deleted the
			// runtime-only JavaScript webpack generated for it, so there is no
			// script, and no dependencies or version to describe one.
			if ( null === $row['js'] ) {
				unset( $row['js'] );

				$rows[ $handle ] = $row;

				continue;
			}

			$this->write_plugin_file( $build . '/' . $row['js'], '// built' );

			$rows[ $handle ] = $row + array(
				'dependencies' => array(),
				'version'      => 'v1',
			);
		}

		$this->write_plugin_file(
			$build . '/' . $filename,
			'<?php return ' . var_export( $rows, true ) . ';'
		);
	}

	/**
	 * The local name a handle was composed from.
	 *
	 * The build's own rule, inverted, so a fixture row that says nothing but its
	 * handle still describes something coherent.
	 *
	 * @param string $handle The handle the row is keyed by.
	 * @param string $source Where it came from: `entry` or `shared`.
	 * @return string The name a caller passes.
	 */
	private function local_name( string $handle, string $source ): string {
		// A module package keeps the npm name its importers import, so the local
		// name is the part after the scope: `@zestry-test/runtime` is `runtime`.
		if ( ! \str_starts_with( $handle, self::PREFIX ) ) {
			return \substr( (string) \strrchr( '/' . $handle, '/' ), 1 );
		}

		$local = \substr( $handle, \strlen( self::PREFIX ) );

		return 'shared' === $source && \str_starts_with( $local, 'shared-' )
			? \substr( $local, \strlen( 'shared-' ) )
			: $local;
	}

}
