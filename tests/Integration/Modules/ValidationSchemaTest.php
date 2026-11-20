<?php

declare( strict_types=1 );

namespace Zestry\WPToolkit\Tests\Integration\Modules;

use Zestry\WPToolkit\Modules\Request\Request;
use Zestry\WPToolkit\Modules\Request\UploadedFile;
use Zestry\WPToolkit\Modules\Validation\ListOf;
use Zestry\WPToolkit\Modules\Validation\MapOf;
use Zestry\WPToolkit\Modules\Validation\Property;
use Zestry\WPToolkit\Modules\Validation\Structure;
use Zestry\WPToolkit\Modules\Validation\Rule;
use Zestry\WPToolkit\Modules\Validation\Rules\OneOf;
use Zestry\WPToolkit\Modules\Validation\Rules\Pattern;
use Zestry\WPToolkit\Modules\Validation\Rules\Sanitize;
use Zestry\WPToolkit\Modules\Validation\Validation;
use Zestry\WPToolkit\Tests\Support\TestCase;

/**
 * Arguments shared by more than one route or ability.
 */
trait ValidationSchemaPaged {

	#[Property( 'Which page.' )]
	public int $page = 1;
}

/**
 * A backed enum an argument can be declared as.
 */
enum ValidationSchemaStatus: string {
	case Draft = 'draft';
	case Live  = 'live';
}

/**
 * An int-backed one, to prove the backing type reaches the schema.
 */
enum ValidationSchemaPriority: int {
	case Low  = 1;
	case High = 2;
}

/**
 * A pure enum, which has no value to send.
 */
enum ValidationSchemaOrder {
	case Ascending;
	case Descending;
}

/**
 * A structure with a readonly property, which can have no default.
 */
final class ValidationSchemaParcel extends Structure {

	#[Property( 'How heavy, in grams.' )]
	public readonly int $weight;

	#[Property( 'Where it is going.' )]
	public string $destination = 'GB';
}

/**
 * A structure an argument can be declared as.
 */
final class ValidationSchemaAddress extends Structure {

	#[Property( 'Street and number.' )]
	public string $line1;

	#[Property( 'Two-letter country code.' ), Pattern( '^[A-Z]{2}$' )]
	public string $country = 'US';
}

/**
 * The declaration side, exercised without a route or an ability around it.
 *
 * @covers \Zestry\WPToolkit\Modules\Validation\Validation
 * @covers \Zestry\WPToolkit\Modules\Request\Request
 */
final class ValidationSchemaTest extends TestCase {

	private function request(): Request {
		return $this->plugin->get( Request::class );
	}

	private function validation(): Validation {
		return $this->plugin->get( Validation::class );
	}

	/**
	 * What a route, an ability or an action does with a call: check and build
	 * the values, then put them on the object.
	 *
	 * @param object               $target The object declaring the properties.
	 * @param array<string, mixed> $values The values, as they arrived.
	 */
	private function fill( object $target, array $values ): void {
		$built = $this->validation()->resolve( $target, $values );

		$this->assertNotInstanceOf( \WP_Error::class, $built, $built instanceof \WP_Error ? $built->get_error_message() : '' );

		$this->validation()->bind( $target, $built );
	}

	public function test_the_property_states_the_type_and_whether_it_is_required(): void {
		$schema = $this->validation()->get_schema(
			new class() {
				#[Property( 'Which one.' )]
				public int $id;

				#[Property( 'Whether to notify.' )]
				public bool $notify = true;

				#[Property( 'A rating, if given.' )]
				public ?float $rating = null;

				// No attribute: not an argument, and not in the schema.
				public string $ignored = 'x';
			}
		);

		$this->assertSame( 'integer', $schema['properties']['id']['type'] );
		$this->assertSame( 'boolean', $schema['properties']['notify']['type'] );
		$this->assertSame( array( 'number', 'null' ), $schema['properties']['rating']['type'], 'A nullable type accepts null too.' );
		$this->assertArrayNotHasKey( 'ignored', $schema['properties'] );

		$this->assertSame( array( 'id' ), $schema['required'], 'Only the property with no default.' );
		$this->assertTrue( $schema['properties']['notify']['default'], 'A default is published for the caller.' );
	}

	public function test_a_rule_states_its_keywords_beside_the_derived_type(): void {
		$schema = $this->validation()->get_schema(
			new class() {
				#[Property( 'How to sort.' ), OneOf( 'date', 'title' )]
				public string $order_by = 'date';

				// An associative payload is a JSON object, which PHP's `array`
				// cannot distinguish from a list -- so it is declared as a map.
				#[MapOf( 'mixed' )]
				public array $extra = array();
			}
		);

		$this->assertSame( array( 'date', 'title' ), $schema['properties']['order_by']['enum'] );
		$this->assertSame( 'string', $schema['properties']['order_by']['type'], 'The derived type survives alongside it.' );
		$this->assertSame( 'object', $schema['properties']['extra']['type'] );
	}

