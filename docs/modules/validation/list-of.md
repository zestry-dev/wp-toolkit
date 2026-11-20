<!--
    Generated from src/Modules/Validation/ListOf.php.
    Do not edit by hand: run `composer docs` after changing the source.
-->

# ListOf

Says an `array` property is a list, and what each item is.

PHP has no typed arrays, so this says what `list<Guest>` would:

```php
#[ListOf( Guest::class )]
public array $guests = array();

#[ListOf( 'string' ), Each( new Slug() )]
public array $tags = array();
```

An item is a class, an enum, a date, or one of `'string'`, `'int'`, `'float'`, `'bool'`, `'object'` (keys of the caller's choosing) and `'mixed'` (anything at all). Rules for each item go in `Each`; rules on the property itself, such as `Count`, apply to the list.

You always get a list back, indexed from 0, even when the caller sent gaps or `?tags=a,b` in a query string.

## Methods

### `__construct( $type )`

Declare the list.

```php
public function __construct( public readonly string $type )
```

|  | Details |
|---|---|
| **Parameters** | `$type` — What each item is: a class name, or `'string'`, `'int'`, `'float'`, `'bool'`, `'object'`, `'mixed'` |
| **Return** | — |
| **Throws** | — |
