<!--
    Generated from src/Modules/Request/Attributes/RequestArgument.php.
    Do not edit by hand: run `composer docs` after changing the source.
-->

# RequestArgument

Declares a property as one of the arguments its class accepts.

One attribute for both callable surfaces this toolkit registers — a `RestRoute` and an `Ability` — because they ask the same question. Each is a named operation, described to a caller who cannot read your code, validated before it runs. Declare the argument once and the `Request` module builds the route's `args` or the ability's input schema from it, and binds the value onto the property before your handler runs.

```php
use Acme\Plugin\Core\Modules\Request\Attributes\RequestArgument;

#[RequestArgument( 'The order to cancel.' )]
public int $order_id;

#[RequestArgument( 'Whether to email the customer.' )]
public bool $notify = true;
```

So a handler reads `$this->order_id`, not `$request->get_param( 'order_id' )` or `$input['order_id']`.

**The property states both the type and whether it is required**, since PHP already made you declare both. `int` is `integer`, `float` is `number`, `bool` is `boolean`, and `?int` accepts null as well. An argument with no default is required — leaving it optional would mean a handler reading an uninitialized property, which is a PHP error rather than a missing value — and one with a default is optional, with that default published so a caller knows what leaving the key out gets them.

If you have written DTOs, value objects or form objects before, this is that: your route or ability *is* the data object, its properties are the fields, and the values arrive already checked and already the right type. What differs is that the declaration is also *published* — WordPress turns it into the schema a client, or an AI agent, reads to work out what to send.

## How it works

Four steps, in this order, every time:

1. **At registration**, your declarations become a schema. A route publishes
it as `args` on `register_rest_route()`; an ability as its `input_schema`.
2. **WordPress validates** the incoming values against that schema and rejects
what does not fit — before your code runs, with its own `400`.
3. **Your own `validate` and `sanitize` run**, for the rules a schema cannot
state.
4. **The values are bound** onto your properties, and your handler runs.

Nothing reaches step 4 that failed an earlier one. That is the whole point of declaring rather than reading: the checking happens where a caller can see it coming.

## What you can declare

| Property | Schema | Arrives as |
|---|---|---|
| `int $count` | `integer` | `int` |
| `float $ratio` | `number` | `float` |
| `string $label` | `string` | `string` |
| `bool $enabled` | `boolean` | `bool` |
| `?int $count` | `["integer","null"]` | `int` or `null` |
| `array $ids` + `schema: [ 'items' => [ 'type' => 'integer' ] ]` | `array` of integers | `int[]` |
| `array $items` + `of: LineItem::class` | `array` of objects | `LineItem[]` |
| `Address $address` | nested `object` | `Address` |
| `stdClass $meta` or `object $meta` | `object`, no fixed keys | `stdClass` |
| `Status $status` (enum) | `string`/`integer` + `enum` | `Status::Live` |
| `DateTimeImmutable $when` | `string`, `format: date-time` | `DateTimeImmutable` |
| `UploadedFile $image` | *(not in the schema)* | `UploadedFile` |

Anything else is refused at registration, with a message naming the property. See **Limitations** below.

Two exceptions to the required/optional rule, both on a route: a `{token}` in the URL pattern is **always required**, whatever its property says, since there is no optional path segment; and an upload is checked here rather than by WordPress, because it never appears among a request's parameters.

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

`validate` and `sanitize` are for what JSON Schema cannot say — that an id exists, that a date has not passed, that a slug is free:

```php
#[RequestArgument( 'The order to cancel.', validate: array( self::class, 'is_open_order' ) )]
public int $order_id;

public static function is_open_order( $value ): bool {
    return acme_order_is_open( $value );
}
```

A `pattern` is a bare regex — no delimiters, and a `#` in it is escaped for you.

**Only public and protected properties are read.** A private one carrying this attribute is left alone entirely: it appears in no schema and is never bound.

## The four surfaces are not checked identically

A route, an ability, an AJAX action and an admin page all declare their input this way, but WordPress does different amounts of the work on each:

| | Route | Ability | AJAX action | Admin page |
|---|---|---|---|---|
| Schema validated | by WordPress | by WordPress | by this module | by this module |
| Value unslashed | by WordPress | not slashed | by this module | by this module |
| Value cast to its type | by WordPress | by this module | by this module | by this module |
| Your `validate` / `sanitize` | in WordPress's own slots | run before binding | run before binding | run before binding |
| Bound before the permission check | no | yes | yes | after it |
| A refusal reads as | `rest_invalid_param`, 400 | `ability_invalid_input` | `rest_invalid_param`, 400 | `wp_die()`, 400 |

An action and a page are the two WordPress does nothing for: both are plain hooks handed the superglobals as they arrived, slashed and unchecked, so declaring arguments is how either stops reading them by hand. A page cannot answer a refusal the way the other three do — what is waiting is a browser mid-POST — so it stops with `wp_die()`, and `handle_submit()` never runs.

