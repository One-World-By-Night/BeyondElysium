<?php

namespace BeyondElysium\REST;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps a route's chronicle pinned to its URL.
 *
 * Authorization::check_request() reads `game_slug` from the URL alone, but
 * `$request['game_slug']` resolves the JSON body first, then the form body,
 * then the query string, and only then the URL. A different `game_slug` in
 * the query string or body used to reach the handler after the permission
 * check had passed against the URL - one chronicle's Storyteller reading and
 * writing another chronicle's data (1.0.0-review F-001). This refuses such a
 * request before its permission callback or handler runs.
 *
 * Only `game_slug` is pinned. Other URL parameters are either checked against
 * the pinned chronicle by the handler that loads them, or legitimately differ
 * in the body - renaming a game sends its new `slug`.
 */
class Url_Param_Guard {

	/** URL parameters that must never be overridden by the query string or body. */
	const PINNED = [ 'game_slug' ];

	/**
	 * Hooks the check onto every REST dispatch. Runs before WordPress calls a
	 * route's permission callback.
	 */
	public static function register(): void {
		add_filter( 'rest_request_before_callbacks', [ self::class, 'check' ], 5, 3 );
	}

	/**
	 * Returns a 400 error when this plugin's route carries a pinned URL
	 * parameter that the query string, form body, or JSON body contradicts;
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

		$url_params = $request->get_url_params();
		$sources    = [ $request->get_query_params(), $request->get_body_params(), (array) $request->get_json_params() ];

		foreach ( self::PINNED as $name ) {
			if ( ! array_key_exists( $name, $url_params ) ) {
				continue;
			}
			foreach ( $sources as $params ) {
				if ( ! array_key_exists( $name, $params ) ) {
					continue;
				}
				$value = $params[ $name ];
				if ( ! is_scalar( $value ) || (string) $value !== (string) $url_params[ $name ] ) {
					return new \WP_Error(
						'url_param_conflict',
						/* translators: %s: request parameter name */
						sprintf( __( 'The "%s" parameter must match the request URL.', 'beyond-elysium' ), $name ),
						[ 'status' => 400 ]
					);
				}
			}
		}

		return $response;
	}
}
