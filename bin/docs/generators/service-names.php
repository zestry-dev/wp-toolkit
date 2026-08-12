<?php

/**
 * DevTools: the installable service names, as an inline list
 */

declare( strict_types=1 );

/**
 * Every service name `wp zt add service` accepts, comma-separated in backticks.
 *
 * @param string $root Absolute path to the repository root.
 * @return string
 */
function zestry_generate_service_names( string $root ): string {
	return zestry_generate_registry_names( $root, 'services' );
}
