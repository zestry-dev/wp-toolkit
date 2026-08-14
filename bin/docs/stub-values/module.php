<?php

/**
 * DevTools: sample values for `stubs/module.php.stub`.
 */

declare( strict_types=1 );

/*
 * What `--bootable` decides. The page shows that form, since the flag is the
 * interesting half: a module without it is the same file minus the interface,
 * the import and the method, and the command's own page says so.
 */
return array(
	'{{bootable_import}}' => "use Acme\\Plugin\\Core\\Kernel\\Contracts\\Bootable;\n",
	'{{bootable_clause}}' => ' implements Bootable',
	'{{bootable_body}}'   => implode(
		"\n",
		array(
			"\t/**",
			"\t * What this module does on its own.",
			"\t *",
			"\t * Runs once, when the plugin builds this module. Its `bootstrap.php`",
			"\t * entry says when that is: `boots_on` names the plugin's own",
			"\t * `{slug}-loaded` action, so this runs after every other module the",
			"\t * plugin has. Bind hooks, register things, walk a directory.",
			"\t *",
			"\t * @return void",
			"\t */",
			"\tpublic function on_boot(): void {",
			"\t\t// add_shortcode( 'example', array( \$this, 'render' ) );",
			"\t}",
		)
	),
);
