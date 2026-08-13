<!--
    Generated from src/Kernel/Exceptions/CircularDependencyException.php.
    Do not edit by hand: run `composer docs` after changing the source.
-->

# CircularDependencyException

Thrown when services or modules depend on each other in a cycle.

Raised while resolving a class whose dependency graph re-enters that same class before it finishes resolving, which cannot be satisfied. The plugin tracks the classes it is part-way through building, and raises this the moment one of them is asked for again.
