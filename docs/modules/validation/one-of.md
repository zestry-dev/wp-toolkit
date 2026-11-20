<!--
    Generated from src/Modules/Validation/Rules/OneOf.php.
    Do not edit by hand: run `composer docs` after changing the source.
-->

# OneOf

The only values allowed.

```php
#[OneOf( 'web', 'phone', 'in-person' )]
public string $channel = 'web';
```

For a set your code also switches on, type the property as an enum instead: you get the case back rather than a string.

## Methods

### `__construct( $values )`

Set the values.

```php
public function __construct( mixed ...$values )
```

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
