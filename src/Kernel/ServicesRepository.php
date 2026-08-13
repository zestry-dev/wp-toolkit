<?php

/**
 * Core API: ServicesRepository resolution engine
 */

declare( strict_types=1 );

namespace Zestry\WPToolkit\Kernel;

// Loaded by WordPress, never requested directly.
\defined( 'ABSPATH' ) || exit;

use Zestry\WPToolkit\Kernel\Abstracts\Module;
use Zestry\WPToolkit\Kernel\Abstracts\Service;
use Zestry\WPToolkit\Kernel\Contracts\PluginAware;
use Zestry\WPToolkit\Kernel\Exceptions\CircularDependencyException;
use Zestry\WPToolkit\Kernel\Exceptions\ModuleException;
use Zestry\WPToolkit\Kernel\Exceptions\ModuleNotFoundException;

/**
 * Stores initializers and lazily resolves service and module instances.
 *
 * This separates the plugin's public API from the resolution lifecycle. It
 * caches each resolved instance by class name, initializes it with the shared
 * plugin, and runs its initializer callback exactly once.
 *
 * Both kinds go through the same pipeline -- a {@see Module} is a
 * {@see Service} that also acts on its own -- regardless of whether the class
 * was configured beforehand, queued in `bootstrap.php`, or resolved on demand
 * as a dependency of something else:
 *
 * ```
 * configure() - remember an initializer callback for later (optional)
 * get()       - first call triggers resolution; later calls hit the cache
 * instantiate - construct the object, wire it, cache it (if singleton),
 *               run its initializer/configurator, then boot() it if it is
 *               a Module
 * ```
 *
 * configure() only stores a callback; nothing is constructed until get() (or
 * make()) is called for that class name, so the order classes are configured
 * in does not need to match the order they are actually needed in. Once
 * resolved, a singleton is cached for the lifetime of the repository and every
 * subsequent get() call for that class returns the same instance.
 */
class ServicesRepository {

	/**
	 * Plugin supplied to every resolved instance.
	 *
	 * @var Plugin
	 */
	private Plugin $plugin;

	/**
	 * Service and module instances indexed by fully qualified class name.
	 *
	 * @var array<class-string, Service>
	 */
	private array $resolved = array();

	/**
	 * Initializer callbacks indexed by the service or module class they configure.
	 *
	 * @var array<class-string, callable(Service $instance, Plugin $plugin): void>
	 */
	private array $registered = array();

	/**
	 * Class names queued to resolve when the plugin's run() is called.
	 *
	 * Modules in practice -- queueing a service only builds it sooner than
	 * something asking for it would have.
	 *
	 * @var class-string[]
	 */
	private array $to_autoload = array();

	/**
	 * Class names currently being resolved, used to detect circular dependencies.
	 *
	 * @var array<class-string, true>
	 */
	private array $resolving = array();

	/**
	 * Construct the repository for a single plugin.
	 *
	 * @param Plugin $plugin The plugin this repository resolves modules for.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Remember an initializer to run when a class is first built.
	 *
	 * Only a class needing custom setup has to be configured; any other resolves
	 * through get() without an initializer.
	 *
	 * @template T of object
	 * @param class-string<T> $name The class name to configure.
	 * @param callable(T $instance, Plugin $plugin): void $initializer Callback receiving the instance and plugin.
	 * @return void
	 * @throws ModuleException If the initializer is not callable (when provided).
	 */
	public function configure( string $name, callable $initializer ): void {
		// Defensive only, hence the coverage markers: the native `callable` hint
		// already rejects a non-callable with a TypeError for any statically
		// typed caller, so this is reachable only via dynamic invocation.
		// @codeCoverageIgnoreStart
		if ( ! \is_callable( $initializer ) ) {
			throw new ModuleException( 'Module initializer must be callable.' );
		}
		// @codeCoverageIgnoreEnd

		$this->registered[ $name ] = $initializer;
	}

	/**
	 * Get the given service or module from the plugin.
	 *
	 * Cached after the first call. The instance is wired and its initializer run
	 * before it is returned; see instantiate() for the full pipeline, including
	 * why the cache is populated before boot().
	 *
	 * @template T of object
	 * @param class-string<T> $name The class name to resolve.
	 * @return T The resolved instance.
	 * @throws \Exception If the class does not exist, validation fails, or a circular dependency is detected.
	 */
	public function get( string $name ): object {
		$this->validate_module_class( $name );

		if ( isset( $this->resolved[ $name ] ) ) {
			return $this->resolved[ $name ];
		}

		// Cache the singleton so that dependencies resolved during its own boot
		// (for example an AJAX action wired while the Ajax module boots) receive
		// the in-flight instance instead of triggering a false circular-dependency.
		return $this->instantiate( $name, null, true );
	}

