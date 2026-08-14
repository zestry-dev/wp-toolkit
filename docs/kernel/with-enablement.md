<!--
    Generated from src/Kernel/Traits/WithEnablement.php.
    Do not edit by hand: run `composer docs` after changing the source.
-->

# WithEnablement

[Registering only when something else is present](#registering-only-when-something-else-is-present) &nbsp;·&nbsp; [Behind one of your own settings](#behind-one-of-your-own-settings) &nbsp;·&nbsp; [Methods](#methods)

Lets a discovered file decide whether it registers at all.

Every file-based thing this toolkit finds — an action, a route, a post type, a field, a schedule, a page, a block — registers because it is on disk. That is the whole convention, and it has no answer for the thing you want to ship but not switch on: a post type behind a feature flag, a route that needs a plugin that may not be installed, a page that only makes sense on multisite.

Overriding `is_enabled()` answers it, and deleting the override is how you turn the thing back on.

## Registering only when something else is present

Checked once, before anything is registered, so a false costs nothing afterwards — no hook is bound and WordPress never hears of it.

```php
return new class() extends PostType {

    public function is_enabled(): bool {
        return class_exists( 'WooCommerce' );
    }

    // ...
};
```

## Behind one of your own settings

The instance is wired before it is asked, so it can reach the whole plugin here — which is what makes a stored setting usable as the switch.

```php
return new class() extends RestRoute {

    public function is_enabled(): bool {
        return (bool) $this->get_plugin()->get( Options::class )->get( 'expose_public_api' );
    }

    // ...
};
```

## Methods

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

It registers nothing either way.
