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
	 * `coterie`, `id`, `narrator`, `aura`, `aura_bonus`, `player`,
	 * `is_npc`, and `boons` do not exist in the XML format and are
	 * emitted as the same empty/zero/false defaults the binary reader
	 * uses when those fields are absent, rather than omitted.
	 *
	 * Each `temp_*` field mirrors its permanent value, matching the
	 * binary reader's own fallback for a source with no separate temp
	 * value. `physical_max`/`social_max`/`mental_max` are backfilled
	 * through `GEX_Parser::backfill_pool_max()` for the same reason: the
	 * XML format never carries them either. `is_npc` always defaults to
	 * `false`, since nothing in the XML format signals otherwise.
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
			'temp_blood'        => (int) $el['blood'],
			'willpower'         => (int) $el['willpower'],
			'temp_willpower'    => (int) $el['willpower'],
			'conscience'        => (int) $el['conscience'],
			'temp_conscience'   => (int) $el['conscience'],
			'self_control'      => (int) $el['selfcontrol'],
			'temp_self_control' => (int) $el['selfcontrol'],
			'courage'           => (int) $el['courage'],
			'temp_courage'      => (int) $el['courage'],
			'path'              => (string) $el['path'],
			'path_traits'       => (int) $el['pathtraits'],
			'temp_path_traits'  => (int) $el['pathtraits'],
			'aura'              => '',
			'aura_bonus'        => '',
			'physical_max'      => $physical_max,
			'social_max'        => $social_max,
			'mental_max'        => $mental_max,
			'player'            => '',
			'status'            => (string) $el['status'],
			'id'                => '',
			'start_date'        => self::parse_date( (string) $el['startdate'] ),
			'narrator'          => '',
			'is_npc'            => false,
			'last_modified'     => self::parse_date( (string) $el['lastmodified'] ),
			'experience'        => $experience,
			'trait_lists'       => $trait_lists,
			'boons'             => [],
			'biography'         => '',
			'notes'             => trim( (string) $el->notes ),
		];
	}

	/**
	 * Maps a `<werewolf>` element to the identical shape
	 * `GEX_Parser::parse_character_werewolf()`'s binary reader produces,
	 * following the same field-mapping discipline as the vampire parser
	 * above. A missing `wisdom` attribute and an empty one both resolve
	 * to `0` through `(int) $el['wisdom']`, so no special-casing is
	 * needed for either case.
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
			'temp_rage'      => (int) $el['rage'],
			'gnosis'         => (int) $el['gnosis'],
			'temp_gnosis'    => (int) $el['gnosis'],
			'willpower'      => (int) $el['willpower'],
			'temp_willpower' => (int) $el['willpower'],
			'honor'          => (int) $el['honor'],
			'glory'          => (int) $el['glory'],
			'wisdom'         => (int) $el['wisdom'],
			'temp_honor'     => (float) $el['honor'],
			'temp_glory'     => (float) $el['glory'],
			'temp_wisdom'    => (float) $el['wisdom'],
			'physical_max'   => $physical_max,
			'social_max'     => $social_max,
			'mental_max'     => $mental_max,
			'player'         => '',
			'status'         => (string) $el['status'],
			'id'             => '',
			'start_date'     => self::parse_date( (string) $el['startdate'] ),
			'narrator'       => '',
			'is_npc'         => false,
			'last_modified'  => self::parse_date( (string) $el['lastmodified'] ),
			'experience'     => $experience,
			'trait_lists'    => $trait_lists,
			'biography'      => '',
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
			$parsed = [
				'name'  => (string) $trait['name'],
				// A string even though it holds a number, matching the binary reader's Total field.
				'total' => (string) $trait['val'],
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
