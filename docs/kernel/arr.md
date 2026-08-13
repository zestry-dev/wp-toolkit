<!--
    Generated from src/Kernel/Helpers/Arr.php.
    Do not edit by hand: run `composer docs` after changing the source.
-->

# Arr

[Reading a nested value](#reading-a-nested-value) &nbsp;·&nbsp; [Shaping a list](#shaping-a-list) &nbsp;·&nbsp; [Methods](#methods)

The array operations you would otherwise write out at every use.

Reaching into a nested array without four `isset()`s, taking only the keys you accept, plucking one field off a list of rows. If you have used Laravel's `Arr`, these are the same names doing the same things.

Static, and not a `Service`, because there is nothing here to configure or inject: every method is a pure function of its arguments.

## Reading a nested value

The path is the point. Each of these is one line instead of a chain of `isset()` calls, and each returns the fallback rather than a warning when a step along the way is missing.

```php
use Acme\Plugin\Core\Kernel\Helpers\Arr;

$city = Arr::get( $order, 'billing.address.city', '' );
$rate = Arr::get( $settings, array( 'tax', 'rates', 0 ), 0.0 );

if ( Arr::has( $request, 'meta.consent' ) ) {
    // Present, even when its value is null -- which `get() !== null` cannot tell you.
}

Arr::set( $settings, 'mail.from.name', 'Acme' );
```

A key with a dot in it still works, because the whole path is tried as a literal key before it is split: `get( $data, 'acme.version' )` finds a top-level `'acme.version'` if that is what you have.

## Shaping a list

```php
$emails = Arr::pluck( $orders, 'billing.email' );
$by_id  = Arr::pluck( $orders, 'total', 'id' );      // keyed

$safe   = Arr::only( $_POST, array( 'name', 'email' ) );
$rest   = Arr::except( $attributes, array( 'className' ) );

// A value that may arrive as one thing or several, which WordPress does often.
foreach ( Arr::wrap( $post_types ) as $post_type ) { ... }
```

`pluck()` takes a dotted path where Laravel's takes a plain key, so it reads a nested field without a second call.

## Methods

### `get( $data, $path, $fallback )`

Read a value from a nested array by path.

```php
public static function get( array $data, string|array $path, mixed $fallback = null ): mixed
```

|  | Details |
|---|---|
| **Parameters** | `$data` — The array to read from<br>`$path` — A dotted path, or its segments<br>`$fallback` — Returned when the path does not resolve |
| **Return** | The value found, or `$fallback` |
| **Throws** | — |

<br>

### `has( $data, $path )`

Whether a path resolves, however the value at the end of it reads.

```php
public static function has( array $data, string|array $path ): bool
```

|  | Details |
|---|---|
| **Parameters** | `$data` — The array to look in<br>`$path` — A dotted path, or its segments |
| **Return** | `bool` |
| **Throws** | — |

Distinct from `null !== get( ... )`: a key that is present and holds `null` answers true here and false there. That difference is the reason this exists — an unchecked box and an absent field are not the same thing, and only one of them means the form never had that field.

<br>

### `set( $data, $path, $value )`

Write a value into a nested array by path, creating what is missing.

```php
public static function set( array &$data, string|array $path, mixed $value ): void
```

|  | Details |
|---|---|
| **Parameters** | `$data` — The array to write into, by reference<br>`$path` — A dotted path, or its segments<br>`$value` — What to store at the end of it |
| **Return** | — |
| **Throws** | — |

Takes the array by reference and returns nothing, so the call reads as the statement it is. A step that does not exist is created; a step that exists and is not an array is replaced, because the path you asked for is the one you get.

<br>

### `forget( $data, $path )`

Remove a value from a nested array by path.

```php
public static function forget( array &$data, string|array $path ): void
```

|  | Details |
|---|---|
| **Parameters** | `$data` — The array to remove from, by reference<br>`$path` — A dotted path, or its segments |
| **Return** | — |
| **Throws** | — |

A path that does not resolve is not an error: the array is already in the state you asked for.

<br>

### `only( $data, $keys )`

Keep only the named keys.

```php
public static function only( array $data, array $keys ): array
```

|  | Details |
|---|---|
| **Parameters** | `$data` — The array to filter<br>`$keys` — The keys to keep |
| **Return** | `array` |
| **Throws** | — |

The shape to reach for before writing request input anywhere: it states what you accept, rather than removing what you happened to think of.

<br>

### `except( $data, $keys )`

Drop the named keys, keeping everything else.

```php
public static function except( array $data, array $keys ): array
```

|  | Details |
|---|---|
| **Parameters** | `$data` — The array to filter<br>`$keys` — The keys to remove |
| **Return** | `array` |
| **Throws** | — |

<br>

### `pluck( $rows, $value, $key )`

Collect one value out of every row.

```php
public static function pluck( array $rows, string|array $value, string|array|null $key = null ): array
```

|  | Details |
|---|---|
| **Parameters** | `$rows` — The rows to read<br>`$value` — Path to the value wanted<br>`$key` — Path to key the result by, if any |
| **Return** | `array` |
| **Throws** | — |

Both arguments take a path, so a nested value needs no loop of its own. Give `$key` to key the result by another of the row's values.

```php
Arr::pluck( $orders, 'billing.email' );
Arr::pluck( $orders, 'total', 'id' );
```

<br>

### `first( $data, $matches, $fallback )`

The first value passing the test, or `$fallback` when none does.

```php
public static function first( array $data, ?callable $matches = null, mixed $fallback = null ): mixed
```

|  | Details |
|---|---|
| **Parameters** | `$data` — The array to search<br>`$matches` — The test, or null for the first of anything<br>`$fallback` — Returned when nothing matches |
| **Return** | `mixed` |
| **Throws** | — |

Without a callback, simply the first value — which for a keyed array is not something `$data[0]` can tell you.

<br>

### `last( $data, $matches, $fallback )`

The last value, optionally the last one matching a test.

```php
public static function last( array $data, ?callable $matches = null, mixed $fallback = null ): mixed
```

|  | Details |
|---|---|
| **Parameters** | `$data` — The array to read<br>`$matches` — Optional test; the last value passing it wins<br>`$fallback` — Returned when nothing matches |
| **Return** | `mixed` |
| **Throws** | — |

The mirror of `first()`, and reached for the same way: the end of a list of revisions, the most recent row.

<br>

### `is_assoc( $data )`

Whether this array is keyed by name rather than numbered.

```php
public static function is_assoc( array $data ): bool
```

|  | Details |
|---|---|
| **Parameters** | `$data` — The array to test |
| **Return** | `bool` |
| **Throws** | — |

The question worth asking before deciding how to walk something WordPress handed you: a numbered list is looped, a keyed array is read by name.

"Numbered" is PHP's `array_is_list()` — the keys `0, 1, 2…` in that order and nothing else — so anything a positional read would get wrong is associative here: keys with gaps in them, keys out of order, and the case that catches people out, **a map keyed by id**. PHP casts a numeric string key to an integer on the way in, so by the time you see `array( '1' => 'a', '7' => 'b' )` every key is an integer, and it is still something you read by name rather than loop by position.

WordPress's own `wp_is_numeric_array()` asks something narrower — whether *any* key is a string — and so calls that same id-keyed map numeric. Reach for it directly when a string key is genuinely what you are asking about.

An empty array is a list, and so is not associative.

<br>

### `replace_recursive( $data, $replacements )`

Replace values into a nested array, descending only into keyed maps.

```php
public static function replace_recursive( array $data, array $replacements ): array
```

|  | Details |
|---|---|
| **Parameters** | `$data` — The array to replace into<br>`$replacements` — The values to state over it |
| **Return** | `array` |
| **Throws** | — |

The merge for anything shaped like configuration: state the one value you are changing, at the depth it lives at, and everything beside it is left exactly as it was.

```php
$settings = Arr::replace_recursive(
    array(
        'mail'  => array( 'from' => array( 'name' => 'Acme', 'email' => 'no-reply@acme.test' ) ),
        'roles' => array( 'editor', 'author' ),
    ),
    array(
        'mail'  => array( 'from' => array( 'name' => 'Acme Support' ) ),
        'roles' => array( 'editor' ),
    )
);

// The `email` beside the renamed `name` survives, and `roles` is exactly
// array( 'editor' ).
```

PHP's own `array_replace_recursive()` is the same idea with one difference: it descends into **lists** as well, and replaces them by position. `array( 'editor' )` over `array( 'editor', 'author' )` leaves both there, because nothing replaced index 1 — so a value you meant to drop is still in the array, with nothing said about it. Here a list is a value, taken as written.

"Keyed map" is `is_assoc()`, so a map keyed by id is descended into as readily as one keyed by name, and an empty array is a list — replacing with one empties that key rather than merging into nothing.

Both sides have to be maps for either to be descended into, which is what keeps a real list safe from a replacement with holes in its keys: `array_filter()` leaves gaps, and its result stated over `array( 'a', 'b' )` is still taken whole.

<br>

### `wrap( $value )`

Wrap a value in an array unless it already is one.

```php
public static function wrap( mixed $value ): array
```

|  | Details |
|---|---|
| **Parameters** | `$value` — Anything |
| **Return** | `array` |
| **Throws** | — |

WordPress hands you one thing or several with the same argument name all over its API, and `null` means none rather than one null.

<br>

### `flatten( $data, $depth )`

Flatten nested arrays into one level.

```php
public static function flatten( array $data, int $depth = PHP_INT_MAX ): array
```

|  | Details |
|---|---|
| **Parameters** | `$data` — The array to flatten<br>`$depth` — How many levels to descend; default all of them |
| **Return** | `array` |
| **Throws** | — |
