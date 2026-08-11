<?php

/**
 * Abilities API: Abilities module
 */

declare( strict_types=1 );

namespace Zestry\WPToolkit\Modules\Abilities;

// Loaded by WordPress, never requested directly.
\defined( 'ABSPATH' ) || exit;

use Zestry\WPToolkit\Kernel\Abstracts\Module;
use Zestry\WPToolkit\Kernel\Exceptions\DiscoveryException;
use Zestry\WPToolkit\Kernel\Traits\WithFolderWalker;
use Zestry\WPToolkit\Services\Path;
use Zestry\WPToolkit\Services\Request\Request;

/**
 * Publishes what your plugin can do, for the REST API and for AI agents.
 *
 * A file in `abilities/` returns an {@see Ability}, and its filename is the name
 * it registers under: `create-order.php` becomes `{plugin-slug}/create-order`.
 * Each one carries a description and JSON Schemas for its input and output —
 * enough for something that has never seen your code to call it correctly.
 *
 * WordPress gives every public ability a REST endpoint at
 * `wp-json/wp-abilities/v1/abilities/{ability}/run` for free. An MCP adapter installed on
 * the site turns the same registration into a tool an AI agent can call, also
 * for free: there is no protocol code to write on your side, which is the point
 * of the API. Requires WordPress 6.9 or newer.
 *
 * > [!IMPORTANT]
 * > **No adapter ships with WordPress, and you do not need one to be finished.**
 * > An ability is a registry entry first. WordPress serves the REST half itself,
 * > so that half is testable today; the MCP half is a separate plugin somebody
 * > installs, and whether one is present is a property of the site rather than
 * > of your code. Write and verify against REST, and an adapter picks the same
 * > registration up when it arrives.
 *
 * Three commands prove the REST half end to end, and are worth running once:
 *
 * ```bash
 * # Every ability registered on this site, yours among them.
 * wp eval 'echo wp_json_encode( array_keys( wp_get_abilities() ) );'
 *
 * # And the endpoint, as a client sees it.
 * curl -s "$(wp option get siteurl)/wp-json/wp-abilities/v1/abilities"
 *
 * # Running one. The arguments go under a single `input`, never at the top level.
 * curl -s "$(wp option get siteurl)/wp-json/wp-abilities/v1/abilities/acme-plugin/list-orders/run?input[status]=open"
 * ```
 *
 * An ability missing from the first is not registered; one missing from the
 * second is registered but not {@see Ability::is_public()}. The third is a `GET`
 * because that ability reads; {@see Effect} decides both the method and whether
 * `input` rides in the query string or the JSON body.
 *
 *
 * Abilities are worth writing even when nothing external calls them yet. One
 * ability is one operation, described once, reachable from a REST client, an
 * agent, a WP-CLI command and your own PHP through
 * {@see run()} — instead of the same operation written four times.
 *
 * @example An ability
 * A typed property carrying a {@see \Zestry\WPToolkit\Services\Request\Attributes\RequestArgument} is both the
 * input schema and the value: it is described once, validated by WordPress, and
 * bound before your code runs. The property says the type, and whether it is
 * required — one with no default has to be supplied.
 *
 * ```
 * // abilities/publish-post.php
 * return new class extends Ability {
 *
 *     public function label(): string {
 *         return __( 'Publish a draft', 'acme-plugin' );
 *     }
 *
 *     public function description(): string {
 *         return __( 'Publishes a draft post immediately. Already-published posts are left alone.', 'acme-plugin' );
 *     }
 *
 *     public function effect(): Effect {
 *         return Effect::Update;
 *     }
 *
 *     public function is_public(): bool {
 *         return true;
 *     }
 *
 *     #[RequestArgument( 'The draft to publish.' )]
 *     public int $id;
 *
 *     public function permission_check( mixed $input ): bool {
 *         return current_user_can( 'publish_post', $this->id );
 *     }
 *
 *     public function handle( mixed $input ): mixed {
 *         return array( 'published' => (bool) wp_publish_post( $this->id ) );
 *     }
 * };
 * ```
 *
 * @example Calling one from your own code
 * ```
 * $result = $this->abilities->run( 'publish-post', array( 'id' => 42 ) );
 *
 * if ( is_wp_error( $result ) ) {
 *     // Invalid input, no permission, or the ability said no.
 * }
 * ```
 *
 * @setup Group them, or read them from elsewhere
 * ```
 * Abilities::class => static function ( Abilities $abilities ): void {
 *     $abilities->set_abilities_root( 'src/abilities' );
 *
 *     $abilities->add_categories(
 *         array(
 *             'acme-billing' => array(
 *                 'label'       => static fn (): string => __( 'Acme billing', 'acme-plugin' ),
 *                 'description' => static fn (): string => __( 'Invoices, refunds and payment methods.', 'acme-plugin' ),
 *             ),
 *         )
 *     );
 * },
 * ```
 */
