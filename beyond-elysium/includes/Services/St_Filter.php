<?php

namespace BeyondElysium\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Strips ST-only text from a field, transcribed from `GameClass.STFilter` (VB6).
 */
class St_Filter {

	/**
	 * Removes every section of text between a start marker and an end marker, scanning left to right and copying through
	 * any text outside a marked section.
	 *
	 * @param string $text
	 * @param string $start_marker
	 * @param string $end_marker
	 * @return string
	 */
	public static function strip( string $text, string $start_marker, string $end_marker ): string {
		if ( $start_marker === '' || $end_marker === '' ) {
			return $text;
		}

		$result = '';
		$pos    = 0;
		$len    = strlen( $text );

		while ( $pos < $len ) {
			$start = strpos( $text, $start_marker, $pos );
			if ( $start === false ) {
				$result .= substr( $text, $pos );
				break;
			}

			$result .= substr( $text, $pos, $start - $pos );

			$end = strpos( $text, $end_marker, $start + strlen( $start_marker ) );
			if ( $end === false ) {
				// An unterminated opener hides everything to the end of the string.
				break;
			}

			$pos = $end + strlen( $end_marker );
		}

		return trim( $result );
	}

	/**
	 * Strips ST sections from a field using a game's configured markers.
	 *
	 * @param string      $text
	 * @param object|null $game_settings Decoded `be_games.settings`, or null.
	 * @return string
	 */
	public static function strip_for_game( string $text, $game_settings ): string {
		$start = (string) ( $game_settings->st_comment_start ?? '' );
		$end   = (string) ( $game_settings->st_comment_end ?? '' );
		return self::strip( $text, $start !== '' ? $start : '[ST]', $end !== '' ? $end : '[/ST]' );
	}

	/**
	 * Same as strip_for_game(), for a field that may hold HTML.
	 *
	 * @param string      $html
	 * @param object|null $game_settings Decoded `be_games.settings`, or null.
	 * @return string
	 */
	public static function strip_html_for_game( string $html, $game_settings ): string {
		return force_balance_tags( wp_kses_post( self::strip_for_game( $html, $game_settings ) ) );
	}
}
