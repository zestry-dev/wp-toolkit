<?php

/**
 * DevTools: sample values for `stubs/shared/`.
 *
 * A shared package's `wordpress` block is written by the command as a JSON
 * fragment rather than as one value, since a script package declares a handle
 * and a global while a module package declares neither. These pick the script
 * variant, which is the one to reach for unless there is a reason not to.
 */

declare( strict_types=1 );

return array(
	'{{export_name}}'     => 'greet',
	'{{wordpress_block}}' => "{\n\t\t\"kind\": \"script\",\n\t\t\"handle\": \"acme-plugin-example\",\n\t\t\"global\": [\n\t\t\t\"acmePlugin\",\n\t\t\t\"example\"\n\t\t]\n\t}",
	'{{loading_note}}'    => 'WordPress loads it as the `acme-plugin-example` script handle.',
);
