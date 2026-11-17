<?php

declare( strict_types=1 );

namespace Zestry\WPToolkit\Tests\Integration\Modules;

use Zestry\WPToolkit\Modules\FileTransients;
use Zestry\WPToolkit\Tests\Support\TestCase;

/**
 * Values stored as PHP files; with the files off there is no storage at all.
 *
 * The test container runs PHP's CLI, where OPcache is usually off, so most
 * tests force the decision through the module's own filter.
 */
final class FileTransientsTest extends TestCase {

	private const FILTER = 'zestry_test_file_transients_enabled';

	public function tear_down(): void {
		$store = $this->store( true );
		$store->flush();

		$plugin_folder = dirname( $store->get_folder() );
		$this->remove_dir( $plugin_folder );

		parent::tear_down();
	}

	/**
	 * @return array<string, array{mixed}>
	 */
	public static function provide_values(): array {
		return array(
			'nested array'    => array(
				array(
					'a' => array( 1, 2, array( 'x' => 'y' ) ),
					5   => 'five',
					'b' => array(),
				),
			),
			'quotes'          => array( "it's \"quoted\" \\ back" ),
			'php tags'        => array( '<?php echo 1; ?> ?> <?= $x ?>' ),
			'null bytes'      => array( "a\0b\0" ),
			'unicode'         => array( 'żółć 日本語 😀' ),
			'int'             => array( 42 ),
			'negative int'    => array( -7 ),
			'float'           => array( 1.5 ),
			'whole float'     => array( 2.0 ),
			'true'            => array( true ),
			'false'           => array( false ),
			'null'            => array( null ),
			'empty string'    => array( '' ),
			'heredoc breaker' => array( "\nEOT;\n" ),
		);
	}

	public function test_the_filter_decides_and_is_named_for_the_slug(): void {
		$this->assertTrue( $this->store( true )->is_available() );
		$this->assertFalse( $this->store( false )->is_available() );
	}

	/**
	 * @dataProvider provide_values
	 */
	public function test_values_round_trip_exactly( mixed $value ): void {
		$store = $this->store( true );

		$store->set( 'k', $value );

		$this->assertSame( $value, $store->get( 'k', 'missing' ) );
		$this->assertTrue( $store->has( 'k' ) );
	}

	public function test_has_tells_a_stored_null_from_a_missing_key(): void {
		$store = $this->store( true );

		$this->assertFalse( $store->has( 'nope' ) );
		$this->assertSame( 'fb', $store->get( 'nope', 'fb' ) );

		$store->set( 'nothing', null );

		$this->assertTrue( $store->has( 'nothing' ) );
		$this->assertNull( $store->get( 'nothing', 'fb' ) );
	}

	public function test_delete_removes_a_value_and_ignores_a_missing_one(): void {
		$store = $this->store( true );
		$store->set( 'k', array( 1 ) );
		$store->delete( 'k' );
		$store->delete( 'never-there' );

		$this->assertFalse( $store->has( 'k' ) );
	}

	public function test_flush_removes_every_value_but_keeps_the_guard(): void {
		$store = $this->store( true );
		$store->set( 'a', 1 );
		$store->set( 'b', 2 );
		$store->flush();

		$this->assertFalse( $store->has( 'a' ) );
		$this->assertFalse( $store->has( 'b' ) );
		$this->assertSame( array( $store->get_folder() . '/index.php' ), glob( $store->get_folder() . '/*.php' ) );
	}

	public function test_a_negative_ttl_is_expired_and_reading_it_deletes_the_file(): void {
		$store = $this->store( true );
		$store->set( 'old', 'x', -10 );

		$this->assertFileExists( $store->get_file( 'old' ) );
		$this->assertFalse( $store->has( 'old' ) );
		$this->assertSame( 'fb', $store->get( 'old', 'fb' ) );
		$this->assertFileDoesNotExist( $store->get_file( 'old' ) );
	}

