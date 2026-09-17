<?php

namespace BeyondElysium\REST;

use BeyondElysium\Core\Authorization;
use BeyondElysium\Models\Character;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Plot;
use BeyondElysium\Models\Release_Batch;
use BeyondElysium\Models\Secret;
use BeyondElysium\Models\Secret_Reveal;
use BeyondElysium\Models\World_Object;
use BeyondElysium\Services\Audience;
use BeyondElysium\Services\Query_Engine;
use BeyondElysium\Services\St_Visibility;

defined( 'ABSPATH' ) || exit;

/**
 * REST controller for Storyteller-authored secrets and their reveals (1.1.0 §3.11).
 * `be_manage_plots` writes both - the design's own chosen capability, reused rather than
 * inventing a new one, matching how `be_manage_plots`/`be_manage_world_objects` are already
 * the write gates for the entities a secret attaches to.
 *
 * @see BE_PROCESS/releases/1.1.0-design-workflow.md §3.11
 */
class Secrets_Controller extends Base_Controller {

	protected $rest_base = 'secrets';

	/** Which capability decides whether a viewer manages the entity a secret is attached to. */
	private const MANAGE_CAPABILITY = [
		'plot'     => 'be_manage_plots',
		'item'     => 'be_manage_world_objects',
		'location' => 'be_manage_world_objects',
		'npc'      => 'be_manage_characters',
	];

