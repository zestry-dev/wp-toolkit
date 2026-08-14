<!--
    Generated from src/Modules/AdminPages/AdminPage.php.
    Do not edit by hand: run `composer docs` after changing the source.
-->

# AdminPage

[Generated starting point](#generated-starting-point) &nbsp;·&nbsp; [You must implement](#you-must-implement) &nbsp;·&nbsp; [Methods you can use](#methods-you-can-use)

Base class for a file-based WordPress admin page.

A page file returns an AdminPage subclass instance; the AdminPages module wires it (assigning the plugin, so `with()` reaches every module), registers it in the admin menu using the typed accessors below, and dispatches to render() when the page is viewed. The page's slug is derived from its path within the pages directory, so `resources/admin-pages/settings.php` becomes `{plugin-slug}-settings` and `resources/admin-pages/reports/index.php` becomes `{plugin-slug}-reports`. The root `resources/admin-pages/index.php`, if present, becomes the bare `{plugin-slug}` itself.

Authorization is enforced by the module before render(): the current user must satisfy capability(), and a nonce is verified on POST. A page therefore only has to describe itself (title, capability, placement) and render its markup.

A file at `resources/admin-pages/settings.php` registers as a top-level menu page with the slug `{plugin}-settings` (see `get_page_slug()`). Return a ParentMenu case from `parent()` to nest it under a core WordPress menu instead, such as `ParentMenu::Settings`. Reach any declared module with `$this->with( Path::class )`; a page also has `views()`, `cookies()` and `admin_pages()` as typed accessors. `wp zt make page <name>` generates a starting point.

A page rendering its own full-width application shell rather than the usual WordPress "wrap" layout should extend `ModernAdminPage` instead, which is this class plus a critical-CSS reset of wp-admin's default chrome. It satisfies the same discovery guard, so it is a drop-in swap for the `extends AdminPage` a generated file starts with.

## Generated starting point

[`wp zt make page <name>`](../../commands/make-page.md) writes this file:

```php
<?php
/**
 * example admin page.
 */

declare( strict_types=1 );

// Loaded by WordPress, never requested directly.
\defined( 'ABSPATH' ) || exit;

use Acme\Plugin\Core\Modules\AdminPages\AdminPage;
use Acme\Plugin\Core\Modules\AdminPages\ParentMenu;

// Rendering your own full-width UI rather than the usual WordPress "wrap"
// layout? Swap AdminPage for ModernAdminPage above and below -- same class plus
// a critical-CSS reset of wp-admin's padding and legacy chrome, so a custom
// shell gets the full canvas without a layout shift while its stylesheet loads.
return new class() extends AdminPage {

	// The page slug is this file's name -- ?page={plugin-slug}-example.
	// Renaming the file changes the URL, so every bookmark, link and redirect
	// to the old one stops working.

	// Page title: shown in the browser tab and as the <h1> if render() uses it.
	public function title(): string {
		return 'Example';
	}

	// WP capability required to see and open this page -- enforced by the
	// AdminPages module before render()/handle_submit() ever run.
	public function capability(): string {
		return 'manage_options';
	}

	// null (default): placed by folder structure (a file inside a folder
	// nests under that folder's own page; otherwise it's top-level). Return a
	// ParentMenu case (e.g. ParentMenu::Settings) to nest under a core
	// WordPress menu instead, or a page slug (build a sibling's via
	// $this->get_page_slug( 'other-file' )) to nest under that.
	public function parent(): ParentMenu|string|null {
		return null;
	}

	// null (default): WordPress's own default ordering among sibling menu items.
	public function position(): ?int {
		return null;
	}

	// Top-level menu icon only -- ignored entirely if parent() nests this
	// page under something. A dashicon class name or an image URL.
	public function icon(): string {
		return 'dashicons-admin-generic';
	}

	// Enqueue this page's own CSS/JS here -- called only when this specific
	// page is being displayed, not on every admin request.
	public function enqueue_assets(): void {}

	// Runs only after the module has verified the POST request's nonce and
	// capability(), before render() would run in the same request.
	//
	// Redirect once you have saved, rather than falling through to render():
	// without it the browser's current request is still the POST, so a refresh
	// resubmits the form and your user gets "Confirm Form Resubmission" instead
	// of their page -- and a second save.
	//
	// The redirect throws away everything this method knew, so anything the next
	// request should say goes through set_flash(). It reads once, which is what
	// keeps the notice off a refresh -- and off a bookmark, as `?updated=1` in
	// the URL would not.
	public function handle_submit(): void {
		// $name = isset( $_POST['name'] ) ? \sanitize_text_field( \wp_unslash( $_POST['name'] ) ) : '';

		$this->set_flash( \__( 'Saved.', 'acme-plugin' ) );

		\wp_safe_redirect( $this->get_page_url() );
		exit;
	}

	// Outputs the page's markup, wrapped in the admin container. Called on
	// every GET view, and again after handle_submit() unless that redirected.
	//
	// The markup lives in views/admin-pages/settings.php, generated alongside
	// this file. The template gets exactly what is named here and nothing else
	// of this page, so its inputs are readable without opening it. Add your own
	// alongside these.
	//
	// Echoing markup from here works for something tiny, and stops working
	// sooner than it looks: an admin page grows a table, then a notice, then a
	// second form.
	public function render(): void {
		$this->view(
			'admin-pages/settings',
			array(
				'title'  => $this->title(),
				'action' => $this->get_page_url(),
				'nonce'  => $this->get_nonce_action(),
				// Left by handle_submit() before it redirected. Reads once,
				// so a refresh shows no notice for a save that already
				// happened.
				'notice' => $this->get_flash( '' ),
			)
		);
	}
};
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

Handle a POST submission, once its nonce and capability have passed.

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

Safe to call from `render()`, which is where a page wants it: the module took the value on `load-{$hook}`, while a cookie could still be sent to clear it.

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
