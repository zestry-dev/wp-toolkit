<?php

/**
 * Fields API: Fields module
 */

declare( strict_types=1 );

namespace Zestry\WPToolkit\Modules\Fields;

// Loaded by WordPress, never requested directly.
\defined( 'ABSPATH' ) || exit;

use Zestry\WPToolkit\Kernel\Contracts\Bootable;
use Zestry\WPToolkit\Kernel\Abstracts\Module;
use Zestry\WPToolkit\Kernel\Exceptions\DiscoveryException;
use Zestry\WPToolkit\Kernel\Traits\WithFolderWalker;
use Zestry\WPToolkit\Modules\Path;

/**
 * Registers post meta from files, with types, sanitisers and permissions.
 *
 * A file in `resources/fields/` returns a {@see Field} naming the post types it attaches
 * to — so a field on your own post type and a field on core's `post` are written
 * the same way, in the same place.
 *
 * `wp zt make field` files each one under `{object-type}/{subtype}/`, so the
 * directory reads as an index of what is stored where. **Nothing reads those
 * folders** — the filename is the key and {@see Field::subtypes()} is what the
 * field attaches to — so rearrange them whenever the grouping stops helping.
 * Two folders may hold the same filename, which is how one key holds an integer
 * on `book` and a string on `movie`.
 *
 * Registering meta is what turns a bare `update_post_meta()` key into something
 * typed, sanitised, permission-checked and visible to the block editor, which
 * reads and writes meta over REST.
 *
 * **A field holds one value per post.** For several, store an array: one row,
 * which is the shape REST and the block editor expect. You never have to ask
 * whether a key holds one value or many, because it always holds one.
 *
 * That costs you one thing — querying. `meta_query` matches a single row's
 * value, so a key spread over many rows can be searched by any one of them,
 * while an array in one row can only be matched with `LIKE`, which is neither
 * indexed nor reliable against serialised data. To find posts by one of several
 * values, use a taxonomy: see {@see \Zestry\WPToolkit\Modules\PostTypes\Taxonomy}, which
 * is indexed, cached and built for exactly that.
 *
 * ## Reading and writing
 *
 * {@see get()}, {@see has()}, {@see set()} and {@see delete()} work on **the
 * fields this plugin registers**, and refuse anything else — a key no file
 * declares, a key whose file switched itself off, and a key declared for some
 * other post type than the one you handed them. That refusal is the point: a
 * mistyped key handed to `get_post_meta()` returns `''`, which looks exactly
 * like a field nobody has filled in.
 *
 * **A meta key is owned per subtype**, which is how WordPress registers one and
 * why these take the object rather than the key alone: they ask
 * `get_object_subtype()` what they are holding, and look the key up for *that*.
 * A field naming `book` is simply not found for a movie — where the key may
 * well be another plugin's, and where writing it would store a value nothing
 * here sanitises or validates.
 *
 * To list what a plugin declares rather than what it registers — a settings
 * screen showing which features are available to switch on — use
 * {@see get_all_fields()} or {@see get_fields_of()}, which include the
 * switched-off ones.
 *
 * They are not a general post-meta helper: reading meta needs to know whether
 * the key holds one value or many, and only a registration says.
 *
 * So unregistered meta goes through `get_post_meta()`, where you supply that
 * knowledge yourself. WordPress's own classic keys are mostly unregistered and
 * have better functions still — `get_post_thumbnail_id()` rather than
 * `_thumbnail_id`, `get_page_template_slug()` rather than `_wp_page_template`.
 *
 * Nothing here renders a form. A registered field is editable in the block
 * editor through its REST exposure, and that is the path that needs no markup.
 * To put one on a classic editor screen instead, add the `meta-boxes` module: a
 * `MetaBox` names the keys its form submits, and each is read, unslashed and
 * written back through this module, so the field's `validate()` and `sanitize()`
 * still apply.
 *
 * @example A field
 * ```
 * // resources/fields/post/book/acme_rating.php -- the filename is the meta key
 * return new class extends Field {
 *
 *     public function subtypes(): array {
 *         return array( 'book' );
 *     }
 *
 *     public function type(): string {
 *         return 'integer';
 *     }
 * };
 * ```
 *
 *
 * @setup-hook init
 */
