<!--
    Generated from src/Modules/Validation/Structure.php.
    Do not edit by hand: run `composer docs` after changing the source.
-->

# Structure

A value with properties of its own — a booking, an address, a guest — built from an array by `Validation`.

```php
final class Booking extends Structure {

    #[Property( 'Guest email.' ), Email]
    public string $email;

    #[Property( 'Anything we should know.' )]
    public ?string $note = null;
}
```

Type a property as one and a route, an ability or an AJAX action builds it from what was sent; `$validation->parse( Booking::class, $data )` builds one anywhere else. Either way you get an instance whose every property has been checked.

**That is the only way to get one.** The constructor is private, so `new Booking()` is an error, and a subclass cannot declare a constructor of its own. A check belongs in a rule on the class, a cleanup in a rule's `sanitize()`, and a value worked out from others in a method.
