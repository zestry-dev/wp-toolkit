<!--
    Generated from src/Kernel/Exceptions/ModuleNotFoundException.php.
    Do not edit by hand: run `composer docs` after changing the source.
-->

# ModuleNotFoundException

Thrown when a requested service or module class cannot be resolved.

Raised when the class does not exist or is not a Service subclass — which includes every Module — so no instance can be created for the requested name. Checked before every instantiation, so it is raised as the class is asked for rather than when something first calls it.
