<?php

/**
 * Fields API: Field base class
 */

declare( strict_types=1 );

namespace Zestry\WPToolkit\Modules\Fields;

// Loaded by WordPress, never requested directly.
\defined( 'ABSPATH' ) || exit;

use Zestry\WPToolkit\Kernel\Contracts\PluginAware;
use Zestry\WPToolkit\Kernel\Traits\WithPlugin;
use Zestry\WPToolkit\Kernel\Traits\WithEnablement;

/**
 * One piece of post meta, registered with a type and a schema.
 *
 * A file in `fields/` returns one of these. It names what it attaches to, so it
 * works the same for a post type you registered and for one you did not — and
 * the same again for term, user and comment meta.
 *
 * Registering meta rather than just calling `update_post_meta()` is what gives
 * it a type, a sanitiser, a permission check and a place in the REST API. The
 * block editor reads and writes meta over REST, so a registered field is one
 * your editor JavaScript can bind to; an unregistered one is invisible to it.
 *
 * **A field holds one value per post.** For several, return `array` from
 * {@see type()} and store them in one row — the shape REST and the block editor
 * expect. To *find* posts by one of those values, use a taxonomy: meta stored
 * as an array cannot be searched by its individual entries.
 *
 * @stub field.php.stub
 *
 * @example A field
 * ```
 * // fields/acme_rating.php
 * namespace Acme\Plugin\Fields;
 *
 * use Acme\Plugin\Core\Modules\Fields\Field;
 *
 * return new class extends Field {
 *
 *     public function subtypes(): array {
 *         return array( 'book' );
 *     }
 *
 *     public function type(): string {
 *         return 'integer';
 *     }
 *
 *     public function sanitize( mixed $value ): mixed {
 *         return max( 1, min( 5, (int) $value ) );
 *     }
 * };
 * ```
 *
 * > [!IMPORTANT]
 * > **This name is not prefixed with your plugin slug, so choose it as though
 * > every plugin on the site can see it — because they can.** A meta key is part of your REST responses, so adding a prefix for you would change your own API.
 * >
 * > Two plugins registering `rating` are the same meta key on the same post, and whichever registers
 * > second loses. Put your own prefix in the filename: `fields/acme_rating.php`.
 *
 */
abstract class Field implements PluginAware {

	use WithPlugin;
	use WithEnablement;

	/**
	 * Prevent direct construction from bypassing plugin initialization.
	 *
	 * @return void
	 */
	final public function __construct() {}

	/**
	 * The meta key this field is stored under.
	 *
	 * Your filename, verbatim: `fields/acme_rating.php` stores under
	 * `acme_rating`. Post meta keys are shared across every plugin on a post, so
	 * name the file with a prefix when the field attaches to a post type you do
	 * not own.
	 *
	 * A leading underscore works, so `fields/_acme_secret.php` stores under
	 * `_acme_secret` — WordPress's mark for protected meta. The filename is the
	 * key, exactly as written, because the key is what stored rows are found by.
	 * {@see is_protected()} is the other way to say the same thing, for a key
	 * whose spelling you would rather choose freely. Override this only for a
	 * key a filename genuinely cannot hold.
	 *
	 * @return string
	 */
	public function key(): string {
		return $this->fields()->get_key_of( $this );
	}

	/**
	 * What kind of object this field is stored against.
	 *
	 * @return MetaType
	 */
	public function object_type(): MetaType {
		return MetaType::Post;
	}

	/**
	 * The subtypes this field attaches to, within its object type.
	 *
	 * Post type names for post meta, taxonomy names for term meta, comment types
	 * for comment meta. Users have no subtypes.
	 *
	 * An empty list attaches the field to **every** subtype — every post type,
	 * every taxonomy — which is what you want for user meta and rarely what you
	 * want for post meta.
	 *
	 * @return string[]
	 */
	public function subtypes(): array {
		return array();
	}

	/**
	 * The value's type.
	 *
	 * One of `string`, `boolean`, `integer`, `number`, `array` or `object`.
	 * An `array` or `object` shown in REST needs a schema — see
	 * {@see is_shown_in_rest()}.
	 *
	 * This describes the REST schema; it does not cast anything on the PHP side.
	 * `get_post_meta()` still hands back a string for a field typed `integer`,
	 * so cast at the point you read it.
	 *
	 * Use `array` to hold several values. A field is always one value per post
	 * here, so many values means one array in one row rather than many rows —
	 * which is also what REST wants, given a schema.
	 *
	 * @return string
	 */
	public function type(): string {
		return 'string';
	}

	/**
	 * What this field is for, shown in the REST schema.
	 *
	 * @return string
	 */
	public function description(): string {
		return '';
	}

	/**
	 * The value returned when nothing is stored.
	 *
	 * Null means no default, which is not the same as an empty string: with no
	 * default, reading an unset key gives `''` for a single value.
	 *
	 * @return mixed
	 */
	public function default_value(): mixed {
		return null;
	}

	/**
	 * Whether the field appears in the REST API.
	 *
	 * **On by default, where WordPress defaults it off.** The block editor reads
	 * and writes meta over REST, so a field kept out of it cannot be edited
	 * there at all. Turn it off for anything a reader of the post should not
	 * see: a field in REST is readable by anyone who can read the post.
	 *
	 * Two things that make a field silently invisible rather than erroring:
	 *
	 * - **The post type must be in REST too.** A `PostType` here is by default;
	 *   `post` and `page` are; another plugin's may not be.
	 * - **An `array` or `object` type needs a schema**, given as an array here
	 *   with a `schema` key, or WordPress refuses to register it.
	 *
	 * @return bool|array<string, mixed> True, false, or an array carrying `schema`.
	 */
	public function is_shown_in_rest(): bool|array {
		return true;
	}

