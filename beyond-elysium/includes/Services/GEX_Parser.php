<?php

namespace BeyondElysium\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Parser for Grapevine's GVBE binary exchange files.
 *
 * A GEX file is a top-level sequence of counted sections: players,
 * characters, items, rotes, locations, actions, plots, rumors, plus a
 * conditional calendar/APR-settings/XP-award/template block on newer
 * versions. This class only turns bytes into a structured array - it does
 * not touch `Trait_Mapper`, any database table, or the REST layer. Every
 * trait list is returned keyed by its own name as read from the file, the
 * same key `Trait_Mapper::classify_list()` expects.
 *
 * Version gating is pervasive and often nested two or three deep, across
 * four thresholds: 2.395, 2.396, 2.397, and 2.399.
 *
 * The 13 per-entity readers (`parse_calendar`, `parse_apr_engine`,
 * `parse_experience_award`, `parse_template`, `parse_player`,
 * `parse_character`, `parse_query`, `parse_item`, `parse_rote`,
 * `parse_location`, `parse_action`, `parse_plot`, `parse_rumor`) are
 * `public` so `Game_File_Parser` can reuse them directly: GVBG's
 * per-entity bodies are identical to GVBE's, only the surrounding
 * container differs. Everything else, including all 12 race-specific
 * character readers, stays `private`.
 *
 * @see BE_PROCESS/GV-SOURCEMAP.md "GVBE binary exchange shape"
 * @see BE_PROCESS/GV-SOURCEMAP.md "GVBG binary game-file shape"
 * @see BE_PROCESS/workflow-0.8.md Step 2, Step 9f
 */
class GEX_Parser {

	/** Binary exchange-file header (PublicConstants.bas BinHeaderExchange). */
	const BINARY_HEADER = 'GVBE';

	/**
	 * `RaceType` -> character-class dispatch, from `GameClass.LoadExchangeBinary`'s
	 * `Select Case RCode` (GameClass.cls lines 649-675). Values from
	 * `PublicTypes.bas` `Enum RaceType` (lines 29-45). `RCode` itself is declared
	 * `As Integer` in `LoadExchangeBinary` (not `As RaceType`), so the selector read
	 * ahead of each character is 2 bytes, not the 4-byte width a genuine enum field
	 * would get elsewhere in this format.
	 *
	 * gvRaceAll (1) has no character class of its own and is not dispatched here - the
	 * VB6 `Select Case` has no `Case` for it either, so an exchange file that somehow
	 * contained it would abort in the original tool too.
	 */
	const RACE_TYPE_MAP = [
		2  => 'vampire',
		3  => 'werewolf',
		4  => 'mortal',
		5  => 'changeling',
		6  => 'wraith',
		7  => 'mage',
		8  => 'fera',
		9  => 'various',
		10 => 'mummy',
		11 => 'kueijin',
		12 => 'hunter',
		13 => 'demon',
	];

	/**
	 * Parses a `.gex` file from disk into its structured contents. Loads
	 * the file into a binary reader and delegates to `parse_binary()` for
	 * the actual decode.
	 *
	 * @param string $path Absolute path.
	 * @return array<string,mixed>
	 * @throws \RuntimeException On an unreadable, non-GVBE, or desynchronized file.
	 */
	public static function parse_file( string $path ): array {
		return self::parse_binary( GV_Binary_Reader::from_file( $path ) );
	}

	/**
	 * Parses a GVBE binary exchange stream into its structured contents.
	 * Reads the header and version, then each top-level counted section
	 * in turn, delegating per-entity decoding to the matching `parse_*`
	 * method.
	 *
	 * Top-level section order, from `GameClass.LoadExchangeBinary`
	 * (GameClass.cls lines 536-790):
	 *
	 *   string header "GVBE"
	 *   double version
	 *   if version >= 2.395: int16 count -> 1 CalendarClass if count > 0
	 *   if version >= 2.397:
	 *       int16 count -> 1 APREngineClass if count > 0
	 *       int16 count -> that many ExperienceAwardClass
	 *       int16 count -> that many TemplateClass
	 *   int16 count -> PlayerClass
	 *   int16 count -> characters, each preceded by an int16 RaceType selecting the class
	 *   int16 count -> QueryClass
	 *   int16 count -> ItemClass
	 *   int16 count -> RoteClass
	 *   int16 count -> LocationClass
	 *   int16 count -> ActionClass
	 *   int16 count -> PlotClass
	 *   int16 count -> RumorClass
	 *
	 * @param GV_Binary_Reader $reader Positioned at the start of the file.
	 * @return array<string,mixed>
	 * @throws \RuntimeException On a bad header or a desynchronized stream.
	 */
	public static function parse_binary( GV_Binary_Reader $reader ): array {
		$header = $reader->string();

		if ( $header !== self::BINARY_HEADER ) {
			throw new \RuntimeException(
				sprintf( 'Not a Grapevine binary exchange file: header was "%s"', $header )
			);
		}

		$version = $reader->double();

		$calendar   = null;
		$apr_engine = null;
		$xp_awards  = [];
		$templates  = [];

		if ( $version >= 2.395 ) {
			$count = $reader->int16();
			if ( $count > 0 ) {
				$calendar = self::parse_calendar( $reader, $version );
			}

			if ( $version >= 2.397 ) {
				$apr_count = $reader->int16();
				if ( $apr_count > 0 ) {
					$apr_engine = self::parse_apr_engine( $reader, $version );
				}

				$award_count = $reader->int16();
				for ( $i = 0; $i < $award_count; $i++ ) {
					$xp_awards[] = self::parse_experience_award( $reader, $version );
				}

				$template_count = $reader->int16();
				for ( $i = 0; $i < $template_count; $i++ ) {
					$templates[] = self::parse_template( $reader, $version );
				}
			}
		}

		$players = [];
		$player_count = $reader->int16();
		for ( $i = 0; $i < $player_count; $i++ ) {
			$players[] = self::parse_player( $reader, $version );
		}

		$characters = [];
		$character_count = $reader->int16();
		for ( $i = 0; $i < $character_count; $i++ ) {
			$characters[] = self::parse_character( $reader, $version );
		}

		$queries = [];
		$query_count = $reader->int16();
		for ( $i = 0; $i < $query_count; $i++ ) {
			$queries[] = self::parse_query( $reader, $version );
		}

		$items = [];
		$item_count = $reader->int16();
		for ( $i = 0; $i < $item_count; $i++ ) {
			$items[] = self::parse_item( $reader, $version );
		}

		$rotes = [];
		$rote_count = $reader->int16();
		for ( $i = 0; $i < $rote_count; $i++ ) {
			$rotes[] = self::parse_rote( $reader, $version );
		}

		$locations = [];
		$location_count = $reader->int16();
		for ( $i = 0; $i < $location_count; $i++ ) {
			$locations[] = self::parse_location( $reader, $version );
		}

		$actions = [];
		$action_count = $reader->int16();
		for ( $i = 0; $i < $action_count; $i++ ) {
			$actions[] = self::parse_action( $reader, $version );
		}

		$plots = [];
		$plot_count = $reader->int16();
		for ( $i = 0; $i < $plot_count; $i++ ) {
			$plots[] = self::parse_plot( $reader, $version );
		}

		$rumors = [];
		$rumor_count = $reader->int16();
		for ( $i = 0; $i < $rumor_count; $i++ ) {
			$rumors[] = self::parse_rumor( $reader, $version );
		}

		if ( ! $reader->eof() ) {
			throw new \RuntimeException(
				sprintf(
					'GEX parse ended at byte %d of %d - the stream desynchronized.',
					$reader->tell(),
					$reader->size()
				)
			);
		}

		return [
			'version'          => $version,
			'calendar'         => $calendar,
			'apr_engine'       => $apr_engine,
			'experience_awards' => $xp_awards,
			'templates'        => $templates,
			'players'          => $players,
			'characters'       => $characters,
			'queries'          => $queries,
			'items'            => $items,
			'rotes'            => $rotes,
			'locations'        => $locations,
			'actions'          => $actions,
			'plots'            => $plots,
			'rumors'           => $rumors,
		];
	}

	// -------------------------------------------------------------------------
	// Shared nested readers
	// -------------------------------------------------------------------------

	/**
	 * Reads a `LinkedTraitList`: the name, four flag/type fields, then a
	 * count-prefixed list of `{name, total, note}` trait rows. Returned
	 * keyed by its own `name`, the same value
	 * `Trait_Mapper::classify_list()` expects as `$gv_list_name`.
	 * Section-divider rows are dropped and their label is stamped onto
	 * every trait that follows, until the next divider.
	 *
	 * @param GV_Binary_Reader $r
	 * @param float            $version
	 * @return array{name:string,alphabetized:bool,atomic:bool,negative:bool,display:int,traits:array<int,array{name:string,total:string,note:string}>}
	 */
	private static function parse_trait_list( GV_Binary_Reader $r, float $version ): array {
		$name         = $r->string();
		$alphabetized = $r->bool();
		$atomic       = $r->bool();
		$negative     = $r->bool();
		$display      = $r->int32();

		$count   = $r->int16();
		$traits  = [];
		$section = null;

		for ( $i = 0; $i < $count; $i++ ) {
			$trait = [
				'name'  => $r->string(),
				// A string even though it holds a number; GV's Total field may carry a note.
				'total' => $r->string(),
				'note'  => $r->string(),
			];
			if ( self::is_section_divider( $trait ) ) {
				// The divider row itself is dropped; only its label is kept.
				$section = self::divider_label( $trait );
				continue;
			}
			if ( $section !== null ) {
				$trait['section'] = $section;
			}
			$traits[] = $trait;
		}

		return [
			'name'         => $name,
			'alphabetized' => $alphabetized,
			'atomic'       => $atomic,
			'negative'     => $negative,
			'display'      => $display,
			'traits'       => $traits,
		];
	}

	/** @var array<string,array<string,mixed>>|null */
	private static ?array $shape = null;

	/**
	 * Returns GX-1's shared field-order authority (`gv-exchange-shape.php`)
	 * for one race - the ordered `scalars`/`trait_lists`/`tail` shape a
	 * writer will also trust. Loaded once and cached for the process.
	 *
	 * @param string $race One of `RACE_TYPE_MAP`'s values.
	 * @return array<string,mixed>
	 * @see BE_PROCESS/gex-export-transfer-design.md GX-1, GX-2
	 */
	public static function shape( string $race ): array {
		if ( self::$shape === null ) {
			self::$shape = require __DIR__ . '/gv-exchange-shape.php';
		}
		return self::$shape[ $race ];
	}

