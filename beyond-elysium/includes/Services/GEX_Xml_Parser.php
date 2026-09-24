<?php

namespace BeyondElysium\Services;

use BeyondElysium\Utils\Uuid;

defined( 'ABSPATH' ) || exit;

/**
 * Parser for Grapevine's GEX XML exchange files (`.gex`).
 */
class GEX_Xml_Parser {

	/**
	 * Parses a `.gex` XML exchange file from disk.
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
	 * Parses a GEX XML exchange document already in memory.
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
					$characters[] = self::with_beyond_elysium_attributes( self::with_transfer_uuid( self::parse_character_vampire( $child ), $child ), $child );
					break;
				case 'werewolf':
					$characters[] = self::with_beyond_elysium_attributes( self::with_transfer_uuid( self::parse_character_werewolf( $child ), $child ), $child );
					break;
				case 'mortal':
				case 'changeling':
				case 'wraith':
				case 'mage':
				case 'fera':
				case 'various':
				case 'mummy':
				case 'kueijin':
				case 'hunter':
				case 'demon':
					$characters[] = self::with_beyond_elysium_attributes( self::with_transfer_uuid( self::parse_character_generic( $child, $name ), $child ), $child );
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
	 * Maps a `<vampire>` element to the identical shape `GEX_Parser::parse_character_vampire()`'s binary reader produces.
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
			'coterie'           => (string) $el['coterie'],
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
			'aura'              => (string) $el['aura'],
			'aura_bonus'        => self::xml_attr_or( $el, 'aurabonus', '+0' ),
			'physical_max'      => $physical_max,
			'social_max'        => $social_max,
			'mental_max'        => $mental_max,
			'player'            => (string) $el['player'],
			'status'            => (string) $el['status'],
			'id'                => (string) $el['id'],
			'start_date'        => self::parse_date( (string) $el['startdate'] ),
			'narrator'          => (string) $el['narrator'],
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
	 * Maps a `<werewolf>` element to the identical shape `GEX_Parser::parse_character_werewolf()`'s binary reader
	 * produces.
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
			'player'         => (string) $el['player'],
			'status'         => (string) $el['status'],
			'id'             => (string) $el['id'],
			'start_date'     => self::parse_date( (string) $el['startdate'] ),
			'narrator'       => (string) $el['narrator'],
			'is_npc'         => (string) $el['npc'] === 'yes',
			'last_modified'  => self::parse_date( (string) $el['lastmodified'] ),
			'experience'     => $experience,
			'trait_lists'    => $trait_lists,
			'biography'      => trim( (string) $el->biography ),
			'notes'          => trim( (string) $el->notes ),
		];
	}

	/**
	 * Maps any of the ten character races the shape table describes without a dedicated XML reader (`mortal`,
	 * `changeling`, `wraith`, `mage`, `fera`, `various`, `mummy`, `kueijin`, `hunter`, `demon`) to the shape their
	 * binary counterpart in `GEX_Parser` produces.
	 *
	 * @param \SimpleXMLElement $el
	 * @param string            $race
	 * @return array<string,mixed>
	 */
	private static function parse_character_generic( \SimpleXMLElement $el, string $race ): array {
		$shape    = GEX_Parser::shape( $race );
		$resolved = [ 'race' => $race ];

		foreach ( $shape['scalars'] as $scalar ) {
			if ( isset( $scalar['xml_omit_if'] ) && array_key_exists( $scalar['xml_omit_if'], $resolved ) ) {
				$fallback = (string) $resolved[ $scalar['xml_omit_if'] ];
			} elseif ( array_key_exists( 'xml_omit', $scalar ) ) {
				$fallback = is_bool( $scalar['xml_omit'] ) ? ( $scalar['xml_omit'] ? 'yes' : 'no' ) : (string) $scalar['xml_omit'];
			} else {
				$fallback = '';
			}

			$raw = self::xml_attr_or( $el, $scalar['xml'], $fallback );

			if ( isset( $scalar['xml_enum'] ) ) {
				$value                        = array_search( $raw, $scalar['xml_enum'], true );
				$resolved[ $scalar['key'] ]   = $value === false ? 0 : $value;
				continue;
			}

			$resolved[ $scalar['key'] ] = match ( $scalar['type'] ) {
				'int16', 'int32' => (int) $raw,
				'single'         => (float) $raw,
				'bool'           => $raw === 'yes',
				'date'           => self::parse_date( $raw ),
				default          => $raw,
			};
		}

		$trait_lists = self::trait_lists_by_name( $el );
		$physical    = $trait_lists['Physical'] ?? self::empty_trait_list( 'Physical' );
		$social      = $trait_lists['Social'] ?? self::empty_trait_list( 'Social' );
		$mental      = $trait_lists['Mental'] ?? self::empty_trait_list( 'Mental' );
		[ $resolved['physical_max'], $resolved['social_max'], $resolved['mental_max'] ] =
			GEX_Parser::backfill_pool_max( 0, $physical, $social, $mental );

		$resolved['experience']  = self::parse_experience( $el->experience );
		$resolved['trait_lists'] = $trait_lists;

		if ( $shape['boons'] ) {
			$resolved['boons'] = self::parse_boons( $el );
		}

		foreach ( $shape['tail'] as $row ) {
			$resolved[ $row['key'] ] = trim( (string) $el->{ $row['xml_cdata'] } );
		}

		return $resolved;
	}

