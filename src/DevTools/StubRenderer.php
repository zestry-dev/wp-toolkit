<?php

/**
 * DevTools: stub rendering engine
 */

declare( strict_types=1 );

namespace Zestry\WPToolkit\DevTools;

// Loaded by WordPress, never requested directly.
\defined( 'ABSPATH' ) || exit;

use Zestry\WPToolkit\Kernel\Helpers\Str;
use Zestry\WPToolkit\Kernel\Abstracts\Service;

/**
 * Renders a `wp zestry make` stub file into a new, ready-to-edit PHP file.
 *
 * Unlike {@see Copier} (which rewrites real, already-valid PHP source with a
 * `token_get_all()`-based parser), a stub is not valid PHP on its own — it
 * contains `{{placeholder}}` tokens a real PHP tokenizer would choke on — so
 * rendering here is a plain string substitution instead. This is
 * deliberately not shared machinery with Copier: a stub is authored content
 * being filled in, not existing source being namespace-rewritten.
 */
class StubRenderer extends Service {

	/**
	 * Render a stub's contents, substituting every `{{key}}` placeholder.
	 *
	 * @param string                $stub_path Absolute path to the `.stub` file.
	 * @param array<string, string> $values    Replacement values, keyed by placeholder name (without the `{{ }}`).
	 * @return string The rendered PHP source.
	 * @throws \InvalidArgumentException When the stub file does not exist.
	 */
	public function render( string $stub_path, array $values ): string {
		if ( ! \is_file( $stub_path ) ) {
			throw new \InvalidArgumentException( 'Stub file does not exist: ' . $stub_path );
		}

		$contents = (string) \file_get_contents( $stub_path );

		foreach ( $values as $key => $value ) {
			$contents = \str_replace( '{{' . $key . '}}', $value, $contents );
		}

		return $contents;
	}

	/**
	 * Convert a kebab-case or snake_case name into a human-readable title.
	 *
	 * Used to fill a stub's `{{title}}` placeholder from the same local name
	 * used for the filename, e.g. `send-welcome-email` -> `Send Welcome Email`.
	 *
	 * @param string $name The local name, e.g. `send-welcome-email`.
	 * @return string The title-cased form.
	 */
	public function to_title( string $name ): string {
		return Str::headline( $name );
	}

	/**
	 * Normalize a name into the lowercase, hyphenated form a slug has to take.
	 *
	 * An npm scope, a block namespace and an ability name accept the same narrow
	 * character set, and a plugin slug or a name typed on the command line need
	 * not already satisfy it. {@see \Zestry\WPToolkit\Kernel\Helpers\Str::slug()} does the work,
	 * so a name reduced for a stub and a name reduced anywhere else agree.
	 *
	 * @param string $name The name to normalize.
	 * @return string The slug form.
	 */
	public function to_slug( string $name ): string {
		return Str::slug( $name );
	}

	/**
	 * Convert a kebab-case or snake_case name into a camelCase identifier.
	 *
	 * A JavaScript global has to be a valid identifier, and neither a hyphen nor
	 * a leading digit is one, so `my-plugin` becomes `myPlugin`. Anything else
	 * outside `[A-Za-z0-9_$]` is dropped rather than escaped -- a name that
	 * needed escaping would be a poor global whatever it was turned into.
	 *
	 * @param string $name The local name, e.g. `my-plugin`.
	 * @return string The camelCase form.
	 */
	public function to_camel( string $name ): string {
		return Str::camel( $name );
	}
}
