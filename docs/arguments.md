# Arguments

A REST route, an ability, an AJAX action and an admin page all take input from somewhere you do not control, and the first two have to describe that input to whoever is calling — a JavaScript client, another server, an AI agent reading your plugin for the first time. You declare it once, as a typed property:

```php
use Acme\Plugin\Core\Services\Request\Attributes\RequestArgument;

#[RequestArgument( 'The order to cancel.' )]
public int $order_id;

#[RequestArgument( 'Whether to email the customer.' )]
public bool $notify = true;
```

and your handler reads `$this->order_id` — never `$request->get_param( 'order_id' )` or `$input['order_id']`.

If you have written DTOs, value objects or form objects before, this is that: **your route or ability is the data object**, its properties are the fields, and the values arrive already checked and already the right type. Nested shapes are plain classes declared the same way, and they arrive as real objects rather than arrays. What is different from a general-purpose mapper is that the declaration is also *published* — WordPress turns it into the schema a client, or an AI agent, reads to work out what to send.

The [`request`](services/request/) service does that work. Add it with `wp zestry add service request`; [`rest-api`](modules/rest-api/), [`abilities`](modules/abilities/), [`ajax`](modules/ajax/) and [`admin-pages`](modules/admin-pages/) each bring it along.

```php
// One declaration, three jobs:
#[RequestArgument( 'The order to cancel.' )]
public int $order_id;
//     ↑ published as {"type":"integer","description":"The order to cancel."}
//     ↑ enforced — a caller sending "abc" is refused before your code runs
//     ↑ bound — $this->order_id is an int by the time handle() starts
```

## How it works

Four steps, in this order, every time:

1. **At registration**, your declarations become a schema. A route publishes it as `args` on `register_rest_route()`; an ability publishes it as its `input_schema`.
2. **WordPress validates** the incoming values against that schema and rejects what does not fit — before your code runs, with its own `400`.
3. **Your own `validate` and `sanitize` run**, for the rules a schema cannot state.
4. **The values are bound** onto your properties, and your handler runs.

Nothing reaches step 4 that failed an earlier one. That is the whole point of declaring rather than reading: the checking happens where a caller can see it coming.

> [!NOTE]
> A schema is not documentation *about* your code — it is the contract a caller reads to decide what to send. An agent picking between your abilities has your description and your schema and nothing else.

## What you can declare

The property states the type, and that one declaration is the whole contract. These are all of them:

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

