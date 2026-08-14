<!--
    Generated from src/Kernel/Abstracts/ActivationHandler.php.
    Do not edit by hand: run `composer docs` after changing the source.
-->

# ActivationHandler

[Writing one](#writing-one) &nbsp;·&nbsp; [Getting the timing right](#getting-the-timing-right) &nbsp;·&nbsp; [Deactivation must not drop data](#deactivation-must-not-drop-data) &nbsp;·&nbsp; [You must implement](#you-must-implement) &nbsp;·&nbsp; [Methods you can use](#methods-you-can-use)

Base class for plugin activation and deactivation lifecycle callbacks.

Extend this for installation or cleanup work — creating tables, seeding options, flushing rewrite rules on activation, and their teardown on deactivation — rather than for ordinary per-request bootstrapping. It is a `Module`, so it is declared in `bootstrap.php` like any other, and `on_boot()` is already written: it registers your `activate()` and `deactivate()` with WordPress.

**The timing constraint is about `run()`, not about how you declare it.** WordPress fires `activate_{plugin}` during the activation request, right after the plugin file loads. `register_activation_hook()` has to have been called by then, and WordPress does not re-fire a past action for a late subscriber — so a plugin whose entry file defers `run()` to `plugins_loaded` or `init` has already missed the window, and `activate()` can never run for that request.

There is no error to notice, so `on_boot()` detects the late boot and emits `_doing_it_wrong()` rather than binding a hook that will silently never fire. Deactivation is still registered, since that hook fires on a later request.

The fix is always the same: call `run()` as the entry file loads.

## Writing one

`activate()` and `deactivate()` are both abstract, so neither can be forgotten. Declare it like any other module.

```php
namespace Acme\Plugin\Modules;

use Acme\Plugin\Core\Kernel\Abstracts\ActivationHandler;
use Acme\Plugin\Core\Modules\Migrations\Migrations;

class Activation extends ActivationHandler {

    public function activate( bool $network_wide ): void {
        $this->with( Migrations::class )->run_pending();
        flush_rewrite_rules();
    }

    public function deactivate( bool $network_wide ): void {
        wp_clear_scheduled_hook( 'acme-plugin-daily' );
        flush_rewrite_rules();
    }
}

// bootstrap.php
return array(
    Activation::class,
);
```

## Getting the timing right

The entry file has to run the plugin as it loads. This is the shape `wp zt init` documents, and the only one that works for activation:

```php
// acme-plugin.php
function acme_plugin(): Plugin {
    static $plugin = null;

    $plugin ??= ( new Plugin( __FILE__ ) )->bootstrap()->run();

    return $plugin;
}

acme_plugin();   // <- runs now, ahead of activate_{plugin}

// Deferring that call is what breaks it. By `plugins_loaded` the
// activate_{plugin} action has already fired, so activate() never runs and
// on_boot() reports it with _doing_it_wrong():
//
//     add_action( 'plugins_loaded', 'acme_plugin' );
```

## Deactivation must not drop data

Deactivation runs whenever the plugin is switched off, and that includes every update — so anything it removes is removed from a site that is about to carry on running. Undo what costs nothing to rebuild; leave anything a user would miss. Deleting a plugin for good is a separate WordPress lifecycle, and not this class's.

```php
public function deactivate( bool $network_wide ): void {
    wp_clear_scheduled_hook( 'acme-plugin-daily' );   // yes
    // $wpdb->query( 'DROP TABLE ...' );              // no -- an update would wipe it
}
```

## You must implement

These 2 methods are abstract: a subclass that does not declare all of them will not load.

### `activate( $network_wide )`

Run plugin activation tasks for one site.

```php
abstract public function activate( bool $network_wide ): void
```

|  | Details |
|---|---|
| **Parameters** | `$network_wide` — Whether the plugin was activated network-wide |
| **Return** | — |
| **Throws** | — |

Always called with one site active, so it never has to think about networks: create your tables, seed your options, and this toolkit sees that it happens everywhere it should. On a network activation it runs once per existing site, and again for each site created afterwards.

`$network_wide` is context, not instruction: it says the plugin was activated for the whole network. Use it to seed something once rather than per site. The per-site work is the same either way.

<br>

### `deactivate( $network_wide )`

Run plugin deactivation tasks for one site.

```php
abstract public function deactivate( bool $network_wide ): void
```

|  | Details |
|---|---|
| **Parameters** | `$network_wide` — Whether the plugin was deactivated network-wide |
| **Return** | — |
| **Throws** | — |

Called under the same rules as `activate()`: once per site on a network deactivation, with that site active.

## Methods you can use

### `activate_site( $site_id )`

Run activation for one site by ID.

```php
public function activate_site( int $site_id ): void
```

|  | Details |
|---|---|
| **Parameters** | `$site_id` — The site to set up |
| **Return** | — |
| **Throws** | — |

The escape hatch for a network too large to loop, and what a WP-CLI command would call to set a site up by hand. Switches into the site, runs `activate()`, and switches back.

<br>

### `on_boot()`

Register this module's activation and deactivation callbacks.

```php
public function on_boot(): void
```

WordPress associates both callbacks with the entry file held by the plugin, ensuring the hooks run only for this plugin. If this runs after the plugin's `activate_{plugin}` hook has already fired, the activation callback cannot bind in time, so a developer warning is emitted instead of failing silently. Deactivation is still registered because that hook fires on a later request.

`register_activation_hook()` binds the callback to the action named `'activate_' . plugin_basename( $entry_file )`. That action is a normal WordPress hook: if it has already fired by the time this method calls `register_activation_hook()`, the call still succeeds and returns nothing to indicate failure, but the callback is bound too late to ever run for this request — WordPress does not re-fire past actions for late subscribers. This is exactly why ActivationHandler subclasses must be resolved synchronously at plugin load, as described on the class: on_boot() must run before that action fires, or activation logic silently never executes.

<br>

### `is_network_active()`

Whether this plugin is active across the whole network.

```php
protected function is_network_active(): bool
```

|  | Details |
|---|---|
| **Parameters** | — |
| **Return** | `bool` |
| **Throws** | — |

<br>

### `on_wp_init( $callback, $priority )`

*Inherited from [`Module`](module.md).*

Run a callback on `init`, or immediately if `init` has already fired.

```php
final public function on_wp_init( callable $callback, int $priority = 10 ): void
```

|  | Details |
|---|---|
| **Parameters** | `$callback` — What to run<br>`$priority` — WordPress hook priority, honoured only while `init` is still ahead |
| **Return** | — |
| **Throws** | — |

Reach for this wherever a plain `add_action( 'init', ... )` would go: that callback never runs once `init` has passed, and a module can be built on either side of it.

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