	/**
	 * Reads one race's full ordered run of trait lists, driven by the
	 * shared shape table instead of a bare sequence of per-class `$add()`
	 * calls (GX-2). A `min_version` row is skipped for an older file,
	 * matching that class's own original read gate exactly. A parsed
	 * list's name not matching the table's expected name is never fatal -
	 * the reader already tolerates a differently-named list by keying on
	 * the file's own name (Dialect C tolerance, gex-export-transfer-design.md
	 * §2e); this loop preserves that, it does not tighten it.
	 *
	 * `$offset`/`$length` read only a slice of the race's ordered trait-list
	 * run - needed for wraith, the one class whose real byte order genuinely
	 * interleaves trait lists with free-text tail fields rather than reading
	 * them as one contiguous run.
	 *
	 * @param GV_Binary_Reader $r
	 * @param float            $version
	 * @param string           $race
	 * @param int              $offset
	 * @param int|null         $length
	 * @return array<string,array<string,mixed>> Keyed by each list's own parsed name.
	 */
	private static function read_trait_lists( GV_Binary_Reader $r, float $version, string $race, int $offset = 0, ?int $length = null ): array {
		$specs = self::shape( $race )['trait_lists'];
		if ( $length !== null || $offset > 0 ) {
			$specs = array_slice( $specs, $offset, $length );
		}

		$trait_lists = [];
		foreach ( $specs as $spec ) {
			if ( isset( $spec['min_version'] ) && $version < $spec['min_version'] ) {
				continue;
			}
			$tl                         = self::parse_trait_list( $r, $version );
			$trait_lists[ $tl['name'] ] = $tl;
		}
		return $trait_lists;
	}

	/**
	 * Determines whether a trait row is really a section-header row
	 * rather than a held trait. Some third-party export tools insert a
	 * zero-value, em-dash-wrapped pseudo-trait into a trait list purely
	 * to visually group later entries for a human reader, for example
	 * `"——Blood Magic——"`, with no game-mechanical meaning of its own.
	 *
	 * Requires both the em-dash wrapping and a zero or blank value: a
	 * real power name could start or end with a plain hyphen, but nothing
	 * legitimate is wrapped in a real em-dash on both sides while also
	 * carrying no held value.
	 *
	 * @param array{name:string,total:string,note:string} $trait
	 */
	public static function is_section_divider( array $trait ): bool {
		$name  = trim( $trait['name'] );
		$total = trim( $trait['total'] );
		return ( $total === '' || $total === '0' )
			&& preg_match( '/^\x{2014}{2,}.*\x{2014}{2,}$/u', $name ) === 1;
	}

	/**
	 * Extracts a divider row's plain label with its em-dash wrapping
	 * stripped, for example `"——Blood Magic——"` becomes `"Blood Magic"`.
	 * Used to stamp a `section` value onto every trait that follows the
	 * divider, until the next one. Callers are expected to have already
	 * confirmed `is_section_divider()` themselves.
	 */
	public static function divider_label( array $trait ): string {
		return trim( (string) preg_replace( '/^\x{2014}+|\x{2014}+$/u', '', trim( $trait['name'] ) ) );
	}

	/**
	 * Reads a `BoonClass` entry: the boon type, the name of the character
	 * it involves, whether it is owed or held, the date it was incurred,
	 * and a free-text description.
	 *
	 * @param GV_Binary_Reader $r
	 * @param float            $version
	 * @return array<string,mixed>
	 */
	private static function parse_boon( GV_Binary_Reader $r, float $version ): array {
		return [
			'boon_type'   => $r->string(),
			'char_name'   => $r->string(),
			'is_owed'     => $r->bool(),
			'boon_date'   => $r->date(),
			'description' => $r->string(),
		];
	}

	/**
	 * Reads one experience-history entry: the date, the change amount, a
	 * change-type code, a free-text reason, and the running earned/unspent
	 * totals at that point in history.
	 *
	 * @param GV_Binary_Reader $r
	 * @param float            $version
	 * @return array<string,mixed>
	 */
	private static function parse_experience_history_node( GV_Binary_Reader $r, float $version ): array {
		return [
			'when'        => $r->date(),
			'change'      => $r->single(),
			'change_type' => $r->int32(),
			'reason'      => $r->string(),
			'earned'      => $r->single(),
			'unspent'     => $r->single(),
		];
	}

	/**
	 * Reads an `ExperienceClass` block: the current unspent and earned
	 * totals, each a `Single` rather than a `Double`, followed by a
	 * count-prefixed list of history entries read through
	 * `parse_experience_history_node()`.
	 *
	 * @param GV_Binary_Reader $r
	 * @param float            $version
	 * @return array{unspent:float,earned:float,history:array<int,array<string,mixed>>}
	 */
	private static function parse_experience( GV_Binary_Reader $r, float $version ): array {
		$unspent = $r->single();
		$earned  = $r->single();

		$count   = $r->int16();
		$history = [];

		for ( $i = 0; $i < $count; $i++ ) {
			$history[] = self::parse_experience_history_node( $r, $version );
		}

		return [
			'unspent' => $unspent,
			'earned'  => $earned,
			'history' => $history,
		];
	}

	/**
	 * `CauseEffectList.InputFromBinary` (GV301Source/Code/CauseEffectList.cls,
	 * lines 492-518), reading a list of `CauseEffectNode` entries. Shared by
	 * `ActionNode` and `PlotNode` as their `Effects` field - neither ever populates
	 * its own `Causes` field from binary (`InputFromBinary` never calls it), so this
	 * parser is only ever invoked for "Effects" in practice.
	 *
	 * @param GV_Binary_Reader $r
	 * @param float            $version
	 * @return array<int,array{apr:int,when:?string,item:string,subitem:string}>
	 */
	private static function parse_cause_effect_list( GV_Binary_Reader $r, float $version ): array {
		$count   = $r->int16();
		$entries = [];

		for ( $i = 0; $i < $count; $i++ ) {
			$entries[] = [
				'apr'     => $r->int32(),
				'when'    => $r->date(),
				'item'    => $r->string(),
				'subitem' => $r->string(),
			];
		}

		return $entries;
	}

	/**
	 * Reads one subaction entry: its name, level, unused and total point
	 * counts, growth, an effects list read through
	 * `parse_cause_effect_list()`, and the free-text action and result.
	 *
	 * @param GV_Binary_Reader $r
	 * @param float            $version
	 * @return array<string,mixed>
	 */
	private static function parse_action_node( GV_Binary_Reader $r, float $version ): array {
		$name    = $r->string();
		$level   = $r->int16();
		$unused  = $r->int16();
		$total   = $r->int16();
		$growth  = $r->int16();
		$effects = self::parse_cause_effect_list( $r, $version );
		$action  = $r->string();
		$result  = $r->string();

		return [
			'name'    => $name,
			'level'   => $level,
			'unused'  => $unused,
			'total'   => $total,
			'growth'  => $growth,
			'effects' => $effects,
			'action'  => $action,
			'result'  => $result,
		];
	}

	/**
	 * Reads one plot development entry: the date of the development, an
	 * effects list read through `parse_cause_effect_list()`, and the
	 * free-text development description.
	 *
	 * @param GV_Binary_Reader $r
	 * @param float            $version
	 * @return array<string,mixed>
	 */
	private static function parse_plot_node( GV_Binary_Reader $r, float $version ): array {
		$dev_date    = $r->date();
		$effects     = self::parse_cause_effect_list( $r, $version );
		$development = $r->string();

		return [
			'dev_date'    => $dev_date,
			'effects'     => $effects,
			'development' => $development,
		];
	}

	/**
	 * Reads one rumor variant: the level it applies at and its free-text
	 * rumor content. A rumor's `variants` list holds one of these per
	 * level the rumor is written for.
	 *
	 * @param GV_Binary_Reader $r
	 * @param float            $version
	 * @return array{level:int,rumor:string}
	 */
	private static function parse_rumor_node( GV_Binary_Reader $r, float $version ): array {
		return [
			'level' => $r->int16(),
			'rumor' => $r->string(),
		];
	}

	/**
	 * Reads one query clause: the field key it tests, a comparison
	 * operator code, a negation flag, a text value to compare against,
	 * and a numeric value read as a `Double`.
	 *
	 * @param GV_Binary_Reader $r
	 * @param float            $version
	 * @return array<string,mixed>
	 */
	private static function parse_query_clause( GV_Binary_Reader $r, float $version ): array {
		return [
			'key'        => $r->string(),
			'comparison' => $r->int32(),
			'comp_not'   => $r->bool(),
			'find'       => $r->string(),
			'number'     => $r->double(),
		];
	}

	/**
	 * Reads a `QueryClass` block: its name, target inventory, match-all
	 * and sort settings, a `last_modified` date read only when the format
	 * version is 2.395 or later, and a count-prefixed list of clauses
	 * read through `parse_query_clause()`.
	 *
	 * @param GV_Binary_Reader $r
	 * @param float            $version
	 * @return array<string,mixed>
	 */
	public static function parse_query( GV_Binary_Reader $r, float $version ): array {
		$name          = $r->string();
		$inventory     = $r->int32();
		$match_all     = $r->bool();
		$sort_key      = $r->string();
		$sort_descend  = $r->bool();
		$last_modified = $version >= 2.395 ? $r->date() : null;

		$count   = $r->int16();
		$clauses = [];

		for ( $i = 0; $i < $count; $i++ ) {
			$clauses[] = self::parse_query_clause( $r, $version );
		}

		return [
			'name'          => $name,
			'inventory'     => $inventory,
			'match_all'     => $match_all,
			'sort_key'      => $sort_key,
			'sort_descend'  => $sort_descend,
			'last_modified' => $last_modified,
			'clauses'       => $clauses,
		];
	}