class Abilities extends Module {

	use WithFolderWalker;

	/**
	 * Where abilities are discovered, relative to the plugin root.
	 */
	const DEFAULT_ABILITIES_ROOT = 'abilities';

	/**
	 * @var Path
	 */
	public Path $path;

	/**
	 * Builds each ability's input schema, and binds the values onto it.
	 *
	 * @var Request
	 */
	public Request $request;

	/**
	 * The directory abilities are read from.
	 *
	 * @var string
	 */
	private string $abilities_root = self::DEFAULT_ABILITIES_ROOT;

	/**
	 * Whether the root above was named rather than defaulted.
	 *
	 * @var bool
	 */
	private bool $abilities_root_was_set = false;

	/**
	 * Discovered abilities by local name, once the directory has been walked.
	 *
	 * Kept rather than rebuilt, so {@see get_name_of()} compares against the same
	 * instances WordPress holds the callbacks of.
	 *
	 * @var array<string, Ability>|null
	 */
	private ?array $discovered = null;

	/**
	 * Categories declared through add_categories(), in declaration order.
	 *
	 * @var array<string, array{label: string|callable(): string, description: string|callable(): string}>
	 */
	private array $categories = array();

	/**
	 * Read abilities from a different directory.
	 *
	 * Call this before the module boots — from its `bootstrap.php` entry. Naming
	 * a directory that does not exist is an error and throws, where leaving the
	 * default alone and having no such directory simply means you have no
	 * abilities yet.
	 *
	 * @param string $root Directory relative to the plugin root.
	 * @return void
	 */
	public function set_abilities_root( string $root ): void {
		$this->abilities_root         = \trim( $root, '/\\' );
		$this->abilities_root_was_set = true;

		// Anything already read came from the old directory.
		$this->discovered = null;
	}

	/**
	 * Declare ability categories of your own.
	 *
	 * Every ability belongs to exactly one category, and WordPress refuses to
	 * register one whose category does not exist. You already have a category
	 * named after your plugin, registered for you and used by default — this is
	 * for splitting a larger plugin into groups a client can show separately, or
	 * for a category shared with another plugin of yours.
	 *
	 * Keyed by slug, the same shape `bootstrap.php` uses for modules. A plain
	 * string is the label, and an array carries a description alongside it:
	 *
	 *     $abilities->add_categories(
	 *         array(
	 *             'acme-billing' => __( 'Acme billing', 'acme-plugin' ),
	 *             'acme-reports' => array(
	 *                 'label'       => __( 'Acme reports', 'acme-plugin' ),
	 *                 'description' => __( 'Reads sales figures. Changes nothing.', 'acme-plugin' ),
	 *             ),
	 *         )
	 *     );
	 *
	 *     // abilities/refund-order.php
	 *     public function category(): string {
	 *         return 'acme-billing';
	 *     }
	 *
	 * The description is worth writing. A client listing categories shows it to
	 * decide which group to look in, so "Reads sales figures. Changes nothing."
	 * earns its place where the generated fallback does not.
	 *
	 * A slug is registered exactly as given and is not namespaced to the plugin:
	 * WordPress's own `site` and `user` are unprefixed, and an ability naming a
	 * category has to match it verbatim. So choose slugs
	 * distinctive enough not to collide — a category already registered by
	 * WordPress or another plugin is left as it is rather than replaced.
	 *
	 * Either value may be given as a callable returning the string, resolved when
	 * WordPress asks for its categories. That is the safe form for a `__()` call:
	 * an initializer runs while the plugin file loads, early enough that
	 * translating there reports `_load_textdomain_just_in_time`.
	 *
	 * @param array<string, string|callable(): string|array{label: string|callable(): string, description?: string|callable(): string}> $categories Labels or configuration, keyed by slug.
	 * @return void
	 * @throws \InvalidArgumentException When an entry is an array without a label.
	 */
	public function add_categories( array $categories ): void {
		foreach ( $categories as $slug => $category ) {
			/*
			 * `is_callable` first, since a callable is legitimately an array --
			 * `array( $object, 'method' )` -- and would otherwise be read as a
			 * configuration array with no label.
			 */
			$is_config = \is_array( $category ) && ! \is_callable( $category );

			if ( $is_config && ! isset( $category['label'] ) ) {
				throw new \InvalidArgumentException(
					\sprintf( 'Ability category "%s" needs a label.', (string) $slug )
				);
			}

			$this->categories[ (string) $slug ] = array(
				'label'       => $is_config ? $category['label'] : $category,
				'description' => $is_config ? ( $category['description'] ?? null ) : null,
			);
		}
	}

