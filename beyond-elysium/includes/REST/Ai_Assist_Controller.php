<?php

namespace BeyondElysium\REST;

use BeyondElysium\Core\Authorization;
use BeyondElysium\Database\Transaction;
use BeyondElysium\Models\After_Game_Report;
use BeyondElysium\Models\Attendance;
use BeyondElysium\Models\Character;
use BeyondElysium\Models\Faction;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Game_Session;
use BeyondElysium\Models\Plot;
use BeyondElysium\Models\Plot_Entry;
use BeyondElysium\Services\Ai_Assist;
use BeyondElysium\Services\Audience;
use BeyondElysium\Services\Demo_Chronicle;
use BeyondElysium\Services\Rumor_Generator;

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
		'npc_public_description'   => 'be_manage_characters',
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

		register_rest_route( $this->namespace, '/(?P<game_slug>[a-z0-9\-]+)/' . $this->rest_base . '/npc-draft', [
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'draft_npc' ],
				'permission_callback' => $this->permission( 'be_manage_characters' ),
				'args'                => [
					'character_id' => [ 'type' => 'integer', 'required' => true ],
					'instruction'  => [ 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ],
				],
			],
		] );

		register_rest_route( $this->namespace, '/(?P<game_slug>[a-z0-9\-]+)/' . $this->rest_base . '/plot-draft', [
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'draft_plot' ],
				'permission_callback' => $this->permission( 'be_manage_plots' ),
				'args'                => [
					'premise'       => [ 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_textarea_field' ],
					'character_ids' => [ 'type' => 'array', 'default' => [], 'items' => [ 'type' => 'integer' ] ],
					'npc_ids'       => [ 'type' => 'array', 'default' => [], 'items' => [ 'type' => 'integer' ] ],
					'faction_ids'   => [ 'type' => 'array', 'default' => [], 'items' => [ 'type' => 'integer' ] ],
					'instruction'   => [ 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ],
				],
			],
		] );

		register_rest_route( $this->namespace, '/(?P<game_slug>[a-z0-9\-]+)/' . $this->rest_base . '/recap-draft', [
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'draft_recap' ],
				'permission_callback' => $this->permission( 'be_manage_sessions' ),
				'args'                => [
					'session_id'  => [ 'type' => 'integer', 'required' => true ],
					'instruction' => [ 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ],
				],
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
	 * @return array<string,array<string,mixed>>
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

		if ( $game_slug ) {
			$game = \BeyondElysium\Models\Game::find_by_slug( $game_slug );
			if ( $game && \BeyondElysium\Services\Demo_Chronicle::is_demo( $game ) ) {
				return $this->error( 'demo_locked', __( 'AI Assist is not available on a demo chronicle.', 'beyond-elysium' ), 403 );
			}
		}

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
	 * Shared demo-lock and rate-limit check for every structured draft route. Returns null when the request may
	 * proceed.
	 *
	 * @param string $game_slug
	 * @return \WP_Error|null
	 */
	private function check_draft_preconditions( string $game_slug ) {
		$game = Game::find_by_slug( $game_slug );
		if ( $game && Demo_Chronicle::is_demo( $game ) ) {
			return $this->error( 'demo_locked', __( 'AI Assist is not available on a demo chronicle.', 'beyond-elysium' ), 403 );
		}
		if ( ! Ai_Assist::within_rate_limit( get_current_user_id() ) ) {
			return $this->error( 'ai_rate_limited', __( 'Too many suggestions in the last minute - wait a moment and try again.', 'beyond-elysium' ), 429 );
		}
		return null;
	}

	/**
	 * Every non-empty string among a list's own values, capped at `$max` entries.
	 *
	 * @param mixed $value
	 * @return array<int,string>
	 */
	private static function only_strings( $value, int $max ): array {
		$strings = [];
		foreach ( (array) $value as $item ) {
			if ( is_string( $item ) && $item !== '' ) {
				$strings[] = $item;
			}
			if ( count( $strings ) >= $max ) {
				break;
			}
		}
		return $strings;
	}

	/**
	 * Converts a structured-draft failure into the same error-response shape `generate()` already uses.
	 *
	 * @param array{ok:bool,code?:string,message?:string} $result
	 * @return \WP_Error
	 */
	private function draft_error( array $result ) {
		$status = ( $result['code'] ?? '' ) === 'ai_not_configured' ? 503 : 502;
		return $this->error( $result['code'] ?? 'ai_error', $result['message'] ?? __( 'AI assist failed.', 'beyond-elysium' ), $status );
	}

	/**
	 * Drafts Storyteller-only roleplaying notes for one NPC, from that NPC's own data only. Returns the drafted
	 * fields; nothing is written here - the Storyteller reviews and saves them like any other edit.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function draft_npc( $request ) {
		$game_slug = (string) $request['game_slug'];
		$blocked   = $this->check_draft_preconditions( $game_slug );
		if ( $blocked !== null ) {
			return $blocked;
		}

		$character = Character::find( (int) $request->get_param( 'character_id' ) );
		if ( ! $character || $character->owner_slug !== $game_slug ) {
			return $this->error( 'not_found', __( 'Character not found in this game.', 'beyond-elysium' ), 404 );
		}

		$identity = $character->sheet_data[ $character->stack_slug . '-identity' ] ?? [];
		$current  = $character->sheet_data['npc-roleplaying-notes'] ?? [];
		$context  = [
			'name'          => $character->name,
			'creature_type' => $character->stack_slug,
			'identity'      => $identity,
			'public_profile' => (string) ( $character->public_description ?? '' ),
			'biography'     => (string) ( $character->biography ?? '' ),
			'notes'         => (string) ( $character->notes ?? '' ),
			'current_notes' => $current,
		];

		$result = Ai_Assist::generate_structured( 'npc_roleplaying_draft', $context, (string) $request->get_param( 'instruction' ), $game_slug );
		if ( ! $result['ok'] ) {
			return $this->draft_error( $result );
		}
		return $this->success( [ 'data' => $result['data'] ] );
	}

	/**
	 * Drafts a new Storyteller plot from a premise and optionally-picked characters/NPCs/factions, and writes
	 * the plot, its Storyteller-only beats and its held rumors in one transaction. Picked characters contribute
	 * their public profile and identity fields only - nothing Storyteller-only from another character reaches
	 * the provider.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function draft_plot( $request ) {
		$game_slug = (string) $request['game_slug'];
		$blocked   = $this->check_draft_preconditions( $game_slug );
		if ( $blocked !== null ) {
			return $blocked;
		}
		$game = Game::find_by_slug( $game_slug );
		if ( ! $game ) {
			return $this->error( 'not_found', __( 'Game not found.', 'beyond-elysium' ), 404 );
		}

		$characters = [];
		$ids        = array_unique( array_merge(
			(array) $request->get_param( 'character_ids' ),
			(array) $request->get_param( 'npc_ids' )
		) );
		foreach ( $ids as $id ) {
			$character = Character::find( (int) $id );
			if ( ! $character || $character->owner_slug !== $game_slug ) {
				continue;
			}
			$characters[] = [
				'name'           => $character->is_npc ? $character->name : (string) ( $character->public_name ?: $character->name ),
				'creature_type'  => $character->stack_slug,
				'identity'       => $character->sheet_data[ $character->stack_slug . '-identity' ] ?? [],
				'public_profile' => (string) ( $character->public_description ?? '' ),
			];
		}

		$factions = [];
		foreach ( (array) $request->get_param( 'faction_ids' ) as $id ) {
			$faction = Faction::find( (int) $id );
			if ( $faction && (int) $faction->game_id === (int) $game->id ) {
				$factions[] = [ 'name' => $faction->name, 'description' => (string) ( $faction->description ?? '' ), 'goals' => (string) ( $faction->goals ?? '' ) ];
			}
		}

		$context = [ 'premise' => (string) $request->get_param( 'premise' ), 'characters' => $characters, 'factions' => $factions ];
		$result  = Ai_Assist::generate_structured( 'plot_draft', $context, (string) $request->get_param( 'instruction' ), $game_slug );
		if ( ! $result['ok'] ) {
			return $this->draft_error( $result );
		}

		$notes  = self::only_strings( $result['data']['notes'] ?? [], 5 );
		$rumors = self::only_strings( $result['data']['rumors'] ?? [], 3 );
		if ( $notes === [] || $rumors === [] ) {
			return $this->error( 'ai_invalid_reply', __( 'The AI provider replied, but not with the expected structure. Try again.', 'beyond-elysium' ), 502 );
		}

		$savepoint = Transaction::begin( 'ai_plot_draft' );
		try {
			$plot_id = Plot::create( [
				'game_id'      => $game->id,
				'title'        => sanitize_text_field( (string) ( $result['data']['title'] ?? '' ) ) ?: __( 'Untitled plot', 'beyond-elysium' ),
				'description'  => wp_kses_post( (string) ( $result['data']['description'] ?? '' ) ),
				'st_notes'     => wp_kses_post( (string) ( $result['data']['st_notes'] ?? '' ) ),
				'cliffhanger'  => wp_kses_post( (string) ( $result['data']['cliffhanger'] ?? '' ) ),
				'audience'     => Audience::STORYTELLERS,
				'initiated_by' => 'st',
			] );
			if ( ! $plot_id ) {
				throw new \RuntimeException( 'plot create failed' );
			}
			foreach ( $notes as $note ) {
				if ( ! Plot_Entry::create( [ 'plot_id' => $plot_id, 'entry_type' => 'note', 'content' => wp_kses_post( $note ), 'audience' => Plot_Entry::AUDIENCE_STORYTELLERS ] ) ) {
					throw new \RuntimeException( 'plot entry create failed' );
				}
			}
			foreach ( $rumors as $rumor_text ) {
				$rumor_text    = wp_kses_post( $rumor_text );
				$rumor_plot_id = Plot::create( [ 'game_id' => $game->id, 'title' => sanitize_text_field( wp_trim_words( $rumor_text, 8 ) ), 'description' => $rumor_text, 'audience' => Audience::EVERYONE, 'initiated_by' => 'st' ] );
				if ( ! $rumor_plot_id || ! Plot::update( $rumor_plot_id, [ 'held' => true ] ) ) {
					throw new \RuntimeException( 'rumor plot create failed' );
				}
				Rumor_Generator::tag_as_rumor( $rumor_plot_id, $game->id );
			}
			Transaction::commit( $savepoint );
		} catch ( \Throwable $e ) {
			Transaction::rollback( $savepoint );
			return $this->error( 'draft_failed', __( 'Could not create the drafted plot.', 'beyond-elysium' ), 500 );
		}

		return $this->success( [ 'plot_id' => $plot_id ] );
	}

	/**
	 * Drafts a Storyteller's own recap of one game night, from that night's real attendance, after-game reports
	 * and dated plot entries. Returns the drafted fields; nothing is written here.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function draft_recap( $request ) {
		$game_slug = (string) $request['game_slug'];
		$blocked   = $this->check_draft_preconditions( $game_slug );
		if ( $blocked !== null ) {
			return $blocked;
		}
		$game = Game::find_by_slug( $game_slug );
		if ( ! $game ) {
			return $this->error( 'not_found', __( 'Game not found.', 'beyond-elysium' ), 404 );
		}
		$session = Game_Session::find( (int) $request->get_param( 'session_id' ) );
		if ( ! $session || (int) $session->game_id !== (int) $game->id ) {
			return $this->error( 'not_found', __( 'Session not found in this game.', 'beyond-elysium' ), 404 );
		}

		$attendance_names = [];
		foreach ( Attendance::for_session( $session->id ) as $row ) {
			$character = $row->character_id ? Character::find( (int) $row->character_id ) : null;
			$attendance_names[] = $character ? $character->name : (string) ( $row->visitor_name ?? '' );
		}

		$reports = [];
		foreach ( After_Game_Report::for_session( $session->id ) as $report ) {
			$character = Character::find( (int) $report->character_id );
			$reports[] = [
				'character' => $character ? $character->name : '',
				'did'       => (string) ( $report->did ?? '' ),
				'wants'     => (string) ( $report->wants ?? '' ),
				'to_staff'  => (string) ( $report->to_staff ?? '' ),
			];
		}

		$entries = array_map(
			static fn( $entry ) => (string) ( $entry->content ?? '' ),
			Plot_Entry::for_game_on_date( (int) $game->id, (string) $session->game_date )
		);

		$context = [
			'game_date'           => $session->game_date,
			'attendance'          => self::only_strings( $attendance_names, 200 ),
			'after_game_reports'  => $reports,
			'plot_entries'        => self::only_strings( $entries, 200 ),
		];

		$result = Ai_Assist::generate_structured( 'session_recap_draft', $context, (string) $request->get_param( 'instruction' ), $game_slug );
		if ( ! $result['ok'] ) {
			return $this->draft_error( $result );
		}
		return $this->success( [ 'data' => $result['data'] ] );
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
			// Not secrets; returned in plain text.
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
			// Not secrets; returned in plain text.
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
		// Not secrets; stored and merged like any other plain setting.
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
	 * @return array<string,array<string,mixed>>
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
