<?php

declare( strict_types=1 );

namespace Zestry\WPToolkit\Tests\Integration\Core;

use Zestry\WPToolkit\Kernel\Abstracts\Module;
use Zestry\WPToolkit\Kernel\Abstracts\Service;
use Zestry\WPToolkit\Kernel\Contracts\PluginAware;
use Zestry\WPToolkit\Kernel\Exceptions\CircularDependencyException;
use Zestry\WPToolkit\Kernel\Exceptions\ModuleException;
use Zestry\WPToolkit\Kernel\Exceptions\ModuleNotFoundException;
use Zestry\WPToolkit\Services\Path;
use Zestry\WPToolkit\Tests\Support\TestCase;

/**
 * Core resolution, injection, boot, and exception behavior of the Plugin/repository.
 *
 * @covers \Zestry\WPToolkit\Kernel\Plugin
 * @covers \Zestry\WPToolkit\Kernel\ServicesRepository
 */
final class ContainerTest extends TestCase {

	public function test_get_module_returns_the_same_instance_each_time(): void {
		$first  = $this->plugin->get( Path::class );
		$second = $this->plugin->get( Path::class );

		$this->assertInstanceOf( Path::class, $first );
		$this->assertSame( $first, $second, 'get() must cache the singleton.' );
	}

	public function test_make_returns_a_fresh_uncached_instance(): void {
		$a = $this->plugin->make( Path::class );
		$b = $this->plugin->make( Path::class );

		$this->assertNotSame( $a, $b, 'make() must not cache.' );
		$this->assertNotSame(
			$this->plugin->get( Path::class ),
			$this->plugin->make( Path::class ),
			'make() must not return the singleton.'
		);
	}

	public function test_unknown_class_throws_module_not_found(): void {
		$this->expectException( ModuleNotFoundException::class );
		$this->plugin->get( 'Zestry\\WPToolkit\\Tests\\Nope\\DoesNotExist' );
	}

	public function test_non_module_class_throws_module_not_found(): void {
		$this->expectException( ModuleNotFoundException::class );
		$this->plugin->get( \stdClass::class );
	}

	public function test_container_exceptions_are_catchable_via_base_and_spl(): void {
		try {
			$this->plugin->get( 'Zestry\\WPToolkit\\Tests\\Nope\\Missing' );
			$this->fail( 'Expected an exception.' );
		} catch ( ModuleException $e ) {
			$this->assertInstanceOf( \RuntimeException::class, $e );
		}
	}

	public function test_non_callable_initializer_is_rejected_at_the_boundary(): void {
		// configure() type-hints `callable`, so a non-callable is rejected with
		// a TypeError at the call site (the internal is_callable() guard is now a
		// belt-and-suspenders fallback for dynamic/variadic call paths).
		$this->expectException( \TypeError::class );
		// @phpstan-ignore-next-line — intentionally passing a non-callable.
		$this->plugin->configure( Path::class, 'not a callable at all' );
	}

	public function test_wire_rejects_a_non_plugin_aware_object(): void {
		$this->expectException( \TypeError::class );
		// @phpstan-ignore-next-line — intentionally passing a non-PluginAware object.
		$this->plugin->wire( new \stdClass() );
	}

	public function test_public_and_protected_properties_are_injected_private_is_not(): void {
		$module = $this->plugin->get( InjectionProbe::class );

		$this->assertInstanceOf( Path::class, $module->public_path );
		$this->assertInstanceOf( Path::class, $module->protected_path_value() );
		$this->assertNull( $module->private_path_value(), 'Private properties must not be injected.' );
	}

	public function test_circular_dependency_is_detected(): void {
		$this->expectException( CircularDependencyException::class );
		$this->plugin->get( CycleA::class );
	}

	public function test_self_reference_during_boot_does_not_false_cycle(): void {
		// Regression for the #1 bug: a module that wires a dependent asking for
		// the module during its own boot must resolve cleanly and hand back the
		// in-flight instance. The singleton is cached before the initializer and
		// boot run, which is what makes the re-entrant get() safe.
		$module = $this->plugin->get( SelfWiringBooter::class );

		$this->assertTrue( $module->is_booted() );
		$this->assertSame(
			$module,
			$module->wired_dependent()->booter(),
			'The dependent wired during boot must receive the in-flight instance.'
		);
	}

	/**
	 * Injection is for services. Building a module boots it -- binding hooks,
	 * walking a directory, registering with WordPress -- and a property
	 * declaration hides all of that behind a type name.
	 */
	public function test_a_property_typed_as_a_module_is_refused(): void {
		$this->expectException( ModuleException::class );
		$this->expectExceptionMessage( '$booter' );

		$this->plugin->wire( new ModuleInjectingDependent() );
	}

	/**
	 * Thrown rather than skipped: skipping would leave the typed property
	 * uninitialized, and the first read of it fatals with PHP's own message
	 * about initialization, which names neither the module nor the reason.
	 */
	public function test_the_refusal_names_the_call_to_use_instead(): void {
		try {
			$this->plugin->wire( new ModuleInjectingDependent() );
			$this->fail( 'Wiring should have refused the module property.' );
		} catch ( ModuleException $exception ) {
			$this->assertStringContainsString( 'get( SelfWiringBooter::class )', $exception->getMessage() );
		}
	}

