<?php

namespace BeyondElysium\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Parser for Grapevine's GEX XML exchange files (`.gex`).
 *
 * Uses a separate, disjoint element vocabulary from GVM menu XML
 * (`GVM_Parser::parse_xml()`). The two formats share only three attribute
 * conventions (`abc`/`negative`/`atomic` are the string `"yes"`, `display`
 * is an int); GVM's container is `<menu>`/`<item name cost note>`, GEX's
 * is `<traitlist>`/`<trait name val note>`.
 *
 * Returns the identical array shape `GEX_Parser::parse_binary()` returns,
 * so every downstream consumer needs no branch past `sniff_format()`.
 *
 * Supports `<item>`, `<rote>`, `<vampire>`, and `<werewolf>` root
 * elements; any other root element throws, naming it. A small number of
 * fields that do not appear in the XML format at all (`appearance`/`notes`
 * on an item, `grades` on a rote) are emitted as `''` rather than omitted,
 * so downstream code can index every key regardless of which parser
 * produced the record. Only the vampire and werewolf character races are
 * supported; the other `RaceType` classes the binary reader supports are
 * not implemented here.
 *
 * @see BE_PROCESS/workflow-0.8.md Step 9
 * @see BE_PROCESS/DECISIONLOG.md Decision 068
 */
class GEX_Xml_Parser {

	/**
	 * Parses a `.gex` XML exchange file from disk. Reads the file's
	 * contents and delegates to `parse_string()` for the actual parse.
	 *
	 * @param string $path Absolute path.
	 * @return array<string,mixed>
	 * @throws \RuntimeException On an unreadable file, malformed XML, a non-`<grapevine>`
	 *                            root, or an unrecognized top-level element.
	 */
	public static function parse_file( string $path ): array {
		$data = @file_get_contents( $path );
		if ( $data === false || $data === '' ) {
			throw new \RuntimeException( "Cannot read file: {$path}" );
		}
		return self::parse_string( $data );
	}

	/**
	 * Parses a GEX XML exchange document already in memory. Dispatches
	 * each top-level child element to the matching parser by tag name and
	 * collects the results into the same shape `GEX_Parser::parse_binary()`
	 * returns.
	 *
	 * @param string $xml
	 * @return array<string,mixed>
	 * @throws \RuntimeException
	 */
	public static function parse_string( string $xml ): array {
		$previous_setting = libxml_use_internal_errors( true );
		$root              = simplexml_load_string( $xml );
		libxml_use_internal_errors( $previous_setting );

		if ( $root === false || $root->getName() !== 'grapevine' ) {
			throw new \RuntimeException( 'Not a Grapevine XML exchange file.' );
		}

		$version    = (float) $root['version'];
		$items      = [];
		$rotes      = [];
		$characters = [];

		foreach ( $root->children() as $child ) {
			$name = $child->getName();
			switch ( $name ) {
				case 'item':
					$items[] = self::parse_item( $child );
					break;
				case 'rote':
					$rotes[] = self::parse_rote( $child );
					break;
				case 'vampire':
					$characters[] = self::parse_character_vampire( $child );
					break;
				case 'werewolf':
					$characters[] = self::parse_character_werewolf( $child );
					break;
				default:
					throw new \RuntimeException(
						"Unrecognized element <{$name}> in this GEX XML file - refusing " .
						'rather than silently skipping it (workflow-0.8.md Step 9c).'
					);
			}
		}

		// Matches GEX_Parser::parse_binary()'s full return shape field-for-field.
		return [
			'version'           => $version,
			'calendar'          => null,
			'apr_engine'        => null,
			'experience_awards' => [],
			'templates'         => [],
			'players'           => [],
			'characters'        => $characters,
			'queries'           => [],
			'items'             => $items,
			'rotes'             => $rotes,
			'locations'         => [],
			'actions'           => [],
			'plots'             => [],
			'rumors'            => [],
		];
	}

