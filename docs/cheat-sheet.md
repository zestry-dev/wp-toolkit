# Cheat sheet

One screen for a plugin you have already built. Every link goes to the page that expands it.

For the absolutes alone, with the tables and the caveats stripped out, see [Rules](rules.md).

## Service or module?

**Does it do anything without being called?**

- **No → [`Service`](services/service.md).** Built the first time something asks for it. Never appears in `bootstrap.php`; configure it with `$plugin->configure()` in the entry file.
- **Yes → [`Module`](modules/module.md).** Binds a hook, registers a post type, walks a directory. Listed in `bootstrap.php` — listing it is what builds it — and `on_boot()` runs once. Defer work to `init` with `$this->run_at_init( $callback )`.

## Namespaces

`lib` is the root you chose at `init`; `Acme\Plugin` is the namespace you chose.

| Written by | On disk | Namespace |
|---|---|---|
| `wp zestry init` | `lib/Core/Kernel/` | `Acme\Plugin\Core\Kernel\Plugin` |
| `wp zestry add module ajax`, `wp zestry add service path` | `lib/Core/Modules/Ajax/`, `lib/Core/Services/Path.php` | `Acme\Plugin\Core\Modules\Ajax\Ajax`, `Acme\Plugin\Core\Services\Path` |
| `wp zestry make module`, `wp zestry make service` | `lib/Modules/Shortcode.php`, `lib/Services/Cache.php` | `Acme\Plugin\Modules\Shortcode`, `Acme\Plugin\Services\Cache` |
| you, anywhere else under `lib/` | `lib/Data/LineItem.php` | `Acme\Plugin\Data\LineItem` |

**A plain class needs no generator and no directory of ours.** The one PSR-4 entry `init` writes maps your whole source root, so any class under it autoloads from its namespace — a DTO, a value object, a helper. `Modules/` and `Services/` are where the two generators write, not a rule about what may exist.

`wp zestry update` and `wp zestry overwrite` may replace anything under `lib/Core/`, and can never touch anything outside it — including directories you made yourself.

## Modules

Add any of them with `wp zestry add module <name>`; dependencies come along.

| Module | Directory | A file returns | Configure with | Generate one |
|---|---|---|---|---|
| [`ajax`](modules/ajax/) | `actions/` | [`AjaxAction`](modules/ajax/ajax-action.md) | `set_actions_root()` | [`make action`](commands/make-action.md) |
| [`admin-pages`](modules/admin-pages/) | `admin-pages/` | [`AdminPage`](modules/admin-pages/admin-page.md) | `set_pages_root()` | [`make page`](commands/make-page.md) |
| [`cli`](modules/cli/) | `commands/` | [`Command`](modules/cli/command.md) | `set_commands_root()` | [`make command`](commands/make-command.md) |
| [`cron`](modules/cron/) | `schedules/` | [`Schedule`](modules/cron/schedule.md) | `set_schedules_root()` | [`make schedule`](commands/make-schedule.md) |
| [`rest-api`](modules/rest-api/) | `routes/` | [`Route`](modules/rest-api/route.md) | `set_routes_root()` | [`make route`](commands/make-route.md) |
| [`post-types`](modules/post-types/) | `post-types/` | [`PostType`](modules/post-types/post-type.md) | `set_post_types_root()` | [`make post-type`](commands/make-post-type.md) |
| [`post-types`](modules/post-types/) | `taxonomies/` | [`Taxonomy`](modules/post-types/taxonomy.md) | `set_taxonomies_root()` | [`make taxonomy`](commands/make-taxonomy.md) |
| [`fields`](modules/fields/) | `fields/` | [`Field`](modules/fields/field.md) | `set_fields_root()` | [`make field`](commands/make-field.md) |
| [`meta-boxes`](modules/meta-boxes/) | `meta-boxes/` | [`MetaBox`](modules/meta-boxes/meta-box.md) | `set_boxes_root()` | [`make meta-box`](commands/make-meta-box.md) |
| [`abilities`](modules/abilities/) | `abilities/` | [`Ability`](modules/abilities/ability.md) | `set_abilities_root()` | [`make ability`](commands/make-ability.md) |
| [`site-health`](modules/site-health/) | `health-checks/` | [`HealthCheck`](modules/site-health/health-check.md) | `set_checks_root()` | [`make health-check`](commands/make-health-check.md) |
| [`site-health`](modules/site-health/) | `debug-sections/` | [`DebugSection`](modules/site-health/debug-section.md) | `set_sections_root()` | [`make debug-section`](commands/make-debug-section.md) |
| [`blocks`](modules/blocks/) | `build/blocks/` | [`Block`](modules/blocks/block.md) | `set_blocks_root()` | [`make block`](commands/make-block.md) |
| [`migrations`](modules/migrations/) | `migrations/` | [`Migration`](modules/migrations/migration.md) | `set_migrations_root()` | [`make migration`](commands/make-migration.md) |
| [`assets`](modules/assets/) | `assets/`, `build/` (via its manifest) | — | `set_build_root()` | [`make entry`](commands/make-entry.md), [`make shared`](commands/make-shared.md) |
| [`options`](modules/options/) | — | — | `set_group_name()` | — |
| [`log`](modules/log/) | — | — | `set_min_level()` | — |

