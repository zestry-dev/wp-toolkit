<?php

/**
 * DB API: DB service
 */

declare( strict_types=1 );

namespace Zestry\WPToolkit\Services;

// Loaded by WordPress, never requested directly.
\defined( 'ABSPATH' ) || exit;

use Zestry\WPToolkit\Kernel\Abstracts\Service;

/**
 * Names a plugin's own database tables, and WordPress's.
 *
 * A custom table is `{$wpdb->prefix}{plugin_prefix}_{name}`, so it carries both
 * the site's prefix and the plugin's, and cannot collide with another plugin's
 * table of the same local name. The plugin's half defaults to its slug and is
 * settable with {@see set_table_prefix()}. {@see get_table()} builds the whole
 * name; nothing else in a plugin should build it by hand.
 *
 * `Migrations` uses this service to create tables, and every other module,
 * route, block or command uses it to find them afterwards -- which is the point
 * of it being a service every one of them can reach rather than a method on
 * `Migration`: a table created in a migration is queried everywhere else.
 *
 * @setup
 * Configure the service only to shorten the table prefix. It defaults to the
 * plugin slug, which is usually right -- but MySQL caps a table name at 64
 * characters, and a long slug can leave too little room for the table's own
 * name. Set a shorter prefix rather than renaming the plugin.
 *
 * Decide it before the first migration runs: changing it later renames
 * nothing, so the existing tables stay under the old name and your plugin
 * stops finding them.
 *
 * `bootstrap.php` is modules only, so the configuration goes in your entry
 * file, where the callback runs the first time something asks for the service.
 *
 * ```
 * // acme-plugin.php
 * ( new Plugin( __FILE__ ) )
 *     ->configure(
 *         DB::class,
 *         static function ( DB $db ): void {
 *             $db->set_table_prefix( 'mc' );   // wp_mc_submissions
 *         }
 *     )
 *     ->bootstrap()
 *     ->run();
 * ```
 *
 * @example Naming a table
 * ```
 * $db = $plugin->get( DB::class );
 *
 * $db->get_table( 'submissions' );   // wp_acme_plugin_submissions
 * $db->get_core_table( 'users' );    // wp_users
 * ```
 *
 * @example Reading and writing rows
 * `$wpdb` does the querying; this service names the table it runs against.
 * {@see get_wpdb()} hands you the handle so there is no `global` line -- assign
 * it to `$wpdb`, and read that method for why the name of the variable matters.
 *
 * Pass the table through `%i`, WordPress's identifier placeholder (6.2+), rather
 * than interpolating it: `%i` backtick-quotes what it is given, so the query is
 * correct even for a name that would need quoting. `%s` is for values and would
 * wrap the table in single quotes, which is a string, not a table.
 *
 * ```
 * $wpdb = $this->db->get_wpdb();
 *
 * $wpdb->get_results(
 *     $wpdb->prepare(
 *         'SELECT * FROM %i WHERE status = %s',
 *         $this->db->get_table( 'submissions' ),
 *         'unread'
 *     )
 * );
 *
 * $wpdb->insert( $this->db->get_table( 'submissions' ), array( 'status' => 'unread' ), array( '%s' ) );
 * ```
 *
 * There is no `query()`, `insert()` or `get_results()` on this class. Naming a
 * table and running a query are two jobs, and only the first is ambiguous enough
 * to need help: a local name says nothing about whether it is one of yours or one
 * of WordPress's, which is the difference between {@see get_table()} and
 * {@see get_core_table()}. A wrapper taking that name would have to guess.
 */
class DB extends Service {

	/**
	 * The longest identifier MySQL accepts, in characters.
	 *
	 * Exceeding it is an `Incorrect table name` error rather than a truncation,
	 * and through `dbDelta()` that error is swallowed -- the migration reports
	 * success and creates nothing. {@see get_table()} refuses to build such a
	 * name at all, so the failure surfaces where it can be read.
	 */
	public const MAX_IDENTIFIER_LENGTH = 64;

