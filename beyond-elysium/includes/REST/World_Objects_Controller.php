<?php

namespace BeyondElysium\REST;

use BeyondElysium\Models\Character;
use BeyondElysium\Models\Connection;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\World_Object;

defined( 'ABSPATH' ) || exit;

/**
 * REST controller for world objects: items, locations, and rotes that exist
 * independently of any character. Supports listing with schema-driven
 * property filters, retrieving a single object with its connected
 * characters, and creating, updating, and deleting objects within a game.
 */
class World_Objects_Controller extends Base_Controller {

	protected $rest_base = 'world-objects';

	/**
	 * Registers the REST routes for the world object collection and for
	 * a single world object by id, both scoped to a game slug. Listing
	 * and retrieval require be_view_characters; writes require
	 * be_manage_world_objects.
	 */
	public function register_routes(): void {
		register_rest_route( $this->namespace, '/(?P<game_slug>[a-z0-9\-]+)/world-objects', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_items' ],
				'permission_callback' => $this->permission( 'be_view_characters' ),
			],
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'create_item' ],
				'permission_callback' => $this->permission( 'be_manage_world_objects' ),
			],
		] );

		register_rest_route( $this->namespace, '/(?P<game_slug>[a-z0-9\-]+)/world-objects/(?P<id>\d+)', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_item' ],
				'permission_callback' => $this->permission( 'be_view_characters' ),
			],
			[
				'methods'             => 'PUT',
				'callback'            => [ $this, 'update_item' ],
				'permission_callback' => $this->permission( 'be_manage_world_objects' ),
			],
			[
				'methods'             => 'DELETE',
				'callback'            => [ $this, 'delete_item' ],
				'permission_callback' => $this->permission( 'be_manage_world_objects' ),
			],
		] );
	}

	/**
	 * Returns a paginated list of world objects for a game. object_type,
	 * rarity, and search are indexed column filters; any other query
	 * parameter matching a schema field (optionally suffixed _min/_max)
	 * filters on the object's properties, evaluated after the column
	 * query.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_items( $request ) {
		$game = $this->resolve_game( $request['game_slug'] );
		if ( is_wp_error( $game ) ) {
			return $game;
		}

		$pagination = $this->get_pagination( $request );
		$args       = [
			'object_type' => $request->get_param( 'object_type' ),
			'rarity'      => $request->get_param( 'rarity' ),
			'search'      => $request->get_param( 'search' ),
			'orderby'     => $request->get_param( 'orderby' ) ?: 'name',
			'order'       => $request->get_param( 'order' ) ?: 'ASC',
		];

		$items = World_Object::for_game( (int) $game->id, $args );
		$items = $this->apply_property_filters( $items, $request, (string) ( $args['object_type'] ?? '' ) );

		$total    = count( $items );
		$per_page = $pagination['per_page'];
		$offset   = $pagination['offset'];
		$paged    = array_slice( $items, $offset, $per_page );

		$response = $this->success( $paged );
		return $this->paginate( $response, $total, $per_page, $pagination['page'] );
	}

	/**
	 * Filters a list of world objects by any request query parameter that
	 * matches a property key defined in the object_type's schema,
	 * honoring a _min/_max suffix for numeric range filtering. Returns
	 * the items unchanged when object_type is empty.
	 *
	 * @param object[]          $items
	 * @param \WP_REST_Request  $request
	 * @param string            $object_type
	 * @return object[]
	 */
	private function apply_property_filters( array $items, $request, string $object_type ): array {
		if ( $object_type === '' ) {
			return $items;
		}
		$schema = World_Object::schemas()[ $object_type ] ?? [];
		$params = $request->get_params();

		foreach ( $params as $param => $value ) {
			if ( $value === null || $value === '' ) {
				continue;
			}

			$key      = $param;
			$operator = 'eq';
			if ( str_ends_with( $param, '_min' ) ) {
				$key      = substr( $param, 0, -4 );
				$operator = 'min';
			} elseif ( str_ends_with( $param, '_max' ) ) {
				$key      = substr( $param, 0, -4 );
				$operator = 'max';
			}

			if ( ! isset( $schema[ $key ] ) ) {
				continue;
			}

			$items = array_values( array_filter( $items, static function ( $item ) use ( $key, $operator, $value, $schema ) {
				$actual = $item->properties[ $key ] ?? null;
				if ( $actual === null ) {
					return false;
				}
				if ( $schema[ $key ] === 'int' ) {
					return $operator === 'min' ? (int) $actual >= (int) $value
						: ( $operator === 'max' ? (int) $actual <= (int) $value : (int) $actual === (int) $value );
				}
				return strcasecmp( (string) $actual, (string) $value ) === 0;
			} ) );
		}

		return $items;
	}

	/**
	 * Returns a single world object with its connected characters resolved
	 * to id, name, and connection label. Hides an NPC connection from a
	 * viewer who cannot manage characters, so the object catalog does not
	 * reveal ownership the viewer could not otherwise see.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_item( $request ) {
		$game = $this->resolve_game( $request['game_slug'] );
		if ( is_wp_error( $game ) ) {
			return $game;
		}

		$object = World_Object::find( (int) $request['id'] );
		if ( ! $object || (int) $object->game_id !== (int) $game->id ) {
			return $this->error( 'not_found', __( 'World object not found in this game.', 'beyond-elysium' ), 404 );
		}

		$connections = Connection::for_entity( 'world_object', (int) $object->id );
		$can_manage  = current_user_can( 'be_manage_characters' );

		$characters = [];
		foreach ( $connections as $connection ) {
			$character_id = $connection->source_type === 'character' ? $connection->source_id : $connection->target_id;
			if ( $character_id === null ) {
				continue;
			}
			$character = Character::find( (int) $character_id );
			// An NPC connection is hidden from a viewer who cannot manage characters.
			if ( $character && ( ! $character->is_npc || $can_manage ) ) {
				$characters[] = [ 'id' => $character->id, 'name' => $character->name, 'label' => $connection->label ];
			}
		}

		$object->connected_characters = $characters;
		return $this->success( $object );
	}

	/**
	 * Creates a new world object from a required name and object_type,
	 * plus an optional description, rarity, cost, limitations, and a
	 * properties object validated against the object_type's schema.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function create_item( $request ) {
		$game = $this->resolve_game( $request['game_slug'] );
		if ( is_wp_error( $game ) ) {
			return $game;
		}

		$name = $request->get_param( 'name' );
		if ( empty( $name ) ) {
			return $this->error( 'invalid_param', __( 'Missing required field: name.', 'beyond-elysium' ), 400 );
		}

		$object_type = (string) $request->get_param( 'object_type' );
		if ( ! in_array( $object_type, World_Object::valid_types(), true ) ) {
			return $this->error( 'invalid_param', sprintf( __( 'object_type must be one of: %s.', 'beyond-elysium' ), implode( ', ', World_Object::valid_types() ) ), 400 );
		}

		$properties = (array) ( $request->get_param( 'properties' ) ?: [] );
		$error      = World_Object::validate_properties( $object_type, $properties );
		if ( $error !== null ) {
			return $this->error( 'invalid_property', $error, 400 );
		}

		$id = World_Object::create( [
			'game_id'     => (int) $game->id,
			'object_type' => $object_type,
			'name'        => sanitize_text_field( $name ),
			'description' => $request->get_param( 'description' ) ? wp_kses_post( $request->get_param( 'description' ) ) : null,
			'rarity'      => $request->get_param( 'rarity' ),
			'cost'        => $request->get_param( 'cost' ),
			'limitations' => $request->get_param( 'limitations' ) ? wp_kses_post( $request->get_param( 'limitations' ) ) : null,
			'properties'  => $properties,
			'created_by'  => get_current_user_id(),
		] );

		if ( ! $id ) {
			return $this->error( 'create_failed', __( 'Failed to create world object.', 'beyond-elysium' ), 500 );
		}

		return $this->success( World_Object::find( $id ), 201 );
	}

	/**
	 * Updates an existing world object with any recognized fields present
	 * in the request. Re-validates properties against the object_type's
	 * schema when included, and returns the updated object.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function update_item( $request ) {
		$object = $this->resolve_object( (int) $request['id'], $request['game_slug'] );
		if ( is_wp_error( $object ) ) {
			return $object;
		}

		$data = [];
		foreach ( [ 'name', 'description', 'rarity', 'cost', 'limitations' ] as $field ) {
			$value = $request->get_param( $field );
			if ( $value !== null ) {
				$data[ $field ] = $value;
			}
		}
		if ( $request->get_param( 'properties' ) !== null ) {
			$properties = (array) $request->get_param( 'properties' );
			$error      = World_Object::validate_properties( $object->object_type, $properties );
			if ( $error !== null ) {
				return $this->error( 'invalid_property', $error, 400 );
			}
			$data['properties'] = $properties;
		}

		if ( ! World_Object::update( (int) $object->id, $data ) && ! empty( $data ) ) {
			return $this->error( 'update_failed', __( 'Failed to update world object.', 'beyond-elysium' ), 500 );
		}

		return $this->success( World_Object::find( (int) $object->id ) );
	}

	/**
	 * Deletes a world object after confirming it exists and belongs to
	 * the requested game. Also removes any connections referencing the
	 * object, so no connection is left pointing at a deleted object.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function delete_item( $request ) {
		$object = $this->resolve_object( (int) $request['id'], $request['game_slug'] );
		if ( is_wp_error( $object ) ) {
			return $object;
		}

		World_Object::delete( (int) $object->id );
		return $this->success( null, 204 );
	}

	/**
	 * Looks up a world object by id and confirms it belongs to the game
	 * identified by the given slug, returning a WP_Error with a 404
	 * status when the game or the object cannot be found.
	 *
	 * @param int    $id
	 * @param string $game_slug
	 * @return object|\WP_Error
	 */
	private function resolve_object( int $id, string $game_slug ) {
		$game = $this->resolve_game( $game_slug );
		if ( is_wp_error( $game ) ) {
			return $game;
		}
		$object = World_Object::find( $id );
		if ( ! $object || (int) $object->game_id !== (int) $game->id ) {
			return $this->error( 'not_found', __( 'World object not found in this game.', 'beyond-elysium' ), 404 );
		}
		return $object;
	}

	/**
	 * Looks up a game by its slug and returns the game object, or a WP_Error
	 * with a 404 status when no game matches. Used by route callbacks to
	 * resolve the game_slug URL parameter before performing further work.
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
