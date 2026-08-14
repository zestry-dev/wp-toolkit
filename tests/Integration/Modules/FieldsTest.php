<?php

declare( strict_types=1 );

namespace Zestry\WPToolkit\Tests\Integration\Modules;

use Zestry\WPToolkit\Kernel\Exceptions\DiscoveryException;
use Zestry\WPToolkit\Modules\Fields\Fields;
use Zestry\WPToolkit\Modules\Fields\MetaType;
use Zestry\WPToolkit\Tests\Support\TestCase;

/**
 * Discovery, and the two defaults that deliberately differ from WordPress:
 * REST exposure on, and a permission check that does not depend on whether the
 * key happens to start with an underscore.
 *
 * @covers \Zestry\WPToolkit\Modules\Fields\Fields
 * @covers \Zestry\WPToolkit\Modules\Fields\Field
 */
final class FieldsTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		mkdir( $this->plugin_dir . '/resources/fields', 0777, true );
	}

	public function tear_down(): void {
		foreach ( array( 'acme-rating', '_acme_secret', 'acme-note', 'acme-tags' ) as $key ) {
			unregister_post_meta( 'post', $key );
		}

		parent::tear_down();
	}

	public function test_a_discovered_field_is_registered_for_each_post_type_it_names(): void {
		$this->write_field( 'acme-rating', array( 'post', 'page' ) );

		$this->boot()->register_fields();

		$this->assertArrayHasKey( 'acme-rating', get_registered_meta_keys( 'post', 'post' ) );
		$this->assertArrayHasKey( 'acme-rating', get_registered_meta_keys( 'post', 'page' ) );
	}

	/**
	 * The block editor reads and writes meta over REST, so a field kept out of
	 * it cannot be edited there at all -- which is why this is on where core
	 * defaults it off.
	 */
	public function test_rest_exposure_is_on_by_default(): void {
		$this->write_field( 'acme-rating' );

		$this->boot()->register_fields();

		$registered = get_registered_meta_keys( 'post', 'post' );
		$this->assertTrue( $registered['acme-rating']['show_in_rest'] );
	}

	/**
	 * Core decides the permission from the key's name: anything starting with
	 * `_` is refused to everyone. So prefixing a key to hide it from the classic
	 * custom-fields box also stops the block editor saving it, silently. This
	 * asks about the post instead.
	 */
	public function test_an_underscored_key_is_still_editable_by_someone_who_can_edit_the_post(): void {
		$this->write_field( 'acme-secret', array( 'post' ), "public function key(): string { return '_acme_secret'; }\n" );

		$this->boot()->register_fields();

		$editor  = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$post_id = self::factory()->post->create();

		wp_set_current_user( $editor );

		$this->assertTrue( current_user_can( 'edit_post_meta', $post_id, '_acme_secret' ) );
	}

	public function test_a_user_who_cannot_edit_the_post_cannot_write_the_field(): void {
		$this->write_field( 'acme-rating' );

		$this->boot()->register_fields();

		$post_id = self::factory()->post->create();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$this->assertFalse( current_user_can( 'edit_post_meta', $post_id, 'acme-rating' ) );
	}

	public function test_the_sanitiser_runs_on_write(): void {
		$this->write_field(
			'acme-rating',
			array( 'post' ),
			"public function type(): string { return 'integer'; }\n"
				. "public function sanitize( mixed \$value ): mixed { return max( 1, min( 5, (int) \$value ) ); }\n"
		);

		$this->boot()->register_fields();

		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, 'acme-rating', 99 );

		// Clamped to 5 by the sanitiser. Compared loosely because a registered
		// `type` describes the REST schema and does not cast on the PHP side --
		// meta comes back out of the database as a string either way.
		$this->assertEquals( 5, get_post_meta( $post_id, 'acme-rating', true ) );
	}

	/**
	 * `post` supports revisions, so the field registers and its value travels
	 * with each revision.
	 */
	public function test_a_field_can_opt_into_revisions(): void {
		$this->write_field(
			'acme-note',
			array( 'post' ),
			"public function has_revisions(): bool { return true; }\n"
		);

		$this->boot()->register_fields();

		$registered = get_registered_meta_keys( 'post', 'post' );
		$this->assertArrayHasKey( 'acme-note', $registered );
		$this->assertTrue( $registered['acme-note']['revisions_enabled'] );
	}

	/**
	 * WordPress decides protection by looking for a leading underscore and
	 * nothing else, which makes a security property of a filename. A field can
	 * say so instead, and the name then stops mattering.
	 */
	public function test_a_field_can_declare_itself_protected_whatever_it_is_called(): void {
		$this->write_field( 'acme-payload', array( 'post' ), "public function is_protected(): ?bool { return true; }\n" );

		$this->boot()->register_fields();

		$this->assertTrue( is_protected_meta( 'acme-payload', 'post' ) );
	}

	/**
	 * What protection actually decides here. Every field registers an
	 * `auth_callback` of its own that defers to `can_edit()`, so the underscore
	 * rule never gets to choose who may write -- but it still governs whether a
	 * block may bind to the key, and whether the Custom Fields panel lists it.
	 */
	public function test_declared_protection_blocks_a_block_binding(): void {
		$this->write_field( 'acme-payload', array( 'post' ), "public function is_protected(): ?bool { return true; }\n" );
		$this->write_field( 'acme-open', array( 'post' ), '' );

		$this->boot()->register_fields();

		$this->assertTrue( is_protected_meta( 'acme-payload', 'post' ) );
		$this->assertFalse( is_protected_meta( 'acme-open', 'post' ) );
	}

	/**
	 * And write authorization stays with the field, unchanged either way --
	 * which is why declaring protection is safe to do for presentation reasons.
	 */
	public function test_protection_does_not_decide_who_may_write(): void {
		$this->write_field( 'acme-payload', array( 'post' ), "public function is_protected(): ?bool { return true; }\n" );

		$this->boot()->register_fields();

		$registered = get_registered_meta_keys( 'post', 'post' );

		$this->assertInstanceOf( \Closure::class, $registered['acme-payload']['auth_callback'] );
	}

	/**
	 * Null is the default and means "the name decides", so a key already spelled
	 * with a leading underscore keeps WordPress's own answer.
	 */

	public function test_a_correctly_named_file_is_silent(): void {
		// No setExpectedIncorrectUsage(): an unexpected notice fails this test,
		// which is what makes it a guard against warning on a good name.
		$this->write_field( 'acme-rating' );
		$this->boot()->register_fields();

		$this->assertArrayHasKey( 'acme-rating', $this->boot()->get_discovered_fields()['post'] );
	}

	public function test_an_undeclared_field_leaves_wordpress_to_decide(): void {
		// Through key(), since a filename is kebab-cased and cannot carry the
		// leading underscore this test is about.
		$this->write_field( 'acme-secret', array( 'post' ), "public function key(): string { return '_acme_secret'; }\n" );

		$this->boot()->register_fields();

		$this->assertTrue( is_protected_meta( '_acme_secret', 'post' ) );
	}

	/**
	 * The filter fires for every key on the site, so one this plugin never
	 * registered has to come back untouched.
	 */
	public function test_leaves_another_plugins_meta_alone(): void {
		$this->write_field( 'acme-payload', array( 'post' ), "public function is_protected(): ?bool { return true; }\n" );

		$this->boot()->register_fields();

		$this->assertFalse( is_protected_meta( 'someone-elses-key', 'post' ) );
		$this->assertTrue( is_protected_meta( '_someone_elses_key', 'post' ) );
	}

	/**
	 * And it can go the other way: a key spelled with a leading underscore that
	 * is deliberately public.
	 */
	public function test_a_field_can_declare_itself_unprotected(): void {
		$this->write_field(
			'acme-public',
			array( 'post' ),
			"public function key(): string { return '_acme_public'; }\n"
				. "public function is_protected(): ?bool { return false; }\n"
		);

		$this->boot()->register_fields();

		$this->assertFalse( is_protected_meta( '_acme_public', 'post' ) );
	}

	/**
	 * The sharp end: without `revisions` support on the post type,
	 * register_post_meta() returns false and the key is never registered at all
	 * -- so the field silently does not exist, rather than merely skipping
	 * revisions. A PostType here supports only title and editor by default.
	 */
	public function test_revisions_on_a_type_that_does_not_support_them_registers_nothing(): void {
		register_post_type( 'zestry_norev', array( 'supports' => array( 'title' ) ) );

		$this->write_field(
			'acme-note',
			array( 'zestry_norev' ),
			"public function has_revisions(): bool { return true; }\n"
		);

		$this->setExpectedIncorrectUsage( 'register_meta' );

		$this->boot()->register_fields();

		$this->assertArrayNotHasKey( 'acme-note', get_registered_meta_keys( 'post', 'zestry_norev' ) );

		unregister_post_type( 'zestry_norev' );
	}

	/**
	 * Null means "no default" rather than "a default of null", or an unset key
	 * would start reading back as null instead of the type's empty value.
	 */
	public function test_no_default_is_registered_unless_one_is_given(): void {
		$this->write_field( 'acme-note' );

		$this->boot()->register_fields();

		$registered = get_registered_meta_keys( 'post', 'post' );
		$this->assertArrayNotHasKey( 'default', $registered['acme-note'] );
	}

	/**
	 * A typo returns '' from the bare function, silently. Here it says which
	 * keys exist.
	 */
	public function test_an_undeclared_key_throws_rather_than_reading_as_empty(): void {
		$this->write_field( 'acme-note' );

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'No post field declares the meta key "acme-notes"' );

		$this->boot()->get( self::factory()->post->create(), 'acme-notes' );
	}

	public function test_has_and_delete_round_trip(): void {
		$this->write_field( 'acme-note' );

		$fields  = $this->boot();
		$post_id = self::factory()->post->create();

		$this->assertFalse( $fields->has( $post_id, 'acme-note' ) );
		$this->assertSame( 'fallback', $fields->get( $post_id, 'acme-note', 'fallback' ) );

		$fields->set( $post_id, 'acme-note', 'stored' );

		$this->assertTrue( $fields->has( $post_id, 'acme-note' ) );

		$fields->delete( $post_id, 'acme-note' );

		$this->assertFalse( $fields->has( $post_id, 'acme-note' ) );
	}

	/**
	 * These accessors are for fields this plugin declares. WordPress's own
	 * classic keys are mostly unregistered and have dedicated functions, so the
	 * refusal points there rather than pretending to own them.
	 */
	public function test_a_core_meta_key_is_refused_with_a_pointer_to_get_post_meta(): void {
		$this->write_field( 'acme-note' );

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Use get_metadata()' );

		$this->boot()->get( self::factory()->post->create(), '_thumbnail_id' );
	}

	/**
	 * A key holds one value here, whatever WordPress would allow, so several
	 * values are one array in one row.
	 */
	public function test_a_field_holds_one_value_and_an_array_goes_in_one_row(): void {
		$this->write_field(
			'acme-tags',
			array( 'post' ),
			"public function type(): string { return 'array'; }\n"
				. "public function is_shown_in_rest(): bool|array { return false; }\n"
		);

		$fields  = $this->boot();
		$post_id = self::factory()->post->create();

		$fields->set( $post_id, 'acme-tags', array( 'a', 'b' ) );

		$this->assertSame( array( 'a', 'b' ), $fields->get( $post_id, 'acme-tags' ) );
		$this->assertCount( 1, get_post_meta( $post_id, 'acme-tags' ), 'One row, holding the array.' );

		$registered = get_registered_meta_keys( 'post', 'post' );
		$this->assertTrue( $registered['acme-tags']['single'] );
	}

	/**
	 * validate() decides whether to take the value, sanitize() what form to
	 * store it in -- the order WordPress dispatches a REST request in, and the
	 * safe one, since it judges what the client actually sent.
	 */
	public function test_set_refuses_a_value_the_field_rejects(): void {
		$this->write_field(
			'acme-rating',
			array( 'post' ),
			"public function validate( mixed \$value ): bool { return is_numeric( \$value ); }\n"
		);

		$fields  = $this->boot();
		$post_id = self::factory()->post->create();

		$this->assertFalse( $fields->set( $post_id, 'acme-rating', 'not a number' ) );
		$this->assertFalse( $fields->has( $post_id, 'acme-rating' ), 'Nothing was written.' );

		$this->assertTrue( $fields->set( $post_id, 'acme-rating', '4' ) );
		$this->assertEquals( 4, $fields->get( $post_id, 'acme-rating' ) );
	}

	/**
	 * WordPress sanitises meta and then offers the write for a veto, which is
	 * the reverse of how it treats a REST parameter -- so validate() is asked
	 * about the sanitised value. A sanitiser that coerces therefore leaves
	 * nothing to reject.
	 */
	public function test_validate_sees_the_value_after_sanitize_has_shaped_it(): void {
		$this->write_field(
			'acme-note',
			array( 'post' ),
			"public function validate( mixed \$value ): bool { \$GLOBALS['zestry_seen'] = \$value; return true; }\n"
				. "public function sanitize( mixed \$value ): mixed { return strtoupper( (string) \$value ); }\n"
		);

		$fields = $this->boot();
		$fields->set( self::factory()->post->create(), 'acme-note', 'raw' );

		$this->assertSame( 'RAW', $GLOBALS['zestry_seen'] );
		unset( $GLOBALS['zestry_seen'] );
	}

	/**
	 * The reason validation lives on WordPress's filter rather than inside
	 * set(): it then applies to every write, not just the one that goes through
	 * this module.
	 */
	public function test_validation_blocks_a_bare_update_post_meta_too(): void {
		$this->write_field(
			'acme-rating',
			array( 'post' ),
			"public function validate( mixed \$value ): bool { return is_numeric( \$value ); }\n"
		);

		$this->boot()->register_fields();
		$post_id = self::factory()->post->create();

		$this->assertFalse( update_post_meta( $post_id, 'acme-rating', 'nope' ) );
		$this->assertSame( '', get_post_meta( $post_id, 'acme-rating', true ) );

		$this->assertNotFalse( update_post_meta( $post_id, 'acme-rating', '4' ) );
	}

	/**
	 * WordPress reads a meta field's schema in its REST controller and nowhere
	 * else, so a field declaring `maximum: 5` used to store 9 through any other
	 * route and say nothing about it.
	 */
	public function test_a_schema_keyword_is_enforced_on_a_bare_update_post_meta(): void {
		$this->write_rated_field();

		$this->boot()->register_fields();
		$post_id = self::factory()->post->create();

		$this->assertFalse( update_post_meta( $post_id, 'acme-rating', 9 ) );
		$this->assertSame( '', get_post_meta( $post_id, 'acme-rating', true ), 'Nothing was written.' );

		$this->assertNotFalse( update_post_meta( $post_id, 'acme-rating', 4 ) );
		$this->assertEquals( 4, get_post_meta( $post_id, 'acme-rating', true ), 'A value inside the range is untouched.' );
	}

	/**
	 * The single most likely way to get this wrong. A key that has never been
	 * written reads back as `''`, which satisfies neither `type: integer` nor any
	 * `enum` -- so checking it would have a field refuse its own absence, on
	 * ordinary content rather than on an edge case.
	 */
	public function test_an_unset_field_is_read_and_cleared_without_being_refused(): void {
		$this->write_rated_field();

		$fields = $this->boot();
		$fields->register_fields();
		$post_id = self::factory()->post->create();

		$this->assertNull( $fields->get( $post_id, 'acme-rating' ), 'Reading one that was never set is not a refusal.' );

		// And emptying one that was set is a write of '' -- which the schema
		// would reject as an integer if it were being checked.
		$fields->set( $post_id, 'acme-rating', 3 );

		$this->assertTrue( $fields->set( $post_id, 'acme-rating', '' ) );
		$this->assertSame( '', get_post_meta( $post_id, 'acme-rating', true ) );
	}

	/**
	 * `enum` is the keyword this unlocks: it was unusable on an optional field,
	 * because an unset key reads as `''` and no enum lists that. Now it is both
	 * safe to declare and enforced on every write.
	 */
	public function test_an_enum_is_enforced_and_still_allows_an_unset_key(): void {
		$this->write_field(
			'acme-colour',
			array( 'post' ),
			"public function is_shown_in_rest(): bool|array { return array( 'schema' => array( 'enum' => array( 'red', 'blue' ) ) ); }\n"
		);

		$fields = $this->boot();
		$fields->register_fields();
		$post_id = self::factory()->post->create();

		$this->assertNull( $fields->get( $post_id, 'acme-colour' ), 'An unset key is not held to the enum.' );
		$this->assertInstanceOf( \WP_Error::class, $fields->set( $post_id, 'acme-colour', 'green' ) );
		$this->assertTrue( $fields->set( $post_id, 'acme-colour', 'blue' ) );
	}

	/**
	 * A refusal with no message makes every consumer write one, which is what
	 * `rest_validate_value_from_schema()` already did for us.
	 */
	public function test_a_refusal_carries_a_message_naming_the_key(): void {
		$this->write_rated_field();

		$fields = $this->boot();
		$fields->register_fields();

		$refused = $fields->set( self::factory()->post->create(), 'acme-rating', 9 );

		$this->assertInstanceOf( \WP_Error::class, $refused );
		$this->assertStringContainsString( 'acme-rating', $refused->get_error_message() );
	}

	/**
	 * The sharpest edge of enforcing the schema, pinned so it stays a decision.
	 *
	 * `type()` defaults to `string`, and WordPress is strict about that one --
	 * where `integer` and `number` accept a numeric string, which is what a value
	 * read back out of the database always is. So a field that never declared a
	 * type refuses an integer write, and the answer is to declare the type rather
	 * than to coerce here: coercing would change what gets stored.
	 */
	public function test_the_default_string_type_refuses_a_non_string_write(): void {
		$this->write_field( 'acme-note' );

		$fields = $this->boot();
		$fields->register_fields();
		$post_id = self::factory()->post->create();

		$this->assertInstanceOf(
			\WP_Error::class,
			$fields->set( $post_id, 'acme-note', 5 ),
			'An untyped field is a string field, and 5 is not a string.'
		);

		$this->assertTrue( $fields->set( $post_id, 'acme-note', '5' ) );
	}

	/**
	 * The other half: a numeric string is what a read gives back, so an integer
	 * field has to accept one or nothing could be written back where it came
	 * from.
	 */
	public function test_an_integer_field_accepts_the_numeric_string_a_read_gives_back(): void {
		$this->write_rated_field();

		$fields = $this->boot();
		$fields->register_fields();
		$post_id = self::factory()->post->create();

		$this->assertTrue( $fields->set( $post_id, 'acme-rating', 4 ) );
		$this->assertTrue( $fields->set( $post_id, 'acme-rating', '3' ), 'The string a read hands back is still a valid write.' );
	}

	/**
	 * The schema validation and the registration are two readings of the same
	 * three methods, so neither can come to hold a value the other would not.
	 */
	public function test_the_schema_is_assembled_from_what_the_field_declares(): void {
		$this->write_field(
			'acme-rating',
			array( 'post' ),
			"public function type(): string { return 'integer'; }\n"
				. "public function description(): string { return 'Out of five.'; }\n"
				. "public function is_shown_in_rest(): bool|array { return array( 'schema' => array( 'minimum' => 1, 'maximum' => 5 ) ); }\n"
		);

		$schema = $this->boot()->get_field( 'acme-rating' )->get_schema();

		$this->assertSame( 'integer', $schema['type'] );
		$this->assertSame( 'Out of five.', $schema['description'] );
		$this->assertSame( 1, $schema['minimum'] );
		$this->assertSame( 5, $schema['maximum'] );
	}

	/**
	 * A field can attach to something other than a post -- the meta table is
	 * chosen by the field, not assumed.
	 */
	public function test_a_field_can_attach_to_term_meta(): void {
		$this->write_field(
			'acme-colour',
			array( 'category' ),
			"public function object_type(): \\Zestry\\WPToolkit\\Modules\\Fields\\MetaType { return \\Zestry\\WPToolkit\\Modules\\Fields\\MetaType::Term; }\n"
		);

		$fields = $this->boot();
		$fields->register_fields();

		$term_id = self::factory()->term->create( array( 'taxonomy' => 'category' ) );

		$this->assertTrue( $fields->set( $term_id, 'acme-colour', 'blue', MetaType::Term ) );
		$this->assertSame( 'blue', $fields->get( $term_id, 'acme-colour', null, MetaType::Term ) );
		$this->assertSame( 'blue', get_term_meta( $term_id, 'acme-colour', true ) );

		unregister_meta_key( 'term', 'acme-colour', 'category' );
	}

	/**
	 * The reason the map is nested: a meta key is unique only within its object
	 * type, so the same name on a post and on a term is two keys in two tables.
	 * A guard that matched on the key alone would police someone else's write.
	 */
	public function test_the_same_key_on_two_object_types_are_two_fields(): void {
		$this->write_field( 'acme-colour', array( 'post' ) );
		$this->write_field(
			'colour-term',
			array( 'category' ),
			"public function key(): string { return 'acme-colour'; }\n"
				. "public function object_type(): MetaType { return MetaType::Term; }\n"
				. "public function validate( mixed \$value ): bool { return 'blue' === \$value; }\n"
		);

		$fields = $this->boot();
		$fields->register_fields();

		$post_id = self::factory()->post->create();
		$term_id = self::factory()->term->create( array( 'taxonomy' => 'category' ) );

		// The term field's validate() must not reach the post's key.
		$this->assertTrue( $fields->set( $post_id, 'acme-colour', 'anything' ) );
		$this->assertFalse( $fields->set( $term_id, 'acme-colour', 'red', MetaType::Term ) );
		$this->assertTrue( $fields->set( $term_id, 'acme-colour', 'blue', MetaType::Term ) );

		unregister_meta_key( 'term', 'acme-colour', 'category' );
	}

	public function test_a_missing_default_directory_is_not_an_error(): void {
		$this->remove_dir( $this->plugin_dir . '/resources/fields' );

		$this->assertSame( array(), $this->boot()->get_discovered_fields() );
	}

	public function test_a_file_returning_the_wrong_type_throws(): void {
		file_put_contents( $this->plugin_dir . '/resources/fields/bad.php', "<?php\nreturn 'nope';\n" );

		$this->expectException( DiscoveryException::class );
		$this->expectExceptionMessage( 'must return an instance of' );

		$this->boot()->get_discovered_fields();
	}

	/**
	 * Boot the module and hand it back.
	 *
	 * @return Fields
	 */
	private function boot(): Fields {
		return $this->plugin->get( Fields::class );
	}

	/**
	 * An integer field constrained to 1-5 by its own schema, and nothing else.
	 *
	 * @return void
	 */
	private function write_rated_field(): void {
		$this->write_field(
			'acme-rating',
			array( 'post' ),
			"public function type(): string { return 'integer'; }\n"
				. "public function is_shown_in_rest(): bool|array { return array( 'schema' => array( 'minimum' => 1, 'maximum' => 5 ) ); }\n"
		);
	}

	/**
	 * Drop a field file into the plugin's fields directory.
	 *
	 * @param string   $file_name  The file's name, without `.php` -- and the meta key.
	 * @param string[] $post_types Post types the field attaches to.
	 * @param string   $extra      Extra class body.
	 * @return void
	 */
	private function write_field( string $file_name, array $post_types = array( 'post' ), string $extra = '' ): void {
		$types = implode( ', ', array_map( static fn( $t ) => "'" . $t . "'", $post_types ) );

		file_put_contents(
			$this->plugin_dir . '/resources/fields/' . $file_name . '.php',
			"<?php\n"
				. "use Zestry\\WPToolkit\\Modules\\Fields\\MetaType;\n"
				. "return new class extends \\Zestry\\WPToolkit\\Modules\\Fields\\Field {\n"
				. "public function subtypes(): array { return array( {$types} ); }\n"
				. $extra
				. "};\n"
		);
	}
}
