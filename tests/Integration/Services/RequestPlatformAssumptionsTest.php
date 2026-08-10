<?php

declare( strict_types=1 );

namespace Zestry\WPToolkit\Tests\Integration\Services;

use Zestry\WPToolkit\Tests\Support\TestCase;
use WP_REST_Request;

/**
 * A structure with a promoted, defaulted, readonly parameter.
 */
final class AssumedParcel {

	public function __construct(
		public readonly string $destination = 'GB'
	) {}
}

/**
 * What the Request service takes for granted about WordPress and about PHP.
 *
 * Each of these was checked once, by hand, and then built on. None of them is
 * documented as a promise, so each is a thing that could change under the
 * service and take a behaviour with it silently — a value arriving unchecked, an
 * argument published as required that is not, an upload that fatals. Pinned
 * here, a change fails one obvious test instead.
 *
 * @covers \Zestry\WPToolkit\Services\Request\Request
 * @covers \Zestry\WPToolkit\Services\Request\UploadedFile
 */
final class RequestPlatformAssumptionsTest extends TestCase {

	/**
	 * Why `get_rest_args()` publishes JSON Schema keywords at all: WordPress
	 * reads a route's `args` entry as a schema, so `pattern`, `enum`, `minimum`
	 * and the rest are enforced without a line of validation from us.
	 */
	public function test_wordpress_validates_a_routes_arg_as_a_json_schema(): void {
		$request = $this->request_expecting(
			array(
				'type'    => 'string',
				'pattern' => '^[A-Z]{2}$',
			)
		);

		$this->assertInstanceOf( \WP_Error::class, rest_validate_request_arg( 'gb', $request, 'subject' ) );
		$this->assertTrue( rest_validate_request_arg( 'GB', $request, 'subject' ) );
	}

	/**
	 * A pattern is written bare, with no delimiters, because WordPress adds its
	 * own — and escapes a `#` in yours so it cannot end the expression early.
	 */
	public function test_a_pattern_needs_no_delimiters(): void {
		$this->assertTrue( rest_validate_json_schema_pattern( '^[A-Z]{2}$', 'GB' ) );
		$this->assertTrue( rest_validate_json_schema_pattern( '^#\d+$', '#42' ) );
	}

	/**
	 * The default `sanitize_callback` validates before it sanitises. That is the
	 * check an argument declaring a `sanitize` of its own takes away, and the
	 * reason `get_rest_args()` names `rest_validate_request_arg` when it does.
	 */
	public function test_the_default_sanitize_validates_before_sanitising(): void {
		$request = $this->request_expecting( array( 'type' => 'integer' ) );

		$this->assertInstanceOf( \WP_Error::class, rest_parse_request_arg( 'abc', $request, 'subject' ) );
		$this->assertSame( 42, rest_parse_request_arg( '42', $request, 'subject' ) );
	}

	/**
	 * Sanitising alone does not check anything, which is why declaring one
	 * without the other would leave an argument unvalidated.
	 */
	public function test_sanitising_alone_checks_nothing(): void {
		$request = $this->request_expecting( array( 'type' => 'integer' ) );

		$this->assertSame( 0, rest_sanitize_request_arg( 'abc', $request, 'subject' ), 'A bad value is cast, not refused.' );
	}

	/**
	 * Uploads are not parameters. `get_param()` never finds one, so a file
	 * argument is read from `get_file_params()` and can carry no schema.
	 */
	public function test_an_upload_is_not_a_request_parameter(): void {
		$request = new WP_REST_Request( 'POST', '/zestry-test/v1/uploads' );
		$request->set_file_params(
			array(
				'image' => array(
					'name'     => 'logo.png',
					'type'     => 'image/png',
					'tmp_name' => '/tmp/php123',
					'error'    => UPLOAD_ERR_OK,
					'size'     => 1,
				),
			)
		);

		$this->assertNull( $request->get_param( 'image' ) );
		$this->assertSame( 'logo.png', $request->get_file_params()['image']['name'] );
	}

	/**
	 * `UploadedFile::store()` loads an admin file before calling this, because a
	 * REST request has not: without that, storing an upload is a fatal error.
	 */
	public function test_the_upload_handler_lives_in_an_admin_file(): void {
		$this->assertFileExists( ABSPATH . 'wp-admin/includes/file.php' );

		require_once ABSPATH . 'wp-admin/includes/file.php';

		$this->assertSame(
			realpath( ABSPATH . 'wp-admin/includes/file.php' ),
			realpath( ( new \ReflectionFunction( 'wp_handle_upload' ) )->getFileName() ),
			'A REST request loads no admin, so this has to be required first.'
		);
	}

