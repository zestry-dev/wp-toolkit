<?php

/**
 * Icons API: Icons module
 */

declare( strict_types=1 );

namespace Zestry\WPToolkit\Modules\IconsLibrary;

// Loaded by WordPress, never requested directly.
\defined( 'ABSPATH' ) || exit;

use Zestry\WPToolkit\Kernel\Abstracts\Module;
use Zestry\WPToolkit\Kernel\Exceptions\DiscoveryException;
use Zestry\WPToolkit\Kernel\Helpers\Str;
use Zestry\WPToolkit\Kernel\Traits\WithFolderWalker;
use Zestry\WPToolkit\Services\Path;

/**
 * Publishes your plugin's SVG icons, for the Icon block and for your own markup.
 *
 * An icon is a file in `svg-icons/`. `arrow-right.php` registers as
 * `{plugin-slug}/arrow-right` -- offered in the editor's icon picker under a
 * collection named after your plugin, served on the REST API at `wp/v2/icons`,
 * and rendered in PHP as `$this->icons->get( 'arrow-right' )`. Requires
 * WordPress 7.1 or newer.
 *
 * > [!IMPORTANT]
 * > **WordPress keeps `<svg>`, `<path>` and `<polygon>` and throws the rest
 * > away.** It sanitizes every icon through `wp_kses()`, so a `<circle>`, a
 * > `<g>`, a `<rect>` or a `<use>` is removed, as is any attribute outside a
 * > short list -- `stroke` among them, which silently empties an icon drawn as
 * > outlines rather than fills. Export icons as filled paths.
 * >
 * > With your plugin's own debug mode on — `wp zt debug on` — an icon that would
 * > lose anything throws a {@see DiscoveryException} naming the file and what it
 * > lost, rather than registering something that renders as a blank square.
 *
 * @example An icon
 * **Write icons as `.php`.** The template echoes the SVG and returns what the
 * icon is called, which is the only shape that lets the label be translated: one
 * derived from a filename cannot be, and one kept in a second file has to be kept
 * in step with the first.
 *
 * ```
 * <!-- svg-icons/arrow-right.php -->
 * <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24">
 *     <path d="M5 12h14" />
 * </svg>
 * <?php
 * return array(
 *     'label' => __( 'Arrow, pointing right', 'acme-plugin' ),
 * );
 * ```
 *
 * The label is what the picker shows and what a screen reader announces, so it
 * is worth writing. Return nothing and it is built from the filename instead --
 * `arrow-right.php` becomes "Arrow Right", which is serviceable and
 * untranslated.
 *
 * An array rather than the label alone, so an icon can say a second thing later
 * without every template that names itself having to change shape.
 *
 * @example A plain `.svg`, when that is all you need
 * A bare `.svg` in the same directory is registered too, straight from the file
 * a designer exported -- nothing to write, and WordPress reads it only when the
 * icon is actually rendered.
 *
 * ```
 * svg-icons/
 *   arrow-right.php    a template: translated label, run on every request
 *   logo.svg           a file: label built from the filename, read on demand
 * ```
 *
 * The label is the whole of the difference. `logo.svg` is announced as "Logo" in
 * every language, so reach for `.svg` where nobody reads the name -- and rename
 * the file to `.php` the moment somebody does. Both spellings of one name is an
 * error rather than a preference: `arrow.php` and `arrow.svg` are one icon.
 *
 * @example Using one
 * ```
 * public IconsLibrary $icons;
 *
 * public function render(): string {
 *     return $this->icons->get( 'arrow-right', array( 'size' => 32 ) );
 * }
 * ```
 *
 * @example Naming one something other than its file
 * `name` replaces the one taken from the filename, which is how an icon keeps a
 * name a filename could not carry:
 *
 * ```
 * <!-- svg-icons/logo-2024.php -->
 * <svg …>…</svg>
 * <?php
 * return array(
 *     'name'  => 'brand_mark',
 *     'label' => __( 'Acme logo', 'acme-plugin' ),
 * );
 * ```
 *
 * **Do not put your plugin slug in it.** WordPress names an icon
 * `collection/icon-name`, and the collection *is* your plugin -- so the name
 * here is the bare half after the slash, and `{plugin-slug}/brand_mark` is what
 * gets registered. A name you prefix yourself registers as
 * `{plugin-slug}/{plugin-slug}-brand_mark`.
 *
 * The filename stays the default, and stays the thing to reach for. A declared
 * name is a second place the answer lives, so it earns its keep only when the
 * file cannot be called what the icon is.
 *
 * @setup-hook init
 * @setup-hook-priority 100
 * @setup Group them
 * ```
 * IconsLibrary::class => array(
 *     'boots_on'    => 'init',
 *     'priority'    => 100,
 *     'before_boot' => static function ( IconsLibrary $icons ): void {
 *         $icons->set_default_collection_details(
 *             __( 'Acme icons', 'acme-plugin' ),
 *             __( 'Everything Acme draws.', 'acme-plugin' )
 *         );
 *
 *         $icons->add_collections(
 *             array( 'acme-brand' => __( 'Acme brand', 'acme-plugin' ) )
 *         );
 *     },
 * ),
 * ```
 *
 * You have one collection already, slugged with your plugin slug and labelled
 * `{slug} icons` until you say otherwise. `before_boot` runs on the hook, right
 * before the module registers anything -- which is what makes the `__()` calls
 * safe, and why this module names a hook at all.
 *
 * Late on `init` because it goes after WordPress's own registries, built at 0
 * and 10, and after any other plugin registering a collection an icon of yours
 * might name.
 */
