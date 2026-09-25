<?php

namespace BeyondElysium\Tests\Unit;

use BeyondElysium\Services\Kept_List_Backfill;
use PHPUnit\Framework\TestCase;

/**
 * Filling a block from the list a character's import kept only in its import record.
 */
class KeptListBackfillTest extends TestCase {

	private const LISTS = [ 'Bonds' => 'vampire-bonds' ];

	private function raw(): array {
		return [
			[ 'name' => 'Miscellaneous', 'traits' => [ [ 'name' => 'Kept Aside', 'total' => '1', 'note' => '' ] ] ],
			[ 'name' => 'Bonds', 'traits' => [
				[ 'name' => 'SOC: Talos', 'total' => '9', 'note' => '' ],
				[ 'name' => 'Regnant', 'total' => '2', 'note' => 'blood bond' ],
				[ 'name' => '', 'total' => '1', 'note' => '' ],
			] ],
		];
	}

	public function test_an_empty_block_is_filled_one_row_per_trait_with_its_rating(): void {
		$result = Kept_List_Backfill::backfill_sheet( [ 'vampire-identity' => [ 'Clan' => 'Lasombra' ] ], $this->raw(), self::LISTS );

		$this->assertSame(
			[
				[ 'name' => 'SOC: Talos', 'count' => 9, 'custom' => true ],
				[ 'name' => 'Regnant', 'count' => 2, 'note' => 'blood bond', 'custom' => true ],
			],
			$result['sheet_data']['vampire-bonds']
		);
		$this->assertSame( [ 'Lasombra' ], array_values( $result['sheet_data']['vampire-identity'] ) );
		$this->assertCount( 2, $result['records'] );
		$this->assertArrayNotHasKey( 'Miscellaneous', $result['sheet_data'], 'a list the map does not mark stays in the record' );
	}

	public function test_a_block_that_already_holds_rows_is_left_alone(): void {
		$sheet  = [ 'vampire-bonds' => [ [ 'name' => 'Someone Else', 'count' => 1 ] ] ];
		$result = Kept_List_Backfill::backfill_sheet( $sheet, $this->raw(), self::LISTS );

		$this->assertSame( $sheet, $result['sheet_data'] );
		$this->assertSame( [], $result['records'] );
	}

	public function test_a_name_the_catalog_holds_is_not_marked_custom(): void {
		$result = Kept_List_Backfill::backfill_sheet( [], $this->raw(), self::LISTS, [ 'vampire-bonds' => [ 'Regnant' => true ] ] );

		$this->assertArrayNotHasKey( 'custom', $result['sheet_data']['vampire-bonds'][1] );
	}

	public function test_the_lists_come_from_the_import_map(): void {
		$this->assertSame( 'vampire-bonds', Kept_List_Backfill::lists()['vampire']['Bonds'] ?? null );
	}
}
