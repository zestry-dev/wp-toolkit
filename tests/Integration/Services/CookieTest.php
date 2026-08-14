<?php

declare( strict_types=1 );

namespace Zestry\WPToolkit\Tests\Integration\Services;

use Zestry\WPToolkit\Kernel\Plugin;
use Zestry\WPToolkit\Modules\Cookie;
use Zestry\WPToolkit\Tests\Support\TestCase;

/**
 * Cookie reading and writing, encryption, and the one-request flash.
 *
 * `setcookie()` cannot send a header from inside PHPUnit, which has already
 * written to stdout -- so the service says so through `_doing_it_wrong()`, and
 * every test that writes silences that trigger rather than asserting it. One test
 * lets it through, which is what keeps the guard honest.
 *
 * The round trips are real either way: `set()` puts the value into `$_COOKIE`
 * itself, exactly as it does in a request whose header did go out.
 *
 * @covers \Zestry\WPToolkit\Modules\Cookie
 */
final class CookieTest extends TestCase {

	public function tear_down(): void {
		foreach ( array_keys( $_COOKIE ) as $name ) {
			if ( str_starts_with( (string) $name, 'zestry-test_' ) || str_starts_with( (string) $name, 'other-plugin_' ) ) {
				unset( $_COOKIE[ $name ] );
			}
		}

		parent::tear_down();
	}

	private function cookies(): Cookie {
		return $this->plugin->get( Cookie::class );
	}

	/**
	 * The service, for a test that writes.
	 *
	 * Every write here trips the headers-sent guard, because PHPUnit has already
	 * written to stdout -- so the notice is declared rather than silenced, which
	 * also means a test that stops writing stops passing.
	 *
	 * @return Cookie
	 */
	private function writing_cookies(): Cookie {
		$this->setExpectedIncorrectUsage( 'Zestry\WPToolkit\Modules\Cookie::send' );

		return $this->plugin->get( Cookie::class );
	}

	/**
	 * The prefix is what keeps two plugins, and WordPress itself, out of each
	 * other's cookies -- and it is public because the name is needed verbatim in
	 * JavaScript and in a caching plugin's exclusion list.
	 *
	 * @return void
	 */
	public function test_a_cookie_name_carries_the_plugin_slug(): void {
		$this->assertSame( 'zestry-test_seen_tour', $this->cookies()->get_cookie_name( 'seen_tour' ) );
	}

	/**
	 * `setcookie()` does not populate `$_COOKIE`, so a value written and read in
	 * one request would otherwise be missing -- the classic half hour lost to this.
	 *
	 * @return void
	 */
	public function test_a_value_written_is_readable_in_the_same_request(): void {
		$cookies = $this->writing_cookies();

		$cookies->set( 'seen_tour', '1' );

		$this->assertSame( '1', $cookies->get( 'seen_tour' ) );
		$this->assertTrue( $cookies->has( 'seen_tour' ) );
	}

	/**
	 * @return void
	 */
	public function test_an_absent_cookie_returns_the_fallback(): void {
		$cookies = $this->cookies();

		$this->assertNull( $cookies->get( 'never_set' ) );
		$this->assertSame( 'default', $cookies->get( 'never_set', 'default' ) );
		$this->assertFalse( $cookies->has( 'never_set' ) );
	}

	/**
	 * @return void
	 */
	public function test_forgetting_a_cookie_removes_it(): void {
		$cookies = $this->writing_cookies();

		$cookies->set( 'seen_tour', '1' );
		$cookies->forget( 'seen_tour' );

		$this->assertFalse( $cookies->has( 'seen_tour' ) );
	}

	/**
	 * A structure survives the round trip as itself, which is what serializing
	 * buys over handing back a string the caller has to decode.
	 *
	 * @return void
	 */
	public function test_an_encrypted_value_round_trips_as_its_own_type(): void {
		$cookies = $this->writing_cookies();
		$value   = array(
			'items' => array( 12, 40 ),
			'note'  => "O'Brien",
			'deep'  => array( 'yes' => true ),
		);

		$cookies->set_encrypted( 'cart', $value );

		$this->assertSame( $value, $cookies->get_encrypted( 'cart' ) );
	}

	/**
	 * The point of encrypting rather than signing: what the browser holds does not
	 * contain what was stored.
	 *
	 * @return void
	 */
	public function test_the_stored_cookie_does_not_contain_the_plaintext(): void {
		$cookies = $this->writing_cookies();

		$cookies->set_encrypted( 'secretish', array( 'note' => 'sentinel-value-here' ) );

		$raw = (string) $cookies->get( 'secretish' );

		$this->assertNotSame( '', $raw );
		$this->assertStringNotContainsString( 'sentinel-value-here', $raw );
		$this->assertStringNotContainsString( 'note', $raw );
	}