	public function test_a_future_expiry_is_kept_and_zero_never_expires(): void {
		$store = $this->store( true );
		$store->set( 'soon', 'x', 3600 );
		$store->set( 'never', 'y', 0 );

		$this->assertEqualsWithDelta( time() + 3600, ( include $store->get_file( 'soon' ) )['expires'], 5 );
		$this->assertSame( 0, ( include $store->get_file( 'never' ) )['expires'] );
		$this->assertSame( 'x', $store->get( 'soon' ) );
	}

	public function test_prune_deletes_expired_files_and_counts_them(): void {
		$store = $this->store( true );
		$store->set( 'a', 1, -5 );
		$store->set( 'b', 2, -5 );
		$store->set( 'keep', 3, 3600 );
		$store->set( 'forever', 4 );

		$this->assertSame( 2, $store->prune() );
		$this->assertSame( 0, $store->prune() );
		$this->assertSame( 3, $store->get( 'keep' ) );
		$this->assertSame( 4, $store->get( 'forever' ) );
	}

	public function test_prune_reads_the_expiry_without_including_the_file(): void {
		$store = $this->store( true );
		$store->set( 'big', array( 'x' => 1 ), 3600 );
		$store->set( 'old', array( 'x' => 1 ), -5 );

		// Bodies that announce being run: prune() must decide from the first line alone.
		foreach ( array( 'big' => time() + 3600, 'old' => time() - 5 ) as $key => $expires ) {
			file_put_contents(
				$store->get_file( $key ),
				"<?php // Expires: {$expires}\n\$GLOBALS['zestry_ft_included'][] = '{$key}'; return array( 'expires' => {$expires}, 'value' => 1 );\n"
			);
		}

		$GLOBALS['zestry_ft_included'] = array();

		$this->assertSame( 1, $store->prune() );
		$this->assertSame( array(), $GLOBALS['zestry_ft_included'] );
		$this->assertFileExists( $store->get_file( 'big' ) );
		$this->assertFileDoesNotExist( $store->get_file( 'old' ) );

		unset( $GLOBALS['zestry_ft_included'] );
	}

	public function test_prune_deletes_a_temp_file_only_once_it_is_stale(): void {
		$store = $this->store( true );
		$store->set( 'k', 1 );

		$stale = $store->get_folder() . '/.tmp-stale';
		$fresh = $store->get_folder() . '/.tmp-fresh';
		file_put_contents( $stale, 'half' );
		file_put_contents( $fresh, 'half' );
		touch( $stale, time() - 2 * HOUR_IN_SECONDS );

		$this->assertSame( 1, $store->prune() );
		$this->assertFileDoesNotExist( $stale );
		$this->assertFileExists( $fresh );
		$this->assertSame( 1, $store->get( 'k' ) );

		unlink( $fresh );
	}

	public function test_prune_runs_with_the_daily_cleanup_of_expired_transients(): void {
		$store = $this->store( true );

		$this->assertNotFalse( has_action( 'delete_expired_transients', array( $store, 'prune' ) ) );
	}

	public function test_an_object_or_a_closure_is_refused_and_not_stored(): void {
		$this->setExpectedIncorrectUsage( FileTransients::class . '::set' );

		$store = $this->store( true );
		$store->set( 'obj', new \stdClass() );
		$store->set(
			'nested',
			array(
				'a' => static function (): int {
					return 1;
				},
			)
		);

		$this->assertFalse( $store->has( 'obj' ) );
		$this->assertFalse( $store->has( 'nested' ) );
		$this->assertFileDoesNotExist( $store->get_file( 'obj' ) );
		$this->assertFileDoesNotExist( $store->get_file( 'nested' ) );
	}

	public function test_a_file_that_does_not_parse_counts_as_missing(): void {
		$store = $this->store( true );
		$store->set( 'bad', 'x' );
		file_put_contents( $store->get_file( 'bad' ), '<?php defined( "ABSPATH" ) || exit; return array( "expires" => ' );

		$this->assertFalse( $store->has( 'bad' ) );
		$this->assertSame( 'fb', $store->get( 'bad', 'fb' ) );
	}

