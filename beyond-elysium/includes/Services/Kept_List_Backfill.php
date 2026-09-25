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
 * Fills a sheet block from the Grapevine list a character's import kept only in its import record, for each list the
 * import map marks `backfill`, when the character holds nothing in that block yet.
 */
class Kept_List_Backfill {

	/**
	 * The lists to fill, by creature stack.
	 *
	 * @return array<string,array<string,string>> Stack slug => [ Grapevine list name => block slug ].
	 */
	public static function lists(): array {
		$map   = require __DIR__ . '/gex-trait-list-map.php';
		$lists = [];
		foreach ( $map as $stack => $entries ) {
			if ( $stack === 'shared' ) {
				continue;
			}
			foreach ( (array) $entries as $list_name => $entry ) {
				if ( ! empty( $entry['backfill'] ) && ( $entry['outcome'] ?? '' ) === 'sheet_block' && isset( $entry['block_slug'] ) ) {
					$lists[ (string) $stack ][ (string) $list_name ] = (string) $entry['block_slug'];
				}
			}
		}
		return $lists;
	}

	/**
	 * One character's sheet with each empty block filled from the matching list in its import record: a row per trait,
	 * `{name, count, note?}`, marked custom unless the block's catalog holds that name.
	 *
	 * @param array<string,mixed>               $sheet_data
	 * @param array<int,mixed>                  $raw_lists     The import record's `raw_record.trait_lists`.
	 * @param array<string,string>              $lists         Grapevine list name => block slug.
	 * @param array<string,array<string,bool>>  $catalog_names Block slug => [ item name => true ].
	 * @return array{sheet_data:array<string,mixed>,records:array<int,array<string,mixed>>}
	 */
	public static function backfill_sheet( array $sheet_data, array $raw_lists, array $lists, array $catalog_names = [] ): array {
		$records = [];

		foreach ( $raw_lists as $list ) {
			$name  = is_array( $list ) ? (string) ( $list['name'] ?? '' ) : '';
			$block = $lists[ $name ] ?? null;
			if ( $block === null ) {
				continue;
			}
			$held = $sheet_data[ $block ] ?? null;
			if ( is_array( $held ) && $held !== [] ) {
				continue;
			}

			$rows = [];
			foreach ( (array) ( $list['traits'] ?? [] ) as $trait ) {
				$trait_name = is_array( $trait ) ? trim( (string) ( $trait['name'] ?? '' ) ) : '';
				if ( $trait_name === '' ) {
					continue;
				}
				$row  = [ 'name' => $trait_name, 'count' => (int) ( $trait['total'] ?? 0 ) ];
				$note = trim( (string) ( $trait['note'] ?? '' ) );
				if ( $note !== '' ) {
					$row['note'] = $note;
				}
				if ( empty( $catalog_names[ $block ][ $trait_name ] ) ) {
					$row['custom'] = true;
				}
				$rows[]    = $row;
				$records[] = [ 'outcome' => 'restored', 'from' => $name, 'to_block' => $block, 'to' => $trait_name, 'count' => $row['count'] ];
			}

			if ( $rows !== [] ) {
				$sheet_data[ $block ] = $rows;
			}
		}

		return [ 'sheet_data' => $sheet_data, 'records' => $records ];
	}