	/**
	 * Maps a `<vampire>` element to the identical shape
	 * `GEX_Parser::parse_character_vampire()`'s binary reader produces.
	 * `coterie`, `narrator`, and `player` do not exist in the XML format
	 * and are emitted as the same empty defaults the binary reader uses
	 * when those fields are absent, rather than omitted.
	 *
	 * `id`, `npc`, `biography`, `aura`/`aurabonus`, `<boon>` children, and
	 * every `temp_*` field are real GV XML attributes/elements this parser
	 * previously dropped (GX-0 defects 2-7) - each is now read from the
	 * source element, falling back to the same default the binary reader
	 * would use only when the attribute is genuinely absent.
	 * `physical_max`/`social_max`/`mental_max` are backfilled through
	 * `GEX_Parser::backfill_pool_max()` since the XML format never
	 * carries them either.
	 *
	 * @param \SimpleXMLElement $el
	 * @return array<string,mixed>
	 */
	private static function parse_character_vampire( \SimpleXMLElement $el ): array {
		$trait_lists = self::trait_lists_by_name( $el );
		$experience  = self::parse_experience( $el->experience );

		$physical = $trait_lists['Physical'] ?? self::empty_trait_list( 'Physical' );
		$social   = $trait_lists['Social'] ?? self::empty_trait_list( 'Social' );
		$mental   = $trait_lists['Mental'] ?? self::empty_trait_list( 'Mental' );
		[ $physical_max, $social_max, $mental_max ] =
			GEX_Parser::backfill_pool_max( 0, $physical, $social, $mental );

		return [
			'race'              => 'vampire',
			'name'              => (string) $el['name'],
			'nature'            => (string) $el['nature'],
			'demeanor'          => (string) $el['demeanor'],
			'clan'              => (string) $el['clan'],
			'sect'              => (string) $el['sect'],
			'coterie'           => '',
			'sire'              => (string) $el['sire'],
			'generation'        => (int) $el['generation'],
			'title'             => (string) $el['title'],
			'blood'             => (int) $el['blood'],
			'temp_blood'        => (int) self::xml_attr_or( $el, 'tempblood', $el['blood'] ),
			'willpower'         => (int) $el['willpower'],
			'temp_willpower'    => (int) self::xml_attr_or( $el, 'tempwillpower', $el['willpower'] ),
			'conscience'        => (int) $el['conscience'],
			'temp_conscience'   => (int) self::xml_attr_or( $el, 'tempconscience', $el['conscience'] ),
			'self_control'      => (int) $el['selfcontrol'],
			'temp_self_control' => (int) self::xml_attr_or( $el, 'tempselfcontrol', $el['selfcontrol'] ),
			'courage'           => (int) $el['courage'],
			'temp_courage'      => (int) self::xml_attr_or( $el, 'tempcourage', $el['courage'] ),
			'path'              => (string) $el['path'],
			'path_traits'       => (int) $el['pathtraits'],
			'temp_path_traits'  => (int) self::xml_attr_or( $el, 'temppathtraits', $el['pathtraits'] ),
			// Real Grapevine writes 'aura' twice (once for Aura, once for AuraBonus with the
			// 'aurabonus' attribute name never actually reaching the file - VampireClass.cls:375-376),
			// which is malformed XML no parser here can read. Support the corrected shape: 'aura'
			// for Aura, a genuine 'aurabonus' attribute for AuraBonus, defaulting to '+0' when absent.
			'aura'              => (string) $el['aura'],
			'aura_bonus'        => self::xml_attr_or( $el, 'aurabonus', '+0' ),
			'physical_max'      => $physical_max,
			'social_max'        => $social_max,
			'mental_max'        => $mental_max,
			'player'            => '',
			'status'            => (string) $el['status'],
			'id'                => (string) $el['id'],
			'start_date'        => self::parse_date( (string) $el['startdate'] ),
			'narrator'          => '',
			'is_npc'            => (string) $el['npc'] === 'yes',
			'last_modified'     => self::parse_date( (string) $el['lastmodified'] ),
			'experience'        => $experience,
			'trait_lists'       => $trait_lists,
			'boons'             => self::parse_boons( $el ),
			'biography'         => trim( (string) $el->biography ),
			'notes'             => trim( (string) $el->notes ),
		];
	}

