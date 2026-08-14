<?php

/**
 * Devtool command: `wp zt make route <name>`.
 *
 * Generates a new REST route stub into a project already set up with `wp zt init`.
 */

declare( strict_types=1 );

use Zestry\WPToolkit\DevTools\Abstracts\MakeCommand;

return new class() extends MakeCommand {

	/**
	 * Generate a new REST route.
	 *
	 * The RestApi module discovers it. On `rest_api_init` it walks your
	 * `routes/` directory at any depth, requires every file in it, and hands the
	 * `Route` each one returns to `register_rest_route()` under
	 * `{plugin-slug}/{version}`. Writing the file is the whole registration;
	 * nothing has to be declared anywhere, and subdirectories are organization
	 * only, not part of the URL.
	 *
	 * Needs the `rest-api` module, so run `wp zt add rest-api` first if
	 * you have not already.
	 *
	 * ## OPTIONS
	 *
	 * <name>
	 * : The local name, e.g. 'get-widget'. Becomes the filename (`{name}.php`)
	 * under `routes/`.
	 *
	 *
	 * [--method=<method>]
	 * : The HTTP method: get, post, put, patch, or delete. Prompted for when
	 * not given.
	 *
	 * [--version=<version>]
	 * : The REST namespace version, e.g. 'v1'. Prompted for when not given.
	 *
	 * [--pattern=<pattern>]
	 * : The URL pattern, e.g. '/widgets/{id}'. Prompted for when not given.
	 *
	 * [--yes]
	 * : Overwrite an existing file without asking, and take the default for
	 * every prompt below rather than asking, for an unattended run.
	 *
	 * ## EXAMPLES
	 *
	 *     # Generate a REST route, prompting for method/version/pattern.
	 *     $ wp zt make route get-widget
	 *     HTTP method (get, post, put, patch, delete): (default: get)
	 *     Namespace version: (default: v1)
	 *     URL pattern: (default: /get-widget)
	 *     Success: Created routes/get-widget.php
	 *
	 * @param array $args
	 * @param array $assoc_args
	 * @return void
	 */
	public function handle( array $args, array $assoc_args ): void {
		parent::handle( $args, $assoc_args );
	}

	/**
	 * Prompt for a REST route's method, namespace version, and URL pattern.
	 *
	 * @param string $name       The local route name, used to default the pattern.
	 * @param array  $assoc_args WP-CLI's named arguments, checked before prompting.
	 * @return array{method: string, method_upper: string, version: string, pattern: string}
	 */
	protected function get_extra_values( string $name, array $assoc_args ): array {
		$valid_methods = array( 'get', 'post', 'put', 'patch', 'delete' );

		$method = $this->get_flag( $assoc_args, 'method', null )
			?? $this->ask( 'HTTP method (' . implode( ', ', $valid_methods ) . '):', 'get' );
		$method = strtolower( $method );

		if ( ! in_array( $method, $valid_methods, true ) ) {
			$this->warning( 'Invalid method "' . $method . '", defaulting to "get".' );
			$method = 'get';
		}

		$version = $this->get_flag( $assoc_args, 'version', null )
			?? $this->ask( 'Namespace version:', 'v1' );

		$pattern = $this->get_flag( $assoc_args, 'pattern', null )
			?? $this->ask( 'URL pattern:', '/' . $name );

		return array(
			'method'       => $method,
			'method_upper' => strtoupper( $method ),
			'version'      => $version,
			'pattern'      => $pattern,
		);
	}

	protected function get_stub(): string {
		return 'route.php.stub';
	}

	protected function get_default_dir( array $config ): string {
		return 'routes';
	}

	protected static function get_type(): string {
		return 'route';
	}
};
