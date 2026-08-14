<!--
    Generated from src/Modules/SiteHealth/SiteHealth.php.
    Do not edit by hand: run `composer docs` after changing the source.
-->

# SiteHealth

Discovers `resources/health-checks/`, `resources/debug-sections/` &nbsp;·&nbsp; Each file returns [`HealthCheck`](health-check.md), [`DebugSection`](debug-section.md) &nbsp;·&nbsp; Dependencies [`path`](../path/)

Puts your plugin on WordPress's Site Health screen.

Two directories, one per tab. A file in `resources/health-checks/` returns a `HealthCheck` and appears on **Status** with a verdict; a file in `resources/debug-sections/` returns a `DebugSection` and appears on **Info** as a panel of values, with no verdict. In both, the filename is the identifier: `api-key.php` becomes `{plugin-slug}-api-key`.

This is the supported way to see a site you cannot log into. A user copies the report into a support ticket, so a check that reports "the API key is missing" — or a panel listing which of your settings are set — answers the first question you were going to ask anyway.

Checks run on the Site Health screen and on the weekly cron behind it, so keep them quick and free of side effects.

[Adding it](#adding-it) &nbsp;·&nbsp; [A health check](#a-health-check) &nbsp;·&nbsp; [A debug section](#a-debug-section) &nbsp;·&nbsp; [Changing the defaults](#changing-the-defaults) &nbsp;·&nbsp; [Writing a HealthCheck](#writing-a-healthcheck) &nbsp;·&nbsp; [Writing a DebugSection](#writing-a-debugsection) &nbsp;·&nbsp; [Related classes](#related-classes) &nbsp;·&nbsp; [Constants](#constants) &nbsp;·&nbsp; [Methods](#methods) &nbsp;·&nbsp; [See also](#see-also)

## Adding it

```bash
wp zt add site-health
```

> [!IMPORTANT]
> **A module is built because `bootstrap.php` lists it.** `SiteHealth` binds its hooks when the plugin builds it, so it has to be listed there — which `wp zt add` writes for you. Left out, nothing is discovered and nothing reports why; [`wp zt doctor`](../../commands/doctor.md) is what catches it.

```php
// bootstrap.php
return array(
    SiteHealth::class,
);
```

## A health check

```php
// resources/health-checks/api-key.php
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
// resources/debug-sections/status.php
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

`SiteHealth` takes no configuration. The bare `modules` entry above is all it needs — reach it with `$plugin->get( SiteHealth::class )`, or declare a property of its type and have it injected.

## Writing a HealthCheck

A file in `resources/health-checks/` returns a [`HealthCheck`](health-check.md) instance, which `wp zt make health-check <name>` generates.

## Writing a DebugSection

A file in `resources/debug-sections/` returns a [`DebugSection`](debug-section.md) instance, which `wp zt make debug-section <name>` generates.

## Related classes

Shipped with this module, and written against directly:

- [`BadgeColor`](badge-color.md) — enum, the colour of the category badge beside a check's label

## Constants

### `CHECKS_ROOT`

```php
const CHECKS_ROOT = 'resources/health-checks';
```

Where checks are discovered, relative to the plugin root.

### `SECTIONS_ROOT`

```php
const SECTIONS_ROOT = 'resources/debug-sections';
```

Where debug sections are discovered, relative to the plugin root.

## Methods

### `get_discovered_checks()`

Every discovered check, keyed by the identifier it registers under.

```php
public function get_discovered_checks(): array
```

|  | Details |
|---|---|
| **Parameters** | — |
| **Return** | Wired instances keyed by identifier |
| **Throws** | `DiscoveryException` — When a file returns the wrong value |

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
| **Throws** | `DiscoveryException` — When a file returns the wrong value |

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

- [`HealthCheck`](health-check.md) — what a file in `resources/health-checks/` returns
- [`DebugSection`](debug-section.md) — what a file in `resources/debug-sections/` returns
- [`path`](../path/) — copied in alongside this one
- [`Module`](../module.md) — what every module inherits
- [`wp zt add site-health`](../../commands/add.md) — the command that copies it
