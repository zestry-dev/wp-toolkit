<?php

declare( strict_types=1 );

namespace Zestry\WPToolkit\Tests\Integration\Core;

use Zestry\WPToolkit\Kernel\Abstracts\Module;
use Zestry\WPToolkit\Kernel\Contracts\Bootable;
use Zestry\WPToolkit\Kernel\Contracts\PluginAware;
use Zestry\WPToolkit\Kernel\Exceptions\CircularDependencyException;
use Zestry\WPToolkit\Kernel\Exceptions\ModuleException;
use Zestry\WPToolkit\Kernel\Exceptions\ModuleNotFoundException;
use Zestry\WPToolkit\Modules\Path;
use Zestry\WPToolkit\Tests\Support\TestCase;

/**
 * Core resolution, injection, boot, and exception behavior of the Plugin/repository.
 *
 * @covers \Zestry\WPToolkit\Kernel\Plugin
 * @covers \Zestry\WPToolkit\Kernel\ModulesRepository
 */
final class ContainerTest extends TestCase {

	/**
	 * Nothing pre-declared: these tests are about what declaring does.
	 *
	 * @return array<class-string>
	 */
	protected function get_toolkit_modules(): array {
		return array();
	}

	public function set_up(): void {
		parent::set_up();

		// Nothing is built without being declared, and these fixtures are
		// modules like any other.
		$this->plugin->declare_modules(
			array( Path::class, BootCounter::class, SelfWiringBooter::class )
		);
	}

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


	/**
	 * Only `make()` can cycle. `get()` publishes the shared instance before the
	 * module boots, so anything reaching back for it during that boot gets the
	 * in-flight one -- `make()` never publishes, so two modules making each
	 * other would recurse until the stack gave out.
	 */
	public function test_a_cycle_between_unshared_instances_is_detected(): void {
		$this->expectException( CircularDependencyException::class );
		$this->plugin->make( CycleA::class );
	}

	public function test_self_reference_during_boot_does_not_false_cycle(): void {
		// Regression for the #1 bug: a module that wires a dependent asking for
		// the module during its own boot must resolve cleanly and hand back the
		// in-flight instance. The singleton is cached before the initializer and
		// boot run, which is what makes the re-entrant get() safe.
		$module = $this->plugin->get( SelfWiringBooter::class );

		$this->assertSame(
			$module,
			$module->wired_dependent()->booter(),
			'The dependent wired during boot must receive the in-flight instance.'
		);
	}



	/**
	 * The whole point of naming a hook: the entry's `configure` runs on it too,
	 * immediately before the module boots -- so a `__()` in there is safe, where
	 * an initializer running at plugin load is not.
	 */
	public function test_boots_on_defers_the_module_and_its_configuration(): void {
		$this->plugin->bootstrap( $this->write_bootstrap() )->run();

		$this->assertSame( array(), $GLOBALS['zestry_boot_order'] ?? array() );

		do_action( 'zestry_test_boot_hook' );

		$this->assertSame(
			array( 'configure', 'on_boot' ),
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

		$this->assertSame(
			array( 'configure', 'on_boot' ),
			$GLOBALS['zestry_boot_order'],
			'A hook that has been and gone means "not before", so run() builds it now.'
		);
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
				. "\t\t'configure' => static function ( \$module ): void {\n"
				. "\t\t\t\$GLOBALS['zestry_boot_order'][] = 'configure';\n"
				. "\t\t},\n"
				. "\t),\n);\n"
		);

		return $file;
	}

	public function test_bootable_module_is_booted_once_after_resolution(): void {
		BootCounter::$boot_count = 0;

		$module = $this->plugin->get( BootCounter::class );

		$this->assertSame( 1, $module::$boot_count );

		$this->plugin->get( BootCounter::class );
		$this->assertSame( 1, $module::$boot_count, 'Cached resolution must not re-boot.' );
	}

	public function test_run_builds_declared_modules_and_runs_the_ready_callback(): void {
		$ran = false;

		BootCounter::$boot_count = 0;

		$returned = $this->plugin
			->declare_modules( array( BootCounter::class ) )
			->run(
				function ( $plugin ) use ( &$ran ): void {
					$ran = true;
					// The callback runs after every declared module is built, so
					// a declared module has already booted by now.
					$this->assertSame( 1, $plugin->get( BootCounter::class )::$boot_count );
				}
			);

		$this->assertTrue( $ran, 'run() must invoke the ready callback.' );
		$this->assertSame( $this->plugin, $returned, 'run() returns the plugin for chaining.' );
		$this->assertSame( 1, BootCounter::$boot_count, 'A declared module is booted during run().' );
	}
}

// --- Fixtures ---------------------------------------------------------------

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound -- test fixtures.

final class CycleA extends Module implements Bootable {

	public function on_boot(): void {
		$this->get_plugin()->make( CycleB::class );
	}
}

final class CycleB extends Module implements Bootable {

	public function on_boot(): void {
		$this->get_plugin()->make( CycleA::class );
	}
}

final class BootCounter extends Module implements Bootable {

	public static int $boot_count = 0;

	public function on_boot(): void {
		++self::$boot_count;
	}
}

final class DeferredBooter extends Module implements Bootable {

	public function on_boot(): void {
		$GLOBALS['zestry_boot_order'][] = 'on_boot';
	}
}

final class SelfWiringDependent implements PluginAware {

	use \Zestry\WPToolkit\Kernel\Traits\WithPlugin;

	public function booter(): SelfWiringBooter {
		return $this->get_plugin()->get( SelfWiringBooter::class );
	}
}

final class SelfWiringBooter extends Module implements Bootable {

	private ?SelfWiringDependent $dependent = null;

	public function wired_dependent(): SelfWiringDependent {
		return $this->dependent;
	}

	public function on_boot(): void {
		$dependent = new SelfWiringDependent();
		$this->get_plugin()->wire( $dependent );
		$this->dependent = $dependent;
	}
}

// phpcs:enable Generic.Files.OneObjectStructurePerFile.MultipleFound
