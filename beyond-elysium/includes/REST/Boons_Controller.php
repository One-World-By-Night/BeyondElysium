<?php

namespace BeyondElysium\REST;

use BeyondElysium\Database\Transaction;
use BeyondElysium\Models\Character;
use BeyondElysium\Models\Connection;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\World_Object;

defined( 'ABSPATH' ) || exit;

/**
 * REST controller for boons: debts owed between characters.
 */
class Boons_Controller extends Base_Controller {

	protected $rest_base = 'boons';

	/**
	 * Connection labels linking a boon object to its two parties.
	 */
	const OWED_BY_LABEL = 'owed_by';
	const OWED_TO_LABEL = 'owed_to';

	/**
	 * Registers the game-scoped boon routes.
	 */
	public function register_routes(): void {
		register_rest_route( $this->namespace, '/(?P<game_slug>[a-z0-9\-]+)/boons', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_items' ],
				'permission_callback' => $this->permission( 'be_view_characters' ),
			],
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'create_item' ],
				'permission_callback' => $this->permission( 'be_manage_boons' ),
			],
		] );

		register_rest_route( $this->namespace, '/(?P<game_slug>[a-z0-9\-]+)/boons/(?P<id>\d+)/repay', [
			[
				'methods'             => 'PUT',
				'callback'            => [ $this, 'repay' ],
				'permission_callback' => $this->permission( 'be_manage_boons' ),
			],
		] );
	}

	/**
	 * Lists every boon in the game as a resolved ledger.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_items( $request ) {
		$game = $this->resolve_game( $request['game_slug'] );
		if ( is_wp_error( $game ) ) {
			return $game;
		}

		$boons      = World_Object::for_game( (int) $game->id, [ 'object_type' => 'boon', 'orderby' => 'created_at', 'order' => 'DESC' ] );
		$can_manage = \BeyondElysium\Core\Authorization::can( 'be_manage_boons' );
		foreach ( $boons as $boon ) {
			\BeyondElysium\Services\St_Visibility::filter_world_object( $boon, $game, $can_manage );
		}

		$level          = $request->get_param( 'level' );
		$status         = $request->get_param( 'status' );
		$character_id   = $request->get_param( 'character_id' );

		$boons = array_values( array_filter( $boons, static function ( $boon ) use ( $level, $status ) {
			return ( ! $level || ( $boon->properties['boon_level'] ?? '' ) === $level )
				&& ( ! $status || ( $boon->properties['status'] ?? 'outstanding' ) === $status );
		} ) );
		$parties_by_boon = $this->parties_by_boon( array_map( static fn( $boon ) => (int) $boon->id, $boons ) );

		$ledger = [];
		foreach ( $boons as $boon ) {
			$parties = $parties_by_boon[ (int) $boon->id ] ?? null;
			if ( $parties === null ) {
				continue;
			}
			if ( $character_id
				&& (int) $parties['owed_by']['id'] !== (int) $character_id
				&& (int) $parties['owed_to']['id'] !== (int) $character_id
			) {
				continue;
			}

			$ledger[] = array_merge( (array) $boon, $parties );
		}

		// Sorts outstanding boons before repaid ones, each group by date descending.
		usort( $ledger, static function ( $a, $b ) {
			$a_outstanding = ( $a['properties']['status'] ?? 'outstanding' ) !== 'repaid';
			$b_outstanding = ( $b['properties']['status'] ?? 'outstanding' ) !== 'repaid';
			if ( $a_outstanding !== $b_outstanding ) {
				return $a_outstanding ? -1 : 1;
			}
			return strcmp( $b['created_at'], $a['created_at'] );
		} );

		return $this->success( $ledger );
	}

	/**
	 * Resolves each boon's two connections into owed_by/owed_to character summaries.
	 *
	 * @param int[] $boon_ids
	 * @return array<int,array{owed_by: array, owed_to: array}> Keyed by boon id.
	 */
	private function parties_by_boon( array $boon_ids ): array {
		if ( $boon_ids === [] ) {
			return [];
		}

		global $wpdb;
		$connections = $wpdb->get_results( $wpdb->prepare(
			'SELECT source_id, target_id, label FROM ' . \BeyondElysium\Database\Manager::table( 'connections' ) . "
			 WHERE source_type = 'world_object' AND target_type = 'character' AND target_id IS NOT NULL
			   AND label IN (%s, %s) AND source_id IN (" . implode( ',', array_fill( 0, count( $boon_ids ), '%d' ) ) . ')
			 ORDER BY created_at DESC',
			array_merge( [ self::OWED_BY_LABEL, self::OWED_TO_LABEL ], $boon_ids )
		) ) ?: [];

		$character_ids = array_values( array_unique( array_map( static fn( $row ) => (int) $row->target_id, $connections ) ) );
		$names         = [];
		if ( $character_ids !== [] ) {
			$rows = $wpdb->get_results( $wpdb->prepare(
				'SELECT id, name FROM ' . \BeyondElysium\Database\Manager::table( 'characters' ) . ' WHERE id IN (' . implode( ',', array_fill( 0, count( $character_ids ), '%d' ) ) . ')',
				$character_ids
			) ) ?: [];
			foreach ( $rows as $row ) {
				$names[ (int) $row->id ] = $row;
			}
		}

		$sides = [];
		foreach ( $connections as $connection ) {
			$character = $names[ (int) $connection->target_id ] ?? null;
			if ( $character !== null ) {
				// A number, as the ledger compares it with the character it's scoped to.
				$sides[ (int) $connection->source_id ][ $connection->label ] = [ 'id' => (int) $character->id, 'name' => $character->name ];
			}
		}

		$parties = [];
		foreach ( $sides as $boon_id => $side ) {
			if ( isset( $side[ self::OWED_BY_LABEL ], $side[ self::OWED_TO_LABEL ] ) ) {
				$parties[ $boon_id ] = [ 'owed_by' => $side[ self::OWED_BY_LABEL ], 'owed_to' => $side[ self::OWED_TO_LABEL ] ];
			}
		}
		return $parties;
	}

	/**
	 * Creates a boon.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function create_item( $request ) {
		$game = $this->resolve_game( $request['game_slug'] );
		if ( is_wp_error( $game ) ) {
			return $game;
		}

		$owed_by_id = (int) $request->get_param( 'owed_by_character_id' );
		$owed_to_id = (int) $request->get_param( 'owed_to_character_id' );
		$boon_level = (string) $request->get_param( 'boon_level' );

		if ( ! $owed_by_id || ! $owed_to_id ) {
			return $this->error( 'invalid_param', __( 'owed_by_character_id and owed_to_character_id are required.', 'beyond-elysium' ), 400 );
		}
		if ( $owed_by_id === $owed_to_id ) {
			return $this->error( 'invalid_param', __( 'A boon cannot be owed to oneself.', 'beyond-elysium' ), 400 );
		}
		if ( empty( $boon_level ) ) {
			return $this->error( 'invalid_param', __( 'boon_level is required.', 'beyond-elysium' ), 400 );
		}

		$debtor  = Character::find( $owed_by_id );
		$creditor = Character::find( $owed_to_id );
		if ( ! $debtor || $debtor->owner_slug !== $request['game_slug'] ) {
			return $this->error( 'invalid_param', __( 'owed_by_character_id does not exist in this game.', 'beyond-elysium' ), 400 );
		}
		if ( ! $creditor || $creditor->owner_slug !== $request['game_slug'] ) {
			return $this->error( 'invalid_param', __( 'owed_to_character_id does not exist in this game.', 'beyond-elysium' ), 400 );
		}

		$savepoint = Transaction::begin( 'be_boon_create' );

		$boon_id = World_Object::create( [
			'game_id'     => (int) $game->id,
			'object_type' => 'boon',
			'name'        => "Boon: {$debtor->name} owes {$creditor->name}",
			'rarity'      => null,
			'properties'  => [
				'boon_level' => $boon_level,
				'boon_date'  => $request->get_param( 'boon_date' ) ?: current_time( 'Y-m-d' ),
				'terms'      => $request->get_param( 'terms' ) ? wp_kses_post( $request->get_param( 'terms' ) ) : '',
				'status'     => 'outstanding',
			],
			'created_by'  => get_current_user_id(),
		] );

		$first  = $boon_id ? Connection::create( [
			'game_id' => (int) $game->id, 'source_type' => 'world_object', 'source_id' => $boon_id,
			'target_type' => 'character', 'target_id' => $owed_by_id, 'label' => self::OWED_BY_LABEL,
			'created_by' => get_current_user_id(),
		] ) : false;
		$second = $first ? Connection::create( [
			'game_id' => (int) $game->id, 'source_type' => 'world_object', 'source_id' => $boon_id,
			'target_type' => 'character', 'target_id' => $owed_to_id, 'label' => self::OWED_TO_LABEL,
			'created_by' => get_current_user_id(),
		] ) : false;

		if ( ! $boon_id || ! $first || ! $second ) {
			Transaction::rollback( $savepoint );
			return $this->error( 'create_failed', __( 'Failed to create this boon.', 'beyond-elysium' ), 500 );
		}

		Transaction::commit( $savepoint );

		return $this->success( array_merge(
			(array) World_Object::find( (int) $boon_id ),
			[ 'owed_by' => [ 'id' => (int) $debtor->id, 'name' => $debtor->name ], 'owed_to' => [ 'id' => (int) $creditor->id, 'name' => $creditor->name ] ]
		), 201 );
	}

	/**
	 * Marks a boon repaid.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function repay( $request ) {
		$game = $this->resolve_game( $request['game_slug'] );
		if ( is_wp_error( $game ) ) {
			return $game;
		}

		$boon = World_Object::find( (int) $request['id'] );
		if ( ! $boon || (int) $boon->game_id !== (int) $game->id || $boon->object_type !== 'boon' ) {
			return $this->error( 'not_found', __( 'Boon not found in this game.', 'beyond-elysium' ), 404 );
		}

		$properties                = (array) $boon->properties;
		$properties['status']      = 'repaid';
		$properties['repaid_date'] = current_time( 'Y-m-d' );

		$note = $request->get_param( 'repaid_note' );
		if ( $note !== null && $note !== '' ) {
			$properties['repaid_note'] = sanitize_textarea_field( (string) $note );
		}

		if ( ! World_Object::update( (int) $boon->id, [ 'properties' => $properties ] ) ) {
			return $this->error( 'save_failed', __( 'Failed to mark this boon repaid.', 'beyond-elysium' ), 500 );
		}
		return $this->success( World_Object::find( (int) $boon->id ) );
	}

	/**
	 * Resolves a game by its slug.
	 *
	 * @param string $game_slug
	 * @return object|\WP_Error
	 */
	protected function resolve_game( string $game_slug ) {
		$game = Game::find_by_slug( $game_slug );
		if ( ! $game ) {
			return $this->error( 'game_not_found', __( 'Game not found.', 'beyond-elysium' ), 404 );
		}
		return $game;
	}
}
