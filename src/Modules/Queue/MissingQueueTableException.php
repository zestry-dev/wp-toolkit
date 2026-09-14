<?php

/**
 * Queue API: missing-table exception
 */

declare( strict_types=1 );

namespace Zestry\WPToolkit\Modules\Queue;

// Loaded by WordPress, never requested directly.
\defined( 'ABSPATH' ) || exit;

use Zestry\WPToolkit\Kernel\Exceptions\ModuleException;

/**
 * The queue table does not exist yet.
 *
 * `wp zt add queue` writes a migration into your `resources/migrations/`
 * directory, and that migration creates the table -- but migrations never run
 * themselves, so until you run yours there is nowhere to put a job.
 *
 * Thrown rather than repaired: creating a table is a schema change, and this
 * toolkit does not make one on a request that only wanted to queue an email.
 * Run your migrations, from wherever you normally run them:
 *
 * ```
 * wp acme-plugin migrations run
 * ```
 *
 * Worth catching where a queued job is a nicety rather than the point -- a
 * dispatch from a contact form, say, on a site that has just updated and not yet
 * migrated.
 */
class MissingQueueTableException extends ModuleException {

	/**
	 * The table is missing, and the message says which and what to run.
	 *
	 * @param string $table The full table name that was expected.
	 * @param string $slug  The plugin's slug, which is what its commands are namespaced under.
	 * @return self
	 */
	public static function for_table( string $table, string $slug ): self {
		return new self(
			\sprintf(
				'The queue table "%1$s" does not exist. `wp zt add queue` writes the migration that creates it;'
					. ' run your migrations to apply it: `wp %2$s migrations run`.',
				$table,
				$slug
			)
		);
	}
}
