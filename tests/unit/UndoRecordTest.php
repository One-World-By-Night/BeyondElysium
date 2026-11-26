<?php

namespace BeyondElysium\Tests\Unit;

use BeyondElysium\Services\Undo_Record;
use PHPUnit\Framework\TestCase;

/**
 * The record of what a change did, and putting it back.
 */
class UndoRecordTest extends TestCase {

	private function undo( array $before, array $after, array $kinds = [], int $earned = 0, int $unspent = 0 ): array {
		$record = Undo_Record::build( $before, $after, $earned, $unspent );
		$this->assertNotNull( $record );
		$this->assertSame( [], Undo_Record::conflicts( $record, $after ), 'a record can be undone against the sheet it made' );
		return Undo_Record::reverse( $record, $after, $kinds );
	}

	public function test_a_change_that_altered_nothing_leaves_no_record(): void {
		$sheet = [ 'abilities' => [ [ 'name' => 'Occult', 'count' => 3 ] ] ];
		$this->assertNull( Undo_Record::build( $sheet, $sheet ) );
	}

	public function test_a_change_that_only_moved_xp_still_leaves_a_record(): void {
		$sheet  = [ 'abilities' => [] ];
		$record = Undo_Record::build( $sheet, $sheet, 3, 3 );
		$this->assertSame( [ 'earned' => 3, 'unspent' => 3 ], Undo_Record::xp( $record ?? [] ) );
	}

	public function test_an_append_is_undone_by_taking_the_row_out(): void {
		$before = [ 'abilities' => [ [ 'name' => 'Occult', 'count' => 3 ] ] ];
		$after  = [ 'abilities' => [ [ 'name' => 'Occult', 'count' => 3 ], [ 'name' => 'Brawl', 'count' => 1 ] ] ];

		$this->assertSame( $before, $this->undo( $before, $after ) );
	}

	public function test_a_removal_from_the_middle_comes_back_where_it_was(): void {
		$before = [ 'abilities' => [ [ 'name' => 'A', 'count' => 1 ], [ 'name' => 'B', 'count' => 2 ], [ 'name' => 'C', 'count' => 3 ] ] ];
		$after  = [ 'abilities' => [ [ 'name' => 'A', 'count' => 1 ], [ 'name' => 'C', 'count' => 3 ] ] ];

		$this->assertSame( $before, $this->undo( $before, $after ) );
	}

	public function test_a_merge_into_one_row_puts_that_row_back_in_place(): void {
		$before = [ 'abilities' => [ [ 'name' => 'Occult', 'count' => 3 ], [ 'name' => 'Brawl', 'count' => 2 ] ] ];
		$after  = [ 'abilities' => [ [ 'name' => 'Occult', 'count' => 4 ], [ 'name' => 'Brawl', 'count' => 2 ] ] ];

		$this->assertSame( $before, $this->undo( $before, $after ) );
	}

	public function test_a_later_change_to_another_row_does_not_stop_the_undo(): void {
		$before = [ 'abilities' => [ [ 'name' => 'Occult', 'count' => 3 ], [ 'name' => 'Brawl', 'count' => 2 ] ] ];
		$after  = [ 'abilities' => [ [ 'name' => 'Occult', 'count' => 4 ], [ 'name' => 'Brawl', 'count' => 2 ] ] ];
		$record = Undo_Record::build( $before, $after );

		$later = [ 'abilities' => [ [ 'name' => 'Brawl', 'count' => 3 ], [ 'name' => 'Occult', 'count' => 4 ], [ 'name' => 'Dodge', 'count' => 1 ] ] ];
		$this->assertSame( [], Undo_Record::conflicts( $record ?? [], $later ) );
		$this->assertSame(
			[ [ 'name' => 'Brawl', 'count' => 3 ], [ 'name' => 'Occult', 'count' => 3 ], [ 'name' => 'Dodge', 'count' => 1 ] ],
			Undo_Record::reverse( $record ?? [], $later )['abilities']
		);
	}

	public function test_a_later_change_to_the_same_row_stops_the_undo_and_says_what_it_is_now(): void {
		$before = [ 'abilities' => [ [ 'name' => 'Occult', 'count' => 3 ] ] ];
		$after  = [ 'abilities' => [ [ 'name' => 'Occult', 'count' => 4 ] ] ];
		$record = Undo_Record::build( $before, $after );

		$later     = [ 'abilities' => [ [ 'name' => 'Occult', 'count' => 5 ] ] ];
		$conflicts = Undo_Record::conflicts( $record ?? [], $later );

		$this->assertCount( 1, $conflicts );
		$this->assertSame( 'missing', $conflicts[0]['kind'] );
		$this->assertSame( 'Occult', $conflicts[0]['name'] );
		$this->assertSame( [ 'name' => 'Occult', 'count' => 5 ], $conflicts[0]['now'] );
	}

	public function test_an_added_row_that_has_gone_stops_the_undo(): void {
		$before = [ 'abilities' => [] ];
		$after  = [ 'abilities' => [ [ 'name' => 'Brawl', 'count' => 1 ] ] ];
		$record = Undo_Record::build( $before, $after );

		$conflicts = Undo_Record::conflicts( $record ?? [], [ 'abilities' => [] ] );

		$this->assertSame( 'missing', $conflicts[0]['kind'] );
		$this->assertNull( $conflicts[0]['now'] );
	}

	public function test_a_removed_row_that_is_back_stops_the_undo(): void {
		$before = [ 'abilities' => [ [ 'name' => 'Brawl', 'count' => 1 ] ] ];
		$after  = [ 'abilities' => [] ];
		$record = Undo_Record::build( $before, $after );

		$conflicts = Undo_Record::conflicts( $record ?? [], $before );

		$this->assertSame( 'back', $conflicts[0]['kind'] );
	}

