<?php

namespace BeyondElysium\REST;

use BeyondElysium\Models\Creature_Stack;

defined( 'ABSPATH' ) || exit;

/**
 * REST controller for creature stacks.
 *
 * A creature stack is the named, reusable definition of what a character
 * sheet looks like for one creature type: which schema blocks it includes,
 * in what order, and what creation rules apply. Covers listing, single-stack
 * retrieval (optionally resolved against a game's customized blocks),
 * creation, update, and deletion.
 */
class Creature_Stacks_Controller extends Base_Controller {

	protected $rest_base = 'creature-stacks';

	/**
	 * Registers the creature stack routes.
	 *
	 * Adds the collection route for listing and creating stacks, plus a
	 * single-stack route for retrieval, update, and deletion by slug.
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
				'permission_callback' => $this->permission( 'be_manage_schemas' ),
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
				'permission_callback' => $this->permission( 'be_manage_schemas' ),
			],
			[
				'methods'             => 'DELETE',
				'callback'            => [ $this, 'delete_item' ],
				'permission_callback' => $this->permission( 'be_manage_schemas' ),
			],
		] );
	}

	/**
	 * Lists creature stacks with optional filters and pagination.
	 *
	 * Supports filtering by game line, system flag, and search text, with
	 * configurable sort order.
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

		$items = Creature_Stack::all( $args );
		$total = Creature_Stack::count( $args );

		$response = $this->success( $items );
		return $this->paginate( $response, $total, $pagination['per_page'], $pagination['page'] );
	}

	/**
	 * Retrieves a single creature stack by slug.
	 *
	 * With `?resolve=true`, also resolves and returns every schema block the
	 * stack references. An optional `game_slug` prefers that game's own
	 * customized blocks over the global catalog wherever it has forked them.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_item( $request ) {
		$slug = $request['slug'];

		if ( $request->get_param( 'resolve' ) ) {
			// game_slug is optional so the base catalog still resolves with no game context.
			$game_slug = (string) ( $request->get_param( 'game_slug' ) ?? '' );
			$resolved  = Creature_Stack::resolve( $slug, $game_slug );
			if ( ! $resolved ) {
				return $this->error( 'not_found', __( 'Creature stack not found.', 'beyond-elysium' ), 404 );
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
	 * Creates a new creature stack.
	 *
	 * Validates the required fields and the shape of `stack_definition`,
	 * rejects a duplicate slug, and creates the record with `is_system`
	 * always false since only the base catalog is a system stack.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function create_item( $request ) {
		$slug            = $request->get_param( 'slug' );
		$name            = $request->get_param( 'name' );
		$stack_definition = $request->get_param( 'stack_definition' );

		if ( empty( $slug ) ) {
			return $this->error( 'invalid_param', __( 'Missing required field: slug.', 'beyond-elysium' ), 400 );
		}
		if ( empty( $name ) ) {
			return $this->error( 'invalid_param', __( 'Missing required field: name.', 'beyond-elysium' ), 400 );
		}
		if ( empty( $stack_definition ) ) {
			return $this->error( 'invalid_param', __( 'Missing required field: stack_definition.', 'beyond-elysium' ), 400 );
		}

		$validation_error = $this->validate_stack_definition( $stack_definition );
		if ( $validation_error ) {
			return $validation_error;
		}

		if ( Creature_Stack::find_by_slug( sanitize_title( $slug ) ) ) {
			return $this->error( 'duplicate_slug', __( 'A creature stack with this slug already exists.', 'beyond-elysium' ), 409 );
		}

		$id = Creature_Stack::create( [
			'slug'             => $slug,
			'name'             => $name,
			'game_line'        => $request->get_param( 'game_line' ) ?: 'met',
			'stack_definition' => $stack_definition,
			'creation_rules'   => $request->get_param( 'creation_rules' ) ?: [],
			'is_system'        => 0,
		] );

		if ( ! $id ) {
			return $this->error( 'create_failed', __( 'Failed to create creature stack.', 'beyond-elysium' ), 500 );
		}

		return $this->success( Creature_Stack::find( $id ), 201 );
	}

	/**
	 * Updates an existing creature stack by slug.
	 *
	 * Writes only the fields present in the request, revalidating
	 * `stack_definition` if it is one of them.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function update_item( $request ) {
		$stack = Creature_Stack::find_by_slug( $request['slug'] );
		if ( ! $stack ) {
			return $this->error( 'not_found', __( 'Creature stack not found.', 'beyond-elysium' ), 404 );
		}

		$data = [];
		foreach ( [ 'name', 'game_line', 'stack_definition', 'creation_rules' ] as $field ) {
			$value = $request->get_param( $field );
			if ( $value !== null ) {
				$data[ $field ] = $value;
			}
		}

		if ( isset( $data['stack_definition'] ) ) {
			$validation_error = $this->validate_stack_definition( $data['stack_definition'] );
			if ( $validation_error ) {
				return $validation_error;
			}
		}

		Creature_Stack::update( $request['slug'], $data );
		return $this->success( Creature_Stack::find_by_slug( $request['slug'] ) );
	}

	/**
	 * Deletes a creature stack by slug.
	 *
	 * Looks up the stack, refuses to delete it if it is a system stack, and
	 * otherwise removes the record permanently.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function delete_item( $request ) {
		$stack = Creature_Stack::find_by_slug( $request['slug'] );
		if ( ! $stack ) {
			return $this->error( 'not_found', __( 'Creature stack not found.', 'beyond-elysium' ), 404 );
		}

		if ( ! empty( $stack->is_system ) ) {
			return $this->error( 'cannot_delete', __( 'Cannot delete a system creature stack.', 'beyond-elysium' ), 403 );
		}

		Creature_Stack::delete( $request['slug'] );
		return $this->success( null, 204 );
	}

	/**
	 * Defines the query parameters accepted by the collection endpoint.
	 *
	 * Covers game line, system flag, and search filtering, sort order, and
	 * pagination.
	 *
	 * @return array
	 */
	public function get_collection_params(): array {
		return [
			'game_line' => [
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
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
	 * Defines the parameters accepted by the create endpoint.
	 *
	 * Declares slug and name as required strings, game_line with a default,
	 * and the stack_definition and creation_rules objects.
	 *
	 * @return array
	 */
	private function get_create_params(): array {
		return [
			'slug' => [
				'type'              => 'string',
				'required'          => true,
				'sanitize_callback' => 'sanitize_title',
			],
			'name' => [
				'type'              => 'string',
				'required'          => true,
				'sanitize_callback' => 'sanitize_text_field',
			],
			'game_line' => [
				'type'              => 'string',
				'default'           => 'met',
				'sanitize_callback' => 'sanitize_text_field',
			],
			'stack_definition' => [
				'type'     => 'object',
				'required' => true,
			],
			'creation_rules' => [
				'type' => 'object',
			],
		];
	}

	/**
	 * Validates the structure of a stack_definition value.
	 *
	 * Accepts a JSON string, a decoded object, or a plain array, normalizing
	 * all three to an array before checking that it has a `sections` array
	 * whose entries each declare a `block_slug` and a `display_order`.
	 *
	 * @param mixed $definition
	 * @return \WP_Error|null
	 */
	private function validate_stack_definition( $definition ) {
		// A JSON request body decodes to a plain array, never a stdClass, here.
		if ( is_string( $definition ) ) {
			$definition = json_decode( $definition, true );
			if ( json_last_error() !== JSON_ERROR_NONE ) {
				return $this->error( 'invalid_json', __( 'stack_definition must be a valid JSON object.', 'beyond-elysium' ), 400 );
			}
		} elseif ( is_object( $definition ) ) {
			$definition = json_decode( wp_json_encode( $definition ), true );
		}

		if ( ! is_array( $definition ) || ! isset( $definition['sections'] ) || ! is_array( $definition['sections'] ) ) {
			return $this->error( 'invalid_param', __( 'stack_definition must contain a sections array.', 'beyond-elysium' ), 400 );
		}

		foreach ( $definition['sections'] as $i => $section ) {
			if ( empty( $section['block_slug'] ) ) {
				return $this->error( 'invalid_param', sprintf( __( 'sections[%d] must have a block_slug.', 'beyond-elysium' ), $i ), 400 );
			}
			if ( ! isset( $section['display_order'] ) ) {
				return $this->error( 'invalid_param', sprintf( __( 'sections[%d] must have a display_order.', 'beyond-elysium' ), $i ), 400 );
			}
		}

		return null;
	}
}
