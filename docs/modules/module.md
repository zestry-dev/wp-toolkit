<!--
    Generated from src/Kernel/Abstracts/Module.php.
    Do not edit by hand: run `composer docs` after changing the source.
-->

# Module

[One that only works when called](#one-that-only-works-when-called) &nbsp;·&nbsp; [One that acts on its own](#one-that-acts-on-its-own) &nbsp;·&nbsp; [One that takes configuration](#one-that-takes-configuration) &nbsp;·&nbsp; [Doing something on `init`](#doing-something-on-init) &nbsp;·&nbsp; [Methods](#methods)

Base class for everything a plugin is made of.

`Path` resolves paths, `Options` stores settings, `Ajax` binds hooks: all three extend this, are listed in `bootstrap.php`, are built by the plugin and are reached the same way.

**Listing it in `bootstrap.php` is what makes it exist.** Nothing else builds a module, and asking for one that is not listed throws rather than quietly constructing it — so that file is the whole inventory of what a plugin is made of, and reading it tells you what the plugin has.

```php
$path = $this->with( Path::class );
```

`WithPlugin::with()` is how a module reaches another, and how a discovered file reaches any of them. There is nothing to construct and nothing to declare in advance.

**Implement `Bootable` to do something without being called.** It goes on the line that names the class, so what a module does unasked is visible before you read the body: a `Bootable` module binds hooks, registers a post type or walks a directory when the plugin builds it, and one without it sits there until something calls it. It is also what decides where the module's `bootstrap.php` entry goes — under the hook it acts on, rather than at the top level.

**Your class may not declare a constructor.** `__construct()` is `final` here and takes no arguments, so every module is built as `new YourModule()`. Configuration comes from the callback its `bootstrap.php` entry names, and dependencies from `with()`. A class that genuinely needs constructor arguments is a value object rather than a module: write it as a plain class, and if it also needs the plugin, have it `use WithPlugin` and pass it through `$plugin->wire( $object )`.

## One that only works when called

No `Bootable`, so nothing happens until something calls it.

```php
namespace Acme\Plugin\Modules;

use Acme\Plugin\Core\Kernel\Abstracts\Module;
use Acme\Plugin\Core\Modules\Path;

class Cache extends Module {

    public function remember( string $key, callable $compute ): mixed {
        $file = $this->with( Path::class )->get_plugin_path( 'cache/' . $key );

        // ...
    }
}

// bootstrap.php
return array(
    Cache::class,
);
```

## One that acts on its own

`on_boot()` runs once, when the plugin builds the module — and a module that acts is listed under the hook it acts on, which is what decides when that is. Left at the top level it throws.

```php
use Acme\Plugin\Core\Kernel\Abstracts\Module;
use Acme\Plugin\Core\Kernel\Contracts\Bootable;

class Shortcode extends Module implements Bootable {

    public function on_boot(): void {
        add_shortcode( 'acme_form', array( $this, 'render' ) );
    }
}

// bootstrap.php
return array(
    'acme_plugin_loaded' => array(
        Shortcode::class,
    ),
);
```

## One that takes configuration

A class entry's value is the callback that configures it, run after the module is built and before `on_boot()` — so `on_boot()` can rely on whatever it set. A module needing no configuration stays bare, as `CLI::class` does here.

```php
// bootstrap.php
return array(
    'init' => array(
        Cron::class => static function ( Cron $cron ): void {
            $cron->add_custom_interval( 'every_15_minutes', 900, 'Every 15 Minutes' );
        },
        CLI::class,
    ),
);
```

## Doing something on `init`

Almost everything WordPress wants registered — a post type, a block, a taxonomy — has to be registered on `init`, and a module can be built on either side of it: an entry file that runs the plugin as it loads is ahead of `init`, one that runs it from a later hook is behind. `on_wp_init()` behaves the same either way, so a module never has to care which, and a plain `add_action( 'init', ... )` would silently never run in the second case.

It is also the answer to `_load_textdomain_just_in_time`: a `__()` at plugin load asks WordPress for translations before it is ready to give them.

```php
public function on_boot(): void {
    $this->on_wp_init( function ( self $module ): void {
        register_post_type( 'acme_report', array(
            'label' => __( 'Reports', 'acme-plugin' ),
        ) );
    } );
}
```

## Methods

### `on_wp_init( $callback, $priority )`

Run a callback on `init`, or immediately if `init` has already fired.

```php
final public function on_wp_init( callable $callback, int $priority = 10 ): void
```

|  | Details |
|---|---|
| **Parameters** | `$callback` — What to run<br>`$priority` — WordPress hook priority, honoured only while `init` is still ahead |
| **Return** | — |
| **Throws** | — |

Almost everything a module registers — a post type, a block, a WP-CLI command — has to happen on `init`, and a plain `add_action( 'init', ... )` is a callback that never runs once `init` has passed. A module can be built on either side of it: `Plugin::run()` is synchronous, so an entry file that calls it at plugin load is ahead of `init`, while one that calls it from a later hook is behind. This behaves the same either way, so a module never has to care which.

The callback receives the module, so a closure declared elsewhere needs no `use` to reach it:

```php
public function on_boot(): void {
    $this->on_wp_init( function ( self $module ): void {
        $module->register_widgets();
    } );
}
```

`$priority` is WordPress's own, for ordering against something else on `init` — another plugin's registration, or a post type a taxonomy of yours attaches to. **It applies only while `init` is still ahead**: a module built after `init` has fired runs its callback immediately, in registration order, whatever priority it asked for. Ordering that has to hold either way belongs inside one callback.

<br>

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

For the plugin's own answers — its slug, its entry file, the headers it declares. To reach another module, `with()` is shorter and says what it is doing.

<br>

### `with( $name )`

*Inherited from [`WithPlugin`](../kernel/with-plugin.md).*

Reach another module.

```php
final public function with( string $name ): object
```

|  | Details |
|---|---|
| **Parameters** | `$name` — The module class to reach |
| **Return** | The shared instance |
| **Throws** | `ModuleException` — If it is not declared, or has not booted yet |

The one way anything in a plugin reaches anything else. Returns the same instance every time, so two callers asking for `Options` share its state:

```php
$this->with( Options::class )->get( 'api_key' );
```

**The module has to be listed in `bootstrap.php`.** Asking for one that is not throws, naming the class and the file to add it to — nothing is built because something asked for it, so that file stays the whole inventory of what the plugin is made of.

A module listed under a heading also throws when asked for before that hook has fired, since building it early would bind it on the wrong side of whatever it was declared to follow.
