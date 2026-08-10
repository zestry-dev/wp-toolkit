<?php

declare( strict_types=1 );

namespace Zestry\WPToolkit\Tests\Integration\Modules;

use Zestry\WPToolkit\Modules\Ajax\AjaxAction;
use Zestry\WPToolkit\Tests\Support\TestCase;

/**
 * Base overridable defaults of the abstract AjaxAction.
 *
 * A concrete subclass only has to implement the two abstract members
 * (`capability_check()` and `handle()`); the three overridable defaults it
 * inherits — `allow_not_privileged()`, `get_nonce_context()`, and
 * `is_nonce_required()` — supply the security-conservative base behavior and are
 * what this test pins.
 *
 * @covers \Zestry\WPToolkit\Modules\Ajax\AjaxAction
 */
final class AjaxActionDefaultsTest extends TestCase {

	/**
	 * A minimal concrete AjaxAction with no-op abstract members.
	 *
	 * @return AjaxAction
	 */
	private function make_action(): AjaxAction {
		return new class() extends AjaxAction {
			public function capability_check(): bool {
				return true;
			}

			public function handle(): void {
			}
		};
	}

	public function test_allow_not_privileged_defaults_to_false(): void {
		$this->assertFalse(
			$this->make_action()->allow_not_privileged(),
			'By default the nopriv path must stay protected.'
		);
	}

	public function test_get_nonce_context_defaults_to_null(): void {
		$this->assertNull(
			$this->make_action()->get_nonce_context(),
			'By default the nonce is scoped only to the action name.'
		);
	}

	public function test_is_nonce_required_defaults_to_true(): void {
		$this->assertTrue(
			$this->make_action()->is_nonce_required(),
			'By default the dispatcher must validate a nonce.'
		);
	}
}
