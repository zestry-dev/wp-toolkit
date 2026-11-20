<!--
    Generated from src/Modules/Validation/Rules/Range.php.
    Do not edit by hand: run `composer docs` after changing the source.
-->

# Range

The values a number may take.

```php
#[Range( 1, 10 )]
public int $seats = 1;

#[Range( 100, 900, step: 100 )]
public int $weight = 400;
```

Both ends are included. `step` asks for a multiple of it, counted from zero rather than from `min`.

Prefer whole numbers for the limits. A fraction is enforced exactly, but WordPress writes the limit into its message as a whole number, so `Range( min: 0.5 )` refuses `0.2` with "must be greater than or equal to 0".

## Methods

### `__construct( $min, $max, $step )`

Set the limits. Leave one out for no limit on that side.

```php
public function __construct( private readonly int|float|null $min = null, private readonly int|float|null $max = null, private readonly int|float|null $step = null )
```

|  | Details |
|---|---|
| **Parameters** | `$min` — The smallest value allowed<br>`$max` — The largest value allowed<br>`$step` — A number the value must be a multiple of |
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
