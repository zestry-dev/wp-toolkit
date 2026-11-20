<?php

declare( strict_types=1 );

namespace Zestry\WPToolkit\Tests\Integration\Modules;

use Zestry\WPToolkit\Modules\Validation\Each;
use Zestry\WPToolkit\Modules\Validation\Keys;
use Zestry\WPToolkit\Modules\Validation\ListOf;
use Zestry\WPToolkit\Modules\Validation\MapOf;
use Zestry\WPToolkit\Modules\Validation\Property;
use Zestry\WPToolkit\Modules\Validation\Structure;
use Zestry\WPToolkit\Modules\Validation\Rule;
use Zestry\WPToolkit\Modules\Validation\Rules\Count;
use Zestry\WPToolkit\Modules\Validation\Rules\Distinct;
use Zestry\WPToolkit\Modules\Validation\Rules\Email;
use Zestry\WPToolkit\Modules\Validation\Rules\Length;
use Zestry\WPToolkit\Modules\Validation\Rules\NotBlank;
use Zestry\WPToolkit\Modules\Validation\Rules\OneOf;
use Zestry\WPToolkit\Modules\Validation\Rules\Pattern;
use Zestry\WPToolkit\Modules\Validation\Rules\Range;
use Zestry\WPToolkit\Modules\Validation\Rules\Sanitize;
use Zestry\WPToolkit\Modules\Validation\Rules\Url;
use Zestry\WPToolkit\Modules\Validation\Rules\Uuid;
use Zestry\WPToolkit\Modules\Validation\Validation;
use Zestry\WPToolkit\Tests\Support\TestCase;

enum ValidationTestStatus: string {
	case Draft = 'draft';
	case Live  = 'live';
}

enum ValidationTestOrder {
	case Ascending;
	case Descending;
}

/**
 * A rule of a consumer's own: all three halves.
 */
#[\Attribute( \Attribute::TARGET_PROPERTY )]
final class ValidationTestSlug extends Rule {

	public static array $checked = array();

	public function get_schema(): array {
		return array(
			'pattern'   => '^[a-z][a-z0-9-]*$',
			'maxLength' => 20,
		);
	}

	public function check( mixed $value ): ?string {
		self::$checked[] = $value;

		return 'taken' === $value ? 'That slug is taken.' : null;
	}

	public function sanitize( mixed $value ): mixed {
		return $value . '!';
	}
}

/**
 * A rule on a class: checks the finished object.
 */
#[\Attribute( \Attribute::TARGET_CLASS )]
final class ValidationTestEndsAfterStart extends Rule {

	public function check( mixed $value ): ?string {
		return $value->ends > $value->starts ? null : 'The end must come after the start.';
	}
}

#[\Attribute( \Attribute::TARGET_PROPERTY )]
final class ValidationTestBadKeyword extends Rule {

	public function get_schema(): array {
		return array( 'type' => 'integer' );
	}
}

#[\Attribute( \Attribute::TARGET_PROPERTY )]
final class ValidationTestUncheckedUri extends Rule {

	public function get_schema(): array {
		return array( 'format' => 'uri' );
	}
}

final class ValidationTestGuest extends Structure {

	#[Length( min: 1 )]
	public string $name;

	#[Email]
	public ?string $email = null;
}

final class ValidationTestBooking extends Structure {

	#[Property( 'The workshop.' )]
	public int $workshop_id;

	#[Email]
	public string $email;

	#[ListOf( ValidationTestGuest::class ), Count( max: 2 )]
	public array $guests = array();

	#[Property]
	public ValidationTestStatus $status = ValidationTestStatus::Draft;

	#[Property]
	public ?\DateTimeImmutable $arrives = null;

	#[ListOf( 'string' ), Each( new Length( max: 5 ) ), Distinct]
	public array $tags = array();

	#[MapOf( 'int' ), Keys( new Pattern( '^[a-z]+$' ) ), Each( new Range( 0, 10 ) )]
	public array $scores = array();

	public string $undeclared = 'left alone';
}

final class ValidationTestReadonly extends Structure {

	#[Property]
	public readonly int $weight;

	#[Property]
	public string $country = 'GB';
}