	/**
	 * Maps a `<werewolf>` element to the identical shape
	 * `GEX_Parser::parse_character_werewolf()`'s binary reader produces,
	 * following the same field-mapping discipline as the vampire parser
	 * above (GX-0 defects 2, 3, 5, 7 - no boon list or aura fields exist
	 * on this race). A missing `wisdom` attribute and an empty one both
	 * resolve to `0` through `(int) $el['wisdom']`, so no special-casing
	 * is needed for either case.
	 *
	 * @param \SimpleXMLElement $el
	 * @return array<string,mixed>
	 */
	private static function parse_character_werewolf( \SimpleXMLElement $el ): array {
		$trait_lists = self::trait_lists_by_name( $el );
		$experience  = self::parse_experience( $el->experience );

		$physical = $trait_lists['Physical'] ?? self::empty_trait_list( 'Physical' );
		$social   = $trait_lists['Social'] ?? self::empty_trait_list( 'Social' );
		$mental   = $trait_lists['Mental'] ?? self::empty_trait_list( 'Mental' );
		[ $physical_max, $social_max, $mental_max ] =
			GEX_Parser::backfill_pool_max( 0, $physical, $social, $mental );

		return [
			'race'           => 'werewolf',
			'name'           => (string) $el['name'],
			'nature'         => (string) $el['nature'],
			'demeanor'       => (string) $el['demeanor'],
			'tribe'          => (string) $el['tribe'],
			'breed'          => (string) $el['breed'],
			'auspice'        => (string) $el['auspice'],
			'rank'           => (string) $el['rank'],
			'pack'           => (string) $el['pack'],
			'totem'          => (string) $el['totem'],
			'camp'           => (string) $el['camp'],
			'position'       => (string) $el['position'],
			'notoriety'      => (int) $el['notoriety'],
			'rage'           => (int) $el['rage'],
			'temp_rage'      => (int) self::xml_attr_or( $el, 'temprage', $el['rage'] ),
			'gnosis'         => (int) $el['gnosis'],
			'temp_gnosis'    => (int) self::xml_attr_or( $el, 'tempgnosis', $el['gnosis'] ),
			'willpower'      => (int) $el['willpower'],
			'temp_willpower' => (int) self::xml_attr_or( $el, 'tempwillpower', $el['willpower'] ),
			'honor'          => (int) $el['honor'],
			'glory'          => (int) $el['glory'],
			'wisdom'         => (int) $el['wisdom'],
			'temp_honor'     => (float) self::xml_attr_or( $el, 'temphonor', $el['honor'] ),
			'temp_glory'     => (float) self::xml_attr_or( $el, 'tempglory', $el['glory'] ),
			'temp_wisdom'    => (float) self::xml_attr_or( $el, 'tempwisdom', $el['wisdom'] ),
			'physical_max'   => $physical_max,
			'social_max'     => $social_max,
			'mental_max'     => $mental_max,
			'player'         => '',
			'status'         => (string) $el['status'],
			'id'             => (string) $el['id'],
			'start_date'     => self::parse_date( (string) $el['startdate'] ),
			'narrator'       => '',
			'is_npc'         => (string) $el['npc'] === 'yes',
			'last_modified'  => self::parse_date( (string) $el['lastmodified'] ),
			'experience'     => $experience,
			'trait_lists'    => $trait_lists,
			'biography'      => trim( (string) $el->biography ),
			'notes'          => trim( (string) $el->notes ),
		];
	}

	/**
	 * Maps an `<experience>` element and its `<entry>` children to the
	 * identical shape `GEX_Parser`'s private experience parsers produce.
	 * Per-entry `earned`/`unspent` running totals do not exist in the XML
	 * format, only the outer current totals do, so each history entry
	 * emits `0.0` for both rather than omitting the keys.
	 *
	 * @param \SimpleXMLElement $experience_el
	 * @return array{unspent:float,earned:float,history:array<int,array<string,mixed>>}
	 */
	private static function parse_experience( \SimpleXMLElement $experience_el ): array {
		$history = [];
		foreach ( $experience_el->entry as $entry ) {
			$history[] = [
				'when'        => self::parse_date_only( (string) $entry['date'] ),
				'change'      => (float) $entry['change'],
				'change_type' => (int) $entry['type'],
				'reason'      => (string) $entry['reason'],
				'earned'      => 0.0,
				'unspent'     => 0.0,
			];
		}

		return [
			'unspent' => (float) $experience_el['unspent'],
			'earned'  => (float) $experience_el['earned'],
			'history' => $history,
		];
	}

	/**
	 * Maps every `<boon>` child of a `<vampire>` element to the identical
	 * shape `GEX_Parser::parse_boon()`'s binary reader produces. Only
	 * vampire writes a boon list (`BoonClass.OutputToFile`, called from
	 * `VampireClass.cls:418-424`); no other race's `OutputToFile` calls it.
	 *
	 * `BoonDate` is a plain VB6 Date field (`BoonClass.cls`), written the
	 * same generic way as `StartDate`/`LastModified` rather than the
	 * date-only `<entry date>` convention `parse_date_only()` handles - so
	 * it is parsed with `parse_date()`. Inferred, not tested against a real
	 * sample: no boon-carrying `.gex` exists in this repo (see the
	 * export/transfer design doc's risk ledger).
	 *
	 * @param \SimpleXMLElement $el
	 * @return array<int,array<string,mixed>>
	 */
	private static function parse_boons( \SimpleXMLElement $el ): array {
		$boons = [];
		foreach ( $el->boon as $boon ) {
			$boons[] = [
				'boon_type'   => (string) $boon['type'],
				'char_name'   => (string) $boon['partner'],
				'is_owed'     => (string) $boon['owed'] === 'yes',
				'boon_date'   => self::parse_date( (string) $boon['date'] ),
				'description' => trim( (string) $boon->description ),
			];
		}
		return $boons;
	}

