<?php

declare( strict_types=1 );

namespace Zestry\WPToolkit\Tests\Integration\DevTools;

use Zestry\WPToolkit\Tests\Support\TestCase;

/**
 * Security-relevant defaults baked into the generated stubs.
 *
 * Asserted against the stub files directly rather than through MakeCommand:
 * the property under test is the text every generated file starts life with,
 * and reading the stubs covers all three authorization surfaces uniformly
 * without the make-command plumbing in between.
 *
 * @coversNothing
 */
final class StubDefaultsTest extends TestCase {

	/**
	 * Every stub that makes an authorization decision must deny by default.
	 *
	 * A generated file lints and runs as-is, so a permissive default ships a
	 * finished-looking endpoint that anyone can call. The route stub used to
	 * `return true;`, which meant `wp zestry make route delete-widget
	 * --method=delete` produced a publicly callable DELETE route.
	 */
	public function test_authorization_stubs_deny_by_default(): void {
		foreach ( array( 'action.php.stub', 'page.php.stub', 'route.php.stub' ) as $stub ) {
			$contents = (string) file_get_contents( $this->stub_path( $stub ) );

			$this->assertStringContainsString(
				'manage_options',
				$contents,
				$stub . ' must default to a real capability, not a permissive placeholder.'
			);
		}
	}

	/**
	 * The route stub specifically must not hand back an open endpoint.
	 *
	 * Narrower than the check above: `return true;` inside permission_check()
	 * is the exact shape that regressed, and a stub could contain the string
	 * 'manage_options' in a comment while still returning true.
	 */
	public function test_route_stub_permission_check_is_not_return_true(): void {
		$contents = (string) file_get_contents( $this->stub_path( 'route.php.stub' ) );

		// The return type is `bool|\WP_Error`, matching the abstract it overrides
		// -- the stub used to narrow it to `bool` while its own comment told the
		// author to return a WP_Error, which fataled under strict_types.
		$this->assertMatchesRegularExpression(
			'/public function permission_check\([^)]*\)\s*:\s*bool\|\\\\WP_Error\s*\{\s*return \\\\?current_user_can\(/',
			$contents,
			'route.php.stub must generate a capability check, not `return true;`.'
		);
	}

	private function stub_path( string $stub ): string {
		return dirname( __DIR__, 3 ) . '/src/DevTools/stubs/' . $stub;
	}
}
