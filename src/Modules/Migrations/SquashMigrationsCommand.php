<?php

/**
 * Migrations API: `wp {slug} migrations squash` command
 */

declare( strict_types=1 );

namespace Zestry\WPToolkit\Modules\Migrations;

// Loaded by WordPress, never requested directly.
\defined( 'ABSPATH' ) || exit;

use Zestry\WPToolkit\Modules\CLI\Command;
use Zestry\WPToolkit\Modules\DB;
use Zestry\WPToolkit\Modules\Path;

/**
 * WP-CLI command: write a baseline from the schema your migrations produced.
 *
 * Registered directly by {@see Migrations::on_boot()} via
 * {@see \Zestry\WPToolkit\Modules\CLI\CLI::register_command_for()} -- not discovered from a
 * file in your own `resources/commands/` directory, since this command exists
 * the moment the Migrations module is added, with nothing to generate or
 * maintain.
 */
class SquashMigrationsCommand extends Command {

	/**
	 * Write a baseline: one migration that creates the schema all the others
	 * build up to.
	 *
	 * A fresh install currently runs every migration you have ever written, in
	 * order, each one diffing tables through `dbDelta()` to reach a schema the
	 * last one could have created outright. This dumps that schema and writes it
	 * as a single {@see Baseline}, which a fresh install runs *instead of*
	 * everything before it. An existing install is untouched: it has a history,
	 * so it keeps migrating one step at a time.
	 *
	 * **This is an authoring command, not a deployment one.** It reads the
	 * database you are pointed at and writes a file into your own
	 * `resources/migrations/` directory, for you to review and commit like any
	 * other source file. Nothing regenerates it later -- not `migrations run`,
	 * which has to work on a read-only production tree and would be dumping the
	 * wrong site's schema anyway.
	 *
	 * There is one baseline, `resources/migrations/baseline.php`, and squashing
	 * again rewrites it in place -- so this shows up in review as a diff rather
	 * than as one file appearing and another vanishing.
	 *
	 * Forgetting to run it again is safe: a migration the baseline does not name
	 * still runs, so a stale baseline makes a fresh install slower than it could
	 * be and never wrong. `--check` is how a build notices, without needing a
	 * database.
	 *
	 * > [!WARNING]
	 * > **A baseline carries schema, and only schema.** Every migration it
	 * > subsumes is recorded as run without running, so anything one of them did
	 * > besides `dbDelta()` -- seeding an option, inserting a row, registering a
	 * > term -- stops happening on fresh installs. The migrations being subsumed
	 * > are listed when you run this, and again in the file it writes. Read
	 * > them, and move anything non-schema into the baseline's own `up()`.
	 *
	 * ## OPTIONS
	 *
	 * [--check]
	 * : Report whether the committed baseline still covers every migration, and
	 * exit non-zero when it does not. Reads files only -- no database, so it runs
	 * in a build that has none. A plugin with no baseline at all passes, since
	 * squashing is something you opt into.
	 *
	 * [--yes]
	 * : Replace an existing baseline without asking, for an unattended run.
	 *
	 * ## EXAMPLES
	 *
	 *     # Squash, on a development site whose migrations have all run.
	 *     $ wp acme-plugin migrations squash
	 *     Dumped 3 tables. 12 migrations are now subsumed.
	 *     Success: Wrote resources/migrations/baseline.php
	 *
	 *     # Fail a build when the committed baseline has fallen behind.
	 *     $ wp acme-plugin migrations squash --check
	 *     Error: 5 migrations are newer than the baseline.
	 *
	 * @param array $args
	 * @param array $assoc_args
	 * @return void
	 */
	public function handle( array $args, array $assoc_args ): void {
		if ( (bool) \WP_CLI\Utils\get_flag_value( $assoc_args, 'check', false ) ) {
			$this->check();

			return;
		}

		try {
			$this->squash();
		} catch ( \Throwable $exception ) {
			$this->error( $exception->getMessage() );
		}
	}

