<?php

declare( strict_types=1 );

namespace Zestry\WPToolkit\Tests\Integration\Modules;

use Zestry\WPToolkit\Kernel\Exceptions\DiscoveryException;
use Zestry\WPToolkit\Modules\Abilities\Abilities;
use Zestry\WPToolkit\Modules\Abilities\Effect;
use Zestry\WPToolkit\Tests\Support\TestCase;

/**
 * Discovery, namespacing, category registration, and the annotations WordPress
 * turns into an HTTP method.
 *
 * @covers \Zestry\WPToolkit\Modules\Abilities\Abilities
 * @covers \Zestry\WPToolkit\Modules\Abilities\Ability
 * @covers \Zestry\WPToolkit\Modules\Abilities\Effect
 */
final class AbilitiesTest extends TestCase {

	public function set_up(): void {
		parent::set_up();

		if ( ! function_exists( 'wp_register_ability' ) ) {
			$this->markTestSkipped( 'Requires the Abilities API, added in WordPress 6.9.' );
		}

		mkdir( $this->plugin_dir . '/abilities', 0777, true );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$this->reset_registries();
	}

	public function tear_down(): void {
		$this->reset_registries();
		parent::tear_down();
	}

	/**
	 * Empty both ability registries.
	 *
	 * They are process-wide singletons that fire their init action once, when
	 * something first asks them a question -- correct for a request, and fatal
	 * for a suite, where the first test to touch one would be the only test whose
	 * abilities ever register.
	 *
	 * @return void
	 */
	private function reset_registries(): void {
		foreach ( array( \WP_Abilities_Registry::class, \WP_Ability_Categories_Registry::class ) as $registry ) {
			$instance = new \ReflectionProperty( $registry, 'instance' );
			$instance->setAccessible( true );
			$instance->setValue( null, null );
		}
	}

	public function test_a_discovered_ability_is_registered_under_the_plugin_namespace(): void {
		$this->write_ability( 'publish-post' );

		$this->register();

		$this->assertTrue( wp_has_ability( 'zestry-test/publish-post' ) );
	}

	/**
	 * A plugin slug and a filename may both contain underscores, which abilities
	 * do not allow at all -- WordPress accepts lowercase letters, digits and
	 * dashes in each half of the name.
	 */
	public function test_both_halves_of_the_name_are_read_exactly_as_written(): void {
		$this->assertSame( 'zestry-test/create-order', $this->boot()->get_ability_name( 'create-order' ) );
	}

	/**
	 * WordPress matches an ability name against `^[a-z0-9-]+/[a-z0-9-]+$` and
	 * refuses anything else with a `_doing_it_wrong()` that names no file. Refused
	 * here instead, while the file that asked for it is still in hand.
	 *
	 * @return void
	 */
	public function test_a_filename_wordpress_would_refuse_throws_at_discovery(): void {
		$this->write_ability( 'create_order' );

		$this->expectException( DiscoveryException::class );
		$this->expectExceptionMessage( 'would register as "zestry-test/create_order"' );

		$this->boot()->get_discovered_abilities();
	}

	public function test_an_ability_can_ask_what_it_is_registered_as(): void {
		$this->write_ability( 'whoami' );

		$abilities = $this->boot();

		$this->assertSame(
			'zestry-test/whoami',
			$abilities->get_discovered_abilities()['whoami']->get_name()
		);
	}

	/**
	 * The whole point of the module: one implementation, reachable from your own
	 * PHP the same way an agent reaches it -- schema validation, permission check
	 * and all.
	 */
	public function test_run_executes_an_ability_by_its_local_name(): void {
		$this->write_ability( 'echo-id', "return array( 'ok' => 1 === \$input['id'] );" );

		$result = $this->boot()->run( 'echo-id', array( 'id' => 1 ) );

		$this->assertSame( array( 'ok' => true ), $result );
	}

