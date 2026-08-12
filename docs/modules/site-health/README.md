<!--
    Generated from src/Modules/SiteHealth/SiteHealth.php.
    Do not edit by hand: run `composer docs` after changing the source.
-->

# SiteHealth

Discovers `health-checks/`, `debug-sections/` &nbsp;·&nbsp; Each file returns [`HealthCheck`](health-check.md), [`DebugSection`](debug-section.md) &nbsp;·&nbsp; Dependencies [`path`](../../services/path/)

Puts your plugin on WordPress's Site Health screen.

Two directories, one per tab. A file in `health-checks/` returns a `HealthCheck` and appears on **Status** with a verdict; a file in `debug-sections/` returns a `DebugSection` and appears on **Info** as a panel of values, with no verdict. In both, the filename is the identifier: `api-key.php` becomes `{plugin-slug}-api-key`.

This is the supported way to see a site you cannot log into. A user copies the report into a support ticket, so a check that reports "the API key is missing" — or a panel listing which of your settings are set — answers the first question you were going to ask anyway.

Checks run on the Site Health screen and on the weekly cron behind it, so keep them quick and free of side effects.

[Adding it](#adding-it) &nbsp;·&nbsp; [A health check](#a-health-check) &nbsp;·&nbsp; [A debug section](#a-debug-section) &nbsp;·&nbsp; [Changing the defaults](#changing-the-defaults) &nbsp;·&nbsp; [Writing a HealthCheck](#writing-a-healthcheck) &nbsp;·&nbsp; [Writing a DebugSection](#writing-a-debugsection) &nbsp;·&nbsp; [Related classes](#related-classes) &nbsp;·&nbsp; [Constants](#constants) &nbsp;·&nbsp; [You must implement](#you-must-implement) &nbsp;·&nbsp; [Methods you can use](#methods-you-can-use) &nbsp;·&nbsp; [See also](#see-also)

## Adding it

```bash
wp zestry add module site-health
```

> [!IMPORTANT]
> **A module is built because `bootstrap.php` lists it.** `SiteHealth` binds its hooks when the plugin builds it, so it has to be listed there — which `wp zestry add` writes for you. Left out, nothing is discovered and nothing reports why; [`wp zestry doctor`](../../commands/doctor.md) is what catches it.

```php
// bootstrap.php
return array(
    SiteHealth::class,
);
```

## A health check

```php
// health-checks/api-key.php
return new class extends HealthCheck {

    public function label(): string {
        return __( 'Acme API key', 'acme-plugin' );
    }

    public function run(): array {
        return $this->good( __( 'Your API key is set.', 'acme-plugin' ) );
    }
};
```

## A debug section

```php
// debug-sections/status.php
return new class extends DebugSection {

    public function label(): string {
        return __( 'Acme', 'acme-plugin' );
    }

    public function fields(): array {
        return array(
            'mode' => array(
                'label' => __( 'Mode', 'acme-plugin' ),
                'value' => __( 'Live', 'acme-plugin' ),
                'debug' => 'live',
            ),
        );
    }
};
```

## Changing the defaults

Point it at different directories

```php
SiteHealth::class => static function ( SiteHealth $health ): void {
    $health->set_checks_root( 'diagnostics' );
    $health->set_sections_root( 'diagnostics/info' );
},
```

## Writing a HealthCheck

A file in `health-checks/` returns a [`HealthCheck`](health-check.md) instance, which `wp zestry make health-check <name>` generates.

## Writing a DebugSection

A file in `debug-sections/` returns a [`DebugSection`](debug-section.md) instance, which `wp zestry make debug-section <name>` generates.

## Related classes

Shipped with this module, and written against directly:

- [`BadgeColor`](badge-color.md) — enum, the colour of the category badge beside a check's label

## Constants

### `DEFAULT_CHECKS_ROOT`

```php
const DEFAULT_CHECKS_ROOT = 'health-checks';
```

Where checks are discovered, relative to the plugin root.

### `DEFAULT_SECTIONS_ROOT`

```php
const DEFAULT_SECTIONS_ROOT = 'debug-sections';
```

Where debug sections are discovered, relative to the plugin root.

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

### `set_checks_root( $root )`

Read checks from a different directory.

```php
public function set_checks_root( string $root ): void
```

|  | Details |
|---|---|
| **Parameters** | `$root` — Directory relative to the plugin root |
| **Return** | — |
| **Throws** | — |

Call this before the module boots — from its `bootstrap.php` entry. Naming a directory that does not exist is an error and throws at boot, where leaving the default alone and having no such directory simply means you have no checks yet.

<br>

### `set_sections_root( $root )`

Read debug sections from a different directory.

```php
public function set_sections_root( string $root ): void
```

|  | Details |
|---|---|
| **Parameters** | `$root` — Directory relative to the plugin root |
| **Return** | — |
| **Throws** | — |

The same rules as `set_checks_root()`: call it before the module boots, and a directory named here and then missing throws.

<br>

### `get_discovered_checks()`

Every discovered check, keyed by the identifier it registers under.

```php
public function get_discovered_checks(): array
```

|  | Details |
|---|---|
| **Parameters** | — |
| **Return** | Wired instances keyed by identifier |
| **Throws** | `DiscoveryException` — When a directory named by set_checks_root() does not exist, or a file returns the wrong value |

<br>

### `get_discovered_sections()`

Every discovered debug section, keyed by the identifier it registers under.

```php
public function get_discovered_sections(): array
```

|  | Details |
|---|---|
| **Parameters** | — |
| **Return** | Wired instances keyed by identifier |
| **Throws** | `DiscoveryException` — When a directory named by set_sections_root() does not exist, or a file returns the wrong value |

<br>

### `get_id_of( $item )`

A check's or section's identifier, from the file it was discovered in.

```php
public function get_id_of( HealthCheck|DebugSection $item ): string
```

|  | Details |
|---|---|
| **Parameters** | `$item` — The instance to look up |
| **Return** | `string` |
| **Throws** | `InvalidArgumentException` — When the instance was not discovered by this module |

<br>

### `get_check_id( $name )`

The identifier a check file registers under.

```php
public function get_check_id( string $name ): string
```

|  | Details |
|---|---|
| **Parameters** | `$name` — The check's local name — its filename without `.php` |
| **Return** | `string` |
| **Throws** | — |

Namespaced to the plugin, since `site_status_tests` is one array shared by every plugin on the site. Your slug and the filename are joined with a hyphen, both as written, so `api-key.php` gives `{plugin-slug}-api-key`.

<br>

### `get_section_id( $name )`

The identifier a debug section file registers under.

```php
public function get_section_id( string $name ): string
```

|  | Details |
|---|---|
| **Parameters** | `$name` — The section's local name — its filename without `.php` |
| **Return** | `string` |
| **Throws** | — |

Namespaced the same way, and for the same reason: `debug_information` is one array shared by every plugin. `status.php` gives `{plugin-slug}-status`.

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

- [`HealthCheck`](health-check.md) — what a file in `health-checks/` returns
- [`DebugSection`](debug-section.md) — what a file in `debug-sections/` returns
- [`path`](../../services/path/) — copied in alongside this one
- [`Module`](../module.md) — what every module inherits
- [`wp zestry add module site-health`](../../commands/add-module.md) — the command that copies it
