<?php

declare( strict_types=1 );

namespace Zestry\WPToolkit\Tests\Integration\DevTools;

use Zestry\WPToolkit\DevTools\StubRenderer;
use Zestry\WPToolkit\Tests\Support\TestCase;

/**
 * Stub placeholder substitution and title-casing.
 *
 * @covers \Zestry\WPToolkit\DevTools\StubRenderer
 */
final class StubRendererTest extends TestCase {

	private function renderer(): StubRenderer {
		return $this->plugin->get( StubRenderer::class );
	}

	public function test_render_substitutes_every_placeholder(): void {
		$stub = $this->write_plugin_file(
			'stub.php.stub',
			"<?php\nnamespace {{namespace}};\n// {{name}}\n"
		);

		$rendered = $this->renderer()->render(
			$stub,
			array(
				'namespace' => 'Acme\\Plugin',
				'name'      => 'send-welcome-email',
			)
		);

		$this->assertSame( "<?php\nnamespace Acme\\Plugin;\n// send-welcome-email\n", $rendered );
	}

	public function test_render_leaves_an_unmatched_placeholder_untouched(): void {
		$stub = $this->write_plugin_file( 'stub.php.stub', 'value: {{missing}}' );

		$rendered = $this->renderer()->render( $stub, array() );

		$this->assertSame( 'value: {{missing}}', $rendered );
	}

	public function test_render_throws_when_the_stub_does_not_exist(): void {
		$this->expectException( \InvalidArgumentException::class );

		$this->renderer()->render( $this->plugin_dir . '/missing.stub', array() );
	}

	public function test_to_title_converts_kebab_case(): void {
		$this->assertSame( 'Send Welcome Email', $this->renderer()->to_title( 'send-welcome-email' ) );
	}

	public function test_to_title_converts_snake_case(): void {
		$this->assertSame( 'Send Welcome Email', $this->renderer()->to_title( 'send_welcome_email' ) );
	}

	public function test_to_title_handles_a_single_word(): void {
		$this->assertSame( 'Cleanup', $this->renderer()->to_title( 'cleanup' ) );
	}
}
