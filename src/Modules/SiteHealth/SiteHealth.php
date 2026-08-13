<?php

/**
 * SiteHealth API: SiteHealth module
 */

declare( strict_types=1 );

namespace Zestry\WPToolkit\Modules\SiteHealth;

// Loaded by WordPress, never requested directly.
\defined( 'ABSPATH' ) || exit;

use Zestry\WPToolkit\Kernel\Abstracts\Module;
use Zestry\WPToolkit\Kernel\Exceptions\DiscoveryException;
use Zestry\WPToolkit\Kernel\Traits\WithFolderWalker;
use Zestry\WPToolkit\Services\Path;

/**
 * Puts your plugin on WordPress's Site Health screen.
 *
 * Two directories, one per tab. A file in `health-checks/` returns a
 * {@see HealthCheck} and appears on **Status** with a verdict; a file in
 * `debug-sections/` returns a {@see DebugSection} and appears on **Info** as a
 * panel of values, with no verdict. In both, the filename is the identifier:
 * `api-key.php` becomes `{plugin-slug}-api-key`.
 *
 * This is the supported way to see a site you cannot log into. A user copies the
 * report into a support ticket, so a check that reports "the API key is missing"
 * — or a panel listing which of your settings are set — answers the first
 * question you were going to ask anyway.
 *
 * Checks run on the Site Health screen and on the weekly cron behind it, so keep
 * them quick and free of side effects.
 *
 * @discovers HealthCheck
 * @discovers DebugSection
 *
 * @example A health check
 * ```
 * // health-checks/api-key.php
 * return new class extends HealthCheck {
 *
 *     public function label(): string {
 *         return __( 'Acme API key', 'acme-plugin' );
 *     }
 *
 *     public function run(): array {
 *         return $this->good( __( 'Your API key is set.', 'acme-plugin' ) );
 *     }
 * };
 * ```
 *
 * @example A debug section
 * ```
 * // debug-sections/status.php
 * return new class extends DebugSection {
 *
 *     public function label(): string {
 *         return __( 'Acme', 'acme-plugin' );
 *     }
 *
 *     public function fields(): array {
 *         return array(
 *             'mode' => array(
 *                 'label' => __( 'Mode', 'acme-plugin' ),
 *                 'value' => __( 'Live', 'acme-plugin' ),
 *                 'debug' => 'live',
 *             ),
 *         );
 *     }
 * };
 * ```
 *
 */
class SiteHealth extends Module {

	use WithFolderWalker;

	/**
	 * Where checks are discovered, relative to the plugin root.
	 */
	const CHECKS_ROOT = 'health-checks';

	/**
	 * Where debug sections are discovered, relative to the plugin root.
	 */
	const SECTIONS_ROOT = 'debug-sections';

	/**
	 * @var Path
	 */
	public Path $path;

	/**
	 * Discovered checks by identifier, once the directory has been walked.
	 *
	 * Kept rather than rebuilt, so {@see get_id_of()} compares against the same
	 * instances a caller is holding.
	 *
	 * @var array<string, HealthCheck>|null
	 */
	private ?array $discovered = null;

	/**
	 * Discovered sections by identifier, once the directory has been walked.
	 *
	 * @var array<string, DebugSection>|null
	 */
	private ?array $discovered_sections = null;

	/**
	 * Every discovered check, keyed by the identifier it registers under.
	 *
	 * @return array<string, HealthCheck> Wired instances keyed by identifier.
	 * @throws DiscoveryException When a file returns the wrong value.
	 */
	public function get_discovered_checks(): array {
		if ( null === $this->discovered ) {
			$this->discovered = $this->get_discovered( self::CHECKS_ROOT, HealthCheck::class, 'Health checks' );
		}

		return $this->discovered;
	}

	/**
	 * Every discovered debug section, keyed by the identifier it registers under.
	 *
	 * @return array<string, DebugSection> Wired instances keyed by identifier.
	 * @throws DiscoveryException When a file returns the wrong value.
	 */
	public function get_discovered_sections(): array {
		if ( null === $this->discovered_sections ) {
			$this->discovered_sections = $this->get_discovered( self::SECTIONS_ROOT, DebugSection::class, 'Debug sections' );
		}

		return $this->discovered_sections;
	}

	/**
	 * A check's or section's identifier, from the file it was discovered in.
	 *
	 * @param HealthCheck|DebugSection $item The instance to look up.
	 * @return string
	 * @throws \InvalidArgumentException When the instance was not discovered by this module.
	 */
	public function get_id_of( HealthCheck|DebugSection $item ): string {
		$discovered = $item instanceof HealthCheck
			? $this->get_discovered_checks()
			: $this->get_discovered_sections();

		$id = \array_search( $item, $discovered, true );

		if ( false === $id ) {
			throw new \InvalidArgumentException(
				\sprintf( 'The given %s instance was not discovered by this SiteHealth module.', $item::class )
			);
		}

		return $id;
	}