	public function test_a_file_of_the_wrong_shape_counts_as_missing_and_is_pruned(): void {
		$store = $this->store( true );
		$store->set( 'odd', 'x' );
		file_put_contents( $store->get_file( 'odd' ), '<?php return 5;' );

		$this->assertFalse( $store->has( 'odd' ) );
		$this->assertSame( 1, $store->prune() );
	}

	public function test_the_file_name_is_stable_and_keeps_keys_that_read_alike_apart(): void {
		$store = $this->store( true );

		$this->assertSame( $store->get_file( 'Catalog/en' ), $store->get_file( 'Catalog/en' ) );
		$this->assertNotSame( $store->get_file( 'a b' ), $store->get_file( 'a-b' ) );
		$this->assertMatchesRegularExpression( '#/catalog-en-[0-9a-f]{16}\.php$#', $store->get_file( 'Catalog/en' ) );
		$this->assertMatchesRegularExpression( '#/[0-9a-f]{16}\.php$#', $store->get_file( '日本語' ) );
		$this->assertSame( dirname( $store->get_file( 'x' ) ), $store->get_folder() );

		$store->set( 'a b', 1 );
		$store->set( 'a-b', 2 );

		$this->assertSame( 1, $store->get( 'a b' ) );
		$this->assertSame( 2, $store->get( 'a-b' ) );
	}

	public function test_a_file_starts_with_the_abspath_guard_and_leaves_no_temp_file(): void {
		$store = $this->store( true );
		$store->set( 'g', array( 'x' => 1 ) );

		$this->assertStringStartsWith(
			"<?php // Expires: 0\ndefined( 'ABSPATH' ) || exit;\nreturn array( 'expires' => 0,",
			(string) file_get_contents( $store->get_file( 'g' ) )
		);
		$this->assertSame( array(), glob( $store->get_folder() . '/.tmp-*' ) );
	}

	public function test_the_folder_is_per_site_inside_the_plugins_cache_folder_with_guards(): void {
		$store = $this->store( true );
		$store->set( 'x', 1 );

		$this->assertSame(
			untrailingslashit( WP_CONTENT_DIR ) . '/cache/zestry-test/' . get_current_blog_id(),
			$store->get_folder()
		);
		$this->assertFileExists( $store->get_folder() . '/index.php' );
		$this->assertFileExists( dirname( $store->get_folder() ) . '/index.php' );

		if ( is_multisite() ) {
			$here = $store->get_folder();
			$blog = self::factory()->blog->create();

			switch_to_blog( $blog );
			$other = $store->get_folder();
			restore_current_blog();

			$this->assertNotSame( $here, $other );
			$this->assertStringEndsWith( '/' . $blog, $other );
		}
	}

	public function test_files_on_writes_no_option_row(): void {
		global $wpdb;

		$rows_before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options}" );

		$store = $this->store( true );
		$store->set( 'p', 1 );

