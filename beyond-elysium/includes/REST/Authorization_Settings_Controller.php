<?php

namespace BeyondElysium\REST;

use BeyondElysium\Core\Authorization;

defined( 'ABSPATH' ) || exit;

/**
 * REST controller for the site-wide authorization mode setting.
 *
 * Exposes a single GET/PUT endpoint pair for the `be_asc_enabled` toggle,
 * which switches the plugin between its two authorization modes. The setting
 * is not game-scoped: it applies across the whole site and is gated by the
 * `be_manage_games` capability, the same as other site-level actions.
 */
class Authorization_Settings_Controller extends Base_Controller {

	protected $rest_base = 'authorization-settings';

	/**
	 * Registers the GET and PUT routes for the authorization settings resource.
	 *
	 * Both routes address the same single, unparameterized settings object
	 * and share the same `be_manage_games` permission check.
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
	 * Reports whether ASC-based authorization is enabled and whether the ASC
	 * client functions are present, so an operator can distinguish "mode is
	 * off" from "mode is on but nothing is installed to back it."
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
	 * Writes the `asc_enabled` request parameter to the `be_asc_enabled`
	 * option, then delegates to `get_item()` to build the response so both
	 * endpoints always return the same shape.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response
	 */
	public function update_item( $request ) {
		update_option( 'be_asc_enabled', (bool) $request->get_param( 'asc_enabled' ) );
		return $this->get_item( $request );
	}
}