	/**
	 * Table prefix set from the entry file, or null to derive it from the slug.
	 *
	 * @var string|null
	 */
	private ?string $table_prefix = null;

	/**
	 * Set the prefix this plugin's tables carry, in place of its slug.
	 *
	 * Call this from `configure()` in your entry file. Rejected outright if it
	 * is not a legal SQL identifier fragment, hyphens included: a slug is
	 * normalised because a plugin inherits it from its directory name, but a
	 * prefix is one you chose here, so silently rewriting it would hide that
	 * choice from you. Write the underscore.
	 *
	 * @param string $prefix The prefix, without a trailing underscore.
	 * @return void
	 * @throws \InvalidArgumentException When the prefix is empty or not a legal identifier fragment.
	 */
	public function set_table_prefix( string $prefix ): void {
		if ( 1 !== \preg_match( '/^[A-Za-z0-9_]+$/', $prefix ) ) {
			throw new \InvalidArgumentException(
				\sprintf(
					'Table prefix "%s" must contain only letters, digits and underscores.',
					$prefix
				)
			);
		}

		$this->table_prefix = $prefix;
	}

	/**
	 * The full, plugin-namespaced name of a custom table.
	 *
	 * The plugin's prefix is normalised on the way in: a hyphen is the
	 * convention for a WordPress plugin slug (`contact-form-7`,
	 * `woocommerce-admin`) and is not legal in an unquoted SQL identifier,
	 * which is what `dbDelta()` needs -- an unquoted `wp_contact-form-7_entries`
	 * fails to create, and `dbDelta()` reports success regardless.
	 *
	 * @param string $name The local table name, e.g. 'submissions'.
	 * @return string The `{$wpdb->prefix}{plugin_prefix}_{name}` table name.
	 * @throws \InvalidArgumentException When the name is empty, illegal, or too long for MySQL.
	 */
	public function get_table( string $name ): string {
		if ( 1 !== \preg_match( '/^[A-Za-z0-9_]+$/', $name ) ) {
			throw new \InvalidArgumentException(
				\sprintf(
					'Table name "%s" must contain only letters, digits and underscores.',
					$name
				)
			);
		}

		$wpdb  = $this->get_wpdb();
		$table = $wpdb->prefix . $this->get_table_prefix() . $name;

		if ( \strlen( $table ) > self::MAX_IDENTIFIER_LENGTH ) {
			throw new \InvalidArgumentException(
				\sprintf(
					'Table name "%s" is %d characters; MySQL allows %d. Shorten the local name, or set a shorter prefix with set_table_prefix().',
					$table,
					\strlen( $table ),
					self::MAX_IDENTIFIER_LENGTH
				)
			);
		}

		return $table;
	}

	/**
	 * The name of one of WordPress's own tables.
	 *
	 * Read off `$wpdb` rather than built from its prefix, because the two
	 * disagree on multisite: `posts` is per-site (`wp_2_posts`) while `users`
	 * and `usermeta` are shared network-wide (`wp_users`, never `wp_2_users`).
	 * Composing `$wpdb->prefix . 'users'` by hand is correct on a single site
	 * and silently wrong on a network.
	 *
	 * Only a name WordPress declares as a table is accepted. Anything else
	 * throws, including a `$wpdb` property that is not one.
	 *
	 * @rationale
	 * This used to read `$wpdb->{$name}` and accept any non-empty string, which
	 * made `get_core_table( 'prefix' )` return `wp_` and
	 * `get_core_table( 'last_query' )` return the last SQL statement -- neither
	 * a table, neither an error. Checking against `$wpdb->tables()` first is
	 * what makes the method's name true. Keep the property read afterwards:
	 * `tables()` lists unprefixed names, and only the property carries the
	 * multisite-correct prefix.
	 *
	 * @param string $name The core table's own name, e.g. 'posts' or 'users'.
	 * @return string The table name for the current site.
	 * @throws \InvalidArgumentException When WordPress declares no such table.
	 */
	public function get_core_table( string $name ): string {
		$wpdb = $this->get_wpdb();

		/*
		 * Both scopes: `tables( 'all' )` covers the per-site tables and the
		 * network-wide ones together, which is the same set this method's
		 * multisite behaviour depends on.
		 */
		$declared = \method_exists( $wpdb, 'tables' ) ? $wpdb->tables( 'all' ) : array();

		if ( ! \in_array( $name, \array_keys( $declared ), true ) && ! \in_array( $name, $declared, true ) ) {
			throw new \InvalidArgumentException(
				\sprintf( 'WordPress declares no core table named "%s".', $name )
			);
		}

		$table = $wpdb->{$name} ?? null;

		if ( ! \is_string( $table ) || '' === $table ) {
			throw new \InvalidArgumentException(
				\sprintf( 'WordPress declares no core table named "%s".', $name )
			);
		}

		return $table;
	}

