<!--
    Generated from src/Modules/Log.php.
    Do not edit by hand: run `composer docs` after changing the source.
-->

# Log

Writes namespaced, levelled messages to wherever WordPress sends errors.

Records go to `error_log()`, which WordPress already routes for the site — `WP_DEBUG_LOG` decides whether that is `wp-content/debug.log`, a path of the site's choosing, or the server's own error log.

Every line carries the plugin slug and the level, so one plugin's records stay greppable in a log every other plugin also writes to.

The levels are PSR-3's names. The PSR-3 interface is not implemented.

[Adding it](#adding-it) &nbsp;·&nbsp; [Recording something](#recording-something) &nbsp;·&nbsp; [Sending records somewhere else](#sending-records-somewhere-else) &nbsp;·&nbsp; [Changing the defaults](#changing-the-defaults) &nbsp;·&nbsp; [Constants](#constants) &nbsp;·&nbsp; [You must implement](#you-must-implement) &nbsp;·&nbsp; [Methods you can use](#methods-you-can-use) &nbsp;·&nbsp; [See also](#see-also)

## Adding it

```bash
wp zestry add module log
```

> [!IMPORTANT]
> **A module is built because `bootstrap.php` lists it.** `Log` binds its hooks when the plugin builds it, so it has to be listed there — which `wp zestry add` writes for you. Left out, nothing is discovered and nothing reports why; [`wp zestry doctor`](../../commands/doctor.md) is what catches it.

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

The one hook this module does bind is for its siblings, not for extension. `Options` and `Cron` announce their failures on a `{plugin-slug}-log` action because they must keep working for a plugin that never added this module — naming a hook rather than a class is what keeps the three independent. When nothing is listening, they fall back to `error_log()` rather than lose the message.

## Changing the defaults

Register an initializer only to change what gets through. Everything at `info` and above is logged by default, plus `debug` when `WP_DEBUG` is on.

```php
// bootstrap.php
return array(
    Log::class => static function ( Log $log ): void {
        $log->set_min_level( Log::LEVEL_WARNING );
    },
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

## You must implement

This one method is abstract: a subclass that does not declare it will not load.

### `on_boot()`

What this module does on its own.

```php
abstract protected function on_boot(): void
```

Runs once, when the plugin builds the module. Abstract rather than optional: a module with nothing to do here is a `Service`.

**Bind hooks here; do the work in them.** An entry file that calls `run()` as it loads — which is the documented shape, and what `ActivationHandler` requires — reaches this before WordPress has required `pluggable.php`, so there is no current user yet: `current_user_can()`, `wp_mail()` and the nonce functions are not defined and calling one is a fatal. It is also before `init`, so `__()` here asks for a text domain nothing has loaded. `$wpdb` *is* up, so a query works — but it runs on every request, including the ones that never needed it.

`run_at_init()` is the way out of all three, and where anything a module registers belongs.

## Methods you can use

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

### `run_at_init( $callback )`

Run a callback on `init`, or immediately if `init` has already fired.

```php
final public function run_at_init( callable $callback ): void
```

|  | Details |
|---|---|
| **Parameters** | `$callback` — What to run |
| **Return** | — |
| **Throws** | — |

Almost everything a module registers — a post type, a block, a WP-CLI command — has to happen on `init`, and a plain `add_action( 'init', ... )` is a callback that never runs once `init` has passed. A module can be resolved on either side of it: `Plugin::run()` is synchronous, so an entry file that calls it at plugin load is ahead of `init`, while one that calls it from a later hook — or a `get()` during a request — is behind. This behaves the same either way, so a module never has to care which.

The callback receives the module, matching the initializer signature, so a closure declared elsewhere needs no `use` to reach it:

```php
protected function on_boot(): void {
    $this->run_at_init( function ( self $module ): void {
        $module->register_widgets();
    } );
}
```

## See also

- [`Module`](../module.md) — what every module inherits
- [`wp zestry add module log`](../../commands/add-module.md) — the command that copies it
