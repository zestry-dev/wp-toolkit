<?php

declare( strict_types=1 );

namespace Zestry\WPToolkit\Tests\Integration\Modules;

use Zestry\WPToolkit\Modules\Ajax\Ajax;
use Zestry\WPToolkit\Modules\Fields\Fields;
use Zestry\WPToolkit\Modules\PostTypes\PostTypes;
use Zestry\WPToolkit\Tests\Support\TestCase;

/**
 * A filename registers as itself, wherever the name outlives the request.
 *
 * The distinction these pin is the one that decides whether a name may be
 * respelled at all: does the toolkit *build* the name, or does it *take* it?
 *
 * A hook name, a page slug and a command are built -- your plugin slug is
 * prefixed on, a separator is chosen to suit where it lands, and an accessor
 * hands you the result so you never spell it yourself. Nothing outside your
 * plugin depends on the filename there.
 *
 * A post type, a taxonomy and a meta key are taken. They are columns in the
 * database and they appear in your REST responses, so the filename *is* the
 * identifier and respelling one renames data on a site that was working. These
 * tests exist because that is exactly what happened.
 *
 * @covers \Zestry\WPToolkit\Modules\PostTypes\PostTypes
 * @covers \Zestry\WPToolkit\Modules\Fields\Fields
 * @covers \Zestry\WPToolkit\Modules\Ajax\Ajax
 */
final class VerbatimNamesTest extends TestCase {

	/**
	 * `post-types/mc_form.php` registers `mc_form`, not `mc-form`.
	 *
	 * The regression this file was written for. A post type name is the
	 * `post_type` column of `wp_posts`: change it and every row already stored
	 * stops being found, with nothing to say so.
	 */
	public function test_a_post_type_keeps_the_name_its_file_was_given(): void {
		$this->write_plugin_file(
			'resources/post-types/mc_form.php',
			"<?php\nuse Zestry\\WPToolkit\\Modules\\PostTypes\\PostType;\nreturn new class extends PostType {\n"
				. "public function singular_name(): string { return 'Form'; }\n"
				. "public function plural_name(): string { return 'Forms'; }\n};\n"
		);

		$this->plugin->get( PostTypes::class )->register_all();

		$this->assertTrue( post_type_exists( 'mc_form' ), 'WordPress knows it by the name on the file.' );
		$this->assertFalse( post_type_exists( 'mc-form' ), 'And by no other.' );
	}

	/**
	 * A meta key is the `meta_key` column, and is equally not ours to respell.
	 */
	public function test_a_field_key_is_the_filename(): void {
		$this->write_field( 'acme_rating' );

		$fields = $this->plugin->get( Fields::class );
		$fields->register_fields();

		$this->assertArrayHasKey( 'acme_rating', $fields->get_discovered_fields()['post'] );
	}

	/**
	 * A leading underscore is WordPress's mark for protected meta, and a
	 * filename has to be able to carry one.
	 */
	public function test_a_field_file_can_carry_a_leading_underscore(): void {
		$this->write_field( '_acme_secret' );

		$fields = $this->plugin->get( Fields::class );
		$fields->register_fields();

		$this->assertArrayHasKey( '_acme_secret', $fields->get_discovered_fields()['post'] );
		$this->assertTrue( is_protected_meta( '_acme_secret', 'post' ), 'Which is what makes it protected.' );
	}

	/**
	 * The other side of the line: a hook name *is* built, from the plugin slug
	 * and the local name joined -- and both halves reach it exactly as written,
	 * so the file it came from is left alone and so is the name it registers.
	 */
	public function test_a_hook_name_is_built_while_the_file_is_not_touched(): void {
		$this->write_plugin_file(
			'resources/actions/send_welcome.php',
			"<?php\nuse Zestry\\WPToolkit\\Modules\\Ajax\\AjaxAction;\nreturn new class extends AjaxAction {\n"
				. "public function capability_check(): bool { return true; }
" . "public function handle(): void {}\n};\n"
		);

		$ajax = $this->plugin->get( Ajax::class );

		$this->assertSame( 'zestry-test-send_welcome', $ajax->get_action_slug( 'send_welcome' ) );
		$this->assertArrayHasKey( 'send_welcome', $ajax->get_discovered_actions(), 'The file keeps its own name.' );
	}

	/**
	 * Drop a field file into the plugin's fields directory.
	 *
	 * @param string $file_name The file's name, without `.php`.
	 * @return void
	 */
	private function write_field( string $file_name ): void {
		$this->write_plugin_file(
			'resources/fields/' . $file_name . '.php',
			"<?php\nreturn new class extends \\Zestry\\WPToolkit\\Modules\\Fields\\Field {\n"
				. "public function subtypes(): array { return array( 'post' ); }\n};\n"
		);
	}
}
