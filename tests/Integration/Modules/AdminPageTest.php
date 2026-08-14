<?php

declare( strict_types=1 );

namespace Zestry\WPToolkit\Tests\Integration\Modules;

use Zestry\WPToolkit\Modules\AdminPages\AdminMenu;
use Zestry\WPToolkit\Modules\AdminPages\AdminPage;
use Zestry\WPToolkit\Modules\AdminPages\ParentMenu;
use Zestry\WPToolkit\Tests\Support\TestCase;

/**
 * The AdminPage base defaults and the ParentMenu enum mapping.
 *
 * @covers \Zestry\WPToolkit\Modules\AdminPages\AdminPage
 * @covers \Zestry\WPToolkit\Modules\AdminPages\ParentMenu
 */
final class AdminPageTest extends TestCase {

	private function page(): AdminPage {
		$page = new class() extends AdminPage {
			public function title(): string {
				return 'My Settings';
			}

			public function capability(): string {
				return 'manage_options';
			}

			public function render(): void {
				echo 'BODY';
			}
		};

		$this->plugin->wire( $page );

		return $page;
	}

	/**
	 * A page's markup lives in a template, and the template gets exactly what
	 * the call names -- nothing of the page itself, so its inputs are readable
	 * without opening it.
	 */
	public function test_view_renders_a_template_with_the_named_data(): void {
		$this->write_plugin_file(
			'resources/views/admin-pages/settings.php',
			'<?php echo esc_html( $title ) . "|" . $heading;'
		);

		ob_start();
		$this->page()->view(
			'admin-pages/settings',
			array(
				'title'   => 'My Settings',
				'heading' => 'Hi',
			)
		);

		$this->assertSame( 'My Settings|Hi', (string) ob_get_clean() );
	}

	/**
	 * The page itself is deliberately not in scope: a template that can reach
	 * the page can reach everything the page can.
	 */
	public function test_view_does_not_put_the_page_in_scope(): void {
		$this->write_plugin_file(
			'resources/views/admin-pages/settings.php',
			'<?php echo isset( $page ) ? "leaked" : "clean";'
		);

		ob_start();
		$this->page()->view( 'admin-pages/settings' );

		$this->assertSame( 'clean', (string) ob_get_clean() );
	}

	/**
	 * A subview goes through the Views service a template already has as
	 * `$this`, which is the shape reached for when nothing provided it.
	 */
	public function test_a_page_template_can_render_a_partial(): void {
		$this->write_plugin_file( 'resources/views/admin-pages/-notice.php', '<?php echo "[" . $message . "]";' );
		$this->write_plugin_file(
			'resources/views/admin-pages/settings.php',
			'<?php $this->render( "admin-pages/-notice", array( "message" => "saved" ) );'
		);

		ob_start();
		$this->page()->view( 'admin-pages/settings' );

		$this->assertSame( '[saved]', (string) ob_get_clean() );
	}

	public function test_menu_title_defaults_to_the_title(): void {
		$this->assertSame( 'My Settings', $this->page()->menu_title() );
	}

	public function test_defaults_for_parent_position_and_icon(): void {
		$page = $this->page();

		$this->assertNull( $page->parent(), 'Default placement is top-level (null).' );
		$this->assertNull( $page->position() );
		$this->assertSame( 'dashicons-admin-generic', $page->icon() );
	}

	public function test_page_slug_of_an_undiscovered_page_falls_back_to_its_filename(): void {
		// This page is constructed directly (not discovered), so the module resolves
		// its slug from the file name: this test file, plugin-prefixed.
		$this->assertSame( 'zestry-test-AdminPageTest', $this->page()->get_page_slug() );
	}

	public function test_get_nonce_action_is_scoped_to_the_plugin_and_page(): void {
		$page = $this->page();

		$this->assertSame( 'zestry-test-AdminPageTest-action', $page->get_nonce_action() );
	}