	/**
	 * The identifier a check file registers under.
	 *
	 * Namespaced to the plugin, since `site_status_tests` is one array shared by
	 * every plugin on the site. Your slug and the filename are joined with a
	 * hyphen, both as written, so `api-key.php` gives `{plugin-slug}-api-key`.
	 *
	 * @param string $name The check's local name — its filename without `.php`.
	 * @return string
	 */
	public function get_check_id( string $name ): string {
		return $this->get_prefixed_id( $name );
	}

	/**
	 * The identifier a debug section file registers under.
	 *
	 * Namespaced the same way, and for the same reason: `debug_information` is
	 * one array shared by every plugin. `status.php` gives `{plugin-slug}-status`.
	 *
	 * @param string $name The section's local name — its filename without `.php`.
	 * @return string
	 */
	public function get_section_id( string $name ): string {
		return $this->get_prefixed_id( $name );
	}

	/**
	 * Add every discovered check to WordPress's list.
	 *
	 * Registered as `direct` tests, which run in the same request as the screen.
	 * WordPress's `async` alternative exists for tests that make an outbound
	 * request; one of those belongs behind a {@see \Zestry\WPToolkit\Services\Transients}
	 * entry and a direct check reading it, rather than blocking the screen.
	 *
	 * @param array<string, array<string, mixed>> $tests The tests WordPress has so far.
	 * @return array<string, array<string, mixed>> The tests with this plugin's added.
	 * @throws DiscoveryException When discovery fails.
	 *
	 * @internal
	 */
	public function filter_site_status_tests( array $tests ): array {
		foreach ( $this->get_discovered_checks() as $id => $check ) {
			$tests['direct'][ $id ] = array(
				'label' => $check->label(),
				'test'  => function () use ( $id, $check ): array {
					return $this->run_check( $id, $check );
				},
			);
		}

		return $tests;
	}

	/**
	 * Add every discovered section to WordPress's Info tab.
	 *
	 * @param array<string, array<string, mixed>> $info The sections WordPress has so far.
	 * @return array<string, array<string, mixed>> The sections with this plugin's added.
	 * @throws DiscoveryException When discovery fails.
	 *
	 * @internal
	 */
	public function filter_debug_information( array $info ): array {
		foreach ( $this->get_discovered_sections() as $id => $section ) {
			$info[ $id ] = array(
				'label'       => $section->label(),
				'description' => $section->description(),
				'show_count'  => $section->show_count(),
				'private'     => $section->is_private(),
				'fields'      => $section->fields(),
			);
		}

		return $info;
	}

	/**
	 * Bind the filter that offers this plugin's checks.
	 *
	 * @return void
	 *
	 * @internal
	 */
	protected function on_boot(): void {
		\add_filter( 'site_status_tests', array( $this, 'filter_site_status_tests' ) );
		\add_filter( 'debug_information', array( $this, 'filter_debug_information' ) );
	}

	/**
	 * Run one check and stamp its identifier onto the result.
	 *
	 * WordPress requires the result's `test` key to match the key the check was
	 * registered under, and uses it to attribute the result on screen. Filling it
	 * here rather than in {@see HealthCheck} is what lets a check be written
	 * without knowing its own identifier — which comes from its filename.
	 *
	 * @param string      $id    The identifier the check is registered under.
	 * @param HealthCheck $check The check to run.
	 * @return array<string, mixed>
	 */
	private function run_check( string $id, HealthCheck $check ): array {
		$result = $check->run();

		$result['test'] = $id;

		return $result;
	}

	/**
	 * Prefix and normalise one file's name into the identifier it registers under.
	 *
	 * @param string $name The local name — a filename without `.php`.
	 * @return string
	 */
	private function get_prefixed_id( string $name ): string {
		return $this->get_plugin()->get_namespaced_name( $name );
	}

	/**
	 * Walk one of this module's directories and wire what its files return.
	 *
	 * Checks and sections are discovered identically -- one flat directory,
	 * filename as identifier -- and differ only in what a file must return and
	 * where the result is offered to WordPress.
	 *
	 * @param string $root     The directory, relative to the plugin root.
	 * @param bool   $was_set  Whether that root was named rather than defaulted.
	 * @param string $expected The base class each file must return an instance of.
	 * @param string $label    What to call the directory when something is wrong.
	 * @return array<string, HealthCheck|DebugSection> Wired instances keyed by identifier.
	 * @throws DiscoveryException When a file returns the wrong value.
	 */
	private function get_discovered( string $root, string $expected, string $label ): array {
		$root_dir = $this->path->get_plugin_path( $root );

		if ( ! \is_dir( $root_dir ) ) {
			return array();
		}

		$instances = array();

		foreach ( $this->walk_folder( $root_dir, array( 'php' ), 1 ) as $file ) {
			$name = \basename( $file, '.php' );
			$id   = $this->get_prefixed_id( $name );
			$path = $root_dir . '/' . $file;

			$instance = require $path;

			if ( ! $instance instanceof $expected ) {
				throw new DiscoveryException(
					\sprintf(
						'The file "%s" must return an instance of %s. Got: %s',
						$path,
						$expected,
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

			$instances[ $id ] = $instance;
		}

		return $instances;
	}
}
