<?php

/**
 * DevTools: the installable module names, as an inline list
 */

declare( strict_types=1 );

/**
 * Every module name `wp zestry add module` accepts, comma-separated in backticks.
 *
 * Derived from `registry.php`, which is the single source of what is
 * installable -- so a module added there appears on every page naming them,
 * with nothing to remember. The order is the registry's own, which is roughly
 * how often one is reached for rather than alphabetical.
 *
 * @param string $root Absolute path to the repository root.
 * @return string
 */
function zestry_generate_module_names( string $root ): string {
	return zestry_generate_registry_names( $root, 'modules' );
}
