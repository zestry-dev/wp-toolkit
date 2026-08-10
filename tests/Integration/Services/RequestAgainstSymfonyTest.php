<?php

declare( strict_types=1 );

namespace Zestry\WPToolkit\Tests\Integration\Services;

use Zestry\WPToolkit\Services\Request\Attributes\RequestArgument;
use Zestry\WPToolkit\Services\Request\Request;
use Zestry\WPToolkit\Tests\Support\TestCase;
use Symfony\Component\PropertyInfo\Extractor\ReflectionExtractor;
use Symfony\Component\Serializer\Normalizer\ArrayDenormalizer;
use Symfony\Component\Serializer\Normalizer\BackedEnumNormalizer;
use Symfony\Component\Serializer\Normalizer\DateTimeNormalizer;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;

/**
 * A structure Symfony and this service each build from the same array.
 */
final class SymfonyParcel {

	#[RequestArgument( 'Street and number.' )]
	public string $line1;

	#[RequestArgument( 'How heavy, in grams.' )]
	public int $weight = 0;

	#[RequestArgument( 'Whether it is fragile.' )]
	public bool $fragile = false;
}

/**
 * The same declarations read by an implementation that shares none of this
 * one's assumptions.
 *
 * Symfony cannot be a dependency here -- this toolkit is copied into a plugin
 * rather than required, so it carries none -- but as a dev dependency it makes
 * a second opinion available where the two libraries genuinely agree: what a
 * property's type is, whether it accepts null, and what an array turns into.
 * Where they disagree, they disagree on purpose, and those are noted below
 * rather than papered over.
 *
 * @covers \Zestry\WPToolkit\Services\Request\Request
 */
final class RequestAgainstSymfonyTest extends TestCase {

	private function request(): Request {
		return $this->plugin->get( Request::class );
	}

	private function serializer(): Serializer {
		return new Serializer(
			array(
				new BackedEnumNormalizer(),
				new DateTimeNormalizer(),
				new ArrayDenormalizer(),
				new ObjectNormalizer(),
			)
		);
	}

	/**
	 * The type this service publishes has to be the type the property actually
	 * declares, and Symfony's extractor reads that independently.
	 *
	 * Nullability has to match outright. The type itself matches for everything
	 * PHP and JSON both have a word for -- and deliberately does not for a date
	 * or an enum, which are objects in PHP and strings on the wire. The provider
	 * states each expectation rather than deriving it, so a divergence has to be
	 * written down to pass.
	 *
	 * @dataProvider properties_to_compare
	 */
	public function test_the_derived_type_agrees_with_symfonys_extractor( string $property, string $php_type, string $wire_type, bool $nullable ): void {
		$extracted = ( new ReflectionExtractor() )->getTypes( SymfonyTypes::class, $property );

		$this->assertNotNull( $extracted, sprintf( 'Symfony reads a type for $%s.', $property ) );
		$this->assertSame( $php_type, $extracted[0]->getBuiltinType(), sprintf( 'Symfony reads $%s as declared.', $property ) );
		$this->assertSame( $nullable, $extracted[0]->isNullable() );

		$schema = $this->request()->get_schema( SymfonyTypes::class )['properties'][ $property ];
		$type   = (array) $schema['type'];

		$this->assertSame(
			$nullable,
			in_array( 'null', $type, true ),
			sprintf( 'Both agree on whether $%s accepts null.', $property )
		);

		$this->assertSame( $wire_type, $type[0], sprintf( 'The schema says what $%s looks like on the wire.', $property ) );
	}

	/**
	 * @return array<string, array{string, string, string, bool}>
	 */
	public function properties_to_compare(): array {
		return array(
			// Property, what Symfony reads in PHP, what the schema publishes, nullable.
			'int'             => array( 'count', 'int', 'integer', false ),
			'float'           => array( 'ratio', 'float', 'number', false ),
			'string'          => array( 'label', 'string', 'string', false ),
			'bool'            => array( 'enabled', 'bool', 'boolean', false ),
			'nullable int'    => array( 'maybe_count', 'int', 'integer', true ),
			'nullable string' => array( 'maybe_label', 'string', 'string', true ),
			'object'          => array( 'parcel', 'object', 'object', false ),
			'nullable object' => array( 'maybe_parcel', 'object', 'object', true ),
			// An object in PHP, a string in JSON -- there is no other way to send one.
			'date'            => array( 'when', 'object', 'string', false ),
			'enum'            => array( 'status', 'object', 'string', false ),
		);
	}

