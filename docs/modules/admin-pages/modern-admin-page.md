<!--
    Generated from src/Modules/AdminPages/ModernAdminPage.php.
    Do not edit by hand: run `composer docs` after changing the source.
-->

# ModernAdminPage

[Taking the full canvas](#taking-the-full-canvas) &nbsp;·&nbsp; [Adding the page's own assets](#adding-the-pages-own-assets) &nbsp;·&nbsp; [You must implement](#you-must-implement) &nbsp;·&nbsp; [Methods you can use](#methods-you-can-use)

An AdminPage that gives a custom UI the whole admin canvas.

Extend this instead of `AdminPage` when a page renders its own full-width application shell — a JS-driven interface, a custom dashboard — rather than the usual WordPress `.wrap` layout. Everything `AdminPage` offers is unchanged: title, capability, menu placement, nonce-verified POST handling, wiring, and discovery from the same `resources/admin-pages/` directory. Adopting it is a one-word edit to an existing page's `extends` clause.

The difference is a CSS reset, inlined before first paint so the page never renders in the default layout and then jumps. It applies only while one of this plugin's own pages is displayed, and no other screen in wp-admin is touched. What it changes:

- `#wpcontent` and `#wpbody-content` lose their padding, so the page starts
at the viewport edge and gets the full width, collapsed sidebar included.
- The background is white, and a short page still fills the screen rather
than ending in grey.
- `.wrap` and the content wrapper lose their margins, and everything inside
them is `border-box`.
- `#wpfooter` is hidden.
- `#wpwrap` scrolls on its own below 782px, which stops a mobile layout
trapping content.

Write no wrapper markup of your own: `AdminPages` already wraps whatever `render()` echoes in a `.{plugin-slug}-admin-page-content` div, which is the one element the reset spares.

> [!IMPORTANT]
> **Admin notices do not appear on these pages.** Everything `#wpbody-content` holds except your content and `#screen-meta` is hidden, yours included — so `add_settings_error()` and anything hooked to `admin_notices` goes unseen. A page that reports success or failure has to render that itself, in `render()`.

## Taking the full canvas

`wp zt make page <name>` generates a file extending `AdminPage`. Changing that one word is the entire migration — every other method behaves exactly as it did.

```php
<?php
return new class() extends ModernAdminPage {
    public function title(): string {
        return __( 'Dashboard', 'acme-plugin' );
    }
    public function capability(): string {
        return 'manage_options';
    }
    public function render(): void {
        // No .wrap and no wrapper div: the module supplies the container,
        // and a .wrap here would only have its margins reset anyway.
        echo '<div id="acme-plugin-app"></div>';
    }
};
```

## Adding the page's own assets

Override `enqueue_assets()`, which runs only while this page is being displayed. The reset is not in it, so there is no `parent::` call to make.

```php
public function enqueue_assets(): void {
    wp_enqueue_script( 'acme-plugin-dashboard' );
}
```

## You must implement

These 3 methods are abstract: a subclass that does not declare all of them will not load.

### `title()`

The page title shown in the browser and at the top of the page.

```php
abstract public function title(): string
```

|  | Details |
|---|---|
| **Parameters** | — |
| **Return** | `string` |
| **Throws** | — |

<br>

### `capability()`

The capability a user must have to see and open the page.

```php
abstract public function capability(): string
```

|  | Details |
|---|---|
| **Parameters** | — |
| **Return** | `string` |
| **Throws** | — |

<br>

### `render()`

Render the page markup. Output is echoed inside the admin wrapper.

```php
abstract public function render(): void
```

## Methods you can use

### `menu_title()`

The menu label. Defaults to the page title.

```php
public function menu_title(): string
```

|  | Details |
|---|---|
| **Parameters** | — |
| **Return** | `string` |
| **Throws** | — |

<br>

### `parent()`

Where the page is placed in the admin menu.

```php
public function parent(): ParentMenu|string|null
```

|  | Details |
|---|---|
| **Parameters** | — |
| **Return** | `ParentMenu\|string\|null` |
| **Throws** | — |

Return a ParentMenu case to nest under a core WordPress menu; a fully-qualified page slug — build a sibling's with `$this->get_page_slug( 'dashboard' )` rather than writing the prefix — or an existing WordPress menu slug such as `edit.php`, to nest under that; or null to use the folder-based placement (a nested file nests under its top-level folder's page — WordPress admin menus are only two levels deep, so `dashboard/adv/tuning.php` still lands under `dashboard`) or a top-level menu at the root.

