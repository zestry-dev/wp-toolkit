<?php

declare( strict_types=1 );

namespace Zestry\WPToolkit\Tests\Integration\Services;

use Zestry\WPToolkit\Modules\Request\Attributes\RequestArgument;
use Zestry\WPToolkit\Modules\Request\Request;
use Zestry\WPToolkit\Tests\Support\TestCase;

/**
 * A structure whose default is another structure, built in a promoted parameter
 * — the one place PHP 8.1 allows `new` in an initializer.
 */
final class EdgeBox {

	public function __construct(
		#[RequestArgument( 'How heavy.' )]
		public readonly int $weight = 0
	) {}
}

/**
 * A structure holding a list of structures.
 */
final class EdgeCrate {

	#[RequestArgument( 'What is in it.', of: EdgeBox::class )]
	public array $boxes = array();
}

/**
 * A structure that contains a list of itself.
 */
final class EdgeTree {

	#[RequestArgument( 'Its children.', of: EdgeTree::class )]
	public array $children = array();
}

/**
 * One half of a pair that reference each other.
 */
final class EdgePing {

	#[RequestArgument( 'The other one.' )]
	public EdgePong $pong;
}

/**
 * The other half.
 */
final class EdgePong {

	#[RequestArgument( 'The first one.' )]
	public EdgePing $ping;
}

/**
 * A base class an argument can be inherited from.
 */
abstract class EdgeBase {

	#[RequestArgument( 'Inherited.' )]
	public int $inherited = 0;
}

/**
 * Everything a declaration can get wrong, and everything a caller can send that
 * a schema would have stopped.
 *
 * @covers \Zestry\WPToolkit\Modules\Request\Request
 */
final class RequestEdgeCasesTest extends TestCase {

	private function request(): Request {
		return $this->plugin->get( Request::class );
	}

	/**
	 * A schema is published as JSON, so a default that is an object is a default
	 * no caller can be told about.
	 */
	public function test_an_object_default_is_published_as_data(): void {
		$schema = $this->request()->get_schema(
			new class() {
				#[RequestArgument( 'A box.' )]
				public EdgeBox $box;
			}
		);

		$this->assertSame( 0, $schema['properties']['box']['properties']['weight']['default'] );
	}

	/**
	 * An enum case is an object too, and the value a caller sends is its backing
	 * value -- so that is what the default has to say.
	 */
	public function test_an_enum_default_is_published_as_its_value(): void {
		$schema = $this->request()->get_schema(
			new class() {
				#[RequestArgument( 'What state.' )]
				public RequestTestStatus $status = RequestTestStatus::Draft;

				#[RequestArgument( 'Which way.' )]
				public RequestTestOrder $order = RequestTestOrder::Ascending;
			}
		);

		$this->assertSame( 'draft', $schema['properties']['status']['default'] );
		$this->assertSame( 'Ascending', $schema['properties']['order']['default'] );
	}

	public function test_a_structure_default_built_in_a_promoted_parameter_is_published_as_data(): void {
		$target = new class() {
			#[RequestArgument( 'A box.' )]
			public EdgeBox $box;
		};

		$schema = $this->request()->get_schema( $target );

		$this->assertIsArray( $schema, 'The schema must contain nothing but data.' );
		$this->assertSame( 'integer', $schema['properties']['box']['properties']['weight']['type'] );
	}

	/**
	 * A list of itself is the recursion the depth guard exists for -- reached
	 * through `of:` rather than through a property type.
	 */
	public function test_a_structure_holding_a_list_of_itself_throws(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'nest more than' );

