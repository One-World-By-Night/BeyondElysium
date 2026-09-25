<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Change;
use BeyondElysium\Models\Character;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Snapshot;
use BeyondElysium\Services\Combo_Refile;
use WP_UnitTestCase;

/**
 * `Combo_Refile::run()` against real characters: combos held as picks move into the combo list with a history record
 * and a snapshot, XP is untouched, and a second run finds nothing to do.
 */
class ComboRefileThreadTest extends WP_UnitTestCase {

	private int $vampire_id;
	private int $mortal_id;

	public function setUp(): void {
		parent::setUp();

		$owner = self::factory()->user->create( [ 'role' => 'administrator' ] );
		Game::create( [ 'slug' => 'combo-refile-test', 'name' => 'Combo Refile Test', 'created_by' => $owner ] );

		$this->vampire_id = (int) Character::create( [
			'name'       => 'Refile Vampire',
			'owner_slug' => 'combo-refile-test',
			'stack_slug' => 'vampire',
			'status'     => 'active',
			'sheet_data' => [
				'vampire-disciplines'       => [
					[ 'name' => 'Celerity', 'level' => 3 ],
					[ 'name' => 'Combo', 'power_name' => 'Mortal Skin', 'tier' => '***', 'level' => 5, 'custom' => true ],
					[ 'name' => 'Combination', 'power_name' => 'Spy Master (&)', 'tier' => '***', 'custom' => true ],
				],
				'vampire-combo-disciplines' => [ [ 'name' => 'Draw Fire', 'count' => 12 ] ],
			],
			'created_by' => $owner,
		] );
		Character::update_xp( $this->vampire_id, 40, 7 );

		$this->mortal_id = (int) Character::create( [
			'name'       => 'Refile Mortal',
			'owner_slug' => 'combo-refile-test',
			'stack_slug' => 'mortal',
			'status'     => 'active',
			'sheet_data' => [ 'vampire-disciplines' => [ [ 'name' => 'Combo', 'power_name' => 'Mortal Skin', 'custom' => true ] ] ],
			'created_by' => $owner,
		] );
	}

	public function test_combos_move_into_the_combo_list_with_a_record_and_a_snapshot(): void {
		$totals = Combo_Refile::run();

		$character = Character::find( $this->vampire_id );
		$this->assertSame( [ [ 'name' => 'Celerity', 'level' => 3 ] ], $character->sheet_data['vampire-disciplines'] );
		$this->assertSame(
			[
				[ 'name' => 'Draw Fire', 'count' => 12 ],
				[ 'name' => 'Mortal Skin', 'count' => 5, 'custom' => true ],
				[ 'name' => 'Spy Master (&)', 'custom' => true ],
			],
			$character->sheet_data['vampire-combo-disciplines']
		);
		$this->assertSame( 40, (int) $character->xp_earned, 'XP is never touched' );
		$this->assertSame( 7, (int) $character->xp_unspent, 'XP is never touched' );

		$records = array_values( array_filter( Change::for_character( $this->vampire_id ), static fn( $c ) => $c->change_type === 'catalog_rekey' ) );
		$this->assertCount( 1, $records );
		$this->assertSame( 'approved', $records[0]->status );
		$this->assertSame( 2, (int) ( (array) $records[0]->change_data['counts'] )['moved_rows'] );
		$this->assertGreaterThan( 0, Snapshot::count_for_character( $this->vampire_id ), 'the sheet before the move is kept' );

		$this->assertGreaterThanOrEqual( 1, $totals['characters'] );
		$this->assertSame( [], $totals['failed'] );
	}

	public function test_a_second_run_finds_nothing_to_move(): void {
		Combo_Refile::run();
		$before = Character::find( $this->vampire_id )->sheet_data;

		$totals = Combo_Refile::run();

		$this->assertSame( 0, $totals['characters'] );
		$this->assertSame( $before, Character::find( $this->vampire_id )->sheet_data );
	}

	public function test_a_stack_the_import_map_pairs_no_combo_list_for_is_left_alone(): void {
		Combo_Refile::run();

		// The stored JSON comes back with its keys in the database's own order.
		$this->assertEquals(
			[ [ 'name' => 'Combo', 'power_name' => 'Mortal Skin', 'custom' => true ] ],
			Character::find( $this->mortal_id )->sheet_data['vampire-disciplines']
		);
	}
}
