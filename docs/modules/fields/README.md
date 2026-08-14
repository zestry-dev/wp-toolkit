<!--
    Generated from src/Modules/Fields/Fields.php.
    Do not edit by hand: run `composer docs` after changing the source.
-->

# Fields

Discovers `resources/fields/` &nbsp;·&nbsp; Each file returns [`Field`](field.md) &nbsp;·&nbsp; Dependencies [`path`](../path/)

Registers post meta from files, with types, sanitisers and permissions.

A file in `resources/fields/` returns a `Field` naming the post types it attaches to — so a field on your own post type and a field on core's `post` are written the same way, in the same place.

Registering meta is what turns a bare `update_post_meta()` key into something typed, sanitised, permission-checked and visible to the block editor, which reads and writes meta over REST.

**A field holds one value per post.** For several, store an array: one row, which is the shape REST and the block editor expect. You never have to ask whether a key holds one value or many, because it always holds one.

That costs you one thing — querying. `meta_query` matches a single row's value, so a key spread over many rows can be searched by any one of them, while an array in one row can only be matched with `LIKE`, which is neither indexed nor reliable against serialised data. To find posts by one of several values, use a taxonomy: see `Taxonomy`, which is indexed, cached and built for exactly that.

## Reading and writing

`get()`, `has()`, `set()` and `delete()` work on **the fields this plugin registers**, and refuse anything else — a key no file declares, and a key whose file switched itself off. That refusal is the point: a mistyped key handed to `get_post_meta()` returns `''`, which looks exactly like a field nobody has filled in.

To list what a plugin declares rather than what it registers — a settings screen showing which features are available to switch on — use `get_all_fields()` or `get_fields_of()`, which include the switched-off ones.

They are not a general post-meta helper: reading meta needs to know whether the key holds one value or many, and only a registration says.

So unregistered meta goes through `get_post_meta()`, where you supply that knowledge yourself. WordPress's own classic keys are mostly unregistered and have better functions still — `get_post_thumbnail_id()` rather than `_thumbnail_id`, `get_page_template_slug()` rather than `_wp_page_template`.

Nothing here renders a form. A registered field is editable in the block editor through its REST exposure, and that is the path that needs no markup. To put one on a classic editor screen instead, add the `meta-boxes` module: a `MetaBox` names the keys its form submits, and each is read, unslashed and written back through this module, so the field's `validate()` and `sanitize()` still apply.

[Adding it](#adding-it) &nbsp;·&nbsp; [A field](#a-field) &nbsp;·&nbsp; [Changing the defaults](#changing-the-defaults) &nbsp;·&nbsp; [Writing a Field](#writing-a-field) &nbsp;·&nbsp; [Related classes](#related-classes) &nbsp;·&nbsp; [Constants](#constants) &nbsp;·&nbsp; [Methods](#methods) &nbsp;·&nbsp; [See also](#see-also)

## Adding it

```bash
wp zt add fields
```

> [!IMPORTANT]
> **A module is built because `bootstrap.php` lists it, and the heading says when.** `Fields` acts the moment it is built, so it goes under the hook it acts on — which `wp zt add` writes for you. Left at the top level it throws; left out entirely, nothing is discovered and nothing reports why, which is what [`wp zt doctor`](../../commands/doctor.md) catches.

```php
// bootstrap.php
return array(
    'init' => array(
        Fields::class,
    ),
);
```

## A field

```php
// resources/fields/acme_rating.php -- the filename is the meta key
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

`Fields` takes no configuration. The entry above is all it needs — reach it with `$this->with( Fields::class )` from any module or discovered file, or `$plugin->get( Fields::class )` from your entry file.

## Writing a Field

A file in `resources/fields/` returns a [`Field`](field.md) instance, which `wp zt make field <name>` generates.

## Related classes

Shipped with this module, and written against directly:

- [`MetaType`](meta-type.md) — enum, what kind of thing a field is stored against

## Constants

### `FIELDS_ROOT`

```php
const FIELDS_ROOT = 'resources/fields';
```

Where fields are discovered, relative to the plugin root.

## Methods

### `get_discovered_fields()`

Every discovered field, by object type and then by meta key.

```php
public function get_discovered_fields(): array
```

|  | Details |
|---|---|
| **Parameters** | — |
| **Return** | Object type => meta key => instance |
| **Throws** | `DiscoveryException` — When a file returns the wrong value |

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

- [`Field`](field.md) — what a file in `resources/fields/` returns
- [`path`](../path/) — copied in alongside this one
- [`Module`](../module.md) — what every module inherits
- [`wp zt add fields`](../../commands/add.md) — the command that copies it
