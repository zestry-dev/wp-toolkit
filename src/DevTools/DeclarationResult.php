<?php

/**
 * DevTools: bootstrap.php declaration outcome
 */

declare( strict_types=1 );

namespace Zestry\WPToolkit\DevTools;

/**
 * Why {@see BootstrapFile::declare_modules()} did or did not write.
 *
 * A boolean cannot carry this: "every module was already declared" and "the
 * file could not be parsed" both mean nothing was written, but the first is
 * the ordinary result of re-running a command and the second is a failure the
 * consumer has to act on. Reported as one value so a caller stays silent for
 * the first and speaks up for the second.
 */
enum DeclarationResult {

	/**
	 * Entries were appended and the file was written.
	 */
	case Declared;

	/**
	 * Every class was already declared, so there was nothing to write.
	 */
	case AlreadyDeclared;

	/**
	 * There is no `bootstrap.php` to append to.
	 */
	case NoFile;

	/**
	 * The file does not end in the returned array every generated one ends
	 * with, so appending would have to guess where an entry belongs.
	 */
	case Unrecognized;

	/**
	 * The file matched but could not be written back.
	 */
	case NotWritable;
}
