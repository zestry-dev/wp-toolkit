<?php

/**
 * Core API: Service base class
 */

declare( strict_types=1 );

namespace Zestry\WPToolkit\Kernel\Abstracts;

// Loaded by WordPress, never requested directly.
\defined( 'ABSPATH' ) || exit;

use Zestry\WPToolkit\Kernel\Contracts\PluginAware;
use Zestry\WPToolkit\Kernel\Traits\WithPlugin;

/**
 * Base class for something the plugin builds on demand.
 *
 * A service does nothing on its own. It is built the first time something asks
 * for it -- a `$plugin->get()` call, or another class declaring a property of
 * its type -- and does its work only when called. `Path` resolves a path when
 * asked; `Views` renders when asked; nothing happens until then.
 *
 * That is the whole distinction from {@see Module}, which acts under its own
 * steam and therefore has to be built whether or not anyone asks. If a class
 * needs to bind a hook, register a post type, schedule a job -- anything at all
 * that happens without being called -- it is a Module, not a Service.
 *
 * A service never appears in `bootstrap.php`. That file is modules only, and
 * listing one is what builds it -- which a service does not need, since being
 * resolved on demand is the whole of its lifecycle.
 *
 * **Your class may not declare a constructor.** `__construct()` is `final` here
 * and takes no arguments, so every service and every module is built as
 * `new YourClass()` and a subclass that declares its own constructor is a fatal
 * error. Instead:
 *
 * - **Dependencies** are typed properties, injected before any of your code
 *   runs (see below).
 * - **Configuration** comes from `configure()` in your entry file, or -- for a
 *   module -- from the initializer in `bootstrap.php`.
 * - **A class that needs constructor arguments** is a value object, not a
 *   service. Write it as a plain class; if it also needs the plugin, have it
 *   `use WithPlugin` and pass it through `$plugin->wire( $object )`.
 *
 * @example A service
 * Declare a public or protected property typed as another Service or Module and
 * it is injected before any of this class's own code runs.
 *
 * ```
 * namespace Acme\Plugin\Services;
 *
 * use Acme\Plugin\Core\Kernel\Abstracts\Service;
 *
 * class Cache extends Service {
 *
 *     public Path $path;
 *
 *     public function remember( string $key, callable $compute ): mixed {
 *         // ...
 *     }
 * }
 * ```
 *
 * @example Configuring one
 * A service that takes configuration gets it from `configure()` in your entry
 * file. The callback runs when the service is first built, before anything else
 * uses it -- and never at all if nothing ever asks for it.
 *
 * ```
 * // acme-plugin.php
 * ( new Plugin( __FILE__ ) )
 *     ->configure(
 *         DB::class,
 *         static function ( DB $db ): void {
 *             $db->set_table_prefix( 'acme' );
 *         }
 *     )
 *     ->bootstrap()
 *     ->run();
 * ```
 *
 * @example Which properties get injected
 * A property is injected when it is `public` or `protected` and its type is a
 * single class name that extends Service or Module -- `?Path $path` included,
 * since a nullable type still names one class. Everything else is left alone as
 * your own state: scalars, union and intersection types, untyped properties,
 * and classes that are neither kind.
 *
 * Injection assigns the property outright, so a declared default is replaced
 * rather than respected. That happens once, when the object is wired, before
 * any of your own code runs.
 *
 * Two cases fail quietly, so check them first when a property is not there:
 *
 * - **`private` is never injected.** Reflection cannot reach a private property
 *   declared on an ancestor class, so injecting it would work on the declaring
 *   class and silently stop working in every subclass. Rather than work
 *   sometimes, it never does. A private property typed as a service is simply
 *   never set, and PHP throws on the first read of an uninitialised typed
 *   property -- an error that points at the read, not at the declaration.
 * - **`#[NoInject]`** opts a property out, for one you mean to assign yourself.
 *
 * ```
 * use Acme\Plugin\Core\Kernel\Attributes\NoInject;
 *
 * class Reports extends Service {
 *
 *     public Path $path;          // injected
 *     protected Views $views;     // injected
 *     private DB $db;             // NEVER injected -- make it public or protected
 *
 *     #[NoInject]
 *     public Path $override;      // skipped; yours to assign
 *
 *     public string $format = 'csv';   // not a service, left alone
 * }
 * ```
 *
 * The same rules apply to anything wired outside the lifecycle -- a `Command`,
 * an `AjaxAction`, a `Route` -- since all of them run through the same
 * `_inject_services()` pass.
 */
abstract class Service implements PluginAware {

	use WithPlugin;

	/**
	 * Build with no arguments, and stop a subclass declaring a constructor.
	 *
	 * The repository always constructs with no arguments and assigns the plugin
	 * and injected properties afterwards. `final` is what holds that: a subclass
	 * declaring its own constructor is a fatal error, so none can take
	 * constructor arguments or run setup before wiring has happened. Anything
	 * that needs to run after wiring goes in an initializer, or -- if it should
	 * run without being asked -- makes the class a {@see Module} instead.
	 *
	 * A class that genuinely needs constructor arguments is a value object
	 * rather than a service: make it a plain class, and if it also needs the
	 * plugin, have it implement {@see PluginAware}, `use WithPlugin`, and pass
	 * it through `$plugin->wire( $object )`.
	 *
	 * @return void
	 */
	// @codeCoverageIgnoreStart
	final public function __construct() {
	}
	// @codeCoverageIgnoreEnd
}
