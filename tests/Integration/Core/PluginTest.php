<?php

declare( strict_types=1 );

namespace Zestry\WPToolkit\Tests\Integration\Core;

use Zestry\WPToolkit\Kernel\Exceptions\ModuleException;
use Zestry\WPToolkit\Kernel\Plugin;
use Zestry\WPToolkit\Services\Path;
use Zestry\WPToolkit\Tests\Support\TestCase;

/**
 * Plugin's own metadata accessors, environment predicates, and thin delegations.
 *
 * ContainerTest exercises the deep resolution behavior of get()/make()/
 * wire()/configure()/autoload()/run() against the repository; this file
 * focuses on the members that live entirely on Plugin: get_slug(), get_entry_file(),
 * and the is_*() predicates, including their defined/undefined and ===true/!==true
 * branches. It also drives the branches that live in Plugin's own method bodies —
 * autoload()'s per-module loop and run()'s callback guard (both true and false).
 *
 * @covers \Zestry\WPToolkit\Kernel\Plugin
 */
final class PluginTest extends TestCase {

	public function test_get_slug_returns_the_configured_slug(): void {
		$this->assertSame( 'zestry-test', $this->plugin->get_slug() );
	}

	public function test_get_slug_defaults_to_the_entry_directory_name(): void {
		// When no slug is passed, the constructor derives it from the entry file's
		// parent directory name (basename( dirname( $entry ) )). This exercises the
		// falsy branch of the `$slug ? $slug : basename(...)` ternary in the constructor.
		$plugin = new Plugin( $this->plugin_dir . '/plugin.php' );

		$this->assertSame( basename( $this->plugin_dir ), $plugin->get_slug() );
	}

	/**
	 * Every name a module registers is built from the slug, and the strictest
	 * destination takes only lowercase letters, digits and single dashes. Checked
	 * once here rather than in each module that composes a name.
	 *
	 * @dataProvider provide_unusable_slugs
	 * @param string $slug   The slug to refuse.
	 * @param string $reason What makes it unusable, for the failure message.
	 * @return void
	 */
	public function test_a_slug_a_registered_name_cannot_carry_is_refused( string $slug, string $reason ): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( $slug );

		new Plugin( $this->plugin_dir . '/plugin.php', $slug );

