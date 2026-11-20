<!--
    Generated from src/Modules/Validation/Validation.php.
    Do not edit by hand: run `composer docs` after changing the source.
-->

# Validation

Describe data with a class, then check it, clean it and build it.

A class whose properties are typed and declared is the whole description: the schema a caller reads, the checks a value must pass, and the object you get back. Nothing to extend and nothing to register:

```php
final class Booking {

    #[Property( 'The workshop to book.' )]
    public int $workshop_id;

    #[Email]
    public string $email;

    #[ListOf( Guest::class ), Count( max: 8 )]
    public array $guests = array();

    #[Property]
    public ?\DateTimeImmutable $arrives = null;
}

$booking = $validation->parse( Booking::class, $data );

if ( is_wp_error( $booking ) ) {
    return $booking; // every problem, keyed by where it is: guests[2].email
}

$booking->guests[0]->name;
```

A route, an ability and an AJAX action use the same declarations on their own properties, and check what arrives before your code runs — so most of the time you declare and never call this.

## Declaring a property

A property is part of the shape when it carries `Property`, any `Rule`, `ListOf` or `MapOf`. Its PHP type says what it holds:

| Type | Accepts | You get |
| --- | --- | --- |
| `string`, `int`, `float`, `bool` | that type, or a string that reads as it | the type |
| an enum | a case's backing value, or a pure enum's case name | the case |
| `\DateTimeImmutable`, `\DateTimeInterface` | a date-time string | the date |
| a class | an object matching its declarations | an instance, built without its constructor |
| `array` with `ListOf` | a list, or a comma-separated string | a list |
| `array` with `MapOf` | an object with keys of the caller's choosing | an array keyed by them |
| `object`, `\stdClass` | any object | a `\stdClass` |
| `UploadedFile` | an upload, on a REST route only | the file |

**No default makes it required.** A default makes it optional, and is what a caller is told leaving it out gets them; `?Type $x = null` is the way to say "optional, with no value".

**Types shape, rules narrow.** A rule never changes what kind of value a property holds; it only limits which values of that kind pass. Something with properties of its own — an address, a guest — is a class, not a rule.

## In what order

For each value: its type and the schema of its rules are checked, then each rule's own `check()`, then it is cast to its type, then each rule's `sanitize()` runs, in the order written. A class, a list or a map is checked from the inside out — every property, item and entry first, then the rules on the whole — and a level whose contents failed is not checked as a whole.

Every problem is reported, one per place, keyed by a path such as `guests[2].email` or `styles['main.css']`.

## Translating

An attribute takes only constant expressions, so `__()` cannot go in one. State what needs working out at runtime as a partial schema over the derived one — `$overrides` here, `args()` on a route, `input_schema()` on an ability:

```php
array(
    'properties' => array(
        'email' => array( 'description' => __( 'Where the receipt goes.', 'acme' ) ),
    ),
)
```

A keyed map is merged into, so naming one description leaves the type, the rules and the required-ness alone. A list — `required`, an `enum` — is replaced whole. See [`Arr::replace_recursive()`](../../kernel/arr.md). An empty schema — `properties` naming nothing, a property or `items` given as `array()` — changes nothing.

