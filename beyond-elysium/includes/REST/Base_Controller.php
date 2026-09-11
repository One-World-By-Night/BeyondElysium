<?php

namespace BeyondElysium\REST;

use BeyondElysium\Core\Authorization;

defined( 'ABSPATH' ) || exit;

/**
 * Abstract base class for all Beyond Elysium REST controllers.
 *
 * Defines the shared REST namespace, standard success/error response
 * helpers, chronicle-scoped permission-callback builders, and pagination
 * helpers that every concrete controller in this plugin builds on.
 */
abstract class Base_Controller extends \WP_REST_Controller {

	/**
	 * REST namespace.
	 */
	protected $namespace = 'be/v1';

	/**
	 * Builds a standard success response.
	 *
	 * Wraps the given data and HTTP status in a `WP_REST_Response` object.
	 * Every controller in this plugin returns its successful results through
	 * this helper so they share one consistent response shape.
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
	 * Wraps a machine-readable error code, a human-readable message, and an
	 * HTTP status into a `WP_Error` object. Every controller in this plugin
	 * returns its failures through this helper so they share one consistent
	 * shape.
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
	 * The returned closure receives the current `WP_REST_Request` and
	 * delegates to `Authorization::check_request()`, which resolves the
	 * capability check against the chronicle named by the request's
	 * `game_slug`. `$allow_bootstrap` is passed straight through for the one
	 * route that must let a user reach a chronicle before they hold any
	 * membership in it.
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
	 * Builds a permission callback that passes if the current user holds any
	 * one of the given capabilities.
	 *
	 * Checks each capability in turn via `Authorization::check_request()`
	 * and returns true on the first match, denying only once none of them
	 * pass. Chronicle-scoped the same way `permission()` is.
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
	 * Builds a permission callback that passes only if the current user
	 * holds every one of the given capabilities.
	 *
	 * Checks each capability in turn via `Authorization::check_request()`
	 * and denies as soon as one fails, requiring all of them to pass.
	 * Chronicle-scoped the same way `permission()` is.
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
	 * Reads `page` and `per_page` from the request, defaulting to page 1 and
	 * 20 items per page, clamping `per_page` to a maximum of 100, and
	 * computing the row offset those two values imply.
	 *
	 * @param \WP_REST_Request $request
	 * @return array{ page: int, per_page: int, offset: int }
	 */
	protected function get_pagination( \WP_REST_Request $request ): array {
		$page     = max( 1, (int) $request->get_param( 'page' ) ?: 1 );
		$per_page = min( 100, max( 1, (int) $request->get_param( 'per_page' ) ?: 20 ) );
		$offset   = ( $page - 1 ) * $per_page;

		return compact( 'page', 'per_page', 'offset' );
	}

	/**
	 * Adds pagination headers to a response.
	 *
	 * Computes the total page count from the given total and per-page
	 * values, then sets the `X-WP-Total` and `X-WP-TotalPages` headers on
	 * the response. The `$page` parameter is accepted for a consistent call
	 * signature but is not itself used to compute either header.
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
