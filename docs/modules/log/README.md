<!--
    Generated from src/Modules/Log.php.
    Do not edit by hand: run `composer docs` after changing the source.
-->

# Log

Writes namespaced, levelled messages to wherever WordPress sends errors.

Records go to `error_log()`, which WordPress already routes for the site — `WP_DEBUG_LOG` decides whether that is `wp-content/debug.log`, a path of the site's choosing, or the server's own error log.

Every line carries the plugin slug and the level, so one plugin's records stay greppable in a log every other plugin also writes to.

The levels are PSR-3's names. The PSR-3 interface is not implemented.

[Adding it](#adding-it) &nbsp;·&nbsp; [Recording something](#recording-something) &nbsp;·&nbsp; [Sending records somewhere else](#sending-records-somewhere-else) &nbsp;·&nbsp; [Changing the defaults](#changing-the-defaults) &nbsp;·&nbsp; [Constants](#constants) &nbsp;·&nbsp; [Methods](#methods) &nbsp;·&nbsp; [See also](#see-also)

## Adding it

```bash
wp zt add log
```

> [!IMPORTANT]
> **A module is built because `bootstrap.php` lists it.** `Log` binds its hooks when the plugin builds it, so it has to be listed there — which `wp zt add` writes for you. Left out, nothing is discovered and nothing reports why; [`wp zt doctor`](../../commands/doctor.md) is what catches it.

```php
// bootstrap.php
return array(
    Log::class,
);
```

## Recording something

The context array is for the values that make a message worth reading. It is JSON-encoded onto the line, so it lands in the log with the message rather than being dropped on the way.

```php
$log = $plugin->get( Log::class );

$log->error( 'Payment capture failed', array( 'order' => 42 ) );
// acme-plugin.ERROR: Payment capture failed {"order":42}

$log->debug( 'Raw gateway response', array( 'body' => $body ) );
```

## Sending records somewhere else

There is no hook to attach to, because this file belongs to the plugin: edit `write()` and every record goes wherever it says.

```php
private function write( string $level, string $message, array $context = array() ): void {
    if ( self::LEVEL_ERROR === $level ) {
        Sentry::captureMessage( $message, $context );
    }
}
```

The one hook this module does bind is for its siblings, not for extension. `Cron` announces a failed event on a `{plugin-slug}-log` action because it must keep working for a plugin that never added this module — naming a hook rather than a class is what keeps the two independent. When nothing is listening, it falls back to `error_log()` rather than lose the message.

## Changing the defaults

Register an initializer only to change what gets through. Everything at `info` and above is logged by default, plus `debug` when `WP_DEBUG` is on.

```php
// bootstrap.php
return array(
    Log::class => array(
        'configure' => static function ( Log $log ): void {
            $log->set_min_level( Log::LEVEL_WARNING );
        },
    ),
);
```

## Constants

### `LEVEL_EMERGENCY`

```php
const LEVEL_EMERGENCY = 'emergency';
```

The system is unusable.

### `LEVEL_ALERT`

```php
const LEVEL_ALERT = 'alert';
```

Action must be taken immediately.

### `LEVEL_CRITICAL`

```php
const LEVEL_CRITICAL = 'critical';
```

Critical conditions.

### `LEVEL_ERROR`

```php
const LEVEL_ERROR = 'error';
```

A runtime error that does not need immediate action.

### `LEVEL_WARNING`

```php
const LEVEL_WARNING = 'warning';
```

An exceptional occurrence that is not an error.

### `LEVEL_NOTICE`

```php
const LEVEL_NOTICE = 'notice';
```

A normal but significant event.

### `LEVEL_INFO`

```php
const LEVEL_INFO = 'info';
```

An interesting event.

### `LEVEL_DEBUG`

```php
const LEVEL_DEBUG = 'debug';
```

Detailed debugging information.

### `HOOK`

```php
const HOOK = 'log';
```

The action sibling modules announce their failures on, before the plugin slug is added. A record made through this module's own methods does not travel it: `log()` writes directly.

## Methods

### `set_min_level( $level )`

Set the least severe level that gets written.

```php
public function set_min_level( string $level ): void
```

|  | Details |
|---|---|
| **Parameters** | `$level` — One of this class's `LEVEL_*` constants |
| **Return** | — |
| **Throws** | `InvalidArgumentException` — When the level is not one this module knows |

Call this from the module initializer. Anything less severe than the given level is dropped before it is written, so raising the threshold costs nothing for the records it excludes.

<br>

### `emergency( $message, $context )`

The system is unusable.

```php
public function emergency( string $message, array $context = array() ): void
```

|  | Details |
|---|---|
| **Parameters** | `$message` — What happened<br>`$context` — Values that make the message worth reading |
| **Return** | — |
| **Throws** | — |

<br>

### `alert( $message, $context )`

Action must be taken immediately.

```php
public function alert( string $message, array $context = array() ): void
```

|  | Details |
|---|---|
| **Parameters** | `$message` — What happened<br>`$context` — Values that make the message worth reading |
| **Return** | — |
| **Throws** | — |

<br>

### `critical( $message, $context )`

A critical condition.

```php
public function critical( string $message, array $context = array() ): void
```

|  | Details |
|---|---|
| **Parameters** | `$message` — What happened<br>`$context` — Values that make the message worth reading |
| **Return** | — |
| **Throws** | — |

<br>

### `error( $message, $context )`

A runtime error that does not need immediate action.

```php
public function error( string $message, array $context = array() ): void
```

|  | Details |
|---|---|
| **Parameters** | `$message` — What happened<br>`$context` — Values that make the message worth reading |
| **Return** | — |
| **Throws** | — |

<br>

### `warning( $message, $context )`

An exceptional occurrence that is not an error.

```php
public function warning( string $message, array $context = array() ): void
```

|  | Details |
|---|---|
| **Parameters** | `$message` — What happened<br>`$context` — Values that make the message worth reading |
| **Return** | — |
| **Throws** | — |

<br>

### `notice( $message, $context )`

A normal but significant event.

```php
public function notice( string $message, array $context = array() ): void
```

|  | Details |
|---|---|
| **Parameters** | `$message` — What happened<br>`$context` — Values that make the message worth reading |
| **Return** | — |
| **Throws** | — |

<br>

### `info( $message, $context )`

An interesting event.

```php
public function info( string $message, array $context = array() ): void
```

|  | Details |
|---|---|
| **Parameters** | `$message` — What happened<br>`$context` — Values that make the message worth reading |
| **Return** | — |
| **Throws** | — |

<br>

### `debug( $message, $context )`

Detailed debugging information.

```php
public function debug( string $message, array $context = array() ): void
```

|  | Details |
|---|---|
| **Parameters** | `$message` — What happened<br>`$context` — Values that make the message worth reading |
| **Return** | — |
| **Throws** | — |

Written only when `WP_DEBUG` is on, unless `set_min_level()` says otherwise.

<br>

### `log( $level, $message, $context )`

Record something at a level decided at runtime.

```php
public function log( string $level, string $message, array $context = array() ): void
```

|  | Details |
|---|---|
| **Parameters** | `$level` — One of this class's `LEVEL_*` constants<br>`$message` — What happened<br>`$context` — Values that make the message worth reading |
| **Return** | — |
| **Throws** | `InvalidArgumentException` — When the level is not one this module knows |

The level-named methods all call this. Use it directly when the level is computed — mapping an exception's severity, say — rather than known where the call is written.

<br>

### `get_hook()`

The action name sibling modules announce their failures on, namespaced to the plugin.

```php
public function get_hook(): string
```

|  | Details |
|---|---|
| **Parameters** | — |
| **Return** | The action name, e.g. `acme-plugin-log` |
| **Throws** | — |

Your slug joined to `log` with a hyphen, so the plugin `acme-plugin` gets `acme-plugin-log`. Call this rather than composing the name yourself — `do_action( $log->get_hook(), ... )` — so a module of your own reports on the same hook this one listens to.

<br>

### `on_wp_init( $callback, $priority )`

*Inherited from [`Module`](../module.md).*

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

*Inherited from [`WithPlugin`](../../kernel/with-plugin.md).*

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

*Inherited from [`WithPlugin`](../../kernel/with-plugin.md).*

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

A module that names a `boots_on` also throws when asked for before that hook has fired, since building it early would bind it on the wrong side of whatever it was declared to follow.

## See also

- [`Module`](../module.md) — what every module inherits
- [`wp zt add log`](../../commands/add.md) — the command that copies it
