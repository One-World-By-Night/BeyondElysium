<?php

namespace BeyondElysium\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Parser for Grapevine's GVBG binary game files (`.gv3`).
 *
 * Reads the game-file container: chronicle metadata, calendar, experience
 * awards, templates, the APR engine, and every entity inventory (players,
 * characters, queries, items, rotes, locations, actions, plots, rumors).
 *
 * The container differs from `GEX_Parser`'s GVBE container in several
 * structural ways, so it is read independently rather than by reusing that
 * container's logic:
 *   1. `Size` (int16, right after `Version`) exists only in GVBG.
 *   2. The chronicle-metadata preamble exists only in GVBG.
 *   3. Read order differs from declaration order: `UsualTime` before `UsualPlace`.
 *   4. The Calendar has no presence flag in GVBG; it is read unconditionally,
 *      where GVBE wraps it in an outer `int16 count` check.
 *   5. The APR engine's version gate is `>= 2.396` with no presence flag,
 *      where GVBE gates it at `>= 2.397` with one.
 *   6. Section order is swapped: GVBG runs Calendar, XP Awards/Templates,
 *      APR Engine, Players; GVBE runs Calendar, APR Engine, XP
 *      Awards/Templates, Players.
 *
 * Per-entity `InputFromBinary` readers are identical between the two
 * formats and are reused directly from `GEX_Parser`.
 *
 * @see BE_PROCESS/GV-SOURCEMAP.md "GVBG binary game-file shape"
 * @see BE_PROCESS/workflow-0.8.md Step 9f
 */
class Game_File_Parser {

	/** Binary game-file header (`PublicConstants.bas` `BinHeaderGame`). */
	const BINARY_HEADER = 'GVBG';

	/**
	 * Parses a `.gv3` game file from disk into its structured contents.
	 * Loads the file into a binary reader and delegates to
	 * `parse_binary()` for the actual decode.
	 *
	 * @param string $path Absolute path.
	 * @return array<string,mixed>
	 * @throws \RuntimeException On an unreadable, non-GVBG, or desynchronized file.
	 */
	public static function parse_file( string $path ): array {
		return self::parse_binary( GV_Binary_Reader::from_file( $path ) );
	}

