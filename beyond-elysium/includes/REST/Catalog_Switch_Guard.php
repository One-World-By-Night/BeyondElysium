<?php

namespace BeyondElysium\REST;

use BeyondElysium\Services\Catalog_Cutover;

defined( 'ABSPATH' ) || exit;

/**
 * Refuses every save to the plugin's REST API while a catalog cutover run holds its lock.
 *
 * `apply()` re-keys one character at a time and flips the install last, and until the flip
 * `live_slug()` is the identity function. A change approved in between is written to the retired
 * block of a sheet that has already moved: invisible on the declared sheet afterwards, with its XP
 * already spent, and `apply()` reports nothing. A run takes seconds, so the saves are refused
 * instead (503, try again); reads are not. The refusal ends by itself if a run dies holding the lock.
 */
class Catalog_Switch_Guard {

	/** HTTP methods that read and change nothing. */
	const READ_METHODS = [ 'GET', 'HEAD', 'OPTIONS' ];

	/**
	 * Hooks the check onto every REST dispatch, just after `Url_Param_Guard` and before WordPress
	 * calls a route's permission callback.
	 */
	public static function register(): void {
		add_filter( 'rest_request_before_callbacks', [ self::class, 'check' ], 6, 3 );
	}

	/**
	 * Returns a 503 error for a write to this plugin's routes while a cutover run is in flight;
	 * otherwise passes the dispatch through untouched.
	 *
	 * @param mixed            $response Null, or an earlier filter's result.
	 * @param array            $handler  The matched route handler.
	 * @param \WP_REST_Request $request
	 * @return mixed
	 */
	public static function check( $response, $handler, $request ) {
		if ( is_wp_error( $response ) || ! $request instanceof \WP_REST_Request ) {
			return $response;
		}
		if ( strpos( (string) $request->get_route(), '/be/v1/' ) !== 0 ) {
			return $response;
		}
		if ( in_array( strtoupper( $request->get_method() ), self::READ_METHODS, true ) ) {
			return $response;
		}
		if ( ! Catalog_Cutover::switching() ) {
			return $response;
		}

		return new \WP_Error(
			'catalog_switch_in_progress',
			__( "The site is being moved to the new catalog and can't save anything for a moment. Try again in a minute.", 'beyond-elysium' ),
			[ 'status' => 503 ]
		);
	}
}
