<?php

/**
 * MetaBoxes API: screen context
 */

declare( strict_types=1 );

namespace Zestry\WPToolkit\Modules\MetaBoxes;

// Loaded by WordPress, never requested directly.
\defined( 'ABSPATH' ) || exit;

/**
 * Where on the edit screen a box appears.
 *
 * The edit screen renders exactly these three. A box registered under any other
 * context is registered successfully and then never drawn, with nothing said —
 * which is why this is a closed set rather than a string.
 */
enum Context: string {

	/**
	 * The narrow column beside the editor.
	 */
	case Side = 'side';

	/**
	 * The main column, directly under the editor.
	 */
	case Normal = 'normal';

	/**
	 * The main column, below everything in `Normal`. The WordPress default.
	 */
	case Advanced = 'advanced';
}
