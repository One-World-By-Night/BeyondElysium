<?php

namespace BeyondElysium\REST;

use BeyondElysium\Database\Transaction;
use BeyondElysium\Models\Attachment;
use BeyondElysium\Models\Character;
use BeyondElysium\Models\Connection;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Item_Attestation;
use BeyondElysium\Models\Item_Event;
use BeyondElysium\Models\World_Object;
use BeyondElysium\Services\Attachment_Storage;
use BeyondElysium\Services\Audience;
use BeyondElysium\Services\Query_Engine;
use BeyondElysium\Services\St_Visibility;

defined( 'ABSPATH' ) || exit;

/**
 * REST controller for world objects: items, locations, and rotes that exist independently of any character.
 */
class World_Objects_Controller extends Base_Controller {

	protected $rest_base = 'world-objects';

	/**
	 * Registers the REST routes for the world object collection and for a single world object by id, both scoped to a
	 * game slug.
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

		register_rest_route( $this->namespace, '/(?P<game_slug>[a-z0-9\-]+)/world-objects/(?P<id>\d+)/copy-for-character', [
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'copy_for_character' ],
				'permission_callback' => $this->permission( 'be_manage_world_objects' ),
			],
		] );

		register_rest_route( $this->namespace, '/(?P<game_slug>[a-z0-9\-]+)/world-objects/(?P<id>\d+)/use', [
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'use_item' ],
				'permission_callback' => $this->permission_any( [ 'be_manage_world_objects', 'be_edit_own_characters' ] ),
			],
		] );

		register_rest_route( $this->namespace, '/(?P<game_slug>[a-z0-9\-]+)/world-objects/(?P<id>\d+)/transfer', [
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'transfer_item' ],
				'permission_callback' => $this->permission( 'be_manage_world_objects' ),
			],
		] );

		register_rest_route( $this->namespace, '/(?P<game_slug>[a-z0-9\-]+)/world-objects/(?P<id>\d+)/events', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_events' ],
				'permission_callback' => $this->permission( 'be_manage_world_objects' ),
			],
		] );

		register_rest_route( $this->namespace, '/(?P<game_slug>[a-z0-9\-]+)/world-objects/(?P<id>\d+)/revoke-cards', [
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'revoke_cards' ],
				'permission_callback' => $this->permission( 'be_manage_world_objects' ),
			],
		] );
	}

	/**
	 * Returns a paginated list of world objects for a game. object_type, rarity, and search are indexed column filters.
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
		$can_manage = \BeyondElysium\Core\Authorization::can( 'be_manage_world_objects' );
		$search     = (string) $request->get_param( 'search' );
		$args       = [
			'object_type' => $request->get_param( 'object_type' ),
			'rarity'      => $request->get_param( 'rarity' ),
			// For anyone but a Storyteller, search and property filters run on the redacted text below.
			'search'      => $can_manage ? $search : '',
			'orderby'     => $request->get_param( 'orderby' ) ?: 'name',
			'order'       => $request->get_param( 'order' ) ?: 'ASC',
			'copies'      => in_array( $request->get_param( 'copies' ), [ 'only', 'include' ], true ) ? $request->get_param( 'copies' ) : 'exclude',
		];

		$items = World_Object::for_game( (int) $game->id, $args );
		if ( ! $can_manage ) {
			$items = Audience::filter_world_objects( $items, get_current_user_id(), $request['game_slug'], false );
		}
		foreach ( $items as $item ) {
			St_Visibility::filter_world_object( $item, $game, $can_manage );
			if ( $item->object_type === 'item' ) {
				$item->used_up = World_Object::is_used_up( $item );
				$item->expired = World_Object::is_expired( $item );
			}
		}
		if ( ! $can_manage && $search !== '' ) {
			$items = array_values( array_filter( $items, static function ( $item ) use ( $search ) {
				return stripos( (string) $item->name, $search ) !== false || stripos( (string) ( $item->description ?? '' ), $search ) !== false;
			} ) );
		}
		$items = $this->apply_property_filters( $items, $request, (string) ( $args['object_type'] ?? '' ) );

		$total    = count( $items );
		$per_page = $pagination['per_page'];
		$offset   = $pagination['offset'];
		$paged    = array_slice( $items, $offset, $per_page );

		$response = $this->success( $paged );
		return $this->paginate( $response, $total, $per_page, $pagination['page'] );
	}

	/**
	 * Filters a list of world objects by any request query parameter that matches a property key defined in the
	 * object_type's schema, honoring a _min/_max suffix for numeric range filtering.
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
				// A list - an item's abilities, a rote's spheres.
				if ( $schema[ $key ] === 'trait_list' ) {
					foreach ( is_array( $actual ) && $operator === 'eq' ? $actual : [] as $entry ) {
						if ( strcasecmp( (string) ( ( (array) $entry )['name'] ?? '' ), (string) $value ) === 0 ) {
							return true;
						}
					}
					return false;
				}
				return is_scalar( $actual ) && strcasecmp( (string) $actual, (string) $value ) === 0;
			} ) );
		}

		return $items;
	}

	/**
	 * Returns a single world object with its connected characters resolved to id, name, and connection label.
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

		$can_manage_objects = \BeyondElysium\Core\Authorization::can( 'be_manage_world_objects' );

		// A rote or boon has no audience concept at all.
		if ( ! $can_manage_objects && in_array( $object->object_type, [ 'item', 'location' ], true )
			&& ! Audience::can_see( $object, $object->object_type, get_current_user_id(), $request['game_slug'], false ) ) {
			return $this->error( 'not_found', __( 'World object not found in this game.', 'beyond-elysium' ), 404 );
		}

		St_Visibility::filter_world_object( $object, $game, $can_manage_objects );

		$connections = Connection::for_entity( 'world_object', (int) $object->id );
		$can_manage  = \BeyondElysium\Core\Authorization::can( 'be_manage_characters' );

		$characters = [];
		foreach ( $connections as $connection ) {
			$character_id = $connection->source_type === 'character' ? $connection->source_id : $connection->target_id;
			if ( $character_id === null ) {
				continue;
			}
			$character = Character::find( (int) $character_id );
			if ( $character && ( ! $character->is_npc || $can_manage ) ) {
				$characters[] = [ 'id' => $character->id, 'name' => $character->name, 'label' => $connection->label ];
			}
		}

		$object->connected_characters = $characters;
		$based_on = null;
		if ( ! empty( $object->based_on_id ) ) {
			$source   = World_Object::find( (int) $object->based_on_id );
			$based_on = $source ? [ 'id' => (int) $source->id, 'name' => $source->name ] : null;
		}
		$object->based_on = $based_on;
		if ( $object->object_type === 'item' ) {
			$object->used_up = World_Object::is_used_up( $object );
			$object->expired = World_Object::is_expired( $object );
		}
		// A rote or boon has no attachment concept (applies to item/location only).
		if ( in_array( $object->object_type, [ 'item', 'location' ], true ) ) {
			$object->attachments = array_map( [ Attachment::class, 'public_shape' ], Attachment::for_entity( $object->object_type, (int) $object->id ) );
		}
		// "Inside of": the breadcrumb reads ancestors nearest-first; the nested location list reads children.
		if ( $object->object_type === 'location' ) {
			$object->ancestors = array_map( static fn( $a ) => [ 'id' => (int) $a->id, 'name' => $a->name ], World_Object::ancestors( (int) $object->id ) );
			$object->children  = array_map( static fn( $c ) => [ 'id' => (int) $c->id, 'name' => $c->name ], World_Object::children( (int) $object->id ) );
			$object->display = \BeyondElysium\Models\Location_Link::resolve_owner_and_where( $object );
		}
		return $this->success( $object );
	}

	/**
	 * Creates a new world object from a required name and object_type, plus an optional description, rarity, cost,
	 * limitations, and a properties object validated against the object_type's schema.
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
		if ( $object_type === 'boon' ) {
			return $this->boon_ledger_error();
		}

		$properties = (array) ( $request->get_param( 'properties' ) ?: [] );
		$error      = World_Object::validate_properties( $object_type, $properties );
		if ( $error !== null ) {
			return $this->error( 'invalid_property', $error, 400 );
		}

		$fields = $this->text_fields( $request );
		if ( is_wp_error( $fields ) ) {
			return $fields;
		}

		$audience = $this->resolve_audience( $request );
		if ( is_wp_error( $audience ) ) {
			return $audience;
		}

		$parent_id = $this->resolve_parent_id( $request, $game, $object_type, null );
		if ( is_wp_error( $parent_id ) ) {
			return $parent_id;
		}

		$id = World_Object::create( array_merge( [
			'game_id'     => (int) $game->id,
			'object_type' => $object_type,
			'name'        => $fields['name'],
			'description' => ( $fields['description'] ?? '' ) !== '' ? $fields['description'] : null,
			'rarity'      => $fields['rarity'] ?? null,
			'cost'        => $fields['cost'] ?? null,
			'limitations' => ( $fields['limitations'] ?? '' ) !== '' ? $fields['limitations'] : null,
			'properties'  => $properties,
			'parent_id'   => $parent_id,
			'created_by'  => get_current_user_id(),
		], $audience ) );

		if ( ! $id ) {
			return $this->error( 'create_failed', __( 'Failed to create world object.', 'beyond-elysium' ), 500 );
		}

		return $this->success( World_Object::find( $id ), 201 );
	}

	/**
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function copy_for_character( $request ) {
		$object = $this->resolve_object( (int) $request['id'], $request['game_slug'] );
		if ( is_wp_error( $object ) ) {
			return $object;
		}
		if ( $object->object_type !== 'item' ) {
			return $this->error( 'items_only', __( 'Only an item may be copied for a character.', 'beyond-elysium' ), 409 );
		}

		$character_id = (int) $request->get_param( 'character_id' );
		if ( ! $character_id ) {
			return $this->error( 'invalid_param', __( 'character_id is required.', 'beyond-elysium' ), 400 );
		}
		$character = Character::find( $character_id );
		if ( ! $character || $character->owner_slug !== $request['game_slug'] ) {
			return $this->error( 'invalid_param', __( 'character_id must be a real character in this game.', 'beyond-elysium' ), 400 );
		}

		$name = $request->get_param( 'name' );
		$name = $name !== null && trim( (string) $name ) !== '' ? sanitize_text_field( (string) $name ) : $object->name;
		if ( mb_strlen( $name ) > 255 ) {
			/* translators: %d: maximum number of characters */
			return $this->error( 'invalid_param', sprintf( __( 'Name can be at most %d characters.', 'beyond-elysium' ), 255 ), 400 );
		}

		$savepoint = Transaction::begin( 'be_item_copy' );

		$copy_id = World_Object::create( [
			'game_id'        => (int) $object->game_id,
			'object_type'    => 'item',
			'name'           => $name,
			'description'    => $object->description,
			'rarity'         => $object->rarity,
			'cost'           => $object->cost,
			'limitations'    => $object->limitations,
			'properties'     => $object->properties,
			'based_on_id'    => (int) $object->id,
			'audience'       => 'restricted',
			'audience_rules' => $object->audience_rules,
			'created_by'     => get_current_user_id(),
		] );

		$attachment_ok = true;
		$source_files  = Attachment::for_entity( 'item', (int) $object->id );
		if ( $copy_id && ! empty( $source_files ) ) {
			$duplicated = Attachment_Storage::duplicate( $source_files[0]->stored_name, $source_files[0]->original_name );
			$attachment_ok = ! is_wp_error( $duplicated ) && (bool) Attachment::create( array_merge( $duplicated, [
				'game_id'     => (int) $object->game_id,
				'entity_type' => 'item',
				'entity_id'   => $copy_id,
				'created_by'  => get_current_user_id(),
			] ) );
		}

		$connection_id = $copy_id ? Connection::create( [
			'game_id'     => (int) $object->game_id,
			'source_type' => 'character',
			'source_id'   => $character_id,
			'target_type' => 'world_object',
			'target_id'   => $copy_id,
			'label'       => 'holds',
			'created_by'  => get_current_user_id(),
		] ) : false;

		$event_id = $copy_id ? Item_Event::record( [
			'game_id'         => (int) $object->game_id,
			'world_object_id' => $copy_id,
			'event'           => 'copied',
			'character_id'    => $character_id,
			'recorded_by'     => get_current_user_id(),
		] ) : false;

		if ( ! $copy_id || ! $attachment_ok || ! $connection_id || ! $event_id ) {
			Transaction::rollback( $savepoint );
			return $this->error( 'create_failed', __( 'Failed to copy this item.', 'beyond-elysium' ), 500 );
		}

		Transaction::commit( $savepoint );

		return $this->success( World_Object::find( $copy_id ), 201 );
	}

	/**
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function use_item( $request ) {
		$object = $this->resolve_object( (int) $request['id'], $request['game_slug'] );
		if ( is_wp_error( $object ) ) {
			return $object;
		}
		if ( $object->object_type !== 'item' ) {
			return $this->error( 'items_only', __( 'Only an item may be used.', 'beyond-elysium' ), 409 );
		}

		$character_id = (int) $request->get_param( 'character_id' );
		if ( ! $character_id ) {
			return $this->error( 'invalid_param', __( 'character_id is required.', 'beyond-elysium' ), 400 );
		}
		$character = Character::find( $character_id );
		if ( ! $character || $character->owner_slug !== $request['game_slug'] ) {
			return $this->error( 'invalid_param', __( 'character_id must be a real character in this game.', 'beyond-elysium' ), 400 );
		}

		$can_manage = \BeyondElysium\Core\Authorization::can( 'be_manage_world_objects' );
		if ( ! $can_manage ) {
			if ( (int) $character->wp_user_id !== get_current_user_id() ) {
				return $this->error( 'ownership_denied', __( 'You may only use an item through your own character.', 'beyond-elysium' ), 403 );
			}
			if ( ! self::character_holds_item( $character_id, (int) $object->id ) ) {
				return $this->error( 'not_holder', __( 'That character does not hold this item.', 'beyond-elysium' ), 403 );
			}
		}

		$uses_max = $object->properties['uses_max'] ?? null;
		if ( $uses_max === null || $uses_max === '' ) {
			return $this->error( 'no_uses', __( 'This item has no uses to spend.', 'beyond-elysium' ), 400 );
		}

		$savepoint = Transaction::begin( 'be_item_use' );
		$locked    = World_Object::find_for_update( (int) $object->id );

		if ( ! $locked ) {
			Transaction::rollback( $savepoint );
			return $this->error( 'update_failed', __( 'Failed to record this use.', 'beyond-elysium' ), 500 );
		}
		$locked_uses_max = $locked->properties['uses_max'] ?? null;
		if ( $locked_uses_max === null || $locked_uses_max === '' ) {
			Transaction::rollback( $savepoint );
			return $this->error( 'no_uses', __( 'This item has no uses to spend.', 'beyond-elysium' ), 400 );
		}
		if ( World_Object::is_used_up( $locked ) ) {
			Transaction::rollback( $savepoint );
			return $this->error( 'used_up', __( 'This item has no uses left.', 'beyond-elysium' ), 409 );
		}
		if ( World_Object::is_expired( $locked ) ) {
			Transaction::rollback( $savepoint );
			return $this->error( 'expired', __( 'This item has expired.', 'beyond-elysium' ), 409 );
		}

		$properties               = $locked->properties;
		$properties['uses_left']  = max( 0, (int) ( $properties['uses_left'] ?? 0 ) - 1 );
		$updated                  = World_Object::update( (int) $object->id, [ 'properties' => $properties ] );

		$note     = $request->get_param( 'note' );
		$event_id = $updated ? Item_Event::record( [
			'game_id'         => (int) $object->game_id,
			'world_object_id' => (int) $object->id,
			'event'           => 'used',
			'character_id'    => $character_id,
			'note'            => $note !== null ? sanitize_textarea_field( (string) $note ) : null,
			'recorded_by'     => get_current_user_id(),
		] ) : false;

		if ( ! $updated || ! $event_id ) {
			Transaction::rollback( $savepoint );
			return $this->error( 'update_failed', __( 'Failed to record this use.', 'beyond-elysium' ), 500 );
		}

		Transaction::commit( $savepoint );

		return $this->success( World_Object::find( (int) $object->id ) );
	}

	/**
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function transfer_item( $request ) {
		$object = $this->resolve_object( (int) $request['id'], $request['game_slug'] );
		if ( is_wp_error( $object ) ) {
			return $object;
		}
		if ( $object->object_type !== 'item' ) {
			return $this->error( 'items_only', __( 'Only an item may be transferred this way.', 'beyond-elysium' ), 409 );
		}

		$how_values = [ 'given', 'traded', 'stolen', 'lost' ];
		$how        = (string) $request->get_param( 'how' );
		if ( ! in_array( $how, $how_values, true ) ) {
			return $this->error( 'invalid_param', sprintf( __( 'how must be one of: %s.', 'beyond-elysium' ), implode( ', ', $how_values ) ), 400 );
		}

		$to_character_id = null;
		if ( $how !== 'lost' ) {
			$raw = $request->get_param( 'to_character_id' );
			if ( empty( $raw ) ) {
				return $this->error( 'invalid_param', __( 'to_character_id is required unless how is lost.', 'beyond-elysium' ), 400 );
			}
			$to_character = Character::find( (int) $raw );
			if ( ! $to_character || $to_character->owner_slug !== $request['game_slug'] ) {
				return $this->error( 'invalid_param', __( 'to_character_id must be a real character in this game.', 'beyond-elysium' ), 400 );
			}
			$to_character_id = (int) $raw;
		}

		$note = $request->get_param( 'note' );
		$note = $note !== null ? sanitize_textarea_field( (string) $note ) : null;

		$savepoint = Transaction::begin( 'be_item_transfer' );

		$existing_holder_id = null;
		foreach ( Connection::for_entity( 'world_object', (int) $object->id ) as $connection ) {
			$character_side_id = self::character_side_of( $connection );
			if ( $character_side_id === null ) {
				continue;
			}
			if ( $existing_holder_id === null ) {
				$existing_holder_id = $character_side_id;
			}
			Connection::delete( (int) $connection->id );
		}

		$connection_ok = true;
		if ( $to_character_id !== null ) {
			$connection_ok = (bool) Connection::create( [
				'game_id'     => (int) $object->game_id,
				'source_type' => 'character',
				'source_id'   => $to_character_id,
				'target_type' => 'world_object',
				'target_id'   => (int) $object->id,
				'label'       => 'holds',
				'created_by'  => get_current_user_id(),
			] );
		}

		$event_id = Item_Event::record( [
			'game_id'           => (int) $object->game_id,
			'world_object_id'   => (int) $object->id,
			'event'             => $how,
			'character_id'      => $to_character_id,
			'from_character_id' => $existing_holder_id,
			'note'              => $note,
			'recorded_by'       => get_current_user_id(),
		] );

		if ( ! $connection_ok || ! $event_id ) {
			Transaction::rollback( $savepoint );
			return $this->error( 'transfer_failed', __( 'Failed to transfer this item.', 'beyond-elysium' ), 500 );
		}

		Transaction::commit( $savepoint );

		return $this->success( World_Object::find( (int) $object->id ) );
	}

	/**
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_events( $request ) {
		$object = $this->resolve_object( (int) $request['id'], $request['game_slug'] );
		if ( is_wp_error( $object ) ) {
			return $object;
		}
		if ( $object->object_type !== 'item' ) {
			return $this->error( 'items_only', __( 'Only an item keeps this kind of history.', 'beyond-elysium' ), 409 );
		}

		return $this->success( array_map( [ Item_Event::class, 'public_shape' ], Item_Event::for_object( (int) $object->id ) ) );
	}

	/**
	 * Revokes every unrevoked verification code issued for one item.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function revoke_cards( $request ) {
		$object = $this->resolve_object( (int) $request['id'], $request['game_slug'] );
		if ( is_wp_error( $object ) ) {
			return $object;
		}
		if ( $object->object_type !== 'item' ) {
			return $this->error( 'items_only', __( 'Only an item has verification codes to revoke.', 'beyond-elysium' ), 409 );
		}

		$revoked = Item_Attestation::revoke_for_object( (int) $object->id );
		return $this->success( [ 'revoked' => $revoked ] );
	}

	/**
	 * Whether a character has any connection to a world object at all.
	 *
	 * @param int $character_id
	 * @param int $world_object_id
	 * @return bool
	 */
	private static function character_holds_item( int $character_id, int $world_object_id ): bool {
		foreach ( Connection::for_entity( 'world_object', $world_object_id ) as $connection ) {
			if ( self::character_side_of( $connection ) === $character_id ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The character-side id of a connection touching a world object, whichever direction it was written in, or null when
	 * the other side isn't a character at all (a plot, a tag).
	 *
	 * @param object $connection A row from `Connection::for_entity()`.
	 * @return int|null
	 */
	private static function character_side_of( object $connection ): ?int {
		if ( $connection->source_type === 'character' ) {
			return (int) $connection->source_id;
		}
		if ( $connection->target_type === 'character' ) {
			return (int) $connection->target_id;
		}
		return null;
	}

	/**
	 * Validates a requested `audience`/`audience_rules` pair.
	 *
	 * @param \WP_REST_Request $request
	 * @return array{audience?:string,audience_rules?:?array}|\WP_Error
	 */
	private function resolve_audience( $request ) {
		$data = [];

		$audience = $request->get_param( 'audience' );
		if ( $audience !== null ) {
			if ( ! in_array( $audience, World_Object::AUDIENCE_VALUES, true ) ) {
				return $this->error( 'invalid_param', sprintf( __( 'audience must be one of: %s.', 'beyond-elysium' ), implode( ', ', World_Object::AUDIENCE_VALUES ) ), 400 );
			}
			$data['audience'] = $audience;
		}

		if ( $request->has_param( 'audience_rules' ) ) {
			$rules = $request->get_param( 'audience_rules' );
			if ( $rules !== null ) {
				if ( ! is_array( $rules ) || empty( $rules['conditions'] ) || ! is_array( $rules['conditions'] ) ) {
					return $this->error( 'invalid_param', __( 'audience_rules must include a conditions array.', 'beyond-elysium' ), 400 );
				}
				$problem = Query_Engine::validate_conditions( $rules['conditions'] );
				if ( $problem !== null ) {
					return $this->error( 'invalid_param', $problem['message'], 400 );
				}
			}
			$data['audience_rules'] = $rules;
		}

		return $data;
	}

	/**
	 * Validates a requested `parent_id` ("Inside of"): meaningless for anything but a location, must name a real
	 * location in this same game.
	 *
	 * @param \WP_REST_Request $request
	 * @param object            $game
	 * @param string            $object_type
	 * @param int|null          $self_id Null on create, since nothing to compare against yet.
	 * @return int|null|\WP_Error Null when no parent_id was sent at all.
	 */
	private function resolve_parent_id( $request, object $game, string $object_type, ?int $self_id ) {
		if ( ! $request->has_param( 'parent_id' ) ) {
			return null;
		}
		$raw = $request->get_param( 'parent_id' );
		if ( empty( $raw ) ) {
			return null;
		}

		$parent_id = (int) $raw;
		if ( $object_type !== 'location' ) {
			return $this->error( 'invalid_parent', __( 'Only a location may have a parent location.', 'beyond-elysium' ), 400 );
		}
		if ( $self_id !== null && $parent_id === $self_id ) {
			return $this->error( 'invalid_parent', __( 'A location cannot be inside itself.', 'beyond-elysium' ), 400 );
		}
		$parent = World_Object::find( $parent_id );
		if ( ! $parent || $parent->object_type !== 'location' || (int) $parent->game_id !== (int) $game->id ) {
			return $this->error( 'invalid_parent', __( 'parent_id must be a real location in this game.', 'beyond-elysium' ), 400 );
		}

		return $parent_id;
	}

	/**
	 * Updates an existing world object with any recognized fields present in the request.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function update_item( $request ) {
		$object = $this->resolve_object( (int) $request['id'], $request['game_slug'] );
		if ( is_wp_error( $object ) ) {
			return $object;
		}
		if ( $object->object_type === 'boon' ) {
			return $this->boon_ledger_error();
		}

		// Cleaned exactly as a create cleans them.
		$data = $this->text_fields( $request );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		if ( $request->get_param( 'properties' ) !== null ) {
			$properties = (array) $request->get_param( 'properties' );
			$error      = World_Object::validate_properties( $object->object_type, $properties );
			if ( $error !== null ) {
				return $this->error( 'invalid_property', $error, 400 );
			}
			$data['properties'] = $properties;
		}

		$audience = $this->resolve_audience( $request );
		if ( is_wp_error( $audience ) ) {
			return $audience;
		}
		$data = array_merge( $data, $audience );

		if ( $request->has_param( 'parent_id' ) ) {
			// The object's own game_id.
			$parent_id = $this->resolve_parent_id( $request, (object) [ 'id' => $object->game_id ], $object->object_type, (int) $object->id );
			if ( is_wp_error( $parent_id ) ) {
				return $parent_id;
			}
			$data['parent_id'] = $parent_id;
		}

		try {
			if ( ! World_Object::update( (int) $object->id, $data ) && ! empty( $data ) ) {
				return $this->error( 'update_failed', __( 'Failed to update world object.', 'beyond-elysium' ), 500 );
			}
		} catch ( \RuntimeException $e ) {
			return $this->error( 'invalid_parent', $e->getMessage(), 400 );
		}

		// A Storyteller editing uses or expiry, when one of the three keys changed.
		if ( $object->object_type === 'item' && array_key_exists( 'properties', $data ) ) {
			$watched      = [ 'uses_max', 'uses_left', 'expires_on' ];
			$new_properties = (array) $data['properties'];
			$before       = array_intersect_key( $object->properties, array_flip( $watched ) );
			$after        = array_intersect_key( $new_properties, array_flip( $watched ) );
			if ( $before != $after ) { // phpcs:ignore Universal.Operators.StrictComparisons
				Item_Event::record( [
					'game_id'         => (int) $object->game_id,
					'world_object_id' => (int) $object->id,
					'event'           => 'adjusted',
					'recorded_by'     => get_current_user_id(),
				] );
			}
		}

		return $this->success( World_Object::find( (int) $object->id ) );
	}

	/**
	 * Deletes a world object after confirming it exists and belongs to the requested game.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function delete_item( $request ) {
		$object = $this->resolve_object( (int) $request['id'], $request['game_slug'] );
		if ( is_wp_error( $object ) ) {
			return $object;
		}
		if ( $object->object_type === 'boon' ) {
			return $this->boon_ledger_error();
		}
		if ( $object->object_type === 'location' && World_Object::has_children( (int) $object->id ) ) {
			return $this->error( 'location_has_children', __( 'Move or delete this location\'s own children first.', 'beyond-elysium' ), 409 );
		}

		if ( in_array( $object->object_type, [ 'item', 'location' ], true ) ) {
			foreach ( Attachment::for_entity( $object->object_type, (int) $object->id ) as $attachment ) {
				Attachment_Storage::delete( $attachment->stored_name, $attachment->original_name );
			}
		}

		World_Object::delete( (int) $object->id );
		return $this->success( null, 204 );
	}

	/**
	 * The text fields a request sets, cleaned the same way for a create and an edit: name, rarity, and cost as plain text
	 * within their column lengths, description and limitations through `wp_kses_post()`.
	 *
	 * @param \WP_REST_Request $request
	 * @return array<string,string>|\WP_Error A 400 naming the first field over its length.
	 */
	private function text_fields( $request ) {
		$fields = [];
		foreach ( [ 'name' => 255, 'rarity' => 20, 'cost' => 100 ] as $field => $max ) {
			$value = $request->get_param( $field );
			if ( $value === null ) {
				continue;
			}
			$value = sanitize_text_field( (string) $value );
			if ( $field === 'name' && $value === '' ) {
				return $this->error( 'invalid_param', __( 'Missing required field: name.', 'beyond-elysium' ), 400 );
			}
			if ( mb_strlen( $value ) > $max ) {
				/* translators: 1: field name, 2: maximum number of characters */
				return $this->error( 'invalid_param', sprintf( __( '%1$s can be at most %2$d characters.', 'beyond-elysium' ), ucfirst( $field ), $max ), 400 );
			}
			$fields[ $field ] = $value;
		}
		foreach ( [ 'description', 'limitations' ] as $field ) {
			$value = $request->get_param( $field );
			if ( $value !== null ) {
				$fields[ $field ] = wp_kses_post( (string) $value );
			}
		}
		return $fields;
	}

	/**
	 * Refuses a boon on the generic routes: the Boon Ledger records, repays, and keeps boons.
	 *
	 * @return \WP_Error
	 */
	private function boon_ledger_error(): \WP_Error {
		return $this->error( 'use_boon_ledger', __( 'Boons are kept on the Boon Ledger and change only there.', 'beyond-elysium' ), 409 );
	}

	/**
	 * Looks up a world object by id and confirms it belongs to the game identified by the given slug, returning a
	 * WP_Error with a 404 status when the game or the object cannot be found.
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
	 * Looks up a game by its slug and returns the game object, or a WP_Error with a 404 status when no game matches.
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
