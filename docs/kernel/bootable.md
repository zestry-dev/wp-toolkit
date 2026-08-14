<!--
    Generated from src/Kernel/Contracts/Bootable.php.
    Do not edit by hand: run `composer docs` after changing the source.
-->

# Bootable

Marks a module that does something without being called.

Most of a plugin only works when called: `Path` resolves a path when asked, `Views` renders when asked. A few act under their own steam — binding a hook, registering a post type, walking a directory — and those implement this.

```php
class Shortcode extends Module implements Bootable {

    public function on_boot(): void {
        add_shortcode( 'acme_form', array( $this, 'render' ) );
    }
}
```

**The `implements` clause is the declaration** — so what a module does without being asked is visible before you read the body.

Every module is listed in `bootstrap.php` either way. What this changes is *where*: a module implementing it goes under the hook it acts on, and the top level throws for one that does — because the top level is for modules that do nothing until something asks, and this one cannot keep that promise.

```php
// bootstrap.php
return array(
    'acme_plugin_loaded' => array(
        Shortcode::class,
    ),
);
```

## Methods

### `on_boot()`

What this module does on its own.

```php
public function on_boot(): void
```

Runs once, when the plugin builds the module — which is what listing it in `bootstrap.php` causes.

**Bind hooks here; do the work in them.** An entry file that calls `run()` as it loads — which is the documented shape, and what `ActivationHandler` requires — reaches this before WordPress has required `pluggable.php`, so there is no current user yet: `current_user_can()`, `wp_mail()` and the nonce functions are not defined and calling one is a fatal. It is also before `init`, so `__()` here asks for a text domain nothing has loaded. `$wpdb` *is* up, so a query works — but it runs on every request, including the ones that never needed it.

`Module::on_wp_init()` is the way out of all three, and where anything a module registers belongs.
