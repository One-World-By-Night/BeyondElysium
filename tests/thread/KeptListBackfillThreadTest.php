<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Change;
use BeyondElysium\Models\Character;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Snapshot;
use BeyondElysium\Services\Change_Engine;
use BeyondElysium\Services\Kept_List_Backfill;
use WP_UnitTestCase;

/**
 * `Kept_List_Backfill::run()` against real characters and their import records.
 */
class KeptListBackfillThreadTest extends WP_UnitTestCase {

	private int $with_note;
	private int $without_note;

	public function setUp(): void {
		parent::setUp();

		$owner = self::factory()->user->create( [ 'role' => 'administrator' ] );
		Game::create( [ 'slug' => 'kept-list-test', 'name' => 'Kept List Test', 'created_by' => $owner ] );

		$this->with_note = (int) Character::create( [
			'name' => 'Bonded Vampire', 'owner_slug' => 'kept-list-test', 'stack_slug' => 'vampire', 'status' => 'active',
			'sheet_data' => [ 'vampire-identity' => [ 'Clan' => 'Lasombra' ] ], 'created_by' => $owner,
		] );
		Character::update_xp( $this->with_note, 30, 4 );
		Change_Engine::submit( $this->with_note, [
			'change_type' => 'import_note', 'category' => 'import',
			'change_data' => [
				'source_file' => 'test.gex', 'imported_at' => current_time( 'mysql' ), 'action' => 'created',
				'raw_record'  => [ 'trait_lists' => [
					[ 'name' => 'Bonds', 'traits' => [ [ 'name' => 'SOC: Talos', 'total' => '9', 'note' => '' ] ] ],
				] ],
			],
		], 1 );

		$this->without_note = (int) Character::create( [
			'name' => 'Hand-made Vampire', 'owner_slug' => 'kept-list-test', 'stack_slug' => 'vampire', 'status' => 'active',
			'sheet_data' => [ 'vampire-identity' => [ 'Clan' => 'Brujah' ] ], 'created_by' => $owner,
		] );
	}

	public function test_bonds_are_filled_from_the_import_record_with_a_record_and_a_snapshot(): void {
		Kept_List_Backfill::run();

		$character = Character::find( $this->with_note );
		$this->assertEquals( [ [ 'name' => 'SOC: Talos', 'count' => 9, 'custom' => true ] ], $character->sheet_data['vampire-bonds'] );
		$this->assertSame( 30, (int) $character->xp_earned, 'XP is never touched' );
		$this->assertSame( 4, (int) $character->xp_unspent, 'XP is never touched' );

		$records = array_values( array_filter( Change::for_character( $this->with_note ), static fn( $c ) => $c->change_type === 'catalog_rekey' ) );
		$this->assertCount( 1, $records );
		$this->assertGreaterThan( 0, Snapshot::count_for_character( $this->with_note ) );
	}

	public function test_a_second_run_changes_nothing_and_a_hand_made_character_is_left_alone(): void {
		Kept_List_Backfill::run();
		$before = Character::find( $this->with_note )->sheet_data;

		$totals = Kept_List_Backfill::run();

		$this->assertSame( 0, $totals['characters'] );
		$this->assertSame( $before, Character::find( $this->with_note )->sheet_data );
		$this->assertArrayNotHasKey( 'vampire-bonds', Character::find( $this->without_note )->sheet_data );
	}
}
