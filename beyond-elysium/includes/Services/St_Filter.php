<?php

namespace BeyondElysium\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Strips ST-only text from a field, transcribed from `GameClass.STFilter` (VB6).
 *
 * The markers are configurable per game rather than a fixed `[ST]`/`[/ST]` pair -
 * they live on `be_games.settings.st_comment_start` / `st_comment_end`, defaulting
 * to `[ST]` / `[/ST]`. An empty marker means filtering is off entirely.
 *
 * This is the authoritative strip: it runs server-side, before `biography`/`notes`
 * ever reach a non-ST client. `src/lib/stripStSections.ts` is a second layer for
 * the sheet's own rendering, not a substitute for this one.
 *
 * @see BE_PROCESS/GV-SOURCEMAP.md "ST data filtering (GameClass.STFilter)"
 * @see BE_PROCESS/workflow-0.3.md Step 4g
 */
class St_Filter {

	/**
	 * Removes every section of text between a start marker and an end marker,
	 * scanning left to right and copying through any text outside a marked
	 * section. Returns the text unchanged when either marker is empty.
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
	 * Strips ST sections from a field using a game's configured markers. Reads
	 * `st_comment_start`/`st_comment_end` from the game's settings, falling back
	 * to `[ST]` / `[/ST]` when the game has not customized them.
	 *
	 * @param string      $text
	 * @param object|null $game_settings Decoded `be_games.settings`, or null.
	 * @return string
	 */
	public static function strip_for_game( string $text, $game_settings ): string {
		$start = $game_settings->st_comment_start ?? '[ST]';
		$end   = $game_settings->st_comment_end ?? '[/ST]';
		return self::strip( $text, (string) $start, (string) $end );
	}
}
