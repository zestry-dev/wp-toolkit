<?php

/**
 * Core API: File-discovery exception class
 */

declare( strict_types=1 );

namespace Zestry\WPToolkit\Kernel\Exceptions;

// Loaded by WordPress, never requested directly.
\defined( 'ABSPATH' ) || exit;

/**
 * Thrown when a module's file-based discovery cannot proceed.
 *
 * Catch this to handle any malformed discovery layout, in any module that reads
 * files, without also catching unrelated failures. It arrives in five shapes:
 *
 * - **A discovered file returned the wrong thing.** Usually a missing `return`,
 *   since `require` yields `1` for a file that returns nothing.
 * - **A root directory named by a `set_*_root()` call does not exist.** A
 *   *default* root that is absent is not an error: the module discovers nothing
 *   and says nothing.
 * - **Two files resolve to one registered name**, which only happens where the
 *   name is built from more than the filename -- `reports.php` and
 *   `reports/index.php` are two paths meaning one admin page.
 * - **A filename the destination could not carry**: an admin page slug a URL
 *   would have to encode, or an ability name outside WordPress's `[a-z0-9-]`.
 * - **WordPress refused the registration**, for the calls that report a refusal
 *   by returning something falsy rather than by saying anything.
 *
 * **A name is refused, never repaired.** Neither naming failure above rewrites
 * your filename into something acceptable: a name spelled for you is a name you
 * cannot find again, and the file would keep looking like working code. Rename
 * the file -- `wp zt make` writes an acceptable name in the first place, and
 * says when it had to.
 *
 * Extends {@see ModuleException}, and therefore `\RuntimeException`: a discovery
 * failure depends on which files exist on disk and what they return at boot, not
 * on an argument you passed. That also puts it in the same hierarchy as the
 * registration, resolution and boot failures it happens alongside, so one
 * `catch ( ModuleException $e )` around boot covers every way a module can fail
 * to come up.
 *
 * Writing a discovery module of your own? {@see missing_root()} and
 * {@see name_collision()} raise the same two sentences the built-in modules do,
 * so yours fails the way the rest of the plugin already does.
 *
 * @rationale
 * The SPL argument for `\RuntimeException` over `\InvalidArgumentException`:
 * the latter means "an argument is not of the expected type", but nothing is
 * passed to these guards -- the offending value is what `require` returned. And
 * its parent `\LogicException` is documented for errors detectable by reading
 * the code, whereas which files exist on disk is the definition of a runtime
 * error. Bad arguments that are not discovery -- an unsafe path, an unknown
 * schedule name, a placeholder that binds to nothing -- stay
 * `\InvalidArgumentException`.
 */
class DiscoveryException extends ModuleException {

	/**
	 * The message every module raises when WordPress refuses a registration.
	 *
	 * Most of WordPress's `register_*` functions report failure by returning
	 * something falsy rather than by throwing -- `false`, `null`, a `WP_Error`
	 * -- so an unchecked call leaves the thing simply absent. No post type, no
	 * route, no meta field, and nothing said anywhere: the feature reads as
	 * broken code rather than as a refused registration, which is the most
	 * expensive way for this to fail.
	 *
	 * **Only where WordPress is silent.** `register_post_type()`,
	 * `register_taxonomy()` and `register_block_type()` refuse without saying
	 * anything, so a module that does not check leaves the feature absent and
	 * unexplained. `register_meta()`, `register_rest_route()` and
	 * `wp_register_ability()` call `_doing_it_wrong()` on every refusal they can
	 * make, so those are left to say it themselves rather than turning a notice
	 * WordPress chose into a fatal that takes the site down.
	 *
	 * @param string $kind   What was being registered, e.g. `post type`.
	 * @param string $name   The name, which is the file's name.
	 * @param string $reason WordPress's own message, when it gave one.
	 * @return self
	 *
	 * @internal
	 */
	public static function registration_refused( string $kind, string $name, string $reason = '' ): self {
		return new self(
			\sprintf(
				'WordPress refused to register the %1$s "%2$s" from %2$s.php.%3$s',
				$kind,
				$name,
				'' === $reason ? ' It gave no reason, which usually means the name is not one it accepts.' : ' ' . $reason
			)
		);
	}

