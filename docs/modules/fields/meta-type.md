<!--
    Generated from src/Modules/Fields/MetaType.php.
    Do not edit by hand: run `composer docs` after changing the source.
-->

# MetaType

What kind of thing a field is stored against.

WordPress keeps a separate meta table per type, and every meta function takes this as its first argument. A field declares one of these rather than a string, because a typo would register meta against a table that does not exist and fail without saying so.
