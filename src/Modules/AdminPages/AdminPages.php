<?php

/**
 * Admin Pages API: AdminPages module
 */

declare( strict_types=1 );

namespace Zestry\WPToolkit\Modules\AdminPages;

// Loaded by WordPress, never requested directly.
\defined( 'ABSPATH' ) || exit;

use Zestry\WPToolkit\Kernel\Contracts\Bootable;
use Zestry\WPToolkit\Kernel\Abstracts\Module;
use Zestry\WPToolkit\Kernel\Exceptions\DiscoveryException;
use Zestry\WPToolkit\Modules\AdminPages\Contracts\RendersCriticalStyles;
use Zestry\WPToolkit\Kernel\Traits\WithFolderWalker;
use Zestry\WPToolkit\Modules\Path;

/**
 * Discovers plugin admin pages and registers them in the WordPress admin menu.
 *
 * A pages directory contains PHP files named after the page, such as
 * `resources/admin-pages/settings.php`, each returning an AdminPage instance. On an
 * admin request the module wires each page, registers it via the appropriate
 * WordPress menu function (top-level, a core submenu chosen by its ParentMenu,
 * or a custom parent), and dispatches to the page's render() when it is viewed
 * — enforcing the page capability first. A POST is handled a step earlier, on
 * `load-{$hook}`, so a page can still redirect after saving.
 *
 * @example A minimal page file
 * The actual authoring surface for most developers is not this class but the
 * page files it discovers. A page such as `resources/admin-pages/settings.php` need
 * only return an AdminPage subclass instance — the module assigns the
 * plugin, so `with()` reaches every module, derives the slug from the
 * file path, and wires up the menu entry.
 *
 * ```
 * <?php
 * return new class() extends AdminPage {
 *     public function title(): string {
 *         return __( 'Settings', 'acme-plugin' );
 *     }
 *     public function capability(): string {
 *         return 'manage_options';
 *     }
 *     public function render(): void {
 *         $this->view( 'admin-pages/settings' );
 *     }
 * };
 * ```
 *
 * @example Where the markup goes
 * A page's markup belongs in a template, and `wp zt make page` writes one
 * alongside the class. An admin page is mostly a form — a table, a notice, a
 * second form further down — and markup assembled by concatenation stops being
 * reviewable long before it stops growing.
 *
 * {@see AdminPage::view()} renders through the {@see \Zestry\WPToolkit\Modules\Views}
 * module, and the template gets what that call passes and nothing else -- it
 * cannot reach the page for anything the call left out. So the call is the list
 * of the template's inputs, readable without opening the template.
 *
 * ```
 * <?php // resources/views/admin-pages/settings.php
 * ?>
 * <div class="wrap">
 *     <h1><?php echo esc_html( $title ); ?></h1>
 *     <form method="post" action="<?php echo esc_url( $action ); ?>">
 *         <?php wp_nonce_field( $nonce ); ?>
 *         <?php $this->render( 'admin-pages/-fields', array( 'values' => $values ) ); ?>
 *         <?php submit_button(); ?>
 *     </form>
 * </div>
 * ```
 *
 * `$this` inside a template is the `views` module -- rendering a subview is the
 * same call every other caller makes, and costs no variable name.
 */
class AdminPages extends Module implements Bootable {

	use WithFolderWalker;

	/**
	 * Default plugin-relative directory of page files.
	 */
	const PAGES_ROOT = 'resources/admin-pages';

	/**
	 * Discovered pages, indexed by full plugin page slug.
	 *
	 * @var array<string, AdminPage>
	 */
	private array $pages = array();

	/**
	 * Pages successfully added to the menu, indexed by full plugin page slug.
	 *
	 * @var array<string, AdminPage>
	 */
	private array $registered = array();

	/**
	 * Folder-derived parent slug per page, indexed by full plugin page slug.
	 *
	 * A value is the full plugin slug of the containing folder's page, or null for
	 * a top-level page. Used only when a page does not declare its own parent().
	 *
	 * @var array<string, string|null>
	 */
	private array $folder_parent = array();