	/**
	 * The whole point of naming a hook: the entry's `before_boot` runs on it too,
	 * immediately before the module boots -- so a `__()` in there is safe, where
	 * an initializer running at plugin load is not.
	 */
	public function test_boots_on_defers_the_module_and_its_configuration(): void {
		$this->plugin->bootstrap( $this->write_bootstrap() )->run();

		$this->assertSame( array(), $GLOBALS['zestry_boot_order'] ?? array() );

		do_action( 'zestry_test_boot_hook' );

		$this->assertSame(
			array( 'before_boot', 'on_boot' ),
			$GLOBALS['zestry_boot_order'],
			'Configuration runs immediately before boot, on the hook.'
		);
	}

	/**
	 * Building it early would bind it on the wrong side of whatever it was
	 * declared to follow, and a module that boots at the wrong moment reports
	 * nothing -- it registers into a registry nobody has filled.
	 */
	public function test_asking_for_a_deferred_module_before_its_hook_throws(): void {
		$this->plugin->bootstrap( $this->write_bootstrap() )->run();

		$this->expectException( ModuleException::class );
		$this->expectExceptionMessage( 'zestry_test_boot_hook' );

		$this->plugin->get( DeferredBooter::class );
	}

	/**
	 * A hook that has already fired would defer forever, so the declaration reads
	 * as "not before" rather than "exactly then".
	 */
	public function test_a_hook_that_already_fired_boots_immediately(): void {
		do_action( 'zestry_test_boot_hook' );

		$this->plugin->bootstrap( $this->write_bootstrap() )->run();

		$this->assertTrue( $this->plugin->get( DeferredBooter::class )->is_booted() );
	}

	/**
	 * Write a bootstrap file declaring the deferred module in the long form.
	 *
	 * @return string Absolute path to the file.
	 */
	private function write_bootstrap(): string {
		$GLOBALS['zestry_boot_order'] = array();

		$file = $this->plugin_dir . '/bootstrap.php';

		file_put_contents(
			$file,
			"<?php\nreturn array(\n"
				. "\t'" . str_replace( '\\', '\\\\', DeferredBooter::class ) . "' => array(\n"
				. "\t\t'boots_on' => 'zestry_test_boot_hook',\n"
				. "\t\t'before_boot' => static function ( \$module ): void {\n"
				. "\t\t\t\$GLOBALS['zestry_boot_order'][] = 'before_boot';\n"
				. "\t\t},\n"
				. "\t),\n);\n"
		);

		return $file;
	}

	public function test_bootable_module_is_booted_once_after_resolution(): void {
		$module = $this->plugin->get( BootCounter::class );

		$this->assertTrue( $module->is_booted() );
		$this->assertSame( 1, $module::$boot_count );

		$this->plugin->get( BootCounter::class );
		$this->assertSame( 1, $module::$boot_count, 'Cached resolution must not re-boot.' );
	}

	public function test_run_resolves_autoloaded_modules_and_ready_callback(): void {
		$ran = false;

		$returned = $this->plugin
			->autoload( array( BootCounter::class ) )
			->run(
				function ( $plugin ) use ( &$ran ): void {
					$ran = true;
					// The callback runs after the queue is resolved, so a
					// queued module has already booted by now.
					$this->assertTrue( $plugin->get( BootCounter::class )->is_booted() );
				}
			);

		$this->assertTrue( $ran, 'run() must invoke the ready callback.' );
		$this->assertSame( $this->plugin, $returned, 'run() returns the plugin for chaining.' );
		$this->assertTrue(
			$this->plugin->get( BootCounter::class )->is_booted(),
			'A queued module is booted during run().'
		);
	}
}

// --- Fixtures ---------------------------------------------------------------

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound -- test fixtures.

final class InjectionProbe extends Service {

	public Path $public_path;
	protected Path $protected_path;
	private ?Path $private_path = null;

	public function protected_path_value(): Path {
		return $this->protected_path;
	}

	public function private_path_value(): ?Path {
		return $this->private_path;
	}
}

final class CycleA extends Service {
	public CycleB $b;
}

final class CycleB extends Service {
	public CycleA $a;
}

final class BootCounter extends Module {


	public static int $boot_count = 0;

	protected function on_boot(): void {
		++self::$boot_count;
	}
}

final class DeferredBooter extends Module {

	protected function on_boot(): void {
		$GLOBALS['zestry_boot_order'][] = 'on_boot';
	}
}

final class ModuleInjectingDependent implements PluginAware {

	use \Zestry\WPToolkit\Kernel\Traits\WithPlugin;

	public SelfWiringBooter $booter;
}

final class SelfWiringDependent implements PluginAware {

	use \Zestry\WPToolkit\Kernel\Traits\WithPlugin;

	public function booter(): SelfWiringBooter {
		return $this->get_plugin()->get( SelfWiringBooter::class );
	}
}

final class SelfWiringBooter extends Module {


	private ?SelfWiringDependent $dependent = null;

	public function wired_dependent(): SelfWiringDependent {
		return $this->dependent;
	}

	protected function on_boot(): void {
		$dependent = new SelfWiringDependent();
		$this->get_plugin()->wire( $dependent );
		$this->dependent = $dependent;
	}
}

// phpcs:enable Generic.Files.OneObjectStructurePerFile.MultipleFound
