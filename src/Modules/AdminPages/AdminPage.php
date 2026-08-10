<?php

/**
 * Admin Pages API: AdminPage base class
 */

declare( strict_types=1 );

namespace Zestry\WPToolkit\Modules\AdminPages;

// Loaded by WordPress, never requested directly.
\defined( 'ABSPATH' ) || exit;

use Zestry\WPToolkit\Kernel\Contracts\PluginAware;
use Zestry\WPToolkit\Services\Cookie;
use Zestry\WPToolkit\Services\Views;
use Zestry\WPToolkit\Kernel\Traits\WithPlugin;
use Zestry\WPToolkit\Kernel\Traits\WithEnablement;

/**
 * Base class for a file-based WordPress admin page.
 *
 * A page file returns an AdminPage subclass instance; the AdminPages module wires
 * it (assigning the plugin and injecting typed module dependencies), registers it
 * in the admin menu using the typed accessors below, and dispatches to render()
 * when the page is viewed. The page's slug is derived from its path within the
 * pages directory, so `admin-pages/settings.php` becomes `{plugin-slug}-settings`
 * and `admin-pages/reports/index.php` becomes `{plugin-slug}-reports`. The root
 * `admin-pages/index.php`, if present, becomes the bare `{plugin-slug}` itself.
 *
 * Authorization is enforced by the module before render(): the current user must
 * satisfy capability(), and a nonce is verified on POST. A page therefore only
 * has to describe itself (title, capability, placement) and render its markup.
 *
 * A file at `admin-pages/settings.php` registers as a top-level menu page with
 * the slug `{plugin}-settings` (see {@see get_page_slug()}). Return a ParentMenu
 * case from `parent()` to nest it under a core WordPress menu instead, such as
 * `ParentMenu::Settings`. A property typed as a Service or Module subclass --
 * `public Path $path;`, say -- is injected automatically when the page is
 * wired.
 * `wp zestry make page <name>` generates a starting point.
 *
 * A page rendering its own full-width application shell rather than the usual
 * WordPress "wrap" layout should extend {@see ModernAdminPage} instead, which
 * is this class plus a critical-CSS reset of wp-admin's default chrome. It
 * satisfies the same discovery guard, so it is a drop-in swap for the
 * `extends AdminPage` a generated file starts with.
 *
 * @stub page.php.stub
 */
abstract class AdminPage implements PluginAware {

	use WithPlugin;
	use WithEnablement;

	/**
	 * Prevent direct construction from bypassing plugin initialization.
	 *
	 * @return void
	 */
	final public function __construct() {}

	/**
	 * The page title shown in the browser and at the top of the page.
	 *
	 * @return string
	 */
	abstract public function title(): string;

	/**
	 * The capability a user must have to see and open the page.
	 *
	 * @return string
	 */
	abstract public function capability(): string;

	/**
	 * Render the page markup. Output is echoed inside the admin wrapper.
	 *
	 * @return void
	 */
	abstract public function render(): void;

	/**
	 * The menu label. Defaults to the page title.
	 *
	 * @return string
	 */
	public function menu_title(): string {
		return $this->title();
	}

	/**
	 * Where the page is placed in the admin menu.
	 *
	 * Return a ParentMenu case to nest under a core WordPress menu; a fully-qualified
	 * page slug — build a sibling's with `$this->get_page_slug( 'dashboard' )` rather
	 * than writing the prefix — or an existing WordPress menu slug such as `edit.php`,
	 * to nest under that; or null to use the folder-based placement (a nested file
	 * nests under its top-level folder's page -- WordPress admin menus are only two
	 * levels deep, so `dashboard/adv/tuning.php` still lands under `dashboard`) or a
	 * top-level menu at the root.
	 *
	 * An explicit non-null return here always overrides the folder-based placement.
	 *
	 * @return ParentMenu|string|null
	 */
	public function parent(): ParentMenu|string|null {
		return null;
	}

	/**
	 * Whether this page is reachable by URL but absent from every menu.
	 *
	 * For a screen nobody browses to: a confirmation step, a per-item editor
	 * reached from a row action, the far side of a redirect. Return true and the
	 * page registers exactly as any other -- {@see get_page_url()} still builds
	 * its address, {@see capability()} is still enforced, `handle_submit()` still
	 * runs -- but nothing lists it.
	 *
	 *     public function is_hidden(): bool {
	 *         return true;
	 *     }
	 *
	 * {@see parent()} and {@see position()} stop meaning anything, since there is
	 * no menu to sit in, and no other page may nest under this one.
	 *
	 * @rationale
	 * WordPress's mechanism is `add_submenu_page( null, ... )`, which registers the
	 * hook in `$_registered_pages` -- the thing `admin.php` checks before serving
	 * `?page=` -- while adding no menu item anywhere. Verified against WP 7.1 on
	 * PHP 8.1: no deprecation, and no entry in `$menu` or `$submenu`.
	 *
	 * Not a `ParentMenu` case, and not a sentinel from `parent()`: that enum
	 * answers "which core menu is above me" with a file, and a hidden page has no
	 * file to name. `parent()` has also already spent `null` on "decide by folder".
	 *
	 * The alternative a plugin reaches for otherwise is registering a real menu
	 * entry and hiding it with CSS, which leaves the item in the DOM and in
	 * `$submenu` for anything else reading the menu.
	 *
	 * @return bool
	 */
	public function is_hidden(): bool {
		return false;
	}