	/**
	 * Whether one of this plugin's tables exists.
	 *
	 * Worth checking after a migration rather than trusting it: `dbDelta()`
	 * reports the statements it decided to run, not the ones that succeeded.
	 *
	 * @param string $name The local table name, e.g. 'submissions'.
	 * @return bool True when the table is present.
	 * @throws \InvalidArgumentException When the name is empty, illegal, or too long for MySQL.
	 */
	public function table_exists( string $name ): bool {
		$wpdb  = $this->get_wpdb();
		$table = $this->get_table( $name );

		// `%s`, not `%i`: SHOW TABLES LIKE takes a pattern string, and
		// backtick-quoting it is a syntax error.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
	}

	/**
	 * WordPress's own `$wpdb`, so a caller needs no `global` line.
	 *
	 * **Assign it to a variable called `$wpdb`. Do not chain off this call.**
	 *
	 *     $wpdb = $this->db->get_wpdb();
	 *
	 *     $rows = $wpdb->get_results(
	 *         $wpdb->prepare( 'SELECT * FROM %i WHERE status = %s', $this->db->get_table( 'submissions' ), 'unread' )
	 *     );
	 *
	 * `WordPress.DB.PreparedSQL` is what catches a value interpolated into a query
	 * instead of prepared, and it finds a query by the *variable name* `$wpdb` --
	 * `WPDBTrait::is_wpdb_method_call()` tests the token, and there is no setting
	 * for another. So `$wpdb->query( "... $value ..." )` is flagged and
	 * `$this->db->get_wpdb()->query( "... $value ..." )` is not. Chaining is the
	 * one form that turns `composer lint` green over an injection; the assignment
	 * costs the same line the `global` did and keeps every sniff working.
	 *
	 * Which is also why there is no `query()`, `prepare()`, `get_var()`,
	 * `get_row()`, `get_col()` or `get_results()` on this class, and will not be.
	 *
	 * @return \wpdb WordPress's database handle.
	 */
	public function get_wpdb(): \wpdb {
		global $wpdb;

		return $wpdb;
	}

	/**
	 * The `DEFAULT CHARACTER SET ... COLLATE ...` clause for the current site.
	 *
	 * `dbDelta()` expects each `CREATE TABLE` statement to end with this, so a
	 * new table matches the site's own configured charset and collation rather
	 * than the server's default.
	 *
	 * @return string
	 */
	public function get_charset_collate(): string {
		return $this->get_wpdb()->get_charset_collate();
	}

	/**
	 * The plugin's own table-name prefix, normalised for SQL.
	 *
	 * Public so you can build a name this service does not cover -- an index
	 * name, say -- against the same prefix your tables use.
	 *
	 * @return string The configured prefix, or the slug, with a trailing underscore.
	 */
	public function get_table_prefix(): string {
		$prefix = $this->table_prefix ?? \str_replace( '-', '_', $this->get_plugin()->get_slug() );

		return $prefix . '_';
	}
}
