<!--
    Generated from src/Modules/Fields/Fields.php.
    Do not edit by hand: run `composer docs` after changing the source.
-->

# Fields

Discovers `fields/` &nbsp;·&nbsp; Each file returns [`Field`](field.md) &nbsp;·&nbsp; Dependencies [`path`](../../services/path/)

Registers post meta from files, with types, sanitisers and permissions.

A file in `fields/` returns a `Field` naming the post types it attaches to — so a field on your own post type and a field on core's `post` are written the same way, in the same place.

Registering meta is what turns a bare `update_post_meta()` key into something typed, sanitised, permission-checked and visible to the block editor, which reads and writes meta over REST.

**A field holds one value per post.** For several, store an array: one row, which is the shape REST and the block editor expect. You never have to ask whether a key holds one value or many, because it always holds one.

That costs you one thing — querying. `meta_query` matches a single row's value, so a key spread over many rows can be searched by any one of them, while an array in one row can only be matched with `LIKE`, which is neither indexed nor reliable against serialised data. To find posts by one of several values, use a taxonomy: see `Taxonomy`, which is indexed, cached and built for exactly that.

## Reading and writing

`get()`, `has()`, `set()` and `delete()` work on **the fields this plugin registers**, and refuse anything else — a key no file declares, and a key whose file switched itself off. That refusal is the point: a mistyped key handed to `get_post_meta()` returns `''`, which looks exactly like a field nobody has filled in.

To list what a plugin declares rather than what it registers — a settings screen showing which features are available to switch on — use `get_all_fields()` or `get_fields_of()`, which include the switched-off ones.

They are not a general post-meta helper: reading meta needs to know whether the key holds one value or many, and only a registration says.

So unregistered meta goes through `get_post_meta()`, where you supply that knowledge yourself. WordPress's own classic keys are mostly unregistered and have better functions still — `get_post_thumbnail_id()` rather than `_thumbnail_id`, `get_page_template_slug()` rather than `_wp_page_template`.

Nothing here renders a form. A registered field is editable in the block editor through its REST exposure, and that is the path that needs no markup. To put one on a classic editor screen instead, add the `meta-boxes` module: a `MetaBox` names the keys its form submits, and each is read, unslashed and written back through this module, so the field's `validate()` and `sanitize()` still apply.

