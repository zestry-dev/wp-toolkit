<!--
    Generated from src/Modules/AdminPages/AdminPages.php.
    Do not edit by hand: run `composer docs` after changing the source.
-->

# AdminPages

Discovers `admin-pages/` &nbsp;·&nbsp; Each file returns [`AdminPage`](admin-page.md) &nbsp;·&nbsp; Dependencies [`cookie`](../../services/cookie/), [`path`](../../services/path/), [`request`](../../services/request/), [`views`](../../services/views/)

Discovers plugin admin pages and registers them in the WordPress admin menu.

A pages directory contains PHP files named after the page, such as `admin-pages/settings.php`, each returning an AdminPage instance. On an admin request the module wires each page, registers it via the appropriate WordPress menu function (top-level, a core submenu chosen by its ParentMenu, or a custom parent), and dispatches to the page's render() when it is viewed — enforcing the page capability first. A POST is handled a step earlier, on `load-{$hook}`, so a page can still redirect after saving.

[Adding it](#adding-it) &nbsp;·&nbsp; [A minimal page file](#a-minimal-page-file) &nbsp;·&nbsp; [Where the markup goes](#where-the-markup-goes) &nbsp;·&nbsp; [Changing the defaults](#changing-the-defaults) &nbsp;·&nbsp; [Writing an AdminPage](#writing-an-adminpage) &nbsp;·&nbsp; [Related classes](#related-classes) &nbsp;·&nbsp; [Constants](#constants) &nbsp;·&nbsp; [Methods](#methods) &nbsp;·&nbsp; [See also](#see-also)

## Adding it

```bash
wp zt add module admin-pages
```

> [!IMPORTANT]
> **A module is built because `bootstrap.php` lists it.** `AdminPages` binds its hooks when the plugin builds it, so it has to be listed there — which `wp zt add` writes for you. Left out, nothing is discovered and nothing reports why; [`wp zt doctor`](../../commands/doctor.md) is what catches it.

```php
// bootstrap.php
return array(
    AdminPages::class,
);
```

## A minimal page file

The actual authoring surface for most developers is not this class but the page files it discovers. A page such as `admin-pages/settings.php` need only return an AdminPage subclass instance — the module assigns the plugin, injects any typed module dependencies, derives the slug from the file path, and wires up the menu entry.

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

`AdminPage::view()` renders through the `Views` service, and the template gets what that call passes and nothing else — it cannot reach the page for anything the call left out. So the call is the list of the template's inputs, readable without opening the template.

```php
<?php // views/admin-pages/settings.php
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

`$this` inside a template is the Views service — rendering a subview is the same call every other caller makes, and costs no variable name.

## Changing the defaults

`AdminPages` takes no configuration. The bare `modules` entry above is all it needs — reach it with `$plugin->get( AdminPages::class )`, or declare a property of its type and have it injected.

## Writing an AdminPage

A file in `admin-pages/` returns an [`AdminPage`](admin-page.md) instance, which `wp zt make page <name>` generates.

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
const PAGES_ROOT = 'admin-pages';
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

Almost everything a module registers — a post type, a block, a WP-CLI command — has to happen on `init`, and a plain `add_action( 'init', ... )` is a callback that never runs once `init` has passed. A module can be resolved on either side of it: `Plugin::run()` is synchronous, so an entry file that calls it at plugin load is ahead of `init`, while one that calls it from a later hook — or a `get()` during a request — is behind. This behaves the same either way, so a module never has to care which.

The callback receives the module, matching the initializer signature, so a closure declared elsewhere needs no `use` to reach it:

```php
protected function on_boot(): void {
    $this->on_wp_init( function ( self $module ): void {
        $module->register_widgets();
    } );
}
```

`$priority` is WordPress's own, for ordering against something else on `init` — another plugin's registration, or a post type a taxonomy of yours attaches to. **It applies only when `init` is still ahead**, which is the case for the documented entry file, since `run()` at plugin load is well before `init`. A module resolved *after* `init` has fired runs its callback immediately, because there is no longer a queue to be ordered in — so two callbacks registered then run in the order they were registered, whatever priority each asked for. Ordering that has to hold in both cases belongs inside one callback.

## See also

- [`AdminPage`](admin-page.md) — what a file in `admin-pages/` returns
- [`cookie`](../../services/cookie/) — copied in alongside this one
- [`path`](../../services/path/) — copied in alongside this one
- [`request`](../../services/request/) — copied in alongside this one
- [`views`](../../services/views/) — copied in alongside this one
- [`Module`](../module.md) — what every module inherits
- [`wp zt add module admin-pages`](../../commands/add-module.md) — the command that copies it
