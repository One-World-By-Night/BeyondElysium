<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Database\Schema;
use BeyondElysium\Models\Change;
use BeyondElysium\Models\Character;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Snapshot;
use WP_UnitTestCase;

/**
 * Moving "Tradition: Path" picks out of Disciplines never leaves a second copy of a path Blood Magic already holds.
 */
class BloodMagicRefileThreadTest extends WP_UnitTestCase {

	private const GAME = 'thread-bm-refile';

	public function setUp(): void {
		parent::setUp();
		Game::create( [ 'slug' => self::GAME, 'name' => 'Thread Blood Magic Refile' ] );
	}

	private function character( array $disciplines, array $blood_magic = [] ): int {
		$sheet = [ 'vampire-disciplines' => $disciplines ];
		if ( $blood_magic !== [] ) {
			$sheet['vampire-blood-magic'] = $blood_magic;
		}
		return Character::create( [
			'name' => 'Refile Test Character', 'stack_slug' => 'vampire',
			'owner_type' => 'chronicle', 'owner_slug' => self::GAME, 'sheet_data' => $sheet,
		] );
	}

	/**
	 * @return array<string,mixed>
	 */
	private function sheet( int $id ): array {
		return Character::find( $id )->sheet_data;
	}

	private function canonical( string $name, int $level, string $tradition ): array {
		return [ 'name' => $name, 'tier' => '***', 'level' => $level, 'custom' => true, 'tradition' => $tradition ];
	}

	public function test_a_pick_blood_magic_already_holds_is_dropped_not_copied(): void {
		$held = $this->canonical( 'Awakening of the Steel', 5, 'Dur An Ki' );
		$id   = $this->character(
			[ [ 'name' => 'Fortitude', 'level' => 3 ], [ 'name' => 'Dur-An-Ki: Awakening of the Steel', 'level' => 5 ] ],
			[ $held ]
		);

		Schema::migrate_blood_magic_held_picks();

		$sheet = $this->sheet( $id );
		$this->assertSame( [ [ 'name' => 'Fortitude', 'level' => 3 ] ], $sheet['vampire-disciplines'] );
		$this->assertSame( [ $held ], $sheet['vampire-blood-magic'], 'one row, the one already held, untouched' );
	}

	public function test_a_misspelled_tradition_is_folded_to_the_catalogs_spelling(): void {
		$id = $this->character( [
			[ 'name' => 'Sadhanna: Alchemy', 'level' => 2 ],
			[ 'name' => 'Dur-An-Ki: Elemental Mastery', 'level' => 5 ],
		] );

		Schema::migrate_blood_magic_held_picks();

		$this->assertSame(
			[
				[ 'name' => 'Alchemy', 'level' => 2, 'tradition' => 'Sadhana' ],
				[ 'name' => 'Elemental Mastery', 'level' => 5, 'tradition' => 'Dur An Ki' ],
			],
			$this->sheet( $id )['vampire-blood-magic']
		);
	}

	public function test_a_misspelled_pick_of_a_path_held_under_the_right_spelling_is_dropped(): void {
		$held = $this->canonical( 'Alchemy', 2, 'Sadhana' );
		$id   = $this->character( [ [ 'name' => 'Sadhanna: Alchemy', 'level' => 2 ] ], [ $held ] );

		Schema::migrate_blood_magic_held_picks();

		$this->assertSame( [ $held ], $this->sheet( $id )['vampire-blood-magic'] );
	}

	public function test_a_pick_at_a_different_level_moves_and_the_history_flags_it(): void {
		$held = $this->canonical( "Neptune's Might", 3, 'Dur An Ki' );
		$id   = $this->character( [ [ 'name' => "Dur-An-Ki: Neptune's Might", 'level' => 5 ] ], [ $held ] );

		Schema::migrate_blood_magic_held_picks();

		$rows = $this->sheet( $id )['vampire-blood-magic'];
		$this->assertCount( 2, $rows, 'nothing is lost' );
		$this->assertSame( [ 3, 5 ], array_column( $rows, 'level' ) );

		$records = Change::for_character( $id, [ 'change_type' => 'catalog_rekey' ] )[0]->change_data['records'];
		$this->assertSame( 'conflict', $records[0]['outcome'] );
		$this->assertSame( 3, $records[0]['held_level'] );
		$this->assertSame( 5, $records[0]['level'] );
	}

