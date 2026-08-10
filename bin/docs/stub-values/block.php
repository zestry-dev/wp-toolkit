<?php

/**
 * DevTools: sample values for `stubs/block/`.
 *
 * A block is the one type whose stubs branch on the answers given, so these
 * values pick a variant: a **dynamic** block with no front-end script. That
 * matches the `{plugin-slug}-php` field below, which is what makes a block
 * dynamic, so the metadata and the registration on the page agree.
 */

declare( strict_types=1 );

return array(
	'{{pascal}}'           => 'Example',

	// `index` holds JSX, so under TypeScript it is `.tsx` -- `tsc` rejects JSX
	// in a `.ts` file. `view` has none, so it stays `.ts`.
	'{{editor_extension}}' => 'tsx',
	'{{script_extension}}' => 'ts',

	// Nothing: `--view=none`, and the field `--dynamic` adds goes under
	// `supports` rather than here.
	'{{extra_metadata}}'   => '',

	// What `--dynamic` adds: the plugin's own field, naming the file that
	// returns the Block subclass. Under `supports`, which is the one object the
	// official block schema allows extra keys on -- a root-level key is refused
	// by every editor reading the `$schema` line the stub declares. Not
	// WordPress's `render`, which takes precedence and which this module
	// deliberately leaves to WordPress. (`supports.interactivity` would join it
	// here for `--view=module`.)
	'{{extra_supports}}'   => ",\n\t\t\"acme-plugin-php\": \"file:./block.php\"",

	// A dynamic block's markup comes from PHP on every request, so `save`
	// persists only the inner blocks -- which is what reaches `render()` as
	// its `$content`. A static block would instead save its own markup here.
	'{{save_import}}'      => "import { InnerBlocks } from '@wordpress/block-editor';\n",
	'{{save}}'             => "\t// Rendered in PHP by block.php. Only the inner blocks are saved,\n"
		. "\t// and they arrive there as the render() method's \$content.\n"
		. "\tsave: () => <InnerBlocks.Content />,",
);
