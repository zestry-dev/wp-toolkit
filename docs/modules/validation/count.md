<!--
    Generated from src/Modules/Validation/Rules/Count.php.
    Do not edit by hand: run `composer docs` after changing the source.
-->

# Count

How many items a list, or entries a map, may have.

```php
#[ListOf( Guest::class ), Count( min: 1, max: 8 )]
public array $guests;
```

## Methods

### `__construct( $min, $max )`

Set the limits. Leave one out for no limit on that side.

```php
public function __construct( private readonly ?int $min = null, private readonly ?int $max = null )
```

|  | Details |
|---|---|
| **Parameters** | `$min` — The fewest allowed<br>`$max` — The most allowed |
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

On a map these are published as `minProperties` and `maxProperties`.

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
