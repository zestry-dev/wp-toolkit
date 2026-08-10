<!--
    Generated from src/Kernel/Traits/WithPlugin.php.
    Do not edit by hand: run `composer docs` after changing the source.
-->

# WithPlugin

Provides plugin access and automatic dependency injection.

Satisfies the PluginAware contract. A class using the trait requests a service or a module by declaring a public or protected property typed as that class — for example `public Path $path;` — which the plugin populates via inject_modules() after set_plugin() runs. The type only has to be a Service subclass, which every Module is, so both kinds are injected the same way. Private properties are never injected (reflection cannot reach a private property declared on an ancestor class). Mark a property with #[NoInject] to exclude it from injection.

Declare injected dependencies `public` by convention: every module and DevTools command in this toolkit does, which keeps a module's dependencies uniformly inspectable. `protected` is equally supported by the mechanism and is the right choice only when a subclass hierarchy genuinely needs the dependency hidden from callers.

Typical usage, matching how Command, AdminPage, and AjaxAction consume this trait directly (a Service or Module gets the same behavior by extending the Service base class, which already uses this trait):

```php
class MyAction {
    use WithPlugin;

    public Path $path;

    public function handle(): void {
        $absolute = $this->path->get_plugin_path( 'some/file.php' );
    }
}
```

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

Use it to reach something you did not declare a property for — a module you need in one method only, or one you look up by a name computed at runtime. For anything you use throughout the class, declare a typed property instead and let it be injected.

```php
$this->get_plugin()->get( Options::class )->get( 'api_key' );
```
