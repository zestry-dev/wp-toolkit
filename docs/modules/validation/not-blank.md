<!--
    Generated from src/Modules/Validation/Rules/NotBlank.php.
    Do not edit by hand: run `composer docs` after changing the source.
-->

# NotBlank

A string with at least one character that is not a space.

```php
#[Property( 'Your name.' ), NotBlank, Length( max: 80 ), Sanitize( 'trim' )]
public string $name;
```

It judges the value as it was sent, like every other rule, so it is what keeps `Sanitize( 'trim' )` from turning three spaces into an empty name. Any kind of space counts as blank, the non-breaking one pasted from a document included.

It states no schema keyword, so it combines with `Length` and `Pattern` freely, and a caller learns of it from the refusal rather than the schema. `null` passes where the property's type allows it, and a value that is not a string is left to its type.

## Methods

### `check( $value )`

Refuse an empty string, or one made only of spaces.

```php
public function check( mixed $value ): ?string
```

|  | Details |
|---|---|
| **Parameters** | `$value` — The value, as it was sent |
| **Return** | Null when the value is fine, or the message saying what is wrong |
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