	/**
	 * And it takes its file by reference, so the array cannot be handed over as
	 * a method's return value.
	 */
	public function test_the_upload_handler_takes_its_file_by_reference(): void {
		require_once ABSPATH . 'wp-admin/includes/file.php';

		$this->assertTrue(
			( new \ReflectionFunction( 'wp_handle_upload' ) )->getParameters()[0]->isPassedByReference()
		);
	}

	/**
	 * An ability's input is validated and never sanitised, and that validation
	 * accepts a numeric string for an integer — so `"42"` is a valid thing to
	 * send and would reach a typed property as a string. `Request::prepare()`
	 * casts it for exactly this reason.
	 */
	public function test_schema_validation_accepts_a_numeric_string_for_an_integer(): void {
		$this->assertTrue( rest_validate_value_from_schema( '42', array( 'type' => 'integer' ), 'id' ) );
		$this->assertSame( 42, rest_sanitize_value_from_schema( '42', array( 'type' => 'integer' ), 'id' ) );
	}

	/**
	 * The whole of what an ability does to its input, in order. A sanitising
	 * step appearing here would make the service's own cast a second one.
	 */
	public function test_an_ability_validates_its_input_and_never_sanitises_it(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			$this->markTestSkipped( 'Requires the Abilities API, added in WordPress 6.9.' );
		}

		$received = null;

		add_action(
			'wp_abilities_api_categories_init',
			static function (): void {
				wp_register_ability_category( 'zestry-assumed', array( 'label' => 'Assumed', 'description' => 'For one test.' ) );
			}
		);

		add_action(
			'wp_abilities_api_init',
			static function () use ( &$received ): void {
				wp_register_ability(
					'zestry-assumed/echo',
					array(
						'label'               => 'Echo',
						'description'         => 'Reports what it was given.',
						'category'            => 'zestry-assumed',
						'input_schema'        => array(
							'type'       => 'object',
							'properties' => array( 'id' => array( 'type' => 'integer' ) ),
						),
						'permission_callback' => '__return_true',
						'execute_callback'    => static function ( $input ) use ( &$received ) {
							$received = $input['id'];

							return array( 'ok' => true );
						},
					)
				);
			}
		);

		$this->reset_ability_registries();
		wp_get_ability( 'zestry-assumed/echo' )->execute( array( 'id' => '42' ) );
		$this->reset_ability_registries();

		$this->assertSame( '42', $received, 'WordPress passed the value through untouched.' );
	}

	/**
	 * A promoted parameter's default belongs to the parameter, so the property
	 * reports having none — which would publish every argument of a modern
	 * structure as required, and leave the defaults unapplied.
	 */
	public function test_a_promoted_default_is_invisible_to_the_property(): void {
		$property = new \ReflectionProperty( AssumedParcel::class, 'destination' );

		$this->assertTrue( $property->isPromoted() );
		$this->assertFalse( $property->hasDefaultValue(), 'Which is why the constructor is read instead.' );

		$parameter = ( new \ReflectionClass( AssumedParcel::class ) )->getConstructor()->getParameters()[0];

		$this->assertTrue( $parameter->isDefaultValueAvailable() );
		$this->assertSame( 'GB', $parameter->getDefaultValue() );
	}

	/**
	 * A structure is built without its constructor and its properties are then
	 * assigned, which is only possible while each one is still uninitialized.
	 * This is what lets a structure be readonly at all.
	 */
	public function test_reflection_can_assign_a_readonly_property_once(): void {
		$instance = ( new \ReflectionClass( AssumedParcel::class ) )->newInstanceWithoutConstructor();
		$property = new \ReflectionProperty( AssumedParcel::class, 'destination' );

		$this->assertFalse( $property->isInitialized( $instance ), 'The constructor never ran, so nothing set it.' );

		$property->setValue( $instance, 'FR' );

		$this->assertSame( 'FR', $instance->destination );

		$this->expectException( \Error::class );
		$property->setValue( $instance, 'DE' );
	}

	/**
	 * Build a request that expects one parameter of the given schema.
	 *
	 * `rest_validate_request_arg()` and its siblings read the schema off the
	 * request's attributes rather than taking it directly.
	 *
	 * @param array<string, mixed> $schema The parameter's schema.
	 * @return WP_REST_Request
	 */
	private function request_expecting( array $schema ): WP_REST_Request {
		$request = new WP_REST_Request( 'GET', '/zestry-test/v1/subject' );
		$request->set_attributes( array( 'args' => array( 'subject' => $schema ) ) );

		return $request;
	}

	/**
	 * Empty both ability registries, so this test's abilities are the only ones.
	 *
	 * @return void
	 */
	private function reset_ability_registries(): void {
		foreach ( array( \WP_Abilities_Registry::class, \WP_Ability_Categories_Registry::class ) as $registry ) {
			$instance = new \ReflectionProperty( $registry, 'instance' );
			$instance->setAccessible( true );
			$instance->setValue( null, null );
		}
	}
}
