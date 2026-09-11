<?php

namespace BeyondElysium\REST;

use BeyondElysium\Models\Schema_Block;

defined( 'ABSPATH' ) || exit;

/**
 * REST controller for schema blocks, the reusable definitions (trait lists,
 * tiered powers, resource pools, identity fields) that character sheets are
 * built from. Supports listing, retrieving, creating, updating, and deleting
 * blocks, plus an optional per-game fork of a global block.
 */
class Schema_Blocks_Controller extends Base_Controller {

	protected $rest_base = 'schema-blocks';

	/**
	 * Registers the REST routes for the schema block collection and for a
	 * single block by slug. Wires up GET/POST on the collection endpoint
	 * and GET/PUT/DELETE on the single-block endpoint.
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
	 * Returns a paginated list of schema blocks, optionally filtered by
	 * section_type, is_system, or a text search, and ordered by the
	 * requested column and direction. When a game_slug is given,
	 * substitutes that game's own fork in place of the global block.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response
	 */
	public function get_items( $request ) {
		$pagination = $this->get_pagination( $request );
		$args = [
			'section_type' => $request->get_param( 'section_type' ),
			'is_system'    => $request->get_param( 'is_system' ),
			'search'       => $request->get_param( 'search' ),
			'orderby'      => $request->get_param( 'orderby' ) ?: 'name',
			'order'        => $request->get_param( 'order' ) ?: 'ASC',
			'per_page'     => $pagination['per_page'],
			'offset'       => $pagination['offset'],
		];

		// Optional; when omitted, only global blocks are returned.
		$game_slug = (string) ( $request->get_param( 'game_slug' ) ?? '' );
		$items     = Schema_Block::all_for_game( $args, $game_slug );
		$total     = Schema_Block::count( $args );

		$response = $this->success( $items );
		return $this->paginate( $response, $total, $pagination['per_page'], $pagination['page'] );
	}

