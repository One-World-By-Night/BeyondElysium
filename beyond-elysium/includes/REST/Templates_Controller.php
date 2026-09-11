<?php

namespace BeyondElysium\REST;

use BeyondElysium\Models\Game;
use BeyondElysium\Models\Schema_Block;
use BeyondElysium\Models\Template;
use BeyondElysium\Services\Layout_Generator;

defined( 'ABSPATH' ) || exit;

/**
 * REST controller for character sheet templates. Serves both global
 * templates and game-scoped templates that override them, plus a resolve
 * endpoint that finds the effective template for a creature stack and
 * template type, generating a fallback layout when neither exists.
 *
 * @see BE_PROCESS/workflow-0.3.md Step 2
 */
class Templates_Controller extends Base_Controller {

	protected $rest_base = 'templates';

	/**
	 * Registers the REST routes for global templates, game-scoped
	 * templates, and the resolve endpoint. The resolve route is
	 * registered before the numeric id route so its non-numeric path
	 * segment is never shadowed.
	 */
	public function register_routes(): void {
		register_rest_route( $this->namespace, '/' . $this->rest_base, [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_globals' ],
				'permission_callback' => $this->permission( 'be_view_characters' ),
				'args'                => $this->get_collection_params(),
			],
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'create_global' ],
				'permission_callback' => $this->permission( 'be_manage_templates' ),
			],
		] );

		register_rest_route( $this->namespace, '/' . $this->rest_base . '/(?P<id>\d+)', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_item' ],
				'permission_callback' => $this->permission( 'be_view_characters' ),
			],
			[
				'methods'             => 'PUT',
				'callback'            => [ $this, 'update_global' ],
				'permission_callback' => $this->permission( 'be_manage_templates' ),
			],
			[
				'methods'             => 'DELETE',
				'callback'            => [ $this, 'delete_global' ],
				'permission_callback' => $this->permission( 'be_manage_templates' ),
			],
		] );

		// Must be registered before the game-scoped id route so "resolve" is matched first.
		register_rest_route( $this->namespace, '/(?P<game_slug>[a-z0-9\-]+)/' . $this->rest_base . '/resolve', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'resolve_template' ],
				'permission_callback' => $this->permission( 'be_view_characters' ),
				'args'                => [
					'stack_slug'    => [ 'type' => 'string', 'required' => true ],
					'template_type' => [ 'type' => 'string', 'required' => true ],
				],
			],
		] );

		register_rest_route( $this->namespace, '/(?P<game_slug>[a-z0-9\-]+)/' . $this->rest_base, [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_for_game' ],
				'permission_callback' => $this->permission( 'be_view_characters' ),
				'args'                => $this->get_collection_params(),
			],
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'create_for_game' ],
				'permission_callback' => $this->permission( 'be_manage_templates' ),
			],
		] );

		register_rest_route( $this->namespace, '/(?P<game_slug>[a-z0-9\-]+)/' . $this->rest_base . '/(?P<id>\d+)', [
			[
				'methods'             => 'PUT',
				'callback'            => [ $this, 'update_for_game' ],
				'permission_callback' => $this->permission( 'be_manage_templates' ),
			],
			[
				'methods'             => 'DELETE',
				'callback'            => [ $this, 'delete_for_game' ],
				'permission_callback' => $this->permission( 'be_manage_templates' ),
			],
		] );
	}

	// Global scope

	/**
	 * Returns a paginated list of global templates, optionally filtered
	 * by stack_slug and template_type. Global templates are the default
	 * templates available to every game.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response
	 */
	public function get_globals( $request ) {
		$args = $this->collection_args( $request );
		return $this->success( Template::globals( $args ) );
	}

	/**
	 * Creates a new global template, available to every game, by
	 * delegating to create_template() with no game id. Requires the
	 * be_manage_templates capability, enforced by the route registration.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function create_global( $request ) {
		return $this->create_template( $request, null );
	}

	/**
	 * Returns a single template by id, whether it is a global template or
	 * a game-scoped one. Returns a 404 error when no matching template
	 * exists. Available to any user who can view characters.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_item( $request ) {
		$template = Template::find( (int) $request['id'] );
		if ( ! $template ) {
			return $this->error( 'not_found', __( 'Template not found.', 'beyond-elysium' ), 404 );
		}
		return $this->success( $template );
	}

	/**
	 * Updates a global template by id, confirming it is global (not
	 * game-scoped) before applying the change. Returns a 404 error when
	 * no matching global template exists.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function update_global( $request ) {
		$template = Template::find( (int) $request['id'] );
		if ( ! $template || $template->game_id !== null ) {
			return $this->error( 'not_found', __( 'Template not found.', 'beyond-elysium' ), 404 );
		}
		return $this->apply_update( $request, $template );
	}

	/**
	 * Deletes a global template by id, confirming it is global (not
	 * game-scoped) before deleting. Returns a 404 error when no matching
	 * global template exists, or a 403 error when it is a system
	 * template.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function delete_global( $request ) {
		$template = Template::find( (int) $request['id'] );
		if ( ! $template || $template->game_id !== null ) {
			return $this->error( 'not_found', __( 'Template not found.', 'beyond-elysium' ), 404 );
		}
		return $this->apply_delete( $template );
	}

	// Game scope

	/**
	 * Removes every section backed by a Storyteller-only block from a
	 * resolved layout, for a viewer without `be_manage_characters`. Returns
	 * the layout untouched for a manager, and for any layout with no
	 * sections array to filter.
	 *
	 * @param array $layout
	 * @return array
	 */
	private static function strip_storyteller_only_sections( array $layout ): array {
		if ( current_user_can( 'be_manage_characters' ) || ! isset( $layout['sections'] ) || ! is_array( $layout['sections'] ) ) {
			return $layout;
		}

		$hidden = Schema_Block::storyteller_only_slugs();
		if ( empty( $hidden ) ) {
			return $layout;
		}

		// array_values keeps sections a JSON array rather than a keyed object.
		$layout['sections'] = array_values( array_filter(
			$layout['sections'],
			static fn( $section ) => ! in_array( $section['block_slug'] ?? '', $hidden, true )
		) );

		return $layout;
	}

	/**
	 * Resolves the effective template for a creature stack and template
	 * type: a game-specific override when one exists, otherwise the
	 * global template, otherwise a freshly generated fallback layout.
	 * Reports which of the three sources the returned template came from.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function resolve_template( $request ) {
		$game = $this->resolve_game( $request['game_slug'] );
		if ( is_wp_error( $game ) ) {
			return $game;
		}

		$stack_slug    = $request->get_param( 'stack_slug' );
		$template_type = $request->get_param( 'template_type' );

		$template = Template::resolve( $stack_slug, $template_type, (int) $game->id );

		if ( $template !== null ) {
			return $this->success( [
				'resolved_from' => $template->game_id !== null ? 'game' : 'global',
				'template'      => [
					'id'            => $template->id,
					'name'          => $template->name,
					'stack_slug'    => $template->stack_slug,
					'template_type' => $template->template_type,
					'layout'        => self::strip_storyteller_only_sections( $template->layout ),
				],
			] );
		}

		$layout = Layout_Generator::generate_for_stack( $stack_slug );
		if ( $layout === null ) {
			return $this->error( 'stack_not_found', __( 'Creature stack not found.', 'beyond-elysium' ), 404 );
		}

		$layout = self::strip_storyteller_only_sections( $layout );

		return $this->success( [
			'resolved_from' => 'generated',
			'template'      => [
				'id'            => null,
				'name'          => null,
				'stack_slug'    => $stack_slug,
				'template_type' => $template_type,
				'layout'        => $layout,
			],
		] );
	}

	/**
	 * Returns a paginated list of templates scoped to one game,
	 * optionally filtered by stack_slug and template_type. Confirms the
	 * game exists before querying.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_for_game( $request ) {
		$game = $this->resolve_game( $request['game_slug'] );
		if ( is_wp_error( $game ) ) {
			return $game;
		}

		$args = $this->collection_args( $request );
		return $this->success( Template::for_game( (int) $game->id, $args ) );
	}

	/**
	 * Creates a new template scoped to one game by delegating to
	 * create_template() with that game's id. Confirms the game exists
	 * before creating.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function create_for_game( $request ) {
		$game = $this->resolve_game( $request['game_slug'] );
		if ( is_wp_error( $game ) ) {
			return $game;
		}

		return $this->create_template( $request, (int) $game->id );
	}

	/**
	 * Updates a template scoped to one game by id, confirming the
	 * template belongs to that game before applying the change. Returns
	 * a 404 error, not a 403, when the template belongs to a different
	 * game or does not exist.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function update_for_game( $request ) {
		$game = $this->resolve_game( $request['game_slug'] );
		if ( is_wp_error( $game ) ) {
			return $game;
		}

		$template = Template::find( (int) $request['id'] );
		if ( ! $template || (int) $template->game_id !== (int) $game->id ) {
			return $this->error( 'not_found', __( 'Template not found.', 'beyond-elysium' ), 404 );
		}

		return $this->apply_update( $request, $template );
	}

	/**
	 * Deletes a template scoped to one game by id, confirming the
	 * template belongs to that game before deleting. Returns a 404 error
	 * when it belongs to a different game or does not exist, or a 403
	 * error when it is a system template.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function delete_for_game( $request ) {
		$game = $this->resolve_game( $request['game_slug'] );
		if ( is_wp_error( $game ) ) {
			return $game;
		}

		$template = Template::find( (int) $request['id'] );
		if ( ! $template || (int) $template->game_id !== (int) $game->id ) {
			return $this->error( 'not_found', __( 'Template not found.', 'beyond-elysium' ), 404 );
		}

		return $this->apply_delete( $template );
	}

	// Shared helpers

	/**
	 * Shared creation logic for both global and game-scoped templates.
	 * Requires name and template_type, decodes and validates the layout
	 * structure, and creates the template row with the given game_id
	 * (null for a global template).
	 *
	 * @param \WP_REST_Request $request
	 * @param int|null         $game_id
	 * @return \WP_REST_Response|\WP_Error
	 */
	private function create_template( $request, ?int $game_id ) {
		$stack_slug    = $request->get_param( 'stack_slug' );
		$name          = $request->get_param( 'name' );
		$template_type = $request->get_param( 'template_type' );
		$layout        = $request->get_param( 'layout' );

		if ( empty( $name ) ) {
			return $this->error( 'invalid_param', __( 'Missing required field: name.', 'beyond-elysium' ), 400 );
		}
		if ( empty( $template_type ) ) {
			return $this->error( 'invalid_param', __( 'Missing required field: template_type.', 'beyond-elysium' ), 400 );
		}

		$decoded = is_string( $layout ) ? json_decode( $layout, true ) : $layout;
		if ( is_object( $decoded ) ) {
			$decoded = json_decode( wp_json_encode( $decoded ), true );
		}

		$validation_error = Template::validate_layout( $decoded );
		if ( $validation_error ) {
			return $this->error( 'invalid_layout', $validation_error->get_error_message(), 400 );
		}

		$id = Template::create( [
			'game_id'       => $game_id,
			'stack_slug'    => $stack_slug,
			'name'          => $name,
			'template_type' => $template_type,
			'layout'        => $decoded,
		] );

		if ( ! $id ) {
			return $this->error( 'create_failed', __( 'Failed to create template.', 'beyond-elysium' ), 500 );
		}

		return $this->success( Template::find( $id ), 201 );
	}

	/**
	 * Shared update logic for both global and game-scoped templates.
	 * Applies any recognized fields present in the request, decoding and
	 * validating a new layout when one is included, and returns the
	 * updated template.
	 *
	 * @param \WP_REST_Request $request
	 * @param object           $template Existing template row.
	 * @return \WP_REST_Response|\WP_Error
	 */
	private function apply_update( $request, $template ) {
		$data = [];

		foreach ( [ 'name', 'template_type' ] as $field ) {
			$value = $request->get_param( $field );
			if ( $value !== null ) {
				$data[ $field ] = $value;
			}
		}

		$layout = $request->get_param( 'layout' );
		if ( $layout !== null ) {
			$decoded = is_string( $layout ) ? json_decode( $layout, true ) : $layout;
			if ( is_object( $decoded ) ) {
				$decoded = json_decode( wp_json_encode( $decoded ), true );
			}

			$validation_error = Template::validate_layout( $decoded );
			if ( $validation_error ) {
				return $this->error( 'invalid_layout', $validation_error->get_error_message(), 400 );
			}

			$data['layout'] = $decoded;
		}

		if ( ! Template::update( (int) $template->id, $data ) ) {
			return $this->error( 'update_failed', __( 'Failed to update template.', 'beyond-elysium' ), 500 );
		}

		return $this->success( Template::find( (int) $template->id ) );
	}

	/**
	 * Shared deletion logic for both global and game-scoped templates.
	 * Refuses to delete a system template with a 403 error, otherwise
	 * deletes the row and responds with an empty 204.
	 *
	 * @param object $template
	 * @return \WP_REST_Response|\WP_Error
	 */
	private function apply_delete( $template ) {
		if ( ! empty( $template->is_system ) ) {
			return $this->error( 'cannot_delete', __( 'Cannot delete a system template.', 'beyond-elysium' ), 403 );
		}

		if ( ! Template::delete( (int) $template->id ) ) {
			return $this->error( 'delete_failed', __( 'Failed to delete template.', 'beyond-elysium' ), 500 );
		}

		return $this->success( null, 204 );
	}

	/**
	 * Builds the shared filter and pagination arguments used by both the
	 * global and game-scoped template listing endpoints, from the
	 * request's stack_slug, template_type, and pagination parameters.
	 *
	 * @param \WP_REST_Request $request
	 * @return array
	 */
	private function collection_args( $request ): array {
		$pagination = $this->get_pagination( $request );
		return [
			'stack_slug'    => $request->get_param( 'stack_slug' ),
			'template_type' => $request->get_param( 'template_type' ),
			'per_page'      => $pagination['per_page'],
			'offset'        => $pagination['offset'],
		];
	}

	/**
	 * Defines the query parameters accepted by the template listing
	 * endpoints: stack_slug and template_type filters plus page/per_page
	 * pagination, each with its allowed values and defaults.
	 *
	 * @return array
	 */
	public function get_collection_params(): array {
		return [
			'stack_slug' => [
				'type' => 'string',
			],
			'template_type' => [
				'type' => 'string',
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