class Fields extends Module implements Bootable {

	use WithFolderWalker;

	/**
	 * Where fields are discovered, relative to the plugin root.
	 */
	const FIELDS_ROOT = 'resources/fields';

	/**
	 * Discovered fields keyed by filename, once the directory has been walked.
	 *
	 * Keyed by file rather than by meta key, because a field's key is by default
	 * read back out of this map — {@see get_key_of()}.
	 *
	 * @var array<string, Field>|null
	 */
	private ?array $discovered = null;

	/**
	 * Every discovered field, by object type, then subtype, then meta key.
	 *
	 * The same three levels WordPress keys its own registry by, and for the same
	 * reason: a meta key is unique only within one subtype of one object type.
	 * `acme_note` on a post and on a term are two keys in two tables, and
	 * `rating` on `book` and on `movie` are two registrations with a type and a
	 * schema each — which is what WordPress stores, and what this has to be able
	 * to say.
	 *
	 * A field naming no subtypes sits under the `''` key, which is how WordPress
	 * spells "every subtype" and the only shape user meta has. A field naming
	 * several appears under each of them, exactly as it is registered several
	 * times.
	 *
	 * Reading it directly is rarely what you want — {@see get_fields_of()}
	 * resolves a subtype against the `''` bucket the way a lookup has to.
	 *
	 * Everything the directory declares, including a field whose `is_enabled()`
	 * returns false — so a screen offering to switch features on can list the
	 * ones currently switched off. Only {@see register_fields()} acts on the
	 * answer, and the value accessors refuse a key belonging to a field that is
	 * switched off.
	 *
	 * @return array<string, array<string, array<string, Field>>> Object type => subtype => meta key => instance.
	 * @throws DiscoveryException When a file returns the wrong value, or two files claim one key on one subtype.
	 */
	public function get_discovered_fields(): array {
		$fields = array();
		$claims = array();

		foreach ( $this->get_fields_by_file() as $file => $field ) {
			$type     = $field->object_type()->value;
			$key      = $field->key();
			$subtypes = $field->subtypes();

			$this->assert_subtypes_are_possible( $file, $field, $subtypes );

			// No subtype means every subtype, which is how WordPress reads an
			// empty `object_subtype` -- and the only shape user meta has.
			foreach ( array() === $subtypes ? array( '' ) : $subtypes as $subtype ) {
				$claims[ $type ][ $key ][ $subtype ][] = $file;

				$fields[ $type ][ $subtype ][ $key ] = $field;
			}
		}

		$this->assert_no_overlapping_claims( $claims );

		return $fields;
	}

	/**
	 * This field's meta key, taken from the file it was discovered in.
	 *
	 * The same reverse lookup {@see \Zestry\WPToolkit\Modules\PostTypes\PostTypes::get_post_type_of()}
	 * does, so a field named by its filename never repeats that name inside the
	 * file.
	 *
	 * The filename alone, never the folders above it: a meta key is a database
	 * column, so the folder a file sits in cannot decide what its rows are
	 * stored under.
	 *
	 * @param Field $field The instance to look up.
	 * @return string
	 * @throws \InvalidArgumentException When the instance was not discovered by this module.
	 */
	public function get_key_of( Field $field ): string {
		$path = \array_search( $field, $this->get_fields_by_file(), true );

		if ( false === $path ) {
			throw new \InvalidArgumentException(
				\sprintf( 'The given %s instance was not discovered by this Fields module.', Field::class )
			);
		}

		return \basename( $path );
	}

	/**
	 * Read a field's value from a post.
	 *
	 * Two things this does that `get_post_meta()` cannot. It always reads a
	 * single value, because a field here always holds one — with the bare
	 * function, forgetting its `$single` argument hands back an array where you
	 * expected a value. And it refuses a key no field declares, rather than
	 * returning `''` for a typo the way the bare function does.
	 *
	 * The post comes first, as it does in `get_post_meta()`: the other stores in
	 * this toolkit take no container because they have one, and this one does.
	 *
	 * @param int    $object_id The object to read from.
	 * @param string $key      A meta key one of your fields declares.
	 * @param mixed  $fallback Returned when the post has no value stored.
	 * @param MetaType $type     Which meta table the key lives in. Post meta by default.
	 * @return mixed The stored value, or `$fallback`.
	 * @throws \InvalidArgumentException When no field declares that key for this object's subtype.
	 */
	public function get( int $object_id, string $key, mixed $fallback = null, MetaType $type = MetaType::Post ): mixed {
		$this->get_field( $key, $type, \get_object_subtype( $type->value, $object_id ) );

		if ( ! \metadata_exists( $type->value, $object_id, $key ) ) {
			return $fallback;
		}

		return \get_metadata( $type->value, $object_id, $key, true );
	}

