<!--
    Generated from src/Kernel/Exceptions/ModuleException.php.
    Do not edit by hand: run `composer docs` after changing the source.
-->

# ModuleException

Base exception for declaration, resolution, and boot failures.

Catch this to handle any error raised while declaring, building, or booting a module, without also catching unrelated runtime exceptions. Plugin throws it directly for a `bootstrap.php` it cannot read — one that returns something other than an array, holds an entry naming no class, or gives a class entry a value that is not a configurator — and for a module asked for that the file never declared, or that has not reached the hook it is listed under. Building one raises the ModuleNotFoundException and CircularDependencyException subclasses, and every file-discovery module throws DiscoveryException for a layout it cannot read.