	/**
	 * Report whether the baseline still covers every migration.
	 *
	 * @return void
	 */
	private function check(): void {
		try {
			$baseline = $this->migrations()->get_baseline();
			$after    = $this->migrations()->get_migrations_after_baseline();
		} catch ( \Throwable $exception ) {
			$this->error( $exception->getMessage() );

			return;
		}

		if ( null === $baseline ) {
			// Not a failure. A plugin that has never squashed is in the state
			// every plugin was in before baselines existed, and a fresh install
			// of it is slow rather than broken.
			$this->success( 'No baseline. Fresh installs run every migration; `migrations squash` is how that changes.' );

			return;
		}

		if ( array() === $after ) {
			$this->success( \sprintf( 'The baseline (%s) covers every migration.', $baseline ) );

			return;
		}

		foreach ( $after as $identifier ) {
			$this->log( $identifier );
		}

		$this->error(
			\sprintf(
				'%d migration%s newer than the baseline (%s). Fresh installs run %s one by one;'
					. ' squash again to fold %s in.',
				\count( $after ),
				1 === \count( $after ) ? ' is' : 's are',
				$baseline,
				1 === \count( $after ) ? 'it' : 'them',
				1 === \count( $after ) ? 'it' : 'them'
			)
		);
	}

	/**
	 * Dump the schema and write the baseline.
	 *
	 * @return void
	 * @throws \RuntimeException When the database is not in a state worth dumping.
	 */
	private function squash(): void {
		$migrations = $this->migrations();
		$discovered = $migrations->get_discovered_migrations();
		$pending    = $migrations->get_pending_migrations();

		// A schema dumped halfway through a batch is a schema no release ever
		// has, and it would be recorded as the one every fresh install starts
		// from.
		if ( array() !== $pending ) {
			throw new \RuntimeException(
				\sprintf(
					'%d migration%s still pending here, so this database is not the schema a baseline should describe.'
						. ' Run `migrations run` first: %s',
					\count( $pending ),
					1 === \count( $pending ) ? ' is' : 's are',
					\implode( ', ', $pending )
				)
			);
		}

		$tables = $this->get_table_definitions();

		if ( array() === $tables ) {
			throw new \RuntimeException(
				'No tables carrying this plugin\'s prefix exist, so there is no schema to dump. A baseline'
					. ' describes tables; a plugin whose migrations only touch options or posts does not need one.'
			);
		}

		$existing = $migrations->get_baseline();

		if ( null !== $existing && ! $this->replace_existing( $existing ) ) {
			return;
		}

		$identifier = Baseline::FILENAME;

		// Everything on disk except the baseline itself. A squash is taken from a
		// database every one of these has already run against, which is what
		// entitles it to stand in for them.
		$subsumed = \array_values( \array_diff( $discovered, array( $identifier, $existing ) ) );

		\file_put_contents(
			$this->get_migrations_dir() . '/' . $identifier . '.php',
			$this->render( $tables, $subsumed )
		);

		$this->log(
			\sprintf(
				'Dumped %d table%s. %d migration%s now subsumed.',
				\count( $tables ),
				1 === \count( $tables ) ? '' : 's',
				\count( $subsumed ),
				1 === \count( $subsumed ) ? ' is' : 's are'
			)
		);

		$this->warning(
			'A baseline carries schema only. Anything those migrations did besides dbDelta() -- seeding an option,'
				. ' inserting a row -- no longer happens on a fresh install. Check them, and move it into the'
				. " baseline's own up()."
		);

		$this->success( 'Wrote ' . Migrations::MIGRATIONS_ROOT . '/' . $identifier . '.php' );
	}

	/**
	 * Ask before replacing a baseline that is already there.
	 *
	 * @param string $existing The existing baseline's identifier.
	 * @return bool True when the caller should go ahead.
	 */
	private function replace_existing( string $existing ): bool {
		if ( $this->confirm( \sprintf( 'Rewrite the existing baseline, %s?', $existing ) ) ) {
			return true;
		}

		$this->log( 'Left ' . $existing . ' alone; nothing was written.' );

		return false;
	}