final class ValidationTestUrlAndPick extends Structure {

	#[Url]
	public string $site = '';

	#[OneOf( 'a', 'b' )]
	public string $pick = 'a';
}

final class ValidationTestEmpty extends Structure {

	public string $name = '';
}

final class ValidationTestPlain {

	#[Property]
	public string $name = '';
}

final class ValidationTestHoldsPlain extends Structure {

	#[Property]
	public ValidationTestPlain $plain;
}

#[ValidationTestEndsAfterStart]
final class ValidationTestSpan extends Structure {

	#[Property]
	public int $starts;

	#[Property]
	public int $ends;
}

final class ValidationTestOwnRule extends Structure {

	#[ValidationTestSlug]
	public string $slug;

	#[Rule( check: array( self::class, 'is_even' ), sanitize: 'absint' )]
	public int $even = 2;

	#[Sanitize( 'strtoupper' ), Sanitize( 'trim' )]
	public string $code = '';

	public static function is_even( $value ): ?string {
		return 0 === (int) $value % 2 ? null : 'Must be even.';
	}
}

final class ValidationTestNested extends Structure {

	#[Property]
	public ValidationTestSpan $span;

	#[ListOf( ValidationTestOrder::class )]
	public array $orders = array();

	#[MapOf( 'mixed' )]
	public array $meta = array();

	#[Property]
	public object $extra;
}

/**
 * The Validation module: declare a class, get a schema, parse data into it.
 */
final class ValidationTest extends TestCase {

	private function validation(): Validation {
		return $this->plugin->get( Validation::class );
	}

	public function test_a_class_becomes_its_schema(): void {
		$schema = $this->validation()->get_schema( ValidationTestBooking::class );

		$this->assertSame( 'object', $schema['type'] );
		$this->assertSame( array( 'workshop_id', 'email' ), $schema['required'] );
		$this->assertArrayNotHasKey( 'undeclared', $schema['properties'] );

		$this->assertSame(
			array(
				'description' => 'The workshop.',
				'type'        => 'integer',
			),
			$schema['properties']['workshop_id']
		);
		$this->assertSame(
			array(
				'type'   => 'string',
				'format' => 'email',
			),
			$schema['properties']['email']
		);
		$this->assertSame(
			array(
				'type'    => 'string',
				'enum'    => array( 'draft', 'live' ),
				'default' => 'draft',
			),
			$schema['properties']['status']
		);
		$this->assertSame( array( 'string', 'null' ), $schema['properties']['arrives']['type'] );
		$this->assertSame( 'date-time', $schema['properties']['arrives']['format'] );

		$guests = $schema['properties']['guests'];
		$this->assertSame( 'array', $guests['type'] );
		$this->assertSame( 2, $guests['maxItems'] );
		$this->assertSame( array( 'name' ), $guests['items']['required'] );
		$this->assertSame( 1, $guests['items']['properties']['name']['minLength'] );

		$tags = $schema['properties']['tags'];
		$this->assertTrue( $tags['uniqueItems'] );
		$this->assertSame( 5, $tags['items']['maxLength'] );

		$scores = $schema['properties']['scores'];
		$this->assertSame( 'object', $scores['type'] );
		$this->assertFalse( $scores['additionalProperties'] );
		$this->assertSame(
			array(
				'type'    => 'integer',
				'minimum' => 0,
				'maximum' => 10,
			),
			$scores['patternProperties']['^[a-z]+$']
		);
	}

	public function test_overrides_are_stated_over_the_derived_schema(): void {
		$schema = $this->validation()->get_schema(
			ValidationTestBooking::class,
			array( 'properties' => array( 'email' => array( 'description' => 'Translated.' ) ) )
		);

		$this->assertSame( 'Translated.', $schema['properties']['email']['description'] );
		$this->assertSame( 'email', $schema['properties']['email']['format'] );
	}

