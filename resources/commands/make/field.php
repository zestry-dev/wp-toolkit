<?php

/**
 * Devtool command: `wp zt make field <name>`.
 */

declare( strict_types=1 );

use Zestry\WPToolkit\DevTools\Abstracts\MakeCommand;
use Zestry\WPToolkit\Modules\Fields\MetaType;

return new class() extends MakeCommand {

	/**
	 * The object type the generated field is stored against.
	 */
	private MetaType $object_type = MetaType::Post;

	/**
	 * The subtypes it attaches to, empty for every one of them.
	 *
	 * @var string[]
	 */
	private array $subtypes = array();

	/**
	 * Generate a post meta field.
	 *
	 * Writes a file into the plugin's `resources/fields/` directory, where the Fields
	 * module discovers it. The name becomes the meta key, which you should
	 * prefix if the field attaches to a post type you do not own.
	 *
	 * The file is filed under `{object-type}/{subtype}/`, so `resources/fields/`
	 * reads as an index of what is stored where. Folders are organization only --
	 * the module reads the filename and `subtypes()`, never the path -- so move
	 * one whenever the grouping stops helping.
	 *
	 * ## OPTIONS
	 *
	 * <name>
	 * : The meta key, e.g. `acme-rating`. Written exactly as given -- a meta key
	 * is the `meta_key` column and appears in your REST responses, so nothing
	 * respells it. To mark the field protected whatever it is called, uncomment
	 * `is_protected()` in the generated file and return true.
	 *
	 * [--object-type=<object-type>]
	 * : Which meta table the key lives in: post, term, user or comment.
	 * Prompted for when not given. Post meta is the common case and the default.
	 *
	 * [--subtypes=<subtypes>]
	 * : What the field attaches to within that table, comma-separated -- post
	 * type names for post meta, taxonomy names for term meta. Prompted for when
	 * not given. Naming them is what makes the field own its key: `Fields::set()`
	 * refuses this key on anything else, where nothing would sanitize or
	 * validate the value. An empty answer attaches it to every subtype.
	 * User and comment meta have no subtypes -- WordPress answers `user` and
	 * `comment` for every one of them -- so this is refused there rather than
	 * registering meta nothing can ever match.
	 *
	 * [--extends=<class>]
	 * : Extend one of your own abstracts instead of the toolkit base. A bare name
	 * is looked for under your Abstracts\ namespace; the generated file stubs the
	 * methods that class leaves abstract, and nothing it has already settled.
	 *
	 * [--yes]
	 * : Answer every prompt without reading input: take the default for the
	 * object type and subtypes, overwrite an existing file, and add the `fields`
	 * module when this plugin has none.
	 *
	 * ## EXAMPLES
	 *
	 *     # Generate a rating on the book post type, prompting for both.
	 *     $ wp zt make field acme-rating
	 *     Which meta table (post, term, user, comment): (default: post)
	 *     Post type(s) this field attaches to, comma-separated: (default: post) book
	 *     Success: Created resources/fields/post/book/acme-rating.php
	 *
	 *     # The same, given explicitly.
	 *     $ wp zt make field acme-rating --object-type=post --subtypes=book
	 *     Success: Created resources/fields/post/book/acme-rating.php
	 *
	 *     # Two post types, so no single folder is right: filed under the table.
	 *     $ wp zt make field acme-rating --subtypes=book,film
	 *     Success: Created resources/fields/post/acme-rating.php
	 *
	 *     # User meta, which has no subtypes.
	 *     $ wp zt make field acme-tier --object-type=user
	 *     Success: Created resources/fields/user/acme-tier.php
	 *
	 * @param array $args
	 * @param array $assoc_args
	 * @return void
	 */
	public function handle( array $args, array $assoc_args ): void {
		parent::handle( $args, $assoc_args );
	}

	public function get_base_class(): ?string {
		return 'Modules\Fields\Field';
	}

	/**
	 * Resolve the object type and subtypes, and render them into the stub.
	 *
	 * Both are settled here rather than in the stub because both decide the
	 * destination path as well as the file's contents, and
	 * {@see get_destination_path()} runs straight after this.
	 *
	 * `--extends` writes a different stub, which carries neither placeholder --
	 * the abstract being extended is where a field like that settles what it
	 * attaches to. So the flags are still read, and the prompts are not asked:
	 * a question whose answer is discarded is worse than no question.
	 *
	 * @param string $name       The meta key given on the command line.
	 * @param array  $assoc_args WP-CLI's named arguments, checked before prompting.
	 * @return array{subtypes: string, object_type: string}
	 */
	protected function get_extra_values( string $name, array $assoc_args ): array {
		$asks = null === $this->get_flag( $assoc_args, 'extends', null );

		$this->object_type = $this->resolve_object_type( $assoc_args, $asks );
		$this->subtypes    = $this->resolve_subtypes( $assoc_args, $asks );

		$quoted = \array_map(
			static function ( string $subtype ): string {
				return "'" . $subtype . "'";
			},
			$this->subtypes
		);

		return array(
			// The whole literal, so the empty case is `array()` rather than an
			// `array(  )` only a formatter the consumer may not have would close up.
			'subtypes'    => array() === $quoted ? 'array()' : 'array( ' . \implode( ', ', $quoted ) . ' )',
			'object_type' => \ucfirst( $this->object_type->value ),
		);
	}

	/**
	 * File the field under its object type, and its subtype where it has one.
	 *
	 * A field on several subtypes is filed under the object type alone, since no
	 * one folder is the right answer for it. The module reads none of this --
	 * only the filename is the key -- so the arrangement is a convention the
	 * generator sets rather than one anything enforces.
	 *
	 * @param string $dir  The fields root, or whatever `--dir` overrode it with.
	 * @param string $name The meta key.
	 * @return string
	 */
	protected function get_destination_path( string $dir, string $name ): string {
		$segments = array( \trim( $dir, '/\\' ), $this->object_type->value );

		if ( 1 === \count( $this->subtypes ) ) {
			$segments[] = $this->subtypes[0];
		}

		$segments[] = $name . '.php';

		return \implode( '/', $segments );
	}

	protected function get_stub(): string {
		return 'field.php.stub';
	}

	protected function get_default_dir( array $config ): string {
		return 'resources/fields';
	}

	/**
	 * Which meta table the field is stored in.
	 *
	 * @param array $assoc_args WP-CLI's named arguments.
	 * @param bool  $asks       Whether an unanswered value may be prompted for.
	 * @return MetaType
	 */
	private function resolve_object_type( array $assoc_args, bool $asks ): MetaType {
		$names = \implode( ', ', \array_column( MetaType::cases(), 'value' ) );

		$given = $this->get_flag( $assoc_args, 'object-type', null )
			?? ( $asks ? $this->ask( \sprintf( 'Which meta table (%s):', $names ), MetaType::Post->value ) : MetaType::Post->value );

		$resolved = MetaType::tryFrom( \strtolower( \trim( $given ) ) );

		if ( null === $resolved ) {
			// Refused rather than defaulted: the object type decides which table
			// the key lives in, and guessing wrong writes a field against the
			// wrong one, which fails by registering nothing rather than by
			// saying so.
			$this->error( \sprintf( '"%1$s" is not a meta table. Use one of: %2$s.', $given, $names ) );
		}

		return $resolved ?? MetaType::Post;
	}

	/**
	 * What the field attaches to within its object type.
	 *
	 * @param array $assoc_args WP-CLI's named arguments.
	 * @param bool  $asks       Whether an unanswered value may be prompted for.
	 * @return string[]
	 */
	private function resolve_subtypes( array $assoc_args, bool $asks ): array {
		$given = $this->get_flag( $assoc_args, 'subtypes', null );

		if ( ! $this->object_type->has_subtypes() ) {
			if ( null !== $given && '' !== \trim( $given ) ) {
				$this->error(
					\sprintf(
						'%1$s meta has no subtypes: WordPress answers "%1$s" for every one of them, so meta registered against "%2$s" is never matched and the field silently does nothing. Drop --subtypes.',
						$this->object_type->value,
						\trim( $given )
					)
				);
			}

			return array();
		}

		if ( null === $given && ! $asks ) {
			return array();
		}

		$answer = $given ?? $this->ask(
			\sprintf( '%s this field attaches to, comma-separated:', $this->get_subtype_noun() ),
			MetaType::Post === $this->object_type ? 'post' : ''
		);

		$subtypes = \array_values(
			\array_filter(
				\array_map( 'trim', \explode( ',', $answer ) ),
				static function ( string $subtype ): bool {
					return '' !== $subtype;
				}
			)
		);

		$this->warn_about_unregistered( $subtypes );

		return $subtypes;
	}

	/**
	 * What this object type's subtypes are called, for the prompt.
	 *
	 * @return string
	 */
	private function get_subtype_noun(): string {
		return MetaType::Term === $this->object_type ? 'Taxonomy(ies)' : 'Post type(s)';
	}

	/**
	 * Say so when a named subtype is not registered on this site.
	 *
	 * A warning rather than a refusal: the post type may be registered by a
	 * plugin that is not installed here, or by code written after this file. But
	 * a typo produces exactly the same silence at runtime -- meta registered
	 * against a subtype nothing ever matches -- so it is worth one line now.
	 *
	 * @param string[] $subtypes The subtypes given.
	 * @return void
	 */
	private function warn_about_unregistered( array $subtypes ): void {
		foreach ( $subtypes as $subtype ) {
			$exists = MetaType::Term === $this->object_type
				? \taxonomy_exists( $subtype )
				: \post_type_exists( $subtype );

			if ( $exists ) {
				continue;
			}

			$this->warning(
				\sprintf(
					'No %1$s named "%2$s" is registered here. If that is a typo, the field registers meta nothing ever matches; if it is registered elsewhere, ignore this.',
					MetaType::Term === $this->object_type ? 'taxonomy' : 'post type',
					$subtype
				)
			);
		}
	}

	protected static function get_type(): string {
		return 'field';
	}
};