- **A route, an ability and an AJAX action declare their inputs the same way**, with [`#[RequestArgument]`](services/request/request-argument.md) on a typed property — the type and the presence of a default state what the schema says, and the value is bound before your handler runs. Full guide: **[Arguments](arguments.md)**.
- **Prefer [`rest-api`](modules/rest-api/) to [`ajax`](modules/ajax/) for anything new.** A route declares its input with [`#[RequestArgument]`](services/request/request-argument.md), publishes a schema, and is callable by anything; an action reads `$_POST` by hand and answers only WordPress-shaped callers. Reach for `ajax` when something already speaks it — an admin screen's existing JavaScript, another plugin's action, the heartbeat.
- **`abilities` is the AI-agent surface.** WordPress 6.9+ gives each one a REST endpoint, and an MCP adapter on the site turns it into a tool an agent can call — with no protocol code from you. Call your own with `$abilities->run( 'name', $input )`.
- **`site-health` has two directories, one per tab.** A `health-checks/` file reports a verdict on **Status**; a `debug-sections/` file lists values on **Info**, which is what the "Copy site info" button copies.
- **`taxonomies/` is its own directory.** One module, two roots — a `Taxonomy` file under `post-types/` is discovered as a `PostType` and throws.
- **`blocks` reads the built directory.** `wp zestry make block` writes source into `src/blocks/`; `npm run build` compiles it to `build/blocks/`, which is what the module walks. A block that has never been built registers nothing.

### Where JavaScript goes

| `src/` | Built to | Registered by | Generate with |
| --- | --- | --- | --- |
| `blocks/{name}/` | `build/blocks/{name}/` | WordPress, from `block.json` | [`make block`](commands/make-block.md) |
| `entries/{name}/` | `build/entries/{name}` | `assets`, as `{slug}-{name}` | [`make entry`](commands/make-entry.md) |
| `shared/{name}/` | `build/shared/{name}` | `assets`, under the build's handle | [`make shared`](commands/make-shared.md) |

- **`wp zestry add module assets` writes the build.** `webpack.config.js`, the `src/shared/*` npm workspace, and `npm run build`/`start`. Without that config `@wordpress/scripts` builds *one* of the three — adding a block silently stops `src/index.ts` being built.
- **`build/assets-manifest.php` is what PHP reads.** One `require`: every entry, its dependencies and version, its stylesheet, and which entries are shared packages. Build output — gitignored, never committed.
- **Nothing empty is registered.** A stylesheet that compiles to nothing is deleted and left out of the manifest, an entry that is only a stylesheet loses the JavaScript webpack generates for it, and a block's `block.json` loses any `file:` field whose target compiled away — so no page pays for an empty `<link>` or `<script>`.
- **An entry or a shared package can be `--kind=module`**, built as an ES module and registered with `wp_register_script_module()`. A module may only import what WordPress ships as one: `@wordpress/interactivity` yes, `@wordpress/element` no.