	/**
	 * Whether a post has a value stored for a field.
	 *
	 * Distinct from `null !== get()`, which cannot tell a stored null from a
	 * post that has never had the field set.
	 *
	 * @param int    $object_id The object to check.
	 * @param string   $key       A meta key one of your fields declares.
	 * @param MetaType $type      Which meta table the key lives in. Post meta by default.
	 * @return bool
	 * @throws \InvalidArgumentException When no field declares that key for this object's subtype.
	 */
	public function has( int $object_id, string $key, MetaType $type = MetaType::Post ): bool {
		$this->get_field( $key, $type, \get_object_subtype( $type->value, $object_id ) );

		return \metadata_exists( $type->value, $object_id, $key );
	}

	/**
	 * Write a field's value to a post.
	 *
	 * The field's `sanitize()` shapes the value and its `validate()` may then
	 * refuse it — WordPress's order for meta, applied from inside the write
	 * rather than here, so `update_post_meta()` behaves identically.
	 *
	 * **Returns a `WP_Error` when the field refuses the value**, which is the one
	 * place this differs from `Options`, `Globals` and `Transients`: those take
	 * anything, and a field is held to its own schema. Check the return with
	 * `is_wp_error()` when the value came from a request — the message names the
	 * key and what was wrong with it, so a form has something to show.
	 *
	 * A plain `false` means the write did not happen for a reason that is not a
	 * refusal: storing the value it already had is the usual one.
	 *
	 * @param int      $object_id The object to write to.
	 * @param string   $key       A meta key one of your fields declares.
	 * @param mixed    $value     The value to store.
	 * @param MetaType $type      Which meta table the key lives in. Post meta by default.
	 * @return bool|\WP_Error True once written, a `WP_Error` when the field refused the value, false when nothing was written for any other reason.
	 * @throws \InvalidArgumentException When no field declares that key for this object's subtype.
	 */
	public function set( int $object_id, string $key, mixed $value, MetaType $type = MetaType::Post ): bool|\WP_Error {
		$field = $this->get_field( $key, $type, \get_object_subtype( $type->value, $object_id ) );

		// The write is what validates, through the filter this module binds, so
		// the check lives in one place and covers update_post_meta() too.
		if ( false !== \update_metadata( $type->value, $object_id, $key, $value ) ) {
			return true;
		}

		/*
		 * Refused, or simply not written -- and the write cannot say which:
		 * WordPress casts the filter's return to a bool, so the reason the field
		 * gave was already thrown away by the time update_metadata() returned.
		 * Asking the field again is what recovers it, and only happens on the
		 * path that is about to report a failure anyway.
		 */
		$refusal = $field->validate( $field->sanitize( $value ) );

		return \is_wp_error( $refusal ) ? $refusal : false;
	}

	/**
	 * Remove a field's value from a post.
	 *
	 * Removing something that was never there is not an error.
	 *
	 * @param int    $object_id The object to remove it from.
	 * @param string   $key       A meta key one of your fields declares.
	 * @param MetaType $type      Which meta table the key lives in. Post meta by default.
	 * @return void
	 * @throws \InvalidArgumentException When no field declares that key for this object's subtype.
	 */
	public function delete( int $object_id, string $key, MetaType $type = MetaType::Post ): void {
		$this->get_field( $key, $type, \get_object_subtype( $type->value, $object_id ) );

		\delete_metadata( $type->value, $object_id, $key );
	}

