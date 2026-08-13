<!--
    Generated from src/Kernel/Contracts/PluginAware.php.
    Do not edit by hand: run `composer docs` after changing the source.
-->

# PluginAware

Contract for objects the plugin can wire.

An object implementing this interface can receive the shared plugin and have its declared service and module dependencies injected. The WithPlugin trait provides a conforming implementation, so classes that use the trait only need to declare that they implement this interface. Modules, AJAX actions, and CLI commands are all plugin-aware and can be passed to Plugin::wire().

"Wiring" an object means performing both steps this interface exposes, in order: assign the shared plugin with set_plugin(), then populate the object's declared service-typed properties with _inject_services(), which needs the plugin already assigned to resolve them. Plugin::wire() and the module repository perform exactly this sequence for every plugin-aware object they construct, whether it is a registered module, a CLI command, or an AJAX action loaded from a file — so an object never needs to call these methods itself, only declare the typed properties it wants populated.

## Methods

### `set_plugin( $plugin )`

Assign the shared plugin instance.

```php
public function set_plugin( Plugin $plugin ): void
```

|  | Details |
|---|---|
| **Parameters** | `$plugin` — The plugin instance |
| **Return** | — |
| **Throws** | — |

The first half of wiring. Must run before _inject_services(), which reads the plugin assigned here to resolve the object's declared dependencies.

<br>

### `get_plugin()`

Get the shared plugin instance.

```php
public function get_plugin(): Plugin
```

|  | Details |
|---|---|
| **Parameters** | — |
| **Return** | The plugin instance |
| **Throws** | — |

<br>

### `_inject_services()`

Populate declared Service-typed properties from the plugin.

```php
public function _inject_services(): void
```

The second half of wiring. Resolves the plugin assigned by set_plugin(), so it must run after that method, not before.
