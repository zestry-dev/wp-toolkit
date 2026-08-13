<?php

declare( strict_types=1 );

namespace Zestry\WPToolkit\Tests\Integration\Modules;

use Zestry\WPToolkit\Modules\AdminPages\Contracts\RendersCriticalStyles;
use Zestry\WPToolkit\Modules\AdminPages\ModernAdminPage;
use Zestry\WPToolkit\Tests\Support\TestCase;

/**
 * ModernAdminPage's critical-style reset.
 *
 * @covers \Zestry\WPToolkit\Modules\AdminPages\ModernAdminPage
 */
final class ModernAdminPageTest extends TestCase {

	public function test_enqueue_critical_styles_inlines_them_on_the_common_handle(): void {
		wp_register_style( 'common', false );

		$this->page()->enqueue_critical_styles();

		global $wp_styles;
		$inline = $wp_styles->get_data( 'common', 'after' );

		$this->assertNotEmpty( $inline, 'A critical style block was queued on the common handle.' );
		$css = implode( "\n", (array) $inline );
		$this->assertStringContainsString( 'zestry-test-admin-page', $css, 'The classname is scoped to this plugin.' );
		$this->assertStringNotContainsString( '<style>', $css, 'The style tags are stripped before inlining.' );
	}

	public function test_it_declares_the_contract_the_module_dispatches_on(): void {
		$this->assertInstanceOf( RendersCriticalStyles::class, $this->page() );
	}

	public function test_enqueue_assets_is_left_entirely_to_the_subclass(): void {
		// The reset does not ride on enqueue_assets(), so a subclass overriding
		// it without a parent:: call cannot lose the page its layout.
		// The registry is rebuilt first: WP_Styles is a global that outlives a
		// single test, and the assertion is about what this call adds.
		unset( $GLOBALS['wp_styles'] );
		wp_styles();
		wp_register_style( 'common', false );

		$this->page()->enqueue_assets();

		$this->assertEmpty( wp_styles()->get_data( 'common', 'after' ) );
	}

	private function page(): ModernAdminPage {
		$page = new class() extends ModernAdminPage {
			public function title(): string {
				return 'Modern Page';
			}

			public function capability(): string {
				return 'manage_options';
			}

			public function render(): void {}
		};

		$this->plugin->wire( $page );

		return $page;
	}
}
