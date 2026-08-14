<?php

declare( strict_types=1 );

namespace Zestry\WPToolkit\Tests\Integration\Modules;

use Zestry\WPToolkit\Kernel\Exceptions\DiscoveryException;
use Zestry\WPToolkit\Modules\AdminPages\AdminMenu;
use Zestry\WPToolkit\Modules\AdminPages\AdminPage;
use Zestry\WPToolkit\Modules\AdminPages\AdminPages;
use Zestry\WPToolkit\Tests\Support\TestCase;

/**
 * Admin page discovery, menu registration, deferred parents, and render dispatch.
 *
 * @covers \Zestry\WPToolkit\Modules\AdminPages\AdminPages
 */
final class AdminPagesTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		// Look like an admin request so the module registers on admin_menu.
		set_current_screen( 'dashboard' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		mkdir( $this->plugin_dir . '/resources/admin-pages', 0777, true );
	}

	public function tear_down(): void {
		$GLOBALS['admin_page_hooks'] = array();
		unset( $_GET['page'], $_POST['_wpnonce'], $_REQUEST['_wpnonce'] );
		$_POST = array();
		set_current_screen( 'front' );
		parent::tear_down();
	}



	/**
	 * A folder's landing page and a same-named sibling both mean one slug, so
	 * only one of them may exist.
	 *
	 * @return void
	 */
	public function test_a_folder_index_colliding_with_a_sibling_throws(): void {
		$this->write_page( 'reports', $this->top_level_page( 'Reports' ) );
		$this->write_page( 'reports/index', $this->top_level_page( 'Reports Landing' ) );

		$this->expectException( DiscoveryException::class );
		$this->expectExceptionMessage( 'reports.php and reports/index.php' );
		$this->admin_pages();
		do_action( 'admin_menu' );
	}

	/**
	 * A slug that cannot survive `?page=` is refused, not repaired: stripping the
	 * character would register the page under a name nobody typed.
	 *
	 * @return void
	 */
	public function test_a_page_whose_slug_a_url_cannot_carry_throws(): void {
		$this->write_page( 'settings&more', $this->top_level_page( 'Settings' ) );

		$this->expectException( DiscoveryException::class );
		$this->expectExceptionMessage( 'would register as "zestry-test-settings&more"' );
		$this->admin_pages();
		do_action( 'admin_menu' );
	}

	/**
	 * The gate is what a URL must encode, not `sanitize_key()`: a capital letter
	 * and a dot both reach `?page=` intact, so neither is refused or rewritten.
	 *
	 * @return void
	 */
	public function test_a_capital_or_a_dot_in_a_page_name_is_kept_verbatim(): void {
		$this->write_page( 'Reports.v2', $this->top_level_page( 'Reports' ) );

		$this->admin_pages();
		do_action( 'admin_menu' );

		global $menu;
		$this->assertContains(
			'zestry-test-Reports.v2',
			array_column( (array) $menu, 2 ),
			'The page slug keeps the spelling the filename gave it.'
		);
	}

	private function admin_pages(): AdminPages {
		return $this->plugin->get( AdminPages::class );
	}

	/**
	 * Write a page file returning an anonymous AdminPage subclass.
	 *
	 * @param string $name       Page file base name.
	 * @param string $class_body PHP body of the anonymous AdminPage subclass.
	 * @return void
	 */
	private function write_page( string $name, string $class_body, string $base = 'AdminPage' ): void {
		$this->write_plugin_file(
			"resources/admin-pages/{$name}.php",
			"<?php\nuse Zestry\\WPToolkit\\Modules\\AdminPages\\AdminMenu;\n"
				. "use Zestry\\WPToolkit\\Modules\\AdminPages\\AdminPage;\nuse Zestry\\WPToolkit\\Modules\\AdminPages\\ParentMenu;\n"
				. "return new class extends {$base} {\n{$class_body}\n};\n"
		);
	}

	private function top_level_page( string $name ): string {
		return "public function title(): string { return '{$name} Title'; }\n"
			. "public function capability(): string { return 'manage_options'; }\n"
			. "public function render(): void { echo '{$name}-body'; }";
	}

	public function test_a_top_level_page_is_registered_on_the_admin_menu(): void {
		$this->write_page( 'dashboard-x', $this->top_level_page( 'Dash' ) );

		$this->admin_pages(); // boot registers the admin_menu hook
		do_action( 'admin_menu' );

		global $menu;
		$slugs = array_column( (array) $menu, 2 );
		$this->assertContains( 'zestry-test-dashboard-x', $slugs, 'The top-level page is on the menu.' );
	}

	public function test_a_network_page_is_registered_only_on_the_network_menu(): void {
		$this->write_page(
			'net-settings',
			"public function title(): string { return 'Network Settings'; }\n"
				. "public function capability(): string { return 'manage_network_options'; }\n"
				. "public function menu(): AdminMenu { return AdminMenu::Network; }\n"
				. "public function render(): void {}"
		);

		$this->admin_pages();
		do_action( 'admin_menu' );

		global $menu;
		$this->assertNotContains(
			'zestry-test-net-settings',
			array_column( (array) $menu, 2 ),
			'A network page has no business on a single site\'s menu.'
		);

		set_current_screen( 'dashboard-network' );
		do_action( 'network_admin_menu' );

		$this->assertContains( 'zestry-test-net-settings', array_column( (array) $menu, 2 ) );
	}

	public function test_a_site_page_is_not_registered_on_the_network_menu(): void {
		$this->write_page( 'site-only', $this->top_level_page( 'Site Only' ) );

		set_current_screen( 'dashboard-network' );
		$this->admin_pages();
		do_action( 'network_admin_menu' );

		global $menu;
		$this->assertNotContains( 'zestry-test-site-only', array_column( (array) $menu, 2 ) );
	}

	public function test_a_network_page_nests_under_the_network_settings_file(): void {
		$this->write_page(
			'net-child',
			"public function title(): string { return 'Net Child'; }\n"
				// A real network page asks for manage_network_options; the test user
				// is a plain site administrator, and add_submenu_page() drops a page
				// the current user cannot see, which would hide what is under test.
				. "public function capability(): string { return 'manage_options'; }\n"
				. "public function menu(): AdminMenu { return AdminMenu::Network; }\n"
				. "public function parent(): ParentMenu|string|null { return ParentMenu::Settings; }\n"
				. "public function render(): void {}"
		);

		set_current_screen( 'dashboard-network' );
		$this->admin_pages();
		do_action( 'network_admin_menu' );

		global $submenu;
		// The network menu's settings live in settings.php; options-general.php is
		// the site menu's file and is not on screen here at all.
		$this->assertContains( 'zestry-test-net-child', array_column( $submenu['settings.php'] ?? array(), 2 ) );
		$this->assertNotContains( 'zestry-test-net-child', array_column( $submenu['options-general.php'] ?? array(), 2 ) );
	}

	public function test_a_network_page_url_points_at_the_network_admin(): void {
		$this->write_page(
			'net-linked',
			"public function title(): string { return 'Net Linked'; }\n"
				. "public function capability(): string { return 'manage_network_options'; }\n"
				. "public function menu(): AdminMenu { return AdminMenu::Network; }\n"
				. "public function render(): void {}"
		);

		set_current_screen( 'dashboard-network' );
		$pages = $this->admin_pages();
		do_action( 'network_admin_menu' );

		$this->assertSame(
			network_admin_url( 'admin.php?page=zestry-test-net-linked' ),
			$pages->get_page_url( 'net-linked' )
		);
	}

	public function test_a_page_nested_under_the_other_menu_fails_loudly(): void {
		$this->write_page(
			'net-parent',
			"public function title(): string { return 'Net Parent'; }\n"
				. "public function capability(): string { return 'manage_network_options'; }\n"
				. "public function menu(): AdminMenu { return AdminMenu::Network; }\n"
				. "public function render(): void {}"
		);
		$this->write_page(
			'site-child',
			"public function title(): string { return 'Site Child'; }\n"
				. "public function capability(): string { return 'manage_options'; }\n"
				. "public function parent(): ParentMenu|string|null { return \$this->get_page_slug( 'net-parent' ); }\n"
				. "public function render(): void {}"
		);

		$this->admin_pages();

		$this->expectException( DiscoveryException::class );
		$this->expectExceptionMessage( 'zestry-test-net-parent' );
		do_action( 'admin_menu' );
	}

	public function test_a_page_under_a_core_parent_becomes_a_submenu(): void {
		$this->write_page(
			'settings-x',
			"public function title(): string { return 'Settings X'; }\n"
				. "public function capability(): string { return 'manage_options'; }\n"
				. "public function parent(): ParentMenu|string|null { return ParentMenu::Settings; }\n"
				. "public function render(): void {}"
		);

		$this->admin_pages();
		do_action( 'admin_menu' );

		global $submenu;
		$options_children = array_column( $submenu['options-general.php'] ?? array(), 2 );
		$this->assertContains( 'zestry-test-settings-x', $options_children, 'Registered under Settings.' );
	}

	public function test_a_custom_parent_page_is_registered_even_when_declared_first(): void {
		// The child is discovered before the parent, so registration must defer it.
		$this->write_page(
			'child',
			"public function title(): string { return 'Child'; }\n"
				. "public function capability(): string { return 'manage_options'; }\n"
				// Reference the sibling by its fully-qualified slug via get_page_slug().
				. "public function parent(): ParentMenu|string|null { return \$this->get_page_slug( 'topparent' ); }\n"
				. "public function render(): void {}"
		);
		$this->write_page( 'topparent', $this->top_level_page( 'Parent' ) );

		$this->admin_pages();
		do_action( 'admin_menu' );

		global $submenu;
		$children = array_column( $submenu['zestry-test-topparent'] ?? array(), 2 );
		$this->assertContains( 'zestry-test-child', $children, 'The deferred child registered under its parent.' );
	}

	public function test_a_folder_index_becomes_a_top_level_page_named_for_the_folder(): void {
		$this->write_page( 'reports/index', $this->top_level_page( 'Reports' ) );

		$this->admin_pages();
		do_action( 'admin_menu' );

		global $menu;
		$this->assertContains( 'zestry-test-reports', array_column( (array) $menu, 2 ), 'reports/index.php -> top-level "reports".' );
	}

	public function test_the_root_index_page_uses_the_bare_plugin_slug(): void {
		// index.php in the root maps to the plugin slug itself, with no page suffix.
		$this->write_page( 'index', $this->top_level_page( 'Home' ) );

		$pages = $this->admin_pages();
		do_action( 'admin_menu' );

		global $menu;
		$this->assertContains( 'zestry-test', array_column( (array) $menu, 2 ), 'root index.php -> the bare plugin slug.' );
		$this->assertNotContains( 'zestry-test-index', array_column( (array) $menu, 2 ), 'No "-index" suffix at the root.' );
		$this->assertArrayHasKey( 'zestry-test', $pages->get_pages(), 'The page is registered under the bare slug.' );

		// It routes and renders under the bare slug too.
		$_GET['page'] = 'zestry-test';
		ob_start();
		$pages->render();
		$this->assertStringContainsString( 'Home-body', ob_get_clean() );
	}

	public function test_slug_of_falls_back_to_the_filename_for_an_undiscovered_page(): void {
		// A page constructed directly (not via register_pages()) is not in the
		// registry, so get_slug_of() falls back to deriving the slug from the file
		// that declares it — this test file itself.
		$page = new class() extends AdminPage {
			public function title(): string {
				return 'Undiscovered';
			}

			public function capability(): string {
				return 'manage_options';
			}

			public function render(): void {}
		};
		$this->plugin->wire( $page );

		$this->assertSame( 'zestry-test-AdminPagesTest', $this->admin_pages()->get_slug_of( $page ) );
	}

	public function test_a_file_in_a_folder_becomes_a_child_of_the_folder(): void {
		$this->write_page( 'reports/index', $this->top_level_page( 'Reports' ) );
		$this->write_page(
			'reports/monthly',
			"public function title(): string { return 'Monthly'; }\n"
				. "public function capability(): string { return 'manage_options'; }\n"
				. "public function render(): void {}"
		);

		$this->admin_pages();
		do_action( 'admin_menu' );

		global $submenu;
		$children = array_column( $submenu['zestry-test-reports'] ?? array(), 2 );
		$this->assertContains( 'zestry-test-reports-monthly', $children, 'reports/monthly.php nests under reports.' );
	}

	public function test_deeper_folders_flatten_under_the_top_level_folder(): void {
		$this->write_page( 'reports/index', $this->top_level_page( 'Reports' ) );
		$this->write_page(
			'reports/advanced/tuning',
			"public function title(): string { return 'Tuning'; }\n"
				. "public function capability(): string { return 'manage_options'; }\n"
				. "public function render(): void {}"
		);

		$this->admin_pages();
		do_action( 'admin_menu' );

		global $submenu;
		$children = array_column( $submenu['zestry-test-reports'] ?? array(), 2 );
		$this->assertContains( 'zestry-test-reports-advanced-tuning', $children, 'Deep files flatten under the top folder.' );
	}

	public function test_an_explicit_parent_overrides_folder_placement(): void {
		$this->write_page( 'reports/index', $this->top_level_page( 'Reports' ) );
		// A file inside reports/ that explicitly places itself under core Settings.
		$this->write_page(
			'reports/prefs',
			"public function title(): string { return 'Prefs'; }\n"
				. "public function capability(): string { return 'manage_options'; }\n"
				. "public function parent(): ParentMenu|string|null { return ParentMenu::Settings; }\n"
				. "public function render(): void {}"
		);

		$this->admin_pages();
		do_action( 'admin_menu' );

		global $submenu;
		$under_settings = array_column( $submenu['options-general.php'] ?? array(), 2 );
		$under_reports  = array_column( $submenu['zestry-test-reports'] ?? array(), 2 );
		$this->assertContains( 'zestry-test-reports-prefs', $under_settings, 'Explicit parent() wins.' );
		$this->assertNotContains( 'zestry-test-reports-prefs', $under_reports, 'It is not under its folder.' );
	}

	public function test_a_verbatim_external_parent_slug_is_used_as_is(): void {
		// A parent that is not one of this plugin's pages (a core WP menu slug) is
		// passed to add_submenu_page() unchanged.
		$this->write_page(
			'under-tools',
			"public function title(): string { return 'Under Tools'; }\n"
				. "public function capability(): string { return 'manage_options'; }\n"
				. "public function parent(): ParentMenu|string|null { return 'tools.php'; }\n"
				. "public function render(): void {}"
		);

		$this->admin_pages();
		do_action( 'admin_menu' );

		global $submenu;
		$children = array_column( $submenu['tools.php'] ?? array(), 2 );
		$this->assertContains( 'zestry-test-under-tools', $children, 'Registered under the verbatim WP menu slug.' );
	}

	public function test_a_page_file_returning_the_wrong_type_throws(): void {
		$this->write_plugin_file( 'resources/admin-pages/bad.php', "<?php\nreturn 42;\n" );

		$this->admin_pages();

		$this->expectException( DiscoveryException::class );
		$this->expectExceptionMessage( 'must return an instance of' );
		do_action( 'admin_menu' );
	}

	public function test_render_outputs_the_page_body_for_an_authorised_user(): void {
		$this->write_page( 'view-me', $this->top_level_page( 'View' ) );
		$pages = $this->admin_pages();
		do_action( 'admin_menu' );

		$_GET['page'] = 'zestry-test-view-me';

		ob_start();
		$pages->render();
		$this->assertStringContainsString( 'View-body', ob_get_clean() );
	}

	public function test_render_rejects_a_user_without_the_capability(): void {
		$this->write_page(
			'restricted',
			"public function title(): string { return 'Restricted'; }\n"
				. "public function capability(): string { return 'activate_plugins'; }\n"
				. "public function render(): void { echo 'secret'; }"
		);
		$pages = $this->admin_pages();
		do_action( 'admin_menu' );

		// Downgrade to a user who lacks the capability.
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$_GET['page'] = 'zestry-test-restricted';

		$this->expectException( \WPDieException::class );
		$pages->render();
	}

	/**
	 * The whole reason `handle_submit()` is not part of `render()`: WordPress calls
	 * a page's own callback from `wp-admin/admin.php` *after* requiring
	 * `admin-header.php`, so a `wp_safe_redirect()` there reaches headers that are
	 * already sent -- two warnings, no `Location`, and `exit` truncating the page.
	 * `load-{$hook}` fires immediately before that require.
	 *
	 * @return void
	 */
	public function test_the_submit_pass_is_bound_before_wordpress_sends_output(): void {
		$this->write_page( 'form', $this->top_level_page( 'Form' ) );

		$this->admin_pages();
		do_action( 'admin_menu' );

		$hook = get_plugin_page_hookname( 'zestry-test-form', '' );

		$this->assertNotFalse(
			has_action( 'load-' . $hook, array( $this->admin_pages(), 'handle_submit' ) ),
			'A redirect from handle_submit() is only possible from load-{$hook}.'
		);
	}

	/**
	 * And the symptom that reported it: the generated page redirects after saving,
	 * which is only possible while nothing has been sent. Asserted through
	 * `wp_redirect`, since the test suite cannot let a real header out.
	 *
	 * @return void
	 */
	public function test_a_page_can_redirect_after_saving(): void {
		$this->write_page(
			'form',
			"public function title(): string { return 'Form'; }\n"
				. "public function capability(): string { return 'manage_options'; }\n"
				. "public function handle_submit(): void { wp_safe_redirect( \$this->get_page_url( null, array( 'updated' => '1' ) ) ); }\n"
				. "public function render(): void { echo 'form-body'; }"
		);

		$pages = $this->admin_pages();
		do_action( 'admin_menu' );

		$slug         = 'zestry-test-form';
		$_GET['page'] = $slug;
		$_POST        = array( 'field' => 'value' );

		$page_instance        = $pages->get_pages()[ $slug ];
		$_REQUEST['_wpnonce'] = wp_create_nonce( $page_instance->get_nonce_action() );

		$redirected_to = null;

		add_filter(
			'wp_redirect',
			static function ( $location ) use ( &$redirected_to ) {
				$redirected_to = $location;

				return false;
			}
		);

		$pages->handle_submit();

		$this->assertNotNull( $redirected_to, 'handle_submit() reached wp_safe_redirect().' );
		$this->assertStringContainsString( 'updated=1', (string) $redirected_to );

		// Not asserted here: `headers_sent()` is true under PHPUnit, which has already
		// written to stdout. That this runs before WordPress's own output is pinned by
		// test_the_submit_pass_is_bound_before_wordpress_sends_output() instead.
	}

	/**
	 * A page declares what it takes the same way a route, an ability and an AJAX
	 * action do, and reads `$this->title` rather than `$_POST['title']`. The
	 * declared type is what turns the posted string into an int.
	 *
	 * @return void
	 */
	public function test_a_page_reads_its_declared_arguments_rather_than_the_post(): void {
		$this->write_page(
			'declared',
			"#[\\Zestry\\WPToolkit\\Modules\\Request\\Attributes\\RequestArgument( 'How many.' )]\n"
				. "public int \$quantity;\n"
				. "public function title(): string { return 'Declared'; }\n"
				. "public function capability(): string { return 'manage_options'; }\n"
				. "public function handle_submit(): void { \$GLOBALS['zestry_bound'] = \$this->quantity; }\n"
				. "public function render(): void { echo 'body'; }"
		);

		$pages = $this->admin_pages();
		do_action( 'admin_menu' );

		$slug         = 'zestry-test-declared';
		$_GET['page'] = $slug;
		$_POST        = array( 'quantity' => '7' );

		// PHP fills $_POST on a real POST only, and WP_REST_Request reads a body on
		// a method that carries one -- so a test standing one up has to say both.
		$_SERVER['REQUEST_METHOD'] = 'POST';

		$_REQUEST['_wpnonce'] = wp_create_nonce( $pages->get_pages()[ $slug ]->get_nonce_action() );

		try {
			$pages->handle_submit();

			$this->assertSame( 7, $GLOBALS['zestry_bound'] ?? null, 'The posted string arrived as the declared int.' );
		} finally {
			unset( $GLOBALS['zestry_bound'], $_SERVER['REQUEST_METHOD'] );
		}
	}

	/**
	 * A value that does not fit stops the submission the same way a failed
	 * capability and a failed nonce already stop it, rather than saving a page
	 * whose declared properties were never bound.
	 *
	 * @return void
	 */
	public function test_a_refused_argument_stops_the_submission(): void {
		$this->write_page(
			'refuses',
			"#[\\Zestry\\WPToolkit\\Modules\\Request\\Attributes\\RequestArgument( 'How many.' )]\n"
				. "public int \$quantity;\n"
				. "public function title(): string { return 'Refuses'; }\n"
				. "public function capability(): string { return 'manage_options'; }\n"
				. "public function handle_submit(): void { \$GLOBALS['zestry_saved'] = true; }\n"
				. "public function render(): void { echo 'body'; }"
		);

		$pages = $this->admin_pages();
		do_action( 'admin_menu' );

		$slug         = 'zestry-test-refuses';
		$_GET['page'] = $slug;
		$_POST        = array( 'quantity' => 'not-a-number' );

		// PHP fills $_POST on a real POST only, and WP_REST_Request reads a body on
		// a method that carries one -- so a test standing one up has to say both.
		$_SERVER['REQUEST_METHOD'] = 'POST';

		$_REQUEST['_wpnonce'] = wp_create_nonce( $pages->get_pages()[ $slug ]->get_nonce_action() );

		try {
			$pages->handle_submit();

			$this->fail( 'A refused argument should have stopped the submission.' );
		} catch ( \WPDieException $e ) {
			$this->assertStringContainsString( 'quantity', $e->getMessage(), 'The message names what did not fit.' );
			$this->assertArrayNotHasKey( 'zestry_saved', $GLOBALS, 'handle_submit() never ran.' );
		} finally {
			unset( $GLOBALS['zestry_saved'], $_SERVER['REQUEST_METHOD'] );
		}
	}

	/**
	 * A hidden page is registered and nothing lists it. Both halves matter: the
	 * `$_registered_pages` entry is what `admin.php` checks before serving
	 * `?page=`, so without it the page is a "You do not have sufficient
	 * permissions" screen rather than a page nobody links to.
	 *
	 * @return void
	 */
	public function test_a_hidden_page_is_registered_but_on_no_menu(): void {
		$this->write_page(
			'confirm',
			"public function title(): string { return 'Confirm'; }\n"
				. "public function capability(): string { return 'manage_options'; }\n"
				. "public function is_hidden(): bool { return true; }\n"
				. "public function render(): void { echo 'confirm-body'; }"
		);

		$this->admin_pages();
		do_action( 'admin_menu' );

		global $menu, $submenu, $_registered_pages;

		$slug = 'zestry-test-confirm';

		$this->assertArrayHasKey(
			get_plugin_page_hookname( $slug, '' ),
			(array) $_registered_pages,
			'Registered, so admin.php will serve it.'
		);

		$this->assertNotContains( $slug, array_column( (array) $menu, 2 ), 'Not a top-level item.' );

		// The entry does exist, in $submenu[''] -- the bucket an empty parent slug
		// makes. Nothing renders it, because no top-level menu claims that slug.
		// What matters is that it is under no *real* parent, so no menu shows it.
		foreach ( (array) $submenu as $parent => $items ) {
			if ( '' === $parent ) {
				continue;
			}

			$this->assertNotContains(
				$slug,
				array_column( (array) $items, 2 ),
				\sprintf( 'A hidden page must sit under no real parent, and it appeared under "%s".', $parent )
			);
		}

		$this->assertNotContains(
			'',
			array_column( (array) $menu, 2 ),
			"And nothing renders \$submenu[''], because no top-level menu has an empty slug."
		);
	}

	/**
	 * The submit pass is still bound, so a hidden page can be a form -- which is
	 * most of what a page nobody browses to is for.
	 *
	 * @return void
	 */
	public function test_a_hidden_page_still_handles_a_submission(): void {
		$this->write_page(
			'confirm-form',
			"public function title(): string { return 'Confirm'; }\n"
				. "public function capability(): string { return 'manage_options'; }\n"
				. "public function is_hidden(): bool { return true; }\n"
				. "public function handle_submit(): void { \$GLOBALS['zestry_hidden_saved'] = true; }\n"
				. "public function render(): void { echo 'body'; }"
		);

		$pages = $this->admin_pages();
		do_action( 'admin_menu' );

		$slug         = 'zestry-test-confirm-form';
		$_GET['page'] = $slug;
		$_POST        = array( 'anything' => '1' );

		$_REQUEST['_wpnonce'] = wp_create_nonce( $pages->get_pages()[ $slug ]->get_nonce_action() );

		try {
			$pages->handle_submit();

			$this->assertTrue( $GLOBALS['zestry_hidden_saved'] ?? false, 'handle_submit() ran.' );
		} finally {
			unset( $GLOBALS['zestry_hidden_saved'] );
		}
	}

	/**
	 * And its URL still resolves through admin.php, since a null parent is what
	 * WordPress records for it -- a link is the only way anyone reaches the page.
	 *
	 * @return void
	 */
	public function test_a_hidden_pages_url_still_resolves(): void {
		$this->write_page(
			'hidden-linked',
			"public function title(): string { return 'Hidden'; }\n"
				. "public function capability(): string { return 'manage_options'; }\n"
				. "public function is_hidden(): bool { return true; }\n"
				. "public function render(): void {}"
		);

		$pages = $this->admin_pages();
		do_action( 'admin_menu' );

		$this->assertSame(
			admin_url( 'admin.php?page=zestry-test-hidden-linked' ),
			$pages->get_page_url( 'hidden-linked' )
		);
	}

	/**
	 * Nesting under a hidden page would register a submenu of nothing, which is
	 * the same reachable-but-unlisted failure the cross-menu guard refuses.
	 *
	 * @return void
	 */
	public function test_a_page_nested_under_a_hidden_page_throws(): void {
		$this->write_page(
			'vault',
			"public function title(): string { return 'Vault'; }\n"
				. "public function capability(): string { return 'manage_options'; }\n"
				. "public function is_hidden(): bool { return true; }\n"
				. "public function render(): void {}"
		);
		$this->write_page(
			'vault/inner',
			"public function title(): string { return 'Inner'; }\n"
				. "public function capability(): string { return 'manage_options'; }\n"
				. "public function render(): void {}"
		);

		$this->expectException( DiscoveryException::class );
		$this->expectExceptionMessage( 'which is hidden' );

		$this->admin_pages();
		do_action( 'admin_menu' );
	}

	public function test_render_processes_a_valid_post_submission(): void {
		$this->write_page(
			'form',
			"public bool \$submitted = false;\n"
				. "public function title(): string { return 'Form'; }\n"
				. "public function capability(): string { return 'manage_options'; }\n"
				. "public function handle_submit(): void { update_option( 'zestry_form_submitted', 'yes' ); }\n"
				. "public function render(): void { echo 'form-body'; }"
		);
		$pages = $this->admin_pages();
		do_action( 'admin_menu' );

		$slug         = 'zestry-test-form';
		$_GET['page'] = $slug;
		$_POST        = array( 'field' => 'value' );

		// A valid nonce for this page's action.
		$page_instance        = $pages->get_pages()[ $slug ];
		$_REQUEST['_wpnonce'] = wp_create_nonce( $page_instance->get_nonce_action() );

		$pages->handle_submit();

		ob_start();
		$pages->render();
		$this->assertStringContainsString( 'form-body', ob_get_clean() );
		$this->assertSame( 'yes', get_option( 'zestry_form_submitted' ), 'handle_submit() ran after nonce passed.' );

		delete_option( 'zestry_form_submitted' );
	}

	public function test_page_url_builds_a_query_for_a_registered_page(): void {
		$this->write_page( 'linked', $this->top_level_page( 'Linked' ) );
		$pages = $this->admin_pages();
		do_action( 'admin_menu' );

		$url = $pages->get_page_url( 'linked', array( 'tab' => 'general' ) );

		$this->assertStringContainsString( 'page=zestry-test-linked', $url );
		$this->assertStringContainsString( 'tab=general', $url );
	}

	public function test_does_nothing_outside_an_admin_request(): void {
		set_current_screen( 'front' ); // not is_admin()

		// Fresh plugin so on_boot() runs while not in admin.
		$pages = $this->admin_pages();
		do_action( 'admin_menu' );

		$this->assertSame( array(), $pages->get_pages(), 'No pages are discovered outside admin.' );
	}

	public function test_render_dies_for_an_unknown_page(): void {
		$this->admin_pages();
		do_action( 'admin_menu' );

		$_GET['page'] = 'zestry-test-nope';

		$this->expectException( \WPDieException::class );
		$this->admin_pages()->render();
	}

	public function test_a_submission_with_a_bad_nonce_dies(): void {
		$this->write_page( 'guarded-form', $this->top_level_page( 'Guarded' ) );
		$pages = $this->admin_pages();
		do_action( 'admin_menu' );

		$_GET['page']         = 'zestry-test-guarded-form';
		$_POST                = array( 'field' => 'x' );
		$_REQUEST['_wpnonce'] = 'invalid-nonce';

		$this->expectException( \WPDieException::class );
		$pages->handle_submit();
	}

	public function test_page_url_falls_back_when_the_page_has_no_menu_entry(): void {
		// get_page_url() for a slug that is not a registered menu page uses the
		// admin.php?page= fallback rather than menu_page_url().
		$url = $this->admin_pages()->get_page_url( 'unregistered' );

		$this->assertStringContainsString( 'admin.php', $url );
		$this->assertStringContainsString( 'page=zestry-test-unregistered', $url );
	}

	public function test_enqueue_assets_runs_only_for_the_current_page(): void {
		$this->write_page(
			'assets',
			"public function title(): string { return 'Assets'; }\n"
				. "public function capability(): string { return 'manage_options'; }\n"
				. "public function enqueue_assets(): void { \$GLOBALS['zestry_assets_ran'] = true; }\n"
				. "public function render(): void {}"
		);
		$this->admin_pages();
		do_action( 'admin_menu' );

		$GLOBALS['zestry_assets_ran'] = false;
		$_GET['page']              = 'zestry-test-assets';
		do_action( 'admin_enqueue_scripts', 'toplevel_page_zestry-test-assets' );

		$this->assertTrue( $GLOBALS['zestry_assets_ran'], 'enqueue_assets() ran for the current page.' );
		unset( $GLOBALS['zestry_assets_ran'] );
	}

	public function test_a_page_declaring_the_critical_styles_contract_has_them_enqueued(): void {
		// The contract is what the module dispatches on, so a page implementing
		// it gets its critical styles whether or not it overrides
		// enqueue_assets() -- which is the whole point of separating the two.
		$this->write_page(
			'critical',
			"public function title(): string { return 'Critical'; }\n"
				. "public function capability(): string { return 'manage_options'; }\n"
				. "public function enqueue_critical_styles(): void { \$GLOBALS['zestry_critical_ran'] = true; }\n"
				. "public function enqueue_assets(): void {}\n"
				. 'public function render(): void {}',
			'\\Zestry\\WPToolkit\\Modules\\AdminPages\\ModernAdminPage'
		);
		$this->admin_pages();
		do_action( 'admin_menu' );

		$GLOBALS['zestry_critical_ran'] = false;
		$_GET['page']                   = 'zestry-test-critical';
		do_action( 'admin_enqueue_scripts', 'toplevel_page_zestry-test-critical' );

		$this->assertTrue( $GLOBALS['zestry_critical_ran'], 'The module called enqueue_critical_styles().' );
		unset( $GLOBALS['zestry_critical_ran'] );
	}

	public function test_admin_body_class_is_added_for_the_current_page(): void {
		$this->write_page( 'bodyclass', $this->top_level_page( 'Body' ) );
		$this->admin_pages();
		do_action( 'admin_menu' );

		// No current page -> classes returned unchanged.
		$this->assertSame( 'base', apply_filters( 'admin_body_class', 'base' ) );

		// On the page -> the plugin/page classes are appended.
		$_GET['page'] = 'zestry-test-bodyclass';
		$classes      = apply_filters( 'admin_body_class', 'base' );
		$this->assertStringContainsString( 'zestry-test-admin-page', $classes );
		$this->assertStringContainsString( 'zestry-test-admin-page--zestry-test-bodyclass', $classes );
	}

	public function test_page_css_classname_accepts_a_page_instance_or_a_plain_slug(): void {
		$this->write_page( 'css-demo', $this->top_level_page( 'CssDemo' ) );
		$pages = $this->admin_pages();
		do_action( 'admin_menu' );

		$page = $pages->get_pages()['zestry-test-css-demo'];

		$this->assertSame( 'zestry-test-admin-page', $pages->get_base_css_classname() );
		// An AdminPage instance -> its full, plugin-prefixed slug is used.
		$this->assertSame( 'zestry-test-admin-page--zestry-test-css-demo', $pages->get_page_css_classname( $page ) );
		// A plain string -> used verbatim (sanitized only), not plugin-prefixed.
		$this->assertSame( 'zestry-test-admin-page--raw-slug', $pages->get_page_css_classname( 'raw-slug' ) );
	}
}
