<?php

declare( strict_types=1 );

namespace Zestry\WPToolkit\Tests\Integration\Core;

use Zestry\WPToolkit\Kernel\Helpers\Arr;
use Zestry\WPToolkit\Kernel\Helpers\Str;
use Zestry\WPToolkit\Tests\Support\TestCase;

/**
 * The static helpers: array paths, path joining, and case conversion.
 *
 * @covers \Zestry\WPToolkit\Kernel\Helpers\Arr
 * @covers \Zestry\WPToolkit\Kernel\Helpers\Str
 */
final class HelpersArrStrTest extends TestCase {

	public function test_a_dotted_path_reads_a_nested_value(): void {
		$data = array( 'billing' => array( 'address' => array( 'city' => 'Cluj' ) ) );

		$this->assertSame( 'Cluj', Arr::get( $data, 'billing.address.city' ) );
		$this->assertSame( 'Cluj', Arr::get( $data, array( 'billing', 'address', 'city' ) ) );
	}

	public function test_a_missing_step_returns_the_fallback(): void {
		$data = array( 'billing' => array( 'address' => array() ) );

		$this->assertSame( 'none', Arr::get( $data, 'billing.address.city', 'none' ) );
		$this->assertSame( 'none', Arr::get( $data, 'nothing.at.all', 'none' ) );
		// Walking *through* a scalar must not warn, it must simply not resolve.
		$this->assertSame( 'none', Arr::get( array( 'a' => 1 ), 'a.b.c', 'none' ) );
	}

	/**
	 * A dot is a legal character in a key, and someone will have used one.
	 */
	public function test_a_literal_key_containing_a_dot_wins_over_splitting(): void {
		$this->assertSame( '2.0', Arr::get( array( 'acme.version' => '2.0' ), 'acme.version' ) );
	}

	public function test_has_answers_for_a_key_holding_null(): void {
		$data = array( 'meta' => array( 'consent' => null ) );

		$this->assertTrue( Arr::has( $data, 'meta.consent' ), 'Present, though null.' );
		$this->assertNull( Arr::get( $data, 'meta.consent' ), 'Which arr_get() alone cannot distinguish.' );
		$this->assertFalse( Arr::has( $data, 'meta.missing' ) );
	}

	public function test_set_creates_the_steps_it_needs(): void {
		$data = array();

		Arr::set( $data, 'mail.from.name', 'Acme' );

		$this->assertSame( array( 'mail' => array( 'from' => array( 'name' => 'Acme' ) ) ), $data );
	}

	public function test_set_replaces_a_step_that_is_not_an_array(): void {
		$data = array( 'mail' => 'yes' );

		Arr::set( $data, 'mail.from', 'Acme' );

		$this->assertSame( array( 'mail' => array( 'from' => 'Acme' ) ), $data );
	}

	public function test_forget_removes_a_leaf_and_ignores_a_path_that_is_not_there(): void {
		$data = array( 'a' => array( 'b' => 1, 'c' => 2 ) );

		Arr::forget( $data, 'a.b' );
		Arr::forget( $data, 'x.y.z' );

		$this->assertSame( array( 'a' => array( 'c' => 2 ) ), $data );
	}

	public function test_only_and_except_are_opposites(): void {
		$data = array( 'name' => 'A', 'email' => 'b@c.d', 'role' => 'admin' );

		$this->assertSame( array( 'name' => 'A', 'email' => 'b@c.d' ), Arr::only( $data, array( 'name', 'email' ) ) );
		$this->assertSame( array( 'role' => 'admin' ), Arr::except( $data, array( 'name', 'email' ) ) );
	}

	public function test_pluck_reads_a_path_and_can_key_the_result(): void {
		$rows = array(
			array( 'id' => 7, 'billing' => array( 'email' => 'a@x.io' ) ),
			array( 'id' => 9, 'billing' => array( 'email' => 'b@x.io' ) ),
		);

		$this->assertSame( array( 'a@x.io', 'b@x.io' ), Arr::pluck( $rows, 'billing.email' ) );
		$this->assertSame( array( 7 => 'a@x.io', 9 => 'b@x.io' ), Arr::pluck( $rows, 'billing.email', 'id' ) );
	}

	public function test_first_takes_a_test_and_falls_back(): void {
		$data = array( 'a' => 1, 'b' => 4, 'c' => 9 );

		$this->assertSame( 1, Arr::first( $data ) );
		$this->assertSame( 4, Arr::first( $data, static fn( int $v ): bool => $v > 2 ) );
		$this->assertSame( 'none', Arr::first( $data, static fn( int $v ): bool => $v > 99, 'none' ) );
	}

	public function test_wrap_leaves_an_array_alone_and_treats_null_as_none(): void {
		$this->assertSame( array( 'post' ), Arr::wrap( 'post' ) );
		$this->assertSame( array( 'post', 'page' ), Arr::wrap( array( 'post', 'page' ) ) );
		$this->assertSame( array(), Arr::wrap( null ) );
		// False is a value, not an absence.
		$this->assertSame( array( false ), Arr::wrap( false ) );
	}

