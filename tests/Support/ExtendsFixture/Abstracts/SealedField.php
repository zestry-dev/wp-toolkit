<?php

declare( strict_types=1 );

namespace Zestry\WPToolkit\Tests\Support\ExtendsFixture\Abstracts;

use Zestry\WPToolkit\Tests\Support\ExtendsFixture\Core\Modules\Fields\Field;

/**
 * A field class nothing can extend, for the refusal that is a fatal rather than
 * a DiscoveryException.
 */
final class SealedField extends Field {

	/**
	 * What this attaches to within its object type.
	 *
	 * @return string[]
	 */
	public function subtypes(): array {
		return array( 'post' );
	}
}
