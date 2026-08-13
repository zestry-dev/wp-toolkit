<!--
    Generated from src/Kernel/Exceptions/DiscoveryException.php.
    Do not edit by hand: run `composer docs` after changing the source.
-->

# DiscoveryException

Thrown when a module's file-based discovery cannot proceed.

Catch this to handle any malformed discovery layout, in any module that reads files, without also catching unrelated failures. It arrives in six shapes:

- **A discovered file returned the wrong thing.** Usually a missing `return`,
since `require` yields `1` for a file that returns nothing.
- **A root directory named by a `set_*_root()` call does not exist.** A
*default* root that is absent is not an error: the module discovers nothing and says nothing.
- **Two files resolve to one registered name**, which only happens where the
name is built from more than the filename — `reports.php` and `reports/index.php` are two paths meaning one admin page.
- **A filename the destination could not carry**: an admin page slug a URL
would have to encode, an ability name outside WordPress's `[a-z0-9-]`, or an icon name outside its own.
- **A file WordPress would quietly alter rather than refuse**: an SVG icon
drawn with anything its sanitizer removes, which registers and then renders as less than it is.
- **WordPress refused the registration**, for the calls that report a refusal
by returning something falsy rather than by saying anything.

**A name is refused, never repaired.** Neither naming failure above rewrites your filename into something acceptable: a name spelled for you is a name you cannot find again, and the file would keep looking like working code. Rename the file — `wp zt make` writes an acceptable name in the first place, and says when it had to.

Extends `ModuleException`, and therefore `\RuntimeException`: a discovery failure depends on which files exist on disk and what they return at boot, not on an argument you passed. That also puts it in the same hierarchy as the registration, resolution and boot failures it happens alongside, so one `catch ( ModuleException $e )` around boot covers every way a module can fail to come up.

Writing a discovery module of your own? `missing_root()` and `name_collision()` raise the same two sentences the built-in modules do, so yours fails the way the rest of the plugin already does.

## Methods

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
