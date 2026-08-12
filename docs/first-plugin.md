# Your first plugin

One plugin, built end to end. **Acme Books** registers a `book` post type, serves it over a REST route, saves a setting from an admin page, writes to a log, and flushes rewrite rules on activation. Every file it contains is on this page in full.

Five files do the work, and the two ideas behind them are the whole toolkit: **a file in the right directory is a feature**, and a typed property is a dependency.

If you have not installed the toolkit before, [Getting started](getting-started.md) covers what `wp zt init` asks and why the code ends up in your namespace. This page assumes none of it.

## 1. Set up

Start with an empty plugin directory, `wp-content/plugins/acme-books/`. Everything below runs from inside it.

```bash
composer require zestry-dev/wp-toolkit --dev
```

`wp zt` is a WP-CLI command that lives in this package, and WordPress only loads a plugin's `vendor/autoload.php` for an **active** plugin. So the entry file and the activation come first:

```php
<?php
/**
 * Plugin Name: Acme Books
 * Description: A book library with a REST endpoint and a settings page.
 * Version:     1.0.0
 * Text Domain: acme-books
 */

declare( strict_types=1 );

require_once __DIR__ . '/vendor/autoload.php';
```

```bash
wp plugin activate acme-books
```

Now initialize. Answer `Acme\Books` for the namespace and take the defaults for the rest — or, if you are scripting this, add the `"Acme\\Books\\": "lib/"` PSR-4 entry to `composer.json` first and run `wp zt init --yes`, which needs it to infer the namespace from:

```bash
$ wp zt init
Namespace (e.g. Vendor\MyPlugin): Acme\Books
Text domain: (default: acme-books) acme-books
Source directory: (default: lib) lib
Copy the kernel into lib/Core/Kernel/ under Acme\Books? [Y/n] y
Created bootstrap.php. Read it with `$plugin->bootstrap()` in your entry file.
...
Success: Initialized. Run `wp zt add module <name>` to copy in feature modules.
```

That copied the kernel to `lib/Core/Kernel/`, added `"Acme\\Books\\": "lib/"` to your `composer.json`, and wrote an empty `bootstrap.php`. The `Plugin` class is now yours, at `Acme\Books\Core\Kernel\Plugin`.

`init` copies the kernel and nothing else. Add the five modules this plugin uses:

```bash
$ wp zt add module post-types rest-api admin-pages options log
Also adding required dependencies: path, request, transients, cookie, views
Added path
Added post-types
Added request
Added rest-api
Added transients
Added cookie
Added views
Added admin-pages
Added options
Added log
Declared in bootstrap.php: post-types, rest-api, admin-pages, options, log
Success: Done.
```

Each module arrives after whatever it needs, which is why the list interleaves. `path` came along because three of those modules resolve plugin-relative directories with it, `request` because that is how a REST route declares what it accepts, and `views` because that is how an admin page renders its markup. `cookie` is how a page carries a notice across the redirect that follows a save, and it brings `transients` for a payload too big for a cookie. All five are **services**, so none of them is declared anywhere — each gets built the first time something asks for it.

## 2. The entry file

`lib/Core/Kernel/` exists now, so the entry file can build a `Plugin`:

```php
<?php
/**
 * Plugin Name: Acme Books
 * Description: A book library with a REST endpoint and a settings page.
 * Version:     1.0.0
 * Text Domain: acme-books
 */

declare( strict_types=1 );

require_once __DIR__ . '/vendor/autoload.php';

use Acme\Books\Core\Kernel\Plugin;

function acme_books(): Plugin {
    static $plugin = null;

    $plugin ??= ( new Plugin( __FILE__ ) )->bootstrap()->run();

    return $plugin;
}

acme_books();
```

That is the entry file finished. It does not change as the plugin grows: `bootstrap()` reads `bootstrap.php`, and `run()` builds everything declared there.

Three details:

- The slug defaults to the entry file's **directory** name, `acme-books`. Every name the modules register carries it — the REST namespace `acme-books/v1`, the admin page slug `acme-books-settings`, the option row, the log prefix.
- `acme_books()` holds the instance for code outside the module system — a template, a test, a hand-registered callback. The files you are about to write never call it, because their dependencies are injected.
- `acme_books();` on the last line runs the plugin **as the file loads**. Keep it there. Deferring it to `plugins_loaded` is what breaks activation, and section 8 depends on it.

## 3. `bootstrap.php`

`wp zt add module` appended an entry per module. Edit it down to this:

