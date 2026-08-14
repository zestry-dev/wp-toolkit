<?php

/**
 * Core API: ModulesRepository resolution engine
 */

declare( strict_types=1 );

namespace Zestry\WPToolkit\Kernel;

// Loaded by WordPress, never requested directly.
\defined( 'ABSPATH' ) || exit;

use Zestry\WPToolkit\Kernel\Abstracts\Module;
use Zestry\WPToolkit\Kernel\Contracts\Bootable;
use Zestry\WPToolkit\Kernel\Contracts\PluginAware;
use Zestry\WPToolkit\Kernel\Exceptions\CircularDependencyException;
use Zestry\WPToolkit\Kernel\Exceptions\ModuleException;
use Zestry\WPToolkit\Kernel\Exceptions\ModuleNotFoundException;

/**
 * Builds and holds the modules a `bootstrap.php` declares.
 *
 * Every module the plugin has is declared, and nothing else is ever built:
 *
 * ```
 * declare_module() - remember a class name, and its configuration (from bootstrap.php)
 * run()            - build each declared class in order, booting as it goes
 * get()            - hand back a built instance, building it if resolution reached
 *                    it first; refuse anything undeclared
 * ```
 *
 * A module reached during another's boot is built then rather than waiting for
 * its turn in the list, so declaration order is not something a plugin has to
 * get right. What order *does* decide is when `on_boot()` runs relative to its
 * neighbours', which is what `boots_on` is for when it matters.
 */
class ModulesRepository {

	/**
	 * Plugin supplied to every module.
	 *
	 * @var Plugin
	 */
	private Plugin $plugin;

	/**
	 * Module instances indexed by fully qualified class name.
	 *
	 * @var array<class-string, Module>
	 */
	private array $built = array();

	/**
	 * Every class name `bootstrap.php` declared, in the order it declared them.
	 *
	 * @var array<class-string, true>
	 */
	private array $declared = array();

	/**
	 * Configuration callbacks indexed by the module class they configure.
	 *
	 * @var array<class-string, callable(Module $instance, Plugin $plugin): void>
	 */
	private array $configurators = array();

	/**
	 * Class names currently being built, used to detect circular dependencies.
	 *
	 * @var array<class-string, true>
	 */
	private array $building = array();

	/**
	 * Boot hooks the bootstrap entries named, keyed by class.
	 *
	 * @var array<class-string, array{hook: string|null, priority: int|null}>
	 */
	private array $boot_hooks = array();

	/**
	 * Modules waiting for their hook, and the hook each is waiting for.
	 *
	 * Read by {@see get()} so asking for one too early says which hook it is
	 * waiting on rather than quietly building it ahead of time.
	 *
	 * @var array<class-string, string>
	 */
	private array $deferred = array();

	/**
	 * Construct the repository for a single plugin.
	 *
	 * @param Plugin $plugin The plugin this repository builds modules for.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Declare a module, so the plugin may build it.
	 *
	 * Declaring is what makes a module exist: {@see get()} refuses a class that
	 * has not been through here. Declaring the same class twice is ignored, so
	 * two setup paths can name it without configuring it twice.
	 *
	 * `$hook` is when the plugin builds it, and may carry its own priority as
	 * `init:20`. Left null the module is built as {@see run()} reaches it, which
	 * is what a module that does nothing on its own wants.
	 *
	 * @param class-string $name     Module class name.
	 * @param string|null  $hook     The hook to build it on, optionally `hook:priority`.
	 * @param int          $priority The priority, when the hook does not carry one.
	 * @return $this
	 */
	public function declare_module( string $name, ?string $hook = null, int $priority = 10 ): self {
		$this->declared[ $name ] ??= true;

		if ( null !== $hook ) {
			[ $parsed, $parsed_priority ] = self::parse_hook( $hook, $priority );

			$this->set_boot_hook( $name, $parsed, $parsed_priority );
		}

		return $this;
	}

	/**
	 * Remember a callback to run when a module is built.
	 *
	 * Runs after the module is constructed and before `on_boot()`, so it can set
	 * what boot depends on.
	 *
	 * @template T of object
	 * @param class-string<T> $name         The class name to configure.
	 * @param callable(T $instance, Plugin $plugin): void $configurator Callback receiving the instance and plugin.
	 * @return void
	 */
	public function configure( string $name, callable $configurator ): void {
		$this->configurators[ $name ] = $configurator;
	}

	/**
	 * Name the hook a declared module boots on.
	 *
	 * Called by {@see Plugin::bootstrap()} for an entry that declared `boots_on`.
	 *
	 * @param class-string $name     Module class name.
	 * @param string|null  $hook     The hook to boot on, or null to boot as the plugin loads.
	 * @param int|null     $priority The priority, or null for WordPress's default.
	 * @return $this
	 */
	public function set_boot_hook( string $name, ?string $hook, ?int $priority = null ): self {
		$this->boot_hooks[ $name ] = array(
			'hook'     => $hook,
			'priority' => $priority,
		);

		return $this;
	}

