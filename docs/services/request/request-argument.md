<!--
    Generated from src/Services/Request/Attributes/RequestArgument.php.
    Do not edit by hand: run `composer docs` after changing the source.
-->

# RequestArgument

Declares a property as one of the arguments its class accepts.

One attribute for both callable surfaces this toolkit registers — a `RestRoute` and an `Ability` — because they ask the same question. Each is a named operation, described to a caller who cannot read your code, validated before it runs. Declare the argument once and the `Request` service builds the route's `args` or the ability's input schema from it, and binds the value onto the property before your handler runs.

```php
use Acme\Plugin\Core\Services\Request\Attributes\RequestArgument;

#[RequestArgument( 'The order to cancel.' )]
public int $order_id;

#[RequestArgument( 'Whether to email the customer.' )]
public bool $notify = true;
```

So a handler reads `$this->order_id`, not `$request->get_param( 'order_id' )` or `$input['order_id']`.

**The property states both the type and whether it is required**, since PHP already made you declare both. `int` is `integer`, `float` is `number`, `bool` is `boolean`, and `?int` accepts null as well. An argument with no default is required — leaving it optional would mean a handler reading an uninitialized property, which is a PHP error rather than a missing value — and one with a default is optional, with that default published so a caller knows what leaving the key out gets them.

## Dates

A moment in time is a string on the wire, and WordPress already knows how to check one:

```php
#[RequestArgument( 'When the sale ends.' )]
public \DateTimeImmutable $ends_at;      // type: string, format: date-time
```

`format: date-time` is enforced by `rest_parse_date()` before anything binds, so `handle()` gets a real date or the call never reaches it. Typing the interface itself gives you a `DateTimeImmutable`, since a value that cannot change underneath its holder is the safer of the two.

What a caller has to send is a full date and time: `2026-08-04T12:00:00Z`, an offset like `+10:00`, or `2026-08-04 12:00:00`. A bare `2026-08-04` is refused, and so is anything WordPress cannot read as a moment.

**A time with no offset is read as UTC**, because WordPress runs PHP in UTC — `2026-08-04 12:00:00` is noon UTC, whatever the site's timezone is set to. Converting for display is one call:

```php
$this->send_at->setTimezone( wp_timezone() );
```

For a day rather than a moment — a birthday, a due date — say so with a pattern, since `date-time` has no date-only form and a `DateTimeImmutable` would invent a midnight that nobody sent:

```php
#[RequestArgument( 'The day it is due.', schema: array( 'pattern' => '^\d{4}-\d{2}-\d{2}$' ) )]
public string $due_on;
```

## Enums

A property typed as an enum is a closed set, and the schema says so — the values are listed, so WordPress rejects anything else before your code runs and a caller can read what it may send:

```php
enum Status: string {
    case Draft = 'draft';
    case Live  = 'live';
}

#[RequestArgument( 'What state to put it in.' )]
public Status $status;            // sends "draft", arrives as Status::Draft
```

A backed enum sends its backing value, which is the case for backing one: the wire value is written down rather than inferred, so renaming a case does not change what callers must send. A pure enum has none, so its case names stand in — the schema lists them either way, so a caller never has to know which kind it is looking at.

This is the better way to write `schema: array( 'enum' => ... )`: the set lives in one place, and the handler reads a case rather than a string.

## Structures

A property typed as a class becomes a nested object, built from that class's own arguments, and arrives as an instance rather than an array:

```php
final class Address {

    #[RequestArgument( 'Street and number.' )]
    public string $line1;

    #[RequestArgument( 'Two-letter country code.', schema: array( 'pattern' => '^[A-Z]{2}$' ) )]
    public string $country = 'US';
}

#[RequestArgument( 'Where to ship it.' )]
public Address $address;          // $this->address->line1
```

PHP has no array-of-type syntax — `LineItem[]` is docblock notation, not a type — so a list names its class with `of`:

```php
#[RequestArgument( 'What was ordered.', of: LineItem::class )]
public array $items;
```

