<!--
    Generated from src/Modules/IconsLibrary/IconsLibrary.php.
    Do not edit by hand: run `composer docs` after changing the source.
-->

# IconsLibrary

Discovers `svg-icons/` &nbsp;·&nbsp; Dependencies [`path`](../../services/path/)

Publishes your plugin's SVG icons, for the Icon block and for your own markup.

An icon is a file in `svg-icons/`. `arrow-right.php` registers as `{plugin-slug}/arrow-right` — offered in the editor's icon picker under a collection named after your plugin, served on the REST API at `wp/v2/icons`, and rendered in PHP as `$this->icons->get( 'arrow-right' )`. Requires WordPress 7.1 or newer.

> [!IMPORTANT]
> **WordPress keeps `<svg>`, `<path>` and `<polygon>` and throws the rest away.** It sanitizes every icon through `wp_kses()`, so a `<circle>`, a `<g>`, a `<rect>` or a `<use>` is removed, as is any attribute outside a short list — `stroke` among them, which silently empties an icon drawn as outlines rather than fills. Export icons as filled paths.
>
> With your plugin's own debug mode on — `wp zt debug on` — an icon that would lose anything throws a `DiscoveryException` naming the file and what it lost, rather than registering something that renders as a blank square.

[Adding it](#adding-it) &nbsp;·&nbsp; [An icon](#an-icon) &nbsp;·&nbsp; [A plain `.svg`, when that is all you need](#a-plain-svg-when-that-is-all-you-need) &nbsp;·&nbsp; [Using one](#using-one) &nbsp;·&nbsp; [Naming one something other than its file](#naming-one-something-other-than-its-file) &nbsp;·&nbsp; [Changing the defaults](#changing-the-defaults) &nbsp;·&nbsp; [Constants](#constants) &nbsp;·&nbsp; [Methods](#methods) &nbsp;·&nbsp; [See also](#see-also)

## Adding it

```bash
wp zt add module icons-library
```

> [!IMPORTANT]
> **A module is built because `bootstrap.php` lists it.** `IconsLibrary` binds its hooks when the plugin builds it, so it has to be listed there — which `wp zt add` writes for you. Left out, nothing is discovered and nothing reports why; [`wp zt doctor`](../../commands/doctor.md) is what catches it.

```php
// bootstrap.php
return array(
    IconsLibrary::class,
);
```

## An icon

**Write icons as `.php`.** The template echoes the SVG and returns what the icon is called, which is the only shape that lets the label be translated: one derived from a filename cannot be, and one kept in a second file has to be kept in step with the first.

```php
<!-- svg-icons/arrow-right.php -->
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24">
    <path d="M5 12h14" />
</svg>
<?php
return array(
    'label' => __( 'Arrow, pointing right', 'acme-plugin' ),
);
```

The label is what the picker shows and what a screen reader announces, so it is worth writing. Return nothing and it is built from the filename instead — `arrow-right.php` becomes "Arrow Right", which is serviceable and untranslated.

An array rather than the label alone, so an icon can say a second thing later without every template that names itself having to change shape.

## A plain `.svg`, when that is all you need

A bare `.svg` in the same directory is registered too, straight from the file a designer exported — nothing to write, and WordPress reads it only when the icon is actually rendered.

```php
svg-icons/
  arrow-right.php    a template: translated label, run on every request
  logo.svg           a file: label built from the filename, read on demand
```

The label is the whole of the difference. `logo.svg` is announced as "Logo" in every language, so reach for `.svg` where nobody reads the name — and rename the file to `.php` the moment somebody does. Both spellings of one name is an error rather than a preference: `arrow.php` and `arrow.svg` are one icon.

## Using one

```php
public IconsLibrary $icons;

public function render(): string {
    return $this->icons->get( 'arrow-right', array( 'size' => 32 ) );
}
```

## Naming one something other than its file

`name` replaces the one taken from the filename, which is how an icon keeps a name a filename could not carry:

```php
<!-- svg-icons/logo-2024.php -->
<svg …>…</svg>
<?php
return array(
    'name'  => 'brand_mark',
    'label' => __( 'Acme logo', 'acme-plugin' ),
);
```

**Do not put your plugin slug in it.** WordPress names an icon `collection/icon-name`, and the collection *is* your plugin — so the name here is the bare half after the slash, and `{plugin-slug}/brand_mark` is what gets registered. A name you prefix yourself registers as `{plugin-slug}/{plugin-slug}-brand_mark`.

The filename stays the default, and stays the thing to reach for. A declared name is a second place the answer lives, so it earns its keep only when the file cannot be called what the icon is.

## Changing the defaults

Group them

```php
IconsLibrary::class => array(
    'boots_on'    => 'init',
    'priority'    => 100,
    'before_boot' => static function ( IconsLibrary $icons ): void {
        $icons->set_default_collection_details(
            __( 'Acme icons', 'acme-plugin' ),
            __( 'Everything Acme draws.', 'acme-plugin' )
        );

        $icons->add_collections(
            array( 'acme-brand' => __( 'Acme brand', 'acme-plugin' ) )
        );
    },
),
```

You have one collection already, slugged with your plugin slug and labelled `{slug} icons` until you say otherwise. `before_boot` runs on the hook, right before the module registers anything — which is what makes the `__()` calls safe, and why this module names a hook at all.

Late on `init` because it goes after WordPress's own registries, built at 0 and 10, and after any other plugin registering a collection an icon of yours might name.

## Constants

### `SVG_ICONS_ROOT`

```php
const SVG_ICONS_ROOT = 'svg-icons';
```

Where icons are discovered, relative to the plugin root.

## Methods

### `add_collections( $collections )`

Declare icon collections of your own.

```php
public function add_collections( array $collections ): void
```

|  | Details |
|---|---|
| **Parameters** | `$collections` — Labels or configuration, keyed by slug |
| **Return** | — |
| **Throws** | `InvalidArgumentException` — When an entry is an array without a label |

Every icon belongs to exactly one collection, and WordPress groups the editor's picker by them. You already have one named after your plugin, registered for you and used by default — this is for splitting a larger set into groups a designer can find things in, or for a collection shared with another plugin of yours.

Keyed by slug, the same shape `bootstrap.php` uses for modules. A plain string is the label, and an array carries a description alongside it:

```php
$icons->on_wp_init(
    static function ( IconsLibrary $icons ): void {
        $icons->add_collections(
            array(
                'acme-brand' => __( 'Acme brand', 'acme-plugin' ),
                'acme-ui'    => array(
                    'label'       => __( 'Acme interface', 'acme-plugin' ),
                    'description' => __( 'Arrows, spinners and toggles.', 'acme-plugin' ),
                ),
            )
        );
    }
);

// svg-icons/logo.php
return array(
    'collection' => 'acme-brand',
    'label'      => __( 'Acme logo', 'acme-plugin' ),
);
```

A slug is registered exactly as given and is not namespaced to the plugin, matching WordPress's own unprefixed `core` — so choose slugs distinctive enough not to collide. One another plugin already registered is left as it is rather than replaced, and an icon may file itself under it.

**Call it from the entry's `before_boot`, as the example does.** A label and a description are both user-visible, so they want translating, and `before_boot` runs on the boot hook rather than at plugin load, where a `__()` reports `_load_textdomain_just_in_time` on every request.

<br>

### `set_default_collection_details( $label, $description )`

Name the collection your plugin gets by default.

```php
public function set_default_collection_details( string $label, string $description = '' ): void
```

|  | Details |
|---|---|
| **Parameters** | `$label` — What the picker calls this collection<br>`$description` — One sentence under it, or '' for none |
| **Return** | — |
| **Throws** | — |

Its slug is your plugin slug and stays that way — this is the label a designer reads in the picker, and the description under it. Without one the label is `{slug} icons`, which is accurate and says nothing.

The description is empty by default and stays out of the registration entirely when it is: an absent description is honest, where a generated sentence occupies the space a real one would go in.

**Call it from the entry's `before_boot`**, for the reason `add_collections()` gives — both of these are read by a person, so both want translating.

<br>

### `get_discovered_icons()`

Every discovered icon, as local name => absolute path.

```php
public function get_discovered_icons(): array
```

|  | Details |
|---|---|
| **Parameters** | — |
| **Return** | `array` |
| **Throws** | `DiscoveryException` — When a name cannot be registered |

<br>

### `get_icon_name( $name, $collection )`

The full name an icon registers under.

```php
public function get_icon_name( string $name, ?string $collection = null ): string
```

|  | Details |
|---|---|
| **Parameters** | `$name` — The icon's local name<br>`$collection` — The collection it belongs to, or null for the plugin's own |
| **Return** | `string` |
| **Throws** | — |

Namespaced to the plugin, since icons share one registry with every other plugin on the site, and joined with the `/` that registry expects. Both halves are read exactly as written, so `arrow-right` in a plugin slugged `acme-plugin` registers as `acme-plugin/arrow-right`.

An icon in a collection of its own is named for that collection instead, since the collection *is* the half before the slash.

<br>

### `get_collection_slug()`

The slug of the icon collection registered for this plugin.

```php
public function get_collection_slug(): string
```

|  | Details |
|---|---|
| **Parameters** | — |
| **Return** | `string` |
| **Throws** | — |

Your plugin slug, which WordPress accepts as a collection slug by construction. It is what groups your icons in the editor's picker, and it is registered only if you actually have an icon to put in it.

<br>

### `get( $name, $args, $collection )`

The markup for one of this plugin's icons.

```php
public function get( string $name, array $args = array(), ?string $collection = null ): string
```

|  | Details |
|---|---|
| **Parameters** | `$name` — The icon's local name<br>`$args` — `size`, `class` and `label`, as `wp_get_icon()` takes them<br>`$collection` — Which collection to read it from |
| **Return** | The SVG markup, or an empty string if there is no such icon |
| **Throws** | — |

Takes the local name and applies your namespace, so `get( 'arrow-right' )` renders `{plugin-slug}/arrow-right`. The markup comes back sanitized and ready to echo.

`size` is width and height in pixels, 24 by default; pass `null` to leave the SVG's own dimensions alone. `class` adds class names, and `label` is the text a screen reader announces — without one the icon is marked `aria-hidden`, which is right for an icon sitting beside its own label and wrong for one standing alone.

The collection is worked out for you: an icon that filed itself under one of your own is still reached by its local name. Name the collection yourself only to tell two icons of the same name apart.

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

Almost everything a module registers — a post type, a block, a WP-CLI command — has to happen on `init`, and a plain `add_action( 'init', ... )` is a callback that never runs once `init` has passed. A module can be resolved on either side of it: `Plugin::run()` is synchronous, so an entry file that calls it at plugin load is ahead of `init`, while one that calls it from a later hook — or a `get()` during a request — is behind. This behaves the same either way, so a module never has to care which.

The callback receives the module, matching the initializer signature, so a closure declared elsewhere needs no `use` to reach it:

```php
protected function on_boot(): void {
    $this->on_wp_init( function ( self $module ): void {
        $module->register_widgets();
    } );
}
```

`$priority` is WordPress's own, for ordering against something else on `init` — another plugin's registration, or a post type a taxonomy of yours attaches to. **It applies only when `init` is still ahead**, which is the case for the documented entry file, since `run()` at plugin load is well before `init`. A module resolved *after* `init` has fired runs its callback immediately, because there is no longer a queue to be ordered in — so two callbacks registered then run in the order they were registered, whatever priority each asked for. Ordering that has to hold in both cases belongs inside one callback.

## See also

- [`path`](../../services/path/) — copied in alongside this one
- [`Module`](../module.md) — what every module inherits
- [`wp zt add module icons-library`](../../commands/add-module.md) — the command that copies it
