<?php

declare( strict_types=1 );

namespace Zestry\WPToolkit\Tests\Integration\Modules;

use Zestry\WPToolkit\Kernel\Plugin;
use Zestry\WPToolkit\Modules\DB;
use Zestry\WPToolkit\Modules\Migrations\ManyBaselinesException;
use Zestry\WPToolkit\Modules\Migrations\Migrations;
use Zestry\WPToolkit\Modules\Options;
use Zestry\WPToolkit\Modules\Migrations\SquashMigrationsCommand;
use Zestry\WPToolkit\Tests\Support\TestCase;

/**
 * Baselines: which site runs one and which records it without running, what a
 * baseline subsumes, and the `migrations squash` command that writes one --
 * including a round trip, where a real table is dumped and the generated file
 * is run back against a database that does not have it.
 *
 * @covers \Zestry\WPToolkit\Modules\Migrations\Migrations
 * @covers \Zestry\WPToolkit\Modules\Migrations\Baseline
 * @covers \Zestry\WPToolkit\Modules\Migrations\SquashMigrationsCommand
 */
final class MigrationsBaselineTest extends TestCase {

	public function set_up(): void {
		parent::set_up();

		$GLOBALS['zestry_migration_log'] = array();

		if ( ! defined( 'WP_CLI' ) ) {
			define( 'WP_CLI', true );
		}

		\WP_CLI::reset();

		/*
		 * Its own slug, and not for tidiness. These tests create real tables, and
		 * dropping one is DDL -- which makes MySQL implicitly commit the
		 * transaction the test case relies on to roll everything back. So this
		 * class's writes outlive it whatever we do about ordering. A slug of its
		 * own is what makes that harmless: the ran-record lives in an option
		 * named after the plugin, so nothing else can read what leaks. Sharing
		 * `zestry-test` with MigrationsTest meant it inherited a ledger naming
		 * migrations it had never written, and threw the rename guard.
		 */
		$this->plugin = ( new Plugin( $this->entry_file, 'zestry-baseline' ) )
			->declare_multiple( $this->get_toolkit_modules() );

		// And the slug alone is not enough within this class, where every test
		// shares it: the commit above is just as durable between two tests here
		// as it was between two classes. Each starts from an empty ledger.
		$this->forget_ran_migrations();
	}

	public function tear_down(): void {
		unset( $GLOBALS['zestry_migration_log'] );

		foreach ( array( 'books', 'authors', 'reviews' ) as $table ) {
			$this->drop_table( $table );
		}

		parent::tear_down();
	}

	public function test_a_fresh_site_runs_the_baseline_instead_of_what_it_subsumes(): void {
		$this->write_migration( '20260101000000-first', $this->record( 'first' ) );
		$this->write_migration( '20260102000000-second', $this->record( 'second' ) );
		$this->write_migration( '20260104000000-later', $this->record( 'later' ) );
		$this->write_baseline(
			'baseline',
			$this->record( 'baseline' ),
			array( '20260101000000-first', '20260102000000-second' )
		);

		$this->plugin->get( Migrations::class )->run_pending();

		$this->assertSame(
			array( 'baseline', 'later' ),
			$GLOBALS['zestry_migration_log'],
			'The two migrations before the baseline must not run; the one after it must.'
		);
	}

	public function test_a_fresh_site_records_what_the_baseline_subsumed_as_run(): void {
		$this->write_migration( '20260101000000-first', $this->record( 'first' ) );
		$this->write_baseline( 'baseline', $this->record( 'baseline' ), array( '20260101000000-first' ) );

		$migrations = $this->plugin->get( Migrations::class );
		$migrations->run_pending();

		$this->assertSame(
			array( '20260101000000-first', 'baseline' ),
			$migrations->get_ran_migrations(),
			'A subsumed migration is recorded, or the next run would run it after all.'
		);

		// And a second run does nothing at all.
		$GLOBALS['zestry_migration_log'] = array();
		$migrations->run_pending();
		$this->assertSame( array(), $GLOBALS['zestry_migration_log'] );
	}