- **`assets` is a module because of one thing it does unasked.** Called, it composes asset URLs and registers scripts and styles. Unasked, on `init`, it registers every shared package `npm run build` compiled from `src/shared/` into `build/shared/`, so an entry that imports one can declare it as a dependency instead of bundling a copy.
- **An admin page's markup goes in a template**, and [`make page`](commands/make-page.md) writes both files. The template gets exactly what the `render()` call names and nothing of the page itself — a form needs three strings, so `render()` passes three. Echoing markup from `render()` works for something tiny and stops working sooner than it looks.
- **[`make view`](commands/make-view.md) writes a standalone template**, and a name with a slash nests: `wp zestry make view emails/receipt` is `views/emails/receipt.php`, rendered as `emails/receipt`.
- **`$this` inside any template is the [`views`](services/views/) service**, so a subview is `$this->render( 'admin-pages/-fields', array( … ) )` — the same call every other caller makes, costing no variable name. Declare `@var` at the top of a template and your editor completes all of it.
- **`admin-pages`** also accepts a [`ModernAdminPage`](modules/admin-pages/modern-admin-page.md). A page whose `menu()` returns [`AdminMenu::Network`](modules/admin-pages/admin-menu.md) goes to the network administrator's menu on multisite instead of every site's — pick `capability()` to match, and remember the two menus offer different `ParentMenu` sections.
- **`meta-boxes` reaches two screens.** Posts and comments are the only ones WordPress renders boxes on; terms and users take custom fields through action hooks instead. Register their meta with `fields` and render it on those forms yourself.
- **`migrations` never triggers itself.** Call `$plugin->get( Migrations::class )->run_pending()`, or run `wp {slug} migrations run` / `wp {slug} migrations list`.
- **A migration's identity is its filename**, description included, so renaming one makes it a migration your site has never run. `migrations list` shows the recorded name as `orphaned` beside the new name's `pending`, and `run` refuses the whole batch when the two share a timestamp — rename the file back, or `--force` to run it as new.
- **`options` and `log` discover nothing, and are still modules.** `Options` loads its row and flushes on `shutdown`, `Log` binds its hook — both act unasked, so both are declared in `bootstrap.php` like any other.
- A **default** directory that does not exist discovers nothing and says nothing. One named by a `set_*_root()` call and then missing throws.
- **Name a discovered file with hyphens** — `book-details.php`. It is a convention, not a rewrite: your filename registers exactly as written. Two destinations hold their filename to their own charset and **throw** rather than respell it — an admin page whose name a URL would have to encode, and an ability outside WordPress's `[a-z0-9-]`.
- **A name the toolkit builds carries your slug; a name it takes is yours.** A hook, a handle, a meta box id, an ability and a command are built — your slug is prefixed on with the separator that destination takes, and an accessor hands you the result. A post type, a taxonomy and a meta key are taken: those are columns in the database and appear in your REST responses, so they are left exactly as you named the file.
- **A file or directory starting with `.` or `-` is skipped.** Use `-partials/` for something inside a discovered directory that is not itself a discoverable unit. A leading `_` is *not* skipped — WordPress uses it for protected meta, so `fields/_acme_secret.php` has to be a valid name.

### Which names get your plugin slug

The filename is what a file registers as. Whether your slug is prefixed onto it depends on where that name lands:

| Prefixed for you | Not prefixed — name it yourself |
|---|---|
| `actions/` → `{slug}-send` | `post-types/` → `book` |
| `admin-pages/` → `{slug}-settings` | `taxonomies/` → `genre` |
| `schedules/` → `{slug}-sync` | `fields/` → `rating` |
| `health-checks/` → `{slug}-api-key` | `build/blocks/` → from `block.json` |
| `abilities/` → `{slug}/create-order` | |
| `debug-sections/` → `{slug}-status` | |
| `commands/` → `wp {slug} greet` | |
| `routes/` → `/{slug}/v1/...` | |

> [!IMPORTANT]
> **The right-hand column is shared with every other plugin on the site.** Two plugins registering a `book` post type, a `genre` taxonomy or a `rating` meta key are registering the same thing, and one of them loses.
>
> Those four are not prefixed for a reason — WordPress caps post type names at 20 characters and taxonomies at 32, a meta key is part of your own REST responses, and a block's namespace already lives in its `block.json`. So put your prefix in the filename: `post-types/acme-book.php`, `fields/acme_rating.php`.

## Services

Add with `wp zestry add service <name>`. Reach one by declaring a typed property on any service, module, command, action, page or route — `public Path $path;` — or with `$plugin->get( Path::class )`.

`globals`, `transients` and the `options` module are the same four verbs — `get`, `set`, `has`, `delete` — differing only in how long a value lasts: this request, until it expires, or until you change it.

