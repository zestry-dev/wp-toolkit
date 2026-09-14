<?php

/**
 * Migrations API: too-many-baselines exception
 */

declare( strict_types=1 );

namespace Zestry\WPToolkit\Modules\Migrations;

// Loaded by WordPress, never requested directly.
\defined( 'ABSPATH' ) || exit;

use Zestry\WPToolkit\Kernel\Exceptions\ModuleException;

/**
 * More than one {@see Baseline} is waiting to run.
 *
 * A baseline stands in for every migration before it, so two of them on a fresh
 * install are two different answers to what that install's schema should be, and
 * nothing here can pick. {@see Migrations::run_pending()} throws this instead of
 * running either -- nothing has run when you see it, so the site is exactly as
 * it was.
 *
 * Almost always a squash whose predecessor was left behind: `migrations squash`
 * offers to remove the old baseline for you, and declining leaves both. Delete
 * the older of the two -- the newer one subsumes everything the older did, and
 * more.
 */
class ManyBaselinesException extends ModuleException {

	/**
	 * Both baselines, named, with the fix.
	 *
	 * @param string[] $identifiers The baseline identifiers found.
	 * @return self
	 */
	public static function for_identifiers( array $identifiers ): self {
		return new self(
			\sprintf(
				'Found %d baselines, and only one can apply: %s. Each stands in for every migration before it,'
					. ' so keep the newest -- it subsumes everything the others did -- and delete the rest.',
				\count( $identifiers ),
				\implode( ', ', $identifiers )
			)
		);
	}
}
