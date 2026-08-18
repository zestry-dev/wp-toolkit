<?php

/**
 * DevTools: sample values for the `stubs/tests/` suite scaffold.
 */

declare( strict_types=1 );

/*
 * The module list a sample plugin's TestCase would carry. Two of them, one of
 * each kind, because the split is the thing a reader has to see: a module that
 * acts on its own goes under a heading, and one left at the top level throws at
 * run(). Composed here the way `wp zt tests` composes it from what is on disk.
 */
return array(
	'{{root}}'                => 'lib',
	'{{test_slug}}'           => 'acme-plugin-test',
	'{{plugin_dir}}'          => 'acme-plugin',
	'{{module_imports}}'      => implode(
		"\n",
		array(
			'use Acme\\Plugin\\Core\\Modules\\Path;',
			'use Acme\\Plugin\\Core\\Modules\\PostTypes\\PostTypes;',
			'use Acme\\Plugin\\Core\\Modules\\Views;',
			'',
		)
	),
	'{{module_declarations}}' => implode(
		"\n",
		array(
			"\t\t\t\tPath::class,",
			"\t\t\t\tViews::class,",
			'',
			"\t\t\t\t'acme-plugin-test_loaded' => array(",
			"\t\t\t\t\tPostTypes::class,",
			"\t\t\t\t),",
			'',
		)
	),
);