	/**
	 * Every declared field, for iterating over all of them.
	 *
	 * A plain list rather than a map, because a meta key does not identify one
	 * field: two of them can share a key on different subtypes. Use
	 * {@see get_fields_of()} when you want them keyed.
	 *
	 * Includes the switched-off ones — this is enumeration, and that is what it
	 * is for. Ask an instance's `is_enabled()` to tell them apart.
	 *
	 * @return array<int, Field> Every instance, once each — a field attached to
	 *                           several subtypes is not repeated.
	 * @throws DiscoveryException When discovery fails.
	 */
	public function get_all_fields(): array {
		$fields = array();

		foreach ( $this->get_fields_by_file() as $field ) {
			$fields[] = $field;
		}

		return $fields;
	}

	/**
	 * Every field attached to one subtype, by meta key.
	 *
	 * The subtype's own fields over the ones attached to every subtype, so a
	 * `book` field named `rating` wins over a field of that key attached to all
	 * post types. That is the order WordPress picks a `sanitize_callback` and an
	 * `auth_callback` in — the subtype's if it has one, the general one
	 * otherwise. Its own `get_registered_meta_keys()` does not fall back at all,
	 * and reads one subtype's bucket exactly.
	 *
	 * The subtype is a post type name for post meta and a taxonomy name for term
	 * meta. Users and comments have one apiece, and `get_object_subtype()` is
	 * what names it for an object you are holding.
	 *
	 * Includes the switched-off ones, on the same terms as
	 * {@see get_all_fields()}.
	 *
	 * @param MetaType $type    The object type.
	 * @param string   $subtype The subtype within it. `''` asks for the fields attached to every subtype and nothing else.
	 * @return array<string, Field>
	 * @throws DiscoveryException When discovery fails.
	 */
	public function get_fields_of( MetaType $type, string $subtype = '' ): array {
		$declared = $this->get_discovered_fields()[ $type->value ] ?? array();

		return ( $declared[ $subtype ] ?? array() ) + ( $declared[''] ?? array() );
	}

	/**
	 * The field declaring a key, within one subtype of an object type.
	 *
	 * **A meta key is owned per subtype, not per plugin.** `rating` on `book` and
	 * `rating` on `movie` can be two fields with a type and a schema each, so a
	 * key alone does not say which one you mean — this is why the accessors ask
	 * `get_object_subtype()` about the object they were handed rather than
	 * looking the key up on its own. Leaving `$subtype` empty asks only about the
	 * fields attached to every subtype.
	 *
	 * A field that switched itself off is refused too, with its own message: its
	 * meta was never registered, so reading it would hand back `''` and writing
	 * it would store a value nothing knows the shape of — the two failures this
	 * method exists to prevent. Enumerate with {@see get_fields_of()} when you
	 * want everything declared.
	 *
	 * @param string   $key     The meta key.
	 * @param MetaType $type    The object type it belongs to. Post meta by default.
	 * @param string   $subtype The subtype it is attached to.
	 * @return Field
	 * @throws \InvalidArgumentException When no field of that subtype declares that key, or the field that does is switched off.
	 */
	public function get_field( string $key, MetaType $type = MetaType::Post, string $subtype = '' ): Field {
		$fields = $this->get_fields_of( $type, $subtype );

		if ( ! isset( $fields[ $key ] ) ) {
			throw new \InvalidArgumentException(
				\sprintf(
					'No %1$s field declares the meta key "%2$s" for %3$s, so this plugin does not own it there. Declared for %3$s: %4$s. A field attaches to the subtypes its subtypes() names, and owns the key on those alone. Use get_metadata() for meta belonging to WordPress or another plugin.',
					$type->value,
					$key,
					'' === $subtype ? 'every subtype' : '"' . $subtype . '"',
					array() === $fields ? 'none' : \implode( ', ', \array_keys( $fields ) )
				)
			);
		}

		if ( ! $fields[ $key ]->is_enabled() ) {
			throw new \InvalidArgumentException(
				\sprintf(
					'The %1$s field "%2$s" is declared but switched off, so its meta is not registered and reading or writing it would not do what you mean. Check is_enabled() on the file that declares it.',
					$type->value,
					$key
				)
			);
		}

		return $fields[ $key ];
	}