	/**
	 * Whether WordPress should treat this field as protected meta.
	 *
	 * WordPress answers this by looking for a leading underscore and nothing
	 * else, which makes a property of a filename. This says it out loud instead,
	 * so `submission-payload` and `_submission_payload` can be the same decision
	 * spelled the way you prefer.
	 *
	 * **It does not decide who may write the field.** That is
	 * {@see can_edit()}, and every field registers with an `auth_callback` of
	 * its own that calls it -- so the underscore's most dangerous effect, where
	 * an unprotected key defaults to writable by anyone who can edit the object,
	 * never applies to a field written this way. What protection still decides:
	 *
	 * - **The Custom Fields panel**, which lists unprotected keys only. A
	 *   field you render yourself does not want a second, raw editor for the
	 *   same value sitting under it.
	 * - **Block bindings**, which refuse a protected key as a source. Mark a
	 *   field protected and no block can bind to it.
	 *
	 * > [!NOTE]
	 * > **The answer is per object type, not per subtype.** WordPress hands the
	 * > filter `post`, `term`, `user` or `comment` and never `page` or `product`
	 * > -- so a field declared on `page` alone still answers for that key across
	 * > every post type. A key is one key; only the subtypes it is *registered*
	 * > against are narrower.
	 *
	 * `null`, the default, defers to WordPress -- so a key already named with a
	 * leading underscore stays protected without saying so twice.
	 *
	 * @return bool|null True or false to decide, null to let the key's name decide.
	 */
	public function is_protected(): ?bool {
		return null;
	}

	/**
	 * Whether the value is saved with each revision of the post.
	 *
	 * Off by default. Turn it on for a field that is part of the content — a
	 * subtitle, a summary — so it reverts with everything else when someone
	 * restores a revision. Leave it off for anything incidental to the post: a
	 * view count saved into every revision is noise.
	 *
	 * **The post type must support `revisions`, or the field does not register
	 * at all.** Not "revisions quietly do nothing" — `register_post_meta()`
	 * returns false and the key is never registered, so nothing about it works.
	 * A `PostType` here supports `title` and `editor` by default, so add
	 * `revisions` to its `supports()` before turning this on.
	 *
	 * @return bool
	 */
	public function has_revisions(): bool {
		return false;
	}

	/**
	 * Whether a value is acceptable at all.
	 *
	 * Returning false stops the write. This applies to **every** write —
	 * `update_post_meta()`, the REST API, a meta box — because it runs on the
	 * filter WordPress offers for exactly this, not inside one accessor.
	 *
	 *     public function validate( mixed $value ): bool {
	 *         return is_numeric( $value ) && (int) $value >= 1 && (int) $value <= 5;
	 *     }
	 *
	 * **The value has already been through {@see sanitize()} by the time this
	 * sees it.** That is WordPress's order for meta, and it is the reverse of
	 * how it treats a REST parameter: a request argument is validated and then
	 * sanitised, meta is sanitised and then offered for a veto.
	 *
	 * So a sanitiser that coerces leaves nothing to reject: clamp 99 to 5 in
	 * `sanitize()` and this is asked about 5. Decide which job each does — coerce
	 * a value into shape, or refuse it — rather than both.
	 *
	 * @param mixed $value The incoming value.
	 * @return bool True to accept it.
	 */
	public function validate( mixed $value ): bool {
		return true;
	}

	/**
	 * Clean a value on its way into the database.
	 *
	 * Runs for every write, including through REST, and before
	 * {@see validate()} — WordPress calls it itself, from inside the write. The
	 * default returns the value untouched.
	 *
	 * @param mixed $value The incoming value.
	 * @return mixed The value to store.
	 */
	public function sanitize( mixed $value ): mixed {
		return $value;
	}

	/**
	 * Whether the current user may write this field on a given post.
	 *
	 * Checked on top of the post's own edit permission, never instead of it.
	 *
	 * The default asks whether they can edit that post, which is almost always
	 * the right answer — and is deliberately not what WordPress does. Core
	 * decides from the key's name: a key starting with `_` is refused to
	 * everyone, so prefixing a key to hide it from the classic custom-fields box
	 * also stops the block editor saving it, with nothing to say why.
	 *
	 * @param int $post_id The post being edited.
	 * @return bool
	 */
	public function can_edit( int $post_id ): bool {
		return \current_user_can( 'edit_post', $post_id );
	}

	/**
	 * The arguments WordPress registers this field with.
	 *
	 * @return array<string, mixed>
	 *
	 * @internal
	 */
	final public function get_args(): array {
		$args = array(
			'type'              => $this->type(),
			'description'       => $this->description(),
			'single'            => true,
			'show_in_rest'      => $this->is_shown_in_rest(),
			'revisions_enabled' => $this->has_revisions(),
			'sanitize_callback' => function ( $value ) {
				return $this->sanitize( $value );
			},
			'auth_callback'     => function ( $allowed, $meta_key, $post_id ) {
				return $this->can_edit( (int) $post_id );
			},
		);

		// Passing a null default is not the same as passing none: WordPress
		// treats the key as present and returns null where it would otherwise
		// give the type's own empty value.
		if ( null !== $this->default_value() ) {
			$args['default'] = $this->default_value();
		}

		return $args;
	}

	/**
	 * The module that discovered this field.
	 *
	 * @return Fields
	 */
	final protected function fields(): Fields {
		return $this->get_plugin()->get( Fields::class );
	}
}