	/**
	 * Reads a `PlayerClass` block: identity fields, a status value, a
	 * last-modified date, an experience block read through
	 * `parse_experience()`, and address/notes text. `status` is a real
	 * string from format version 2.397 on; before that, the file carries
	 * a Boolean `Active` flag that is mapped onto an `Active`/`Inactive`
	 * status string.
	 *
	 * @param GV_Binary_Reader $r
	 * @param float            $version
	 * @return array<string,mixed>
	 */
	public static function parse_player( GV_Binary_Reader $r, float $version ): array {
		$name     = $r->string();
		$id       = $r->string();
		$email    = $r->string();
		$phone    = $r->string();
		$position = $r->string();

		if ( $version >= 2.397 ) {
			$status = $r->string();
		} else {
			$active = $r->bool();
			$status = $active ? 'Active' : 'Inactive';
		}

		$last_modified = $r->date();
		$experience    = self::parse_experience( $r, $version );
		$address       = $r->string();
		$notes         = $r->string();

		return [
			'name'          => $name,
			'id'            => $id,
			'email'         => $email,
			'phone'         => $phone,
			'position'      => $position,
			'status'        => $status,
			'last_modified' => $last_modified,
			'experience'    => $experience,
			'address'       => $address,
			'notes'         => $notes,
		];
	}

	/**
	 * Reads a `TemplateClass` entry: its name, whether it is a character
	 * sheet template, and the file names of its text, RTF, and HTML
	 * variants.
	 *
	 * @param GV_Binary_Reader $r
	 * @param float            $version
	 * @return array<string,mixed>
	 */
	public static function parse_template( GV_Binary_Reader $r, float $version ): array {
		return [
			'name'                 => $r->string(),
			'is_character_sheet'   => $r->bool(),
			'file_name_text'       => $r->string(),
			'file_name_rtf'        => $r->string(),
			'file_name_html'       => $r->string(),
		];
	}

	/**
	 * Reads an `ExperienceAwardClass` entry: a boolean flag for whether
	 * the award is XP or PP, the award's name, a change-type code, the
	 * change amount, and a free-text reason.
	 *
	 * @param GV_Binary_Reader $r
	 * @param float            $version
	 * @return array<string,mixed>
	 */
	public static function parse_experience_award( GV_Binary_Reader $r, float $version ): array {
		return [
			'xp'          => $r->bool(),
			'name'        => $r->string(),
			'change_type' => $r->int32(),
			'change'      => $r->single(),
			'reason'      => $r->string(),
		];
	}

	/**
	 * Reads a `CalendarClass` block: a `last_modified` date gated on
	 * format version 2.395 or later, followed by a count-prefixed list of
	 * calendar entries, each a date, time, place, and notes.
	 *
	 * @param GV_Binary_Reader $r
	 * @param float            $version
	 * @return array<string,mixed>
	 */
	public static function parse_calendar( GV_Binary_Reader $r, float $version ): array {
		$last_modified = $version >= 2.395 ? $r->date() : null;

		$count   = $r->int16();
		$entries = [];

		for ( $i = 0; $i < $count; $i++ ) {
			$entries[] = [
				'date'  => $r->date(),
				'time'  => $r->string(),
				'place' => $r->string(),
				'notes' => $r->string(),
			];
		}

		return [
			'last_modified' => $last_modified,
			'entries'       => $entries,
		];
	}

	/**
	 * Reads an `APREngineClass` block: the personal-action total, several
	 * boolean settings controlling common-action and rumor-visibility
	 * behavior, and two trait lists (`background_actions`,
	 * `actions_per_level`) read through `parse_trait_list()`.
	 *
	 * @param GV_Binary_Reader $r
	 * @param float            $version
	 * @return array<string,mixed>
	 */
	public static function parse_apr_engine( GV_Binary_Reader $r, float $version ): array {
		$personal_actions = $r->int16();
		$add_common       = $r->bool();
		$carry_unused     = $version >= 2.397 ? $r->bool() : null;
		$public_rumors    = $r->bool();
		$personal_rumors  = $r->bool();
		$race_rumors      = $r->bool();
		$group_rumors     = $r->bool();
		$subgroup_rumors  = $r->bool();
		$influence_rumors = $r->bool();
		$previous_rumors  = $r->bool();
		$copy_previous    = $r->bool();

		$background_actions = self::parse_trait_list( $r, $version );
		$actions_per_level   = self::parse_trait_list( $r, $version );

		return [
			'personal_actions'    => $personal_actions,
			'add_common'          => $add_common,
			'carry_unused'        => $carry_unused,
			'public_rumors'       => $public_rumors,
			'personal_rumors'     => $personal_rumors,
			'race_rumors'         => $race_rumors,
			'group_rumors'        => $group_rumors,
			'subgroup_rumors'     => $subgroup_rumors,
			'influence_rumors'    => $influence_rumors,
			'previous_rumors'     => $previous_rumors,
			'copy_previous'       => $copy_previous,
			'background_actions'  => $background_actions,
			'actions_per_level'   => $actions_per_level,
		];
	}

	// -------------------------------------------------------------------------
	// World objects
	// -------------------------------------------------------------------------

	/**
	 * Reads an `ItemClass` entry: identity and damage fields, then four
	 * trait lists in read order (Temper, Ability, Negative, Availability),
	 * followed by powers, appearance, and notes text and a last-modified
	 * date.
	 *
	 * @param GV_Binary_Reader $r
	 * @param float            $version
	 * @return array<string,mixed>
	 */
	public static function parse_item( GV_Binary_Reader $r, float $version ): array {
		$name            = $r->string();
		$item_type       = $r->string();
		$item_subtype    = $r->string();
		$level           = $r->int16();
		$bonus           = $r->int16();
		$damage_type     = $r->string();
		$damage_amount   = $r->int16();
		$concealability  = $r->string();

		$temper_list      = self::parse_trait_list( $r, $version );
		$ability_list     = self::parse_trait_list( $r, $version );
		$negative_list    = self::parse_trait_list( $r, $version );
		$availability     = self::parse_trait_list( $r, $version );

		$powers     = $r->string();
		$appearance = $r->string();
		$notes      = $r->string();
		$last_modified = $r->date();

		return [
			'name'           => $name,
			'item_type'      => $item_type,
			'item_subtype'   => $item_subtype,
			'level'          => $level,
			'bonus'          => $bonus,
			'damage_type'    => $damage_type,
			'damage_amount'  => $damage_amount,
			'concealability' => $concealability,
			'temper_list'    => $temper_list,
			'ability_list'   => $ability_list,
			'negative_list'  => $negative_list,
			'availability'   => $availability,
			'powers'         => $powers,
			'appearance'     => $appearance,
			'notes'          => $notes,
			'last_modified'  => $last_modified,
		];
	}

	/**
	 * Reads a `RoteClass` entry: its name, level, duration, a sphere
	 * trait list, a description, grades text, and a last-modified date.
	 * Has no version conditionals of its own.
	 *
	 * @param GV_Binary_Reader $r
	 * @param float            $version
	 * @return array<string,mixed>
	 */
	public static function parse_rote( GV_Binary_Reader $r, float $version ): array {
		$name         = $r->string();
		$level        = $r->int16();
		$duration     = $r->string();
		$sphere_list  = self::parse_trait_list( $r, $version );
		$description  = $r->string();
		$grades       = $r->string();
		$last_modified = $r->date();

		return [
			'name'          => $name,
			'level'         => $level,
			'duration'      => $duration,
			'sphere_list'   => $sphere_list,
			'description'   => $description,
			'grades'        => $grades,
			'last_modified' => $last_modified,
		];
	}

	/**
	 * Reads a `LocationClass` entry: identity and ownership fields, then
	 * a group of security-related fields (`sec_traits`, `sec_retests`,
	 * `gauntlet`, `link_list`, `umbra`) gated on format version 2.396,
	 * with `link_list` additionally requiring 2.399 and `access` gating
	 * on 2.399 alone.
	 *
	 * @param GV_Binary_Reader $r
	 * @param float            $version
	 * @return array<string,mixed>
	 */
	public static function parse_location( GV_Binary_Reader $r, float $version ): array {
		$name     = $r->string();
		$loc_type = $r->string();
		$level    = $r->int16();
		$owner    = $r->string();
		$access   = $version >= 2.399 ? $r->string() : '';
		$affinity = $r->string();
		$totem    = $r->string();

		$sec_traits  = null;
		$sec_retests = null;
		$gauntlet    = null;
		$link_list   = null;

		if ( $version >= 2.396 ) {
			$sec_traits  = $r->int16();
			$sec_retests = $r->int16();
			$gauntlet    = $r->int16();

			if ( $version >= 2.399 ) {
				$link_list = self::parse_trait_list( $r, $version );
			}
		}

		$where      = $r->string();
		$appearance = $r->string();
		$security   = $r->string();
		$umbra      = $version >= 2.396 ? $r->string() : '';
		$notes      = $r->string();
		$last_modified = $r->date();

		return [
			'name'          => $name,
			'loc_type'      => $loc_type,
			'level'         => $level,
			'owner'         => $owner,
			'access'        => $access,
			'affinity'      => $affinity,
			'totem'         => $totem,
			'sec_traits'    => $sec_traits,
			'sec_retests'   => $sec_retests,
			'gauntlet'      => $gauntlet,
			'link_list'     => $link_list,
			'where'         => $where,
			'appearance'    => $appearance,
			'security'      => $security,
			'umbra'         => $umbra,
			'notes'         => $notes,
			'last_modified' => $last_modified,
		];
	}

	/**
	 * Reads an `ActionClass` entry: the action date, character name, done
	 * flag, last-modified date, and a count-prefixed list of subactions
	 * read through `parse_action_node()`. Has no version conditionals of
	 * its own.
	 *
	 * @param GV_Binary_Reader $r
	 * @param float            $version
	 * @return array<string,mixed>
	 */
	public static function parse_action( GV_Binary_Reader $r, float $version ): array {
		$act_date      = $r->date();
		$char_name     = $r->string();
		$done          = $r->bool();
		$last_modified = $r->date();

		$count       = $r->int16();
		$subactions  = [];

		for ( $i = 0; $i < $count; $i++ ) {
			$subactions[] = self::parse_action_node( $r, $version );
		}

		return [
			'act_date'      => $act_date,
			'char_name'     => $char_name,
			'done'          => $done,
			'last_modified' => $last_modified,
			'subactions'    => $subactions,
		];
	}