An array of plain values says so through the schema instead:

```php
#[RequestArgument( 'The orders to cancel.', schema: array( 'items' => array( 'type' => 'integer' ) ) )]
public array $order_ids;
```

One or the other is required. An array whose contents go undescribed is the one hole a caller cannot read its way out of, so it throws rather than publishing a shape that says nothing.

A class used this way needs nothing but public typed properties carrying this attribute — no base class, no constructor. It is built without calling one, so property defaults apply and required constructor arguments are not a problem.

## Files

A property typed as an `UploadedFile` takes an upload, and `of: UploadedFile::class` on an `array` takes several:

```php
#[RequestArgument( 'The image to attach.' )]
public UploadedFile $image;
```

**Only a route can take one.** An upload arrives as `multipart/form-data`, which JSON Schema has no type for, so WordPress keeps uploads out of a request's parameters entirely — an ability, whose input is JSON, is refused one at registration. A file is therefore the one argument no schema checks: ask `UploadedFile::is_ok()` before reading it.

## Open objects

`stdClass` and `object` both mean an object whose keys are the caller's business — a settings blob, a payload passed through to somewhere else:

```php
#[RequestArgument( 'Whatever your client keeps here.' )]
public \stdClass $meta;          // $this->meta->colour
```

The schema is `type: object` with no fixed keys, and `schema:` can still describe the parts you do know. A named class is the better choice wherever the shape is known: a caller can read a structure and cannot read an open object.

## Say it in the schema where you can

Anything JSON Schema expresses goes in `schema` — `enum` for a closed set, `minimum`, `format`, `pattern`:

```php
#[RequestArgument( 'How to sort.', schema: array( 'enum' => array( 'date', 'title' ) ) )]
public string $order_by = 'date';
```

**Prefer this to the callbacks.** WordPress enforces the schema either way, and a rule stated there is one a caller can read and satisfy before calling; the same rule in a callback is one it can only discover by getting it wrong. For an AI agent choosing what to send, that is the difference between a contract and a guessing game.

`validate` and `sanitize` are for what JSON Schema cannot say — that an id exists, that a date has not passed, that a slug is free.

Only public and protected properties are read — the same rule `WithPlugin::inject_modules()` uses, for the same reason: reflection cannot reliably reach a private property declared on an ancestor. A private one marked with this attribute is left alone entirely, appearing in no schema and never bound.

## Methods

### `__construct( $description, $validate, $sanitize, $of, $schema )`

Validation runs first and sanitising second, which is the order WordPress dispatches a route's own callbacks in (`has_valid_params()` then `sanitize_params()`) and the safe one: you decide about the value that was actually sent, rather than about a cleaned-up version of it.

```php
public function __construct( public readonly string $description = '', public readonly mixed $validate = null, public readonly mixed $sanitize = null, public readonly ?string $of = null, public readonly array $schema = array() )
```

|  | Details |
|---|---|
| **Parameters** | `$description` — What this argument is, in a sentence a stranger can act on. Optional, for an argument whose name and type already say it — but the caller has nobody to ask, so write one wherever the name alone would leave it guessing<br>`$validate` — Called as `( $value )` for a rule JSON Schema cannot express; returning false rejects the call. A bare built-in name, a `[Class, 'method']` pair or a one-parameter closure<br>`$sanitize` — Called as `( $value )` once validation passes; its return value is what gets bound. Same three shapes as `$validate`<br>`$of` — For an `array` property, the class each item is built from<br>`$schema` — Any other JSON Schema keys, merged over the type and default derived from the property itself |
| **Return** | — |
| **Throws** | — |

Both are called with the value alone, though WordPress hands its own callbacks three arguments. A callback here answers one question about one value, which is all `is_email` or `sanitize_text_field` want — and passing more is what makes the obvious `'intval'` fatal, since it is declared `intval( $value, $base = 10 )` and would take the request as its base.

A check spanning several arguments belongs in the handler, where every bound property is available at once and the error can name the combination that was wrong. A callback here sees one value, in isolation.
