<?php

namespace BeyondElysium\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Whether a chronicle can send to the Archivist Toolkit, and where. A chronicle sends when its accessSchema path names
 * an OWbN chronicle (`chronicle/kony`), owbn-core is running, and the network's main site holds the Toolkit
 * connection (remote mode, an address and a key). The key is read when a call is made and is never stored, copied,
 * logged or returned.
 */
class Oat_Connection {

	/**
	 * Why a chronicle cannot send.
	 */
	public const NO_PATH       = 'no_path';
	public const NO_CORE       = 'no_core';
	public const NO_CONNECTION = 'no_connection';

	/**
	 * The OWbN chronicle slug a game names through its accessSchema path (`chronicle/kony` reads as `kony`), or null
	 * when the path names no chronicle.
	 */
	public static function chronicle_slug( object $game ): ?string {
		$path = strtolower( trim( (string) ( $game->asc_role_path ?? '' ) ) );
		if ( preg_match( '#^chronicle/([a-z0-9][a-z0-9\-]*)/?$#', $path, $found ) !== 1 ) {
			return null;
		}
		return $found[1];
	}

	/**
	 * Whether a chronicle sends to the Toolkit, and when it does not, the reason.
	 *
	 * @return array{available:bool,reason:?string,host:?string,chronicle:?string}
	 */
	public static function status( object $game ): array {
		$chronicle = self::chronicle_slug( $game );
		if ( $chronicle === null ) {
			return [ 'available' => false, 'reason' => self::NO_PATH, 'host' => null, 'chronicle' => null ];
		}
		if ( ! self::core_running() ) {
			return [ 'available' => false, 'reason' => self::NO_CORE, 'host' => null, 'chronicle' => $chronicle ];
		}
		$endpoint = self::endpoint();
		if ( $endpoint === null ) {
			return [ 'available' => false, 'reason' => self::NO_CONNECTION, 'host' => null, 'chronicle' => $chronicle ];
		}
		return [
			'available' => true,
			'reason'    => null,
			'host'      => (string) wp_parse_url( $endpoint['base'], PHP_URL_HOST ),
			'chronicle' => $chronicle,
		];
	}

	public static function available( object $game ): bool {
		return self::status( $game )['available'];
	}

	/**
	 * Why a chronicle cannot send, in words.
	 */
	public static function reason_text( string $reason ): string {
		switch ( $reason ) {
			case self::NO_PATH:
				return __( 'This chronicle has no OWbN chronicle path.', 'beyond-elysium' );
			case self::NO_CORE:
				return __( 'owbn-core is not running on this site.', 'beyond-elysium' );
			default:
				return __( 'This network has no Archivist Toolkit connection.', 'beyond-elysium' );
		}
	}

	/**
	 * The Toolkit gateway's address and this network's key, from the main site's owbn-core settings, or null when the
	 * main site is not a remote-mode Toolkit client with both set.
	 *
	 * @return array{base:string,key:string}|null
	 */
	public static function endpoint(): ?array {
		if ( ! function_exists( 'owc_get_mode' ) || ! function_exists( 'owc_get_remote_base' ) || ! function_exists( 'owc_get_remote_key' ) ) {
			return null;
		}

		$switched = is_multisite() && ! is_main_site();
		if ( $switched ) {
			switch_to_blog( (int) get_main_site_id() );
		}
		try {
			$mode = (string) owc_get_mode( 'oat' );
			$base = (string) owc_get_remote_base( 'oat' );
			$key  = (string) owc_get_remote_key( 'oat' );
		} finally {
			if ( $switched ) {
				restore_current_blog();
			}
		}

		if ( $mode !== 'remote' || $base === '' || $key === '' ) {
			return null;
		}
		return [ 'base' => $base, 'key' => $key ];
	}

	/**
	 * The page of a Toolkit request, reached through single sign-on, or null when the connection is not there.
	 */
	public static function entry_url( int $entry_id ): ?string {
		$endpoint = self::endpoint();
		if ( $endpoint === null || $entry_id <= 0 ) {
			return null;
		}
		$site = (string) preg_replace( '#/wp-json/owbn/v1/?$#', '', $endpoint['base'] );
		return rtrim( $site, '/' ) . '/?auth=sso&redirect_uri=' . rawurlencode( '/oat-entry/?oat_entry=' . $entry_id );
	}

	private static function core_running(): bool {
		return function_exists( 'owc_get_mode' ) && function_exists( 'owc_get_remote_base' ) && function_exists( 'owc_get_remote_key' );
	}
}