	/**
	 * Reads a `PlotClass` entry: name, start and end dates, an outline,
	 * and a count-prefixed list of developments read through
	 * `parse_plot_node()`. `narrator` and `cast_list` are both read only
	 * when the format version is 2.399 or later.
	 *
	 * @param GV_Binary_Reader $r
	 * @param float            $version
	 * @return array<string,mixed>
	 */
	public static function parse_plot( GV_Binary_Reader $r, float $version ): array {
		$name          = $r->string();
		$start_date    = $r->date();
		$end_date      = $r->date();
		$narrator      = $version >= 2.399 ? $r->string() : '';
		$last_modified = $r->date();
		$outline       = $r->string();

		$cast_list = $version >= 2.399 ? self::parse_trait_list( $r, $version ) : null;

		$count        = $r->int16();
		$developments = [];

		for ( $i = 0; $i < $count; $i++ ) {
			$developments[] = self::parse_plot_node( $r, $version );
		}

		return [
			'name'          => $name,
			'start_date'    => $start_date,
			'end_date'      => $end_date,
			'narrator'      => $narrator,
			'last_modified' => $last_modified,
			'outline'       => $outline,
			'cast_list'     => $cast_list,
			'developments'  => $developments,
		];
	}

	/**
	 * `RumorClass.InputFromBinary` (GV301Source/Code/RumorClass.cls,
	 * lines 611-646). `Query` is read only when `MultiKey` is empty - a
	 * DATA-dependent branch, not a version gate: a rumor with a non-empty
	 * `MultiKey` never had a `QueryClass` written for it in the first place, so
	 * there is nothing to desynchronize on if it is skipped.
	 *
	 * @param GV_Binary_Reader $r
	 * @param float            $version
	 * @return array<string,mixed>
	 */
	public static function parse_rumor( GV_Binary_Reader $r, float $version ): array {
		$title         = $r->string();
		$rumor_date    = $r->date();
		$category      = $r->int32();
		$multi_key     = $r->string();
		$multi_match   = $r->string();
		$done          = $r->bool();
		$last_modified = $r->date();

		$query = $multi_key === '' ? self::parse_query( $r, $version ) : null;

		$count    = $r->int16();
		$variants = [];

		for ( $i = 0; $i < $count; $i++ ) {
			$variants[] = self::parse_rumor_node( $r, $version );
		}

		return [
			'title'         => $title,
			'rumor_date'    => $rumor_date,
			'category'      => $category,
			'multi_key'     => $multi_key,
			'multi_match'   => $multi_match,
			'done'          => $done,
			'last_modified' => $last_modified,
			'query'         => $query,
			'variants'      => $variants,
		];
	}

	// -------------------------------------------------------------------------
	// Characters
	// -------------------------------------------------------------------------

	/**
	 * Reads the leading `RaceType` selector and dispatches to the
	 * matching character parser. There is no record-length field anywhere
	 * in this format, so an unrecognized code cannot be skipped past; it
	 * throws instead.
	 *
	 * @param GV_Binary_Reader $r
	 * @param float            $version
	 * @return array<string,mixed>
	 * @throws \RuntimeException On an unrecognized RaceType code.
	 */
	public static function parse_character( GV_Binary_Reader $r, float $version ): array {
		$race_code = $r->int16();
		$race      = self::RACE_TYPE_MAP[ $race_code ] ?? null;

		if ( $race === null ) {
			throw new \RuntimeException(
				sprintf( 'Unrecognized RaceType code %d at byte %d - cannot skip an unknown character record.', $race_code, $r->tell() )
			);
		}

		return match ( $race ) {
			'vampire'    => self::parse_character_vampire( $r, $version ),
			'werewolf'   => self::parse_character_werewolf( $r, $version ),
			'mage'       => self::parse_character_mage( $r, $version ),
			'changeling' => self::parse_character_changeling( $r, $version ),
			'wraith'     => self::parse_character_wraith( $r, $version ),
			'mortal'     => self::parse_character_mortal( $r, $version ),
			'mummy'      => self::parse_character_mummy( $r, $version ),
			'kueijin'    => self::parse_character_kueijin( $r, $version ),
			'fera'       => self::parse_character_fera( $r, $version ),
			'various'    => self::parse_character_various( $r, $version ),
			'hunter'     => self::parse_character_hunter( $r, $version ),
			'demon'      => self::parse_character_demon( $r, $version ),
		};
	}

	/**
	 * Backfills `physical_max`/`social_max`/`mental_max` from the actual
	 * trait counts of the three resource pools, for a source with no
	 * stored pool-max fields of its own. Not a binary read: pure
	 * post-processing on already-parsed trait lists, taking the highest
	 * of the given starting max and each pool's trait count.
	 *
	 * Public so `GEX_Xml_Parser`'s character parsers can reuse it
	 * directly, since the XML format never carries these three fields
	 * either.
	 *
	 * @param int                 $physical_max
	 * @param array<string,mixed> $physical
	 * @param array<string,mixed> $social
	 * @param array<string,mixed> $mental
	 * @return array{0:int,1:int,2:int} [physical_max, social_max, mental_max]
	 */
	public static function backfill_pool_max( int $physical_max, array $physical, array $social, array $mental ): array {
		$max = $physical_max;
		$max = max( $max, count( $physical['traits'] ) );
		$max = max( $max, count( $social['traits'] ) );
		$max = max( $max, count( $mental['traits'] ) );

		return [ $max, $max, $max ];
	}

	/**
	 * Reads a `VampireClass` entry. Version-gates several fields:
	 * `coterie` at format version 2.395+, `sire`/`aura`/`aura_bonus` at
	 * 2.399+, a full resource-pool split at 2.397+ that back-fills the
	 * `temp_*` fields for older files, and a trailing count-prefixed boon
	 * list at 2.399+ read through `parse_boon()`.
	 *
	 * @param GV_Binary_Reader $r
	 * @param float            $version
	 * @return array<string,mixed>
	 */
	private static function parse_character_vampire( GV_Binary_Reader $r, float $version ): array {
		$name     = $r->string();
		$nature   = $r->string();
		$demeanor = $r->string();
		$clan     = $r->string();
		$sect     = $r->string();
		$coterie  = $version >= 2.395 ? $r->string() : '';
		$sire     = $version >= 2.399 ? $r->string() : '';
		$generation = $r->int16();
		$title    = $r->string();

		if ( $version >= 2.397 ) {
			$blood              = $r->int16();
			$temp_blood         = $r->int16();
			$willpower          = $r->int16();
			$temp_willpower     = $r->int16();
			$conscience         = $r->int16();
			$temp_conscience    = $r->int16();
			$self_control       = $r->int16();
			$temp_self_control  = $r->int16();
			$courage            = $r->int16();
			$temp_courage       = $r->int16();
			$path               = $r->string();
			$path_traits        = $r->int16();
			$temp_path_traits   = $r->int16();
			$aura       = $version >= 2.399 ? $r->string() : '';
			$aura_bonus = $version >= 2.399 ? $r->string() : '';
			$physical_max = $r->int16();
			$social_max   = $r->int16();
			$mental_max   = $r->int16();
		} else {
			$blood            = $r->int16();
			$willpower        = $r->int16();
			$conscience       = $r->int16();
			$self_control     = $r->int16();
			$courage          = $r->int16();
			$path             = $r->string();
			$path_traits      = $r->int16();
			$temp_blood       = $blood;
			$temp_willpower   = $willpower;
			$temp_conscience  = $conscience;
			$temp_self_control = $self_control;
			$temp_courage     = $courage;
			$temp_path_traits = $path_traits;
			$aura         = '';
			$aura_bonus   = '';
			$physical_max = 0;
			$social_max   = 0;
			$mental_max   = 0;
		}

		$player = $r->string();
		$status = $r->string();
		$id     = $r->string();

		if ( $version >= 2.397 ) {
			$start_date = $r->date();
		} else {
			// Pre-2.397 files stored the start date as free text; not parsed, left null.
			$r->string();
			$start_date = null;
		}

		$narrator      = $r->string();
		$is_npc        = $r->bool();
		$last_modified = $r->date();

		$experience  = self::parse_experience( $r, $version );
		$trait_lists = self::read_trait_lists( $r, $version, 'vampire' );
		$physical    = $trait_lists['Physical'];
		$social      = $trait_lists['Social'];
		$mental      = $trait_lists['Mental'];

		$boons = [];
		if ( $version >= 2.399 ) {
			$boon_count = $r->int16();
			for ( $i = 0; $i < $boon_count; $i++ ) {
				$boons[] = self::parse_boon( $r, $version );
			}
		}

		$biography = $version >= 2.397 ? $r->string() : '';
		$notes     = $r->string();

		if ( $version < 2.397 ) {
			[ $physical_max, $social_max, $mental_max ] = self::backfill_pool_max( $physical_max, $physical, $social, $mental );
		}

		return [
			'race'              => 'vampire',
			'name'              => $name,
			'nature'            => $nature,
			'demeanor'          => $demeanor,
			'clan'              => $clan,
			'sect'              => $sect,
			'coterie'           => $coterie,
			'sire'              => $sire,
			'generation'        => $generation,
			'title'             => $title,
			'blood'             => $blood,
			'temp_blood'        => $temp_blood,
			'willpower'         => $willpower,
			'temp_willpower'    => $temp_willpower,
			'conscience'        => $conscience,
			'temp_conscience'   => $temp_conscience,
			'self_control'      => $self_control,
			'temp_self_control' => $temp_self_control,
			'courage'           => $courage,
			'temp_courage'      => $temp_courage,
			'path'              => $path,
			'path_traits'       => $path_traits,
			'temp_path_traits'  => $temp_path_traits,
			'aura'              => $aura,
			'aura_bonus'        => $aura_bonus,
			'physical_max'      => $physical_max,
			'social_max'        => $social_max,
			'mental_max'        => $mental_max,
			'player'            => $player,
			'status'            => $status,
			'id'                => $id,
			'start_date'        => $start_date,
			'narrator'          => $narrator,
			'is_npc'            => $is_npc,
			'last_modified'     => $last_modified,
			'experience'        => $experience,
			'trait_lists'       => $trait_lists,
			'boons'             => $boons,
			'biography'         => $biography,
			'notes'             => $notes,
		];
	}

