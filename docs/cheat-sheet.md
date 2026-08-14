# Cheat sheet

One screen for a plugin you have already built. Every link goes to the page that expands it.

For the absolutes alone, with the tables and the caveats stripped out, see [Rules](rules.md).

## What a plugin is made of

Everything is a **[`Module`](modules/module.md)**, and `bootstrap.php` lists every one. Nothing outside that list is ever built — asking for an undeclared module throws.

**Does it do anything without being called?** If yes, it implements [`Bootable`](kernel/bootable.md), its `on_boot()` runs once when the plugin builds it, and its entry goes under the hook it acts on. If no, it has no `on_boot()`, sits there until something calls it, and its entry goes at the top level. Reached the same way either way.

Reach any module with `$this->with( X::class )` — the same instance every time, from a module or from any file a module discovers.

## `bootstrap.php`

The top level is for modules that do nothing until something asks. A module that acts on its own goes under the hook it acts on.

```php
return array(
    Path::class,
    Options::class => static function ( Options $options ): void {   // configurator
        $options->add_autoloaded_groups( array( 'reports' ) );
    },

    'acme_plugin_loaded' => array(   // this plugin's own action, fired at the end of run()
        Log::class,
    ),

    'init' => array(
        PostTypes::class,
        Blocks::class => static function ( Blocks $blocks ) use ( $categories ): void {
            $blocks->add_categories( $categories );
        },
    ),

    'init:20' => array( Dashboard::class ),   // behind everything at the default 10
);
```

Leaving a module that acts on its own at the top level throws, naming the headings. Asking for a module before its heading fires throws, naming the hook; a hook that has already fired builds it immediately, so a heading reads as "not before".

## Namespaces

`lib` is fixed; `Acme\Plugin` is the namespace you chose at `init`.

| Written by | On disk | Namespace |
|---|---|---|
| `wp zt init` | `lib/Core/Kernel/` | `Acme\Plugin\Core\Kernel\Plugin` |
| `wp zt add ajax`, `wp zt add path` | `lib/Core/Modules/Ajax/`, `lib/Core/Modules/Path.php` | `Acme\Plugin\Core\Modules\Ajax\Ajax`, `Acme\Plugin\Core\Modules\Path` |
| `wp zt make module`, `wp zt make abstract` | `lib/Modules/Shortcode.php`, `lib/Abstracts/EntityField.php` | `Acme\Plugin\Modules\Shortcode`, `Acme\Plugin\Abstracts\EntityField` |
| you, anywhere else under `lib/` | `lib/Data/LineItem.php` | `Acme\Plugin\Data\LineItem` |

**A plain class needs no generator and no directory of ours.** The one PSR-4 entry `init` writes maps your whole source root, so any class under it autoloads from its namespace — a DTO, a value object, a helper. `Modules/` is where `make module` writes, not a rule about what may exist.

`wp zt update` and `wp zt overwrite` may replace anything under `lib/Core/`, and can never touch anything outside it — including directories you made yourself.

## Modules

Add any of them with `wp zt add <name>`; dependencies come along.

