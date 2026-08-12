<?php

/**
 * DevTools: sample values for `stubs/page.php.stub`.
 */

declare( strict_types=1 );

/*
 * What the generated `render()` and the comment above it read as, which
 * `--no-view` decides. The page shows the default -- a template written
 * alongside the class -- because that is what `wp zt make page settings`
 * produces and what the rest of the page describes.
 */
return array(
	'{{render_note}}' => implode(
		"\n",
		array(
			"\t// The markup lives in views/admin-pages/settings.php, generated alongside",
			"\t// this file. The template gets exactly what is named here and nothing else",
			"\t// of this page, so its inputs are readable without opening it. Add your own",
			"\t// alongside these.",
			"\t//",
			"\t// Echoing markup from here works for something tiny, and stops working",
			"\t// sooner than it looks: an admin page grows a table, then a notice, then a",
			"\t// second form.",
		)
	),
	'{{render_body}}' => implode(
		"\n",
		array(
			"\t\t\$this->view(",
			"\t\t\t'admin-pages/settings',",
			"\t\t\tarray(",
			"\t\t\t\t'title'   => \$this->title(),",
			"\t\t\t\t'action'  => \$this->get_page_url(),",
			"\t\t\t\t'nonce'   => \$this->get_nonce_action(),",
			"\t\t\t\t// Set by the redirect in handle_submit(). Reading a query",
			"\t\t\t\t// argument to decide what to show needs no nonce -- nothing is",
			"\t\t\t\t// being acted on -- which is what the ignore says.",
			"\t\t\t\t'updated' => isset( \$_GET['updated'] ), // phpcs:ignore WordPress.Security.NonceVerification.Recommended",
			"\t\t\t)",
			"\t\t);",
		)
	),
);