	public function test_flatten_descends_all_the_way_or_by_depth(): void {
		$data = array( 1, array( 2, array( 3, array( 4 ) ) ) );

		$this->assertSame( array( 1, 2, 3, 4 ), Arr::flatten( $data ) );
		$this->assertSame( array( 1, 2, array( 3, array( 4 ) ) ), Arr::flatten( $data, 1 ) );
	}

	public function test_is_assoc_tells_a_keyed_array_from_a_list(): void {
		$this->assertTrue( Arr::is_assoc( array( 'name' => 'A' ) ) );
		// WordPress's definition: a string key, not an out-of-order integer one.
		$this->assertFalse( Arr::is_assoc( array( 1 => 'a', 0 => 'b' ) ) );
		$this->assertFalse( Arr::is_assoc( array( 'a', 'b' ) ) );
		$this->assertFalse( Arr::is_assoc( array() ), 'An empty array is a list.' );
	}

	public function test_join_path_does_not_care_which_side_carried_the_slash(): void {
		$this->assertSame( 'lib/views/emails/receipt.php', Str::join_path( 'lib', 'views/', '/emails/receipt.php' ) );
		$this->assertSame( 'lib/views', Str::join_path( 'lib/', '', '/views/' ) );
	}

	public function test_join_path_keeps_a_leading_slash_but_adds_none(): void {
		$this->assertSame( '/var/www/lib', Str::join_path( '/var/www', 'lib' ) );
		$this->assertSame( 'var/www/lib', Str::join_path( 'var/www', 'lib' ) );
	}

	/**
	 * @dataProvider spellings
	 */
	public function test_a_name_is_spelled_for_where_it_is_going( string $given ): void {
		$this->assertSame( 'send-invoice', Str::kebab( $given ) );
		$this->assertSame( 'send_invoice', Str::snake( $given ) );
		$this->assertSame( 'SendInvoice', Str::pascal( $given ) );
		$this->assertSame( 'sendInvoice', Str::camel( $given ) );
		$this->assertSame( 'Send Invoice', Str::headline( $given ) );
	}

	/**
	 * Every spelling of one name has to arrive at the same place, or the
	 * conversions disagree with the names discovery derives from filenames.
	 *
	 * @return array<string, array<int, string>>
	 */
	public function spellings(): array {
		return array(
			'kebab'  => array( 'send-invoice' ),
			'snake'  => array( 'send_invoice' ),
			'pascal' => array( 'SendInvoice' ),
			'camel'  => array( 'sendInvoice' ),
			'spaced' => array( 'Send Invoice' ),
		);
	}

	public function test_an_acronym_is_split_the_way_wordpress_splits_it(): void {
		$this->assertSame( 'xml-http-request', Str::kebab( 'XMLHttpRequest' ) );
		$this->assertSame( 'XmlHttpRequest', Str::pascal( 'XMLHttpRequest' ) );
	}

	/**
	 * `Str::slug()` is the lossy one: it chooses a separator like the other five,
	 * then keeps only what a destination publishing `[a-z0-9-]` will accept --
	 * an npm package name, a block name, an ability name.
	 *
	 * @return void
	 */
	public function test_slug_reduces_to_the_ascii_character_set(): void {
		// Kebab-cased first, so a word boundary is found before anything is dropped.
		$this->assertSame( 'monthly-report', Str::slug( 'MonthlyReport' ) );
		$this->assertSame( 'monthly-report', Str::slug( 'Monthly Report' ) );
		$this->assertSame( 'create-order', Str::slug( 'create_order' ) );
		$this->assertSame( 'my-thing', Str::slug( 'my.thing' ) );

		// Then reduced, and runs of dashes collapse rather than doubling up.
		$this->assertSame( 'a-b', Str::slug( 'a--b' ) );
		$this->assertSame( 'cafe-menu', Str::slug( 'Café Menu' ) );
		$this->assertSame( '', Str::slug( '---' ) );
	}

	/**
	 * Where the two differ: `Str::kebab()` keeps a letter outside ASCII,
	 * because it is choosing a separator rather than a character set.
	 *
	 * @return void
	 */
	public function test_slug_is_lossy_where_str_kebab_case_is_not(): void {
		$this->assertSame( 'café', Str::kebab( 'Café' ) );
		$this->assertSame( 'cafe', Str::slug( 'Café' ), 'remove_accents() transliterates rather than dropping.' );
	}

	public function test_squish_collapses_every_run_of_whitespace(): void {
		$this->assertSame( 'one two three', Str::squish( "  one \n\t two    three  " ) );
	}

	public function test_limit_never_exceeds_the_length_asked_for(): void {
		$value = 'The quick brown fox jumps';

		$this->assertSame( $value, Str::limit( $value, 100 ) );

		$short = Str::limit( $value, 12 );

		$this->assertLessThanOrEqual( 12, mb_strlen( $short ), 'The suffix counts towards the limit.' );
		$this->assertSame( 'The quick…', $short );
	}
}
