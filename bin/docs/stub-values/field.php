<?php

/**
 * DevTools: sample values for `stubs/field.php.stub`.
 */

declare( strict_types=1 );

return array(
	// Rendered from the answer to `make field`'s subtypes prompt, already
	// whole array literal, so the no-subtypes case closes up cleanly. The post
	// type matches the sample `post-type.php.stub` renders with.
	'{{subtypes}}'    => "array( 'book' )",
	// A MetaType case name, so the stub reads `MetaType::Post`.
	'{{object_type}}' => 'Post',
);