```php
<?php

/**
 * What this plugin uses, and how each is configured.
 */

declare( strict_types=1 );

// Loaded by the plugin, never requested directly.
defined( 'ABSPATH' ) || exit;

use Acme\Books\Core\Modules\AdminPages\AdminPages;
use Acme\Books\Core\Modules\Assets\Assets;
use Acme\Books\Core\Modules\Log;
use Acme\Books\Core\Modules\Options;
use Acme\Books\Core\Modules\PostTypes\PostTypes;
use Acme\Books\Core\Modules\RestApi\RestApi;
use Acme\Books\Modules\Activation;

return array(
    PostTypes::class,
    RestApi::class,
    AdminPages::class,
    Assets::class,
    Log::class,
    Options::class => static function ( Options $options ): void {
        $options->autoload_default_group();
    },
    Activation::class,
);
```

An entry's value is its **initializer** — the callback that configures the module before it boots. `Options` gets one here so its row is loaded with the rest of WordPress's autoloaded options, since the REST route reads a setting on every request. The other four need no configuration, so they are written bare.

`Assets` and `Activation` are the two lines you do not add by hand — `wp zt add module assets` appends the first in section 7, and `wp zt make activation` the second in section 8. Both are shown here so the finished file is in one place.

> [!IMPORTANT]
> **This file is modules only, and listing one is what builds it.** A module acts on its own — it binds a hook, registers a post type, walks a directory — so it has to be built for any of that to happen.
>
> A service never appears here. It is built the moment something asks for it, so an entry naming one would only build it sooner than needed. A service that takes configuration gets it from `$plugin->configure()` in the entry file instead.

## 4. The post type

```bash
$ wp zt make post-type book --plural=Books
Success: Created post-types/book.php
```

The generated file carries every overridable method with a comment explaining it. Keep the two that are abstract, keep the two you are changing, and delete the rest — anything you do not override takes its default:

```php
<?php
/**
 * Book post type.
 */

declare( strict_types=1 );

// Loaded by WordPress, never requested directly.
defined( 'ABSPATH' ) || exit;

use Acme\Books\Core\Modules\PostTypes\PostType;

return new class extends PostType {

    public function singular_name(): string {
        return 'Book';
    }

    public function plural_name(): string {
        return 'Books';
    }

    public function supports(): array {
        return array( 'title', 'editor', 'excerpt', 'thumbnail' );
    }

    public function menu_icon(): string {
        return 'dashicons-book-alt';
    }
};
```

That is the whole registration. `PostTypes` walks `post-types/` on `init`, requires each file, and passes what it returns to `register_post_type()`. The registered name comes from the **filename** — `post-types/book.php` registers `book` — so there is no name to spell twice.

`singular_name()` and `plural_name()` are the only abstract methods. From those two you get the full label set: *Add New Book*, *Edit Book*, *No Books found in Trash*, and the rest.

> [!NOTE]
> A post type name is the one thing this toolkit does **not** prefix with your plugin slug — WordPress caps it at 20 characters. Pick something short and globally unique, the same care you would take naming a database table.

## 5. The REST route

```bash
$ wp zt make route books --method=get --version=v1 --pattern=/books
Success: Created routes/books.php
```

A route file returns a `Route` — the HTTP method, the namespace version, the URL pattern, and the handler — rather than a bare handler, so the file is the single source of truth for its own URL. Fill it in:

```php
<?php
/**
 * GET /books
 */

declare( strict_types=1 );

// Loaded by WordPress, never requested directly.
defined( 'ABSPATH' ) || exit;

use Acme\Books\Core\Modules\Log;
use Acme\Books\Core\Modules\Options;
use Acme\Books\Core\Modules\RestApi\RestRoute;
use Acme\Books\Core\Modules\RestApi\Route;
use Acme\Books\Core\Services\Request\Attributes\RequestArgument;

return Route::get( 'v1', '/books', new class extends RestRoute {

    public Options $options;
    public Log $log;

    #[RequestArgument( 'Words to match in the title.', sanitize: 'sanitize_text_field' )]
    public string $search = '';

    public function permission_check( WP_REST_Request $request ): bool|\WP_Error {
        return true;
    }

    public function handle( WP_REST_Request $request ): WP_REST_Response|\WP_Error {
        $books = get_posts(
            array(
                'post_type'      => 'book',
                'post_status'    => 'publish',
                'posts_per_page' => (int) $this->options->get( 'per_page', 10 ),
                's'              => $this->search,
            )
        );

        $this->log->info(
            'Books requested',
            array(
                'search' => $this->search,
                'found'  => count( $books ),
            )
        );

        return new WP_REST_Response(
            array_map(
                static fn ( WP_Post $book ): array => array(
                    'id'    => $book->ID,
                    'title' => get_the_title( $book ),
                    'url'   => (string) get_permalink( $book ),
                ),
                $books
            )
        );
    }

    public function schema(): ?array {
        return array(
            '$schema'    => 'http://json-schema.org/draft-04/schema#',
            'title'      => 'book',
            'type'       => 'object',
            'properties' => array(
                'id'    => array( 'type' => 'integer' ),
                'title' => array( 'type' => 'string' ),
                'url'   => array( 'type' => 'string' ),
            ),
        );
    }
} );
```