	/**
	 * Maps an `<item>` element to the identical shape
	 * `GEX_Parser::parse_item()`'s binary reader produces. Trait lists
	 * are matched by their own `name` attribute, since XML gives named
	 * elements rather than the binary format's fixed read order, against
	 * the four the binary reader always reads: `Tempers`, `Abilities`,
	 * `Negatives`, and `Availability`.
	 *
	 * @param \SimpleXMLElement $el
	 * @return array<string,mixed>
	 */
	private static function parse_item( \SimpleXMLElement $el ): array {
		$lists = self::trait_lists_by_name( $el );

		return [
			'name'           => (string) $el['name'],
			'item_type'      => (string) $el['type'],
			'item_subtype'   => (string) $el['subtype'],
			'level'          => (int) $el['level'],
			'bonus'          => (int) $el['bonus'],
			'damage_type'    => (string) $el['damage'],
			'damage_amount'  => (int) $el['amount'],
			'concealability' => (string) $el['conceal'],
			'temper_list'    => $lists['Tempers'] ?? self::empty_trait_list( 'Tempers' ),
			'ability_list'   => $lists['Abilities'] ?? self::empty_trait_list( 'Abilities' ),
			'negative_list'  => $lists['Negatives'] ?? self::empty_trait_list( 'Negatives' ),
			'availability'   => $lists['Availability'] ?? self::empty_trait_list( 'Availability' ),
			'powers'         => trim( (string) $el->powers ),
			// Not present in the XML format; emitted as '' rather than omitted.
			'appearance'     => '',
			'notes'          => '',
			'last_modified'  => self::parse_date( (string) $el['lastmodified'] ),
		];
	}

	/**
	 * Maps a `<rote>` element to the identical shape
	 * `GEX_Parser::parse_rote()`'s binary reader produces. `grades` does
	 * not exist in the XML format at all and is emitted as `''` rather
	 * than omitted.
	 *
	 * @param \SimpleXMLElement $el
	 * @return array<string,mixed>
	 */
	private static function parse_rote( \SimpleXMLElement $el ): array {
		$lists = self::trait_lists_by_name( $el );

		return [
			'name'          => (string) $el['name'],
			'level'         => (int) $el['level'],
			'duration'      => (string) $el['duration'],
			'sphere_list'   => $lists['Spheres'] ?? self::empty_trait_list( 'Spheres' ),
			'description'   => trim( (string) $el->description ),
			'grades'        => '',
			'last_modified' => self::parse_date( (string) $el['lastmodified'] ),
		];
	}

	/**
	 * Collects every `<traitlist>` child of an `<item>`, `<rote>`, or
	 * character element, keyed by its own `name` attribute. Parses each
	 * child through `parse_trait_list()`.
	 *
	 * @param \SimpleXMLElement $el
	 * @return array<string,array<string,mixed>>
	 */
	private static function trait_lists_by_name( \SimpleXMLElement $el ): array {
		$out = [];
		foreach ( $el->traitlist as $traitlist ) {
			$parsed                  = self::parse_trait_list( $traitlist );
			$out[ $parsed['name'] ]  = $parsed;
		}
		return $out;
	}