An explicit non-null return here always overrides the folder-based placement.

<br>

### `is_hidden()`

Whether this page is reachable by URL but absent from every menu.

```php
public function is_hidden(): bool
```

|  | Details |
|---|---|
| **Parameters** | — |
| **Return** | `bool` |
| **Throws** | — |

For a screen nobody browses to: a confirmation step, a per-item editor reached from a row action, the far side of a redirect. Return true and the page registers exactly as any other — `get_page_url()` still builds its address, `capability()` is still enforced, `handle_submit()` still runs — but nothing lists it.

```php
public function is_hidden(): bool {
    return true;
}
```

`parent()` and `position()` stop meaning anything, since there is no menu to sit in, and no other page may nest under this one.

<br>

### `menu()`

Which admin menu the page appears in.

```php
public function menu(): AdminMenu
```

|  | Details |
|---|---|
| **Parameters** | — |
| **Return** | `AdminMenu` |
| **Throws** | — |

The default is the ordinary per-site admin. Return `AdminMenu::Network` for a page that belongs to the network administrator on a multisite install — settings that apply to every site, and are not a single site's to change:

```php
public function menu(): AdminMenu {
    return AdminMenu::Network;
}

public function capability(): string {
    return 'manage_network_options';
}
```

Pick the capability to match — `manage_options` is a site administrator's, and every site has one. A network page is inert on a single-site install.

The two menus hold different sections, so a network page's `parent()` is limited to those the network menu has.

<br>

### `position()`

The menu position, or null for the default ordering.

```php
public function position(): ?int
```

|  | Details |
|---|---|
| **Parameters** | — |
| **Return** | `?int` |
| **Throws** | — |

This is the mechanism for controlling where this page sits in the admin menu: the value is passed straight through as WordPress's own `$position` argument to `add_menu_page()`/`add_submenu_page()`. The module registers on `admin_menu` at WordPress's default priority and exposes no hook-priority knob, because shifting *when* registration runs is a far blunter and less predictable way to reach the same goal than declaring the position outright, per page, here.

<br>

### `icon()`

The top-level menu icon (a dashicon class or image URL). Ignored for submenus.

```php
public function icon(): string
```

|  | Details |
|---|---|
| **Parameters** | — |
| **Return** | `string` |
| **Throws** | — |

<br>

### `enqueue_assets()`

Enqueue CSS/JS for this page. Called only when the page is being displayed.

```php
public function enqueue_assets(): void
```

<br>

### `handle_submit()`

Handle a validated POST submission (called after the nonce check passes).

```php
public function handle_submit(): void
```

Runs on `load-{$hook}`, before WordPress has emitted anything, so a redirect from here works — which is what it is for. Falling through to `render()` instead leaves the browser's current request a POST, so a refresh resubmits.

<br>

### `view( $view, $data )`

Render one of this plugin's templates as this page's markup.

```php
public function view( string $view, array $data = array() ): void
```

|  | Details |
|---|---|
| **Parameters** | `$view` — A view name, relative to the views root<br>`$data` — Variables for the template |
| **Return** | — |
| **Throws** | `InvalidArgumentException` — When the views root or the view is missing |

The markup belongs in `resources/views/`, not in a PHP string. An admin page is mostly a form — tables, fields, notices, a second form further down — and markup assembled by concatenation stops being reviewable long before it stops growing. `wp zt make page` writes the template alongside the class, so there is one to render from the start.

```php
public function render(): void {
    $this->view(
        'admin-pages/settings',
        array(
            'title'  => $this->title(),
            'action' => $this->get_page_url(),
            'nonce'  => $this->get_nonce_action(),
            'items'  => $this->items,
        )
    );
}
```

The template gets what this call passes and nothing else — it cannot reach the page for anything the call left out. So this call *is* the list of the template's inputs, and you can read it without opening the template.

`Views` puts one thing of its own in scope: `$this`, the module itself, so a subview is `$this->render( 'admin-pages/-fields', array( ... ) )`.

<br>

### `set_flash( $value )`

Keep something for the page you are about to redirect to.

```php
final public function set_flash( mixed $value ): bool
```

|  | Details |
|---|---|
| **Parameters** | `$value` — What the next request should be told |
| **Return** | Whether it was stored; false once output has begun |
| **Throws** | — |

