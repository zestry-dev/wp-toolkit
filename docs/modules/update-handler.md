<!--
    Generated from src/Kernel/Abstracts/UpdateHandler.php.
    Do not edit by hand: run `composer docs` after changing the source.
-->

# UpdateHandler

[Writing one](#writing-one) &nbsp;·&nbsp; [Keeping the state with the rest of your settings](#keeping-the-state-with-the-rest-of-your-settings) &nbsp;·&nbsp; [You must implement](#you-must-implement) &nbsp;·&nbsp; [Methods you can use](#methods-you-can-use)

Base class for work that has to happen once after the plugin's code changes.

The counterpart to `ActivationHandler`, for the half of the lifecycle WordPress gives you no hook for. Extend it to run migrations, flush rewrite rules, clear a cache whose shape changed, or re-add a capability — anything that must happen once per release rather than once per request.

**There is no update hook, and the two candidates both fail.** `activate_{plugin}` does not fire on an update: the plugin stays active throughout, and nothing re-activates it. `upgrader_process_complete` fires for wp.org, an uploaded zip and `wp plugin update`, but the *old* code is still in memory while the new files are already on disk — so the work it runs belongs to the release you just replaced. Neither fires at all for an FTP upload.

So this compares instead of listening. The plugin's `Version:` header is read from the entry file that is actually loaded, and checked against the version recorded the last time `update()` completed. They disagree exactly when new code is running, whatever delivered it — wp.org, a zip, a private update server, WP-CLI, or a file copied over by hand. It is what WordPress does for itself with `db_version`, for the same reason.

## When the check runs

Whenever `bootstrap.php` says to build it. There is no hook setting on this class: the heading a module is listed under is already its timing, and a second way to say the same thing could only disagree with the first.

`admin_init` is the one to want, and what `wp zt make update` writes. It is late enough that `init` has finished, so anything registered there is available to `update()`; narrow enough that no front-end visitor ever carries the work; and immediate in practice, because every updater-driven update redirects into wp-admin.

```php
// bootstrap.php
'admin_init' => array( Update::class ),
```

Move it if you must — `'plugins_loaded'` runs the check before `init`-listed modules of your own, and `'admin_init:5'` just moves the priority. Know what an earlier heading costs: on anything that fires for visitors, the first front-end request after an update is the one that carries it, and on a busy site that is many requests at once. The lock keeps them from overlapping; it does not make them fast.

## On multisite

**Per site, and lazily** — each site records its own version, and updates itself the first time somebody opens its dashboard. Deliberately unlike `ActivationHandler`, which loops every site on a network activation.

The difference is that activation gets one chance. WordPress fires it once, on whichever site the network administrator happened to be on, so a handler that did not loop would set up one site and leave the rest without their tables. This check runs on every admin request of every site, so each one gets endless chances and fixes itself. Looping here would take the timeout risk that `wp_is_large_network()` exists to avoid, and take it again on every admin request until it finished.

What that costs is a site nobody administers: its update work waits until somebody opens it. WordPress has the same gap and answers it the same way — per-site on visit, plus a network upgrade screen for driving the rest. `update_site()` is that lever here, for a command or an action of your own:

```php
foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $site_id ) {
    $plugin->get( Update::class )->update_site( (int) $site_id );
}
```

> [!IMPORTANT]
> **No hook closes the gap between new code and its update work.** WordPress swaps the files first, so some request runs new code against the old state no matter what you pick here — an earlier hook narrows the window without closing it. Where you control deployment, run the work as a deploy step (`wp {slug} migrations run`) with the site in maintenance mode. Where you do not, write releases that tolerate the gap: add the column in one release and read it in the next, and nothing depends on this having run yet.

## Writing one

`$previous` is null when this site has no recorded version — a fresh install, or one that predates this handler. You cannot tell those apart, so do the work that brings a site up to date from anywhere; `Migrations::run_pending()` is already exactly that, running the baseline on a new site and the pending migrations on an old one.

```php
namespace Acme\Plugin\Modules;

use Acme\Plugin\Core\Kernel\Abstracts\UpdateHandler;
use Acme\Plugin\Core\Modules\Migrations\Migrations;

class Update extends UpdateHandler {

    public function update( ?string $previous, string $current ): bool|WP_Error {
        $this->with( Migrations::class )->run_pending();

        // Version-specific work, for sites coming from before a change.
        if ( null !== $previous && version_compare( $previous, '2.0.0', '<' ) ) {
            delete_option( 'acme_plugin_legacy_cache' );
        }

        flush_rewrite_rules();

        return true;
    }
}

// bootstrap.php -- `wp zt make update` writes this line for you.
return array(
    'admin_init' => array( Update::class ),
);
```

The heading is the timing: this is built when `admin_init` fires, and being built is what runs the check.

## Keeping the state with the rest of your settings

Two `wp_option` rows by default, written with `update_option()` directly — because this class lives in the kernel, which every plugin gets from `wp zt init`, while [`options`](../modules/options/) is one you add. Reaching for a module from here would make the kernel stop working on a plugin that never added it.

So it is a seam instead. Override the two readers and the two writers and the state goes wherever you keep the rest of yours — one row, in its own group:

```php
public function get_recorded_version(): ?string {
    return $this->with( Options::class )->group( '_update_' )->get( 'version' );
}

protected function record_version( string $version ): void {
    $options = $this->with( Options::class )->group( '_update_' );
    $options->set( 'version', $version );
    $options->save();
}
```

`get_last_error()` and `record_error()` are the same pair for the failure message. A group rather than the ungrouped row, so this can never collide with a setting of yours — and it does not autoload unless you name it in `add_autoloaded_groups()`, which is worth doing here since the check reads it on every admin request.

## You must implement

This one method is abstract: a subclass that does not declare it will not load.

### `update( $previous, $current )`

Do the work this release needs doing once.

```php
abstract public function update( ?string $previous, string $current ): bool|\WP_Error
```

|  | Details |
|---|---|
| **Parameters** | `$previous` — The version recorded last time, or null when this site has none<br>`$current` — The version in the entry file that is running now |
| **Return** | True when the work is done; false or a WP_Error to be retried |
| **Throws** | — |

Called on the first request that notices the version has changed, and not again until it changes once more.

**`true` is the only thing that means done.** Return `false` or a `WP_Error`, or throw, and the version is not recorded — so the work is retried rather than skipped — and an administrator is told, on every admin screen, what went wrong. Much of WordPress already hands you a `WP_Error`, so returning it straight through is usually the whole of your error handling.

Nothing propagates out of here. An update that throws on every request would take the admin down with it, and an administrator locked out of wp-admin cannot fix the plugin that locked them out.

Two requests arriving together after an update cannot both run it: the second sees a lock and leaves. A failure leaves that lock in place until it expires, so a broken update is retried every few minutes rather than on every single admin request.

## Methods you can use

### `get_last_error()`

What went wrong the last time an update was attempted.

```php
public function get_last_error(): ?string
```

|  | Details |
|---|---|
| **Parameters** | — |
| **Return** | The failure message |
| **Throws** | — |

Cleared by the first attempt that succeeds. Present means this site is still running code whose update work has not completed.

<br>

### `get_error_option_name()`

The option a failure message is kept in.

```php
public function get_error_option_name(): string
```

|  | Details |
|---|---|
| **Parameters** | — |
| **Return** | `string` |
| **Throws** | — |

Not a transient: a failed update is not a cache, and it should still be on screen tomorrow if nobody has fixed it.

<br>

### `update_site( $site_id )`

Run the check for one site by ID.

```php
public function update_site( int $site_id ): void
```

|  | Details |
|---|---|
| **Parameters** | `$site_id` — The site to bring up to date |
| **Return** | — |
| **Throws** | — |

The counterpart to `ActivationHandler::activate_site()`: what a WP-CLI command or a network-admin action calls to bring a site up to date without waiting for somebody to open its dashboard. Switches into the site, checks, and switches back.

<br>

### `get_recorded_version()`

The version recorded the last time `update()` completed.

```php
public function get_recorded_version(): ?string
```

|  | Details |
|---|---|
| **Parameters** | — |
| **Return** | `?string` |
| **Throws** | — |

Null on a site that has never recorded one, which is a fresh install or a plugin that added this handler in a later release.

<br>

### `get_version_option_name()`

The option the recorded version lives in.

```php
public function get_version_option_name(): string
```

|  | Details |
|---|---|
| **Parameters** | — |
| **Return** | `string` |
| **Throws** | — |

Autoloaded, since the check reads it on every request the hook fires on. Per site on multisite, so each site of a network updates itself the first time somebody opens its admin — deliberately not looped the way activation is, because an update check costs one autoloaded read and fixes itself, where a loop over a large network times out half way.

<br>

### `get_lock_duration()`

How long the lock is trusted if it is never cleared explicitly.

```php
protected function get_lock_duration(): int
```

|  | Details |
|---|---|
| **Parameters** | — |
| **Return** | Seconds; five minutes unless a subclass overrides it |
| **Throws** | — |

Long enough for a slow batch of migrations, short enough that a process killed mid-update does not block the retry for the rest of the day.

<br>

### `should_update( $previous, $current )`

Whether there is anything to do.

```php
protected function should_update( ?string $previous, string $current ): bool
```

|  | Details |
|---|---|
| **Parameters** | `$previous` — The version recorded last time, or null when this site has none<br>`$current` — The version running now |
| **Return** | `bool` |
| **Throws** | — |

True when the running version is newer than the recorded one, by `version_compare()`, and on the first run of a site that has recorded nothing. A rollback does not run: the recorded version stays ahead until a release passes it again.

<br>

### `record_version( $version )`

Write the version this site is now up to date with.

```php
protected function record_version( string $version ): void
```

|  | Details |
|---|---|
| **Parameters** | `$version` — The version to record |
| **Return** | — |
| **Throws** | — |

One half of the storage seam, with `get_recorded_version()`. Override both to keep this wherever the rest of your plugin's state lives — see the class docblock for doing that with the `options` module.

<br>

### `record_error( $message )`

Write, or clear, the last failure message.

```php
protected function record_error( ?string $message ): void
```

|  | Details |
|---|---|
| **Parameters** | `$message` — The failure, or null to clear it |
| **Return** | — |
| **Throws** | — |

The other half of the seam, with `get_last_error()`. Null clears, which is what a successful update does.

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
