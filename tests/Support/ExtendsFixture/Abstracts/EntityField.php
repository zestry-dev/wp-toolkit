<?php

declare( strict_types=1 );

namespace Zestry\WPToolkit\Tests\Support\ExtendsFixture\Abstracts;

use Zestry\WPToolkit\Tests\Support\ExtendsFixture\Core\Modules\Fields\Field;

/**
 * The intermediate abstract this whole feature exists for.
 *
 * It settles one of the base's abstract methods and adds four of its own, which
 * is what a generated file has to be able to tell apart: `subtypes()` is
 * answered here and must not be stubbed again, and the four below must.
 */
abstract class EntityField extends Field {

	/**
	 * What this attaches to within its object type.
	 *
	 * @return string[]
	 */
	public function subtypes(): array {
		return array( 'post' );
	}

	/**
	 * Which entities this field belongs to.
	 *
	 * @return string[]
	 */
	abstract public function attaches_to(): array;

	/**
	 * The label shown beside the input.
	 *
	 * @return string
	 */
	abstract public function label(): string;

	/**
	 * Render the stored value for display.
	 *
	 * @param mixed $value  The stored value.
	 * @param bool  $escape Whether to escape it.
	 * @return string|null
	 */
	abstract public function format( mixed $value, bool $escape = true ): ?string;

	/**
	 * Where values of this field are kept.
	 *
	 * @return \ArrayObject
	 */
	abstract protected function get_store(): \ArrayObject;
}
