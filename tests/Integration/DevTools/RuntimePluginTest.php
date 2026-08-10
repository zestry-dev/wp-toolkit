<?php

declare( strict_types=1 );

namespace Zestry\WPToolkit\Tests\Integration\DevTools;

use Zestry\WPToolkit\DevTools\RuntimePlugin;
use Zestry\WPToolkit\Kernel\Plugin;
use Zestry\WPToolkit\Tests\Support\TestCase;

/**
 * A running plugin publishing itself where `wp zestry` can find it.
 *
 * Every other devtool reader answers from files, which describe what a plugin
 * is declared to be. This is the one channel for what it became -- a slug the
 * entry file passed, a root an initializer moved -- and it exists because by
 * the time a devtool command runs, WordPress has already built the instance
 * holding those answers.
 *
 * @covers \Zestry\WPToolkit\DevTools\RuntimePlugin
 */
final class RuntimePluginTest extends TestCase {

	private RuntimePlugin $runtime;

	public function set_up(): void {
		parent::set_up();

		$this->runtime = $this->plugin->get( RuntimePlugin::class );

		unset( $GLOBALS[ RuntimePlugin::REGISTRY ] );
	}

	public function tear_down(): void {
		unset( $GLOBALS[ RuntimePlugin::REGISTRY ] );
		parent::tear_down();
	}

	/**
	 * A web request must carry no trace of this. The constant is defined by the
	 * autoload shim only after it has established a `wp` run from inside a
	 * plugin that requires this package.
	 */
	public function test_a_plugin_publishes_nothing_without_the_devtool_constant(): void {
		$this->assertFalse( defined( 'ZESTRY_DEVTOOL' ), 'Guard assumption: the suite runs without it.' );

		( new Plugin( $this->plugin_dir . '/plugin.php', 'acme' ) )->run();

		$this->assertArrayNotHasKey( RuntimePlugin::REGISTRY, $GLOBALS );
	}

	/**
	 * The other half, and the half that cannot be asserted in this process:
	 * `ZESTRY_DEVTOOL` is permanent once defined, so defining it here would leak
	 * into every later test. A subprocess gets a clean one, which is also the
	 * honest shape of the thing under test -- the constant is defined during
	 * autoload, before the plugin runs, exactly as it is in a real `wp` run.
	 */
	public function test_a_plugin_publishes_itself_when_the_constant_is_defined(): void {
		$script = sprintf(
			'define( "ABSPATH", "/" ); define( "ZESTRY_DEVTOOL", true );'
				. ' require %1$s; $p = new \Zestry\WPToolkit\Kernel\Plugin( %2$s, "acme" ); $p->run();'
				. ' echo isset( $GLOBALS["zestry_runtime_plugins"][ dirname( %2$s ) ] )'
				. ' && $GLOBALS["zestry_runtime_plugins"][ dirname( %2$s ) ] === $p ? "published" : "missing";',
			var_export( dirname( __DIR__, 3 ) . '/vendor/autoload.php', true ),
			var_export( $this->plugin_dir . '/plugin.php', true )
		);

		$output = array();
		$status = 0;

		exec( sprintf( '%s -r %s 2>&1', escapeshellarg( PHP_BINARY ), escapeshellarg( $script ) ), $output, $status );

		$this->assertSame( 0, $status, implode( "\n", $output ) );
		$this->assertSame( 'published', trim( implode( '', $output ) ) );
	}

	public function test_finds_a_running_plugin_by_its_own_directory(): void {
		$running = $this->publish( $this->plugin_dir . '/plugin.php', 'acme' );

		$this->assertSame( $running, $this->runtime->get( $this->plugin_dir ) );
	}

	public function test_a_trailing_slash_is_the_same_directory(): void {
		$running = $this->publish( $this->plugin_dir . '/plugin.php', 'acme' );

		$this->assertSame( $running, $this->runtime->get( $this->plugin_dir . '/' ) );
	}

	/**
	 * Keyed by directory rather than by slug, so a site running several of these
	 * answers for the one asked about instead of whichever ran last.
	 */
	public function test_answers_for_the_directory_asked_about(): void {
		$this->publish( $this->plugin_dir . '/plugin.php', 'acme' );
		$this->publish( $this->plugin_dir . '-other/plugin.php', 'beta' );

		$this->assertSame( 'acme', $this->runtime->get_slug( $this->plugin_dir ) );
		$this->assertSame( 'beta', $this->runtime->get_slug( $this->plugin_dir . '-other' ) );
	}

