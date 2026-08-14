<!--
    Generated from src/Kernel/Plugin.php.
    Do not edit by hand: run `composer docs` after changing the source.
-->

# Plugin

[The entry file](#the-entry-file) &nbsp;·&nbsp; [The bootstrap file](#the-bootstrap-file) &nbsp;·&nbsp; [Declaring modules in the entry file instead](#declaring-modules-in-the-entry-file-instead) &nbsp;·&nbsp; [Constants](#constants) &nbsp;·&nbsp; [Methods](#methods)

The one object your plugin builds, in its entry file.

It holds every service and module the plugin uses, builds each the first time it is needed, and answers what the plugin knows about itself — its slug, its own directory, the headers its entry file declares. Nothing else has to be constructed by hand: a class asks for another by declaring a typed property, and this is what fills it in.

Modules are declared in a `bootstrap.php`, which `bootstrap()` reads and `run()` builds and boots. `wp zt init` creates that file and `wp zt add` appends to it, so a module is active as soon as it is copied and the entry file never has to change. `configure()` and `autoload()` are public, so a plugin that prefers to declare its modules in the entry file can do that instead, and the two approaches can be combined.

A `Service` is never declared there: it resolves on demand through `get()`, or is injected into another class by type. One that takes configuration is given it with `configure()` in the entry file.

There is nothing to register: `get()` builds any `Service` subclass the first time you ask for it — both kinds, since a Module is a Service that also acts on its own — so asking whether the plugin "has" one is never a question you need to answer. To reach a module your plugin may not have added, emit a hook instead of asking for it, the way `Options` and `Cron` reach `Log`.

## The entry file

Constructs the plugin and runs it. Module declarations live in `bootstrap.php`, so this file is unchanged by how many modules the plugin uses.

The accessor holds the instance for anything outside the module system — a test, a template, a hand-registered callback — since modules themselves are injected by type and never need it.

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

With this in place, a file returned from `actions/save-profile.php` becomes an AJAX action (see `AjaxAction`), a file returned from `commands/greet.php` becomes the WP-CLI command `wp acme-plugin greet` (see `Command`), and a file returned from `admin-pages/settings.php` becomes an admin menu page (see `AdminPage`) — none of them need registering by hand.

Every entry is a module, and listing one is what builds it. A module needing no configuration is written bare, as `Ajax::class` and `AdminPages::class` below; one that needs some gets an array, whose `before_boot` is the callback that configures it.

```php
// bootstrap.php
use Acme\Plugin\Core\Modules\AdminPages\AdminPages;
use Acme\Plugin\Core\Modules\Ajax\Ajax;
use Acme\Plugin\Core\Modules\Cron\Cron;

return array(
    Cron::class => array(
        'before_boot' => static function ( Cron $cron ): void {
            $cron->add_custom_interval( 'every_15_minutes', 900, 'Every 15 Minutes' );
        },
    ),
    Ajax::class,
    AdminPages::class,
);
```

## Declaring modules in the entry file instead

`bootstrap.php` is optional. It calls `configure()` and `autoload()`, both of which are public, so a plugin that prefers a single file can call them directly.

```php
// acme-plugin.php
function acme_plugin(): Plugin {
    static $plugin = null;

    $plugin ??= ( new Plugin( __FILE__ ) )
        ->configure(
            Cron::class,
            static function ( Cron $cron ): void {
                $cron->add_custom_interval( 'every_15_minutes', 900, 'Every 15 Minutes' );
            }
        )
        ->autoload( array( Cron::class ) )
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

Construct the plugin and its service repository.

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

### `configure( $name, $initializer )`

Configure a service or a module before anything builds it.

```php
public function configure( string $name, callable $initializer ): self
```

|  | Details |
|---|---|
| **Parameters** | `$name` — The class name to configure<br>`$initializer` — Callback receiving the instance and plugin |
| **Return** | Fluent interface for method chaining |
| **Throws** | — |

Either kind: what is stored is a callback against a class name, and the two are configured identically. The initializer runs when the class is first built, after wiring and — for a `Module` — before `on_boot()`, so it can set what boot depends on. Only needed by a class that takes configuration; anything else resolves fine without one.

```php
$plugin->configure( Cron::class, function ( Cron $cron ) {
    $cron->add_custom_interval( 'every_15_minutes', 900, 'Every 15 Minutes' );
} );
```

Nothing here declares a class to the plugin, and nothing here loads one: every service and module is found by type, so this only remembers a callback against a name. A module still has to be queued — by `autoload()`, or by being listed in `bootstrap.php` — for anything to happen. **This is where a service is configured**, since `bootstrap.php` is modules only: the callback runs when something first asks for it, and never at all if nothing does.

<br>

### `autoload( $modules )`

Queue modules to be resolved when `run()` is called.

```php
public function autoload( array $modules = array() ): self
```

|  | Details |
|---|---|
| **Parameters** | `$modules` — Module classes to resolve automatically |
| **Return** | `self` |
| **Throws** | — |

Only remembers the class names — nothing is built here, and no hook of this method's own decides the timing. Your entry file does, by choosing when it calls `run()`, which resolves the queue synchronously and boots each module as it goes.

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

The file returns one flat list, so the entry file never changes as modules are added — and `wp zt add` has somewhere to register what it copies, meaning a module works the moment it arrives rather than after a hand-edit:

```php
// bootstrap.php
return array(
    Cron::class => array(
        'before_boot' => static function ( Cron $cron ): void {
            $cron->add_custom_interval( 'every_15_minutes', 900, 'Every 15 Minutes' );
        },
    ),
    Options::class,
);
```

**A module needing no configuration is written bare**, as `Options::class,` above. One that needs some gets an array, which takes three keys:

| Key | What it does |
|---|---|
| `before_boot` | The initializer — the same callback `configure()` takes. Runs after wiring, immediately before `on_boot()`. |
| `boots_on` | A WordPress hook to boot on, for a module that cannot do its work as the plugin loads. Without it the module boots the moment `run()` reaches it. |
| `priority` | What `boots_on` binds at. Defaults to 10. |

Configuration is always the array, never a bare callback, so adding a `boots_on` to an entry that already has an initializer is one more line rather than a rewrite:

```php
Blocks::class => array(
    'boots_on'    => 'init',
    'before_boot' => static fn ( Blocks $blocks ) => $blocks->add_categories( $categories ),
),
```

A module that names a `boots_on` cannot be built before that hook: asking for it through `get()` beforehand throws, naming the hook, rather than booting it on the wrong side of whatever it was waiting for.

**The file is modules only, and listing one is what builds it.** That is its whole job, which is what makes it readable at a glance: every name here is something the plugin starts, and its array — when it has one — configures it on the way.

A `Service` does not belong here. It is built the moment something asks for it, so listing it would only build it sooner than it needed to be. Configure one from the entry file instead, where `configure()` takes the same callback:

```php
( new Plugin( __FILE__ ) )
    ->configure( DB::class, static fn ( DB $db ) => $db->set_table_prefix( 'acme' ) )
    ->bootstrap()
    ->run();
```

Because every entry means one thing, nothing here has to ask what a class *is* — so reading this file compiles none of the classes it names. They load when `run()` builds them.

A missing file is not an error. If there is no `bootstrap.php` the plugin is returned unchanged, so you can call this unconditionally from a template entry file and declare everything in the entry file itself.

A plugin with a hand-written entry file needs none of this: `configure()` and `autoload()` are public, and the two approaches can be mixed.

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

The text domain defaults to the plugin slug, matching what `wp zt init` writes into `zestry.json` and stamps into every copied file, so the two cannot disagree unless a consumer deliberately changes one.

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

Get the given service or module from the plugin.

```php
public function get( string $name ): object
```

|  | Details |
|---|---|
| **Parameters** | `$name` — The class name to resolve |
| **Return** | The resolved instance |
| **Throws** | `ModuleNotFoundException` — If the class does not exist or does not extend Service<br>`CircularDependencyException` — If the dependency graph re-enters itself |

Resolved once and cached, so repeated calls return the same object. One accessor for both kinds, since a `Module` *is* a `Service`: what differs is that resolving a module also boots it.

<br>

### `make( $name, $configurator )`

Build a fresh, fully wired instance of a service or module class.

```php
public function make( string $name, ?callable $configurator = null ): object
```

|  | Details |
|---|---|
| **Parameters** | `$name` — The class name to construct<br>`$configurator` — Optional callback run after wiring, before boot |
| **Return** | A new, wired instance |
| **Throws** | `ModuleNotFoundException` — If the class does not exist or does not extend Service<br>`CircularDependencyException` — If the dependency graph re-enters itself |

Unlike get(), never cached: every call returns a new wired instance. The configurator runs after wiring and before boot(). Use it for a second instance of a module, such as a dedicated Options group:

```php
$api_options = $plugin->make( Options::class, function ( Options $o ) {
    $o->set_group_name( 'api' );
} );
```

<br>

### `wire( $instance )`

Assign the plugin and inject declared dependencies into an existing object.

```php
public function wire( PluginAware $instance ): PluginAware
```

|  | Details |
|---|---|
| **Parameters** | `$instance` — The object to wire |
| **Return** | The same instance, now wired |
| **Throws** | `CircularDependencyException` — If the dependency graph re-enters itself |

Lets an object built outside the resolution lifecycle — a CLI command or an AJAX action loaded from a file — declare typed properties and receive them the way a service does, without being one itself. The object must implement `PluginAware`, which the `WithPlugin` trait satisfies.

Each typed property is resolved through `get()` as it is injected, so wiring an object can raise the same failures resolving one does.

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

### `run( $on_boot_callback )`

Resolve autoloaded modules and run an optional ready callback.

```php
public function run( ?callable $on_boot_callback = null ): self
```

|  | Details |
|---|---|
| **Parameters** | `$on_boot_callback` — Optional callback receiving this plugin after modules are ready |
| **Return** | `self` |
| **Throws** | `ModuleException` — When a queued class cannot be built, or a discovery module cannot read its root<br>`Throwable` — Whatever a module's own `on_boot()` raises, unchanged |

Call this from the plugin entry file once modules are registered. It runs synchronously, so the caller controls timing: invoke it directly at plugin load, or from inside a `plugins_loaded`/`init` hook when a later point is needed. Queued classes resolve first — and a `Module` boots as it resolves — then the callback runs with all of them available.

An `ActivationHandler` subclass is the one case where *when* this is called is load-bearing: WordPress fires `activate_{plugin}` immediately after the entry file loads, so a `run()` deferred to a later hook is already too late to register the activation callback.

**Modules boot in the order they are listed**, each fully resolved and booted before the next begins. A module that throws stops the ones after it, and nothing wraps what it threw: the toolkit's own failures are `ModuleException`s, and whatever your `on_boot()` raises arrives as itself.

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
