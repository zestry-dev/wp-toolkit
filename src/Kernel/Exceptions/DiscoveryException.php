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
 * - **Two files resolve to one registered name**, which only happens where the
 *   name is built from more than the filename -- `reports.php` and
 *   `reports/index.php` are two paths meaning one admin page.
 * - **A filename the destination could not carry**: an admin page slug a URL
 *   would have to encode, an ability name outside WordPress's `[a-z0-9-]`, or an
 *   icon name outside its own.
 * - **A file WordPress would quietly alter rather than refuse**: an SVG icon
 *   drawn with anything its sanitizer removes, which registers and then renders
 *   as less than it is.
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
 * Writing a discovery module of your own? {@see name_collision()} raises the same
 * sentence the built-in modules do, so yours fails the way the rest of the plugin
 * already does.
 *
 * @rationale
 * `\RuntimeException` over `\InvalidArgumentException`: nothing is passed to
 * these guards -- the offending value is what `require` returned -- and
 * `\LogicException` is documented for errors detectable by reading the code,
 * whereas which files exist on disk is the definition of a runtime error. Bad
 * arguments that are not discovery -- an unsafe path, an unknown schedule name,
 * a placeholder that binds to nothing -- stay `\InvalidArgumentException`.
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
	 * The message raised for an ability whose name WordPress would not accept.
	 *
	 * The abilities registry matches `^[a-z0-9-]+/[a-z0-9-]+$` and refuses
	 * anything else, so `resources/abilities/create_order.php` asks to register a name that
	 * cannot exist. The refusal is `_doing_it_wrong()` inside WordPress, arriving
	 * long after boot and naming no file.
	 *
	 * `$abilities->run()` takes the local name, so it has to be the one on disk.
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

	/**
	 * The message raised for an icon whose name WordPress would not accept.
	 *
	 * Both icon registries match `^[a-z0-9]([a-z0-9_-]*[a-z0-9])?$`, so
	 * `resources/svg-icons/Arrow Right.svg` asks to register a name that cannot exist. The
	 * refusal is `_doing_it_wrong()` inside WordPress, arriving on `init` and
	 * naming no file.
	 *
	 * Underscores are allowed here, unlike in an ability name -- so this fires for
	 * capitals, spaces and punctuation rather than for the separator you chose.
	 *
	 * @param string $file The discovered path, relative to the icons root.
	 * @param string $name The full name it asked to register under.
	 * @return self
	 *
	 * @internal
	 */
	public static function unregistrable_icon_name( string $file, string $name ): self {
		return new self(
			\sprintf(
				'The icon "%1$s" would register as "%2$s", which WordPress refuses: an icon name takes'
					. ' lowercase letters, digits, dashes and underscores, and must start and end with a'
					. ' letter or digit. Rename the file.',
				$file,
				$name
			)
		);
	}

	/**
	 * The message raised for an icon template that rendered nothing.
	 *
	 * An icon component echoes its SVG and returns its label, so a template
	 * producing no output has said what it is called and drawn nothing. Usually a
	 * `return` above the markup rather than below it, which ends the file before
	 * the picture.
	 *
	 * @param string $file The discovered path, relative to the icons root.
	 * @return self
	 *
	 * @internal
	 */
	public static function empty_icon_template( string $file ): self {
		return new self(
			\sprintf(
				'The icon "%s" rendered nothing. An icon template echoes its SVG and returns its label, so'
					. ' check the `return` sits below the markup rather than above it.',
				$file
			)
		);
	}

	/**
	 * The message raised for an icon filed under a collection nobody registered.
	 *
	 * WordPress groups the editor's picker by collection, and one that does not
	 * exist has no group to appear in. The collection is checked against the
	 * registry rather than against what this plugin declared, so `core` and a
	 * collection another plugin registered are both accepted -- arriving here
	 * means nothing anywhere registered this slug.
	 *
	 * @param string $file       The discovered path, relative to the icons root.
	 * @param string $collection The collection it asked for.
	 * @return self
	 *
	 * @internal
	 */
	public static function unknown_icon_collection( string $file, string $collection ): self {
		return new self(
			\sprintf(
				'The icon "%1$s" belongs to the collection "%2$s", which nothing has registered. Declare it'
					. ' with `add_collections()` from the module\'s initializer, or correct the name.',
				$file,
				$collection
			)
		);
	}

	/**
	 * The message raised for an icon WordPress would sanitize down.
	 *
	 * WordPress runs every icon through `wp_kses()` with a list allowing `<svg>`,
	 * `<path>` and `<polygon>` and a handful of attributes on each. Anything else
	 * is removed and the rest kept, so the icon registers, renders, and is wrong
	 * -- usually invisible, since an outline icon loses `stroke` and keeps
	 * `fill="none"`. WordPress says something only when the file sanitizes away
	 * entirely.
	 *
	 * Raised while the plugin's own `{SLUG}_DEBUG` is on -- `wp zt debug on` --
	 * which is where a picture that renders blank is still cheap to fix. Redraw the
	 * icon with filled paths; most vector editors offer this as "outline stroke" or
	 * "expand".
	 *
	 * @param string   $file The discovered path, relative to the icons root.
	 * @param string[] $lost The element and attribute names that would not survive.
	 * @return self
	 *
	 * @internal
	 */
	public static function stripped_icon_markup( string $file, array $lost ): self {
		return new self(
			\sprintf(
				'WordPress would remove %1$s from the icon "%2$s", which leaves it rendering as less than'
					. ' it is. Only `<svg>`, `<path>` and `<polygon>` survive, with a few attributes each.',
				\implode( ', ', $lost ),
				$file
			)
		);
	}
}
