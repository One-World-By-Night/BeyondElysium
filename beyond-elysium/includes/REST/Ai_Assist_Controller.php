<?php

namespace BeyondElysium\REST;

use BeyondElysium\Core\Authorization;
use BeyondElysium\Models\Game;
use BeyondElysium\Services\Ai_Assist;

defined( 'ABSPATH' ) || exit;

/**
 * REST controller for the AI writing-assist tool (ai-writing-assist-design.md).
 *
 * Two shapes of route, both handled by the same callbacks: a site-wide pair
 * (`/ai-assist`, `/ai-assist/settings`) for fields that belong to no
 * chronicle (Schema Block catalog descriptions, Credits text), and a
 * chronicle-scoped pair (`/{game_slug}/ai-assist`, `/{game_slug}/ai-assist/settings`)
 * for everything else. `FIELD_CAPABILITIES` is the one authoritative map from
 * a client-supplied `field_context` to the capability that must actually
 * gate it - never trusted from the client itself, since a field_context
 * string alone proves nothing about who is allowed to touch that field.
 */
class Ai_Assist_Controller extends Base_Controller {

	protected $rest_base = 'ai-assist';

	/**
	 * The authoritative field_context -> capability map. Every key here
	 * must also exist in Ai_Assist::FIELD_CONTEXTS; the reverse isn't
	 * required (a context can exist without a caller-facing route yet).
	 */
	const FIELD_CAPABILITIES = [
		'character_biography'      => 'be_manage_characters',
		'character_notes'          => 'be_manage_characters',
		'npc_roleplaying_notes'    => 'be_manage_characters',
		'plot_description'         => 'be_manage_plots',
		'plot_cliffhanger'         => 'be_manage_plots',
		'plot_st_notes'            => 'be_manage_plots',
		'plot_entry'               => 'be_manage_plots',
		'rumor_description'        => 'be_manage_plots',
		'world_object_description' => 'be_manage_world_objects',
		'world_object_limitations' => 'be_manage_world_objects',
		'world_object_property'    => 'be_manage_world_objects',
		'schema_item_reference'    => 'be_manage_schemas',
		'schema_item_description'  => 'be_manage_schemas',
		'schema_item_source'       => 'be_manage_schemas',
		'approval_reason'          => 'be_manage_approval_rules',
		'chronicle_description'    => 'be_manage_games',
		'credits_text'             => 'be_manage_games',
	];

	/** field_contexts that belong to no chronicle - only reachable via the site-wide routes. */
	const SITE_WIDE_FIELD_CONTEXTS = [ 'schema_item_reference', 'schema_item_description', 'schema_item_source', 'credits_text' ];

