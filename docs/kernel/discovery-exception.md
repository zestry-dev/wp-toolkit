<!--
    Generated from src/Kernel/Exceptions/DiscoveryException.php.
    Do not edit by hand: run `composer docs` after changing the source.
-->

# DiscoveryException

Thrown when a module's file-based discovery cannot proceed.

Covers both halves of the discovery convention every file-discovery module shares: a configured root directory that does not exist, and a discovered file that returns something other than the base class that module expects. Catch this to handle any malformed discovery layout, in any module that reads files, without also catching unrelated failures.

Extends `ModuleException`, and therefore `\RuntimeException`: a discovery failure depends on which files exist on disk and what they return at boot, not on an argument you passed. That also puts it in the same hierarchy as the registration, resolution and boot failures it happens alongside, so one `catch ( ModuleException $e )` around boot covers every way a module can fail to come up.

## Methods

### `registration_refused( $kind, $name, $reason )`

The message every module raises when WordPress refuses a registration.

```php
public static function registration_refused( string $kind, string $name, string $reason = '' ): self
```

|  | Details |
|---|---|
| **Parameters** | `$kind` — What was being registered, e.g. `post type`<br>`$name` — The name, which is the file's name<br>`$reason` — WordPress's own message, when it gave one |
| **Return** | `self` |
| **Throws** | — |

Most of WordPress's `register_*` functions report failure by returning something falsy rather than by throwing — `false`, `null`, a `WP_Error` — so an unchecked call leaves the thing simply absent. No post type, no route, no meta field, and nothing said anywhere: the feature reads as broken code rather than as a refused registration, which is the most expensive way for this to fail.

Each module tests the return WordPress actually gives it, because they differ; the sentence they raise is here, so it is one sentence rather than several that drift.

**Only where WordPress is silent.** `register_post_type()`, `register_taxonomy()` and `register_block_type()` refuse without saying anything, so a module that does not check leaves the feature absent and unexplained. `register_meta()`, `register_rest_route()` and `wp_register_ability()` call `_doing_it_wrong()` on every refusal they can make — checking those as well would turn a notice WordPress chose into a fatal that takes the site down, and say the same thing twice. Verified against core rather than assumed; the modules that do not check say so where the call is.

<br>

### `name_collision( $label, $name, $first, $other )`

The message every module raises when two files claim one name.

```php
public static function name_collision( string $label, string $name, string $first, string $other ): self
```

|  | Details |
|---|---|
| **Parameters** | `$label` — What the module discovers, e.g. `commands`<br>`$name` — The name both files resolved to<br>`$first` — The file that claimed it<br>`$other` — The file that collided with it |
| **Return** | `self` |
| **Throws** | — |

A discovered file's name is what it registers as, read exactly as written, so two files usually cannot collide. Where they can is a destination whose name is built from more than the filename: `dashboard.php` and `dashboard/index.php` are two paths meaning one admin page, and only one of them can be it.

Refused rather than resolved, because either resolution is wrong. Keeping the first leaves the second registered against nothing; keeping the last makes the answer depend on directory order. Neither says anything, and the file that lost still looks like working code.

<br>

### `missing_root( $what, $path, $setter )`

The message every module raises for a root it was told to read.

```php
public static function missing_root( string $what, string $path, string $setter ): self
```

|  | Details |
|---|---|
| **Parameters** | `$what` — What the module discovers, e.g. `Commands`<br>`$path` — The absolute path it looked in<br>`$setter` — The setter that named it, e.g. `set_commands_root()` |
| **Return** | `self` |
| **Throws** | — |

Reached only when a `set_*_root()` call named the directory. A *default* root that does not exist discovers nothing and says nothing — adding a module before writing its first file is ordinary — so arriving here means someone asked for this path by name and it is not there. That is what makes a next step worth stating: the setter's argument is wrong, or the directory has yet to be made.

<br>

### `unregistrable_ability_name( $file, $name )`

The message raised for an ability whose name WordPress would not accept.

```php
public static function unregistrable_ability_name( string $file, string $name ): self
```

|  | Details |
|---|---|
| **Parameters** | `$file` — The discovered path, relative to the abilities root<br>`$name` — The full name it asked to register under |
| **Return** | `self` |
| **Throws** | — |

The abilities registry matches `^[a-z0-9-]+/[a-z0-9-]+$` and refuses anything else, so `abilities/create_order.php` asks to register a name that cannot exist. The refusal is `_doing_it_wrong()` inside WordPress, arriving long after boot and naming no file.

Refused rather than rewritten, for the same reason as everywhere else here: a name spelled for you is a name you cannot find again. `$abilities->run()` takes the local name, and it has to be the one on disk.

<br>

### `unsafe_page_slug( $file, $slug )`

The message raised for a page whose slug cannot survive a URL.

```php
public static function unsafe_page_slug( string $file, string $slug ): self
```

|  | Details |
|---|---|
| **Parameters** | `$file` — The discovered path, relative to the pages root<br>`$slug` — The slug it asked to register under |
| **Return** | `self` |
| **Throws** | — |

An admin page's slug is what WordPress puts after `?page=`, and what it appends to the `{parent}_page_` hook it fires. A character a URL has to encode does not survive that round trip: `settings&more.php` asks for `?page={slug}-settings&more`, where the `&` ends the query argument and the page answers with a permissions error instead of itself.

Refused rather than rewritten. Stripping the character would register a page under a name nobody typed, and the file would keep looking like working code — rename the file and the slug is yours again.
