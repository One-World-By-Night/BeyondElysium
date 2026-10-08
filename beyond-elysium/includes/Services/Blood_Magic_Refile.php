<?php

namespace BeyondElysium\Services;

use BeyondElysium\Database\Manager;
use BeyondElysium\Database\Transaction;
use BeyondElysium\Models\Change;
use BeyondElysium\Models\Character;
use BeyondElysium\Models\Schema_Block;
use BeyondElysium\Models\Snapshot;

defined( 'ABSPATH' ) || exit;

/**
 * Moves the Blood Magic picks a character holds in its Disciplines list into the Blood Magic list: a pick named
 * "{Tradition}: {Path}", or a custom pick whose name is a tradition and whose power name is the path. Each lands as
 * `{name, level, tradition}` with the tradition spelled the way the catalog spells it. A pick Blood Magic already holds
 * (the same path, tradition and level) is dropped instead of copied, and a pick that disagrees with a held row on level
 * moves and is flagged, so a move never creates a second copy and never loses a row.
 */
class Blood_Magic_Refile {

	const SOURCE_BLOCK = 'vampire-disciplines';
	const TARGET_BLOCK = 'vampire-blood-magic';
	const GROUP_SEPARATOR = ': ';

	/**
	 * The four Assamite caste names, which contain a colon-free comma form and stay in Disciplines.
	 */
	const EXCLUDED_NAMES = [
		'Quietus, Cruscitus / Warrior', 'Quietus, Hematus / Vizier',
		'Quietus, Minhit Dume / Vizier', 'Quietus, Sorcerer',
	];

	/**
	 * The traditions used when the Blood Magic block cannot be read.
	 */
	const FALLBACK_TRADITIONS = [
		'Akhu', 'Bacaban', 'Dark Thaumaturgy', 'Dur An Ki', 'Judicium', 'Koldunism', 'Mortis',
		'Nahuallotl', 'Necromancy', 'Sadhana', 'Sielanic', 'Thaumaturgy (Anarch)', 'Thaumaturgy (Camarilla)', 'Wanga',
	];

	/**
	 * A tradition as the catalog spells it: the listed name that matches ignoring case and punctuation, or the name an
	 * alias points to; the text as typed when neither applies.
	 *
	 * @param string               $raw
	 * @param string[]             $known   The catalog's tradition names.
	 * @param array<string,string> $aliases Misspelling => catalog name.
	 */
	public static function canonical_tradition( string $raw, array $known, array $aliases = [] ): string {
		$raw        = trim( $raw );
		$normalized = Fuzzy_Matcher::normalize( $raw );
		foreach ( $known as $tradition ) {
			if ( Fuzzy_Matcher::normalize( (string) $tradition ) === $normalized ) {
				return (string) $tradition;
			}
		}
		foreach ( $aliases as $spelling => $tradition ) {
			if ( Fuzzy_Matcher::normalize( (string) $spelling ) === $normalized ) {
				return (string) $tradition;
			}
		}
		return $raw;
	}

	/**
	 * The listed tradition a custom pick's name stands for: an exact match ignoring case and punctuation, else the one
	 * listed name close enough to be the only candidate.
	 *
	 * @param string[] $known
	 */
	private static function match_known_tradition( string $raw, array $known ): ?string {
		foreach ( $known as $tradition ) {
			if ( Fuzzy_Matcher::normalize( $raw ) === Fuzzy_Matcher::normalize( (string) $tradition ) ) {
				return (string) $tradition;
			}
		}
		$suggestions = Fuzzy_Matcher::suggest( $raw, array_map( 'strval', $known ) );
		return count( $suggestions ) === 1 ? $suggestions[0] : null;
	}

	/**
	 * The Blood Magic row a held Disciplines entry becomes, or null when the entry is not a Blood Magic pick.
	 *
	 * @param mixed                $entry
	 * @param string[]             $known
	 * @param array<string,string> $aliases
	 * @return array<string,mixed>|null
	 */
	private static function movable( $entry, array $known, array $aliases ): ?array {
		if ( ! is_array( $entry ) || ! isset( $entry['name'] ) ) {
			return null;
		}

		$name  = (string) $entry['name'];
		$colon = strpos( $name, self::GROUP_SEPARATOR );
		if ( $colon !== false && ! in_array( $name, self::EXCLUDED_NAMES, true ) ) {
			$entry['tradition'] = self::canonical_tradition( substr( $name, 0, $colon ), $known, $aliases );
			$entry['name']      = trim( substr( $name, $colon + 2 ) );
			return $entry;
		}

		$tradition = ( ! empty( $entry['custom'] ) && isset( $entry['power_name'] ) )
			? self::match_known_tradition( $name, $known )
			: null;
		if ( $tradition === null ) {
			return null;
		}
		$entry['tradition'] = $tradition;
		$entry['name']      = $entry['power_name'];
		unset( $entry['power_name'] );
		return $entry;
	}