	/**
	 * Reads a `WerewolfClass` entry. `temp_honor`/`temp_glory`/
	 * `temp_wisdom` are `Single` fields. Before format version 2.395,
	 * `honor`/`glory`/`wisdom` were not stored as separate integers: each
	 * stat was a single packed `Single` value, split here into an integer
	 * part and a fractional part scaled by 10.
	 *
	 * @param GV_Binary_Reader $r
	 * @param float            $version
	 * @return array<string,mixed>
	 */
	private static function parse_character_werewolf( GV_Binary_Reader $r, float $version ): array {
		$name     = $r->string();
		$nature   = $r->string();
		$demeanor = $r->string();
		$tribe    = $r->string();
		$breed    = $r->string();
		$auspice  = $r->string();
		$rank     = $r->string();
		$pack     = $r->string();
		$totem    = $r->string();
		$camp     = $r->string();
		$position = $r->string();
		$notoriety = $r->int16();

		if ( $version >= 2.397 ) {
			$rage           = $r->int16();
			$temp_rage      = $r->int16();
			$gnosis         = $r->int16();
			$temp_gnosis    = $r->int16();
			$willpower      = $r->int16();
			$temp_willpower = $r->int16();
		} else {
			$rage           = $r->int16();
			$gnosis         = $r->int16();
			$willpower      = $r->int16();
			$temp_rage      = $rage;
			$temp_gnosis    = $gnosis;
			$temp_willpower = $willpower;
		}

		if ( $version >= 2.395 ) {
			$honor       = $r->int16();
			$glory       = $r->int16();
			$wisdom      = $r->int16();
			$temp_honor  = $r->single();
			$temp_glory  = $r->single();
			$temp_wisdom = $r->single();
		} else {
			$temp_honor  = $r->single();
			$honor       = (int) $temp_honor;
			$temp_honor  = round( ( $temp_honor - $honor ) * 10, 1 );
			$temp_glory  = $r->single();
			$glory       = (int) $temp_glory;
			$temp_glory  = round( ( $temp_glory - $glory ) * 10, 1 );
			$temp_wisdom = $r->single();
			$wisdom      = (int) $temp_wisdom;
			$temp_wisdom = round( ( $temp_wisdom - $wisdom ) * 10, 1 );
		}

		$physical_max = 0;
		$social_max   = 0;
		$mental_max   = 0;
		if ( $version >= 2.397 ) {
			$physical_max = $r->int16();
			$social_max   = $r->int16();
			$mental_max   = $r->int16();
		}

		$player = $r->string();
		$status = $r->string();
		$id     = $r->string();

		if ( $version >= 2.397 ) {
			$start_date = $r->date();
		} else {
			$r->string();
			$start_date = null;
		}

		$narrator      = $r->string();
		$is_npc        = $r->bool();
		$last_modified = $r->date();

		$experience  = self::parse_experience( $r, $version );
		$trait_lists = self::read_trait_lists( $r, $version, 'werewolf' );
		$physical    = $trait_lists['Physical'];
		$social      = $trait_lists['Social'];
		$mental      = $trait_lists['Mental'];

		$biography = $version >= 2.397 ? $r->string() : '';
		$notes     = $r->string();

		if ( $version < 2.397 ) {
			[ $physical_max, $social_max, $mental_max ] = self::backfill_pool_max( $physical_max, $physical, $social, $mental );
		}

		return [
			'race'           => 'werewolf',
			'name'           => $name,
			'nature'         => $nature,
			'demeanor'       => $demeanor,
			'tribe'          => $tribe,
			'breed'          => $breed,
			'auspice'        => $auspice,
			'rank'           => $rank,
			'pack'           => $pack,
			'totem'          => $totem,
			'camp'           => $camp,
			'position'       => $position,
			'notoriety'      => $notoriety,
			'rage'           => $rage,
			'temp_rage'      => $temp_rage,
			'gnosis'         => $gnosis,
			'temp_gnosis'    => $temp_gnosis,
			'willpower'      => $willpower,
			'temp_willpower' => $temp_willpower,
			'honor'          => $honor,
			'glory'          => $glory,
			'wisdom'         => $wisdom,
			'temp_honor'     => $temp_honor,
			'temp_glory'     => $temp_glory,
			'temp_wisdom'    => $temp_wisdom,
			'physical_max'   => $physical_max,
			'social_max'     => $social_max,
			'mental_max'     => $mental_max,
			'player'         => $player,
			'status'         => $status,
			'id'             => $id,
			'start_date'     => $start_date,
			'narrator'       => $narrator,
			'is_npc'         => $is_npc,
			'last_modified'  => $last_modified,
			'experience'     => $experience,
			'trait_lists'    => $trait_lists,
			'biography'      => $biography,
			'notes'          => $notes,
		];
	}

	/**
	 * Reads a `MageClass` entry. `foci` is read after the trait-list
	 * block rather than with the other identity fields near the top,
	 * matching the file's actual read order rather than the class's
	 * property declaration order.
	 *
	 * @param GV_Binary_Reader $r
	 * @param float            $version
	 * @return array<string,mixed>
	 */
	private static function parse_character_mage( GV_Binary_Reader $r, float $version ): array {
		$name      = $r->string();
		$nature    = $r->string();
		$demeanor  = $r->string();
		$essence   = $r->string();
		$tradition = $r->string();
		$faction   = $r->string();
		$cabal     = $r->string();
		$rank      = $r->string();

		if ( $version >= 2.397 ) {
			$willpower       = $r->int16();
			$temp_willpower  = $r->int16();
			$arete           = $r->int16();
			$temp_arete      = $r->int16();
			$quintessence    = $r->int16();
			$temp_quintessence = $r->int16();
			$paradox         = $r->int16();
			$temp_paradox    = $r->int16();
			$physical_max    = $r->int16();
			$social_max      = $r->int16();
			$mental_max      = $r->int16();
		} else {
			$willpower    = $r->int16();
			$arete        = $r->int16();
			$quintessence = $r->int16();
			$paradox      = $r->int16();
			$temp_willpower    = $willpower;
			$temp_arete        = $arete;
			$temp_quintessence = $quintessence;
			$temp_paradox      = $paradox;
			$physical_max = 0;
			$social_max   = 0;
			$mental_max   = 0;
		}

		$player = $r->string();
		$status = $r->string();
		$id     = $r->string();

		if ( $version >= 2.397 ) {
			$start_date = $r->date();
		} else {
			$r->string();
			$start_date = null;
		}

		$narrator      = $r->string();
		$is_npc        = $r->bool();
		$last_modified = $r->date();

		$experience  = self::parse_experience( $r, $version );
		$trait_lists = self::read_trait_lists( $r, $version, 'mage' );
		$physical    = $trait_lists['Physical'];
		$social      = $trait_lists['Social'];
		$mental      = $trait_lists['Mental'];

		$foci      = $r->string();
		$biography = $version >= 2.397 ? $r->string() : '';
		$notes     = $r->string();

		if ( $version < 2.397 ) {
			[ $physical_max, $social_max, $mental_max ] = self::backfill_pool_max( $physical_max, $physical, $social, $mental );
		}

		return [
			'race'              => 'mage',
			'name'              => $name,
			'nature'            => $nature,
			'demeanor'          => $demeanor,
			'essence'           => $essence,
			'tradition'         => $tradition,
			'faction'           => $faction,
			'cabal'             => $cabal,
			'rank'              => $rank,
			'willpower'         => $willpower,
			'temp_willpower'    => $temp_willpower,
			'arete'             => $arete,
			'temp_arete'        => $temp_arete,
			'quintessence'      => $quintessence,
			'temp_quintessence' => $temp_quintessence,
			'paradox'           => $paradox,
			'temp_paradox'      => $temp_paradox,
			'physical_max'      => $physical_max,
			'social_max'        => $social_max,
			'mental_max'        => $mental_max,
			'player'            => $player,
			'status'            => $status,
			'id'                => $id,
			'start_date'        => $start_date,
			'narrator'          => $narrator,
			'is_npc'            => $is_npc,
			'last_modified'     => $last_modified,
			'experience'        => $experience,
			'trait_lists'       => $trait_lists,
			'foci'              => $foci,
			'biography'         => $biography,
			'notes'             => $notes,
		];
	}

	/**
	 * Reads a `ChangelingClass` entry. The opening identity strings are
	 * read in the order `seelie_legacy, unseelie_legacy, court, kith,
	 * seeming, house, threshold, title`, which does not match the class's
	 * property declaration order.
	 *
	 * @param GV_Binary_Reader $r
	 * @param float            $version
	 * @return array<string,mixed>
	 */
	private static function parse_character_changeling( GV_Binary_Reader $r, float $version ): array {
		$name            = $r->string();
		$seelie_legacy   = $r->string();
		$unseelie_legacy = $r->string();
		$court           = $r->string();
		$kith            = $r->string();
		$seeming         = $r->string();
		$house           = $r->string();
		$threshold       = $r->string();
		$title           = $r->string();

		if ( $version >= 2.397 ) {
			$glamour       = $r->int16();
			$temp_glamour  = $r->int16();
			$banality      = $r->int16();
			$temp_banality = $r->int16();
			$willpower     = $r->int16();
			$temp_willpower = $r->int16();
			$physical_max  = $r->int16();
			$social_max    = $r->int16();
			$mental_max    = $r->int16();
		} else {
			$glamour   = $r->int16();
			$banality  = $r->int16();
			$willpower = $r->int16();
			$temp_glamour   = $glamour;
			$temp_banality  = $banality;
			$temp_willpower = $willpower;
			$physical_max = 0;
			$social_max   = 0;
			$mental_max   = 0;
		}

		$player = $r->string();
		$status = $r->string();
		$id     = $r->string();

		if ( $version >= 2.397 ) {
			$start_date = $r->date();
		} else {
			$r->string();
			$start_date = null;
		}

		$narrator      = $r->string();
		$is_npc        = $r->bool();
		$last_modified = $r->date();

		$experience  = self::parse_experience( $r, $version );
		$trait_lists = self::read_trait_lists( $r, $version, 'changeling' );
		$physical    = $trait_lists['Physical'];
		$social      = $trait_lists['Social'];
		$mental      = $trait_lists['Mental'];

		$oaths     = $r->string();
		$biography = $version >= 2.397 ? $r->string() : '';
		$notes     = $r->string();

		if ( $version < 2.397 ) {
			[ $physical_max, $social_max, $mental_max ] = self::backfill_pool_max( $physical_max, $physical, $social, $mental );
		}

		return [
			'race'              => 'changeling',
			'name'              => $name,
			'seelie_legacy'     => $seelie_legacy,
			'unseelie_legacy'   => $unseelie_legacy,
			'court'             => $court,
			'kith'              => $kith,
			'seeming'           => $seeming,
			'house'             => $house,
			'threshold'         => $threshold,
			'title'             => $title,
			'glamour'           => $glamour,
			'temp_glamour'      => $temp_glamour,
			'banality'          => $banality,
			'temp_banality'     => $temp_banality,
			'willpower'         => $willpower,
			'temp_willpower'    => $temp_willpower,
			'physical_max'      => $physical_max,
			'social_max'        => $social_max,
			'mental_max'        => $mental_max,
			'player'            => $player,
			'status'            => $status,
			'id'                => $id,
			'start_date'        => $start_date,
			'narrator'          => $narrator,
			'is_npc'            => $is_npc,
			'last_modified'     => $last_modified,
			'experience'        => $experience,
			'trait_lists'       => $trait_lists,
			'oaths'             => $oaths,
			'biography'         => $biography,
			'notes'             => $notes,
		];
	}