class IconsLibrary extends Module {

	use WithFolderWalker;

	/**
	 * Where icons are discovered, relative to the plugin root.
	 */
	const SVG_ICONS_ROOT = 'svg-icons';

	/**
	 * @var Path
	 */
	public Path $path;

	/**
	 * Discovered icons as local name => absolute path, once the directory has been walked.
	 *
	 * @var array<string, string>|null
	 */
	private ?array $discovered = null;

	/**
	 * Collections declared through add_collections(), in declaration order.
	 *
	 * @var array<string, array{label: string, description: string}>
	 */
	private array $collections = array();

	/**
	 * Label and description for the plugin's own collection, once stated.
	 *
	 * @var array{label: string, description: string}|null
	 */
	private ?array $default_collection = null;

	/**
	 * The full name each local name registered under, filled as they register.
	 *
	 * @var array<string, string>
	 */
	private array $registered = array();

	/**
	 * Declare icon collections of your own.
	 *
	 * Every icon belongs to exactly one collection, and WordPress groups the
	 * editor's picker by them. You already have one named after your plugin,
	 * registered for you and used by default -- this is for splitting a larger set
	 * into groups a designer can find things in, or for a collection shared with
	 * another plugin of yours.
	 *
	 * Keyed by slug, the same shape `bootstrap.php` uses for modules. A plain
	 * string is the label, and an array carries a description alongside it:
	 *
	 * ```
	 * $icons->on_wp_init(
	 *     static function ( IconsLibrary $icons ): void {
	 *         $icons->add_collections(
	 *             array(
	 *                 'acme-brand' => __( 'Acme brand', 'acme-plugin' ),
	 *                 'acme-ui'    => array(
	 *                     'label'       => __( 'Acme interface', 'acme-plugin' ),
	 *                     'description' => __( 'Arrows, spinners and toggles.', 'acme-plugin' ),
	 *                 ),
	 *             )
	 *         );
	 *     }
	 * );
	 *
	 * // svg-icons/logo.php
	 * return array(
	 *     'collection' => 'acme-brand',
	 *     'label'      => __( 'Acme logo', 'acme-plugin' ),
	 * );
	 * ```
	 *
	 * A slug is registered exactly as given and is not namespaced to the plugin,
	 * matching WordPress's own unprefixed `core` -- so choose slugs distinctive
	 * enough not to collide. One another plugin already registered is left as it
	 * is rather than replaced, and an icon may file itself under it.
	 *
	 * **Call it from the entry's `before_boot`, as the example does.** A label and
	 * a description are both user-visible, so they want translating, and
	 * `before_boot` runs on the boot hook rather than at plugin load, where a
	 * `__()` reports `_load_textdomain_just_in_time` on every request.
	 *
	 * @param array<string, string|array{label: string, description?: string}> $collections Labels or configuration, keyed by slug.
	 * @return void
	 * @throws \InvalidArgumentException When an entry is an array without a label.
	 */
	public function add_collections( array $collections ): void {
		foreach ( $collections as $slug => $collection ) {
			$is_config = \is_array( $collection );

			if ( $is_config && ! isset( $collection['label'] ) ) {
				throw new \InvalidArgumentException(
					\sprintf( 'Icon collection "%s" needs a label.', (string) $slug )
				);
			}

			$this->collections[ (string) $slug ] = array(
				'label'       => $is_config ? $collection['label'] : $collection,
				'description' => $is_config ? ( $collection['description'] ?? '' ) : '',
			);
		}
	}