| Module | Directory | A file returns | Generate one |
|---|---|---|---|
| [`ajax`](modules/ajax/) | `resources/actions/` | [`AjaxAction`](modules/ajax/ajax-action.md) | [`make action`](commands/make-action.md) |
| [`admin-pages`](modules/admin-pages/) | `resources/admin-pages/` | [`AdminPage`](modules/admin-pages/admin-page.md) | [`make page`](commands/make-page.md) |
| [`cli`](modules/cli/) | `resources/commands/` | [`Command`](modules/cli/command.md) | [`make command`](commands/make-command.md) |
| [`cron`](modules/cron/) | `resources/schedules/` | [`Schedule`](modules/cron/schedule.md) | [`make schedule`](commands/make-schedule.md) |
| [`rest-api`](modules/rest-api/) | `resources/routes/` | [`Route`](modules/rest-api/route.md) | [`make route`](commands/make-route.md) |
| [`post-types`](modules/post-types/) | `resources/post-types/` | [`PostType`](modules/post-types/post-type.md) | [`make post-type`](commands/make-post-type.md) |
| [`post-types`](modules/post-types/) | `resources/taxonomies/` | [`Taxonomy`](modules/post-types/taxonomy.md) | [`make taxonomy`](commands/make-taxonomy.md) |
| [`fields`](modules/fields/) | `resources/fields/` | [`Field`](modules/fields/field.md) | [`make field`](commands/make-field.md) |
| [`meta-boxes`](modules/meta-boxes/) | `resources/meta-boxes/` | [`MetaBox`](modules/meta-boxes/meta-box.md) | [`make meta-box`](commands/make-meta-box.md) |
| [`abilities`](modules/abilities/) | `resources/abilities/` | [`Ability`](modules/abilities/ability.md) | [`make ability`](commands/make-ability.md) |
| [`icons-library`](modules/icons-library/) **(WP 7.1+)** | `resources/svg-icons/` | — (a `.php` echoing the SVG and returning `array( 'label' => … )`, or a plain `.svg`) | — |
| [`site-health`](modules/site-health/) | `resources/health-checks/` | [`HealthCheck`](modules/site-health/health-check.md) | [`make health-check`](commands/make-health-check.md) |
| [`site-health`](modules/site-health/) | `resources/debug-sections/` | [`DebugSection`](modules/site-health/debug-section.md) | [`make debug-section`](commands/make-debug-section.md) |
| [`blocks`](modules/blocks/) | `build/blocks/` | [`Block`](modules/blocks/block.md) | [`make block`](commands/make-block.md) |
| [`migrations`](modules/migrations/) | `resources/migrations/` | [`Migration`](modules/migrations/migration.md) | [`make migration`](commands/make-migration.md) |
| [`assets`](modules/assets/) | `assets/`, `build/` (via its manifest) | — | [`make entry`](commands/make-entry.md), [`make shared`](commands/make-shared.md) |
| [`options`](modules/options/) | — | — | — |
| [`log`](modules/log/) | — | — | — |
| [`path`](modules/path/) | — | — | — |
| [`views`](modules/views/) | `resources/views/` | — | [`make view`](commands/make-view.md) |
| [`db`](modules/db/) | — | — | — |
| [`globals`](modules/globals/) | — | — | — |
| [`transients`](modules/transients/) | — | — | — |
| [`cookie`](modules/cookie/) | — | — | — |
| [`request`](modules/request/) | — | — | — |

- **A route, an ability, an AJAX action and an admin page declare their inputs the same way**, with [`#[RequestArgument]`](modules/request/request-argument.md) on a typed property — the type and the presence of a default state what the schema says, and the value is bound before your handler runs. That page is the full guide: every type you can declare, and every one you cannot.
- **Prefer [`rest-api`](modules/rest-api/) to [`ajax`](modules/ajax/) for anything new.** Both declare their input the same way, but a route also publishes a schema and is callable by anything, where an action answers only WordPress-shaped callers. Reach for `ajax` when something already speaks it — an admin screen's existing JavaScript, another plugin's action, the heartbeat.
- **`abilities` is the AI-agent surface.** WordPress 6.9+ gives each one a REST endpoint, and an MCP adapter on the site turns it into a tool an agent can call — with no protocol code from you. Call your own with `$abilities->run( 'name', $input )`.
- **`site-health` has two directories, one per tab.** A `resources/health-checks/` file reports a verdict on **Status**; a `resources/debug-sections/` file lists values on **Info**, which is what the "Copy site info" button copies.
- **`resources/taxonomies/` is its own directory.** One module, two roots — a `Taxonomy` file under `resources/post-types/` is discovered as a `PostType` and throws.
- **`blocks` reads the built directory.** `wp zt make block` writes source into `src/blocks/`; `npm run build` compiles it to `build/blocks/`, which is what the module walks. A block that has never been built registers nothing.

### Where JavaScript goes

