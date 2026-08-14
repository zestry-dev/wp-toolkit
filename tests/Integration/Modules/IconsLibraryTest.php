<?php

declare( strict_types=1 );

namespace Zestry\WPToolkit\Tests\Integration\Modules;

use Zestry\WPToolkit\Kernel\Exceptions\DiscoveryException;
use Zestry\WPToolkit\Modules\IconsLibrary\IconsLibrary;
use Zestry\WPToolkit\Tests\Support\TestCase;

/**
 * Discovery, namespacing, labels, and the sanitizer guard.
 *
 * An icon is a view component: the template echoes the SVG and returns what it
 * is called, which is what makes the label translatable. What is pinned here is
 * that pairing, and that WordPress quietly removing half an icon is refused
 * rather than registered.
 *
 * @covers \Zestry\WPToolkit\Modules\IconsLibrary\IconsLibrary
 */
final class IconsLibraryTest extends TestCase {

	/**
	 * A minimal icon using only what WordPress's sanitizer keeps.
	 */
	private const VALID_ICON = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24">'
		. '<path d="M5 12h14" /></svg>';

	public function set_up(): void {
		parent::set_up();

		if ( ! function_exists( 'wp_register_icon' ) ) {
			$this->markTestSkipped( 'Requires the Icons API, added in WordPress 7.1.' );
		}

		// The sanitizer check is asked for per plugin rather than site-wide, so
		// the constant the plugin reads has to be on for these tests to see it.
		if ( ! defined( 'ZESTRY_TEST_DEBUG' ) ) {
			define( 'ZESTRY_TEST_DEBUG', true );
		}

		mkdir( $this->plugin_dir . '/svg-icons', 0777, true );

		$this->reset_registries();
	}

	public function tear_down(): void {
		$this->reset_registries();
		parent::tear_down();
	}

	public function test_a_discovered_icon_is_registered_under_the_plugin_namespace(): void {
		$this->write_icon( 'arrow-right' );

		$this->boot();

		$this->assertNotSame( '', wp_get_icon( 'zestry-test/arrow-right' ) );
	}

	/**
	 * WordPress allows underscores in an icon name where it allows none at all in
	 * an ability's, so a filename carrying one registers exactly as written rather
	 * than being respelled to match the module next door.
	 */
	public function test_the_filename_is_the_name_verbatim(): void {
		$this->write_icon( 'brand_mark' );

		$this->boot();

		$this->assertNotSame( '', wp_get_icon( 'zestry-test/brand_mark' ) );
	}

	public function test_the_collection_is_registered_for_the_plugin(): void {
		$this->write_icon( 'arrow-right' );

		$this->boot();

		$this->assertTrue(
			\WP_Icon_Collections_Registry::get_instance()->is_registered( 'zestry-test' )
		);
	}

	/**
	 * The plugin's own collection is derived rather than asked for, so it waits
	 * until there is something to put in it: an empty group is one more thing for
	 * the editor's picker to list and nothing to find inside it.
	 */
	public function test_the_derived_collection_waits_for_an_icon(): void {
		$this->boot();

		$this->assertFalse(
			\WP_Icon_Collections_Registry::get_instance()->is_registered( 'zestry-test' )
		);
	}

	/**
	 * A declared one does not wait. Declaring it is a deliberate act, so a plugin
	 * that has named its groups and drawn nothing yet still gets them.
	 */
	public function test_a_declared_collection_registers_without_any_icons(): void {
		$this->boot_with(
			static function ( IconsLibrary $icons ): void {
				$icons->add_collections( array( 'acme-brand' => 'Acme brand' ) );
			}
		);

		$this->assertTrue(
			\WP_Icon_Collections_Registry::get_instance()->is_registered( 'acme-brand' )
		);
	}

	public function test_get_applies_the_namespace_and_renders(): void {
		$this->write_icon( 'arrow-right' );

		$markup = $this->boot()->get( 'arrow-right', array( 'size' => 32 ) );

		$this->assertStringContainsString( 'width="32"', $markup );
		$this->assertStringContainsString( 'height="32"', $markup );
	}

	public function test_a_label_is_built_from_the_filename_when_none_is_given(): void {
		$this->write_icon( 'arrow-right' );

		$this->boot();

		$this->assertSame(
			'Arrow Right',
			\WP_Icons_Registry::get_instance()->get_registered_icon( 'zestry-test/arrow-right' )['label']
		);
	}

