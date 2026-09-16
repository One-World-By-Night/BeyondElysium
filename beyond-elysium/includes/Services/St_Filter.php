<?php

namespace BeyondElysium\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Strips ST-only text from a field, transcribed from `GameClass.STFilter` (VB6).
 *
 * The markers are configurable per game rather than a fixed `[ST]`/`[/ST]` pair -
 * they live on `be_games.settings.st_comment_start` / `st_comment_end`, defaulting
 * to `[ST]` / `[/ST]`. Passed an empty marker, `strip()` does no filtering, as
 * Grapevine does; a game's blank stored marker falls back to the default instead.
 *
 * This is the authoritative strip: it runs server-side, before `biography`/`notes`
 * ever reach a non-ST client. `src/lib/stripStSections.ts` is a second layer for
 * the sheet's own rendering, not a substitute for this one.
 *
 * @see BE_PROCESS/reference/GV-SOURCEMAP.md "ST data filtering (GameClass.STFilter)"
 * @see BE_PROCESS/releases/workflow-0.3.md Step 4g
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
	 * to `[ST]` / `[/ST]` when the game has not customized them - or has stored
	 * a blank one. Unlike `strip()`, a chronicle's settings can never turn
	 * filtering off: that would show every player every `[ST]` passage in it
	 * (1.0.0-review F-061).
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
	 * Same as strip_for_game(), for a field that may hold HTML rather than plain text
	 * (character biography/notes, world-object description/limitations/text properties -
	 * every one of them a rich-text field this filter already ran against before it could
	 * hold HTML). `strip()` cuts by byte offset with no notion of tag boundaries, so a
	 * marker placed across a paragraph break or inline formatting can leave a dangling
	 * unclosed tag in what's left over.
	 *
	 * Two passes, and both are needed. `wp_kses_post()` re-narrows the markup - it costs
	 * nothing when nothing was cut mid-tag and cannot reintroduce the secret text `strip()`
	 * already removed. But it does **not** balance tags, which this function claimed it did
	 * until 1.0.1 D1's own test measured it: a marker opening inside `<em>` and closing
	 * after `</em>` takes the closing tag with it, and `wp_kses_post()` hands back the
	 * unclosed `<em>` untouched, leaving it to swallow the rest of the page's formatting.
	 * `force_balance_tags()` is WordPress's own function for exactly that and closes it.
	 *
	 * @param string      $html
	 * @param object|null $game_settings Decoded `be_games.settings`, or null.
	 * @return string
	 */
	public static function strip_html_for_game( string $html, $game_settings ): string {
		return force_balance_tags( wp_kses_post( self::strip_for_game( $html, $game_settings ) ) );
	}
}
