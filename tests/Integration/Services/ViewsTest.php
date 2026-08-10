<?php

declare( strict_types=1 );

namespace Zestry\WPToolkit\Tests\Integration\Services;

use Zestry\WPToolkit\Services\Views;
use Zestry\WPToolkit\Tests\Support\TestCase;

/**
 * View resolution, rendering, and path-traversal defense (review finding #3).
 *
 * @covers \Zestry\WPToolkit\Services\Views
 */
final class ViewsTest extends TestCase {

	private function views(): Views {
		return $this->plugin->get( Views::class );
	}

	public function test_renders_a_nested_view_with_data(): void {
		$this->write_plugin_file( 'views/emails/receipt.php', 'Total: <?php echo (int) $total; ?>' );

		$this->assertSame( 'Total: 42', $this->views()->get( 'emails/receipt', array( 'total' => 42 ) ) );
	}

	public function test_php_extension_is_optional(): void {
		$this->write_plugin_file( 'views/card.php', 'CARD' );

		$this->assertSame( 'CARD', $this->views()->get( 'card' ) );
		$this->assertSame( 'CARD', $this->views()->get( 'card.php' ) );
	}

	public function test_render_echoes_to_the_output_buffer(): void {
		$this->write_plugin_file( 'views/hi.php', 'HELLO' );

		$this->expectOutputString( 'HELLO' );
		$this->views()->render( 'hi' );
	}

	public function test_data_keys_become_local_variables(): void {
		$this->write_plugin_file( 'views/greet.php', 'Hi <?php echo esc_html( $name ); ?>' );

		$this->assertSame( 'Hi Ada', $this->views()->get( 'greet', array( 'name' => 'Ada' ) ) );
	}

	public function test_missing_view_throws_without_leaking_the_absolute_path(): void {
		try {
			$this->views()->get( 'does/not/exist' );
			$this->fail( 'Expected an exception for a missing view.' );
		} catch ( \InvalidArgumentException $e ) {
			$this->assertStringNotContainsString( $this->plugin_dir, $e->getMessage() );
		}
	}

	public function test_parent_directory_traversal_is_rejected_before_include(): void {
		// A secret PHP file outside the views root must never be included.
		$this->write_plugin_file( 'secret.php', '<?php throw new \RuntimeException( "LFI executed" );' );

		$this->expectException( \InvalidArgumentException::class );
		$this->views()->get( '../secret' );
	}

	public function test_set_views_root_changes_and_reinvalidates_the_root(): void {
		$this->write_plugin_file( 'templates/page.php', 'PAGE' );

		$views = $this->views();

		// Not found under the default 'views' root.
		$missing = false;
		try {
			$views->get( 'page' );
		} catch ( \InvalidArgumentException $e ) {
			$missing = true;
		}
		$this->assertTrue( $missing );

		$views->set_views_root( 'templates' );
		$this->assertSame( 'PAGE', $views->get( 'page' ) );
	}

	public function test_a_null_byte_in_the_view_name_is_rejected(): void {
		// A null byte is refused (Path's containment guard catches it first).
		$this->expectException( \InvalidArgumentException::class );
		$this->views()->get( "card\0.php" );
	}

	public function test_a_symlink_escaping_the_views_root_is_rejected(): void {
		// A view whose real (symlink-resolved) path is INSIDE the plugin but OUTSIDE
		// the views root must be refused after resolution. Keeping the target inside
		// the plugin means Path's containment passes and Views' own root check is what
		// rejects it.
		$this->write_plugin_file( 'private/secret.php', 'SECRET' );
		mkdir( $this->plugin_dir . '/views', 0777, true );
		symlink( $this->plugin_dir . '/private/secret.php', $this->plugin_dir . '/views/escape.php' );

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Invalid view name.' );
		$this->views()->get( 'escape' );
	}

	/**
	 * A data key named after one of the method's own parameters still arrives.
	 *
	 * The include used to happen in get()'s own scope, where `$view` and `$data`
	 * are its parameters, so `EXTR_SKIP` dropped both silently: a template asking
	 * for `$data` got the whole data array instead of the value passed under that
	 * key, with no warning anywhere.
	 *
	 * @dataProvider ordinary_names_a_template_might_use
	 */
	public function test_a_data_key_named_like_an_internal_local_reaches_the_view( string $key ): void {
		$this->write_plugin_file(
			'views/collision.php',
			'<?php echo esc_html( $' . $key . ' ); ?>'
		);

		$this->assertSame( 'passed through', $this->views()->get( 'collision', array( $key => 'passed through' ) ) );
	}

	/**
	 * @return array<string, string[]>
	 */
	public function ordinary_names_a_template_might_use(): array {
		return array(
			'view' => array( 'view' ),
			'data' => array( 'data' ),
			'file' => array( 'file' ),
		);
	}

	/**
	 * A template renders a subview with the same call everything else uses.
	 *
	 * Through `$this` rather than a variable: a name in `$data` could collide
	 * with one or be shadowed by one, and every template would carry a reserved
	 * word it did not ask for. `$this` is this service, so a subview is the same
	 * `render()` every other caller makes.
	 */
	public function test_a_template_can_render_a_subview(): void {
		$this->write_plugin_file( 'views/parts/-line.php', '<?php echo "[" . $label . "]";' );
		$this->write_plugin_file(
			'views/receipt.php',
			'<?php $this->render( "parts/-line", array( "label" => "one" ) ); echo $this->get( "parts/-line", array( "label" => "two" ) );'
		);

		$this->assertSame( '[one][two]', $this->views()->get( 'receipt' ) );
	}

	/**
	 * `$this` is the one name `extract()` cannot create, which is why the
	 * capability costs the template's scope nothing.
	 */
	public function test_a_data_key_cannot_replace_the_render_scope(): void {
		$this->write_plugin_file( 'views/scope.php', '<?php echo is_object( $this ) ? "intact" : "hijacked";' );

		$this->assertSame( 'intact', $this->views()->get( 'scope', array( 'this' => 'hijacked' ) ) );
	}
}