	/**
	 * WordPress matches both halves of an icon name against
	 * `^[a-z0-9]([a-z0-9_-]*[a-z0-9])?$` and refuses anything else with a
	 * `_doing_it_wrong()` naming no file. Refused here instead, while the file
	 * that asked for it is still in hand.
	 */
	public function test_a_filename_wordpress_would_refuse_throws(): void {
		$this->write_icon( 'Arrow Right' );

		$this->expectException( DiscoveryException::class );
		$this->expectExceptionMessage( 'zestry-test/Arrow Right' );

		$this->boot();
	}

	public function test_a_returned_label_replaces_the_derived_one(): void {
		$this->write_icon( 'arrow-right', self::VALID_ICON, "array( 'label' => 'Arrow, pointing right' )" );

		$this->boot();

		$this->assertSame(
			'Arrow, pointing right',
			\WP_Icons_Registry::get_instance()->get_registered_icon( 'zestry-test/arrow-right' )['label']
		);
	}

	/**
	 * The collection is the plugin's half of the name, so a declared name is the
	 * bare half after the slash and never carries the slug itself.
	 */
	public function test_a_returned_name_replaces_the_one_from_the_filename(): void {
		$this->write_icon( 'logo-2024', self::VALID_ICON, "array( 'name' => 'brand_mark', 'label' => 'Acme logo' )" );

		$this->boot();

		$this->assertNotSame( '', wp_get_icon( 'zestry-test/brand_mark' ) );
		$this->assertSame( '', wp_get_icon( 'zestry-test/logo-2024' ) );
	}

	/**
	 * Two filenames cannot be one name, so nothing collides until a template says
	 * so -- and then only one of them can be it.
	 */
	public function test_two_templates_claiming_one_name_throw(): void {
		$this->write_icon( 'first', self::VALID_ICON, "array( 'name' => 'shared' )" );
		$this->write_icon( 'second', self::VALID_ICON, "array( 'name' => 'shared' )" );

		$this->expectException( DiscoveryException::class );
		$this->expectExceptionMessage( 'resolve to the name "zestry-test/shared"' );

		$this->boot();
	}

	/**
	 * An icon component echoes its SVG and returns its label, so one that renders
	 * nothing has said what it is called and drawn nothing.
	 */
	public function test_a_template_that_renders_nothing_throws(): void {
		file_put_contents(
			$this->plugin_dir . '/svg-icons/blank.php',
			"<?php\nreturn 'Blank';\n"
		);

		$this->expectException( DiscoveryException::class );
		$this->expectExceptionMessage( 'rendered nothing' );

		$this->boot();
	}

	/**
	 * Adding the module before drawing the first icon is ordinary, so only a
	 * directory asked for by name is missing in the sense worth throwing over.
	 */
	public function test_an_absent_default_root_discovers_nothing_and_says_nothing(): void {
		$this->remove_dir( $this->plugin_dir . '/svg-icons' );

		$this->assertSame( array(), $this->plugin->get( IconsLibrary::class )->get_discovered_icons() );
	}

	/**
	 * `wp_kses()` removes what it does not recognise and keeps the rest, so this
	 * icon would register, render, and be missing its only visible element --
	 * with WordPress saying nothing, since something did survive.
	 */
	public function test_an_icon_using_an_element_wordpress_strips_throws(): void {
		$this->write_icon(
			'logo',
			'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24">'
				. '<path d="M5 12h14" /><circle cx="12" cy="12" r="10" /></svg>'
		);

		$this->expectException( DiscoveryException::class );
		$this->expectExceptionMessage( '<circle>' );

		$this->boot();
	}

	/**
	 * The expensive half of the same guard: every element here is allowed, and the
	 * icon still renders as nothing, because an outline is drawn entirely in
	 * attributes the sanitizer drops.
	 */
	public function test_an_outline_icon_loses_its_stroke_and_throws(): void {
		$this->write_icon(
			'outline',
			'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24">'
				. '<path d="M5 12h14" fill="none" stroke="currentColor" stroke-width="2" /></svg>'
		);

		$this->expectException( DiscoveryException::class );
		$this->expectExceptionMessage( 'path[stroke]' );

		$this->boot();
	}

	public function test_an_icon_that_survives_sanitizing_registers_without_complaint(): void {
		$this->write_icon( 'arrow-right' );

		$this->boot();

		$this->assertStringContainsString( 'M5 12h14', wp_get_icon( 'zestry-test/arrow-right' ) );
	}

