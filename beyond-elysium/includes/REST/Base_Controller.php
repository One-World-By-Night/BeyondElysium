<?php

namespace BeyondElysium\REST;

use BeyondElysium\Core\Authorization;

defined( 'ABSPATH' ) || exit;

/**
 * Abstract base class for all Beyond Elysium REST controllers.
 */
abstract class Base_Controller extends \WP_REST_Controller {

	/**
	 * REST namespace.
	 */
	protected $namespace = 'be/v1';

	/**
	 * Builds a standard success response.
	 *
	 * @param mixed $data    Response data.
	 * @param int   $status  HTTP status code.
	 * @return \WP_REST_Response
	 */
	protected function success( $data, int $status = 200 ): \WP_REST_Response {
		return new \WP_REST_Response( $data, $status );
	}

	/**
	 * Builds a standard error response.
	 *
	 * @param string $code    Error code.
	 * @param string $message Error message.
	 * @param int    $status  HTTP status code.
	 * @return \WP_Error
	 */
	protected function error( string $code, string $message, int $status = 400 ): \WP_Error {
		return new \WP_Error( $code, $message, [ 'status' => $status ] );
	}

	/**
	 * Builds a permission callback that checks a single WordPress capability.
	 *
	 * @param string $capability      WordPress capability.
	 * @param bool   $allow_bootstrap See `Authorization::check_request()`.
	 * @return callable
	 */
	protected function permission( string $capability, bool $allow_bootstrap = false ): callable {
		return function ( \WP_REST_Request $request ) use ( $capability, $allow_bootstrap ) {
			return Authorization::check_request( $capability, $request, $allow_bootstrap )
				? true
				: Authorization::denied();
		};
	}

	/**
	 * Builds a permission callback that passes if the current user holds any one of the given capabilities.
	 *
	 * @param string[] $capabilities
	 * @return callable
	 */
	protected function permission_any( array $capabilities ): callable {
		return function ( \WP_REST_Request $request ) use ( $capabilities ) {
			foreach ( $capabilities as $capability ) {
				if ( Authorization::check_request( $capability, $request ) ) {
					return true;
				}
			}
			return Authorization::denied();
		};
	}

	/**
	 * Builds a permission callback that passes only if the current user holds every one of the given capabilities.
	 *
	 * @param string[] $capabilities
	 * @return callable
	 */
	protected function permission_all( array $capabilities ): callable {
		return function ( \WP_REST_Request $request ) use ( $capabilities ) {
			foreach ( $capabilities as $capability ) {
				if ( ! Authorization::check_request( $capability, $request ) ) {
					return Authorization::denied();
				}
			}
			return true;
		};
	}

	/**
	 * Resolves pagination parameters from the request, applying defaults and limits.
	 *
	 * @param \WP_REST_Request $request
	 * @param int              $max
	 * @return array{ page: int, per_page: int, offset: int }
	 */
	protected function get_pagination( \WP_REST_Request $request, int $max = 100 ): array {
		$page     = max( 1, (int) $request->get_param( 'page' ) ?: 1 );
		$per_page = min( $max, max( 1, (int) $request->get_param( 'per_page' ) ?: 20 ) );
		$offset   = ( $page - 1 ) * $per_page;

		return compact( 'page', 'per_page', 'offset' );
	}

	/**
	 * Adds pagination headers to a response.
	 *
	 * @param \WP_REST_Response $response
	 * @param int               $total       Total items.
	 * @param int               $per_page    Items per page.
	 * @param int               $page        Current page.
	 * @return \WP_REST_Response
	 */
	protected function paginate( \WP_REST_Response $response, int $total, int $per_page, int $page ): \WP_REST_Response {
		$total_pages = (int) ceil( $total / $per_page );

		// WP_HTTP_Response::header() expects string values.
		$response->header( 'X-WP-Total', (string) $total );
		$response->header( 'X-WP-TotalPages', (string) $total_pages );

		return $response;
	}
}