	/**
	 * Register every discovered field against each post type it names.
	 *
	 * @return void
	 * @throws DiscoveryException When discovery fails.
	 *
	 * @internal
	 */
	public function register_fields(): void {
		// Before the first register_meta(), because that is where WordPress
		// reads is_protected_meta() to pick a field's default auth callback --
		// a filter added afterwards would change what the Custom Fields panel
		// shows and leave the authorization already decided.
		$this->filter_protected_meta();

		foreach ( $this->get_all_fields() as $field ) {
			// Declared but switched off. Discovery lists it either way; this is
			// where it stops short of a registration, so nothing about it
			// reaches REST or the block editor.
			if ( ! $field->is_enabled() ) {
				continue;
			}

			$type     = $field->object_type()->value;
			$key      = $field->key();
			$subtypes = $field->subtypes();

			// No subtype means every subtype, which is how WordPress reads an
			// empty `object_subtype` -- and the only shape user meta has.
			foreach ( array() === $subtypes ? array( '' ) : $subtypes as $subtype ) {
				// The return is deliberately not checked. Every way
				// `register_meta()` can refuse -- a default whose type does not
				// match, revisions on an object that has none -- calls
				// `_doing_it_wrong()` first, so WordPress has already said so in
				// the reader's own error log. Throwing on top would turn a
				// notice into a fatal and take the site down for it.
				\register_meta( $type, $key, $field->get_args() + array( 'object_subtype' => $subtype ) );
			}
		}
	}

	/**
	 * Register the fields once WordPress is ready for them.
	 *
	 * On `init`, which is where post types are registered and the earliest point
	 * meta registration is read for REST.
	 *
	 * @return void
	 *
	 * @internal
	 */
	public function on_boot(): void {
		$this->register_fields();
		$this->guard_every_write();
	}

	/**
	 * Refuse a write whose value the field rejects.
	 *
	 * Leaves the write alone — returning `$check` untouched — for anything this
	 * plugin does not own. A meta key is unique only within its object type and
	 * subtype, so all three have to match before a field's rule is applied:
	 * `acme_note` on a post and `acme_note` on a term are different keys, and
	 * governing someone else's write would be worse than governing none.
	 *
	 * The subtype is resolved by the lookup rather than tested afterwards, so a
	 * field naming `book` governs a book and is simply not found for a movie —
	 * where the key may well be another plugin's.
	 *
	 * @param MetaType  $type       The object type whose filter fired.
	 * @param null|bool $check      What an earlier filter decided, if anything.
	 * @param int       $object_id  The object being written to.
	 * @param string    $meta_key   The key being written.
	 * @param mixed     $meta_value The value being written.
	 * @return null|bool False to block the write, otherwise `$check` untouched.
	 */
	private function block_invalid_write( MetaType $type, $check, int $object_id, string $meta_key, $meta_value ) {
		if ( null !== $check ) {
			// Something ahead of this already decided; do not overrule it.
			return $check;
		}

		$subtype = \get_object_subtype( $type->value, $object_id );
		$field   = $this->get_fields_of( $type, $subtype )[ $meta_key ] ?? null;

		// Nothing this plugin governs -- and a field that switched itself off
		// registered no meta, so a write to that key is someone else's.
		if ( null === $field || ! $field->is_enabled() ) {
			return $check;
		}

		// Compared against true rather than tested for truth: a refusal carrying
		// its reason is a WP_Error, and an object is truthy -- so anything looser
		// here would let every explained refusal through.
		return true === $field->validate( $meta_value ) ? $check : false;
	}

	/**
	 * Every discovered field, keyed by the file it came from.
	 *
	 * The key is the path below the root without its extension, not the bare
	 * filename: directories here are organization, so `books/rating.php` and
	 * `films/rating.php` are two files and one meta key, which is legal as long
	 * as they attach to different subtypes.
	 *
	 * @return array<string, Field>
	 * @throws DiscoveryException When a file returns the wrong value.
	 */
	private function get_fields_by_file(): array {
		if ( null !== $this->discovered ) {
			return $this->discovered;
		}

		$root_dir = $this->with( Path::class )->get_plugin_path( self::FIELDS_ROOT );

		if ( ! \is_dir( $root_dir ) ) {
			// Never named, and the default is absent: this plugin has none of
			// these yet. Only a directory asked for by name is missing in the
			// sense worth throwing over.
			$this->discovered = array();

			return $this->discovered;
		}

		$instances = array();

		// Walked to any depth, and a directory means nothing: a meta key is the
		// `meta_key` column and appears in your REST responses, so nesting a file
		// cannot change it the way it changes a command's name or a page's place
		// in the menu. What a field attaches to is `subtypes()`, in the file.
		// Folders are yours to organize with -- one per post type is the obvious
		// way, and the toolkit reads nothing into it.
		//
		// The filename is only the *default* key -- `Field::key()` overrides it,
		// and two fields may share a key across object types or subtypes -- but
		// the default is spelled the one way, like every other discovered name.
		foreach ( $this->walk_folder( $root_dir, array( 'php' ) ) as $file ) {
			// Wired inside, so is_enabled() can reach a module with `with()` whenever
			// it is asked. Every file is kept, switched on or off: what a field
			// declares is readable, and what it *registers* is decided in
			// register_fields().
			$instance = $this->wire_field_file( $root_dir . '/' . $file );

			$instances[ \substr( $file, 0, -\strlen( '.php' ) ) ] = $instance;
		}

		$this->discovered = $instances;

		return $this->discovered;
	}