**Where the value comes from** is the same answer on all four: the values are loaded into a `WP_REST_Request` and resolved by `get_param()`, so the JSON body wins, then the form body, then the query string. A cookie is never a parameter.

An ability's input is validated and never sanitised — and that validation accepts a numeric string for an `integer`, so `"42"` is a valid thing for a caller to send. It arrives as `42` either way.

## Translation, and anything else computed

`__()` cannot go inside the attribute. PHP allows only constant expressions in an attribute argument, so this fails when the file is compiled, before anything of yours runs:

```php
// Fatal error: Constant expression contains invalid operations
#[RequestArgument( __( 'The order to cancel.', 'acme-plugin' ) )]
public int $order_id;
```

State that one argument's description in `input_schema()` on an ability, or `args()` on a route. What you write there is merged *over* the schema your declarations already give, so the property keeps its type, its required-ness, its `validate` rule and its binding — you are finishing the sentence the attribute started, not writing the schema instead of it:

```php
// Still the declaration — only the description moved.
#[RequestArgument]
public int $order_id;

public function input_schema(): array {
    return array(
        'properties' => array(
            'order_id' => array( 'description' => __( 'The order to cancel.', 'acme-plugin' ) ),
        ),
    );
}
```

A route says the same through `args()`, keyed by argument name rather than nested under `properties`, because that is the shape `register_rest_route()` takes. A keyed map is merged into; a list — `required`, an `enum`, a nullable `type` — is replaced whole, so state all of it when you state any of it.

The same door takes everything else an attribute cannot hold: `'enum' => get_post_types()` is a function call, and a function call is not a constant expression either. **An AJAX action and an admin page have no equivalent and need none** — nothing publishes their schema, so a rule you would have stated there belongs in `validate`.

## Limitations

Each is refused with a message naming the property, and never silently. All but the last two are caught while your route or ability registers, so you meet them the first time the code loads rather than the first time someone calls.

| Not supported | Why | Instead |
|---|---|---|
| `public $thing` (untyped) | Nothing says what it is | Declare a type |
| `int\|string $thing` | A caller cannot be told which to send | Pick one, or take a structure |
| `mixed`, `iterable` | No JSON type corresponds | Declare the real shape, or `stdClass` for a genuinely open one |
| `array $things` with neither `of:` nor `schema['items']` | A list whose contents go undescribed is a hole a caller cannot read its way out of | Add `of:` or `items` |
| `of:` on anything but an array | It describes what a list holds | Remove it |
| A class with no `#[RequestArgument]` properties | Nothing describes it | Declare its properties, or use `schema:` |
| A `static` property | One value shared by every call at once | Make it an instance property |
| Structures nesting more than 10 deep | A structure containing itself has no schema, only an ever-deeper one | Break the cycle |
| `readonly` on a **route or ability** property | See below | Drop `readonly` — or move the arguments into a structure, where it works |
| `UploadedFile` on an **ability** | Its input is JSON; an upload is multipart | Take the upload on a route |
| `@var LineItem[]` docblocks | `getDocComment()` returns nothing when `opcache.save_comments=0` | `of: LineItem::class` |
| `__()` in the attribute | Only constant expressions are allowed there | State it in `input_schema()` or `args()` |

### Why not readonly

PHP lets a `readonly` property be assigned exactly once. Your route or ability object is built **once** and answers every call for the rest of the request, so binding arguments onto it means assigning the same properties again on the next call — the assignment PHP refuses. On an ability it fails sooner still: values are bound before `permission_check()` and again before `handle()`.

A structure is the opposite. It is built fresh every time, so its properties are assigned exactly once in their lifetime, which is what `readonly` asks for. If immutable arguments are what you are after, that is the shape:

```php
final class Filter {

    public function __construct(
        #[RequestArgument( 'Which page.' )]
        public readonly int $page = 1,
        #[RequestArgument( 'How to sort.' )]
        public readonly string $order_by = 'date'
    ) {}
}

// On the route or ability — not readonly, because this object is reused:
#[RequestArgument( 'How to narrow the list.' )]
public Filter $filter;             // $this->filter->page, and Filter is immutable
```

## Tips

- **Describe every argument.** The description is optional, but whatever is
calling reads it to decide what to send and cannot ask you.
- **Give every optional argument a default.** It is what makes it optional,
and it is published so a caller knows what it gets.
- **Reach for an enum before `schema: [ 'enum' => ... ]`.** One source of
truth, and your handler gets a case.
- **Put shared shapes in a structure.** An `Address` declared once can be an
argument of every route and ability that takes one.
- **A route and an ability can share the same structures**, and often should.
- **Unknown keys are ignored**, never refused, and names are used verbatim —
there is no camelCase-to-snake_case conversion.

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
