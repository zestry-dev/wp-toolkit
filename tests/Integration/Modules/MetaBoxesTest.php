<?php

declare( strict_types=1 );

namespace Zestry\WPToolkit\Tests\Integration\Modules;

use Zestry\WPToolkit\Kernel\Exceptions\DiscoveryException;
use Zestry\WPToolkit\Modules\MetaBoxes\MetaBoxes;
use Zestry\WPToolkit\Modules\MetaBoxes\MetaBoxType;
use Zestry\WPToolkit\Tests\Support\TestCase;

/**
 * The four guards between `save_post` and a box's save(), which are the reason
 * this module exists -- omitting any one of them is a bug, and two are security
 * bugs.
 *
 * @covers \Zestry\WPToolkit\Modules\MetaBoxes\MetaBoxes
 * @covers \Zestry\WPToolkit\Modules\MetaBoxes\MetaBox
 */
final class MetaBoxesTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		mkdir( $this->plugin_dir . '/resources/meta-boxes', 0777, true );

		$GLOBALS['zestry_saved'] = 0;
	}

	public function tear_down(): void {
		unset( $GLOBALS['zestry_saved'], $_POST );

		parent::tear_down();
	}

	public function test_a_discovered_box_is_registered_on_the_screens_it_names(): void {
		$this->write_box( 'details', array( 'post' ) );

		set_current_screen( 'post' );
		$this->boot()->register_all();

		global $wp_meta_boxes;
		$this->assertArrayHasKey( 'zestry-test-details', $wp_meta_boxes['post']['advanced']['default'] );
	}

	/**
	 * A box id is an element id on a screen every plugin can add panels to.
	 */
	public function test_the_identifier_is_namespaced_to_the_plugin(): void {
		$this->assertSame( 'zestry-test-book-details', $this->boot()->get_box_id( 'book-details' ) );
	}

	/**
	 * The whole point. An autosave carries none of the form, so a handler that
	 * does not check reads absent fields and writes empty values over what was
	 * stored.
	 *
	 * Its own process: DOING_AUTOSAVE cannot be undefined once set, so defining
	 * it here would make every later test in the run look like an autosave.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 * @return void
	 */
	public function test_an_autosave_never_reaches_save(): void {
		$this->write_box( 'details', array( 'post' ) );
		$post = get_post( self::factory()->post->create() );

		$this->submit( 'zestry-test-details' );

		if ( ! defined( 'DOING_AUTOSAVE' ) ) {
			define( 'DOING_AUTOSAVE', true );
		}

		$this->boot()->save_all( $post->ID, $post );

		$this->assertSame( 0, $GLOBALS['zestry_saved'] );
	}

	/**
	 * A revision is saved as its own post, so writing meta then attaches it to
	 * the revision rather than to the post being edited.
	 */
	public function test_a_revision_never_reaches_save(): void {
		$this->write_box( 'details', array( 'post' ) );

		$post        = get_post( self::factory()->post->create() );
		$revision_id = wp_save_post_revision( $post->ID );

		if ( null === $revision_id ) {
			$this->markTestSkipped( 'This install stored no revision to test against.' );
		}

		$this->submit( 'zestry-test-details' );

		$this->boot()->save_all( (int) $revision_id, get_post( $revision_id ) );

		$this->assertSame( 0, $GLOBALS['zestry_saved'] );
	}

	/**
	 * Without this, any page could submit the form on a logged-in user's behalf.
	 */
	public function test_a_missing_or_wrong_nonce_never_reaches_save(): void {
		$this->write_box( 'details', array( 'post' ) );
		$post = get_post( self::factory()->post->create() );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$this->boot()->save_all( $post->ID, $post );
		$this->assertSame( 0, $GLOBALS['zestry_saved'], 'No nonce at all.' );

		$_POST['zestry-test-details-nonce'] = 'not-a-real-nonce';

		$this->boot()->save_all( $post->ID, $post );
		$this->assertSame( 0, $GLOBALS['zestry_saved'], 'A nonce that does not verify.' );
	}

	public function test_a_user_who_cannot_edit_the_post_never_reaches_save(): void {
		$this->write_box( 'details', array( 'post' ) );
		$post = get_post( self::factory()->post->create() );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->submit( 'zestry-test-details' );

		$this->boot()->save_all( $post->ID, $post );

		$this->assertSame( 0, $GLOBALS['zestry_saved'] );
	}

	public function test_a_real_save_by_a_permitted_user_reaches_save(): void {
		$this->write_box( 'details', array( 'post' ) );
		$post = get_post( self::factory()->post->create() );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->submit( 'zestry-test-details' );

		$this->boot()->save_all( $post->ID, $post );

		$this->assertSame( 1, $GLOBALS['zestry_saved'] );
	}

	/**
	 * A screen showing three boxes and saving one is ordinary, so a box absent
	 * from the submitted form is skipped rather than failing the request.
	 */
	public function test_a_box_for_another_post_type_is_skipped(): void {
		$this->write_box( 'details', array( 'page' ) );
		$post = get_post( self::factory()->post->create() );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->submit( 'zestry-test-details' );

		$this->boot()->save_all( $post->ID, $post );

		$this->assertSame( 0, $GLOBALS['zestry_saved'] );
	}

	/**
	 * Printed by the module rather than left to the box, so a box cannot be
	 * written without one.
	 */
	public function test_the_nonce_field_is_printed_before_the_box_renders(): void {
		$this->write_box( 'details', array( 'post' ) );
		$post = get_post( self::factory()->post->create() );

		set_current_screen( 'post' );
		$this->boot()->register_all();

		global $wp_meta_boxes;
		$callback = $wp_meta_boxes['post']['advanced']['default']['zestry-test-details']['callback'];

		ob_start();
		$callback( $post );
		$markup = (string) ob_get_clean();

		$this->assertStringContainsString( 'name="zestry-test-details-nonce"', $markup );
		$this->assertStringContainsString( 'RENDERED', $markup, 'The box still got to render.' );
	}

	/**
	 * The declarative path: a key named by fields() is read from the request and
	 * written through Fields, so its validate() and sanitize() both apply.
	 */
	public function test_a_declared_field_is_stored_from_the_request(): void {
		mkdir( $this->plugin_dir . '/resources/fields', 0777, true );
		file_put_contents(
			$this->plugin_dir . '/resources/fields/acme-note.php',
			"<?php\nreturn new class extends \\Zestry\\WPToolkit\\Modules\\Fields\\Field {\n"
				. "public function subtypes(): array { return array( 'post' ); }\n"
				. "public function sanitize( mixed \$value ): mixed { return strtoupper( (string) \$value ); }\n"
				. "};\n"
		);
		$this->write_box( 'details', array( 'post' ), "public function fields(): array { return array( 'acme-note' ); }\n" );

		$post = get_post( self::factory()->post->create() );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->submit( 'zestry-test-details' );
		$_POST['acme-note'] = 'quiet';

		$this->boot()->save_all( $post->ID, $post );

		$this->assertSame( 'QUIET', get_post_meta( $post->ID, 'acme-note', true ), 'sanitize() ran on the way in.' );
	}

	/**
	 * A checkbox is absent when unchecked, and so is every field of a box the
	 * user never opened -- writing those as empty would erase what was there.
	 */
	public function test_a_declared_field_absent_from_the_request_is_left_alone(): void {
		mkdir( $this->plugin_dir . '/resources/fields', 0777, true );
		file_put_contents(
			$this->plugin_dir . '/resources/fields/acme-note.php',
			"<?php\nreturn new class extends \\Zestry\\WPToolkit\\Modules\\Fields\\Field {\n"
				. "public function post_types(): array { return array( 'post' ); }\n};\n"
		);
		$this->write_box( 'details', array( 'post' ), "public function fields(): array { return array( 'acme-note' ); }\n" );

		$post = get_post( self::factory()->post->create() );
		update_post_meta( $post->ID, 'acme-note', 'kept' );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->submit( 'zestry-test-details' );

		$this->boot()->save_all( $post->ID, $post );

		$this->assertSame( 'kept', get_post_meta( $post->ID, 'acme-note', true ) );
	}

	/**
	 * The comment edit screen is the only other one WordPress renders boxes on.
	 * It has no autosave and no revisions, so the nonce and the capability are
	 * the whole check.
	 */
	public function test_a_comment_box_saves_on_its_own_hook(): void {
		// No screens() at all: a comment box has one screen and defaults to it.
		$this->write_box(
			'moderation',
			null,
			"public function object_type(): MetaBoxType { return MetaBoxType::Comment; }\n"
		);

		$post_id    = self::factory()->post->create();
		$comment_id = self::factory()->comment->create( array( 'comment_post_ID' => $post_id ) );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->submit( 'zestry-test-moderation' );

		$this->boot()->save_comment( $comment_id );

		$this->assertSame( 1, $GLOBALS['zestry_saved'] );
	}

	/**
	 * The nested map is what keeps these apart: a post save must not reach a
	 * comment box, even one sharing its identifier.
	 */
	public function test_a_post_save_never_reaches_a_comment_box(): void {
		$this->write_box(
			'moderation',
			null,
			"public function object_type(): MetaBoxType { return MetaBoxType::Comment; }\n"
		);

		$post = get_post( self::factory()->post->create() );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->submit( 'zestry-test-moderation' );

		$this->boot()->save_all( $post->ID, $post );

		$this->assertSame( 0, $GLOBALS['zestry_saved'] );
	}

	public function test_a_missing_default_directory_is_not_an_error(): void {
		$this->remove_dir( $this->plugin_dir . '/resources/meta-boxes' );

		$this->assertSame( array(), $this->boot()->get_discovered_boxes() );
	}

	public function test_a_file_returning_the_wrong_type_throws(): void {
		file_put_contents( $this->plugin_dir . '/resources/meta-boxes/bad.php', "<?php\nreturn 'nope';\n" );

		$this->expectException( DiscoveryException::class );
		$this->expectExceptionMessage( 'must return an instance of' );

		$this->boot()->get_discovered_boxes();
	}

	/**
	 * Boot the module and hand it back.
	 *
	 * @return MetaBoxes
	 */
	private function boot(): MetaBoxes {
		return $this->plugin->get( MetaBoxes::class );
	}

	/**
	 * Put a valid nonce for a box into the request.
	 *
	 * @param string $id The box's identifier.
	 * @return void
	 */
	private function submit( string $id ): void {
		$_POST[ $id . '-nonce' ] = wp_create_nonce( $id );
	}

	/**
	 * Drop a box file into the plugin's meta-boxes directory.
	 *
	 * @param string   $name       The box's local name.
	 * @param string[]|null $post_types Screens the box names, or null to leave the default.
	 * @param string   $extra      Extra class body.
	 * @return void
	 */
	private function write_box( string $name, ?array $post_types, string $extra = '' ): void {
		// Null leaves screens() off the class entirely, so the base default
		// applies -- which is the whole point for a comment box.
		$screens = null === $post_types
			? ''
			: 'public function screens(): array { return array( '
				. implode( ', ', array_map( static fn( $t ) => "'" . $t . "'", $post_types ) )
				. " ); }\n";

		file_put_contents(
			$this->plugin_dir . '/resources/meta-boxes/' . $name . '.php',
			"<?php\n"
				. "use Zestry\\WPToolkit\\Modules\\MetaBoxes\\MetaBoxType;\n"
				. "return new class extends \\Zestry\\WPToolkit\\Modules\\MetaBoxes\\MetaBox {\n"
				. $extra
				. "public function title(): string { return 'A box'; }\n"
				. $screens
				. "public function render( object \$post ): void { echo 'RENDERED'; }\n"
				. "public function save( object \$post ): void { ++\$GLOBALS['zestry_saved']; }\n"
				. "};\n"
		);
	}
}