	public function test_a_site_with_a_history_records_the_baseline_without_running_it(): void {
		// The site is at "first", which is how it differs from a fresh install.
		$this->write_migration( '20260101000000-first', $this->record( 'first' ) );
		$migrations = $this->plugin->get( Migrations::class );
		$migrations->run_pending();

		// The release that ships a baseline also ships the migrations it covers.
		$this->write_migration( '20260102000000-second', $this->record( 'second' ) );
		$this->write_baseline(
			'baseline',
			$this->record( 'baseline' ),
			array( '20260101000000-first', '20260102000000-second' )
		);

		$GLOBALS['zestry_migration_log'] = array();
		$this->plugin->make( Migrations::class )->run_pending();

		$this->assertSame(
			array( 'second' ),
			$GLOBALS['zestry_migration_log'],
			'The baseline must not run over an existing schema, and the migration it would have subsumed must still run.'
		);
	}

	public function test_the_baseline_is_recorded_on_a_site_with_a_history_so_it_never_stays_pending(): void {
		$this->write_migration( '20260101000000-first', $this->record( 'first' ) );
		$migrations = $this->plugin->get( Migrations::class );
		$migrations->run_pending();

		$this->write_baseline( 'baseline', $this->record( 'baseline' ), array( '20260101000000-first' ) );
		$this->plugin->make( Migrations::class )->run_pending();

		$this->assertContains( 'baseline', $migrations->get_ran_migrations() );
	}

	public function test_a_migration_the_baseline_does_not_name_still_runs_however_it_sorts(): void {
		// Backdated on purpose: it sorts before everything the baseline covers,
		// and it is still not covered. Subsumption is the list, not the order --
		// which is what a positional rule got wrong, and silently.
		$this->write_migration( '20250101000000-backdated', $this->record( 'backdated' ) );
		$this->write_migration( '20260101000000-first', $this->record( 'first' ) );
		$this->write_baseline( 'baseline', $this->record( 'baseline' ), array( '20260101000000-first' ) );

		$this->plugin->get( Migrations::class )->run_pending();

		$this->assertSame( array( 'baseline', 'backdated' ), $GLOBALS['zestry_migration_log'] );
	}

	public function test_a_subsumed_migration_that_no_longer_exists_leaves_no_phantom_in_the_ledger(): void {
		// The baseline names one that has since been deleted. Recording it would
		// put an identifier with no file into the ran-list, which is exactly what
		// `migrations list` reports as orphaned.
		$this->write_baseline( 'baseline', $this->record( 'baseline' ), array( '20260101000000-deleted' ) );

		$migrations = $this->plugin->get( Migrations::class );
		$migrations->run_pending();

		$this->assertSame( array( 'baseline' ), $migrations->get_ran_migrations() );
		$this->assertSame( array(), $migrations->get_orphaned_migrations() );
	}

	public function test_two_baselines_stop_the_run_before_anything_happens(): void {
		$this->write_baseline( 'baseline', $this->record( 'one' ) );
		$this->write_baseline( 'baseline-copy', $this->record( 'two' ) );

		try {
			$this->plugin->get( Migrations::class )->run_pending();
			$this->fail( 'Two baselines must stop the run.' );
		} catch ( ManyBaselinesException $exception ) {
			$this->assertStringContainsString( 'baseline', $exception->getMessage() );
			$this->assertStringContainsString( 'baseline-copy', $exception->getMessage() );
		}

		$this->assertSame( array(), $GLOBALS['zestry_migration_log'], 'Nothing ran.' );
	}

	public function test_get_migrations_after_baseline_is_what_a_build_checks(): void {
		$this->write_migration( '20260101000000-first', '' );
		$this->write_migration( '20260103000000-later', '' );
		$this->write_baseline( 'baseline', '', array( '20260101000000-first' ) );

		$migrations = $this->plugin->get( Migrations::class );

		$this->assertSame( 'baseline', $migrations->get_baseline() );
		$this->assertSame( array( '20260103000000-later' ), $migrations->get_migrations_after_baseline() );
	}

	public function test_every_migration_is_after_a_baseline_that_does_not_exist(): void {
		$this->write_migration( '20260101000000-first', '' );

		$migrations = $this->plugin->get( Migrations::class );

		$this->assertNull( $migrations->get_baseline() );
		$this->assertSame( array( '20260101000000-first' ), $migrations->get_migrations_after_baseline() );
	}

	public function test_check_passes_when_the_baseline_covers_everything(): void {
		$this->write_migration( '20260101000000-first', '' );
		$this->write_baseline( 'baseline', '', array( '20260101000000-first' ) );

		$this->run_squash( array( 'check' => true ) );

		$this->assertNotNull( \WP_CLI::last( 'success' ) );
		$this->assertNull( \WP_CLI::last( 'error' ) );
	}

