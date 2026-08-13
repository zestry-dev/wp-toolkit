<?php

declare( strict_types=1 );

namespace Zestry\WPToolkit\Tests\Integration\Core;

use Zestry\WPToolkit\Kernel\Abstracts\Module;
use Zestry\WPToolkit\Kernel\Abstracts\Service;
use Zestry\WPToolkit\Kernel\Attributes\NoInject;
use Zestry\WPToolkit\Services\Path;
use Zestry\WPToolkit\Services\Views;
use Zestry\WPToolkit\Tests\Support\TestCase;

/**
 * Behavior of the #[NoInject] attribute and the injection pass that honors it.
 *
 * The attribute class itself is empty, so its meaning lives entirely in
 * WithPlugin::_inject_services(): the pass must skip a #[NoInject] property while
 * still injecting an unmarked one. To keep the #[NoInject] "skip" branch honest
 * (a skip is only meaningful next to the branches that do inject or skip for
 * other reasons), this file drives every branch of _inject_services() and both
 * plugin accessors it relies on.
 *
 * @covers \Zestry\WPToolkit\Kernel\Attributes\NoInject
 * @covers \Zestry\WPToolkit\Kernel\Traits\WithPlugin
 */
final class NoInjectTest extends TestCase {

	public function test_unmarked_module_property_is_injected(): void {
		$consumer = $this->plugin->get( NoInjectProbe::class );

		$this->assertInstanceOf(
			Path::class,
			$consumer->injected,
			'A module-typed property without #[NoInject] must be injected.'
		);
	}

	public function test_marked_module_property_is_left_untouched(): void {
		$consumer = $this->plugin->get( NoInjectProbe::class );

		$this->assertNull(
			$consumer->skipped_value(),
			'A module-typed property marked #[NoInject] must be left as declared (null).'
		);
	}

	public function test_no_inject_attribute_is_present_and_constructible(): void {
		$property   = new \ReflectionProperty( NoInjectProbe::class, 'skipped' );
		$attributes = $property->getAttributes( NoInject::class );

		$this->assertCount(
			1,
			$attributes,
			'The #[NoInject] attribute must be discoverable on the marked property.'
		);
		$this->assertInstanceOf(
			NoInject::class,
			$attributes[0]->newInstance(),
			'#[NoInject] must be constructible via reflection.'
		);
	}

	public function test_unmarked_property_has_no_no_inject_attribute(): void {
		$property = new \ReflectionProperty( NoInjectProbe::class, 'injected' );

		$this->assertCount(
			0,
			$property->getAttributes( NoInject::class ),
			'The injected property must not carry the #[NoInject] attribute.'
		);
	}

	/**
	 * Drives every non-injecting branch of _inject_services() in one probe so the
	 * #[NoInject] skip is verified alongside the other reasons a property is left
	 * alone: no type, builtin scalar, union type, and a non-module class type.
	 * Only the two unmarked module-typed properties (one non-nullable, one
	 * nullable) may be populated.
	 */
	public function test_only_unmarked_module_typed_properties_are_injected(): void {
		$probe = $this->plugin->get( InjectionBranchProbe::class );

		// Unmarked module-typed properties are injected (nullable and non-nullable
		// alike), proving the final assignment branch runs.
		$this->assertInstanceOf(
			Path::class,
			$probe->injected,
			'An unmarked, non-nullable module property must be injected.'
		);
		$this->assertInstanceOf(
			Views::class,
			$probe->nullable_value(),
			'A nullable ?Module property without #[NoInject] must still be injected.'
		);

		// #[NoInject] on a module-typed property: skipped.
		$this->assertNull(
			$probe->skipped_value(),
			'A module-typed property marked #[NoInject] must be left untouched.'
		);

		// Untyped property: no ReflectionType, left as caller state.
		$this->assertSame(
			'untouched',
			$probe->untyped,
			'An untyped property must be left as caller-owned state.'
		);

		// Builtin scalar type: skipped by the isBuiltin() guard.
		$this->assertSame(
			42,
			$probe->scalar,
			'A builtin-typed (int) property must be left as caller-owned state.'
		);

		// Union type: not a ReflectionNamedType, skipped by the first guard clause.
		$this->assertNull(
			$probe->union_value(),
			'A union-typed property must be left as caller-owned state.'
		);

		// Named class type that is not a Module subclass: skipped by is_subclass_of.
		$this->assertNull(
			$probe->non_module_value(),
			'A non-module class-typed property must be left as caller-owned state.'
		);
	}

	public function test_set_plugin_and_get_plugin_round_trip(): void {
		$probe = new class() extends Service {};
		$probe->set_plugin( $this->plugin );

		$this->assertSame(
			$this->plugin,
			$probe->get_plugin(),
			'get_plugin() must return the instance stored by set_plugin().'
		);
	}
}

// --- Fixtures ---------------------------------------------------------------

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound -- test fixtures.

final class NoInjectProbe extends Service {

	/**
	 * Injected by the plugin: typed Module property without #[NoInject].
	 *
	 * @var Path
	 */
	public Path $injected;

	/**
	 * Opted out of injection: typed Module property marked #[NoInject].
	 *
	 * @var Path|null
	 */
	#[NoInject]
	protected ?Path $skipped = null;

	public function skipped_value(): ?Path {
		return $this->skipped;
	}
}

/**
 * One property per branch of WithPlugin::_inject_services().
 */
final class InjectionBranchProbe extends Service {

	/**
	 * Unmarked, non-nullable module type: injected.
	 *
	 * @var Path
	 */
	public Path $injected;

	/**
	 * Unmarked, nullable module type: injected (the ?? default is overwritten).
	 *
	 * @var Views|null
	 */
	protected ?Views $nullable = null;

	/**
	 * Module type opted out via #[NoInject]: skipped.
	 *
	 * @var Path|null
	 */
	#[NoInject]
	protected ?Path $skipped = null;

	/**
	 * Untyped property: no ReflectionType, skipped.
	 *
	 * @var mixed
	 */
	public $untyped = 'untouched';

	/**
	 * Builtin scalar type: skipped by isBuiltin().
	 *
	 * @var int
	 */
	public int $scalar = 42;

	/**
	 * Union type: not a ReflectionNamedType, skipped.
	 *
	 * @var Path|Views|null
	 */
	protected Path|Views|null $union = null;

	/**
	 * Named non-module class type: skipped by is_subclass_of().
	 *
	 * @var \stdClass|null
	 */
	protected ?\stdClass $non_module = null;

	public function nullable_value(): ?Views {
		return $this->nullable;
	}

	public function skipped_value(): ?Path {
		return $this->skipped;
	}

	public function union_value(): Path|Views|null {
		return $this->union;
	}

	public function non_module_value(): ?\stdClass {
		return $this->non_module;
	}
}

// phpcs:enable Generic.Files.OneObjectStructurePerFile.MultipleFound