	/**
	 * Build the full, plugin-prefixed slug for a page.
	 *
	 * Both halves are read exactly as written, so the name you see in the URL is
	 * the name you gave the file. An empty local slug (the root index page)
	 * yields the bare plugin slug, with nothing to join it to.
	 *
	 * A slug a URL could not carry is refused when the page is discovered, rather
	 * than repaired here -- see {@see DiscoveryException::unsafe_page_slug()}.
	 *
	 * @param string $name The page's own name.
	 * @return string The `{plugin-slug}` or `{plugin-slug}-{page-slug}` identifier.
	 */
	public function get_page_slug( string $name ): string {
		return '' === $name
			? $this->get_plugin()->get_slug()
			: $this->get_plugin()->get_namespaced_name( $name );
	}

	/**
	 * Build the admin URL for a page identified by its short slug.
	 *
	 * @param string              $name The page's own name.
	 * @param array<string,mixed> $args Optional query arguments.
	 * @return string The page URL.
	 */
	public function get_page_url( string $name, array $args = array() ): string {
		return $this->get_page_url_for( $this->get_page_slug( $name ), $args );
	}

	/**
	 * Build the admin URL for a page identified by its full, plugin-prefixed slug.
	 *
	 * @param string              $full_slug The full `{plugin}-{page}` slug.
	 * @param array<string,mixed> $args      Optional query arguments.
	 * @return string The page URL.
	 */
	public function get_page_url_for( string $full_slug, array $args = array() ): string {
		// This mirrors WordPress's own menu_page_url(), which cannot be used
		// directly: it always builds a site-admin URL, so a network page would be
		// linked to an address where it is not registered.
		$page = $this->pages[ $full_slug ] ?? null;
		$menu = null !== $page ? $page->menu() : AdminMenu::get_current();

		$parents = $GLOBALS['_parent_pages'] ?? array();
		$parent  = $parents[ $full_slug ] ?? null;

		// A page is reached through its parent's admin file, unless that parent is
		// itself one of ours -- those are all served by admin.php. An unregistered
		// slug takes the same fallback, and so does the empty parent a hidden page
		// records: `/wp-admin/?page=x` reaches index.php, which dispatches nothing.
		$file = \is_string( $parent ) && '' !== $parent && ! isset( $parents[ $parent ] ) ? $parent : 'admin.php';

		return \add_query_arg( $args, $menu->get_url( \add_query_arg( 'page', $full_slug, $file ) ) );
	}

	/**
	 * All discovered pages, indexed by full plugin page slug.
	 *
	 * @return array<string, AdminPage>
	 */
	public function get_pages(): array {
		return $this->pages;
	}

	/**
	 * The full plugin slug of a given page.
	 *
	 * Resolved from the discovery registry, so a page never needs to store its own
	 * slug. Because discovery runs on every admin request (admin_menu), the page is
	 * in the registry during render() too. A page that is not discovered (for
	 * example one constructed directly in a test) falls back to its file's own name.
	 *
	 * @param AdminPage $page The page to identify.
	 * @return string The `{plugin-slug}` or `{plugin-slug}-{page-slug}` identifier.
	 */
	public function get_slug_of( AdminPage $page ): string {
		$full = \array_search( $page, $this->pages, true );

		if ( false !== $full ) {
			return (string) $full;
		}

		// Not discovered: derive from the file's own name (no folder context).
		$file = ( new \ReflectionClass( $page ) )->getFileName();

		return $this->get_page_slug( \pathinfo( (string) $file, PATHINFO_FILENAME ) );
	}