	/**
	 * Empty both icon registries.
	 *
	 * They are process-wide singletons, so the second test to register
	 * `zestry-test/arrow-right` would otherwise be registering over the first --
	 * which WordPress reports with `_doing_it_wrong()`, and the suite turns into
	 * a failure.
	 *
	 * @return void
	 */
	private function reset_registries(): void {
		foreach ( array( \WP_Icons_Registry::class, \WP_Icon_Collections_Registry::class ) as $registry ) {
			$instance = new \ReflectionProperty( $registry, 'instance' );
			$instance->setAccessible( true );
			$instance->setValue( null, null );
		}
	}

	/**
	 * Drop an icon component into the plugin's icons directory.
	 *
	 * @param string $name    The icon's local name.
	 * @param string $content The markup it echoes.
	 * @param string $returns A PHP expression it returns, or '' to return nothing.
	 * @return void
	 */
	private function write_icon( string $name, string $content = self::VALID_ICON, string $returns = '' ): void {
		file_put_contents(
			$this->plugin_dir . '/svg-icons/' . $name . '.php',
			"<?php ?>" . $content . ( '' === $returns ? '' : "\n<?php\nreturn " . $returns . ';' )
		);
	}

	/**
	 * Build the module, which registers on `init` -- already fired here, so the
	 * registration happens during this call.
	 *
	 * @return Icons
	 */
	private function boot(): IconsLibrary {
		return $this->plugin->get( IconsLibrary::class );
	}

	/**
	 * Build the module with an initializer, the way `bootstrap.php` configures one.
	 *
	 * `init` has already fired in a test, so `get()` registers everything during
	 * the call and anything set afterwards is too late -- which is not true of a
	 * real request, where boot happens while the plugin file loads. Configuring
	 * through the initializer is what puts the two in the same order.
	 *
	 * @param callable $initializer Called with the module, before it boots.
	 * @return Icons
	 */
	private function boot_with( callable $initializer ): IconsLibrary {
		/** @var IconsLibrary $icons */
		$icons = $this->plugin->make( IconsLibrary::class, $initializer );

		return $icons;
	}


	/**
	 * The plain form: a file a designer exported, with no PHP around it. Its label
	 * is built from the filename, which is the whole of what it gives up.
	 */
	public function test_a_plain_svg_is_registered_too(): void {
		file_put_contents( $this->plugin_dir . '/svg-icons/logo.svg', self::VALID_ICON );

		$this->boot();

		$this->assertNotSame( '', wp_get_icon( 'zestry-test/logo' ) );
		$this->assertSame(
			'Logo',
			\WP_Icons_Registry::get_instance()->get_registered_icon( 'zestry-test/logo' )['label']
		);
	}

	/**
	 * Registered as a path rather than as content, so WordPress reads it only when
	 * the icon is actually rendered.
	 */
	public function test_a_plain_svg_is_registered_by_path(): void {
		file_put_contents( $this->plugin_dir . '/svg-icons/logo.svg', self::VALID_ICON );

		$this->boot();

		$this->assertArrayHasKey(
			'file_path',
			\WP_Icons_Registry::get_instance()->get_registered_icon( 'zestry-test/logo' )
		);
	}

	/**
	 * The one way two icon files can collide: both spellings are one name, and
	 * only one of them can be it.
	 */
	public function test_the_same_name_as_both_php_and_svg_throws(): void {
		$this->write_icon( 'arrow' );
		file_put_contents( $this->plugin_dir . '/svg-icons/arrow.svg', self::VALID_ICON );

		$this->expectException( DiscoveryException::class );
		$this->expectExceptionMessage( 'resolve to the name "arrow"' );

		$this->boot();
	}

	/**
	 * The sanitizer guard reads a plain `.svg` off disk, so an icon that would be
	 * cut down is refused whichever way it was written.
	 */
	public function test_a_plain_svg_is_checked_against_the_sanitizer_too(): void {
		file_put_contents(
			$this->plugin_dir . '/svg-icons/badge.svg',
			'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24">'
				. '<path d="M5 12h14" /><circle cx="12" cy="12" r="10" /></svg>'
		);

		$this->expectException( DiscoveryException::class );
		$this->expectExceptionMessage( '<circle>' );

		$this->boot();
	}


	/**
	 * Its slug is the plugin slug and stays that way; the label is what a designer
	 * reads, and saying nothing gives them the slug back.
	 */
	public function test_the_default_collection_is_labelled_from_the_slug(): void {
		$this->write_icon( 'arrow-right' );

		$this->boot();

		$this->assertSame(
			'zestry-test icons',
			\WP_Icon_Collections_Registry::get_instance()->get_registered( 'zestry-test' )['label']
		);
	}

