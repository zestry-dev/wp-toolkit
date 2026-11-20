<?php

declare( strict_types=1 );

namespace Zestry\WPToolkit\Tests\Integration\Modules;

use Zestry\WPToolkit\Modules\Request\Request;
use Zestry\WPToolkit\Modules\Validation\ListOf;
use Zestry\WPToolkit\Modules\Validation\Property;
use Zestry\WPToolkit\Modules\Validation\Rules\Sanitize;
use Zestry\WPToolkit\Modules\Validation\Validation;
use Zestry\WPToolkit\Tests\Support\TestCase;

/**
 * A structure whose default is another structure, built in a promoted parameter
 * — the one place PHP 8.1 allows `new` in an initializer.
 */
final class ValidationEdgeBox {

	public function __construct(
		#[Property( 'How heavy.' )]
		public readonly int $weight = 0
	) {}
}

/**
 * A structure holding a list of structures.
 */
final class ValidationEdgeCrate {

	#[Property( 'What is in it.' ), ListOf( ValidationEdgeBox::class )]
	public array $boxes = array();
}

/**
 * A structure that contains a list of itself.
 */
final class ValidationEdgeTree {

	#[Property( 'Its children.' ), ListOf( ValidationEdgeTree::class )]
	public array $children = array();
}

/**
 * One half of a pair that reference each other.
 */
final class ValidationEdgePing {

	#[Property( 'The other one.' )]
	public ValidationEdgePong $pong;
}

/**
 * The other half.
 */
final class ValidationEdgePong {

	#[Property( 'The first one.' )]
	public ValidationEdgePing $ping;
}

/**
 * A base class an argument can be inherited from.
 */
abstract class ValidationEdgeBase {

	#[Property( 'Inherited.' )]
	public int $inherited = 0;
}

enum ValidationEdgeStatus: string {
	case Draft = 'draft';
	case Live  = 'live';
}

enum ValidationEdgeOrder {
	case Ascending;
	case Descending;
}

/**
 * Everything a declaration can get wrong, and everything a caller can send that
 * a schema would have stopped.
 *
 * @covers \Zestry\WPToolkit\Modules\Validation\Validation
 */
final class ValidationEdgeCasesTest extends TestCase {

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

	/**
	 * A schema is published as JSON, so a default that is an object is a default
	 * no caller can be told about.
	 */
	public function test_an_object_default_is_published_as_data(): void {
		$schema = $this->validation()->get_schema(
			new class() {
				#[Property( 'A box.' )]
				public ValidationEdgeBox $box;
			}
		);

		$this->assertSame( 0, $schema['properties']['box']['properties']['weight']['default'] );
	}

	/**
	 * An enum case is an object too, and the value a caller sends is its backing
	 * value -- so that is what the default has to say.
	 */
	public function test_an_enum_default_is_published_as_its_value(): void {
		$schema = $this->validation()->get_schema(
			new class() {
				#[Property( 'What state.' )]
				public ValidationEdgeStatus $status = ValidationEdgeStatus::Draft;

				#[Property( 'Which way.' )]
				public ValidationEdgeOrder $order = ValidationEdgeOrder::Ascending;
			}
		);

		$this->assertSame( 'draft', $schema['properties']['status']['default'] );
		$this->assertSame( 'Ascending', $schema['properties']['order']['default'] );
	}

	public function test_a_structure_default_built_in_a_promoted_parameter_is_published_as_data(): void {
		$target = new class() {
			#[Property( 'A box.' )]
			public ValidationEdgeBox $box;
		};

		$schema = $this->validation()->get_schema( $target );

		$this->assertIsArray( $schema, 'The schema must contain nothing but data.' );
		$this->assertSame( 'integer', $schema['properties']['box']['properties']['weight']['type'] );
	}

	/**
	 * A list of itself is the recursion the depth guard exists for -- reached
	 * through `ListOf` rather than through a property type.
	 */
	public function test_a_structure_holding_a_list_of_itself_throws(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'nests more than' );

