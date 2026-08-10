<?php

/**
 * Migrations API: renamed-migration exception
 */

declare( strict_types=1 );

namespace Zestry\WPToolkit\Modules\Migrations;

// Loaded by WordPress, never requested directly.
\defined( 'ABSPATH' ) || exit;

use Zestry\WPToolkit\Kernel\Exceptions\ModuleException;

/**
 * A pending migration looks like a rename of one that has already run.
 *
 * A migration's identity is its filename, description and all, so renaming any
 * part of the file makes it a migration the site has never run -- and the next
 * run runs it again. For a `dbDelta()` that is harmless; for a data backfill it
 * is not.
 *
 * {@see Migrations::run_pending()} throws this instead of running anything when
 * it finds a pending migration sharing a timestamp prefix with an identifier
 * that ran and no longer has a file. The whole batch stops, including unrelated
 * pending migrations: a batch is usually a release, and running half of one
 * because the other half is suspicious is worse than running none.
 *
 * > [!NOTE]
 * > **This is a heuristic, and it can be wrong.** Two genuinely distinct
 * > migrations authored in the same second would look identical to a rename.
 * > That is why it refuses rather than repairs, and why `--force` exists: the
 * > operator is told, not blocked.
 *
 * Catch it to tell a rename apart from a migration that actually failed --
 * nothing has run when this is thrown, so the schema is exactly as it was.
 *
 * ```
 * try {
 *     $this->migrations->run_pending();
 * } catch ( RenamedMigrationException $exception ) {
 *     // Nothing ran. Show the operator which files to put back.
 *     $this->notice( $exception->getMessage() );
 * }
 * ```
 */
class RenamedMigrationException extends ModuleException {
}