```bash
curl "$(wp option get siteurl)/wp-json/acme-books/v1/books?search=dune"
```

The namespace is `{plugin-slug}/{version}`, so the full path is `/wp-json/acme-books/v1/books`.

`RestRoute` has three abstract methods, so a route file implements all three:

- **`permission_check()`** — there is no default. A public route returns `true` explicitly, which for a REST route means callable by anyone on the internet. A private one returns `current_user_can( ... )`, or a `WP_Error`.
- **`handle()`** — runs only after the permission check passes and after every bound property is populated.
- **`schema()`** — describes the *response*. Return `null` to publish none; return an array and WordPress exposes it through an `OPTIONS` request, the same way core's own routes do.

`#[RequestArgument]` binds a request parameter — a `{token}` in the pattern, a query-string value, or a body field — onto a typed property, so `handle()` reads `$this->search` and never `$request->get_param( 'search' )`.

The property is the declaration: `string` makes WordPress reject anything else with a 400 before `handle()` runs, and the description is published to whoever is calling. `sanitize:` cleans the value on the way in, `validate:` adds a rule JSON Schema cannot state, and `schema:` adds one it can — an `enum`, a `minimum` — which is the better place for it, since a caller can read a schema and satisfy it before calling.

The same attribute declares an [ability](modules/abilities/)'s input, because a route and an ability ask the same question. Every type you can declare, and every one you cannot: **[Arguments](arguments.md)**.

> [!NOTE]
> **A default is what makes an argument optional**, as `public string $search = ''` does. A property with no default is required, and WordPress rejects a request that omits it — which is the right answer, since a typed property with no value throws on read. A `{token}` from the URL is required whatever its property says, because there is no optional path segment.

## 6. The settings page

```bash
$ wp zt make page settings
Success: Created admin-pages/settings.php
Created views/admin-pages/settings.php
```

Two files. The class decides *what* the page is — its title, who may see it, what a submission does — and the template decides what it looks like.

```php
<?php
/**
 * settings admin page.
 */

declare( strict_types=1 );

// Loaded by WordPress, never requested directly.
defined( 'ABSPATH' ) || exit;

use Acme\Books\Core\Modules\AdminPages\AdminPage;
use Acme\Books\Core\Modules\AdminPages\ParentMenu;
use Acme\Books\Core\Modules\Log;
use Acme\Books\Core\Modules\Options;

return new class extends AdminPage {

    public Options $options;
    public Log $log;

    public function title(): string {
        return __( 'Book Settings', 'acme-books' );
    }

    public function capability(): string {
        return 'manage_options';
    }

    public function parent(): ParentMenu|string|null {
        return 'edit.php?post_type=book';
    }

    public function handle_submit(): void {
        // The nonce is verified by the module before this method runs.
        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $per_page = isset( $_POST['per_page'] ) ? absint( wp_unslash( $_POST['per_page'] ) ) : 10;
        $per_page = max( 1, min( 100, $per_page ) );

        $this->options->set( 'per_page', $per_page );
        $this->options->save();

        $this->log->info( 'Book settings saved', array( 'per_page' => $per_page ) );
    }

    public function render(): void {
        $this->view(
            'admin-pages/settings',
            array(
                'title'    => $this->title(),
                'action'   => $this->get_page_url(),
                'nonce'    => $this->get_nonce_action(),
                'per_page' => (int) $this->options->get( 'per_page', 10 ),
            )
        );
    }
};
```

And the template it renders:

```php
<?php
/** @var \Acme\Books\Core\Services\Views $this */
/** @var string $title */
/** @var string $action */
/** @var string $nonce */
/** @var int $per_page */
?>
<div class="wrap">
    <h1><?php echo esc_html( $title ); ?></h1>

    <form method="post" action="<?php echo esc_url( $action ); ?>">
        <?php wp_nonce_field( $nonce ); ?>

        <table class="form-table" role="presentation">
            <tr>
                <th scope="row">
                    <label for="per_page"><?php esc_html_e( 'Books per API response', 'acme-books' ); ?></label>
                </th>
                <td>
                    <input type="number" name="per_page" id="per_page" min="1" max="100"
                        value="<?php echo esc_attr( (string) $per_page ); ?>" />
                </td>
            </tr>
        </table>

        <?php submit_button(); ?>
    </form>
</div>
```

One field does not need a template, but markup assembled by concatenation in `render()` stops being reviewable long before it stops growing.

The template gets exactly what that `render()` call named, and nothing of the page itself — so its inputs are readable without opening it, and it cannot reach into the page for something the call did not offer.

Inside any template `$this` is the [Views](services/views/) service, so a subview is the same call everything else makes: `$this->render( 'admin-pages/-fields', array( 'per_page' => $per_page ) )`. A template is included rather than called, so the `@var` block at the top describes the whole scope and gives your editor completion.

`admin-pages/settings.php` registers the page slug `acme-books-settings`. Returning `'edit.php?post_type=book'` from `parent()` nests it under the Books menu the post type created; return a `ParentMenu` case such as `ParentMenu::Settings` to nest under a core menu instead, or `null` to get a top-level menu of its own.

The module enforces `capability()` before anything on the page runs, verifies the nonce on every POST, and only then calls `handle_submit()` followed by `render()`. `nonce_field()` emits the matching field. There is no `add_menu_page()`, no `admin_menu` hook, and no `check_admin_referer()` to write.

`Options` writes are deferred to `shutdown` so several `set()` calls cost one database write. `save()` above forces the write early, which is what you want before a redirect or a long task.

### Where the dependencies came from

Both files declare a public typed property and use it without ever building it:

```php
public Options $options;
public Log $log;
```

That is the whole mechanism. When a module discovers a file, it **wires** the object it returns: it assigns the plugin, then walks the object's public and protected properties, and any property typed as a service or module gets that instance assigned before your code runs. Ask for `Options` in ten files and all ten get the same instance.

This is why nothing here declares a constructor. `Service::__construct()` is `final` and takes no arguments, so dependencies arrive as properties and configuration arrives from the initializer in `bootstrap.php`. Mark a property `#[NoInject]` to opt one out.

## 7. A script for the settings page

The page works without JavaScript. Giving it some is two commands:

```bash
$ wp zt add module assets
Created webpack.config.js
Declared the src/shared/* npm workspace in package.json
Added npm scripts: build, start

$ wp zt make entry settings
Success: Created src/entries/settings (2 files)
```

That writes `src/entries/settings/index.ts` and a `style.scss` beside it, which the script imports:

```ts
import './style.scss';

function start(): void {
    document.querySelector( '.acme-books-settings' )?.classList.add( 'is-ready' );
}

if ( 'loading' === document.readyState ) {
    document.addEventListener( 'DOMContentLoaded', start );
} else {
    start();
}
```

Build it, then load it from the page — `AdminPage::enqueue_assets()` is called only when the page is being displayed:

```bash
$ npm install && npm run build
```

```php
public Assets $assets;

public function enqueue_assets(): void {
    $this->assets->enqueue_entry( 'settings' );
}
```

That is the whole wiring. There is no `register_script()`, no `.asset.php` to read, no version string to bump, and no separate call for the stylesheet — the build wrote `build/assets-manifest.php` naming every entry it produced, and the `assets` module registered each one on `init` before any page could ask for it.

`wp zt add module assets` is what makes that possible, and the `webpack.config.js` it wrote is the load-bearing part: `@wordpress/scripts` picks entry points three mutually exclusive ways, so on a stock setup a plugin cannot have both a block and a script of its own. See **[JavaScript](javascript.md)** for the rest — shared code two screens import by name, and ES module entries.

## 8. ActivationHandler

Rewrite rules need flushing once, when the `book` post type first appears. That is activation work, and it gets its own class:

```bash
$ wp zt make activation Activation
Success: Created lib/Modules/Activation.php
Declared Activation in bootstrap.php.
```