	/**
	 * Name the collection your plugin gets by default.
	 *
	 * Its slug is your plugin slug and stays that way -- this is the label a
	 * designer reads in the picker, and the description under it. Without one the
	 * label is `{slug} icons`, which is accurate and says nothing.
	 *
	 * The description is empty by default and stays out of the registration
	 * entirely when it is: an absent description is honest, where a generated
	 * sentence occupies the space a real one would go in.
	 *
	 * **Call it from the entry's `before_boot`**, for the reason
	 * {@see add_collections()} gives -- both of these are read by a person, so
	 * both want translating.
	 *
	 * @param string $label       What the picker calls this collection.
	 * @param string $description One sentence under it, or '' for none.
	 * @return void
	 */
	public function set_default_collection_details( string $label, string $description = '' ): void {
		$this->default_collection = array(
			'label'       => $label,
			'description' => $description,
		);
	}

	/**
	 * Every discovered icon, as local name => absolute path.
	 *
	 * @return array<string, string>
	 * @throws DiscoveryException When a name cannot be registered.
	 */
	public function get_discovered_icons(): array {
		if ( null !== $this->discovered ) {
			return $this->discovered;
		}

		$root_dir = $this->path->get_plugin_path( self::SVG_ICONS_ROOT );

		if ( ! \is_dir( $root_dir ) ) {
			// Never named, and the default is absent: this plugin has none of
			// these yet. Only a directory asked for by name is missing in the
			// sense worth throwing over.
			$this->discovered = array();

			return $this->discovered;
		}

		$this->discovered = array();

		foreach ( $this->walk_folder( $root_dir, array( 'php', 'svg' ), 1 ) as $file ) {
			$name = \pathinfo( $file, PATHINFO_FILENAME );

			// The only way two icon files can collide: `arrow.php` and
			// `arrow.svg` are one name, and only one of them can be it.
			if ( isset( $this->discovered[ $name ] ) ) {
				throw DiscoveryException::name_collision(
					'icons',
					$name,
					\basename( $this->discovered[ $name ] ),
					$file
				);
			}

			$this->discovered[ $name ] = Str::join_path( $root_dir, $file );
		}

		return $this->discovered;
	}

	/**
	 * The full name an icon registers under.
	 *
	 * Namespaced to the plugin, since icons share one registry with every other
	 * plugin on the site, and joined with the `/` that registry expects. Both
	 * halves are read exactly as written, so `arrow-right` in a plugin slugged
	 * `acme-plugin` registers as `acme-plugin/arrow-right`.
	 *
	 * An icon in a collection of its own is named for that collection instead,
	 * since the collection *is* the half before the slash.
	 *
	 * @param string      $name       The icon's local name.
	 * @param string|null $collection The collection it belongs to, or null for the plugin's own.
	 * @return string
	 */
	public function get_icon_name( string $name, ?string $collection = null ): string {
		return null === $collection
			? $this->get_plugin()->get_namespaced_name( $name, '/' )
			: $collection . '/' . $name;
	}

	/**
	 * The slug of the icon collection registered for this plugin.
	 *
	 * Your plugin slug, which WordPress accepts as a collection slug by
	 * construction. It is what groups your icons in the editor's picker, and it is
	 * registered only if you actually have an icon to put in it.
	 *
	 * @return string
	 */
	public function get_collection_slug(): string {
		// No check here: `Plugin` accepts only a slug WordPress would take as a
		// collection slug, so this is registrable by construction.
		return $this->get_plugin()->get_slug();
	}

	/**
	 * The markup for one of this plugin's icons.
	 *
	 * Takes the local name and applies your namespace, so `get( 'arrow-right' )`
	 * renders `{plugin-slug}/arrow-right`. The markup comes back sanitized and
	 * ready to echo.
	 *
	 * `size` is width and height in pixels, 24 by default; pass `null` to leave
	 * the SVG's own dimensions alone. `class` adds class names, and `label` is the
	 * text a screen reader announces -- without one the icon is marked
	 * `aria-hidden`, which is right for an icon sitting beside its own label and
	 * wrong for one standing alone.
	 *
	 * The collection is worked out for you: an icon that filed itself under one of
	 * your own is still reached by its local name. Name the collection yourself
	 * only to tell two icons of the same name apart.
	 *
	 * @param string               $name       The icon's local name.
	 * @param array<string, mixed> $args       `size`, `class` and `label`, as `wp_get_icon()` takes them.
	 * @param string|null          $collection Which collection to read it from.
	 * @return string The SVG markup, or an empty string if there is no such icon.
	 */
	public function get( string $name, array $args = array(), ?string $collection = null ): string {
		if ( null !== $collection ) {
			return \wp_get_icon( $this->get_icon_name( $name, $collection ), $args );
		}

		return \wp_get_icon( $this->registered[ $name ] ?? $this->get_icon_name( $name ), $args );
	}

