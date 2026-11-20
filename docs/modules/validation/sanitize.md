<!--
    Generated from src/Modules/Validation/Rules/Sanitize.php.
    Do not edit by hand: run `composer docs` after changing the source.
-->

# Sanitize

Clean a value with a function, once it has passed every check.

```php
#[Sanitize( 'sanitize_title' )]
public string $slug;

#[Sanitize( 'sanitize_textarea_field' )]
public string $note = '';
```

It refuses nothing. Several run in the order they are written.

Every check sees the value as it was sent, and cleaning comes after them. So a sanitizer has to leave a valid value valid: with `Length( min: 1 ), Sanitize( 'trim' )`, a name of three spaces passes and then becomes empty. Say what you mean about what was sent instead: `NotBlank` refuses a value that is only spaces.

```php
#[NotBlank, Sanitize( 'trim' )]
public string $name;
```

## Methods

### `__construct( $callback )`

Set the function.

```php
public function __construct( mixed $callback )
```

|  | Details |
|---|---|
| **Parameters** | `$callback` — Called with the value; returns the cleaned value. A function name or `array( self::class, 'method' )` |
| **Return** | — |
| **Throws** | — |

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
