<!--
    Generated from src/Modules/Validation/Rules/Email.php.
    Do not edit by hand: run `composer docs` after changing the source.
-->

# Email

An email address, cleaned with `sanitize_email()`.

```php
#[Email]
public string $email;
```

## Methods

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

Clean the value once it has passed.

```php
public function sanitize( mixed $value ): mixed
```

|  | Details |
|---|---|
| **Parameters** | `$value` — The value to clean |
| **Return** | The cleaned value |
| **Throws** | — |

<br>

### `__construct( $schema, $check, $sanitize )`

State a rule inline.

```php
public function __construct( array $schema = array(), mixed $check = null, mixed $sanitize = null )
```

|  | Details |
|---|---|
| **Parameters** | `$schema` — Schema keywords the value must meet<br>`$check` — Called with the value. Returns `null` when it is fine, or the message. A function name or `array( self::class, 'method' )`<br>`$sanitize` — Called with the value once it passed. Returns the cleaned value |
| **Return** | — |
| **Throws** | — |

A rule class of your own does not need to call this: every half defaults to doing nothing.

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
