<!--
    Generated from src/Kernel/Attributes/NoInject.php.
    Do not edit by hand: run `composer docs` after changing the source.
-->

# NoInject

Excludes a property from automatic dependency injection.

By default the plugin injects every public or protected property typed as a Service subclass — which includes every Module. Mark a property with this attribute to opt it out, so the plugin leaves it untouched and the declaring class manages it itself. This is the escape hatch for a property that holds a hand-built, lazily created, keyed, or test-supplied instance rather than the shared one.

```php
use Acme\Plugin\Core\Kernel\Attributes\NoInject;

class Reports extends Module {
    public Path $path;                 // injected by the plugin

    #[NoInject]
    private ?Options $api = null;      // managed by this class, never injected
}
```