| `src/` | Built to | Registered by | Generate with |
| --- | --- | --- | --- |
| `blocks/{name}/` | `build/blocks/{name}/` | WordPress, from `block.json` | [`make block`](commands/make-block.md) |
| `entries/{name}/` | `build/entries/{name}` | `assets`, as `{slug}-{name}` | [`make entry`](commands/make-entry.md) |
| `shared/{name}/` | `build/shared/{name}` | `assets`, under the build's handle | [`make shared`](commands/make-shared.md) |

- **`wp zt add assets` writes the build.** `webpack.config.js`, the `src/shared/*` npm workspace, and `npm run build`/`start`. Without that config `@wordpress/scripts` builds *one* of the three — adding a block silently stops `src/index` being built.
- **`build/assets-manifest.php` is what PHP reads.** One `require`: every entry, its dependencies and version, its stylesheet, and which entries are shared packages. Build output — gitignored, never committed.
- **Nothing empty is registered.** A stylesheet that compiles to nothing is deleted and left out of the manifest, an entry that is only a stylesheet loses the JavaScript webpack generates for it, and a block's `block.json` loses any `file:` field whose target compiled away — so no page pays for an empty `<link>` or `<script>`.
- **An entry or a shared package can be `--kind=module`**, built as an ES module and registered with `wp_register_script_module()`.

- **`assets` is a module because of one thing it does unasked.** Called, it composes asset URLs and registers scripts and styles. Unasked, on `init`, it registers everything `npm run build` produced — every entry and every shared package — so `enqueue_entry( 'settings' )` needs no registration call first, and an entry importing a shared package declares it as a dependency instead of bundling a copy.
- **An admin page's markup goes in a template**, and [`make page`](commands/make-page.md) writes both files. The template gets exactly what the `render()` call names and nothing of the page itself. Echoing markup from `render()` works for something tiny and stops working sooner than it looks.
- **[`make view`](commands/make-view.md) writes a standalone template**, and a name with a slash nests: `wp zt make view emails/receipt` is `resources/views/emails/receipt.php`, rendered as `emails/receipt`.
- **`$this` inside any template is the [`views`](modules/views/) module**, so a subview is `$this->render( 'admin-pages/-fields', array( … ) )` — the same call every other caller makes, costing no variable name. Declare `@var` at the top of a template and your editor completes all of it.
- **`admin-pages`** also accepts a [`ModernAdminPage`](modules/admin-pages/modern-admin-page.md). A page whose `menu()` returns [`AdminMenu::Network`](modules/admin-pages/admin-menu.md) goes to the network administrator's menu on multisite instead of every site's — pick `capability()` to match, and remember the two menus offer different `ParentMenu` sections.
- **`meta-boxes` reaches two screens.** Posts and comments are the only ones WordPress renders boxes on; terms and users take custom fields through action hooks instead. Register their meta with `fields` and render it on those forms yourself.
- **`migrations` never triggers itself.** Call `$plugin->get( Migrations::class )->run_pending()`, or run `wp {slug} migrations run` / `wp {slug} migrations list`.
- **A migration's identity is its filename**, description included, so renaming one makes it a migration your site has never run. `migrations list` shows the recorded name as `orphaned` beside the new name's `pending`, and `run` refuses the whole batch when the two share a timestamp — rename the file back, or `--force` to run it as new.
- **`options` writes only when you tell it to.** `set()` and `delete()` change memory; `save()` is the only thing that reaches the database, so a request that dies halfway leaves the stored settings untouched. A key is a dotted path — `set( 'mail.from.name', … )` nests. The ungrouped row autoloads; a `group()` does not unless `add_autoloaded_groups()` names it.
- **The directory each module reads is fixed**, and one that does not exist discovers nothing and says nothing — so adding a module before writing its first file is fine.
- **Name a discovered file with hyphens** — `book-details.php`. It is a convention, not a rewrite: your filename registers exactly as written. Two destinations hold their filename to their own charset and **throw** rather than respell it — an admin page whose name a URL would have to encode, and an ability outside WordPress's `[a-z0-9-]`.
- **A name the toolkit builds carries your slug; a name it takes is yours.** A hook, a handle, a meta box id, an ability and a command are built — your slug is prefixed on with the separator that destination takes, and an accessor hands you the result. A post type, a taxonomy and a meta key are taken: those are columns in the database and appear in your REST responses, so they are left exactly as you named the file.
- **A file or directory starting with `.` or `-` is skipped.** Use `-partials/` for something inside a discovered directory that is not itself a discoverable unit. A leading `_` is *not* skipped — WordPress uses it for protected meta, so `resources/fields/_acme_secret.php` has to be a valid name.

