<?php

/**
 * DevTools: sample values for `make test`, PHP and JavaScript alike.
 */

declare( strict_types=1 );

/*
 * One test of each kind, mirroring a real source file. The PHP class sits in
 * the namespace its folder gives it, and the JavaScript one imports its script
 * by a path relative to where the test lands.
 */
return array(
	'{{class_name}}'     => 'Book',
	'{{test_namespace}}' => 'Acme\\Plugin\\Tests\\PostTypes',
	'{{source}}'         => 'src/shared/collections/index.ts',
	'{{import_path}}'    => '../../../../src/shared/collections/index',
);