	public function test_a_block_the_sheet_did_not_hold_is_taken_off_again(): void {
		$before = [ 'abilities' => [] ];
		$after  = [ 'abilities' => [], 'merits' => [ [ 'name' => 'Lucky' ] ] ];

		$this->assertSame( $before, $this->undo( $before, $after ) );
	}

	public function test_a_changed_pool_goes_back_and_its_temporary_rating_comes_down_with_it(): void {
		$before = [ 'resources' => [ 'Willpower' => [ 'permanent' => 5, 'temporary' => 5 ], 'Blood' => [ 'permanent' => 10, 'temporary' => 10 ] ] ];
		$after  = [ 'resources' => [ 'Willpower' => [ 'permanent' => 6, 'temporary' => 6 ], 'Blood' => [ 'permanent' => 10, 'temporary' => 10 ] ] ];
		$record = Undo_Record::build( $before, $after );

		$played = [ 'resources' => [ 'Willpower' => [ 'permanent' => 6, 'temporary' => 2 ], 'Blood' => [ 'permanent' => 10, 'temporary' => 4 ] ] ];
		$this->assertSame( [], Undo_Record::conflicts( $record ?? [], $played ), 'what was spent at the table never stops it' );

		$undone = Undo_Record::reverse( $record ?? [], $played );
		$this->assertSame( [ 'permanent' => 5, 'temporary' => 1 ], $undone['resources']['Willpower'] );
		$this->assertSame( [ 'permanent' => 10, 'temporary' => 4 ], $undone['resources']['Blood'] );
	}

	public function test_a_pool_that_built_up_keeps_its_count_when_the_rating_comes_back_down(): void {
		$before = [ 'resources' => [ 'Banality' => [ 'permanent' => 2, 'temporary' => 7 ] ] ];
		$after  = [ 'resources' => [ 'Banality' => [ 'permanent' => 3, 'temporary' => 7 ] ] ];
		$kinds  = [ 'resources' => [ 'Banality' => [ 'kind' => 'accumulates', 'max' => 10 ] ] ];

		$this->assertSame( $before, $this->undo( $before, $after, $kinds ) );
	}

	public function test_a_pool_with_no_temporary_points_stays_level_with_its_rating(): void {
		$before = [ 'resources' => [ 'Morality' => [ 'permanent' => 7, 'temporary' => 7 ] ] ];
		$after  = [ 'resources' => [ 'Morality' => [ 'permanent' => 6, 'temporary' => 6 ] ] ];
		$kinds  = [ 'resources' => [ 'Morality' => [ 'kind' => 'rating_only', 'max' => 10 ] ] ];

		$this->assertSame( $before, $this->undo( $before, $after, $kinds ) );
	}

	public function test_a_spent_from_block_gets_its_spent_dots_back(): void {
		$before = [ 'virtues' => [ 'Mercy' => [ 'permanent' => 3, 'temporary' => 0, 'spent' => 0 ] ] ];
		$after  = [ 'virtues' => [ 'Mercy' => [ 'permanent' => 3, 'temporary' => 0, 'spent' => 1 ] ] ];

		$this->assertSame( $before, $this->undo( $before, $after ) );
	}

	public function test_a_raised_by_source_gets_its_temporary_points_back(): void {
		$before = [
			'virtues'   => [ 'Mercy' => [ 'permanent' => 3, 'temporary' => 0 ] ],
			'resources' => [ 'Conviction' => [ 'permanent' => 3, 'temporary' => 10 ] ],
		];
		$after = [
			'virtues'   => [ 'Mercy' => [ 'permanent' => 4, 'temporary' => 0 ] ],
			'resources' => [ 'Conviction' => [ 'permanent' => 3, 'temporary' => 0 ] ],
		];
		$kinds = [ 'resources' => [ 'Conviction' => [ 'kind' => 'accumulates', 'max' => 10 ] ] ];

		$this->assertSame( $before, $this->undo( $before, $after, $kinds ) );
	}

	public function test_an_identity_field_goes_back_and_a_later_edit_stops_it(): void {
		$before = [ 'identity' => [ 'Clan' => 'Tremere', 'Sect' => 'Camarilla' ] ];
		$after  = [ 'identity' => [ 'Clan' => 'Tremere', 'Sect' => 'Sabbat' ] ];
		$record = Undo_Record::build( $before, $after );

		$this->assertSame( $before, $this->undo( $before, $after ) );

		$later     = [ 'identity' => [ 'Clan' => 'Tremere', 'Sect' => 'Anarch' ] ];
		$conflicts = Undo_Record::conflicts( $record ?? [], $later );
		$this->assertSame( [ 'block' => 'identity', 'name' => 'Sect', 'now' => 'Anarch', 'kind' => 'changed' ], $conflicts[0] );
	}

	public function test_a_field_the_change_added_is_removed_again(): void {
		$before = [ 'identity' => [ 'Clan' => 'Tremere' ] ];
		$after  = [ 'identity' => [ 'Clan' => 'Tremere', 'Sire' => 'Etrius' ] ];

		$this->assertSame( $before, $this->undo( $before, $after ) );
	}

	public function test_the_record_survives_a_round_trip_through_json(): void {
		$before = [ 'abilities' => [ [ 'name' => 'Occult', 'count' => 3 ] ] ];
		$after  = [ 'abilities' => [ [ 'name' => 'Occult', 'count' => 4 ] ] ];
		$record = Undo_Record::build( $before, $after, 0, -1 );

		$again = json_decode( (string) json_encode( $record ), true );

		$this->assertSame( $before, Undo_Record::reverse( $again, $after ) );
		$this->assertSame( [ 'earned' => 0, 'unspent' => -1 ], Undo_Record::xp( $again ) );
	}
}