	/**
	 * The message every module raises when two files claim one name.
	 *
	 * A discovered file's name is what it registers as, read exactly as written,
	 * so two files usually cannot collide. Where they can is a destination whose
	 * name is built from more than the filename: `dashboard.php` and
	 * `dashboard/index.php` are two paths meaning one admin page, and only one of
	 * them can be it.
	 *
	 * Refused rather than resolved, because either resolution is wrong. Keeping
	 * the first leaves the second registered against nothing; keeping the last
	 * makes the answer depend on directory order. Neither says anything, and the
	 * file that lost still looks like working code.
	 *
	 * @param string $label What the module discovers, e.g. `commands`.
	 * @param string $name  The name both files resolved to.
	 * @param string $first The file that claimed it.
	 * @param string $other The file that collided with it.
	 * @return self
	 */
	public static function name_collision( string $label, string $name, string $first, string $other ): self {
		return new self(
			\sprintf(
				'Two %1$s resolve to the name "%2$s": %3$s and %4$s. Only one of them can be it,'
					. ' so rename the other.',
				$label,
				$name,
				$first,
				$other
			)
		);
	}

	/**
	 * The message every module raises for a root it was told to read.
	 *
	 * Reached only when a `set_*_root()` call named the directory. A *default*
	 * root that does not exist discovers nothing and says nothing -- adding a
	 * module before writing its first file is ordinary -- so arriving here means
	 * someone asked for this path by name and it is not there. That is what
	 * makes a next step worth stating: the setter's argument is wrong, or the
	 * directory has yet to be made.
	 *
	 * @param string $what   What the module discovers, e.g. `Commands`.
	 * @param string $path   The absolute path it looked in.
	 * @param string $setter The setter that named it, e.g. `set_commands_root()`.
	 * @return self
	 */
	public static function missing_root( string $what, string $path, string $setter ): self {
		return new self(
			\sprintf(
				'%s root directory does not exist: %s. `%s` named it, so create that directory or correct'
					. ' the path in the initializer. (A default root that is absent is not an error.)',
				$what,
				$path,
				$setter
			)
		);
	}

	/**
	 * The message raised for an ability whose name WordPress would not accept.
	 *
	 * The abilities registry matches `^[a-z0-9-]+/[a-z0-9-]+$` and refuses
	 * anything else, so `abilities/create_order.php` asks to register a name that
	 * cannot exist. The refusal is `_doing_it_wrong()` inside WordPress, arriving
	 * long after boot and naming no file.
	 *
	 * Refused rather than rewritten, for the same reason as everywhere else here:
	 * a name spelled for you is a name you cannot find again. `$abilities->run()`
	 * takes the local name, and it has to be the one on disk.
	 *
	 * @param string $file The discovered path, relative to the abilities root.
	 * @param string $name The full name it asked to register under.
	 * @return self
	 *
	 * @internal
	 */
	public static function unregistrable_ability_name( string $file, string $name ): self {
		return new self(
			\sprintf(
				'The ability "%1$s" would register as "%2$s", which WordPress refuses: an ability name'
					. ' takes only lowercase letters, digits and dashes on either side of the `/`.'
					. ' Rename the file.',
				$file,
				$name
			)
		);
	}

	/**
	 * The message raised for a page whose slug cannot survive a URL.
	 *
	 * An admin page's slug is what WordPress puts after `?page=`, and what it
	 * appends to the `{parent}_page_` hook it fires. A character a URL has to
	 * encode does not survive that round trip: `settings&more.php` asks for
	 * `?page={slug}-settings&more`, where the `&` ends the query argument and the
	 * page answers with a permissions error instead of itself.
	 *
	 * Refused rather than rewritten. Stripping the character would register a
	 * page under a name nobody typed, and the file would keep looking like
	 * working code -- rename the file and the slug is yours again.
	 *
	 * @param string $file The discovered path, relative to the pages root.
	 * @param string $slug The slug it asked to register under.
	 * @return self
	 *
	 * @internal
	 */
	public static function unsafe_page_slug( string $file, string $slug ): self {
		return new self(
			\sprintf(
				'The admin page "%1$s" would register as "%2$s", which cannot appear in a URL as'
					. ' `?page=%2$s`. Rename the file using only letters, digits, `-`, `_`, `.` or `~`.',
				$file,
				$slug
			)
		);
	}
}
