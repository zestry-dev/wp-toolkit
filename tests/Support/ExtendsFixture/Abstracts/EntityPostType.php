<?php

declare( strict_types=1 );

namespace Zestry\WPToolkit\Tests\Support\ExtendsFixture\Abstracts;

use Zestry\WPToolkit\Tests\Support\ExtendsFixture\Core\Modules\PostTypes\PostType;

/**
 * A perfectly good abstract, for the wrong kind of file -- which is the
 * `DiscoveryException` this refuses in advance.
 */
abstract class EntityPostType extends PostType {

	/**
	 * The plural label.
	 *
	 * @return string
	 */
	abstract public function plural_name(): string;
}
