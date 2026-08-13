<?php

/**
 * Fields API: Fields module
 */

declare( strict_types=1 );

namespace Zestry\WPToolkit\Modules\Fields;

// Loaded by WordPress, never requested directly.
\defined( 'ABSPATH' ) || exit;

use Zestry\WPToolkit\Kernel\Abstracts\Module;
use Zestry\WPToolkit\Kernel\Exceptions\DiscoveryException;
use Zestry\WPToolkit\Kernel\Traits\WithFolderWalker;
use Zestry\WPToolkit\Services\Path;

/**
 * Registers post meta from files, with types, sanitisers and permissions.
 *
 * A file in `fields/` returns a {@see Field} naming the post types it attaches
 * to — so a field on your own post type and a field on core's `post` are written
 * the same way, in the same place.
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
 * declares, and a key whose file switched itself off. That refusal is the
 * point: a mistyped key handed to `get_post_meta()` returns `''`, which looks
 * exactly like a field nobody has filled in.
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
 * // fields/acme_rating.php -- the filename is the meta key
 * return new class extends Field {
 *
 *     public function subtypes(): array {
 *         return array( 'book', 'post' );
 *     }
 *
 *     public function type(): string {
 *         return 'integer';
 *     }
 * };
 * ```
 *
 */
class Fields extends Module {

	use WithFolderWalker;

	/**
	 * Where fields are discovered, relative to the plugin root.
	 */
	const FIELDS_ROOT = 'fields';

	/**
	 * @var Path
	 */
	public Path $path;

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
	 * Every discovered field, by object type and then by meta key.
	 *
	 * Nested because a meta key is unique only within an object type. Use
	 * {@see get_fields_of()} when you know which type you want.
	 *
	 * Everything the directory declares, including a field whose `is_enabled()`
	 * returns false — so a screen offering to switch features on can list the
	 * ones currently switched off. Only {@see register_fields()} acts on the
	 * answer, and the value accessors refuse a key belonging to a field that is
	 * switched off.
	 *
	 * @return array<string, array<string, Field>> Object type => meta key => instance.
	 * @throws DiscoveryException When a file returns the wrong value.
	 */
	public function get_discovered_fields(): array {
		$fields = array();

		foreach ( $this->get_fields_by_file() as $file => $field ) {
			$type = $field->object_type()->value;
			$key  = $field->key();

			if ( isset( $fields[ $type ][ $key ] ) ) {
				// Nesting settles the cross-type case -- `acme_note` on a post
				// and on a term are two keys in two tables. Two files claiming
				// one key on one type is not that, and keeping either silently
				// would leave the other registered but unreachable.
				throw new DiscoveryException(
					\sprintf(
						'Both %1$s.php and another file declare the %2$s meta key "%3$s". One key, one file -- switching one of them off does not settle which owns the key.',
						$file,
						$type,
						$key
					)
				);
			}

			$fields[ $type ][ $key ] = $field;
		}

		return $fields;
	}

