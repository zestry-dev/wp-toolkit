<!--
    Generated from src/Kernel/Abstracts/Module.php.
    Do not edit by hand: run `composer docs` after changing the source.
-->

# Module

[A module](#a-module) &nbsp;·&nbsp; [One that takes configuration](#one-that-takes-configuration) &nbsp;·&nbsp; [Doing something on `init`](#doing-something-on-init) &nbsp;·&nbsp; [You must implement](#you-must-implement) &nbsp;·&nbsp; [Methods you can use](#methods-you-can-use)

Base class for something that acts on its own.

A module does something without being called: it binds a hook, registers a post type, walks a directory, schedules a job. That is the whole distinction from `Service`, which sits there until something asks it for something.

Because it acts on its own, it has to be built for that to happen — so every module is listed in `bootstrap.php`, and the plugin resolves each one as it loads. `on_boot()` then runs, once, and is where the acting-on-its-own goes. Nothing has to be said about *when*: being a module is the declaration.

`Options` is the case worth understanding. It is something you call — `$options->get( 'key' )` — which makes it look like a service. But it also loads its persisted values and binds `shutdown` to flush deferred writes, without being asked. That is acting on its own, so it is a module.

Everything `Service` says about construction applies here too: your class may not declare a constructor, since `__construct()` is `final` and takes no arguments. Dependencies arrive as injected typed properties, and configuration from the initializer in `bootstrap.php`.

## A module

`on_boot()` is abstract, so a module cannot be written without saying what it does at boot. Listing it in `bootstrap.php` is what builds it.

```php
namespace Acme\Plugin\Modules;

use Acme\Plugin\Core\Kernel\Abstracts\Module;

class Shortcode extends Module {

    protected function on_boot(): void {
        add_shortcode( 'acme_form', array( $this, 'render' ) );
    }
}

// bootstrap.php
return array(
    Shortcode::class,
);
```

## One that takes configuration

The entry's value is the initializer, which runs after wiring and before `on_boot()` — so `on_boot()` can rely on whatever it set.

```php
// bootstrap.php
return array(
    Ajax::class => static function ( Ajax $ajax ): void {
        $ajax->set_actions_root( 'actions' );
    },
    CLI::class,
);
```

## Doing something on `init`

Almost everything WordPress wants registered — a post type, a block, a taxonomy — has to be registered on `init`, and a module can be built on either side of it: an entry file that runs the plugin as it loads is ahead of `init`, one that runs it from a later hook is behind. `on_wp_init()` behaves the same either way, so a module never has to care which, and a plain `add_action( 'init', ... )` would silently never run in the second case.

It is also the answer to `_load_textdomain_just_in_time`: a `__()` at plugin load asks WordPress for translations before it is ready to give them.

```php
protected function on_boot(): void {
    $this->on_wp_init( function ( self $module ): void {
        register_post_type( 'acme_report', array(
            'label' => __( 'Reports', 'acme-plugin' ),
        ) );
    } );
}
```

## You must implement

This one method is abstract: a subclass that does not declare it will not load.

### `on_boot()`

What this module does on its own.

```php
abstract protected function on_boot(): void
```

Runs once, when the plugin builds the module. Abstract rather than optional: a module with nothing to do here is a `Service`.

**Bind hooks here; do the work in them.** An entry file that calls `run()` as it loads — which is the documented shape, and what `ActivationHandler` requires — reaches this before WordPress has required `pluggable.php`, so there is no current user yet: `current_user_can()`, `wp_mail()` and the nonce functions are not defined and calling one is a fatal. It is also before `init`, so `__()` here asks for a text domain nothing has loaded. `$wpdb` *is* up, so a query works — but it runs on every request, including the ones that never needed it.

`on_wp_init()` is the way out of all three, and where anything a module registers belongs.

## Methods you can use

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

Almost everything a module registers — a post type, a block, a WP-CLI command — has to happen on `init`, and a plain `add_action( 'init', ... )` is a callback that never runs once `init` has passed. A module can be resolved on either side of it: `Plugin::run()` is synchronous, so an entry file that calls it at plugin load is ahead of `init`, while one that calls it from a later hook — or a `get()` during a request — is behind. This behaves the same either way, so a module never has to care which.

The callback receives the module, matching the initializer signature, so a closure declared elsewhere needs no `use` to reach it:

```php
protected function on_boot(): void {
    $this->on_wp_init( function ( self $module ): void {
        $module->register_widgets();
    } );
}
```

`$priority` is WordPress's own, for ordering against something else on `init` — another plugin's registration, or a post type a taxonomy of yours attaches to. **It applies only when `init` is still ahead**, which is the case for the documented entry file, since `run()` at plugin load is well before `init`. A module resolved *after* `init` has fired runs its callback immediately, because there is no longer a queue to be ordered in — so two callbacks registered then run in the order they were registered, whatever priority each asked for. Ordering that has to hold in both cases belongs inside one callback.

<br>

### `get_plugin()`

Get the plugin this class belongs to.

```php
final public function get_plugin(): Plugin
```

|  | Details |
|---|---|
| **Parameters** | — |
| **Return** | The plugin instance |
| **Throws** | — |

Use it to reach something you did not declare a property for — a module you need in one method only, or one you look up by a name computed at runtime. For anything you use throughout the class, declare a typed property instead and let it be injected.

```php
$this->get_plugin()->get( Options::class )->get( 'api_key' );
```
