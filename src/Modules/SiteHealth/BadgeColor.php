<?php

/**
 * SiteHealth API: badge colour
 */

declare( strict_types=1 );

namespace Zestry\WPToolkit\Modules\SiteHealth;

// Loaded by WordPress, never requested directly.
\defined( 'ABSPATH' ) || exit;

/**
 * The colour of the category badge beside a check's label.
 *
 * WordPress renders it as a class name — `<span class="badge {color}">` — and
 * styles exactly these six. Any other string produces an unstyled badge, which
 * looks like a bug in your plugin rather than a typo, and nothing warns you.
 * A closed set is the fix.
 */
enum BadgeColor: string {

	/**
	 * The default, and what WordPress uses for its own informational badges.
	 */
	case Blue = 'blue';

	/**
	 * Muted, for a badge that should not draw the eye.
	 */
	case Gray = 'gray';

	case Green = 'green';

	case Orange = 'orange';

	case Purple = 'purple';

	case Red = 'red';
}
