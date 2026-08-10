<?php

/**
 * MetaBoxes API: ordering within a context
 */

declare( strict_types=1 );

namespace Zestry\WPToolkit\Modules\MetaBoxes;

// Loaded by WordPress, never requested directly.
\defined( 'ABSPATH' ) || exit;

/**
 * Where a box sits among the others sharing its context.
 *
 * WordPress walks these four in order. A box registered under any other
 * priority is never reached, so it is registered and never drawn — which is why
 * this is a closed set rather than a string.
 */
enum Priority: string {

	/**
	 * Above everything else in the context.
	 */
	case High = 'high';

	/**
	 * Above `Default`, below `High`. Core's own boxes use this.
	 */
	case Core = 'core';

	/**
	 * The WordPress default.
	 */
	case Default = 'default';

	/**
	 * Below everything else in the context.
	 */
	case Low = 'low';
}
