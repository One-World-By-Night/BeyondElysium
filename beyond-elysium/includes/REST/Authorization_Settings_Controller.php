<?php

namespace BeyondElysium\REST;

use BeyondElysium\Core\Authorization;

defined( 'ABSPATH' ) || exit;

/**
 * REST controller for the site-wide authorization mode setting.
 */
class Authorization_Settings_Controller extends Base_Controller {

	protected $rest_base = 'authorization-settings';

	/**
	 * Registers the GET and PUT routes for the authorization settings resource.
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
	}

	/**
	 * Returns the current authorization settings.
	 *
	 * @param \WP_REST_Request $request Unused - this resource is a single, unparameterized
	 *                                  settings object, but the base class's signature
	 *                                  still requires accepting it.
	 * @return \WP_REST_Response
	 */
	public function get_item( $request ) {
		return $this->success( [
			'asc_enabled'     => Authorization::asc_enabled(),
			// Whether the owbn-client functions this mode depends on are registered at all.
			'client_detected' => function_exists( 'owc_asc_register_client' ) && function_exists( 'owc_asc_check_access' ),
		] );
	}

	/**
	 * Updates the authorization mode setting and returns the new state.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response
	 */
	public function update_item( $request ) {
		update_option( 'be_asc_enabled', (bool) $request->get_param( 'asc_enabled' ) );
		return $this->get_item( $request );
	}
}