[Adding it](#adding-it) &nbsp;·&nbsp; [Changing the defaults](#changing-the-defaults) &nbsp;·&nbsp; [Related classes](#related-classes) &nbsp;·&nbsp; [Methods](#methods)

## Adding it

```bash
wp zt add validation
```

> [!IMPORTANT]
> **A module is built because `bootstrap.php` lists it.** `Validation` does nothing until something asks, so it goes at the top level — which `wp zt add` writes for you. Left out, the first `with( Validation::class )` throws rather than building it, which [`wp zt doctor`](../../commands/doctor.md) catches before a request does.

```php
// bootstrap.php
return array(
    Validation::class,
);
```

## Changing the defaults

`Validation` takes no configuration. The entry above is all it needs — reach it with `$this->with( Validation::class )` from any module or discovered file, or `$plugin->get( Validation::class )` from your entry file.

## Related classes

Shipped with this module, and written against directly:

- [`Count`](count.md) — attribute, how many items a list, or entries a map, may have
- [`Distinct`](distinct.md) — attribute, no item of a list may appear twice
- [`Each`](each.md) — attribute, rules for every item of a `ListOf`, or every value of a `MapOf`
- [`Email`](email.md) — attribute, an email address, cleaned with `sanitize_email()`
- [`HexColor`](hex-color.md) — attribute, a color written `#rgb` or `#rrggbb`
- [`Ip`](ip.md) — attribute, an IPv4 or IPv6 address
- [`Keys`](keys.md) — attribute, rules for every key of a `MapOf`
- [`Length`](length.md) — attribute, how many characters a string may have
- [`ListOf`](list-of.md) — attribute, says an `array` property is a list, and what each item is
- [`MapOf`](map-of.md) — attribute, says an `array` property is a map with keys of the caller's choosing, and what each value is
- [`NotBlank`](not-blank.md) — attribute, a string with at least one character that is not a space
- [`OneOf`](one-of.md) — attribute, the only values allowed
- [`Pattern`](pattern.md) — attribute, a regular expression a string must match
- [`Property`](property.md) — attribute, declares a property as part of its class's shape, and describes it
- [`Range`](range.md) — attribute, the values a number may take
- [`Rule`](rule.md) — attribute, a limit on a value, written once and used on any property
- [`Sanitize`](sanitize.md) — attribute, clean a value with a function, once it has passed every check
- [`Url`](url.md) — attribute, an absolute `http` or `https` address
- [`Uuid`](uuid.md) — attribute, a UUID, in its usual hyphenated form

## Methods

### `get_properties( $target )`

The properties a class declares, keyed by name.

```php
public function get_properties( object|string $target ): array
```

|  | Details |
|---|---|
| **Parameters** | `$target` — The object, or its class name |
| **Return** | `array` |
| **Throws** | `InvalidArgumentException` — When a declaration cannot be described |

Public and protected only: reflection cannot reliably reach a private property declared on an ancestor.

<br>

### `get_schema( $target, $overrides )`

The JSON Schema describing everything a class accepts.

```php
public function get_schema( object|string $target, array $overrides = array() ): array
```

|  | Details |
|---|---|
| **Parameters** | `$target` — The object, or its class name<br>`$overrides` — A partial schema stated over the derived one |
| **Return** | A schema, or an empty array when nothing is declared |
| **Throws** | `InvalidArgumentException` — When a declaration cannot be described |

`$overrides` is a partial schema stated over the derived one, for what an attribute cannot carry: a translated description, an `enum` worked out at runtime. Anything you state wins; everything you leave out keeps what the declarations gave it.

A file is left out: no schema can describe one.

<br>

### `validate( $class_name, $data, $overrides )`

Check data against a class, and name every problem.

```php
public function validate( string $class_name, mixed $data, array $overrides = array() ): array
```

|  | Details |
|---|---|
| **Parameters** | `$class_name` — The class describing the data<br>`$data` — The data, as it arrived<br>`$overrides` — A partial schema stated over the derived one |
| **Return** | Each problem's message, keyed by its path. Empty when the data passed |
| **Throws** | `InvalidArgumentException` — When a declaration cannot be described |

<br>

### `parse( $class_name, $data, $overrides )`

Check data against a class, and build an instance of it.

```php
public function parse( string $class_name, mixed $data, array $overrides = array() ): object
```

|  | Details |
|---|---|
| **Parameters** | `$class_name` — The class describing the data<br>`$data` — The data, as it arrived<br>`$overrides` — A partial schema stated over the derived one |
| **Return** | The instance, checked and cleaned, or the refusal |
| **Throws** | `InvalidArgumentException` — When a declaration cannot be described |

```php
$booking = $validation->parse( Booking::class, json_decode( $body, true ) );

if ( is_wp_error( $booking ) ) {
    wp_send_json_error( $booking->get_error_data()['errors'], 400 );
}
```

The refusal's message reads every problem as one sentence each, and its data carries them under `errors`, keyed by path, and the top-level names involved under `params`.

<br>

### `to_array( $value )`

Reduce an object to the data that would build it again.

```php
public function to_array( mixed $value ): mixed
```

|  | Details |
|---|---|
| **Parameters** | `$value` — The object, or any value |
| **Return** | `mixed` |
| **Throws** | `InvalidArgumentException` — When a declaration cannot be described |

An enum becomes its value, a date an RFC 3339 string, and an object of a declared class an array of its declared properties: what a caller would send, and what JSON can carry.

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

- [`Module`](../module.md) — what every module inherits
- [`wp zt add validation`](../../commands/add.md) — the command that copies it
