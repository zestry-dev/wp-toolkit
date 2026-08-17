<!--
    Generated from src/Kernel/Plugin.php.
    Do not edit by hand: run `composer docs` after changing the source.
-->

# Plugin

[The entry file](#the-entry-file) &nbsp;·&nbsp; [The bootstrap file](#the-bootstrap-file) &nbsp;·&nbsp; [Declaring modules in the entry file instead](#declaring-modules-in-the-entry-file-instead) &nbsp;·&nbsp; [Constants](#constants) &nbsp;·&nbsp; [Methods](#methods)

The one object your plugin builds, in its entry file.

It holds every module the plugin is made of, builds each one, and answers what the plugin knows about itself — its slug, its own directory, the headers its entry file declares. Nothing has to be constructed by hand: a module reaches another with `$this->with( Path::class )`, and this is what hands it over.

Every module is declared in a `bootstrap.php`, which `bootstrap()` reads and `run()` builds. `wp zt init` creates that file and `wp zt add` appends to it, so a module works as soon as it is copied and the entry file never has to change.

**That file is the whole inventory.** Nothing is built without being listed there, and asking for an undeclared class throws — so reading it tells you what the plugin is made of, and that stays true. To reach a module your plugin may not have declared, emit a hook instead of asking for it: compose the name with `get_namespaced_name()`, check `has_action()`, and fall back when nothing is listening.

## The entry file

Constructs the plugin and runs it. Module declarations live in `bootstrap.php`, so this file is unchanged by how many modules the plugin uses.

The accessor holds the instance for anything outside the module system — a test, a template, a hand-registered callback — since a module already reaches every other one with `with()`.

```php
// acme-plugin.php
use Acme\Plugin\Core\Kernel\Plugin;

require_once __DIR__ . '/vendor/autoload.php';

function acme_plugin(): Plugin {
    static $plugin = null;

    $plugin ??= ( new Plugin( __FILE__ ) )->bootstrap()->run();

    return $plugin;
}

acme_plugin();
```

## The bootstrap file

Declares every module the plugin uses, with the configuration each requires. `wp zt init` creates the file and `wp zt add` appends to it, so a module is active as soon as it is copied.

With this in place, a file returned from `resources/actions/save-profile.php` becomes an AJAX action (see `AjaxAction`), a file returned from `resources/commands/greet.php` becomes the WP-CLI command `wp acme-plugin greet` (see `Command`), and a file returned from `resources/admin-pages/settings.php` becomes an admin menu page (see `AdminPage`) — none of them need registering by hand.

Every entry is a module, and listing one is what makes it exist. The top level is for modules that do nothing until something asks; one that acts on its own goes under the hook it acts on, and a class entry's value is the callback that configures it.

```php
// bootstrap.php
use Acme\Plugin\Core\Modules\AdminPages\AdminPages;
use Acme\Plugin\Core\Modules\Ajax\Ajax;
use Acme\Plugin\Core\Modules\Cron\Cron;

return array(
    Path::class,

    'acme_plugin_loaded' => array(
        AdminPages::class,
    ),

    'init' => array(
        Ajax::class,
        Cron::class => static function ( Cron $cron ): void {
            $cron->add_custom_interval( 'every_15_minutes', 900, 'Every 15 Minutes' );
        },
    ),
);
```

## Declaring modules in the entry file instead

`bootstrap.php` is optional — `bootstrap()` hands what it read to `declare_multiple()`, which is public and takes the same entries, so a plugin that prefers a single file calls it directly.

```php
// acme-plugin.php
function acme_plugin(): Plugin {
    static $plugin = null;

    $plugin ??= ( new Plugin( __FILE__ ) )
        ->declare_multiple(
            array(
                Path::class,
                'init' => array(
                    Cron::class => static function ( Cron $cron ): void {
                        $cron->add_custom_interval( 'every_15_minutes', 900, 'Every 15 Minutes' );
                    },
                ),
            )
        )
        ->run();

    return $plugin;
}

acme_plugin();
```

## Constants

### `MAX_SLUG_LENGTH`

```php
const MAX_SLUG_LENGTH = 32;
```

The longest slug a registered name can carry.

## Methods

### `__construct( $entry, $slug )`

Construct the plugin and its module repository.

```php
public function __construct( string $entry, ?string $slug = null )
```

|  | Details |
|---|---|
| **Parameters** | `$entry` — The absolute path to your plugin's entry file<br>`$slug` — The plugin slug; defaults to the entry file's directory name |
| **Return** | — |
| **Throws** | `InvalidArgumentException` — When the slug is not one a registered name can carry |

Pass `__FILE__` from your entry file. Everything else the plugin needs to know about itself — its own directory, and the headers it declares — is read from that path.

The slug is your plugin's namespace, and every name a module registers carries it: option names, hook names, script and style handles, AJAX action names, REST route namespaces, admin page slugs, WP-CLI commands (`wp {slug} greet`), the default table prefix, and the `{SLUG}_DEBUG` constant `is_plugin_debug()` reads. Omit it and it defaults to the *directory* the entry file sits in, so `plugins/acme-crm/plugin.php` gives `acme-crm`. Pass one explicitly when the registered names should read differently from the directory name.

Choose it once: changing it later renames everything registered under the old one, which orphans stored options and the schedules pointing at the old hook names.

**Spell it `acme`, `acme-crm` or `acme-crm2`**: a lowercase letter, then lowercase letters and digits, single dashes between them, up to `MAX_SLUG_LENGTH` characters. Anything else throws. A directory named otherwise is fine — pass the slug you want as the second argument.

<br>

### `configure( $name, $configurator )`

Configure a module before the plugin builds it.

```php
public function configure( string $name, callable $configurator ): self
```

|  | Details |
|---|---|
| **Parameters** | `$name` — The class name to configure<br>`$configurator` — Callback receiving the module and plugin |
| **Return** | Fluent interface for method chaining |
| **Throws** | — |

The callback runs when the module is built, after it has the plugin and before `on_boot()`, so it can set what boot depends on. The same callback a `bootstrap.php` class entry's value is — this is for a plugin that prefers to keep its configuration in the entry file.

```php
$plugin->configure( Cron::class, function ( Cron $cron ) {
    $cron->add_custom_interval( 'every_15_minutes', 900, 'Every 15 Minutes' );
} );
```

**This does not declare the module.** It remembers a callback against a name and loads nothing; the module still has to be listed, either in `bootstrap.php` or through `declare_multiple()`, for anything to build it.

<br>

### `declare( $name, $hook, $priority )`

Declare one module, and when the plugin should build it.

```php
public function declare( string $name, ?string $hook = null, int $priority = 10 ): self
```

|  | Details |
|---|---|
| **Parameters** | `$name` — The module class to declare<br>`$hook` — The hook to build it on, optionally `hook:priority`<br>`$priority` — The priority, when the hook does not carry one |
| **Return** | `self` |
| **Throws** | — |

Declaring is what makes a module exist: nothing outside what is declared is ever built, and asking for an undeclared class throws.

```php
$plugin->declare( Path::class );                  // built as run() reaches it
$plugin->declare( PostTypes::class, 'init' );     // built on init
$plugin->declare( Dashboard::class, 'init:20' );  // ordered behind the default 10
```

A module that acts on its own has to name a hook — left without one it throws, since the whole of what it does is decided by when it is built. `get_loaded_hook()` is the earliest that still has the whole plugin behind it, and where such a module belongs unless WordPress will not accept its work that early.

<br>

### `declare_multiple( $entries, $hook )`

Declare everything a `bootstrap.php` returns.

```php
public function declare_multiple( array $entries = array(), ?string $hook = null ): self
```

|  | Details |
|---|---|
| **Parameters** | `$entries` — The entries `bootstrap.php` would hold<br>`$hook` — The hook this list is listed under, when it is a group |
| **Return** | `self` |
| **Throws** | `ModuleException` — When an entry names no class, or is written in a shape this does not take |

What `bootstrap()` calls with the entries it read, and what an entry file calls directly when it prefers to keep its declarations in one file. Both take the same list, because they are the same declaration written in different places.

**A module that acts on its own is listed under the hook it acts on.** The timing is a heading over the modules that share it, said once, rather than repeated in every entry — so reading the file top to bottom is reading the order the plugin comes up in:

```php
return array(
    // Built with the plugin. Nothing happens until something asks.
    Path::class,
    Views::class,
    Options::class => static function ( Options $options ): void {
        $options->add_autoloaded_groups( array( 'reports' ) );
    },

    // These act. The key is when.
    'acme_plugin_loaded' => array(
        Log::class,          // binds its hook before anything can log through it
        AdminPages::class,
    ),

    'init' => array(
        PostTypes::class,
        Assets::class,
    ),

    // Behind Assets at 10: an inline script attached to a handle Assets
    // registers has to come after the handle exists.
    'init:20' => array(
        Dashboard::class,
    ),
);
```

Three shapes, and the key says which:

| Written | Means |
|---|---|
| `Path::class,` | Declared, built as `run()` reaches it. |
| `Options::class => $callable` | The same, with a configurator run before it boots. |
| `'init' => array( … )` | Everything in the list is built on `init`. |

A heading takes the same two class shapes, so a module needing a hook *and* configuration is `'init' => array( Assets::class => $callable )` rather than a fourth shape.

Nothing here loads a class. An entry remembers a name and a configurator remembers a closure against it, so a list naming a dozen classes reads without compiling any of them — they compile when `run()` builds them.

<br>

### `bootstrap( $file )`

Register and queue every module a `bootstrap.php` declares.

```php
public function bootstrap( ?string $file = null ): self
```

|  | Details |
|---|---|
| **Parameters** | `$file` — Absolute path to the bootstrap file; defaults to `bootstrap.php` beside the entry file |
| **Return** | `self` |
| **Throws** | `ModuleException` — When the file does not return an array, or an entry is malformed |

The file is one list, so the entry file never changes as modules are added — and `wp zt add` has somewhere to register what it copies, meaning a module works the moment it arrives rather than after a hand-edit:

```php
// bootstrap.php
return array(
    Path::class,
    Options::class => static function ( Options $options ): void {
        $options->add_autoloaded_groups( array( 'reports' ) );
    },

    'init' => array(
        Cron::class,
    ),
);
```

`declare_multiple()` has the whole grammar.

A module under a heading cannot be built before that hook: asking for it beforehand throws, naming the hook, rather than booting it on the wrong side of whatever it was waiting for.

A missing file is not an error. If there is no `bootstrap.php` the plugin is returned unchanged, so you can call this unconditionally from a template entry file and declare everything in the entry file itself.

A plugin with a hand-written entry file needs none of this: `declare_multiple()` takes the same entries directly, and the two approaches can be mixed.

<br>

### `set_languages_path( $path, $text_domain )`

Tell WordPress where this plugin keeps its own translations.

```php
public function set_languages_path( string $path, ?string $text_domain = null ): self
```

|  | Details |
|---|---|
| **Parameters** | `$path` — Plugin-relative directory holding the `.mo` files<br>`$text_domain` — Text domain; defaults to the plugin slug |
| **Return** | `self` |
| **Throws** | — |

Only needed for a plugin shipping a `languages/` directory of its own. WordPress already looks in `wp-content/languages/plugins` without being asked, which is where a wordpress.org-hosted plugin's translations are installed — so a plugin distributed that way needs no call at all.

The text domain defaults to the plugin slug, matching what `wp zt init` writes into `zestry.json` and stamps into every copied file, so the two cannot disagree unless you deliberately change one.

```php
// acme-plugin.php, inside the accessor that builds the plugin.
$plugin ??= ( new Plugin( __FILE__ ) )
    ->set_languages_path( 'languages' )
    ->bootstrap()
    ->run();
```

This registers a path rather than loading anything: translations load on the first `__()` call that needs them. Calling it here, as the plugin file loads, is therefore both early enough and not too early — what WordPress warns about is *using* a translation before `init`, not registering where they live.

<br>

### `get( $name )`

Get a module the plugin declared.

```php
public function get( string $name ): object
```

|  | Details |
|---|---|
| **Parameters** | `$name` — The class name to get |
| **Return** | The shared instance |
| **Throws** | `ModuleException` — If the class was never declared, or has not reached the hook it is listed under<br>`ModuleNotFoundException` — If the class does not exist or does not extend Module<br>`CircularDependencyException` — If the dependency graph re-enters itself |

The same instance every time. Inside a module or anything the plugin wired, `$this->with( X::class )` is the shorter way to say this; use `get()` from an entry file, a template, or anywhere holding the plugin itself.

**The module has to be declared.** Asking for one that is not throws, because `bootstrap.php` is the whole inventory of what the plugin is made of — and that only holds while nothing is built without being listed there.

<br>

### `make( $name, $configurator )`

Build a fresh, unshared instance of a module class.

```php
public function make( string $name, ?callable $configurator = null ): object
```

|  | Details |
|---|---|
| **Parameters** | `$name` — The class name to construct<br>`$configurator` — Optional callback run before boot |
| **Return** | A new instance |
| **Throws** | `ModuleNotFoundException` — If the class does not exist or does not extend Module<br>`CircularDependencyException` — If the dependency graph re-enters itself |

Unlike get(), never shared: every call returns a new instance. The configurator runs before boot(). Use it for a second instance of a module, such as a dedicated Options group:

```php
$api_options = $plugin->make( Options::class, function ( Options $o ) {
    $o->set_group_name( 'api' );
} );
```

<br>

### `wire( $instance )`

Give an object the plugin, so it can reach modules through `with()`.

```php
public function wire( PluginAware $instance ): PluginAware
```

|  | Details |
|---|---|
| **Parameters** | `$instance` — The object to wire |
| **Return** | The same instance, now holding the plugin |
| **Throws** | — |

Lets an object the plugin did not build — a CLI command, an AJAX action, an admin page loaded from a file — reach every module exactly the way a module does, without being one itself. The object must implement `PluginAware`, which the `WithPlugin` trait satisfies.

<br>

### `get_slug()`

Get the plugin slug, used to namespace every module's registered names.

```php
public function get_slug(): string
```

|  | Details |
|---|---|
| **Parameters** | — |
| **Return** | The plugin identifier |
| **Throws** | — |

<br>

### `get_namespaced_name( $name, $glue )`

A local name, namespaced to this plugin.

```php
public function get_namespaced_name( string $name, string $glue = '-' ): string
```

|  | Details |
|---|---|
| **Parameters** | `$name` — The local name, without the plugin prefix<br>`$glue` — What to join the two halves with |
| **Return** | The namespaced name |
| **Throws** | — |

Every global name this plugin registers comes through here — an action or filter of your own, and behind the scenes a script handle, a transient key, a meta box id, a cron hook, a Site Health identifier, a REST namespace, a WP-CLI command, an option name. One function, so anything this plugin puts into a namespace it shares with every other plugin on the site is prefixed the same way and cannot collide.

```php
do_action( $plugin->get_namespaced_name( 'import-finished' ), $count );
```

Both halves are passed through exactly as written, so your slug and your local name appear in the result the way you spelled them. `$glue` is what joins them, and defaults to the hyphen a hook, handle or id wants; the destinations that want something else say so — an option name joins with `_`, a REST namespace with `/`, a WP-CLI command with a space.

<br>

### `get_bootstrap_file()`

The file this plugin reads its module declarations from.

```php
public function get_bootstrap_file(): ?string
```

|  | Details |
|---|---|
| **Parameters** | — |
| **Return** | The path, or null when `bootstrap()` has not run |
| **Throws** | — |

The path `bootstrap()` read: `bootstrap.php` beside your entry file unless you passed it another. Null until `bootstrap()` is called, which is also the answer for a plugin declaring its modules in the entry file instead.

<br>

### `get_entry_file()`

The plugin's entry file, as passed to the constructor.

```php
public function get_entry_file(): string
```

|  | Details |
|---|---|
| **Parameters** | — |
| **Return** | `string` |
| **Throws** | — |

<br>

### `get_header( $header )`

Read a single plugin header field from the entry file's own docblock.

```php
public function get_header( string $header ): ?string
```

|  | Details |
|---|---|
| **Parameters** | `$header` — The header name as WordPress declares it, e.g. 'Version', 'Text Domain' |
| **Return** | The header's value, or null if absent or blank |
| **Throws** | — |

Reads the same header comment WordPress itself parses for the plugin list, so nothing needs declaring twice. Not cached — read fresh on every call.

<br>

### `get_version()`

Get the plugin's own declared version.

```php
public function get_version(): ?string
```

|  | Details |
|---|---|
| **Parameters** | — |
| **Return** | The plugin's `Version:` header value, or null if absent |
| **Throws** | — |

Shorthand for `get_header( 'Version' )`.

<br>

### `is_wp_debug()`

Check if WordPress debug mode is enabled.

```php
public function is_wp_debug(): bool
```

|  | Details |
|---|---|
| **Parameters** | — |
| **Return** | True if the WP_DEBUG constant is defined and set to true |
| **Throws** | — |

Read fresh, so a late `define( 'WP_DEBUG' )` is still reflected.

<br>

### `is_wp_cli()`

Check if WP-CLI is active.

```php
public function is_wp_cli(): bool
```

|  | Details |
|---|---|
| **Parameters** | — |
| **Return** | True if the WP_CLI constant is defined and set to true |
| **Throws** | — |

<br>

### `is_plugin_debug()`

Check if plugin debug mode is enabled.

```php
public function is_plugin_debug(): bool
```

|  | Details |
|---|---|
| **Parameters** | — |
| **Return** | True if the plugin's debug constant is defined and set to true |
| **Throws** | — |

Checks for a plugin-specific debug constant based on the plugin slug. Constant name format: {PLUGIN_SLUG}_DEBUG (e.g., ACME_PLUGIN_DEBUG).

<br>

### `run()`

Build every declared module, and run an optional ready callback.

```php
public function run(): self
```

|  | Details |
|---|---|
| **Parameters** | — |
| **Return** | `self` |
| **Throws** | `ModuleException` — When a declared class cannot be built, or a discovery module cannot read its root<br>`Throwable` — Whatever a module's own `on_boot()` raises, unchanged |

Call this from the plugin entry file once the modules are declared. It runs synchronously, so the caller controls timing: invoke it directly at plugin load, or from inside a `plugins_loaded`/`init` hook when a later point is needed. Every declared class is built first — booting as it goes, unless it is listed under a hook — and the plugin then announces itself on `get_loaded_hook()`, which is where anything waiting for it listens.

An `ActivationHandler` subclass is the one case where *when* this is called is load-bearing: WordPress fires `activate_{plugin}` immediately after the entry file loads, so a `run()` deferred to a later hook is already too late to register the activation callback.

**Modules boot in the order they are listed**, each fully resolved and booted before the next begins. A module that throws stops the ones after it, and nothing wraps what it threw: the toolkit's own failures are `ModuleException`s, and whatever your `on_boot()` raises arrives as itself.

<br>

### `get_loaded_hook()`

The action this plugin fires at the end of `run()`.

```php
public function get_loaded_hook(): string
```

|  | Details |
|---|---|
| **Parameters** | — |
| **Return** | The action name |
| **Throws** | — |

`{slug}_loaded` — `acme_plugin_loaded` for a plugin slugged `acme-plugin` — passed this plugin. It fires once every declared module is built, so a listener can reach any of them:

```php
add_action( 'acme_plugin_loaded', function ( $plugin ) {
    $plugin->get( Options::class )->get( 'api_key' );
} );
```

**It is also a heading a module can be listed under.** A module under this hook is built when it fires rather than in declaration order, which is how a module says "after everything else this plugin has":

```php
// bootstrap.php
'acme_plugin_loaded' => array(
    Reports::class,
),
```

It fires wherever `run()` is called from, so a plugin that runs as it loads announces itself before `init` and one that runs from a later hook announces itself then. A module whose work WordPress will not accept that early belongs under `'init'` instead.

Underscored throughout — `acme_plugin_loaded`, not `acme-plugin-loaded` — which is how WordPress spells an action and what keeps this from being the one hook that reads differently from every other one on the site. Composed here rather than through `get_namespaced_name()`, whose job is to pass both halves through exactly as written.

<br>

### `get_debug_constant( $slug )`

The name of a plugin's own debug constant.

```php
public static function get_debug_constant( string $slug ): string
```

|  | Details |
|---|---|
| **Parameters** | `$slug` — The plugin slug |
| **Return** | `string` |
| **Throws** | — |

`{SLUG}_DEBUG`, upper-cased with dashes turned into underscores, so a plugin slugged `acme-crm` reads `ACME_CRM_DEBUG`. `is_plugin_debug()` is what asks whether it is set.

Static, and taking the slug rather than reading its own, so tooling can name the constant for a plugin that is not running — `wp zt debug` writes this name into `wp-config.php`, and a second spelling of the rule would be a command that turns on a constant nothing reads.