	/**
	 * The level a row holds as a whole number, or null when it holds none.
	 *
	 * @param array<string,mixed> $row
	 */
	private static function level_of( array $row ): ?int {
		return isset( $row['level'] ) && is_numeric( $row['level'] ) ? (int) $row['level'] : null;
	}

	/**
	 * One path under one tradition, as a comparison key.
	 *
	 * @param array<string,mixed> $row
	 */
	private static function path_key( array $row ): string {
		return Fuzzy_Matcher::normalize( (string) ( $row['name'] ?? '' ) ) . "\0" . Fuzzy_Matcher::normalize( (string) ( $row['tradition'] ?? '' ) );
	}

	/**
	 * One character's sheet with its Blood Magic picks moved. Each record names what became of one pick: `moved`,
	 * `duplicate` (already held at that level, so dropped) or `conflict` (moved, though one row already held it at a
	 * different level).
	 *
	 * @param array<string,mixed>  $sheet_data
	 * @param string[]             $known   The catalog's tradition names.
	 * @param array<string,string> $aliases Misspelling => catalog name.
	 * @return array{sheet_data:array<string,mixed>,records:array<int,array<string,mixed>>}
	 */
	public static function refile_sheet( array $sheet_data, array $known, array $aliases = [] ): array {
		$held = $sheet_data[ self::SOURCE_BLOCK ] ?? null;
		if ( ! is_array( $held ) || ! array_is_list( $held ) ) {
			return [ 'sheet_data' => $sheet_data, 'records' => [] ];
		}

		$rows   = is_array( $sheet_data[ self::TARGET_BLOCK ] ?? null ) ? array_values( $sheet_data[ self::TARGET_BLOCK ] ) : [];
		$levels = [];
		foreach ( $rows as $row ) {
			if ( is_array( $row ) && isset( $row['name'] ) ) {
				$levels[ self::path_key( $row ) ][] = self::level_of( $row );
			}
		}

		$stay    = [];
		$records = [];
		$moved   = false;
		foreach ( $held as $entry ) {
			$row = self::movable( $entry, $known, $aliases );
			if ( $row === null ) {
				$stay[] = $entry;
				continue;
			}

			$moved = true;
			$key   = self::path_key( $row );
			$level = self::level_of( $row );
			$from  = (string) ( $entry['name'] ?? '' );
			$held_levels = $levels[ $key ] ?? [];

			if ( in_array( $level, $held_levels, true ) ) {
				$records[] = [ 'outcome' => 'duplicate', 'from_block' => self::SOURCE_BLOCK, 'to_block' => self::TARGET_BLOCK, 'from' => $from, 'to' => (string) $row['name'], 'tradition' => (string) $row['tradition'], 'level' => $level ];
				continue;
			}

			$outcome = count( $held_levels ) === 1 ? 'conflict' : 'moved';
			$record  = [ 'outcome' => $outcome, 'from_block' => self::SOURCE_BLOCK, 'to_block' => self::TARGET_BLOCK, 'from' => $from, 'to' => (string) $row['name'], 'tradition' => (string) $row['tradition'], 'level' => $level ];
			if ( $outcome === 'conflict' ) {
				$record['held_level'] = $held_levels[0];
			}
			$records[] = $record;

			$rows[]            = $row;
			$levels[ $key ][] = $level;
		}

		if ( ! $moved ) {
			return [ 'sheet_data' => $sheet_data, 'records' => [] ];
		}

		$sheet_data[ self::SOURCE_BLOCK ] = $stay;
		$sheet_data[ self::TARGET_BLOCK ] = $rows;
		return [ 'sheet_data' => $sheet_data, 'records' => $records ];
	}

