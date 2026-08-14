<?php

/**
 * Views API: Views module
 */

declare( strict_types=1 );

namespace Zestry\WPToolkit\Modules;

// Loaded by WordPress, never requested directly.
\defined( 'ABSPATH' ) || exit;

use Zestry\WPToolkit\Kernel\Abstracts\Module;

/**
 * Resolves and renders PHP view templates from the plugin directory.
 *
 * A view is an ordinary PHP file under `resources/views/`. Each key in the data array
 * becomes a local variable inside the template. Only names beginning
 * `__include_` are reserved -- the render scope holds two of them and nothing
 * else -- so every ordinary key reaches the template, `view` and `data`
 * included.
 *
 * The `.php` extension is optional, and a name may address a subdirectory, so
 * `'emails/receipt'` and `'emails/receipt.php'` resolve to the same file. A
 * name that escapes the views root is rejected.
 *
 * @example Rendering a view
 * `render()` echoes the template; `get()` returns it as a string.
 *
 * ```
 * $views = $plugin->get( Views::class );
 *
 * // Echoes resources/views/emails/receipt.php, with $order and $total in scope:
 * $views->render( 'emails/receipt', array(
 *     'order' => $order,
 *     'total' => $total,
 * ) );
 *
 * // Same, but returns the markup instead of echoing it:
 * $html = $views->get( 'emails/receipt', array( 'order' => $order ) );
 * ```
 *
 * @example Writing a template
 * The template is plain PHP, with the passed data as local variables. Inside
 * one, `$this` is this module, so a template renders a subview with the same
 * `render()` everything else uses -- and it costs no variable name to do it.
 *
 * ```
 * <!-- resources/views/emails/receipt.php -->
 * <h1><?php echo esc_html( $order->title ); ?></h1>
 * <?php $this->render( 'emails/-lines', array( 'lines' => $order->lines ) ); ?>
 * ```
 *
 * A template is included rather than called, so nothing tells your editor what
 * is in scope. Say so at the top and you get completion for all of it,
 * `$this` included -- which is what the generated templates do:
 *
 * ```
 * @var \Acme\Plugin\Core\Modules\Views $this
 * @var string                             $title
 * ```
 *
 * @example Rendering an admin page
 * This is the case most plugins reach for first, and it has a shortcut: an
 * {@see \Zestry\WPToolkit\Modules\AdminPages\AdminPage} calls `$this->view()` rather than
 * resolving this module. `wp zt make page` writes both files, and the
 * template gets exactly what the `render()` call names -- nothing of the page
 * itself, so its inputs are readable without opening it.
 *
 * ```
 * // resources/admin-pages/settings.php
 * public function render(): void {
 *     $this->view( 'admin-pages/settings', array( 'items' => $this->items ) );
 * }
 * ```
 *
 */
class Views extends Module {

	/**
	 * Default plugin-relative directory of view files.
	 */
	const VIEWS_ROOT = 'resources/views';

	/**
	 * Cached real (symlink-resolved) absolute path of the views root.
	 *
	 * @var string|false|null
	 */
	private string|false|null $real_root = null;

	/**
	 * Render a view directly to the current output stream.
	 *
	 * @param string               $view The view name.
	 * @param array<string, mixed> $data Variables made available to the view.
	 * @return void
	 * @throws \InvalidArgumentException When the views root or the view is missing, or the view resolves outside the root.
	 */
	public function render( string $view, array $data = array() ): void {
		echo $this->get( $view, $data ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Render a view and return its output as a string.
	 *
	 * Each key in `$data` becomes a template variable. For example,
	 * `get( 'card', array( 'title' => 'Hello' ) )` makes `$title` available to
	 * `resources/views/card.php`. Escape the data in the template according to context.
	 *
	 * The including is {@see \Zestry\WPToolkit\Modules\Path::include_file()}, which is
	 * also what reserves the names: only keys beginning `__include_` are, and
	 * every ordinary name reaches the template, `view` and `data` included.
	 * Rendering a subview costs no name at all, since a template reaches this
	 * module as `$this`.
	 *
	 * @param string               $view Logical view name.
	 * @param array<string, mixed> $data Variables made available to the view.
	 * @return string Rendered template output.
	 * @throws \InvalidArgumentException When the views root or the view is missing, or the view resolves outside the root.
	 */
	public function get( string $view, array $data = array() ): string {
		return $this->with( Path::class )->include_file( $this->normalize_view_path( $view ), $data, $this )['buffer'];
	}

	/**
	 * Resolve and cache the real absolute path of the configured views root.
	 *
	 * Only a successful resolution is cached, so a root created after a first miss
	 * still resolves on a later call.
	 *
	 * @return string|false The resolved root, or false when the directory is absent.
	 */
	private function get_real_root(): string|false {
		if ( $this->real_root === null ) {
			$resolved = \realpath( $this->with( Path::class )->get_plugin_path( self::VIEWS_ROOT ) );
			if ( $resolved === false ) {
				return false;
			}
			$this->real_root = $resolved;
		}

		return $this->real_root;
	}

	/**
	 * Convert a logical view name into an existing absolute PHP file path.
	 *
	 * Both `account/profile` and `account/profile.php` identify the same view.
	 * The resolved file must lie inside the configured views root: the real,
	 * symlink-resolved path is compared against the real path of the root, so a
	 * caller cannot escape it with `..` segments or a symlink and include an
	 * arbitrary file. An exception is thrown before rendering when the view is
	 * missing or resolves outside the root.
	 *
	 * When the resolved path falls outside the root, the exception message is
	 * deliberately generic ("Invalid view name.") rather than including the
	 * resolved path: disclosing the real filesystem path would leak server
	 * layout information to whatever surface eventually displays the error.
	 *
	 * @param string $view Logical view name relative to the configured root.
	 * @return string Absolute path to the template file.
	 * @throws \InvalidArgumentException When the views root is missing, the view is missing, or the view resolves outside the root.
	 */
	private function normalize_view_path( string $view ): string {
		if ( \str_ends_with( $view, '.php' ) ) {
			$view = \substr( $view, 0, -4 );
		}

		$full_path = $this->with( Path::class )->get_plugin_path( self::VIEWS_ROOT . '/' . $view . '.php' );

		// Resolve symlinks and .. segments on both the candidate and the root, then
		// require the candidate to sit inside the root. realpath() returns false for
		// a non-existent path, so this also covers the missing-view case.
		$real_view = \realpath( $full_path );
		$real_root = $this->get_real_root();

		// Both messages name the plugin-relative value, never the resolved
		// absolute path: unlike a discovery root, a view name can come from
		// request input, so this can surface to an attacker. Reported
		// separately so a mistyped root is not blamed on the view name.
		if ( false === $real_root ) {
			throw new \InvalidArgumentException( 'Views root directory does not exist: ' . self::VIEWS_ROOT );
		}

		if ( false === $real_view ) {
			throw new \InvalidArgumentException(
				\sprintf( 'View file does not exist: %s (in views root: %s)', $view, self::VIEWS_ROOT )
			);
		}

		if ( 0 !== \strpos( $real_view, $real_root . DIRECTORY_SEPARATOR ) ) {
			// Resolved outside the views root: refuse without leaking the resolved path.
			throw new \InvalidArgumentException( 'Invalid view name.' );
		}

		return $real_view;
	}
}