	/**
	 * Reads a `WraithClass` entry. `ethnos` is a genuine 4-byte enum
	 * field, unlike the top-level `RaceType` selector which is overridden
	 * to 2 bytes. `temp_angst` is read only when the format version is
	 * 2.397 or later, with no fallback for older files, so it is left at
	 * its zero default. This class has no `biography` field at all.
	 *
	 * @param GV_Binary_Reader $r
	 * @param float            $version
	 * @return array<string,mixed>
	 */
	private static function parse_character_wraith( GV_Binary_Reader $r, float $version ): array {
		$name     = $r->string();
		$ethnos   = $r->int32();
		$nature   = $r->string();
		$demeanor = $r->string();
		$guild    = $r->string();
		$faction  = $r->string();
		$legion   = $r->string();
		$rank     = $r->string();

		if ( $version >= 2.397 ) {
			$pathos          = $r->int16();
			$temp_pathos     = $r->int16();
			$corpus          = $r->int16();
			$temp_corpus     = $r->int16();
			$willpower       = $r->int16();
			$temp_willpower  = $r->int16();
		} else {
			$pathos    = $r->int16();
			$corpus    = $r->int16();
			$willpower = $r->int16();
			$temp_pathos    = $pathos;
			$temp_corpus    = $corpus;
			$temp_willpower = $willpower;
		}

		$shadow_archetype = $r->string();
		$shadow_player    = $r->string();
		$angst            = $r->int16();

		$temp_angst   = 0;
		$physical_max = 0;
		$social_max   = 0;
		$mental_max   = 0;
		if ( $version >= 2.397 ) {
			$temp_angst   = $r->int16();
			$physical_max = $r->int16();
			$social_max   = $r->int16();
			$mental_max   = $r->int16();
		}

		$player = $r->string();
		$status = $r->string();
		$id     = $r->string();

		if ( $version >= 2.397 ) {
			$start_date = $r->date();
		} else {
			$r->string();
			$start_date = null;
		}

		$narrator      = $r->string();
		$is_npc        = $r->bool();
		$last_modified = $r->date();

		$experience = self::parse_experience( $r, $version );

		// Wraith's own byte order genuinely interleaves trait lists with free-text
		// fields - read in the shape table's own order, sliced at each interleave
		// point (Physical..Influences, then Arcanoi..Locations, then Thorns alone).
		$trait_lists = self::read_trait_lists( $r, $version, 'wraith', 0, 10 );
		$physical    = $trait_lists['Physical'];
		$social      = $trait_lists['Social'];
		$mental      = $trait_lists['Mental'];

		$passions = $r->string();
		$fetters  = $r->string();
		$life     = $r->string();
		$death    = $r->string();
		$haunt    = $r->string();
		$regret   = $r->string();

		$trait_lists += self::read_trait_lists( $r, $version, 'wraith', 10, 5 );

		$dark_passions = $r->string();
		$trait_lists  += self::read_trait_lists( $r, $version, 'wraith', 15, 1 );

		$notes = $r->string();

		if ( $version < 2.397 ) {
			[ $physical_max, $social_max, $mental_max ] = self::backfill_pool_max( $physical_max, $physical, $social, $mental );
		}

		return [
			'race'              => 'wraith',
			'name'              => $name,
			'ethnos'            => $ethnos,
			'nature'            => $nature,
			'demeanor'          => $demeanor,
			'guild'             => $guild,
			'faction'           => $faction,
			'legion'            => $legion,
			'rank'              => $rank,
			'pathos'            => $pathos,
			'temp_pathos'       => $temp_pathos,
			'corpus'            => $corpus,
			'temp_corpus'       => $temp_corpus,
			'willpower'         => $willpower,
			'temp_willpower'    => $temp_willpower,
			'shadow_archetype'  => $shadow_archetype,
			'shadow_player'     => $shadow_player,
			'angst'             => $angst,
			'temp_angst'        => $temp_angst,
			'physical_max'      => $physical_max,
			'social_max'        => $social_max,
			'mental_max'        => $mental_max,
			'player'            => $player,
			'status'            => $status,
			'id'                => $id,
			'start_date'        => $start_date,
			'narrator'          => $narrator,
			'is_npc'            => $is_npc,
			'last_modified'     => $last_modified,
			'experience'        => $experience,
			'trait_lists'       => $trait_lists,
			'passions'          => $passions,
			'fetters'           => $fetters,
			'life'              => $life,
			'death'             => $death,
			'haunt'             => $haunt,
			'regret'            => $regret,
			'dark_passions'     => $dark_passions,
			'notes'             => $notes,
		];
	}

	/**
	 * Reads a `MortalClass` entry. `regnant` is read only when the format
	 * version is 2.399 or later, gated independently of the surrounding
	 * 2.397+ resource-pool block.
	 *
	 * @param GV_Binary_Reader $r
	 * @param float            $version
	 * @return array<string,mixed>
	 */
	private static function parse_character_mortal( GV_Binary_Reader $r, float $version ): array {
		$name        = $r->string();
		$nature      = $r->string();
		$demeanor    = $r->string();
		$motivation  = $r->string();
		$association = $r->string();
		$regnant     = $version >= 2.399 ? $r->string() : '';
		$title       = $r->string();

		if ( $version >= 2.397 ) {
			$willpower        = $r->int16();
			$temp_willpower   = $r->int16();
			$humanity         = $r->int16();
			$temp_humanity    = $r->int16();
			$conscience       = $r->int16();
			$temp_conscience  = $r->int16();
			$self_control     = $r->int16();
			$temp_self_control = $r->int16();
			$courage          = $r->int16();
			$temp_courage     = $r->int16();
			$blood            = $r->int16();
			$temp_blood       = $r->int16();
			$true_faith       = $r->int16();
			$temp_true_faith  = $r->int16();
			$physical_max     = $r->int16();
			$social_max       = $r->int16();
			$mental_max       = $r->int16();
		} else {
			$willpower    = $r->int16();
			$humanity     = $r->int16();
			$conscience   = $r->int16();
			$self_control = $r->int16();
			$courage      = $r->int16();
			$blood        = $r->int16();
			$true_faith   = $r->int16();
			$temp_willpower    = $willpower;
			$temp_humanity     = $humanity;
			$temp_conscience   = $conscience;
			$temp_self_control = $self_control;
			$temp_courage      = $courage;
			$temp_blood        = $blood;
			$temp_true_faith   = $true_faith;
			$physical_max = 0;
			$social_max   = 0;
			$mental_max   = 0;
		}

		$player = $r->string();
		$status = $r->string();
		$id     = $r->string();

		if ( $version >= 2.397 ) {
			$start_date = $r->date();
		} else {
			$r->string();
			$start_date = null;
		}

		$narrator      = $r->string();
		$is_npc        = $r->bool();
		$last_modified = $r->date();

		$experience  = self::parse_experience( $r, $version );
		$trait_lists = self::read_trait_lists( $r, $version, 'mortal' );
		$physical    = $trait_lists['Physical'];
		$social      = $trait_lists['Social'];
		$mental      = $trait_lists['Mental'];

		$other     = $r->string();
		$biography = $version >= 2.397 ? $r->string() : '';
		$notes     = $r->string();

		if ( $version < 2.397 ) {
			[ $physical_max, $social_max, $mental_max ] = self::backfill_pool_max( $physical_max, $physical, $social, $mental );
		}

		return [
			'race'              => 'mortal',
			'name'              => $name,
			'nature'            => $nature,
			'demeanor'          => $demeanor,
			'motivation'        => $motivation,
			'association'       => $association,
			'regnant'           => $regnant,
			'title'             => $title,
			'willpower'         => $willpower,
			'temp_willpower'    => $temp_willpower,
			'humanity'          => $humanity,
			'temp_humanity'     => $temp_humanity,
			'conscience'        => $conscience,
			'temp_conscience'   => $temp_conscience,
			'self_control'      => $self_control,
			'temp_self_control' => $temp_self_control,
			'courage'           => $courage,
			'temp_courage'      => $temp_courage,
			'blood'             => $blood,
			'temp_blood'        => $temp_blood,
			'true_faith'        => $true_faith,
			'temp_true_faith'   => $temp_true_faith,
			'physical_max'      => $physical_max,
			'social_max'        => $social_max,
			'mental_max'        => $mental_max,
			'player'            => $player,
			'status'            => $status,
			'id'                => $id,
			'start_date'        => $start_date,
			'narrator'          => $narrator,
			'is_npc'            => $is_npc,
			'last_modified'     => $last_modified,
			'experience'        => $experience,
			'trait_lists'       => $trait_lists,
			'other'             => $other,
			'biography'         => $biography,
			'notes'             => $notes,
		];
	}