	/**
	 * A cookie is a value the browser holds, so the only useful question about an
	 * edited one is whether it is detected -- not whether it can be repaired.
	 *
	 * @return void
	 */
	public function test_a_tampered_value_is_refused_and_reads_as_absent(): void {
		$cookies = $this->writing_cookies();

		$cookies->set_encrypted( 'cart', array( 'total' => 10 ) );

		$sealed = (string) $cookies->get( 'cart' );

		// Flip a byte in the middle, leaving the length and the encoding intact.
		$middle           = (int) ( strlen( $sealed ) / 2 );
		$sealed[ $middle ] = 'A' === $sealed[ $middle ] ? 'B' : 'A';

		$cookies->set( 'cart', $sealed );

		$this->assertSame( 'fallback', $cookies->get_encrypted( 'cart', 'fallback' ) );
	}

	/**
	 * Two plugins on one site derive different keys, so one cannot read the
	 * other's cookie even knowing its name.
	 *
	 * @return void
	 */
	public function test_another_plugins_cookie_does_not_decrypt(): void {
		$this->writing_cookies()->set_encrypted( 'shared_name', array( 'mine' => true ) );

		// A second plugin, same site, same salts, different slug.
		$other = ( new Plugin( $this->entry_file, 'other-plugin' ) )
			->declare_modules( $this->get_toolkit_modules() )
			->get( Cookie::class );

		// Hand it the first plugin's sealed value under its own name.
		$other->set( 'shared_name', (string) $this->cookies()->get( 'shared_name' ) );

		$this->assertNull( $other->get_encrypted( 'shared_name' ) );
	}

	/**
	 * The whole reason the service exists: one value survives the redirect, and
	 * the URL stays clean.
	 *
	 * @return void
	 */
	public function test_a_flashed_value_survives_and_is_read_once(): void {
		$cookies = $this->writing_cookies();

		$cookies->set_flash( array( 'saved' => 'Settings saved.' ) );

		$this->assertSame( array( 'saved' => 'Settings saved.' ), $cookies->get_flash() );
		$this->assertNull( $cookies->get_flash(), 'A refresh shows no notice for a save that already happened.' );
	}

	/**
	 * @return void
	 */
	public function test_reading_a_flash_that_was_never_set_gives_the_fallback(): void {
		// No cookie means nothing to expire, so this sends no header at all -- which
		// is why no incorrect-usage notice is declared here.
		$this->assertSame( array(), $this->cookies()->get_flash( array() ) );
		$this->assertFalse( $this->cookies()->has( Cookie::FLASH_COOKIE ) );
	}

	/**
	 * A payload past what a browser will hold moves into a transient, with an
	 * unguessable id in the cookie -- so the size limit stops being the caller's
	 * problem, and the common small flash still costs no database write.
	 *
	 * @return void
	 */
	public function test_a_flash_too_large_for_a_cookie_falls_back_to_a_transient(): void {
		$cookies = $this->writing_cookies();
		$value   = array( 'skipped' => array_fill( 0, 400, 'a-fairly-long-row-identifier' ) );

		// Not asserting the return value: every write reports false here, because
		// the header cannot go out from inside PHPUnit. What it did is what matters.
		$cookies->set_flash( $value );

		$carried = (string) $cookies->get( Cookie::FLASH_COOKIE );

		$this->assertLessThan(
			Cookie::MAX_COOKIE_BYTES,
			strlen( $carried ),
			'The cookie carries an id, not the payload.'
		);
		$this->assertStringStartsWith( 't', $carried );

		$this->assertSame( $value, $cookies->get_flash(), 'And it comes back whole.' );
		$this->assertNull( $cookies->get_flash(), 'Still read once.' );
	}

	/**
	 * The small case is the one that must not touch the database, so the cookie has
	 * to be carrying the payload itself.
	 *
	 * @return void
	 */
	public function test_a_small_flash_travels_in_the_cookie_itself(): void {
		$cookies = $this->writing_cookies();

		$cookies->set_flash( 'Settings saved.' );

		$this->assertStringStartsWith( 'v', (string) $cookies->get( Cookie::FLASH_COOKIE ) );
		$this->assertSame( 'Settings saved.', $cookies->get_flash() );
	}

	/**
	 * A browser drops an oversized cookie without saying anything, so the service
	 * says it instead -- the failure is otherwise a notice that never appears.
	 *
	 * @return void
	 */
	public function test_an_oversized_value_is_refused_out_loud(): void {
		$this->setExpectedIncorrectUsage( 'Zestry\WPToolkit\Modules\Cookie::set_encrypted' );

		$cookies = $this->cookies();

		$this->assertFalse( $cookies->set_encrypted( 'huge', str_repeat( 'x', Cookie::MAX_COOKIE_BYTES ) ) );
		$this->assertFalse( $cookies->has( 'huge' ), 'Nothing was written.' );
	}

	/**
	 * And the mistake with no other symptom: a cookie written after output has
	 * begun never reaches the browser, and PHP says nothing a developer will see.
	 *
	 * @return void
	 */
	public function test_writing_after_output_has_started_says_so(): void {
		$this->setExpectedIncorrectUsage( 'Zestry\WPToolkit\Modules\Cookie::send' );

		// PHPUnit has written to stdout already, so headers are sent for real here.
		$this->assertFalse( $this->cookies()->set( 'late', '1' ) );
	}
}