	/**
	 * `array( 'properties' => $route->args() )` is the shape for a route with no
	 * args too, and an empty array replaces whole -- so it once dropped every
	 * property. A list keeps that meaning: an empty `enum` is still stated.
	 */
	public function test_an_empty_map_of_properties_changes_nothing(): void {
		$derived = $this->validation()->get_schema( ValidationTestBooking::class );

		$this->assertEquals( $derived, $this->validation()->get_schema( ValidationTestBooking::class, array( 'properties' => array() ) ) );
		$this->assertEquals(
			$derived['properties']['guests'],
			$this->validation()->get_schema( ValidationTestBooking::class, array( 'properties' => array( 'guests' => array( 'items' => array( 'properties' => array() ) ) ) ) )['properties']['guests']
		);
		$this->assertEquals( $derived, $this->validation()->get_schema( ValidationTestBooking::class, array( 'properties' => array( 'guests' => array() ) ) ) );

		$emptied = $this->validation()->get_schema( ValidationTestBooking::class, array( 'properties' => array( 'status' => array( 'enum' => array() ) ) ) );
		$this->assertSame( array(), $emptied['properties']['status']['enum'] );
	}

	public function test_parse_builds_the_object(): void {
		$booking = $this->validation()->parse(
			ValidationTestBooking::class,
			array(
				'workshop_id' => '42',
				'email'       => 'ada@example.com',
				'guests'      => array( array( 'name' => 'Grace' ) ),
				'status'      => 'live',
				'arrives'     => '2026-11-19T10:00:00Z',
				'tags'        => 'a,b',
				'scores'      => array( 'speed' => '7' ),
			)
		);

		$this->assertInstanceOf( ValidationTestBooking::class, $booking );
		$this->assertSame( 42, $booking->workshop_id );
		$this->assertInstanceOf( ValidationTestGuest::class, $booking->guests[0] );
		$this->assertSame( 'Grace', $booking->guests[0]->name );
		$this->assertNull( $booking->guests[0]->email );
		$this->assertSame( ValidationTestStatus::Live, $booking->status );
		$this->assertInstanceOf( \DateTimeImmutable::class, $booking->arrives );
		$this->assertSame( array( 'a', 'b' ), $booking->tags );
		$this->assertSame( array( 'speed' => 7 ), $booking->scores );
		$this->assertSame( 'left alone', $booking->undeclared );
	}

	public function test_every_problem_is_reported_by_path(): void {
		$errors = $this->validation()->validate(
			ValidationTestBooking::class,
			array(
				'workshop_id' => 'nope',
				'guests'      => array(
					array( 'name' => 'Ok' ),
					array(
						'name'  => '',
						'email' => 'not-an-email',
					),
				),
				'tags'        => array( 'fine', 'toolong' ),
				'scores'      => array(
					'speed' => 11,
					'Bad'   => 1,
				),
			)
		);

		$this->assertSame(
			array( 'workshop_id', 'email', 'guests[1].name', 'guests[1].email', 'tags[1]', 'scores.speed', 'scores.Bad' ),
			array_keys( $errors )
		);
		$this->assertSame( 'email is required.', $errors['email'] );
	}

	public function test_an_outer_rule_is_skipped_when_an_inner_level_failed(): void {
		$errors = $this->validation()->validate(
			ValidationTestBooking::class,
			array(
				'workshop_id' => 1,
				'email'       => 'a@example.com',
				'guests'      => array( array(), array(), array() ),
			)
		);

		$this->assertSame( array( 'guests[0].name', 'guests[1].name', 'guests[2].name' ), array_keys( $errors ) );
	}

	public function test_a_container_rule_runs_once_the_contents_passed(): void {
		$errors = $this->validation()->validate(
			ValidationTestBooking::class,
			array(
				'workshop_id' => 1,
				'email'       => 'a@example.com',
				'guests'      => array( array( 'name' => 'a' ), array( 'name' => 'b' ), array( 'name' => 'c' ) ),
			)
		);

		$this->assertSame( array( 'guests' ), array_keys( $errors ) );
	}

