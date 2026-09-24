<?php

namespace BeyondElysium\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Parser for Grapevine's GVBG binary game files (`.gv3`).
 */
class Game_File_Parser {

	/**
	 * Binary game-file header (`PublicConstants.bas` `BinHeaderGame`).
	 */
	const BINARY_HEADER = 'GVBG';

	/**
	 * Parses a `.gv3` game file from disk into its structured contents.
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
