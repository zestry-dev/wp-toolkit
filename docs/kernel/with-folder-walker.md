<!--
    Generated from src/Kernel/Traits/WithFolderWalker.php.
    Do not edit by hand: run `composer docs` after changing the source.
-->

# WithFolderWalker

Provides recursive, depth-limited, convention-based file discovery.

A module using this trait can enumerate the PHP (or other) files under one of its own folders without hand-rolling directory recursion. Discovery honors a simple naming convention instead of a manifest or config file: any file or directory whose name begins with `.` or `-` is treated as private and excluded, which lets a module ship a folder such as `-partials/` or `.disabled/` that is never picked up as a discoverable unit. This is the shared file-walking primitive behind every file-discovery module, each of which finds its own units by scanning a folder rather than requiring explicit registration.
