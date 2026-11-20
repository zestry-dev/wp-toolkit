<!--
    Generated from src/Modules/Validation/Each.php.
    Do not edit by hand: run `composer docs` after changing the source.
-->

# Each

Rules for every item of a `ListOf`, or every value of a `MapOf`.

```php
#[ListOf( 'int' ), Count( min: 1 ), Each( new Range( 100, 900, step: 100 ) )]
public array $weights;
```

`Count` applies to the list; `Range` to each number in it. A problem is reported at the item, `$.weights[2]`.

## Methods

### `__construct( $rules )`

Declare the rules.

```php
public function __construct( Rule ...$rules )
```