	/**
	 * Every table carrying this plugin's prefix, as a `CREATE TABLE` statement.
	 *
	 * Read back out of MySQL rather than reconstructed from the migrations: what
	 * the migrations *say* and what they *produced* are the two things a baseline
	 * has to agree with, and only one of them is a fact.
	 *
	 * @return array<string, string> Local table name => normalised CREATE TABLE statement.
	 */
	private function get_table_definitions(): array {
		$db     = $this->with( DB::class );
		$wpdb   = $db->get_wpdb();
		$prefix = $wpdb->prefix . $db->get_table_prefix();

		// esc_like(), because `_` is a single-character wildcard in LIKE and a
		// table prefix is mostly underscores -- without it this matches tables
		// belonging to other plugins.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$names = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $prefix ) . '%' ) );

		$definitions = array();

		foreach ( \is_array( $names ) ? $names : array() as $name ) {
			$name = (string) $name;

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$row = $wpdb->get_row( $wpdb->prepare( 'SHOW CREATE TABLE %i', $name ), ARRAY_N );

			if ( ! \is_array( $row ) || ! isset( $row[1] ) ) {
				continue;
			}

			$definitions[ \substr( $name, \strlen( $prefix ) ) ] = $this->normalize( (string) $row[1], $name );
		}

		\ksort( $definitions );

		return $definitions;
	}

	/**
	 * Make one `SHOW CREATE TABLE` statement fit to be written down.
	 *
	 * Five changes, and each is something that would otherwise be wrong on
	 * somebody else's site rather than merely untidy:
	 *
	 * - the table name becomes a `get_table()` call, so the baseline works under
	 *   any `$wpdb->prefix` rather than only the one it was dumped from;
	 * - `DEFAULT CHARSET`/`COLLATE` become `get_charset_collate()`, so a new
	 *   table matches the site it is created on;
	 * - `AUTO_INCREMENT=N` goes, since it is this database's row count;
	 * - `PRIMARY KEY (` gains the second space and index names lose their
	 *   backticks, which is the shape `dbDelta()` can read;
	 * - `TEMPORARY` goes, because a baseline is the one file that must not
	 *   create a table which disappears with the connection.
	 *
	 * That last one is not hypothetical. WordPress's own PHPUnit bootstrap
	 * rewrites every `CREATE TABLE` a test makes into `CREATE TEMPORARY TABLE`,
	 * so a squash run against a test database dumps exactly that.
	 *
	 * @param string $create The raw statement.
	 * @param string $table  The full table name it was dumped from.
	 * @return string
	 */
	private function normalize( string $create, string $table ): string {
		$create = \str_replace( '`' . $table . '`', '{{table}}', $create );
		$create = (string) \preg_replace( '/^CREATE\s+TEMPORARY\s+TABLE/i', 'CREATE TABLE', $create );
		$create = (string) \preg_replace( '/\s*AUTO_INCREMENT=\d+/i', '', $create );
		$create = (string) \preg_replace( '/\s*DEFAULT CHARSET=\S+(\s+COLLATE=\S+)?/i', ' {{charset_collate}}', $create );
		$create = (string) \preg_replace( '/^(\s*PRIMARY KEY) \(/mi', '$1  (', $create );

		return (string) \preg_replace( '/^(\s*(?:UNIQUE |FULLTEXT |SPATIAL )?KEY )`([^`]+)`/mi', '$1$2', $create );
	}

