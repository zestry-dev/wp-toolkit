<?php

/**
 * Admin Pages API: ModernAdminPage base class
 */

declare( strict_types=1 );

namespace Zestry\WPToolkit\Modules\AdminPages;

// Loaded by WordPress, never requested directly.
\defined( 'ABSPATH' ) || exit;

/**
 * An AdminPage that gives a custom UI the whole admin canvas.
 *
 * Extend this instead of {@see AdminPage} when a page renders its own
 * full-width application shell -- a JS-driven interface, a custom dashboard --
 * rather than the usual WordPress `.wrap` layout. Everything `AdminPage` offers
 * is unchanged: title, capability, menu placement, nonce-verified POST
 * handling, module injection, and discovery from the same `admin-pages/`
 * directory. The only difference is a critical-CSS reset of wp-admin's own
 * chrome, so adopting it is a one-word edit to an existing page's `extends`
 * clause.
 *
 * The reset is inlined into `<head>` on core's always-registered `common`
 * stylesheet handle rather than requested as a file of its own, so it applies
 * before first paint: the page never renders in the default layout and then
 * jumps once its stylesheet arrives. It is also strictly scoped -- every rule
 * sits behind the `{plugin-slug}-admin-page` body class that
 * {@see AdminPages} adds only while one of this plugin's own pages is being
 * displayed, so no other screen in wp-admin is touched.
 *
 * What it changes:
 *
 * - `#wpcontent` and `#wpbody-content` lose their padding, so the page starts
 *   at the viewport edge and gets the full width, collapsed sidebar included.
 *
 * - The body and `#wpbody-content` are forced white, and `#wpbody-content`
 *   gets a `min-height` of the viewport less the admin bar, so a short page
 *   still fills the screen instead of ending in grey.
 *
 * - `.wrap` and the module's own content wrapper lose their margins, and
 *   everything inside them switches to `border-box` sizing.
 *
 * - `#wpfooter` -- the "Thank you for creating with WordPress" line and the
 *   version number -- is hidden.
 *
 * - `#wpwrap` scrolls on its own below 782px and reverts to the browser's own
 *   scrolling above it, which is what stops a mobile layout trapping content.
 *
 * A page extending this needs no wrapper markup of its own: `AdminPages`
 * already wraps whatever `render()` echoes in a
 * `.{plugin-slug}-admin-page-content` div, and that div is the one element the
 * reset deliberately spares.
 *
 * > [!IMPORTANT]
 * > **Admin notices do not appear on these pages.** Every direct `div` child
 * > of `#wpbody-content` except the content wrapper and `#screen-meta` is
 * > hidden, which is what keeps another plugin's "Your license has expired"
 * > banner from landing in the middle of a custom layout -- but it hides your
 * > own just as effectively. `add_settings_error()` and anything hooked to
 * > `admin_notices` will not be seen, so a page that needs to report success
 * > or failure has to render that itself, inside `render()`.
 *
 * @example Taking the full canvas
 * `wp zt make page <name>` generates a file extending `AdminPage`. Changing
 * that one word is the entire migration -- every other method behaves exactly
 * as it did.
 *
 * ```
 * <?php
 * return new class() extends ModernAdminPage {
 *     public function title(): string {
 *         return __( 'Dashboard', 'my-plugin' );
 *     }
 *     public function capability(): string {
 *         return 'manage_options';
 *     }
 *     public function render(): void {
 *         // No .wrap and no wrapper div: the module supplies the container,
 *         // and a .wrap here would only have its margins reset anyway.
 *         echo '<div id="my-plugin-app"></div>';
 *     }
 * };
 * ```
 *
 * @example Adding the page's own assets
 * `enqueue_assets()` is where the reset is injected, so a subclass overriding
 * it must call the parent. Leave the call out and the page loads with
 * wp-admin's chrome intact -- which looks like this class silently not
 * working, rather than like a missing line.
 *
 * ```
 * public function enqueue_assets(): void {
 *     parent::enqueue_assets();
 *
 *     wp_enqueue_script( 'my-plugin-dashboard' );
 * }
 * ```
 */
abstract class ModernAdminPage extends AdminPage {

	/**
	 * Enqueue this page's critical-CSS reset.
	 *
	 * Overrides AdminPage::enqueue_assets(), so — per that method's contract — it
	 * only runs when this page is the one being displayed, not on every admin
	 * request. A subclass that also needs its own scripts/styles should override
	 * this method and call `parent::enqueue_assets()` to keep the reset.
	 *
	 * @return void
	 */
	public function enqueue_assets(): void {
		$this->enqueue_critical_styles();
	}

	/**
	 * Build the critical-style block once per request and inline it on 'common'.
	 *
	 * The style markup is identical for every ModernAdminPage instance (it only
	 * depends on get_base_css_classname(), which is derived from the plugin
	 * slug), so it is built once and cached in a function-local static rather than
	 * being rebuilt per instance or per render. It is attached via
	 * wp_add_inline_style() to the always-registered 'common' handle so it is
	 * printed inline in `<head>` — before first paint — instead of costing a
	 * separate blocking stylesheet request; this is what actually prevents the
	 * layout shift described in the class docblock.
	 *
	 * @return void
	 */
	private function enqueue_critical_styles(): void {
		static $critical_styles;
		if ( $critical_styles === null ) {
			$classname = $this->get_base_css_classname();
			\ob_start();
			?>
			<style>
				/* Critical styles to prevent layout shifts - inlined for immediate application */
				
				body.<?php echo \esc_attr( $classname ); ?> {
					background: #fff;
				}
				
				.<?php echo \esc_attr( $classname ); ?> #wpwrap {
					overflow-y: auto;
				}

				/* Reset wp-admin padding */
				.<?php echo \esc_attr( $classname ); ?> #wpcontent {
					padding-inline-start: 0;
				}
				.<?php echo \esc_attr( $classname ); ?> #wpbody-content {
					padding:0;
					min-height: calc(100vh - var(--wp-admin--admin-bar--height));
					background: #fff;
				}

				.<?php echo \esc_attr( $classname ); ?>.auto-fold #wpcontent{
						padding:0;
				}

				.<?php echo \esc_attr( $classname ); ?>-content,
				.<?php echo \esc_attr( $classname ); ?> .wrap {
					margin: 0;
					box-sizing: border-box;
				}

				.<?php echo \esc_attr( $classname ); ?>-content *,
				.<?php echo \esc_attr( $classname ); ?> .wrap * {
					box-sizing: border-box;
				}

				/* Hide legacy admin elements */
				.<?php echo \esc_attr( $classname ); ?> #wpfooter {
					display: none;
				}

				.<?php echo \esc_attr( $classname ); ?> #wpbody-content > div:not(.<?php echo \esc_attr( $classname ); ?>-content):not(#screen-meta) {
					display: none;
				}

				/* Responsive overflow fix for #wpwrap */
				@media (min-width: 782px) {
					.<?php echo \esc_attr( $classname ); ?> #wpwrap {
						overflow-y: initial;
					}
				}
			</style>
			<?php
			$critical_styles = \ob_get_clean();
			$critical_styles = \preg_replace( '/<style>|<\/style>/', '', $critical_styles );
		}

		\wp_add_inline_style( 'common', $critical_styles );
	}
}