	public function test_the_default_collection_details_can_be_stated(): void {
		$this->write_icon( 'arrow-right' );

		$this->boot_with(
			static function ( IconsLibrary $icons ): void {
				$icons->set_default_collection_details( 'Acme icons', 'Everything Acme draws.' );
			}
		);

		$collection = \WP_Icon_Collections_Registry::get_instance()->get_registered( 'zestry-test' );

		$this->assertSame( 'Acme icons', $collection['label'] );
		$this->assertSame( 'Everything Acme draws.', $collection['description'] );
	}

	public function test_an_icon_can_belong_to_a_declared_collection(): void {
		$this->write_icon( 'logo', self::VALID_ICON, "array( 'collection' => 'acme-brand' )" );

		$this->boot_with(
			static function ( IconsLibrary $icons ): void {
				$icons->add_collections( array( 'acme-brand' => 'Acme brand' ) );
			}
		);

		$this->assertNotSame( '', wp_get_icon( 'acme-brand/logo' ) );
		$this->assertSame( '', wp_get_icon( 'zestry-test/logo' ) );
	}

	/**
	 * The collection is worked out from what the icon registered as, so an icon
	 * filed elsewhere is still reached by its local name.
	 */
	public function test_get_finds_an_icon_in_another_collection(): void {
		$this->write_icon( 'logo', self::VALID_ICON, "array( 'collection' => 'acme-brand' )" );

		$icons = $this->boot_with(
			static function ( IconsLibrary $icons ): void {
				$icons->add_collections( array( 'acme-brand' => 'Acme brand' ) );
			}
		);

		$this->assertNotSame( '', $icons->get( 'logo' ) );
	}

	/**
	 * Declaring a collection is already a deliberate act, so nothing here works out
	 * which ones the icons use: the plugin's own is registered alongside them.
	 */
	public function test_the_default_collection_is_registered_alongside_declared_ones(): void {
		$this->write_icon( 'logo', self::VALID_ICON, "array( 'collection' => 'acme-brand' )" );

		$this->boot_with(
			static function ( IconsLibrary $icons ): void {
				$icons->add_collections( array( 'acme-brand' => 'Acme brand' ) );
			}
		);

		$registry = \WP_Icon_Collections_Registry::get_instance();

		$this->assertTrue( $registry->is_registered( 'acme-brand' ) );
		$this->assertTrue( $registry->is_registered( 'zestry-test' ) );
	}

	/**
	 * A declaration for the plugin's own slug replaces the derived one, so the two
	 * ways of naming it cannot both apply.
	 */
	public function test_a_declaration_for_the_plugins_own_slug_wins(): void {
		$this->write_icon( 'arrow-right' );

		$this->boot_with(
			static function ( IconsLibrary $icons ): void {
				$icons->add_collections( array( 'zestry-test' => 'Declared here' ) );
				$icons->set_default_collection_details( 'Set there' );
			}
		);

		$this->assertSame(
			'Declared here',
			\WP_Icon_Collections_Registry::get_instance()->get_registered( 'zestry-test' )['label']
		);
	}

	public function test_an_icon_naming_a_collection_nobody_registered_throws(): void {
		$this->write_icon( 'logo', self::VALID_ICON, "array( 'collection' => 'nowhere' )" );

		$this->expectException( DiscoveryException::class );
		$this->expectExceptionMessage( 'nowhere' );

		$this->boot();
	}

	/**
	 * A name is only claimed within its own collection, so two collections may
	 * each have an `arrow`.
	 */
	public function test_one_name_in_two_collections_is_not_a_collision(): void {
		$this->write_icon( 'arrow' );
		file_put_contents(
			$this->plugin_dir . '/svg-icons/arrow-brand.php',
			"<?php ?>" . self::VALID_ICON . "\n<?php\nreturn array( 'name' => 'arrow', 'collection' => 'acme-brand' );"
		);

		$this->boot_with(
			static function ( IconsLibrary $icons ): void {
				$icons->add_collections( array( 'acme-brand' => 'Acme brand' ) );
			}
		);

		$this->assertNotSame( '', wp_get_icon( 'zestry-test/arrow' ) );
		$this->assertNotSame( '', wp_get_icon( 'acme-brand/arrow' ) );
	}
}
