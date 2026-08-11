<?php

declare( strict_types=1 );

namespace Zestry\WPToolkit\Tests\Support\ExtendsFixture\Core\Modules\Fields;

/**
 * Stands in for the copied `Field` in a consumer's own tree.
 *
 * `--extends` is checked against the plugin's copy, not against this package's
 * class, because that is what a generated file and a consumer's own abstract
 * both actually extend. So the fixture needs a class at the copied namespace,
 * and this is it.
 */
abstract class Field {

	/**
	 * What this attaches to within its object type.
	 *
	 * @return string[]
	 */
	abstract public function subtypes(): array;
}
