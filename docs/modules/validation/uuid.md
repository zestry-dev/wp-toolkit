<!--
    Generated from src/Modules/Validation/Rules/Uuid.php.
    Do not edit by hand: run `composer docs` after changing the source.
-->

# Uuid

A UUID, in its usual hyphenated form.

```php
#[Uuid]
public string $token;

#[Uuid( version: 7 )]
public string $event_id;
```

Any version by default; name one to accept only that version. Upper and lower case are both accepted, and the value reaches your code in lower case, so two spellings of one UUID compare equal.

## Methods

### `__construct( $version )`

Set the version.

```php
public function __construct( private readonly ?int $version = null )
```

|  | Details |
|---|---|
| **Parameters** | `$version` — The only version accepted, 1 to 8. Leave it out for any |
| **Return** | — |
| **Throws** | `InvalidArgumentException` — When the version is not one a UUID can have |

<br>

### `get_schema()`

The schema keywords the value must meet.

```php
public function get_schema(): array
```

|  | Details |
|---|---|
| **Parameters** | — |
| **Return** | `array` |
| **Throws** | — |

<br>

### `sanitize( $value )`

Lower-case the value, so one UUID has one spelling.

```php
public function sanitize( mixed $value ): mixed
```

|  | Details |
|---|---|
| **Parameters** | `$value` — The value, already checked |
| **Return** | `mixed` |
| **Throws** | — |

<br>

### `check( $value )`

Whether the value is fine.

```php
public function check( mixed $value ): ?string
```

|  | Details |
|---|---|
| **Parameters** | `$value` — The value to check |
| **Return** | Null when the value is fine, or the message saying what is wrong |
| **Throws** | — |

For a value on a property, `$value` is what was sent, once it has passed the schema. For an object, a list or a map, it is what was built from it.