	public function test_rows_for_each_level_of_one_path_are_never_taken_for_repeats(): void {
		$rungs = [
			[ 'name' => 'Sepulchre Path', 'level' => 1, 'tradition' => 'Necromancy' ],
			[ 'name' => 'Sepulchre Path', 'level' => 2, 'tradition' => 'Necromancy' ],
			[ 'name' => 'Sepulchre Path', 'level' => 3, 'tradition' => 'Necromancy' ],
		];
		$id = $this->character(
			[
				[ 'name' => 'Necromancy: Sepulchre Path', 'level' => 2 ],
				[ 'name' => 'Necromancy: Sepulchre Path', 'level' => 4 ],
			],
			$rungs
		);

		Schema::migrate_blood_magic_held_picks();

		$rows = $this->sheet( $id )['vampire-blood-magic'];
		$this->assertSame( [ 1, 2, 3, 4 ], array_column( $rows, 'level' ), 'level 2 was already held; level 4 is a new row' );
		$records = Change::for_character( $id, [ 'change_type' => 'catalog_rekey' ] )[0]->change_data['records'];
		$this->assertSame( [ 'duplicate', 'moved' ], array_column( $records, 'outcome' ), 'a path held at several levels has no single level to disagree with' );
	}

	public function test_it_snapshots_the_sheet_first_and_records_what_it_did(): void {
		$id = $this->character(
			[ [ 'name' => 'Dur-An-Ki: Awakening of the Steel', 'level' => 5 ], [ 'name' => 'Sadhanna: Alchemy', 'level' => 2 ] ],
			[ $this->canonical( 'Awakening of the Steel', 5, 'Dur An Ki' ) ]
		);

		$snapshots_before = count( Snapshot::for_character( $id ) );

		Schema::migrate_blood_magic_held_picks();

		$changes = Change::for_character( $id, [ 'change_type' => 'catalog_rekey' ] );
		$this->assertCount( 1, $changes );
		$this->assertSame( 'approved', $changes[0]->status );
		$this->assertSame( 1, $changes[0]->change_data['counts']['moved_rows'] );
		$this->assertSame( 1, $changes[0]->change_data['counts']['dropped'] );

		$snapshots = Snapshot::for_character( $id );
		$this->assertCount( $snapshots_before + 1, $snapshots );
		$mine = array_values( array_filter( $snapshots, static fn( $s ) => (int) $s->change_id === (int) $changes[0]->id ) );
		$this->assertCount( 1, $mine, 'one snapshot belongs to the history record' );
		$this->assertCount( 2, $mine[0]->snapshot_data['vampire-disciplines'], 'the snapshot is the sheet as it was before the move' );
	}

	public function test_a_second_run_changes_nothing(): void {
		$id = $this->character(
			[ [ 'name' => 'Dur-An-Ki: Awakening of the Steel', 'level' => 5 ] ],
			[ $this->canonical( 'Awakening of the Steel', 5, 'Dur An Ki' ) ]
		);

		Schema::migrate_blood_magic_held_picks();
		$first     = $this->sheet( $id );
		$snapshots = count( Snapshot::for_character( $id ) );
		Schema::migrate_blood_magic_held_picks();

		$this->assertSame( $first, $this->sheet( $id ) );
		$this->assertCount( 1, Change::for_character( $id, [ 'change_type' => 'catalog_rekey' ] ) );
		$this->assertCount( $snapshots, Snapshot::for_character( $id ) );
	}
}