	/**
	 * Parses a GVBG binary game-file stream into its structured contents.
	 * Reads the header, chronicle metadata, calendar, experience awards,
	 * templates, APR engine, and each entity inventory in the fixed order
	 * below, delegating per-entity decoding to `GEX_Parser`.
	 *
	 * Read order, from `GameClass.OpenGameBinary` (`GameClass.cls` lines
	 * 1321-1524). Every string is length-prefixed, so field positions
	 * shift per file; the line numbers below identify source order, not
	 * byte offsets:
	 *
	 *   string header "GVBG"                                           (1321)
	 *   double version                                                 (1329)
	 *   int16  size                                                    (1330)
	 *   string chronicleTitle, website, email, phone                   (1348-1351)
	 *   string usualTime, THEN usualPlace (declaration order reversed) (1352-1353)
	 *   string description                                             (1354)
	 *   bool   extendedHealth, enforceHistory                          (1356-1357)
	 *   if version == 2.396 exactly: bool tempBool (discarded)         (1358)
	 *   if version >= 2.397:
	 *       string stCommentStart, stCommentEnd; bool linkTraitMaxes   (1360-1362)
	 *       if version >= 2.399: string randomTraits                  (1363)
	 *   string menuFileName                                            (1367)
	 *   Calendar body - UNCONDITIONAL, no presence flag                (1383)
	 *   if version >= 2.397: int16 count -> ExperienceAwardClass * N   (1387-1393)
	 *                        int16 count -> TemplateClass * N          (1395-1401)
	 *   if version >= 2.396: APREngine body - no presence flag         (1405-1408)
	 *   int16 count -> PlayerClass * N                                 (1411-1418)
	 *   int16 count -> (int16 RCode + race dispatch) * N               (1421-1454)
	 *   int16 count -> QueryClass * N                                  (1457-1464)
	 *   int16 count -> ItemClass * N                                   (1467-1474)
	 *   int16 count -> RoteClass * N                                   (1477-1484)
	 *   int16 count -> LocationClass * N                               (1487-1494)
	 *   int16 count -> ActionClass * N                                 (1497-1504)
	 *   int16 count -> PlotClass * N                                   (1507-1514)
	 *   int16 count -> RumorClass * N                                  (1517-1524)
	 *
	 * @param GV_Binary_Reader $reader Positioned at the start of the file.
	 * @return array<string,mixed>
	 * @throws \RuntimeException On a bad header or a desynchronized stream.
	 */
	public static function parse_binary( GV_Binary_Reader $reader ): array {
		$header = $reader->string();

		if ( $header !== self::BINARY_HEADER ) {
			throw new \RuntimeException(
				sprintf( 'Not a Grapevine game file: header was "%s"', $header )
			);
		}

		$version = $reader->double();
		$size    = $reader->int16(); // GVBG-only - GEX_Parser's GVBE container has no equivalent field.

		$chronicle_title = $reader->string();
		$website         = $reader->string();
		$email           = $reader->string();
		$phone           = $reader->string();
		// Read order differs from declaration order: UsualPlace is declared first.
		$usual_time  = $reader->string();
		$usual_place = $reader->string();
		$description = $reader->string();

		$extended_health = $reader->bool();
		$enforce_history = $reader->bool();

		// Read and discarded; not referenced elsewhere.
		if ( $version === 2.396 ) {
			$reader->bool();
		}

		$st_comment_start = null;
		$st_comment_end   = null;
		$link_trait_maxes = null;
		$random_traits    = null;

		if ( $version >= 2.397 ) {
			$st_comment_start = $reader->string();
			$st_comment_end   = $reader->string();
			$link_trait_maxes = $reader->bool();

			if ( $version >= 2.399 ) {
				$random_traits = $reader->string();
			}
		}

		$menu_file_name = $reader->string();

		// Calendar has no outer presence flag in GVBG; read unconditionally.
		$calendar = GEX_Parser::parse_calendar( $reader, $version );

		$xp_awards = [];
		$templates = [];

		if ( $version >= 2.397 ) {
			$xp_award_count = $reader->int16();
			for ( $i = 0; $i < $xp_award_count; $i++ ) {
				$xp_awards[] = GEX_Parser::parse_experience_award( $reader, $version );
			}

			$template_count = $reader->int16();
			for ( $i = 0; $i < $template_count; $i++ ) {
				$templates[] = GEX_Parser::parse_template( $reader, $version );
			}
		}

		// APR engine also has no outer presence flag in GVBG.
		$apr_engine = null;
		if ( $version >= 2.396 ) {
			$apr_engine = GEX_Parser::parse_apr_engine( $reader, $version );
		}

		$players      = [];
		$player_count = $reader->int16();
		for ( $i = 0; $i < $player_count; $i++ ) {
			$players[] = GEX_Parser::parse_player( $reader, $version );
		}

		$characters       = [];
		$character_count = $reader->int16();
		for ( $i = 0; $i < $character_count; $i++ ) {
			// parse_character() reads its own leading RCode and dispatches by race.
			$characters[] = GEX_Parser::parse_character( $reader, $version );
		}

		$queries    = [];
		$query_count = $reader->int16();
		for ( $i = 0; $i < $query_count; $i++ ) {
			$queries[] = GEX_Parser::parse_query( $reader, $version );
		}

		$items      = [];
		$item_count = $reader->int16();
		for ( $i = 0; $i < $item_count; $i++ ) {
			$items[] = GEX_Parser::parse_item( $reader, $version );
		}

		$rotes      = [];
		$rote_count = $reader->int16();
		for ( $i = 0; $i < $rote_count; $i++ ) {
			$rotes[] = GEX_Parser::parse_rote( $reader, $version );
		}

		$locations      = [];
		$location_count = $reader->int16();
		for ( $i = 0; $i < $location_count; $i++ ) {
			$locations[] = GEX_Parser::parse_location( $reader, $version );
		}

		$actions      = [];
		$action_count = $reader->int16();
		for ( $i = 0; $i < $action_count; $i++ ) {
			$actions[] = GEX_Parser::parse_action( $reader, $version );
		}

		$plots      = [];
		$plot_count = $reader->int16();
		for ( $i = 0; $i < $plot_count; $i++ ) {
			$plots[] = GEX_Parser::parse_plot( $reader, $version );
		}

		$rumors      = [];
		$rumor_count = $reader->int16();
		for ( $i = 0; $i < $rumor_count; $i++ ) {
			$rumors[] = GEX_Parser::parse_rumor( $reader, $version );
		}

		if ( ! $reader->eof() ) {
			throw new \RuntimeException(
				sprintf(
					'GVBG parse ended at byte %d of %d - the stream desynchronized.',
					$reader->tell(),
					$reader->size()
				)
			);
		}

		return [
			'version'           => $version,
			'size'              => $size,
			'chronicle_title'   => $chronicle_title,
			'website'           => $website,
			'email'             => $email,
			'phone'             => $phone,
			'usual_time'        => $usual_time,
			'usual_place'       => $usual_place,
			'description'       => $description,
			'extended_health'   => $extended_health,
			'enforce_history'   => $enforce_history,
			'st_comment_start'  => $st_comment_start,
			'st_comment_end'    => $st_comment_end,
			'link_trait_maxes'  => $link_trait_maxes,
			'random_traits'     => $random_traits,
			'menu_file_name'    => $menu_file_name,
			'calendar'          => $calendar,
			'apr_engine'        => $apr_engine,
			'experience_awards' => $xp_awards,
			'templates'         => $templates,
			'players'           => $players,
			'characters'        => $characters,
			'queries'           => $queries,
			'items'             => $items,
			'rotes'             => $rotes,
			'locations'         => $locations,
			'actions'           => $actions,
			'plots'             => $plots,
			'rumors'            => $rumors,
		];
	}
}
