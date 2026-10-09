<?php

namespace BeyondElysium\REST;

use BeyondElysium\Core\Authorization;
use BeyondElysium\Core\Notifications;
use BeyondElysium\Models\Change;
use BeyondElysium\Models\Character;
use BeyondElysium\Models\Connection;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Plot;
use BeyondElysium\Models\Release_Batch;
use BeyondElysium\Models\Secret;
use BeyondElysium\Models\Secret_Reveal;
use BeyondElysium\Models\World_Object;
use BeyondElysium\Services\Audience;
use BeyondElysium\Services\Change_Engine;
use BeyondElysium\Services\Query_Engine;
use BeyondElysium\Services\St_Visibility;

defined( 'ABSPATH' ) || exit;

/**
 * REST controller for Storyteller-authored secrets and their reveals.
 */
class Secrets_Controller extends Base_Controller {

	protected $rest_base = 'secrets';

	/**
	 * Which capability decides whether a viewer manages the entity a secret is attached to.
	 */
	private const MANAGE_CAPABILITY = [
		'plot'      => 'be_manage_plots',
		'item'      => 'be_manage_world_objects',
		'location'  => 'be_manage_world_objects',
		'character' => 'be_manage_characters',
		'npc'       => 'be_manage_characters',
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

		// Registered before the numeric id route.
		register_rest_route( $this->namespace, '/(?P<game_slug>[a-z0-9\-]+)/my/secrets', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_my_secrets' ],
				'permission_callback' => $this->permission( 'be_view_characters' ),
			],
		] );

		register_rest_route( $this->namespace, '/(?P<game_slug>[a-z0-9\-]+)/my/secrets/log', [
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'log_knowledge' ],
				'permission_callback' => $this->permission( 'be_view_characters' ),
			],
		] );

		register_rest_route( $this->namespace, '/(?P<game_slug>[a-z0-9\-]+)/my/secrets/people', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_people' ],
				'permission_callback' => $this->permission( 'be_view_characters' ),
			],
		] );

		register_rest_route( $this->namespace, '/(?P<game_slug>[a-z0-9\-]+)/secrets/all', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_all_secrets' ],
				'permission_callback' => $this->permission( 'be_manage_plots' ),
			],
		] );

		register_rest_route( $this->namespace, '/(?P<game_slug>[a-z0-9\-]+)/secrets/(?P<id>\d+)/pass', [
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'pass_secret' ],
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
	 * Every secret attached to one entity.
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

		$entity = self::resolve_entity( $entity_type, $entity_id, (int) $game->id );
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
	 * "What I Know": every secret revealed to one of the caller's own characters, grouped with the entity's type and
	 * name.
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
		$names_by_id    = array_combine( $my_ids, array_map( static fn( $c ) => (string) $c->name, $my_characters ) );
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

				$entity      = $secret->entity_type !== null
					? self::resolve_entity( $secret->entity_type, (int) $secret->entity_id, (int) $game->id )
					: null;
				$entity_name = null;
				if ( $entity !== null ) {
					$entity_can_manage = Authorization::can( self::MANAGE_CAPABILITY[ $secret->entity_type ] ?? 'be_manage_plots' );
					if ( $entity_can_manage || Audience::can_see( $entity, $secret->entity_type, $wp_user_id, $request['game_slug'], false ) ) {
						$entity_name = $entity->name ?? $entity->title ?? null;
					}
				}

				$told_by = null;
				if ( ! empty( $reveal->from_character_id ) ) {
					$teller  = Character::find( (int) $reveal->from_character_id );
					$told_by = $teller ? $teller->name : null;
				}

				$results[] = [
					'id'           => (int) $secret->id,
					'reveal_id'    => (int) $reveal->id,
					'character_id' => $character_id,
					'character_name' => $names_by_id[ $character_id ] ?? '',
					'title'        => $secret->title,
					'content'      => $secret->content,
					'entity_type'  => $secret->entity_type,
					'entity_name'  => $entity_name,
					'how'          => $reveal->how,
					'told_by'      => $told_by,
					'approved'     => (bool) $reveal->approved,
					'can_pass'     => (bool) $reveal->approved && $secret->audience !== 'everyone',
					'learned_at'   => $reveal->created_at,
				];
			}
		}

		$waiting = [];
		foreach ( [ 'log_knowledge', 'pass_secret' ] as $type ) {
			foreach ( Change::for_game( $request['game_slug'], [ 'change_type' => $type, 'wp_user_id' => $wp_user_id ] ) as $change ) {
				if ( $change->status === 'approved' ) {
					continue;
				}
				St_Visibility::filter_change( $change, $game, $can_manage );
				$data      = is_array( $change->change_data ) ? $change->change_data : [];
				$waiting[] = [
					'id'            => (int) $change->id,
					'change_type'   => $change->change_type,
					'status'        => $change->status,
					'character_id'  => (int) $change->character_id,
					'character_name' => $names_by_id[ (int) $change->character_id ] ?? '',
					'title'         => $data['title'] ?? ( $data['secret_title'] ?? '' ),
					'review_notes'  => $change->review_notes,
					'submitted_at'  => $change->submitted_at,
				];
			}
		}

		return $this->success( [ 'known' => $results, 'waiting' => $waiting ] );
	}

	/**
	 * Every secret in a chronicle, across every entity, for the tie-to-an-existing-secret picker - never
	 * audience-filtered.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_all_secrets( $request ) {
		$game = $this->resolve_game( $request['game_slug'] );
		if ( is_wp_error( $game ) ) {
			return $game;
		}

		$secrets = Secret::for_game( (int) $game->id );

		$search = trim( (string) $request->get_param( 'search' ) );
		if ( $search !== '' ) {
			$secrets = array_values( array_filter(
				$secrets,
				static fn( $secret ) => stripos( (string) $secret->title, $search ) !== false
			) );
		}

		return $this->success( $secrets );
	}

	/**
	 * The people the viewer may name as the one who told them something.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_people( $request ) {
		$game = $this->resolve_game( $request['game_slug'] );
		if ( is_wp_error( $game ) ) {
			return $game;
		}

		return $this->success( array_values( self::nameable_people( $request['game_slug'], get_current_user_id() ) ) );
	}

	/**
	 * Every character a viewer may name as the person who told them something: their Who's Who, plus the characters at
	 * the far end of a character-to-character connection from one of their own active characters. Sorted by name,
	 * keyed by character id.
	 *
	 * @param string $game_slug
	 * @param int    $viewer_id
	 * @return array<int,array{id:int,name:string,kind:string}>
	 */
	public static function nameable_people( string $game_slug, int $viewer_id ): array {
		$people  = Npc_Profiles_Controller::whos_who_people( $game_slug, $viewer_id );
		$own_ids = array_map(
			static fn( $character ) => (int) $character->id,
			Character::all_for_game( $game_slug, [ 'wp_user_id' => $viewer_id, 'status' => 'active' ] )
		);

		foreach ( self::connected_character_ids( $own_ids ) as $id ) {
			if ( isset( $people[ $id ] ) ) {
				continue;
			}
			$character = Character::find( $id );
			if ( ! $character || $character->owner_slug !== $game_slug || $character->status !== 'active' ) {
				continue;
			}
			$people[ $id ] = [
				'id'   => $id,
				'name' => Npc_Profiles_Controller::display_name( $character ),
				'kind' => $character->is_npc ? 'npc' : 'pc',
			];
		}

		uasort( $people, static fn( $a, $b ) => strcasecmp( $a['name'], $b['name'] ) );
		return $people;
	}

	/**
	 * The characters on the far end of a character-to-character connection from any of the given characters.
	 *
	 * @param int[] $own_ids
	 * @return int[]
	 */
	private static function connected_character_ids( array $own_ids ): array {
		$far = [];
		foreach ( Connection::for_entities( 'character', $own_ids ) as $connection ) {
			$row = (array) $connection;
			if ( ( $row['source_type'] ?? '' ) !== 'character' || ( $row['target_type'] ?? '' ) !== 'character' || empty( $row['target_id'] ) ) {
				continue;
			}
			$source = (int) ( $row['source_id'] ?? 0 );
			$target = (int) $row['target_id'];
			if ( in_array( $source, $own_ids, true ) ) {
				$far[] = $target;
			}
			if ( in_array( $target, $own_ids, true ) ) {
				$far[] = $source;
			}
		}
		return array_values( array_unique( $far ) );
	}

	/**
	 * Files a pending `log_knowledge` change: a player recording what one of their own characters learned, for a
	 * Storyteller to tie to an existing secret or turn into a new one.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function log_knowledge( $request ) {
		$game = $this->resolve_game( $request['game_slug'] );
		if ( is_wp_error( $game ) ) {
			return $game;
		}

		if ( ( $game->settings->secret_passing ?? 'approval' ) === 'off' ) {
			return $this->error( 'secret_passing_off', __( 'This chronicle has logging and passing secrets turned off.', 'beyond-elysium' ), 403 );
		}

		$character_id = (int) $request->get_param( 'character_id' );
		$character    = $character_id ? Character::find( $character_id ) : null;
		if ( ! $character || $character->owner_slug !== $request['game_slug'] || (int) $character->wp_user_id !== get_current_user_id() ) {
			return $this->error( 'invalid_param', __( 'character_id must be one of your own characters in this game.', 'beyond-elysium' ), 400 );
		}

		$title = trim( (string) $request->get_param( 'title' ) );
		$details = trim( (string) $request->get_param( 'details' ) );
		if ( $title === '' || $details === '' ) {
			return $this->error( 'invalid_param', __( 'title and details are both required.', 'beyond-elysium' ), 400 );
		}

		$how = (string) ( $request->get_param( 'how' ) ?: 'other' );
		if ( ! in_array( $how, Secret_Reveal::HOW_VALUES, true ) ) {
			return $this->error( 'invalid_param', sprintf( __( 'how must be one of: %s.', 'beyond-elysium' ), implode( ', ', Secret_Reveal::HOW_VALUES ) ), 400 );
		}

		$teller_character_id = $request->get_param( 'teller_character_id' ) ? (int) $request->get_param( 'teller_character_id' ) : null;
		if ( $teller_character_id !== null && $teller_character_id === $character_id ) {
			return $this->error( 'invalid_param', __( 'A character cannot be named as the one who told it something.', 'beyond-elysium' ), 400 );
		}
		if ( $teller_character_id !== null && ! isset( self::nameable_people( $request['game_slug'], get_current_user_id() )[ $teller_character_id ] ) ) {
			return $this->error( 'invalid_param', __( 'teller_character_id must be someone you can name: a character in your Who\'s Who, or one your characters are connected to.', 'beyond-elysium' ), 400 );
		}
		$teller_name          = $teller_character_id ? null : ( $request->get_param( 'teller_name' ) ? sanitize_text_field( (string) $request->get_param( 'teller_name' ) ) : null );

		$change_id = Change_Engine::submit( $character_id, [
			'change_type' => 'log_knowledge',
			'category'    => 'secret',
			'change_data' => [
				'title'               => sanitize_text_field( $title ),
				'details'             => sanitize_textarea_field( $details ),
				'how'                 => $how,
				'teller_character_id' => $teller_character_id,
				'teller_name'         => $teller_name,
			],
			'xp_cost'     => 0,
		], get_current_user_id() );

		if ( ! $change_id ) {
			return $this->error( 'create_failed', __( 'Failed to log this.', 'beyond-elysium' ), 500 );
		}

		return $this->success( Change::find( $change_id ), 201 );
	}

	/**
	 * Tells another character a secret the caller's own character already knows. Refuses, in order: the chronicle has
	 * this off; the teller isn't the caller's own character; the teller hasn't learned the secret (never confirming it
	 * exists); the teller's own knowledge isn't approved yet; the secret is already `everyone`-visible; the recipient
	 * isn't in the caller's Who's Who; the recipient is the teller; the recipient already knows it.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function pass_secret( $request ) {
		$game = $this->resolve_game( $request['game_slug'] );
		if ( is_wp_error( $game ) ) {
			return $game;
		}
		$mode = (string) ( $game->settings->secret_passing ?? 'approval' );
		if ( $mode === 'off' ) {
			return $this->error( 'secret_passing_off', __( 'This chronicle has logging and passing secrets turned off.', 'beyond-elysium' ), 403 );
		}

		$wp_user_id         = get_current_user_id();
		$from_character_id  = (int) $request->get_param( 'from_character_id' );
		$to_character_id    = (int) $request->get_param( 'to_character_id' );

		$from = $from_character_id ? Character::find( $from_character_id ) : null;
		if ( ! $from || $from->owner_slug !== $request['game_slug'] || (int) $from->wp_user_id !== $wp_user_id ) {
			return $this->error( 'ownership_denied', __( 'from_character_id must be one of your own characters in this game.', 'beyond-elysium' ), 403 );
		}

		$secret = $this->resolve_secret( $request );
		if ( is_wp_error( $secret ) ) {
			return $secret;
		}

		$out_batch_ids = Release_Batch::out_ids( (int) $game->id );
		$reveal        = Secret_Reveal::find_for( (int) $secret->id, $from_character_id );
		if ( ! $reveal || ! Secret_Reveal::is_effective( $reveal, $out_batch_ids ) ) {
			return $this->error( 'not_found', __( 'Secret not found in this game.', 'beyond-elysium' ), 404 );
		}
		if ( empty( $reveal->approved ) ) {
			return $this->error( 'knowledge_not_approved', __( 'A Storyteller has not approved this knowledge yet, so it cannot be passed on.', 'beyond-elysium' ), 403 );
		}
		if ( $secret->audience === 'everyone' ) {
			return $this->error( 'secret_already_public', __( 'Everyone can already see this, so there is nothing to tell.', 'beyond-elysium' ), 400 );
		}
		if ( ! Npc_Profiles_Controller::is_in_whos_who( $to_character_id, $request['game_slug'], $wp_user_id ) ) {
			return $this->error( 'not_found', __( 'Character not found in this game.', 'beyond-elysium' ), 404 );
		}
		if ( $to_character_id === $from_character_id ) {
			return $this->error( 'invalid_param', __( 'You cannot tell a secret to the character who already knows it.', 'beyond-elysium' ), 400 );
		}
		if ( Secret_Reveal::already_revealed( (int) $secret->id, $to_character_id ) ) {
			return $this->error( 'already_known', __( 'This character already knows this secret.', 'beyond-elysium' ), 409 );
		}

		$to = Character::find( $to_character_id );
		$note = $request->get_param( 'note' ) ? sanitize_textarea_field( (string) $request->get_param( 'note' ) ) : null;

		$change_id = Change_Engine::submit( $from_character_id, [
			'change_type' => 'pass_secret',
			'category'    => 'secret',
			'change_data' => [
				'secret_id'         => (int) $secret->id,
				'secret_title'      => $secret->title,
				'from_character_id' => $from_character_id,
				'from_name'         => $from->name,
				'to_character_id'   => $to_character_id,
				'to_name'           => $to ? $to->name : '',
				'note'              => $note,
			],
			'xp_cost'     => 0,
		], $wp_user_id );

		if ( ! $change_id ) {
			return $this->error( 'create_failed', __( 'Failed to send this.', 'beyond-elysium' ), 500 );
		}

		if ( $mode === 'immediate' ) {
			Secret_Reveal::create( [
				'secret_id'         => (int) $secret->id,
				'character_id'      => $to_character_id,
				'how'               => 'told',
				'note'              => $note,
				'from_character_id' => $from_character_id,
				'revealed_by'       => $wp_user_id,
				'approved'          => false,
			] );

			if ( $to ) {
				Notifications::notify_if_newly_visible(
					$to,
					false,
					true,
					$game,
					'secret_told',
					(string) $secret->title,
					Notifications::player_what_i_know_url( $request['game_slug'] ),
					$wp_user_id
				);
				Notifications::flush_visible();
			}
			Notifications::secret_told_staff( $game, $secret, (string) $from->name, $to ? (string) $to->name : '' );
		}

		return $this->success( Change::find( $change_id ), 201 );
	}

	/**
	 * Creates a secret. 400 `invalid_param` on an unrecognized entity_type, or an entity_id that doesn't resolve to a
	 * real entity of that type in this game.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function create_item( $request ) {
		$game = $this->resolve_game( $request['game_slug'] );
		if ( is_wp_error( $game ) ) {
			return $game;
		}

		$entity_type_raw = $request->get_param( 'entity_type' );
		$entity_type     = $entity_type_raw !== null && $entity_type_raw !== ''
			? (string) $entity_type_raw
			: null;

		if ( $entity_type !== null ) {
			$entity_id = (int) $request->get_param( 'entity_id' );
			if ( ! in_array( $entity_type, Secret::ENTITY_TYPES, true ) || ! $entity_id
				|| self::resolve_entity( $entity_type, $entity_id, (int) $game->id ) === null ) {
				return $this->error( 'invalid_param', sprintf( __( 'entity_type must be one of: %s, naming a real entity in this game.', 'beyond-elysium' ), implode( ', ', Secret::ENTITY_TYPES ) ), 400 );
			}
		} else {
			$entity_id = null;
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
	 * @return array{audience?:string,audience_rules?:array<string,mixed>|null}|\WP_Error
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
	 * Every reveal of one secret.
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
	 * Reveals a secret to a character. 400 `invalid_param` on a character outside this game, or an unrecognized `how`.
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
	 * Resolves the real entity a secret attaches.
	 *
	 * @param string $entity_type
	 * @param int    $entity_id
	 * @param int    $game_id
	 * @return object|null
	 */
	public static function resolve_entity( string $entity_type, int $entity_id, int $game_id ): ?object {
		if ( $entity_type === 'plot' ) {
			$plot = Plot::find( $entity_id );
			return ( $plot && (int) $plot->game_id === $game_id ) ? $plot : null;
		}
		if ( in_array( $entity_type, [ 'item', 'location' ], true ) ) {
			$object = World_Object::find( $entity_id );
			return ( $object && (int) $object->game_id === $game_id && $object->object_type === $entity_type ) ? $object : null;
		}
		if ( $entity_type === 'npc' || $entity_type === 'character' ) {
			$character     = Character::find( $entity_id );
			$game          = Game::find( $game_id );
			$wants_npc     = $entity_type === 'npc';
			if ( ! $character || ! $game || $character->owner_type !== 'chronicle'
				|| $character->owner_slug !== $game->slug
				|| ( (int) $character->is_npc === 1 ) !== $wants_npc ) {
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