	/**
	 * Get a declared module, building it if this is the first ask.
	 *
	 * @template T of object
	 * @param class-string<T> $name The class name to resolve.
	 * @return T The shared instance.
	 * @throws ModuleException If the class was never declared, or has not booted yet.
	 * @throws ModuleNotFoundException If the class does not exist or does not extend Module.
	 * @throws CircularDependencyException If the dependency graph re-enters itself.
	 */
	public function get( string $name ): object {
		if ( isset( $this->built[ $name ] ) ) {
			return $this->built[ $name ];
		}

		// Before the declaration check, so a name that could never be a module
		// says so: "not declared" would send you to `bootstrap.php` to add a
		// class that does not exist, or one that could not be built if it did.
		$this->validate_module_class( $name );

		if ( ! isset( $this->declared[ $name ] ) ) {
			throw ModuleException::not_declared( $name, $this->plugin->get_bootstrap_file() );
		}

		/*
		 * Waiting for a hook that has not fired. Building it now would boot it
		 * early -- binding on the wrong side of the thing it was declared to
		 * follow -- so this says which hook it is waiting on instead. `run()`
		 * clears the entry as the hook fires, and a module reached during its
		 * own boot is already built above, so this cannot fire on itself.
		 */
		if ( isset( $this->deferred[ $name ] ) ) {
			throw ModuleException::not_booted_yet( $name, $this->deferred[ $name ] );
		}

		return $this->build( $name, null, true );
	}

	/**
	 * Build a fresh, uncached instance of a module class.
	 *
	 * Never shared, unlike {@see get()}: every call returns a new instance. Use
	 * it for a second instance of a module, such as a dedicated Options group.
	 * The configurator runs after construction and before boot(), so it can set
	 * what boot() depends on.
	 *
	 * Takes any module class, declared or not -- what {@see get()} guards is the
	 * *shared* instance every other caller sees, and this one is the caller's
	 * alone.
	 *
	 * @template T of object
	 * @param class-string<T> $name The class name to construct.
	 * @param callable(T $instance, Plugin $plugin): void|null $configurator Optional callback run before boot.
	 * @return T A new instance.
	 * @throws ModuleNotFoundException If the class does not exist or does not extend Module.
	 * @throws CircularDependencyException If the dependency graph re-enters itself.
	 */
	public function make( string $name, ?callable $configurator = null ): object {
		return $this->build( $name, $configurator, false );
	}

	/**
	 * Give an object the plugin, so it can reach modules through `with()`.
	 *
	 * How a class the plugin did not build -- a command, an action, a page --
	 * joins the plugin without being a module itself.
	 *
	 * @template T of PluginAware
	 * @param T $instance The object to wire.
	 * @return T The same instance, now wired.
	 */
	public function wire( PluginAware $instance ): PluginAware {
		$instance->set_plugin( $this->plugin );

		return $instance;
	}

	/**
	 * Build every declared module, in the order they were declared.
	 *
	 * Called by `Plugin::run()`, synchronously -- the entry file decides when
	 * that happens rather than this waiting on a hook of its own. A module that
	 * named a `boots_on` is held back for it instead.
	 *
	 * @return void
	 */
	public function run(): void {
		foreach ( \array_keys( $this->declared ) as $name ) {
			$this->assert_boot_timing_declared( $name );

			[ $hook, $priority ] = $this->get_boot_timing( $name );

			if ( null === $hook || \did_action( $hook ) ) {
				/*
				 * No hook, or one that has been and gone -- an entry file run
				 * from a late hook, or a test. Deferring to a hook that has
				 * fired is deferring forever, so this builds now and the
				 * declaration reads as "not before".
				 */
				$this->get( $name );
				continue;
			}

			$this->deferred[ $name ] = $hook;

			\add_action(
				$hook,
				function () use ( $name ): void {
					unset( $this->deferred[ $name ] );
					$this->get( $name );
				},
				$priority
			);
		}
	}

	/**
	 * Refuse a {@see Bootable} module whose entry never says when it boots.
	 *
	 * A `Bootable` module acts the moment it is built, so *when* it is built is
	 * the whole of what it does -- and a bare entry leaves that unanswered.
	 * Answering it by default was the alternative, and it hides the one thing
	 * worth reading: a plugin registering post types before `init` looks
	 * identical to one registering them correctly, right up until something
	 * else needs to filter them.
	 *
	 * So the entry says it, and `null` is a real answer:
	 *
	 * ```
	 * Shortcodes::class => array( 'boots_on' => 'acme-plugin-loaded' ),
	 * Activation::class => array( 'boots_on' => null ),  // as the plugin loads
	 * ```
	 *
	 * A module that is not `Bootable` does nothing when built, so it has nothing
	 * to declare and a bare entry is the whole of it.
	 *
	 * @param class-string $name The declared class name.
	 * @return void
	 * @throws ModuleException When a Bootable module's entry omits `boots_on`.
	 */
	private function assert_boot_timing_declared( string $name ): void {
		// Said something, including `null`. Nothing to enforce.
		if ( \array_key_exists( $name, $this->boot_hooks ) ) {
			return;
		}

		// Autoloads, which is why this is here and not in `Plugin::declare_multiple()`:
		// reading `bootstrap.php` compiles none of the classes it names, and
		// `run()` is about to build every one of them anyway.
		$this->validate_module_class( $name );

		if ( ! \is_a( $name, Bootable::class, true ) ) {
			return;
		}

		throw ModuleException::boot_timing_undeclared(
			$name,
			$this->plugin->get_loaded_hook(),
			$this->plugin->get_bootstrap_file()
		);
	}

