<?php

namespace BeyondElysium\REST;

use BeyondElysium\Models\Character;
use BeyondElysium\Services\Pdf_Signer;
use BeyondElysium\Services\Report_Document;
use BeyondElysium\Services\Report_Writer;

defined( 'ABSPATH' ) || exit;

/**
 * REST controller for the 19 reports: `GET /{game_slug}/reports` (the
 * registry, for the Reports admin page) and `GET /{game_slug}/reports/{report_key}/pdf`.
 *
 * `be_view_reports` gates both routes the same broad way `be_view_characters`
 * gates `Sheets_Controller` - every real chronicle role holds it
 * (reports-cards-batch-design.md §4). Row-level visibility (NPC hiding,
 * `[ST]`-marked text) still runs inside `Report_Document`/`Query_Engine`
 * exactly as it does for the character list and the signed sheet.
 *
 * @see BE_PROCESS/reports-cards-batch-design.md §3.5
 */
class Reports_Controller extends Base_Controller {

	protected $rest_base = 'reports';

	public function register_routes(): void {
		register_rest_route( $this->namespace, '/(?P<game_slug>[a-z0-9\-]+)/reports', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_items' ],
				'permission_callback' => $this->permission( 'be_view_reports' ),
			],
		] );

		register_rest_route( $this->namespace, '/(?P<game_slug>[a-z0-9\-]+)/reports/(?P<report_key>[a-z0-9\-]+)', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_document' ],
				'permission_callback' => $this->permission( 'be_view_reports' ),
				'args'                => [
					'conditions'   => [ 'type' => 'string', 'required' => false ],
					'logic'        => [ 'type' => 'string', 'default' => 'AND' ],
					'stat_field'   => [ 'type' => 'string', 'required' => false ],
					'stat_type'    => [ 'type' => 'string', 'required' => false ],
					'character_id' => [ 'type' => 'integer', 'required' => false ],
				],
			],
		] );

		register_rest_route( $this->namespace, '/(?P<game_slug>[a-z0-9\-]+)/reports/(?P<report_key>[a-z0-9\-]+)/pdf', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_pdf' ],
				'permission_callback' => $this->permission( 'be_view_reports' ),
				'args'                => [
					'conditions'   => [ 'type' => 'string', 'required' => false ],
					'logic'        => [ 'type' => 'string', 'default' => 'AND' ],
					'stat_field'   => [ 'type' => 'string', 'required' => false ],
					'stat_type'    => [ 'type' => 'string', 'required' => false ],
					'character_id' => [ 'type' => 'integer', 'required' => false ],
				],
			],
		] );

		add_filter( 'rest_pre_serve_request', [ $this, 'serve_pdf_bytes' ], 10, 4 );
	}

	/**
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_items( $request ) {
		$game = $this->resolve_game( $request['game_slug'] );
		if ( is_wp_error( $game ) ) {
			return $game;
		}

		$rows = [];
		foreach ( Report_Document::registry() as $key => $report ) {
			$rows[] = [
				'key'    => $key,
				'title'  => $report['title'],
				'shape'  => $report['shape'],
				'entity' => $report['entity'] ?? null,
			];
		}

		return $this->success( $rows );
	}

	/**
	 * Plain JSON form of a report's resolved document - what a front-end
	 * widget (an Elementor drop-in, a shortcode) renders live on a page,
	 * as opposed to `get_pdf()`'s signed, downloadable form of the same
	 * data. No `Pdf_Signer` involvement at all; this route never touches
	 * signing.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_document( $request ) {
		$game = $this->resolve_game( $request['game_slug'] );
		if ( is_wp_error( $game ) ) {
			return $game;
		}

		$document = $this->build_document_from_request( $request );
		if ( is_wp_error( $document ) ) {
			return $document;
		}

		return $this->success( $document );
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

		$availability = Pdf_Signer::availability();
		if ( ! $availability['ok'] ) {
			return $this->error(
				'signing_unavailable',
				__( 'This chronicle has not set up sheet signing yet - ask your Storyteller.', 'beyond-elysium' ),
				503
			);
		}

		$document = $this->build_document_from_request( $request );
		if ( is_wp_error( $document ) ) {
			return $document;
		}

		$bytes    = Report_Writer::write( $document, $game );
		$filename = sanitize_file_name( $request['game_slug'] . '-' . (string) $request['report_key'] ) . '.pdf';

		return $this->success( [ 'bytes' => $bytes, 'filename' => $filename ] );
	}

	/**
	 * Shared build step behind `get_document()` and `get_pdf()` - validates
	 * the report key, parses `conditions`, and resolves the document.
	 *
	 * @param \WP_REST_Request $request
	 * @return array<string,mixed>|\WP_Error
	 */
	private function build_document_from_request( $request ) {
		$report_key = (string) $request['report_key'];
		if ( ! array_key_exists( $report_key, Report_Document::registry() ) ) {
			return $this->error( 'report_not_found', __( 'Report not found.', 'beyond-elysium' ), 404 );
		}

		$raw_conditions = (string) $request->get_param( 'conditions' );
		$conditions     = $raw_conditions !== '' ? json_decode( $raw_conditions, true ) : [];
		if ( ! is_array( $conditions ) ) {
			return $this->error( 'invalid_request', __( 'conditions must be valid JSON.', 'beyond-elysium' ), 400 );
		}

		$can_manage = current_user_can( 'be_manage_characters' );
		$filters    = [ 'conditions' => $conditions, 'logic' => (string) $request->get_param( 'logic' ) ];

		$character_id = $request->get_param( 'character_id' );
		if ( $character_id !== null && $character_id !== '' ) {
			$character = Character::find( (int) $character_id );
			if ( ! $character || $character->owner_slug !== $request['game_slug'] ) {
				return $this->error( 'character_not_found', __( 'Character not found in this game.', 'beyond-elysium' ), 404 );
			}
			if ( ! $can_manage && (int) $character->wp_user_id !== get_current_user_id() ) {
				return $this->error(
					'ownership_denied',
					__( 'You do not have permission to view this character.', 'beyond-elysium' ),
					403
				);
			}
			$filters['character_id'] = (int) $character_id;
		}

		$document = Report_Document::build(
			$report_key,
			$request['game_slug'],
			$filters,
			[
				'can_manage' => $can_manage,
				'stat_field' => (string) $request->get_param( 'stat_field' ),
				'stat_type'  => (string) $request->get_param( 'stat_type' ),
			]
		);

		if ( $document === null ) {
			return $this->error( 'report_not_found', __( 'Report not found.', 'beyond-elysium' ), 404 );
		}

		return $document;
	}

	/**
	 * Same interception pattern as `Sheets_Controller::serve_pdf_bytes()`,
	 * matched by callback identity so no other route is affected.
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
		echo $data['bytes']; // phpcs:ignore -- raw binary PDF bytes, not HTML output.
		return true;
	}

	/**
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