	/**
	 * The one devtool reader that can return nothing. A plugin that is not
	 * active, or has not run, is an ordinary state rather than an error.
	 */
	public function test_returns_null_for_a_plugin_that_is_not_running(): void {
		$this->assertNull( $this->runtime->get( $this->plugin_dir ) );
		$this->assertNull( $this->runtime->get_slug( $this->plugin_dir ) );
	}

	/**
	 * The case every real consumer is, and the one nothing here covered: `Copier`
	 * rewrites the namespace of every file it copies, so a plugin publishes its
	 * own `Acme\Plugin\Core\Kernel\Plugin` -- a class unrelated to this
	 * package's. Each test above builds the real one, which is why an
	 * `instanceof` check against it passed here and failed for every plugin on
	 * a real site.
	 *
	 * @return void
	 */
	public function test_finds_a_plugin_whose_class_was_rewritten_by_the_copier(): void {
		$GLOBALS[ RuntimePlugin::REGISTRY ] = array(
			$this->plugin_dir => new RewrittenPluginDouble( 'acme-books' ),
		);

		$this->assertNotNull(
			$this->runtime->get( $this->plugin_dir ),
			'A copied Plugin is not this package\'s Plugin, and is still the running plugin.'
		);
		$this->assertSame( 'acme-books', $this->runtime->get_slug( $this->plugin_dir ) );
		$this->assertSame( 'acme-books', $this->runtime->get_slug_or_default( $this->plugin_dir ) );
	}

	/**
	 * Identified by what it can do, so an object that cannot do it is still
	 * refused -- the check is duck-typed, not absent.
	 *
	 * @return void
	 */
	public function test_ignores_an_object_that_is_not_a_plugin_at_all(): void {
		$GLOBALS[ RuntimePlugin::REGISTRY ] = array( $this->plugin_dir => new \stdClass() );

		$this->assertNull( $this->runtime->get( $this->plugin_dir ) );
		$this->assertSame(
			basename( $this->plugin_dir ),
			$this->runtime->get_slug_or_default( $this->plugin_dir ),
			'And the caller that needs an answer either way still gets the directory name.'
		);
	}

	public function test_ignores_a_registry_holding_something_else(): void {
		$GLOBALS[ RuntimePlugin::REGISTRY ] = array( $this->plugin_dir => 'not a plugin' );

		$this->assertNull( $this->runtime->get( $this->plugin_dir ) );
	}

	public function test_ignores_a_registry_that_is_not_an_array(): void {
		$GLOBALS[ RuntimePlugin::REGISTRY ] = 'clobbered';

		$this->assertNull( $this->runtime->get( $this->plugin_dir ) );
	}

	/**
	 * The slug is the entry file's second argument and appears in no file, so it
	 * is the clearest thing this channel exists to answer. A plugin that passes
	 * one deliberately is exactly the case a directory-name guess gets wrong.
	 */
	public function test_reports_a_slug_that_is_not_the_directory_name(): void {
		$this->publish( $this->plugin_dir . '/plugin.php', 'something-else' );

		$this->assertSame( 'something-else', $this->runtime->get_slug( $this->plugin_dir ) );
		$this->assertNotSame( basename( $this->plugin_dir ), 'something-else' );
	}

	/**
	 * Stand in for what `Plugin::run()` does once `ZESTRY_DEVTOOL` is defined.
	 *
	 * The constant cannot be defined here: it is process-wide and permanent, so
	 * defining it would leak into every later test in the run.
	 *
	 * @param string $entry Absolute path to the plugin's entry file.
	 * @param string $slug  The slug it registers names under.
	 * @return Plugin
	 */
	private function publish( string $entry, string $slug ): Plugin {
		$running = new Plugin( $entry, $slug );

		$GLOBALS[ RuntimePlugin::REGISTRY ][ dirname( $entry ) ] = $running;

		return $running;
	}
}

/**
 * Stands in for a consumer's copied `Plugin`: the same two methods the devtool
 * calls, under a class name this package does not own.
 */
final class RewrittenPluginDouble {

	/**
	 * @var string
	 */
	private string $slug;

	/**
	 * @param string $slug The slug it registers names under.
	 */
	public function __construct( string $slug ) {
		$this->slug = $slug;
	}

	public function get_slug(): string {
		return $this->slug;
	}

	public function get_bootstrap_file(): ?string {
		return null;
	}
}