		$this->validation()->get_schema(
			new class() {
				#[Property( 'A tree.' )]
				public ValidationEdgeTree $tree;
			}
		);
	}

	public function test_two_structures_referencing_each_other_throw(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'nests more than' );

		$this->validation()->get_schema(
			new class() {
				#[Property( 'A ping.' )]
				public ValidationEdgePing $ping;
			}
		);
	}

	public function test_a_class_that_does_not_exist_throws_clearly(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'NoSuchStructure' );

		$this->validation()->get_schema(
			new class() {
				#[Property( 'Nothing.' ), ListOf( 'NoSuchStructure' )]
				public array $things;
			}
		);
	}

	/**
	 * `ListOf` says what a list holds. On anything but an array it describes
	 * nothing, so it is a declaration that quietly does not apply.
	 */
	public function test_list_of_on_something_that_is_not_an_array_throws(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'ListOf' );

		$this->validation()->get_schema(
			new class() {
				#[Property( 'Which one.' ), ListOf( ValidationEdgeBox::class )]
				public int $id;
			}
		);
	}

	/**
	 * A static property is shared by every instance of the class, so binding one
	 * would carry one caller's argument into the next one's call. Ignoring it
	 * would leave the author waiting for a value that never arrives, so it is
	 * refused instead.
	 */
	public function test_a_static_property_is_refused_as_an_argument(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'is static' );

		$this->validation()->get_schema( new ValidationEdgeStatic() );
	}

	public function test_an_inherited_argument_is_declared_too(): void {
		$schema = $this->validation()->get_schema(
			new class() extends ValidationEdgeBase {
				#[Property( 'Its own.' )]
				public string $own = '';
			}
		);

		$this->assertArrayHasKey( 'inherited', $schema['properties'] );
		$this->assertArrayHasKey( 'own', $schema['properties'] );
	}

	/**
	 * Refused where it was sent, naming the property -- rather than a TypeError
	 * from inside reflection that says nothing about which one was wrong.
	 */
	public function test_a_scalar_sent_where_a_structure_belongs_is_refused_by_name(): void {
		$target = new class() {
			#[Property( 'A box.' )]
			public ValidationEdgeBox $box;
		};

		$refused = $this->validation()->resolve( $target, array( 'box' => 'not a box' ) );

		$this->assertInstanceOf( \WP_Error::class, $refused );
		$this->assertSame( array( 'box' ), array_keys( $refused->get_error_data()['errors'] ) );
		$this->assertSame( array( 'box' ), $refused->get_error_data()['params'] );
	}

	public function test_null_sent_for_an_argument_that_does_not_accept_it_is_refused_by_name(): void {
		$target = new class() {
			#[Property( 'Which one.' )]
			public int $id;
		};

		$refused = $this->validation()->resolve( $target, array( 'id' => null ) );

		$this->assertInstanceOf( \WP_Error::class, $refused );
		$this->assertArrayHasKey( 'id', $refused->get_error_data()['errors'] );
	}

	/**
	 * A route or an ability outlives one call -- the module holds one instance
	 * and binds it per request -- so a readonly property could be assigned once
	 * and would fatal on the next call.
	 */
	public function test_a_readonly_argument_on_a_reused_object_throws_clearly(): void {
		$target = new class() {
			#[Property( 'Which one.' )]
			public readonly int $id;
		};

		$this->fill( $target, array( 'id' => 1 ) );

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'id' );

		$this->fill( $target, array( 'id' => 2 ) );
	}

	/**
	 * A structure is built once per call, so its properties are assigned exactly
	 * once in their lifetime -- which is what readonly asks for.
	 */
	public function test_readonly_works_on_a_structure(): void {
		$target = new class() {
			#[Property( 'A box.' )]
			public ValidationEdgeBox $box;
		};

		$this->fill( $target, array( 'box' => array( 'weight' => 1 ) ) );
		$first = $target->box;

		$this->fill( $target, array( 'box' => array( 'weight' => 2 ) ) );

		$this->assertSame( 1, $first->weight, 'The first call keeps the value it was built with.' );
		$this->assertSame( 2, $target->box->weight, 'The second call gets a structure of its own.' );
	}

	/**
	 * A list of structures given something that is not one per item: the refusal
	 * says which item.
	 */
	public function test_a_list_of_structures_given_scalars_is_refused_by_item(): void {
		$target = new class() {
			#[Property( 'Some boxes.' ), ListOf( ValidationEdgeBox::class )]
			public array $boxes;
		};

		$refused = $this->validation()->resolve( $target, array( 'boxes' => array( 'nope' ) ) );

		$this->assertInstanceOf( \WP_Error::class, $refused );
		$this->assertSame( array( 'boxes[0]' ), array_keys( $refused->get_error_data()['errors'] ) );
		$this->assertSame( array( 'boxes' ), $refused->get_error_data()['params'] );
	}

	/**
	 * A protected property is an argument too — the same rule module injection
	 * uses, since reflection can reach it and a subclass can read it.
	 */
	public function test_a_protected_property_is_an_argument(): void {
		$target = new class() {
			#[Property( 'Which one.' )]
			protected int $id = 0;

			public function id(): int {
				return $this->id;
			}
		};

		$this->assertArrayHasKey( 'id', $this->validation()->get_schema( $target )['properties'] );

		$this->fill( $target, array( 'id' => 7 ) );

		$this->assertSame( 7, $target->id() );
	}

	/**
	 * A private one is left alone entirely: reflection cannot reliably reach one
	 * declared on an ancestor, so binding it would work in some class
	 * hierarchies and not others.
	 */
	public function test_a_private_property_is_not_an_argument(): void {
		$target = new class() {
			#[Property( 'Which one.' )]
			private int $id = 0;

			public function id(): int {
				return $this->id;
			}
		};

		$this->assertSame( array(), $this->validation()->get_schema( $target ) );

		$this->fill( $target, array( 'id' => 7 ) );

		$this->assertSame( 0, $target->id(), 'Nothing was bound.' );
	}

	/**
	 * Null is a value a caller can send, and a nullable structure has to take it
	 * rather than try to build one out of it.
	 */
	public function test_null_binds_to_a_nullable_structure(): void {
		$target = new class() {
			#[Property( 'A box, or none.' )]
			public ?ValidationEdgeBox $box = null;
		};

		$this->fill( $target, array( 'box' => array( 'weight' => 3 ) ) );
		$this->assertSame( 3, $target->box->weight );

		$this->fill( $target, array( 'box' => null ) );
		$this->assertNull( $target->box );
	}

	/**
	 * Structures nest as deeply as the data does, and each level is described in
	 * full — a list inside a structure inside a list.
	 */
	public function test_a_structure_holding_a_list_of_structures_is_described_in_full(): void {
		$schema = $this->validation()->get_schema(
			new class() {
				#[Property( 'Every crate.' ), ListOf( ValidationEdgeCrate::class )]
				public array $crates;
			}
		);

		$boxes = $schema['properties']['crates']['items']['properties']['boxes'];

		$this->assertSame( 'array', $boxes['type'] );
		$this->assertSame( 'integer', $boxes['items']['properties']['weight']['type'] );
	}

	public function test_a_route_with_no_arguments_publishes_none(): void {
		$target = new class() {
			public string $not_an_argument = '';
		};

		$this->assertSame( array(), $this->request()->get_rest_args( $target ) );
		$this->assertSame( array(), $this->validation()->get_file_properties( $target ) );
		$this->assertSame( array(), $this->validation()->get_schema( $target ) );
	}

	/**
	 * A callback is handed the value as its declared type, so one that cannot
	 * take that type fails — and PHP's own message names the callback's
	 * parameter and a line of library code, neither of which is the declaration
	 * that paired them.
	 */
	public function test_a_callback_that_cannot_take_its_value_names_the_argument(): void {
		$target = new class() {
			#[Property( 'Which one.' ), Sanitize( 'str_split' )]
			public int $id = 0;
		};

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'on "id" cannot clean the value' );

		$this->validation()->resolve( $target, array( 'id' => 7 ), 'invalid_input' );
	}

	/**
	 * A value is assigned strictly, so a sanitizer returning a numeric string
	 * for an `int` property is a bug in the sanitizer, named with the property,
	 * rather than coerced past.
	 */
	public function test_a_sanitizer_returning_the_wrong_type_is_refused_by_name(): void {
		$target = new class() {
			#[Property( 'Which one.' ), Sanitize( 'strval' )]
			public int $id = 0;
		};

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'The value given for "id" is not what it was declared as' );

		$this->fill( $target, array( 'id' => 7 ) );
	}

	/**
	 * An empty list is a legitimate answer, not a missing one.
	 */
	public function test_an_empty_list_binds_as_an_empty_list(): void {
		$target = new class() {
			#[Property( 'Some boxes.' ), ListOf( ValidationEdgeBox::class )]
			public array $boxes = array( 'unset' );
		};

		$this->fill( $target, array( 'boxes' => array() ) );

		$this->assertSame( array(), $target->boxes );
	}

	/**
	 * A structure given keys it never declared: extra data is the caller's
	 * problem, not a reason to refuse what was asked for.
	 */
	public function test_unknown_keys_in_a_structure_are_ignored(): void {
		$target = new class() {
			#[Property( 'A box.' )]
			public ValidationEdgeBox $box;
		};

		$this->fill( $target, array( 'box' => array( 'weight' => 5, 'colour' => 'red' ) ) );

		$this->assertSame( 5, $target->box->weight );
	}

	public function test_prepare_leaves_an_absent_argument_alone(): void {
		$target = new class() {
			#[Property( 'A name.' ), Sanitize( 'trim' )]
			public string $name = 'unset';
		};

		$this->assertSame( array(), $this->validation()->resolve( $target, array(), 'invalid_input' ) );
	}

	/**
	 * A structure is filled from the request and nothing else. It is the one place
	 * a public typed Service property is *not* injected -- `parse()` builds the
	 * instance and fills it, and never wires it -- so the docs say to
	 * reach for a service on the route or ability instead.
	 *
	 * @return void
	 */
	public function test_a_structure_is_filled_from_the_request_and_not_wired(): void {
		$structure = $this->validation()->parse( ValidationEdgeUnwired::class, array( 'label' => 'x' ) );

		$this->assertSame( 'x', $structure->label );
		$this->assertFalse(
			( new \ReflectionProperty( $structure, 'path' ) )->isInitialized( $structure ),
			'Injecting here would make a structure depend on the container that built it.'
		);
	}
}

/**
 * A class carrying a static property marked as an argument.
 */
// phpcs:ignore PSR1.Classes.ClassDeclaration.MultipleClasses
final class ValidationEdgeStatic {

	#[Property( 'Shared by everyone.' )]
	public static string $shared = '';

	#[Property( 'Its own.' )]
	public string $own = '';
}

/**
 * A structure declaring a service property, to prove it is left alone.
 */
// phpcs:ignore PSR1.Classes.ClassDeclaration.MultipleClasses
final class ValidationEdgeUnwired {

	/**
	 * @var \Zestry\WPToolkit\Modules\Path
	 */
	public \Zestry\WPToolkit\Modules\Path $path;

	#[Property( 'A label.' )]
	public string $label = '';
}
