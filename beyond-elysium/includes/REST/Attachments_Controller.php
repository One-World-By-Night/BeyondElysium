<?php

namespace BeyondElysium\REST;

use BeyondElysium\Core\Authorization;
use BeyondElysium\Models\Attachment;
use BeyondElysium\Models\Character;
use BeyondElysium\Models\Connection;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Plot;
use BeyondElysium\Models\World_Object;
use BeyondElysium\Services\Action_Allocator;
use BeyondElysium\Services\Attachment_Storage;
use BeyondElysium\Services\Audience;

defined( 'ABSPATH' ) || exit;

/**
 * REST controller for file uploads on a plot, item, or location (1.1.0 §2.6).
 *
 * An attachment always follows its entity's own audience - the download route is the only
 * place its bytes ever reach a client, and it checks that audience on every single request,
 * the same as opening the entity itself would. Nothing here ever exposes `stored_name`: the
 * only handle a client is ever given is the attachment's own numeric id.
 */
class Attachments_Controller extends Base_Controller {

	protected $rest_base = 'attachments';

	/**
	 * Registers the upload, download, and delete routes, all scoped to a game slug. The outer
	 * gate on upload and delete is broad - anyone who could conceivably own the entity an
	 * attachment belongs to - because which specific entity decides who actually may act on it,
	 * resolved inside each callback the same way `Plots_Controller`'s membership routes do.
	 */
	public function register_routes(): void {
		register_rest_route( $this->namespace, '/(?P<game_slug>[a-z0-9\-]+)/attachments', [
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'create_item' ],
				'permission_callback' => $this->permission_any( [ 'be_submit_actions', 'be_manage_plots', 'be_manage_world_objects' ] ),
			],
		] );

		register_rest_route( $this->namespace, '/(?P<game_slug>[a-z0-9\-]+)/attachments/(?P<id>\d+)', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_item' ],
				'permission_callback' => $this->permission( 'be_view_characters' ),
			],
			[
				'methods'             => 'DELETE',
				'callback'            => [ $this, 'delete_item' ],
				'permission_callback' => $this->permission_any( [ 'be_submit_actions', 'be_manage_plots', 'be_manage_world_objects' ] ),
			],
		] );

		add_filter( 'rest_pre_serve_request', [ $this, 'serve_attachment_bytes' ], 10, 4 );
	}

	/**
	 * Uploads a file onto a plot, item, or location. `entity_type` and `entity_id` name the
	 * owner; the file itself is the request's own single file parameter, whatever it is named
	 * - a REST client posts exactly one file per call, so the first (and normally only) entry
	 * in `get_file_params()` is used rather than requiring a fixed field name.
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
		if ( ! in_array( $entity_type, Attachment::ENTITY_TYPES, true ) ) {
			return $this->error( 'invalid_param', sprintf( __( 'entity_type must be one of: %s.', 'beyond-elysium' ), implode( ', ', Attachment::ENTITY_TYPES ) ), 400 );
		}

		$entity = $this->resolve_entity( $entity_type, $entity_id, (int) $game->id );
		if ( is_wp_error( $entity ) ) {
			return $entity;
		}

		if ( ! $this->may_manage_attachments( $entity_type, $entity ) ) {
			return $this->error( 'forbidden', __( 'You do not have permission to upload a file here.', 'beyond-elysium' ), 403 );
		}

		$existing = Attachment::count_for_entity( $entity_type, $entity_id );
		if ( $existing >= Attachment::LIMITS[ $entity_type ] ) {
			return $this->error(
				'limit_reached',
				sprintf(
					/* translators: %d: the maximum number of files this entity type may carry */
					__( 'This %1$s already has the most files it may carry (%2$d).', 'beyond-elysium' ),
					$entity_type,
					Attachment::LIMITS[ $entity_type ]
				),
				409
			);
		}

		$files = $request->get_file_params();
		if ( empty( $files ) ) {
			return $this->error( 'invalid_param', __( 'No file was uploaded.', 'beyond-elysium' ), 400 );
		}
		$uploaded = reset( $files );

		$stored = Attachment_Storage::store( $uploaded );
		if ( is_wp_error( $stored ) ) {
			return $stored;
		}

		$id = Attachment::create( [
			'game_id'       => (int) $game->id,
			'entity_type'   => $entity_type,
			'entity_id'     => $entity_id,
			'original_name' => $stored['original_name'],
			'stored_name'   => $stored['stored_name'],
			'mime'          => $stored['mime'],
			'bytes'         => $stored['bytes'],
			'created_by'    => get_current_user_id(),
		] );

		if ( ! $id ) {
			// The row failed after the file was already written - the file is orphaned
			// deliberately rather than guessed-deleted, matching this codebase's own preference
			// for a recoverable inert leftover over a destructive cleanup attempt on a path that
			// may not be what it appears (1.0.2's own orphaned-tables precedent, same reasoning).
			return $this->error( 'create_failed', __( 'Failed to record the upload.', 'beyond-elysium' ), 500 );
		}

		$row = Attachment::find( (int) $id );
		if ( ! $row ) {
			// A row this method's own insert just created is gone by the time it re-reads it -
			// real DB inconsistency, not an ordinary failure path, matching
			// Attestation::issue()'s identical precedent for the identical situation.
			throw new \RuntimeException( 'Attachments_Controller::create_item() failed to read back its own insert.' );
		}

		return $this->success( Attachment::public_shape( $row ), 201 );
	}

	/**
	 * Resolves and returns one attachment's public metadata, never its `stored_name`. The
	 * actual bytes are served by `serve_attachment_bytes()` below, not this method - a GET
	 * request always reaches that filter first when the audience check here passes, since
	 * WordPress runs `rest_pre_serve_request` on every REST response.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_item( $request ) {
		$resolved = $this->resolve_visible_attachment( $request );
		if ( is_wp_error( $resolved ) ) {
			return $resolved;
		}
		[ , $attachment ] = $resolved;

		$path = Attachment_Storage::path_for( $attachment->stored_name, $attachment->original_name );
		if ( ! file_exists( $path ) ) {
			return $this->error( 'file_missing', __( 'This file is no longer available.', 'beyond-elysium' ), 404 );
		}

		// Read here, not in serve_attachment_bytes(): a WP_Error at this point still gets
		// WordPress's own ordinary JSON error response, which the raw-byte filter below never
		// gets a chance to intercept (matching Sheets_Controller::serve_pdf_bytes()'s own split).
		$bytes = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		return $this->success( [
			'bytes'    => $bytes,
			'mime'     => $attachment->mime,
			'filename' => $attachment->original_name,
		] );
	}

	/**
	 * Streams an attachment's raw bytes in place of the ordinary JSON envelope, matched by
	 * callback identity so no other route is affected - `Sheets_Controller::serve_pdf_bytes()`'s
	 * own established pattern for the one other route in this plugin that serves a real file
	 * rather than JSON.
	 *
	 * @param bool              $served
	 * @param \WP_REST_Response $result
	 * @param \WP_REST_Request  $request
	 * @param \WP_REST_Server   $server
	 * @return bool
	 */
	public function serve_attachment_bytes( $served, $result, $request, $server ) {
		$attributes = $request->get_attributes();
		if ( ( $attributes['callback'] ?? null ) !== [ $this, 'get_item' ] ) {
			return $served;
		}

		$data = $result->get_data();
		if ( ! is_array( $data ) || ! isset( $data['bytes'], $data['mime'], $data['filename'] ) ) {
			return $served;
		}

		header( 'Content-Type: ' . $data['mime'] );
		header( 'Content-Disposition: attachment; filename="' . $data['filename'] . '"' );
		header( 'Cache-Control: no-store' );
		echo $data['bytes']; // phpcs:ignore WordPress.Security.EscapeOutput -- raw file bytes, not HTML output.
		return true;
	}

	/**
	 * Deletes an attachment: the database row and its file on disk together, or neither -
	 * removing the row before confirming the file is gone would leave an orphaned file with
	 * nothing left able to name or clean it up.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function delete_item( $request ) {
		$attachment = Attachment::find( (int) $request['id'] );
		if ( ! $attachment ) {
			return $this->error( 'not_found', __( 'File not found.', 'beyond-elysium' ), 404 );
		}
		$game = $this->resolve_game( $request['game_slug'] );
		if ( is_wp_error( $game ) ) {
			return $game;
		}
		if ( (int) $attachment->game_id !== (int) $game->id ) {
			return $this->error( 'not_found', __( 'File not found.', 'beyond-elysium' ), 404 );
		}

		$entity = $this->resolve_entity( $attachment->entity_type, (int) $attachment->entity_id, (int) $game->id );
		if ( is_wp_error( $entity ) ) {
			return $entity;
		}
		if ( ! $this->may_manage_attachments( $attachment->entity_type, $entity ) ) {
			return $this->error( 'forbidden', __( 'You do not have permission to remove this file.', 'beyond-elysium' ), 403 );
		}

		Attachment::delete( (int) $attachment->id );
		Attachment_Storage::delete( $attachment->stored_name, $attachment->original_name );

		return $this->success( null, 204 );
	}

	/**
	 * Resolves an attachment by id and confirms the current viewer's audience reaches its
	 * owning entity - the same visibility-then-nothing-else-disclosed order every other
	 * audience check in this plugin already uses. Shared by `get_item()`; `delete_item()` does
	 * its own resolution instead, since deleting needs the *manage* check, not the *view* one.
	 *
	 * @param \WP_REST_Request $request
	 * @return array{0:object,1:object}|\WP_Error [$entity, $attachment]
	 */
	private function resolve_visible_attachment( $request ) {
		$attachment = Attachment::find( (int) $request['id'] );
		if ( ! $attachment ) {
			return $this->error( 'not_found', __( 'File not found.', 'beyond-elysium' ), 404 );
		}
		$game = $this->resolve_game( $request['game_slug'] );
		if ( is_wp_error( $game ) ) {
			return $game;
		}
		if ( (int) $attachment->game_id !== (int) $game->id ) {
			return $this->error( 'not_found', __( 'File not found.', 'beyond-elysium' ), 404 );
		}

		$entity = $this->resolve_entity( $attachment->entity_type, (int) $attachment->entity_id, (int) $game->id );
		if ( is_wp_error( $entity ) ) {
			return $this->error( 'not_found', __( 'File not found.', 'beyond-elysium' ), 404 );
		}

		$can_manage = Authorization::check_request( $this->manage_capability_for( $attachment->entity_type ), $request );
		if ( ! $can_manage && ! Audience::can_see( $entity, $attachment->entity_type, get_current_user_id(), $request['game_slug'], false ) ) {
			return $this->error( 'not_found', __( 'File not found.', 'beyond-elysium' ), 404 );
		}

		return [ $entity, $attachment ];
	}

	/**
	 * Looks up the entity an attachment belongs (or would belong) to, confirming it exists,
	 * belongs to this game, and - for `item`/`location` - is really that `object_type` and not
	 * the other one, since both share the same `world_objects` table.
	 *
	 * @param string $entity_type One of Attachment::ENTITY_TYPES.
	 * @param int    $entity_id
	 * @param int    $game_id
	 * @return object|\WP_Error
	 */
	private function resolve_entity( string $entity_type, int $entity_id, int $game_id ) {
		if ( $entity_type === 'plot' ) {
			$plot = Plot::find( $entity_id );
			if ( ! $plot || (int) $plot->game_id !== $game_id ) {
				return $this->error( 'entity_not_found', __( 'Plot not found in this game.', 'beyond-elysium' ), 404 );
			}
			return $plot;
		}

		$object = World_Object::find( $entity_id );
		if ( ! $object || (int) $object->game_id !== $game_id || $object->object_type !== $entity_type ) {
			return $this->error( 'entity_not_found', __( 'World object not found in this game.', 'beyond-elysium' ), 404 );
		}
		return $object;
	}

	/**
	 * The manage capability an entity type's own audience decisions key off.
	 *
	 * @param string $entity_type
	 * @return string
	 */
	private function manage_capability_for( string $entity_type ): string {
		return $entity_type === 'plot' ? 'be_manage_plots' : 'be_manage_world_objects';
	}

	/**
	 * Whether the current request may upload or remove a file on this entity: a Storyteller
	 * always may; for a plot, so may the plot's own owner (§2.3a - a player plot gets
	 * "everything a global plot has," uploads included), checked the same way
	 * `Plots_Controller::owns_plot()` does. Items and locations have no player-ownership
	 * concept at all, so `be_manage_world_objects` is the only path for either.
	 *
	 * @param string $entity_type
	 * @param object $entity
	 * @return bool
	 */
	private function may_manage_attachments( string $entity_type, object $entity ): bool {
		if ( Authorization::can( $this->manage_capability_for( $entity_type ) ) ) {
			return true;
		}
		if ( $entity_type !== 'plot' ) {
			return false;
		}

		$wp_user_id = get_current_user_id();
		foreach ( Connection::for_source( 'plot', (int) $entity->id ) as $connection ) {
			if ( $connection->target_type !== 'character'
				|| ! in_array( $connection->label, [ Action_Allocator::ACTOR_LABEL, 'plot_owner' ], true ) ) {
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
	 * Looks up a game by its slug and returns the game object, or a WP_Error with a 404 status
	 * when no game matches.
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
