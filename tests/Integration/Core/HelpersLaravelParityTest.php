<?php

declare( strict_types=1 );

namespace Zestry\WPToolkit\Tests\Integration\Core;

use Zestry\WPToolkit\Kernel\Helpers\Arr;
use Zestry\WPToolkit\Kernel\Helpers\Str;
use Zestry\WPToolkit\Tests\Support\TestCase;

/**
 * The same inputs Laravel's own `Str` and `Arr` suites use.
 *
 * These helpers are named to be the ones a consumer would guess, and someone
 * arriving from Laravel will guess `Str::snake` and `Arr::pluck`. Answering
 * differently from what that name means elsewhere is a trap, so the cases from
 * `SupportStrTest` and `SupportArrTest` are run here against ours.
 *
 * Where the answer differs it is deliberate, and each divergence is asserted
 * rather than skipped: a test that quietly omits the awkward case is how the
 * difference stops being deliberate.
 *
 * @covers \Zestry\WPToolkit\Kernel\Helpers\Arr
 * @covers \Zestry\WPToolkit\Kernel\Helpers\Str
 */
final class HelpersLaravelParityTest extends TestCase {

	/**
	 * @dataProvider laravel_kebab_cases
	 */
	public function test_kebab_matches_laravel( string $given, string $expected ): void {
		$this->assertSame( $expected, Str::kebab( $given ) );
	}

	/**
	 * @return array<string, array<int, string>>
	 */
	public function laravel_kebab_cases(): array {
		return array(
			'studly'  => array( 'LaravelPhpFramework', 'laravel-php-framework' ),
			'spaced'  => array( 'Laravel Php Framework', 'laravel-php-framework' ),
			'empty'   => array( '', '' ),
		);
	}

	/**
	 * @dataProvider laravel_camel_and_studly_cases
	 */
	public function test_camel_and_pascal_match_laravel( string $given, string $camel, string $pascal ): void {
		$this->assertSame( $camel, Str::camel( $given ) );
		$this->assertSame( $pascal, Str::pascal( $given ) );
	}

	/**
	 * `Laravel_p_h_p_framework` -> `laravelPHPFramework` is Laravel's own
	 * expectation, and ours agrees: single letters rejoin into the acronym.
	 *
	 * @return array<string, array<int, string>>
	 */
	public function laravel_camel_and_studly_cases(): array {
		return array(
			'spelled acronym' => array( 'Laravel_p_h_p_framework', 'laravelPHPFramework', 'LaravelPHPFramework' ),
			'snake'           => array( 'Laravel_php_framework', 'laravelPhpFramework', 'LaravelPhpFramework' ),
			'already studly'  => array( 'FooBar', 'fooBar', 'FooBar' ),
			'snake short'     => array( 'foo_bar', 'fooBar', 'FooBar' ),
			'empty'           => array( '', '', '' ),
		);
	}

	/**
	 * @dataProvider laravel_squish_cases
	 */
	public function test_squish_matches_laravel( string $given, string $expected ): void {
		$this->assertSame( $expected, Str::squish( $given ) );
	}

	/**
	 * @return array<string, array<int, string>>
	 */
	public function laravel_squish_cases(): array {
		return array(
			'spaces' => array( ' laravel   php  framework ', 'laravel php framework' ),
			'tabs'   => array( "laravel\t\tphp\n\nframework", 'laravel php framework' ),
			'digits' => array( '   123    ', '123' ),
		);
	}

	/**
	 * An acronym stays whole, where Laravel spells it out letter by letter.
	 *
	 * `Str::snake( 'LaravelPHPFramework' )` is `laravel_p_h_p_framework` there.
	 * Ours has to agree with the names discovery derives from filenames, and
	 * those come from WordPress's kebab-caser, which reads `PHP` as one word.
	 */
	public function test_an_acronym_stays_whole_unlike_laravel(): void {
		$this->assertSame( 'laravel_php_framework', Str::snake( 'LaravelPHPFramework' ) );
		$this->assertSame( 'laravel-php-framework', Str::kebab( 'LaravelPHPFramework' ) );
	}

	/**
	 * Title case turns separators into spaces, where Laravel's leaves them.
	 *
	 * `Str::title( 'send-invoice' )` is `Send-invoice` there, which is not a
	 * label. The cost is that prose with irregular capitalisation comes apart on
	 * the same rule that keeps `XMLHttpRequest` readable, so this takes an
	 * identifier and the docblock says so.
	 */
	public function test_title_labels_an_identifier_rather_than_casing_a_sentence(): void {
		$this->assertSame( 'Send Invoice', Str::headline( 'send-invoice' ) );
		$this->assertSame( 'Jefferson Costella', Str::headline( 'jefferson costella' ) );
		$this->assertSame( '123 Laravel', Str::headline( '123 laravel' ) );

		// The documented limit, asserted so it cannot drift into a surprise.
		$this->assertSame( 'Jef F Erson Co S Tella', Str::headline( 'jefFErson coSTella' ) );
	}

