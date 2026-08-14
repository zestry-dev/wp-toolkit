<!--
    Generated from src/Modules/Abilities/Abilities.php.
    Do not edit by hand: run `composer docs` after changing the source.
-->

# Abilities

Discovers `resources/abilities/` &nbsp;·&nbsp; Each file returns [`Ability`](ability.md) &nbsp;·&nbsp; Dependencies [`path`](../path/), [`request`](../request/)

Publishes what your plugin can do, for the REST API and for AI agents.

A file in `resources/abilities/` returns an `Ability`, and its filename is the name it registers under: `create-order.php` becomes `{plugin-slug}/create-order`. Each one carries a description and JSON Schemas for its input and output — enough for something that has never seen your code to call it correctly.

WordPress gives every public ability a REST endpoint at `wp-json/wp-abilities/v1/abilities/{ability}/run` for free. An MCP adapter installed on the site turns the same registration into a tool an AI agent can call, also for free: there is no protocol code to write on your side, which is the point of the API. Requires WordPress 6.9 or newer.

> [!IMPORTANT]
> **No adapter ships with WordPress, and you do not need one to be finished.** An ability is a registry entry first. WordPress serves the REST half itself, so that half is testable today; the MCP half is a separate plugin somebody installs, and whether one is present is a property of the site rather than of your code. Write and verify against REST, and an adapter picks the same registration up when it arrives.

Three commands prove the REST half end to end, and are worth running once:

```bash
# Every ability registered on this site, yours among them.
wp eval 'echo wp_json_encode( array_keys( wp_get_abilities() ) );'

# And the endpoint, as a client sees it.
curl -s "$(wp option get siteurl)/wp-json/wp-abilities/v1/abilities"

# Running one. The arguments go under a single `input`, never at the top level.
curl -s "$(wp option get siteurl)/wp-json/wp-abilities/v1/abilities/acme-plugin/list-orders/run?input[status]=open"
```

An ability missing from the first is not registered; one missing from the second is registered but not `Ability::is_public()`. The third is a `GET` because that ability reads; `Effect` decides both the method and whether `input` rides in the query string or the JSON body.

Abilities are worth writing even when nothing external calls them yet. One ability is one operation, described once, reachable from a REST client, an agent, a WP-CLI command and your own PHP through `run()` — instead of the same operation written four times.

