<!--
    Generated from src/Kernel/Contracts/PluginAware.php.
    Do not edit by hand: run `composer docs` after changing the source.
-->

# PluginAware

Contract for objects the plugin can wire.

An object implementing this interface can be given the shared plugin, which is what lets it reach every module through `with()`. The `WithPlugin` trait provides a conforming implementation, so a class using the trait only has to declare that it implements this.

`Module` already does both. This is for everything else the plugin wires but does not build — a CLI command, an AJAX action, an admin page, anything a discovered file returns — which reaches its dependencies exactly the way a module does.

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
