<?php

declare( strict_types=1 );

namespace Zestry\WPToolkit\Tests\Integration\Services;

use Zestry\WPToolkit\Services\Request\Attributes\RequestArgument;
use Zestry\WPToolkit\Services\Request\Request;
use Zestry\WPToolkit\Services\Request\UploadedFile;
use Zestry\WPToolkit\Tests\Support\TestCase;

/**
 * Arguments shared by more than one route or ability.
 */
trait RequestTestPaged {

	#[RequestArgument( 'Which page.' )]
	public int $page = 1;
}

/**
 * A backed enum an argument can be declared as.
 */
enum RequestTestStatus: string {
	case Draft = 'draft';
	case Live  = 'live';
}

/**
 * An int-backed one, to prove the backing type reaches the schema.
 */
enum RequestTestPriority: int {
	case Low  = 1;
	case High = 2;
}

/**
 * A pure enum, which has no value to send.
 */
enum RequestTestOrder {
	case Ascending;
	case Descending;
}

/**
 * A structure declared the modern way: promoted, readonly, with a default that
 * lives on the constructor parameter rather than the property.
 */
final class RequestTestParcel {

	public function __construct(
		#[RequestArgument( 'How heavy, in grams.' )]
		public readonly int $weight,
		#[RequestArgument( 'Where it is going.' )]
		public readonly string $destination = 'GB'
	) {}
}

/**
 * A structure an argument can be declared as.
 */
final class RequestTestAddress {

	#[RequestArgument( 'Street and number.' )]
	public string $line1;

	#[RequestArgument( 'Two-letter country code.', schema: array( 'pattern' => '^[A-Z]{2}$' ) )]
	public string $country = 'US';

	/**
	 * A constructor the service must not need, since it cannot know what to pass.
	 *
	 * @param string $line1 Street and number.
	 */
	public function __construct( string $line1 ) {
		$this->line1 = $line1;
	}
}

/**
 * The declaration side, exercised without a route or an ability around it.
 *
 * @covers \Zestry\WPToolkit\Services\Request\Request
 * @covers \Zestry\WPToolkit\Services\Request\Attributes\RequestArgument
 */
final class RequestTest extends TestCase {

	private function request(): Request {
		return $this->plugin->get( Request::class );
	}

