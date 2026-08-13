<!--
    Generated from src/Modules/SiteHealth/HealthCheck.php.
    Do not edit by hand: run `composer docs` after changing the source.
-->

# HealthCheck

[A check](#a-check) &nbsp;·&nbsp; [Generated starting point](#generated-starting-point) &nbsp;·&nbsp; [Constants](#constants) &nbsp;·&nbsp; [You must implement](#you-must-implement) &nbsp;·&nbsp; [Methods you can use](#methods-you-can-use)

One check on the Site Health screen.

A file in `health-checks/` returns one of these. Its filename is the check's identifier, so `api-key.php` becomes `{plugin-slug}-api-key` on the screen.

Site Health is the supported way to see a site you cannot log into: a user copies the report into a support ticket. A check that says "the API key is missing" saves the round trip that starts with "what does your settings page show?".

## A check

`run()` returns one of `good()`, `recommended()` or `critical()`. Anything a Service or Module can be injected into this can too, so the check reads real state rather than guessing.

```php
namespace Acme\Plugin\HealthChecks;

use Acme\Plugin\Core\Modules\SiteHealth\HealthCheck;
use Acme\Plugin\Core\Modules\Options;

return new class extends HealthCheck {

    public function label(): string {
        return __( 'Acme API key', 'acme-plugin' );
    }

    public function run(): array {
        if ( '' !== (string) $this->options->get( 'api_key', '' ) ) {
            return $this->good( __( 'Your API key is set.', 'acme-plugin' ) );
        }

        return $this->critical(
            __( 'Acme cannot reach its API without a key, so nothing will sync.', 'acme-plugin' ),
            sprintf(
                '<a href="%s">%s</a>',
                esc_url( admin_url( 'admin.php?page=acme-settings' ) ),
                esc_html__( 'Add your API key', 'acme-plugin' )
            )
        );
    }
};
```

## Generated starting point

[`wp zt make health-check <name>`](../../commands/make-health-check.md) writes this file:

```php
<?php
/**
 * example health check.
 */

declare( strict_types=1 );

// Loaded by WordPress, never requested directly.
\defined( 'ABSPATH' ) || exit;

use Acme\Plugin\Core\Modules\SiteHealth\HealthCheck;
// use Acme\Plugin\Core\Modules\SiteHealth\BadgeColor;

return new class() extends HealthCheck {

	// The test id is this file's name -- {plugin-slug}-example. Renaming the
	// file registers a different test, so a filter or mu-plugin that removed
	// the old one silently stops removing anything.

	// The name shown for this check on the Site Health screen.
	public function label(): string {
		return 'Example';
	}

	// The check itself. Return good(), recommended() or critical() -- each
	// takes a sentence explaining what it found, and optionally some HTML
	// linking to wherever the problem is fixed.
	//
	// This runs on the Site Health screen and on the weekly cron behind it,
	// so keep it quick and free of side effects. Anything a Module or Service
	// can be injected into this can too: declare a typed public property and
	// read real state rather than guessing.
	public function run(): array {
		return $this->good( 'Everything looks fine.' );
	}

	// Which category this check is filed under, and the colour beside it.
	// Defaults to your plugin's name, which groups your checks together.
	// Override to use one of WordPress's own, e.g. 'Performance'.
	//
	// public function badge_label(): string {
	//     return 'Performance';
	// }
	//
	// public function badge_color(): BadgeColor {
	//     return BadgeColor::Purple;
	// }
};
```

## Constants

### `STATUS_GOOD`

```php
const STATUS_GOOD = 'good';
```

Everything is as it should be.

### `STATUS_RECOMMENDED`

```php
const STATUS_RECOMMENDED = 'recommended';
```

Worth improving, but nothing is broken.

### `STATUS_CRITICAL`

```php
const STATUS_CRITICAL = 'critical';
```

Something is broken and the user should act.

## You must implement

These 2 methods are abstract: a subclass that does not declare all of them will not load.

### `label()`

The name shown for this check on the Site Health screen.

```php
abstract public function label(): string
```

|  | Details |
|---|---|
| **Parameters** | — |
| **Return** | A short, translated label |
| **Throws** | — |

<br>

### `run()`

Run the check.

```php
abstract public function run(): array
```

|  | Details |
|---|---|
| **Parameters** | — |
| **Return** | The result, as WordPress expects it |
| **Throws** | — |

Runs on the Site Health screen and on the weekly cron that populates it, so keep it quick and avoid side effects. Return `good()`, `recommended()` or `critical()`.

## Methods you can use

### `get_id()`

The identifier this check is registered under.

```php
final public function get_id(): string
```

|  | Details |
|---|---|
| **Parameters** | — |
| **Return** | `string` |
| **Throws** | — |

Your filename with the plugin slug prefixed, since `site_status_tests` is one array shared by every plugin: `api-key.php` gives `{plugin-slug}-api-key`. Useful for logging which check reported, since that is the name the report shows.

<br>

### `badge_label()`

The category this check is filed under on the screen.

```php
public function badge_label(): string
```

|  | Details |
|---|---|
| **Parameters** | — |
| **Return** | A short, translated badge label |
| **Throws** | — |

Defaults to your plugin's name, which is usually what you want — it groups your checks together and tells a reader whose check failed. Override to use one of WordPress's own categories, such as `Performance` or `Security`.

<br>

### `badge_color()`

The colour of the badge beside the category.

```php
public function badge_color(): BadgeColor
```

|  | Details |
|---|---|
| **Parameters** | — |
| **Return** | `BadgeColor` |
| **Throws** | — |

<br>

### `site_health()`

The module that discovered this check.

```php
final protected function site_health(): SiteHealth
```

|  | Details |
|---|---|
| **Parameters** | — |
| **Return** | `SiteHealth` |
| **Throws** | — |

<br>

### `good( $description, $actions )`

Nothing to do.

```php
protected function good( string $description, string $actions = '' ): array
```

|  | Details |
|---|---|
| **Parameters** | `$description` — What is fine, in a sentence<br>`$actions` — Optional HTML, usually a link |
| **Return** | `array` |
| **Throws** | — |

<br>

### `recommended( $description, $actions )`

Worth improving, but nothing is broken.

```php
protected function recommended( string $description, string $actions = '' ): array
```

|  | Details |
|---|---|
| **Parameters** | `$description` — What could be better, in a sentence<br>`$actions` — Optional HTML, usually a link to where to fix it |
| **Return** | `array` |
| **Throws** | — |

<br>

### `critical( $description, $actions )`

Something is broken and the user should act.

```php
protected function critical( string $description, string $actions = '' ): array
```

|  | Details |
|---|---|
| **Parameters** | `$description` — What is wrong and what it costs, in a sentence<br>`$actions` — Optional HTML, usually a link to where to fix it |
| **Return** | `array` |
| **Throws** | — |

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

How you reach a module, always: building one boots it, so the cost belongs at the call rather than hidden in a property declaration. Also how you reach a service you look up by a name computed at runtime.

```php
$this->get_plugin()->get( Options::class )->get( 'api_key' );
```

<br>

### `is_enabled()`

*Inherited from [`WithEnablement`](../../kernel/with-enablement.md).*

Whether this should be registered at all.

```php
public function is_enabled(): bool
```

|  | Details |
|---|---|
| **Parameters** | — |
| **Return** | `bool` |
| **Throws** | — |

Called once, after the instance is wired and before anything is registered. Return false and nothing happens: no hook is bound and no WordPress registration is made.

The default is true, so a file that says nothing registers — being on disk is the convention, and this is the exception to it.

It registers nothing either way.