	public function test_a_class_typed_argument_becomes_a_nested_object(): void {
		$schema = $this->validation()->get_schema(
			new class() {
				#[Property( 'Where to ship it.' )]
				public ValidationSchemaAddress $address;
			}
		);

		$address = $schema['properties']['address'];

		$this->assertSame( 'object', $address['type'] );
		$this->assertSame( 'Where to ship it.', $address['description'] );
		$this->assertSame( 'string', $address['properties']['line1']['type'] );
		$this->assertSame( '^[A-Z]{2}$', $address['properties']['country']['pattern'] );
		$this->assertSame( array( 'line1' ), $address['required'], 'The nested rules are the same rules.' );
	}

	public function test_a_list_of_structures_names_its_class(): void {
		$schema = $this->validation()->get_schema(
			new class() {
				#[Property( 'Where to ship each parcel.' ), ListOf( ValidationSchemaAddress::class )]
				public array $addresses;
			}
		);

		$this->assertSame( 'array', $schema['properties']['addresses']['type'] );
		$this->assertSame( 'object', $schema['properties']['addresses']['items']['type'] );
		$this->assertSame( 'string', $schema['properties']['addresses']['items']['properties']['line1']['type'] );
	}

	public function test_a_backed_enum_argument_publishes_its_values(): void {
		$schema = $this->validation()->get_schema(
			new class() {
				#[Property( 'What state it is in.' )]
				public ValidationSchemaStatus $status;

				#[Property( 'How urgent, if stated.' )]
				public ?ValidationSchemaPriority $priority = null;
			}
		);

		$this->assertSame( 'string', $schema['properties']['status']['type'] );
		$this->assertSame( array( 'draft', 'live' ), $schema['properties']['status']['enum'] );

		// A nullable closed set has to admit null on both counts, or the value
		// its own default declares would be rejected.
		$this->assertSame( array( 'integer', 'null' ), $schema['properties']['priority']['type'] );
		$this->assertSame( array( 1, 2, null ), $schema['properties']['priority']['enum'] );
	}

	/**
	 * A pure enum has no value to send, so its case names stand in -- and the
	 * schema names them, so a caller never has to know which kind it is.
	 */
	public function test_a_pure_enum_argument_publishes_its_case_names(): void {
		$schema = $this->validation()->get_schema(
			new class() {
				#[Property( 'Which way round.' )]
				public ValidationSchemaOrder $order;
			}
		);

		$this->assertSame( 'string', $schema['properties']['order']['type'] );
		$this->assertSame( array( 'Ascending', 'Descending' ), $schema['properties']['order']['enum'] );
	}

	public function test_an_enum_argument_arrives_as_a_case(): void {
		$target = new class() {
			#[Property( 'What state it is in.' )]
			public ValidationSchemaStatus $status;

			#[Property( 'Which way round.' )]
			public ValidationSchemaOrder $order;

			#[Property( 'Every state to include.' ), ListOf( ValidationSchemaStatus::class )]
			public array $states = array();
		};

		$this->fill(
			$target,
			array(
				'status' => 'live',
				'order'  => 'Descending',
				'states' => array( 'draft', 'live' ),
			)
		);

		$this->assertSame( ValidationSchemaStatus::Live, $target->status );
		$this->assertSame( ValidationSchemaOrder::Descending, $target->order, 'A pure enum comes back by case name.' );
		$this->assertSame( array( ValidationSchemaStatus::Draft, ValidationSchemaStatus::Live ), $target->states );
	}

	/**
	 * The schema lists every case, so a value naming none is refused where it is
	 * sent. Only with the check switched off -- the platform vouching for it --
	 * does it reach the property, which will not take it.
	 */
	public function test_a_value_naming_no_case_is_refused(): void {
		$target = new class() {
			#[Property( 'What state it is in.' )]
			public ValidationSchemaStatus $status;
		};

		$refused = $this->validation()->resolve( $target, array( 'status' => 'archived' ), 'invalid_input' );

		$this->assertInstanceOf( \WP_Error::class, $refused );
		$this->assertSame( 'invalid_input', $refused->get_error_code() );
		$this->assertArrayHasKey( 'status', $refused->get_error_data()['errors'] );

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'status' );

