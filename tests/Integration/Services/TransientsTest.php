<?php

declare( strict_types=1 );

namespace Zestry\WPToolkit\Tests\Integration\Services;

use Zestry\WPToolkit\Services\Transients;
use Zestry\WPToolkit\Tests\Support\TestCase;

/**
 * Namespacing, the get-or-compute path, and the two limits worth failing on.
 *
 * @covers \Zestry\WPToolkit\Services\Transients
 */
final class TransientsTest extends TestCase {

	private Transients $transients;

	public function set_up(): void {
		parent::set_up();

		$this->transients = $this->plugin->get( Transients::class );
	}

	public function test_a_value_round_trips(): void {
		$this->transients->set( 'rates', array( 'usd' => 1.1 ) );

		$this->assertSame( array( 'usd' => 1.1 ), $this->transients->get( 'rates' ) );
	}

	/**
	 * Every plugin's transients share one namespace, so the prefix is what stops
	 * two plugins' `config` from being the same entry.
	 */
	public function test_keys_are_stored_under_the_plugin_prefix(): void {
		$this->transients->set( 'config', 'mine' );

		$this->assertSame( array( 'v' => 'mine' ), get_transient( 'zestry-test-config' ) );
		$this->assertFalse( get_transient( 'config' ), 'The unprefixed key is untouched.' );
	}

	public function test_a_missing_value_returns_the_fallback(): void {
		$this->assertNull( $this->transients->get( 'absent' ) );
		$this->assertSame( array(), $this->transients->get( 'absent', array() ) );
	}

	public function test_delete_removes_a_value(): void {
		$this->transients->set( 'rates', 'x' );

		$this->transients->delete( 'rates' );

		$this->assertFalse( $this->transients->has( 'rates' ) );
		$this->assertNull( $this->transients->get( 'rates' ) );
	}

	/**
	 * `false` and `null` are the values WordPress cannot store in a transient
	 * distinguishably -- and they fail differently per backend, so a plugin
	 * developed locally and deployed behind Redis would behave two ways. Wrapping
	 * makes them ordinary values, which is what Options already manages.
	 *
	 * @dataProvider provide_indistinguishable_values
	 * @param mixed $value A value WordPress would otherwise confuse with a miss.
	 * @return void
	 */
	public function test_a_value_wordpress_confuses_with_a_miss_round_trips( mixed $value ): void {
		$this->transients->set( 'flag', $value );

		$this->assertSame( $value, $this->transients->get( 'flag', 'MISSING' ) );
		$this->assertTrue( $this->transients->has( 'flag' ) );
	}

	/**
	 * @return array<string, array{mixed}>
	 */
	public function provide_indistinguishable_values(): array {
		return array(
			'false' => array( false ),
			'null'  => array( null ),
		);
	}

	public function test_has_is_false_for_a_key_never_set(): void {
		$this->assertFalse( $this->transients->has( 'absent' ) );
	}

	/**
	 * Every falsy value stores and reads back as itself.
	 *
	 * @dataProvider provide_falsy_values
	 * @param mixed $value A falsy value.
	 * @return void
	 */
	public function test_a_falsy_value_round_trips_as_itself( mixed $value ): void {
		$this->transients->set( 'empty', $value );

		$this->assertSame( $value, $this->transients->get( 'empty', 'MISSING' ) );
	}

	/**
	 * @return array<string, array{mixed}>
	 */
	public function provide_falsy_values(): array {
		return array(
			'zero'        => array( 0 ),
			'empty array' => array( array() ),
			'empty string' => array( '' ),
		);
	}

	/**
	 * The database truncates an over-long option name, which would quietly make
	 * two different keys into one entry returning each other's values.
	 */
	public function test_an_over_long_key_throws_rather_than_being_truncated(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'characters once prefixed' );

		$this->transients->set( str_repeat( 'a', Transients::MAX_KEY_LENGTH ), 'x' );
	}

	public function test_an_empty_key_throws(): void {
		$this->expectException( \InvalidArgumentException::class );

		$this->transients->get( '   ' );
	}

	/**
	 * The limit is the real one, not a round number: a key landing exactly on it
	 * has to work, or the message sends people shortening keys that were fine.
	 */
	public function test_a_key_at_exactly_the_limit_is_accepted(): void {
		$prefix_length = strlen( 'zestry-test_' );

		$key = str_repeat( 'a', Transients::MAX_KEY_LENGTH - $prefix_length );

		$this->transients->set( $key, 'x' );

		$this->assertSame( 'x', $this->transients->get( $key ) );
	}
}