	/**
	 * When a declared module boots, and at what priority.
	 *
	 * From the bootstrap entry and nowhere else. A module could hold its own
	 * default in a constant, and then a bare entry would boot on a hook for
	 * reasons the file never says -- `bootstrap.php` is where a plugin declares
	 * what it has and when each part starts, so it is the whole answer.
	 *
	 * @param class-string $name The declared class name.
	 * @return array{0: string|null, 1: int}
	 */
	private function get_boot_timing( string $name ): array {
		if ( ! \array_key_exists( $name, $this->boot_hooks ) ) {
			return array( null, 10 );
		}

		$declared = $this->boot_hooks[ $name ];

		return array( $declared['hook'], $declared['priority'] ?? 10 );
	}

	/**
	 * Validate that a class exists and extends Module.
	 *
	 * @param string $name The class name to validate.
	 * @return void
	 * @throws ModuleNotFoundException If the class does not exist or does not extend Module.
	 */
	private function validate_module_class( string $name ): void {
		if ( ! \class_exists( $name ) ) {
			throw new ModuleNotFoundException( "Class {$name} does not exist." );
		}

		if ( ! \is_subclass_of( $name, Module::class ) ) {
			throw new ModuleNotFoundException(
				\sprintf(
					'Class %s must extend %s.',
					$name,
					Module::class
				)
			);
		}
	}

	/**
	 * Construct a module, configure it, and boot it if it is {@see Bootable}.
	 *
	 * Construct -> wire -> share (when $share) -> run the configurator -> boot.
	 * $share is true on the {@see get()} path and false for {@see make()}, which
	 * never shares.
	 *
	 * @template T of object
	 * @param class-string<T> $name The class name to instantiate.
	 * @param callable(T $instance, Plugin $plugin): void|null $configurator Optional callback run before boot.
	 * @param bool $share Whether to publish the instance as the shared one before booting.
	 * @return T The built module.
	 * @throws CircularDependencyException If a circular dependency is detected.
	 */
	private function build( string $name, ?callable $configurator, bool $share ): object {
		$this->validate_module_class( $name );

		// No dependency graph is ever built: cycle detection rides the PHP call
		// stack. A module's boot may call with(), which re-enters here, and
		// $building marks a class for the duration of its own call -- so
		// re-entry means a cycle rather than a stack overflow.
		if ( isset( $this->building[ $name ] ) ) {
			throw new CircularDependencyException(
				\sprintf(
					'Circular module dependency detected: %s -> %s.',
					\implode( ' -> ', \array_keys( $this->building ) ),
					$name
				)
			);
		}

		$this->building[ $name ] = true;

		try {
			// Modules have final, no-argument constructors.
			$instance = $this->wire( new $name() );

			if ( $share ) {
				// Published before boot so a module reached during this one's
				// boot gets the in-flight instance, not a false cycle.
				$this->built[ $name ] = $instance;
			}

			// An explicit configurator (make()) takes precedence over the one
			// bootstrap.php declared, so a one-off instance can be set up
			// differently from the shared one.
			$callback = $configurator ?? $this->configurators[ $name ] ?? null;

			if ( null !== $callback ) {
				$callback( $instance, $this->plugin );
			}

			/*
			 * Once the module is fully configured, and once only: the shared
			 * instance is published above before this runs, so get() never
			 * reaches here twice for one class. There is no booted flag to keep
			 * anywhere -- a module holding one would be answering a question the
			 * repository already knows.
			 */
			if ( $instance instanceof Bootable ) {
				$instance->on_boot();
			}
		} catch ( \Throwable $e ) {
			if ( $share ) {
				// Roll back the partial publish so a failed build is retryable.
				unset( $this->built[ $name ] );
			}
			throw $e;
		} finally {
			// Always clear the guard so a failed build does not poison later calls.
			unset( $this->building[ $name ] );
		}

		return $instance;
	}

	/**
	 * Split a hook that carries its own priority.
	 *
	 * `init` is the hook at WordPress's own default; `init:20` is the same hook
	 * ordered behind everything at 10. A colon cannot appear in a hook name, so
	 * it is free to mean this -- which lets a `bootstrap.php` heading and a
	 * {@see \Zestry\WPToolkit\Kernel\Plugin::declare()} call spell the timing the same way.
	 *
	 * @param string $hook     The hook, `hook` or `hook:priority`.
	 * @param int    $priority The priority to use when the hook does not carry one.
	 * @return array{0: string, 1: int}
	 */
	public static function parse_hook( string $hook, int $priority = 10 ): array {
		if ( ! \str_contains( $hook, ':' ) ) {
			return array( $hook, $priority );
		}

		[ $name, $carried ] = \explode( ':', $hook, 2 );

		return array( $name, (int) $carried );
	}
}
