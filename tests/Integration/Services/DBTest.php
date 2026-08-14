<?php

declare( strict_types=1 );

namespace Zestry\WPToolkit\Tests\Integration\Services;

use Zestry\WPToolkit\Kernel\Plugin;
use Zestry\WPToolkit\Modules\DB;
use Zestry\WPToolkit\Tests\Support\TestCase;

/**
 * Table naming: slug normalisation, length limits, and core table lookup.
 *
 * The normalisation case is the one that matters. A hyphen is the convention
 * for a WordPress plugin slug and is illegal in an unquoted SQL identifier,
 * which is what dbDelta() needs -- and dbDelta() reports success either way, so
 * an unnormalised name creates nothing and says nothing.
 *
 * @covers \Zestry\WPToolkit\Modules\DB
 */
final class DBTest extends TestCase {

	private function db(): DB {
		return $this->plugin->get( DB::class );
	}

	/**
	 * Build a DB bound to a plugin with the given slug, since the slug is what
	 * this module's whole output turns on.
	 */
	private function db_for_slug( string $slug ): DB {
		$plugin = ( new Plugin( $this->entry_file, $slug ) )->declare_modules( $this->get_toolkit_modules() );

		return $plugin->get( DB::class );
	}

	public function test_a_table_is_prefixed_by_the_site_and_the_plugin(): void {
		global $wpdb;

		$this->assertSame(
			$wpdb->prefix . 'zestry_test_submissions',
			$this->db()->get_table( 'submissions' )
		);
	}

	/**
	 * The defect this module exists to remove: `contact-form-7` yields
	 * `wp_contact-form-7_entries`, which MySQL rejects as an unquoted
	 * identifier while dbDelta() reports having created it.
	 */
	public function test_a_hyphenated_slug_becomes_a_legal_identifier(): void {
		global $wpdb;

		$this->assertSame(
			$wpdb->prefix . 'contact_form_7_entries',
			$this->db_for_slug( 'contact-form-7' )->get_table( 'entries' )
		);
	}

	/**
	 * MySQL caps an identifier at 64 characters and errors above it -- an error
	 * dbDelta() swallows, so the check has to happen before the SQL is built.
	 */
	public function test_a_name_too_long_for_mysql_is_refused(): void {
		$db = $this->db_for_slug( str_repeat( 'a', 32 ) );

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'MySQL allows 64' );