	/**
	 * Which admin menu the page appears in.
	 *
	 * The default is the ordinary per-site admin. Return `AdminMenu::Network` for
	 * a page that belongs to the network administrator on a multisite install —
	 * settings that apply to every site, and are not a single site's to change:
	 *
	 *     public function menu(): AdminMenu {
	 *         return AdminMenu::Network;
	 *     }
	 *
	 *     public function capability(): string {
	 *         return 'manage_network_options';
	 *     }
	 *
	 * Pick the capability to match — `manage_options` is a site administrator's,
	 * and every site has one. A network page is inert on a single-site install.
	 *
	 * The two menus hold different sections, so a network page's {@see parent()}
	 * is limited to those the network menu has.
	 *
	 * @return AdminMenu
	 */
	public function menu(): AdminMenu {
		return AdminMenu::Site;
	}

	/**
	 * The menu position, or null for the default ordering.
	 *
	 * This is the mechanism for controlling where this page sits in the admin
	 * menu: the value is passed straight through as WordPress's own `$position`
	 * argument to `add_menu_page()`/`add_submenu_page()`. The module registers
	 * on `admin_menu` at WordPress's default priority and exposes no
	 * hook-priority knob, because shifting *when* registration runs is a far
	 * blunter and less predictable way to reach the same goal than declaring
	 * the position outright, per page, here.
	 *
	 * @return int|null
	 */
	public function position(): ?int {
		return null;
	}

	/**
	 * The top-level menu icon (a dashicon class or image URL). Ignored for submenus.
	 *
	 * @return string
	 */
	public function icon(): string {
		return 'dashicons-admin-generic';
	}

	/**
	 * Enqueue CSS/JS for this page. Called only when the page is being displayed.
	 *
	 * @return void
	 */
	public function enqueue_assets(): void {}

	/**
	 * Handle a validated POST submission (called after the nonce check passes).
	 *
	 * Runs on `load-{$hook}`, before WordPress has emitted anything, so a redirect
	 * from here works -- which is what it is for. Falling through to `render()`
	 * instead leaves the browser's current request a POST, so a refresh resubmits.
	 *
	 * @return void
	 */
	public function handle_submit(): void {}

	/**
	 * Render one of this plugin's templates as this page's markup.
	 *
	 * The markup belongs in `views/`, not in a PHP string. An admin page is
	 * mostly a form -- tables, fields, notices, a second form further down --
	 * and markup assembled by concatenation stops being reviewable long before
	 * it stops growing. `wp zestry make page` writes the template alongside the
	 * class, so there is one to render from the start.
	 *
	 * ```
	 * public function render(): void {
	 *     $this->view(
	 *         'admin-pages/settings',
	 *         array(
	 *             'title'  => $this->title(),
	 *             'action' => $this->get_page_url(),
	 *             'nonce'  => $this->get_nonce_action(),
	 *             'items'  => $this->items,
	 *         )
	 *     );
	 * }
	 * ```
	 *
	 * The template gets exactly what is passed here, and nothing else of this
	 * page -- what a form needs is three strings, so three strings is what it is
	 * given. Naming them at the call site is also what makes a template's inputs
	 * readable without opening it.
	 *
	 * {@see \Zestry\WPToolkit\Services\Views} puts one thing of its own in scope,
	 * `$partial`, for rendering a subview.
	 *
	 * @param string               $view A view name, relative to the views root.
	 * @param array<string, mixed> $data Variables for the template.
	 * @return void
	 * @throws \InvalidArgumentException When the views root or the view is missing.
	 */
	public function view( string $view, array $data = array() ): void {
		$this->views()->render( $view, $data );
	}

