<?php

/**
 * Migrations API: Migration base class
 */

declare( strict_types=1 );

namespace Zestry\WPToolkit\Modules\Migrations;

// Loaded by WordPress, never requested directly.
\defined( 'ABSPATH' ) || exit;

use Zestry\WPToolkit\Kernel\Contracts\PluginAware;
use Zestry\WPToolkit\Kernel\Traits\WithPlugin;
use Zestry\WPToolkit\Services\DB;

/**
 * Base class for a file-based, one-time database migration.
 *
 * A migration file returns a subclass instance. The Migrations module wires it,
 * assigning the shared plugin and injecting typed module dependencies, then
 * calls `up()` exactly once for a given site. Once it has run successfully it
 * never runs again, tracked by the migration's own identifier -- see
 * {@see Migrations} for how that identifier comes from the filename.
 *
 * Forward-only: there is no `down()`. A WordPress plugin has no
 * staging/production migration pipeline to roll back through the way a
 * Rails or Laravel app might -- a schema change either ships forward in a
 * later migration or is left alone. Write a new migration to undo a mistake,
 * rather than reversing an old one in place.
 *
 * A file at `migrations/20260115120000-create-books-table.php` runs once, in
 * filename order. `wp zt make migration <name>` generates a starting point,
 * timestamp prefix included.
 * A migration doing something `dbDelta()` cannot express (a data backfill, an
 * index `dbDelta()` cannot parse, a one-off `UPDATE`) uses `$wpdb` directly --
 * declare it as a typed property like any other injected dependency, or
 * reach `$GLOBALS['wpdb']` the way WordPress code ordinarily does.
 *
 * @stub migration.php.stub
 */
abstract class Migration implements PluginAware {

	use WithPlugin;

	/**
	 * DB module injected by the plugin, for naming tables.
	 *
	 * @var DB
	 */
	public DB $db;

	/**
	 * Prevent direct construction from bypassing plugin initialization.
	 *
	 * @return void
	 */
	final public function __construct() {}

	/**
	 * Run this migration's schema or data change.
	 *
	 * Called at most once, ever, per site -- the Migrations module records
	 * this migration's identifier as run immediately after this returns
	 * without throwing, and never calls it again. Throwing leaves the
	 * migration unrecorded, so it is retried the next time migrations run.
	 *
	 * @return void
	 */
	abstract public function up(): void;

	/**
	 * Run one or more `CREATE TABLE`/`ALTER TABLE` statements through
	 * WordPress core's own `dbDelta()`.
	 *
	 * Loads `wp-admin/includes/upgrade.php` on demand, since `dbDelta()` is
	 * not available on an ordinary front-end request. `dbDelta()` has strict,
	 * well-documented formatting requirements of its own (two spaces between
	 * `PRIMARY KEY` and the column list, each `KEY`/`UNIQUE KEY` on its own
	 * line, ...) that this method does not validate or relax -- write the SQL
	 * the way WordPress's own Codex documents `dbDelta()` requiring it.
	 *
	 * Name the table with {@see get_table()} rather than composing it: the
	 * plugin slug is hyphenated by convention and a hyphen is illegal in an
	 * unquoted SQL identifier, which is what `dbDelta()` needs.
	 *
	 * Verifies afterwards that every table `dbDelta()` claimed to create really
	 * exists, and throws when one does not. `dbDelta()`'s return value reports
	 * the statements it decided to run rather than the ones that succeeded, so
	 * without this check a `CREATE TABLE` that MySQL rejected would report
	 * success, create nothing, and -- because `up()` returned without throwing
	 * -- be recorded as run and never retried.
	 *
	 * @param string[]|string $queries One or more `CREATE TABLE` statements.
	 * @return string[] dbDelta()'s own per-statement result strings.
	 * @throws \RuntimeException When a table dbDelta() reported creating does not exist.
	 */
	final public function db_delta( array|string $queries ): array {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$result = \dbDelta( $queries ); // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid

		foreach ( \array_keys( $result ) as $table ) {
			if ( ! \str_contains( \strtolower( (string) $result[ $table ] ), 'created table' ) ) {
				continue;
			}

			// `%s`, not `%i`: SHOW TABLES LIKE takes a pattern string, and
			// backtick-quoting it is a syntax error.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$exists = (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;

			if ( ! $exists ) {
				throw new \RuntimeException(
					\sprintf(
						'dbDelta() reported creating "%s", but the table does not exist. %s',
						$table,
						'' !== $wpdb->last_error ? 'MySQL said: ' . $wpdb->last_error : 'MySQL reported no error.'
					)
				);
			}
		}

		return $result;
	}

	/**
	 * The full, plugin-namespaced name of a custom table.
	 *
	 * Delegates to {@see DB::get_table()}, which is also what everything
	 * outside a migration uses: a table created here is queried from routes,
	 * blocks and commands, and all of them have to agree on its name.
	 *
	 * @param string $name The local table name, e.g. 'books'.
	 * @return string The `{$wpdb->prefix}{plugin_slug}_{name}` table name.
	 * @throws \InvalidArgumentException When the name is empty, illegal, or too long for MySQL.
	 */
	final public function get_table( string $name ): string {
		return $this->db->get_table( $name );
	}

	/**
	 * The `DEFAULT CHARACTER SET ... COLLATE ...` clause for the current site.
	 *
	 * `dbDelta()` expects each `CREATE TABLE` statement to end with this, so
	 * a new table matches the site's own configured charset/collation rather
	 * than silently defaulting to the server's.
	 *
	 * @return string
	 */
	final public function get_charset_collate(): string {
		return $this->db->get_charset_collate();
	}
}