	/**
	 * Register this plugin's collections and every discovered icon with WordPress.
	 *
	 * Read first and registered second, in two passes: an icon may name a
	 * collection, so which collections exist is not known until every icon has
	 * been read -- and a collection has to exist before an icon points at it.
	 *
	 * @return void
	 * @throws DiscoveryException When discovery fails, or an icon renders nothing, names a collection that does not exist, or would not survive sanitizing.
	 *
	 * @internal
	 */
	public function register_icons(): void {
		$icons = $this->read_icons();

		// Declaring a collection is a deliberate act, so the declared ones are
		// registered whether or not an icon uses them -- a plugin that has named
		// its groups and drawn nothing yet still gets them.
		$this->register_collections();

		if ( array() === $icons ) {
			return;
		}

		// The plugin's own is derived rather than asked for, so it waits until
		// there is something to put in it. Before the icons, which may name it.
		$this->register_default_collection();

		/*
		 * This plugin's own switch, not `WP_DEBUG`: the check reads every icon on
		 * every request, and that is a cost to ask for while working on this
		 * plugin rather than one to impose on every site with debugging on.
		 */
		$debugging = $this->get_plugin()->is_plugin_debug();
		$registry  = \WP_Icon_Collections_Registry::get_instance();

		foreach ( $icons as $icon ) {
			// Checked against the registry rather than against what this module
			// declared, so an icon may join `core` or a collection another plugin
			// of yours registered -- and one naming nothing at all is refused
			// here, while the file that asked for it is still in hand.
			if ( ! $registry->is_registered( $icon['collection'] ) ) {
				throw DiscoveryException::unknown_icon_collection( $icon['file'], $icon['collection'] );
			}

			$icon_name = $this->get_icon_name( $icon['name'], $icon['collection'] );

			/*
			 * A template has already been run, so its markup is in hand. An
			 * `.svg` is handed over as a path instead, which WordPress reads and
			 * sanitizes only when the icon is actually rendered -- so a page
			 * using none of them reads none of the files.
			 */
			\wp_register_icon(
				$icon_name,
				null === $icon['markup']
					? array(
						'label'     => $icon['label'],
						'file_path' => $icon['path'],
					)
					: array(
						'label'   => $icon['label'],
						'content' => $icon['markup'],
					)
			);

			// First one wins, so a name in two collections still resolves through
			// get() -- naming the collection is how the other is reached.
			$this->registered[ $icon['name'] ] ??= $icon_name;

			if ( $debugging ) {
				$this->assert_nothing_is_stripped(
					$icon['file'],
					$icon['markup'] ?? (string) \file_get_contents( $icon['path'] ),
					$icon_name
				);
			}
		}
	}

	/**
	 * Register the icons once WordPress is ready to take them.
	 *
	 * Deferred to `init`, which is where WordPress builds both icon registries --
	 * its collections at priority 0 and its own icons at 10 -- and to the end of
	 * it, for the reasons at the call below.
	 *
	 * @return void
	 *
	 * @internal
	 */
	protected function on_boot(): void {
		if ( ! \function_exists( 'wp_register_icon' ) ) {
			\_doing_it_wrong(
				__METHOD__,
				// Deliberately not translated: this runs at plugin load, before
				// `init`, where a __() call would itself trigger
				// _load_textdomain_just_in_time.
				'The icons module requires the Icons API, added in WordPress 7.1. Nothing was registered.',
				'7.1.0'
			);

			return;
		}

		/*
		 * Late on `init`, where WordPress registers its own at 0 and 10. Nothing
		 * in core requires it -- `wp_register_icon()` splits the name and checks
		 * only the half after the slash, never that the collection exists -- but
		 * two things here do.
		 *
		 * `add_collections()` and `set_default_collection_details()` are called
		 * from `on_wp_init()`, which defaults to 10. At 10 this would work only
		 * because a module's initializer runs before its boot, so the consumer's
		 * callback happens to be added first; running later makes that ordering a
		 * fact rather than a coincidence.
		 *
		 * And an icon may name a collection another plugin registers, which this
		 * module refuses if it cannot find it. Going last is what gives that
		 * plugin its turn.
		 */
		$this->on_wp_init(
			static function ( self $module ): void {
				$module->register_icons();
			},
			100
		);
	}

