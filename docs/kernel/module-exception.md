<!--
    Generated from src/Kernel/Exceptions/ModuleException.php.
    Do not edit by hand: run `composer docs` after changing the source.
-->

# ModuleException

Base exception for declaration, resolution, and boot failures.

Catch this to handle any error raised while declaring, resolving, or booting a service or a module, without also catching unrelated runtime exceptions. More specific failures extend this class: Plugin throws this directly for a malformed `bootstrap.php` — one that returns something other than an array, or holds an entry naming no class. ServicesRepository throws the ModuleNotFoundException and CircularDependencyException subclasses for those particular failures, and every file-discovery module throws DiscoveryException for a layout it cannot read.