	public function test_check_fails_when_a_migration_is_newer_than_the_baseline(): void {
		$this->write_baseline( 'baseline', '' );
		$this->write_migration( '20260103000000-later', '' );

		$this->run_squash( array( 'check' => true ) );

		$error = \WP_CLI::last( 'error' );

		$this->assertNotNull( $error );
		$this->assertStringContainsString( '1 migration is newer than the baseline', (string) $error[0] );
	}

	public function test_check_passes_for_a_plugin_that_has_never_squashed(): void {
		$this->write_migration( '20260101000000-first', '' );

		$this->run_squash( array( 'check' => true ) );

		// Not a failure: squashing is opt-in, and a plugin without a baseline is
		// in the state every plugin was in before they existed.
		$this->assertNull( \WP_CLI::last( 'error' ) );
		$this->assertStringContainsString( 'No baseline', (string) \WP_CLI::last( 'success' )[0] );
	}

	public function test_squash_refuses_while_a_migration_is_still_pending(): void {
		$this->write_migration( '20260101000000-first', '' );

		$this->run_squash();

		$this->assertStringContainsString(
			'Run `migrations run` first',
			(string) \WP_CLI::last( 'error' )[0],
			'A schema dumped halfway through a batch is a schema no release ever has.'
		);
	}

	public function test_squash_refuses_when_the_plugin_owns_no_tables(): void {
		$this->run_squash();

		$this->assertStringContainsString( 'no schema to dump', (string) \WP_CLI::last( 'error' )[0] );
	}

	public function test_squash_writes_a_baseline_that_recreates_the_dumped_schema(): void {
		// A real table, made by a real migration, so the dump is of something
		// this plugin's own migrations produced.
		$this->write_migration( '20260101000000-create-books', $this->create_books_table() );

		$migrations = $this->plugin->get( Migrations::class );
		$migrations->run_pending();

		$this->assertTrue( $this->plugin->get( DB::class )->table_exists( 'books' ) );

		$this->run_squash( array( 'yes' => true ) );

		$written = (array) glob( $this->plugin_dir . '/resources/migrations/baseline.php' );

		$this->assertCount( 1, $written );

		$source = (string) file_get_contents( (string) $written[0] );

		// Prefix-independent, charset-independent, and not carrying this
		// database's row count -- the three things that would make the file
		// wrong on somebody else's site.
		$this->assertStringContainsString( "\$this->get_table( 'books' )", $source );
		$this->assertStringContainsString( '$this->get_charset_collate()', $source );
		$this->assertStringNotContainsString( 'AUTO_INCREMENT=', $source );
		$this->assertStringNotContainsString(
			'TEMPORARY',
			$source,
			"WordPress's own test bootstrap rewrites CREATE TABLE to CREATE TEMPORARY TABLE, and a baseline that"
				. ' kept the word would create a table that vanishes with the connection.'
		);
		$this->assertStringContainsString( '20260101000000-create-books', $source, 'The file lists what it subsumes.' );

		// The round trip: drop the table, forget the history, and let the
		// generated file be the only thing that puts the schema back.
		$this->drop_table( 'books' );
		$this->forget_ran_migrations();

		$GLOBALS['zestry_migration_log'] = array();
		$this->plugin->make( Migrations::class )->run_pending();

		$this->assertTrue(
			$this->plugin->get( DB::class )->table_exists( 'books' ),
			'The generated baseline must create the schema it was dumped from.'
		);
		$this->assertSame(
			array(),
			$GLOBALS['zestry_migration_log'],
			'And the migration it subsumes must not have run.'
		);
	}

