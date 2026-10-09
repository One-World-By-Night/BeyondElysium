<?php

namespace BeyondElysium\REST;

use BeyondElysium\Models\Character;
use BeyondElysium\Models\Creature_Stack;
use BeyondElysium\Models\Schema_Block;

defined( 'ABSPATH' ) || exit;

/**
 * REST controller for creature stacks.
 */
class Creature_Stacks_Controller extends Base_Controller {

	protected $rest_base = 'creature-stacks';

	/**
	 * Registers the creature stack routes.
	 */
	public function register_routes(): void {
		register_rest_route( $this->namespace, '/' . $this->rest_base, [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_items' ],
				'permission_callback' => $this->permission( 'be_view_characters' ),
				'args'                => $this->get_collection_params(),
			],
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'create_item' ],
				'permission_callback' => $this->permission( 'be_manage_games' ),
				'args'                => $this->get_create_params(),
			],
		] );

		register_rest_route( $this->namespace, '/' . $this->rest_base . '/(?P<slug>[a-z0-9\-_]+)', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_item' ],
				'permission_callback' => $this->permission( 'be_view_characters' ),
				'args'                => [
					'resolve' => [
						'type'    => 'boolean',
						'default' => false,
					],
				],
			],
			[
				'methods'             => 'PUT',
				'callback'            => [ $this, 'update_item' ],
				'permission_callback' => $this->permission( 'be_manage_games' ),
			],
			[
				'methods'             => 'DELETE',
				'callback'            => [ $this, 'delete_item' ],
				'permission_callback' => $this->permission( 'be_manage_games' ),
			],
		] );

		// A chronicle's own creature type, built from nothing rather than a layer over one the book declares.
		register_rest_route( $this->namespace, '/(?P<game_slug>[a-z0-9\-]+)/' . $this->rest_base, [
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'create_item' ],
				'permission_callback' => $this->permission( 'be_manage_schemas' ),
				'args'                => $this->get_create_params(),
			],
		] );

		// Game-scoped write routes, over a chronicle's own layer of a book creature type.
		register_rest_route( $this->namespace, '/(?P<game_slug>[a-z0-9\-]+)/' . $this->rest_base . '/(?P<slug>[a-z0-9\-_]+)', [
			[
				'methods'             => 'PUT',
				'callback'            => [ $this, 'update_item' ],
				'permission_callback' => $this->permission( 'be_manage_schemas' ),
			],
			[
				'methods'             => 'DELETE',
				'callback'            => [ $this, 'delete_item' ],
				'permission_callback' => $this->permission( 'be_manage_schemas' ),
			],
		] );

		register_rest_route( $this->namespace, '/(?P<game_slug>[a-z0-9\-]+)/' . $this->rest_base . '/(?P<slug>[a-z0-9\-_]+)/sections', [
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'add_section' ],
				'permission_callback' => $this->permission( 'be_manage_schemas' ),
			],
		] );

		register_rest_route( $this->namespace, '/(?P<game_slug>[a-z0-9\-]+)/' . $this->rest_base . '/(?P<slug>[a-z0-9\-_]+)/sections/(?P<block_slug>[a-z0-9\-_]+)', [
			[
				'methods'             => 'PUT',
				'callback'            => [ $this, 'update_section' ],
				'permission_callback' => $this->permission( 'be_manage_schemas' ),
			],
			[
				'methods'             => 'DELETE',
				'callback'            => [ $this, 'remove_section' ],
				'permission_callback' => $this->permission( 'be_manage_schemas' ),
			],
		] );
	}

	/**
	 * Lists creature stacks with optional filters and pagination.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response
	 */
	public function get_items( $request ) {
		$pagination = $this->get_pagination( $request );
		$args = [
			'game_line' => $request->get_param( 'game_line' ),
			'is_system' => $request->get_param( 'is_system' ),
			'search'    => $request->get_param( 'search' ),
			'orderby'   => $request->get_param( 'orderby' ) ?: 'name',
			'order'     => $request->get_param( 'order' ) ?: 'ASC',
			'per_page'  => $pagination['per_page'],
			'offset'    => $pagination['offset'],
		];

		// Optional; when omitted, every stack is offered, unchanged.
		$game_slug        = (string) ( $request->get_param( 'game_slug' ) ?? '' );
		$include_disabled = (bool) $request->get_param( 'include_disabled' );
		$items = $game_slug !== ''
			? Creature_Stack::all_for_game( $game_slug, $args, $include_disabled, \BeyondElysium\Core\Authorization::can( 'be_manage_characters' ) )
			: Creature_Stack::all( $args );
		$total = $game_slug !== '' ? count( $items ) : Creature_Stack::count( $args );

		$response = $this->success( $items );
		return $this->paginate( $response, $total, $pagination['per_page'], $pagination['page'] );
	}

	/**
	 * Retrieves a single creature stack by slug.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_item( $request ) {
		$slug = $request['slug'];

		if ( $request->get_param( 'resolve' ) ) {
			$game_slug = (string) ( $request->get_param( 'game_slug' ) ?? '' );
			$resolved  = Creature_Stack::resolve( $slug, $game_slug, $this->blocks_held_by( (int) $request->get_param( 'character_id' ), $game_slug ) );
			if ( ! $resolved ) {
				return $this->error( 'not_found', __( 'Creature stack not found.', 'beyond-elysium' ), 404 );
			}
			// Narrowing to the chronicle's enabled stacks is opt-in.
			if ( $request->get_param( 'for_creation' ) ) {
				$resolved = Creature_Stack::narrow_for_creation( $resolved, $slug, $game_slug );
			}
			return $this->success( $resolved );
		}

		$stack = Creature_Stack::find_by_slug( $slug );
		if ( ! $stack ) {
			return $this->error( 'not_found', __( 'Creature stack not found.', 'beyond-elysium' ), 404 );
		}
		return $this->success( $stack );
	}

	/**
	 * The block slugs a character holds, for a caller allowed to see that character: a Storyteller, or its own player.
	 *
	 * @param int    $character_id
	 * @param string $game_slug
	 * @return string[]
	 */
	private function blocks_held_by( int $character_id, string $game_slug ): array {
		if ( $character_id <= 0 ) {
			return [];
		}
		$character = \BeyondElysium\Models\Character::find( $character_id );
		if ( ! $character || $character->owner_slug !== $game_slug ) {
			return [];
		}
		$may_see = \BeyondElysium\Core\Authorization::can( 'be_manage_characters' )
			|| (int) $character->wp_user_id === get_current_user_id();
		return $may_see && is_array( $character->sheet_data ) ? array_map( 'strval', array_keys( $character->sheet_data ) ) : [];
	}

	/**
	 * Creates a chronicle's own brand-new creature type from a slug, name, and at least one section. The book's own
	 * creature types are read-only; this only ever reaches the chronicle-scoped route.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function create_item( $request ) {
		$game_slug = $this->write_scope( $request );
		if ( is_wp_error( $game_slug ) ) {
			return $game_slug;
		}

		$slug      = (string) $request->get_param( 'slug' );
		$name      = (string) $request->get_param( 'name' );
		$game_line = (string) ( $request->get_param( 'game_line' ) ?: 'met' );
		$sections  = $request->get_param( 'sections' );

		if ( $slug === '' ) {
			return $this->error( 'invalid_param', __( 'Missing required field: slug.', 'beyond-elysium' ), 400 );
		}
		if ( $name === '' ) {
			return $this->error( 'invalid_param', __( 'Missing required field: name.', 'beyond-elysium' ), 400 );
		}
		if ( ! is_array( $sections ) || $sections === [] ) {
			return $this->error( 'invalid_param', __( 'At least one section is required.', 'beyond-elysium' ), 400 );
		}

		$slug = sanitize_title( $slug );
		if ( Creature_Stack::slug_in_use( $slug ) ) {
			return $this->error( 'duplicate_slug', __( 'A creature stack with this slug already exists.', 'beyond-elysium' ), 409 );
		}

		$clean_sections = [];
		foreach ( array_values( $sections ) as $i => $section ) {
			$section    = (array) $section;
			$block_slug = (string) ( $section['block_slug'] ?? '' );
			if ( $block_slug === '' ) {
				return $this->error( 'invalid_param', sprintf( __( 'Section %d is missing block_slug.', 'beyond-elysium' ), $i + 1 ), 400 );
			}
			if ( ! Schema_Block::find_for_game( $block_slug, $game_slug ) ) {
				return $this->error( 'unknown_block', sprintf( __( 'Unknown block_slug: %s.', 'beyond-elysium' ), $block_slug ), 400 );
			}
			$clean_sections[] = [
				'block_slug'    => $block_slug,
				'label'         => (string) ( $section['label'] ?? '' ) ?: $block_slug,
				'display_order' => (int) ( $section['display_order'] ?? ( $i + 1 ) ),
				'required'      => (bool) ( $section['required'] ?? false ),
			];
		}

		$id = Creature_Stack::create_for_game( $game_slug, [
			'slug'             => $slug,
			'name'             => $name,
			'game_line'        => $game_line,
			'stack_definition' => [ 'sections' => $clean_sections, 'display_preferences' => new \stdClass() ],
			'creation_rules'   => $request->get_param( 'creation_rules' ),
		] );

		if ( ! $id ) {
			return $this->error( 'create_failed', __( 'Failed to create creature stack.', 'beyond-elysium' ), 500 );
		}

		return $this->success( Creature_Stack::find( $id ), 201 );
	}

	/**
	 * Saves a chronicle's own layer over one of the book's creature types: its `creation_rules`, `game_line` and
	 * `display_preferences`. Refused for the bare, book route.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function update_item( $request ) {
		$game_slug = $this->write_scope( $request );
		if ( is_wp_error( $game_slug ) ) {
			return $game_slug;
		}
		if ( ! Creature_Stack::find_for_game( $request['slug'], $game_slug ) ) {
			return $this->error( 'not_found', __( 'Creature stack not found.', 'beyond-elysium' ), 404 );
		}

		$data = [];
		foreach ( [ 'stack_definition', 'creation_rules' ] as $field ) {
			$value = $request->get_param( $field );
			if ( $value !== null ) {
				$data[ $field ] = $value;
			}
		}

		if ( ! empty( $data ) && ! Creature_Stack::update_for_game( $request['slug'], $game_slug, $data ) ) {
			return $this->error( 'save_failed', __( 'Failed to update creature stack.', 'beyond-elysium' ), 500 );
		}
		return $this->success( Creature_Stack::find_for_game( $request['slug'], $game_slug ) );
	}

	/**
	 * Removes a chronicle's own layer, so it reads as the book's creature type again. Refused for the bare, book
	 * route.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function delete_item( $request ) {
		$game_slug = $this->write_scope( $request );
		if ( is_wp_error( $game_slug ) ) {
			return $game_slug;
		}
		// A layer falls back to the book on delete, but a chronicle's own creature type has no fallback at all.
		if ( ! Creature_Stack::find_by_slug( $request['slug'] ) ) {
			$count = Character::count_for_stack( $request['slug'] );
			if ( $count > 0 ) {
				return new \WP_Error(
					'creature_stack_in_use',
					__( 'This creature type is still held by at least one character.', 'beyond-elysium' ),
					[ 'status' => 409, 'count' => $count ]
				);
			}
		}
		Creature_Stack::delete_for_game( $request['slug'], $game_slug );
		return $this->success( null, 204 );
	}

	/**
	 * Adds a section for a block the chronicle can read to its own layer of a creature type.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function add_section( $request ) {
		$game_slug = $this->write_scope( $request );
		if ( is_wp_error( $game_slug ) ) {
			return $game_slug;
		}
		$block_slug = (string) $request->get_param( 'block_slug' );
		if ( $block_slug === '' ) {
			return $this->error( 'invalid_param', __( 'block_slug is required.', 'beyond-elysium' ), 400 );
		}
		$added = Creature_Stack::add_section( $request['slug'], $game_slug, $block_slug, (string) ( $request->get_param( 'label' ) ?? '' ) );
		if ( ! $added ) {
			return $this->error( 'add_failed', __( 'The block is unknown to this chronicle, or already on this creature type.', 'beyond-elysium' ), 400 );
		}
		return $this->success( Creature_Stack::find_for_game( $request['slug'], $game_slug ), 201 );
	}

	/**
	 * Hides or shows one section of a chronicle's own layer of a creature type.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function update_section( $request ) {
		$game_slug = $this->write_scope( $request );
		if ( is_wp_error( $game_slug ) ) {
			return $game_slug;
		}
		$hidden  = (bool) $request->get_param( 'hidden' );
		$changed = $hidden
			? Creature_Stack::hide_section( $request['slug'], $game_slug, $request['block_slug'] )
			: Creature_Stack::show_section( $request['slug'], $game_slug, $request['block_slug'] );
		if ( ! $changed ) {
			return $this->error( 'not_found', __( 'That section is not on this creature type.', 'beyond-elysium' ), 404 );
		}
		return $this->success( Creature_Stack::find_for_game( $request['slug'], $game_slug ) );
	}

	/**
	 * Removes a section a chronicle added to its own layer. A book section can be hidden, never removed.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function remove_section( $request ) {
		$game_slug = $this->write_scope( $request );
		if ( is_wp_error( $game_slug ) ) {
			return $game_slug;
		}
		$book = Creature_Stack::find_by_slug( $request['slug'] );
		if ( $book ) {
			$book_sections = (array) ( ( json_decode( (string) wp_json_encode( $book->stack_definition ), true ) )['sections'] ?? [] );
			if ( in_array( $request['block_slug'], array_column( $book_sections, 'block_slug' ), true ) ) {
				return $this->error( 'book_section', __( 'A section the book declares can be hidden, but not removed.', 'beyond-elysium' ), 400 );
			}
		}
		if ( ! Creature_Stack::remove_section( $request['slug'], $game_slug, $request['block_slug'] ) ) {
			return $this->error( 'not_found', __( 'That section is not on this creature type.', 'beyond-elysium' ), 404 );
		}
		return $this->success( Creature_Stack::find_for_game( $request['slug'], $game_slug ) );
	}

	/**
	 * Returns the chronicle a request addresses: the `game_slug` in the route's own URL, or '' for the book.
	 *
	 * @param \WP_REST_Request $request
	 * @return string
	 */
	private function scope( \WP_REST_Request $request ): string {
		return (string) ( $request->get_url_params()['game_slug'] ?? '' );
	}

	/**
	 * Returns the chronicle a write targets. A write without one would change the book, which is read-only.
	 *
	 * @param \WP_REST_Request $request
	 * @return string|\WP_Error
	 */
	private function write_scope( \WP_REST_Request $request ) {
		$scope = $this->scope( $request );
		return $scope === '' ? $this->book_read_only() : $scope;
	}

	/**
	 * Defines the query parameters accepted by the collection endpoint.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public function get_collection_params(): array {
		return [
			'game_line' => [
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			],
			'game_slug' => [
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_title',
			],
			'is_system' => [
				'type' => 'integer',
				'enum' => [ 0, 1 ],
			],
			'search' => [
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			],
			'orderby' => [
				'type'    => 'string',
				'default' => 'name',
				'enum'    => [ 'name', 'slug', 'game_line', 'created_at' ],
			],
			'order' => [
				'type'    => 'string',
				'default' => 'ASC',
				'enum'    => [ 'ASC', 'DESC' ],
			],
			'page' => [
				'type'    => 'integer',
				'default' => 1,
				'minimum' => 1,
			],
			'per_page' => [
				'type'    => 'integer',
				'default' => 20,
				'minimum' => 1,
				'maximum' => 100,
			],
		];
	}

	/**
	 * Defines the request parameters accepted when creating a chronicle's own creature type: the required slug, name,
	 * and sections, plus an optional game_line and creation_rules.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private function get_create_params(): array {
		return [
			'slug' => [
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_title',
			],
			'name' => [
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			],
			'game_line' => [
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			],
			'sections' => [
				'type' => 'array',
			],
			'creation_rules' => [
				'type' => 'object',
			],
		];
	}
}
