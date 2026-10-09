<?php

namespace BeyondElysium\REST;

use BeyondElysium\Models\Game;
use BeyondElysium\Models\Mail_Log;

defined( 'ABSPATH' ) || exit;

/**
 * REST controller for a chronicle's mail log, for that chronicle's Storytellers: who the plugin emailed, what about, and
 * whether the mail system took it - plus the emails it chose not to send, with the reason.
 */
class Mail_Log_Controller extends Base_Controller {

	protected $rest_base = 'mail-log';

	/**
	 * How far back each period choice reaches, in seconds.
	 */
	private const PERIODS = [
		'day'   => DAY_IN_SECONDS,
		'week'  => WEEK_IN_SECONDS,
		'month' => 30 * DAY_IN_SECONDS,
	];

	/**
	 * Registers the mail log routes.
	 */
	public function register_routes(): void {
		register_rest_route( $this->namespace, '/(?P<game_slug>[a-z0-9\-]+)/' . $this->rest_base, [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_items' ],
				'permission_callback' => $this->permission( 'be_manage_characters' ),
				'args'                => [
					'page'        => [ 'type' => 'integer', 'minimum' => 1, 'default' => 1 ],
					'per_page'    => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 20 ],
					'search'      => [ 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
					'kind'        => [ 'type' => 'string', 'sanitize_callback' => 'sanitize_key' ],
					'result'      => [ 'type' => 'string', 'sanitize_callback' => 'sanitize_key' ],
					'since'       => [ 'type' => 'string', 'enum' => [ 'day', 'week', 'month', 'all' ] ],
					'entity_type' => [ 'type' => 'string', 'sanitize_callback' => 'sanitize_key' ],
					'entity_id'   => [ 'type' => 'integer', 'minimum' => 1 ],
					'wp_user_id'  => [ 'type' => 'integer', 'minimum' => 1 ],
				],
			],
		] );

		register_rest_route( $this->namespace, '/(?P<game_slug>[a-z0-9\-]+)/' . $this->rest_base . '/options', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_options' ],
				'permission_callback' => $this->permission( 'be_manage_characters' ),
			],
		] );
	}

	/**
	 * One page of the chronicle's log, newest first.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_items( $request ) {
		$game = Game::find_by_slug( (string) $request['game_slug'] );
		if ( ! $game ) {
			return $this->error( 'not_found', __( 'Game not found.', 'beyond-elysium' ), 404 );
		}

		$pagination = $this->get_pagination( $request );
		$filters    = [
			'search'      => (string) $request->get_param( 'search' ),
			'kind'        => (string) $request->get_param( 'kind' ),
			'result'      => (string) $request->get_param( 'result' ),
			'since'       => self::PERIODS[ (string) $request->get_param( 'since' ) ] ?? 0,
			'entity_type' => (string) $request->get_param( 'entity_type' ),
			'entity_id'   => (int) $request->get_param( 'entity_id' ),
			'wp_user_id'  => (int) $request->get_param( 'wp_user_id' ),
		];

		$rows  = Mail_Log::for_game( (int) $game->id, $filters, $pagination['per_page'], $pagination['offset'] );
		$total = Mail_Log::count_for_game( (int) $game->id, $filters );

		$kinds   = Mail_Log::kinds();
		$results = Mail_Log::results();
		$labels  = [];
		$items   = [];
		foreach ( $rows as $row ) {
			$entity = $row->entity_type . ':' . $row->entity_id;
			if ( ! isset( $labels[ $entity ] ) ) {
				$labels[ $entity ] = Mail_Log::entity_label( (string) $row->entity_type, (int) $row->entity_id );
			}
			$items[] = [
				'id'              => (int) $row->id,
				'created_at'      => (string) $row->created_at,
				'wp_user_id'      => (int) $row->wp_user_id,
				'recipient_name'  => (string) $row->recipient_name,
				'recipient_email' => (string) $row->recipient_email,
				'kind'            => (string) $row->kind,
				'kind_label'      => $kinds[ $row->kind ] ?? (string) $row->kind,
				'subject'         => (string) $row->subject,
				'result'          => (string) $row->result,
				'result_label'    => $results[ $row->result ] ?? (string) $row->result,
				'reason'          => (string) $row->reason,
				'reason_label'    => Mail_Log::reason_label( (string) $row->reason ),
				'error'           => (string) $row->error,
				'entity_type'     => (string) $row->entity_type,
				'entity_id'       => (int) $row->entity_id,
				'entity_label'    => $labels[ $entity ],
			];
		}

		return $this->paginate( $this->success( $items ), $total, $pagination['per_page'], $pagination['page'] );
	}

	/**
	 * What the screen's filters offer.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response
	 */
	public function get_options( $request ) {
		$options = static function ( array $labels ): array {
			$list = [];
			foreach ( $labels as $key => $label ) {
				$list[] = [ 'key' => (string) $key, 'label' => (string) $label ];
			}
			return $list;
		};

		return $this->success( [
			'kinds'          => $options( Mail_Log::kinds() ),
			'results'        => $options( Mail_Log::results() ),
			'periods'        => $options( [
				'day'   => __( 'Last 24 hours', 'beyond-elysium' ),
				'week'  => __( 'Last 7 days', 'beyond-elysium' ),
				'month' => __( 'Last 30 days', 'beyond-elysium' ),
				'all'   => __( 'All kept', 'beyond-elysium' ),
			] ),
			'retention_days' => Mail_Log::RETENTION_DAYS,
		] );
	}
}
