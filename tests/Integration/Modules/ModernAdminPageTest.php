<?php

declare( strict_types=1 );

namespace Zestry\WPToolkit\Tests\Integration\Modules;

use Zestry\WPToolkit\Modules\AdminPages\ModernAdminPage;
use Zestry\WPToolkit\Tests\Support\TestCase;

/**
 * ModernAdminPage's critical-style reset, inlined on enqueue_assets().
 *
 * @covers \Zestry\WPToolkit\Modules\AdminPages\ModernAdminPage
 */
final class ModernAdminPageTest extends TestCase {

	public function test_enqueue_assets_inlines_the_critical_styles_on_the_common_handle(): void {
		wp_register_style( 'common', false );

		$this->page()->enqueue_assets();

		global $wp_styles;
		$inline = $wp_styles->get_data( 'common', 'after' );

		$this->assertNotEmpty( $inline, 'A critical style block was queued on the common handle.' );
		$css = implode( "\n", (array) $inline );
		$this->assertStringContainsString( 'zestry-test-admin-page', $css, 'The classname is scoped to this plugin.' );
		$this->assertStringNotContainsString( '<style>', $css, 'The style tags are stripped before inlining.' );
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
