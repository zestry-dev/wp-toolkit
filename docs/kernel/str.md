<!--
    Generated from src/Kernel/Helpers/Str.php.
    Do not edit by hand: run `composer docs` after changing the source.
-->

# Str

The string operations you would otherwise write out at every use.

Mostly spelling a name the way the thing you are handing it to spells names. If you have used Laravel's `Str`, these are the same names doing the same things, with WordPress's own functions underneath wherever it has one — `_wp_to_kebab_case()` splits a name, `remove_accents()` transliterates, `wp_trim_words()` trims to whole words.

Static, and not a `Service`, because there is nothing here to configure or inject: every method is a pure function of its arguments.

## Spelling a name for where it is going

```php
use Acme\Plugin\Core\Kernel\Helpers\Str;

Str::kebab( 'Send_Invoice' );     // 'send-invoice'  a filename, a hook
Str::snake( 'sendInvoice' );      // 'send_invoice'  a database column
Str::pascal( 'send-invoice' );    // 'SendInvoice'   a class name
Str::camel( 'send-invoice' );     // 'sendInvoice'   a JavaScript property
Str::headline( 'send-invoice' );  // 'Send Invoice'  a label
Str::slug( 'Café Menu' );         // 'cafe-menu'     an ability, a block, an npm scope
```

All of them go through `_wp_to_kebab_case()` first, so they agree with each other and with the names this toolkit derives from your filenames — `XMLHttpRequest` becomes `xml-http-request` rather than collapsing into one word.

Coming from Laravel, three differ on purpose: an acronym stays whole (`snake( 'LaravelPHPFramework' )` gives `laravel_php_framework`, not `laravel_p_h_p_framework`); `headline()` is the one that labels an identifier, where Laravel also offers a `title()` that cases a whole sentence; and `limit()` counts its suffix towards the limit and keeps whole words, where Laravel appends the suffix afterwards and takes a flag for words.

## Methods

### `kebab( $value )`

`send-invoice` — a discovered filename, an ability, a command.

```php
public static function kebab( string $value ): string
```

|  | Details |
|---|---|
| **Parameters** | `$value` — Any spelling |
| **Return** | `string` |
| **Throws** | — |

WordPress's own `_wp_to_kebab_case()`, named here so the other four have something to agree with.

<br>

### `snake( $value )`

`send_invoice` — a database column, a hook name written by hand.

```php
public static function snake( string $value ): string
```

|  | Details |
|---|---|
| **Parameters** | `$value` — Any spelling |
| **Return** | `string` |
| **Throws** | — |

<br>

### `camel( $value )`

`sendInvoice` — a JavaScript property, a block attribute.

```php
public static function camel( string $value ): string
```

|  | Details |
|---|---|
| **Parameters** | `$value` — Any spelling |
| **Return** | `string` |
| **Throws** | — |

<br>

### `pascal( $value )`

`SendInvoice` — a class name.

```php
public static function pascal( string $value ): string
```

|  | Details |
|---|---|
| **Parameters** | `$value` — Any spelling |
| **Return** | `string` |
| **Throws** | — |

<br>

### `headline( $value )`

`Send Invoice` — a label, from an identifier.

```php
public static function headline( string $value ): string
```

|  | Details |
|---|---|
| **Parameters** | `$value` — Any spelling |
| **Return** | `string` |
| **Throws** | — |

An identifier is what this takes: a filename, a slug, a key. It is not a sentence-caser, because it splits on a change of case to keep `XMLHttpRequest` readable, and prose with irregular capitalisation comes apart on the same rule — `jefFErson` becomes `Jef F Erson`. For prose, `mb_convert_case( $value, MB_CASE_TITLE, 'UTF-8' )` is the one you want.

A first guess at a title and nothing more. Anything someone reads should be translated, and a translated string is one you wrote rather than one derived from a filename.

<br>

### `slug( $value, $separator )`

`send-invoice` — and only ever `[a-z0-9-]`, for somewhere that demands it.

```php
public static function slug( string $value, string $separator = '-' ): string
```

|  | Details |
|---|---|
| **Parameters** | `$value` — Any spelling |
| **Return** | `string` |
| **Throws** | — |

Kebab-case first, so a separator is *chosen* the same way as everywhere else and `SendInvoice` comes apart into two words, then everything outside ASCII letters, digits and dashes is dropped and runs of dashes collapse. `to_kebab_case()` alone keeps accented and CJK letters, which an npm package name, a block name and an ability name are all refused for.

Lossy, unlike the other five: reach for it only where the destination publishes that character set as a rule.

<br>

### `squish( $value )`

Collapse every run of whitespace to one space, and trim the ends.

```php
public static function squish( string $value ): string
```

|  | Details |
|---|---|
| **Parameters** | `$value` — The string to tidy |
| **Return** | `string` |
| **Throws** | — |

<br>

### `limit( $value, $limit, $suffix )`

Shorten to a length, without cutting a word in half.

```php
public static function limit( string $value, int $limit, string $suffix = '…' ): string
```

|  | Details |
|---|---|
| **Parameters** | `$value` — The string to shorten<br>`$limit` — The most characters to return, suffix included<br>`$suffix` — Appended when the string had to be shortened |
| **Return** | `string` |
| **Throws** | — |

The suffix counts towards the limit, so the result is never longer than asked for — which is the point when the limit came from a column width or a meta description. Laravel's `Str::limit()` appends its suffix *after* the limit and cuts mid-word by default; this does neither, so a length you pass here is a length you get.

<br>

### `words( $value, $words, $suffix )`

Trim to a number of whole words, appending a suffix when anything was cut.

```php
public static function words( string $value, int $words = 100, string $suffix = '…' ): string
```

|  | Details |
|---|---|
| **Parameters** | `$value` — The text to trim<br>`$words` — How many words to keep<br>`$suffix` — Appended only when the text is longer than that |
| **Return** | `string` |
| **Throws** | — |

WordPress's own `wp_trim_words()`, which strips tags and normalises whitespace on the way. Reach for this over `limit()` when the budget is a readable length rather than a column width.

<br>

### `join_path( $segments )`

Join path segments with exactly one separator between them.

```php
public static function join_path( string ...$segments ): string
```

|  | Details |
|---|---|
| **Parameters** | — |
| **Return** | The joined path |
| **Throws** | — |

Every segment is trimmed of slashes before joining, so it does not matter which side carried one — the reason to call this rather than concatenate. An empty segment contributes nothing instead of a doubled slash, and a leading slash on the *first* segment is kept, since that is the difference between an absolute path and a relative one.

```php
Str::join_path( $plugin_root, 'views/', '/emails/receipt.php' );
```
