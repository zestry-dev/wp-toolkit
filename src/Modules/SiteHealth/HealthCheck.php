<?php

/**
 * SiteHealth API: HealthCheck base class
 */

declare( strict_types=1 );

namespace Zestry\WPToolkit\Modules\SiteHealth;

// Loaded by WordPress, never requested directly.
\defined( 'ABSPATH' ) || exit;

use Zestry\WPToolkit\Kernel\Contracts\PluginAware;
use Zestry\WPToolkit\Kernel\Traits\WithPlugin;
use Zestry\WPToolkit\Kernel\Traits\WithEnablement;

/**
 * One check on the Site Health screen.
 *
 * A file in `health-checks/` returns one of these. Its filename is the check's
 * identifier, so `api-key.php` becomes `{plugin-slug}-api-key` on the screen.
 *
 * Site Health is the supported way to see a site you cannot log into: a user
 * copies the report into a support ticket. A check that says "the API key is
 * missing" saves the round trip that starts with "what does your settings page
 * show?".
 *
 * @stub health-check.php.stub
 *
 * @example A check
 * `run()` returns one of {@see good()}, {@see recommended()} or
 * {@see critical()}. Reach any declared module with `$this->with( … )`, so the
 * check reads real state rather than guessing.
 *
 * ```
 * namespace Acme\Plugin\HealthChecks;
 *
 * use Acme\Plugin\Core\Modules\SiteHealth\HealthCheck;
 * use Acme\Plugin\Core\Modules\Options;
 *
 * return new class extends HealthCheck {
 *
 *     public function label(): string {
 *         return __( 'Acme API key', 'acme-plugin' );
 *     }
 *
 *     public function run(): array {
 *         $options = $this->get_plugin()->get( Options::class );
 *
 *         if ( '' !== (string) $options->get( 'api_key', '' ) ) {
 *             return $this->good( __( 'Your API key is set.', 'acme-plugin' ) );
 *         }
 *
 *         return $this->critical(
 *             __( 'Acme cannot reach its API without a key, so nothing will sync.', 'acme-plugin' ),
 *             sprintf(
 *                 '<a href="%s">%s</a>',
 *                 esc_url( admin_url( 'admin.php?page=acme-settings' ) ),
 *                 esc_html__( 'Add your API key', 'acme-plugin' )
 *             )
 *         );
 *     }
 * };
 * ```
 */
abstract class HealthCheck implements PluginAware {

	use WithPlugin;
	use WithEnablement;

	/**
	 * Everything is as it should be.
	 */
	public const STATUS_GOOD = 'good';

	/**
	 * Worth improving, but nothing is broken.
	 */
	public const STATUS_RECOMMENDED = 'recommended';

	/**
	 * Something is broken and the user should act.
	 */
	public const STATUS_CRITICAL = 'critical';

	/**
	 * Prevent direct construction from bypassing plugin initialization.
	 *
	 * @return void
	 */
	final public function __construct() {}

	/**
	 * The name shown for this check on the Site Health screen.
	 *
	 * @return string A short, translated label.
	 */
	abstract public function label(): string;

	/**
	 * Run the check.
	 *
	 * Runs on the Site Health screen and on the weekly cron that populates it,
	 * so keep it quick and avoid side effects. Return {@see good()},
	 * {@see recommended()} or {@see critical()}.
	 *
	 * @return array<string, mixed> The result, as WordPress expects it.
	 */
	abstract public function run(): array;

	/**
	 * The identifier this check is registered under.
	 *
	 * Your filename with the plugin slug prefixed, since `site_status_tests` is
	 * one array shared by every plugin: `api-key.php` gives
	 * `{plugin-slug}-api-key`. Useful for logging which check reported, since
	 * that is the name the report shows.
	 *
	 * @return string
	 */
	final public function get_id(): string {
		return $this->site_health()->get_id_of( $this );
	}

	/**
	 * The category this check is filed under on the screen.
	 *
	 * Defaults to your plugin's name, which is usually what you want — it groups
	 * your checks together and tells a reader whose check failed. Override to
	 * use one of WordPress's own categories, such as `Performance` or
	 * `Security`.
	 *
	 * @return string A short, translated badge label.
	 */
	public function badge_label(): string {
		return (string) $this->get_plugin()->get_header( 'Name' );
	}

	/**
	 * The colour of the badge beside the category.
	 *
	 * @return BadgeColor
	 */
	public function badge_color(): BadgeColor {
		return BadgeColor::Blue;
	}

	/**
	 * The module that discovered this check.
	 *
	 * @return SiteHealth
	 */
	final protected function site_health(): SiteHealth {
		return $this->get_plugin()->get( SiteHealth::class );
	}

	/**
	 * Nothing to do.
	 *
	 * @param string $description What is fine, in a sentence.
	 * @param string $actions     Optional HTML, usually a link.
	 * @return array<string, mixed>
	 */
	protected function good( string $description, string $actions = '' ): array {
		return $this->result( self::STATUS_GOOD, $description, $actions );
	}

	/**
	 * Worth improving, but nothing is broken.
	 *
	 * @param string $description What could be better, in a sentence.
	 * @param string $actions     Optional HTML, usually a link to where to fix it.
	 * @return array<string, mixed>
	 */
	protected function recommended( string $description, string $actions = '' ): array {
		return $this->result( self::STATUS_RECOMMENDED, $description, $actions );
	}

	/**
	 * Something is broken and the user should act.
	 *
	 * @param string $description What is wrong and what it costs, in a sentence.
	 * @param string $actions     Optional HTML, usually a link to where to fix it.
	 * @return array<string, mixed>
	 */
	protected function critical( string $description, string $actions = '' ): array {
		return $this->result( self::STATUS_CRITICAL, $description, $actions );
	}

	/**
	 * Assemble a result in the shape WordPress reads.
	 *
	 * The `test` key is filled in by {@see SiteHealth} rather than here, because
	 * it has to match the identifier the check was registered under — and that
	 * comes from the filename, which this class never sees.
	 *
	 * @param string $status      One of this class's STATUS_ constants.
	 * @param string $description The sentence shown under the label.
	 * @param string $actions     Optional HTML.
	 * @return array<string, mixed>
	 */
	private function result( string $status, string $description, string $actions ): array {
		return array(
			'label'       => $this->label(),
			'status'      => $status,
			'badge'       => array(
				'label' => $this->badge_label(),
				'color' => $this->badge_color()->value,
			),
			'description' => \wpautop( $description ),
			'actions'     => $actions,
		);
	}
}