	/**
	 * The baseline file's source.
	 *
	 * Built here rather than rendered from a `.stub`: this module is copied into
	 * your own tree, where the toolkit's stub directory does not exist.
	 *
	 * @param array<string, string> $tables   Local table name => CREATE TABLE statement.
	 * @param string[]              $subsumed The identifiers this baseline stands in for.
	 * @return string
	 */
	private function render( array $tables, array $subsumed ): string {
		$lines = array(
			'<?php',
			'/**',
			' * Baseline: the schema this plugin\'s migrations build up to, in one step.',
			' *',
			' * Generated by `wp ' . $this->get_plugin()->get_slug() . ' migrations squash` on '
				. \gmdate( 'Y-m-d' ) . ', and yours from here on.',
			' */',
			'',
			'declare( strict_types=1 );',
			'',
			'// Loaded by WordPress, never requested directly.',
			'\defined( \'ABSPATH\' ) || exit;',
			'',
			'use ' . Baseline::class . ';',
			'',
			'return new class() extends Baseline {',
			'',
			'	// Runs ONLY on a site where no migration has ever run, and stands in for',
			'	// exactly the migrations subsumes() names. An existing site ignores it and',
			'	// keeps migrating one step at a time.',
			'	//',
			'	// SCHEMA ONLY. The migrations named below are recorded as run WITHOUT',
			'	// running, so anything they did besides dbDelta() -- seeding an option,',
			'	// inserting a row, registering a term -- no longer happens on a fresh',
			'	// install. Check them, and move it into up().',
			'	//',
			'	// This list is the mechanism, not a note: a migration not named here still',
			'	// runs, even one whose filename sorts earlier. Rewritten wholesale by the',
			'	// next squash.',
			'	public function subsumes(): array {',
			'		return array(',
		);

		foreach ( $subsumed as $identifier ) {
			$lines[] = '			\'' . $identifier . '\',';
		}

		$lines[] = '		);';
		$lines[] = '	}';
		$lines[] = '';
		$lines[] = '	public function up(): void {';
		$lines[] = '		$this->db_delta(';
		$lines[] = '			array(';

		foreach ( $tables as $name => $create ) {
			$lines[] = '				"' . $this->to_php_string( $create, $name ) . ';",';
		}

		$lines[] = '			)';
		$lines[] = '		);';
		$lines[] = '	}';
		$lines[] = '};';
		$lines[] = '';

		return \implode( "\n", $lines );
	}

	/**
	 * One CREATE TABLE statement, as the body of a double-quoted PHP string.
	 *
	 * Escaped first and interpolated second, so a default value containing a
	 * dollar sign or a backslash cannot become something PHP reads as code.
	 *
	 * @param string $create The normalised statement, carrying the two placeholders.
	 * @param string $name   The local table name.
	 * @return string
	 */
	private function to_php_string( string $create, string $name ): string {
		$escaped = \str_replace( array( '\\', '"', '$' ), array( '\\\\', '\"', '\$' ), $create );

		// Re-indented to sit inside the generated array(). MySQL's own two-space
		// continuation indent is dropped first, or it would be added to this one.
		$lines   = \explode( "\n", $escaped );
		$escaped = \ltrim( (string) \array_shift( $lines ) );

		foreach ( $lines as $line ) {
			$line = \ltrim( $line );

			// The closing paren sits a level out from the columns, where a reader
			// expects it. MySQL's own indent is dropped above, or it would be
			// added to this one.
			$escaped .= ( \str_starts_with( $line, ')' ) ? "\n\t\t\t\t" : "\n\t\t\t\t\t" ) . $line;
		}

		return \str_replace(
			array( '{{table}}', '{{charset_collate}}' ),
			array(
				'{$this->get_table( \'' . $name . '\' )}',
				'{$this->get_charset_collate()}',
			),
			$escaped
		);
	}

	/**
	 * The plugin's own migrations directory.
	 *
	 * @return string
	 */
	private function get_migrations_dir(): string {
		return $this->with( Path::class )->get_plugin_path( Migrations::MIGRATIONS_ROOT );
	}

	/**
	 * The module that registered this command.
	 *
	 * Not a property: building a module boots it, and a declaration would hide
	 * that behind a type name.
	 *
	 * @return Migrations
	 */
	private function migrations(): Migrations {
		return $this->with( Migrations::class );
	}
}
