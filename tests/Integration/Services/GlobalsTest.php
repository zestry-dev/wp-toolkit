<?php

declare( strict_types=1 );

namespace Zestry\WPToolkit\Tests\Integration\Services;

use Zestry\WPToolkit\Modules\Globals;
use Zestry\WPToolkit\Tests\Support\TestCase;

/**
 * In-memory global registry: set()/get()/has() semantics.
 *
 * @covers \Zestry\WPToolkit\Modules\Globals
 */
final class GlobalsTest extends TestCase {

	private function globals(): Globals {
		return $this->plugin->get( Globals::class );
	}

	/**
	 * set() returns void, matching every other module setter.
	 *
	 * It previously returned $this, so chaining worked here but was a fatal on
	 * the sibling Options store, whose set() has always returned void.
	 */
	public function test_set_stores_each_value_and_returns_void(): void {
		$globals = $this->globals();

		$this->assertNull( $globals->set( 'a', 1 ) );

		$globals->set( 'x', 'one' );
		$globals->set( 'y', 'two' );
		$this->assertSame( 'one', $globals->get( 'x' ) );
		$this->assertSame( 'two', $globals->get( 'y' ) );
	}

	public function test_get_returns_a_stored_value(): void {
		$globals = $this->globals();
		$globals->set( 'colour', 'blue' );

		$this->assertSame( 'blue', $globals->get( 'colour' ) );
	}

	public function test_get_returns_fallback_for_a_missing_key(): void {
		$globals = $this->globals();

		// Default fallback is null.
		$this->assertNull( $globals->get( 'never_set' ) );

		// Explicit fallback is returned for a missing key.
		$this->assertSame( 'fallback', $globals->get( 'never_set', 'fallback' ) );
	}

	public function test_get_returns_a_stored_null_not_the_fallback(): void {
		$globals = $this->globals();
		$globals->set( 'explicit_null', null );

		// has()-based branch: an explicitly stored null wins over the fallback.
		$this->assertNull( $globals->get( 'explicit_null', 'fallback' ) );
		$this->assertTrue( $globals->has( 'explicit_null' ) );
	}

	public function test_has_true_and_false(): void {
		$globals = $this->globals();

		$this->assertFalse( $globals->has( 'absent' ) );

		$globals->set( 'present', 'here' );
		$this->assertTrue( $globals->has( 'present' ) );

		// Storing null still counts as present.
		$globals->set( 'present', null );
		$this->assertTrue( $globals->has( 'present' ) );
	}
}