[Adding it](#adding-it) &nbsp;·&nbsp; [An ability](#an-ability) &nbsp;·&nbsp; [Calling one from your own code](#calling-one-from-your-own-code) &nbsp;·&nbsp; [Changing the defaults](#changing-the-defaults) &nbsp;·&nbsp; [Writing an Ability](#writing-an-ability) &nbsp;·&nbsp; [Related classes](#related-classes) &nbsp;·&nbsp; [Constants](#constants) &nbsp;·&nbsp; [Methods](#methods) &nbsp;·&nbsp; [See also](#see-also)

## Adding it

```bash
wp zt add abilities
```

> [!IMPORTANT]
> **A module is built because `bootstrap.php` lists it, and the heading says when.** `Abilities` acts the moment it is built, so it goes under the hook it acts on — which `wp zt add` writes for you. Left at the top level it throws; left out entirely, nothing is discovered and nothing reports why, which is what [`wp zt doctor`](../../commands/doctor.md) catches.

```php
// bootstrap.php
return array(
    'init' => array(
        Abilities::class,
    ),
);
```

## An ability

A typed property carrying a `RequestArgument` is both the input schema and the value: it is described once, validated by WordPress, and bound before your code runs. The property says the type, and whether it is required — one with no default has to be supplied.

```php
// resources/abilities/publish-post.php
return new class extends Ability {

    public function label(): string {
        return __( 'Publish a draft', 'acme-plugin' );
    }

    public function description(): string {
        return __( 'Publishes a draft post immediately. Already-published posts are left alone.', 'acme-plugin' );
    }

    public function effect(): Effect {
        return Effect::Update;
    }

    public function is_public(): bool {
        return true;
    }

    #[RequestArgument( 'The draft to publish.' )]
    public int $id;

    public function permission_check( mixed $input ): bool {
        return current_user_can( 'publish_post', $this->id );
    }

    public function handle( mixed $input ): mixed {
        return array( 'published' => (bool) wp_publish_post( $this->id ) );
    }
};
```

## Calling one from your own code

```php
$abilities = $this->with( Abilities::class );

$result = $abilities->run( 'publish-post', array( 'id' => 42 ) );

if ( is_wp_error( $result ) ) {
    // Invalid input, no permission, or the ability said no.
}
```

## Changing the defaults

Group them

```php
// bootstrap.php
return array(
    'init' => array(
        Abilities::class => static function ( Abilities $abilities ): void {
            $abilities->add_categories(
                array(
                    'acme-billing' => array(
                        'label'       => __( 'Acme billing', 'acme-plugin' ),
                        'description' => __( 'Invoices, refunds and payment methods.', 'acme-plugin' ),
                    ),
                )
            );
        },
    ),
);
```

The callback runs on that hook, right before the module registers anything, which is what makes the `__()` calls safe — one running at plugin load would report `_load_textdomain_just_in_time` on every request.

## Writing an Ability

A file in `resources/abilities/` returns an [`Ability`](ability.md) instance, which `wp zt make ability <name>` generates.

## Related classes

Shipped with this module, and written against directly:

- [`Effect`](effect.md) — enum, what running an ability does to the site

## Constants

### `ABILITIES_ROOT`

```php
const ABILITIES_ROOT = 'resources/abilities';
```

Where abilities are discovered, relative to the plugin root.

## Methods

### `add_categories( $categories )`

Declare ability categories of your own.

```php
public function add_categories( array $categories ): void
```

|  | Details |
|---|---|
| **Parameters** | `$categories` — Labels or configuration, keyed by slug |
| **Return** | — |
| **Throws** | `InvalidArgumentException` — When an entry is an array without a label |

Every ability belongs to exactly one category, and WordPress refuses to register one whose category does not exist. You already have a category named after your plugin, registered for you and used by default — this is for splitting a larger plugin into groups a client can show separately, or for a category shared with another plugin of yours.

Keyed by slug, the same shape `bootstrap.php` uses for modules. A plain string is the label, and an array carries a description alongside it:

```php
// bootstrap.php
return array(
    'init' => array(
        Abilities::class => static function ( Abilities $abilities ): void {
            $abilities->add_categories(
                array(
                    'acme-billing' => __( 'Acme billing', 'acme-plugin' ),
                    'acme-reports' => array(
                        'label'       => __( 'Acme reports', 'acme-plugin' ),
                        'description' => __( 'Reads sales figures. Changes nothing.', 'acme-plugin' ),
                    ),
                )
            );
        },
    ),
);

// resources/abilities/refund-order.php
public function category(): string {
    return 'acme-billing';
}
```

The description is worth writing. A client listing categories shows it to decide which group to look in, so "Reads sales figures. Changes nothing." earns its place where the generated fallback does not.

A slug is registered exactly as given and is not namespaced to the plugin: WordPress's own `site` and `user` are unprefixed, and an ability naming a category has to match it verbatim. So choose slugs distinctive enough not to collide — a category already registered by WordPress or another plugin is left as it is rather than replaced.

**Call it from the entry's callback, as the example does.** A label and a description are both user-visible, so they usually want translating, and that callback runs on the boot hook, where ordinary `__()` is correct — which is why both are plain strings and nothing here is lazy. Calling it from somewhere that runs at plugin load would ask for the text domain before WordPress is ready, reporting `_load_textdomain_just_in_time` on every request.

<br>

### `get_discovered_abilities()`

Every discovered ability, keyed by its local name.

```php
public function get_discovered_abilities(): array
```

|  | Details |
|---|---|
| **Parameters** | — |
| **Return** | Wired instances keyed by local name |
| **Throws** | `DiscoveryException` — When a file returns the wrong value |

<br>

### `get_ability_name( $name )`

The full name an ability file registers under.

```php
public function get_ability_name( string $name ): string
```

|  | Details |
|---|---|
| **Parameters** | `$name` — The ability's local name — its filename without `.php` |
| **Return** | `string` |
| **Throws** | — |

Namespaced to the plugin, since abilities share one registry with every other plugin on the site, and joined with the `/` that registry expects. Both halves are read exactly as written, so `create-order.php` in a plugin slugged `acme-plugin` registers as `acme-plugin/create-order`.

WordPress accepts only lowercase letters, digits and dashes in either half. A file whose name it would refuse is refused here first, when the ability is discovered — see `DiscoveryException::unregistrable_ability_name()`.

<br>

### `get_name_of( $ability )`

This ability's full name, from the file it was discovered in.

```php
public function get_name_of( Ability $ability ): string
```

|  | Details |
|---|---|
| **Parameters** | `$ability` — The instance to look up |
| **Return** | `string` |
| **Throws** | `InvalidArgumentException` — When the instance was not discovered by this module |

<br>

### `get_category_slug()`

The slug of the category registered for this plugin.

```php
public function get_category_slug(): string
```

|  | Details |
|---|---|
| **Parameters** | — |
| **Return** | `string` |
| **Throws** | — |

Your plugin slug, in the form WordPress accepts. It is what `Ability::category()` returns unless an ability says otherwise, and it is registered only if at least one ability actually uses it.

<br>

### `run( $name, $input )`

Run one of this plugin's abilities.

```php
public function run( string $name, mixed $input = null ): mixed
```

|  | Details |
|---|---|
| **Parameters** | `$name` — The ability's local name<br>`$input` — Input matching the ability's schema |
| **Return** | The ability's result, or a `WP_Error` |
| **Throws** | `InvalidArgumentException` — When this plugin has no such ability |

Takes the local name — the filename — and applies your namespace, so `run( 'publish-post', … )` calls `{plugin-slug}/publish-post`. Everything an outside caller gets happens here too: the input is validated against the schema, `Ability::permission_check()` is checked, and the result is validated on the way out.

Returns whatever the ability returned, or a `WP_Error` for any of those three failing. That makes an ability the one implementation of an operation, called the same way from a CLI command, an admin page or a cron schedule as from an agent.

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

## See also

- [`Ability`](ability.md) — what a file in `resources/abilities/` returns
- [`path`](../path/) — copied in alongside this one
- [`request`](../request/) — copied in alongside this one
- [`Module`](../module.md) — what every module inherits
- [`wp zt add abilities`](../../commands/add.md) — the command that copies it
