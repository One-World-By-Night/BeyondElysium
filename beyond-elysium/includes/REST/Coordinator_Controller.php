<?php

namespace BeyondElysium\REST;

use BeyondElysium\Models\Character;
use BeyondElysium\Models\Coordinator_Request;
use BeyondElysium\Models\Game;
use BeyondElysium\Services\Coordinator_Reader;
use BeyondElysium\Services\Coordinator_Send;
use BeyondElysium\Services\Coordinator_Sender;
use BeyondElysium\Services\Oat_Client;
use BeyondElysium\Services\Oat_Connection;
use BeyondElysium\Services\Pending_Picks;

defined( 'ABSPATH' ) || exit;

/**
 * What a Storyteller uses to send a waiting change to the Archivist Toolkit: how a send goes here, a character's link to
 * its Toolkit record and the search that finds it, the Toolkit's rules and the Coordinator offices to tick, the send
 * itself, and a read of where a request stands. Open to whoever approves changes in the chronicle, and to no one else.
 */
class Coordinator_Controller extends Base_Controller {

	protected $rest_base = 'coordinator';

	public function register_routes(): void {
		$base      = '/(?P<game_slug>[a-z0-9\-]+)';
		$character = $base . '/characters/(?P<character_id>\d+)';

		$route = function ( string $path, string $method, string $callback ): void {
			register_rest_route( $this->namespace, $path, [
				[
					'methods'             => $method,
					'callback'            => [ $this, $callback ],
					'permission_callback' => $this->permission( 'be_manage_characters' ),
				],
			] );
		};

		$route( $base . '/coordinator', 'GET', 'get_status' );
		$route( $base . '/coordinator/rules', 'GET', 'search_rules' );
		$route( $base . '/coordinator/offices', 'GET', 'get_offices' );
		$route( $base . '/coordinator/send', 'POST', 'send' );
		$route( $base . '/coordinator/requests/(?P<id>\d+)/check', 'POST', 'check' );
		$route( $character . '/coordinator-picks', 'GET', 'get_picks' );
		$route( $character . '/toolkit-search', 'GET', 'search_characters' );
		$route( $character . '/toolkit-link', 'GET', 'get_link' );
		$route( $character . '/toolkit-link', 'PUT', 'put_link' );
		$route( $character . '/toolkit-link', 'DELETE', 'delete_link' );
	}