	public function test_a_baseline_reproduces_a_schema_five_migrations_built_up(): void {
		// A plugin's schema as it actually arrives: tables added over time,
		// columns added to one that already existed, a type widened, indexes
		// appearing late. The final shape is in none of these files -- it is what
		// they add up to, which is exactly what a baseline has to capture.
		$this->write_migration(
			'20260101000000-create-books',
			$this->db_delta_body(
				'books',
				array(
					'id bigint(20) unsigned NOT NULL auto_increment',
					'title varchar(100) NOT NULL',
					'PRIMARY KEY  (id)',
				)
			)
		);

		$this->write_migration(
			'20260102000000-create-authors',
			$this->db_delta_body(
				'authors',
				array(
					'id bigint(20) unsigned NOT NULL auto_increment',
					'name varchar(255) NOT NULL',
					'email varchar(100) DEFAULT NULL',
					'PRIMARY KEY  (id)',
					'KEY name (name)',
				)
			)
		);

		// Two new columns on a table that already has rows' worth of definition.
		$this->write_migration(
			'20260103000000-add-book-columns',
			$this->db_delta_body(
				'books',
				array(
					'id bigint(20) unsigned NOT NULL auto_increment',
					'title varchar(100) NOT NULL',
					'author_id bigint(20) unsigned NOT NULL default 0',
					'published_at datetime DEFAULT NULL',
					'PRIMARY KEY  (id)',
				)
			)
		);

		// A widened type and an index arriving after the fact.
		$this->write_migration(
			'20260104000000-widen-title-and-index-author',
			$this->db_delta_body(
				'books',
				array(
					'id bigint(20) unsigned NOT NULL auto_increment',
					'title varchar(255) NOT NULL',
					'author_id bigint(20) unsigned NOT NULL default 0',
					'published_at datetime DEFAULT NULL',
					'PRIMARY KEY  (id)',
					'KEY author_id (author_id)',
				)
			)
		);

		$this->write_migration(
			'20260105000000-create-reviews',
			$this->db_delta_body(
				'reviews',
				array(
					'id bigint(20) unsigned NOT NULL auto_increment',
					'book_id bigint(20) unsigned NOT NULL',
					'reviewer varchar(100) NOT NULL',
					'rating tinyint(1) unsigned NOT NULL default 0',
					'created_at datetime NOT NULL',
					'PRIMARY KEY  (id)',
					'UNIQUE KEY book_reviewer (book_id,reviewer)',
					'KEY rating_created (rating,created_at)',
				)
			)
		);

		$this->plugin->get( Migrations::class )->run_pending();

		$tables = array( 'authors', 'books', 'reviews' );
		$before = $this->describe_schema( $tables );

		// The later migrations really did change things, so the comparison below
		// is against an evolved schema rather than against the first one.
		$this->assertStringContainsString( '`title` varchar(255)', $before['books'], 'The widened type took effect.' );
		$this->assertStringContainsString( '`author_id`', $before['books'], 'The added column took effect.' );
		$this->assertStringContainsString( '`published_at` datetime', $before['books'] );
		$this->assertStringContainsString( 'KEY `author_id`', $before['books'], 'The late index took effect.' );
		$this->assertStringContainsString( 'UNIQUE KEY `book_reviewer`', $before['reviews'] );

		$this->run_squash( array( 'yes' => true ) );

		$source = (string) file_get_contents( $this->plugin_dir . '/resources/migrations/baseline.php' );

		$this->assertSame( 3, substr_count( $source, 'CREATE TABLE' ), 'One statement per table, and no more.' );
		$this->assertStringContainsString( "\$this->get_table( 'books' )", $source );
		$this->assertStringContainsString( "\$this->get_table( 'authors' )", $source );
		$this->assertStringContainsString( "\$this->get_table( 'reviews' )", $source );
		$this->assertSame( 5, substr_count( $source, "\n\t\t\t'2026" ), 'All five migrations are named in subsumes().' );

		// The round trip: no tables, no history, and the baseline is the only
		// thing left to put the schema back.
		foreach ( $tables as $table ) {
			$this->drop_table( $table );
		}

		$this->forget_ran_migrations();

		$GLOBALS['zestry_migration_log'] = array();
		$this->plugin->make( Migrations::class )->run_pending();

		$this->assertSame(
			$before,
			$this->describe_schema( $tables ),
			'The baseline must reproduce the schema exactly -- every column, type, default and index.'
		);
		$this->assertSame(
			array(),
			$GLOBALS['zestry_migration_log'],
			'And it must have got there in one step, with none of the five running.'
		);
	}

	public function test_squash_replaces_an_existing_baseline_rather_than_leaving_two(): void {
		$this->write_baseline( 'baseline', '' );
		$this->write_migration( '20260102000000-create-books', $this->create_books_table() );

		$this->plugin->get( Migrations::class )->run_pending();
		$this->run_squash( array( 'yes' => true ) );

		$this->assertCount(
			1,
			(array) glob( $this->plugin_dir . '/resources/migrations/*baseline*.php' ),
			'One baseline, rewritten in place, rather than one file appearing and another vanishing.'
		);
		$this->assertStringContainsString(
			"'20260102000000-create-books'",
			(string) file_get_contents( $this->plugin_dir . '/resources/migrations/baseline.php' ),
			'The rewrite picks up the migration that ran since the last squash.'
		);
	}