		$this->validation()->bind( $target, $this->validation()->resolve( $target, array( 'status' => 'archived' ), 'invalid_input', array(), false ) );
	}

	/**
	 * Every shape an argument may be declared as, in one place, so a gap in the
	 * matrix is visible rather than discovered.
	 *
	 * @dataProvider declarable_types
	 */
	public function test_each_declarable_type_derives_a_usable_schema( string $declaration, array $expected ): void {
		$file = $this->write_plugin_file(
			'target.php',
			"<?php\nuse Zestry\\WPToolkit\\Modules\\Validation\\ListOf;\nuse Zestry\\WPToolkit\\Modules\\Validation\\Property;\n"
				. "use Zestry\\WPToolkit\\Tests\\Integration\\Modules\\ValidationSchemaAddress;\n"
				. "use Zestry\\WPToolkit\\Tests\\Integration\\Modules\\ValidationSchemaStatus;\n"
				. "return new class() {\n{$declaration}\n};\n"
		);

		$schema = $this->validation()->get_schema( require $file );

		foreach ( $expected as $key => $value ) {
			$this->assertSame( $value, $schema['properties']['subject'][ $key ], sprintf( 'The %s of %s.', $key, $declaration ) );
		}
	}

	/**
	 * @return array<string, array{string, array<string, mixed>}>
	 */
	public function declarable_types(): array {
		return array(
			'int'               => array( '#[Property] public int $subject;', array( 'type' => 'integer' ) ),
			'float'             => array( '#[Property] public float $subject;', array( 'type' => 'number' ) ),
			'string'            => array( '#[Property] public string $subject;', array( 'type' => 'string' ) ),
			'bool'              => array( '#[Property] public bool $subject;', array( 'type' => 'boolean' ) ),
			'nullable int'      => array( '#[Property] public ?int $subject = null;', array( 'type' => array( 'integer', 'null' ) ) ),
			'array of ints'     => array(
				"#[ListOf( 'int' )] public array \$subject;",
				array( 'type' => 'array', 'items' => array( 'type' => 'integer' ) ),
			),
			'array of objects'  => array(
				'#[ListOf( ValidationSchemaAddress::class )] public array $subject;',
				array( 'type' => 'array' ),
			),
			'array of enums'    => array(
				'#[ListOf( ValidationSchemaStatus::class )] public array $subject;',
				array( 'type' => 'array', 'items' => array( 'type' => 'string', 'enum' => array( 'draft', 'live' ) ) ),
			),
			'object'            => array( '#[Property] public ValidationSchemaAddress $subject;', array( 'type' => 'object' ) ),
			'nullable object'   => array(
				'#[Property] public ?ValidationSchemaAddress $subject = null;',
				array( 'type' => array( 'object', 'null' ) ),
			),
			'enum'              => array(
				'#[Property] public ValidationSchemaStatus $subject;',
				array( 'type' => 'string', 'enum' => array( 'draft', 'live' ) ),
			),
			'date'              => array(
				'#[Property] public \DateTimeImmutable $subject;',
				array( 'type' => 'string', 'format' => 'date-time' ),
			),
			'nullable date'     => array(
				'#[Property] public ?\DateTimeInterface $subject = null;',
				array( 'type' => array( 'string', 'null' ), 'format' => 'date-time' ),
			),
			'described'         => array(
				"#[Property( 'Which one.' )] public int \$subject;",
				array( 'type' => 'integer', 'description' => 'Which one.' ),
			),
		);
	}

	/**
	 * Some arguments are genuinely open: a settings blob, a third party's
	 * payload. `object` and `stdClass` both say so, and JSON Schema has a word
	 * for exactly that.
	 */
	public function test_a_free_form_object_argument_is_described_and_bound(): void {
		$target = new class() {
			#[Property( 'Whatever the client keeps here.' )]
			public \stdClass $meta;

			#[Property( 'Anything at all.' )]
			public object $extra;

			#[Property( 'Several of them.' ), ListOf( \stdClass::class )]
			public array $blobs = array();
		};

		$schema = $this->validation()->get_schema( $target );

		$this->assertSame( 'object', $schema['properties']['meta']['type'] );
		$this->assertSame( 'object', $schema['properties']['extra']['type'] );
		$this->assertSame( 'object', $schema['properties']['blobs']['items']['type'] );
		$this->assertArrayNotHasKey( 'properties', $schema['properties']['meta'], 'Open means open: no keys are demanded.' );

		$this->fill(
			$target,
			array(
				'meta'  => array( 'colour' => 'red', 'tags' => array( 'a', 'b' ), 'nested' => array( 'deep' => 1 ) ),
				'extra' => array( 'anything' => true ),
				'blobs' => array( array( 'one' => 1 ), array( 'two' => 2 ) ),
			)
		);

		$this->assertSame( 'red', $target->meta->colour );
		$this->assertSame( array( 'a', 'b' ), $target->meta->tags, 'A list stays a list.' );
		$this->assertSame( 1, $target->meta->nested->deep, 'And an object nested inside it is an object.' );
		$this->assertTrue( $target->extra->anything );
		$this->assertSame( 2, $target->blobs[1]->two );
	}

	/**
	 * `{}` is how a caller says "an object with nothing in it". WordPress decodes
	 * a JSON body to associative arrays, so `{}` and `[]` both arrive as `array()`
	 * and the distinction is gone before binding sees the value -- but the
	 * declared type still says which one was meant.
	 */
	public function test_an_empty_object_binds_where_an_object_is_declared(): void {
		$target = new class() {
			#[Property( 'Whatever the client keeps here.' )]
			public \stdClass $meta;

			#[Property( 'Anything at all.' )]
			public object $extra;

			#[Property( 'Several of them.' ), ListOf( \stdClass::class )]
			public array $blobs = array();
		};

		$this->fill(
			$target,
			array(
				'meta'  => array(),
				'extra' => array(),
				'blobs' => array( array(), array( 'two' => 2 ) ),
			)
		);

		$this->assertInstanceOf( \stdClass::class, $target->meta, 'An empty object is still an object.' );
		$this->assertSame( array(), \get_object_vars( $target->meta ), 'And it has nothing in it.' );
		$this->assertInstanceOf( \stdClass::class, $target->extra );
		$this->assertInstanceOf( \stdClass::class, $target->blobs[0], 'Including one inside a list of them.' );
		$this->assertInstanceOf( \stdClass::class, $target->blobs[1] );
		$this->assertSame( 2, $target->blobs[1]->two );
	}

	/**
	 * The empty object above must not cost the empty list: where the declared
	 * type is `array`, nothing casts, and a nested list keeps its positions.
	 */
	public function test_an_empty_list_stays_a_list_where_an_array_is_declared(): void {
		$target = new class() {
			#[Property( 'Plain values.' ), ListOf( 'string' )]
			public array $tags = array( 'untouched' );

			#[Property( 'Whatever the client keeps here.' )]
			public \stdClass $meta;
		};

		$this->fill(
			$target,
			array(
				'tags' => array(),
				'meta' => array( 'empty' => array(), 'list' => array() ),
			)
		);

		$this->assertSame( array(), $target->tags, 'An array argument is handed its array.' );
		$this->assertSame(
			array(),
			$target->meta->list,
			'A value nested under an open object declares nothing, so an empty array stays one.'
		);
	}

	/**
	 * An open object still takes a schema of its own, for the parts you do know:
	 * stated as an override, since a rule may not describe a shape.
	 */
	public function test_a_free_form_object_can_still_be_narrowed(): void {
		$schema = $this->validation()->get_schema(
			new class() {
				#[Property( 'Settings.' )]
				public \stdClass $settings;
			},
			array( 'properties' => array( 'settings' => array( 'properties' => array( 'theme' => array( 'type' => 'string' ) ) ) ) )
		);

		$this->assertSame( 'object', $schema['properties']['settings']['type'] );
		$this->assertSame( 'string', $schema['properties']['settings']['properties']['theme']['type'] );
	}

	/**
	 * A description is optional, and an argument without one publishes no empty
	 * key for a caller to read as "described, but as nothing".
	 */
	public function test_an_argument_without_a_description_publishes_none(): void {
		$schema = $this->validation()->get_schema(
			new class() {
				#[Property]
				public int $id;
			}
		);

		$this->assertArrayNotHasKey( 'description', $schema['properties']['id'] );
	}

	public function test_a_date_argument_arrives_as_a_date(): void {
		$target = new class() {
			#[Property( 'When it happened.' )]
			public \DateTimeImmutable $when;

			#[Property( 'When it is due, if ever.' )]
			public ?\DateTimeInterface $due = null;
		};

		$this->fill( $target, array( 'when' => '2026-08-04T12:00:00Z', 'due' => null ) );

		$this->assertSame( '2026-08-04', $target->when->format( 'Y-m-d' ) );
		$this->assertNull( $target->due );
	}

	/**
	 * A readonly property is filled once, as it is built.
	 */
	public function test_a_readonly_structure_is_described_and_built(): void {
		$schema = $this->validation()->get_schema(
			new class() {
				#[Property( 'What is being shipped.' )]
				public ValidationSchemaParcel $parcel;
			}
		);

		$parcel = $schema['properties']['parcel'];

		$this->assertSame( array( 'weight' ), $parcel['required'], 'A default makes its argument optional.' );
		$this->assertSame( 'GB', $parcel['properties']['destination']['default'] );
	}

	public function test_a_default_is_applied_when_the_caller_omits_it(): void {
		$target = new class() {
			#[Property( 'What is being shipped.' )]
			public ValidationSchemaParcel $parcel;
		};

		$this->fill( $target, array( 'parcel' => array( 'weight' => 500 ) ) );

		$this->assertSame( 500, $target->parcel->weight );
		$this->assertSame( 'GB', $target->parcel->destination );
	}

	/**
	 * A class with nothing to recurse into cannot be published as a shape --
	 * unlike `stdClass`, which says it has no shape on purpose.
	 */
	public function test_a_class_that_describes_nothing_throws(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'typed ArrayObject, which is neither string, int, float, bool, an enum, a date, nor a Structure' );

		$this->validation()->get_schema(
			new class() {
				#[Property( 'Anything.' )]
				public \ArrayObject $thing;
			}
		);
	}

	/**
	 * @dataProvider undescribable_types
	 */
	public function test_a_type_that_cannot_be_described_throws( string $declaration ): void {
		$file = $this->write_plugin_file(
			'undescribable.php',
			"<?php\nuse Zestry\\WPToolkit\\Modules\\Validation\\ListOf;\nuse Zestry\\WPToolkit\\Modules\\Validation\\Property;\n"
				. "return new class() {\n{$declaration}\n};\n"
		);

		$this->expectException( \InvalidArgumentException::class );

		$this->validation()->get_schema( require $file );
	}

	/**
	 * @return array<string, array{string}>
	 */
	public function undescribable_types(): array {
		return array(
			'untyped'      => array( '#[Property] public $subject;' ),
			'union'        => array( '#[Property] public int|string $subject;' ),
			'mixed'        => array( '#[Property] public mixed $subject;' ),
			'iterable'     => array( '#[Property] public iterable $subject;' ),
			'unsaid array' => array( '#[Property] public array $subject;' ),
		);
	}

	/**
	 * An upload arrives as multipart/form-data, which JSON Schema has no type
	 * for -- so a schema leaves it out, and it is listed apart, for the one
	 * platform that can read it.
	 */
	public function test_a_file_argument_is_left_out_of_a_schema(): void {
		$target = new class() {
			#[Property( 'The image to attach.' )]
			public UploadedFile $image;

			#[Property( 'What to call it.' )]
			public string $title = '';
		};

		$schema = $this->validation()->get_schema( $target );

		$this->assertSame( array( 'title' ), array_keys( $schema['properties'] ) );
		$this->assertArrayNotHasKey( 'required', $schema, 'A file cannot be required of a caller who cannot send one in JSON.' );
		$this->assertSame( array( 'image' => true ), $this->validation()->get_file_properties( $target ) );
	}

	/**
	 * WordPress leaves uploads out of a request's parameters, so an arg declared
	 * for one would be a parameter that is always missing -- and `required` would
	 * then reject every request that carried the file correctly.
	 */
	public function test_a_file_argument_is_absent_from_a_routes_args(): void {
		$target = new class() {
			#[Property( 'The image to attach.' )]
			public UploadedFile $image;

			#[Property( 'What to call it.' )]
			public string $title = '';
		};

		$args = $this->request()->get_rest_args( $target );

		$this->assertArrayNotHasKey( 'image', $args );
		$this->assertArrayHasKey( 'title', $args );

		$this->assertSame( array( 'image' => true ), $this->validation()->get_file_properties( $target ) );
	}

	public function test_a_file_argument_with_a_default_is_optional(): void {
		$target = new class() {
			#[Property( 'The image, if there is one.' )]
			public ?UploadedFile $image = null;
		};

		$this->assertSame( array( 'image' => false ), $this->validation()->get_file_properties( $target ) );
	}

	public function test_a_file_argument_arrives_as_an_uploaded_file(): void {
		$target = new class() {
			#[Property( 'The image to attach.' )]
			public UploadedFile $image;
		};

		$this->fill(
			$target,
			array(
				'image' => array(
					'name'     => 'logo.png',
					'type'     => 'image/png',
					'tmp_name' => '/tmp/php123',
					'error'    => UPLOAD_ERR_OK,
					'size'     => 1024,
				),
			)
		);

		$this->assertInstanceOf( UploadedFile::class, $target->image );
		$this->assertSame( 'logo.png', $target->image->name );
		$this->assertTrue( $target->image->is_ok() );
		$this->assertSame( '', $target->image->get_error_message() );
	}

	/**
	 * PHP transposes a multi-file field -- one `name` key holding every name --
	 * so what arrives is one entry rather than a list of them.
	 */
	public function test_a_multi_file_argument_is_untangled_into_one_per_file(): void {
		$target = new class() {
			#[Property( 'Every page.' ), ListOf( UploadedFile::class )]
			public array $pages;
		};

		$this->fill(
			$target,
			array(
				'pages' => array(
					'name'     => array( 'one.pdf', 'two.pdf' ),
					'type'     => array( 'application/pdf', 'application/pdf' ),
					'tmp_name' => array( '/tmp/a', '/tmp/b' ),
					'error'    => array( UPLOAD_ERR_OK, UPLOAD_ERR_INI_SIZE ),
					'size'     => array( 10, 0 ),
				),
			)
		);

		$this->assertCount( 2, $target->pages );
		$this->assertSame( 'one.pdf', $target->pages[0]->name );
		$this->assertFalse( $target->pages[1]->is_ok() );
		$this->assertStringContainsString( 'larger', $target->pages[1]->get_error_message() );
	}

	/**
	 * A single file sent to a field declared as many is still a list of one, so
	 * a route never has to branch on how many arrived.
	 */
	public function test_one_file_sent_to_a_multi_file_argument_is_still_a_list(): void {
		$target = new class() {
			#[Property( 'Every page.' ), ListOf( UploadedFile::class )]
			public array $pages;
		};

		$this->fill(
			$target,
			array(
				'pages' => array(
					'name'     => 'one.pdf',
					'type'     => 'application/pdf',
					'tmp_name' => '/tmp/a',
					'error'    => UPLOAD_ERR_OK,
					'size'     => 10,
				),
			)
		);

		$this->assertCount( 1, $target->pages );
		$this->assertSame( 'one.pdf', $target->pages[0]->name );
	}

	/**
	 * Everything a route would otherwise have to know -- that the upload
	 * functions live in wp-admin, which a REST request has not loaded, and that
	 * wp_handle_upload() refuses a file for missing a form field REST never
	 * sends -- handled, so storing one is a call and an error check.
	 */
	public function test_an_uploaded_file_stores_itself(): void {
		$source = $this->plugin_dir . '/incoming.txt';
		file_put_contents( $source, 'hello' );

		$file = new UploadedFile( 'incoming.txt', 'text/plain', $source, UPLOAD_ERR_OK, 5 );

		// The action WordPress's own tests use: it copies rather than requiring
		// a genuine POST upload, which no test can produce.
		$stored = $file->store( array( 'action' => 'wp_handle_mock_upload' ) );

		$this->assertIsArray( $stored, 'A stored file comes back as WordPress describes it.' );
		$this->assertFileExists( $stored['file'] );
		$this->assertSame( 'hello', file_get_contents( $stored['file'] ) );
		$this->assertStringContainsString( 'incoming', $stored['url'] );

		unlink( $stored['file'] );
	}

	/**
	 * A file that never arrived is answered before WordPress is asked anything,
	 * so the message says what went wrong rather than what went wrong later.
	 */
	public function test_storing_a_file_that_never_arrived_refuses_it(): void {
		$file = new UploadedFile( '', '', '', UPLOAD_ERR_INI_SIZE, 0 );

		$stored = $file->store();

		$this->assertInstanceOf( \WP_Error::class, $stored );
		$this->assertSame( 'rest_upload_no_data', $stored->get_error_code() );
		$this->assertSame( 400, $stored->get_error_data()['status'] );
		$this->assertStringContainsString( 'larger', $stored->get_error_message() );
	}

	public function test_test_form_cannot_be_switched_back_on(): void {
		$source = $this->plugin_dir . '/incoming.txt';
		file_put_contents( $source, 'hello' );

		$file = new UploadedFile( 'incoming.txt', 'text/plain', $source, UPLOAD_ERR_OK, 5 );

		// Passing it explicitly must not bring back the check for a form field
		// that a REST request never carries.
		$stored = $file->store(
			array(
				'action'    => 'wp_handle_mock_upload',
				'test_form' => true,
			)
		);

		$this->assertIsArray( $stored );

		unlink( $stored['file'] );
	}

	public function test_a_refused_upload_carries_wordpresss_own_message(): void {
		$file = new UploadedFile( 'incoming.txt', 'text/plain', '/nowhere/at/all', UPLOAD_ERR_OK, 5 );

		$stored = $file->store( array( 'action' => 'wp_handle_mock_upload' ) );

		$this->assertInstanceOf( \WP_Error::class, $stored );
		$this->assertSame( 'rest_upload_unknown_error', $stored->get_error_code() );
		$this->assertSame( 500, $stored->get_error_data()['status'] );
	}

	/**
	 * WordPress checks an argument against its schema on its own, but a
	 * validate_callback runs *instead of* that check. Every declared property
	 * gets one that runs the same check and reports every problem by path, and
	 * the default sanitizer is left to do the cast alone -- so nothing is checked
	 * twice, whether or not a rule of the property has a check of its own.
	 */
	public function test_a_routes_args_check_once_through_validation(): void {
		$target = new class() {
			#[Property]
			public int $plain;

			#[Rule( check: array( self::class, 'check' ), sanitize: array( self::class, 'clean' ) )]
			public int $ruled = 0;

			public static function check( $value ): bool {
				return true;
			}

			public static function clean( $value ) {
				return $value;
			}
		};

		$args = $this->request()->get_rest_args( $target );

		foreach ( array( 'plain', 'ruled' ) as $name ) {
			$this->assertInstanceOf( \Closure::class, $args[ $name ]['validate_callback'], $name );
			$this->assertSame( array( '7', array( 1 ) ), array( $args[ $name ]['sanitize_callback']( '7' ), $args[ $name ]['sanitize_callback']( array( 1 ) ) ), "{$name} is left as sent: the build step casts it." );
		}

		$this->assertTrue( $args['plain']['validate_callback']( 7 ) );
		$this->assertInstanceOf( \WP_Error::class, $args['plain']['validate_callback']( 'nope' ) );
	}

	/**
	 * A callback stated in an override is the caller's own, and is kept.
	 */
	public function test_a_callback_stated_in_an_override_is_kept(): void {
		$target = new class() {
			#[Property]
			public int $subject;
		};

		$args = $this->request()->get_rest_args( $target, array(), array( 'subject' => array( 'validate_callback' => '__return_true' ) ) );

		$this->assertSame( '__return_true', $args['subject']['validate_callback'] );
		$this->assertSame( 'x', $args['subject']['sanitize_callback']( 'x' ), 'The sanitizer not stated is still ours.' );
	}

	/**
	 * A trait is where a plugin puts the arguments several routes share, and
	 * reflection reports its properties as the using class's own.
	 */
	public function test_arguments_declared_in_a_trait_are_found(): void {
		$target = new class() {
			use ValidationSchemaPaged;

			#[Property( 'Its own.' )]
			public string $own = '';
		};

		$schema = $this->validation()->get_schema( $target );

		$this->assertSame( 'integer', $schema['properties']['page']['type'] );
		$this->assertSame( 1, $schema['properties']['page']['default'] );
		$this->assertArrayHasKey( 'own', $schema['properties'] );

		$this->fill( $target, array( 'page' => 3 ) );

		$this->assertSame( 3, $target->page );
	}

	/**
	 * A date is a string on the wire, and `format: date-time` is what refuses
	 * one that is not a moment -- so a handler is never given a
	 * DateTimeImmutable built from nonsense.
	 *
	 * @dataProvider unreadable_dates
	 */
	public function test_a_date_argument_refuses_what_is_not_a_moment( string $sent ): void {
		$target = new class() {
			#[Property( 'When to send it.' )]
			public \DateTimeImmutable $send_at;
		};

		$refused = $this->validation()->resolve( $target, array( 'send_at' => $sent ), 'invalid_input' );

		$this->assertInstanceOf( \WP_Error::class, $refused, sprintf( '"%s" is not a moment.', $sent ) );
	}

	/**
	 * @return array<string, array{string}>
	 */
	public function unreadable_dates(): array {
		return array(
			// A day is not a moment: date-time has no date-only form.
			'a day alone'     => array( '2026-08-04' ),
			'no seconds'      => array( '2026-08-04T12:00' ),
			'a time alone'    => array( '12:00' ),
			'words'           => array( 'next tuesday-ish' ),
			'nothing like it' => array( 'not-a-date' ),
		);
	}

	/**
	 * @dataProvider readable_dates
	 */
	public function test_a_date_argument_accepts_a_moment( string $sent, string $expected ): void {
		$target = new class() {
			#[Property( 'When to send it.' )]
			public \DateTimeImmutable $send_at;
		};

		$values = $this->validation()->resolve( $target, array( 'send_at' => $sent ), 'invalid_input' );

		$this->assertIsArray( $values, sprintf( '"%s" is a moment.', $sent ) );

		$this->validation()->bind( $target, $values );

		$this->assertSame( $expected, $target->send_at->format( 'c' ) );
	}

	/**
	 * @return array<string, array{string, string}>
	 */
	public function readable_dates(): array {
		return array(
			'zulu'            => array( '2026-08-04T12:00:00Z', '2026-08-04T12:00:00+00:00' ),
			'an offset'       => array( '2026-08-04T12:00:00+10:00', '2026-08-04T12:00:00+10:00' ),
			// WordPress runs PHP in UTC, so a time with no offset is UTC --
			// whatever the site's timezone is set to.
			'no offset'       => array( '2026-08-04 12:00:00', '2026-08-04T12:00:00+00:00' ),
		);
	}

	/**
	 * A time with no offset is read as UTC rather than in the site's timezone,
	 * so converting for display is the handler's to do.
	 */
	public function test_a_time_without_an_offset_is_read_as_utc_not_site_time(): void {
		update_option( 'timezone_string', 'Australia/Sydney' );

		$target = new class() {
			#[Property( 'When to send it.' )]
			public \DateTimeImmutable $send_at;
		};

		$this->fill( $target, array( 'send_at' => '2026-08-04 12:00:00' ) );

		$this->assertSame( '2026-08-04T12:00:00+00:00', $target->send_at->format( 'c' ) );
		$this->assertSame(
			'2026-08-04T22:00:00+10:00',
			$target->send_at->setTimezone( wp_timezone() )->format( 'c' ),
			'And this is how a handler shows it in the site\'s own time.'
		);

		update_option( 'timezone_string', '' );
	}

	public function test_a_nullable_date_accepts_null_and_absence(): void {
		$target = new class() {
			#[Property( 'When to send it, if ever.' )]
			public ?\DateTimeInterface $send_at = null;
		};

		$values = $this->validation()->resolve( $target, array( 'send_at' => null ), 'invalid_input' );

		$this->assertIsArray( $values, 'Null passes the schema for a nullable date.' );

		$this->validation()->bind( $target, $values );
		$this->assertNull( $target->send_at );

		$this->assertIsArray(
			$this->validation()->resolve( $target, array(), 'invalid_input' ),
			'And leaving it out is not a missing required argument.'
		);
	}

	/**
	 * PHP has no array-of-type syntax, so nothing in the declaration says what
	 * an array holds. Publishing it anyway would hand a caller a shape it cannot
	 * satisfy without guessing.
	 */
	public function test_an_array_that_does_not_say_what_it_holds_throws(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'does not say what it holds' );

		$this->validation()->get_schema(
			new class() {
				#[Property( 'Some things.' )]
				public array $things;
			}
		);
	}

	public function test_an_untyped_argument_throws(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'needs a single declared type' );

		$this->validation()->get_schema(
			new class() {
				#[Property( 'Anything at all.' )]
				public $whatever;
			}
		);
	}

	public function test_a_structure_is_built_without_calling_its_constructor(): void {
		$target = new class() {
			#[Property( 'Where to ship it.' )]
			public ValidationSchemaAddress $address;

			#[Property( 'Where else.' ), ListOf( ValidationSchemaAddress::class )]
			public array $others = array();
		};

		$this->fill(
			$target,
			array(
				'address' => array( 'line1' => '1 Example Street' ),
				'others'  => array( array( 'line1' => '2 Example Street', 'country' => 'FR' ) ),
			)
		);

		$this->assertInstanceOf( ValidationSchemaAddress::class, $target->address );
		$this->assertSame( '1 Example Street', $target->address->line1 );
		$this->assertSame( 'US', $target->address->country, "A property default the constructor never set." );

		$this->assertInstanceOf( ValidationSchemaAddress::class, $target->others[0] );
		$this->assertSame( 'FR', $target->others[0]->country );
	}

	public function test_an_absent_value_leaves_the_declared_default_alone(): void {
		$target = new class() {
			#[Property( 'Whether to notify.' )]
			public bool $notify = true;
		};

		$this->fill( $target, array() );

		$this->assertTrue( $target->notify );
	}

	public function test_prepare_validates_then_sanitizes(): void {
		$target = new class() {
			#[Property( 'A name.' ), Rule( check: 'is_string' ), Sanitize( 'trim' )]
			public string $name;
		};

		$prepared = $this->validation()->resolve( $target, array( 'name' => '  acme  ' ), 'invalid_input' );

		$this->assertSame( array( 'name' => 'acme' ), $prepared );
	}

	public function test_prepare_refuses_with_the_code_its_caller_asked_for(): void {
		$target = new class() {
			#[Property( 'A number.' ), Rule( check: 'is_int' )]
			public int $count;
		};

		$refused = $this->validation()->resolve( $target, array( 'count' => 'nope' ), 'ability_invalid_input' );

		$this->assertInstanceOf( \WP_Error::class, $refused );
		$this->assertSame( 'ability_invalid_input', $refused->get_error_code() );
		$this->assertStringContainsString( 'count', $refused->get_error_message() );
	}

	/**
	 * A structure containing itself has no schema, only an ever-deeper one, so
	 * it has to stop rather than exhaust memory.
	 */
	public function test_a_structure_that_contains_itself_throws(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'nests more than' );

		$this->validation()->get_schema( ValidationSchemaCycle::class );
	}

	/**
	 * A route, an ability, an action and a page are each built once and answer
	 * many calls, so the second call has to start where the first one did.
	 *
	 * @return void
	 */
	public function test_an_argument_left_out_of_a_second_bind_returns_to_its_default(): void {
		$target = new class() {
			#[Property( 'Which one.' )]
			public ?string $slug = null;

			#[Property( 'How many.' )]
			public int $limit = 10;
		};

		$this->fill( $target, array( 'slug' => 'our-team', 'limit' => 50 ) );

		$this->assertSame( 'our-team', $target->slug );
		$this->assertSame( 50, $target->limit );

		$this->fill( $target, array() );

		$this->assertNull( $target->slug, 'The declared default, not the last call.' );
		$this->assertSame( 10, $target->limit );
	}

	/**
	 * An argument with no default is uninitialized until something assigns it,
	 * which is what makes it required. Putting a stale value back would leave a
	 * missing required argument looking supplied.
	 *
	 * @return void
	 */
	public function test_an_argument_with_no_default_goes_back_to_uninitialized(): void {
		$target = new class() {
			#[Property( 'Which one.' )]
			public int $id;
		};

		$this->fill( $target, array( 'id' => 42 ) );

		$this->assertSame( 42, $target->id );

		$this->validation()->reset( $target );

		$this->assertFalse(
			( new \ReflectionProperty( $target, 'id' ) )->isInitialized( $target ),
			'Not zero, and not the last call: unset, as it was before anything was bound.'
		);
	}

	/**
	 * `unset()` obeys the scope it runs in, so a protected argument cannot be
	 * cleared the way a public one can -- and every module that binds accepts
	 * both.
	 *
	 * @return void
	 */
	public function test_a_protected_argument_is_cleared_too(): void {
		$target = new class() {
			#[Property( 'Which one.' )]
			protected ?string $slug = null;

			#[Property( 'A number.' )]
			protected int $count;

			public function get_slug(): ?string {
				return $this->slug;
			}

			public function has_count(): bool {
				return isset( $this->count );
			}
		};

		$this->fill( $target, array( 'slug' => 'kept', 'count' => 3 ) );

		$this->assertSame( 'kept', $target->get_slug() );
		$this->assertTrue( $target->has_count() );

		// Nothing was sent for a required `count`, which is the point: bind() alone clears it.
		$this->validation()->bind( $target, array() );

		$this->assertNull( $target->get_slug() );
		$this->assertFalse( $target->has_count() );
	}

	/**
	 * A readonly argument is left exactly as it was, so the message explaining
	 * why it cannot work on a reused object still reaches the reader. Clearing it
	 * would replace that with PHP's own fatal for unsetting one.
	 *
	 * @return void
	 */
	public function test_a_readonly_argument_still_reports_itself_rather_than_being_cleared(): void {
		$target = new class() {
			#[Property( 'Which one.' )]
			public readonly int $id;
		};

		$this->fill( $target, array( 'id' => 1 ) );

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'readonly' );

		$this->fill( $target, array( 'id' => 2 ) );
	}
}

/**
 * A structure that contains itself.
 */
// phpcs:ignore Squiz.Classes.ClassFileName.NoMatch, PSR1.Classes.ClassDeclaration.MultipleClasses
final class ValidationSchemaCycle extends Structure {

	#[Property( 'Itself, forever.' )]
	public ValidationSchemaCycle $inner;
}
