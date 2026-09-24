<?php

namespace BeyondElysium\Tests\Thread;

require_once __DIR__ . '/../support/LegacyInstall.php';

use BeyondElysium\Database\Manager;
use BeyondElysium\Database\Option_Lock;
use BeyondElysium\Database\Seeder;
use BeyondElysium\Models\Change;
use BeyondElysium\Models\Character;
use BeyondElysium\Models\Creature_Stack;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Snapshot;
use BeyondElysium\Models\Template;
use BeyondElysium\Services\Catalog_Cutover;
use BeyondElysium\Services\Catalog_Reader;
use BeyondElysium\Tests\Support\LegacyInstall;
use WP_UnitTestCase;

/**
 * `Catalog_Cutover::apply()`.
 */
class CatalogCutoverApplyThreadTest extends WP_UnitTestCase {

	private string $game = 'cutover-apply';
	private int $actor;

	public function setUp(): void {
		parent::setUp();
		if ( ! Catalog_Reader::available() ) {
			$this->markTestSkipped( 'no declared catalog in this checkout' );
		}
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . Manager::table( 'characters' ) );
		LegacyInstall::put_in_place();

		$this->actor = self::factory()->user->create( [ 'role' => 'administrator' ] );
		Game::create( [ 'slug' => $this->game, 'name' => 'Cutover Apply' ] );
	}

	public function tearDown(): void {
		delete_option( Catalog_Cutover::OPTION );
		Option_Lock::release( Catalog_Cutover::LOCK );
		parent::tearDown();
	}

	/** @param array<string,mixed> $sheet */
	private function character( string $stack, string $name, array $sheet, int $earned = 40, int $unspent = 7 ): int {
		$id = (int) Character::create( [
			'name' => $name, 'stack_slug' => $stack, 'owner_type' => 'chronicle',
			'owner_slug' => $this->game, 'status' => 'active', 'sheet_data' => $sheet,
		] );
		Character::update_xp( $id, $earned, $unspent );
		return $id;
	}

	/** @return array<string,mixed> */
	private function vampire_sheet(): array {
		return [
			'vampire-identity' => [ 'Clan' => 'Tremere' ],
			'met-abilities'    => [
				[ 'name' => 'Brawl', 'count' => 3 ],
				[ 'name' => 'Lore: Kindred', 'count' => 2, 'custom' => true ],
				[ 'name' => 'Basket Weaving', 'count' => 1, 'custom' => true ],
			],
			'met-merits'       => [ [ 'name' => 'Iron Will' ] ],
		];
	}

	/** @return array<int,array{xp_earned:string,xp_unspent:string}> Every character's raw XP columns. */
	private function xp_columns(): array {
		$out = [];
		foreach ( Manager::get_results( 'SELECT id, xp_earned, xp_unspent FROM ' . Manager::table( 'characters' ) . ' ORDER BY id' ) as $row ) {
			$out[ (int) $row->id ] = [ 'xp_earned' => (string) $row->xp_earned, 'xp_unspent' => (string) $row->xp_unspent ];
		}
		return $out;
	}

	/** @return array<int,string> Every character's sheet as stored, by id. */
	private function sheets(): array {
		$out = [];
		foreach ( Manager::get_results( 'SELECT id, sheet_data FROM ' . Manager::table( 'characters' ) . ' ORDER BY id' ) as $row ) {
			$out[ (int) $row->id ] = (string) $row->sheet_data;
		}
		return $out;
	}

	private function rekey_changes( int $character_id ): array {
		return array_values( array_filter(
			Change::for_character( $character_id ),
			static fn( $change ) => $change->change_type === 'catalog_rekey'
		) );
	}

	public function test_the_shipped_demo_characters_need_nothing_moved(): void {
		// The 22 demo characters already hold each creature type's own blocks: a plan finds nothing to lose or change.
		$fixtures = Seeder::demo_fixtures();
		foreach ( $fixtures as $fixture ) {
			Character::create( [
				'name' => $fixture['name'], 'stack_slug' => $fixture['stack_slug'], 'owner_type' => 'chronicle',
				'owner_slug' => $this->game, 'status' => 'active', 'sheet_data' => $fixture['sheet_data'],
			] );
		}

		$plan = Catalog_Cutover::plan( $this->game );
		$this->assertSame( [], $plan['retention_gaps'] );
		$this->assertSame( count( $fixtures ), $plan['characters'] );
		$this->assertSame( 0, $plan['characters_changed'] );

		$result = Catalog_Cutover::apply( $this->actor );

		$this->assertSame( 'applied', $result['status'] );
		$this->assertSame( 0, $result['characters_rekeyed'] );
		$this->assertSame( count( $fixtures ), $result['characters_unchanged'] );
	}

	public function test_apply_rekeys_the_install_and_flips_it(): void {
		$original = $this->vampire_sheet();
		$id       = $this->character( 'vampire', 'Apply Vampire', $original );

		$result = Catalog_Cutover::apply( $this->actor );

		$this->assertSame( 'applied', $result['status'] );
		$this->assertTrue( Catalog_Cutover::is_declared() );

		$sheet = Character::find( $id )->sheet_data;
		$this->assertArrayNotHasKey( 'met-abilities', $sheet );
		$this->assertArrayNotHasKey( 'met-merits', $sheet );
		$this->assertSame( [ 'Brawl', 'Lore', 'Basket Weaving' ], array_column( $sheet['vampire-abilities'], 'name' ) );
		$this->assertSame( 'Kindred', $sheet['vampire-abilities'][1]['specialization'] );
		$this->assertSame( 'Lore: Kindred', $sheet['vampire-abilities'][1]['rekeyed_from'] );
		$this->assertArrayNotHasKey( 'custom', $sheet['vampire-abilities'][1] );
		$this->assertTrue( $sheet['vampire-abilities'][2]['custom'], 'a row nothing could match stays custom, untouched' );
		$this->assertSame( [ [ 'name' => 'Iron Will' ] ], $sheet['vampire-merits'] );
		$this->assertSame( [ 'Clan' => 'Tremere' ], $sheet['vampire-identity'] );
	}

	public function test_each_rekeyed_character_has_one_approved_zero_xp_record_and_a_pre_image_snapshot(): void {
		$original = $this->vampire_sheet();
		$id       = $this->character( 'vampire', 'Apply Vampire', $original );

		Catalog_Cutover::apply( $this->actor );

		$changes = $this->rekey_changes( $id );
		$this->assertCount( 1, $changes );
		$change = $changes[0];
		$this->assertSame( 'approved', $change->status );
		$this->assertSame( 0.0, (float) $change->xp_cost );
		$this->assertSame( $this->actor, (int) $change->submitted_by );
		$this->assertSame( $this->actor, (int) $change->reviewed_by );

		$this->assertSame( Catalog_Cutover::hash_value( $original ), $change->change_data['before_hash'] );
		$this->assertSame( Catalog_Cutover::hash_value( Character::find( $id )->sheet_data ), $change->change_data['after_hash'] );
		$this->assertSame( 4, $change->change_data['counts']['moved_rows'] );

		$snapshots = array_filter( Snapshot::for_character( $id ), static fn( $s ) => (int) $s->change_id === (int) $change->id );
		$this->assertCount( 1, $snapshots );
		$this->assertSame( Catalog_Cutover::hash_value( $original ), Catalog_Cutover::hash_value( array_values( $snapshots )[0]->snapshot_data ), 'the snapshot is the sheet as it was BEFORE the write' );
	}

	public function test_xp_columns_are_byte_identical_for_every_character(): void {
		$this->character( 'vampire', 'Apply Vampire', $this->vampire_sheet(), 90, 13 );
		$before = $this->xp_columns();

		Catalog_Cutover::apply( $this->actor );

		$this->assertSame( $before, $this->xp_columns() );
	}

	public function test_stacks_and_templates_follow_the_flip(): void {
		Catalog_Cutover::apply( $this->actor );

		$sections = array_column( (array) Creature_Stack::find_by_slug( 'vampire' )->stack_definition->sections, 'block_slug' );
		$this->assertContains( 'vampire-abilities', $sections );
		$this->assertNotContains( 'met-abilities', $sections );

		$layout = array_column( Template::resolve( 'vampire', 'sheet_full', null )->layout['sections'], 'block_slug' );
		$this->assertContains( 'vampire-abilities', $layout );
		$this->assertNotContains( 'met-abilities', $layout );
	}

	public function test_the_genuinely_new_declared_sections_reach_the_system_templates(): void {
		// Demon's declared stack carries evocations and rituals.
		$sections = static fn() => array_column( Template::resolve( 'demon', 'sheet_full', null )->layout['sections'], 'block_slug' );
		$this->assertNotContains( 'demon-evocations', $sections() );

		Catalog_Cutover::apply( $this->actor );

		$this->assertContains( 'demon-evocations', $sections() );
		$this->assertContains( 'demon-rituals', $sections() );
	}

	public function test_each_moved_character_is_recorded_and_the_lock_is_released(): void {
		$id = $this->character( 'vampire', 'Apply Vampire', $this->vampire_sheet() );

		Catalog_Cutover::apply( $this->actor );

		$rekeys = $this->rekey_changes( $id );
		$this->assertCount( 1, $rekeys );
		$this->assertSame( $this->actor, (int) $rekeys[0]->submitted_by );
		$this->assertTrue( Option_Lock::claim( Catalog_Cutover::LOCK, 60 ), 'the lock is free again' );
	}

	public function test_a_second_apply_is_a_no_op(): void {
		$id = $this->character( 'vampire', 'Apply Vampire', $this->vampire_sheet() );
		Catalog_Cutover::apply( $this->actor );
		$sheets = $this->sheets();

		$second = Catalog_Cutover::apply( $this->actor );

		$this->assertSame( 'already_declared', $second['status'] );
		$this->assertSame( 0, $second['templates_rewritten'] );
		$this->assertSame( 0, $second['pending_rewritten'] );
		$this->assertSame( $sheets, $this->sheets() );
		$this->assertCount( 1, $this->rekey_changes( $id ) );
	}

	public function test_pending_changes_are_rewritten(): void {
		$id      = $this->character( 'vampire', 'Apply Vampire', $this->vampire_sheet() );
		$pending = Change::create( [
			'character_id' => $id, 'change_type' => 'add_trait', 'category' => 'met-merits',
			'change_data'  => [ 'block_slug' => 'met-merits', 'trait' => [ 'name' => 'Iron Will' ] ], 'submitted_by' => $this->actor,
		] );

		Catalog_Cutover::apply( $this->actor );

		$this->assertSame( 'vampire-merits', Change::find( $pending )->change_data['block_slug'] );
		$this->assertSame( 'pending', Change::find( $pending )->status );
	}

	public function test_a_character_changed_between_plan_and_apply_is_replanned_from_a_fresh_read(): void {
		$id     = $this->character( 'vampire', 'Racing Vampire', $this->vampire_sheet() );
		$racing = function ( int $character_id ) use ( $id ): void {
			if ( $character_id !== $id ) {
				return;
			}
			$sheet                    = Character::find( $id )->sheet_data;
			$sheet['met-abilities'][] = [ 'name' => 'occult', 'count' => 2, 'custom' => true ]; // Arrives after the install-wide plan.
			Character::update_sheet_data( $id, $sheet );
		};

		$result = Catalog_Cutover::apply( $this->actor, [ 'on_character' => $racing ] );

		$this->assertSame( 'applied', $result['status'] );
		$names = array_column( Character::find( $id )->sheet_data['vampire-abilities'], 'name' );
		$this->assertContains( 'Occult', $names, 'the late row was seen, matched and moved - not lost' );
		$this->assertCount( 4, $names, 'Brawl, Lore, Basket Weaving and the late Occult - nothing else was lost' );
	}

	public function test_a_retention_gap_refuses_the_whole_install_and_writes_nothing(): void {
		$good = $this->character( 'vampire', 'Good Vampire', $this->vampire_sheet() );
		$this->character( 'vampire', 'Gap Vampire', [ 'met-abilities' => [ [ 'name' => 'Definitely Not An Ability' ] ] ] );
		$sheets = $this->sheets();

		$result = Catalog_Cutover::apply( $this->actor );

		$this->assertSame( 'refused', $result['status'] );
		$this->assertSame( 'retention_gaps', $result['reason'] );
		$this->assertSame( 'Definitely Not An Ability', $result['retention_gaps'][0]['name'] );
		$this->assertFalse( Catalog_Cutover::is_declared() );
		$this->assertSame( $sheets, $this->sheets(), 'not even the good character was touched' );
		$this->assertSame( [], $this->rekey_changes( $good ) );
		$this->assertTrue( Option_Lock::claim( Catalog_Cutover::LOCK, 60 ), 'a refusal releases the lock' );
	}

	public function test_a_catalog_row_spelled_another_way_is_respelled_and_only_its_spelling_changes(): void {
		$original = [
			'met-abilities' => [
				[ 'name' => 'Fortune-telling', 'count' => 2, 'note' => 'kept', 'temp' => 1 ],
				[ 'name' => 'Meditiation', 'count' => 3 ],
			],
			'met-flaws'     => [ [ 'name' => 'Light Sensitive' ] ],
		];
		$id       = $this->character( 'vampire', 'Spelling Vampire', $original, 90, 13 );
		$xp       = $this->xp_columns();

		$result = Catalog_Cutover::apply( $this->actor );

		$this->assertSame( 'applied', $result['status'] );
		$this->assertSame( 3, $result['rows_respelled'] );
		$this->assertSame( 0, $result['rows_rekeyed'] );

		// assertEquals: MySQL's JSON column returns an object's keys in its own order.
		$sheet = Character::find( $id )->sheet_data;
		$this->assertEquals(
			[
				[ 'name' => 'Fortune-Telling', 'count' => 2, 'note' => 'kept', 'temp' => 1, 'rekeyed_from' => 'Fortune-telling' ],
				[ 'name' => 'Meditation', 'count' => 3, 'rekeyed_from' => 'Meditiation' ],
			],
			$sheet['vampire-abilities']
		);
		$this->assertEquals( [ [ 'name' => 'Light-Sensitive', 'rekeyed_from' => 'Light Sensitive' ] ], $sheet['vampire-flaws'] );
		$this->assertSame( $xp, $this->xp_columns(), 'a spelling correction never touches XP' );

		$change = $this->rekey_changes( $id )[0];
		$this->assertSame( 3, $change->change_data['counts']['respelled'] );
		$this->assertSame( 0, $change->change_data['counts']['rekeyed'] );
		$this->assertSame( 0.0, (float) $change->xp_cost );
		$this->assertSame( [ true, true, true ], array_column( $change->change_data['records'], 'catalog' ) );
	}

	public function test_a_respelling_that_would_double_a_held_row_refuses_the_install_and_merges_nothing(): void {
		$rows = [ [ 'name' => 'Fortune-Telling', 'count' => 1 ], [ 'name' => 'Fortune-telling', 'count' => 2 ] ];
		$this->character( 'vampire', 'Doubled Vampire', [ 'met-abilities' => $rows ] );
		$sheets = $this->sheets();

		$result = Catalog_Cutover::apply( $this->actor );

		$this->assertSame( 'refused', $result['status'] );
		$this->assertSame( 'retention_gaps', $result['reason'] );
		$this->assertSame( 'collision', $result['retention_gaps'][0]['reason'] );
		$this->assertSame( $sheets, $this->sheets() );
		$this->assertFalse( Catalog_Cutover::is_declared() );
	}

	public function test_a_character_deleted_after_it_was_listed_is_not_a_failure(): void {
		$keep   = $this->character( 'vampire', 'Aaron Keep', $this->vampire_sheet() );
		$vanish = $this->character( 'vampire', 'Zed Vanishes', $this->vampire_sheet() );
		$delete = static function ( int $id ) use ( $vanish ): void {
			if ( $id === $vanish ) {
				Character::delete( $vanish );
			}
		};

		$result = Catalog_Cutover::apply( $this->actor, [ 'on_character' => $delete ] );

		$this->assertSame( 'applied', $result['status'], 'a character that no longer exists has nothing to lose' );
		$this->assertSame( 1, $result['characters_rekeyed'] );
		$this->assertArrayHasKey( 'vampire-abilities', Character::find( $keep )->sheet_data );
	}

	public function test_a_character_outside_any_chronicle_refuses_rather_than_being_flipped_past(): void {
		$this->character( 'vampire', 'Apply Vampire', $this->vampire_sheet() );
		Character::create( [
			'name' => 'Orphan', 'stack_slug' => 'vampire', 'owner_type' => 'chronicle',
			'owner_slug' => 'a-chronicle-that-was-deleted', 'status' => 'active', 'sheet_data' => $this->vampire_sheet(),
		] );
		$sheets = $this->sheets();

		$result = Catalog_Cutover::apply( $this->actor );

		$this->assertSame( 'refused', $result['status'] );
		$this->assertSame( 'characters_outside_any_chronicle', $result['reason'] );
		$this->assertSame( $result['planned'] + 1, $result['on_install'] );
		$this->assertSame( $sheets, $this->sheets() );
		$this->assertFalse( Catalog_Cutover::is_declared() );
	}

	public function test_a_held_lock_refuses_and_is_left_held(): void {
		$this->character( 'vampire', 'Apply Vampire', $this->vampire_sheet() );
		$sheets = $this->sheets();
		$this->assertTrue( Option_Lock::claim( Catalog_Cutover::LOCK, 600 ) );

		$result = Catalog_Cutover::apply( $this->actor );

		$this->assertSame( 'locked', $result['status'] );
		$this->assertSame( $sheets, $this->sheets() );
		$this->assertFalse( Catalog_Cutover::is_declared() );
		$this->assertFalse( Option_Lock::claim( Catalog_Cutover::LOCK, 600 ), 'someone else\'s lock is not apply()\'s to release' );
	}

	public function test_no_declared_catalog_refuses(): void {
		$this->character( 'vampire', 'Apply Vampire', $this->vampire_sheet() );
		$sheets  = $this->sheets();
		$without = new class() extends Catalog_Cutover {
			protected static function catalog_available(): bool {
				return false;
			}
		};

		$result = $without::apply( $this->actor );

		$this->assertSame( [ 'status' => 'refused', 'reason' => 'no_declared_catalog' ], $result );
		$this->assertSame( $sheets, $this->sheets() );
		$this->assertFalse( Catalog_Cutover::is_declared() );
	}

	public function test_a_declared_stack_naming_a_block_the_database_lacks_refuses(): void {
		$this->character( 'vampire', 'Apply Vampire', $this->vampire_sheet() );
		Manager::delete( 'schema_blocks', [ 'slug' => 'vampire-abilities', 'game_slug' => '' ] );

		$result = Catalog_Cutover::apply( $this->actor );

		$this->assertSame( 'refused', $result['status'] );
		$this->assertSame( 'declared_blocks_missing', $result['reason'] );
		$this->assertContains( 'vampire -> vampire-abilities', $result['missing'] );
		$this->assertFalse( Catalog_Cutover::is_declared() );
	}

	public function test_a_character_that_cannot_be_rekeyed_leaves_the_install_unflipped_and_apply_resumes(): void {
		$early = $this->character( 'vampire', 'Aaron Early', $this->vampire_sheet() );
		$late  = $this->character( 'vampire', 'Zed Late', $this->vampire_sheet() );

		// A catalog row the replacement lacks turns up on one sheet after the install-wide plan.
		$poison = function ( int $character_id ) use ( $late ): void {
			if ( $character_id !== $late ) {
				return;
			}
			$sheet                    = Character::find( $late )->sheet_data;
			$sheet['met-abilities'][] = [ 'name' => 'Definitely Not An Ability' ];
			Character::update_sheet_data( $late, $sheet );
		};

		$first = Catalog_Cutover::apply( $this->actor, [ 'on_character' => $poison ] );

		$this->assertSame( 'partial', $first['status'] );
		$this->assertArrayHasKey( $late, $first['failed'] );
		$this->assertSame( 'retention_gap', $first['failed'][ $late ]['reason'] );
		$this->assertFalse( Catalog_Cutover::is_declared(), 'a stranded character would show empty Abilities on a declared sheet' );
		$this->assertCount( 1, $this->rekey_changes( $early ), 'the character that could be re-keyed is done' );
		$this->assertSame( [], $this->rekey_changes( $late ), 'the failed one rolled back entirely' );

		// Fix the sheet and run it again: it picks up where it stopped.
		$sheet                = Character::find( $late )->sheet_data;
		$sheet['met-abilities'] = array_values( array_filter( $sheet['met-abilities'], static fn( $r ) => $r['name'] !== 'Definitely Not An Ability' ) );
		Character::update_sheet_data( $late, $sheet );

		$second = Catalog_Cutover::apply( $this->actor );

		$this->assertSame( 'applied', $second['status'] );
		$this->assertTrue( Catalog_Cutover::is_declared() );
		$this->assertCount( 1, $this->rekey_changes( $early ), 'the first character is not re-keyed a second time' );
		$this->assertCount( 1, $this->rekey_changes( $late ) );
	}
}