### Which names get your plugin slug

The filename is what a file registers as. Whether your slug is prefixed onto it depends on where that name lands:

| Prefixed for you | Not prefixed — name it yourself |
|---|---|
| `resources/actions/` → `{slug}-send` | `resources/post-types/` → `book` |
| `resources/admin-pages/` → `{slug}-settings` | `resources/taxonomies/` → `genre` |
| `resources/schedules/` → `{slug}-sync` | `resources/fields/` → `rating` |
| `resources/health-checks/` → `{slug}-api-key` | `build/blocks/` → from `block.json` |
| `resources/abilities/` → `{slug}/create-order` | |
| `resources/debug-sections/` → `{slug}-status` | |
| `resources/commands/` → `wp {slug} greet` | |
| `resources/routes/` → `/{slug}/v1/...` | |

> [!IMPORTANT]
> **The right-hand column is shared with every other plugin on the site.** Two plugins registering a `book` post type, a `genre` taxonomy or a `rating` meta key are registering the same thing, and one of them loses.
>
> Those four are not prefixed for a reason — WordPress caps post type names at 20 characters and taxonomies at 32, a meta key is part of your own REST responses, and a block's namespace already lives in its `block.json`. So put your prefix in the filename: `resources/post-types/acme-book.php`, `resources/fields/acme_rating.php`.

Eight modules do nothing on their own — no `Bootable`, no `on_boot()`. They are listed in `bootstrap.php` like the rest, and reached the same way:

| Module | What it does | A first call |
|---|---|---|
| [`options`](modules/options/) | Settings, one array per plugin or group | `$this->with( Options::class )->get( 'per_page', 10 )` |
| [`path`](modules/path/) | Plugin-relative paths and URLs | `$this->with( Path::class )->get_plugin_url( 'logo.png' )` |
| [`request`](modules/request/) | Declared arguments become schemas and bound properties | `#[RequestArgument( 'Which one.' )] public int $id;` |
| [`views`](modules/views/) | Renders `resources/views/*.php` templates | `$this->with( Views::class )->render( 'emails/receipt', $data )` |
| [`db`](modules/db/) | Names your tables and WordPress's | `$this->with( DB::class )->get_table( 'events' )` |
| [`globals`](modules/globals/) | Request-scoped key/value store | `$this->with( Globals::class )->set( 'run_id', $id )` |
| [`transients`](modules/transients/) | Key/value that outlives the request, with a TTL | `$this->with( Transients::class )->set( 'rates', $r, HOUR_IN_SECONDS )` |
| [`cookie`](modules/cookie/) | Cookies, encrypted, and one value carried across a redirect | `$this->with( Cookie::class )->set_flash( 'Saved.' )` |

`globals`, `transients` and `options` are the same four verbs — `get`, `set`, `has`, `delete` — differing only in how long a value lasts: this request, until it expires, or until you change it.

## The `Plugin` API

Every method below is called on the instance `Acme\Plugin\Core\Kernel\Plugin` builds.

```php
__construct( string $entry, ?string $slug = null )     // pass __FILE__; slug defaults to the directory name

configure( string $name, callable $configurator ): self // callback run when that module is built
bootstrap( ?string $file = null ): self                // read bootstrap.php; a missing file is not an error
declare( string $name, ?string $hook = null, int $priority = 10 ): self  // one module, and when
declare_multiple( array $entries = array() ): self     // everything a bootstrap.php returns
run(): self                                            // build every declared module, synchronously

get( string $name ): object                            // resolve once, cached forever
make( string $name, ?callable $configurator = null ): object  // fresh instance, never cached
wire( PluginAware $instance ): PluginAware             // give the plugin to an object you built yourself

get_header( string $header ): ?string                  // any header in the entry file's docblock
get_version(): ?string                                 // shorthand for get_header( 'Version' )
get_slug(): string                                     // what every registered name is namespaced with
```