	/**
	 * This field's meta key, taken from the file it was discovered in.
	 *
	 * The same reverse lookup {@see \Zestry\WPToolkit\Modules\PostTypes\PostTypes::get_post_type_of()}
	 * does, so a field named by its filename never repeats that name inside the
	 * file.
	 *
	 * @param Field $field The instance to look up.
	 * @return string
	 * @throws \InvalidArgumentException When the instance was not discovered by this module.
	 */
	public function get_key_of( Field $field ): string {
		$name = \array_search( $field, $this->get_fields_by_file(), true );

		if ( false === $name ) {
			throw new \InvalidArgumentException(
				\sprintf( 'The given %s instance was not discovered by this Fields module.', Field::class )
			);
		}

		return $name;
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
	 * @throws \InvalidArgumentException When no field declares that key.
	 */
	public function get( int $object_id, string $key, mixed $fallback = null, MetaType $type = MetaType::Post ): mixed {
		$this->get_field( $key, $type );

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
	 * @throws \InvalidArgumentException When no field declares that key.
	 */
	public function has( int $object_id, string $key, MetaType $type = MetaType::Post ): bool {
		$this->get_field( $key, $type );

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
	 * @throws \InvalidArgumentException When no field declares that key.
	 */
	public function set( int $object_id, string $key, mixed $value, MetaType $type = MetaType::Post ): bool|\WP_Error {
		$field = $this->get_field( $key, $type );

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
	 * @throws \InvalidArgumentException When no field declares that key.
	 */
	public function delete( int $object_id, string $key, MetaType $type = MetaType::Post ): void {
		$this->get_field( $key, $type );

		\delete_metadata( $type->value, $object_id, $key );
	}

	/**
	 * Every declared field, flattened, for iterating over all of them.
	 *
	 * Includes the switched-off ones — this is enumeration, and that is what it
	 * is for. Ask an instance's `is_enabled()` to tell them apart.
	 *
	 * @return array<string, Field> Meta key => instance. A key shared by two
	 *                              object types appears once; use
	 *                              {@see get_fields_of()} to tell them apart.
	 * @throws DiscoveryException When discovery fails.
	 */
	public function get_all_fields(): array {
		$by_type = \array_values( $this->get_discovered_fields() );

		return array() === $by_type ? array() : \array_merge( ...$by_type );
	}

	/**
	 * Every field of one object type, by meta key.
	 *
	 * Includes the switched-off ones, on the same terms as
	 * {@see get_all_fields()}.
	 *
	 * @param MetaType $type The object type.
	 * @return array<string, Field>
	 * @throws DiscoveryException When discovery fails.
	 */
	public function get_fields_of( MetaType $type ): array {
		return $this->get_discovered_fields()[ $type->value ] ?? array();
	}

	/**
	 * The field declaring a key, within an object type.
	 *
	 * A field that switched itself off is refused too, with its own message: its
	 * meta was never registered, so reading it would hand back `''` and writing
	 * it would store a value nothing knows the shape of — the two failures this
	 * method exists to prevent. Enumerate with {@see get_fields_of()} when you
	 * want everything declared.
	 *
	 * @param string   $key  The meta key.
	 * @param MetaType $type The object type it belongs to. Post meta by default.
	 * @return Field
	 * @throws \InvalidArgumentException When no field of that type declares that key, or the field that does is switched off.
	 */
	public function get_field( string $key, MetaType $type = MetaType::Post ): Field {
		$fields = $this->get_fields_of( $type );

		if ( ! isset( $fields[ $key ] ) ) {
			throw new \InvalidArgumentException(
				\sprintf(
					'No %1$s field declares the meta key "%2$s", so this plugin does not own it. Declared: %3$s. Use get_metadata() for meta belonging to WordPress or another plugin.',
					$type->value,
					$key,
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

		foreach ( $this->get_all_fields() as $key => $field ) {
			// Declared but switched off. Discovery lists it either way; this is
			// where it stops short of a registration, so nothing about it
			// reaches REST or the block editor.
			if ( ! $field->is_enabled() ) {
				continue;
			}

			$type     = $field->object_type()->value;
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
	protected function on_boot(): void {
		$this->on_wp_init(
			static function ( self $module ): void {
				$module->register_fields();
			}
		);

		/*
		 * Validation belongs in the write, not in one accessor. WordPress lets
		 * these two short-circuit a write of any meta type, so hooking them
		 * applies a field's validate() to `update_post_meta()` and everything
		 * else -- not only to set(). WordPress has no validate callback of its
		 * own for meta; this is the closest thing to one.
		 */
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
	 * Refuse a write whose value the field rejects.
	 *
	 * Leaves the write alone — returning `$check` untouched — for anything this
	 * plugin does not own. A meta key is unique only within its object type and
	 * subtype, so all three have to match before a field's rule is applied:
	 * `acme_note` on a post and `acme_note` on a term are different keys, and
	 * governing someone else's write would be worse than governing none.
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

		$field = $this->get_fields_of( $type )[ $meta_key ] ?? null;

		// Nothing this plugin governs -- and a field that switched itself off
		// registered no meta, so a write to that key is someone else's.
		if ( null === $field || ! $field->is_enabled() ) {
			return $check;
		}

		$subtypes = $field->subtypes();

		// A field naming its subtypes governs only those. One naming none is
		// registered against every subtype, so it governs every one.
		if ( array() !== $subtypes
			&& ! \in_array( \get_object_subtype( $type->value, $object_id ), $subtypes, true )
		) {
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
	 * @return array<string, Field>
	 * @throws DiscoveryException When a file returns the wrong value.
	 */
	private function get_fields_by_file(): array {
		if ( null !== $this->discovered ) {
			return $this->discovered;
		}

		$root_dir = $this->path->get_plugin_path( self::FIELDS_ROOT );

		if ( ! \is_dir( $root_dir ) ) {
			// Never named, and the default is absent: this plugin has none of
			// these yet. Only a directory asked for by name is missing in the
			// sense worth throwing over.
			$this->discovered = array();

			return $this->discovered;
		}

		$instances = array();

		// The filename is only the *default* key -- `Field::key()` overrides it,
		// and two fields may share a key across object types -- but the default
		// is spelled the one way, like every other discovered name.
		// A meta key is the `meta_key` column, and appears in your REST responses.
		// The filename is the default key, exactly as written.
		foreach ( $this->walk_folder( $root_dir, array( 'php' ), 1 ) as $file ) {
			// Wired inside, so is_enabled() can read an injected service whenever
			// it is asked. Every file is kept, switched on or off: what a field
			// declares is readable, and what it *registers* is decided in
			// register_fields().
			$instance = $this->wire_field_file( $root_dir . '/' . $file );

			$instances[ \basename( $file, '.php' ) ] = $instance;
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
	 * @return void
	 * @throws DiscoveryException When discovery fails.
	 */
	private function filter_protected_meta(): void {
		$decided = array();

		foreach ( $this->get_all_fields() as $key => $field ) {
			// A field that registers nothing has no business deciding whether a
			// key it does not own is protected -- this filter answers for every
			// key on the site.
			if ( ! $field->is_enabled() ) {
				continue;
			}

			$protected = $field->is_protected();

			if ( null !== $protected ) {
				$decided[ $field->object_type()->value ][ $key ] = $protected;
			}
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
}