		$this->request()->get_schema(
			new class() {
				#[RequestArgument( 'A tree.' )]
				public EdgeTree $tree;
			}
		);
	}

	public function test_two_structures_referencing_each_other_throw(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'nest more than' );

		$this->request()->get_schema(
			new class() {
				#[RequestArgument( 'A ping.' )]
				public EdgePing $ping;
			}
		);
	}

	public function test_a_class_that_does_not_exist_throws_clearly(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'NoSuchStructure' );

		$this->request()->get_schema(
			new class() {
				#[RequestArgument( 'Nothing.', of: 'NoSuchStructure' )]
				public array $things;
			}
		);
	}

	/**
	 * `of:` says what a list holds. On anything but a list it describes nothing,
	 * so it is a declaration that quietly does not apply.
	 */
	public function test_of_on_something_that_is_not_a_list_throws(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'of:' );

		$this->request()->get_schema(
			new class() {
				#[RequestArgument( 'Which one.', of: EdgeBox::class )]
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

		$this->request()->get_schema( new EdgeStatic() );
	}

	public function test_an_inherited_argument_is_declared_too(): void {
		$schema = $this->request()->get_schema(
			new class() extends EdgeBase {
				#[RequestArgument( 'Its own.' )]
				public string $own = '';
			}
		);

		$this->assertArrayHasKey( 'inherited', $schema['properties'] );
		$this->assertArrayHasKey( 'own', $schema['properties'] );
	}

	/**
	 * Reachable only when a hand-written schema no longer agrees with the
	 * property it fills, but a TypeError from inside reflection says nothing
	 * about which argument was wrong.
	 */
	public function test_a_scalar_sent_where_a_structure_belongs_throws_clearly(): void {
		$target = new class() {
			#[RequestArgument( 'A box.' )]
			public EdgeBox $box;
		};

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'built from an object' );

		$this->request()->bind( $target, array( 'box' => 'not a box' ) );
	}

	public function test_null_sent_for_an_argument_that_does_not_accept_it_throws_clearly(): void {
		$target = new class() {
			#[RequestArgument( 'Which one.' )]
			public int $id;
		};

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'id' );

		$this->request()->bind( $target, array( 'id' => null ) );
	}

	/**
	 * A route or an ability outlives one call -- the module holds one instance
	 * and binds it per request -- so a readonly property could be assigned once
	 * and would fatal on the next call.
	 */
	public function test_a_readonly_argument_on_a_reused_object_throws_clearly(): void {
		$target = new class() {
			#[RequestArgument( 'Which one.' )]
			public readonly int $id;
		};

		$this->request()->bind( $target, array( 'id' => 1 ) );

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'id' );

		$this->request()->bind( $target, array( 'id' => 2 ) );
	}

	/**
	 * A structure is built once per call, so its properties are assigned exactly
	 * once in their lifetime -- which is what readonly asks for.
	 */
	public function test_readonly_works_on_a_structure(): void {
		$target = new class() {
			#[RequestArgument( 'A box.' )]
			public EdgeBox $box;
		};

		$this->request()->bind( $target, array( 'box' => array( 'weight' => 1 ) ) );
		$first = $target->box;

		$this->request()->bind( $target, array( 'box' => array( 'weight' => 2 ) ) );

		$this->assertSame( 1, $first->weight, 'The first call keeps the value it was built with.' );
		$this->assertSame( 2, $target->box->weight, 'The second call gets a structure of its own.' );
	}

	/**
	 * A list of structures given something that is not one per item.
	 */
	public function test_a_list_of_structures_given_scalars_throws_clearly(): void {
		$target = new class() {
			#[RequestArgument( 'Some boxes.', of: EdgeBox::class )]
			public array $boxes;
		};

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'built from an object' );

		$this->request()->bind( $target, array( 'boxes' => array( 'nope' ) ) );
	}

	/**
	 * A protected property is an argument too — the same rule module injection
	 * uses, since reflection can reach it and a subclass can read it.
	 */
	public function test_a_protected_property_is_an_argument(): void {
		$target = new class() {
			#[RequestArgument( 'Which one.' )]
			protected int $id = 0;

			public function id(): int {
				return $this->id;
			}
		};

		$this->assertArrayHasKey( 'id', $this->request()->get_schema( $target )['properties'] );

		$this->request()->bind( $target, array( 'id' => 7 ) );

		$this->assertSame( 7, $target->id() );
	}

	/**
	 * A private one is left alone entirely: reflection cannot reliably reach one
	 * declared on an ancestor, so binding it would work in some class
	 * hierarchies and not others.
	 */
	public function test_a_private_property_is_not_an_argument(): void {
		$target = new class() {
			#[RequestArgument( 'Which one.' )]
			private int $id = 0;

			public function id(): int {
				return $this->id;
			}
		};

		$this->assertSame( array(), $this->request()->get_schema( $target ) );

		$this->request()->bind( $target, array( 'id' => 7 ) );

		$this->assertSame( 0, $target->id(), 'Nothing was bound.' );
	}

	/**
	 * Null is a value a caller can send, and a nullable structure has to take it
	 * rather than try to build one out of it.
	 */
	public function test_null_binds_to_a_nullable_structure(): void {
		$target = new class() {
			#[RequestArgument( 'A box, or none.' )]
			public ?EdgeBox $box = null;
		};

		$this->request()->bind( $target, array( 'box' => array( 'weight' => 3 ) ) );
		$this->assertSame( 3, $target->box->weight );

		$this->request()->bind( $target, array( 'box' => null ) );
		$this->assertNull( $target->box );
	}

	/**
	 * Structures nest as deeply as the data does, and each level is described in
	 * full — a list inside a structure inside a list.
	 */
	public function test_a_structure_holding_a_list_of_structures_is_described_in_full(): void {
		$schema = $this->request()->get_schema(
			new class() {
				#[RequestArgument( 'Every crate.', of: EdgeCrate::class )]
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
		$this->assertSame( array(), $this->request()->get_file_arguments( $target ) );
		$this->assertSame( array(), $this->request()->get_schema( $target ) );
	}

	/**
	 * A callback is handed the value as its declared type, so one that cannot
	 * take that type fails — and PHP's own message names the callback's
	 * parameter and a line of library code, neither of which is the declaration
	 * that paired them.
	 */
	public function test_a_callback_that_cannot_take_its_value_names_the_argument(): void {
		$target = new class() {
			#[RequestArgument( 'Which one.', sanitize: 'str_split' )]
			public int $id = 0;
		};

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'The sanitize callback for "id"' );

		$this->request()->get_prepared_values( $target, array( 'id' => 7 ), 'invalid_input' );
	}

	/**
	 * Assigning by reflection coerces where PHP would, so a sanitizer returning
	 * a numeric string for an `int` argument is not an error.
	 */
	public function test_a_sanitizer_returning_a_coercible_value_is_fine(): void {
		$target = new class() {
			#[RequestArgument( 'Which one.', sanitize: 'strval' )]
			public int $id = 0;
		};

		$this->request()->bind(
			$target,
			$this->request()->get_prepared_values( $target, array( 'id' => 7 ), 'invalid_input' )
		);

		$this->assertSame( 7, $target->id );
	}

	/**
	 * An empty list is a legitimate answer, not a missing one.
	 */
	public function test_an_empty_list_binds_as_an_empty_list(): void {
		$target = new class() {
			#[RequestArgument( 'Some boxes.', of: EdgeBox::class )]
			public array $boxes = array( 'unset' );
		};

		$this->request()->bind( $target, array( 'boxes' => array() ) );

		$this->assertSame( array(), $target->boxes );
	}

	/**
	 * A structure given keys it never declared: extra data is the caller's
	 * problem, not a reason to refuse what was asked for.
	 */
	public function test_unknown_keys_in_a_structure_are_ignored(): void {
		$target = new class() {
			#[RequestArgument( 'A box.' )]
			public EdgeBox $box;
		};

		$this->request()->bind( $target, array( 'box' => array( 'weight' => 5, 'colour' => 'red' ) ) );

		$this->assertSame( 5, $target->box->weight );
	}

	public function test_prepare_leaves_an_absent_argument_alone(): void {
		$target = new class() {
			#[RequestArgument( 'A name.', validate: 'is_string', sanitize: 'trim' )]
			public string $name = 'unset';
		};

		$this->assertSame( array(), $this->request()->get_prepared_values( $target, array(), 'invalid_input' ) );
	}

	/**
	 * A structure is filled from the request and nothing else. It is the one place
	 * a public typed Service property is *not* injected -- `hydrate()` builds the
	 * instance and binds values onto it, and never wires it -- so the docs say to
	 * reach for a service on the route or ability instead.
	 *
	 * @return void
	 */
	public function test_a_structure_is_filled_from_the_request_and_not_wired(): void {
		$structure = $this->request()->hydrate( EdgeUnwired::class, array( 'label' => 'x' ) );

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
final class EdgeStatic {

	#[RequestArgument( 'Shared by everyone.' )]
	public static string $shared = '';

	#[RequestArgument( 'Its own.' )]
	public string $own = '';
}

/**
 * A structure declaring a service property, to prove it is left alone.
 */
// phpcs:ignore PSR1.Classes.ClassDeclaration.MultipleClasses
final class EdgeUnwired {

	/**
	 * @var \Zestry\WPToolkit\Modules\Path
	 */
	public \Zestry\WPToolkit\Modules\Path $path;

	#[RequestArgument( 'A label.' )]
	public string $label = '';
}