Anything else is refused at registration. See [Limitations](#limitations).

### Required, and defaults

**A property with no default is required. A property with a default is optional, and that default is published** so a caller knows what leaving the key out gets them.

```php
#[RequestArgument( 'The order to cancel.' )]
public int $order_id;              // required

#[RequestArgument( 'Whether to email the customer.' )]
public bool $notify = true;        // optional; the schema says default: true
```

There is nothing to write beyond the declaration, and nothing that can disagree with it. An optional argument *needs* its default: a typed property with no value throws the moment your handler reads it, which is the failure the rule exists to make impossible.

Two exceptions on a route:

- **A `{token}` in the URL pattern is always required**, whatever its property says — there is no optional path segment.
- **An upload is checked by us, not by WordPress**, because it never appears among a request's parameters.

### Structures

This is the DTO half. A property typed as a class becomes a nested object, described by that class's own arguments, and arrives as an instance of it:

```php
final class Address {

    #[RequestArgument( 'Street and number.' )]
    public string $line1;

    #[RequestArgument( 'Two-letter country code.', schema: array( 'pattern' => '^[A-Z]{2}$' ) )]
    public string $country = 'GB';
}

#[RequestArgument( 'Where to ship it.' )]
public Address $address;           // $this->address->line1
```

A structure needs nothing but public typed properties carrying the attribute — no base class, no interface, no constructor. It is built **without calling a constructor**, so a required constructor argument is not a problem and a promoted parameter's default is applied for you.

It is also the one place a typed property is *not* injected. A structure is filled from the request and nothing else, so a `public Path $path;` on one stays uninitialised and fatals on first read — reach for what you need on the route or ability that declared it, where injection does happen.

That makes the modern shape work as written:

```php
final class Parcel {

    public function __construct(
        #[RequestArgument( 'How heavy, in grams.' )]
        public readonly int $weight,
        #[RequestArgument( 'Where it is going.' )]
        public readonly string $destination = 'GB'
    ) {}
}
```

Keys the structure never declared are ignored rather than refused.

### Open objects

Some arguments are genuinely open — a settings blob, a payload you pass through to somewhere else. `stdClass` and `object` both say so:

```php
#[RequestArgument( 'Whatever your client keeps here.' )]
public stdClass $meta;             // $this->meta->colour
```

The schema is `type: object` with no fixed keys, so any object is accepted. Lists inside stay lists, and objects nested inside arrive as objects too.

You can still describe the parts you do know, and leave the rest open:

```php
#[RequestArgument( 'Settings.', schema: array( 'properties' => array( 'theme' => array( 'type' => 'string' ) ) ) )]
public stdClass $settings;
```

A named class is the better choice wherever the shape is actually known — a caller can read a structure and cannot read an open object.

### Lists

PHP has no array-of-type syntax — `LineItem[]` is docblock notation, not a type — so a list says what it holds:

```php
#[RequestArgument( 'What was ordered.', of: LineItem::class )]
public array $items;                                            // LineItem[]

#[RequestArgument( 'The orders to cancel.', schema: array( 'items' => array( 'type' => 'integer' ) ) )]
public array $order_ids;                                        // int[]
```

`of:` also takes an enum. One or the other is required — see [Limitations](#limitations).

`LineItem` is a plain class of yours — the PSR-4 entry `wp zestry init` writes covers your whole source root, so `lib/Data/LineItem.php` autoloads with nothing else to do.

### Enums

An enum is a closed set, and the schema says so:

```php
enum Status: string {
    case Draft = 'draft';
    case Live  = 'live';
}

#[RequestArgument( 'What state to put it in.' )]
public Status $status;             // sends "draft", arrives as Status::Draft
```

A **backed** enum sends its backing value, which is the reason to back one: the value a caller sends is written down, so renaming a case does not change your API. A **pure** enum has no such value, so its case names stand in. The schema lists the accepted values either way, so a caller never has to know which kind it is looking at.

This is the better way to write `schema: array( 'enum' => ... )` by hand — the set lives in one place, and your handler reads a case instead of a string.

### Dates

```php
#[RequestArgument( 'When the sale ends.' )]
public DateTimeImmutable $ends_at;
```

Published as `type: string, format: date-time`, which WordPress enforces with `rest_parse_date()` before anything binds — so `handle()` gets a real date or the call never reaches it. Typing the interface (`DateTimeInterface`) gives you a `DateTimeImmutable`.

Three things to know before you declare one:

| | |
|---|---|
| **What a caller must send** | A full date *and* time: `2026-08-04T12:00:00Z`, `2026-08-04T12:00:00+10:00`, or `2026-08-04 12:00:00`. `2026-08-04` is refused, and so is `now`. |
| **What it means** | A time with no offset is **UTC**, because WordPress runs PHP in UTC — `2026-08-04 12:00:00` is noon UTC whatever the site's timezone says. |
| **Getting site-local** | `$this->send_at->setTimezone( wp_timezone() )`. |

For a *day* rather than a moment — a birthday, a due date — say so with a pattern. `date-time` has no date-only form, and a `DateTimeImmutable` would invent a midnight nobody sent:

```php
#[RequestArgument( 'The day it is due.', schema: array( 'pattern' => '^\d{4}-\d{2}-\d{2}$' ) )]
public string $due_on;
```

### Files

Uploads are **routes only**:

```php
use Acme\Plugin\Core\Services\Request\UploadedFile;

#[RequestArgument( 'The image to attach.' )]
public UploadedFile $image;

#[RequestArgument( 'Every page of the document.', of: UploadedFile::class )]
public array $pages;
```

An upload arrives as `multipart/form-data`, which JSON Schema has no type for — so WordPress keeps uploads out of a request's parameters entirely, and an ability (whose input is JSON) is refused one when it registers.

A file is therefore the one argument no schema checks. [`store()`](services/request/uploaded-file.md) does the checking WordPress would want and moves the file into the uploads directory:

```php
public function handle( WP_REST_Request $request ): WP_REST_Response|WP_Error {
    $stored = $this->image->store();

    if ( is_wp_error( $stored ) ) {
        return $stored;
    }

    return new WP_REST_Response( array( 'url' => $stored['url'] ) );
}
```

It returns WordPress's own array — `file`, `url`, `type` — or a `WP_Error` carrying the status to answer with, using core's own codes so a client written against the media endpoints handles yours the same way. `$overrides` reaches `wp_handle_upload()`, so `mimes` narrows what is accepted.

Calling `wp_handle_upload()` yourself from a route fails three ways, all of which `store()` absorbs: the upload functions live in `wp-admin`, which a REST request has not loaded; `wp_handle_upload()` looks for a form field REST never sends and refuses the file for missing it; and it takes its file **by reference**, so a method's return value cannot be passed to it.

To decide for yourself instead, ask first — a request can carry a file that failed to arrive:

```php
if ( ! $this->image->is_ok() ) {
    return new \WP_Error( 'acme_no_image', $this->image->get_error_message(), array( 'status' => 400 ) );
}

if ( $this->image->size > 5 * MB_IN_BYTES ) {
    return new \WP_Error( 'acme_image_too_large', __( 'Images must be under 5 MB.', 'acme-plugin' ), array( 'status' => 400 ) );
}
```

Storing a file is not adding it to the media library: that is `wp_insert_attachment()` with the path `store()` returns.

A missing required upload is refused with `rest_missing_callback_param` before your handler runs. PHP transposes a multi-file field into one entry holding every name; `of: UploadedFile::class` untangles that, and a single file sent to such a field still arrives as a list of one.

## Narrowing what you accept

Three ways, in the order you should reach for them.

**1. The type.** `int $id` already rejects `abc` with a `400`.

**2. `schema:` — anything JSON Schema expresses.** Merged over the derived type, so an explicit key always wins. WordPress enforces every keyword it recognises — `enum`, `minimum`, `maximum`, `minLength`, `maxLength`, `pattern`, `minItems`, `maxItems`, `uniqueItems`, `format` — before your handler runs, on a route and on an ability alike:

```php
#[RequestArgument( 'How to sort.', schema: array( 'enum' => array( 'date', 'title' ) ) )]
public string $order_by = 'date';

#[RequestArgument( 'Which page.', schema: array( 'minimum' => 1, 'maximum' => 100 ) )]
public int $page = 1;

#[RequestArgument( 'Two-letter country code.', schema: array( 'pattern' => '^[A-Z]{2}$' ) )]
public string $country = 'GB';
```

A `pattern` is a bare regex — no delimiters, and a `#` in it is escaped for you. It applies to strings, and a value that does not match is refused with `does not match pattern ^[A-Z]{2}$` before anything binds.

**3. `validate:` and `sanitize:` — for what a schema cannot say.** That a row exists, that a slug is free, that a date has not passed:

```php
#[RequestArgument( 'The order to cancel.', validate: array( self::class, 'is_open_order' ) )]
public int $order_id;

public static function is_open_order( $value ): bool {
    return acme_order_is_open( $value );
}
```

> [!IMPORTANT]
> **Prefer the schema to the callbacks.** A rule stated in the schema is one a caller can read and satisfy before calling. The same rule in a callback is one it can only discover by getting it wrong — and an agent choosing what to send has no way to guess it.

Both callbacks receive the value **alone**, so a bare built-in name works: `validate: 'is_email'`, `sanitize: 'sanitize_text_field'`. WordPress hands its own callbacks three arguments, which is what makes the obvious `'intval'` fatal there — it is declared `intval( $value, $base = 10 )` and takes the request as its base. Here it is safe.

Validation runs first and sanitising second, so you decide about the value that was actually sent rather than a cleaned-up version of it. Your `sanitize` is handed the value as its declared type — WordPress casts it against the schema first — so a callback on an `int` argument gets an `int`.

Neither replaces the schema. Your callback runs in addition to it, not instead of it, and nothing is checked twice.

### The four are not checked identically

WordPress does different amounts of the work on each, so the same declaration reaches your handler by more than one path:

| | Route | Ability | AJAX action | Admin page |
|---|---|---|---|---|
| Schema validated | by WordPress | by WordPress | by this service | by this service |
| Value unslashed | by WordPress | not slashed | by this service | by this service |
| Value cast to its type | by WordPress | by this service | by this service | by this service |
| Your `validate` / `sanitize` | in WordPress's own slots | run before binding | run before binding | run before binding |
| Bound before the permission check | no | yes | yes | after it |
| A refusal reads as | `rest_invalid_param`, 400 | `ability_invalid_input` | `rest_invalid_param`, 400 | `wp_die()`, 400 |

An action and a page are the two WordPress does nothing for: both are plain hooks handed the superglobals as they arrived, slashed and unchecked. Declaring arguments is how either stops reading them by hand — though for anything new reachable from outside the admin, a route is still the better place, for the reasons in [`ajax`](modules/ajax/).

A page cannot answer a refusal the way the other three do, because what is waiting is a browser mid-POST rather than a caller. It stops with `wp_die()` — the same answer the page already gives a failed capability and a failed nonce — naming the arguments, with a link back to the form. `handle_submit()` never runs.

**Where the value comes from** is the same question on all four, and gets the same answer: the values are loaded into a `WP_REST_Request` and resolved by `get_param()`, so the JSON body wins, then the form body, then the query string. A cookie is never a parameter — and would have been, had `$_REQUEST` been read instead, since PHP builds it from `variables_order` (`EGPCS`) when `request_order` is unset.

An ability's input is validated and never sanitised — and that validation accepts a numeric string for an `integer`, so `"42"` is a valid thing for a caller to send. It arrives as `42` either way.

A check spanning **two** arguments belongs in your handler, where every property is bound at once and the error can name the combination that was wrong. A callback here sees one value in isolation.

### Where a refusal comes from

| Refused by | Code | Status |
|---|---|---|
| The schema, on a route | `rest_invalid_param` | 400 |
| Your `validate`, on a route | `rest_invalid_param` | 400 |
| The schema, on an ability | `ability_invalid_input` | — |
| Your `validate`, on an ability | `ability_invalid_input` | — |
| A missing upload | `rest_missing_callback_param` | 400 |

Each matches what WordPress itself returns for the same kind of failure on that platform, so a client handles one code per platform rather than two.

## What a real one looks like

An ability with a nested structure, a list of structures, an enum and a date, and the schema WordPress publishes for it — this is what a client or an agent reads before calling:

```php
final class Address {

    public function __construct(
        #[RequestArgument( 'Street and number.' )]
        public readonly string $line1,
        #[RequestArgument( 'Two-letter country code.', schema: array( 'pattern' => '^[A-Z]{2}$' ) )]
        public readonly string $country = 'GB'
    ) {}
}

enum Channel: string {
    case Web   = 'web';
    case Phone = 'phone';
}

// abilities/create-order.php
#[RequestArgument( 'Where to ship it.' )]
public Address $shipping;

#[RequestArgument( 'What was ordered.', of: LineItem::class )]
public array $items;

#[RequestArgument( 'Where the order came from.' )]
public Channel $channel = Channel::Web;

#[RequestArgument( 'Do not ship before this moment.' )]
public ?DateTimeImmutable $deliver_after = null;
```

```json
{
  "type": "object",
  "properties": {
    "shipping": {
      "description": "Where to ship it.",
      "type": "object",
      "properties": {
        "line1":   { "description": "Street and number.", "type": "string" },
        "country": { "description": "Two-letter country code.", "type": "string", "default": "GB", "pattern": "^[A-Z]{2}$" }
      },
      "required": ["line1"]
    },
    "items": { "description": "What was ordered.", "type": "array", "items": { "type": "object", "properties": { … } } },
    "channel": { "description": "Where the order came from.", "type": "string", "enum": ["web","phone"], "default": "web" },
    "deliver_after": { "description": "Do not ship before this moment.", "type": ["string","null"], "format": "date-time", "default": null }
  },
  "required": ["shipping", "items"]
}
```

Nothing in that JSON was written by hand. `required` came from which properties have defaults, `enum` from the enum's cases, `default` from the declarations, and `format: date-time` from the type — and each is enforced before your handler runs.

## Translation

`__()` cannot go inside the attribute. PHP allows only constant expressions in an attribute argument, so this does not fail at runtime or in the wrong order — it fails when the file is compiled, before anything of yours runs:

```php
// Fatal error: Constant expression contains invalid operations
#[RequestArgument( __( 'The order to cancel.', 'acme-plugin' ) )]
public int $order_id;
```

Everywhere else, `__()` is fine. `label()`, `description()` and a hand-written `input_schema()` are ordinary methods, and both modules register after `init`, so nothing translates too early:

```php
public function description(): string {
    return __( 'Cancels an order that has not shipped yet.', 'acme-plugin' );   // fine
}
```

So to translate an argument's own description, write that argument's schema by hand:

```php
public function input_schema(): array {
    return array(
        'type'       => 'object',
        'properties' => array(
            'order_id' => array(
                'type'        => 'integer',
                'description' => __( 'The order to cancel.', 'acme-plugin' ),
            ),
        ),
        'required'   => array( 'order_id' ),
    );
}
```

Worth weighing against what the description is for. It is read by whoever *calls* your ability — a developer, or an agent choosing between tools — not shown to the person using your plugin. English is often the right answer, and keeping the declaration is worth more than translating a string nobody in your plugin's UI will see.

## Six behaviours that differ from a general-purpose mapper

Each is shaped by WordPress already shipping the validator and dictating the schema format:

- **A constructor is never called.** A structure is built without one and its properties are assigned, so a constructor that enforces an invariant does not run and cannot reject anything. Put the rule in `validate:` or your handler.
- **Unknown keys are ignored**, as Symfony ignores them. Valinor refuses them by default; nothing here does.
- **`object` is allowed.** Valinor refuses it as too permissive, but WordPress passes arbitrary payloads around constantly, and JSON Schema has a word for one. Use a named class wherever the shape is known.
- **Names are used verbatim.** There is no camelCase-to-snake_case conversion: an argument is called what the property is called, which is the WordPress convention anyway.
- **A keyed map of structures is not built.** `of:` describes a list. A JSON object whose *values* are structures arrives as an array — describe it with `schema: array( 'additionalProperties' => ... )` and convert it in your handler.
- **Every refusal is reported at once**, not the first one, matching what WordPress answers a route with.

## Tips

- **Describe every argument.** The description is optional and `#[RequestArgument]` alone is fine for an obvious `$id`, but whatever is calling reads it to decide what to send and cannot ask you.
- **Do not reach for `__()` inside the attribute.** PHP allows only constant expressions there, so it is a fatal error at compile time — see [Translation](#translation).
- **Give every optional argument a default.** It is what makes it optional, and it is published so a caller knows what it gets.
- **Reach for an enum before `schema: [ 'enum' => ... ]`.** One source of truth, and your handler gets a case.
- **Put shared shapes in a structure.** An `Address` declared once can be an argument of every route and ability that takes one.
- **Keep `validate` about one value.** Anything comparing two arguments belongs in the handler.
- **A route and an ability can share the same structures**, and often should — the same operation reachable both ways is the point of writing an ability at all.

## Limitations

Each is refused with a message naming the property, and never silently. All but the last two are caught while your route or ability registers, so you meet them the first time the code loads rather than the first time someone calls it.

| Not supported | Why | Instead |
|---|---|---|
| `public $thing` (untyped) | Nothing says what it is | Declare a type |
| `int\|string $thing` | A caller cannot be told which to send | Pick one, or take a structure |
| `mixed`, `iterable` | No JSON type corresponds | Declare the real shape, or `stdClass` for a genuinely open one |
| `array $things` with neither `of:` nor `schema['items']` | A list whose contents go undescribed is a hole a caller cannot read its way out of | Add `of:` or `items` |
| `of:` on anything but an array | It describes what a list holds | Remove it |
| A class with no `#[RequestArgument]` properties | Nothing describes it, so the schema would carry a type-less entry | Declare its properties, or use `schema:` |
| A `static` property | One value shared by every call at once | Make it an instance property |
| Structures nesting more than 10 deep | A structure containing itself has no schema, only an ever-deeper one | Break the cycle |
| `readonly` on a **route or ability** property | See [Why not readonly](#why-not-readonly) | Drop `readonly` — or move the arguments into a structure, where it works |
| `UploadedFile` on an **ability** | Its input is JSON; an upload is multipart | Take the upload on a route |
| `@var LineItem[]` docblocks | `getDocComment()` returns nothing when `opcache.save_comments=0`, so the shape would vanish on some servers | `of: LineItem::class` |
| `__()` in the attribute | PHP allows only constant expressions in an attribute argument — this is a fatal error at compile time, not a load-order problem | Write that argument's [`input_schema()`](#translation) by hand |

A value that contradicts the schema — a string where a structure belongs, `null` for an argument that does not take it — is refused when it binds, naming the argument. You only reach that by replacing a derived schema with a hand-written one that no longer matches the property it fills.

### Why not readonly

PHP lets a `readonly` property be assigned exactly once. Assign it again and you get `Cannot modify readonly property`, whoever is doing the assigning.

Your route or ability object is built **once**. The module discovers the file, wires the instance, and that one object answers every call for the rest of the request — so binding arguments onto it means assigning the same properties again on the next call, which is the assignment PHP refuses. On an ability it fails sooner still: the values are bound before `permission_check()` and again before `handle()`, so both happen inside a single call.

A structure is the opposite. It is built fresh every time, by `hydrate()`, so its properties are assigned exactly once in their lifetime — which is precisely what `readonly` asks for. So this works:

```php
final class Filter {

    public function __construct(
        #[RequestArgument( 'Which page.' )]
        public readonly int $page = 1,
        #[RequestArgument( 'How to sort.' )]
        public readonly string $order_by = 'date'
    ) {}
}

// on the route or ability — not readonly, because this object is reused:
#[RequestArgument( 'How to narrow the list.' )]
public Filter $filter;             // $this->filter->page, and Filter is immutable
```

If immutable arguments are what you are after, that is the shape to reach for: the handler's own properties are plumbing, and the values you actually care about live in an object that cannot be changed under you.

## See also

- [`RequestArgument`](services/request/request-argument.md) — the attribute's own reference.
- [`request`](services/request/) — the service, for the rare case you call it yourself.
- [`rest-api`](modules/rest-api/) — routes, and everything else a route declares.
- [`abilities`](modules/abilities/) — abilities, and what makes one callable by an agent.
- [Errors](errors.md) — every exception this toolkit throws, and what to do about it.