	/**
	 * Discover page files, wire them, and add them to the admin menu.
	 *
	 * @return void
	 * @throws DiscoveryException When the pages directory is missing or a file is invalid.
	 *
	 * @internal
	 */
	public function register_pages(): void {
		// Reset before walking, matching PostTypes: this is a discovery pass, not
		// an accumulation. register_pages() is hooked by name and is callable
		// directly, so without this a second invocation would re-run
		// add_menu_page()/add_submenu_page() for every already-discovered page.
		// Building a module only once guards on_boot(), not a hook callback.
		$this->pages         = array();
		$this->registered    = array();
		$this->folder_parent = array();

		$root_dir = $this->with( Path::class )->get_plugin_path( self::PAGES_ROOT );

		if ( ! \is_dir( $root_dir ) ) {
			// Never named, and the default is absent: this plugin has none of
			// these yet. Only a directory asked for by name is missing in the
			// sense worth throwing over.
			return;
		}

		$files = $this->walk_folder( $root_dir, array( 'php' ), 0 );
		$seen  = array();

		foreach ( $files as $file ) {
			$page_file = $root_dir . '/' . $file;
			$instance  = require $page_file;

			if ( ! $instance instanceof AdminPage ) {
				throw new DiscoveryException(
					\sprintf(
						'The file "%s" must return an instance of %s. Got: %s',
						$page_file,
						AdminPage::class,
						\is_object( $instance ) ? $instance::class : \gettype( $instance )
					)
				);
			}

			$this->get_plugin()->wire( $instance );

			// Discovered but switched off: wired first, so is_enabled() can read an
			// module reached with `with()`, then nothing about it is registered.
			if ( ! $instance->is_enabled() ) {
				continue;
			}

			// Derive the slug from the file's path within the pages directory (see
			// get_slug_from_path()), then plugin-prefix it. Both halves are read
			// exactly as written -- nothing here rewrites a name.
			$path = $file;
			$full = $this->get_page_slug( $this->get_slug_from_path( $path ) );

			// Which is why this is checked instead: the slug goes into `?page=`
			// and onto a hook name, so a character a URL has to encode would take
			// the page down quietly. Tested with rawurlencode() rather than
			// sanitize_key() because a capital letter and a dot both survive a
			// query argument intact, and refusing them would be stricter than the
			// destination is.
			if ( \rawurlencode( $full ) !== $full ) {
				throw DiscoveryException::unsafe_page_slug( $file, $full );
			}

			// Checked on the slug rather than the path, because two different
			// paths can reach one: `dashboard.php` and `dashboard/index.php` both
			// mean the dashboard page, and only one of them can be it.
			if ( isset( $seen[ $full ] ) ) {
				throw DiscoveryException::name_collision( 'admin pages', $full, $seen[ $full ], $file );
			}

			$seen[ $full ]                = $file;
			$this->pages[ $full ]         = $instance;
			$this->folder_parent[ $full ] = $this->get_folder_parent_from_path( $path );
		}

		// Every page is discovered, so render() and get_slug_of() can find any of
		// them, but only the ones belonging to the menu being built are added to
		// it.
		$current = AdminMenu::get_current();

		$this->add_to_menu(
			\array_filter(
				$this->pages,
				static function ( AdminPage $page ) use ( $current ): bool {
					return $current === $page->menu();
				}
			)
		);
	}

	/**
	 * Verify a page's POST and hand it to its own `handle_submit()`.
	 *
	 * Bound to `load-{$hook}` by {@see handle_submit_before_output()}, so this runs
	 * before WordPress has emitted a byte -- which is what lets a page redirect
	 * after saving, the thing `handle_submit()` exists to do.
	 *
	 * The capability is checked here as well as in {@see render()}, because this is
	 * the earlier of the two entry points and a POST reaching it has not passed the
	 * other yet.
	 *
	 * @return void
	 *
	 * @internal
	 */
	public function handle_submit(): void {
		if ( array() === $_POST ) {
			return;
		}

		$page = $this->get_current_page();

		if ( null === $page ) {
			return;
		}

		if ( ! \current_user_can( $page->capability() ) ) {
			\wp_die( \esc_html__( 'You are not allowed to access this page.', 'zestry-toolkit' ), '', array( 'response' => 403 ) );
		}

		$nonce = isset( $_REQUEST['_wpnonce'] ) ? \sanitize_text_field( \wp_unslash( $_REQUEST['_wpnonce'] ) ) : '';

		if ( ! \wp_verify_nonce( $nonce, $page->get_nonce_action() ) ) {
			\wp_nonce_ays( $page->get_nonce_action() );
		}

		$page->handle_submit();
	}

