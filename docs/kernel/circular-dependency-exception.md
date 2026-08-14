<!--
    Generated from src/Kernel/Exceptions/CircularDependencyException.php.
    Do not edit by hand: run `composer docs` after changing the source.
-->

# CircularDependencyException

Thrown when two modules reach for each other while building.

Only `make()` can get here. `get()` publishes the shared instance before the module boots, so anything reaching back for it during that boot receives the in-flight one — `make()` never publishes, so two modules making each other would recurse until the stack gave out. The plugin tracks the classes it is part-way through building and raises this the moment one is asked for again.