	/**
	 * Every discovered ability, keyed by its local name.
	 *
	 * @return array<string, Ability> Wired instances keyed by local name.
	 * @throws DiscoveryException When a directory named by set_abilities_root() does not exist, or a file returns the wrong value.
	 */
	public function get_discovered_abilities(): array {
		if ( null !== $this->discovered ) {
			return $this->discovered;
		}

		$root_dir = $this->path->get_plugin_path( $this->abilities_root );

		if ( ! \is_dir( $root_dir ) ) {
			// Never named, and the default is absent: this plugin has none of
			// these yet. Only a directory asked for by name is missing in the
			// sense worth throwing over.
			if ( ! $this->abilities_root_was_set ) {
				$this->discovered = array();

				return $this->discovered;
			}

			throw DiscoveryException::missing_root( 'Abilities', $root_dir, 'set_abilities_root()' );
		}

		$instances = array();

		foreach ( $this->walk_folder( $root_dir, array( 'php' ), 1 ) as $file ) {
			$name = \basename( $file, '.php' );
			$path = $root_dir . '/' . $file;

			$instance = require $path;

			if ( ! $instance instanceof Ability ) {
				throw new DiscoveryException(
					\sprintf(
						'The file "%s" must return an instance of %s. Got: %s',
						$path,
						Ability::class,
						\is_object( $instance ) ? $instance::class : \gettype( $instance )
					)
				);
			}

			$this->get_plugin()->wire( $instance );

			// Discovered but switched off: wired first, so is_enabled() can read an
			// injected service, then nothing about it is registered.
			if ( ! $instance->is_enabled() ) {
				continue;
			}

			// Checked here rather than left to the registry: WordPress refuses a
			// name outside its charset with a _doing_it_wrong() that names no
			// file, and nothing here rewrites the name to fit.
			if ( ! $this->is_registrable_segment( $name ) ) {
				throw DiscoveryException::unregistrable_ability_name( $file, $this->get_ability_name( $name ) );
			}

			$instances[ $name ] = $instance;
		}

		$this->discovered = $instances;

		return $this->discovered;
	}

	/**
	 * The full name an ability file registers under.
	 *
	 * Namespaced to the plugin, since abilities share one registry with every
	 * other plugin on the site, and joined with the `/` that registry expects.
	 * Both halves are read exactly as written, so `create-order.php` in a plugin
	 * slugged `acme-plugin` registers as `acme-plugin/create-order`.
	 *
	 * WordPress accepts only lowercase letters, digits and dashes in either half.
	 * A file whose name it would refuse is refused here first, when the ability is
	 * discovered — see {@see DiscoveryException::unregistrable_ability_name()}.
	 *
	 * @param string $name The ability's local name — its filename without `.php`.
	 * @return string
	 */
	public function get_ability_name( string $name ): string {
		return $this->get_plugin()->get_namespaced_name( $name, '/' );
	}

	/**
	 * This ability's full name, from the file it was discovered in.
	 *
	 * @param Ability $ability The instance to look up.
	 * @return string
	 * @throws \InvalidArgumentException When the instance was not discovered by this module.
	 */
	public function get_name_of( Ability $ability ): string {
		$name = \array_search( $ability, $this->get_discovered_abilities(), true );

		if ( false === $name ) {
			throw new \InvalidArgumentException(
				\sprintf( 'The given %s instance was not discovered by this Abilities module.', Ability::class )
			);
		}

		return $this->get_ability_name( $name );
	}

	/**
	 * The slug of the category registered for this plugin.
	 *
	 * Your plugin slug, in the form WordPress accepts. It is what
	 * {@see Ability::category()} returns unless an ability says otherwise, and it
	 * is registered only if at least one ability actually uses it.
	 *
	 * @return string
	 */
	public function get_category_slug(): string {
		return $this->get_namespace();
	}