	/**
	 * Keep something for the page you are about to redirect to.
	 *
	 * `handle_submit()` redirects, because the browser's current request is still
	 * the POST and a refresh would resubmit it -- and the redirect throws away
	 * everything the handler knew. This is what survives it, without going in the
	 * URL where a bookmark would replay it:
	 *
	 *     public function handle_submit(): void {
	 *         $this->options->set( 'threshold', $this->threshold );
	 *         $this->set_flash( __( 'Settings saved.', 'acme-plugin' ) );
	 *
	 *         wp_safe_redirect( $this->get_page_url() );
	 *         exit;
	 *     }
	 *
	 *     public function render(): void {
	 *         $this->view( 'admin-pages/settings', array( 'notice' => $this->get_flash( '' ) ) );
	 *     }
	 *
	 * Anything serializable, so an array carries a notice and a count together.
	 * Encrypted on the way out by {@see \Zestry\WPToolkit\Services\Cookie}, so nothing of it is
	 * readable in the browser.
	 *
	 * @param mixed $value What the next request should be told.
	 * @return bool Whether it was stored; false once output has begun.
	 */
	final public function set_flash( mixed $value ): bool {
		return $this->cookies()->set_flash( $value );
	}

	/**
	 * Take what the request before this one left, which reads only once.
	 *
	 * The second call gives the fallback, so a refresh shows no notice for a save
	 * that already happened -- the thing `?updated=1` in the URL gets wrong.
	 *
	 * @param mixed $fallback Returned when nothing was flashed.
	 * @return mixed
	 */
	final public function get_flash( mixed $fallback = null ): mixed {
		return $this->cookies()->get_flash( $fallback );
	}

	/**
	 * The nonce action string scoping this page's forms.
	 *
	 * @return string
	 */
	final public function get_nonce_action(): string {
		return $this->get_page_slug() . '-action';
	}

	/**
	 * Output a hidden nonce field for this page's forms.
	 *
	 * @return void
	 */
	final public function nonce_field(): void {
		\wp_nonce_field( $this->get_nonce_action() );
	}

	/**
	 * Full, plugin-prefixed slug for a page identified by its short name.
	 *
	 * Delegates to the AdminPages module so a page never writes the plugin prefix.
	 * Pass no argument for this page's own (already-prefixed) slug, which the
	 * module resolves from its registry by deriving it from the page's path
	 * within the pages directory -- `dashboard/settings.php` becomes
	 * `{plugin}-dashboard-settings` and `dashboard/index.php` becomes
	 * `{plugin}-dashboard`. The page itself stores no slug state.
	 *
	 * This is the single spelling for a page's own identity, matching
	 * `PostType::get_post_type()` and `Taxonomy::get_taxonomy()` for the same
	 * concept.
	 *
	 * @param string|null $page A page's own short slug (e.g. `settings`), or null for this page.
	 * @return string The `{plugin-slug}` or `{plugin-slug}-{page-slug}` identifier.
	 */
	final public function get_page_slug( ?string $page = null ): string {
		return null === $page
			? $this->admin_pages()->get_slug_of( $this )
			: $this->admin_pages()->get_page_slug( $page );
	}

	/**
	 * Admin URL for a page identified by its short name.
	 *
	 * Delegates to the AdminPages module. Pass no page for this page's own URL.
	 *
	 * @param string|null         $page A page's own short slug, or null for this page.
	 * @param array<string,mixed> $args Optional query arguments.
	 * @return string The page URL.
	 */
	final public function get_page_url( ?string $page = null, array $args = array() ): string {
		return $this->admin_pages()->get_page_url_for( $this->get_page_slug( $page ), $args );
	}

	/**
	 * The AdminPages module that manages this page.
	 *
	 * @return AdminPages
	 */
	final protected function admin_pages(): AdminPages {
		return $this->get_plugin()->get( AdminPages::class );
	}

	/**
	 * The Views service this page renders through.
	 *
	 * Resolved rather than injected, matching {@see admin_pages()}: a public
	 * property would put it on every page's own surface, which is not where it
	 * belongs when {@see view()} is the thing to call.
	 *
	 * @return Views
	 */
	final protected function views(): Views {
		return $this->get_plugin()->get( Views::class );
	}

	/**
	 * The Cookie service this page's flash values travel in.
	 *
	 * @return Cookie
	 */
	final protected function cookies(): Cookie {
		return $this->get_plugin()->get( Cookie::class );
	}

	/**
	 * A BEM modifier class scoping a rule to one page, delegated to the module.
	 *
	 * @param string|null $page A page's own short slug, or null for this page.
	 * @return string The `{base}--{slug}` class name.
	 */
	final protected function get_page_css_classname( ?string $page = null ): string {
		return $this->admin_pages()->get_page_css_classname( $page ? $page : $this );
	}

	/**
	 * The BEM block class shared by every page of this plugin.
	 *
	 * @return string The `{plugin-slug}-admin-page` class name.
	 */
	final protected function get_base_css_classname(): string {
		return $this->admin_pages()->get_base_css_classname();
	}
}