	/**
	 * Build a fresh, fully wired instance without caching it.
	 *
	 * Never cached, unlike get(). Use it for a second instance of a module (an
	 * Options group) or to wire a file-loaded object. The configurator runs
	 * after wiring and before boot(), so it can set what boot() depends on.
	 *
	 * @template T of object
	 * @param class-string<T> $name The class name to construct.
	 * @param callable(T $instance, Plugin $plugin): void|null $configurator Optional callback receiving the instance and plugin before boot.
	 * @return T A new, wired instance.
	 * @throws \Exception If the class does not exist, does not extend Service, or re-enters its own resolution.
	 */
	public function make( string $name, ?callable $configurator = null ): object {
		$this->validate_module_class( $name );
		return $this->instantiate( $name, $configurator, false );
	}

	/**
	 * Assign the plugin and inject declared dependencies into an instance.
	 *
	 * The single wiring routine behind singleton resolution, make() and
	 * file-loaded handlers, so a command or action can declare typed Service
	 * properties and behave like a module without being cached.
	 *
	 * @template T of PluginAware
	 * @param T $instance The object to wire.
	 * @return T The same instance, now wired.
	 */
	public function wire( PluginAware $instance ): PluginAware {
		$instance->set_plugin( $this->plugin );
		$instance->inject_modules();
		return $instance;
	}

	/**
	 * Queue a module to resolve when the plugin's run() is called.
	 *
	 * Duplicate class names are ignored, so multiple setup paths can request the
	 * same module without running its initializer twice.
	 *
	 * @param class-string $name Module class name.
	 * @return $this
	 */
	public function set_autoload( string $name ): self {
		if ( ! \in_array( $name, $this->to_autoload, true ) ) {
			$this->to_autoload[] = $name;
		}

		return $this;
	}

	/**
	 * Resolve every module queued for automatic loading.
	 *
	 * Called by `Plugin::run()`, synchronously -- the entry file decides when
	 * that happens rather than this waiting on a hook of its own.
	 *
	 * @return void
	 */
	public function run_autoload(): void {
		foreach ( $this->to_autoload as $name ) {
			$this->get( $name );
		}
	}

	/**
	 * Validate that a class exists and extends Service.
	 *
	 * Runs before every instantiation, on both kinds: a Module is a Service that
	 * also acts on its own, so one check covers both.
	 *
	 * @param string $name The class name to validate.
	 * @return void
	 * @throws ModuleNotFoundException If the class does not exist or does not extend Service.
	 */
	private function validate_module_class( string $name ): void {
		if ( ! \class_exists( $name ) ) {
			throw new ModuleNotFoundException( "Class {$name} does not exist." );
		}

		// Service, not Module: the repository resolves both, and a Module is a
		// Service that also acts on its own.
		if ( ! \is_subclass_of( $name, Service::class ) ) {
			throw new ModuleNotFoundException(
				\sprintf(
					'Class %s must extend %s.',
					$name,
					Service::class
				)
			);
		}
	}

	/**
	 * Instantiate and initialize a service or module.
	 *
	 * Construct -> wire -> cache (when $cache) -> run the configurator or the
	 * registered initializer -> boot() if it is a Module. $cache is true on the
	 * singleton get() path and false for make(), which never caches.
	 *
	 * @template T of object
	 * @param class-string<T> $name The class name to instantiate.
	 * @param callable(T $instance, Plugin $plugin): void|null $configurator Optional callback run after wiring, before boot.
	 * @param bool $cache Whether to publish the wired instance to the singleton cache before booting.
	 * @return T The instantiated and initialized instance.
	 * @throws CircularDependencyException If a circular dependency is detected or resolution fails.
	 */
	private function instantiate( string $name, ?callable $configurator = null, bool $cache = false ): object {
		// No dependency graph is ever built: cycle detection rides the PHP call
		// stack. wire() -> inject_modules() -> get() -> instantiate() recurses
		// per typed property, and $resolving marks a class for the duration of
		// its own call, so re-entry means a cycle rather than a stack overflow.
		if ( isset( $this->resolving[ $name ] ) ) {
			throw new CircularDependencyException(
				\sprintf(
					'Circular module dependency detected: %s -> %s.',
					\implode( ' -> ', \array_keys( $this->resolving ) ),
					$name
				)
			);
		}

		$this->resolving[ $name ] = true;

		try {
			// Modules have final, no-argument constructors; wire services afterward.
			$instance = $this->wire( new $name() );

			if ( $cache ) {
				// Publish before boot so self- or sibling-references resolved during
				// this module's boot get the in-flight instance, not a false cycle.
				$this->resolved[ $name ] = $instance;
			}

			// An explicit configurator (make()) takes precedence over the registered
			// initializer so callers can configure one-off instances before boot.
			$callback = $configurator ?? $this->registered[ $name ] ?? null;

			if ( $callback !== null ) {
				$callback( $instance, $this->plugin );
			}

			if ( $instance instanceof Module && ! $instance->is_booted() ) {
				// Boot once the module is fully configured, unless the callback
				// already booted it (for example to pass a custom configuration).
				$instance->boot();
			}
		} catch ( \Throwable $e ) {
			if ( $cache ) {
				// Roll back the partial publish so a failed resolution is retryable.
				unset( $this->resolved[ $name ] );
			}
			throw $e;
		} finally {
			// Always clear the guard so a failed resolution does not poison later calls.
			unset( $this->resolving[ $name ] );
		}

		return $instance;
	}
}
