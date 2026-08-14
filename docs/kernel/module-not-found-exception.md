<!--
    Generated from src/Kernel/Exceptions/ModuleNotFoundException.php.
    Do not edit by hand: run `composer docs` after changing the source.
-->

# ModuleNotFoundException

Thrown when a requested module class cannot be built.

Raised when the class does not exist or is not a `Module`, so no instance could be created for the name. Checked before the declaration is looked up, so a name that could never be a module says that rather than sending you to `bootstrap.php` to add it.
