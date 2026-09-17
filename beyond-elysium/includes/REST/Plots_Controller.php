<?php

namespace BeyondElysium\REST;

use BeyondElysium\Core\Authorization;
use BeyondElysium\Database\Transaction;
use BeyondElysium\Models\Attachment;
use BeyondElysium\Models\Character;
use BeyondElysium\Models\Connection;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Game_Member;
use BeyondElysium\Models\Plot;
use BeyondElysium\Models\Plot_Entry;
use BeyondElysium\Models\Release_Batch;
use BeyondElysium\Services\St_Visibility;
use BeyondElysium\Services\Action_Allocator;
use BeyondElysium\Services\Attachment_Storage;
use BeyondElysium\Services\Audience;
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
	 * Connection label marking a player plot's owning character (1.1.0 §2.3a) - distinct from
	 * `Action_Allocator::ACTOR_LABEL` so a player's own free-standing plot, created through
	 * `create_item()`, is never confused with an action-allocation record and never trips
	 * `Action_Allocator`'s own single-plot-per-character assumptions.
	 */
	const OWNER_LABEL = 'plot_owner';

	/**
	 * Registers the REST routes for the plot collection, a single plot,
	 * the player's cross-plot feed, the action-allocation and
	 * rumor-generation helper endpoints, and a player plot's membership
	 * (§2.3a). All routes are scoped to a game slug.
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

		// A player plot's membership (§2.3a). The outer gate is the same as create_item()'s -
		// anyone who could conceivably own a player plot - and the real "owner or Storyteller"
		// check runs inside each callback, since it depends on the specific plot in the URL.
		register_rest_route( $this->namespace, '/(?P<game_slug>[a-z0-9\-]+)/plots/(?P<id>\d+)/member-candidates', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_member_candidates' ],
				'permission_callback' => $this->permission_any( [ 'be_submit_actions', 'be_manage_plots' ] ),
			],
		] );

		register_rest_route( $this->namespace, '/(?P<game_slug>[a-z0-9\-]+)/plots/(?P<id>\d+)/members', [
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'add_member' ],
				'permission_callback' => $this->permission_any( [ 'be_submit_actions', 'be_manage_plots' ] ),
			],
		] );

		register_rest_route( $this->namespace, '/(?P<game_slug>[a-z0-9\-]+)/plots/(?P<id>\d+)/members/(?P<connection_id>\d+)', [
			[
				'methods'             => 'DELETE',
				'callback'            => [ $this, 'remove_member' ],
				'permission_callback' => $this->permission_any( [ 'be_submit_actions', 'be_manage_plots' ] ),
			],
		] );

		// The one place a Storyteller's "direct this post to specific characters" picker
		// (1.1.0 §2.4) can get a real answer - Audience::visible_character_ids() is otherwise
		// only ever called from inside Entries_Controller's own validation, with nothing
		// exposing it to a caller ahead of time. Manager-only: only a Storyteller may ever post
		// a `characters`-audience entry in the first place.
		register_rest_route( $this->namespace, '/(?P<game_slug>[a-z0-9\-]+)/plots/(?P<id>\d+)/visible-characters', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_visible_characters' ],
				'permission_callback' => $this->permission( 'be_manage_plots' ),
			],
		] );

		// A rumor's level texts (1.1.0 §3.4) - upserted as a set, not one at a time, so "3 of 5
		// levels written" and "delete a level by sending it empty" both have one obvious route.
		register_rest_route( $this->namespace, '/(?P<game_slug>[a-z0-9\-]+)/plots/(?P<id>\d+)/rumor-levels', [
			[
				'methods'             => 'PUT',
				'callback'            => [ $this, 'update_rumor_levels' ],
				'permission_callback' => $this->permission( 'be_manage_plots' ),
			],
		] );
	}

	/**
	 * Returns a paginated list of plots for a game, optionally filtered
	 * by status, initiated_by, search text, a date range, or whether a
	 * character is tied to the plot (`character_plots`), and ordered
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

		$can_manage = Authorization::can( 'be_manage_plots' );
		$pagination = $this->get_pagination( $request );
		$args       = [
			'status'          => $request->get_param( 'status' ),
			'initiated_by'    => $request->get_param( 'initiated_by' ),
			'search'          => $request->get_param( 'search' ),
			'date_from'       => $request->get_param( 'date_from' ),
			'date_to'         => $request->get_param( 'date_to' ),
			'character_plots' => $request->get_param( 'character_plots' ),
			'orderby'         => $request->get_param( 'orderby' ) ?: 'updated_at',
			'order'           => $request->get_param( 'order' ) ?: 'DESC',
		];
		// A non-manager never sees another character's action-allocation plot in the list -
		// its title alone already discloses who has one (§3.4/§5.8).
		if ( ! $can_manage ) {
			$args['exclude_actor_plots_not_owned_by'] = get_current_user_id();
		}

		if ( $can_manage ) {
			// A manager sees everything, so the existing SQL-level pagination is exact and
			// cheap - no reason to fetch more than one page.
			$args['per_page'] = $pagination['per_page'];
			$args['offset']   = $pagination['offset'];

			$items = Plot::for_game( (int) $game->id, $args );
			foreach ( $items as $item ) {
				$this->prepare_plot( $item, true, 'thumbnail', $game );
			}
			$total = Plot::count_for_game( (int) $game->id, $args );
		} else {
			// Audience is decided in PHP (a `restricted` plot's rules can reference character
			// sheet data no SQL WHERE clause here can see), so pagination has to happen after
			// filtering, not before. Fetching the whole matching set first and paginating in
			// PHP - real chronicles run to hundreds of plots, not tens of thousands - is the
			// difference between "the last page is short because that's really all there is"
			// and D38's own bug class: a page silently truncated to fewer than per_page rows
			// while more real, visible plots existed past the cut a SQL LIMIT already made.
			$all_matching = Plot::for_game( (int) $game->id, $args );
			$visible      = Audience::filter( $all_matching, 'plot', get_current_user_id(), $request['game_slug'], false );

			$total = count( $visible );
			$items = array_slice( $visible, $pagination['offset'], $pagination['per_page'] );
			foreach ( $items as $item ) {
				$this->prepare_plot( $item, false, 'thumbnail', $game );
			}
		}

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

		// Someone else's action allocation is not there for this viewer at all: its entries disclose
		// exact background ratings, and its title and actor link name the character - which is why
		// the list leaves it out (§3.4/§5.8, 1.0.0-review F-063).
		if ( ! $can_manage && self::is_unowned_allocation( (int) $plot->id ) ) {
			return $this->error( 'not_found', __( 'Plot not found in this game.', 'beyond-elysium' ), 404 );
		}
		// A plot's own audience (1.1.0 §2.1) - never found rather than a 403, matching the
		// non-disclosure choice the unowned-allocation check just above already made.
		if ( ! Audience::can_see( $plot, 'plot', get_current_user_id(), $request['game_slug'], $can_manage ) ) {
			return $this->error( 'not_found', __( 'Plot not found in this game.', 'beyond-elysium' ), 404 );
		}
		$this->prepare_plot( $plot, $can_manage, "medium", $game );

		$entries = Plot_Entry::for_plot( (int) $plot->id );
		if ( ! $can_manage ) {
			$wp_user_id    = get_current_user_id();
			$game_slug     = $request['game_slug'];
			$out_batch_ids = Release_Batch::out_ids( (int) $plot->game_id );
			$entries       = array_values( array_filter( $entries, static function ( $entry ) use ( $wp_user_id, $game_slug, $out_batch_ids, $plot ) {
				// Note entries are ST-only; excluded from the response, not just hidden
				// client-side. Every other entry follows its own audience (1.1.0 §2.4) - this
				// embedded copy must apply the identical check Entries_Controller::get_items()
				// does, since a caller reading this plot's own response never sees that route's
				// separate filtering at all.
				if ( $entry->entry_type === 'note' ) {
					return false;
				}
				return Audience::can_see_entry( $entry, $wp_user_id, $game_slug, false, $out_batch_ids, $plot );
			} ) );
		}

		$plot->entries     = $entries;
		$plot->connections = Connection::for_entity( 'plot', (int) $plot->id );
		$plot->attachments = array_map( [ Attachment::class, 'public_shape' ], Attachment::for_entity( 'plot', (int) $plot->id ) );
		// Immediate child plots are included and prepared the same way as the parent - an
		// allocation nested under a shared plot only for those who could open it, and only for
		// those its own audience reaches.
		$children = array_values( array_filter(
			Plot::children( (int) $plot->id ),
			static fn( $child ) => $can_manage || ! self::is_unowned_allocation( (int) $child->id )
		) );
		$plot->children = Audience::filter( $children, 'plot', get_current_user_id(), $request['game_slug'], $can_manage );
		foreach ( $plot->children as $child ) {
			$this->prepare_plot( $child, $can_manage, "thumbnail", $game );
		}

		return $this->success( $plot );
	}

	/**
	 * Whether a plot is an action allocation for a character the current user does
	 * not own AND is not an invited member of (§2.3a). A hard floor independent of
	 * the plot's own `audience` column - it hides a stranger's private downtime plot
	 * even from a row whose audience was somehow left `everyone` - but an owner's
	 * own explicit invitation still lets a co-narrator in, exactly as it would for
	 * any other player plot. Callers skip the check for a plot manager, who may see
	 * every allocation.
	 *
	 * @param int $plot_id
	 * @return bool
	 */
	private static function is_unowned_allocation( int $plot_id ): bool {
		if ( Action_Allocator::actor_character_id( $plot_id ) === null ) {
			return false;
		}
		$wp_user_id = get_current_user_id();
		return ! Action_Allocator::is_actor_owned_by( $plot_id, $wp_user_id )
			&& ! self::viewer_is_a_plot_member( $plot_id, $wp_user_id );
	}

	/**
	 * Whether one of `$wp_user_id`'s own characters holds a `plot_member` connection
	 * to this plot (§2.3a) - an invited co-narrator, never the owner.
	 *
	 * @param int $plot_id
	 * @param int $wp_user_id
	 * @return bool
	 */
	private static function viewer_is_a_plot_member( int $plot_id, int $wp_user_id ): bool {
		foreach ( Connection::for_source( 'plot', $plot_id ) as $connection ) {
			if ( $connection->target_type !== 'character' || $connection->label !== 'plot_member' ) {
				continue;
			}
			$character = Character::find( (int) $connection->target_id );
			if ( $character && (int) $character->wp_user_id === $wp_user_id ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The character a player plot belongs to, whichever of the two labels an owning
	 * connection carries - `Action_Allocator::ACTOR_LABEL` for every per-character
	 * allocation plot, `self::OWNER_LABEL` for one created through `create_item()`'s
	 * player branch. Null for a plot with neither (a global plot has no owner).
	 *
	 * @param int $plot_id
	 * @return int|null
	 */
	private static function owning_character_id( int $plot_id ): ?int {
		foreach ( Connection::for_source( 'plot', $plot_id ) as $connection ) {
			if ( $connection->target_type === 'character'
				&& in_array( $connection->label, [ Action_Allocator::ACTOR_LABEL, self::OWNER_LABEL ], true ) ) {
				return (int) $connection->target_id;
			}
		}
		return null;
	}

	/**
	 * Whether `$wp_user_id` owns a player plot - holds the character its owning
	 * connection targets (§2.3a). False for a global plot (no owning connection at all)
	 * and false for anyone but the one player who owns the character.
	 *
	 * @param int $plot_id
	 * @param int $wp_user_id
	 * @return bool
	 */
	private static function owns_plot( int $plot_id, int $wp_user_id ): bool {
		$character_id = self::owning_character_id( $plot_id );
		if ( $character_id === null ) {
			return false;
		}
		$character = Character::find( $character_id );
		return $character && (int) $character->wp_user_id === $wp_user_id;
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

		$can_manage = Authorization::can( 'be_manage_plots' );
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
		// A plot reachable through a connection or a target_query match is not automatically
		// visible - a plot connected to the player's own character for some in-fiction reason
		// can still be storytellers-only (1.1.0 §2.1).
		$plots = Audience::filter( $plots, 'plot', $wp_user_id, $request['game_slug'], $can_manage );
		usort( $plots, static fn( $a, $b ) => strcmp( $b->updated_at, $a->updated_at ) );
		foreach ( $plots as $plot ) {
			$this->prepare_plot( $plot, $can_manage, "medium", $game );
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
	 * A global plot (created by a manager) defaults to a `storytellers`
	 * audience (owner ruling, 1.1.0 §2.3): a draft never reaches players by
	 * accident. A player-created plot is always their own **player plot**
	 * (§2.3a) - `restricted` to its owning character, named via
	 * `character_id`, connected with the `plot_owner` label so `Audience`
	 * finds the owner through it. Only a Storyteller may set `everyone` or
	 * `audience_rules` on any plot; a player cannot widen their own.
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

		$can_manage = Authorization::can( 'be_manage_plots' );

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

		// Set inside the non-manager branch below; read again after Plot::create() succeeds,
		// to connect the new plot to its owner.
		$owner_character_id = null;

		// Read once, ahead of the audience derivation below - a rumor's own default audience
		// (1.1.0 §3.4 item 1) differs from an ordinary global plot's.
		$is_rumor = $can_manage && $request->get_param( 'is_rumor' );

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

			// A rumor's own target_query (1.1.0 §3.4) - unlike an ordinary plot, which has none
			// at creation, a hand-written rumor may already name one, deriving its audience below.
			$target_query = null;
			if ( $is_rumor ) {
				$target_query          = $request->get_param( 'target_query' ) ?: null;
				$data['target_query'] = $target_query;
			}

			$explicit_audience       = $request->get_param( 'audience' );
			$explicit_audience_rules = $request->get_param( 'audience_rules' );

			if ( $explicit_audience !== null ) {
				if ( ! in_array( $explicit_audience, Audience::VALUES, true ) ) {
					return $this->error( 'invalid_param', sprintf( __( 'audience must be one of: %s.', 'beyond-elysium' ), implode( ', ', Audience::VALUES ) ), 400 );
				}
				$data['audience'] = $explicit_audience;
			} elseif ( $is_rumor ) {
				// A rumor with a target_query is restricted to whoever it matches; Public
				// Knowledge (no target_query) reaches everyone (1.1.0 §3.4 item 1).
				$data['audience'] = $target_query ? Audience::RESTRICTED : Audience::EVERYONE;
			} else {
				// Audience is manager-only to set explicitly; a new global plot defaults to
				// storytellers-only rather than everyone (owner ruling, 1.1.0 §2.3).
				$data['audience'] = Audience::STORYTELLERS;
			}

			if ( $explicit_audience_rules !== null ) {
				if ( ! is_array( $explicit_audience_rules ) || empty( $explicit_audience_rules['conditions'] ) || ! is_array( $explicit_audience_rules['conditions'] ) ) {
					return $this->error( 'invalid_param', __( 'audience_rules must include a conditions array.', 'beyond-elysium' ), 400 );
				}
				$problem = Query_Engine::validate_conditions( $explicit_audience_rules['conditions'] );
				if ( $problem !== null ) {
					return $this->error( 'invalid_param', $problem['message'], 400 );
				}
				$data['audience_rules'] = $explicit_audience_rules;
			} elseif ( $is_rumor && $target_query ) {
				$problem = Query_Engine::validate_conditions( [ $target_query ] );
				if ( $problem !== null ) {
					return $this->error( 'invalid_param', $problem['message'], 400 );
				}
				$data['audience_rules'] = [ 'logic' => 'AND', 'conditions' => [ Query_Engine::target_query_to_condition( $target_query ) ] ];
			}
		} else {
			// initiated_by is always forced to 'player' regardless of what the request sends.
			$data['initiated_by'] = 'player';

			// A player plot (1.1.0 §2.3a): always restricted to its owner, never everyone or
			// rules - only a Storyteller may widen it afterward. character_id names the owner;
			// D33's own rule applies here too, so it must be a character this player owns.
			$character_id = (int) $request->get_param( 'character_id' );
			if ( ! $character_id ) {
				return $this->error( 'invalid_param', __( 'Missing required field: character_id.', 'beyond-elysium' ), 400 );
			}
			$character = Character::find( $character_id );
			if ( ! $character || $character->owner_slug !== $request['game_slug'] ) {
				return $this->error( 'character_not_found', __( 'Character not found in this game.', 'beyond-elysium' ), 404 );
			}
			if ( (int) $character->wp_user_id !== get_current_user_id() ) {
				return $this->error( 'ownership_denied', __( 'You may only create a plot for your own character.', 'beyond-elysium' ), 403 );
			}
			$data['audience']   = Audience::RESTRICTED;
			$owner_character_id = $character_id;
		}

		// A rumor is tagged the same way Rumor_Generator tags one, and a rumor whose tag didn't save
		// is not kept as an ordinary plot (1.0.0-review F-111). A player plot's own owner
		// connection is held to the identical standard - half of what was asked for is not
		// a plot the request can be considered to have succeeded at creating.
		$unit   = Transaction::begin( 'be_plot_create' );
		$id     = Plot::create( $data );
		$linked = true;
		if ( $id && $owner_character_id !== null ) {
			$linked = (bool) Connection::create( [
				'game_id'     => (int) $game->id,
				'source_type' => 'plot',
				'source_id'   => $id,
				'target_type' => 'character',
				'target_id'   => $owner_character_id,
				'label'       => self::OWNER_LABEL,
				'created_by'  => get_current_user_id(),
			] );
		}
		// Held from birth (1.1.0 §3.4 item 2): a rumor is always a draft until a release
		// batch takes it out, the same rule as a rumor Rumor_Generator itself creates.
		$held = ! $is_rumor || ( $id && Plot::update( $id, [ 'held' => true ] ) );
		if ( ! $id || ! $linked || ! $held || ( $is_rumor && ! Rumor_Generator::tag_as_rumor( $id, (int) $game->id ) ) ) {
			Transaction::rollback( $unit );
			return $this->error( 'create_failed', __( 'Failed to create plot.', 'beyond-elysium' ), 500 );
		}
		Transaction::commit( $unit );

		$plot = Plot::find( $id );
		if ( ! $plot ) {
			return $this->error( 'not_found', __( 'Plot not found in this game.', 'beyond-elysium' ), 404 );
		}
		$this->prepare_plot( $plot, $can_manage, "medium", $game );
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
			'cliffhanger', 'image_id', 'audience', 'audience_rules', 'assigned_to',
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
		// An assignee must be a real member of this chronicle holding a staff role (1.1.0 §3.6) -
		// never validated as a bare WordPress user id, which could name someone with no standing
		// in this chronicle at all.
		if ( array_key_exists( 'assigned_to', $data ) && $data['assigned_to'] ) {
			$assignee = Game_Member::find( (int) $game->id, (int) $data['assigned_to'] );
			if ( ! $assignee || ! in_array( $assignee->role, Game_Member::STAFF_ROLES, true ) ) {
				return $this->error( 'invalid_assignee', __( 'assigned_to must be a chronicle member with role hst, ast, or narrator.', 'beyond-elysium' ), 400 );
			}
		}
		// This route is already be_manage_plots-only, so a player's own plot can be widened
		// to everyone or given rules here, but never through any route a player can reach
		// (owner ruling, 1.1.0 §2.3a - only a Storyteller may widen a player plot).
		if ( isset( $data['audience'] ) && ! in_array( $data['audience'], Audience::VALUES, true ) ) {
			return $this->error( 'invalid_param', sprintf( __( 'audience must be one of: %s.', 'beyond-elysium' ), implode( ', ', Audience::VALUES ) ), 400 );
		}
		if ( array_key_exists( 'audience_rules', $data ) && $data['audience_rules'] !== null ) {
			if ( ! is_array( $data['audience_rules'] ) || empty( $data['audience_rules']['conditions'] ) || ! is_array( $data['audience_rules']['conditions'] ) ) {
				return $this->error( 'invalid_param', __( 'audience_rules must include a conditions array.', 'beyond-elysium' ), 400 );
			}
			$problem = Query_Engine::validate_conditions( $data['audience_rules']['conditions'] );
			if ( $problem !== null ) {
				return $this->error( 'invalid_param', $problem['message'], 400 );
			}
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

		// A rumor's audience is re-derived from its target_query when the request changes one
		// but not the other (1.1.0 §3.4 item 1) - an explicit audience/audience_rules in the
		// same request always wins, matching create_item()'s own precedence.
		if ( array_key_exists( 'target_query', $data ) && ! array_key_exists( 'audience_rules', $data )
			&& Rumor_Generator::is_rumor( (int) $plot->id ) ) {
			$target_query = $data['target_query'];
			if ( ! isset( $data['audience'] ) ) {
				$data['audience'] = $target_query ? Audience::RESTRICTED : Audience::EVERYONE;
			}
			if ( $target_query ) {
				$problem = Query_Engine::validate_conditions( [ $target_query ] );
				if ( $problem !== null ) {
					return $this->error( 'invalid_param', $problem['message'], 400 );
				}
				$data['audience_rules'] = [ 'logic' => 'AND', 'conditions' => [ Query_Engine::target_query_to_condition( $target_query ) ] ];
			} else {
				$data['audience_rules'] = null;
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
		if ( ! $updated ) {
			return $this->error( 'not_found', __( 'Plot not found in this game.', 'beyond-elysium' ), 404 );
		}
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

		// A character's own plot goes with the character, never on its own (owner, 2026-09-15).
		$actor = Action_Allocator::actor_character_id( (int) $plot->id );
		if ( $actor !== null && Character::plot_id( $actor ) === (int) $plot->id ) {
			return $this->error( 'character_plot', __( "A character's own plot is deleted with the character, not on its own.", 'beyond-elysium' ), 409 );
		}

		// Files first, while the rows naming them still exist: Plot::delete() removes the
		// attachment rows itself, but never the files, since that needs
		// Services\Attachment_Storage and Models does not depend on Services here.
		foreach ( Attachment::for_entity( 'plot', (int) $plot->id ) as $attachment ) {
			Attachment_Storage::delete( $attachment->stored_name, $attachment->original_name );
		}

		Plot::delete( (int) $plot->id );
		return $this->success( null, 204 );
	}

	/**
	 * Resolves the plot a membership route names, and reports whether the current
	 * caller may act on its membership at all - a Storyteller, or the plot's own
	 * owner (§2.3a). Shared by all three membership endpoints so each one applies
	 * the identical visibility-then-ownership order: a plot this viewer cannot see
	 * at all is reported not found, exactly as `get_item()` already does, before
	 * anything about ownership is revealed.
	 *
	 * @param \WP_REST_Request $request
	 * @return array{0:object,1:object,2:bool,3:bool}|\WP_Error [$game, $plot, $can_manage, $is_owner]
	 */
	private function resolve_plot_for_membership( $request ) {
		$game = $this->resolve_game( $request['game_slug'] );
		if ( is_wp_error( $game ) ) {
			return $game;
		}

		$plot = Plot::find( (int) $request['id'] );
		if ( ! $plot || (int) $plot->game_id !== (int) $game->id ) {
			return $this->error( 'not_found', __( 'Plot not found in this game.', 'beyond-elysium' ), 404 );
		}

		$can_manage = Authorization::check_request( 'be_manage_plots', $request );
		$wp_user_id = get_current_user_id();
		if ( ! $can_manage && ! Audience::can_see( $plot, 'plot', $wp_user_id, $request['game_slug'], false ) ) {
			return $this->error( 'not_found', __( 'Plot not found in this game.', 'beyond-elysium' ), 404 );
		}

		return [ $game, $plot, $can_manage, self::owns_plot( (int) $plot->id, $wp_user_id ) ];
	}

	/**
	 * Lists who may be invited into a player plot: active, non-NPC characters in this
	 * chronicle, name and id only (§2.3a) - a player can never otherwise list another
	 * character (D33), so this is the one narrow exception, and it stays narrow. Only
	 * the plot's own owner or a Storyteller may see it; already-connected characters
	 * (the owner, existing members) are left out, since inviting them again is not
	 * a real choice.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_member_candidates( $request ) {
		$resolved = $this->resolve_plot_for_membership( $request );
		if ( is_wp_error( $resolved ) ) {
			return $resolved;
		}
		[ $game, $plot, $can_manage, $is_owner ] = $resolved;
		if ( ! $can_manage && ! $is_owner ) {
			return $this->error( 'ownership_denied', __( 'Only the plot\'s owner or a Storyteller may invite characters to it.', 'beyond-elysium' ), 403 );
		}

		$connected_ids = array_map(
			static fn( $c ) => (int) $c->target_id,
			array_filter(
				Connection::for_source( 'plot', (int) $plot->id ),
				static fn( $c ) => $c->target_type === 'character'
			)
		);

		$candidates = Character::all_for_game( $game->slug, [ 'status' => 'active', 'is_npc' => 0 ] );
		$candidates = array_values( array_filter(
			$candidates,
			static fn( $c ) => ! in_array( (int) $c->id, $connected_ids, true )
		) );

		return $this->success( array_map(
			static fn( $c ) => [ 'id' => (int) $c->id, 'name' => $c->name ],
			$candidates
		) );
	}

	/**
	 * Every character who can currently see this plot - name and id only, the same narrow
	 * disclosure `get_member_candidates()` already uses - for the entry form's "direct this
	 * post to specific characters" picker (1.1.0 §2.4). Backs `Audience::visible_character_ids()`
	 * with a real route: that method was otherwise only ever called from inside
	 * `Entries_Controller`'s own validation, with nothing exposing the same answer to a caller
	 * ahead of time - naming a character outside this list still 400s there regardless, this
	 * route only lets the picker show the truth before the request is sent rather than after.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_visible_characters( $request ) {
		$game = $this->resolve_game( $request['game_slug'] );
		if ( is_wp_error( $game ) ) {
			return $game;
		}
		$plot = Plot::find( (int) $request['id'] );
		if ( ! $plot || (int) $plot->game_id !== (int) $game->id ) {
			return $this->error( 'not_found', __( 'Plot not found in this game.', 'beyond-elysium' ), 404 );
		}

		$characters = array_filter( array_map(
			static fn( $id ) => Character::find( $id ),
			Audience::visible_character_ids( $plot, 'plot', $request['game_slug'] )
		) );

		return $this->success( array_map(
			static fn( $c ) => [ 'id' => (int) $c->id, 'name' => $c->name ],
			array_values( $characters )
		) );
	}

	/**
	 * Upserts a rumor's level texts as a set (1.1.0 §3.4): `{levels: {"1": "text", ...}}`,
	 * keys 1-10. A level sent with empty text is deleted rather than left as an empty row -
	 * "3 of 5 levels written" counts real text, not placeholder rows. Not restricted to a
	 * plot actually tagged as a rumor: `rumor_level_key`/`rumor_level_match` (set only by
	 * `Rumor_Generator` on an influence rumor, or by hand via `update_item()`) are what makes
	 * a level text visible at all - `Audience::can_see_entry()` hides every `rumor_level`
	 * entry on a plot that carries neither, so writing one on an ordinary plot is inert, not
	 * unsafe, and needs no extra gate here.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function update_rumor_levels( $request ) {
		$game = $this->resolve_game( $request['game_slug'] );
		if ( is_wp_error( $game ) ) {
			return $game;
		}
		$plot = Plot::find( (int) $request['id'] );
		if ( ! $plot || (int) $plot->game_id !== (int) $game->id ) {
			return $this->error( 'not_found', __( 'Plot not found in this game.', 'beyond-elysium' ), 404 );
		}

		$levels = $request->get_param( 'levels' );
		if ( ! is_array( $levels ) || empty( $levels ) ) {
			return $this->error( 'invalid_param', __( 'levels must be an object of level number to text.', 'beyond-elysium' ), 400 );
		}

		$existing = [];
		foreach ( Plot_Entry::for_plot( (int) $plot->id, [ 'entry_type' => 'rumor_level' ] ) as $entry ) {
			if ( $entry->level !== null ) {
				$existing[ (int) $entry->level ] = $entry;
			}
		}

		foreach ( $levels as $level => $text ) {
			$level = (int) $level;
			if ( $level < 1 || $level > 10 ) {
				return $this->error( 'invalid_param', __( 'Each level key must be 1-10.', 'beyond-elysium' ), 400 );
			}
			$text = is_string( $text ) ? wp_kses_post( $text ) : '';

			if ( $text === '' ) {
				if ( isset( $existing[ $level ] ) ) {
					Plot_Entry::delete( (int) $existing[ $level ]->id );
				}
				continue;
			}

			if ( isset( $existing[ $level ] ) ) {
				Plot_Entry::update( (int) $existing[ $level ]->id, [ 'content' => $text ] );
			} else {
				Plot_Entry::create( [
					'plot_id'    => (int) $plot->id,
					'author_id'  => get_current_user_id(),
					'entry_type' => 'rumor_level',
					'content'    => $text,
					'level'      => $level,
				] );
			}
		}

		return $this->success( Plot_Entry::for_plot( (int) $plot->id, [ 'entry_type' => 'rumor_level' ] ) );
	}

	/**
	 * Adds a character to a player plot as a `plot_member` connection (§2.3a) - the
	 * owner's own connection carries a different label
	 * (`Action_Allocator::ACTOR_LABEL`/`self::OWNER_LABEL`) precisely so this route can
	 * never touch it: the owner cannot be added again, and by the same structural
	 * fact, `remove_member()` can never remove them either. A Storyteller may add any
	 * character; the owning player is held to the picker's own narrowing
	 * (active, non-NPC) even when they bypass the picker and post a character_id
	 * directly - the same "don't trust a privileged filter from a non-manager" rule
	 * D33 already established elsewhere.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function add_member( $request ) {
		$resolved = $this->resolve_plot_for_membership( $request );
		if ( is_wp_error( $resolved ) ) {
			return $resolved;
		}
		[ $game, $plot, $can_manage, $is_owner ] = $resolved;
		if ( ! $can_manage && ! $is_owner ) {
			return $this->error( 'ownership_denied', __( 'Only the plot\'s owner or a Storyteller may invite characters to it.', 'beyond-elysium' ), 403 );
		}

		$character_id = (int) $request->get_param( 'character_id' );
		if ( ! $character_id ) {
			return $this->error( 'invalid_param', __( 'Missing required field: character_id.', 'beyond-elysium' ), 400 );
		}
		$character = Character::find( $character_id );
		if ( ! $character || $character->owner_slug !== $game->slug ) {
			return $this->error( 'character_not_found', __( 'Character not found in this game.', 'beyond-elysium' ), 404 );
		}
		if ( $character_id === self::owning_character_id( (int) $plot->id ) ) {
			return $this->error( 'already_owner', __( 'This character already owns the plot.', 'beyond-elysium' ), 400 );
		}
		if ( ! $can_manage && ( $character->status !== 'active' || (int) $character->is_npc !== 0 ) ) {
			return $this->error( 'invalid_candidate', __( 'You may only invite an active, non-NPC character.', 'beyond-elysium' ), 400 );
		}

		$connection_id = Connection::create( [
			'game_id'     => (int) $game->id,
			'source_type' => 'plot',
			'source_id'   => (int) $plot->id,
			'target_type' => 'character',
			'target_id'   => $character_id,
			'label'       => 'plot_member',
			'created_by'  => get_current_user_id(),
		] );
		if ( ! $connection_id ) {
			return $this->error( 'create_failed', __( 'Failed to add this character to the plot.', 'beyond-elysium' ), 500 );
		}

		return $this->success( Connection::find( (int) $connection_id ), 201 );
	}

	/**
	 * Removes a character from a player plot's membership (§2.3a). Scoped to
	 * `plot_member` connections only - a mismatched label means either the id names
	 * some other connection entirely or, structurally, the owner's own connection,
	 * and either way this route reports it not found rather than touching it. A
	 * Storyteller may remove any member; the owning player may remove only a member
	 * they themselves added (`created_by`), never one a Storyteller added, and never
	 * the owner - which is never reachable here at all.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function remove_member( $request ) {
		$resolved = $this->resolve_plot_for_membership( $request );
		if ( is_wp_error( $resolved ) ) {
			return $resolved;
		}
		[ , $plot, $can_manage, $is_owner ] = $resolved;

		$connection = Connection::find( (int) $request['connection_id'] );
		if ( ! $connection
			|| $connection->source_type !== 'plot'
			|| (int) $connection->source_id !== (int) $plot->id
			|| $connection->label !== 'plot_member' ) {
			return $this->error( 'member_not_found', __( 'This character is not a member of this plot.', 'beyond-elysium' ), 404 );
		}

		if ( ! $can_manage ) {
			if ( ! $is_owner ) {
				return $this->error( 'ownership_denied', __( 'Only the plot\'s owner or a Storyteller may remove a member.', 'beyond-elysium' ), 403 );
			}
			if ( (int) $connection->created_by !== get_current_user_id() ) {
				return $this->error( 'not_your_addition', __( 'You may only remove a character you added yourself.', 'beyond-elysium' ), 403 );
			}
		}

		Connection::delete( (int) $connection->id );
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
			if ( $plot_id === 0 ) {
				return $this->error( 'allocation_failed', __( 'The actions could not be saved. Nothing was changed.', 'beyond-elysium' ), 500 );
			}
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
	 * via the query engine. Every generated rumor is created held, with
	 * no release batch - a draft, invisible to every non-manager until a
	 * Storyteller releases it in a batch (1.1.0 §3.4 items 2-3). Generation
	 * itself never sends mail: the old "a rumor reached you" email at
	 * generation time named a rumor whose text was still empty, and
	 * duplicated whatever a later batch release sends anyway once the
	 * Storyteller has actually written it (`Release_Engine::release()`).
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
		if ( is_wp_error( $rumors ) ) {
			return $rumors;
		}

		foreach ( $rumors as &$rumor ) {
			$character_ids            = Query_Engine::resolve_target_query( $request['game_slug'], $rumor['target_query'] );
			$rumor['recipient_count'] = count( $character_ids );
		}
		unset( $rumor );

		return $this->success( [
			'rumors'    => $rumors,
			'committed' => $commit,
		], $commit ? 201 : 200 );
	}

	/**
	 * Strips st_notes from a plot for any viewer without be_manage_plots,
	 * attaches the plot's derived_status, resolves its cover image id to
	 * a real URL at the given size, and reports whether the current
	 * viewer owns it (§2.3a) - the one signal the client has no other way
	 * to derive, and the exact test `may_manage_attachments()` itself
	 * uses, so a client gating its own upload/delete controls on it never
	 * shows a control the server would then 403. The single place this
	 * preparation happens for both list and single-item responses.
	 *
	 * @param object $plot
	 * @param bool   $can_manage
	 * @return void
	 */
	private function prepare_plot( $plot, bool $can_manage, string $image_size = "medium", ?object $game = null ): void {
		if ( ! $can_manage ) {
			unset( $plot->st_notes );
		}
		// description and cliffhanger are ordinary rich text a Storyteller may mark with
		// [ST]; st_notes above is Storyteller-only in full, so it is removed, not stripped.
		St_Visibility::filter_plot( $plot, $game, $can_manage );
		$plot->derived_status = Plot::derive_status( $plot );
		// Cover image is resolved server-side to a URL rather than stored.
		$plot->image_url = ! empty( $plot->image_id )
			? wp_get_attachment_image_url( (int) $plot->image_id, $image_size )
			: null;
		$plot->is_owner = self::owns_plot( (int) $plot->id, get_current_user_id() );
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
	 * endpoint: status/initiated_by/search/date_from/date_to/character_plots filters,
	 * orderby/order sort controls, and page/per_page pagination.
	 *
	 * @return array
	 */
	public function get_collection_params(): array {
		return [
			'status'          => [
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			],
			'initiated_by'    => [
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			],
			'search'          => [
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			],
			'date_from'       => [
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			],
			'date_to'         => [
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			],
			// `only`: each character's own plot and its action rounds; `exclude`: every other plot.
			'character_plots' => [
				'type' => 'string',
				'enum' => [ 'only', 'exclude' ],
			],
			'orderby'         => [
				'type'    => 'string',
				'default' => 'updated_at',
				'enum'    => [ 'title', 'status', 'created_at', 'updated_at' ],
			],
			'order'           => [
				'type'    => 'string',
				'default' => 'DESC',
				'enum'    => [ 'ASC', 'DESC' ],
			],
			'page'            => [
				'type'    => 'integer',
				'default' => 1,
				'minimum' => 1,
			],
			'per_page'        => [
				'type'    => 'integer',
				'default' => 20,
				'minimum' => 1,
				'maximum' => 100,
			],
		];
	}
}