	/**
	 * PHP decodes `{ "0": "a" }` and `[ "a" ]` to the same array, so a map
	 * cannot refuse a list without refusing keys that are numbers. A map's key
	 * rules are where to narrow which keys it takes.
	 */
	public function test_a_map_takes_keys_that_are_numbers(): void {
		$target = new class() {
			#[MapOf( 'string' )]
			public array $labels = array();
		};

		$built = $this->validation()->resolve( $target, array( 'labels' => array( 0 => 'zero', 1 => 'one' ) ) );

		$this->assertIsArray( $built );
		$this->assertSame( array( 0 => 'zero', 1 => 'one' ), $built['labels'] );

		$base = array(
			'workshop_id' => 1,
			'email'       => 'a@example.com',
		);

		$this->assertArrayHasKey( "scores['0']", $this->validation()->validate( ValidationTestBooking::class, $base + array( 'scores' => array( 1, 2 ) ) ), 'Its key rule still refuses them.' );
		$this->assertSame( array(), $this->validation()->validate( ValidationTestBooking::class, $base + array( 'scores' => array() ) ) );
	}

	/**
	 * Otherwise the later entry silently replaced the earlier one.
	 */
	public function test_two_keys_that_clean_to_one_are_refused(): void {
		$target = new class() {
			#[MapOf( 'int' ), Keys( new Sanitize( 'strtolower' ) )]
			public array $counts = array();
		};

		$refused = $this->validation()->resolve(
			$target,
			array(
				'counts' => array(
					'A' => 1,
					'a' => 2,
				),
			)
		);

		$this->assertWPError( $refused );
		$this->assertSame( array( 'counts.a' ), array_keys( $refused->get_error_data()['errors'] ) );
	}

	/**
	 * A default is published as JSON, and an empty PHP array is `[]` there --
	 * which the map's own `type: object` refuses.
	 */
	public function test_an_empty_map_default_is_published_as_an_object(): void {
		$target = new class() {
			#[MapOf( 'int' )]
			public array $counts = array();

			#[ListOf( 'int' )]
			public array $ids = array();
		};

		$properties = $this->validation()->get_schema( $target )['properties'];

		$this->assertSame( '{}', wp_json_encode( $properties['counts']['default'] ) );
		$this->assertSame( '[]', wp_json_encode( $properties['ids']['default'] ) );
	}

	/**
	 * A message that already names where it is is left alone, and only that:
	 * `id` appearing inside "valid" is not the message naming `id`.
	 */
	public function test_a_refusal_names_the_path_unless_the_message_starts_with_it(): void {
		$refusal = $this->validation()->get_refusal(
			array(
				'id'   => 'This value is not valid.',
				'name' => 'name is required.',
			),
			'validation_failed'
		);

		$this->assertSame( 'id: This value is not valid. name is required.', $refusal->get_error_message() );
	}

	/**
	 * Nothing to check against, so every value would pass -- a forgotten
	 * `#[Property]`, not a class that accepts anything.
	 */
	public function test_parsing_against_a_class_that_declares_nothing_throws(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'declares no properties' );