	public function register_routes(): void {
		register_rest_route( $this->namespace, '/' . $this->rest_base, [
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'generate' ],
				'permission_callback' => [ $this, 'check_field_permission' ],
				'args'                => $this->get_generate_params(),
			],
		] );

		register_rest_route( $this->namespace, '/' . $this->rest_base . '/settings', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_site_settings' ],
				'permission_callback' => $this->permission( 'be_manage_games' ),
			],
			[
				'methods'             => 'PUT',
				'callback'            => [ $this, 'update_site_settings' ],
				'permission_callback' => $this->permission( 'be_manage_games' ),
			],
		] );

		// Tests a provider/key/endpoint combination the admin just typed - never the
		// currently-saved one implicitly, so a bad draft value can be caught before Save.
		register_rest_route( $this->namespace, '/' . $this->rest_base . '/test', [
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'test_site_connection' ],
				'permission_callback' => $this->permission( 'be_manage_games' ),
				'args'                => $this->get_test_params(),
			],
		] );

		register_rest_route( $this->namespace, '/(?P<game_slug>[a-z0-9\-]+)/' . $this->rest_base, [
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'generate' ],
				'permission_callback' => [ $this, 'check_field_permission' ],
				'args'                => $this->get_generate_params(),
			],
		] );

		// be_manage_apr, not be_manage_games: this chronicle-scoped route needs to be
		// reachable by an HST (WordPress editor, not administrator), matching the original
		// scope note's own "each HST supplies and stores their own key." be_manage_apr is
		// the closest existing precedent for "an HST configures how this chronicle
		// behaves" - the same access tier as, and the same Chronicle Setup hub as, Action &
		// Rumor Settings, which this tab sits alongside.
		register_rest_route( $this->namespace, '/(?P<game_slug>[a-z0-9\-]+)/' . $this->rest_base . '/settings', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_chronicle_settings' ],
				'permission_callback' => $this->permission( 'be_manage_apr' ),
			],
			[
				'methods'             => 'PUT',
				'callback'            => [ $this, 'update_chronicle_settings' ],
				'permission_callback' => $this->permission( 'be_manage_apr' ),
			],
		] );

		register_rest_route( $this->namespace, '/(?P<game_slug>[a-z0-9\-]+)/' . $this->rest_base . '/test', [
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'test_chronicle_connection' ],
				'permission_callback' => $this->permission( 'be_manage_apr' ),
				'args'                => $this->get_test_params(),
			],
		] );
	}

	/**
	 * Defines the request parameters accepted by both "test connection"
	 * routes: the exact value to test, which may not be saved yet.
	 *
	 * @return array
	 */
	private function get_test_params(): array {
		return [
			'provider' => [
				'type'     => 'string',
				'required' => true,
				'enum'     => [ 'openai', 'claude' ],
			],
			'key' => [
				'type'     => 'string',
				'required' => true,
			],
			'base_url' => [
				'type'    => 'string',
				'default' => '',
			],
			'model' => [
				'type'    => 'string',
				'default' => '',
			],
		];
	}

	/**
	 * Tests a provider/key/endpoint/model combination directly from the
	 * request body - used by the site-wide settings page's own "Test
	 * Connection" button before (or instead of) saving.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function test_site_connection( $request ) {
		return $this->run_test( $request, false );
	}

	/**
	 * Tests a provider/key/endpoint/model combination for one chronicle's
	 * own settings page - identical shape to the site-wide test, kept as
	 * two thin methods rather than one shared route so each stays gated by
	 * its own capability via register_routes()'s normal permission wiring.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function test_chronicle_connection( $request ) {
		return $this->run_test( $request, true );
	}

	/**
	 * Shared implementation for both test-connection routes. A chronicle's
	 * test requests only public addresses and answers only "connected" or
	 * "not" - the distinct failure messages a site administrator sees would
	 * otherwise tell a chronicle's Storyteller which addresses and ports on
	 * the host's network answer (1.0.0-review F-025).
	 *
	 * @param \WP_REST_Request $request
	 * @param bool             $chronicle
	 * @return \WP_REST_Response|\WP_Error
	 */
	private function run_test( \WP_REST_Request $request, bool $chronicle ) {
		$result = Ai_Assist::test_connection(
			(string) $request->get_param( 'provider' ),
			(string) $request->get_param( 'key' ),
			$chronicle ? esc_url_raw( (string) $request->get_param( 'base_url' ) ) : (string) $request->get_param( 'base_url' ),
			(string) $request->get_param( 'model' ),
			$chronicle
		);

		if ( ! $result['ok'] ) {
			if ( $chronicle && ( $result['code'] ?? '' ) !== 'ai_not_configured' ) {
				return $this->error( 'ai_connection_failed', __( 'Could not connect with these settings.', 'beyond-elysium' ), 502 );
			}
			return $this->error( $result['code'] ?? 'ai_error', $result['message'] ?? __( 'Connection test failed.', 'beyond-elysium' ), 502 );
		}
		return $this->success( [ 'message' => $result['message'] ] );
	}

	/**
	 * Permission callback for the generate route, shared by both the
	 * site-wide and chronicle-scoped registration. Looks up the capability
	 * that field_context actually requires from FIELD_CAPABILITIES - never
	 * a capability the client names itself - and checks it the same
	 * chronicle-scoped way every other write route in this plugin does.
	 * Also rejects a site-wide-only field_context reached through the
	 * chronicle-scoped route (and vice versa) before any generation work
	 * happens.
	 *
	 * @param \WP_REST_Request $request
	 * @return bool|\WP_Error
	 */
	public function check_field_permission( \WP_REST_Request $request ) {
		$field_context = (string) $request->get_param( 'field_context' );
		$capability    = self::FIELD_CAPABILITIES[ $field_context ] ?? null;
		if ( $capability === null ) {
			// Fails closed: a field with no capability mapped is refused here, before any key is
			// resolved or anything is sent upstream (1.0.0-review F-026).
			return new \WP_Error( 'unknown_field_context', __( 'Unknown field.', 'beyond-elysium' ), [ 'status' => 400 ] );
		}

		$has_game_slug = $request->get_url_params()['game_slug'] ?? null;
		$is_site_wide  = in_array( $field_context, self::SITE_WIDE_FIELD_CONTEXTS, true );
		if ( $is_site_wide === (bool) $has_game_slug ) {
			// A site-wide field reached via the game-scoped route, or a chronicle field reached
			// via the site-wide route - both are a real mismatch, not the field's own permission.
			return Authorization::denied();
		}

		return Authorization::check_request( $capability, $request ) ? true : Authorization::denied();
	}

	/**
	 * Generates a suggestion for one field. The route's own game_slug URL
	 * param (if present) is what gets passed to Ai_Assist::generate() -
	 * never a client-supplied game_slug in the body, so a caller can't ask
	 * for one chronicle's key to be used while editing a field that
	 * belongs to (and is capability-checked against) a different one.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function generate( $request ) {
		$game_slug = $request->get_url_params()['game_slug'] ?? null;

		if ( ! Ai_Assist::within_rate_limit( get_current_user_id() ) ) {
			return $this->error( 'ai_rate_limited', __( 'Too many suggestions in the last minute - wait a moment and try again.', 'beyond-elysium' ), 429 );
		}

		$result = Ai_Assist::generate(
			(string) $request->get_param( 'field_context' ),
			(string) $request->get_param( 'current_text' ),
			(string) $request->get_param( 'instruction' ),
			$game_slug
		);

		if ( ! $result['ok'] ) {
			$status = ( $result['code'] ?? '' ) === 'ai_not_configured' ? 503 : 502;
			return $this->error( $result['code'] ?? 'ai_error', $result['message'] ?? __( 'AI assist failed.', 'beyond-elysium' ), $status );
		}

		return $this->success( [ 'suggestion' => $result['suggestion'] ] );
	}

	/**
	 * Reports the site-wide AI assist configuration: which provider is
	 * active and whether each provider's key is configured - never the key
	 * material itself, even to an administrator re-opening this page.
	 *
	 * @return \WP_REST_Response
	 */
	public function get_site_settings() {
		return $this->success( [
			'provider'         => get_option( Ai_Assist::SITE_PROVIDER_OPTION, Ai_Assist::DEFAULT_PROVIDER ),
			'has_openai_key'   => (bool) get_option( Ai_Assist::SITE_OPENAI_KEY_OPTION, '' ),
			'has_claude_key'   => (bool) get_option( Ai_Assist::SITE_CLAUDE_KEY_OPTION, '' ),
			// Not secrets - echoed back in plain text, unlike the key fields above.
			'openai_base_url'  => (string) get_option( Ai_Assist::SITE_OPENAI_BASE_URL_OPTION, '' ),
			'openai_model'     => (string) get_option( Ai_Assist::SITE_OPENAI_MODEL_OPTION, '' ),
			'claude_base_url'  => (string) get_option( Ai_Assist::SITE_CLAUDE_BASE_URL_OPTION, '' ),
			'claude_model'     => (string) get_option( Ai_Assist::SITE_CLAUDE_MODEL_OPTION, '' ),
		] );
	}

	/**
	 * Updates the site-wide AI assist configuration. An empty string for
	 * either key field clears it (stored as no option value at all, not an
	 * encrypted empty string); a real value is encrypted before storage.
	 * Only fields present in the request are touched.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response
	 */
	public function update_site_settings( $request ) {
		$provider = $request->get_param( 'provider' );
		if ( $provider !== null && in_array( $provider, [ 'openai', 'claude' ], true ) ) {
			update_option( Ai_Assist::SITE_PROVIDER_OPTION, $provider );
		}

		$this->write_site_key( $request, 'openai_key', Ai_Assist::SITE_OPENAI_KEY_OPTION );
		$this->write_site_key( $request, 'claude_key', Ai_Assist::SITE_CLAUDE_KEY_OPTION );

		$this->write_plain_setting( $request, 'openai_base_url', Ai_Assist::SITE_OPENAI_BASE_URL_OPTION, true );
		$this->write_plain_setting( $request, 'openai_model', Ai_Assist::SITE_OPENAI_MODEL_OPTION, false );
		$this->write_plain_setting( $request, 'claude_base_url', Ai_Assist::SITE_CLAUDE_BASE_URL_OPTION, true );
		$this->write_plain_setting( $request, 'claude_model', Ai_Assist::SITE_CLAUDE_MODEL_OPTION, false );

		return $this->get_site_settings();
	}

	/**
	 * Writes one non-secret site-wide option (a base URL or model name)
	 * from the request - unlike write_site_key(), no encryption, since
	 * these carry no sensitive material and are echoed back in plain text
	 * by get_site_settings(). An empty string is a perfectly valid stored
	 * value here (resolve_key() treats it as "use the built-in default"),
	 * not a "clear" signal the way an empty key is.
	 *
	 * @param \WP_REST_Request $request
	 * @param string           $param_name
	 * @param string           $option_name
	 * @param bool             $is_url Uses esc_url_raw() instead of sanitize_text_field() when true.
	 */
	private function write_plain_setting( \WP_REST_Request $request, string $param_name, string $option_name, bool $is_url ): void {
		$value = $request->get_param( $param_name );
		if ( $value === null ) {
			return;
		}
		update_option( $option_name, $is_url ? esc_url_raw( (string) $value ) : sanitize_text_field( (string) $value ) );
	}

	/**
	 * Writes one site-wide key option from the request, encrypting a real
	 * value or deleting the option entirely for an explicit empty string.
	 * Absent from the request at all leaves the stored value untouched.
	 *
	 * @param \WP_REST_Request $request
	 * @param string           $param_name
	 * @param string           $option_name
	 */
	private function write_site_key( \WP_REST_Request $request, string $param_name, string $option_name ): void {
		$value = $request->get_param( $param_name );
		if ( $value === null ) {
			return;
		}
		if ( $value === '' ) {
			delete_option( $option_name );
			return;
		}
		update_option( $option_name, Ai_Assist::encrypt( (string) $value ) );
	}

	/**
	 * Reports one chronicle's own AI assist configuration: whether it has
	 * opted in, which provider it uses, and whether it has its own key for
	 * each provider (falling back to the site-wide key otherwise) - never
	 * the key material itself.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_chronicle_settings( $request ) {
		$game = Game::find_by_slug( $request['game_slug'] );
		if ( ! $game ) {
			return $this->error( 'not_found', __( 'Game not found.', 'beyond-elysium' ), 404 );
		}

		$settings = $game->settings ?? new \stdClass();
		return $this->success( [
			'enabled'         => ! empty( $settings->ai_assist_enabled ),
			'provider'        => $settings->ai_provider ?? get_option( Ai_Assist::SITE_PROVIDER_OPTION, Ai_Assist::DEFAULT_PROVIDER ),
			'has_openai_key'  => ! empty( $settings->ai_openai_key ),
			'has_claude_key'  => ! empty( $settings->ai_claude_key ),
			// Not secrets - echoed back in plain text, unlike the key fields above.
			'openai_base_url' => (string) ( $settings->ai_openai_base_url ?? '' ),
			'openai_model'    => (string) ( $settings->ai_openai_model ?? '' ),
			'claude_base_url' => (string) ( $settings->ai_claude_base_url ?? '' ),
			'claude_model'    => (string) ( $settings->ai_claude_model ?? '' ),
		] );
	}

	/**
	 * Updates one chronicle's own AI assist configuration - opt-in toggle,
	 * provider choice, and its own optional key override - through the same
	 * merge-into-settings discipline every other per-chronicle setting in
	 * this plugin already uses (Games_Controller::update_item()), via
	 * Ai_Assist::merge_settings_write() for the key-specific
	 * encrypt/clear handling.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function update_chronicle_settings( $request ) {
		$game = Game::find_by_slug( $request['game_slug'] );
		if ( ! $game ) {
			return $this->error( 'not_found', __( 'Game not found.', 'beyond-elysium' ), 404 );
		}

		$incoming = [];
		$enabled  = $request->get_param( 'enabled' );
		if ( $enabled !== null ) {
			$incoming['ai_assist_enabled'] = (bool) $enabled;
		}
		$provider = $request->get_param( 'provider' );
		if ( $provider !== null && in_array( $provider, [ 'openai', 'claude' ], true ) ) {
			$incoming['ai_provider'] = $provider;
		}
		foreach ( [ 'openai_key' => 'ai_openai_key', 'claude_key' => 'ai_claude_key' ] as $param => $settings_field ) {
			$value = $request->get_param( $param );
			if ( $value !== null ) {
				$incoming[ $settings_field ] = $value;
			}
		}
		// Not secrets - stored/merged like any other plain setting, no encryption.
		foreach ( [
			'openai_base_url' => 'ai_openai_base_url',
			'openai_model'    => 'ai_openai_model',
			'claude_base_url' => 'ai_claude_base_url',
			'claude_model'    => 'ai_claude_model',
		] as $param => $settings_field ) {
			$value = $request->get_param( $param );
			if ( $value !== null ) {
				$is_url = str_ends_with( $param, '_base_url' );
				$incoming[ $settings_field ] = $is_url ? esc_url_raw( (string) $value ) : sanitize_text_field( (string) $value );
			}
		}

		if ( ! empty( $incoming ) ) {
			$existing = (array) ( $game->settings ?? new \stdClass() );
			$merged   = Ai_Assist::merge_settings_write( $incoming, $existing );
			if ( ! Game::update( $game->slug, [ 'settings' => $merged ] ) ) {
				return $this->error( 'save_failed', __( 'Failed to update game.', 'beyond-elysium' ), 500 );
			}
		}

		return $this->get_chronicle_settings( $request );
	}

	/**
	 * Defines the request parameters accepted by the generate route.
	 *
	 * @return array
	 */
	private function get_generate_params(): array {
		return [
			'field_context' => [
				'type'     => 'string',
				'required' => true,
			],
			'current_text' => [
				'type'    => 'string',
				'default' => '',
			],
			'instruction' => [
				'type'    => 'string',
				'default' => '',
				'sanitize_callback' => 'sanitize_text_field',
			],
		];
	}
}
