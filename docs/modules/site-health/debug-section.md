<!--
    Generated from src/Modules/SiteHealth/DebugSection.php.
    Do not edit by hand: run `composer docs` after changing the source.
-->

# DebugSection

[A section](#a-section) &nbsp;·&nbsp; [Generated starting point](#generated-starting-point) &nbsp;·&nbsp; [You must implement](#you-must-implement) &nbsp;·&nbsp; [Methods you can use](#methods-you-can-use)

One panel on the Site Health *Info* tab.

A file in `debug-sections/` returns one of these, and its filename is the section's identifier: `status.php` becomes `{plugin-slug}-status`.

This is the other half of Site Health, and it answers a different question from a `HealthCheck`. A check has a verdict — good, recommended, critical — and belongs to something that can be wrong. A section has no verdict: it lists what your plugin's state actually is, so the values reach you in a support ticket without a round trip. The whole tab is behind one "Copy site info to clipboard" button, which is what a user pastes.

Most plugins want exactly one, listing the handful of values you would ask for first.

## A section

`fields()` is keyed by field id; each field needs a translated `label` and a `value`. Anything a Service or Module can be injected into this can too, so the section reports real state.

```php
namespace Acme\Plugin\DebugSections;

use Acme\Plugin\Core\Modules\Options;
use Acme\Plugin\Core\Modules\SiteHealth\DebugSection;

return new class extends DebugSection {

    public Options $options;

    public function label(): string {
        return __( 'Acme', 'acme-plugin' );
    }

    public function fields(): array {
        return array(
            'api_key' => array(
                'label' => __( 'API key', 'acme-plugin' ),
                'value' => '' !== (string) $this->options->get( 'api_key', '' )
                    ? __( 'Set', 'acme-plugin' )
                    : __( 'Missing', 'acme-plugin' ),
                'debug' => '' !== (string) $this->options->get( 'api_key', '' ) ? 'set' : 'missing',
            ),
            'last_sync' => array(
                'label' => __( 'Last sync', 'acme-plugin' ),
                'value' => (string) $this->options->get( 'last_sync', __( 'Never', 'acme-plugin' ) ),
            ),
        );
    }
};
```

## Generated starting point

[`wp zestry make debug-section <name>`](../../commands/make-debug-section.md) writes this file:

```php
<?php
/**
 * example debug section.
 */

declare( strict_types=1 );

// Loaded by WordPress, never requested directly.
\defined( 'ABSPATH' ) || exit;

use Acme\Plugin\Core\Modules\SiteHealth\DebugSection;

return new class() extends DebugSection {

	// The section id is this file's name -- {plugin-slug}-example. Nothing a
	// user sees depends on it, but anything filtering `debug_information` by
	// that key does.

	// The heading shown above this panel on Site Health -> Info.
	public function label(): string {
		return 'Example';
	}

	// The values the panel lists, keyed by field id. Each needs a translated
	// label and a value; add 'debug' for a short, untranslated version that
	// goes into the copied text a user pastes into a support ticket.
	//
	// Anything a Module or Service can be injected into this can too: declare
	// a typed public property and report real state rather than guessing.
	public function fields(): array {
		return array(
			'version' => array(
				'label' => 'Version',
				'value' => $this->get_plugin()->get_version(),
			),
		);
	}

	// A sentence under the heading, if the labels do not speak for themselves.
	//
	// public function description(): string {
	//     return 'What this plugin is currently doing.';
	// }

	// Keep a field out of the copied text with 'private' => true in the field
	// above, or the whole panel out with this. The value is still shown on
	// screen either way, so never put a credential in one at all.
	//
	// public function is_private(): bool {
	//     return true;
	// }
};
```

## You must implement

These 2 methods are abstract: a subclass that does not declare all of them will not load.

### `label()`

The heading shown above this panel.

```php
abstract public function label(): string
```

|  | Details |
|---|---|
| **Parameters** | — |
| **Return** | A short, translated label — usually your plugin's name |
| **Throws** | — |

<br>

### `fields()`

The values this panel lists.

```php
abstract public function fields(): array
```

|  | Details |
|---|---|
| **Parameters** | — |
| **Return** | `array` |
| **Throws** | — |

Keyed by field id, in the shape WordPress reads:

```php
return array(
    'api_key' => array(
        'label'   => __( 'API key', 'acme-plugin' ),
        'value'   => __( 'Set', 'acme-plugin' ),
        'debug'   => 'set',  // optional: what the copied text says
        'private' => false,  // optional: true keeps it out of the copy
    ),
);
```

`label` and `value` are required; a `value` may be an array, which is rendered as name/value pairs. `debug` replaces the value in the copied text, and should be short and untranslated — a user pasting into a ticket writes in their language, and you read the paste in yours.

**`private` is not redaction.** The value is still printed on screen, where the site's administrator can read it; it is only left out of the copied text. Never put a credential in a field at all.

## Methods you can use

### `get_id()`

The identifier this section is registered under.

```php
final public function get_id(): string
```

|  | Details |
|---|---|
| **Parameters** | — |
| **Return** | `string` |
| **Throws** | — |

Your filename with the plugin slug prefixed, since `debug_information` is one array shared by every plugin: `status.php` gives `{plugin-slug}-status`.

<br>

### `description()`

A sentence under the heading, explaining what the panel is.

```php
public function description(): string
```

|  | Details |
|---|---|
| **Parameters** | — |
| **Return** | `string` |
| **Throws** | — |

Rendered inside a paragraph, so inline HTML only. Empty by default — fields with clear labels rarely need one.

<br>

### `show_count()`

Whether to show the number of fields beside the heading.

```php
public function show_count(): bool
```

|  | Details |
|---|---|
| **Parameters** | — |
| **Return** | `bool` |
| **Throws** | — |

<br>

### `is_private()`

Whether to leave the whole panel out of the copied text.

```php
public function is_private(): bool
```

|  | Details |
|---|---|
| **Parameters** | — |
| **Return** | `bool` |
| **Throws** | — |

Same caveat as a field's `private`: the panel is still on screen. Default false, since a section nobody can paste defeats the point.

<br>

### `site_health()`

The module that discovered this section.

```php
final protected function site_health(): SiteHealth
```

|  | Details |
|---|---|
| **Parameters** | — |
| **Return** | `SiteHealth` |
| **Throws** | — |

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

<br>

### `is_enabled()`

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

Most modules ask at discovery and drop the file there. `post-types` and `fields` ask at registration instead, so that a switched-off file still appears in what they list — a screen offering to switch a feature on can only offer what it can see. It registers nothing either way.