		$this->fail( 'Expected refusal: ' . $reason );
	}

	/**
	 * @return array<string, array{0: string, 1: string}>
	 */
	public function provide_unusable_slugs(): array {
		return array(
			'underscore'      => array( 'acme_crm', 'an ability name and a block namespace take no underscore' ),
			'capital'         => array( 'AcmeCrm', 'both are matched lowercase' ),
			'leading digit'   => array( '2acme', 'a CSS class cannot begin with a digit' ),
			'leading dash'    => array( '-acme', '`wp -acme greet` reads it as a flag' ),
			'trailing dash'   => array( 'acme-', 'every composed name adds its own separator' ),
			'doubled dash'    => array( 'acme--crm', 'one dash joins two halves' ),
			'space'           => array( 'acme crm', 'nothing shared takes a space' ),
			'dot'             => array( 'acme.crm', 'a URL would have to encode it' ),
			'slash'           => array( 'acme/crm', 'it is the separator an ability name uses' ),
			'too long'        => array( 'a' . str_repeat( 'b', Plugin::MAX_SLUG_LENGTH ), 'it leaves no room for your own half' ),
		);
	}

	/**
	 * @return void
	 */
	public function test_the_shapes_a_registered_name_can_carry_are_accepted(): void {
		foreach ( array( 'acme', 'acme-crm', 'acme-crm2', 'a', str_repeat( 'a', Plugin::MAX_SLUG_LENGTH ) ) as $slug ) {
			$this->assertSame(
				$slug,
				( new Plugin( $this->plugin_dir . '/plugin.php', $slug ) )->get_slug(),
				$slug . ' is a usable slug.'
			);
		}
	}

	/**
	 * What `on_boot()` may do is decided by where WordPress loads plugins:
	 * `wp-settings.php` includes them, *then* requires `pluggable.php`, *then*
	 * fires `plugins_loaded`. So an entry file that calls `run()` as it loads --
	 * the documented shape, and what `ActivationHandler` requires -- boots modules
	 * before there is a current user or a nonce function to call.
	 *
	 * Asserted against WordPress's own file rather than a live boot, since the
	 * test suite has long since passed that point.
	 *
	 * @return void
	 */
	public function test_a_module_boots_before_wordpress_loads_its_pluggable_functions(): void {
		$settings = (string) file_get_contents( ABSPATH . 'wp-settings.php' );

		$plugins   = strpos( $settings, 'wp_get_active_and_valid_plugins()' );
		$pluggable = strpos( $settings, "WPINC . '/pluggable.php'" );
		$loaded    = strpos( $settings, "do_action( 'plugins_loaded' )" );

        $this->assertIsInt( $plugins );
        $this->assertIsInt( $pluggable );
        $this->assertIsInt( $loaded );

		$this->assertLessThan( $pluggable, $plugins, 'Plugins load before pluggable.php.' );
		$this->assertLessThan( $loaded, $pluggable, 'And both before plugins_loaded.' );
	}

	/**
	 * `bootstrap.php` is documented as booting in the order it lists, and a
	 * consumer orders it deliberately -- so the order is a guarantee, not an
	 * accident of `run_autoload()` happening to use a foreach.
	 *
	 * @return void
	 */
	public function test_modules_boot_in_the_order_the_bootstrap_file_lists_them(): void {
		$this->write_plugin_file(
			'bootstrap.php',
			"<?php\nreturn array(\n"
				. "\t\\Zestry\\WPToolkit\\Tests\\Integration\\Core\\OrderProbeTwo::class,\n"
				. "\t\\Zestry\\WPToolkit\\Tests\\Integration\\Core\\OrderProbeOne::class,\n);\n"
		);

		$GLOBALS['zestry_boot_order'] = array();

		$this->plugin->bootstrap( $this->plugin_dir . '/bootstrap.php' )->run();

		$this->assertSame(
			array( 'two', 'one' ),
			$GLOBALS['zestry_boot_order'],
			'Listed second-then-first, so booted second-then-first.'
		);

		unset( $GLOBALS['zestry_boot_order'] );
	}

	/**
	 * `ModuleException` covers the toolkit's own failures, and nothing wraps a
	 * consumer's: `on_boot()` is called bare, so whatever it raises arrives as
	 * itself. Pinned because the docs promise it, and because a `catch
	 * ( ModuleException )` in an entry file would not see this one --
	 * `ModuleException` extends `\RuntimeException`, so the parent escapes the
	 * child.
	 *
	 * @return void
	 */
	public function test_a_modules_own_exception_is_not_reclassified_as_a_module_exception(): void {
		$this->write_plugin_file(
			'bootstrap.php',
			"<?php\nreturn array(\n"
				. "\t\\Zestry\\WPToolkit\\Tests\\Integration\\Core\\ForeignThrowingProbe::class,\n);\n"
		);

		$this->plugin->bootstrap( $this->plugin_dir . '/bootstrap.php' );

		try {
			$this->plugin->run();
			$this->fail( 'run() swallowed a boot failure.' );
		} catch ( \Throwable $exception ) {
			$this->assertNotInstanceOf(
				ModuleException::class,
				$exception,
				'Wrapping a consumer exception would hide what actually broke.'
			);
			$this->assertSame( \RuntimeException::class, $exception::class );
			$this->assertSame( 'my own bug', $exception->getMessage() );
		}
	}

	/**
	 * And the other half of what the entry file has to know: a module that throws
	 * while booting takes the rest of the list with it, and the exception leaves
	 * run() rather than being swallowed.
	 *
	 * @return void
	 */
	public function test_a_module_that_throws_while_booting_stops_the_ones_after_it(): void {
		$this->write_plugin_file(
			'bootstrap.php',
			"<?php\nreturn array(\n"
				. "\t\\Zestry\\WPToolkit\\Tests\\Integration\\Core\\ThrowingProbe::class,\n"
				. "\t\\Zestry\\WPToolkit\\Tests\\Integration\\Core\\OrderProbeOne::class,\n);\n"
		);

		$GLOBALS['zestry_boot_order'] = array();

		$this->plugin->bootstrap( $this->plugin_dir . '/bootstrap.php' );

		try {
			$this->plugin->run();
			$this->fail( 'run() swallowed a boot failure.' );
		} catch ( ModuleException $exception ) {
			$this->assertSame( 'boot refused', $exception->getMessage() );
		}

		$this->assertSame(
			array(),
			$GLOBALS['zestry_boot_order'],
			'The module listed after the failure never booted.'
		);

		unset( $GLOBALS['zestry_boot_order'] );
	}

	/**
	 * The whole point: a plugin's modules are declared in one file, so its entry
	 * file stays one line however many it uses -- and `wp zt add` has somewhere
	 * to register what it copies.
	 */
	public function test_bootstrap_registers_and_queues_what_the_file_declares(): void {
		$this->write_plugin_file(
			'bootstrap.php',
			"<?php\nreturn array(\n"
				. "\t\\Zestry\\WPToolkit\\Tests\\Integration\\Core\\BootstrapProbe::class => static function ( \\Zestry\\WPToolkit\\Tests\\Integration\\Core\\BootstrapProbe \$probe ): void {\n"
				. "\t\t\$GLOBALS['zestry_bootstrap_ran'] = true;\n"
				. "\t},\n);\n"
		);

		$GLOBALS['zestry_bootstrap_ran'] = false;

		$returned = $this->plugin->bootstrap( $this->plugin_dir . '/bootstrap.php' );

		$this->assertSame( $this->plugin, $returned, 'bootstrap() returns the plugin for chaining.' );
		$this->assertFalse( $GLOBALS['zestry_bootstrap_ran'], 'Declaring a module does not resolve it.' );

		$this->plugin->run();

		$this->assertTrue( $GLOBALS['zestry_bootstrap_ran'], 'run() builds the modules bootstrap() declared.' );
		unset( $GLOBALS['zestry_bootstrap_ran'] );
	}

	/**
	 * A service is never declared in `bootstrap.php` -- that file is modules
	 * only. One that takes configuration gets it from configure() in the entry
	 * file, and the callback waits for the first get() rather than running at
	 * load.
	 */
	public function test_a_service_is_configured_through_register_rather_than_bootstrap(): void {
		$GLOBALS['zestry_bootstrap_ran'] = false;

		$this->plugin->configure(
			Path::class,
			static function ( Path $path ): void {
				$GLOBALS['zestry_bootstrap_ran'] = true;
			}
		);

		$this->plugin->run();

		$this->assertFalse( $GLOBALS['zestry_bootstrap_ran'], 'Registering does not build it.' );

		$this->plugin->get( Path::class );

		$this->assertTrue( $GLOBALS['zestry_bootstrap_ran'], 'The initializer runs when it is resolved.' );
		unset( $GLOBALS['zestry_bootstrap_ran'] );
	}

	/**
	 * A module needing no configuration is written bare, so the file stays a
	 * plain list rather than demanding an empty closure each time.
	 */
	public function test_bootstrap_accepts_a_module_with_no_configuration(): void {
		$this->write_plugin_file(
			'bootstrap.php',
			"<?php\nreturn array(\n"
				. "\t\\Zestry\\WPToolkit\\Tests\\Integration\\Core\\BootstrapProbe::class,\n"
				. ");\n"
		);

		BootstrapProbe::$boots = 0;

		$this->plugin->bootstrap( $this->plugin_dir . '/bootstrap.php' )->run();

		$this->assertSame( 1, BootstrapProbe::$boots, 'A bare entry is what builds the module.' );
	}

	/**
	 * Reading the file compiles none of the classes it names.
	 *
	 * Every entry means one thing -- build this module -- so nothing has to ask
	 * what a class *is*, and nothing is autoloaded to answer. The names are
	 * remembered as strings and the classes compile when `run()` builds them.
	 * An earlier design asked `is_a( $name, Module::class, true )` per entry,
	 * whose `true` compiled every declared class just to classify it.
	 */
	public function test_reading_the_bootstrap_file_compiles_nothing(): void {
		$this->write_plugin_file(
			'bootstrap.php',
			"<?php\nreturn array(\n"
				. "\t'Zestry\\WPToolkit\\\\Tests\\\\Integration\\\\Core\\\\NotLoadedProbe' => static function ( \$m ): void {},\n"
				. ");\n"
		);

		$this->plugin->bootstrap( $this->plugin_dir . '/bootstrap.php' );

		$this->assertFalse(
			class_exists( 'Zestry\\WPToolkit\\Tests\\Integration\\Core\\NotLoadedProbe', false ),
			'Declaring a class must not compile it.'
		);
	}

	/**
	 * Absent is not an error: a plugin may configure everything in its entry
	 * file, so a template can call bootstrap() unconditionally.
	 */
	public function test_bootstrap_is_a_no_op_when_the_file_does_not_exist(): void {
		$this->assertSame(
			$this->plugin,
			$this->plugin->bootstrap( $this->plugin_dir . '/nothing-here.php' )
		);
	}

	public function test_bootstrap_defaults_to_the_file_beside_the_entry_file(): void {
		$this->write_plugin_file(
			'bootstrap.php',
			"<?php\nreturn array(\n"
				. "\t\\Zestry\\WPToolkit\\Tests\\Integration\\Core\\BootstrapProbe::class,\n"
				. ");\n"
		);

		BootstrapProbe::$boots = 0;

		// No path given: it looks beside $this->entry_file, which TestCase puts
		// in the same directory.
		$this->plugin->bootstrap()->run();

		$this->assertSame( 1, BootstrapProbe::$boots );
	}

	public function test_bootstrap_rejects_a_file_that_returns_no_array(): void {
		$this->write_plugin_file( 'bootstrap.php', "<?php\nreturn 'not an array';\n" );

		$this->expectException( ModuleException::class );
		$this->expectExceptionMessage( 'Bootstrap file must return an array' );

		$this->plugin->bootstrap( $this->plugin_dir . '/bootstrap.php' );
	}

	public function test_get_entry_file_returns_the_entry_path(): void {
		$this->assertSame( $this->entry_file, $this->plugin->get_entry_file() );
	}

	/**
	 * The registered path is observed through WP_Textdomain_Registry, which is
	 * what load_plugin_textdomain() writes to -- it does not load a file, so
	 * there is no loaded translation to assert on.
	 */
	public function test_set_languages_path_registers_the_directory_for_the_slug(): void {
		global $wp_textdomain_registry;

		$returned = $this->plugin->set_languages_path( 'languages' );

		$this->assertSame( $this->plugin, $returned, 'set_languages_path() returns the plugin for chaining.' );

		$paths = new \ReflectionProperty( $wp_textdomain_registry, 'custom_paths' );
		$paths->setAccessible( true );
		$registered = $paths->getValue( $wp_textdomain_registry );

		$this->assertArrayHasKey( 'zestry-test', $registered, 'The text domain defaults to the plugin slug.' );
		$this->assertStringEndsWith(
			basename( $this->plugin_dir ) . '/languages',
			$registered['zestry-test'],
			"The path is resolved against the plugin's own directory."
		);
	}

	public function test_set_languages_path_accepts_a_text_domain_of_its_own(): void {
		global $wp_textdomain_registry;

		$this->plugin->set_languages_path( 'languages', 'acme-plugin' );

		$paths = new \ReflectionProperty( $wp_textdomain_registry, 'custom_paths' );
		$paths->setAccessible( true );

		$this->assertArrayHasKey( 'acme-plugin', $paths->getValue( $wp_textdomain_registry ) );
	}

	public function test_get_header_reads_a_declared_header(): void {
		file_put_contents( $this->entry_file, "<?php\n/**\n * Plugin Name: Zestry Test\n * Version: 1.2.3\n */\n" );

		$this->assertSame( '1.2.3', $this->plugin->get_header( 'Version' ) );
	}

	public function test_get_header_returns_null_for_an_absent_header(): void {
		$this->assertNull( $this->plugin->get_header( 'Version' ) );
	}

	public function test_get_version_is_shorthand_for_the_version_header(): void {
		file_put_contents( $this->entry_file, "<?php\n/**\n * Plugin Name: Zestry Test\n * Version: 4.5.6\n */\n" );

		$this->assertSame( '4.5.6', $this->plugin->get_version() );
	}

	public function test_get_version_returns_null_when_no_version_header_is_declared(): void {
		$this->assertNull( $this->plugin->get_version() );
	}

	public function test_is_wp_debug_reflects_the_wp_debug_constant(): void {
		// .wp-env.test.json defines WP_DEBUG => true, so both sub-conditions of
		// ( defined( 'WP_DEBUG' ) && WP_DEBUG === true ) are satisfied.
		$this->assertTrue( defined( 'WP_DEBUG' ), 'Test environment must define WP_DEBUG.' );
		$this->assertTrue( WP_DEBUG === true, 'Test environment must set WP_DEBUG to boolean true.' );
		$this->assertTrue( $this->plugin->is_wp_debug() );
	}

	public function test_is_wp_cli_is_false_in_the_phpunit_web_context(): void {
		// The WP_CLI *constant* is intentionally NOT defined here (tests only stub the
		// WP_CLI *class*); defining the constant would leak process-wide. This exercises
		// the short-circuit (false) branch of the defined() guard.
		$this->assertFalse( defined( 'WP_CLI' ), 'WP_CLI must not be defined in this context.' );
		$this->assertFalse( $this->plugin->is_wp_cli() );
	}

	public function test_is_plugin_debug_is_false_when_the_slug_constant_is_undefined(): void {
		// Slug 'zestry-test' maps to constant ZESTRY_TEST_DEBUG, which is not defined.
		// Exercises the short-circuit (false) branch of is_plugin_debug()'s defined() guard.
		$this->assertFalse( defined( 'ZESTRY_TEST_DEBUG' ), 'ZESTRY_TEST_DEBUG must not be defined.' );
		$this->assertFalse( $this->plugin->is_plugin_debug() );
	}

	public function test_is_plugin_debug_is_true_when_the_slug_constant_is_defined_true(): void {
		// Build a plugin whose slug maps to a test-owned constant defined as true,
		// exercising the fully-true path: defined() && constant() === true.
		// The '-' -> '_' and uppercase transforms are covered by the slug shape:
		// strtoupper('zestry-plugintest-true') then str_replace('-','_') -> ZESTRY_PLUGINTEST_TRUE.
		$constant = 'ZESTRY_PLUGINTEST_TRUE_DEBUG';
		if ( ! defined( $constant ) ) {
			define( $constant, true );
		}

		$plugin = new Plugin( $this->entry_file, 'zestry-plugintest-true' );

		$this->assertSame( 'zestry-plugintest-true', $plugin->get_slug() );
		$this->assertTrue( $plugin->is_plugin_debug() );
	}

	public function test_is_plugin_debug_is_false_when_the_slug_constant_is_defined_non_true(): void {
		// Constant is defined but not identical to true, exercising the
		// constant( $name ) === true comparison's false branch (not the defined() guard).
		$constant = 'ZESTRY_PLUGINTEST_FALSE_DEBUG';
		if ( ! defined( $constant ) ) {
			define( $constant, false );
		}

		$plugin = new Plugin( $this->entry_file, 'zestry-plugintest-false' );

		$this->assertTrue( defined( $constant ) );
		$this->assertFalse( $plugin->is_plugin_debug() );
	}

	public function test_delegations_return_expected_values_and_are_fluent(): void {
		// Light re-assertion of the thin delegations owned by Plugin: configure()
		// and autoload() return $this for chaining; get()/make()/has() delegate
		// to the repository. Deep behavior is covered by ContainerTest.
		$this->assertSame(
			$this->plugin,
			$this->plugin->configure( Path::class, static function ( Path $path ): void {} ),
			'configure() returns the plugin for chaining.'
		);
		$this->assertSame(
			$this->plugin,
			$this->plugin->autoload( array() ),
			'autoload() returns the plugin for chaining.'
		);

		$this->assertInstanceOf( Path::class, $this->plugin->get( Path::class ) );
		$this->assertInstanceOf( Path::class, $this->plugin->make( Path::class ) );
	}

	public function test_make_runs_the_configurator_before_returning(): void {
		// Exercises make()'s optional-configurator argument (the non-null default) so the
		// callback path through Plugin::make() is driven, not just the null-configurator
		// call in the delegations test.
		$seen = null;

		$instance = $this->plugin->make(
			Path::class,
			function ( Path $path, Plugin $plugin ) use ( &$seen ): void {
				$seen = $plugin;
			}
		);

		$this->assertInstanceOf( Path::class, $instance );
		$this->assertSame( $this->plugin, $seen, 'make() passes this plugin to the configurator.' );
	}

	public function test_autoload_queues_each_listed_module(): void {
		// A non-empty list drives the body of autoload()'s foreach loop (the empty-array
		// call in the delegations test never enters the loop). Queuing is observed
		// through run(), which resolves what was queued -- the initializer only runs
		// for a module that actually got queued.
		$initialized = false;

		$this->plugin->configure(
			Path::class,
			function ( Path $path ) use ( &$initialized ): void {
				$initialized = true;
			}
		);

		$returned = $this->plugin->autoload( array( Path::class ) );

		$this->assertSame( $this->plugin, $returned, 'autoload() returns the plugin for chaining.' );
		$this->assertFalse( $initialized, 'autoload() queues without resolving.' );

		$this->plugin->run();

		$this->assertTrue( $initialized, 'run() resolves what autoload() queued.' );
	}

	public function test_run_without_a_callback_returns_the_plugin(): void {
		// Covers run()'s null-callback branch (the if ( $on_boot_callback ) guard is skipped).
		$this->assertSame( $this->plugin, $this->plugin->run() );
	}

	public function test_run_invokes_the_callback_with_the_plugin_and_returns_it(): void {
		// Covers run()'s truthy-callback branch: the guard passes and the callback is
		// invoked with $this as its argument. Kept minimal (no autoload) so this file
		// owns the callback-guard branch without duplicating ContainerTest's autoload run.
		$received = null;

		$returned = $this->plugin->run(
			function ( Plugin $plugin ) use ( &$received ): void {
				$received = $plugin;
			}
		);

		$this->assertSame( $this->plugin, $received, 'run() passes this plugin to the callback.' );
		$this->assertSame( $this->plugin, $returned, 'run() returns the plugin for chaining.' );
	}
}