	/**
	 * Dispatch the current admin page: enforce capability, then render.
	 *
	 * A POST was already handled on `load-{$hook}`, before any output --
	 * {@see handle_submit()}.
	 *
	 * @return void
	 *
	 * @internal
	 */
	public function render(): void {
		$page = $this->get_current_page();

		if ( null === $page ) {
			\wp_die( \esc_html__( 'Invalid page.', 'zestry-toolkit' ), '', array( 'response' => 404 ) );
		}

		if ( ! \current_user_can( $page->capability() ) ) {
			\wp_die( \esc_html__( 'You are not allowed to access this page.', 'zestry-toolkit' ), '', array( 'response' => 403 ) );
		}

		\ob_start();

		$page->render();

		$content            = \ob_get_clean();
		$page_content_class = \implode(
			' ',
			array(
				$this->get_page_css_classname( $page ) . '-content',
				$this->get_base_css_classname() . '-content',
			)
		);
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		\printf(
			'<div class="%s">%s</div>',
			\esc_attr( $page_content_class ),
			$content
		);
	}

	/**
	 * The BEM block class shared by every page of this plugin.
	 *
	 * @return string The `{plugin-slug}-admin-page` class name.
	 */
	final public function get_base_css_classname(): string {
		return \sanitize_key( $this->get_plugin()->get_slug() ) . '-admin-page';
	}

	/**
	 * A BEM modifier class scoping a rule to one page.
	 *
	 * An AdminPage instance is identified by its full slug; a plain string is used
	 * as given (sanitized only, not plugin-prefixed) so a caller may pass an
	 * arbitrary identifier that is not one of this plugin's registered pages.
	 *
	 * @param AdminPage|string $slug A page instance, or a slug to use verbatim.
	 * @return string The `{base}--{slug}` class name.
	 */
	final public function get_page_css_classname( AdminPage|string $slug ): string {
		if ( $slug instanceof AdminPage ) {
			$slug = $this->get_slug_of( $slug );
		} else {
			$slug = \sanitize_key( $slug );
		}

		$base = $this->get_base_css_classname();
		return $base . '--' . \sanitize_key( $slug );
	}

	/**
	 * Register the WordPress admin_menu hook and page-scoped admin behaviours.
	 *
	 * Discovery and registration are deferred to `admin_menu`, and only on admin
	 * requests, because that is when WordPress builds the menu.
	 *
	 * @return void
	 *
	 * @internal
	 */
	public function on_boot(): void {
		if ( ! \is_admin() ) {
			return;
		}

		// One hook per menu, and only one of them fires on any given request:
		// WordPress builds the network menu from network_admin_menu instead of
		// admin_menu, not as well as.
		foreach ( AdminMenu::cases() as $menu ) {
			\add_action( $menu->get_menu_hook(), array( $this, 'register_pages' ) );
		}

		\add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_page_assets' ) );
		\add_filter( 'admin_body_class', array( $this, 'filter_admin_body_class' ) );
	}

	/**
	 * Enqueue the assets of whichever page is being displayed.
	 *
	 * @return void
	 *
	 * @internal
	 */
	public function enqueue_page_assets(): void {
		$page = $this->get_current_page();

		if ( null === $page ) {
			return;
		}

		// Before the page's own assets, and separately from them: a page whose
		// styles must beat first paint says so by implementing the contract,
		// rather than by remembering a `parent::` call inside an
		// enqueue_assets() it was going to override anyway.
		if ( $page instanceof RendersCriticalStyles ) {
			$page->enqueue_critical_styles();
		}

		$page->enqueue_assets();
	}

	/**
	 * Add this plugin's own classes to the admin body on one of its pages.
	 *
	 * @param string $classes The classes WordPress has so far.
	 * @return string
	 *
	 * @internal
	 */
	public function filter_admin_body_class( string $classes ): string {
		$page = $this->get_current_page();

		if ( null === $page ) {
			return $classes;
		}

		return \implode(
			' ',
			array(
				$classes,
				$this->get_base_css_classname(),
				$this->get_page_css_classname( $page ),
			)
		);
	}

