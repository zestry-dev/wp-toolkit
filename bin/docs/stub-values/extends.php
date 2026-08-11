<?php

/**
 * DevTools: sample values for `stubs/extends.php.stub`.
 */

declare( strict_types=1 );

return array(
	'{{parent_fqn}}'       => 'Acme\\Plugin\\Abstracts\\EntityField',
	'{{parent}}'           => 'EntityField',
	'{{type}}'             => 'field',
	// What reflection writes out for an abstract the parent still leaves --
	// the whole point of the flag, so the sample carries one rather than an
	// empty class body.
	'{{abstract_methods}}' => "\t/**\n"
		. "\t * Which entities this field belongs to.\n"
		. "\t *\n"
		. "\t * @return string[]\n"
		. "\t */\n"
		. "\tpublic function attaches_to(): array {\n"
		. "\t\treturn array();\n"
		. "\t}",
);
