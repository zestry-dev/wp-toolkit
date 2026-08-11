<?php

/**
 * DevTools: sample values for `stubs/abstract.php.stub`.
 */

declare( strict_types=1 );

return array(
	'{{class_name}}'    => 'EntityField',
	'{{type}}'          => 'field',
	// What `--for=field` fills in. Without it both are empty and the generated
	// class extends nothing, which is the other half of what this stub renders.
	'{{parent_import}}' => "\nuse Acme\\Plugin\\Core\\Modules\\Fields\\Field;\n",
	'{{extends}}'       => ' extends Field',
);