	/**
	 * Derive a page slug from its path within the pages directory.
	 *
	 * `index.php` -> `` (the root landing page is the bare plugin slug);
	 * `dashboard.php` -> `dashboard`; `dashboard/index.php` -> `dashboard` (the
	 * folder's landing page takes the folder's name); `dashboard/settings.php` ->
	 * `dashboard-settings`; deeper paths join their segments with `-`.
	 *
	 * @param string $relative_path The page file path relative to the pages root.
	 * @return string The local slug (empty for the root index page).
	 */
	private function get_slug_from_path( string $relative_path ): string {
		$segments = \explode( '/', (string) \preg_replace( '/\.php$/', '', $relative_path ) );

		// An index.php represents its folder, so drop the "index" leaf; at the root
		// this leaves an empty slug, so the landing page is the bare plugin slug.
		if ( 'index' === \end( $segments ) ) {
			\array_pop( $segments );
		}

		return \implode( '-', $segments );
	}

	/**
	 * Derive a page's folder-based parent from its path.
	 *
	 * A file in the root, or a folder's own index.php, has no folder parent (null).
	 * Any other nested file is parented under its top-level folder's page, so
	 * `dashboard/settings.php` and `dashboard/adv/tuning.php` both nest under
	 * `dashboard` — WordPress admin menus are only two levels deep.
	 *
	 * @param string $relative_path The page file path relative to the pages root.
	 * @return string|null The full plugin slug of the parent page, or null.
	 */
	private function get_folder_parent_from_path( string $relative_path ): ?string {
		$segments = \explode( '/', $relative_path );

		// Root file (no folder), or the folder's own index.php: top-level.
		if ( \count( $segments ) < 2 || 'index.php' === \end( $segments ) ) {
			return null;
		}

		return $this->get_page_slug( $segments[0] );
	}

	/**
	 * Add pages to the WordPress admin menu, deferring those whose parent has not
	 * been registered yet.
	 *
	 * @param array<string, AdminPage> $pages Pages to add.
	 * @return void
	 */
	private function add_to_menu( array $pages ): void {
		$deferred = array();

		foreach ( $pages as $slug => $page ) {
			if ( $page->is_hidden() ) {
				// No menu to be placed in, so nothing to resolve or wait for.
				$this->add_single( $slug, $page, null );
				$this->registered[ $slug ] = $page;
				continue;
			}

			// An explicit parent() wins; otherwise the folder structure decides.
			$placement = $page->parent() ?? $this->folder_parent[ $slug ] ?? null;

			if ( \is_string( $placement ) && isset( $this->pages[ $placement ] ) ) {
				// A hidden parent is on no menu, so WordPress would register this
				// page as a submenu of nothing -- reachable by URL, listed nowhere,
				// which is the same failure the cross-menu case below describes.
				if ( $this->pages[ $placement ]->is_hidden() ) {
					throw new DiscoveryException(
						\sprintf(
							'The page "%s" is nested under "%s", which is hidden and therefore on no menu for it to appear in.',
							$slug,
							$placement
						)
					);
				}

				// A parent in the other admin menu can never appear above this
				// page, and WordPress would register it under a menu file that is
				// not there -- reachable by URL, listed nowhere.
				if ( $this->pages[ $placement ]->menu() !== $page->menu() ) {
					throw new DiscoveryException(
						\sprintf(
							'The page "%s" is nested under "%s", which is in the %s admin menu instead of the %s one.',
							$slug,
							$placement,
							$this->pages[ $placement ]->menu()->value,
							$page->menu()->value
						)
					);
				}

				// The parent is one of this plugin's pages that is not on the menu
				// yet: defer this page until a later pass has registered it.
				if ( ! isset( $this->registered[ $placement ] ) ) {
					$deferred[ $slug ] = $page;
					continue;
				}
			}

			$this->add_single( $slug, $page, $placement );
			$this->registered[ $slug ] = $page;
		}

		// Retry deferred pages only if this pass registered at least one new page,
		// otherwise an unresolvable parent would loop forever.
		if ( array() !== $deferred && \count( $deferred ) < \count( $pages ) ) {
			$this->add_to_menu( $deferred );
		}
	}

