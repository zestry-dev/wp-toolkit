<!--
    Generated from src/Modules/Fields/MetaType.php.
    Do not edit by hand: run `composer docs` after changing the source.
-->

# MetaType

What kind of thing a field is stored against.

WordPress keeps a separate meta table per type, and every meta function takes this as its first argument. A field declares one of these rather than a string, because a typo would register meta against a table that does not exist and fail without saying so.

## Methods

### `has_subtypes()`

Whether meta of this type is divided into subtypes at all.

```php
public function has_subtypes(): bool
```

|  | Details |
|---|---|
| **Parameters** | — |
| **Return** | `bool` |
| **Throws** | — |

True for posts and terms, whose subtypes are post type and taxonomy names. False for users and comments: `get_object_subtype()` answers with the literal `user` and `comment` for every one of them, never a role or a `comment_type` — so a field naming a subtype there registers meta that nothing ever matches, and `Field::subtypes()` must be left empty.
