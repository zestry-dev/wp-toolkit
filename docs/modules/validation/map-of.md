<!--
    Generated from src/Modules/Validation/MapOf.php.
    Do not edit by hand: run `composer docs` after changing the source.
-->

# MapOf

Says an `array` property is a map with keys of the caller's choosing, and what each value is.

```php
#[MapOf( StyleFile::class ), Keys( new Slug() )]
public array $styles = array();
```

For keys you know in advance, write a `Structure` instead: its properties can carry rules, and you get a typed object back. A map is for keys you do not.

A value takes the same types as a `ListOf` item. Rules for each value go in `Each`, rules for each key in `Keys`.

A list is refused where a map is declared, so `[ "a", "b" ]` is not read as `{ "0": "a", "1": "b" }`. An empty one is accepted either way, since `{}` and `[]` decode to the same empty array.

## Methods

### `__construct( $type )`

Declare the map.

```php
public function __construct( public readonly string $type )
```

|  | Details |
|---|---|
| **Parameters** | `$type` — What each value is: a class name, or `'string'`, `'int'`, `'float'`, `'bool'`, `'object'`, `'mixed'` |
| **Return** | — |
| **Throws** | — |
