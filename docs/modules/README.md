<!--
    Generated from src/DevTools/registry.php and each class it names.
    Do not edit by hand: run `composer docs` after changing the source.
-->

# Modules

A module acts on its own: it binds a hook, registers a post type, walks a directory. Because it acts without being called, it has to be built for that to happen — so every module is listed in `bootstrap.php`, and the plugin builds it as the plugin loads.

## What your files are named

A discovered file's name is the thing it registers as: `commands/greet.php` is `wp your-plugin greet`, `post-types/book.php` is the `book` post type, `fields/acme_rating.php` is the `acme_rating` meta key. You never repeat that name inside the file.

Whether your plugin slug is prefixed onto that name depends on where the name lands:

- **Prefixed**, when the name goes into something every plugin on the site shares — admin page slugs, cron hooks, AJAX actions, Site Health checks, REST namespaces, WP-CLI commands. Two plugins with a `sync` schedule must not collide, so yours is `your-plugin-sync`. A hyphen joins the two halves wherever the destination takes one; the few that take something else say so — an option name joins with `_`, a REST namespace with `/`, a WP-CLI command with a space.
- **Not prefixed**, when the name is your own public API and something else constrains it — post types and taxonomies, which WordPress caps at 20 and 32 characters; meta keys, which appear in your REST responses; block names, which `block.json` already qualifies. Prefix these yourself in the filename when you need to.

All of them extend [`Module`](module.md), whose abstract `on_boot()` is where the acting-on-its-own goes. For the things that only work when called, see [Services](../services/).

Everything here is optional. `wp zestry add module <name>` copies one into your plugin, along with anything it depends on.

## Every module

Add nothing up front. Reach for one when you hit what it solves:

| Module | Reach for it to… | Discovers | A file returns | Also copies |
|---|---|---|---|---|
| [`abilities`](abilities/) | give an AI agent a tool it can call (WordPress 6.9+) | `abilities/` | [`Ability`](abilities/ability.md) | `path`, `request` |
| [`admin-pages`](admin-pages/) | add a screen to the admin menu | `admin-pages/` | [`AdminPage`](admin-pages/admin-page.md), [`ModernAdminPage`](admin-pages/modern-admin-page.md) | `cookie`, `path`, `request`, `views` |
| [`ajax`](ajax/) | answer `admin-ajax.php`, for callers that already speak it | `actions/` | [`AjaxAction`](ajax/ajax-action.md) | `path`, `request` |
| [`assets`](assets/) | enqueue a script or stylesheet, and share code between them | `assets/`, `build/` (read, not walked) | — | `path` |
| [`blocks`](blocks/) | build a block for the editor | `build/blocks/` | [`Block`](blocks/block.md) | `path` |
| [`cli`](cli/) | add a `wp` command | `commands/` | [`Command`](cli/command.md) | `path` |
| [`cron`](cron/) | run something on a schedule | `schedules/` | [`Schedule`](cron/schedule.md) | `path` |
| [`fields`](fields/) | register post meta, and render it on the editor | `fields/` | [`Field`](fields/field.md) | `path` |
| [`log`](log/) | record what went wrong | — | — | — |
| [`meta-boxes`](meta-boxes/) | put a panel on the post or comment editor | `meta-boxes/` | [`MetaBox`](meta-boxes/meta-box.md) | `path`, `fields` |
| [`migrations`](migrations/) | create or change a database table | `migrations/` | [`Migration`](migrations/migration.md) | `path`, `db`, `options`, `cli` |
| [`options`](options/) | store settings | — | — | — |
| [`post-types`](post-types/) | register a custom post type or taxonomy | `post-types/`, `taxonomies/` | [`PostType`](post-types/post-type.md), [`Taxonomy`](post-types/taxonomy.md) | `path` |
| [`rest-api`](rest-api/) | expose an HTTP endpoint | `routes/` | [`Route`](rest-api/route.md) | `path`, `request` |
| [`site-health`](site-health/) | report a verdict on Site Health, or list values on Info | `health-checks/`, `debug-sections/` | [`HealthCheck`](site-health/health-check.md), [`DebugSection`](site-health/debug-section.md) | `path` |

**`blocks` and `assets` also write build tooling outside their own tree** — npm scripts and devDependencies, a `tsconfig.json`, a `webpack.config.js`, `.gitignore` entries. Everything either writes is additive, and [`wp zestry add module`](../commands/add-module.md) lists it.

One worth calling out: **`ajax` serves `admin-ajax.php`**, not the REST API. Reach for it when something already speaks that protocol — an existing script, a third-party integration — and `rest-api` otherwise.

> [!NOTE]
> **A module whose directory does not exist yet discovers nothing, and says nothing.** Adding one before writing its first file is fine. What does throw is a directory named explicitly through a `set_*_root()` call and then not found, since asking for a directory by name and getting nothing is a typo worth hearing about rather than a module quietly registering zero of everything.