	/**
	 * Fills every character's empty blocks from its newest import record, one character at a time, each with a
	 * `catalog_rekey` history record and a snapshot of the sheet before it.
	 *
	 * @param int $actor_id User recorded as submitter of each history record.
	 * @return array{characters:int,rows:int,failed:array<int,string>}
	 */
	public static function run( int $actor_id = 0 ): array {
		$totals = [ 'characters' => 0, 'rows' => 0, 'failed' => [] ];

		foreach ( self::lists() as $stack => $lists ) {
			$catalog_names = self::catalog_names( array_values( $lists ) );
			$last_id       = 0;
			do {
				$rows = Manager::get_results(
					'SELECT DISTINCT c.id FROM ' . Manager::table( 'characters' ) . ' c'
					. ' JOIN ' . Manager::table( 'character_changes' ) . " x ON x.character_id = c.id AND x.change_type = 'import_note'"
					. ' WHERE c.stack_slug = %s AND c.id > %d ORDER BY c.id ASC LIMIT 200',
					$stack,
					$last_id
				);
				foreach ( $rows as $row ) {
					$last_id = (int) $row->id;
					$result  = self::backfill_character( $last_id, $lists, $catalog_names, $actor_id );
					if ( $result['outcome'] === 'failed' ) {
						$totals['failed'][ $last_id ] = (string) $result['reason'];
					} elseif ( $result['outcome'] === 'changed' ) {
						++$totals['characters'];
						$totals['rows'] += $result['rows'];
					}
				}
			} while ( count( $rows ) === 200 );
		}

		if ( $totals['characters'] > 0 || $totals['failed'] !== [] ) {
			error_log( sprintf(
				'Beyond Elysium: filled %d rows on %d characters from their import records; %d characters failed.',
				$totals['rows'],
				$totals['characters'],
				count( $totals['failed'] )
			) );
		}

		return $totals;
	}

	/**
	 * Each block's catalog item names.
	 *
	 * @param array<int,string> $block_slugs
	 * @return array<string,array<string,bool>>
	 */
	private static function catalog_names( array $block_slugs ): array {
		$names = [];
		foreach ( array_unique( $block_slugs ) as $slug ) {
			$block = Schema_Block::find_by_slug( $slug );
			foreach ( (array) ( $block->definition->items ?? [] ) as $item ) {
				if ( isset( $item->name ) ) {
					$names[ $slug ][ (string) $item->name ] = true;
				}
			}
		}
		return $names;
	}

	/**
	 * One character, all or nothing, under a row lock.
	 *
	 * @param array<string,string>             $lists
	 * @param array<string,array<string,bool>> $catalog_names
	 * @return array<string,mixed> `outcome` is `changed`, `unchanged`, `missing` or `failed`.
	 */
	private static function backfill_character( int $id, array $lists, array $catalog_names, int $actor_id ): array {
		$savepoint = Transaction::begin( 'be_kept_list_backfill' );
		try {
			Character::lock( $id );
			$character = Character::find( $id );
			if ( ! $character ) {
				Transaction::rollback( $savepoint );
				return [ 'outcome' => 'missing' ];
			}

			$note = Manager::get_row(
				'SELECT change_data FROM ' . Manager::table( 'character_changes' )
				. " WHERE character_id = %d AND change_type = 'import_note' ORDER BY id DESC LIMIT 1",
				$id
			);
			$data      = $note ? json_decode( (string) $note->change_data, true ) : null;
			$raw_lists = is_array( $data['raw_record']['trait_lists'] ?? null ) ? array_values( $data['raw_record']['trait_lists'] ) : [];

			$sheet  = is_array( $character->sheet_data ) ? $character->sheet_data : [];
			$result = self::backfill_sheet( $sheet, $raw_lists, $lists, $catalog_names );
			if ( $result['records'] === [] ) {
				Transaction::commit( $savepoint );
				return [ 'outcome' => 'unchanged' ];
			}

			$count     = count( $result['records'] );
			$change_id = Change::create( [
				'character_id' => $id,
				'change_type'  => 'catalog_rekey',
				'category'     => 'catalog',
				'change_data'  => [
					'counts'  => [ 'moved_rows' => $count, 'rekeyed' => 0, 'respelled' => 0 ],
					'records' => $result['records'],
				],
				'xp_cost'      => 0,
				'status'       => 'approved',
				'submitted_by' => $actor_id,
				'notes'        => 'Rows filled from the character\'s import record.',
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
			return [ 'outcome' => 'changed', 'rows' => $count ];
		} catch ( \Throwable $e ) {
			Transaction::rollback( $savepoint );
			return [ 'outcome' => 'failed', 'reason' => $e->getMessage() ];
		}
	}
}
