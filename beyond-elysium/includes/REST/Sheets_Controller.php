<?php

namespace BeyondElysium\REST;

use BeyondElysium\Models\Character;
use BeyondElysium\Models\Creature_Stack;
use BeyondElysium\Services\Pdf_Signer;
use BeyondElysium\Services\Pdf_Writer;
use BeyondElysium\Services\Sheet_Document;

defined( 'ABSPATH' ) || exit;

/**
 * REST controller for signed-PDF character sheets: `GET /{game_slug}/sheets/pdf` and its preflight, `GET
 * /{game_slug}/sheets/availability`.
 */
class Sheets_Controller extends Base_Controller {

	protected $rest_base = 'sheets';

	private const MAX_CHARACTERS = 50;

	public function register_routes(): void {
		register_rest_route( $this->namespace, '/(?P<game_slug>[a-z0-9\-]+)/sheets/pdf', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_pdf' ],
				'permission_callback' => $this->permission( 'be_view_characters' ),
				'args'                => [
					'character_ids'    => [ 'type' => 'string', 'required' => true ],
					'full_power_names' => [ 'type' => 'boolean', 'default' => false ],
					'background'       => [ 'type' => 'boolean', 'default' => false ],
					'notes'            => [ 'type' => 'boolean', 'default' => false ],
					'xp_history'       => [ 'type' => 'boolean', 'default' => false ],
					'show_cost'        => [ 'type' => 'boolean', 'default' => true ],
					'page_size'        => [ 'type' => 'string', 'enum' => [ 'letter', 'a4' ] ],
				],
			],
		] );

		register_rest_route( $this->namespace, '/(?P<game_slug>[a-z0-9\-]+)/sheets/availability', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_availability' ],
				'permission_callback' => $this->permission( 'be_view_characters' ),
			],
		] );

		add_filter( 'rest_pre_serve_request', [ $this, 'serve_pdf_bytes' ], 10, 4 );
	}

	/**
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_pdf( $request ) {
		$game = $this->resolve_game( $request['game_slug'] );
		if ( is_wp_error( $game ) ) {
			return $game;
		}

		$ids = array_values( array_filter( array_map( 'intval', explode( ',', (string) $request->get_param( 'character_ids' ) ) ) ) );
		if ( empty( $ids ) ) {
			return $this->error( 'invalid_request', __( 'character_ids is required.', 'beyond-elysium' ), 400 );
		}
		if ( count( $ids ) > self::MAX_CHARACTERS ) {
			return $this->error(
				'too_many_characters',
				sprintf(
					/* translators: %d: maximum number of characters per request */
					__( 'A maximum of %d characters may be requested at once.', 'beyond-elysium' ),
					self::MAX_CHARACTERS
				),
				400
			);
		}

		$can_manage = \BeyondElysium\Core\Authorization::can( 'be_manage_characters' );

		foreach ( $ids as $id ) {
			$character = Character::find( $id );
			if ( ! $character || $character->owner_slug !== $request['game_slug'] ) {
				return $this->error( 'character_not_found', __( 'Character not found in this game.', 'beyond-elysium' ), 404 );
			}
			if ( ! $can_manage && (int) $character->wp_user_id !== get_current_user_id() ) {
				return $this->error(
					'ownership_denied',
					__( 'You do not have permission to view one or more of these characters.', 'beyond-elysium' ),
					403
				);
			}
			// A character whose creature type is gone has no sheet to build: say so.
			if ( Creature_Stack::find_by_slug( (string) $character->stack_slug ) === null ) {
				return $this->error(
					'creature_stack_not_found',
					sprintf(
						/* translators: %s: character name */
						__( '%s\'s creature type no longer exists, so no sheet can be printed for them.', 'beyond-elysium' ),
						$character->name
					),
					404
				);
			}
		}

		$documents = Sheet_Document::for_characters( $ids, $request['game_slug'], [
			'can_manage'       => $can_manage,
			'full_power_names' => (bool) $request->get_param( 'full_power_names' ),
			'background'       => (bool) $request->get_param( 'background' ),
			'notes'            => (bool) $request->get_param( 'notes' ),
			'xp_history'       => (bool) $request->get_param( 'xp_history' ),
			'show_cost'        => (bool) $request->get_param( 'show_cost' ),
		] );

		// Signed only when an administrator switched secure printing on AND a usable certificate is configured.
		$signed   = Pdf_Signer::should_sign()['ok'];
		$bytes    = Pdf_Writer::write( $documents, $game, $signed, $request->get_param( 'page_size' ) );
		$filename = ( count( $documents ) === 1
			? sanitize_file_name( (string) $documents[0]['title'] )
			: sanitize_file_name( $request['game_slug'] ) . '-sheets' ) . ( $signed ? '' : '-unsigned' ) . '.pdf';

		return $this->success( [ 'bytes' => $bytes, 'filename' => $filename ] );
	}

	/**
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_availability( $request ) {
		$game = $this->resolve_game( $request['game_slug'] );
		if ( is_wp_error( $game ) ) {
			return $game;
		}

		// The preflight answers "will this print be signed".
		return $this->success( Pdf_Signer::should_sign() );
	}

	/**
	 * Intercepts the normal JSON-serialize-and-serve step for exactly this controller's `get_pdf()` route, matched by
	 * callback identity.
	 *
	 * @param bool              $served
	 * @param \WP_REST_Response $result
	 * @param \WP_REST_Request  $request
	 * @param \WP_REST_Server   $server
	 * @return bool
	 */
	public function serve_pdf_bytes( $served, $result, $request, $server ) {
		$attributes = $request->get_attributes();
		if ( ( $attributes['callback'] ?? null ) !== [ $this, 'get_pdf' ] ) {
			return $served;
		}

		$data = $result->get_data();
		if ( ! is_array( $data ) || ! isset( $data['bytes'], $data['filename'] ) ) {
			return $served;
		}

		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: attachment; filename="' . $data['filename'] . '"' );
		echo $data['bytes']; // phpcs:ignore
		return true;
	}

	/**
	 * Looks up the game record for the given slug and returns a 404 error when no game matches it.
	 *
	 * @param string $game_slug
	 * @return object|\WP_Error
	 */
	protected function resolve_game( string $game_slug ) {
		$game = \BeyondElysium\Models\Game::find_by_slug( $game_slug );
		if ( ! $game ) {
			return $this->error( 'game_not_found', __( 'Game not found.', 'beyond-elysium' ), 404 );
		}
		return $game;
	}
}
