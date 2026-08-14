<!--
    Generated from src/Kernel/Abstracts/Service.php.
    Do not edit by hand: run `composer docs` after changing the source.
-->

# Service

[A service](#a-service) &nbsp;·&nbsp; [Configuring one](#configuring-one) &nbsp;·&nbsp; [Which properties get injected](#which-properties-get-injected) &nbsp;·&nbsp; [Methods](#methods)

Base class for something the plugin builds on demand.

A service does nothing on its own. It is built the first time something asks for it — a `$plugin->get()` call, or another class declaring a property of its type — and does its work only when called. `Path` resolves a path when asked; `Views` renders when asked; nothing happens until then.

That is the whole distinction from `Module`, which acts under its own steam and therefore has to be built whether or not anyone asks. If a class needs to bind a hook, register a post type, schedule a job — anything at all that happens without being called — it is a Module, not a Service.

A service never appears in `bootstrap.php`. That file is modules only, and listing one is what builds it — which a service does not need, since being resolved on demand is the whole of its lifecycle.

**Your class may not declare a constructor.** `__construct()` is `final` here and takes no arguments, so every service and every module is built as `new YourClass()` and a subclass that declares its own constructor is a fatal error. Instead:

- **Dependencies** are typed properties, injected before any of your code
runs (see below).
- **Configuration** comes from `configure()` in your entry file, or — for a
module — from the `before_boot` in its `bootstrap.php` entry.
- **A class that needs constructor arguments** is a value object, not a
service. Write it as a plain class; if it also needs the plugin, have it `use WithPlugin` and pass it through `$plugin->wire( $object )`.

## A service

Declare a public or protected property typed as another Service or Module and it is injected before any of this class's own code runs.

```php
namespace Acme\Plugin\Services;

use Acme\Plugin\Core\Kernel\Abstracts\Service;

class Cache extends Service {

    public Path $path;

    public function remember( string $key, callable $compute ): mixed {
        // ...
    }
}
```

## Configuring one

A service that takes configuration gets it from `configure()` in your entry file. The callback runs when the service is first built, before anything else uses it — and never at all if nothing ever asks for it.

```php
// acme-plugin.php
( new Plugin( __FILE__ ) )
    ->configure(
        DB::class,
        static function ( DB $db ): void {
            $db->set_table_prefix( 'acme' );
        }
    )
    ->bootstrap()
    ->run();
```

## Which properties get injected

A property is injected when it is `public` or `protected` and its type is a single class name that extends Service — `?Path $path` included, since a nullable type still names one class. Everything else is left alone as your own state: scalars, union and intersection types, untyped properties, and classes that are not services.

Injection assigns the property outright, so a declared default is replaced rather than respected. That happens once, when the object is wired, before any of your own code runs.

**A property typed as a Module is refused, not injected.** Building a module boots it — it binds hooks, walks a directory, registers things with WordPress — and a property declaration hides all of that behind a type name. Ask for one where you need it instead, which puts the cost where a reader can see it: `$this->get_plugin()->get( Options::class )`. Declaring the property throws a `ModuleException` naming the property and the call to use.

Two cases fail quietly, so check them first when a property is not there:

- **`private` is never injected.** Reflection cannot reach a private property
declared on an ancestor class, so injecting it would work on the declaring class and silently stop working in every subclass. Rather than work sometimes, it never does. A private property typed as a service is simply never set, and PHP throws on the first read of an uninitialised typed property — an error that points at the read, not at the declaration.
- **`#[NoInject]`** opts a property out, for one you mean to assign yourself.

```php
use Acme\Plugin\Core\Kernel\Attributes\NoInject;

class Reports extends Service {

    public Path $path;          // injected
    protected Views $views;     // injected
    private DB $db;             // NEVER injected -- make it public or protected
    public Options $options;    // THROWS -- Options is a module; get() it instead

    #[NoInject]
    public Path $override;      // skipped; yours to assign

    public string $format = 'csv';   // not a service, left alone
}
```

The same rules apply to anything wired outside the lifecycle — a `Command`, an `AjaxAction`, a `Route` — since all of them run through the same `_inject_services()` pass.

## Methods

### `get_plugin()`

*Inherited from [`WithPlugin`](../kernel/with-plugin.md).*

Get the plugin this class belongs to.

```php
final public function get_plugin(): Plugin
```

|  | Details |
|---|---|
| **Parameters** | — |
| **Return** | The plugin instance |
| **Throws** | — |

How you reach a module, always: building one boots it, so the cost belongs at the call rather than hidden in a property declaration. Also how you reach a service you look up by a name computed at runtime.

```php
$this->get_plugin()->get( Options::class )->get( 'api_key' );
```
