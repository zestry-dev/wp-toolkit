<?php

/**
 * Admin Pages API: RendersCriticalStyles contract
 */

declare( strict_types=1 );

namespace Zestry\WPToolkit\Modules\AdminPages\Contracts;

// Loaded by WordPress, never requested directly.
\defined( 'ABSPATH' ) || exit;

/**
 * A page with styles that have to be inlined before first paint.
 *
 * {@see \Zestry\WPToolkit\Modules\AdminPages\AdminPages} calls
 * {@see enqueue_critical_styles()} on any page declaring this, just before it
 * calls that page's own `enqueue_assets()`. The two are separate on purpose:
 * `enqueue_assets()` is yours to override with no `parent::` call to remember,
 * and forgetting one cannot cost the page its layout.
 *
 * {@see \Zestry\WPToolkit\Modules\AdminPages\ModernAdminPage} is the
 * implementation this module ships. Implement it yourself for a page that needs
 * its own critical CSS on the same terms — inline, in `<head>`, before anything
 * the browser has to fetch.
 */
interface RendersCriticalStyles {

	/**
	 * Print this page's critical styles inline, before first paint.
	 *
	 * Called on `admin_enqueue_scripts`, and only while this page is the one
	 * being displayed.
	 *
	 * Attach them with `wp_add_inline_style()` to `common`: WordPress registers
	 * that handle on every admin request and prints it in `<head>`, which is
	 * what gets the styles in ahead of first paint. A stylesheet of your own,
	 * enqueued from `enqueue_assets()`, arrives too late to stop a layout jump.
	 *
	 * Scope every rule to the page's body class, which
	 * `AdminPage::get_base_css_classname()` returns and {@see
	 * \Zestry\WPToolkit\Modules\AdminPages\AdminPages} adds only while one of
	 * this plugin's pages is displayed. Unscoped rules reach the whole of
	 * wp-admin.
	 *
	 * ```
	 * public function enqueue_critical_styles(): void {
	 *     $page = $this->get_base_css_classname();
	 *
	 *     wp_add_inline_style(
	 *         'common',
	 *         sprintf(
	 *             '.%1$s #wpcontent { padding-inline-start: 0; }
	 *              .%1$s #wpfooter { display: none; }',
	 *             esc_attr( $page )
	 *         )
	 *     );
	 * }
	 * ```
	 *
	 * @return void
	 */
	public function enqueue_critical_styles(): void;
}
