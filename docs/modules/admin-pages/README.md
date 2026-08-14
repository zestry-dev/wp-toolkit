<!--
    Generated from src/Modules/AdminPages/AdminPages.php.
    Do not edit by hand: run `composer docs` after changing the source.
-->

# AdminPages

Discovers `resources/admin-pages/` &nbsp;·&nbsp; Each file returns [`AdminPage`](admin-page.md) &nbsp;·&nbsp; Dependencies [`cookie`](../cookie/), [`path`](../path/), [`request`](../request/), [`views`](../views/)

Discovers plugin admin pages and registers them in the WordPress admin menu.

A pages directory contains PHP files named after the page, such as `resources/admin-pages/settings.php`, each returning an AdminPage instance. On an admin request the module wires each page, registers it via the appropriate WordPress menu function (top-level, a core submenu chosen by its ParentMenu, or a custom parent), and dispatches to the page's render() when it is viewed — enforcing the page capability first. A POST is handled a step earlier, on `load-{$hook}`, so a page can still redirect after saving.

[Adding it](#adding-it) &nbsp;·&nbsp; [A minimal page file](#a-minimal-page-file) &nbsp;·&nbsp; [Where the markup goes](#where-the-markup-goes) &nbsp;·&nbsp; [Changing the defaults](#changing-the-defaults) &nbsp;·&nbsp; [Writing an AdminPage](#writing-an-adminpage) &nbsp;·&nbsp; [Related classes](#related-classes) &nbsp;·&nbsp; [Constants](#constants) &nbsp;·&nbsp; [Methods](#methods) &nbsp;·&nbsp; [See also](#see-also)

## Adding it

```bash
wp zt add admin-pages
```

> [!IMPORTANT]
> **A module is built because `bootstrap.php` lists it, and the heading says when.** `AdminPages` acts the moment it is built, so it goes under the hook it acts on — which `wp zt add` writes for you. Left at the top level it throws; left out entirely, nothing is discovered and nothing reports why, which is what [`wp zt doctor`](../../commands/doctor.md) catches.

```php
// bootstrap.php
return array(
    'acme_plugin_loaded' => array(
        AdminPages::class,
    ),
);
```

`acme_plugin_loaded` is your plugin's own action, fired at the end of `run()` once every module is built — `{slug}_loaded`, so a plugin slugged `acme-crm` spells it `acme_crm_loaded`. It is the earliest heading that still has the whole plugin behind it.

## A minimal page file

The actual authoring surface for most developers is not this class but the page files it discovers. A page such as `resources/admin-pages/settings.php` need only return an AdminPage subclass instance — the module assigns the plugin, so `with()` reaches every module, derives the slug from the file path, and wires up the menu entry.

```php
<?php
return new class() extends AdminPage {
    public function title(): string {
        return __( 'Settings', 'acme-plugin' );
    }
    public function capability(): string {
        return 'manage_options';
    }
    public function render(): void {
        $this->view( 'admin-pages/settings' );
    }
};
```

## Where the markup goes

A page's markup belongs in a template, and `wp zt make page` writes one alongside the class. An admin page is mostly a form — a table, a notice, a second form further down — and markup assembled by concatenation stops being reviewable long before it stops growing.

`AdminPage::view()` renders through the `Views` module, and the template gets what that call passes and nothing else — it cannot reach the page for anything the call left out. So the call is the list of the template's inputs, readable without opening the template.

```php
<?php // resources/views/admin-pages/settings.php
?>
<div class="wrap">
    <h1><?php echo esc_html( $title ); ?></h1>
    <form method="post" action="<?php echo esc_url( $action ); ?>">
        <?php wp_nonce_field( $nonce ); ?>
        <?php $this->render( 'admin-pages/-fields', array( 'values' => $values ) ); ?>
        <?php submit_button(); ?>
    </form>
</div>
```

`$this` inside a template is the `views` module — rendering a subview is the same call every other caller makes, and costs no variable name.

## Changing the defaults

`AdminPages` takes no configuration. The entry above is all it needs — reach it with `$this->with( AdminPages::class )` from any module or discovered file, or `$plugin->get( AdminPages::class )` from your entry file.

## Writing an AdminPage

A file in `resources/admin-pages/` returns an [`AdminPage`](admin-page.md) instance, which `wp zt make page <name>` generates.

The toolkit also ships a specialised base to extend in place of `AdminPage`, satisfying the same guard:

- [`ModernAdminPage`](modern-admin-page.md) — an AdminPage that gives a custom UI the whole admin canvas

## Related classes

Shipped with this module, and written against directly:

- [`AdminMenu`](admin-menu.md) — enum, which of WordPress's admin menus a page belongs to
- [`ParentMenu`](parent-menu.md) — enum, the built-in WordPress menus an AdminPage can be nested under
- [`RendersCriticalStyles`](renders-critical-styles.md) — interface, a page with styles that have to be inlined before first paint

## Constants

### `PAGES_ROOT`

```php
const PAGES_ROOT = 'resources/admin-pages';
```

Default plugin-relative directory of page files.

## Methods

### `get_page_slug( $name )`

Build the full, plugin-prefixed slug for a page.

```php
public function get_page_slug( string $name ): string
```

|  | Details |
|---|---|
| **Parameters** | `$name` — The page's own name |
| **Return** | The `{plugin-slug}` or `{plugin-slug}-{page-slug}` identifier |
| **Throws** | — |

Both halves are read exactly as written, so the name you see in the URL is the name you gave the file. An empty local slug (the root index page) yields the bare plugin slug, with nothing to join it to.

A slug a URL could not carry is refused when the page is discovered, rather than repaired here — see `DiscoveryException::unsafe_page_slug()`.

<br>

### `get_page_url( $name, $args )`

Build the admin URL for a page identified by its short slug.

```php
public function get_page_url( string $name, array $args = array() ): string
```

|  | Details |
|---|---|
| **Parameters** | `$name` — The page's own name<br>`$args` — Optional query arguments |
| **Return** | The page URL |
| **Throws** | — |

<br>

### `get_page_url_for( $full_slug, $args )`

Build the admin URL for a page identified by its full, plugin-prefixed slug.

```php
public function get_page_url_for( string $full_slug, array $args = array() ): string
```

|  | Details |
|---|---|
| **Parameters** | `$full_slug` — The full `{plugin}-{page}` slug<br>`$args` — Optional query arguments |
| **Return** | The page URL |
| **Throws** | — |

<br>

### `get_pages()`

All discovered pages, indexed by full plugin page slug.

```php
public function get_pages(): array
```

|  | Details |
|---|---|
| **Parameters** | — |
| **Return** | `array` |
| **Throws** | — |

<br>

### `get_slug_of( $page )`

The full plugin slug of a given page.

```php
public function get_slug_of( AdminPage $page ): string
```

|  | Details |
|---|---|
| **Parameters** | `$page` — The page to identify |
| **Return** | The `{plugin-slug}` or `{plugin-slug}-{page-slug}` identifier |
| **Throws** | — |

Resolved from the discovery registry, so a page never needs to store its own slug. Because discovery runs on every admin request (admin_menu), the page is in the registry during render() too. A page that is not discovered (for example one constructed directly in a test) falls back to its file's own name.

<br>

### `get_base_css_classname()`

The BEM block class shared by every page of this plugin.

```php
final public function get_base_css_classname(): string
```

|  | Details |
|---|---|
| **Parameters** | — |
| **Return** | The `{plugin-slug}-admin-page` class name |
| **Throws** | — |

<br>

### `get_page_css_classname( $slug )`

A BEM modifier class scoping a rule to one page.

```php
final public function get_page_css_classname( AdminPage|string $slug ): string
```

|  | Details |
|---|---|
| **Parameters** | `$slug` — A page instance, or a slug to use verbatim |
| **Return** | The `{base}--{slug}` class name |
| **Throws** | — |

An AdminPage instance is identified by its full slug; a plain string is used as given (sanitized only, not plugin-prefixed) so a caller may pass an arbitrary identifier that is not one of this plugin's registered pages.

<br>

### `on_wp_init( $callback, $priority )`

*Inherited from [`Module`](../module.md).*

Run a callback on `init`, or immediately if `init` has already fired.

```php
final public function on_wp_init( callable $callback, int $priority = 10 ): void
```

|  | Details |
|---|---|
| **Parameters** | `$callback` — What to run<br>`$priority` — WordPress hook priority, honoured only while `init` is still ahead |
| **Return** | — |
| **Throws** | — |

Almost everything a module registers — a post type, a block, a WP-CLI command — has to happen on `init`, and a plain `add_action( 'init', ... )` is a callback that never runs once `init` has passed. A module can be built on either side of it: `Plugin::run()` is synchronous, so an entry file that calls it at plugin load is ahead of `init`, while one that calls it from a later hook is behind. This behaves the same either way, so a module never has to care which.

The callback receives the module, so a closure declared elsewhere needs no `use` to reach it:

```php
public function on_boot(): void {
    $this->on_wp_init( function ( self $module ): void {
        $module->register_widgets();
    } );
}
```

`$priority` is WordPress's own, for ordering against something else on `init` — another plugin's registration, or a post type a taxonomy of yours attaches to. **It applies only while `init` is still ahead**: a module built after `init` has fired runs its callback immediately, in registration order, whatever priority it asked for. Ordering that has to hold either way belongs inside one callback.

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

For the plugin's own answers — its slug, its entry file, the headers it declares. To reach another module, `with()` is shorter and says what it is doing.

<br>

### `with( $name )`

*Inherited from [`WithPlugin`](../../kernel/with-plugin.md).*

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

A module listed under a heading also throws when asked for before that hook has fired, since building it early would bind it on the wrong side of whatever it was declared to follow.

## See also

- [`AdminPage`](admin-page.md) — what a file in `resources/admin-pages/` returns
- [`cookie`](../cookie/) — copied in alongside this one
- [`path`](../path/) — copied in alongside this one
- [`request`](../request/) — copied in alongside this one
- [`views`](../views/) — copied in alongside this one
- [`Module`](../module.md) — what every module inherits
- [`wp zt add admin-pages`](../../commands/add.md) — the command that copies it