	public function test_the_property_states_the_type_and_whether_it_is_required(): void {
		$schema = $this->request()->get_schema(
			new class() {
				#[RequestArgument( 'Which one.' )]
				public int $id;

				#[RequestArgument( 'Whether to notify.' )]
				public bool $notify = true;

				#[RequestArgument( 'A rating, if given.' )]
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

	public function test_an_explicit_schema_wins_over_the_derived_one(): void {
		$schema = $this->request()->get_schema(
			new class() {
				#[RequestArgument( 'How to sort.', schema: array( 'enum' => array( 'date', 'title' ) ) )]
				public string $order_by = 'date';

				// An associative payload is a JSON object, which PHP's `array`
				// cannot distinguish from a list -- so the schema says so.
				#[RequestArgument( 'Anything.', schema: array( 'type' => 'object' ) )]
				public array $extra = array();
			}
		);

		$this->assertSame( array( 'date', 'title' ), $schema['properties']['order_by']['enum'] );
		$this->assertSame( 'string', $schema['properties']['order_by']['type'], 'The derived type survives alongside it.' );
		$this->assertSame( 'object', $schema['properties']['extra']['type'] );
	}

	public function test_a_class_typed_argument_becomes_a_nested_object(): void {
		$schema = $this->request()->get_schema(
			new class() {
				#[RequestArgument( 'Where to ship it.' )]
				public RequestTestAddress $address;
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
		$schema = $this->request()->get_schema(
			new class() {
				#[RequestArgument( 'Where to ship each parcel.', of: RequestTestAddress::class )]
				public array $addresses;
			}
		);

		$this->assertSame( 'array', $schema['properties']['addresses']['type'] );
		$this->assertSame( 'object', $schema['properties']['addresses']['items']['type'] );
		$this->assertSame( 'string', $schema['properties']['addresses']['items']['properties']['line1']['type'] );
	}

	public function test_a_backed_enum_argument_publishes_its_values(): void {
		$schema = $this->request()->get_schema(
			new class() {
				#[RequestArgument( 'What state it is in.' )]
				public RequestTestStatus $status;

				#[RequestArgument( 'How urgent, if stated.' )]
				public ?RequestTestPriority $priority = null;
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
		$schema = $this->request()->get_schema(
			new class() {
				#[RequestArgument( 'Which way round.' )]
				public RequestTestOrder $order;
			}
		);

		$this->assertSame( 'string', $schema['properties']['order']['type'] );
		$this->assertSame( array( 'Ascending', 'Descending' ), $schema['properties']['order']['enum'] );
	}

	public function test_an_enum_argument_arrives_as_a_case(): void {
		$target = new class() {
			#[RequestArgument( 'What state it is in.' )]
			public RequestTestStatus $status;

			#[RequestArgument( 'Which way round.' )]
			public RequestTestOrder $order;

			#[RequestArgument( 'Every state to include.', of: RequestTestStatus::class )]
			public array $states = array();
		};

		$this->request()->bind(
			$target,
			array(
				'status' => 'live',
				'order'  => 'Descending',
				'states' => array( 'draft', 'live' ),
			)
		);

		$this->assertSame( RequestTestStatus::Live, $target->status );
		$this->assertSame( RequestTestOrder::Descending, $target->order, 'A pure enum comes back by case name.' );
		$this->assertSame( array( RequestTestStatus::Draft, RequestTestStatus::Live ), $target->states );
	}

	/**
	 * Unreachable through a schema this service built, so it means a hand-written
	 * one no longer agrees with the property it fills.
	 */
	public function test_a_value_naming_no_case_throws(): void {
		$target = new class() {
			#[RequestArgument( 'What state it is in.' )]
			public RequestTestStatus $status;
		};

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'is not a case of' );

		$this->request()->bind( $target, array( 'status' => 'archived' ) );
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
			"<?php\nuse Zestry\\WPToolkit\\Services\\Request\\Attributes\\RequestArgument;\n"
				. "use Zestry\\WPToolkit\\Tests\\Integration\\Services\\RequestTestAddress;\n"
				. "use Zestry\\WPToolkit\\Tests\\Integration\\Services\\RequestTestStatus;\n"
				. "return new class() {\n{$declaration}\n};\n"
		);

		$schema = $this->request()->get_schema( require $file );

		foreach ( $expected as $key => $value ) {
			$this->assertSame( $value, $schema['properties']['subject'][ $key ], sprintf( 'The %s of %s.', $key, $declaration ) );
		}
	}

	/**
	 * @return array<string, array{string, array<string, mixed>}>
	 */
	public function declarable_types(): array {
		return array(
			'int'               => array( '#[RequestArgument] public int $subject;', array( 'type' => 'integer' ) ),
			'float'             => array( '#[RequestArgument] public float $subject;', array( 'type' => 'number' ) ),
			'string'            => array( '#[RequestArgument] public string $subject;', array( 'type' => 'string' ) ),
			'bool'              => array( '#[RequestArgument] public bool $subject;', array( 'type' => 'boolean' ) ),
			'nullable int'      => array( '#[RequestArgument] public ?int $subject = null;', array( 'type' => array( 'integer', 'null' ) ) ),
			'array of ints'     => array(
				"#[RequestArgument( schema: array( 'items' => array( 'type' => 'integer' ) ) )] public array \$subject;",
				array( 'type' => 'array', 'items' => array( 'type' => 'integer' ) ),
			),
			'array of objects'  => array(
				'#[RequestArgument( of: RequestTestAddress::class )] public array $subject;',
				array( 'type' => 'array' ),
			),
			'array of enums'    => array(
				'#[RequestArgument( of: RequestTestStatus::class )] public array $subject;',
				array( 'type' => 'array', 'items' => array( 'type' => 'string', 'enum' => array( 'draft', 'live' ) ) ),
			),
			'object'            => array( '#[RequestArgument] public RequestTestAddress $subject;', array( 'type' => 'object' ) ),
			'nullable object'   => array(
				'#[RequestArgument] public ?RequestTestAddress $subject = null;',
				array( 'type' => array( 'object', 'null' ) ),
			),
			'enum'              => array(
				'#[RequestArgument] public RequestTestStatus $subject;',
				array( 'type' => 'string', 'enum' => array( 'draft', 'live' ) ),
			),
			'date'              => array(
				'#[RequestArgument] public \DateTimeImmutable $subject;',
				array( 'type' => 'string', 'format' => 'date-time' ),
			),
			'nullable date'     => array(
				'#[RequestArgument] public ?\DateTimeInterface $subject = null;',
				array( 'type' => array( 'string', 'null' ), 'format' => 'date-time' ),
			),
			'described'         => array(
				"#[RequestArgument( 'Which one.' )] public int \$subject;",
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
			#[RequestArgument( 'Whatever the client keeps here.' )]
			public \stdClass $meta;

			#[RequestArgument( 'Anything at all.' )]
			public object $extra;

			#[RequestArgument( 'Several of them.', of: \stdClass::class )]
			public array $blobs = array();
		};

		$schema = $this->request()->get_schema( $target );

		$this->assertSame( 'object', $schema['properties']['meta']['type'] );
		$this->assertSame( 'object', $schema['properties']['extra']['type'] );
		$this->assertSame( 'object', $schema['properties']['blobs']['items']['type'] );
		$this->assertArrayNotHasKey( 'properties', $schema['properties']['meta'], 'Open means open: no keys are demanded.' );

		$this->request()->bind(
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
	 * An open object still takes a schema of its own, for the parts you do know.
	 */
	public function test_a_free_form_object_can_still_be_narrowed(): void {
		$schema = $this->request()->get_schema(
			new class() {
				#[RequestArgument(
					'Settings.',
					schema: array( 'properties' => array( 'theme' => array( 'type' => 'string' ) ) )
				)]
				public \stdClass $settings;
			}
		);

		$this->assertSame( 'object', $schema['properties']['settings']['type'] );
		$this->assertSame( 'string', $schema['properties']['settings']['properties']['theme']['type'] );
	}

	/**
	 * A description is optional, and an argument without one publishes no empty
	 * key for a caller to read as "described, but as nothing".
	 */
	public function test_an_argument_without_a_description_publishes_none(): void {
		$schema = $this->request()->get_schema(
			new class() {
				#[RequestArgument]
				public int $id;
			}
		);

		$this->assertArrayNotHasKey( 'description', $schema['properties']['id'] );
	}

	public function test_a_date_argument_arrives_as_a_date(): void {
		$target = new class() {
			#[RequestArgument( 'When it happened.' )]
			public \DateTimeImmutable $when;

			#[RequestArgument( 'When it is due, if ever.' )]
			public ?\DateTimeInterface $due = null;
		};

		$this->request()->bind( $target, array( 'when' => '2026-08-04T12:00:00Z', 'due' => null ) );

		$this->assertSame( '2026-08-04', $target->when->format( 'Y-m-d' ) );
		$this->assertNull( $target->due );
	}

	/**
	 * The idiomatic modern structure: promoted, readonly, and defaulted on the
	 * constructor parameter -- which is where `hasDefaultValue()` cannot see it.
	 */
	public function test_a_promoted_readonly_structure_is_described_and_built(): void {
		$schema = $this->request()->get_schema(
			new class() {
				#[RequestArgument( 'What is being shipped.' )]
				public RequestTestParcel $parcel;
			}
		);

		$parcel = $schema['properties']['parcel'];

		$this->assertSame( array( 'weight' ), $parcel['required'], 'A promoted default makes its argument optional.' );
		$this->assertSame( 'GB', $parcel['properties']['destination']['default'] );
	}

	public function test_a_promoted_default_is_applied_when_the_caller_omits_it(): void {
		$target = new class() {
			#[RequestArgument( 'What is being shipped.' )]
			public RequestTestParcel $parcel;
		};

		// The constructor never runs, so nothing else would have set it.
		$this->request()->bind( $target, array( 'parcel' => array( 'weight' => 500 ) ) );

		$this->assertSame( 500, $target->parcel->weight );
		$this->assertSame( 'GB', $target->parcel->destination );
	}

	/**
	 * A class with nothing to recurse into cannot be published as a shape --
	 * unlike `stdClass`, which says it has no shape on purpose.
	 */
	public function test_a_class_that_describes_nothing_throws(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'declares no arguments' );

		$this->request()->get_schema(
			new class() {
				#[RequestArgument( 'Anything.' )]
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
			"<?php\nuse Zestry\\WPToolkit\\Services\\Request\\Attributes\\RequestArgument;\n"
				. "return new class() {\n{$declaration}\n};\n"
		);

		$this->expectException( \InvalidArgumentException::class );

		$this->request()->get_schema( require $file );
	}

	/**
	 * @return array<string, array{string}>
	 */
	public function undescribable_types(): array {
		return array(
			'untyped'      => array( '#[RequestArgument] public $subject;' ),
			'union'        => array( '#[RequestArgument] public int|string $subject;' ),
			'mixed'        => array( '#[RequestArgument] public mixed $subject;' ),
			'iterable'     => array( '#[RequestArgument] public iterable $subject;' ),
			'unsaid array' => array( '#[RequestArgument] public array $subject;' ),
		);
	}

	/**
	 * An upload arrives as multipart/form-data, which JSON Schema has no type
	 * for -- so a schema cannot carry one, and an ability whose whole input is
	 * JSON must be told at registration rather than wait for input that can
	 * never come.
	 */
	public function test_a_file_argument_cannot_be_described_in_a_schema(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'only a REST route can take one' );

		$this->request()->get_schema(
			new class() {
				#[RequestArgument( 'The image to attach.' )]
				public UploadedFile $image;
			}
		);
	}

	/**
	 * WordPress leaves uploads out of a request's parameters, so an arg declared
	 * for one would be a parameter that is always missing -- and `required` would
	 * then reject every request that carried the file correctly.
	 */
	public function test_a_file_argument_is_absent_from_a_routes_args(): void {
		$target = new class() {
			#[RequestArgument( 'The image to attach.' )]
			public UploadedFile $image;

			#[RequestArgument( 'What to call it.' )]
			public string $title = '';
		};

		$args = $this->request()->get_rest_args( $target );

		$this->assertArrayNotHasKey( 'image', $args );
		$this->assertArrayHasKey( 'title', $args );

		$this->assertSame( array( 'image' => true ), $this->request()->get_file_arguments( $target ) );
	}

	public function test_a_file_argument_with_a_default_is_optional(): void {
		$target = new class() {
			#[RequestArgument( 'The image, if there is one.' )]
			public ?UploadedFile $image = null;
		};

		$this->assertSame( array( 'image' => false ), $this->request()->get_file_arguments( $target ) );
	}

	public function test_a_file_argument_arrives_as_an_uploaded_file(): void {
		$target = new class() {
			#[RequestArgument( 'The image to attach.' )]
			public UploadedFile $image;
		};

		$this->request()->bind(
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
			#[RequestArgument( 'Every page.', of: UploadedFile::class )]
			public array $pages;
		};

		$this->request()->bind(
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
			#[RequestArgument( 'Every page.', of: UploadedFile::class )]
			public array $pages;
		};

		$this->request()->bind(
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
	 * WordPress checks an argument against its schema on its own. A
	 * validate_callback runs *instead of* that check, and the fallback lives
	 * inside the default sanitize_callback -- so declaring either one displaces
	 * something, and nothing is checked twice.
	 *
	 * @dataProvider callback_combinations
	 */
	public function test_only_the_displaced_check_is_restored( string $declaration, $validate, $sanitize ): void {
		$file = $this->write_plugin_file(
			'callbacks.php',
			"<?php\nuse Zestry\\WPToolkit\\Services\\Request\\Attributes\\RequestArgument;\n"
				. "return new class() {\n{$declaration}\n"
				. "public static function check( \$value ): bool { return true; }\n"
				. "public static function clean( \$value ) { return \$value; }\n};\n"
		);

		$arg = $this->request()->get_rest_args( require $file )['subject'];

		foreach ( array( 'validate_callback' => $validate, 'sanitize_callback' => $sanitize ) as $key => $expected ) {
			if ( null === $expected ) {
				$this->assertArrayNotHasKey( $key, $arg, sprintf( 'WordPress does its own %s here.', $key ) );
				continue;
			}

			$this->assertSame( $expected, is_string( $arg[ $key ] ) ? $arg[ $key ] : 'closure', $key );
		}
	}

	/**
	 * @return array<string, array{string, string|null, string|null}>
	 */
	public function callback_combinations(): array {
		return array(
			// Declaration, expected validate_callback, expected sanitize_callback.
			'neither'  => array( '#[RequestArgument] public int $subject;', null, null ),
			'validate' => array(
				'#[RequestArgument( validate: [ self::class, \'check\' ] )] public int $subject;',
				'closure',
				'rest_sanitize_request_arg',
			),
			'sanitize' => array(
				'#[RequestArgument( sanitize: [ self::class, \'clean\' ] )] public int $subject;',
				'rest_validate_request_arg',
				'closure',
			),
			'both'     => array(
				'#[RequestArgument( validate: [ self::class, \'check\' ], sanitize: [ self::class, \'clean\' ] )] public int $subject;',
				'closure',
				'closure',
			),
		);
	}

	/**
	 * A trait is where a plugin puts the arguments several routes share, and
	 * reflection reports its properties as the using class's own.
	 */
	public function test_arguments_declared_in_a_trait_are_found(): void {
		$target = new class() {
			use RequestTestPaged;

			#[RequestArgument( 'Its own.' )]
			public string $own = '';
		};

		$schema = $this->request()->get_schema( $target );

		$this->assertSame( 'integer', $schema['properties']['page']['type'] );
		$this->assertSame( 1, $schema['properties']['page']['default'] );
		$this->assertArrayHasKey( 'own', $schema['properties'] );

		$this->request()->bind( $target, array( 'page' => 3 ) );

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
			#[RequestArgument( 'When to send it.' )]
			public \DateTimeImmutable $send_at;
		};

		$refused = $this->request()->get_validated_values( $target, array( 'send_at' => $sent ), 'invalid_input' );

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
			#[RequestArgument( 'When to send it.' )]
			public \DateTimeImmutable $send_at;
		};

		$values = $this->request()->get_validated_values( $target, array( 'send_at' => $sent ), 'invalid_input' );

		$this->assertIsArray( $values, sprintf( '"%s" is a moment.', $sent ) );

		$this->request()->bind( $target, $values );

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
			#[RequestArgument( 'When to send it.' )]
			public \DateTimeImmutable $send_at;
		};

		$this->request()->bind( $target, array( 'send_at' => '2026-08-04 12:00:00' ) );

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
			#[RequestArgument( 'When to send it, if ever.' )]
			public ?\DateTimeInterface $send_at = null;
		};

		$values = $this->request()->get_validated_values( $target, array( 'send_at' => null ), 'invalid_input' );

		$this->assertIsArray( $values, 'Null passes the schema for a nullable date.' );

		$this->request()->bind( $target, $values );
		$this->assertNull( $target->send_at );

		$this->assertIsArray(
			$this->request()->get_validated_values( $target, array(), 'invalid_input' ),
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

		$this->request()->get_schema(
			new class() {
				#[RequestArgument( 'Some things.' )]
				public array $things;
			}
		);
	}

	public function test_an_untyped_argument_throws(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'needs a single declared type' );

		$this->request()->get_schema(
			new class() {
				#[RequestArgument( 'Anything at all.' )]
				public $whatever;
			}
		);
	}

	public function test_a_structure_is_built_without_calling_its_constructor(): void {
		$target = new class() {
			#[RequestArgument( 'Where to ship it.' )]
			public RequestTestAddress $address;

			#[RequestArgument( 'Where else.', of: RequestTestAddress::class )]
			public array $others = array();
		};

		$this->request()->bind(
			$target,
			array(
				'address' => array( 'line1' => '1 Example Street' ),
				'others'  => array( array( 'line1' => '2 Example Street', 'country' => 'FR' ) ),
			)
		);

		$this->assertInstanceOf( RequestTestAddress::class, $target->address );
		$this->assertSame( '1 Example Street', $target->address->line1 );
		$this->assertSame( 'US', $target->address->country, "A property default the constructor never set." );

		$this->assertInstanceOf( RequestTestAddress::class, $target->others[0] );
		$this->assertSame( 'FR', $target->others[0]->country );
	}

	public function test_an_absent_value_leaves_the_declared_default_alone(): void {
		$target = new class() {
			#[RequestArgument( 'Whether to notify.' )]
			public bool $notify = true;
		};

		$this->request()->bind( $target, array() );

		$this->assertTrue( $target->notify );
	}

	public function test_prepare_validates_then_sanitizes(): void {
		$target = new class() {
			#[RequestArgument( 'A name.', validate: 'is_string', sanitize: 'trim' )]
			public string $name;
		};

		$prepared = $this->request()->get_prepared_values( $target, array( 'name' => '  acme  ' ), 'invalid_input' );

		$this->assertSame( array( 'name' => 'acme' ), $prepared );
	}

	public function test_prepare_refuses_with_the_code_its_caller_asked_for(): void {
		$target = new class() {
			#[RequestArgument( 'A number.', validate: 'is_int' )]
			public int $count;
		};

		$refused = $this->request()->get_prepared_values( $target, array( 'count' => 'nope' ), 'ability_invalid_input' );

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
		$this->expectExceptionMessage( 'nest more than' );

		$this->request()->get_schema( new RequestTestCycle() );
	}

	/**
	 * A route, an ability, an action and a page are each built once and answer
	 * many calls, so the second call has to start where the first one did.
	 *
	 * @return void
	 */
	public function test_an_argument_left_out_of_a_second_bind_returns_to_its_default(): void {
		$target = new class() {
			#[RequestArgument( 'Which one.' )]
			public ?string $slug = null;

			#[RequestArgument( 'How many.' )]
			public int $limit = 10;
		};

		$this->request()->bind( $target, array( 'slug' => 'our-team', 'limit' => 50 ) );

		$this->assertSame( 'our-team', $target->slug );
		$this->assertSame( 50, $target->limit );

		$this->request()->bind( $target, array() );

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
			#[RequestArgument( 'Which one.' )]
			public int $id;
		};

		$this->request()->bind( $target, array( 'id' => 42 ) );

		$this->assertSame( 42, $target->id );

		$this->request()->reset( $target );

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
			#[RequestArgument( 'Which one.' )]
			protected ?string $slug = null;

			#[RequestArgument( 'A number.' )]
			protected int $count;

			public function get_slug(): ?string {
				return $this->slug;
			}

			public function has_count(): bool {
				return isset( $this->count );
			}
		};

		$this->request()->bind( $target, array( 'slug' => 'kept', 'count' => 3 ) );

		$this->assertSame( 'kept', $target->get_slug() );
		$this->assertTrue( $target->has_count() );

		$this->request()->bind( $target, array() );

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
			#[RequestArgument( 'Which one.' )]
			public readonly int $id;
		};

		$this->request()->bind( $target, array( 'id' => 1 ) );

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'readonly' );

		$this->request()->bind( $target, array( 'id' => 2 ) );
	}
}

/**
 * A structure that contains itself.
 */
// phpcs:ignore Squiz.Classes.ClassFileName.NoMatch, PSR1.Classes.ClassDeclaration.MultipleClasses
final class RequestTestCycle {

	#[RequestArgument( 'Itself, forever.' )]
	public RequestTestCycle $inner;
}