`handle_submit()` redirects, because the browser's current request is still the POST and a refresh would resubmit it — and the redirect throws away everything the handler knew. This is what survives it, without going in the URL where a bookmark would replay it:

```php
public function handle_submit(): void {
    $this->with( Options::class )->set( 'threshold', $this->threshold );
    $this->set_flash( __( 'Settings saved.', 'acme-plugin' ) );

    wp_safe_redirect( $this->get_page_url() );
    exit;
}

public function render(): void {
    $this->view( 'admin-pages/settings', array( 'notice' => $this->get_flash( '' ) ) );
}
```

Anything serializable, so an array carries a notice and a count together. Encrypted on the way out by `Cookie`, so nothing of it is readable in the browser.

<br>

### `get_flash( $fallback )`

Take what the request before this one left, which reads only once.

```php
final public function get_flash( mixed $fallback = null ): mixed
```

|  | Details |
|---|---|
| **Parameters** | `$fallback` — Returned when nothing was flashed |
| **Return** | `mixed` |
| **Throws** | — |

The second call gives the fallback, so a refresh shows no notice for a save that already happened — the thing `?updated=1` in the URL gets wrong.

<br>

### `get_nonce_action()`

The nonce action string scoping this page's forms.

```php
final public function get_nonce_action(): string
```

|  | Details |
|---|---|
| **Parameters** | — |
| **Return** | `string` |
| **Throws** | — |

<br>

### `nonce_field()`

Output a hidden nonce field for this page's forms.

```php
final public function nonce_field(): void
```

<br>

### `get_page_slug( $page )`

Full, plugin-prefixed slug for a page identified by its short name.

```php
final public function get_page_slug( ?string $page = null ): string
```

|  | Details |
|---|---|
| **Parameters** | `$page` — A page's own short slug (e.g. `settings`), or null for this page |
| **Return** | The `{plugin-slug}` or `{plugin-slug}-{page-slug}` identifier |
| **Throws** | — |

Delegates to the AdminPages module so a page never writes the plugin prefix. Pass no argument for this page's own (already-prefixed) slug, which the module resolves from its registry by deriving it from the page's path within the pages directory — `dashboard/settings.php` becomes `{plugin}-dashboard-settings` and `dashboard/index.php` becomes `{plugin}-dashboard`. The page itself stores no slug state.

This is the single spelling for a page's own identity, matching `PostType::get_post_type()` and `Taxonomy::get_taxonomy()` for the same concept.

<br>

### `get_page_url( $page, $args )`

Admin URL for a page identified by its short name.

```php
final public function get_page_url( ?string $page = null, array $args = array() ): string
```

|  | Details |
|---|---|
| **Parameters** | `$page` — A page's own short slug, or null for this page<br>`$args` — Optional query arguments |
| **Return** | The page URL |
| **Throws** | — |

Delegates to the AdminPages module. Pass no page for this page's own URL.

<br>

### `admin_pages()`

The AdminPages module that manages this page.

```php
final protected function admin_pages(): AdminPages
```

|  | Details |
|---|---|
| **Parameters** | — |
| **Return** | `AdminPages` |
| **Throws** | — |

<br>

### `views()`

The `views` module this page renders through.

```php
final protected function views(): Views
```

|  | Details |
|---|---|
| **Parameters** | — |
| **Return** | `Views` |
| **Throws** | — |

An accessor rather than a public property, matching `admin_pages()`: a property would put it on every page's own surface, which is not where it belongs when `view()` is the thing to call.

<br>

### `cookies()`

The `cookie` module this page's flash values travel in.

```php
final protected function cookies(): Cookie
```

|  | Details |
|---|---|
| **Parameters** | — |
| **Return** | `Cookie` |
| **Throws** | — |

<br>

### `get_page_css_classname( $page )`

A BEM modifier class scoping a rule to one page, delegated to the module.

```php
final protected function get_page_css_classname( ?string $page = null ): string
```

|  | Details |
|---|---|
| **Parameters** | `$page` — A page's own short slug, or null for this page |
| **Return** | The `{base}--{slug}` class name |
| **Throws** | — |

<br>

### `get_base_css_classname()`

The BEM block class shared by every page of this plugin.

```php
final protected function get_base_css_classname(): string
```

|  | Details |
|---|---|
| **Parameters** | — |
| **Return** | The `{plugin-slug}-admin-page` class name |
| **Throws** | — |

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

<br>

### `is_enabled()`

*Inherited from [`WithEnablement`](../../kernel/with-enablement.md).*

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