	/**
	 * Require a field file and wire the instance it returns.
	 *
	 * @param string $file Absolute path to the field file.
	 * @return Field
	 * @throws DiscoveryException When the file does not return a Field instance.
	 */
	private function wire_field_file( string $file ): Field {
		/** @var Field $instance */
		$instance = require $file;

		if ( ! $instance instanceof Field ) {
			throw new DiscoveryException(
				\sprintf(
					'The file "%s" must return an instance of %s. Got: %s',
					$file,
					Field::class,
					\is_object( $instance ) ? $instance::class : \gettype( $instance )
				)
			);
		}

		$this->get_plugin()->wire( $instance );

		return $instance;
	}

	/**
	 * Answer `is_protected_meta()` for the fields that decided for themselves.
	 *
	 * WordPress decides protection by looking for a leading underscore, which
	 * makes a security property of a filename: rename `_secret` to `secret` and
	 * its default auth callback flips from `__return_false` to `__return_true`.
	 * {@see Field::is_protected()} lets a field say so instead, and this is what
	 * makes the answer stick.
	 *
	 * The filter fires for every meta key on the site, so a key this plugin did
	 * not register is passed through untouched -- and so is one whose field
	 * returned null, which is the default and means "the name decides".
	 *
	 * Registered once, and left registered: `is_protected_meta()` is asked again
	 * long after registration, by the capability map, by block bindings, and by
	 * the Custom Fields panel.
	 *
	 * **This is the one answer a subtype cannot narrow.** WordPress hands the
	 * filter an object type and a key and nothing else, so two fields sharing a
	 * key on two post types have one answer between them. Disagreeing about it
	 * is refused here rather than silently resolved, since either resolution
	 * would leave one field's declaration quietly not doing what it says.
	 *
	 * @return void
	 * @throws DiscoveryException When discovery fails, or two fields sharing a key disagree about protection.
	 */
	private function filter_protected_meta(): void {
		$decided = array();

		foreach ( $this->get_all_fields() as $field ) {
			// A field that registers nothing has no business deciding whether a
			// key it does not own is protected -- this filter answers for every
			// key on the site.
			if ( ! $field->is_enabled() ) {
				continue;
			}

			$protected = $field->is_protected();

			if ( null === $protected ) {
				continue;
			}

			$type = $field->object_type()->value;
			$key  = $field->key();

			if ( isset( $decided[ $type ][ $key ] ) && $decided[ $type ][ $key ] !== $protected ) {
				throw new DiscoveryException(
					\sprintf(
						'Two %1$s fields share the meta key "%2$s" and disagree about is_protected(). WordPress asks that question per object type, never per subtype, so one key has one answer -- make them agree, or let one return null and leave the key\'s spelling to decide.',
						$type,
						$key
					)
				);
			}

			$decided[ $type ][ $key ] = $protected;
		}

		if ( array() === $decided ) {
			return;
		}

		\add_filter(
			'is_protected_meta',
			static function ( $is_protected, $meta_key, $meta_type ) use ( $decided ) {
				return $decided[ $meta_type ][ $meta_key ] ?? $is_protected;
			},
			10,
			3
		);
	}

