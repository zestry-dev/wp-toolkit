<!--
    Generated from src/Kernel/Traits/WithPlugin.php.
    Do not edit by hand: run `composer docs` after changing the source.
-->

# WithPlugin

Gives a class the plugin, and `with()` to reach every module through it.

Satisfies the PluginAware contract. `Module` already uses it, so a module has this for free; a class the plugin did not build — a `Command`, an `AjaxAction`, an `AdminPage`, anything a discovered file returns — uses it directly and is passed through `$plugin->wire( $object )`.

```php
class MyAction {
    use WithPlugin;

    public function handle(): void {
        $absolute = $this->with( Path::class )->get_plugin_path( 'some/file.php' );
    }
}
```

One way to reach a dependency, and it reads the same in a module, a command and a template helper.

## Methods

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

For the plugin's own answers — its slug, its entry file, the headers it declares. To reach another module, `with()` is shorter and says what it is doing.

<br>

### `with( $name )`

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
