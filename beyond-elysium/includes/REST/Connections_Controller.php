<?php

namespace BeyondElysium\REST;

use BeyondElysium\Core\Authorization;
use BeyondElysium\Database\Manager;
use BeyondElysium\Models\Character;
use BeyondElysium\Models\Connection;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Item_Event;
use BeyondElysium\Models\Plot;
use BeyondElysium\Models\World_Object;
use BeyondElysium\Services\St_Visibility;

defined( 'ABSPATH' ) || exit;

/**
 * REST controller for connections between entities.
 */
class Connections_Controller extends Base_Controller {

	protected $rest_base = 'connections';

	/**
	 * Registers the game-scoped connection routes.
	 */
	public function register_routes(): void {
		register_rest_route( $this->namespace, '/(?P<game_slug>[a-z0-9\-]+)/connections', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_items' ],
				// Every link in the chronicle.
				'permission_callback' => $this->permission_any( [ 'be_manage_connections', 'be_manage_plots' ] ),
			],
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'create_item' ],
				'permission_callback' => $this->permission( 'be_manage_connections' ),
			],
		] );

		register_rest_route( $this->namespace, '/(?P<game_slug>[a-z0-9\-]+)/connections/(?P<id>\d+)', [
			[
				'methods'             => 'PUT',
				'callback'            => [ $this, 'update_item' ],
				'permission_callback' => $this->permission( 'be_manage_connections' ),
			],
			[
				'methods'             => 'DELETE',
				'callback'            => [ $this, 'delete_item' ],
				'permission_callback' => $this->permission( 'be_manage_connections' ),
			],
		] );
	}

	/**
	 * Lists connections for a game.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_items( $request ) {
		$game = $this->resolve_game( $request['game_slug'] );
		if ( is_wp_error( $game ) ) {
			return $game;
		}

		$entity_type = $request->get_param( 'entity_type' );
		$entity_id   = $request->get_param( 'entity_id' );

		$can_manage = Authorization::check_request( 'be_manage_connections', $request );

		if ( $entity_type && $entity_id ) {
			$connections = array_values( array_filter(
				Connection::for_entity( (string) $entity_type, (int) $entity_id ),
				static function ( $connection ) use ( $game ) {
					return (int) $connection->game_id === (int) $game->id;
				}
			) );
			foreach ( $connections as $connection ) {
				St_Visibility::filter_connection( $connection, $game, $can_manage );
			}
			return $this->success( $connections );
		}

		$args = [];
		if ( $request->get_param( 'source_type' ) ) {
			$args['source_type'] = $request->get_param( 'source_type' );
		}
		if ( $request->get_param( 'target_type' ) ) {
			$args['target_type'] = $request->get_param( 'target_type' );
		}

		$connections = Connection::for_game( (int) $game->id, $args );

		$source_id = $request->get_param( 'source_id' );
		if ( $source_id !== null ) {
			$connections = array_values( array_filter( $connections, static function ( $connection ) use ( $source_id ) {
				return (int) $connection->source_id === (int) $source_id;
			} ) );
		}
		$target_id = $request->get_param( 'target_id' );
		if ( $target_id !== null ) {
			$connections = array_values( array_filter( $connections, static function ( $connection ) use ( $target_id ) {
				return (int) $connection->target_id === (int) $target_id;
			} ) );
		}

		foreach ( $connections as $connection ) {
			St_Visibility::filter_connection( $connection, $game, $can_manage );
		}

		return $this->success( $connections );
	}

	/**
	 * Creates a connection.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function create_item( $request ) {
		$game = $this->resolve_game( $request['game_slug'] );
		if ( is_wp_error( $game ) ) {
			return $game;
		}

		$source_type = (string) $request->get_param( 'source_type' );
		$source_id   = (int) $request->get_param( 'source_id' );
		$target_type = (string) $request->get_param( 'target_type' );
		$target_id   = $request->get_param( 'target_id' );

		if ( ! in_array( $source_type, Connection::valid_entity_types(), true )
			|| ! in_array( $target_type, Connection::valid_entity_types(), true ) ) {
			return $this->error( 'invalid_param', sprintf( __( 'source_type and target_type must be one of: %s.', 'beyond-elysium' ), implode( ', ', Connection::valid_entity_types() ) ), 400 );
		}

		if ( ! $this->entity_exists_in_game( $source_type, $source_id, (int) $game->id ) ) {
			return $this->error( 'invalid_param', __( 'source entity does not exist in this game.', 'beyond-elysium' ), 400 );
		}
		if ( $target_type !== 'tag' ) {
			if ( empty( $target_id ) || ! $this->entity_exists_in_game( $target_type, (int) $target_id, (int) $game->id ) ) {
				return $this->error( 'invalid_param', __( 'target entity does not exist in this game.', 'beyond-elysium' ), 400 );
			}
		}

		$id = Connection::create( [
			'game_id'     => (int) $game->id,
			'source_type' => $source_type,
			'source_id'   => $source_id,
			'target_type' => $target_type,
			'target_id'   => $target_type === 'tag' ? null : (int) $target_id,
			'label'       => $request->get_param( 'label' ) ? sanitize_text_field( $request->get_param( 'label' ) ) : null,
			'notes'       => $request->get_param( 'notes' ) ? sanitize_textarea_field( $request->get_param( 'notes' ) ) : null,
			'created_by'  => get_current_user_id(),
		] );

		if ( ! $id ) {
			return $this->error( 'create_failed', __( 'Failed to create connection.', 'beyond-elysium' ), 500 );
		}

		$this->record_connection_item_event(
			$source_type,
			$source_id,
			$target_type,
			$target_type === 'tag' ? null : (int) $target_id,
			'given',
			(int) $game->id
		);

		return $this->success( Connection::find( (int) $id ), 201 );
	}

	/**
	 * Updates a connection's label and/or notes.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function update_item( $request ) {
		$connection = $this->resolve_connection( (int) $request['id'], $request['game_slug'] );
		if ( is_wp_error( $connection ) ) {
			return $connection;
		}

		// Both fields are sanitized here; neither has a format to further validate.
		$data = [];
		$label = $request->get_param( 'label' );
		if ( $label !== null ) {
			$data['label'] = sanitize_text_field( $label );
		}
		$notes = $request->get_param( 'notes' );
		if ( $notes !== null ) {
			$data['notes'] = sanitize_textarea_field( $notes );
		}

		Connection::update( (int) $connection->id, $data );
		return $this->success( Connection::find( (int) $connection->id ) );
	}

	/**
	 * Deletes a connection.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function delete_item( $request ) {
		$connection = $this->resolve_connection( (int) $request['id'], $request['game_slug'] );
		if ( is_wp_error( $connection ) ) {
			return $connection;
		}

		Connection::delete( (int) $connection->id );

		$this->record_connection_item_event(
			$connection->source_type,
			(int) $connection->source_id,
			$connection->target_type,
			$connection->target_id !== null ? (int) $connection->target_id : null,
			'taken',
			(int) $connection->game_id
		);

		return $this->success( null, 204 );
	}

	/**
	 * Writes a `given`/`taken` item event when a connection just added or removed links a character to an item
	 * specifically.
	 *
	 * @param string   $source_type
	 * @param int      $source_id
	 * @param string   $target_type
	 * @param int|null $target_id
	 * @param string   $event 'given' or 'taken'.
	 * @param int      $game_id
	 * @return void
	 */
	private function record_connection_item_event(
		string $source_type,
		int $source_id,
		string $target_type,
		?int $target_id,
		string $event,
		int $game_id
	): void {
		if ( $source_type === 'character' && $target_type === 'world_object' ) {
			$character_id    = $source_id;
			$world_object_id = $target_id;
		} elseif ( $source_type === 'world_object' && $target_type === 'character' ) {
			$character_id    = $target_id;
			$world_object_id = $source_id;
		} else {
			return;
		}
		if ( $world_object_id === null ) {
			return;
		}

		$object = World_Object::find( $world_object_id );
		if ( ! $object || $object->object_type !== 'item' ) {
			return;
		}

		Item_Event::record( [
			'game_id'         => $game_id,
			'world_object_id' => $world_object_id,
			'event'           => $event,
			'character_id'    => $character_id,
			'recorded_by'     => get_current_user_id(),
		] );
	}

	/**
	 * Checks whether an entity of the given type and ID exists and belongs to this game.
	 *
	 * @param string $type
	 * @param int    $id
	 * @param int    $game_id
	 * @return bool
	 */
	private function entity_exists_in_game( string $type, int $id, int $game_id ): bool {
		switch ( $type ) {
			case 'character':
				$game      = Game::find( $game_id );
				$character = Character::find( $id );
				return $game && $character && $character->owner_slug === $game->slug;

			case 'plot':
				$plot = Plot::find( $id );
				return $plot && (int) $plot->game_id === $game_id;

			case 'world_object':
				global $wpdb;
				$table = Manager::table( 'world_objects' );
				$found = $wpdb->get_var( $wpdb->prepare(
					"SELECT id FROM {$table} WHERE id = %d AND game_id = %d",
					$id,
					$game_id
				) );
				return (bool) $found;

			default:
				return false;
		}
	}

	/**
	 * Resolves a connection by ID, verifying it belongs to the game named in the URL.
	 *
	 * @param int    $id
	 * @param string $game_slug
	 * @return object|\WP_Error
	 */
	private function resolve_connection( int $id, string $game_slug ) {
		$game = $this->resolve_game( $game_slug );
		if ( is_wp_error( $game ) ) {
			return $game;
		}

		$connection = Connection::find( $id );
		if ( ! $connection || (int) $connection->game_id !== (int) $game->id ) {
			return $this->error( 'not_found', __( 'Connection not found in this game.', 'beyond-elysium' ), 404 );
		}

		return $connection;
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
