<?php

namespace BeyondElysium\REST;

use BeyondElysium\Services\Field_Registry;

defined( 'ABSPATH' ) || exit;

/**
 * REST controller that exposes the field registry backing the template editor and the query builder.
 */
class Query_Fields_Controller extends Base_Controller {

	protected $rest_base = 'query-fields';

	/**
	 * Registers the REST route for listing field registry entries.
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
						// The four inventories the query builder offers.
						'enum' => Field_Registry::QUERYABLE_INVENTORIES,
					],
				],
			],
		] );
	}

	/**
	 * Returns field registry entries as a flat list of key, title, type, and mapped status, optionally filtered to a
	 * single inventory type.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response
	 */
	public function get_items( $request ) {
		$inventory = $request->get_param( 'inventory' );

		$rows = $inventory ? Field_Registry::for_inventory( $inventory ) : Field_Registry::all();

		$items = array_values( array_map(
			static function ( array $row ) use ( $inventory ): array {
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
