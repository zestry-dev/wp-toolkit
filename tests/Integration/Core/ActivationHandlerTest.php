<?php

declare( strict_types=1 );

namespace Zestry\WPToolkit\Tests\Integration\Core;

use Zestry\WPToolkit\Kernel\Abstracts\ActivationHandler;
use Zestry\WPToolkit\Tests\Support\TestCase;

/**
 * ActivationHandler lifecycle hook registration and the too-late guard (review #4).
 *
 * @covers \Zestry\WPToolkit\Kernel\Abstracts\ActivationHandler
 */
final class ActivationHandlerTest extends TestCase {

	public function set_up(): void {
		parent::set_up();

		// Nothing is built without being declared, and this fixture is a
		// module like any other.
		$this->plugin->declare_modules( array( SpyActivation::class ) );
	}

	public function test_registers_activation_and_deactivation_hooks_at_load_time(): void {
		$this->plugin->get( SpyActivation::class );

		$basename = plugin_basename( $this->entry_file );

		// The callbacks are registered as first-class callables ($this->activate(...)),
		// which are distinct Closures, so assert a callback exists rather than matching it.
		$this->assertNotFalse(
			has_action( 'activate_' . $basename ),
			'A callback is bound to the activation hook.'
		);
		$this->assertNotFalse(
			has_action( 'deactivate_' . $basename ),
			'A callback is bound to the deactivation hook.'
		);
	}

	public function test_activation_callback_actually_runs_when_the_hook_fires(): void {
		$module = $this->plugin->get( SpyActivation::class );

		$this->assertFalse( $module->activated );
		do_action( 'activate_' . plugin_basename( $this->entry_file ) );
		$this->assertTrue( $module->activated, 'Firing the hook invokes activate().' );
	}

	public function test_booting_after_the_activation_hook_warns_and_does_not_bind(): void {
		$basename = plugin_basename( $this->entry_file );

		// Simulate the activation hook having already fired this request.
		do_action( 'activate_' . $basename );

		// The module warns via _doing_it_wrong() instead of registering a dead hook.
		$this->setExpectedIncorrectUsage( 'Zestry\WPToolkit\Kernel\Abstracts\ActivationHandler::on_boot' );

		$this->plugin->get( SpyActivation::class );

		$this->assertFalse(
			has_action( 'activate_' . $basename ),
			'No activation hook is bound after the window has passed.'
		);
		$this->assertNotFalse(
			has_action( 'deactivate_' . $basename ),
			'Deactivation is still registered because that hook fires on a later request.'
		);
	}

	/**
	 * WordPress fires the activation hook once, on whichever site the network
	 * admin happened to be on. Calling activate() directly would therefore set
	 * up exactly one site and leave every other one without the plugin's tables,
	 * which is the failure this loop exists to prevent.
	 *
	 * @group ms-required
	 * @return void
	 */
	public function test_a_network_activation_runs_for_every_site(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Requires a multisite install.' );
		}

		$spy   = $this->plugin->get( SpyActivation::class );
		$other = (int) self::factory()->blog->create();

		$spy->activated_sites = array();
		$spy->run_activation( true );

		$this->assertContains( get_current_blog_id(), $spy->activated_sites );
		$this->assertContains( $other, $spy->activated_sites );
	}

	/**
	 * A single-site activation must not switch blogs or loop anything.
	 */
	public function test_a_single_site_activation_runs_once(): void {
		$spy = $this->plugin->get( SpyActivation::class );

		$spy->activated_sites = array();
		$spy->run_activation( false );

		$this->assertSame( array( get_current_blog_id() ), $spy->activated_sites );
		$this->assertFalse( $spy->saw_network_wide );
	}

	/**
	 * The flag is context a consumer may want -- "seed this once, not per site"
	 * -- so it has to reach activate() rather than being swallowed.
	 */
	public function test_activate_is_told_whether_the_activation_was_network_wide(): void {
		$spy = $this->plugin->get( SpyActivation::class );

		$spy->run_activation( false );
		$this->assertFalse( $spy->saw_network_wide );

		if ( is_multisite() ) {
			$spy->run_activation( true );
			$this->assertTrue( $spy->saw_network_wide );
		}
	}

	/**
	 * Looping tens of thousands of sites times out part-way and leaves the
	 * network half-configured, which is worse than not starting. Those get
	 * activate_site() instead, on demand.
	 *
	 * @return void
	 */
	public function test_a_large_network_is_not_looped(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Requires a multisite install.' );
		}

		add_filter( 'wp_is_large_network', '__return_true' );

		$spy                  = $this->plugin->get( SpyActivation::class );
		$spy->activated_sites = array();

		$spy->run_activation( true );

		remove_filter( 'wp_is_large_network', '__return_true' );

		$this->assertSame( array(), $spy->activated_sites );
	}

}

/**
 * A concrete ActivationHandler that records whether its callbacks ran.
 */
final class SpyActivation extends ActivationHandler {

	public bool $activated   = false;
	public bool $deactivated = false;

	/**
	 * The site IDs activate() ran under, in order.
	 *
	 * @var int[]
	 */
	public array $activated_sites = array();

	/**
	 * Whether activate() was told this was a network activation.
	 *
	 * @var bool|null
	 */
	public ?bool $saw_network_wide = null;

	public function activate( bool $network_wide ): void {
		$this->activated         = true;
		$this->saw_network_wide  = $network_wide;
		$this->activated_sites[] = get_current_blog_id();
	}

	public function deactivate( bool $network_wide ): void { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
		$this->deactivated = true;
	}

	/**
	 * Widened so a test can drive the network branch directly.
	 *
	 * WordPress calls this through the activation hook, which a test cannot fire
	 * with a chosen $network_wide.
	 *
	 * @param bool $network_wide Whether the plugin was activated network-wide.
	 * @return void
	 */
	public function run_activation( bool $network_wide = false ): void {
		parent::run_activation( $network_wide );
	}
}
