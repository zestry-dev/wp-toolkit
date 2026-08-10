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

	public function tear_down(): void {
		// wp_deregister_*() only unsets the handle from the registered list --
		// it does not remove it from the queue -- so an enqueue in one test
		// would otherwise still show up in wp_scripts()->queue during the next.
		foreach ( array( 'zestry-test-app', 'zestry-test-widgets', 'zestry-test-theme', 'zestry-test-settings', 'zestry-test-cart', 'zestry-test-admin-theme', 'zestry-test-shared', 'zestry-test-styled' ) as $handle ) {
			wp_dequeue_script( $handle );
			wp_deregister_script( $handle );
			wp_dequeue_style( $handle );
			wp_deregister_style( $handle );
		}
		wp_deregister_script_module( '@zestry-test/runtime' );
		wp_deregister_script_module( 'zestry-test-cart' );

		parent::tear_down();
	}

	public function test_registers_a_shared_package_under_its_declared_handle(): void {
		$this->write_manifest(
			array(
				'shared/formatting' => array(
					'kind'  => 'script',
					'id'    => 'zestry-test-shared',
					'asset' => array(
						'dependencies' => array( 'wp-element' ),
						'version'      => 'abc123',
					),
				),
			)
		);

		$this->boot_assets();

		$this->assertTrue( wp_script_is( 'zestry-test-shared', 'registered' ) );

		$script = wp_scripts()->registered['zestry-test-shared'];

		// Verbatim, not namespaced: this is the handle the build already wrote
		// into every importer's own .asset.php.
		$this->assertSame( array( 'wp-element' ), $script->deps );
		$this->assertSame( 'abc123', $script->ver );
		$this->assertStringEndsWith( 'build/shared/formatting.js', $script->src );
	}

	/**
	 * `--experimental-modules` runs two compilations into one directory, so each
	 * writes its own manifest. Reading only the first loses every module entry.
	 */
	public function test_registers_a_module_package_from_the_second_manifest(): void {
		$this->write_manifest( array( 'index' => array() ) );
		$this->write_manifest(
			array( 'shared/runtime' => array( 'kind' => 'module', 'id' => '@zestry-test/runtime' ) ),
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
				'shared/styled' => array(
					'kind' => 'script',
					'id'   => 'zestry-test-styled',
					'css'  => 'shared/styled.css',
				),
			)
		);

		$this->boot_assets();

		$this->assertTrue( wp_style_is( 'zestry-test-styled', 'registered' ) );
		$this->assertStringEndsWith( 'build/shared/styled.css', wp_styles()->registered['zestry-test-styled']->src );
	}

	/**
	 * One setting decides both ends: the manifest and the shared directory are
	 * both derived from the build root rather than named separately.
	 */
	public function test_a_package_follows_a_moved_build_directory(): void {
		$this->write_manifest(
			array( 'shared/formatting' => array( 'kind' => 'script', 'id' => 'zestry-test-shared' ) ),
			'dist'
		);

		$this->boot_assets(
			static function ( Assets $assets ): void {
				$assets->set_build_root( 'dist' );
			}
		);

		$this->assertStringEndsWith( 'dist/shared/formatting.js', wp_scripts()->registered['zestry-test-shared']->src );
	}

	public function test_keys_shared_packages_by_their_local_name(): void {
		$this->write_manifest(
			array(
				'index'             => array(),
				'shared/formatting' => array( 'kind' => 'script', 'id' => 'zestry-test-shared' ),
			)
		);

		$assets = $this->boot_assets();

		// Every entry is in the manifest; only the shared ones are packages.
		$this->assertSame( array( 'index', 'shared/formatting' ), array_keys( $assets->get_build_manifest() ) );
		$this->assertSame( array( 'formatting' ), array_keys( $assets->get_shared_packages() ) );
	}

	public function test_get_shared_handle_returns_the_handle_for_a_script_and_the_id_for_a_module(): void {
		$this->write_manifest(
			array(
				'shared/formatting' => array( 'kind' => 'script', 'id' => 'zestry-test-shared' ),
				'shared/runtime'    => array( 'kind' => 'module', 'id' => '@zestry-test/runtime' ),
			)
		);

		$assets = $this->boot_assets();

		$this->assertSame( 'zestry-test-shared', $assets->get_shared_handle( 'formatting' ) );
		$this->assertSame( '@zestry-test/runtime', $assets->get_shared_handle( 'runtime' ) );
		$this->assertFalse( $assets->is_shared_module( 'formatting' ) );
		$this->assertTrue( $assets->is_shared_module( 'runtime' ) );
	}

	public function test_get_shared_handle_names_what_was_built_when_asked_for_something_that_was_not(): void {
		$this->write_manifest(
			array( 'shared/formatting' => array( 'kind' => 'script', 'id' => 'zestry-test-shared' ) )
		);

		$assets = $this->boot_assets();

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Built: formatting' );

		$assets->get_shared_handle( 'missing' );
	}

	public function test_enqueue_shared_takes_the_stylesheet_with_it(): void {
		$this->write_manifest(
			array(
				'shared/styled' => array(
					'kind' => 'script',
					'id'   => 'zestry-test-styled',
					'css'  => 'shared/styled.css',
				),
			)
		);

		$this->boot_assets()->enqueue_shared( 'styled' );

		$this->assertTrue( wp_script_is( 'zestry-test-styled', 'enqueued' ) );
		$this->assertTrue( wp_style_is( 'zestry-test-styled', 'enqueued' ) );
	}

	public function test_registers_this_plugins_own_entries_under_namespaced_handles(): void {
		$this->write_manifest(
			array(
				'entries/settings'    => array(),
				'blocks/toggle/index' => array(),
			)
		);

		$this->boot_assets();

		// Namespaced, unlike a shared package: this handle is ours to choose,
		// so it gets the collision protection every other handle here has.
		$this->assertTrue( wp_script_is( 'zestry-test-settings', 'registered' ) );

		// A block's scripts are WordPress's to register, from its own
		// block.json. Registering them again would load each of them twice.
		$this->assertFalse( wp_script_is( 'zestry-test-blocks/toggle/index', 'registered' ) );
	}

	public function test_keys_entries_by_their_local_name(): void {
		$this->write_manifest(
			array(
				'entries/settings' => array(),
				'shared/formatting' => array( 'kind' => 'script', 'id' => 'zestry-test-shared' ),
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
		$this->write_manifest( array( 'entries/cart' => array( 'kind' => 'module' ) ) );

		$assets = $this->boot_assets();

		$this->assertFalse( wp_script_is( 'zestry-test-cart', 'registered' ) );

		$assets->enqueue_entry( 'cart' );
		$printed = get_echo( array( wp_script_modules(), 'print_enqueued_script_modules' ) );

		$this->assertStringContainsString( 'id="zestry-test-cart-js-module"', $printed );
		$this->assertStringContainsString( 'build/entries/cart.js', $printed );
	}

	public function test_enqueue_entry_takes_the_stylesheet_with_a_classic_entry(): void {
		$this->write_manifest(
			array( 'entries/settings' => array( 'css' => 'entries/style-settings.css' ) )
		);

		$this->boot_assets()->enqueue_entry( 'settings' );

		$this->assertTrue( wp_script_is( 'zestry-test-settings', 'enqueued' ) );
		$this->assertTrue( wp_style_is( 'zestry-test-settings', 'enqueued' ) );
	}

	public function test_enqueue_entry_names_what_was_built_when_asked_for_something_that_was_not(): void {
		$this->write_manifest( array( 'entries/settings' => array() ) );

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
		$this->write_manifest( array( 'entries/settings' => array() ) );
		// On disk but unrecorded: the manifest is what decides.
		$this->write_plugin_file( 'build/entries/settings.css', '' );

		$this->boot_assets();

		$this->assertTrue( wp_script_is( 'zestry-test-settings', 'registered' ) );
		$this->assertFalse( wp_style_is( 'zestry-test-settings', 'registered' ) );
	}

	/**
	 * An entry that is only a stylesheet has its generated JavaScript -- pure
	 * webpack runtime -- deleted by the build, so there is no `asset` to read
	 * and no script to register.
	 */
	public function test_registers_a_style_only_entry_without_a_script(): void {
		$this->write_manifest(
			array(
				'entries/admin-theme' => array(
					'css'   => 'entries/style-admin-theme.css',
					'rtl'   => 'entries/style-admin-theme-rtl.css',
					'asset' => null,
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
				'entries/admin-theme' => array(
					'css'   => 'entries/style-admin-theme.css',
					'asset' => null,
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
				'entries/settings' => array(
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
		$this->write_manifest( array( 'entries/settings' => array() ) );

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
	 * The manifest answers for every entry in one read, so a hand-registered
	 * script never has to reach the per-entry file either.
	 */
	public function test_registering_an_entry_by_hand_reads_the_same_manifest(): void {
		$this->write_manifest(
			array(
				'index' => array(
					'asset' => array(
						'dependencies' => array( 'wp-api-fetch' ),
						'version'      => 'deadbeef',
					),
				),
			)
		);

		$handle = $this->boot_assets()->register_script_from_manifest( 'app', 'index' );

		$this->assertSame( array( 'wp-api-fetch' ), wp_scripts()->registered[ $handle ]->deps );
		$this->assertSame( 'deadbeef', wp_scripts()->registered[ $handle ]->ver );
	}

	/**
	 * A build that wrote no manifest still works: the per-entry `.asset.php` is
	 * what WordPress itself reads, and it is the fallback here too.
	 */
	public function test_falls_back_to_the_per_entry_asset_file(): void {
		$this->write_plugin_file( 'build/index.js', '// built' );
		$this->write_plugin_file(
			'build/index.asset.php',
			"<?php return array( 'dependencies' => array( 'wp-i18n' ), 'version' => 'fallback' );"
		);

		$handle = $this->boot_assets()->register_script_from_manifest( 'app', 'index' );

		$this->assertSame( array( 'wp-i18n' ), wp_scripts()->registered[ $handle ]->deps );
		$this->assertSame( 'fallback', wp_scripts()->registered[ $handle ]->ver );
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

	public function test_a_manifest_entry_declaring_an_unknown_kind_throws(): void {
		$this->write_manifest(
			array( 'shared/formatting' => array( 'kind' => 'stylesheet', 'id' => 'x' ) )
		);

		$this->expectException( DiscoveryException::class );
		$this->expectExceptionMessage( 'expected "script" or "module"' );

		$this->boot_assets()->get_shared_packages();
	}

	public function test_a_shared_manifest_entry_declaring_no_id_throws(): void {
		$this->write_manifest( array( 'shared/formatting' => array( 'kind' => 'script' ) ) );

		$this->expectException( DiscoveryException::class );
		$this->expectExceptionMessage( 'declares no "id"' );

		$this->boot_assets()->get_shared_packages();
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

	public function test_enqueue_script_queues_an_already_registered_script(): void {
		$assets = $this->assets();
		$assets->register_script( 'app', 'app.js' );

		$slug = $assets->enqueue_script( 'app' );

		$this->assertSame( 'zestry-test-app', $slug );
		$this->assertContains( $slug, wp_scripts()->queue );
	}

	public function test_enqueue_style_queues_an_already_registered_style(): void {
		$assets = $this->assets();
		$assets->register_style( 'app', 'app.css' );

		$slug = $assets->enqueue_style( 'app' );

		$this->assertSame( 'zestry-test-app', $slug );
		$this->assertContains( $slug, wp_styles()->queue );
	}

	public function test_a_dependent_asset_uses_the_returned_namespaced_handle(): void {
		$assets = $this->assets();
		$app    = $assets->register_script( 'app', 'app.js' );
		$assets->register_script( 'widgets', 'widgets.js', array( $app ) );

		$this->assertSame( array( $app ), wp_scripts()->registered['zestry-test-widgets']->deps );
	}

	public function test_add_inline_script_attaches_to_the_namespaced_handle(): void {
		$assets = $this->assets();
		$assets->register_script( 'app', 'app.js' );

		$this->assertTrue( $assets->add_inline_script( 'app', 'window.app = {};' ) );

		// WP core seeds the 'after' data array with a leading `false` placeholder
		// (meaning "no wp_localize_script() data yet"), so assert the inline
		// script was appended rather than asserting the array's exact shape.
		$this->assertContains( 'window.app = {};', wp_scripts()->get_data( 'zestry-test-app', 'after' ) );
	}

	public function test_add_inline_style_attaches_to_the_namespaced_handle(): void {
		$assets = $this->assets();
		$assets->register_style( 'app', 'app.css' );

		$this->assertTrue( $assets->add_inline_style( 'app', 'body{color:red;}' ) );
		$this->assertSame(
			array( 'body{color:red;}' ),
			wp_styles()->get_data( 'zestry-test-app', 'after' )
		);
	}

	public function test_localize_script_exposes_data_on_the_namespaced_handle(): void {
		$assets = $this->assets();
		$assets->register_script( 'app', 'app.js' );

		$this->assertTrue( $assets->localize_script( 'app', 'AppData', array( 'endpoint' => '/wp-json/zestry/v1' ) ) );

		// wp_localize_script() stores an already rendered JS snippet under the
		// 'data' key (e.g. `var AppData = {"endpoint":"/wp-json/zestry/v1"};`), not
		// the raw array, so assert against the rendered snippet's contents.
		$rendered = wp_scripts()->get_data( 'zestry-test-app', 'data' );
		$this->assertStringContainsString( 'var AppData', $rendered );
		$this->assertStringContainsString( 'wp-json/zestry/v1', $rendered );
	}

	public function test_script_add_data_attaches_metadata_to_the_namespaced_handle(): void {
		$assets = $this->assets();
		$assets->register_script( 'app', 'app.js' );

		$this->assertTrue( $assets->script_add_data( 'app', 'strategy', 'defer' ) );
		$this->assertSame( 'defer', wp_scripts()->get_data( 'zestry-test-app', 'strategy' ) );
	}

	public function test_style_add_data_attaches_metadata_to_the_namespaced_handle(): void {
		$assets = $this->assets();
		$assets->register_style( 'app', 'app.css' );

		$this->assertTrue( $assets->style_add_data( 'app', 'rtl', true ) );
		$this->assertTrue( wp_styles()->get_data( 'zestry-test-app', 'rtl' ) );
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
		$assets = $this->assets();
		$assets->register_script_from_manifest( 'app', 'app' );

		$slug = $assets->enqueue_script( 'app' );

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
		foreach ( $entries as $entry => $fields ) {
			// An explicit null `asset` is a style-only entry: the build deleted
			// the JavaScript webpack generated for it, so there is no script.
			if ( \array_key_exists( 'asset', $fields ) && null === $fields['asset'] ) {
				unset( $entries[ $entry ]['asset'] );

				continue;
			}

			$this->write_plugin_file( $build . '/' . $entry . '.js', '// built' );

			$entries[ $entry ] = $fields + array(
				'asset' => array(
					'dependencies' => array(),
					'version'      => 'v1',
				),
			);
		}

		$this->write_plugin_file(
			$build . '/' . $filename,
			'<?php return ' . var_export( $entries, true ) . ';'
		);
	}

}