		$this->validation()->parse( ValidationTestEmpty::class, array( 'name' => 'x' ) );
	}

	public function test_a_rule_of_your_own_checks_the_raw_value_and_sanitizes_in_order(): void {
		ValidationTestSlug::$checked = array();

		$built = $this->validation()->parse(
			ValidationTestOwnRule::class,
			array(
				'slug' => 'acme',
				'even' => '4',
				'code' => ' ab ',
			)
		);

		$this->assertSame( array( 'acme' ), ValidationTestSlug::$checked );
		$this->assertSame( 'acme!', $built->slug );
		$this->assertSame( 4, $built->even );
		$this->assertSame( 'AB', $built->code );

		$errors = $this->validation()->validate(
			ValidationTestOwnRule::class,
			array(
				'slug' => 'taken',
				'even' => 3,
			)
		);

		$this->assertSame(
			array(
				'slug' => 'That slug is taken.',
				'even' => 'Must be even.',
			),
			$errors
		);
	}

	public function test_a_rule_check_is_not_run_when_the_schema_already_refused(): void {
		ValidationTestSlug::$checked = array();

		$errors = $this->validation()->validate( ValidationTestOwnRule::class, array( 'slug' => 'Not A Slug' ) );

		$this->assertArrayHasKey( 'slug', $errors );
		$this->assertSame( array(), ValidationTestSlug::$checked );
	}

	public function test_a_class_rule_checks_the_finished_object(): void {
		$errors = $this->validation()->validate(
			ValidationTestNested::class,
			array(
				'span'  => array(
					'starts' => 5,
					'ends'   => 1,
				),
				'extra' => array(),
			)
		);

		$this->assertSame( array( 'span' => 'The end must come after the start.' ), $errors );
	}

	public function test_a_structure_is_built_by_validation_only(): void {
		$class_name = ValidationTestBooking::class;

		$this->expectException( \Error::class );
		$this->expectExceptionMessage( 'private' );

		new $class_name();
	}

	/**
	 * @dataProvider provide_classes_that_are_not_structures
	 *
	 * @param string $class_name The class.
	 * @param string $message    What the refusal says.
	 */
	public function test_building_a_class_that_is_not_a_structure_throws( string $class_name, string $message ): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( $message );

		$this->validation()->parse( $class_name, array() );
	}

	/**
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function provide_classes_that_are_not_structures(): array {
		return array(
			'parsed'             => array( ValidationTestPlain::class, 'ValidationTestPlain is not a Structure' ),
			'a property\'s type' => array( ValidationTestHoldsPlain::class, 'typed Zestry\WPToolkit\Tests\Integration\Modules\ValidationTestPlain, which is neither' ),
		);
	}

	public function test_readonly_properties_are_built_with_their_defaults(): void {
		$parcel = $this->validation()->parse( ValidationTestReadonly::class, array( 'weight' => 3 ) );

		$this->assertSame( 3, $parcel->weight );
		$this->assertSame( 'GB', $parcel->country );
		$this->assertSame( array( 'weight' ), $this->validation()->get_schema( ValidationTestReadonly::class )['required'] );
	}

	public function test_enums_mixed_and_open_objects(): void {
		$nested = $this->validation()->parse(
			ValidationTestNested::class,
			array(
				'span'   => array(
					'starts' => 1,
					'ends'   => 2,
				),
				'orders' => array( 'Descending' ),
				'meta'   => array(
					'a' => 1,
					'b' => array( 'x' ),
				),
				'extra'  => array( 'k' => 'v' ),
			)
		);

		$this->assertSame( array( ValidationTestOrder::Descending ), $nested->orders );
		$this->assertSame(
			array(
				'a' => 1,
				'b' => array( 'x' ),
			),
			$nested->meta
		);
		$this->assertInstanceOf( \stdClass::class, $nested->extra );
		$this->assertSame( 'v', $nested->extra->k );
	}

	public function test_parse_refuses_with_every_error_in_the_data(): void {
		$refusal = $this->validation()->parse( ValidationTestBooking::class, array( 'workshop_id' => 1 ) );

		$this->assertWPError( $refusal );
		$this->assertSame( 'validation_failed', $refusal->get_error_code() );
		$this->assertSame( array( 'email' => 'email is required.' ), $refusal->get_error_data()['errors'] );
		$this->assertSame( array( 'email' ), $refusal->get_error_data()['params'] );
	}

	public function test_to_array_gives_back_what_would_build_it(): void {
		$data = array(
			'workshop_id' => 7,
			'email'       => 'a@example.com',
			'guests'      => array( array( 'name' => 'G' ) ),
			'status'      => 'live',
			'arrives'     => '2026-11-19T10:00:00+00:00',
			'tags'        => array(),
			'scores'      => array(),
		);

		$array = $this->validation()->to_array( $this->validation()->parse( ValidationTestBooking::class, $data ) );

		$this->assertSame( 'live', $array['status'] );
		$this->assertSame( '2026-11-19T10:00:00+00:00', $array['arrives'] );
		$this->assertSame(
			array(
				'name'  => 'G',
				'email' => null,
			),
			$array['guests'][0]
		);
	}

	public function test_bind_and_reset_restore_a_reused_object(): void {
		$target = new class() {
			#[Property]
			public int $id;

			#[Property]
			public ?string $note = null;
		};

		$validation = $this->validation();
		$validation->bind(
			$target,
			array(
				'id'   => 1,
				'note' => 'first',
			)
		);
		$this->assertSame( 'first', $target->note );

		$validation->bind( $target, array( 'id' => 2 ) );
		$this->assertNull( $target->note );

		$validation->bind( $target, array() );
		$this->assertFalse( ( new \ReflectionProperty( $target, 'id' ) )->isInitialized( $target ) );
	}

	public function test_resolve_refuses_a_target_carrying_a_class_rule(): void {
		$this->expectException( \InvalidArgumentException::class );

		$this->validation()->resolve(
			new #[ValidationTestEndsAfterStart] class() {
				#[Property]
				public int $starts;

				#[Property]
				public int $ends;
			},
			array()
		);
	}

	/**
	 * @dataProvider provide_bad_declarations
	 */
	public function test_a_declaration_that_cannot_be_described_throws( object $target, string $message ): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( $message );

		$this->validation()->get_schema( $target );
	}

	public static function provide_bad_declarations(): array {
		return array(
			'array without ListOf or MapOf' => array(
				new class() {
					#[Property]
					public array $items = array();
				},
				'does not say what it holds',
			),
			'ListOf on a string'            => array(
				new class() {
					#[ListOf( 'string' )]
					public string $items = '';
				},
				'describes nothing',
			),
			'Keys on a list'                => array(
				new class() {
					#[ListOf( 'string' ), Keys( new Length( max: 3 ) )]
					public array $items = array();
				},
				'Keys without MapOf',
			),
			'a shape keyword in a rule'     => array(
				new class() {
					#[ValidationTestBadKeyword]
					public int $count = 0;
				},
				'"type"',
			),
			'uri without a check'           => array(
				new class() {
					#[ValidationTestUncheckedUri]
					public string $link = '';
				},
				'"uri"',
			),
			'a union type'                  => array(
				new class() {
					#[Property]
					public int|string $id = 0;
				},
				'single declared type',
			),
			// Merged, the later one replaced the earlier: Slug's 20 was lost to 100.
			'two rules stating one keyword' => array(
				new class() {
					#[ValidationTestSlug, Length( max: 100 )]
					public string $handle = '';
				},
				'both state "maxLength"',
			),
			'the same, on each item'        => array(
				new class() {
					#[ListOf( 'string' ), Each( new Pattern( '^a' ), new ValidationTestSlug() )]
					public array $handles = array();
				},
				'both state "pattern"',
			),
			// WordPress checks maxLength on strings only, so it was published and skipped.
			'a string rule on an int'       => array(
				new class() {
					#[Length( max: 3 )]
					public int $code = 0;
				},
				'"maxLength", which WordPress checks only on string values, so on an int',
			),
			'a number rule on a string'     => array(
				new class() {
					#[Range( min: 1 )]
					public string $qty = '';
				},
				'"minimum", which WordPress checks only on number values',
			),
			'a format on a bool'            => array(
				new class() {
					#[Email]
					public bool $flag = false;
				},
				'"format", which WordPress checks only on string values',
			),
			'a list rule on a map'          => array(
				new class() {
					#[MapOf( 'int' ), Distinct]
					public array $scores = array();
				},
				'"uniqueItems", which WordPress checks only on array values, so on a map',
			),
			'a count on uploads'            => array(
				new class() {
					#[ListOf( \Zestry\WPToolkit\Modules\Request\UploadedFile::class ), Count( max: 3 )]
					public array $pages = array();
				},
				'so on an upload',
			),
			'a string rule on each int'     => array(
				new class() {
					#[ListOf( 'int' ), Each( new Length( max: 3 ) )]
					public array $codes = array();
				},
				'"maxLength", which WordPress checks only on string values',
			),
			// The type already set the format; the rule replaced it.
			'a format on a date'            => array(
				new class() {
					#[Email]
					public \DateTimeImmutable $at;
				},
				'already makes it "date-time"',
			),
			'a value that is no case'       => array(
				new class() {
					#[OneOf( 'draft', 'archived' )]
					public ValidationTestStatus $status = ValidationTestStatus::Draft;
				},
				'allows "archived", which is not a case of',
			),
		);
	}

	/**
	 * Any case is accepted and lower-cased; a named version refuses the others.
	 * WordPress's own `format: uuid` refused upper case and could pin only v4.
	 */
	public function test_uuid_takes_any_case_and_can_pin_a_version(): void {
		$target = new class() {
			#[Uuid]
			public string $any = '';

			#[Uuid( version: 7 )]
			public string $v7 = '';
		};

		$v4 = '550E8400-E29B-41D4-A716-446655440000';
		$v7 = '01890A5D-AC96-774B-BCCE-B302099A8057';

		$built = $this->validation()->resolve( $target, array( 'any' => $v4, 'v7' => $v7 ) );

		$this->assertIsArray( $built );
		$this->assertSame( \strtolower( $v4 ), $built['any'] );
		$this->assertSame( \strtolower( $v7 ), $built['v7'] );

		$refused = $this->validation()->resolve( $target, array( 'v7' => $v4 ) );

		$this->assertWPError( $refused );
		$this->assertSame( array( 'v7' ), \array_keys( $refused->get_error_data()['errors'] ) );
	}

	/**
	 * Judged on what was sent, so trimming afterwards cannot empty a value that
	 * passed. It states no keyword, so it sits beside a Pattern and a Length.
	 */
	public function test_not_blank_refuses_only_spaces_before_trimming(): void {
		$target = new class() {
			#[NotBlank, Pattern( '^[A-Za-z ]+$' ), Length( max: 20 ), Sanitize( 'trim' )]
			public string $name = '';

			#[NotBlank]
			public ?string $nickname = null;
		};

		$built = $this->validation()->resolve(
			$target,
			array(
				'name'     => '  Ada  ',
				'nickname' => null,
			)
		);

		$this->assertIsArray( $built );
		$this->assertSame( 'Ada', $built['name'] );
		$this->assertNull( $built['nickname'], 'Null is the type\'s business, not blankness.' );

		foreach ( array( '', '   ', "\u{00A0}\t" ) as $blank ) {
			$refused = $this->validation()->resolve( $target, array( 'nickname' => $blank ) );

			$this->assertWPError( $refused );
			$this->assertSame(
				array( 'nickname' => 'This must not be blank.' ),
				$refused->get_error_data()['errors'],
				\sprintf( '%s should be blank.', \wp_json_encode( $blank ) )
			);
		}
	}

	public function test_uuid_refuses_a_version_no_uuid_has(): void {
		$this->expectException( \InvalidArgumentException::class );

		new Uuid( version: 9 );
	}

	/**
	 * What the guard above must leave alone: a count on a map is a count of
	 * its entries, a key is a string whatever the map holds, and a rule may
	 * narrow an enum to some of its cases.
	 */
	public function test_rules_that_fit_their_value_are_published(): void {
		$target = new class() {
			#[MapOf( 'int' ), Count( max: 5 ), Keys( new Length( max: 10 ) )]
			public array $scores = array();

			#[OneOf( 'draft' )]
			public ValidationTestStatus $status = ValidationTestStatus::Draft;

			#[Length( max: 40 )]
			public \DateTimeImmutable $at;
		};

		$properties = $this->validation()->get_schema( $target )['properties'];

		$this->assertSame( 5, $properties['scores']['maxProperties'] );
		$this->assertSame( array( 'draft' ), $properties['status']['enum'] );
		$this->assertSame( 'date-time', $properties['at']['format'] );
	}

	/**
	 * Rules stating different keywords all apply, which is the ordinary case
	 * the guard above must leave alone.
	 */
	public function test_rules_stating_different_keywords_combine(): void {
		$target = new class() {
			#[Property, Length( min: 2 ), Pattern( '^[a-z]+$' )]
			public string $code = '';
		};

		$schema = $this->validation()->get_schema( $target )['properties']['code'];

		$this->assertSame( 2, $schema['minLength'] );
		$this->assertSame( '^[a-z]+$', $schema['pattern'] );
	}

	public function test_the_url_rule_checks_what_wordpress_only_cleans(): void {
		$errors = $this->validation()->validate(
			ValidationTestUrlAndPick::class,
			array(
				'site' => 'javascript:alert(1)',
				'pick' => 'c',
			)
		);

		$this->assertSame( array( 'site', 'pick' ), array_keys( $errors ) );
		$this->assertSame( array(), $this->validation()->validate( ValidationTestUrlAndPick::class, array( 'site' => 'https://example.com/x' ) ) );
	}
}
