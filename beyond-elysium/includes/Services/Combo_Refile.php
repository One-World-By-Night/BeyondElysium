<?php

namespace BeyondElysium\Services;

use BeyondElysium\Database\Manager;
use BeyondElysium\Database\Transaction;
use BeyondElysium\Models\Change;
use BeyondElysium\Models\Character;
use BeyondElysium\Models\Snapshot;

defined( 'ABSPATH' ) || exit;

/**
 * Moves combos held as custom picks in a power list into the combo list the Grapevine import map pairs with it, each as
 * a custom combo carrying the pick's held level as its cost. A pick whose label names no combo stays where it is.
 */
class Combo_Refile {

	/**
	 * The power lists that have a combo list beside them, by creature stack.
	 *
	 * @return array<string,array<string,string>> Stack slug => [ power block slug => combo block slug ].
	 */
	public static function pairs(): array {
		$map   = require __DIR__ . '/gex-trait-list-map.php';
		$pairs = [];
		foreach ( $map as $stack => $lists ) {
			if ( $stack === 'shared' ) {
				continue;
			}
			foreach ( (array) $lists as $entry ) {
				if ( isset( $entry['block_slug'], $entry['combo_block_slug'] ) ) {
					$pairs[ (string) $stack ][ (string) $entry['block_slug'] ] = (string) $entry['combo_block_slug'];
				}
			}
		}
		return $pairs;
	}

	/**
	 * The combo a held pick names, or null when it is not a combo: the name after its label, or, when the label stands
	 * alone before a colon, the pick's own power name.
	 *
	 * @param mixed $entry A held entry in a power list.
	 */
	public static function combo_of( $entry ): ?string {
		if ( ! is_array( $entry ) || empty( $entry['custom'] ) || ! isset( $entry['name'], $entry['power_name'] ) ) {
			return null;
		}
		$family = (string) $entry['name'];
		$power  = trim( (string) $entry['power_name'] );
		$rest   = Trait_Mapper::combo_name( $family );
		if ( $rest === null ) {
			return null;
		}

		if ( $power === '' || $power === trim( $family ) ) {
			return $rest !== '' ? $rest : null;
		}
		return $rest !== '' ? $rest . ': ' . $power : $power;
	}

	/**
	 * One character's sheet with its combos moved: each into the paired combo list as `{name, count?, custom}`, dropped
	 * instead when that list already holds a combo of the same name.
	 *
	 * @param array<string,mixed>  $sheet_data
	 * @param array<string,string> $pairs Power block slug => combo block slug.
	 * @return array{sheet_data:array<string,mixed>,records:array<int,array<string,mixed>>}
	 */
	public static function refile_sheet( array $sheet_data, array $pairs ): array {
		$records = [];

		foreach ( $pairs as $power_block => $combo_block ) {
			$held = $sheet_data[ $power_block ] ?? null;
			if ( ! is_array( $held ) || ! array_is_list( $held ) ) {
				continue;
			}

			$combos = is_array( $sheet_data[ $combo_block ] ?? null ) ? array_values( $sheet_data[ $combo_block ] ) : [];
			$names  = [];
			foreach ( $combos as $combo ) {
				if ( is_array( $combo ) && isset( $combo['name'] ) ) {
					$names[ (string) $combo['name'] ] = true;
				}
			}

			$stay  = [];
			$moved = false;
			foreach ( $held as $entry ) {
				$name = self::combo_of( $entry );
				if ( $name === null ) {
					$stay[] = $entry;
					continue;
				}

				$moved = true;
				$cost  = isset( $entry['level'] ) && is_numeric( $entry['level'] ) && (int) $entry['level'] > 0 ? (int) $entry['level'] : null;
				$from  = (string) $entry['name'] . ( (string) $entry['power_name'] !== (string) $entry['name'] ? ': ' . (string) $entry['power_name'] : '' );

				if ( isset( $names[ $name ] ) ) {
					$records[] = [ 'outcome' => 'duplicate', 'from_block' => $power_block, 'to_block' => $combo_block, 'from' => $from, 'to' => $name, 'cost' => $cost ];
					continue;
				}

				$row = [ 'name' => $name ];
				if ( $cost !== null ) {
					$row['count'] = $cost;
				}
				$row['custom'] = true;

				$combos[]       = $row;
				$names[ $name ] = true;
				$records[]      = [ 'outcome' => 'moved', 'from_block' => $power_block, 'to_block' => $combo_block, 'from' => $from, 'to' => $name, 'cost' => $cost ];
			}

			if ( $moved ) {
				$sheet_data[ $power_block ] = $stay;
				$sheet_data[ $combo_block ] = $combos;
			}
		}

		return [ 'sheet_data' => $sheet_data, 'records' => $records ];
	}