`make activation` writes to `lib/Modules/` — **your** tree, beside the copied `lib/Core/` rather than inside it — and appends the class to `bootstrap.php`, because nothing discovers a plain module: being listed is what builds it. That matters more here than anywhere else. WordPress fires the activation hook immediately after your plugin file loads, so the class has to exist by then for `activate()` to bind in time.

What it writes already extends `ActivationHandler` and already has both methods, commented with what belongs in each. Fill them in:

```php
<?php

/**
 * Activation activation handler.
 */

declare( strict_types=1 );

namespace Acme\Books\Modules;

// Loaded by WordPress, never requested directly.
defined( 'ABSPATH' ) || exit;

use Acme\Books\Core\Kernel\Abstracts\ActivationHandler;
use Acme\Books\Core\Modules\Log;

class Activation extends ActivationHandler {

    public Log $log;

    public function activate( bool $network_wide ): void {
        flush_rewrite_rules();

        $this->log->info( 'Acme Books activated' );
    }

    public function deactivate( bool $network_wide ): void {
        flush_rewrite_rules();
    }
}
```

`activate()` and `deactivate()` are both abstract, so neither can be forgotten. `ActivationHandler`'s own `on_boot()` is already written — it registers both with `register_activation_hook()`/`register_deactivation_hook()` against your entry file.

Flushing is enough on its own here: by the time `activate()` runs, the plugin has loaded and `PostTypes` has already registered `book`, so the new rules include it.

> [!IMPORTANT]
> **This is the one feature where `run()`'s timing matters.** WordPress fires `activate_{plugin}` immediately after the plugin file loads, and it does not re-fire a past action for a late subscriber. An entry file that defers `run()` to `plugins_loaded` has already missed the window, and `activate()` can never run. The entry file in section 2 calls `acme_books();` at load, which is what makes this work.
>
> Deactivation runs on every update too, not only when someone switches the plugin off — so it must not drop data. Undo what costs nothing to rebuild, and leave everything a user would miss.

## 9. Check the wiring

One class of mistake in this system produces no error: a module on disk that `bootstrap.php` does not list is never built, so it discovers nothing and binds nothing, and the feature is simply absent.

```bash
$ wp zt doctor
zestry.json    Acme\Books -> lib/
bootstrap.php  7 classes declared
Success: No problems found.
```

Seven: `PostTypes`, `RestApi`, `AdminPages`, `Assets`, `Log`, `Options`, `Activation`. It exits non-zero when it finds something, so it gates a build on its own. See [`wp zt doctor`](commands/doctor.md) for everything it checks.

## 10. What you have

```
acme-books/
├── acme-books.php              ← the entry file
├── bootstrap.php               ← the modules, and how each is configured
├── zestry.json                 ← namespace, root, text domain
├── zestry.lock.json            ← a hash per copied file; commit it
├── composer.json
├── phpcs.xml
├── admin-pages/
│   └── settings.php            ← an admin page
├── views/
│   └── admin-pages/
│       └── settings.php        ← its markup
├── post-types/
│   └── book.php                ← a post type
├── routes/
│   └── books.php               ← a REST route
├── src/
│   └── entries/settings/       ← index.ts + style.scss; built to build/entries/
├── build/                      ← npm run build writes this; gitignored, ships in the zip
├── lib/
│   ├── Core/                   ← copied in; `wp zt update` may replace it
│   │   ├── Kernel/             ← Plugin, ServicesRepository, Service, Module, ActivationHandler
│   │   ├── Modules/
│   │   │   ├── AdminPages/
│   │   │   ├── PostTypes/
│   │   │   ├── RestApi/
│   │   │   ├── Assets/
│   │   │   ├── Log.php
│   │   │   └── Options.php
│   │   └── Services/
│   │       ├── Cookie.php
│   │       ├── Path.php
│   │       ├── Request/
│   │       ├── Transients.php
│   │       └── Views.php
│   └── Modules/
│       └── Activation.php     ← yours; nothing overwrites it
└── vendor/                     ← dev only, not shipped
```

A directory is a feature set, a file returns an object, and a public typed property is filled in before your code runs — the same three conventions in every module. Adding the next feature is one more file. Adding the next module is `wp zt add module cron` and a file in `schedules/`.

## Next

- [Modules](modules/) — the ones that act on their own, and what each one discovers
- [Services](services/) — the ones that work when called
- [`Plugin`](plugin.md) — `configure()`, `make()`, `wire()`, and everything else the entry file can do
- [Command reference](commands/) — every `wp zt` command
- [`wp zt update`](commands/update.md) — take a later release of the toolkit without losing your edits