Also on it: `get_loaded_hook()`, `get_namespaced_name( $name, $glue = '-' )`, `get_entry_file()`, `get_bootstrap_file()`, `set_languages_path( $path, $text_domain = null )`, `is_wp_debug()`, `is_wp_cli()`, `is_plugin_debug()`. Full page: [`Plugin`](plugin.md).

An [`ActivationHandler`](modules/activation-handler.md) subclass only works if `run()` is called as the entry file loads — WordPress fires `activate_{plugin}` right after that, and a `run()` deferred to `plugins_loaded` has already missed it.

## Reaching another module

`$this->with( X::class )` — from a module, or from any file a module discovers. The same instance every time.

A module the plugin never declared throws rather than being built on the spot, and one listed under a heading throws until that hook fires. A class the plugin did not build gets `with()` by `use WithPlugin;` and `$plugin->wire( $object )`, which is how discovered commands, actions and pages are wired.

See [`WithPlugin`](kernel/with-plugin.md), [`Bootable`](kernel/bootable.md), [`PluginAware`](kernel/plugin-aware.md).

## Every `wp zt` command

Run from inside your plugin's directory, with the plugin active.

| Command | What it does |
|---|---|
| [`wp zt init`](commands/init.md) | Copies the kernel; writes `zestry.json`, `zestry.lock.json`, `bootstrap.php`, the PSR-4 entry, `.gitignore`, the linter configs and `AGENTS.md`. `--no-phpcs`, `--no-eslint`, `--no-prettier`, `--no-agents`, `--yes` |
| [`wp zt add <name>...`](commands/add.md) | Copies modules and their dependencies; declares each in `bootstrap.php`. Skips what is already there. Never asks. |
| [`wp zt make <type> <name>`](commands/) | Generates one file from a stub — see the 20 types below. `--yes` on every type; `--extends=` on every type whose file returns a base-class instance, except `route` and `block`, plus `abstract`, which also takes `--for=<type>` |
| [`wp zt describe`](commands/describe.md) | Reports what this plugin has: each module installed, declared, the directory it reads and the base class a file there returns. `--format`, `--installed` |
| [`wp zt doctor`](commands/doctor.md) | Reports the wiring mistakes that raise no error — chiefly a module on disk that nothing declares. `--format=report\|csv\|json\|yaml` |
| [`wp zt debug`](commands/debug.md) | Reports this plugin's own `{SLUG}_DEBUG` constant, or writes it to `wp-config.php`. Takes `on` or `off`; omit both to report. |
| [`wp zt update`](commands/update.md) | Re-copies everything under `lib/Core/` from the installed toolkit, keeping files you edited. `--dry-run`, `--force`, `--yes` |
| [`wp zt overwrite <name>...`](commands/overwrite.md) | Like `add module`, but replaces what is already on disk after one confirmation. `--yes` |

### What `make` generates

Each type writes one file into the directory its module discovers, so the generated file *is* the registration — there is nothing to wire up afterwards. One exception: `block` writes *source*, and `npm run build` is what puts it where the module looks.