	/**
	 * Moves every character's combos, one character at a time, each with a `catalog_rekey` history record and a
	 * snapshot of the sheet before it.
	 *
	 * @param int $actor_id User recorded as submitter of each history record.
	 * @return array{characters:int,moved:int,duplicates:int,failed:array<int,string>}
	 */
	public static function run( int $actor_id = 0 ): array {
		$totals = [ 'characters' => 0, 'moved' => 0, 'duplicates' => 0, 'failed' => [] ];

		foreach ( self::pairs() as $stack => $pairs ) {
			$last_id = 0;
			do {
				$rows = Manager::get_results(
					'SELECT id FROM ' . Manager::table( 'characters' ) . ' WHERE stack_slug = %s AND id > %d ORDER BY id ASC LIMIT 200',
					$stack,
					$last_id
				);
				$ids  = array_map( static fn( $row ): int => (int) $row->id, $rows );
				foreach ( $ids as $id ) {
					$last_id = $id;
					$result  = self::refile_character( $id, $pairs, $actor_id );
					if ( $result['outcome'] === 'failed' ) {
						$totals['failed'][ $id ] = (string) $result['reason'];
					} elseif ( $result['outcome'] === 'changed' ) {
						++$totals['characters'];
						$totals['moved']      += $result['moved'];
						$totals['duplicates'] += $result['duplicates'];
					}
				}
			} while ( count( $ids ) === 200 );
		}

		if ( $totals['characters'] > 0 || $totals['failed'] !== [] ) {
			error_log( sprintf(
				'Beyond Elysium: moved %d combos (%d duplicates dropped) on %d characters into their combo lists; %d characters failed.',
				$totals['moved'],
				$totals['duplicates'],
				$totals['characters'],
				count( $totals['failed'] )
			) );
		}

		return $totals;
	}

	/**
	 * One character, all or nothing, under a row lock.
	 *
	 * @param array<string,string> $pairs
	 * @return array<string,mixed> `outcome` is `changed`, `unchanged`, `missing` or `failed`.
	 */
	private static function refile_character( int $id, array $pairs, int $actor_id ): array {
		$savepoint = Transaction::begin( 'be_combo_refile' );
		try {
			Character::lock( $id );
			$character = Character::find( $id );
			if ( ! $character ) {
				Transaction::rollback( $savepoint );
				return [ 'outcome' => 'missing' ];
			}

			$sheet  = is_array( $character->sheet_data ) ? $character->sheet_data : [];
			$result = self::refile_sheet( $sheet, $pairs );
			if ( $result['records'] === [] ) {
				Transaction::commit( $savepoint );
				return [ 'outcome' => 'unchanged' ];
			}

			$moved      = count( array_filter( $result['records'], static fn( $r ) => $r['outcome'] === 'moved' ) );
			$duplicates = count( $result['records'] ) - $moved;
			$change_id  = Change::create( [
				'character_id' => $id,
				'change_type'  => 'catalog_rekey',
				'category'     => 'catalog',
				'change_data'  => [
					'counts'  => [ 'moved_rows' => $moved, 'rekeyed' => 0, 'respelled' => 0, 'duplicates_dropped' => $duplicates ],
					'records' => $result['records'],
				],
				'xp_cost'      => 0,
				'status'       => 'approved',
				'submitted_by' => $actor_id,
				'notes'        => 'Combos moved from the power list into the combo list.',
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
			return [ 'outcome' => 'changed', 'moved' => $moved, 'duplicates' => $duplicates ];
		} catch ( \Throwable $e ) {
			Transaction::rollback( $savepoint );
			return [ 'outcome' => 'failed', 'reason' => $e->getMessage() ];
		}
	}
}
