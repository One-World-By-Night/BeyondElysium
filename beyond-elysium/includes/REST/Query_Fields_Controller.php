<?php

namespace BeyondElysium\REST;

use BeyondElysium\Services\Field_Registry;

defined( 'ABSPATH' ) || exit;

/**
 * REST controller that exposes the field registry backing the template editor
 * and the query builder. Each entry describes one field available across the
 * game's data types (character, player, item, location, rote, plot, rumor,
 * action) along with its display title, data type, and whether it is mapped
 * to a concrete schema field.
 *
 * @see BE_PROCESS/workflow-0.3.md Step 0d
 */
class Query_Fields_Controller extends Base_Controller {

	protected $rest_base = 'query-fields';

	/**
	 * Registers the REST route for listing field registry entries. Exposes
	 * a single GET endpoint that accepts an optional inventory type to
	 * filter the returned fields.
	 */
	public function register_routes(): void {
		register_rest_route( $this->namespace, '/' . $this->rest_base, [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_items' ],
				'permission_callback' => $this->permission( 'be_view_characters' ),
				'args'                => [
					'inventory' => [
						'type' => 'string',
						'enum' => [ 'char', 'player', 'item', 'loc', 'rote', 'plot', 'rumor', 'action' ],
					],
				],
			],
		] );
	}

	/**
	 * Returns field registry entries as a flat list of key, title, type, and
	 * mapped status, optionally filtered to a single inventory type. Used by
	 * the template editor and query builder to present the fields available
	 * for the current data type.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response
	 */
	public function get_items( $request ) {
		$inventory = $request->get_param( 'inventory' );

		$rows = $inventory ? Field_Registry::for_inventory( $inventory ) : Field_Registry::all();

		$items = array_values( array_map(
			static function ( array $row ): array {
				return [
					'key'    => $row['key'],
					'title'  => $row['title'],
					'type'   => $row['type'],
					'mapped' => Field_Registry::is_mapped( $row['key'] ),
				];
			},
			$rows
		) );

		return $this->success( $items );
	}
}
