<?php

/**
 * Devtool command: `wp zt make rule <name>`.
 *
 * Generates a validation rule of the plugin's own -- an attribute that narrows
 * a property, used on any route, ability, action or class that declares one.
 */

declare( strict_types=1 );

use Zestry\WPToolkit\DevTools\Abstracts\MakeCommand;
use Zestry\WPToolkit\DevTools\ConsumerPlugin;
use Zestry\WPToolkit\DevTools\ZestryConfig;

return new class() extends MakeCommand {

	/**
	 * Generate a validation rule of your own.
	 *
	 * Requires `wp zt init` to have already run in this plugin, and the
	 * `validation` module (`wp zt add validation`). The file lands in
	 * `{zestry.json root}/Rules/` and is reached by being used: put
	 * `#[Slug]` on a property and it applies there. Nothing is declared in
	 * `bootstrap.php`.
	 *
	 * ## OPTIONS
	 *
	 * <name>
	 * : The class name, e.g. 'Slug'. Becomes both the filename (`{name}.php`)
	 * and the attribute's name -- give it exactly as it should read after
	 * `#[`. Qualify it to group: `Posts/PublishedPost` writes
	 * `Rules/Posts/PublishedPost.php`.
	 *
	 * [--yes]
	 * : Overwrite an existing rule without asking, for an unattended run.
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp zt make rule Slug
	 *     Success: Created lib/Rules/Slug.php
	 *
	 *     $ wp zt make rule Posts/PublishedPost
	 *     Success: Created lib/Rules/Posts/PublishedPost.php
	 *
	 * @param array $args
	 * @param array $assoc_args
	 * @return void
	 */
	public function handle( array $args, array $assoc_args ): void {
		parent::handle( $args, $assoc_args );
	}

	/**
	 * Every rule extends the copied `Rule`.
	 *
	 * @return string|null
	 */
	public function get_base_class(): ?string {
		return 'Modules\\Validation\\Rule';
	}

	/**
	 * Fill in the class name and the namespace its directory gives it.
	 *
	 * @param string $name       The class name given on the command line.
	 * @param array  $assoc_args WP-CLI's named arguments.
	 * @return array<string, string>
	 */
	protected function get_extra_values( string $name, array $assoc_args ): array {
		$segments = $this->get_name_segments( $name );

		return array(
			'class_name'      => (string) array_pop( $segments ),
			'class_namespace' => $this->get_rule_namespace( $segments ),
		);
	}

	protected function get_stub(): string {
		return 'rule.php.stub';
	}

	/**
	 * The project's own `Rules` directory, read from zestry.json.
	 *
	 * @param array{namespace: string, root: string} $config The project's zestry.json.
	 * @return string
	 */
	protected function get_default_dir( array $config ): string {
		return trim( $config['root'], '/\\' ) . '/Rules';
	}

	/**
	 * Say how the rule is used, since nothing declares it.
	 *
	 * @param string                                                          $name        The class name given on the command line.
	 * @param string                                                          $plugin_root Absolute path to the consuming plugin's root.
	 * @param array{namespace: string, root: string, text_domain: string|null} $config      The project's zestry.json.
	 * @return void
	 */
	protected function after_write( string $name, string $plugin_root, array $config ): void {
		$this->log(
			sprintf( 'Use it on a property as `#[%s]`.', basename( str_replace( '\\', '/', $name ) ) )
		);
	}

	/**
	 * The namespace a rule belongs to, given its leading name segments.
	 *
	 * @param string[] $segments Leading segments of the given name, without the class name.
	 * @return string
	 */
	private function get_rule_namespace( array $segments ): string {
		$config    = $this->with( ZestryConfig::class )->read( $this->with( ConsumerPlugin::class )->get_plugin_root() );
		$namespace = rtrim( $config['namespace'], '\\' ) . '\\Rules';

		foreach ( $segments as $segment ) {
			if ( 1 !== preg_match( '/^[A-Za-z_][A-Za-z0-9_]*$/', $segment ) ) {
				$this->error(
					sprintf( '"%s" is not a valid namespace segment, so PSR-4 cannot map it to a directory.', $segment )
				);
			}

			$namespace .= '\\' . $segment;
		}

		return $namespace;
	}

	protected static function get_type(): string {
		return 'rule';
	}
};
