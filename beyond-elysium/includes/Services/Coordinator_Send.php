<?php

namespace BeyondElysium\Services;

defined( 'ABSPATH' ) || exit;

/**
 * How a Storyteller's send to a Coordinator goes in one chronicle. The HST's switch decides whether the choice is
 * offered; the connection decides whether it reaches the Archivist Toolkit; a demo chronicle only shows what would
 * be sent.
 */
class Coordinator_Send {

	public const LIVE    = 'live';
	public const PREVIEW = 'preview';

	/**
	 * The way a send goes here: `live` files the request in the Toolkit, `preview` shows what would be filed, and null
	 * when the switch is off or the chronicle cannot send.
	 */
	public static function mode( object $game ): ?string {
		if ( ( $game->settings->coordinator_send ?? false ) !== true ) {
			return null;
		}
		if ( Demo_Chronicle::is_demo( $game ) ) {
			return self::PREVIEW;
		}
		return Oat_Connection::available( $game ) ? self::LIVE : null;
	}

	/**
	 * Why the switch cannot be turned on here (an `Oat_Connection` reason), or null when it can: a demo chronicle
	 * previews, and a connected chronicle sends.
	 */
	public static function unavailable_reason( object $game ): ?string {
		if ( Demo_Chronicle::is_demo( $game ) ) {
			return null;
		}
		return Oat_Connection::status( $game )['reason'];
	}
}