	/**
	 * What an icon template said about itself, in the two shapes it may say it.
	 *
	 * `label`, `name` for an icon whose filename cannot be what it is called, and
	 * `collection` for one belonging somewhere other than the plugin's own.
	 * Anything that is not an array -- including the `1` PHP hands back for a
	 * template that returns nothing -- says nothing, and the defaults stand.
	 *
	 * @param mixed $returned Whatever the template returned.
	 * @return array{label?: string, name?: string, collection?: string}
	 */
	private function get_declared( mixed $returned ): array {
		if ( ! \is_array( $returned ) ) {
			return array();
		}

		$declared = array();

		foreach ( array( 'label', 'name', 'collection' ) as $key ) {
			if ( isset( $returned[ $key ] ) && \is_string( $returned[ $key ] ) && '' !== \trim( $returned[ $key ] ) ) {
				$declared[ $key ] = \trim( $returned[ $key ] );
			}
		}

		return $declared;
	}

	/**
	 * Read every discovered icon, without registering anything.
	 *
	 * A template is run here, which is what produces both its markup and what it
	 * says about itself; an `.svg` says nothing and is left on disk. Two files
	 * reaching one name in one collection is refused, which a declared `name` is
	 * the only way to cause.
	 *
	 * @return array<int, array{file: string, path: string, name: string, label: string, collection: string, markup: string|null}>
	 * @throws DiscoveryException When discovery fails, an icon renders nothing, or two reach one name.
	 */
	private function read_icons(): array {
		$icons   = array();
		$claimed = array();

		foreach ( $this->get_discovered_icons() as $file_name => $path ) {
			$file     = \basename( $path );
			$declared = array();
			$markup   = null;

			if ( \str_ends_with( $path, '.php' ) ) {
				$included = $this->path->include_file( $path );
				$declared = $this->get_declared( $included['returned'] );
				$markup   = \trim( $included['buffer'] );

				if ( '' === $markup ) {
					throw DiscoveryException::empty_icon_template( $file );
				}
			}

			$name       = $declared['name'] ?? $file_name;
			$collection = $declared['collection'] ?? $this->get_collection_slug();

			// Checked here rather than left to the registry: WordPress refuses a
			// name outside its charset with a _doing_it_wrong() that names no
			// file, and nothing here rewrites the name to fit.
			if ( ! $this->is_registrable_segment( $name ) ) {
				throw DiscoveryException::unregistrable_icon_name( $file, $this->get_icon_name( $name, $collection ) );
			}

			$key = $collection . '/' . $name;

			if ( isset( $claimed[ $key ] ) ) {
				throw DiscoveryException::name_collision( 'icons', $key, $claimed[ $key ], $file );
			}

			$claimed[ $key ] = $file;

			$icons[] = array(
				'file'       => $file,
				'path'       => $path,
				'name'       => $name,
				'label'      => $declared['label'] ?? Str::headline( $name ),
				'collection' => $collection,
				'markup'     => $markup,
			);
		}

		return $icons;
	}

	/**
	 * Register every collection this plugin's icons are grouped under.
	 *
	 * The ones {@see add_collections()} declared, and only those. Nothing here asks
	 * which the icons actually use: declaring one is already a deliberate act, and
	 * a usage check would earn a plugin nothing but a group it asked for going
	 * missing.
	 *
	 * @return void
	 */
	private function register_collections(): void {
		$collections = $this->collections;

		foreach ( $collections as $slug => $collection ) {
			$this->register_collection( $slug, $collection );
		}
	}

	/**
	 * Register one collection, unless something already owns the slug.
	 *
	 * Left alone rather than replaced: registering over one WordPress or another
	 * plugin already has is an error there, and the existing one is what icons
	 * naming that slug reference.
	 *
	 * @param string                                     $slug       The collection slug.
	 * @param array{label: string, description: string}  $collection Its label and description.
	 * @return void
	 */
	private function register_collection( string $slug, array $collection ): void {
		if ( \WP_Icon_Collections_Registry::get_instance()->is_registered( $slug ) ) {
			return;
		}

		$args = array( 'label' => $collection['label'] );

		// Omitted rather than empty: an absent description is honest, where one
		// generated from the label occupies the space a real one goes in.
		if ( '' !== $collection['description'] ) {
			$args['description'] = $collection['description'];
		}

		\wp_register_icon_collection( $slug, $args );
	}

