<?php

/**
 * Fields API: the object a field attaches to
 */

declare( strict_types=1 );

namespace Zestry\WPToolkit\Modules\Fields;

// Loaded by WordPress, never requested directly.
\defined( 'ABSPATH' ) || exit;

/**
 * What kind of thing a field is stored against.
 *
 * WordPress keeps a separate meta table per type, and every meta function takes
 * this as its first argument. A field declares one of these rather than a
 * string, because a typo would register meta against a table that does not
 * exist and fail without saying so.
 */
enum MetaType: string {

	/**
	 * Post meta — the common case, and the default.
	 *
	 * Subtypes are post type names.
	 */
	case Post = 'post';

	/**
	 * Term meta. Subtypes are taxonomy names.
	 */
	case Term = 'term';

	/**
	 * User meta. Users have no subtypes.
	 */
	case User = 'user';

	/**
	 * Comment meta. Subtypes are comment types.
	 */
	case Comment = 'comment';
}