| Service | What it does | A first call |
|---|---|---|
| [`path`](services/path/) | Plugin-relative paths and URLs | `$this->path->get_plugin_url( 'logo.png' )` |
| [`request`](services/request/) | Declared arguments become schemas and bound properties | `#[RequestArgument( 'Which one.' )] public int $id;` |
| [`views`](services/views/) | Renders `views/*.php` templates | `$this->views->render( 'emails/receipt', $data )` |
| [`db`](services/db/) | Names your tables and WordPress's | `$this->db->get_table( 'events' )` |
| [`globals`](services/globals/) | Request-scoped key/value store | `$this->globals->set( 'run_id', $id )` |
| [`transients`](services/transients/) | Key/value that outlives the request, with a TTL | `$this->transients->set( 'rates', $r, HOUR_IN_SECONDS )` |
| [`cookie`](services/cookie/) | Cookies, encrypted, and one value carried across a redirect | `$this->cookies->set_flash( 'Saved.' )` |

**[`assets`](modules/assets/) is a module, not a service** — `wp zestry add module assets`.

## The `Plugin` API

Every method below is called on the instance `Acme\Plugin\Core\Kernel\Plugin` builds.

```php
__construct( string $entry, ?string $slug = null )     // pass __FILE__; slug defaults to the directory name

configure( string $name, callable $initializer ): self // callback run when that class is first built
bootstrap( ?string $file = null ): self                // read bootstrap.php; a missing file is not an error
autoload( array $modules = array() ): self             // queue classes; builds nothing yet
run( ?callable $on_boot_callback = null ): self        // build and boot the queue, synchronously

get( string $name ): object                            // resolve once, cached forever
make( string $name, ?callable $configurator = null ): object  // fresh instance, never cached
wire( PluginAware $instance ): PluginAware             // inject into an object you built yourself

get_header( string $header ): ?string                  // any header in the entry file's docblock
get_version(): ?string                                 // shorthand for get_header( 'Version' )
get_slug(): string                                     // what every registered name is namespaced with
```

Also on it: `get_entry_file()`, `set_languages_path( $path, $text_domain = null )`, `is_wp_debug()`, `is_wp_cli()`, `is_plugin_debug()`. Full page: [`Plugin`](plugin.md).

An [`ActivationHandler`](modules/activation-handler.md) subclass only works if `run()` is called as the entry file loads — WordPress fires `activate_{plugin}` right after that, and a `run()` deferred to `plugins_loaded` has already missed it.

## Injection

1. A **public or protected** property typed as a `Service` subclass — which every `Module` is — is resolved and assigned before any of your code runs.
2. **Private is never injected**, and `#[NoInject]` opts a property out. Scalars, unions, untyped and other class types are left alone.
3. A class the plugin did not build gets the same by `use WithPlugin;` and `$plugin->wire( $object )` — which is how discovered commands, actions and pages are wired.

See [`WithPlugin`](kernel/with-plugin.md), [`NoInject`](kernel/no-inject.md), [`PluginAware`](kernel/plugin-aware.md).

## Every `wp zestry` command

Run from inside your plugin's directory, with the plugin active.

| Command | What it does |
|---|---|
| [`wp zestry init`](commands/init.md) | Copies the kernel; writes `zestry.json`, `zestry.lock.json`, `bootstrap.php`, the PSR-4 entry, `.gitignore`, the linter configs and `AGENTS.md`. `--no-phpcs`, `--no-eslint`, `--no-prettier`, `--no-agents`, `--yes` |
| [`wp zestry add module <name>...`](commands/add-module.md) | Copies modules and their dependencies; declares each in `bootstrap.php`. Skips what is already there. `--yes` |
| [`wp zestry add service <name>...`](commands/add-service.md) | Copies services and their dependencies. Declares nothing. `--yes` |
| [`wp zestry make <type> <name>`](commands/) | Generates one file from a stub — see the 20 types below. `--yes`, plus `--dir=` on every type but `module` and `service` |
| [`wp zestry describe`](commands/describe.md) | Reports what this plugin has: each module installed, declared, the directory it reads and the base class a file there returns. `--format`, `--kind`, `--installed` |
| [`wp zestry doctor`](commands/doctor.md) | Reports the wiring mistakes that raise no error — chiefly a module on disk that nothing declares. `--format=report\|csv\|json\|yaml` |
| [`wp zestry update`](commands/update.md) | Re-copies everything under `lib/Core/` from the installed toolkit, keeping files you edited. `--dry-run`, `--force`, `--yes` |
| [`wp zestry overwrite module <name>...`](commands/overwrite-module.md) | Like `add module`, but replaces what is already on disk after one confirmation. `--yes` |
| [`wp zestry overwrite service <name>...`](commands/overwrite-service.md) | Like `add service`, but replaces what is already on disk after one confirmation. `--yes` |

