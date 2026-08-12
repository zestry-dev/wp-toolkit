<?php

/**
 * DevTools: sample values for `stubs/shared/`.
 *
 * A shared package's `wordpress` block is written by the command as a JSON
 * fragment rather than as one value, since the two kinds are loaded by different
 * WordPress APIs. These pick the script variant, which is the one to reach for
 * unless there is a reason not to.
 *
 * `kind` is all of it: the handle and the global a script package registers with
 * are composed by the generated `webpack.config.js`, which is also what writes
 * the handle into every importer's `.asset.php`.
 */

declare( strict_types=1 );

return array(
	'{{export_name}}'     => 'greet',
	'{{wordpress_block}}' => "{\n\t\t\"kind\": \"script\"\n\t}",
	'{{loading_note}}'    => 'WordPress loads it as the `acme-plugin-shared-example` script handle.',
);
