<?php

namespace BeyondElysium\REST;

use BeyondElysium\Database\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * REST controller for the site-wide uninstall-data setting and a full data export.
 */
class Data_Management_Controller extends Base_Controller {

	protected $rest_base = 'data-management';

	const DELETE_OPTION = 'be_delete_data_on_uninstall';

	/**
	 * Registers the settings routes and the export route, all gated on `be_manage_games`.
	 */
	public function register_routes(): void {
		register_rest_route( $this->namespace, '/' . $this->rest_base, [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_item' ],
				'permission_callback' => $this->permission( 'be_manage_games' ),
			],
			[
				'methods'             => 'PUT',
				'callback'            => [ $this, 'update_item' ],
				'permission_callback' => $this->permission( 'be_manage_games' ),
			],
		] );

		register_rest_route( $this->namespace, '/' . $this->rest_base . '/export', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'export' ],
				'permission_callback' => $this->permission( 'be_manage_games' ),
			],
		] );
	}

	/**
	 * Returns whether an uninstall is currently set to delete plugin data.
	 *
	 * @param \WP_REST_Request $request Unused - see Authorization_Settings_Controller::get_item() for why the base class still requires it.
	 * @return \WP_REST_Response
	 */
	public function get_item( $request ) {
		return $this->success( [
			'delete_on_uninstall' => (bool) get_option( self::DELETE_OPTION, false ),
		] );
	}

	/**
	 * Updates the delete-on-uninstall setting and returns the new state.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response
	 */
	public function update_item( $request ) {
		update_option( self::DELETE_OPTION, (bool) $request->get_param( 'delete_on_uninstall' ) );
		return $this->get_item( $request );
	}

	/**
	 * Returns every row of every plugin table as one JSON object, keyed by table name.
	 *
	 * @param \WP_REST_Request $request Unused - see get_item()'s own note.
	 * @return \WP_REST_Response
	 */
	public function export( $request ) {
		global $wpdb;

		$data = [
			'exported_at'  => current_time( 'mysql' ),
			'plugin_version' => defined( 'BE_VERSION' ) ? BE_VERSION : null,
			'tables'       => [],
		];

		foreach ( Schema::TABLES as $table ) {
			$full_name = $wpdb->prefix . 'be_' . $table;
			$data['tables'][ $table ] = $wpdb->get_results( "SELECT * FROM {$full_name}", ARRAY_A );
		}

		return $this->success( $data );
	}
}