	/**
	 * Returns a single schema block by slug, substituting a game's own
	 * fork of the block when a game_slug is given. Returns a 404 error
	 * when no matching block exists.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_item( $request ) {
		$game_slug = (string) ( $request->get_param( 'game_slug' ) ?? '' );
		$block     = Schema_Block::find_for_game( $request['slug'], $game_slug );
		if ( ! $block ) {
			return $this->error( 'not_found', __( 'Schema block not found.', 'beyond-elysium' ), 404 );
		}
		return $this->success( $block );
	}

	/**
	 * Creates a new global schema block from a required slug, name, and
	 * section_type. Validates the definition structure against the shape
	 * required for the given section_type, or builds a minimal default
	 * definition when none is supplied.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function create_item( $request ) {
		$slug         = $request->get_param( 'slug' );
		$name         = $request->get_param( 'name' );
		$section_type = $request->get_param( 'section_type' );

		if ( empty( $slug ) ) {
			return $this->error( 'invalid_param', __( 'Missing required field: slug.', 'beyond-elysium' ), 400 );
		}
		if ( empty( $name ) ) {
			return $this->error( 'invalid_param', __( 'Missing required field: name.', 'beyond-elysium' ), 400 );
		}
		if ( empty( $section_type ) ) {
			return $this->error( 'invalid_param', __( 'Missing required field: section_type.', 'beyond-elysium' ), 400 );
		}
		if ( ! in_array( $section_type, Schema_Block::valid_section_types(), true ) ) {
			return $this->error( 'invalid_section_type', sprintf( __( 'Invalid section_type. Must be one of: %s.', 'beyond-elysium' ), implode( ', ', Schema_Block::valid_section_types() ) ), 400 );
		}

		$definition = $request->get_param( 'definition' );
		if ( $definition !== null ) {
			$validation_error = $this->validate_definition( $section_type, $definition );
			if ( $validation_error ) {
				return $validation_error;
			}
		} else {
			$definition = $this->default_definition( $section_type );
		}

		if ( Schema_Block::find_by_slug( sanitize_title( $slug ) ) ) {
			return $this->error( 'duplicate_slug', __( 'A schema block with this slug already exists.', 'beyond-elysium' ), 409 );
		}

		$id = Schema_Block::create( [
			'slug'             => $slug,
			'name'             => $name,
			'section_type'     => $section_type,
			'definition'       => $definition,
			'is_system'        => 0,
			'storyteller_only' => $request->get_param( 'storyteller_only' ) ? 1 : 0,
		] );

		if ( ! $id ) {
			return $this->error( 'create_failed', __( 'Failed to create schema block.', 'beyond-elysium' ), 500 );
		}

		return $this->success( Schema_Block::find( $id ), 201 );
	}

	/**
	 * Updates an existing schema block by slug with any recognized fields
	 * present in the request. When a game_slug is given, writes to
	 * (creating if needed) that game's own fork of the block rather than
	 * the shared global block. Validates section_type and definition when
	 * included.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function update_item( $request ) {
		// Auto-creates the game's fork of the block on first edit if one doesn't exist yet.
		$game_slug = (string) ( $request->get_param( 'game_slug' ) ?? '' );
		$block     = $game_slug !== ''
			? Schema_Block::find_or_create_fork_for_game( $request['slug'], $game_slug )
			: Schema_Block::find_by_slug( $request['slug'] );
		if ( ! $block ) {
			return $this->error( 'not_found', __( 'Schema block not found.', 'beyond-elysium' ), 404 );
		}

		$data = [];
		foreach ( [ 'name', 'section_type', 'definition', 'storyteller_only' ] as $field ) {
			$value = $request->get_param( $field );
			if ( $value !== null ) {
				$data[ $field ] = $value;
			}
		}

		if ( isset( $data['section_type'] ) && ! in_array( $data['section_type'], Schema_Block::valid_section_types(), true ) ) {
			return $this->error( 'invalid_section_type', __( 'Invalid section_type.', 'beyond-elysium' ), 400 );
		}

		if ( isset( $data['definition'] ) ) {
			$section_type     = $data['section_type'] ?? $block->section_type;
			$validation_error = $this->validate_definition( $section_type, $data['definition'] );
			if ( $validation_error ) {
				return $validation_error;
			}
		}

		Schema_Block::update( $request['slug'], $data, $game_slug );
		return $this->success( Schema_Block::find_for_game( $request['slug'], $game_slug ) );
	}

	/**
	 * Deletes a schema block by slug after confirming it exists and is
	 * not a system block. Returns a 404 error when no matching block
	 * exists, or a 403 error when the block is a protected system block.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function delete_item( $request ) {
		$block = Schema_Block::find_by_slug( $request['slug'] );
		if ( ! $block ) {
			return $this->error( 'not_found', __( 'Schema block not found.', 'beyond-elysium' ), 404 );
		}

		if ( ! empty( $block->is_system ) ) {
			return $this->error( 'cannot_delete', __( 'Cannot delete a system schema block.', 'beyond-elysium' ), 403 );
		}

		Schema_Block::delete( $request['slug'] );
		return $this->success( null, 204 );
	}

	/**
	 * Defines the query parameters accepted by the schema block collection
	 * endpoint: section_type/is_system/search filters, orderby/order sort
	 * controls, and page/per_page pagination.
	 *
	 * @return array
	 */
	public function get_collection_params(): array {
		return [
			'section_type' => [
				'type'              => 'string',
				'enum'              => Schema_Block::valid_section_types(),
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
				'enum'    => [ 'name', 'slug', 'section_type', 'created_at' ],
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
	 * Defines the request parameters accepted when creating a schema
	 * block: the required slug, name, and section_type, plus an optional
	 * definition object.
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
			'section_type' => [
				'type'     => 'string',
				'required' => true,
				'enum'     => Schema_Block::valid_section_types(),
			],
			'definition' => [
				'type' => 'object',
			],
		];
	}

	/**
	 * Checks that a definition value contains the array key required for
	 * its section_type (items for trait_list, powers for tiered_power,
	 * pools for resource_pool, fields for identity_field). Decodes a JSON
	 * string or stdClass into a plain array first.
	 *
	 * @param string $section_type
	 * @param mixed  $definition
	 * @return \WP_Error|null Null on valid, WP_Error on invalid.
	 */
	private function validate_definition( string $section_type, $definition ) {
		// A JSON request body decodes to a plain PHP array; a stdClass is handled too.
		if ( is_string( $definition ) ) {
			$definition = json_decode( $definition, true );
			if ( json_last_error() !== JSON_ERROR_NONE ) {
				return $this->error( 'invalid_json', __( 'definition must be a valid JSON object.', 'beyond-elysium' ), 400 );
			}
		} elseif ( is_object( $definition ) ) {
			$definition = json_decode( wp_json_encode( $definition ), true );
		}

		$required_keys = [
			'trait_list'    => 'items',
			'tiered_power'  => 'powers',
			'resource_pool' => 'pools',
			'identity_field' => 'fields',
		];

		$key = $required_keys[ $section_type ] ?? null;
		if ( $key && ( ! is_array( $definition ) || ! isset( $definition[ $key ] ) ) ) {
			return $this->error( 'invalid_param', sprintf( __( 'definition for section_type \'%1$s\' must contain \'%2$s\' array.', 'beyond-elysium' ), $section_type, $key ), 400 );
		}

		return null;
	}

	/**
	 * Builds an empty default definition shape for a given section_type,
	 * used when creating a schema block without an explicit definition.
	 * Returns an empty array for an unrecognized section_type.
	 *
	 * @param string $section_type
	 * @return array
	 */
	private function default_definition( string $section_type ): array {
		$defaults = [
			'trait_list'     => [ 'items' => [] ],
			'tiered_power'   => [ 'powers' => [] ],
			'resource_pool'  => [ 'pools' => [] ],
			'identity_field' => [ 'fields' => [] ],
		];
		return $defaults[ $section_type ] ?? [];
	}
}
