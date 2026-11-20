<!--
    Generated from src/Modules/Validation/Rules/Pattern.php.
    Do not edit by hand: run `composer docs` after changing the source.
-->

# Pattern

A regular expression a string must match.

```php
#[Pattern( '^[A-Z]{2}$' )]
public string $country;
```

Written without delimiters, the way JSON Schema writes one, since it is published as it is. It matches anywhere in the string unless you anchor it with `^` and `$`: `Pattern( '[a-z]+' )` accepts `ABCa`, since `a` is in there. Nothing anchors it for you, because a caller reads the same pattern and has to reach the same answer.

## Methods

### `__construct( $pattern )`

Set the expression.

```php
public function __construct( private readonly string $pattern )
```

|  | Details |
|---|---|
| **Parameters** | `$pattern` — The expression, without delimiters |
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
