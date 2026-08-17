<?php

/**
 * SiteHealth API: DebugSection base class
 */

declare( strict_types=1 );

namespace Zestry\WPToolkit\Modules\SiteHealth;

// Loaded by WordPress, never requested directly.
\defined( 'ABSPATH' ) || exit;

use Zestry\WPToolkit\Kernel\Contracts\PluginAware;
use Zestry\WPToolkit\Kernel\Traits\WithPlugin;
use Zestry\WPToolkit\Kernel\Traits\WithEnablement;

/**
 * One panel on the Site Health *Info* tab.
 *
 * A file in `resources/debug-sections/` returns one of these, and its filename is the
 * section's identifier: `status.php` becomes `{plugin-slug}-status`.
 *
 * This is the other half of Site Health, and it answers a different question
 * from a {@see HealthCheck}. A check has a verdict — good, recommended,
 * critical — and belongs to something that can be wrong. A section has no
 * verdict: it lists what your plugin's state actually is, so the values reach
 * you in a support ticket without a round trip. The whole tab is behind one
 * "Copy site info to clipboard" button, which is what a user pastes.
 *
 * Most plugins want exactly one, listing the handful of values you would ask
 * for first.
 *
 * @stub debug-section.php.stub
 *
 * @example A section
 * `fields()` is keyed by field id; each field needs a translated `label` and a
 * `value`. Reach any declared module with `$this->with( … )`, so the section
 * reports real state.
 *
 * ```
 * use Acme\Plugin\Core\Modules\Options;
 * use Acme\Plugin\Core\Modules\Options;
 * use Acme\Plugin\Core\Modules\SiteHealth\DebugSection;
 *
 * return new class extends DebugSection {
 *
 *     public function label(): string {
 *         return __( 'Acme', 'acme-plugin' );
 *     }
 *
 *     public function fields(): array {
 *         $options = $this->with( Options::class );
 *         $has_key = '' !== (string) $options->get( 'api_key', '' );
 *
 *         return array(
 *             'api_key' => array(
 *                 'label' => __( 'API key', 'acme-plugin' ),
 *                 'value' => $has_key ? __( 'Set', 'acme-plugin' ) : __( 'Missing', 'acme-plugin' ),
 *                 'debug' => $has_key ? 'set' : 'missing',
 *             ),
 *             'last_sync' => array(
 *                 'label' => __( 'Last sync', 'acme-plugin' ),
 *                 'value' => (string) $options->get( 'last_sync', __( 'Never', 'acme-plugin' ) ),
 *             ),
 *         );
 *     }
 * };
 * ```
 */
abstract class DebugSection implements PluginAware {

	use WithPlugin;
	use WithEnablement;

	/**
	 * Prevent direct construction from bypassing plugin initialization.
	 *
	 * @return void
	 */
	final public function __construct() {}

	/**
	 * The heading shown above this panel.
	 *
	 * @return string A short, translated label — usually your plugin's name.
	 */
	abstract public function label(): string;

	/**
	 * The values this panel lists.
	 *
	 * Keyed by field id, in the shape WordPress reads:
	 *
	 * ```
	 * return array(
	 *     'api_key' => array(
	 *         'label'   => __( 'API key', 'acme-plugin' ),
	 *         'value'   => __( 'Set', 'acme-plugin' ),
	 *         'debug'   => 'set',  // optional: what the copied text says
	 *         'private' => false,  // optional: true keeps it out of the copy
	 *     ),
	 * );
	 * ```
	 *
	 * `label` and `value` are required; a `value` may be an array, which is
	 * rendered as name/value pairs. `debug` replaces the value in the copied
	 * text, and should be short and untranslated — a user pasting into a ticket
	 * writes in their language, and you read the paste in yours.
	 *
	 * **`private` is not redaction.** The value is still printed on screen,
	 * where the site's administrator can read it; it is only left out of the
	 * copied text. Never put a credential in a field at all.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	abstract public function fields(): array;

	/**
	 * The identifier this section is registered under.
	 *
	 * Your filename with the plugin slug prefixed, since `debug_information` is
	 * one array shared by every plugin: `status.php` gives
	 * `{plugin-slug}-status`.
	 *
	 * @return string
	 */
	final public function get_id(): string {
		return $this->site_health()->get_id_of( $this );
	}

	/**
	 * A sentence under the heading, explaining what the panel is.
	 *
	 * Rendered inside a paragraph, so inline HTML only. Empty by default —
	 * fields with clear labels rarely need one.
	 *
	 * @return string
	 */
	public function description(): string {
		return '';
	}

	/**
	 * Whether to show the number of fields beside the heading.
	 *
	 * @return bool
	 */
	public function show_count(): bool {
		return false;
	}

	/**
	 * Whether to leave the whole panel out of the copied text.
	 *
	 * Same caveat as a field's `private`: the panel is still on screen. Default
	 * false, since a section nobody can paste defeats the point.
	 *
	 * @return bool
	 */
	public function is_private(): bool {
		return false;
	}

	/**
	 * The module that discovered this section.
	 *
	 * @return SiteHealth
	 */
	final protected function site_health(): SiteHealth {
		return $this->with( SiteHealth::class );
	}
}