	/**
	 * Run the real squash command against the test plugin.
	 *
	 * @param array<string, mixed> $assoc_args Flags, as WP-CLI would pass them.
	 * @return void
	 */
	private function run_squash( array $assoc_args = array() ): void {
		\WP_CLI::reset();

		$command = new SquashMigrationsCommand();
		$this->plugin->wire( $command );
		$command->set_arguments( array(), $assoc_args );
		$command->handle( array(), $assoc_args );
	}

	/**
	 * A migration body running one `dbDelta()` over a table definition.
	 *
	 * @param string   $table The local table name.
	 * @param string[] $lines Column and key definitions, in order.
	 * @return string
	 */
	private function db_delta_body( string $table, array $lines ): string {
		$indented = array();

		foreach ( $lines as $line ) {
			$indented[] = '  ' . $line;
		}

		return '$this->db_delta( "CREATE TABLE {$this->get_table( \'' . $table . '\' )} (\n'
			. implode( ',\n', $indented )
			. '\n) {$this->get_charset_collate()};" );';
	}

	/**
	 * Each table's `CREATE TABLE`, with this database's row count taken out.
	 *
	 * The strongest comparison available: column order, types, nullability,
	 * defaults and every index, in MySQL's own words rather than in the test's.
	 *
	 * @param string[] $tables Local table names.
	 * @return array<string, string>
	 */
	private function describe_schema( array $tables ): array {
		$db     = $this->plugin->get( DB::class );
		$wpdb   = $db->get_wpdb();
		$schema = array();

		foreach ( $tables as $table ) {
			$row = $wpdb->get_row( $wpdb->prepare( 'SHOW CREATE TABLE %i', $db->get_table( $table ) ), ARRAY_N );

			$schema[ $table ] = (string) preg_replace(
				'/\s*AUTO_INCREMENT=\d+/i',
				'',
				(string) ( $row[1] ?? '' )
			);
		}

		return $schema;
	}

	/**
	 * A migration body that records that it ran.
	 *
	 * @param string $label What to record.
	 * @return string
	 */
	private function record( string $label ): string {
		return '$GLOBALS["zestry_migration_log"][] = "' . $label . '";';
	}

	/**
	 * A migration body creating a real table, so there is a schema to dump.
	 *
	 * @return string
	 */
	private function create_books_table(): string {
		return '$this->db_delta( "CREATE TABLE {$this->get_table( \'books\' )} ('
			. ' id bigint(20) unsigned NOT NULL auto_increment,'
			. ' title varchar(255) NOT NULL,'
			. ' PRIMARY KEY  (id),'
			. ' KEY title (title)'
			. ' ) {$this->get_charset_collate()};" );';
	}

	private function write_migration( string $name, string $body ): void {
		$this->write_plugin_file(
			'resources/migrations/' . $name . '.php',
			"<?php\nuse Zestry\\WPToolkit\\Modules\\Migrations\\Migration;\nreturn new class extends Migration {\n    public function up(): void {\n        {$body}\n    }\n};\n"
		);
	}

	/**
	 * @param string   $name     The identifier (filename without `.php`).
	 * @param string   $body     The up() body.
	 * @param string[] $subsumes What it stands in for.
	 * @return void
	 */
	private function write_baseline( string $name, string $body, array $subsumes = array() ): void {
		$list = '';

		foreach ( $subsumes as $identifier ) {
			$list .= "'" . $identifier . "', ";
		}

		$this->write_plugin_file(
			'resources/migrations/' . $name . '.php',
			"<?php\nuse Zestry\\WPToolkit\\Modules\\Migrations\\Baseline;\nreturn new class extends Baseline {\n"
				. "    public function subsumes(): array { return array( {$list} ); }\n"
				. "    public function up(): void {\n        {$body}\n    }\n};\n"
		);
	}

	/**
	 * Make the site look like one that has never migrated.
	 *
	 * @return void
	 */
	private function forget_ran_migrations(): void {
		$options = $this->plugin->get( Options::class )->group( Migrations::OPTIONS_GROUP_NAME );

		$options->delete( 'ran' );
		$options->delete( 'running_since' );
		$options->save();
	}

	/**
	 * @param string $name The local table name.
	 * @return void
	 */
	private function drop_table( string $name ): void {
		$wpdb = $this->plugin->get( DB::class )->get_wpdb();

		$wpdb->query( 'DROP TABLE IF EXISTS `' . $this->plugin->get( DB::class )->get_table( $name ) . '`' );
	}
}