	/**
	 * Maps a `<traitlist>` element to the identical shape
	 * `GEX_Parser::parse_trait_list()`'s binary reader produces, including
	 * the one attribute convention it shares with GVM menu XML:
	 * `abc`/`negative`/`atomic` are the string `"yes"`, and `display` is
	 * an int. Detects section-divider entries and tags subsequent traits
	 * with the divider's label.
	 *
	 * @param \SimpleXMLElement $traitlist
	 * @return array{name:string,alphabetized:bool,atomic:bool,negative:bool,display:int,traits:array<int,array{name:string,total:string,note:string}>}
	 */
	private static function parse_trait_list( \SimpleXMLElement $traitlist ): array {
		$traits  = [];
		$section = null;
		foreach ( $traitlist->trait as $trait ) {
			// A string even though it holds a number, matching the binary reader's Total field.
			// An absent val defaults to '1' (LinkedTraitList.cls:833's WriteAttribute omits it
			// when Total is 1 - GX-0 defect 1). A real production file (a Dialect B web-tool
			// export, gex-export-transfer-design.md §2e) showed this reaches the file as a
			// present-but-empty val="" instead of a fully omitted attribute for the same
			// no-real-count case - confirmed on kony-sabbat.net/Boston, where every affected
			// trait sat in a note-only, atomic list (Rituals, Merits, Derangements) that has
			// no real "0" state: a ritual or merit is either held or it isn't. Both shapes are
			// treated identically here, since neither carries a real recorded value.
			$total = (string) ( $trait['val'] ?? '1' );
			if ( $total === '' ) {
				$total = '1';
			}
			$parsed = [
				'name'  => (string) $trait['name'],
				'total' => $total,
				'note'  => (string) $trait['note'],
			];
			// Reuses GEX_Parser::is_section_divider()/divider_label() rather than duplicating the logic.
			if ( GEX_Parser::is_section_divider( $parsed ) ) {
				$section = GEX_Parser::divider_label( $parsed );
				continue;
			}
			if ( $section !== null ) {
				$parsed['section'] = $section;
			}
			$traits[] = $parsed;
		}

		return [
			'name'         => (string) $traitlist['name'],
			'alphabetized' => (string) $traitlist['abc'] === 'yes',
			'atomic'       => (string) $traitlist['atomic'] === 'yes',
			'negative'     => (string) $traitlist['negative'] === 'yes',
			'display'      => (int) $traitlist['display'],
			'traits'       => $traits,
		];
	}

	/**
	 * Builds an empty trait list placeholder for a name with no matching
	 * `<traitlist>` element in the source XML. Returns the same shape a
	 * parsed trait list would have, with all values at their defaults.
	 *
	 * @param string $name
	 * @return array{name:string,alphabetized:bool,atomic:bool,negative:bool,display:int,traits:array<int,mixed>}
	 */
	private static function empty_trait_list( string $name ): array {
		return [
			'name'         => $name,
			'alphabetized' => false,
			'atomic'       => false,
			'negative'     => false,
			'display'      => 0,
			'traits'       => [],
		];
	}

	/**
	 * Reads an attribute if the source element actually carries it,
	 * falling back to a caller-supplied default otherwise. Used for every
	 * `temp*` field and `aurabonus`: real Grapevine omits these when they
	 * equal their non-temp/default counterpart (`XMLWriterClass.cls`'s
	 * `WriteAttribute` omit rule), so an absent attribute means "same as
	 * the fallback", not "zero" (GX-0 defect 7).
	 *
	 * @param \SimpleXMLElement $el
	 * @param string            $attr
	 * @param mixed             $fallback
	 * @return string
	 */
	private static function xml_attr_or( \SimpleXMLElement $el, string $attr, $fallback ): string {
		return isset( $el[ $attr ] ) ? (string) $el[ $attr ] : (string) $fallback;
	}

	/**
	 * Parses a `lastmodified`-style timestamp attribute, for example
	 * `"11/20/2002 12:13:04 AM"`, into the same `'Y-m-d H:i:s'`-or-null
	 * shape `GV_Binary_Reader::date()` produces. Returns null for an
	 * empty or unparseable value.
	 *
	 * @param string $raw
	 * @return string|null
	 */
	private static function parse_date( string $raw ): ?string {
		if ( $raw === '' ) {
			return null;
		}
		$parsed = \DateTime::createFromFormat( 'n/j/Y g:i:s A', $raw );
		return $parsed !== false ? $parsed->format( 'Y-m-d H:i:s' ) : null;
	}

	/**
	 * Parses a date-only attribute, for example `<entry date="08/20/2021">`,
	 * which carries no time component, unlike `lastmodified`/`startdate`
	 * handled by `parse_date()` above.
	 *
	 * The leading `!` in the format string resets every unspecified field
	 * to the Unix epoch (00:00:00) rather than the current system time,
	 * so the parsed value is always midnight rather than whatever time
	 * the code happens to run.
	 *
	 * @param string $raw
	 * @return string|null
	 */
	private static function parse_date_only( string $raw ): ?string {
		if ( $raw === '' ) {
			return null;
		}
		$parsed = \DateTime::createFromFormat( '!n/j/Y', $raw );
		return $parsed !== false ? $parsed->format( 'Y-m-d H:i:s' ) : null;
	}
}
