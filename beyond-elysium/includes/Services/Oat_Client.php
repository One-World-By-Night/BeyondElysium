<?php

namespace BeyondElysium\Services;

defined( 'ABSPATH' ) || exit;

/**
 * One call to the Archivist Toolkit's gateway, made for a named person. The network's key proves the site and the
 * person's account email names them, so the Toolkit applies its own rules to them as it does for every OWbN site's
 * Toolkit pages.
 */
class Oat_Client {

	public const TIMEOUT = 20;

	/**
	 * Posts a JSON body to one Toolkit gateway route as a person.
	 *
	 * @param array<mixed> $body
	 * @return array{ok:bool,status:int,code:string,message:string,data:array<mixed>,challenge:bool}
	 */
	public static function call( string $route, array $body, \WP_User $as ): array {
		$endpoint = Oat_Connection::endpoint();
		if ( $endpoint === null ) {
			return self::failure( 'oat_not_connected', __( 'This chronicle is not connected to the Archivist Toolkit.', 'beyond-elysium' ) );
		}
		if ( ! is_email( (string) $as->user_email ) ) {
			return self::failure( 'oat_no_email', __( 'Your account has no email address to act with in the Archivist Toolkit.', 'beyond-elysium' ) );
		}

		$player_id = defined( 'OWC_PLAYER_ID_META_KEY' ) ? (string) get_user_meta( (int) $as->ID, (string) constant( 'OWC_PLAYER_ID_META_KEY' ), true ) : '';
		$response  = wp_remote_post( $endpoint['base'] . 'oat/' . ltrim( $route, '/' ), [
			'timeout' => self::TIMEOUT,
			'headers' => self::request_headers( $endpoint['key'], (string) $as->user_email, $player_id ),
			'body'    => (string) wp_json_encode( $body ),
		] );
		if ( is_wp_error( $response ) ) {
			return self::failure( 'oat_unreachable', self::no_answer() );
		}

		$headers = [];
		foreach ( wp_remote_retrieve_headers( $response ) as $name => $value ) {
			$headers[ strtolower( (string) $name ) ] = is_array( $value ) ? implode( ',', $value ) : (string) $value;
		}
		return self::interpret( (int) wp_remote_retrieve_response_code( $response ), (string) wp_remote_retrieve_body( $response ), $headers );
	}

	/**
	 * The headers of a call: the site's key, and the person's email (and player id when they have one).
	 *
	 * @return array<string,string>
	 */
	public static function request_headers( string $key, string $email, string $player_id ): array {
		$headers = [
			'Content-Type'     => 'application/json',
			'x-api-key'        => $key,
			'x-oat-user-email' => $email,
		];
		if ( $player_id !== '' ) {
			$headers['x-oat-player-id'] = $player_id;
		}
		return $headers;
	}

	/**
	 * What an answer from the gateway says. A 2xx JSON object or list is an answer. A 4xx or 5xx JSON object is the
	 * Toolkit's own refusal, its message kept word for word. A hosting challenge, a page that is not JSON, a server
	 * error page or nothing at all is no answer.
	 *
	 * @param array<string,string> $headers Response headers by lower-case name.
	 * @return array{ok:bool,status:int,code:string,message:string,data:array<mixed>,challenge:bool}
	 */
	public static function interpret( int $status, string $body, array $headers = [] ): array {
		$headers = array_change_key_case( $headers, CASE_LOWER );
		if ( $status === 202 || ( $headers['sg-captcha'] ?? '' ) !== '' ) {
			$result              = self::failure( 'oat_unreachable', self::no_answer() );
			$result['status']    = $status;
			$result['challenge'] = true;
			return $result;
		}

		$decoded = json_decode( $body, true );
		if ( ! is_array( $decoded ) ) {
			$code   = ( $status === 0 || $status >= 500 ) ? 'oat_unreachable' : 'oat_not_json';
			$result = self::failure( $code, self::no_answer() );
			$result['status'] = $status;
			return $result;
		}

		if ( $status >= 400 ) {
			$result            = self::failure(
				(string) ( $decoded['code'] ?? 'oat_refused' ),
				(string) ( $decoded['message'] ?? __( 'The Archivist Toolkit refused this.', 'beyond-elysium' ) )
			);
			$result['status'] = $status;
			return $result;
		}
		if ( $status < 200 || $status >= 300 ) {
			$result           = self::failure( 'oat_unreachable', self::no_answer() );
			$result['status'] = $status;
			return $result;
		}

		return [ 'ok' => true, 'status' => $status, 'code' => '', 'message' => '', 'data' => $decoded, 'challenge' => false ];
	}

	/**
	 * @return array{ok:bool,status:int,code:string,message:string,data:array<mixed>,challenge:bool}
	 */
	private static function failure( string $code, string $message ): array {
		return [ 'ok' => false, 'status' => 0, 'code' => $code, 'message' => $message, 'data' => [], 'challenge' => false ];
	}

	private static function no_answer(): string {
		return __( "The Archivist Toolkit didn't answer. Try again in a minute.", 'beyond-elysium' );
	}
}
