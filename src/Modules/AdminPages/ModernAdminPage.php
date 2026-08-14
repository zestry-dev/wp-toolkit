<?php

/**
 * Admin Pages API: ModernAdminPage base class
 */

declare( strict_types=1 );

namespace Zestry\WPToolkit\Modules\AdminPages;

// Loaded by WordPress, never requested directly.
\defined( 'ABSPATH' ) || exit;

use Zestry\WPToolkit\Modules\AdminPages\Contracts\RendersCriticalStyles;

/**
 * An AdminPage that gives a custom UI the whole admin canvas.
 *
 * Extend this instead of {@see AdminPage} when a page renders its own
 * full-width application shell -- a JS-driven interface, a custom dashboard --
 * rather than the usual WordPress `.wrap` layout. Everything `AdminPage` offers
 * is unchanged: title, capability, menu placement, nonce-verified POST
 * handling, wiring, and discovery from the same `admin-pages/`
 * directory. Adopting it is a one-word edit to an existing page's `extends`
 * clause.
 *
 * The difference is a CSS reset, inlined before first paint so the page never
 * renders in the default layout and then jumps. It applies only while one of
 * this plugin's own pages is displayed, and no other screen in wp-admin is
 * touched. What it changes:
 *
 * - `#wpcontent` and `#wpbody-content` lose their padding, so the page starts
 *   at the viewport edge and gets the full width, collapsed sidebar included.
 * - The background is white, and a short page still fills the screen rather
 *   than ending in grey.
 * - `.wrap` and the content wrapper lose their margins, and everything inside
 *   them is `border-box`.
 * - `#wpfooter` is hidden.
 * - `#wpwrap` scrolls on its own below 782px, which stops a mobile layout
 *   trapping content.
 *
 * Write no wrapper markup of your own: {@see AdminPages} already wraps whatever
 * `render()` echoes in a `.{plugin-slug}-admin-page-content` div, which is the
 * one element the reset spares.
 *
 * > [!IMPORTANT]
 * > **Admin notices do not appear on these pages.** Everything
 * > `#wpbody-content` holds except your content and `#screen-meta` is hidden,
 * > yours included — so `add_settings_error()` and anything hooked to
 * > `admin_notices` goes unseen. A page that reports success or failure has to
 * > render that itself, in `render()`.
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
 *         return __( 'Dashboard', 'acme-plugin' );
 *     }
 *     public function capability(): string {
 *         return 'manage_options';
 *     }
 *     public function render(): void {
 *         // No .wrap and no wrapper div: the module supplies the container,
 *         // and a .wrap here would only have its margins reset anyway.
 *         echo '<div id="acme-plugin-app"></div>';
 *     }
 * };
 * ```
 *
 * @example Adding the page's own assets
 * Override `enqueue_assets()`, which runs only while this page is being
 * displayed. The reset is not in it, so there is no `parent::` call to make.
 *
 * ```
 * public function enqueue_assets(): void {
 *     wp_enqueue_script( 'acme-plugin-dashboard' );
 * }
 * ```
 */
abstract class ModernAdminPage extends AdminPage implements RendersCriticalStyles {

	/**
	 * The reset, with `%1$s` standing in for the page's body class.
	 *
	 * Every rule is scoped to that class, so the reset reaches this plugin's own
	 * pages and no other screen in wp-admin. `%1$s` rather than `%s` because the
	 * one value is substituted many times.
	 *
	 * @var string
	 */
	private const CRITICAL_STYLES = '
		body.%1$s {
			background: #fff;
		}

		.%1$s #wpwrap {
			overflow-y: auto;
		}

		/* Reset wp-admin padding. */
		.%1$s #wpcontent {
			padding-inline-start: 0;
		}

		.%1$s #wpbody-content {
			padding: 0;
			min-height: calc(100vh - var(--wp-admin--admin-bar--height));
			background: #fff;
		}

		.%1$s.auto-fold #wpcontent {
			padding: 0;
		}

		.%1$s-content,
		.%1$s .wrap {
			margin: 0;
			box-sizing: border-box;
		}

		.%1$s-content *,
		.%1$s .wrap * {
			box-sizing: border-box;
		}

		/* Hide the chrome a full-canvas page does not use. */
		.%1$s #wpfooter {
			display: none;
		}

		.%1$s #wpbody-content > div:not(.%1$s-content):not(#screen-meta) {
			display: none;
		}

		/* Let the browser scroll once there is room for the sidebar. */
		@media (min-width: 782px) {
			.%1$s #wpwrap {
				overflow-y: initial;
			}
		}
	';

	/**
	 * Build the critical-style block once per request and inline it on 'common'.
	 *
	 * The result depends only on get_base_css_classname(), which is derived from
	 * the plugin slug, so it is built once and cached in a function-local static.
	 * `common` is registered on every admin request and printed in `<head>`,
	 * which is what gets the reset in ahead of first paint.
	 *
	 * @return void
	 *
	 * @internal
	 */
	public function enqueue_critical_styles(): void {
		static $critical_styles;

		if ( null === $critical_styles ) {
			$critical_styles = \sprintf(
				self::CRITICAL_STYLES,
				\esc_attr( $this->get_base_css_classname() )
			);
		}

		\wp_add_inline_style( 'common', $critical_styles );
	}
}