		$db->get_table( str_repeat( 'b', 30 ) );
	}

	public function test_a_name_at_the_limit_is_allowed(): void {
		global $wpdb;

		// prefix + slug + '_' + name == exactly 64.
		$slug   = str_repeat( 'a', 30 );
		$length = DB::MAX_IDENTIFIER_LENGTH - strlen( $wpdb->prefix ) - strlen( $slug ) - 1;

		$this->assertSame(
			DB::MAX_IDENTIFIER_LENGTH,
			strlen( $this->db_for_slug( $slug )->get_table( str_repeat( 'b', $length ) ) )
		);
	}

	public function test_an_illegal_local_name_is_refused(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'letters, digits and underscores' );

		$this->db()->get_table( 'sub missions' );
	}

	public function test_an_empty_local_name_is_refused(): void {
		$this->expectException( \InvalidArgumentException::class );

		$this->db()->get_table( '' );
	}

	/**
	 * Read off $wpdb rather than composed from its prefix: on multisite `posts`
	 * is per-site while `users` is shared network-wide, so composing the name
	 * by hand is correct on one site and silently wrong on a network.
	 */
	public function test_a_core_table_comes_from_wordpress_itself(): void {
		global $wpdb;

		$this->assertSame( $wpdb->posts, $this->db()->get_core_table( 'posts' ) );
		$this->assertSame( $wpdb->users, $this->db()->get_core_table( 'users' ) );
	}

	public function test_an_unknown_core_table_is_refused(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'no core table named "widgets"' );

		$this->db()->get_core_table( 'widgets' );
	}

	public function test_table_exists_reports_a_missing_table(): void {
		$this->assertFalse( $this->db()->table_exists( 'never_created' ) );
	}

	/**
	 * The accessor exists so a caller needs no `global` line, and so a query still
	 * runs through a variable named `$wpdb` -- which is the only thing
	 * `WordPress.DB.PreparedSQL` recognises.
	 *
	 * @return void
	 */
	public function test_get_wpdb_hands_back_wordpress_own_handle(): void {
		global $wpdb;

		$this->assertSame( $wpdb, $this->db()->get_wpdb() );
	}

	/**
	 * The decision this class documents, pinned: no method here takes SQL.
	 *
	 * `WordPress.DB.PreparedSQL` is what catches a value interpolated into a query
	 * instead of prepared, and `WPDBTrait::is_wpdb_method_call()` recognises a
	 * query only by the literal `$wpdb` receiver -- there is no setting for
	 * another. Wrapping any of these would leave a consumer's `composer lint`
	 * green over an injection, so the wrapper must not exist.
	 *
	 * @return void
	 */
	public function test_no_method_here_takes_sql(): void {
		foreach ( array( 'query', 'prepare', 'get_var', 'get_row', 'get_col', 'get_results' ) as $method ) {
			$this->assertFalse(
				method_exists( DB::class, $method ),
				sprintf( 'DB::%s() would hide unprepared SQL from WordPress.DB.PreparedSQL.', $method )
			);
		}
	}

	public function test_table_exists_reports_a_present_one(): void {
		global $wpdb;

		$table = $this->db()->get_table( 'probe' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "CREATE TABLE {$table} ( id bigint(20) unsigned NOT NULL )" );

		try {
			$this->assertTrue( $this->db()->table_exists( 'probe' ) );
		} finally {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
		}
	}

	/**
	 * MySQL's 64-character cap is the reason this is settable: a long slug can
	 * leave too little room for the table's own name, and renaming the plugin
	 * to fix that would be absurd.
	 */
	public function test_a_configured_prefix_replaces_the_slug(): void {
		global $wpdb;

		$plugin = ( new Plugin( $this->entry_file, 'my-rather-long-plugin-name' ) )->declare_modules( $this->get_toolkit_modules() );
		$plugin->configure(
			DB::class,
			static function ( DB $db ): void {
				$db->set_table_prefix( 'mrlpn' );
			}
		);

		$this->assertSame(
			$wpdb->prefix . 'mrlpn_submissions',
			$plugin->get( DB::class )->get_table( 'submissions' )
		);
	}

	/**
	 * A slug is normalised because a plugin inherits it from its directory
	 * name; a prefix is chosen at the call site, so a hyphen there is a
	 * mistake worth naming rather than silently rewriting.
	 */
	public function test_a_hyphenated_prefix_is_refused_rather_than_normalised(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'letters, digits and underscores' );

		$this->db()->set_table_prefix( 'my-prefix' );
	}

	public function test_an_underscored_prefix_is_kept_as_written(): void {
		$db = $this->db();
		$db->set_table_prefix( 'my_prefix' );

		$this->assertSame( 'my_prefix_', $db->get_table_prefix() );
	}

	public function test_an_illegal_prefix_is_refused(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'must contain only letters, digits' );

		$this->db()->set_table_prefix( 'my prefix!' );
	}

	public function test_an_empty_prefix_is_refused(): void {
		$this->expectException( \InvalidArgumentException::class );

		$this->db()->set_table_prefix( '' );
	}

	/**
	 * The error names the way out, since the caller reaching it is the one who
	 * has to decide between a shorter table name and a shorter prefix.
	 */
	public function test_the_length_error_points_at_the_prefix_setter(): void {
		$db = $this->db_for_slug( str_repeat( 'a', 32 ) );

		$this->expectExceptionMessage( 'set_table_prefix()' );

		$db->get_table( str_repeat( 'b', 30 ) );
	}

	public function test_the_table_prefix_is_the_normalised_slug(): void {
		$this->assertSame( 'contact_form_7_', $this->db_for_slug( 'contact-form-7' )->get_table_prefix() );
	}

	public function test_the_charset_collate_clause_comes_from_the_site(): void {
		global $wpdb;

		$this->assertSame( $wpdb->get_charset_collate(), $this->db()->get_charset_collate() );
	}

	/**
	 * A `$wpdb` property that is not a table is refused.
	 *
	 * The guard used to accept any non-empty string property, so
	 * `get_core_table( 'prefix' )` returned `wp_` and `get_core_table(
	 * 'last_query' )` returned the last SQL statement -- each a plausible-looking
	 * value that is not a table, and neither an error.
	 *
	 * @dataProvider wpdb_properties_that_are_not_tables
	 */
	public function test_a_wpdb_property_that_is_not_a_table_is_refused( string $name ): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( $name );

		$this->db()->get_core_table( $name );
	}

	/**
	 * @return array<string, string[]>
	 */
	public function wpdb_properties_that_are_not_tables(): array {
		return array(
			'prefix'     => array( 'prefix' ),
			'charset'    => array( 'charset' ),
			'last_query' => array( 'last_query' ),
		);
	}
}