	/**
	 * Apply each field's `validate()` to every write of its meta key.
	 *
	 * Validation belongs in the write, not in one accessor. WordPress lets these
	 * two filters short-circuit a write of any meta type, so hooking them applies
	 * a field's `validate()` to `update_post_meta()` and everything else -- not
	 * only to `set()`. WordPress has no validate callback of its own for meta;
	 * this is the closest thing to one.
	 *
	 * @return void
	 */
	private function guard_every_write(): void {
		foreach ( MetaType::cases() as $type ) {
			// Bound per type, and the type is carried into the check: these
			// filters fire for every key of their type, and the same key can
			// exist on a post and a term and mean different things.
			$guard = function ( $check, $object_id, $meta_key, $meta_value ) use ( $type ) {
				return $this->block_invalid_write( $type, $check, (int) $object_id, (string) $meta_key, $meta_value );
			};

			\add_filter( 'add_' . $type->value . '_metadata', $guard, 10, 4 );
			\add_filter( 'update_' . $type->value . '_metadata', $guard, 10, 4 );
		}
	}

	/**
	 * Refuse a subtype on an object type that has none.
	 *
	 * User and comment meta are not divided: `get_object_subtype()` answers with
	 * the literal `user` and `comment` for every one of them, never a role or a
	 * `comment_type`. So a field naming a subtype there registers meta against a
	 * name nothing ever produces — the key is never matched, its `sanitize()`
	 * never runs, and `update_user_meta()` stores whatever it is given. Nothing
	 * about that failure is visible, which is why it is refused here rather than
	 * left to be noticed.
	 *
	 * @param string   $file     The file the field came from, for the message.
	 * @param Field    $field    The field being examined.
	 * @param string[] $subtypes What it says it attaches to.
	 * @return void
	 * @throws DiscoveryException When the object type has no subtypes and the field names one.
	 */
	private function assert_subtypes_are_possible( string $file, Field $field, array $subtypes ): void {
		if ( array() === $subtypes || $field->object_type()->has_subtypes() ) {
			return;
		}

		throw new DiscoveryException(
			\sprintf(
				'%1$s.php declares subtypes( %2$s ) on %3$s meta, which has no subtypes: WordPress answers "%3$s" for every one of them, so meta registered against that name is never matched and the field silently does nothing. Return an empty array to attach it to every %3$s.',
				$file,
				\implode( ', ', $subtypes ),
				$field->object_type()->value
			)
		);
	}

	/**
	 * Refuse two files claiming one meta key where their subtypes meet.
	 *
	 * Sharing a key is legal, and is how `rating` holds an integer on `book` and
	 * a string on `movie` — WordPress registers those separately, and so does
	 * this. What is not legal is two files claiming it on the *same* subtype,
	 * because the second registration replaces the first and leaves a file on
	 * disk that reads as though it does something.
	 *
	 * A field attached to every subtype overlaps every other claim on its key,
	 * which is the case a per-subtype check alone would miss.
	 *
	 * @param array<string, array<string, array<string, string[]>>> $claims Object type => meta key => subtype => the files claiming it.
	 * @return void
	 * @throws DiscoveryException When two files claim one key on one subtype.
	 */
	private function assert_no_overlapping_claims( array $claims ): void {
		foreach ( $claims as $type => $keys ) {
			foreach ( $keys as $key => $by_subtype ) {
				foreach ( $by_subtype as $subtype => $files ) {
					// A named subtype meets the fields attached to every
					// subtype; `''` meets all of them at once.
					$overlapping = '' === $subtype
						? \array_merge( ...\array_values( $by_subtype ) )
						: \array_merge( $files, $by_subtype[''] ?? array() );

					// A file naming one subtype twice is not two files.
					$overlapping = \array_values( \array_unique( $overlapping ) );

					if ( \count( $overlapping ) < 2 ) {
						continue;
					}

					throw new DiscoveryException(
						\sprintf(
							'Both %1$s.php and %2$s.php declare the %3$s meta key "%4$s" for %5$s. One key, one file per subtype -- switching one of them off does not settle which owns the key. Give them different keys, or narrow subtypes() so they do not overlap.',
							$overlapping[0],
							$overlapping[1],
							$type,
							$key,
							'' === $subtype ? 'every subtype' : '"' . $subtype . '"'
						)
					);
				}
			}
		}
	}
}
