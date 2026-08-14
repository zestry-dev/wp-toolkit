<!--
    Generated from src/Kernel/Traits/WithPlugin.php.
    Do not edit by hand: run `composer docs` after changing the source.
-->

# WithPlugin

Provides plugin access and automatic dependency injection.

Satisfies the PluginAware contract. A class using the trait asks for a service by declaring a public or protected property typed as it — for example `public Path $path;` — which the plugin populates via _inject_services() after set_plugin() runs.

**Services only.** A service is built when something asks for it and does nothing else, so a property is an honest way to ask. A module *boots* when it is built, and a property declaration hides that behind a type name — so one typed as a Module throws, naming the property and the call to use instead. Ask for a module where you need it: `$this->get_plugin()->get( Options::class )`.

Private properties are never injected (reflection cannot reach a private property declared on an ancestor class). Mark a property with #[NoInject] to exclude it from injection.

Declare injected dependencies `public` by convention, which keeps a class's dependencies inspectable. `protected` is equally supported, and is the right choice only when a subclass hierarchy genuinely needs the dependency hidden from callers.

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

How you reach a module, always: building one boots it, so the cost belongs at the call rather than hidden in a property declaration. Also how you reach a service you look up by a name computed at runtime.

```php
$this->get_plugin()->get( Options::class )->get( 'api_key' );
```