	/**
	 * Register the collection named after this plugin.
	 *
	 * Separate from {@see register_collections()} because it is derived rather than
	 * asked for: registering it with no icons in it would put an empty group in
	 * every picker for the sole reason that the module is installed.
	 *
	 * A declaration for the same slug wins, so naming it through
	 * {@see add_collections()} and through {@see set_default_collection_details()}
	 * cannot both apply.
	 *
	 * @return void
	 */
	private function register_default_collection(): void {
		$slug = $this->get_collection_slug();

		if ( isset( $this->collections[ $slug ] ) ) {
			return;
		}

		$this->register_collection( $slug, $this->get_default_collection() );
	}

	/**
	 * Label and description for the collection named after this plugin.
	 *
	 * @return array{label: string, description: string}
	 */
	private function get_default_collection(): array {
		return $this->default_collection ?? array(
			'label'       => \sprintf(
				/* translators: %s: the plugin slug. */
				\__( '%s icons', 'zestry-toolkit' ),
				$this->get_collection_slug()
			),
			'description' => '',
		);
	}

	/**
	 * Refuse an icon WordPress would quietly cut down.
	 *
	 * `wp_kses()` removes what it does not recognise and keeps the rest, so an
	 * icon using anything outside `<svg>`, `<path>` and `<polygon>` registers
	 * without complaint and renders as a fragment of itself -- most often as
	 * nothing at all, since a stroked outline loses every attribute that drew it.
	 * WordPress only says something when the whole file sanitizes away.
	 *
	 * Asked by rendering the icon and comparing what came back against what the
	 * template produced, rather than by holding a copy of the allowed list here:
	 * the answer then comes from the same `wp_kses()` call that will run in
	 * production, and cannot fall behind it.
	 *
	 * @param string $file      The template's path, for the message.
	 * @param string $content   What the template rendered.
	 * @param string $icon_name The name it registered under.
	 * @return void
	 * @throws DiscoveryException When sanitizing would remove anything.
	 */
	private function assert_nothing_is_stripped( string $file, string $content, string $icon_name ): void {
		$lost = \array_diff(
			$this->get_markup_names( $content ),
			// `size` null so nothing is resized: this asks what survived, and a
			// width WordPress writes in is not something the template lost.
			$this->get_markup_names( \wp_get_icon( $icon_name, array( 'size' => null ) ) )
		);

		if ( array() === $lost ) {
			return;
		}

		throw DiscoveryException::stripped_icon_markup( $file, $lost );
	}

	/**
	 * Every element and attribute name some markup uses.
	 *
	 * Names only -- `<path>` and `path[stroke]` -- since the comparison this feeds
	 * is about what survived rather than about how it was written, and `wp_kses()`
	 * rewrites quoting and spacing on everything it keeps.
	 *
	 * @param string $markup The markup to read.
	 * @return string[] Unique names, in the order they appear.
	 */
	private function get_markup_names( string $markup ): array {
		$names = array();

		\preg_match_all( '/<([a-z][a-z0-9:-]*)((?:[^>"\']|"[^"]*"|\'[^\']*\')*)>/i', $markup, $tags, PREG_SET_ORDER );

		foreach ( $tags as $tag ) {
			$element = \strtolower( $tag[1] );
			$names[] = '<' . $element . '>';

			\preg_match_all( '/([a-z][a-z0-9:-]*)\s*=/i', $tag[2], $attributes );

			foreach ( $attributes[1] as $attribute ) {
				$names[] = $element . '[' . \strtolower( $attribute ) . ']';
			}
		}

		return \array_values( \array_unique( $names ) );
	}

	/**
	 * Whether WordPress would accept a value as one half of an icon name.
	 *
	 * Both registries match `^[a-z0-9]([a-z0-9_-]*[a-z0-9])?$`, so a name takes
	 * lowercase letters, digits, dashes and underscores, and has to start and end
	 * with a letter or digit.
	 *
	 * A test rather than a converter: reducing the name to fit would register an
	 * icon under something other than what it is called, and {@see get()} takes
	 * the name as written.
	 *
	 * @param string $value The half to check.
	 * @return bool
	 */
	private function is_registrable_segment( string $value ): bool {
		return 1 === \preg_match( '/^[a-z0-9]([a-z0-9_-]*[a-z0-9])?$/', $value );
	}
}