	public function test_optional_hooks_are_no_ops_by_default(): void {
		$page = $this->page();

		// enqueue_assets() and handle_submit() do nothing unless overridden.
		$this->expectNotToPerformAssertions();
		$page->enqueue_assets();
		$page->handle_submit();
	}

	public function test_nonce_field_outputs_a_nonce_input_for_this_page(): void {
		ob_start();
		$this->page()->nonce_field();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'name="_wpnonce"', $html );
	}

	public function test_page_slug_delegates_to_the_module_for_this_and_other_pages(): void {
		$page = $this->page();

		// No argument -> this page's own slug.
		$this->assertSame( 'zestry-test-AdminPageTest', $page->get_page_slug() );
		// A sibling short name -> prefixed by the module (no 'zestry-test-' written here).
		$this->assertSame( 'zestry-test-settings', $page->get_page_slug( 'settings' ) );
	}

	public function test_page_url_delegates_to_the_module(): void {
		$page = $this->page();

		$url = $page->get_page_url( 'settings', array( 'tab' => 'general' ) );

		$this->assertStringContainsString( 'page=zestry-test-settings', $url );
		$this->assertStringContainsString( 'tab=general', $url );
	}

	public function test_css_classnames_delegate_to_the_module(): void {
		$page = new class() extends AdminPage {
			public function title(): string {
				return 'My Settings';
			}

			public function capability(): string {
				return 'manage_options';
			}

			public function render(): void {}

			public function base_css_classname(): string {
				return $this->get_base_css_classname();
			}

			public function page_css_classname( ?string $page = null ): string {
				return $this->get_page_css_classname( $page );
			}
		};
		$this->plugin->wire( $page );

		$this->assertSame( 'zestry-test-admin-page', $page->base_css_classname() );
		// No argument -> this page's own (BEM modifier) class; sanitize_key() lowercases it.
		$this->assertSame( 'zestry-test-admin-page--zestry-test-adminpagetest', $page->page_css_classname() );
		// An explicit slug is used verbatim (sanitized), not plugin-prefixed.
		$this->assertSame( 'zestry-test-admin-page--settings', $page->page_css_classname( 'settings' ) );
	}

	/**
	 * @dataProvider parent_menus
	 */
	public function test_parent_menu_maps_to_its_admin_file( ParentMenu $menu, ?string $site, ?string $network ): void {
		foreach ( array( AdminMenu::Site->value => $site, AdminMenu::Network->value => $network ) as $name => $expected ) {
			$in = AdminMenu::from( $name );

			if ( null === $expected ) {
				// A section that menu does not have: registering the page there
				// would put it under a menu file that is not on screen.
				try {
					$menu->get_parent_file( $in );
					$this->fail( sprintf( '%s should not resolve in the %s menu.', $menu->name, $name ) );
				} catch ( \InvalidArgumentException $e ) {
					$this->assertStringContainsString( $menu->name, $e->getMessage() );
				}

				continue;
			}

			$this->assertSame( $expected, $menu->get_parent_file( $in ) );
		}
	}

	public function parent_menus(): array {
		// case => [ the site menu's admin file, the network menu's ], null where
		// that menu has no such section.
		return array(
			array( ParentMenu::Dashboard, 'index.php', 'index.php' ),
			array( ParentMenu::Posts, 'edit.php', null ),
			array( ParentMenu::Media, 'upload.php', null ),
			array( ParentMenu::Pages, 'edit.php?post_type=page', null ),
			array( ParentMenu::Comments, 'edit-comments.php', null ),
			array( ParentMenu::Themes, 'themes.php', 'themes.php' ),
			array( ParentMenu::Plugins, 'plugins.php', 'plugins.php' ),
			array( ParentMenu::Users, 'users.php', 'users.php' ),
			array( ParentMenu::Tools, 'tools.php', null ),
			array( ParentMenu::Settings, 'options-general.php', 'settings.php' ),
			array( ParentMenu::Sites, null, 'sites.php' ),
		);
	}
}