	public function register_routes(): void {
		register_rest_route( $this->namespace, '/(?P<game_slug>[a-z0-9\-]+)/secrets', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_items' ],
				'permission_callback' => $this->permission( 'be_view_characters' ),
			],
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'create_item' ],
				'permission_callback' => $this->permission( 'be_manage_plots' ),
			],
		] );

		// Registered before the numeric id route so /secrets/my is never shadowed - same
		// convention as Plots_Controller's own /my/plots.
		register_rest_route( $this->namespace, '/(?P<game_slug>[a-z0-9\-]+)/my/secrets', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_my_secrets' ],
				'permission_callback' => $this->permission( 'be_view_characters' ),
			],
		] );

		register_rest_route( $this->namespace, '/(?P<game_slug>[a-z0-9\-]+)/secrets/(?P<id>\d+)', [
			[
				'methods'             => 'PUT',
				'callback'            => [ $this, 'update_item' ],
				'permission_callback' => $this->permission( 'be_manage_plots' ),
			],
			[
				'methods'             => 'DELETE',
				'callback'            => [ $this, 'delete_item' ],
				'permission_callback' => $this->permission( 'be_manage_plots' ),
			],
		] );

		register_rest_route( $this->namespace, '/(?P<game_slug>[a-z0-9\-]+)/secrets/(?P<id>\d+)/reveals', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_reveals' ],
				'permission_callback' => $this->permission( 'be_manage_plots' ),
			],
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'create_reveal' ],
				'permission_callback' => $this->permission( 'be_manage_plots' ),
			],
		] );

		register_rest_route( $this->namespace, '/(?P<game_slug>[a-z0-9\-]+)/secrets/(?P<id>\d+)/reveals/(?P<reveal_id>\d+)', [
			[
				'methods'             => 'DELETE',
				'callback'            => [ $this, 'delete_reveal' ],
				'permission_callback' => $this->permission( 'be_manage_plots' ),
			],
		] );
	}

	/**
	 * Every secret attached to one entity - only once the viewer can see the entity itself
	 * (a secret never surfaces through an entity a viewer couldn't otherwise open), then
	 * narrowed to the secrets this viewer's own characters actually reach.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_items( $request ) {
		$game = $this->resolve_game( $request['game_slug'] );
		if ( is_wp_error( $game ) ) {
			return $game;
		}

		$entity_type = (string) $request->get_param( 'entity_type' );
		$entity_id   = (int) $request->get_param( 'entity_id' );
		if ( ! in_array( $entity_type, Secret::ENTITY_TYPES, true ) || ! $entity_id ) {
			return $this->error( 'invalid_param', sprintf( __( 'entity_type must be one of: %s.', 'beyond-elysium' ), implode( ', ', Secret::ENTITY_TYPES ) ), 400 );
		}

		$entity = $this->resolve_entity( $entity_type, $entity_id, (int) $game->id );
		if ( $entity === null ) {
			return $this->error( 'not_found', __( 'Entity not found in this game.', 'beyond-elysium' ), 404 );
		}

		$entity_can_manage = Authorization::can( self::MANAGE_CAPABILITY[ $entity_type ] );
		if ( ! $entity_can_manage && ! Audience::can_see( $entity, $entity_type, get_current_user_id(), $request['game_slug'], false ) ) {
			return $this->error( 'not_found', __( 'Entity not found in this game.', 'beyond-elysium' ), 404 );
		}

		$can_manage_secrets = Authorization::can( 'be_manage_plots' );
		$secrets            = Secret::for_entity( (int) $game->id, $entity_type, $entity_id );
		if ( ! $can_manage_secrets ) {
			$secrets = Audience::filter( $secrets, 'secret', get_current_user_id(), $request['game_slug'], false );
			foreach ( $secrets as $secret ) {
				St_Visibility::filter_secret( $secret, $game, false );
			}
		}

		return $this->success( array_values( $secrets ) );
	}

	/**
	 * "What I Know" (1.1.0 §3.11): every secret revealed to one of the caller's own
	 * characters, grouped with the entity's type and name - null when the viewer can't
	 * independently see that entity, so a secret never discloses a hidden one - and when/how
	 * it was learned. A held reveal whose batch hasn't gone out yet is skipped entirely, the
	 * same as it never happened.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_my_secrets( $request ) {
		$game = $this->resolve_game( $request['game_slug'] );
		if ( is_wp_error( $game ) ) {
			return $game;
		}

		$wp_user_id     = get_current_user_id();
		$my_characters  = Character::find_for_user( $wp_user_id, $request['game_slug'] );
		$my_ids         = array_map( static fn( $c ) => (int) $c->id, $my_characters );
		$out_batch_ids  = Release_Batch::out_ids( (int) $game->id );
		$can_manage     = Authorization::can( 'be_manage_plots' );

		$results = [];
		foreach ( $my_ids as $character_id ) {
			foreach ( Secret_Reveal::for_character( $character_id ) as $reveal ) {
				if ( ! Secret_Reveal::is_effective( $reveal, $out_batch_ids ) ) {
					continue;
				}
				$secret = Secret::find( (int) $reveal->secret_id );
				if ( ! $secret || (int) $secret->game_id !== (int) $game->id ) {
					continue;
				}
				St_Visibility::filter_secret( $secret, $game, $can_manage );

				$entity      = $this->resolve_entity( $secret->entity_type, (int) $secret->entity_id, (int) $game->id );
				$entity_name = null;
				if ( $entity !== null ) {
					$entity_can_manage = Authorization::can( self::MANAGE_CAPABILITY[ $secret->entity_type ] ?? 'be_manage_plots' );
					if ( $entity_can_manage || Audience::can_see( $entity, $secret->entity_type, $wp_user_id, $request['game_slug'], false ) ) {
						$entity_name = $entity->name ?? $entity->title ?? null;
					}
				}

				$results[] = [
					'id'          => (int) $secret->id,
					'title'       => $secret->title,
					'content'     => $secret->content,
					'entity_type' => $secret->entity_type,
					'entity_name' => $entity_name,
					'how'         => $reveal->how,
					'learned_at'  => $reveal->created_at,
				];
			}
		}

		return $this->success( $results );
	}

	/**
	 * Creates a secret. 400 `invalid_param` on an unrecognized entity_type, or an entity_id
	 * that doesn't resolve to a real entity of that type in this game.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function create_item( $request ) {
		$game = $this->resolve_game( $request['game_slug'] );
		if ( is_wp_error( $game ) ) {
			return $game;
		}

		$entity_type = (string) $request->get_param( 'entity_type' );
		$entity_id   = (int) $request->get_param( 'entity_id' );
		if ( ! in_array( $entity_type, Secret::ENTITY_TYPES, true ) || ! $entity_id
			|| $this->resolve_entity( $entity_type, $entity_id, (int) $game->id ) === null ) {
			return $this->error( 'invalid_param', sprintf( __( 'entity_type must be one of: %s, naming a real entity in this game.', 'beyond-elysium' ), implode( ', ', Secret::ENTITY_TYPES ) ), 400 );
		}

		$title = trim( (string) $request->get_param( 'title' ) );
		if ( $title === '' ) {
			return $this->error( 'invalid_param', __( 'Missing required field: title.', 'beyond-elysium' ), 400 );
		}

		$audience = $this->resolve_audience( $request );
		if ( is_wp_error( $audience ) ) {
			return $audience;
		}

		$id = Secret::create( array_merge( [
			'game_id'     => (int) $game->id,
			'entity_type' => $entity_type,
			'entity_id'   => $entity_id,
			'title'       => sanitize_text_field( $title ),
			'content'     => $request->get_param( 'content' ) ? wp_kses_post( (string) $request->get_param( 'content' ) ) : null,
			'created_by'  => get_current_user_id(),
		], $audience ) );
		if ( ! $id ) {
			return $this->error( 'create_failed', __( 'Failed to create this secret.', 'beyond-elysium' ), 500 );
		}

		return $this->success( Secret::find( (int) $id ), 201 );
	}

	/**
	 * Validates a requested `audience`/`audience_rules` pair, identical shape to
	 * `World_Objects_Controller::resolve_audience()`.
	 *
	 * @param \WP_REST_Request $request
	 * @return array{audience?:string,audience_rules?:?array}|\WP_Error
	 */
	private function resolve_audience( $request ) {
		$data = [];

		$audience = $request->get_param( 'audience' );
		if ( $audience !== null ) {
			if ( ! in_array( $audience, Secret::AUDIENCE_VALUES, true ) ) {
				return $this->error( 'invalid_param', sprintf( __( 'audience must be one of: %s.', 'beyond-elysium' ), implode( ', ', Secret::AUDIENCE_VALUES ) ), 400 );
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
	 * Updates a secret's title, content, audience, and/or audience_rules.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function update_item( $request ) {
		$secret = $this->resolve_secret( $request );
		if ( is_wp_error( $secret ) ) {
			return $secret;
		}

		$data = [];
		if ( $request->has_param( 'title' ) ) {
			$title = trim( (string) $request->get_param( 'title' ) );
			if ( $title === '' ) {
				return $this->error( 'invalid_param', __( 'Missing required field: title.', 'beyond-elysium' ), 400 );
			}
			$data['title'] = sanitize_text_field( $title );
		}
		if ( $request->has_param( 'content' ) ) {
			$data['content'] = wp_kses_post( (string) $request->get_param( 'content' ) );
		}

		$audience = $this->resolve_audience( $request );
		if ( is_wp_error( $audience ) ) {
			return $audience;
		}
		$data = array_merge( $data, $audience );

		if ( ! Secret::update( (int) $secret->id, $data ) && ! empty( $data ) ) {
			return $this->error( 'update_failed', __( 'Failed to update this secret.', 'beyond-elysium' ), 500 );
		}

		return $this->success( Secret::find( (int) $secret->id ) );
	}

	/**
	 * Deletes a secret and every one of its reveals.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function delete_item( $request ) {
		$secret = $this->resolve_secret( $request );
		if ( is_wp_error( $secret ) ) {
			return $secret;
		}

		Secret::delete( (int) $secret->id );
		return $this->success( null, 204 );
	}

	/**
	 * Every reveal of one secret - the Secrets panel's own "revealed to" list. Manager only,
	 * same as everything else about a secret's own management.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_reveals( $request ) {
		$secret = $this->resolve_secret( $request );
		if ( is_wp_error( $secret ) ) {
			return $secret;
		}

		return $this->success( Secret_Reveal::for_secret( (int) $secret->id ) );
	}

	/**
	 * Reveals a secret to a character. 400 `invalid_param` on a character outside this game,
	 * or an unrecognized `how`; 409 `already_revealed` when this character already has a
	 * reveal for this secret.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function create_reveal( $request ) {
		$secret = $this->resolve_secret( $request );
		if ( is_wp_error( $secret ) ) {
			return $secret;
		}

		$character_id = (int) $request->get_param( 'character_id' );
		$character    = $character_id ? Character::find( $character_id ) : null;
		if ( ! $character || $character->owner_slug !== $request['game_slug'] ) {
			return $this->error( 'invalid_param', __( 'character_id must be a real character in this game.', 'beyond-elysium' ), 400 );
		}

		if ( Secret_Reveal::already_revealed( (int) $secret->id, $character_id ) ) {
			return $this->error( 'already_revealed', __( 'This character has already been revealed this secret.', 'beyond-elysium' ), 409 );
		}

		$how = (string) ( $request->get_param( 'how' ) ?: 'game' );
		if ( ! in_array( $how, Secret_Reveal::HOW_VALUES, true ) ) {
			return $this->error( 'invalid_param', sprintf( __( 'how must be one of: %s.', 'beyond-elysium' ), implode( ', ', Secret_Reveal::HOW_VALUES ) ), 400 );
		}

		$id = Secret_Reveal::create( [
			'secret_id'        => (int) $secret->id,
			'character_id'     => $character_id,
			'how'              => $how,
			'note'             => $request->get_param( 'note' ) ? sanitize_textarea_field( (string) $request->get_param( 'note' ) ) : null,
			'held'             => (bool) $request->get_param( 'held' ),
			'release_batch_id' => $request->get_param( 'release_batch_id' ) ? (int) $request->get_param( 'release_batch_id' ) : null,
			'revealed_by'      => get_current_user_id(),
		] );
		if ( ! $id ) {
			return $this->error( 'create_failed', __( 'Failed to reveal this secret.', 'beyond-elysium' ), 500 );
		}

		return $this->success( Secret_Reveal::find( (int) $id ), 201 );
	}

	/**
	 * Removes one reveal.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function delete_reveal( $request ) {
		$secret = $this->resolve_secret( $request );
		if ( is_wp_error( $secret ) ) {
			return $secret;
		}

		$reveal = Secret_Reveal::find( (int) $request['reveal_id'] );
		if ( ! $reveal || (int) $reveal->secret_id !== (int) $secret->id ) {
			return $this->error( 'not_found', __( 'Reveal not found on this secret.', 'beyond-elysium' ), 404 );
		}

		Secret_Reveal::delete( (int) $reveal->id );
		return $this->success( null, 204 );
	}

	/**
	 * Resolves the real entity a secret attaches to - the parent-visibility check `get_items()`
	 * needs and the existence check `create_item()` needs, in one place so both agree on what
	 * "a real entity of that type" means.
	 *
	 * @param string $entity_type
	 * @param int    $entity_id
	 * @param int    $game_id
	 * @return object|null
	 */
	private function resolve_entity( string $entity_type, int $entity_id, int $game_id ): ?object {
		if ( $entity_type === 'plot' ) {
			$plot = Plot::find( $entity_id );
			return ( $plot && (int) $plot->game_id === $game_id ) ? $plot : null;
		}
		if ( in_array( $entity_type, [ 'item', 'location' ], true ) ) {
			$object = World_Object::find( $entity_id );
			return ( $object && (int) $object->game_id === $game_id && $object->object_type === $entity_type ) ? $object : null;
		}
		if ( $entity_type === 'npc' ) {
			$character = Character::find( $entity_id );
			$game      = Game::find( $game_id );
			if ( ! $character || ! $game || $character->owner_type !== 'chronicle'
				|| $character->owner_slug !== $game->slug || (int) $character->is_npc !== 1 ) {
				return null;
			}
			return $character;
		}
		return null;
	}

	/**
	 * Looks up a secret by id, confirming it belongs to the URL's game.
	 *
	 * @param \WP_REST_Request $request
	 * @return object|\WP_Error
	 */
	private function resolve_secret( $request ) {
		$game = $this->resolve_game( $request['game_slug'] );
		if ( is_wp_error( $game ) ) {
			return $game;
		}
		$secret = Secret::find( (int) $request['id'] );
		if ( ! $secret || (int) $secret->game_id !== (int) $game->id ) {
			return $this->error( 'not_found', __( 'Secret not found in this game.', 'beyond-elysium' ), 404 );
		}
		return $secret;
	}

	/**
	 * Looks up a game by its slug and returns the game object, or a WP_Error with a 404
	 * status when no game matches.
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