	/**
	 * Run one of this plugin's abilities.
	 *
	 * Takes the local name — the filename — and applies your namespace, so
	 * `run( 'publish-post', … )` calls `{plugin-slug}/publish-post`. Everything
	 * an outside caller gets happens here too: the input is validated against the
	 * schema, {@see Ability::permission_check()} is checked, and the result is
	 * validated on the way out.
	 *
	 * Returns whatever the ability returned, or a `WP_Error` for any of those
	 * three failing. That makes an ability the one implementation of an
	 * operation, called the same way from a CLI command, an admin page or a
	 * cron schedule as from an agent.
	 *
	 * @param string $name  The ability's local name.
	 * @param mixed  $input Input matching the ability's schema.
	 * @return mixed The ability's result, or a `WP_Error`.
	 * @throws \InvalidArgumentException When this plugin has no such ability.
	 */
	public function run( string $name, mixed $input = null ): mixed {
		$full = $this->get_ability_name( $name );

		// Asking the registry is what fires wp_abilities_api_init, so this works
		// whether or not anything has touched abilities yet this request. Asked
		// first rather than reading wp_get_ability()'s null, since that reports a
		// miss with _doing_it_wrong() and the exception below says it better.
		if ( ! \wp_has_ability( $full ) ) {
			throw new \InvalidArgumentException(
				\sprintf( 'The ability "%s" is not registered.', $full )
			);
		}

		return \wp_get_ability( $full )->execute( $input );
	}

	/**
	 * Register this plugin's ability categories with WordPress.
	 *
	 * @return void
	 * @throws DiscoveryException When discovery fails.
	 *
	 * @internal
	 */
	public function register_categories(): void {
		foreach ( $this->get_all_categories() as $slug => $category ) {
			// Registering over one WordPress or another plugin already has is an
			// error there, and the existing one is the one abilities reference.
			if ( \wp_has_ability_category( $slug ) ) {
				continue;
			}

			// Resolved here rather than at declaration: this fires long after
			// `init`, so a label given as a callable can translate.
			$label = $this->get_resolved_string( $category['label'] );

			\wp_register_ability_category(
				$slug,
				array(
					'label'       => $label,
					'description' => null !== $category['description']
						? $this->get_resolved_string( $category['description'] )
						: \sprintf(
							/* translators: %s: ability category label. */
							\__( 'Abilities grouped under %s.', 'zestry-toolkit' ),
							$label
						),
				)
			);
		}
	}

	/**
	 * Register every discovered ability with WordPress.
	 *
	 * @return void
	 * @throws DiscoveryException When discovery fails.
	 *
	 * @internal
	 */
	public function register_abilities(): void {
		foreach ( $this->get_discovered_abilities() as $name => $ability ) {
			$args = array(
				'label'               => $ability->label(),
				'description'         => $ability->description(),
				'category'            => $ability->category(),
				// Bound on the way into both, since WordPress checks permission
				// first and a RequestArgument property is what a check like
				// current_user_can( 'edit_post', $this->id ) reads. Only the
				// execute side runs the argument callbacks: a permission callback
				// cannot carry their refusal, since WordPress replaces whatever it
				// returns with a message about permissions.
				/*
				 * Both take null, because WordPress calls them with no arguments
				 * at all when an ability declares no input schema -- which is the
				 * shape of every "list everything" and "get status" there is.
				 */
				'execute_callback'    => function ( $input = null ) use ( $ability ) {
					if ( \is_array( $input ) ) {
						// The code WordPress itself uses when an ability's schema rejects input.
						$prepared = $this->request->get_prepared_values( $ability, $input, 'ability_invalid_input' );

						if ( \is_wp_error( $prepared ) ) {
							return $prepared;
						}

						$input = $prepared;
					}

					/*
					 * Bound even with nothing to bind, because binding is also
					 * what takes the last call's arguments back off this same
					 * instance -- and an ability invoked with no input at all is
					 * exactly the call that would otherwise still be holding
					 * them.
					 */
					$this->request->bind( $ability, \is_array( $input ) ? $input : array() );

					return $ability->handle( $input );
				},
				'permission_callback' => function ( $input = null ) use ( $ability ) {
					$this->request->bind( $ability, \is_array( $input ) ? $input : array() );

					return $ability->permission_check( $input );
				},
				'meta'                => \array_merge(
					/*
					 * Written rather than left to be derived. Recent WordPress
					 * seeds `show_in_rest` from `public`, and one that does not
					 * leaves a public ability off the REST API with nothing said
					 * -- a 404 reading as a mistyped name. First in the merge, so
					 * meta() can still say `show_in_rest => false` and offer an
					 * ability to an MCP adapter while keeping it off REST.
					 */
					array( 'show_in_rest' => $ability->is_public() ),
					$ability->meta(),
					array(
						'annotations' => $ability->effect()->get_annotations(),
						'public'      => $ability->is_public(),
					)
				),
			);

			// Omitted rather than empty: an empty schema is a schema, and would
			// describe an ability that accepts or returns nothing at all.
			$input_schema = $ability->input_schema();
			if ( array() !== $input_schema ) {
				$args['input_schema'] = $input_schema;
			}

			$output_schema = $ability->output_schema();
			if ( array() !== $output_schema ) {
				$args['output_schema'] = $output_schema;
			}

			// The return is deliberately not checked: `wp_register_ability()`
			// calls `_doing_it_wrong()` on every refusal, so WordPress has
			// already reported it.
			\wp_register_ability( $this->get_ability_name( $name ), $args );
		}
	}

