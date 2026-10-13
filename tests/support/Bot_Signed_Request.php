<?php

namespace BeyondElysium\Tests\Support;

use BeyondElysium\Core\Bot_Signing;
use WP_REST_Request;

/**
 * Builds a `WP_REST_Request` signed the way a bot signs one, for dispatching through the real REST server.
 */
class Bot_Signed_Request {

	/**
	 * @param string               $method
	 * @param string               $route
	 * @param int                  $connection_id
	 * @param string               $secret         Raw bytes.
	 * @param string               $discord_user_id Empty string for a service call.
	 * @param array<string,string> $query
	 * @param string|null          $body
	 * @param int|null             $timestamp
	 * @param string|null          $nonce
	 * @return WP_REST_Request
	 */
	public static function build(
		string $method,
		string $route,
		int $connection_id,
		string $secret,
		string $discord_user_id = '',
		array $query = [],
		?string $body = null,
		?int $timestamp = null,
		?string $nonce = null
	): WP_REST_Request {
		$timestamp = $timestamp ?? time();
		$nonce     = $nonce ?? bin2hex( random_bytes( 16 ) );
		$body      = $body ?? '';

		$request = new WP_REST_Request( $method, $route );
		foreach ( $query as $key => $value ) {
			$request->set_param( $key, $value );
		}
		if ( $body !== '' ) {
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( $body );
		}

		$canonical_query = (string) Bot_Signing::canonical_query( $query );
		$canonical       = Bot_Signing::canonical_string(
			$method,
			$route,
			$canonical_query,
			(string) $timestamp,
			$nonce,
			(string) $connection_id,
			$discord_user_id,
			$body
		);

		$request->set_header( 'X-BE-Bot-Connection', (string) $connection_id );
		$request->set_header( 'X-BE-Bot-Timestamp', (string) $timestamp );
		$request->set_header( 'X-BE-Bot-Nonce', $nonce );
		if ( $discord_user_id !== '' ) {
			$request->set_header( 'X-BE-Bot-User', $discord_user_id );
		}
		$request->set_header( 'X-BE-Bot-Signature', Bot_Signing::sign( $canonical, $secret ) );

		return $request;
	}
}
