<?php

namespace BeyondElysium\REST;

use BeyondElysium\Core\Authorization;
use BeyondElysium\Core\Notifications;
use BeyondElysium\Models\Character;
use BeyondElysium\Models\Connection;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Plot;
use BeyondElysium\Models\Plot_Entry;
use BeyondElysium\Services\Action_Allocator;
use BeyondElysium\Services\Query_Engine;
use BeyondElysium\Services\Rumor_Generator;

defined( 'ABSPATH' ) || exit;

/**
 * REST controller for plots: the storyteller-authored and player-submitted
 * threads that structure a game's ongoing story. Supports listing, single-
 * item retrieval with entries and connections, a player's cross-plot feed,
 * creation, update, deletion, and the action-allocation and rumor-generation
 * helper endpoints used to run a game date.
 */
class Plots_Controller extends Base_Controller {

	protected $rest_base = 'plots';

	/**
	 * Registers the REST routes for the plot collection, a single plot,
	 * the player's cross-plot feed, and the action-allocation and
	 * rumor-generation helper endpoints. All routes are scoped to a game
	 * slug.
	 */
	public function register_routes(): void {
		register_rest_route( $this->namespace, '/(?P<game_slug>[a-z0-9\-]+)/plots', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_items' ],
				'permission_callback' => $this->permission( 'be_view_characters' ),
				'args'                => $this->get_collection_params(),
			],
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'create_item' ],
				'permission_callback' => $this->permission_any( [ 'be_submit_actions', 'be_manage_plots' ] ),
			],
		] );

		// Registered before the numeric plot id route so /my/plots is never shadowed.
		register_rest_route( $this->namespace, '/(?P<game_slug>[a-z0-9\-]+)/my/plots', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_my_plots' ],
				'permission_callback' => $this->permission( 'be_view_characters' ),
			],
		] );

		// Neither route is numeric, so neither collides with the /plots/{id} route below.
		register_rest_route( $this->namespace, '/(?P<game_slug>[a-z0-9\-]+)/plots/allocate-actions', [
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'allocate_actions' ],
				'permission_callback' => $this->permission( 'be_manage_plots' ),
			],
		] );

		register_rest_route( $this->namespace, '/(?P<game_slug>[a-z0-9\-]+)/plots/generate-rumors', [
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'generate_rumors' ],
				'permission_callback' => $this->permission( 'be_manage_plots' ),
			],
		] );

		register_rest_route( $this->namespace, '/(?P<game_slug>[a-z0-9\-]+)/plots/(?P<id>\d+)', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_item' ],
				'permission_callback' => $this->permission( 'be_view_characters' ),
			],
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
	}

	/**
	 * Returns a paginated list of plots for a game, optionally filtered
	 * by status, initiated_by, search text, or a date range, and ordered
	 * by the requested column and direction. Each plot is prepared with
	 * its derived status and a thumbnail image URL.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_items( $request ) {
		$game = $this->resolve_game( $request['game_slug'] );
		if ( is_wp_error( $game ) ) {
			return $game;
		}

		$can_manage = current_user_can( 'be_manage_plots' );
		$pagination = $this->get_pagination( $request );
		$args       = [
			'status'       => $request->get_param( 'status' ),
			'initiated_by' => $request->get_param( 'initiated_by' ),
			'search'       => $request->get_param( 'search' ),
			'date_from'    => $request->get_param( 'date_from' ),
			'date_to'      => $request->get_param( 'date_to' ),
			'orderby'      => $request->get_param( 'orderby' ) ?: 'updated_at',
			'order'        => $request->get_param( 'order' ) ?: 'DESC',
			'per_page'     => $pagination['per_page'],
			'offset'       => $pagination['offset'],
		];
		// A non-manager never sees another character's action-allocation plot in the list -
		// its title alone already discloses who has one (§3.4/§5.8).
		if ( ! $can_manage ) {
			$args['exclude_actor_plots_not_owned_by'] = get_current_user_id();
		}

		$items = Plot::for_game( (int) $game->id, $args );
		foreach ( $items as $item ) {
			$this->prepare_plot( $item, $can_manage, 'thumbnail' );
		}

		$total    = Plot::count_for_game( (int) $game->id, $args );
		$response = $this->success( $items );
		return $this->paginate( $response, $total, $pagination['per_page'], $pagination['page'] );
	}

	/**
	 * Returns a single plot along with its entries, connections, and
	 * immediate child plots in one response. Strips note-type entries
	 * for a viewer without be_manage_plots, and prepares each child the
	 * same way a top-level plot is prepared.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_item( $request ) {
		$game = $this->resolve_game( $request['game_slug'] );
		if ( is_wp_error( $game ) ) {
			return $game;
		}

		$plot = Plot::find( (int) $request['id'] );
		if ( ! $plot || (int) $plot->game_id !== (int) $game->id ) {
			return $this->error( 'not_found', __( 'Plot not found in this game.', 'beyond-elysium' ), 404 );
		}

		// Chronicle-scoped, not a bare current_user_can(): a site editor who is only a
		// plain player in this specific chronicle must not see its ST notes or another
		// character's allocation, even though the capability alone would pass (§3.4/§5.8).
		$can_manage = Authorization::check_request( 'be_manage_plots', $request );
		$this->prepare_plot( $plot, $can_manage );

		// Whether this plot is someone else's action-allocation - its entries disclose
		// exact background dot ratings and are never visible past ownership (§3.4/§5.8).
		$is_unowned_allocation = ! $can_manage
			&& Action_Allocator::actor_character_id( (int) $plot->id ) !== null
			&& ! Action_Allocator::is_actor_owned_by( (int) $plot->id, get_current_user_id() );

		$entries = $is_unowned_allocation ? [] : Plot_Entry::for_plot( (int) $plot->id );
		if ( ! $can_manage ) {
			// Note entries are ST-only; excluded from the response, not just hidden client-side.
			$entries = array_values( array_filter( $entries, static function ( $entry ) {
				return $entry->entry_type !== 'note';
			} ) );
		}

		$plot->entries     = $entries;
		$plot->connections = Connection::for_entity( 'plot', (int) $plot->id );
		// Immediate child plots are included and prepared the same way as the parent.
		$plot->children = Plot::children( (int) $plot->id );
		foreach ( $plot->children as $child ) {
			$this->prepare_plot( $child, $can_manage, 'thumbnail' );
		}

		return $this->success( $plot );
	}

	/**
	 * Returns the current player's cross-plot feed: every plot directly
	 * connected to one of their characters, plus every plot whose
	 * target_query resolves to include one of their characters. Reports
	 * which resolution methods were applied.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_my_plots( $request ) {
		$game = $this->resolve_game( $request['game_slug'] );
		if ( is_wp_error( $game ) ) {
			return $game;
		}

		$can_manage = current_user_can( 'be_manage_plots' );
		$wp_user_id = get_current_user_id();

		$by_id = [];
		foreach ( Plot::for_user( (int) $game->id, $wp_user_id ) as $plot ) {
			$by_id[ $plot->id ] = $plot;
		}

		// Resolves target_query candidates against the user's own characters only.
		$character_ids = array_map(
			static fn( $c ) => (int) $c->id,
			Character::find_for_user( $wp_user_id, $request['game_slug'] )
		);
		if ( ! empty( $character_ids ) ) {
			foreach ( Plot::for_target_query_candidates( (int) $game->id ) as $plot ) {
				if ( isset( $by_id[ $plot->id ] ) ) {
					continue;
				}
				$recipients = Query_Engine::resolve_target_query( $request['game_slug'], $plot->target_query );
				if ( array_intersect( $character_ids, $recipients ) ) {
					$by_id[ $plot->id ] = $plot;
				}
			}
		}

		$plots = array_values( $by_id );
		usort( $plots, static fn( $a, $b ) => strcmp( $b->updated_at, $a->updated_at ) );
		foreach ( $plots as $plot ) {
			$this->prepare_plot( $plot, $can_manage );
		}

		return $this->success( [
			'plots'      => $plots,
			'resolution' => [
				'direct_connections' => true,
				'target_query'       => true,
			],
		] );
	}

	/**
	 * Creates a new plot. A caller without be_manage_plots may only
	 * create a player-initiated plot and cannot set st_notes, status,
	 * plot_category, image_id, cliffhanger, or faction_goals - those
	 * fields are filtered out by capability rather than trusted from
	 * the request.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function create_item( $request ) {
		$game = $this->resolve_game( $request['game_slug'] );
		if ( is_wp_error( $game ) ) {
			return $game;
		}

		$title = $request->get_param( 'title' );
		if ( empty( $title ) ) {
			return $this->error( 'invalid_param', __( 'Missing required field: title.', 'beyond-elysium' ), 400 );
		}

		$can_manage = current_user_can( 'be_manage_plots' );

		// parent_plot_id is validated here to return a clean 400 instead of a generic failure.
		$parent_plot_id = $request->get_param( 'parent_plot_id' );
		if ( $parent_plot_id ) {
			$parent = Plot::find( (int) $parent_plot_id );
			if ( ! $parent || (int) $parent->game_id !== (int) $game->id ) {
				return $this->error( 'invalid_param', __( 'parent_plot_id must be a real plot in this game.', 'beyond-elysium' ), 400 );
			}
		}

		$data = [
			'game_id'          => (int) $game->id,
			'parent_plot_id'   => $parent_plot_id ? (int) $parent_plot_id : null,
			'title'            => sanitize_text_field( $title ),
			// Rich text: sanitized with the same allowlist used for other free-text fields.
			'description'      => $request->get_param( 'description' ) ? wp_kses_post( $request->get_param( 'description' ) ) : null,
			'first_introduced' => $request->get_param( 'first_introduced' ) ? sanitize_text_field( $request->get_param( 'first_introduced' ) ) : null,
			'start_date'       => $request->get_param( 'start_date' ),
			'end_date'         => $request->get_param( 'end_date' ),
			'created_by'       => get_current_user_id(),
		];

		if ( $can_manage ) {
			$data['initiated_by'] = $request->get_param( 'initiated_by' ) ?: 'st';
			$data['status']       = $request->get_param( 'status' ) ?: 'active';
			$data['st_notes']     = $request->get_param( 'st_notes' ) ? wp_kses_post( $request->get_param( 'st_notes' ) ) : null;
			// Category/goal fields are manager-only, same trust level as status/st_notes.
			$plot_category = $request->get_param( 'plot_category' );
			if ( $plot_category && ! in_array( $plot_category, Plot::PLOT_CATEGORIES, true ) ) {
				return $this->error( 'invalid_param', sprintf( __( 'plot_category must be one of: %s.', 'beyond-elysium' ), implode( ', ', Plot::PLOT_CATEGORIES ) ), 400 );
			}
			$data['plot_category'] = $plot_category ?: null;
			// image_id must reference a real media attachment, or it silently renders nothing.
			$image_id = $request->get_param( 'image_id' );
			if ( ! empty( $image_id ) && get_post_type( (int) $image_id ) !== 'attachment' ) {
				return $this->error( 'invalid_param', __( 'image_id must be a real media attachment.', 'beyond-elysium' ), 400 );
			}
			$data['image_id'] = ! empty( $image_id ) ? (int) $image_id : null;
			$data['cliffhanger']   = $request->get_param( 'cliffhanger' ) ? wp_kses_post( $request->get_param( 'cliffhanger' ) ) : null;
			$faction_goals         = $request->get_param( 'faction_goals' );
			$data['faction_goals'] = is_array( $faction_goals ) ? $faction_goals : null;
		} else {
			// initiated_by is always forced to 'player' regardless of what the request sends.
			$data['initiated_by'] = 'player';
		}

		$id = Plot::create( $data );
		if ( ! $id ) {
			return $this->error( 'create_failed', __( 'Failed to create plot.', 'beyond-elysium' ), 500 );
		}

		// Tags the new plot as a rumor using the same mechanism Rumor_Generator applies.
		if ( $can_manage && $request->get_param( 'is_rumor' ) ) {
			Rumor_Generator::tag_as_rumor( $id, (int) $game->id );
		}

		$plot = Plot::find( $id );
		$this->prepare_plot( $plot, $can_manage );
		return $this->success( $plot, 201 );
	}

	/**
	 * Updates an existing plot with any of the allowed fields present in
	 * the request. Requires be_manage_plots, so unlike create_item no
	 * per-capability field filtering is needed. Sanitizes rich-text and
	 * plain-text fields, and validates status, plot_category, image_id,
	 * initiated_by, and parent_plot_id when present.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function update_item( $request ) {
		$game = $this->resolve_game( $request['game_slug'] );
		if ( is_wp_error( $game ) ) {
			return $game;
		}

		$plot = Plot::find( (int) $request['id'] );
		if ( ! $plot || (int) $plot->game_id !== (int) $game->id ) {
			return $this->error( 'not_found', __( 'Plot not found in this game.', 'beyond-elysium' ), 404 );
		}

		$allowed = [
			'title', 'description', 'status', 'initiated_by', 'first_introduced',
			'start_date', 'end_date', 'resolution_details', 'resolution_impact',
			'target_query', 'st_notes', 'parent_plot_id', 'plot_category', 'faction_goals',
			'cliffhanger', 'image_id',
		];
		// Rich-text fields get wp_kses_post(); other free-text fields get plain-text sanitization.
		$rich_text_fields  = [ 'description', 'st_notes', 'cliffhanger', 'resolution_details', 'resolution_impact' ];
		$plain_text_fields = [ 'title', 'first_introduced' ];

		$data = [];
		foreach ( $allowed as $field ) {
			$value = $request->get_param( $field );
			if ( $value === null ) {
				continue;
			}
			if ( in_array( $field, $rich_text_fields, true ) ) {
				$data[ $field ] = is_string( $value ) ? wp_kses_post( $value ) : $value;
			} elseif ( in_array( $field, $plain_text_fields, true ) ) {
				$data[ $field ] = is_string( $value ) ? sanitize_text_field( $value ) : $value;
			} else {
				$data[ $field ] = $value;
			}
		}

		// Validated here so an invalid value returns a 400 rather than a generic failure.
		if ( isset( $data['status'] ) && ! in_array( $data['status'], Plot::STATUSES, true ) ) {
			return $this->error( 'invalid_param', sprintf( __( 'status must be one of: %s.', 'beyond-elysium' ), implode( ', ', Plot::STATUSES ) ), 400 );
		}
		if ( isset( $data['plot_category'] ) && ! in_array( $data['plot_category'], Plot::PLOT_CATEGORIES, true ) ) {
			return $this->error( 'invalid_param', sprintf( __( 'plot_category must be one of: %s.', 'beyond-elysium' ), implode( ', ', Plot::PLOT_CATEGORIES ) ), 400 );
		}
		if ( ! empty( $data['image_id'] ) && get_post_type( (int) $data['image_id'] ) !== 'attachment' ) {
			return $this->error( 'invalid_param', __( 'image_id must be a real media attachment.', 'beyond-elysium' ), 400 );
		}
		if ( isset( $data['initiated_by'] ) && ! in_array( $data['initiated_by'], Plot::INITIATORS, true ) ) {
			return $this->error( 'invalid_param', sprintf( __( 'initiated_by must be one of: %s.', 'beyond-elysium' ), implode( ', ', Plot::INITIATORS ) ), 400 );
		}
		if ( array_key_exists( 'parent_plot_id', $data ) && $data['parent_plot_id'] ) {
			$parent = Plot::find( (int) $data['parent_plot_id'] );
			if ( ! $parent || (int) $parent->game_id !== (int) $game->id ) {
				return $this->error( 'invalid_param', __( 'parent_plot_id must be a real plot in this game.', 'beyond-elysium' ), 400 );
			}
			if ( (int) $data['parent_plot_id'] === (int) $plot->id ) {
				return $this->error( 'invalid_param', __( 'A plot cannot be its own parent.', 'beyond-elysium' ), 400 );
			}
		}

		try {
			if ( ! Plot::update( (int) $plot->id, $data ) && ! empty( $data ) ) {
				return $this->error( 'update_failed', __( 'Failed to update plot.', 'beyond-elysium' ), 500 );
			}
		} catch ( \RuntimeException $e ) {
			return $this->error( 'invalid_param', $e->getMessage(), 400 );
		}

		$updated = Plot::find( (int) $plot->id );
		$this->prepare_plot( $updated, true );
		return $this->success( $updated );
	}

	/**
	 * Deletes a plot after confirming it exists and belongs to the
	 * requested game. Plot::delete() cascades the deletion to the
	 * plot's entries and connections.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function delete_item( $request ) {
		$game = $this->resolve_game( $request['game_slug'] );
		if ( is_wp_error( $game ) ) {
			return $game;
		}

		$plot = Plot::find( (int) $request['id'] );
		if ( ! $plot || (int) $plot->game_id !== (int) $game->id ) {
			return $this->error( 'not_found', __( 'Plot not found in this game.', 'beyond-elysium' ), 404 );
		}

		Plot::delete( (int) $plot->id );
		return $this->success( null, 204 );
	}

	/**
	 * Previews a character's action allocation for a game date, or, when
	 * commit is set, persists it as a plot. Validates parent_plot_id
	 * before either previewing or committing. Preview mode never writes
	 * to the database.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function allocate_actions( $request ) {
		$game = $this->resolve_game( $request['game_slug'] );
		if ( is_wp_error( $game ) ) {
			return $game;
		}

		$character_id = (int) $request->get_param( 'character_id' );
		$game_date    = (string) $request->get_param( 'game_date' );
		if ( ! $character_id || ! $game_date ) {
			return $this->error( 'invalid_param', __( 'character_id and game_date are required.', 'beyond-elysium' ), 400 );
		}

		$character = Character::find( $character_id );
		if ( ! $character || $character->owner_slug !== $request['game_slug'] ) {
			return $this->error( 'character_not_found', __( 'Character not found in this game.', 'beyond-elysium' ), 404 );
		}

		// parent_plot_id is validated here so a bad value never reaches persist().
		$parent_plot_id = $request->get_param( 'parent_plot_id' );
		if ( $parent_plot_id ) {
			$parent = Plot::find( (int) $parent_plot_id );
			if ( ! $parent || (int) $parent->game_id !== (int) $game->id ) {
				return $this->error( 'invalid_param', __( 'parent_plot_id must be a real plot in this game.', 'beyond-elysium' ), 400 );
			}
		}

		if ( $request->get_param( 'commit' ) ) {
			$plot_id = Action_Allocator::persist( $character_id, $game_date, $parent_plot_id ? (int) $parent_plot_id : null );
			return $this->success( [
				'plot_id'    => $plot_id,
				'subactions' => Action_Allocator::allocate( $character_id, $game_date ),
				'complete'   => Action_Allocator::is_complete( $plot_id ),
				'committed'  => true,
			], 201 );
		}

		return $this->success( [
			'subactions' => Action_Allocator::allocate( $character_id, $game_date ),
			'committed'  => false,
		] );
	}

	/**
	 * Generates (or, when commit is set, persists) the standard rumor
	 * set for a game date, with each rumor's recipient count resolved
	 * via the query engine. Preview mode never writes to the database
	 * and never sends mail - only a committed generation notifies each
	 * matched player once by email that a new rumor reaches one of their
	 * characters (APREngineClass::PrepareRecipients computed recipients
	 * and stopped one step short of delivering; the "Rumor delivery"
	 * idea in 0.99.X-Ideas.md). A player with several matching characters,
	 * or several new rumors in one pass, still gets one summary email.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function generate_rumors( $request ) {
		$game = $this->resolve_game( $request['game_slug'] );
		if ( is_wp_error( $game ) ) {
			return $game;
		}

		$game_date = (string) $request->get_param( 'game_date' );
		if ( ! $game_date ) {
			return $this->error( 'invalid_param', __( 'game_date is required.', 'beyond-elysium' ), 400 );
		}

		$commit = (bool) $request->get_param( 'commit' );
		$rumors = Rumor_Generator::generate( (int) $game->id, $game_date, $commit );

		foreach ( $rumors as &$rumor ) {
			$character_ids            = Query_Engine::resolve_target_query( $request['game_slug'], $rumor['target_query'] );
			$rumor['recipient_count'] = count( $character_ids );

			if ( $commit ) {
				foreach ( $character_ids as $character_id ) {
					$character  = Character::find( $character_id );
					$wp_user_id = (int) ( $character->wp_user_id ?? 0 );
					if ( $wp_user_id ) {
						Notifications::enqueue_rumor( $wp_user_id, $game, $rumor['title'] );
					}
				}
			}
		}
		unset( $rumor );

		if ( $commit ) {
			Notifications::flush_rumors();
		}

		return $this->success( [
			'rumors'    => $rumors,
			'committed' => $commit,
		], $commit ? 201 : 200 );
	}

	/**
	 * Strips st_notes from a plot for any viewer without be_manage_plots,
	 * attaches the plot's derived_status, and resolves its cover image
	 * id to a real URL at the given size. The single place this
	 * preparation happens for both list and single-item responses.
	 *
	 * @param object $plot
	 * @param bool   $can_manage
	 * @return void
	 */
	private function prepare_plot( $plot, bool $can_manage, string $image_size = 'medium' ): void {
		if ( ! $can_manage ) {
			unset( $plot->st_notes );
		}
		$plot->derived_status = Plot::derive_status( $plot );
		// Cover image is resolved server-side to a URL rather than stored.
		$plot->image_url = ! empty( $plot->image_id )
			? wp_get_attachment_image_url( (int) $plot->image_id, $image_size )
			: null;
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

	/**
	 * Defines the query parameters accepted by the plot collection
	 * endpoint: status/initiated_by/search/date_from/date_to filters,
	 * orderby/order sort controls, and page/per_page pagination.
	 *
	 * @return array
	 */
	public function get_collection_params(): array {
		return [
			'status'       => [
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			],
			'initiated_by' => [
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			],
			'search'       => [
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			],
			'date_from'    => [
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			],
			'date_to'      => [
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			],
			'orderby'      => [
				'type'    => 'string',
				'default' => 'updated_at',
				'enum'    => [ 'title', 'status', 'created_at', 'updated_at' ],
			],
			'order'        => [
				'type'    => 'string',
				'default' => 'DESC',
				'enum'    => [ 'ASC', 'DESC' ],
			],
			'page'         => [
				'type'    => 'integer',
				'default' => 1,
				'minimum' => 1,
			],
			'per_page'     => [
				'type'    => 'integer',
				'default' => 20,
				'minimum' => 1,
				'maximum' => 100,
			],
		];
	}
}