	/**
	 * Moves every character's Blood Magic picks, one character at a time, each with a `catalog_rekey` history record and
	 * a snapshot of the sheet before it.
	 *
	 * @param int $actor_id User recorded as submitter of each history record.
	 * @return array{characters:int,moved:int,duplicates:int,conflicts:int,failed:array<int,string>}
	 */
	public static function run( int $actor_id = 0 ): array {
		$totals  = [ 'characters' => 0, 'moved' => 0, 'duplicates' => 0, 'conflicts' => 0, 'failed' => [] ];
		$last_id = 0;
		do {
			$rows = Manager::get_results(
				'SELECT id FROM ' . Manager::table( 'characters' )
				. ' WHERE id > %d AND JSON_CONTAINS_PATH( sheet_data, \'one\', \'$."' . self::SOURCE_BLOCK . '"\' )'
				. ' ORDER BY id ASC LIMIT 200',
				$last_id
			);
			$ids  = array_map( static fn( $row ): int => (int) $row->id, $rows );
			foreach ( $ids as $id ) {
				$last_id = $id;
				$result  = self::refile_character( $id, $actor_id );
				if ( $result['outcome'] === 'failed' ) {
					$totals['failed'][ $id ] = (string) $result['reason'];
				} elseif ( $result['outcome'] === 'changed' ) {
					++$totals['characters'];
					$totals['moved']      += $result['moved'];
					$totals['duplicates'] += $result['duplicates'];
					$totals['conflicts']  += $result['conflicts'];
				}
			}
		} while ( count( $ids ) === 200 );

		if ( $totals['characters'] > 0 || $totals['failed'] !== [] ) {
			error_log( sprintf(
				'Beyond Elysium: moved %d Blood Magic picks (%d already held and dropped, %d at a different level) on %d characters; %d characters failed.',
				$totals['moved'],
				$totals['duplicates'],
				$totals['conflicts'],
				$totals['characters'],
				count( $totals['failed'] )
			) );
		}

		return $totals;
	}

	/**
	 * The traditions and misspellings a chronicle's Blood Magic list uses.
	 *
	 * @return array{known:string[],aliases:array<string,string>}
	 */
	public static function catalog_traditions( string $game_slug ): array {
		static $cache = [];
		if ( ! isset( $cache[ $game_slug ] ) ) {
			$block      = Schema_Block::find_for_game( self::TARGET_BLOCK, $game_slug );
			$definition = $block !== null && is_object( $block->definition ?? null ) ? $block->definition : null;
			$known      = $definition && is_array( $definition->traditions ?? null ) && $definition->traditions !== []
				? array_map( 'strval', $definition->traditions )
				: self::FALLBACK_TRADITIONS;
			$aliases    = $definition ? (array) ( $definition->tradition_aliases ?? [] ) : [];
			$cache[ $game_slug ] = [ 'known' => $known, 'aliases' => array_map( 'strval', $aliases ) ];
		}
		return $cache[ $game_slug ];
	}

	/**
	 * One character, all or nothing, under a row lock.
	 *
	 * @return array<string,mixed> `outcome` is `changed`, `unchanged`, `missing` or `failed`.
	 */
	private static function refile_character( int $id, int $actor_id ): array {
		$savepoint = Transaction::begin( 'be_blood_magic_refile' );
		try {
			Character::lock( $id );
			$character = Character::find( $id );
			if ( ! $character ) {
				Transaction::rollback( $savepoint );
				return [ 'outcome' => 'missing' ];
			}

			$traditions = self::catalog_traditions( (string) ( $character->owner_slug ?? '' ) );
			$sheet      = is_array( $character->sheet_data ) ? $character->sheet_data : [];
			$result     = self::refile_sheet( $sheet, $traditions['known'], $traditions['aliases'] );
			if ( $result['records'] === [] ) {
				Transaction::commit( $savepoint );
				return [ 'outcome' => 'unchanged' ];
			}

			$outcomes   = array_count_values( array_column( $result['records'], 'outcome' ) );
			$moved      = ( $outcomes['moved'] ?? 0 ) + ( $outcomes['conflict'] ?? 0 );
			$duplicates = $outcomes['duplicate'] ?? 0;
			$conflicts  = $outcomes['conflict'] ?? 0;
			$change_id  = Change::create( [
				'character_id' => $id,
				'change_type'  => 'catalog_rekey',
				'category'     => 'catalog',
				'change_data'  => [
					'counts'  => [ 'moved_rows' => $moved, 'rekeyed' => 0, 'respelled' => 0, 'dropped' => $duplicates ],
					'records' => $result['records'],
				],
				'xp_cost'      => 0,
				'status'       => 'approved',
				'submitted_by' => $actor_id,
				'notes'        => 'Blood Magic picks moved out of Disciplines.',
			] );
			if ( ! $change_id ) {
				throw new \RuntimeException( 'the history record could not be written' );
			}
			Change::update_status( $change_id, 'approved', $actor_id, null );
			Snapshot::create( $id, $change_id );
			if ( ! Character::update_sheet_data( $id, $result['sheet_data'] ) ) {
				throw new \RuntimeException( 'the sheet could not be written' );
			}

			Transaction::commit( $savepoint );
			return [ 'outcome' => 'changed', 'moved' => $moved, 'duplicates' => $duplicates, 'conflicts' => $conflicts ];
		} catch ( \Throwable $e ) {
			Transaction::rollback( $savepoint );
			return [ 'outcome' => 'failed', 'reason' => $e->getMessage() ];
		}
	}
}