	/**
	 * Reads a `MummyClass` entry: identity fields, an eight-stat resource
	 * pool (`willpower`, `sekhem`, `balance`, `memory`, `integrity`,
	 * `joy`, `ba`, `ka`) each with a `temp_*` counterpart, and the
	 * standard trait-list block.
	 *
	 * @param GV_Binary_Reader $r
	 * @param float            $version
	 * @return array<string,mixed>
	 */
	private static function parse_character_mummy( GV_Binary_Reader $r, float $version ): array {
		$name     = $r->string();
		$amenti   = $r->string();
		$nature   = $r->string();
		$demeanor = $r->string();

		if ( $version >= 2.397 ) {
			$willpower       = $r->int16();
			$temp_willpower  = $r->int16();
			$sekhem          = $r->int16();
			$temp_sekhem     = $r->int16();
			$balance         = $r->int16();
			$temp_balance    = $r->int16();
			$memory          = $r->int16();
			$temp_memory     = $r->int16();
			$integrity       = $r->int16();
			$temp_integrity  = $r->int16();
			$joy             = $r->int16();
			$temp_joy        = $r->int16();
			$ba              = $r->int16();
			$temp_ba         = $r->int16();
			$ka              = $r->int16();
			$temp_ka         = $r->int16();
			$physical_max    = $r->int16();
			$social_max      = $r->int16();
			$mental_max      = $r->int16();
		} else {
			$willpower  = $r->int16();
			$sekhem     = $r->int16();
			$balance    = $r->int16();
			$memory     = $r->int16();
			$integrity  = $r->int16();
			$joy        = $r->int16();
			$ba         = $r->int16();
			$ka         = $r->int16();
			$temp_willpower = $willpower;
			$temp_sekhem    = $sekhem;
			$temp_balance   = $balance;
			$temp_memory    = $memory;
			$temp_integrity = $integrity;
			$temp_joy       = $joy;
			$temp_ba        = $ba;
			$temp_ka        = $ka;
			$physical_max = 0;
			$social_max   = 0;
			$mental_max   = 0;
		}

		$player = $r->string();
		$status = $r->string();
		$id     = $r->string();

		if ( $version >= 2.397 ) {
			$start_date = $r->date();
		} else {
			$r->string();
			$start_date = null;
		}

		$narrator      = $r->string();
		$is_npc        = $r->bool();
		$last_modified = $r->date();

		$experience  = self::parse_experience( $r, $version );
		$trait_lists = self::read_trait_lists( $r, $version, 'mummy' );
		$physical    = $trait_lists['Physical'];
		$social      = $trait_lists['Social'];
		$mental      = $trait_lists['Mental'];

		$inheritance = $r->string();
		$biography   = $version >= 2.397 ? $r->string() : '';
		$notes       = $r->string();

		if ( $version < 2.397 ) {
			[ $physical_max, $social_max, $mental_max ] = self::backfill_pool_max( $physical_max, $physical, $social, $mental );
		}

		return [
			'race'             => 'mummy',
			'name'             => $name,
			'amenti'           => $amenti,
			'nature'           => $nature,
			'demeanor'         => $demeanor,
			'willpower'        => $willpower,
			'temp_willpower'   => $temp_willpower,
			'sekhem'           => $sekhem,
			'temp_sekhem'      => $temp_sekhem,
			'balance'          => $balance,
			'temp_balance'     => $temp_balance,
			'memory'           => $memory,
			'temp_memory'      => $temp_memory,
			'integrity'        => $integrity,
			'temp_integrity'   => $temp_integrity,
			'joy'              => $joy,
			'temp_joy'         => $temp_joy,
			'ba'               => $ba,
			'temp_ba'          => $temp_ba,
			'ka'               => $ka,
			'temp_ka'          => $temp_ka,
			'physical_max'     => $physical_max,
			'social_max'       => $social_max,
			'mental_max'       => $mental_max,
			'player'           => $player,
			'status'           => $status,
			'id'               => $id,
			'start_date'       => $start_date,
			'narrator'         => $narrator,
			'is_npc'           => $is_npc,
			'last_modified'    => $last_modified,
			'experience'       => $experience,
			'trait_lists'      => $trait_lists,
			'inheritance'      => $inheritance,
			'biography'        => $biography,
			'notes'            => $notes,
		];
	}

	/**
	 * Reads a `KueiJinClass` entry. The opening identity strings are read
	 * in the order `dharma, balance, direction`, which does not match the
	 * class's declared property order.
	 *
	 * @param GV_Binary_Reader $r
	 * @param float            $version
	 * @return array<string,mixed>
	 */
	private static function parse_character_kueijin( GV_Binary_Reader $r, float $version ): array {
		$name          = $r->string();
		$nature        = $r->string();
		$demeanor      = $r->string();
		$dharma        = $r->string();
		$balance       = $r->string();
		$direction     = $r->string();
		$station       = $r->string();
		$po_archetype  = $r->string();

		if ( $version >= 2.397 ) {
			$hun               = $r->int16();
			$temp_hun          = $r->int16();
			$po                = $r->int16();
			$temp_po           = $r->int16();
			$yin_chi           = $r->int16();
			$temp_yin_chi      = $r->int16();
			$yang_chi          = $r->int16();
			$temp_yang_chi     = $r->int16();
			$demon_chi         = $r->int16();
			$temp_demon_chi    = $r->int16();
			$dharma_traits     = $r->int16();
			$temp_dharma_traits = $r->int16();
			$willpower         = $r->int16();
			$temp_willpower    = $r->int16();
			$physical_max      = $r->int16();
			$social_max        = $r->int16();
			$mental_max        = $r->int16();
		} else {
			$hun           = $r->int16();
			$po            = $r->int16();
			$yin_chi       = $r->int16();
			$yang_chi      = $r->int16();
			$demon_chi     = $r->int16();
			$dharma_traits = $r->int16();
			$willpower     = $r->int16();
			$temp_hun            = $hun;
			$temp_po             = $po;
			$temp_yin_chi        = $yin_chi;
			$temp_yang_chi       = $yang_chi;
			$temp_demon_chi      = $demon_chi;
			$temp_dharma_traits  = $dharma_traits;
			$temp_willpower      = $willpower;
			$physical_max = 0;
			$social_max   = 0;
			$mental_max   = 0;
		}

		$player = $r->string();
		$status = $r->string();
		$id     = $r->string();

		if ( $version >= 2.397 ) {
			$start_date = $r->date();
		} else {
			$r->string();
			$start_date = null;
		}

		$narrator      = $r->string();
		$is_npc        = $r->bool();
		$last_modified = $r->date();

		$experience  = self::parse_experience( $r, $version );
		$trait_lists = self::read_trait_lists( $r, $version, 'kueijin' );
		$physical    = $trait_lists['Physical'];
		$social      = $trait_lists['Social'];
		$mental      = $trait_lists['Mental'];

		$biography = $version >= 2.397 ? $r->string() : '';
		$notes     = $r->string();

		if ( $version < 2.397 ) {
			[ $physical_max, $social_max, $mental_max ] = self::backfill_pool_max( $physical_max, $physical, $social, $mental );
		}

		return [
			'race'                => 'kueijin',
			'name'                => $name,
			'nature'              => $nature,
			'demeanor'            => $demeanor,
			'dharma'              => $dharma,
			'balance'             => $balance,
			'direction'           => $direction,
			'station'             => $station,
			'po_archetype'        => $po_archetype,
			'hun'                 => $hun,
			'temp_hun'            => $temp_hun,
			'po'                  => $po,
			'temp_po'             => $temp_po,
			'yin_chi'             => $yin_chi,
			'temp_yin_chi'        => $temp_yin_chi,
			'yang_chi'            => $yang_chi,
			'temp_yang_chi'       => $temp_yang_chi,
			'demon_chi'           => $demon_chi,
			'temp_demon_chi'      => $temp_demon_chi,
			'dharma_traits'       => $dharma_traits,
			'temp_dharma_traits'  => $temp_dharma_traits,
			'willpower'           => $willpower,
			'temp_willpower'      => $temp_willpower,
			'physical_max'        => $physical_max,
			'social_max'          => $social_max,
			'mental_max'          => $mental_max,
			'player'              => $player,
			'status'              => $status,
			'id'                  => $id,
			'start_date'          => $start_date,
			'narrator'            => $narrator,
			'is_npc'              => $is_npc,
			'last_modified'       => $last_modified,
			'experience'          => $experience,
			'trait_lists'         => $trait_lists,
			'biography'           => $biography,
			'notes'               => $notes,
		];
	}

	/**
	 * Reads a `FeraClass` entry. Structurally identical to
	 * `WerewolfClass`, including the same pre-2.395 packed-Single encoding
	 * for `honor`/`glory`/`wisdom` described in
	 * `parse_character_werewolf()`, but without a `camp` field and with
	 * `tribe` renamed to `fera`.
	 *
	 * @param GV_Binary_Reader $r
	 * @param float            $version
	 * @return array<string,mixed>
	 */
	private static function parse_character_fera( GV_Binary_Reader $r, float $version ): array {
		$name     = $r->string();
		$nature   = $r->string();
		$demeanor = $r->string();
		$fera     = $r->string();
		$breed    = $r->string();
		$auspice  = $r->string();
		$rank     = $r->string();
		$pack     = $r->string();
		$totem    = $r->string();
		$position = $r->string();
		$notoriety = $r->int16();

		if ( $version >= 2.397 ) {
			$rage           = $r->int16();
			$temp_rage      = $r->int16();
			$gnosis         = $r->int16();
			$temp_gnosis    = $r->int16();
			$willpower      = $r->int16();
			$temp_willpower = $r->int16();
		} else {
			$rage      = $r->int16();
			$gnosis    = $r->int16();
			$willpower = $r->int16();
			$temp_rage      = $rage;
			$temp_gnosis    = $gnosis;
			$temp_willpower = $willpower;
		}

		if ( $version >= 2.395 ) {
			$honor       = $r->int16();
			$glory       = $r->int16();
			$wisdom      = $r->int16();
			$temp_honor  = $r->single();
			$temp_glory  = $r->single();
			$temp_wisdom = $r->single();
		} else {
			$temp_honor  = $r->single();
			$honor       = (int) $temp_honor;
			$temp_honor  = round( ( $temp_honor - $honor ) * 10, 1 );
			$temp_glory  = $r->single();
			$glory       = (int) $temp_glory;
			$temp_glory  = round( ( $temp_glory - $glory ) * 10, 1 );
			$temp_wisdom = $r->single();
			$wisdom      = (int) $temp_wisdom;
			$temp_wisdom = round( ( $temp_wisdom - $wisdom ) * 10, 1 );
		}

		$physical_max = 0;
		$social_max   = 0;
		$mental_max   = 0;
		if ( $version >= 2.397 ) {
			$physical_max = $r->int16();
			$social_max   = $r->int16();
			$mental_max   = $r->int16();
		}

		$player = $r->string();
		$status = $r->string();
		$id     = $r->string();

		if ( $version >= 2.397 ) {
			$start_date = $r->date();
		} else {
			$r->string();
			$start_date = null;
		}

		$narrator      = $r->string();
		$is_npc        = $r->bool();
		$last_modified = $r->date();

		$experience  = self::parse_experience( $r, $version );
		$trait_lists = self::read_trait_lists( $r, $version, 'fera' );
		$physical    = $trait_lists['Physical'];
		$social      = $trait_lists['Social'];
		$mental      = $trait_lists['Mental'];

		$biography = $version >= 2.397 ? $r->string() : '';
		$notes     = $r->string();

		if ( $version < 2.397 ) {
			[ $physical_max, $social_max, $mental_max ] = self::backfill_pool_max( $physical_max, $physical, $social, $mental );
		}

		return [
			'race'           => 'fera',
			'name'           => $name,
			'nature'         => $nature,
			'demeanor'       => $demeanor,
			'fera'           => $fera,
			'breed'          => $breed,
			'auspice'        => $auspice,
			'rank'           => $rank,
			'pack'           => $pack,
			'totem'          => $totem,
			'position'       => $position,
			'notoriety'      => $notoriety,
			'rage'           => $rage,
			'temp_rage'      => $temp_rage,
			'gnosis'         => $gnosis,
			'temp_gnosis'    => $temp_gnosis,
			'willpower'      => $willpower,
			'temp_willpower' => $temp_willpower,
			'honor'          => $honor,
			'glory'          => $glory,
			'wisdom'         => $wisdom,
			'temp_honor'     => $temp_honor,
			'temp_glory'     => $temp_glory,
			'temp_wisdom'    => $temp_wisdom,
			'physical_max'   => $physical_max,
			'social_max'     => $social_max,
			'mental_max'     => $mental_max,
			'player'         => $player,
			'status'         => $status,
			'id'             => $id,
			'start_date'     => $start_date,
			'narrator'       => $narrator,
			'is_npc'         => $is_npc,
			'last_modified'  => $last_modified,
			'experience'     => $experience,
			'trait_lists'    => $trait_lists,
			'biography'      => $biography,
			'notes'          => $notes,
		];
	}