	/**
	 * Register a single page with the correct WordPress menu function.
	 *
	 * @param string                 $slug   The full plugin page slug.
	 * @param AdminPage              $page   The page instance.
	 * @param ParentMenu|string|null $placement The page's declared parent.
	 * @return void
	 */
	private function add_single( string $slug, AdminPage $page, ParentMenu|string|null $placement ): void {
		$render = array( $this, 'render' );

		if ( $page->is_hidden() ) {
			/*
			 * An empty parent is WordPress's own way to register a page that no menu
			 * lists: the hook lands in $_registered_pages, which is what admin.php
			 * checks before serving `?page=`, while the menu entry goes into
			 * $submenu[''] -- a bucket no top-level menu claims, so nothing renders
			 * it. Whatever placement was worked out above is discarded; there is no
			 * menu for it to describe.
			 *
			 * Written as '' rather than the null this idiom is usually spelled with,
			 * because plugin_basename() turns one into the other -- via str_replace()
			 * calls that a null argument deprecates on PHP 8.1.
			 */
			$this->handle_submit_before_output(
				\add_submenu_page(
					'',
					$page->title(),
					$page->menu_title(),
					$page->capability(),
					$slug,
					$render
				)
			);

			return;
		}

		if ( null === $placement ) {
			$this->handle_submit_before_output(
				\add_menu_page(
					$page->title(),
					$page->menu_title(),
					$page->capability(),
					$slug,
					$render,
					$page->icon(),
					$page->position()
				)
			);
			return;
		}

		// A ParentMenu names a core section, whose admin file differs between the
		// two menus; a string is a custom parent slug, either one of this plugin's
		// page slugs or an existing WordPress menu slug used verbatim.
		$this->handle_submit_before_output(
			\add_submenu_page(
				$placement instanceof ParentMenu ? $placement->get_parent_file( $page->menu() ) : $placement,
				$page->title(),
				$page->menu_title(),
				$page->capability(),
				$slug,
				$render,
				$page->position()
			)
		);
	}

	/**
	 * Run a page's submit pass on `load-{$hook}`, before anything is output.
	 *
	 * WordPress calls a page's own callback from `wp-admin/admin.php` *after* it
	 * has required `admin-header.php`, so by the time {@see render()} runs the
	 * response body has already begun. A `handle_submit()` that redirects -- which
	 * is what it is for, and what the generated page does -- would reach
	 * `wp_safe_redirect()` with headers already sent: two PHP warnings, no
	 * `Location`, and `exit` truncating the page it had just saved.
	 *
	 * `load-{$hook}` is the hook immediately before that require (`admin.php`
	 * fires it, then includes the header, then calls the page), so binding here is
	 * what makes a redirect possible at all. A page needs no knowledge of this:
	 * the hook suffix comes back from the `add_*_page()` call that registered it.
	 *
	 * @param string|false $hook_suffix Whatever `add_menu_page()`/`add_submenu_page()` returned.
	 * @return void
	 */
	private function handle_submit_before_output( string|false $hook_suffix ): void {
		// False when the current user lacks the capability, in which case
		// WordPress registered no page and there is nothing to submit to.
		if ( ! \is_string( $hook_suffix ) || '' === $hook_suffix ) {
			return;
		}

		\add_action( 'load-' . $hook_suffix, array( $this, 'handle_submit' ) );
	}

	/**
	 * The registered page for the current `?page=` request, or null.
	 *
	 * @return AdminPage|null
	 */
	private function get_current_page(): ?AdminPage {
		// Reading the page id to route the request is not form processing; POST is
		// nonce-verified in handle_submit() before any handler runs.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$slug = isset( $_GET['page'] ) ? \sanitize_text_field( \wp_unslash( $_GET['page'] ) ) : '';

		return $this->registered[ $slug ] ?? null;
	}
}