	/**
	 * The suffix counts towards the limit, and a word is never cut in half.
	 *
	 * Laravel appends after the limit and cuts mid-word unless asked not to, so
	 * `Str::limit( 'The PHP framework...', 7 )` is `The PHP...` -- ten characters
	 * from a limit of seven. A length passed here is a length returned.
	 */
	public function test_limit_treats_the_limit_as_a_ceiling_unlike_laravel(): void {
		$this->assertSame( 'The…', Str::limit( 'The PHP framework...', 7 ) );
		$this->assertLessThanOrEqual( 7, mb_strlen( Str::limit( 'The PHP framework...', 7 ) ) );

		// Short enough already: returned untouched, as Laravel does.
		$this->assertSame( 'Laravel is', Str::limit( 'Laravel is', 10 ) );
	}

	/**
	 * @dataProvider laravel_arr_get_cases
	 */
	public function test_arr_get_matches_laravel( array $data, string $path, mixed $expected ): void {
		$this->assertSame( $expected, Arr::get( $data, $path ) );
	}

	/**
	 * @return array<string, array<int, mixed>>
	 */
	public function laravel_arr_get_cases(): array {
		$products = array( 'products' => array( 'desk' => array( 'price' => 100 ) ) );

		return array(
			'nested'          => array( $products, 'products.desk.price', 100 ),
			'partial path'    => array( $products, 'products.desk', array( 'price' => 100 ) ),
			'missing leaf'    => array( $products, 'products.desk.colour', null ),
			'missing branch'  => array( $products, 'products.chair.price', null ),
			'through scalar'  => array( $products, 'products.desk.price.colour', null ),
		);
	}

	/**
	 * Laravel's `Arr::has` takes several keys and answers only if all resolve.
	 * Ours takes one path, so the same question is a call each -- which is the
	 * difference worth knowing rather than a behaviour that disagrees.
	 */
	public function test_arr_has_answers_per_path(): void {
		$data = array( 'products' => array( 'desk' => array( 'price' => 100 ) ) );

		$this->assertTrue( Arr::has( $data, 'products.desk' ) );
		$this->assertTrue( Arr::has( $data, 'products.desk.price' ) );
		$this->assertFalse( Arr::has( $data, 'products.foo' ) );
		$this->assertFalse( Arr::has( $data, 'products.desk.price.foo' ) );
	}

	public function test_arr_pluck_matches_laravel(): void {
		$rows = array(
			array( 'developer' => array( 'name' => 'Taylor' ) ),
			array( 'developer' => array( 'name' => 'Abigail' ) ),
		);

		$this->assertSame( array( 'Taylor', 'Abigail' ), Arr::pluck( $rows, 'developer.name' ) );
	}

	public function test_arr_flatten_matches_laravel(): void {
		// Laravel: Arr::flatten( ['#foo', '#bar', '#baz'] ) and the nested forms.
		$this->assertSame( array( '#foo', '#bar', '#baz' ), Arr::flatten( array( '#foo', '#bar', '#baz' ) ) );
		$this->assertSame(
			array( '#foo', '#bar', '#baz' ),
			Arr::flatten( array( array( '#foo', '#bar' ), array( '#baz' ) ) )
		);
		$this->assertSame(
			array( '#foo', array( '#bar' ), '#baz' ),
			Arr::flatten( array( '#foo', array( array( '#bar' ) ), '#baz' ), 1 )
		);
	}

	public function test_arr_only_and_except_match_laravel(): void {
		$data = array( 'name' => 'Desk', 'price' => 100, 'orders' => 10 );

		$this->assertSame( array( 'name' => 'Desk', 'price' => 100 ), Arr::only( $data, array( 'name', 'price' ) ) );
		$this->assertSame( array( 'orders' => 10 ), Arr::except( $data, array( 'name', 'price' ) ) );
	}

	public function test_arr_first_matches_laravel(): void {
		$data = array( 100, 200, 300 );

		$this->assertSame( 200, Arr::first( $data, static fn( int $value ): bool => $value >= 150 ) );
		$this->assertSame( 100, Arr::first( $data ) );
		$this->assertSame( 'none', Arr::first( array(), null, 'none' ) );
	}

	public function test_arr_wrap_matches_laravel(): void {
		$this->assertSame( array( 'Taylor' ), Arr::wrap( 'Taylor' ) );
		$this->assertSame( array( 'Taylor' ), Arr::wrap( array( 'Taylor' ) ) );
		$this->assertSame( array(), Arr::wrap( null ) );
	}
}