	/**
	 * Reads a `VariousClass` entry, Grapevine's generic template
	 * character. Has no resource-pool fields; its `Tempers` trait list is
	 * read first, before the Physical/Social/Mental block. `brood` and
	 * the pool-max fields are read only when the format version is 2.397
	 * or later, with no fallback for older files.
	 *
	 * @param GV_Binary_Reader $r
	 * @param float            $version
	 * @return array<string,mixed>
	 */
	private static function parse_character_various( GV_Binary_Reader $r, float $version ): array {
		$name     = $r->string();
		$nature   = $r->string();
		$demeanor = $r->string();
		$class    = $r->string();
		$subclass = $r->string();
		$affinity = $r->string();
		$plane    = $r->string();

		$brood        = '';
		$physical_max = 0;
		$social_max   = 0;
		$mental_max   = 0;
		if ( $version >= 2.397 ) {
			$brood        = $r->string();
			$physical_max = $r->int16();
			$social_max   = $r->int16();
			$mental_max   = $r->int16();
		}

		$player = $r->string();
		$status = $r->string();
		$id     = $r->string();

		if ( $version >= 2.397 ) {
			$start_date = $r->date();
		} else {
			$r->string();
			$start_date = null;
		}

		$narrator      = $r->string();
		$is_npc        = $r->bool();
		$last_modified = $r->date();

		$experience  = self::parse_experience( $r, $version );
		$trait_lists = self::read_trait_lists( $r, $version, 'various' );
		$physical    = $trait_lists['Physical'];
		$social      = $trait_lists['Social'];
		$mental      = $trait_lists['Mental'];

		$other     = $r->string();
		$biography = $version >= 2.397 ? $r->string() : '';
		$notes     = $r->string();

		if ( $version < 2.397 ) {
			[ $physical_max, $social_max, $mental_max ] = self::backfill_pool_max( $physical_max, $physical, $social, $mental );
		}

		return [
			'race'          => 'various',
			'name'          => $name,
			'nature'        => $nature,
			'demeanor'      => $demeanor,
			'class'         => $class,
			'subclass'      => $subclass,
			'affinity'      => $affinity,
			'plane'         => $plane,
			'brood'         => $brood,
			'physical_max'  => $physical_max,
			'social_max'    => $social_max,
			'mental_max'    => $mental_max,
			'player'        => $player,
			'status'        => $status,
			'id'            => $id,
			'start_date'    => $start_date,
			'narrator'      => $narrator,
			'is_npc'        => $is_npc,
			'last_modified' => $last_modified,
			'experience'    => $experience,
			'trait_lists'   => $trait_lists,
			'other'         => $other,
			'biography'     => $biography,
			'notes'         => $notes,
		];
	}

	/**
	 * Reads a `HunterClass` entry. Unlike every other character class,
	 * the resource-pool block, the start date, and the hangouts trait
	 * list are all read unconditionally, with no pre-2.397/pre-2.395
	 * fallback branches; only `biography` gates on format version 2.397
	 * or later.
	 *
	 * @param GV_Binary_Reader $r
	 * @param float            $version
	 * @return array<string,mixed>
	 */
	private static function parse_character_hunter( GV_Binary_Reader $r, float $version ): array {
		$name     = $r->string();
		$creed    = $r->string();
		$nature   = $r->string();
		$demeanor = $r->string();
		$camp     = $r->string();
		$handle   = $r->string();

		$conviction      = $r->int16();
		$temp_conviction = $r->int16();
		$willpower       = $r->int16();
		$temp_willpower  = $r->int16();
		$mercy           = $r->int16();
		$temp_mercy      = $r->int16();
		$vision          = $r->int16();
		$temp_vision     = $r->int16();
		$zeal            = $r->int16();
		$temp_zeal       = $r->int16();
		$physical_max    = $r->int16();
		$social_max      = $r->int16();
		$mental_max      = $r->int16();

		$player     = $r->string();
		$status     = $r->string();
		$id         = $r->string();
		$start_date = $r->date();
		$narrator   = $r->string();
		$is_npc     = $r->bool();
		$last_modified = $r->date();

		$experience  = self::parse_experience( $r, $version );
		$trait_lists = self::read_trait_lists( $r, $version, 'hunter' );

		$biography = $version >= 2.397 ? $r->string() : '';
		$notes     = $r->string();

		return [
			'race'              => 'hunter',
			'name'              => $name,
			'creed'             => $creed,
			'nature'            => $nature,
			'demeanor'          => $demeanor,
			'camp'              => $camp,
			'handle'            => $handle,
			'conviction'        => $conviction,
			'temp_conviction'   => $temp_conviction,
			'willpower'         => $willpower,
			'temp_willpower'    => $temp_willpower,
			'mercy'             => $mercy,
			'temp_mercy'        => $temp_mercy,
			'vision'            => $vision,
			'temp_vision'       => $temp_vision,
			'zeal'              => $zeal,
			'temp_zeal'         => $temp_zeal,
			'physical_max'      => $physical_max,
			'social_max'        => $social_max,
			'mental_max'        => $mental_max,
			'player'            => $player,
			'status'            => $status,
			'id'                => $id,
			'start_date'        => $start_date,
			'narrator'          => $narrator,
			'is_npc'            => $is_npc,
			'last_modified'     => $last_modified,
			'experience'        => $experience,
			'trait_lists'       => $trait_lists,
			'biography'         => $biography,
			'notes'             => $notes,
		];
	}

	/**
	 * Reads a `DemonClass` entry. Like `HunterClass`, the resource-pool
	 * block, start date, and hangouts trait list are all read
	 * unconditionally, with only `biography` gating on format version
	 * 2.397 or later. The opening identity strings are read in the order
	 * `name, house, faction, nature, demeanor`, which does not match the
	 * class's declared property order.
	 *
	 * @param GV_Binary_Reader $r
	 * @param float            $version
	 * @return array<string,mixed>
	 */
	private static function parse_character_demon( GV_Binary_Reader $r, float $version ): array {
		$name     = $r->string();
		$house    = $r->string();
		$faction  = $r->string();
		$nature   = $r->string();
		$demeanor = $r->string();

		$torment          = $r->int16();
		$temp_torment     = $r->int16();
		$faith            = $r->int16();
		$temp_faith       = $r->int16();
		$willpower        = $r->int16();
		$temp_willpower   = $r->int16();
		$conscience       = $r->int16();
		$temp_conscience  = $r->int16();
		$conviction       = $r->int16();
		$temp_conviction  = $r->int16();
		$courage          = $r->int16();
		$temp_courage     = $r->int16();
		$physical_max     = $r->int16();
		$social_max       = $r->int16();
		$mental_max       = $r->int16();

		$player     = $r->string();
		$status     = $r->string();
		$id         = $r->string();
		$start_date = $r->date();
		$narrator   = $r->string();
		$is_npc     = $r->bool();
		$last_modified = $r->date();

		$experience  = self::parse_experience( $r, $version );
		$trait_lists = self::read_trait_lists( $r, $version, 'demon' );

		$biography = $version >= 2.397 ? $r->string() : '';
		$notes     = $r->string();

		return [
			'race'             => 'demon',
			'name'             => $name,
			'house'            => $house,
			'faction'          => $faction,
			'nature'           => $nature,
			'demeanor'         => $demeanor,
			'torment'          => $torment,
			'temp_torment'     => $temp_torment,
			'faith'            => $faith,
			'temp_faith'       => $temp_faith,
			'willpower'        => $willpower,
			'temp_willpower'   => $temp_willpower,
			'conscience'       => $conscience,
			'temp_conscience'  => $temp_conscience,
			'conviction'       => $conviction,
			'temp_conviction'  => $temp_conviction,
			'courage'          => $courage,
			'temp_courage'     => $temp_courage,
			'physical_max'     => $physical_max,
			'social_max'       => $social_max,
			'mental_max'       => $mental_max,
			'player'           => $player,
			'status'           => $status,
			'id'               => $id,
			'start_date'       => $start_date,
			'narrator'         => $narrator,
			'is_npc'           => $is_npc,
			'last_modified'    => $last_modified,
			'experience'       => $experience,
			'trait_lists'      => $trait_lists,
			'biography'        => $biography,
			'notes'            => $notes,
		];
	}
}
