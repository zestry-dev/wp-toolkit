<!--
    Generated from src/Modules/AdminPages/ModernAdminPage.php.
    Do not edit by hand: run `composer docs` after changing the source.
-->

# ModernAdminPage

[Taking the full canvas](#taking-the-full-canvas) &nbsp;·&nbsp; [Adding the page's own assets](#adding-the-pages-own-assets) &nbsp;·&nbsp; [You must implement](#you-must-implement) &nbsp;·&nbsp; [Methods you can use](#methods-you-can-use)

An AdminPage that gives a custom UI the whole admin canvas.

Extend this instead of `AdminPage` when a page renders its own full-width application shell — a JS-driven interface, a custom dashboard — rather than the usual WordPress `.wrap` layout. Everything `AdminPage` offers is unchanged: title, capability, menu placement, nonce-verified POST handling, module injection, and discovery from the same `admin-pages/` directory. The only difference is a critical-CSS reset of wp-admin's own chrome, so adopting it is a one-word edit to an existing page's `extends` clause.

The reset is inlined into `<head>` on core's always-registered `common` stylesheet handle rather than requested as a file of its own, so it applies before first paint: the page never renders in the default layout and then jumps once its stylesheet arrives. It is also strictly scoped — every rule sits behind the `{plugin-slug}-admin-page` body class that `AdminPages` adds only while one of this plugin's own pages is being displayed, so no other screen in wp-admin is touched.

What it changes:

- `#wpcontent` and `#wpbody-content` lose their padding, so the page starts
at the viewport edge and gets the full width, collapsed sidebar included.

- The body and `#wpbody-content` are forced white, and `#wpbody-content`
gets a `min-height` of the viewport less the admin bar, so a short page still fills the screen instead of ending in grey.

- `.wrap` and the module's own content wrapper lose their margins, and
everything inside them switches to `border-box` sizing.

- `#wpfooter` — the "Thank you for creating with WordPress" line and the
version number — is hidden.

- `#wpwrap` scrolls on its own below 782px and reverts to the browser's own
scrolling above it, which is what stops a mobile layout trapping content.

A page extending this needs no wrapper markup of its own: `AdminPages` already wraps whatever `render()` echoes in a `.{plugin-slug}-admin-page-content` div, and that div is the one element the reset deliberately spares.

> [!IMPORTANT]
> **Admin notices do not appear on these pages.** Every direct `div` child of `#wpbody-content` except the content wrapper and `#screen-meta` is hidden, which is what keeps another plugin's "Your license has expired" banner from landing in the middle of a custom layout — but it hides your own just as effectively. `add_settings_error()` and anything hooked to `admin_notices` will not be seen, so a page that needs to report success or failure has to render that itself, inside `render()`.

## Taking the full canvas

`wp zestry make page <name>` generates a file extending `AdminPage`. Changing that one word is the entire migration — every other method behaves exactly as it did.

```php
<?php
return new class() extends ModernAdminPage {
    public function title(): string {
        return __( 'Dashboard', 'my-plugin' );
    }
    public function capability(): string {
        return 'manage_options';
    }
    public function render(): void {
        // No .wrap and no wrapper div: the module supplies the container,
        // and a .wrap here would only have its margins reset anyway.
        echo '<div id="my-plugin-app"></div>';
    }
};
```

## Adding the page's own assets

`enqueue_assets()` is where the reset is injected, so a subclass overriding it must call the parent. Leave the call out and the page loads with wp-admin's chrome intact — which looks like this class silently not working, rather than like a missing line.

```php
public function enqueue_assets(): void {
    parent::enqueue_assets();

    wp_enqueue_script( 'my-plugin-dashboard' );
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

### `enqueue_assets()`

Enqueue this page's critical-CSS reset.

```php
public function enqueue_assets(): void
```

Overrides AdminPage::enqueue_assets(), so — per that method's contract — it only runs when this page is the one being displayed, not on every admin request. A subclass that also needs its own scripts/styles should override this method and call `parent::enqueue_assets()` to keep the reset.

<br>

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

The markup belongs in `views/`, not in a PHP string. An admin page is mostly a form — tables, fields, notices, a second form further down — and markup assembled by concatenation stops being reviewable long before it stops growing. `wp zestry make page` writes the template alongside the class, so there is one to render from the start.

```
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

`Views` puts one thing of its own in scope: `$this`, the service itself, so a subview is `$this->render( 'admin-pages/-fields', array( ... ) )`.

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
    $this->options->set( 'threshold', $this->threshold );
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

The Views service this page renders through.

```php
final protected function views(): Views
```

|  | Details |
|---|---|
| **Parameters** | — |
| **Return** | `Views` |
| **Throws** | — |

Resolved rather than injected, matching `admin_pages()`: a public property would put it on every page's own surface, which is not where it belongs when `view()` is the thing to call.

<br>

### `cookies()`

The Cookie service this page's flash values travel in.

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

<br>

### `is_enabled()`

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

Most modules ask at discovery and drop the file there. `post-types` and `fields` ask at registration instead, so that a switched-off file still appears in what they list — a screen offering to switch a feature on can only offer what it can see. It registers nothing either way.
