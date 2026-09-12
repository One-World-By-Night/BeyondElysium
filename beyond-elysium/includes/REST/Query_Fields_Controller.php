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
						// Narrowed from all eight qkdata inventories to the four the query
						// builder actually offers - the other four (player, plot, rumor,
						// action) can only ever return an empty field list: qkdata.gvd
						// declares zero keys for plot/rumor/action, and there is no Player
						// entity in Beyond Elysium (query-beyond-characters-design.md §5.5).
						'enum' => Field_Registry::QUERYABLE_INVENTORIES,
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
			static function ( array $row ) use ( $inventory ): array {
				// Falls back to 'char' only for the no-inventory ("every field") case;
				// $inventory is otherwise always one of the four validated by the route's
				// own enum. Without this, ?inventory=loc reported every location field as
				// unmapped, since is_mapped() defaulted to reading the char-only map.
				$scope = $inventory ?: 'char';
				return [
					'key'    => $row['key'],
					'title'  => $row['title'],
					'type'   => Field_Registry::type_for( $row['key'], $scope ),
					'mapped' => Field_Registry::is_mapped( $row['key'], $scope ),
				];
			},
			$rows
		) );

		return $this->success( $items );
	}
}
