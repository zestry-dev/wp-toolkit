<?php

declare( strict_types=1 );

namespace Zestry\WPToolkit\Tests\Integration\Modules;

use Zestry\WPToolkit\Modules\Ajax\Ajax;
use Zestry\WPToolkit\Tests\Support\TestCase;

/**
 * AJAX action naming and the real nonce create/verify round-trip.
 *
 * @covers \Zestry\WPToolkit\Modules\Ajax\Ajax
 */
final class AjaxNonceTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		// Ensure the default actions directory exists: another test may have defined
		// the DOING_AJAX constant (process-wide, un-undefinable), which makes the
		// module attempt action discovery on boot.
		mkdir( $this->plugin_dir . '/resources/actions', 0777, true );
	}

	private function ajax(): Ajax {
		return $this->plugin->get( Ajax::class );
	}

	public function test_action_slug_is_namespaced_by_the_plugin_slug(): void {
		// Underscored, not `zestry-test-save-profile`: files are named with hyphens
		// and a WordPress hook is not, so the separator suits the destination.
		$this->assertSame( 'zestry-test-save-profile', $this->ajax()->get_action_slug( 'save-profile' ) );
	}

	public function test_nonce_round_trips_for_the_same_action(): void {
		$ajax = $this->ajax();

		$nonce                  = $ajax->create_action_nonce( 'delete-item' );
		$_REQUEST['_wpnonce']   = $nonce;

		$this->assertNotFalse(
			$ajax->verify_action_nonce( 'delete-item' ),
			'A freshly minted nonce must verify for its action.'
		);

		unset( $_REQUEST['_wpnonce'] );
	}

	public function test_nonce_for_one_action_does_not_verify_for_another(): void {
		$ajax = $this->ajax();

		$_REQUEST['_wpnonce'] = $ajax->create_action_nonce( 'action-a' );

		$this->assertFalse(
			$ajax->verify_action_nonce( 'action-b' ),
			'A nonce is scoped to its action.'
		);

		unset( $_REQUEST['_wpnonce'] );
	}

	public function test_context_scopes_the_nonce(): void {
		$ajax = $this->ajax();

		$_REQUEST['_wpnonce'] = $ajax->create_action_nonce( 'edit', 'post-1' );

		$this->assertNotFalse(
			$ajax->verify_action_nonce( 'edit', 'post-1' ),
			'A context-scoped nonce verifies for the same context.'
		);
		$this->assertFalse(
			$ajax->verify_action_nonce( 'edit', 'post-2' ),
			'It must not verify for a different context.'
		);

		unset( $_REQUEST['_wpnonce'] );
	}

	public function test_action_url_carries_the_action_and_a_valid_nonce(): void {
		$ajax = $this->ajax();

		$url = $ajax->get_action_url( 'ping', array( 'foo' => 'bar' ) );

		$this->assertStringContainsString( 'action=zestry-test-ping', $url );
		$this->assertStringContainsString( 'foo=bar', $url );

		// The embedded nonce must actually verify.
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );
		$_REQUEST['_wpnonce'] = $query['_wpnonce'];
		$this->assertNotFalse( $ajax->verify_action_nonce( 'ping' ) );
		unset( $_REQUEST['_wpnonce'] );
	}

	public function test_action_url_scopes_the_nonce_by_a_context_key(): void {
		$ajax = $this->ajax();

		$url = $ajax->get_action_url( 'edit', array( 'key' => 'post-9' ), 'key' );

		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );
		$_REQUEST['_wpnonce'] = $query['_wpnonce'];

		$this->assertNotFalse( $ajax->verify_action_nonce( 'edit', 'post-9' ) );
		$this->assertFalse( $ajax->verify_action_nonce( 'edit', 'other' ) );

		unset( $_REQUEST['_wpnonce'] );
	}

	/**
	 * A context of `0` scopes the nonce like any other value.
	 *
	 * Both guards tested `if ( $context )`, so a resource identifier of zero was
	 * silently treated as no context. Minting and verifying agreed, so nothing
	 * broke visibly -- an action returning `0` just got an unscoped nonce while
	 * believing it was scoped to that resource.
	 *
	 * @dataProvider falsy_contexts_that_are_still_contexts
	 */
	public function test_a_falsy_context_still_scopes_the_nonce( string|int $context ): void {
		$ajax = $this->ajax();

		$_REQUEST['_wpnonce'] = $ajax->create_action_nonce( 'edit', $context );

		$this->assertNotFalse(
			$ajax->verify_action_nonce( 'edit', $context ),
			'A falsy-but-present context verifies against itself.'
		);
		$this->assertFalse(
			$ajax->verify_action_nonce( 'edit' ),
			'It must not verify as though no context had been given.'
		);

		unset( $_REQUEST['_wpnonce'] );
	}

	/**
	 * @return array<string, array<int, string|int>>
	 */
	public function falsy_contexts_that_are_still_contexts(): array {
		return array(
			'int zero'    => array( 0 ),
			'string zero' => array( '0' ),
		);
	}

	public function test_an_empty_context_means_no_context(): void {
		$ajax = $this->ajax();

		$_REQUEST['_wpnonce'] = $ajax->create_action_nonce( 'edit', '' );

		$this->assertNotFalse(
			$ajax->verify_action_nonce( 'edit' ),
			'An empty string is the one falsy value that still means "unscoped".'
		);

		unset( $_REQUEST['_wpnonce'] );
	}

	/**
	 * A context key naming a non-scalar argument is reported by name.
	 *
	 * The value went straight into a `string|int|null` parameter, so an array
	 * raised a TypeError from inside the toolkit naming neither the key nor the
	 * call that passed it.
	 */
	public function test_a_non_scalar_context_argument_names_the_key(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'ids' );

		$this->ajax()->get_action_url( 'edit', array( 'ids' => array( 1, 2 ) ), 'ids' );
	}
}
