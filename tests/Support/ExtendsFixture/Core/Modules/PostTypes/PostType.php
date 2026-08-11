<?php

declare( strict_types=1 );

namespace Zestry\WPToolkit\Tests\Support\ExtendsFixture\Core\Modules\PostTypes;

/**
 * Stands in for the copied `PostType`, so a field pointed at a post type
 * abstract can be refused for the right reason.
 */
abstract class PostType {

	/**
	 * The singular label.
	 *
	 * @return string
	 */
	abstract public function singular_name(): string;
}