		$this->assertTrue( $store->has( 'p' ) );
		$this->assertSame( $rows_before, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options}" ) );
	}

	public function test_files_off_stores_nothing_anywhere_and_says_why(): void {
		global $wpdb;

		$rows_before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options}" );
		$store       = $this->store( false );
		$store->set( 'q', 2 );

		$this->assertFalse( $store->has( 'q' ) );
		$this->assertNotSame( '', $store->get_unavailable_reason() );
		$this->assertFileDoesNotExist( $store->get_file( 'q' ) );
		$this->assertSame( $rows_before, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options}" ) );
	}

	public function test_files_off_get_returns_the_fallback_and_has_is_false(): void {
		$this->store( true )->set( 'q', 2 );

		$store = $this->store( false );

		$this->assertSame( 'fb', $store->get( 'q', 'fb' ) );
		$this->assertNull( $store->get( 'q' ) );
		$this->assertFalse( $store->has( 'q' ) );
	}

	public function test_the_reason_names_the_filter_when_the_filter_turned_them_off(): void {
		$store = $this->store( false );

		if ( ! $this->opcache_enabled() ) {
			$this->assertStringContainsString( 'OPcache', $store->get_unavailable_reason() );

			return;
		}

		$this->assertStringContainsString( self::FILTER, $store->get_unavailable_reason() );
	}

	public function test_the_reason_is_empty_when_the_files_are_on(): void {
		$this->assertSame( '', $this->store( true )->get_unavailable_reason() );
	}

	public function test_a_write_that_fails_warns_and_leaves_nothing_behind(): void {
		$store = $this->store( true );
		$path  = $store->get_file( 'w' );

		// A directory where the file belongs: rename() onto it fails, even as root.
		wp_mkdir_p( $path );

		// PHP's own warning about the rename is not hidden: it is caught here and checked.
		$warnings = array();
		set_error_handler(
			static function ( int $level, string $message ) use ( &$warnings ): bool {
				$warnings[] = $message;

				return true;
			},
			E_WARNING
		);

		try {
			$store->set( 'w', 1 );
		} finally {
			restore_error_handler();
		}

		$this->assertCount( 1, $warnings );
		$this->assertStringContainsString( 'rename', $warnings[0] );
		$this->assertFalse( $store->has( 'w' ) );
		$this->assertFalse( is_file( $path ) );
		$this->assertSame( array(), glob( $store->get_folder() . '/.tmp-*' ) );

		rmdir( $path );
	}

	public function test_a_second_set_replaces_the_first(): void {
		$store = $this->store( true );
		$store->set( 'k', array( 'old' ) );
		$store->set( 'k', array( 'new' ), 3600 );

		$this->assertSame( array( 'new' ), $store->get( 'k' ) );
		$this->assertSame( array( 'new' ), $this->store( true )->get( 'k' ), 'A fresh instance reads the same file.' );
	}

	public function test_the_decision_is_made_once_per_instance(): void {
		$store = $this->store( true );

		add_filter( self::FILTER, '__return_false' );

		try {
			$this->assertTrue( $store->is_available() );
			$store->set( 'k', 1 );
			$this->assertSame( 1, $store->get( 'k' ) );
		} finally {
			remove_filter( self::FILTER, '__return_false' );
		}
	}

	public function test_the_filter_is_handed_what_the_module_found(): void {
		$seen = null;
		$spy  = static function ( bool $found ) use ( &$seen ): bool {
			$seen = $found;

			return $found;
		};

		add_filter( self::FILTER, $spy );

		try {
			$available = $this->plugin->make( FileTransients::class )->is_available();
		} finally {
			remove_filter( self::FILTER, $spy );
		}

		$this->assertSame( $this->opcache_enabled(), $seen );
		$this->assertSame( $seen, $available );
	}

	public function test_long_keys_that_share_a_readable_part_stay_apart(): void {
		$store  = $this->store( true );
		$prefix = str_repeat( 'catalog-', 10 );

		$store->set( $prefix . 'en', 'en' );
		$store->set( $prefix . 'fr', 'fr' );

		$this->assertNotSame( $store->get_file( $prefix . 'en' ), $store->get_file( $prefix . 'fr' ) );
		$this->assertSame( 'en', $store->get( $prefix . 'en' ) );
		$this->assertSame( 'fr', $store->get( $prefix . 'fr' ) );
		$this->assertLessThanOrEqual( 40 + 1 + 16 + 4, strlen( basename( $store->get_file( $prefix . 'en' ) ) ) );
	}

	public function test_an_expires_line_inside_a_value_does_not_fool_prune(): void {
		$store = $this->store( true );
		$store->set( 'trap', "first\nExpires: 0\n", -5 );
		$store->set( 'safe', "first\nExpires: 1\n", 3600 );

		$this->assertSame( 1, $store->prune() );
		$this->assertFalse( $store->has( 'trap' ) );
		$this->assertSame( "first\nExpires: 1\n", $store->get( 'safe' ) );
	}

	public function test_a_resource_and_a_deeply_nested_object_are_refused(): void {
		$this->setExpectedIncorrectUsage( FileTransients::class . '::set' );

		$store    = $this->store( true );
		$resource = fopen( 'php://memory', 'rb' );

		try {
			$store->set( 'res', $resource );
			$store->set( 'deep', array( 'a' => array( 'b' => array( 'c' => new \ArrayObject() ) ) ) );
		} finally {
			fclose( $resource );
		}

		$this->assertFalse( $store->has( 'res' ) );
		$this->assertFalse( $store->has( 'deep' ) );
	}

	public function test_an_expiry_of_exactly_now_has_expired(): void {
		$store = $this->store( true );
		$store->set( 'now', 'x', 0 );

		// Rewritten to expire this second: the boundary counts as gone.
		$now = time();
		file_put_contents(
			$store->get_file( 'now' ),
			"<?php // Expires: {$now}\ndefined( 'ABSPATH' ) || exit;\nreturn array( 'expires' => {$now}, 'value' => 'x' );\n"
		);

		$this->assertFalse( $store->has( 'now' ) );
	}

	public function test_a_folder_that_cannot_be_created_breaks_nothing(): void {
		$blocker = untrailingslashit( WP_CONTENT_DIR ) . '/cache/zestry-test';

		if ( is_dir( $blocker ) ) {
			$this->remove_dir( $blocker );
		}

		wp_mkdir_p( dirname( $blocker ) );
		file_put_contents( $blocker, 'a file where the folder belongs' );

		try {
			$store = $this->store( true );
			$store->set( 'k', 1 );

			$this->assertNull( $store->get( 'k' ) );
			$this->assertFalse( $store->has( 'k' ) );

			$store->delete( 'k' );
			$store->flush();
			$this->assertSame( 0, $store->prune() );

			// Unforced, the module finds no folder and turns the files off itself.
			$this->assertFalse( $this->plugin->make( FileTransients::class )->is_available() );
		} finally {
			unlink( $blocker );
		}
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function provide_empty_keys(): array {
		return array(
			'empty'      => array( '' ),
			'whitespace' => array( " \t\n" ),
		);
	}

	/**
	 * @dataProvider provide_empty_keys
	 */
	public function test_an_empty_key_throws_whichever_method_is_called( string $key ): void {
		$store = $this->store( true );
		$calls = array(
			'get'      => static fn () => $store->get( $key ),
			'set'      => static fn () => $store->set( $key, 1 ),
			'has'      => static fn () => $store->has( $key ),
			'delete'   => static fn () => $store->delete( $key ),
			'get_file' => static fn () => $store->get_file( $key ),
		);

		foreach ( $calls as $method => $call ) {
			try {
				$call();
				$this->fail( "{$method}() accepted an empty key." );
			} catch ( \InvalidArgumentException $e ) {
				$this->assertStringContainsString( 'empty', $e->getMessage() );
			}
		}
	}

	public function test_an_empty_key_throws_even_with_the_files_off(): void {
		$this->expectException( \InvalidArgumentException::class );

		$this->store( false )->set( '', 1 );
	}

	/**
	 * Skipped under the container's CLI. It only proves the invalidation with
	 * OPcache caching a file the moment it is written:
	 *
	 *     php -d opcache.enable_cli=1 -d opcache.file_update_protection=0 \
	 *         -d opcache.validate_timestamps=0 vendor/bin/phpunit --filter FileTransientsTest
	 *
	 * With the default two-second protection a fresh file is never cached, so
	 * the test passes with or without the invalidation.
	 */
	public function test_with_opcache_on_a_rewritten_value_is_served_fresh(): void {
		if ( ! $this->opcache_enabled() ) {
			$this->markTestSkipped( 'OPcache is off in this PHP, so there is no compiled copy to go stale.' );
		}

		$store = $this->plugin->make( FileTransients::class );

		$this->assertTrue( $store->is_available() );

		$store->set( 'live', array( 'v' => 1 ) );
		$this->assertSame( array( 'v' => 1 ), $store->get( 'live' ) );

		// Same request, same second: only the invalidation can make OPcache drop the first copy.
		$store->set( 'live', array( 'v' => 2 ) );
		$this->assertSame( array( 'v' => 2 ), $store->get( 'live' ) );

		$store->delete( 'live' );
		$this->assertFalse( $store->has( 'live' ) );
	}

	public function test_remember_works_a_value_out_once_and_then_reads_the_file(): void {
		$store    = $this->store( true );
		$calls    = 0;
		$callback = static function () use ( &$calls ): array {
			++$calls;

			return array( 'items' => array( 1, 2, 3 ) );
		};

		$this->assertSame( array( 'items' => array( 1, 2, 3 ) ), $store->remember( 'index', $callback, 3600 ) );
		$this->assertSame( array( 'items' => array( 1, 2, 3 ) ), $store->remember( 'index', $callback, 3600 ) );
		$this->assertSame( 1, $calls );
		$this->assertFileExists( $store->get_file( 'index' ) );
		$this->assertEqualsWithDelta( time() + 3600, ( include $store->get_file( 'index' ) )['expires'], 5 );
	}

	public function test_remember_keeps_a_null_answer_rather_than_asking_again(): void {
		$store    = $this->store( true );
		$calls    = 0;
		$callback = static function () use ( &$calls ): mixed {
			++$calls;

			return null;
		};

		$store->remember( 'nothing', $callback );

		$this->assertNull( $store->remember( 'nothing', $callback ) );
		$this->assertSame( 1, $calls );
	}

	public function test_remember_works_it_out_again_once_expired(): void {
		$store = $this->store( true );
		$store->set( 'old', 'stale', -5 );

		$this->assertSame( 'fresh', $store->remember( 'old', static fn (): string => 'fresh', 3600 ) );
		$this->assertSame( 'fresh', $store->get( 'old' ) );
	}

	public function test_remember_with_the_files_off_works_it_out_every_time_and_writes_nothing(): void {
		$store    = $this->store( false );
		$calls    = 0;
		$callback = static function () use ( &$calls ): int {
			return ++$calls;
		};

		$this->assertSame( 1, $store->remember( 'k', $callback ) );
		$this->assertSame( 2, $store->remember( 'k', $callback ) );
		$this->assertFileDoesNotExist( $store->get_file( 'k' ) );
	}

	public function test_remember_returns_a_value_it_cannot_store_and_says_so(): void {
		$this->setExpectedIncorrectUsage( FileTransients::class . '::set' );

		$store = $this->store( true );
		$error = new \WP_Error( 'down', 'The remote is down.' );

		$this->assertSame( $error, $store->remember( 'k', static fn (): \WP_Error => $error ) );
		$this->assertFalse( $store->has( 'k' ) );
	}

	public function test_remember_stores_nothing_when_the_callback_throws(): void {
		$store = $this->store( true );

		try {
			$store->remember(
				'k',
				static function (): never {
					throw new \RuntimeException( 'The remote is down.' );
				}
			);
			$this->fail( 'The exception was swallowed.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'The remote is down.', $e->getMessage() );
		}

		$this->assertFalse( $store->has( 'k' ) );
		$this->assertFileDoesNotExist( $store->get_file( 'k' ) );
	}

	public function test_remember_with_an_empty_key_throws_before_working_anything_out(): void {
		$called = false;

		try {
			$this->store( true )->remember(
				' ',
				static function () use ( &$called ): int {
					$called = true;

					return 1;
				}
			);
			$this->fail( 'An empty key was accepted.' );
		} catch ( \InvalidArgumentException $e ) {
			$this->assertFalse( $called );
		}
	}

	/**
	 * A fresh module, its once-per-request decision forced one way.
	 */
	private function store( bool $available ): FileTransients {
		$force = static fn (): bool => $available;

		add_filter( self::FILTER, $force );

		$store = $this->plugin->make( FileTransients::class );
		$store->is_available();

		remove_filter( self::FILTER, $force );

		return $store;
	}

	private function opcache_enabled(): bool {
		$status = function_exists( 'opcache_get_status' ) ? opcache_get_status( false ) : false;

		return is_array( $status ) && ! empty( $status['opcache_enabled'] );
	}
}
