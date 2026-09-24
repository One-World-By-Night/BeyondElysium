<?php

namespace BeyondElysium\REST;

use BeyondElysium\Core\Authorization;
use BeyondElysium\Models\Game;
use BeyondElysium\Services\Ai_Assist;

defined( 'ABSPATH' ) || exit;

/**
 * REST controller for the AI writing-assist tool.
 */
class Ai_Assist_Controller extends Base_Controller {

	protected $rest_base = 'ai-assist';

	/**
	 * The authoritative field_context -> capability map.
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

	/**
	 * field_contexts that belong to no chronicle.
	 */
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

		// Tests a provider/key/endpoint combination the admin just typed.
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
	 * Defines the request parameters accepted by both "test connection" routes: the exact value to test.
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
	 * Tests a provider/key/endpoint/model combination directly from the request body.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function test_site_connection( $request ) {
		return $this->run_test( $request, false );
	}

	/**
	 * Tests a provider/key/endpoint/model combination for one chronicle's own settings page.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function test_chronicle_connection( $request ) {
		return $this->run_test( $request, true );
	}

	/**
	 * Shared implementation for both test-connection routes.
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
	 * Permission callback for the generate route, shared by both the site-wide and chronicle-scoped registration.
	 *
	 * @param \WP_REST_Request $request
	 * @return bool|\WP_Error
	 */
	public function check_field_permission( \WP_REST_Request $request ) {
		$field_context = (string) $request->get_param( 'field_context' );
		$capability    = self::FIELD_CAPABILITIES[ $field_context ] ?? null;
		if ( $capability === null ) {
			return new \WP_Error( 'unknown_field_context', __( 'Unknown field.', 'beyond-elysium' ), [ 'status' => 400 ] );
		}

		$has_game_slug = $request->get_url_params()['game_slug'] ?? null;
		$is_site_wide  = in_array( $field_context, self::SITE_WIDE_FIELD_CONTEXTS, true );
		if ( $is_site_wide === (bool) $has_game_slug ) {
			// A site-wide field reached via the game-scoped route, or a chronicle field reached via the site-wide route.
			return Authorization::denied();
		}

		return Authorization::check_request( $capability, $request ) ? true : Authorization::denied();
	}

	/**
	 * Generates a suggestion for one field.
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
	 * Reports the site-wide AI assist configuration: which provider is active and whether each provider's key is
	 * configured.
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
	 * Updates the site-wide AI assist configuration.
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
	 * Writes one non-secret site-wide option (a base URL or model name) from the request.
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
	 * Writes one site-wide key option from the request, encrypting a real value or deleting the option entirely for an
	 * explicit empty string.
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
	 * Reports one chronicle's own AI assist configuration: whether it has opted.
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
	 * Updates one chronicle's own AI assist configuration.
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