### What `make` generates

Each type writes one file into the directory its module discovers, so the generated file *is* the registration — there is nothing to wire up afterwards. One exception: `block` writes *source*, and `npm run build` is what puts it where the module looks.

| Type | Writes to | |
|---|---|---|
| [`action`](commands/make-action.md) | `actions/` | an `admin-ajax.php` action |
| [`page`](commands/make-page.md) | `admin-pages/` | an admin screen, **and its template** |
| [`view`](commands/make-view.md) | `views/` | a standalone template; a slash nests |
| [`command`](commands/make-command.md) | `commands/` | a `wp` subcommand |
| [`schedule`](commands/make-schedule.md) | `schedules/` | a cron event |
| [`route`](commands/make-route.md) | `routes/` | a REST endpoint |
| [`ability`](commands/make-ability.md) | `abilities/` | an ability (WP 6.9+) |
| [`post-type`](commands/make-post-type.md) | `post-types/` | a custom post type |
| [`taxonomy`](commands/make-taxonomy.md) | `taxonomies/` | a taxonomy |
| [`field`](commands/make-field.md) | `fields/` | a meta field |
| [`meta-box`](commands/make-meta-box.md) | `meta-boxes/` | an editor meta box |
| [`health-check`](commands/make-health-check.md) | `health-checks/` | a Site Health **Status** test |
| [`debug-section`](commands/make-debug-section.md) | `debug-sections/` | a Site Health **Info** panel |
| [`migration`](commands/make-migration.md) | `migrations/` | a schema change, timestamp-prefixed |
| [`block`](commands/make-block.md) | `src/blocks/` | a block. `--view=script\|module`, `--js` |
| [`entry`](commands/make-entry.md) | `src/entries/` | your own script. `--kind=script\|module` |
| [`shared`](commands/make-shared.md) | `src/shared/` | a package two entries can share |
| [`module`](commands/make-module.md) | `lib/Modules/` | your own module, **declared in `bootstrap.php`** |
| [`activation`](commands/make-activation.md) | `lib/Modules/` | an activation handler, **declared** too |
| [`service`](commands/make-service.md) | `lib/Services/` | your own service. Declares nothing |

The last three land beside the copied `lib/Core/` tree, never inside it — that tree is what [`wp zestry update`](commands/update.md) may replace. `module` and `service` are the two types with no `--dir=` at all, for the same reason.

<!-- zestry:include generator="prompting-generators" -->
**5 of the 20 generators ask for what you leave out** — [`block`](commands/make-block.md), [`post-type`](commands/make-post-type.md), [`route`](commands/make-route.md), [`shared`](commands/make-shared.md), [`taxonomy`](commands/make-taxonomy.md). Give every option and none of them stops; `--yes` answers each with its documented default without reading input. The other 15 never prompt.
<!-- /zestry:include -->

`make module` and `make activation` are the only generators that also write to `bootstrap.php`, since being listed is the only thing that builds a module. `make service` declares nothing: a service is built the moment something asks for it, so an entry naming one would only build it sooner than needed.

## What throws what

| Exception | Raised when |
|---|---|
| [`ModuleException`](kernel/module-exception.md) | Base class for every declaration, resolution and boot failure. Thrown directly for a `bootstrap.php` that returns something other than an array, or an entry naming no class. One `catch` covers all four |
| [`DiscoveryException`](kernel/discovery-exception.md) | A directory named by a `set_*_root()` call does not exist, or a discovered file returned something other than the base class that module expects |
| [`ModuleNotFoundException`](kernel/module-not-found-exception.md) | `get()`, `make()` or an injected property named a class that does not exist or does not extend `Service` |
| [`CircularDependencyException`](kernel/circular-dependency-exception.md) | Two classes are typed as properties of each other, directly or through a chain |
| [`RenamedMigrationException`](modules/migrations/) | A pending migration shares a timestamp with one that ran and no longer has a file. Nothing ran when this is thrown |

Bad arguments stay `\InvalidArgumentException`: a `Path` call escaping the plugin root, an unknown `Cron` schedule name, a REST placeholder with nothing bound to it, a CLI command name already taken.
