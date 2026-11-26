<?php

namespace BeyondElysium\REST;

use BeyondElysium\Core\App_Layout;

defined( 'ABSPATH' ) || exit;

/**
 * REST controller for the home-screen manifest of My Chronicle and the Storyteller Toolkit. Public: a manifest names the
 * site and the page, and nothing about anyone.
 */
class App_Manifest_Controller extends Base_Controller {

	protected $rest_base = 'app-manifest';

	/**
	 * Registers the manifest route.
	 */
	public function register_routes(): void {
		register_rest_route( $this->namespace, '/' . $this->rest_base . '/(?P<page>player|storyteller)', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_item' ],
				'permission_callback' => '__return_true',
			],
		] );
	}

	/**
	 * One page's manifest, as `application/manifest+json`.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_item( $request ) {
		$manifest = App_Layout::manifest( (string) $request['page'] );
		if ( null === $manifest ) {
			return $this->error( 'not_found', __( 'This site has no such page.', 'beyond-elysium' ), 404 );
		}
		$response = $this->success( $manifest );
		$response->header( 'Content-Type', 'application/manifest+json; charset=' . get_option( 'blog_charset' ) );
		return $response;
	}
}
