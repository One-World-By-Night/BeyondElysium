<?php

namespace BeyondElysium\REST;

use BeyondElysium\Models\Character;
use BeyondElysium\Models\Creature_Stack;
use BeyondElysium\Services\Pdf_Signer;
use BeyondElysium\Services\Report_Document;
use BeyondElysium\Services\Report_Writer;

defined( 'ABSPATH' ) || exit;

/**
 * REST controller for the 19 reports: `GET /{game_slug}/reports` (the
 * registry, for the Reports admin page) and `GET /{game_slug}/reports/{report_key}/pdf`.
 *
 * `be_view_reports` gates the routes, and every real chronicle role holds it;
 * each report then needs its own capability (`Report_Document::required_capability()`):
 * character and player reports are a Storyteller's, plot, action, and rumor
 * reports need `be_manage_plots`, and only catalog cards, the calendar, and
 * House Rules are open to every member (1.0.0-review F-047). The list shows a
 * caller only the reports they may run.
 *
 * @see BE_PROCESS/design/reports-cards-batch-design.md §3.5
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

		register_rest_route( $this->namespace, '/(?P<game_slug>[a-z0-9\-]+)/reports/availability', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_availability' ],
				'permission_callback' => $this->permission( 'be_view_reports' ),
				'args'                => [
					'character_id' => [ 'type' => 'integer', 'required' => false ],
				],
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
			if ( ! self::may_run( $report ) ) {
				continue;
			}
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

		$document = $this->build_document_from_request( $request );
		if ( is_wp_error( $document ) ) {
			return $document;
		}

		// With no certificate, or with secure printing switched off (1.0.1 C2), a report still
		// prints, stamped UNSIGNED (1.0.0-review F-042).
		$signed   = Pdf_Signer::should_sign()['ok'];
		$bytes    = Report_Writer::write( $document, $game, $signed );
		$filename = sanitize_file_name( $request['game_slug'] . '-' . (string) $request['report_key'] ) . ( $signed ? '' : '-unsigned' ) . '.pdf';

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
		if ( ! self::may_run( Report_Document::registry()[ $report_key ] ) ) {
			return $this->error( 'report_forbidden', __( 'You do not have permission to run this report.', 'beyond-elysium' ), 403 );
		}

		$raw_conditions = (string) $request->get_param( 'conditions' );
		$conditions     = $raw_conditions !== '' ? json_decode( $raw_conditions, true ) : [];
		if ( ! is_array( $conditions ) ) {
			return $this->error( 'invalid_request', __( 'conditions must be valid JSON.', 'beyond-elysium' ), 400 );
		}

		$can_manage   = \BeyondElysium\Core\Authorization::can( 'be_manage_characters' );
		$filters      = [ 'conditions' => $conditions, 'logic' => (string) $request->get_param( 'logic' ) ];
		$holder_block = Report_Document::registry()[ $report_key ]['holder_block'] ?? null;

		$character_id = $request->get_param( 'character_id' );

		// 1.1.0 §3.15, C1 - a non-manager needs a character to scope this report to at all;
		// a manager may still run it unscoped (every rote in the chronicle).
		if ( $holder_block !== null && ! $can_manage && ( $character_id === null || $character_id === '' ) ) {
			return $this->error( 'character_required', __( 'character_id is required.', 'beyond-elysium' ), 400 );
		}

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
			if ( $holder_block !== null && ! $can_manage && ! self::stack_has_block( $character, $holder_block ) ) {
				return $this->error(
					'report_not_available',
					__( 'This report is not available for this character.', 'beyond-elysium' ),
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
	 * Whether the current caller holds the capability a report needs in the
	 * chronicle being served.
	 *
	 * @param array<string,mixed> $report A registry row.
	 */
	private static function may_run( array $report ): bool {
		$capability = Report_Document::required_capability( $report );
		return $capability === null || \BeyondElysium\Core\Authorization::can( $capability );
	}

	/**
	 * Whether a character's resolved stack includes a given block among its own
	 * `stack_definition->sections` (1.1.0 §3.15, C1) - the same section-scan shape
	 * `Cost_Engine`/`Change_Validator`/`Layout_Generator` already use for "does this stack
	 * have this block", never a creature-type check (the engine pattern, kept).
	 *
	 * @param object $character
	 * @param string $block_slug
	 * @return bool
	 */
	private static function stack_has_block( object $character, string $block_slug ): bool {
		$resolved = Creature_Stack::resolve( (string) $character->stack_slug, (string) $character->owner_slug );
		$stack    = $resolved['stack'] ?? null;
		foreach ( ( $stack->stack_definition->sections ?? [] ) as $section ) {
			if ( ( $section->block_slug ?? null ) === $block_slug ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * `GET /{game_slug}/reports/availability?character_id=` (1.1.0 §3.15, C1) - whether each
	 * card report (item-cards, location-cards, rote-cards) is available for the given
	 * character. A manager always sees every card report available; for a non-manager, only
	 * rote-cards (the one card report with a `holder_block`) can ever be unavailable - the
	 * others have no such gate.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_availability( $request ) {
		$game = $this->resolve_game( $request['game_slug'] );
		if ( is_wp_error( $game ) ) {
			return $game;
		}

		$can_manage   = \BeyondElysium\Core\Authorization::can( 'be_manage_characters' );
		$character_id = $request->get_param( 'character_id' );
		$character    = $character_id ? Character::find( (int) $character_id ) : null;
		if ( $character && $character->owner_slug !== $request['game_slug'] ) {
			$character = null;
		}

		$result = [];
		foreach ( Report_Document::registry() as $key => $report ) {
			if ( ( $report['shape'] ?? null ) !== 'card' || ! self::may_run( $report ) ) {
				continue;
			}
			$holder_block = $report['holder_block'] ?? null;
			$result[ $key ] = $can_manage
				|| $holder_block === null
				|| ( $character !== null && self::stack_has_block( $character, $holder_block ) );
		}

		return $this->success( $result );
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