	/**
	 * Maps an `<experience>` element and its `<entry>` children to the identical shape `GEX_Parser`'s private experience
	 * parsers produce.
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
	 * Maps every `<boon>` child of a `<vampire>` element to the identical shape `GEX_Parser::parse_boon()`'s binary
	 * reader produces.
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
	 * Maps an `<item>` element to the identical shape `GEX_Parser::parse_item()`'s binary reader produces.
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
			// Not present in the XML format.
			'appearance'     => '',
			'notes'          => '',
			'last_modified'  => self::parse_date( (string) $el['lastmodified'] ),
		];
	}

	/**
	 * Maps a `<rote>` element to the identical shape `GEX_Parser::parse_rote()`'s binary reader produces.
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
	 * Collects every `<traitlist>` child of an `<item>`, `<rote>`, or character element, keyed by its own `name`
	 * attribute.
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
	 * Maps a `<traitlist>` element to the identical shape `GEX_Parser::parse_trait_list()`'s binary reader produces,
	 * including the one attribute convention it shares with GVM menu XML: `abc`/`negative`/`atomic` are the string
	 * `"yes"`, and `display` is an int.
	 *
	 * @param \SimpleXMLElement $traitlist
	 * @return array{name:string,alphabetized:bool,atomic:bool,negative:bool,display:int,traits:array<int,array{name:string,total:string,note:string}>}
	 */
	private static function parse_trait_list( \SimpleXMLElement $traitlist ): array {
		$traits  = [];
		$section = null;
		foreach ( $traitlist->trait as $trait ) {
			// A string even though it holds a number, matching the binary reader's Total field.
			$total = (string) ( $trait['val'] ?? '1' );
			if ( $total === '' ) {
				$total = '1';
			}
			$parsed = [
				'name'  => (string) $trait['name'],
				'total' => $total,
				'note'  => (string) $trait['note'],
			];
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
	 * Builds an empty trait list placeholder for a name with no matching `<traitlist>` element in the source XML.
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
	 * Reads an attribute if the source element actually carries it, falling back to a caller-supplied default.
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
	 * Reads the attributes `Character_Exporter` adds for a Beyond Elysium import.
	 *
	 * @param array<string,mixed> $character
	 * @param \SimpleXMLElement   $el
	 * @return array<string,mixed>
	 */
	private static function with_beyond_elysium_attributes( array $character, \SimpleXMLElement $el ): array {
		$stack = (string) $el['bestack'];
		if ( $stack !== '' && $stack !== $character['race'] && GEX_Parser::exchange_race( $stack ) === $character['race'] ) {
			$character['race'] = $stack;
		}
		if ( ! array_key_exists( 'nature', $character ) && isset( $el['benature'] ) ) {
			$character['nature']   = (string) $el['benature'];
			$character['demeanor'] = (string) $el['bedemeanor'];
		}
		return $character;
	}

	/**
	 * Merges the character's own permanent uuid into its parsed record when the document carries one.
	 *
	 * @param array<string,mixed> $character
	 * @param \SimpleXMLElement   $el
	 * @return array<string,mixed>
	 */
	private static function with_transfer_uuid( array $character, \SimpleXMLElement $el ): array {
		$uuid = (string) $el->verification['character_uuid'];
		if ( $uuid !== '' && Uuid::is_valid( $uuid ) ) {
			$character['uuid'] = strtolower( $uuid );
		}
		return $character;
	}

	/**
	 * Parses a `lastmodified`-style timestamp attribute, for example `"11/20/2002 12:13:04 AM"`, into the same `'Y-m-d
	 * H:i:s'`-or-null shape `GV_Binary_Reader::date()` produces.
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
	 * Parses a date-only attribute, for example `<entry date="08/20/2021">`.
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