	/**
	 * Register the two hooks WordPress builds its ability registries on.
	 *
	 * Both fire the first time anything asks the registry a question, which may
	 * be an admin page, a REST request, or {@see run()} — and may never happen at
	 * all. Discovery is deferred to them, so a request that never touches
	 * abilities never reads the directory.
	 *
	 * @return void
	 *
	 * @internal
	 */
	protected function on_boot(): void {
		if ( ! \function_exists( 'wp_register_ability' ) ) {
			\_doing_it_wrong(
				__METHOD__,
				// Deliberately not translated: this runs at plugin load, before
				// `init`, where a __() call would itself trigger
				// _load_textdomain_just_in_time.
				'The Abilities module requires the Abilities API, added in WordPress 6.9. Nothing was registered.',
				'6.9.0'
			);

			return;
		}

		// Categories first: WordPress initializes that registry ahead of the
		// abilities one precisely so an ability can name a category registered here.
		\add_action( 'wp_abilities_api_categories_init', array( $this, 'register_categories' ) );
		\add_action( 'wp_abilities_api_init', array( $this, 'register_abilities' ) );
	}

	/**
	 * Every category to register: the ones declared, plus this plugin's own.
	 *
	 * The plugin's own is included only when an ability actually asks for it, so
	 * a plugin that files everything under `site` does not leave an empty group
	 * in every client that lists categories.
	 *
	 * @return array<string, array{label: string|callable(): string, description: string|callable(): string|null}>
	 * @throws DiscoveryException When discovery fails.
	 */
	private function get_all_categories(): array {
		$categories = $this->categories;
		$own        = $this->get_category_slug();

		if ( isset( $categories[ $own ] ) ) {
			return $categories;
		}

		foreach ( $this->get_discovered_abilities() as $ability ) {
			if ( $own !== $ability->category() ) {
				continue;
			}

			$name = (string) $this->get_plugin()->get_header( 'Name' );

			$categories[ $own ] = array(
				'label'       => '' !== $name ? $name : $own,
				'description' => null,
			);

			break;
		}

		return $categories;
	}

	/**
	 * This plugin's half of every ability name, and its own category's slug.
	 *
	 * @return string
	 */
	private function get_namespace(): string {
		// No check here: `Plugin` accepts only a slug WordPress would take as one
		// half of an ability name, so this half is registrable by construction.
		return $this->get_plugin()->get_slug();
	}

	/**
	 * Whether WordPress would accept a value as one half of an ability name.
	 *
	 * The registry matches `^[a-z0-9-]+/[a-z0-9-]+$` (see
	 * `WP_Abilities_Registry::register()`), so each half takes lowercase letters,
	 * digits and dashes and nothing else. Underscores are the common case in a
	 * plugin slug and a filename, and are not allowed here.
	 *
	 * A test rather than a converter: reducing the name to fit would register an
	 * ability under something other than what its file is called, and `run()`
	 * takes the name on disk.
	 *
	 * @param string $value The half to check.
	 * @return bool
	 */
	private function is_registrable_segment( string $value ): bool {
		return 1 === \preg_match( '/^[a-z0-9-]+$/', $value );
	}

	/**
	 * Resolve a value that may have been given as a callable.
	 *
	 * @param string|callable(): string $value The declared value.
	 * @return string
	 */
	private function get_resolved_string( $value ): string {
		return \is_callable( $value ) ? (string) $value() : (string) $value;
	}
}