	/**
	 * How a send goes in this chronicle, and who it would be made as.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_status( $request ) {
		$game = $this->game( $request );
		if ( is_wp_error( $game ) ) {
			return $game;
		}
		$status = Oat_Connection::status( $game );
		$user   = wp_get_current_user();
		return $this->success( [
			'mode'      => Coordinator_Send::mode( $game ),
			'enabled'   => ( $game->settings->coordinator_send ?? false ) === true,
			'demo'      => \BeyondElysium\Services\Demo_Chronicle::is_demo( $game ),
			'host'      => $status['host'],
			'chronicle' => $status['chronicle'],
			'sender'    => [ 'name' => (string) $user->display_name, 'email' => (string) $user->user_email ],
		] );
	}

	/**
	 * The Toolkit's regulation rules that match a search.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function search_rules( $request ) {
		$game = $this->game( $request );
		if ( is_wp_error( $game ) ) {
			return $game;
		}
		$term = trim( (string) $request->get_param( 'term' ) );
		if ( mb_strlen( $term ) < 2 || Coordinator_Send::mode( $game ) !== Coordinator_Send::LIVE ) {
			return $this->success( [ 'results' => [] ] );
		}

		$answer = Oat_Client::call( 'rules/search', [ 'q' => sanitize_text_field( $term ) ], wp_get_current_user() );
		if ( ! $answer['ok'] ) {
			return $this->toolkit_error( $answer );
		}
		$rows = [];
		foreach ( $answer['data'] as $rule ) {
			$rule   = (array) $rule;
			$rows[] = [
				'id'          => (int) ( $rule['id'] ?? 0 ),
				'label'       => (string) ( $rule['label'] ?? '' ),
				'genre'       => (string) ( $rule['genre'] ?? '' ),
				'category'    => (string) ( $rule['category'] ?? '' ),
				'condition'   => (string) ( $rule['condition'] ?? '' ),
				'pc_level'    => (string) ( $rule['pc_level'] ?? '' ),
				'npc_level'   => (string) ( $rule['npc_level'] ?? '' ),
				'coordinator' => (string) ( $rule['coordinator'] ?? '' ),
			];
		}
		return $this->success( [ 'results' => $rows ] );
	}

	/**
	 * The Coordinator offices a request can name, from owbn-core's list.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_offices( $request ) {
		$offices = [];
		$listed  = function_exists( 'owc_get_coordinators' ) ? owc_get_coordinators() : [];
		foreach ( is_array( $listed ) ? $listed : [] as $office ) {
			$office = (array) $office;
			$slug   = (string) ( $office['slug'] ?? '' );
			if ( $slug === '' ) {
				continue;
			}
			$name      = (string) ( $office['title'] ?? $office['coordinator_title'] ?? '' );
			$offices[] = [ 'slug' => $slug, 'name' => $name !== '' ? $name : $slug ];
		}
		usort( $offices, static fn( $a, $b ): int => strcasecmp( $a['name'], $b['name'] ) );
		return $this->success( [ 'offices' => $offices ] );
	}

	/**
	 * Characters in the Toolkit that could be this one, as the Storyteller sees them there.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function search_characters( $request ) {
		$both = $this->game_and_character( $request );
		if ( is_wp_error( $both ) ) {
			return $both;
		}
		[ $game, $character ] = $both;
		$term = trim( (string) $request->get_param( 'term' ) );
		if ( mb_strlen( $term ) < 2 || Coordinator_Send::mode( $game ) !== Coordinator_Send::LIVE ) {
			return $this->success( [ 'results' => [] ] );
		}

		$answer = Oat_Client::call( 'characters/search', [
			'term'           => sanitize_text_field( $term ),
			'scope'          => 'staff',
			'chronicle_slug' => (string) ( Oat_Connection::chronicle_slug( $game ) ?? '' ),
		], wp_get_current_user() );
		if ( ! $answer['ok'] ) {
			return $this->toolkit_error( $answer );
		}

		$rows = [];
		foreach ( $answer['data'] as $found ) {
			$found  = (array) $found;
			$name   = (string) ( $found['character_name'] ?? '' );
			$rows[] = [
				'uuid'      => (string) ( $found['uuid'] ?? '' ),
				'name'      => $name,
				'chronicle' => (string) ( $found['chronicle_title'] ?? $found['chronicle_slug'] ?? '' ),
				'player'    => (string) ( $found['player_name'] ?? '' ),
				'pc_npc'    => (string) ( $found['pc_npc'] ?? '' ),
				'exact'     => strcasecmp( $name, (string) $character->name ) === 0,
			];
		}
		usort( $rows, static fn( $a, $b ): int => (int) $b['exact'] <=> (int) $a['exact'] );
		return $this->success( [ 'results' => $rows ] );
	}

	/**
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_link( $request ) {
		$both = $this->game_and_character( $request );
		return is_wp_error( $both ) ? $both : $this->success( [ 'uuid' => $both[1]->oat_character_uuid ?? null ] );
	}

	/**
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function put_link( $request ) {
		$both = $this->game_and_character( $request );
		if ( is_wp_error( $both ) ) {
			return $both;
		}
		$character = $both[1];
		$uuid      = strtolower( trim( (string) $request->get_param( 'uuid' ) ) );
		if ( preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $uuid ) !== 1 ) {
			return $this->error( 'invalid_param', __( 'That is not a character from the Archivist Toolkit.', 'beyond-elysium' ), 400 );
		}
		if ( ! Character::set_oat_link( (int) $character->id, $uuid ) ) {
			return $this->error( 'update_failed', __( 'The link could not be saved.', 'beyond-elysium' ), 500 );
		}
		return $this->success( [ 'uuid' => $uuid ] );
	}

	/**
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function delete_link( $request ) {
		$both = $this->game_and_character( $request );
		if ( is_wp_error( $both ) ) {
			return $both;
		}
		Character::set_oat_link( (int) $both[1]->id, null );
		return $this->success( [ 'uuid' => null ] );
	}

	/**
	 * The picks of a pending new character that ask for a Coordinator, with where each one's request stands.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_picks( $request ) {
		$both = $this->game_and_character( $request );
		if ( is_wp_error( $both ) ) {
			return $both;
		}
		return $this->success( [ 'picks' => Pending_Picks::for_character( $both[0], $both[1] ) ] );
	}

	/**
	 * Sends waiting changes to the Toolkit, or shows what would be sent.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function send( $request ) {
		$game = $this->game( $request );
		if ( is_wp_error( $game ) ) {
			return $game;
		}
		$character = Character::find( (int) $request->get_param( 'character_id' ) );
		if ( ! $character || $character->owner_slug !== $game->slug ) {
			return $this->error( 'not_found', __( 'That character was not found.', 'beyond-elysium' ), 404 );
		}
		$ids  = $request->get_param( 'change_ids' );
		$keys = $request->get_param( 'pick_keys' );
		if ( ! is_array( $keys ) && ( ! is_array( $ids ) || $ids === [] ) ) {
			return $this->error( 'invalid_param', __( 'Name at least one change to send.', 'beyond-elysium' ), 400 );
		}

		$rules = [];
		foreach ( (array) $request->get_param( 'rules' ) as $rule ) {
			$rule = (array) $rule;
			if ( (int) ( $rule['id'] ?? 0 ) > 0 ) {
				$rules[] = [ 'id' => absint( $rule['id'] ), 'pc_level' => sanitize_key( (string) ( $rule['pc_level'] ?? '' ) ), 'npc_level' => sanitize_key( (string) ( $rule['npc_level'] ?? '' ) ) ];
			}
		}

		$options = [
			'rules'        => $rules,
			'coordinators' => array_values( array_filter( array_map( 'sanitize_title', array_map( 'strval', (array) $request->get_param( 'coordinators' ) ) ) ) ),
			'note'         => sanitize_textarea_field( (string) $request->get_param( 'note' ) ),
			'purpose'      => (string) $request->get_param( 'purpose' ),
		];
		$sent    = is_array( $keys )
			? Coordinator_Sender::send_picks( $game, wp_get_current_user(), $character, array_map( 'sanitize_key', array_map( 'strval', $keys ) ), $options )
			: Coordinator_Sender::send( $game, wp_get_current_user(), $character, array_map( 'intval', (array) $ids ), $options );
		if ( is_wp_error( $sent ) ) {
			return $sent;
		}

		$results = $sent['results'] ?? [];
		if ( $results !== [] && array_unique( array_column( $results, 'state' ) ) === [ 'refused' ] ) {
			return $this->error( (string) ( $results[0]['code'] ?? 'oat_refused' ), (string) ( $results[0]['message'] ?? '' ), 422 );
		}
		return $this->success( $sent );
	}

	/**
	 * Reads one request now, as the person who asks, and says where it stands.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function check( $request ) {
		$game = $this->game( $request );
		if ( is_wp_error( $game ) ) {
			return $game;
		}
		$row = Coordinator_Request::find( (int) $request['id'] );
		if ( ! $row || $row->game_id !== (int) $game->id ) {
			return $this->error( 'not_found', __( 'That request was not found.', 'beyond-elysium' ), 404 );
		}
		if ( Coordinator_Send::mode( $game ) !== Coordinator_Send::LIVE ) {
			return $this->error( 'coordinator_send_off', __( 'Sending to a Coordinator is not switched on for this chronicle.', 'beyond-elysium' ), 409 );
		}

		$result = Coordinator_Reader::read( $row, wp_get_current_user() );
		$fresh  = Coordinator_Request::find( $row->id );
		return $this->success( [
			'request' => $fresh ? Coordinator_Request::view( $fresh, true ) : null,
			'message' => $result['message'],
		] );
	}

	/**
	 * @param \WP_REST_Request $request
	 * @return object|\WP_Error
	 */
	private function game( $request ) {
		$game = Game::find_by_slug( (string) $request['game_slug'] );
		return $game ?: $this->error( 'game_not_found', __( 'Game not found.', 'beyond-elysium' ), 404 );
	}

	/**
	 * @param \WP_REST_Request $request
	 * @return array{0:object,1:object}|\WP_Error
	 */
	private function game_and_character( $request ) {
		$game = $this->game( $request );
		if ( is_wp_error( $game ) ) {
			return $game;
		}
		$character = Character::find( (int) $request['character_id'] );
		if ( ! $character || $character->owner_slug !== $game->slug ) {
			return $this->error( 'not_found', __( 'That character was not found.', 'beyond-elysium' ), 404 );
		}
		return [ $game, $character ];
	}

	/**
	 * The Toolkit's refusal or silence, as an error the screen shows word for word.
	 *
	 * @param array{ok:bool,status:int,code:string,message:string,data:array<mixed>,challenge:bool} $answer
	 */
	private function toolkit_error( array $answer ): \WP_Error {
		$silent = in_array( $answer['code'], [ 'oat_unreachable', 'oat_not_json' ], true );
		return $this->error( $answer['code'], $answer['message'], $silent ? 502 : 422 );
	}
}
