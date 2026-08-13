<?php

/**
 * Devtool command: `wp zt make page <name>`.
 *
 * Generates a new admin page stub into a project already set up with `wp zt init`.
 */

declare( strict_types=1 );

use Zestry\WPToolkit\DevTools\Abstracts\MakeCommand;
use Zestry\WPToolkit\DevTools\Copier;

return new class() extends MakeCommand {

	/**
	 * Whether a template is being written alongside the page class.
	 *
	 * @var bool
	 */
	private bool $write_view = true;

	/**
	 * The plugin-relative views root the template goes under.
	 *
	 * @var string
	 */
	private string $views_dir = 'views';

	/**
	 * Generate a new admin page.
	 *
	 * The AdminPages module discovers it. At boot it walks your `admin-pages/`
	 * directory at any depth, requires every file in it, and registers the
	 * `AdminPage` each one returns through `add_menu_page()` or
	 * `add_submenu_page()` -- nested directories become nested menus. Writing
	 * the file is the whole registration; nothing has to be declared anywhere.
	 *
	 * The page's slug becomes `?page={plugin-slug}-{name}`, so it can hold only
	 * what a URL does not have to encode: a name holding anything else is written
	 * as one that survives, and the command says what it wrote.
	 *
	 * Two files, the way `make block` writes several: the page class, and the
	 * template it renders. An admin page is mostly a form -- tables, fields,
	 * notices, a second form further down -- and markup assembled by
	 * concatenation stops being reviewable long before it stops growing. A page
	 * with one field does not need a template, and costs nothing for having
	 * one; the point is that nobody has to notice when the threshold passed.
	 *
	 * Needs the `admin-pages` module, so run `wp zt add module admin-pages`
	 * first if you have not already. It brings `views` with it, which is what
	 * renders the template.
	 *
	 * ## OPTIONS
	 *
	 * <name>
	 * : The local name, e.g. 'settings'. Becomes the filename (`{name}.php`)
	 * under `admin-pages/`.
	 *
	 *
	 * [--no-view]
	 * : Skip the template, and generate a `render()` that echoes its own markup
	 * instead of rendering one. The page class is written either way.
	 *
	 * [--views-dir=<dir>]
	 * : Write the template under this plugin-relative directory instead of
	 * `views` -- pass it when you have pointed the Views service's root
	 * somewhere other than its default.
	 *
	 * [--yes]
	 * : Overwrite an existing file without asking, for an unattended run.
	 *
	 * [--extends=<class>]
	 * : Extend one of your own abstracts instead of the toolkit base. A bare name
	 * is looked for under your Abstracts\ namespace; the generated file stubs the
	 * methods that class leaves abstract, and nothing it has already settled.
	 *
	 * ## EXAMPLES
	 *
	 *     # Generate an admin page and the template it renders.
	 *     $ wp zt make page settings
	 *     Success: Created admin-pages/settings.php
	 *     Created views/admin-pages/settings.php
	 *
	 *     # Just the class, for a page that renders almost nothing.
	 *     $ wp zt make page ping --no-view
	 *     Success: Created admin-pages/ping.php
	 *
	 * @param array $args
	 * @param array $assoc_args
	 * @return void
	 */
	public function handle( array $args, array $assoc_args ): void {
		parent::handle( $args, $assoc_args );
	}

	public function get_base_class(): ?string {
		return 'Modules\AdminPages\AdminPage';
	}

	/**
	 * Write the template the generated page renders.
	 *
	 * Here rather than through the stub-directory mechanism `make block` uses,
	 * because the two files do not land in one tree: the class goes under
	 * `admin-pages/` and the template under the views root, and a stub directory
	 * writes everything into a single destination.
	 *
	 * Skipped silently when the file is already there. Regenerating a page
	 * should not overwrite markup someone has written.
	 *
	 * @param string                                                          $name        The local name given on the command line.
	 * @param string                                                          $plugin_root Absolute path to the consuming plugin's root.
	 * @param array{namespace: string, root: string, text_domain?: string}    $config      The project's zestry.json.
	 * @return void
	 */
	protected function after_write( string $name, string $plugin_root, array $config ): void {
		if ( ! $this->write_view ) {
			return;
		}

		$root     = rtrim( $plugin_root, '/\\' );
		$relative = $this->views_dir . '/admin-pages/' . $name . '.php';
		$target   = $root . '/' . $relative;

		if ( file_exists( $target ) ) {
			$this->log( $relative . ' already exists -- left as it is.' );

			return;
		}

		if ( ! is_dir( dirname( $target ) ) ) {
			wp_mkdir_p( dirname( $target ) );
		}

		$contents = $this->stub_renderer->render(
			$this->path->get_plugin_path( 'src/DevTools/stubs/page-view.php.stub' ),
			array(
				'name'             => $name,
				'title'            => $this->stub_renderer->to_title( $name ),
				'copied_namespace' => Copier::get_target_namespace( $config['namespace'] ),
				// The same domain the page class itself is stamped with: the two
				// files are one feature, and a template translated under another
				// domain is one nothing loads.
				'text_domain'      => self::get_text_domain( $config, $plugin_root ),
			)
		);

		if ( false === file_put_contents( $target, $contents ) ) {
			$this->warning( 'Failed to write ' . $relative );

			return;
		}

		$this->formatter->format( $plugin_root, array( $target ) );

		$this->log( 'Created ' . $relative );
	}

	/**
	 * Capture the answers `after_write()` needs, and pick the `render()` body.
	 *
	 * `after_write()` is handed none of the arguments, so the two flags are read
	 * here. `--no-view` has to reach the stub as well as the template writer:
	 * skipping the template while still generating a `render()` that calls one
	 * writes a page that throws the first time anyone opens it.
	 *
	 * @param string $name       The local name given on the command line.
	 * @param array  $assoc_args WP-CLI's named arguments.
	 * @return array<string, string>
	 */
	protected function get_extra_values( string $name, array $assoc_args ): array {
		// WP-CLI negates a declared `[--view]` flag to exactly false.
		$this->write_view = false !== ( $assoc_args['view'] ?? null );
		$this->views_dir  = trim( (string) $this->get_flag( $assoc_args, 'views-dir', 'views' ), '/\\' );

		return array(
			'render_note' => $this->get_render_note( $name ),
			'render_body' => $this->get_render_body( $name ),
		);
	}

	protected function get_stub(): string {
		return 'page.php.stub';
	}

	/**
	 * The name WordPress will accept, which is not always the one given.
	 *
	 * @param string $name The local name given on the command line.
	 * @return string
	 */
	protected function normalize_name( string $name ): string {
		return $this->stub_renderer->to_slug( $name );
	}

	/**
	 * @return string
	 */
	protected function get_name_constraint(): string {
		return 'a page slug becomes `?page={plugin-slug}-{name}`, so it can hold only what a URL does not have to encode.';
	}

	protected function get_default_dir( array $config ): string {
		return 'admin-pages';
	}

	/**
	 * The comment above the generated `render()`, which `--no-view` changes.
	 *
	 * @return string
	 */
	private function get_render_note( string $name ): string {
		if ( ! $this->write_view ) {
			return implode(
				"\n",
				array(
					"\t// No template: --no-view was given, so this echoes its own markup.",
					"\t// That works for something tiny and stops working sooner than it looks",
					"\t// -- an admin page grows a table, then a notice, then a second form.",
					"\t// `wp zt make view admin-pages/" . $name . '` writes one, and',
					"\t// `\$this->view( 'admin-pages/" . $name . "', array( ... ) )` renders it.",
				)
			);
		}

		return implode(
			"\n",
			array(
				"\t// The markup lives in views/admin-pages/" . $name . '.php, generated alongside',
				"\t// this file. The template gets exactly what is named here and nothing else",
				"\t// of this page, so its inputs are readable without opening it. Add your own",
				"\t// alongside these.",
				"\t//",
				"\t// Echoing markup from here works for something tiny, and stops working",
				"\t// sooner than it looks: an admin page grows a table, then a notice, then a",
				"\t// second form.",
			)
		);
	}

	/**
	 * The body of the generated `render()`, which `--no-view` changes.
	 *
	 * With a template, one `view()` call naming what the template gets. Without
	 * one, markup echoed here -- which is what `--no-view` is asking for, and
	 * the only shape that works, since the template it would otherwise render
	 * was deliberately not written.
	 *
	 * @param string $name The local name given on the command line.
	 * @return string
	 */
	private function get_render_body( string $name ): string {
		if ( ! $this->write_view ) {
			return implode(
				"\n",
				array(
					"\t\tprintf(",
					"\t\t\t'<div class=\"wrap\"><h1>%s</h1></div>',",
					"\t\t\tesc_html( \$this->title() )",
					"\t\t);",
				)
			);
		}

		return implode(
			"\n",
			array(
				"\t\t\$this->view(",
				"\t\t\t'admin-pages/" . $name . "',",
				"\t\t\tarray(",
				"\t\t\t\t'title'  => \$this->title(),",
				"\t\t\t\t'action' => \$this->get_page_url(),",
				"\t\t\t\t'nonce'  => \$this->get_nonce_action(),",
				"\t\t\t\t// Left by handle_submit() before it redirected. Reads once,",
				"\t\t\t\t// so a refresh shows no notice for a save that already",
				"\t\t\t\t// happened.",
				"\t\t\t\t'notice' => \$this->get_flash( '' ),",
				"\t\t\t)",
				"\t\t);",
			)
		);
	}

	protected static function get_type(): string {
		return 'page';
	}
};