[Adding it](#adding-it) &nbsp;·&nbsp; [A field](#a-field) &nbsp;·&nbsp; [Changing the defaults](#changing-the-defaults) &nbsp;·&nbsp; [Writing a Field](#writing-a-field) &nbsp;·&nbsp; [Related classes](#related-classes) &nbsp;·&nbsp; [Constants](#constants) &nbsp;·&nbsp; [You must implement](#you-must-implement) &nbsp;·&nbsp; [Methods you can use](#methods-you-can-use) &nbsp;·&nbsp; [See also](#see-also)

## Adding it

```bash
wp zt add module fields
```

> [!IMPORTANT]
> **A module is built because `bootstrap.php` lists it.** `Fields` binds its hooks when the plugin builds it, so it has to be listed there — which `wp zt add` writes for you. Left out, nothing is discovered and nothing reports why; [`wp zt doctor`](../../commands/doctor.md) is what catches it.

```php
// bootstrap.php
return array(
    Fields::class,
);
```

## A field

```php
// fields/acme_rating.php -- the filename is the meta key
return new class extends Field {

    public function subtypes(): array {
        return array( 'book', 'post' );
    }

    public function type(): string {
        return 'integer';
    }
};
```

## Changing the defaults

Point it at a different directory

```php
Fields::class => static function ( Fields $fields ): void {
    $fields->set_fields_root( 'meta' );
},
```

## Writing a Field

A file in `fields/` returns a [`Field`](field.md) instance, which `wp zt make field <name>` generates.

## Related classes

Shipped with this module, and written against directly:

- [`MetaType`](meta-type.md) — enum, what kind of thing a field is stored against

## Constants

### `DEFAULT_FIELDS_ROOT`

```php
const DEFAULT_FIELDS_ROOT = 'fields';
```

Where fields are discovered, relative to the plugin root.

## You must implement

This one method is abstract: a subclass that does not declare it will not load.

### `on_boot()`

What this module does on its own.

```php
abstract protected function on_boot(): void
```

Runs once, when the plugin builds the module. Abstract rather than optional: a module with nothing to do here is a `Service`.

**Bind hooks here; do the work in them.** An entry file that calls `run()` as it loads — which is the documented shape, and what `ActivationHandler` requires — reaches this before WordPress has required `pluggable.php`, so there is no current user yet: `current_user_can()`, `wp_mail()` and the nonce functions are not defined and calling one is a fatal. It is also before `init`, so `__()` here asks for a text domain nothing has loaded. `$wpdb` *is* up, so a query works — but it runs on every request, including the ones that never needed it.

`on_wp_init()` is the way out of all three, and where anything a module registers belongs.

## Methods you can use

### `set_fields_root( $root )`

Read fields from a different directory.

```php
public function set_fields_root( string $root ): void
```

|  | Details |
|---|---|
| **Parameters** | `$root` — Directory relative to the plugin root |
| **Return** | — |
| **Throws** | — |

Call this before the module boots — from its `bootstrap.php` entry. Naming a directory that does not exist is an error and throws at boot, where leaving the default alone and having no such directory simply means you have no fields yet.

<br>

### `get_discovered_fields()`

Every discovered field, by object type and then by meta key.

```php
public function get_discovered_fields(): array
```

|  | Details |
|---|---|
| **Parameters** | — |
| **Return** | Object type => meta key => instance |
| **Throws** | `DiscoveryException` — When a directory named by set_fields_root() does not exist, or a file returns the wrong value |

Nested because a meta key is unique only within an object type. Use `get_fields_of()` when you know which type you want.

Everything the directory declares, including a field whose `is_enabled()` returns false — so a screen offering to switch features on can list the ones currently switched off. Only `register_fields()` acts on the answer, and the value accessors refuse a key belonging to a field that is switched off.

<br>

### `get_key_of( $field )`

This field's meta key, taken from the file it was discovered in.

```php
public function get_key_of( Field $field ): string
```

|  | Details |
|---|---|
| **Parameters** | `$field` — The instance to look up |
| **Return** | `string` |
| **Throws** | `InvalidArgumentException` — When the instance was not discovered by this module |

The same reverse lookup `PostTypes::get_post_type_of()` does, so a field named by its filename never repeats that name inside the file.

<br>

### `get( $object_id, $key, $fallback, $type )`

Read a field's value from a post.

```php
public function get( int $object_id, string $key, mixed $fallback = null, MetaType $type = MetaType::Post ): mixed
```

|  | Details |
|---|---|
| **Parameters** | `$object_id` — The object to read from<br>`$key` — A meta key one of your fields declares<br>`$fallback` — Returned when the post has no value stored<br>`$type` — Which meta table the key lives in. Post meta by default |
| **Return** | The stored value, or `$fallback` |
| **Throws** | `InvalidArgumentException` — When no field declares that key |

Two things this does that `get_post_meta()` cannot. It always reads a single value, because a field here always holds one — with the bare function, forgetting its `$single` argument hands back an array where you expected a value. And it refuses a key no field declares, rather than returning `''` for a typo the way the bare function does.

The post comes first, as it does in `get_post_meta()`: the other stores in this toolkit take no container because they have one, and this one does.

<br>

### `has( $object_id, $key, $type )`

Whether a post has a value stored for a field.

```php
public function has( int $object_id, string $key, MetaType $type = MetaType::Post ): bool
```

|  | Details |
|---|---|
| **Parameters** | `$object_id` — The object to check<br>`$key` — A meta key one of your fields declares<br>`$type` — Which meta table the key lives in. Post meta by default |
| **Return** | `bool` |
| **Throws** | `InvalidArgumentException` — When no field declares that key |

Distinct from `null !== get()`, which cannot tell a stored null from a post that has never had the field set.

<br>

### `set( $object_id, $key, $value, $type )`

Write a field's value to a post.

```php
public function set( int $object_id, string $key, mixed $value, MetaType $type = MetaType::Post ): bool|\WP_Error
```

|  | Details |
|---|---|
| **Parameters** | `$object_id` — The object to write to<br>`$key` — A meta key one of your fields declares<br>`$value` — The value to store<br>`$type` — Which meta table the key lives in. Post meta by default |
| **Return** | True once written, a `WP_Error` when the field refused the value, false when nothing was written for any other reason |
| **Throws** | `InvalidArgumentException` — When no field declares that key |

The field's `sanitize()` shapes the value and its `validate()` may then refuse it — WordPress's order for meta, applied from inside the write rather than here, so `update_post_meta()` behaves identically.

**Returns a `WP_Error` when the field refuses the value**, which is the one place this differs from `Options`, `Globals` and `Transients`: those take anything, and a field is held to its own schema. Check the return with `is_wp_error()` when the value came from a request — the message names the key and what was wrong with it, so a form has something to show.

A plain `false` means the write did not happen for a reason that is not a refusal: storing the value it already had is the usual one.

<br>

### `delete( $object_id, $key, $type )`

Remove a field's value from a post.

```php
public function delete( int $object_id, string $key, MetaType $type = MetaType::Post ): void
```

|  | Details |
|---|---|
| **Parameters** | `$object_id` — The object to remove it from<br>`$key` — A meta key one of your fields declares<br>`$type` — Which meta table the key lives in. Post meta by default |
| **Return** | — |
| **Throws** | `InvalidArgumentException` — When no field declares that key |

Removing something that was never there is not an error.

<br>

### `get_all_fields()`

Every declared field, flattened, for iterating over all of them.

```php
public function get_all_fields(): array
```

|  | Details |
|---|---|
| **Parameters** | — |
| **Return** | Meta key => instance. A key shared by two object types appears once; use `get_fields_of()` to tell them apart |
| **Throws** | `DiscoveryException` — When discovery fails |

Includes the switched-off ones — this is enumeration, and that is what it is for. Ask an instance's `is_enabled()` to tell them apart.

<br>

### `get_fields_of( $type )`

Every field of one object type, by meta key.

```php
public function get_fields_of( MetaType $type ): array
```

|  | Details |
|---|---|
| **Parameters** | `$type` — The object type |
| **Return** | `array` |
| **Throws** | `DiscoveryException` — When discovery fails |

Includes the switched-off ones, on the same terms as `get_all_fields()`.

<br>

### `get_field( $key, $type )`

The field declaring a key, within an object type.

```php
public function get_field( string $key, MetaType $type = MetaType::Post ): Field
```

|  | Details |
|---|---|
| **Parameters** | `$key` — The meta key<br>`$type` — The object type it belongs to. Post meta by default |
| **Return** | `Field` |
| **Throws** | `InvalidArgumentException` — When no field of that type declares that key, or the field that does is switched off |

A field that switched itself off is refused too, with its own message: its meta was never registered, so reading it would hand back `''` and writing it would store a value nothing knows the shape of — the two failures this method exists to prevent. Enumerate with `get_fields_of()` when you want everything declared.

<br>

### `on_wp_init( $callback, $priority )`

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

- [`Field`](field.md) — what a file in `fields/` returns
- [`path`](../../services/path/) — copied in alongside this one
- [`Module`](../module.md) — what every module inherits
- [`wp zt add module fields`](../../commands/add-module.md) — the command that copies it
