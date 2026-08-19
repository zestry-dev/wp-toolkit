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
	 * User meta, which has no subtypes.
	 */
	case User = 'user';

	/**
	 * Comment meta, which has no subtypes.
	 *
	 * Not comment types: `get_object_subtype()` answers `comment` for every
	 * comment whatever its `comment_type`, so a field naming one attaches to
	 * nothing.
	 */
	case Comment = 'comment';

	/**
	 * Whether meta of this type is divided into subtypes at all.
	 *
	 * True for posts and terms, whose subtypes are post type and taxonomy
	 * names. False for users and comments: `get_object_subtype()` answers with
	 * the literal `user` and `comment` for every one of them, never a role or a
	 * `comment_type` — so a field naming a subtype there registers meta that
	 * nothing ever matches, and {@see Field::subtypes()} must be left empty.
	 *
	 * @return bool
	 */
	public function has_subtypes(): bool {
		return self::Post === $this || self::Term === $this;
	}
}
