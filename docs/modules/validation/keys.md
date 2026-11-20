<!--
    Generated from src/Modules/Validation/Keys.php.
    Do not edit by hand: run `composer docs` after changing the source.
-->

# Keys

Rules for every key of a `MapOf`.

```php
#[MapOf( 'string' ), Keys( new Slug() )]
public array $remap = array();
```

A key is always a string by the time a rule sees it, even one PHP stored as a number. Its `pattern` is published through `patternProperties`, which WordPress enforces; anything else a key rule says is checked here.

## Methods

### `__construct( $rules )`

Declare the rules.

```php
public function __construct( Rule ...$rules )
```