	/**
	 * Both turn the same array into the same object, which is the part of this
	 * service that could quietly hydrate something plausible but wrong.
	 */
	public function test_a_structure_is_built_the_same_way_symfony_builds_it(): void {
		$values = array(
			'line1'   => '1 Example Street',
			'weight'  => 500,
			'fragile' => true,
		);

		$theirs = $this->serializer()->denormalize( $values, SymfonyParcel::class );
		$ours   = $this->request()->hydrate( SymfonyParcel::class, $values );

		$this->assertEquals( $theirs, $ours );
	}

	/**
	 * A key the structure never declared: Symfony's ObjectNormalizer ignores it
	 * by default, and so does this.
	 */
	public function test_an_unknown_key_is_ignored_by_both(): void {
		$values = array( 'line1' => '1 Example Street', 'colour' => 'red' );

		$theirs = $this->serializer()->denormalize( $values, SymfonyParcel::class );
		$ours   = $this->request()->hydrate( SymfonyParcel::class, $values );

		$this->assertEquals( $theirs, $ours );
		$this->assertSame( 0, $ours->weight, 'The declared default stands.' );
	}

	public function test_a_list_of_structures_is_built_the_same_way(): void {
		$values = array(
			array( 'line1' => 'One', 'weight' => 1 ),
			array( 'line1' => 'Two', 'weight' => 2 ),
		);

		$theirs = $this->serializer()->denormalize( $values, SymfonyParcel::class . '[]' );

		$target = new class() {
			#[RequestArgument( 'Some parcels.', of: SymfonyParcel::class )]
			public array $parcels;
		};

		$this->request()->bind( $target, array( 'parcels' => $values ) );

		$this->assertEquals( $theirs, $target->parcels );
	}

	public function test_a_backed_enum_resolves_to_the_same_case(): void {
		$theirs = $this->serializer()->denormalize( 'draft', RequestTestStatus::class );

		$target = new class() {
			#[RequestArgument( 'What state.' )]
			public RequestTestStatus $status;
		};

		$this->request()->bind( $target, array( 'status' => 'draft' ) );

		$this->assertSame( $theirs, $target->status );
	}

	public function test_a_date_resolves_to_the_same_moment(): void {
		$theirs = $this->serializer()->denormalize( '2026-08-04T12:00:00+00:00', \DateTimeImmutable::class );

		$target = new class() {
			#[RequestArgument( 'When.' )]
			public \DateTimeImmutable $when;
		};

		$this->request()->bind( $target, array( 'when' => '2026-08-04T12:00:00+00:00' ) );

		$this->assertEquals( $theirs, $target->when );
	}

	/**
	 * Where the two part company, on purpose.
	 *
	 * Symfony denormalizes a pure enum by *name* only when told to; this service
	 * always does, because a schema has to publish something a caller can send
	 * and a pure enum has no other value to offer. The point of the assertion is
	 * that the value published in the schema is the value that binds.
	 */
	public function test_a_pure_enum_binds_by_the_name_the_schema_publishes(): void {
		$schema = $this->request()->get_schema(
			new class() {
				#[RequestArgument( 'Which way.' )]
				public RequestTestOrder $order;
			}
		);

		$published = $schema['properties']['order']['enum'][1];

		$target = new class() {
			#[RequestArgument( 'Which way.' )]
			public RequestTestOrder $order;
		};

		$this->request()->bind( $target, array( 'order' => $published ) );

		$this->assertSame( RequestTestOrder::Descending, $target->order );
	}
}

/**
 * Every type the comparison covers, on one class.
 */
// phpcs:ignore PSR1.Classes.ClassDeclaration.MultipleClasses
final class SymfonyTypes {

	#[RequestArgument( 'A count.' )]
	public int $count;

	#[RequestArgument( 'A ratio.' )]
	public float $ratio;

	#[RequestArgument( 'A label.' )]
	public string $label;

	#[RequestArgument( 'Whether enabled.' )]
	public bool $enabled;

	#[RequestArgument( 'A count, maybe.' )]
	public ?int $maybe_count = null;

	#[RequestArgument( 'A label, maybe.' )]
	public ?string $maybe_label = null;

	#[RequestArgument( 'A parcel.' )]
	public SymfonyParcel $parcel;

	#[RequestArgument( 'A parcel, maybe.' )]
	public ?SymfonyParcel $maybe_parcel = null;

	#[RequestArgument( 'When.' )]
	public \DateTimeImmutable $when;

	#[RequestArgument( 'What state.' )]
	public RequestTestStatus $status;
}