	public function test_run_returns_an_error_when_the_input_does_not_match_the_schema(): void {
		$this->write_ability( 'echo-id' );

		$result = $this->boot()->run( 'echo-id', array( 'id' => 'not-an-integer' ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
	}

	public function test_run_returns_an_error_when_the_ability_refuses(): void {
		$this->write_ability( 'guarded', "return array( 'ok' => true );", 'public function permission_check( mixed $input ): bool { return false; }' );

		$result = $this->boot()->run( 'guarded', array( 'id' => 1 ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_run_throws_for_an_ability_this_plugin_does_not_have(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'zestry-test/nope' );
		$this->boot()->run( 'nope' );
	}

	/**
	 * WordPress reads the three annotations as three HTTP methods and answers
	 * every other one with 405, so the mapping is asserted against core's own
	 * controller rather than restated here.
	 *
	 * @dataProvider effects
	 */
	public function test_an_effect_declares_the_method_the_rest_endpoint_accepts( Effect $effect, string $expected ): void {
		$this->assertSame( $expected, $effect->get_rest_method() );

		$controller = new \WP_REST_Abilities_V1_Run_Controller();

		$this->assertTrue(
			$controller->validate_request_method( $expected, $effect->get_annotations() ),
			'WordPress must accept the method this effect claims.'
		);

		foreach ( array( 'GET', 'POST', 'DELETE' ) as $method ) {
			if ( $method === $expected ) {
				continue;
			}

			$this->assertInstanceOf(
				\WP_Error::class,
				$controller->validate_request_method( $method, $effect->get_annotations() ),
				sprintf( '%s must not also accept %s.', $effect->name, $method )
			);
		}
	}

	/**
	 * @return array<int, array{Effect, string}>
	 */
	public function effects(): array {
		return array(
			array( Effect::Read, 'GET' ),
			array( Effect::Create, 'POST' ),
			array( Effect::Update, 'POST' ),
			array( Effect::Delete, 'DELETE' ),
		);
	}

	/**
	 * False by default, matching WordPress: an ability is a registry entry first
	 * and an endpoint second.
	 */
	public function test_an_ability_is_private_until_it_says_otherwise(): void {
		$this->write_ability( 'internal' );

		$this->register();
		$ability = wp_get_ability( 'zestry-test/internal' );

		$this->assertFalse( $ability->get_meta_item( 'public' ) );
		$this->assertFalse( $ability->get_meta_item( 'show_in_rest' ) );
	}

	public function test_a_public_ability_reaches_the_rest_api(): void {
		$this->write_ability( 'shared', "return array( 'ok' => true );", 'public function is_public(): bool { return true; }' );

		$this->register();
		$ability = wp_get_ability( 'zestry-test/shared' );

		$this->assertTrue( $ability->get_meta_item( 'public' ) );
		$this->assertTrue( $ability->get_meta_item( 'show_in_rest' ) );
	}

	/**
	 * Written rather than left to WordPress to derive. WordPress 7.1 seeds
	 * `show_in_rest` from `public`; 6.9 does not, and there a public ability
	 * would be left off the REST API, answering with a 404 that reads as a
	 * mistyped name.
	 */
	public function test_show_in_rest_is_written_rather_than_inferred(): void {
		$this->write_ability( 'shared', "return array( 'ok' => true );", 'public function is_public(): bool { return true; }' );

		$this->register();

		$this->assertTrue(
			array_key_exists( 'show_in_rest', wp_get_ability( 'zestry-test/shared' )->get_meta() ),
			'The key core gates on is set by us, not inherited from a default.'
		);
	}

	/**
	 * The whole point of a public ability: an unauthenticated caller reaches it.
	 * WordPress's run endpoint has no authentication of its own -- listing
	 * abilities requires a user, running one does not -- so permission_check()
	 * is the only gate, and this pins that rather than describing it.
	 */
	public function test_a_public_ability_answers_an_anonymous_rest_request(): void {
		$this->write_ability(
			'open-door',
			"return array( 'ok' => true );",
			"public function is_public(): bool { return true; }\n"
				. 'public function permission_check( mixed $input ): bool { return true; }'
		);

		$this->register();

		// No user at all: no cookie, no nonce, nobody logged in.
		wp_set_current_user( 0 );
		rest_get_server();

		$request = new \WP_REST_Request( 'GET', '/wp-abilities/v1/abilities/zestry-test/open-door/run' );
		$request->set_param( 'input', array( 'id' => 1 ) );

		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 200, $response->get_status(), 'permission_check() is the only thing that ran.' );
		$this->assertSame( array( 'ok' => true ), $response->get_data() );
	}

	/**
	 * And one that refuses is refused, by that same check rather than by any
	 * gate in front of it.
	 */
	public function test_a_public_ability_that_refuses_is_refused_anonymously(): void {
		$this->write_ability(
			'closed-door',
			"return array( 'ok' => true );",
			"public function is_public(): bool { return true; }\n"
				. 'public function permission_check( mixed $input ): bool { return current_user_can( "manage_options" ); }'
		);

		$this->register();

		wp_set_current_user( 0 );
		rest_get_server();

		$refused = new \WP_REST_Request( 'GET', '/wp-abilities/v1/abilities/zestry-test/closed-door/run' );
		$refused->set_param( 'input', array( 'id' => 1 ) );

		$response = rest_get_server()->dispatch( $refused );

		$this->assertNotSame( 200, $response->get_status() );
		$this->assertSame(
			'rest_ability_cannot_execute',
			$response->get_data()['code'],
			'Refused for what the check said, not for being a stranger.'
		);
	}

	/**
	 * `show_in_rest` follows `public` unless is_shown_in_rest() says otherwise,
	 * which is how an ability is offered to an MCP adapter while staying off the
	 * REST API.
	 */
	public function test_an_ability_can_be_public_and_stay_off_the_rest_api(): void {
		$this->write_ability(
			'mcp-only',
			"return array( 'ok' => true );",
			"public function is_public(): bool { return true; }\n"
				. 'public function is_shown_in_rest(): bool { return false; }'
		);

		$this->register();
		$ability = wp_get_ability( 'zestry-test/mcp-only' );

		$this->assertTrue( $ability->get_meta_item( 'public' ) );
		$this->assertFalse( $ability->get_meta_item( 'show_in_rest' ) );
	}

	/**
	 * effect() is the source of the annotations, so meta() cannot quietly
	 * disagree with it and leave the endpoint answering a different method.
	 */
	public function test_meta_cannot_override_the_effect_annotations(): void {
		$this->write_ability(
			'stubborn',
			"return array( 'ok' => true );",
			"public function effect(): Effect { return Effect::Read; }\n"
				. "public function meta(): array { return array( 'annotations' => array( 'readonly' => false ) ); }"
		);

		$this->register();
		$annotations = wp_get_ability( 'zestry-test/stubborn' )->get_meta_item( 'annotations' );

		$this->assertTrue( $annotations['readonly'] );
	}

	public function test_a_category_is_registered_for_the_plugin_and_used_by_default(): void {
		$this->write_ability( 'grouped' );

		$this->register();

		$this->assertTrue( wp_has_ability_category( 'zestry-test' ) );
		$this->assertSame( 'zestry-test', wp_get_ability( 'zestry-test/grouped' )->get_category() );
	}

	/**
	 * A group nobody is in is noise in every client that lists categories.
	 */
	public function test_no_category_is_registered_when_nothing_uses_it(): void {
		$this->write_ability( 'elsewhere', "return array( 'ok' => true );", "public function category(): string { return 'zestry-test-elsewhere'; }" );

		$abilities = $this->boot();
		$abilities->add_categories( array( 'zestry-test-elsewhere' => 'Elsewhere' ) );
		$this->register();

		$this->assertTrue( wp_has_ability_category( 'zestry-test-elsewhere' ) );
		$this->assertFalse( wp_has_ability_category( 'zestry-test' ), 'The plugin category has no members.' );
	}

	public function test_declared_categories_are_registered(): void {
		$abilities = $this->boot();
		$abilities->add_categories(
			array(
				'zestry-test-billing' => 'Billing',
				'zestry-test-reports' => array(
					'label'       => 'Reports',
					'description' => 'Reads sales figures. Changes nothing.',
				),
			)
		);

		$this->register();

		$this->assertTrue( wp_has_ability_category( 'zestry-test-billing' ) );
		$this->assertSame(
			'Reads sales figures. Changes nothing.',
			wp_get_ability_category( 'zestry-test-reports' )->get_description()
		);
	}

	/**
	 * A label is a label, whatever else in PHP answers to that name.
	 *
	 * A label used to be resolved through `is_callable()`, which is true for any
	 * string naming a defined function and matches case-insensitively -- so
	 * `Time` registered as a unix timestamp, and `Log` or `Date` raised an
	 * `ArgumentCountError` that took every ability in the plugin with it. A
	 * label is a plain string now, and translating one is what
	 * {@see \Zestry\WPToolkit\Kernel\Abstracts\Module::on_wp_init()} is for.
	 */
	public function test_a_label_naming_a_function_is_not_called(): void {
		$this->assertTrue( \is_callable( 'time' ), 'The premise: PHP has a one-word function by this name.' );

		$abilities = $this->boot();
		$abilities->add_categories(
			array(
				'zestry-test-clock' => 'Time',
				'zestry-test-diary' => array(
					'label'       => 'Date',
					'description' => 'Log',
				),
			)
		);

		$this->register();

		$this->assertSame( 'Time', wp_get_ability_category( 'zestry-test-clock' )->get_label() );
		$this->assertSame( 'Date', wp_get_ability_category( 'zestry-test-diary' )->get_label() );
		$this->assertSame( 'Log', wp_get_ability_category( 'zestry-test-diary' )->get_description() );
	}

	/**
	 * A category WordPress or another plugin already registered is the one
	 * abilities reference, and registering over it is an error there.
	 *
	 * Core's own `site` and `user` would be the natural subject, but the
	 * WordPress test suite unhooks their registration, so this stands in for
	 * another plugin having got there first.
	 */
	public function test_an_existing_category_is_left_alone(): void {
		add_action(
			'wp_abilities_api_categories_init',
			static function (): void {
				wp_register_ability_category( 'zestry-test-shared', array( 'label' => 'Theirs', 'description' => 'Registered first.' ) );
			},
			1
		);

		$abilities = $this->boot();
		$abilities->add_categories( array( 'zestry-test-shared' => 'Ours' ) );

		$this->register();

		$this->assertSame( 'Theirs', wp_get_ability_category( 'zestry-test-shared' )->get_label() );
	}

	public function test_a_file_returning_the_wrong_type_throws(): void {
		file_put_contents( $this->plugin_dir . '/abilities/bad.php', "<?php\nreturn 'not an ability';\n" );

		$this->expectException( DiscoveryException::class );
		$this->expectExceptionMessage( 'must return an instance of' );
		$this->register();
	}


	/**
	 * A typed property with an AbilityArgument is the whole declaration: it
	 * becomes the input schema, and the validated value is bound onto it.
	 */
	public function test_arguments_become_the_input_schema_and_are_bound(): void {
		$this->write_ability(
			'bound',
			'return array( \'ok\' => 42 === $this->id && \'live\' === $this->mode );',
			"#[\\Zestry\\WPToolkit\\Services\\Request\\Attributes\\RequestArgument( 'Which one.' )]\n"
				. "public int \$id;\n"
				. "#[\\Zestry\\WPToolkit\\Services\\Request\\Attributes\\RequestArgument( 'How to run.', schema: array( 'enum' => array( 'live', 'test' ) ) )]\n"
				. "public string \$mode = 'live';"

		);

		$abilities = $this->register();
		$schema    = wp_get_ability( 'zestry-test/bound' )->get_input_schema();

		$this->assertSame( 'integer', $schema['properties']['id']['type'] );
		$this->assertSame( 'Which one.', $schema['properties']['id']['description'] );
		$this->assertSame( array( 'live', 'test' ), $schema['properties']['mode']['enum'] );
		$this->assertSame( array( 'id' ), $schema['required'], 'A property with no default is required.' );
		$this->assertSame( 'live', $schema['properties']['mode']['default'], 'A default is published for the caller.' );
		$this->assertArrayNotHasKey( 'default', $schema['properties']['id'] );

		$this->assertSame( array( 'ok' => true ), $abilities->run( 'bound', array( 'id' => 42 ) ) );
	}

	/**
	 * `__()` cannot go inside an attribute, so a translated description has to be
	 * stated in input_schema() — and stating one must not cost the declaration
	 * everything else it gave: the type, the required-ness, the sibling argument,
	 * and the binding.
	 */
	public function test_a_stated_input_schema_is_laid_over_the_declared_one(): void {
		$this->write_ability(
			'described',
			'return array( \'ok\' => 42 === $this->id );',
			"#[\\Zestry\\WPToolkit\\Services\\Request\\Attributes\\RequestArgument( 'Which one.' )]\n"
				. "public int \$id;\n"
				. "#[\\Zestry\\WPToolkit\\Services\\Request\\Attributes\\RequestArgument( 'How to run.' )]\n"
				. "public string \$mode = 'live';\n"
				. "public function input_schema(): array {\n"
				. "    return array( 'properties' => array( 'id' => array( 'description' => 'Care dintre ele.' ) ) );\n"
				. '}'
		);

		$abilities = $this->register();
		$schema    = wp_get_ability( 'zestry-test/described' )->get_input_schema();

		$this->assertSame( 'Care dintre ele.', $schema['properties']['id']['description'] );
		$this->assertSame( 'integer', $schema['properties']['id']['type'], 'The declaration still says the type.' );
		$this->assertSame( array( 'id' ), $schema['required'], 'And still says which are required.' );
		$this->assertSame( 'How to run.', $schema['properties']['mode']['description'], 'A sibling is left alone.' );

		$this->assertSame( array( 'ok' => true ), $abilities->run( 'described', array( 'id' => 42 ) ), 'And the value still binds.' );
	}

	/**
	 * Translation is not the only thing an attribute cannot hold — anything worked
	 * out while the request runs is the rest of it. A list replaces whole rather
	 * than by position, and a name with no declaration behind it is published and
	 * validated like any other, but never bound.
	 */
	public function test_a_stated_input_schema_replaces_a_list_and_may_add_a_name(): void {
		$this->write_ability(
			'stated',
			'return array( \'extra\' => $input[\'extra\'] ?? null );',
			"#[\\Zestry\\WPToolkit\\Services\\Request\\Attributes\\RequestArgument( 'How to run.', schema: array( 'enum' => array( 'live', 'test', 'stub' ) ) )]\n"
				. "public string \$mode = 'live';\n"
				. "public function input_schema(): array {\n"
				. "    return array(\n"
				. "        'properties' => array(\n"
				. "            'mode'  => array( 'enum' => array( 'live' ) ),\n"
				. "            'extra' => array( 'type' => 'string' ),\n"
				. "        ),\n"
				. "    );\n"
				. '}'
		);

		$abilities = $this->register();
		$schema    = wp_get_ability( 'zestry-test/stated' )->get_input_schema();

		$this->assertSame( array( 'live' ), $schema['properties']['mode']['enum'], 'A list is taken whole.' );
		$this->assertSame( 'string', $schema['properties']['extra']['type'] );
		$this->assertSame( array( 'extra' => 'read' ), $abilities->run( 'stated', array( 'extra' => 'read' ) ) );
	}

	/**
	 * An ability is discovered once and that instance answers every call, so what
	 * one call binds is still on the object when the next one arrives. A nullable
	 * argument meaning "not supplied" is how an optional one is written, which
	 * makes this the difference between an update that changes one field and one
	 * that repeats the previous caller's.
	 *
	 * Invisible over REST, where a request is a process. It shows up when one
	 * request runs an ability twice -- a WP-CLI command, a cron schedule, an
	 * agent batching tool calls.
	 *
	 * @return void
	 */
	public function test_an_argument_left_out_of_a_second_run_returns_to_its_default(): void {
		$this->write_ability(
			'update-entity',
			'return array( \'slug\' => $this->slug );',
			"#[\\Zestry\\WPToolkit\\Services\\Request\\Attributes\\RequestArgument( 'Which one.' )]\n"
				. 'public ?string $slug = null;'
		);

		$abilities = $this->register();

		$this->assertSame(
			array( 'slug' => 'our-team' ),
			$abilities->run( 'update-entity', array( 'slug' => 'our-team' ) )
		);

		$this->assertSame(
			array( 'slug' => null ),
			$abilities->run( 'update-entity', array() ),
			'The second call sees its declared default, not what the first call sent.'
		);
	}

	/**
	 * Why the argument above can only go stale through a second *array* input:
	 * WordPress refuses anything that is not an object before either callback
	 * runs, so an ability declaring arguments is never executed with none.
	 *
	 * The callbacks bind unconditionally all the same -- what clears the last
	 * call must not depend on the shape of this one's input -- and this is what
	 * says the platform is holding that line today rather than the toolkit.
	 *
	 * @return void
	 */
	public function test_wordpress_refuses_a_run_with_no_input_when_the_ability_declares_arguments(): void {
		$this->write_ability(
			'read-entity',
			'return array( \'slug\' => $this->slug );',
			"#[\\Zestry\\WPToolkit\\Services\\Request\\Attributes\\RequestArgument( 'Which one.' )]\n"
				. 'public ?string $slug = null;'
		);

		$abilities = $this->register();

		$abilities->run( 'read-entity', array( 'slug' => 'our-team' ) );

		$refused = $abilities->run( 'read-entity' );

		$this->assertInstanceOf( \WP_Error::class, $refused );
		$this->assertSame( 'ability_invalid_input', $refused->get_error_code() );
		$this->assertStringContainsString( 'not of type object', $refused->get_error_message() );
	}

	/**
	 * The permission check runs before the callback, and often needs the very
	 * thing being acted on, so binding has to have happened by then.
	 */
	public function test_arguments_are_bound_before_the_permission_check(): void {
		$this->write_ability(
			'guarded-by-arg',
			"return array( 'ok' => true );",
			"#[\\Zestry\\WPToolkit\\Services\\Request\\Attributes\\RequestArgument( 'Which one.' )]\n"
				. "public int \$id;\n"
				. 'public function permission_check( mixed $input ): bool { return 1 === $this->id; }'
		);

		$abilities = $this->register();

		$this->assertSame( array( 'ok' => true ), $abilities->run( 'guarded-by-arg', array( 'id' => 1 ) ) );
		$this->assertInstanceOf( \WP_Error::class, $abilities->run( 'guarded-by-arg', array( 'id' => 2 ) ) );
	}

	/**
	 * The schema states what it can; a callback covers what JSON Schema cannot
	 * say, and its refusal has to reach the caller as an error about the
	 * argument rather than one about permissions.
	 */
	public function test_an_argument_validate_callback_rejects_the_call(): void {
		$this->write_ability(
			'checked',
			"return array( 'ok' => true );",
			"#[\\Zestry\\WPToolkit\\Services\\Request\\Attributes\\RequestArgument( 'Which one.', validate: array( self::class, 'is_known' ) )]\n"
				. "public int \$id;\n"
				. 'public static function is_known( $value ): bool { return 1 === $value; }'
		);

		$abilities = $this->register();

		$this->assertSame( array( 'ok' => true ), $abilities->run( 'checked', array( 'id' => 1 ) ) );

		$refused = $abilities->run( 'checked', array( 'id' => 2 ) );

		$this->assertInstanceOf( \WP_Error::class, $refused );
		$this->assertSame( 'ability_invalid_input', $refused->get_error_code(), "Core's own code for input it rejected." );
		$this->assertStringContainsString( 'id', $refused->get_error_message() );
	}

	public function test_an_argument_sanitize_callback_reaches_both_the_property_and_handle(): void {
		$this->write_ability(
			'trimmed',
			"return array( 'ok' => 'acme' === \$this->name && 'acme' === \$input['name'] );",
			"#[\\Zestry\\WPToolkit\\Services\\Request\\Attributes\\RequestArgument( 'A name.', sanitize: 'trim' )]\n"
				. 'public string $name;'
		);

		$this->assertSame(
			array( 'ok' => true ),
			$this->register()->run( 'trimmed', array( 'name' => '  acme  ' ) )
		);
	}

	/**
	 * A property WordPress has no JSON type for cannot be described to a caller,
	 * and silently dropping it would leave an input nothing knows to send.
	 */
	public function test_an_argument_with_no_declared_type_throws(): void {
		$this->write_ability(
			'untyped',
			"return array( 'ok' => true );",
			"#[\\Zestry\\WPToolkit\\Services\\Request\\Attributes\\RequestArgument( 'Which one.' )]\n"
				. 'public $anything;'
		);

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'needs a single declared type' );
		$this->register();
	}

	/**
	 * WordPress validates an ability's input and never sanitises it, and its
	 * validation passes a numeric string for an integer -- so a caller sending
	 * `"42"` for an `int` argument is making a valid call, and it has to arrive
	 * as the type the property was declared as.
	 */
	public function test_input_is_cast_to_the_type_each_argument_declares(): void {
		$this->write_ability(
			'typed',
			"return array( 'ok' => 42 === \$this->id && true === \$this->flag && 1.5 === \$this->ratio );",
			"#[\\Zestry\\WPToolkit\\Services\\Request\\Attributes\\RequestArgument( 'Which one.' )]\n"
				. "public int \$id;\n"
				. "#[\\Zestry\\WPToolkit\\Services\\Request\\Attributes\\RequestArgument( 'Whether to.' )]\n"
				. "public bool \$flag = false;\n"
				. "#[\\Zestry\\WPToolkit\\Services\\Request\\Attributes\\RequestArgument( 'How much.' )]\n"
				. 'public float $ratio = 0.0;'
		);

		$this->assertSame(
			array( 'ok' => true ),
			$this->register()->run( 'typed', array( 'id' => '42', 'flag' => '1', 'ratio' => '1.5' ) )
		);
	}

	/**
	 * The rule sees what was sent, before any cast -- the same order a route
	 * runs them in.
	 */
	public function test_a_validate_rule_sees_the_value_as_it_was_sent(): void {
		$this->write_ability(
			'strict',
			"return array( 'ok' => true );",
			"#[\\Zestry\\WPToolkit\\Services\\Request\\Attributes\\RequestArgument( 'Which one.', validate: 'is_int' )]\n"
				. 'public int $id;'
		);

		$abilities = $this->register();

		$this->assertSame( array( 'ok' => true ), $abilities->run( 'strict', array( 'id' => 42 ) ) );
		$this->assertInstanceOf( \WP_Error::class, $abilities->run( 'strict', array( 'id' => '42' ) ) );
	}

	/**
	 * An open object survives the whole way: published as `type: object` with no
	 * fixed keys, validated by WordPress, cast against that schema, and handed
	 * over as an object rather than the array JSON decoded to.
	 */
	public function test_an_open_object_argument_runs_end_to_end(): void {
		$this->write_ability(
			'passthrough',
			"return array( 'ok' => 'blue' === \$this->params->colour && array( 1, 2 ) === \$this->params->sizes && 'deep' === \$this->params->nested->level );",
			"#[\\Zestry\\WPToolkit\\Services\\Request\\Attributes\\RequestArgument( 'Whatever the caller keeps here.' )]\n"
				. 'public object $params;'
		);

		$abilities = $this->register();

		$this->assertSame(
			'object',
			wp_get_ability( 'zestry-test/passthrough' )->get_input_schema()['properties']['params']['type']
		);

		$this->assertSame(
			array( 'ok' => true ),
			$abilities->run(
				'passthrough',
				array(
					'params' => array(
						'colour' => 'blue',
						'sizes'  => array( 1, 2 ),
						'nested' => array( 'level' => 'deep' ),
					),
				)
			)
		);
	}

	/**
	 * WordPress answers a route with every parameter it refused at once. An
	 * ability does the same, so a caller fixing its call does not discover them
	 * one round trip at a time.
	 */
	public function test_every_refused_argument_is_named_at_once(): void {
		$this->write_ability(
			'picky',
			"return array( 'ok' => true );",
			"#[\\Zestry\\WPToolkit\\Services\\Request\\Attributes\\RequestArgument( 'One.', validate: 'is_int' )]\n"
				. "public int \$first;\n"
				. "#[\\Zestry\\WPToolkit\\Services\\Request\\Attributes\\RequestArgument( 'Two.', validate: 'is_int' )]\n"
				. "public int \$second;\n"
				. "#[\\Zestry\\WPToolkit\\Services\\Request\\Attributes\\RequestArgument( 'Three.', validate: 'is_int' )]\n"
				. 'public int $third;'
		);

		$refused = $this->register()->run(
			'picky',
			array(
				'first'  => '1',
				'second' => 2,
				'third'  => '3',
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $refused );
		$this->assertSame( array( 'first', 'third' ), $refused->get_error_data()['params'] );
		$this->assertStringContainsString( 'first', $refused->get_error_message() );
		$this->assertStringContainsString( 'third', $refused->get_error_message() );
	}

	/**
	 * An ability that needs nothing from its caller is a documented shape, and
	 * the natural one for "list everything" or "get status" — the two an agent
	 * reaches for first. WordPress calls a schema-less ability's callbacks with
	 * no arguments at all.
	 */
	public function test_an_ability_that_takes_no_input_runs(): void {
		$this->write_ability( 'list-everything', "return array( 'ok' => true );", 'public function input_schema(): array { return array(); }' );

		$this->assertSame( array( 'ok' => true ), $this->register()->run( 'list-everything' ) );
	}

	/**
	 * And its permission check is reached, rather than the call failing before
	 * anything of the author's runs.
	 */
	public function test_a_no_input_ability_reaches_its_permission_check(): void {
		$this->write_ability(
			'guarded-list',
			"return array( 'ok' => true );",
			"public function input_schema(): array { return array(); }\n"
				. 'public function permission_check( mixed $input ): bool { $GLOBALS["zestry_checked"] = true; return false; }'
		);

		$GLOBALS['zestry_checked'] = false;

		$refused = $this->register()->run( 'guarded-list' );

		$this->assertTrue( $GLOBALS['zestry_checked'], 'permission_check() ran.' );
		$this->assertInstanceOf( \WP_Error::class, $refused );

		unset( $GLOBALS['zestry_checked'] );
	}

	/**
	 * Boot the module and hand it back.
	 *
	 * @return Abilities
	 */
	private function boot(): Abilities {
		return $this->plugin->get( Abilities::class );
	}

	/**
	 * Boot the module, then make WordPress build its registries.
	 *
	 * `wp_register_ability()` refuses to run anywhere but inside
	 * `wp_abilities_api_init`, so registration is driven the way a real request
	 * drives it: bind the hooks, then ask the registry a question.
	 *
	 * @return Abilities
	 */
	private function register(): Abilities {
		$abilities = $this->boot();

		wp_get_abilities();

		return $abilities;
	}

	/**
	 * Drop an ability file into the plugin's abilities directory.
	 *
	 * @param string $name         The ability's local name.
	 * @param string $execute_body The body of execute().
	 * @param string $extra        Extra class body to place before the methods.
	 * @return void
	 */
	private function write_ability( string $name, string $execute_body = "return array( 'ok' => true );", string $extra = '' ): void {
		$defaults = array(
			'label'        => "public function label(): string { return 'An ability'; }",
			'description'  => "public function description(): string { return 'Does a thing.'; }",
			'effect'       => 'public function effect(): Effect { return Effect::Read; }',
			'permission_check'  => 'public function permission_check( mixed $input ): bool { return true; }',
			'input_schema' => "public function input_schema(): array { return array( 'type' => 'object', "
				. "'properties' => array( 'id' => array( 'type' => 'integer' ) ) ); }",
			'handle'       => "public function handle( mixed \$input ): mixed { {$execute_body} }",
		);

		// An ability declaring arguments takes the schema derived from them, which
		// is the whole point of the attribute. Left in, this default would be
		// stated *over* that and add a property no test asked for.
		if ( str_contains( $extra, 'RequestArgument' ) ) {
			unset( $defaults['input_schema'] );
		}

		// $extra wins: a test overriding one of these declares it in full, and
		// PHP fatals on the redeclaration rather than ignoring the duplicate.
		foreach ( array_keys( $defaults ) as $method ) {
			if ( str_contains( $extra, 'function ' . $method . '(' ) ) {
				unset( $defaults[ $method ] );
			}
		}

		file_put_contents(
			$this->plugin_dir . '/abilities/' . $name . '.php',
			"<?php\n"
				. "use Zestry\\WPToolkit\\Modules\\Abilities\\Effect;\n"
				. "return new class extends \\Zestry\\WPToolkit\\Modules\\Abilities\\Ability {\n"
				. $extra . "\n"
				. implode( "\n", $defaults ) . "\n"
				. "};\n"
		);
	}
}