| Type | Writes to | |
|---|---|---|
| [`action`](commands/make-action.md) | `resources/actions/` | an `admin-ajax.php` action |
| [`page`](commands/make-page.md) | `resources/admin-pages/` | an admin screen, **and its template**. `--no-view`, `--views-dir=` |
| [`view`](commands/make-view.md) | `resources/views/` | a standalone template; a slash nests |
| [`command`](commands/make-command.md) | `resources/commands/` | a `wp` subcommand |
| [`schedule`](commands/make-schedule.md) | `resources/schedules/` | a cron event. `--recurrence=` |
| [`route`](commands/make-route.md) | `resources/routes/` | a REST endpoint. `--method=`, `--pattern=`, `--version=` |
| [`ability`](commands/make-ability.md) | `resources/abilities/` | an ability (WP 6.9+) |
| [`post-type`](commands/make-post-type.md) | `resources/post-types/` | a custom post type. `--singular=`, `--plural=` |
| [`taxonomy`](commands/make-taxonomy.md) | `resources/taxonomies/` | a taxonomy. `--singular=`, `--plural=`, `--object-type=` |
| [`field`](commands/make-field.md) | `resources/fields/` | a meta field |
| [`meta-box`](commands/make-meta-box.md) | `resources/meta-boxes/` | an editor meta box |
| [`health-check`](commands/make-health-check.md) | `resources/health-checks/` | a Site Health **Status** test |
| [`debug-section`](commands/make-debug-section.md) | `resources/debug-sections/` | a Site Health **Info** panel |
| [`migration`](commands/make-migration.md) | `resources/migrations/` | a schema change, timestamp-prefixed |
| [`block`](commands/make-block.md) | `src/blocks/` | a block. `--dynamic`, `--view=none\|script\|module`, `--js` |
| [`entry`](commands/make-entry.md) | `src/entries/` | your own script. `--kind=script\|module` |
| [`shared`](commands/make-shared.md) | `src/shared/` | a package two entries can share. `--kind=script\|module` |
| [`module`](commands/make-module.md) | `lib/Modules/` | your own module, **declared in `bootstrap.php`**. `--bootable` |
| [`activation`](commands/make-activation.md) | `lib/Modules/` | an activation handler, **declared** too |
| [`abstract`](commands/make-abstract.md) | `lib/Abstracts/` | a base your own files share. `--for=<type>`, `--extends=` |

The last three land beside the copied `lib/Core/` tree, never inside it — that tree is what [`wp zt update`](commands/update.md) may replace. Every type writes to one directory, fixed by the module that reads it; a name with a slash nests inside it, so `make command reports/daily` writes `resources/commands/reports/daily.php`.

<!-- zestry:include generator="prompting-generators" -->
**5 of the 20 generators ask for what you leave out** — [`block`](commands/make-block.md), [`post-type`](commands/make-post-type.md), [`route`](commands/make-route.md), [`shared`](commands/make-shared.md), [`taxonomy`](commands/make-taxonomy.md). Give every option and none of them stops. The other 15 take no options they could ask about — but *any* generator stops to ask before overwriting a file, or to offer the module the generated file needs. `--yes` answers all of it without reading input, which is what an unattended run wants.
<!-- /zestry:include -->

`make module` and `make activation` are the only generators that also write to `bootstrap.php`, since being listed is the only thing that makes a module exist.

## What throws what

| Exception | Raised when |
|---|---|
| [`ModuleException`](kernel/module-exception.md) | Base class for every declaration, resolution and boot failure, so one `catch` covers all of them. Thrown directly for a `bootstrap.php` it cannot read, a module nothing declared, and a module asked for before the hook it is listed under |
| [`DiscoveryException`](kernel/discovery-exception.md) | A discovered file returned something other than the base class that module expects, two files claim one registered name, a filename a destination cannot carry, an SVG icon WordPress would strip, or WordPress refused the registration |
| [`ModuleNotFoundException`](kernel/module-not-found-exception.md) | `with()`, `get()` or `make()` named a class that does not exist or does not extend `Module` |
| [`CircularDependencyException`](kernel/circular-dependency-exception.md) | Two modules built with `make()` reached for each other while building. `get()` cannot cycle |
| [`RenamedMigrationException`](modules/migrations/) | A pending migration shares a timestamp with one that ran and no longer has a file. Nothing ran when this is thrown |

Bad arguments stay `\InvalidArgumentException`: a `Path` call escaping the plugin root, an unknown `Cron` schedule name, a REST placeholder with nothing bound to it, a CLI command name already taken.