/**
 * A module for the bootstrap test to declare: being a Module is what makes
 * run() build it, which is the behaviour under test.
 */
final class BootstrapProbe extends \Zestry\WPToolkit\Kernel\Abstracts\Module {

	/**
	 * How many times boot() has run across the whole test run.
	 *
	 * Counted rather than flagged, so a test can tell "never built" from
	 * "built once" from "built twice".
	 *
	 * @var int
	 */
	public static int $boots = 0;

	protected function on_boot(): void {
		++self::$boots;
	}
}

/**
 * Two modules that record the order they booted in, for the ordering test.
 */
final class OrderProbeOne extends \Zestry\WPToolkit\Kernel\Abstracts\Module {

	protected function on_boot(): void {
		$GLOBALS['zestry_boot_order'][] = 'one';
	}
}

/**
 * The second of the pair.
 */
final class OrderProbeTwo extends \Zestry\WPToolkit\Kernel\Abstracts\Module {

	protected function on_boot(): void {
		$GLOBALS['zestry_boot_order'][] = 'two';
	}
}

/**
 * A module whose boot fails, for the blast-radius test.
 */
final class ThrowingProbe extends \Zestry\WPToolkit\Kernel\Abstracts\Module {

	protected function on_boot(): void {
		throw new \Zestry\WPToolkit\Kernel\Exceptions\ModuleException( 'boot refused' );
	}
}

/**
 * A module whose boot throws something the toolkit does not own, for the test
 * that a consumer's own exception is not reclassified on its way out.
 */
final class ForeignThrowingProbe extends \Zestry\WPToolkit\Kernel\Abstracts\Module {

	protected function on_boot(): void {
		throw new \RuntimeException( 'my own bug' );
	}
}
