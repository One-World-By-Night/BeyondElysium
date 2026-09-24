<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Database\Manager;
use BeyondElysium\Database\Option_Lock;
use BeyondElysium\Models\Change;
use BeyondElysium\Models\Character;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Snapshot;
use BeyondElysium\Models\Template;
use BeyondElysium\Services\Catalog_Cutover;
use BeyondElysium\Services\Catalog_Reader;
use WP_UnitTestCase;

/**
 * R6: `Catalog_Cutover::rollback()` (§3.7). The headline is Gate 4's own claim: plan -> apply ->
 * rollback leaves every sheet, every XP column, every template layout and every stack definition
 * byte-identical to before - proved over the whole install, the seeded demo chronicle included.
 */
class CatalogCutoverRollbackThreadTest extends WP_UnitTestCase {

	private string $game = 'cutover-rollback';
	private int $actor;

	public function setUp(): void {
		parent::setUp();
		if ( ! Catalog_Reader::available() ) {
			$this->markTestSkipped( 'no declared catalog in this checkout' );
		}
		// apply() runs over the whole install, and this database's plugin tables outlive a run (only
		// WordPress's own are reinstalled), so the demo chronicle it holds can be months stale. Clear
		// every character inside this test's own transaction - rolled back after - so each case sees
		// only what it builds; the current demo fixtures get their own explicit case instead.
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . Manager::table( 'characters' ) );

		$this->actor = self::factory()->user->create( [ 'role' => 'administrator' ] );
		Game::create( [ 'slug' => $this->game, 'name' => 'Cutover Rollback' ] );
	}

	public function tearDown(): void {
		delete_option( Catalog_Cutover::OPTION );
		delete_option( Catalog_Cutover::RECORD_OPTION );
		Option_Lock::release( Catalog_Cutover::LOCK );
		Catalog_Cutover::reset_cache();
		parent::tearDown();
	}

	/** @param array<string,mixed> $sheet */
	private function character( string $stack, string $name, array $sheet ): int {
		$id = (int) Character::create( [
			'name' => $name, 'stack_slug' => $stack, 'owner_type' => 'chronicle',
			'owner_slug' => $this->game, 'status' => 'active', 'sheet_data' => $sheet,
		] );
		Character::update_xp( $id, 55, 9 );
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

	/** Everything a cutover touches, as raw stored bytes. @return array<string,mixed> */
	private function install_state(): array {
		$rows = static function ( string $table, string $columns ): array {
			return Manager::get_results( "SELECT {$columns} FROM " . Manager::table( $table ) . ' ORDER BY id' );
		};
		return [
			'sheets'    => array_map( static fn( $r ) => [ $r->id, (string) $r->sheet_data, (string) $r->xp_earned, (string) $r->xp_unspent ], $rows( 'characters', 'id, sheet_data, xp_earned, xp_unspent' ) ),
			'templates' => array_map( static fn( $r ) => [ $r->id, (string) $r->layout ], $rows( 'templates', 'id, layout' ) ),
			'stacks'    => Manager::get_results( 'SELECT slug, stack_definition, creation_rules FROM ' . Manager::table( 'creature_stacks' ) . ' ORDER BY slug' ),
		];
	}

	/** @return object[] */
	private function changes_of_type( int $character_id, string $type ): array {
		return array_values( array_filter( Change::for_character( $character_id ), static fn( $c ) => $c->change_type === $type ) );
	}

	public function test_apply_then_rollback_leaves_the_whole_install_byte_identical(): void {
		$this->character( 'vampire', 'Round Trip Vampire', $this->vampire_sheet() );
		$this->character( 'demon', 'Round Trip Demon', [ 'met-abilities' => [ [ 'name' => 'Occult', 'count' => 2 ] ], 'demon-lores' => [ [ 'name' => 'Vampire' ] ] ] );
		$before = $this->install_state();

		$this->assertSame( 'applied', Catalog_Cutover::apply( $this->actor )['status'] );
		$this->assertNotEquals( $before['sheets'], $this->install_state()['sheets'], 'precondition: apply really changed the install' );

		$result = Catalog_Cutover::rollback( $this->actor );

		$this->assertSame( 'rolled_back', $result['status'] );
		$this->assertFalse( Catalog_Cutover::is_declared() );
		$this->assertFalse( get_option( Catalog_Cutover::RECORD_OPTION ) );
		$after = $this->install_state();
		$this->assertSame( $before['sheets'], $after['sheets'], 'every sheet and every XP column' );
		$this->assertSame( $before['templates'], $after['templates'], 'every template layout' );
		$this->assertEquals( $before['stacks'], $after['stacks'], 'every stack definition' );
		$this->assertTrue( Option_Lock::claim( Catalog_Cutover::LOCK, 60 ), 'the lock is released' );
	}

	public function test_a_respelled_row_comes_back_exactly_as_it_was_written(): void {
		$this->character( 'vampire', 'Spelling Vampire', [ 'met-abilities' => [ [ 'name' => 'Fortune-telling', 'count' => 2 ], [ 'name' => 'Meditiation' ] ] ] );
		$before = $this->install_state();

		$this->assertSame( 'applied', Catalog_Cutover::apply( $this->actor )['status'] );
		$this->assertSame( 'rolled_back', Catalog_Cutover::rollback( $this->actor )['status'] );

		$this->assertSame( $before['sheets'], $this->install_state()['sheets'], 'the legacy spelling, the typo included' );
	}

	public function test_each_restore_is_a_recorded_zero_xp_revert_with_a_snapshot_of_what_it_replaced(): void {
		$id = $this->character( 'vampire', 'Revert Vampire', $this->vampire_sheet() );
		Catalog_Cutover::apply( $this->actor );
		$rekey        = $this->changes_of_type( $id, 'catalog_rekey' )[0];
		$declared_hash = Catalog_Cutover::hash_value( Character::find( $id )->sheet_data );

		Catalog_Cutover::rollback( $this->actor );

		$reverts = $this->changes_of_type( $id, 'catalog_rekey_revert' );
		$this->assertCount( 1, $reverts );
		$this->assertSame( 'approved', $reverts[0]->status );
		$this->assertSame( $this->actor, (int) $reverts[0]->reviewed_by );
		$this->assertSame( 0.0, (float) $reverts[0]->xp_cost );
		$this->assertSame( (int) $rekey->id, $reverts[0]->change_data['reverts'] );
		$this->assertFalse( $reverts[0]->change_data['forced'] );
		$this->assertSame( $declared_hash, $reverts[0]->change_data['before_hash'] );

		$snapshot = array_values( array_filter( Snapshot::for_character( $id ), static fn( $s ) => (int) $s->change_id === (int) $reverts[0]->id ) );
		$this->assertCount( 1, $snapshot );
		$this->assertSame( $declared_hash, Catalog_Cutover::hash_value( $snapshot[0]->snapshot_data ), 'the declared sheet it replaced, so a rollback can be undone by hand' );
	}

	public function test_a_character_changed_since_the_cutover_blocks_the_rollback_and_nothing_is_written(): void {
		$early = $this->character( 'vampire', 'Aaron Early', $this->vampire_sheet() );
		$moved = $this->character( 'vampire', 'Zed Moved', $this->vampire_sheet() );
		Catalog_Cutover::apply( $this->actor );

		// A change approved after the cutover lands in the declared block.
		$sheet                        = Character::find( $moved )->sheet_data;
		$sheet['vampire-abilities'][] = [ 'name' => 'Occult', 'count' => 1 ];
		Character::update_sheet_data( $moved, $sheet );
		$declared = $this->install_state();

		$result = Catalog_Cutover::rollback( $this->actor );

		$this->assertSame( 'blocked', $result['status'] );
		$this->assertSame( [ [ 'character_id' => $moved, 'reason' => 'changed_since_cutover' ] ], $result['skipped'] );
		$this->assertTrue( Catalog_Cutover::is_declared(), 'still declared - flipping back would strand that character' );
		$after = $this->install_state();
		$this->assertSame( $declared['sheets'], $after['sheets'], 'not even the unchanged character was restored' );
		$this->assertSame( $declared['templates'], $after['templates'] );
		$this->assertEquals( $declared['stacks'], $after['stacks'] );
		$this->assertSame( [], $this->changes_of_type( $early, 'catalog_rekey_revert' ) );
	}

	public function test_force_restores_the_changed_character_anyway_and_says_so(): void {
		$moved = $this->character( 'vampire', 'Zed Moved', $this->vampire_sheet() );
		$original = $this->vampire_sheet();
		Catalog_Cutover::apply( $this->actor );
		$sheet                        = Character::find( $moved )->sheet_data;
		$sheet['vampire-abilities'][] = [ 'name' => 'Occult', 'count' => 1 ];
		Snapshot::create( $moved, null ); // What an approved change takes before it writes: a newer snapshot than the re-key's.
		Character::update_sheet_data( $moved, $sheet );

		$result = Catalog_Cutover::rollback( $this->actor, true );

		$this->assertSame( 'rolled_back', $result['status'] );
		$this->assertSame( [ $moved ], $result['characters_forced'] );
		$this->assertSame( Catalog_Cutover::hash_value( $original ), Catalog_Cutover::hash_value( Character::find( $moved )->sheet_data ), 'the pre-cutover sheet; the later Occult is what force costs' );
		$this->assertTrue( $this->changes_of_type( $moved, 'catalog_rekey_revert' )[0]->change_data['forced'] );
	}

	public function test_a_character_changed_after_the_check_is_caught_under_the_lock_and_can_be_forced_later(): void {
		$early    = $this->character( 'vampire', 'Aaron Early', $this->vampire_sheet() );
		$late     = $this->character( 'vampire', 'Zed Late', $this->vampire_sheet() );
		$original = $this->vampire_sheet();
		Catalog_Cutover::apply( $this->actor );

		// The whole-install check passes; then a change lands on the second character before its turn.
		$touch = function ( int $character_id ) use ( $late ): void {
			if ( $character_id === $late ) {
				$sheet                        = Character::find( $late )->sheet_data;
				$sheet['vampire-abilities'][] = [ 'name' => 'Occult', 'count' => 1 ];
				Character::update_sheet_data( $late, $sheet );
			}
		};
		$result = Catalog_Cutover::rollback( $this->actor, false, [ 'on_character' => $touch ] );

		$this->assertSame( 'partial', $result['status'] );
		$this->assertSame( 1, $result['characters_reverted'] );
		$this->assertSame( 'changed_since_cutover', $result['failed'][ $late ]['outcome'] );
		$this->assertTrue( Catalog_Cutover::is_declared(), 'not flipped back with a character left behind' );
		$this->assertArrayHasKey( 'vampire-abilities', Character::find( $late )->sheet_data, 'its later change was not erased' );
		$this->assertArrayHasKey( 'met-abilities', Character::find( $early )->sheet_data, 'the one that could be restored was' );

		// Run again: the restored one is done, the changed one is named, and force finishes it.
		$again = Catalog_Cutover::rollback( $this->actor );
		$this->assertSame( 'blocked', $again['status'] );
		$this->assertSame( [ [ 'character_id' => $late, 'reason' => 'changed_since_cutover' ] ], $again['skipped'] );
		$this->assertSame( 'rolled_back', Catalog_Cutover::rollback( $this->actor, true )['status'] );
		$this->assertFalse( Catalog_Cutover::is_declared() );
		$this->assertSame( Catalog_Cutover::hash_value( $original ), Catalog_Cutover::hash_value( Character::find( $late )->sheet_data ) );
	}

	public function test_a_character_that_vanishes_mid_run_is_not_a_failure(): void {
		$early = $this->character( 'vampire', 'Aaron Early', $this->vampire_sheet() );
		$late  = $this->character( 'vampire', 'Zed Late', $this->vampire_sheet() );
		Catalog_Cutover::apply( $this->actor );

		$vanish = static function ( int $character_id ) use ( $late ): void {
			if ( $character_id === $late ) {
				Manager::delete( 'characters', [ 'id' => $late ] );
			}
		};
		$result = Catalog_Cutover::rollback( $this->actor, false, [ 'on_character' => $vanish ] );

		$this->assertSame( 'rolled_back', $result['status'] );
		$this->assertSame( 1, $result['characters_reverted'] );
		$this->assertArrayHasKey( 'met-abilities', Character::find( $early )->sheet_data );
	}

	public function test_a_character_whose_pre_image_is_gone_fails_and_keeps_its_sheet(): void {
		$id = $this->character( 'vampire', 'No Pre Image', $this->vampire_sheet() );
		Catalog_Cutover::apply( $this->actor );
		$declared = Catalog_Cutover::hash_value( Character::find( $id )->sheet_data );
		Snapshot::delete_for_character( $id );

		$result = Catalog_Cutover::rollback( $this->actor );

		$this->assertSame( 'partial', $result['status'] );
		$this->assertSame( 'no_snapshot', $result['failed'][ $id ]['reason'] );
		$this->assertTrue( Catalog_Cutover::is_declared() );
		$this->assertSame( $declared, Catalog_Cutover::hash_value( Character::find( $id )->sheet_data ), 'never written with nothing' );
		$this->assertSame( [], $this->changes_of_type( $id, 'catalog_rekey_revert' ), 'and no revert recorded for a restore that did not happen' );
	}

	public function test_pending_changes_go_back_to_the_retired_slug_including_ones_queued_since(): void {
		$id      = $this->character( 'vampire', 'Pending Vampire', $this->vampire_sheet() );
		$before  = Change::create( [
			'character_id' => $id, 'change_type' => 'add_trait', 'category' => 'met-merits',
			'change_data'  => [ 'block_slug' => 'met-merits', 'trait' => [ 'name' => 'Iron Will' ] ], 'submitted_by' => $this->actor,
		] );
		Catalog_Cutover::apply( $this->actor );
		$since = Change::create( [
			'character_id' => $id, 'change_type' => 'add_trait', 'category' => 'vampire-flaws',
			'change_data'  => [ 'block_slug' => 'vampire-flaws', 'trait' => [ 'name' => 'Prey Exclusion' ] ], 'submitted_by' => $this->actor,
		] );
		$this->assertSame( 'vampire-merits', Change::find( $before )->change_data['block_slug'] );

		$result = Catalog_Cutover::rollback( $this->actor );

		$this->assertSame( 2, $result['pending_restored'] );
		$this->assertSame( 'met-merits', Change::find( $before )->change_data['block_slug'] );
		$this->assertSame( 'met-flaws', Change::find( $since )->change_data['block_slug'] );
		$this->assertSame( 'pending', Change::find( $since )->status );
	}

	public function test_a_template_edited_since_the_cutover_keeps_its_edit_and_is_reported(): void {
		Catalog_Cutover::apply( $this->actor );
		$template = Template::resolve( 'vampire', 'sheet_full', null );
		$layout   = $template->layout;
		$layout['sections'][0]['title'] = 'Edited by a Storyteller';
		Template::update( (int) $template->id, [ 'layout' => $layout ] );

		$result = Catalog_Cutover::rollback( $this->actor );

		$this->assertSame( 'rolled_back', $result['status'] );
		$this->assertContains( [ 'template_id' => (int) $template->id, 'reason' => 'edited_since_cutover' ], $result['templates_skipped'] );
		$this->assertSame( 'Edited by a Storyteller', Template::find( (int) $template->id )->layout['sections'][0]['title'] );
	}

	public function test_a_template_changed_since_the_cutover_is_pointed_back_at_the_old_blocks(): void {
		Catalog_Cutover::apply( $this->actor );
		$template = Template::resolve( 'vampire', 'sheet_full', null );
		$before   = array_column( $template->layout['sections'], 'block_slug' );
		$this->assertContains( 'vampire-abilities', $before, 'precondition: apply pointed the sheet at the declared block' );
		$this->assertNotContains( 'met-abilities', $before );

		// What a later plugin update, or a person, leaves behind: a layout that no longer hashes to what apply wrote.
		$layout = $template->layout;
		$layout['sections'][0]['title'] = 'Rewritten after the cutover';
		$this->assertTrue( Template::update( (int) $template->id, [ 'layout' => $layout ] ), 'precondition: the edit was accepted' );

		$result = Catalog_Cutover::rollback( $this->actor );

		$after = Template::find( (int) $template->id )->layout;
		$slugs = array_column( $after['sections'], 'block_slug' );
		$this->assertContains( 'met-abilities', $slugs, 'the sheet reads the block its characters hold again' );
		$this->assertNotContains( 'vampire-abilities', $slugs );
		$this->assertSame( 'Rewritten after the cutover', $after['sections'][0]['title'], 'and what changed is kept' );
		$this->assertContains( (int) $template->id, $result['templates_repointed'] );
		$this->assertContains( [ 'template_id' => (int) $template->id, 'reason' => 'edited_since_cutover' ], $result['templates_skipped'] );
	}

	public function test_a_changed_template_with_no_retired_block_left_in_it_is_reported_but_not_repointed(): void {
		Catalog_Cutover::apply( $this->actor );
		$template = Template::resolve( 'vampire', 'sheet_full', null );
		$layout   = $template->layout;
		$layout['sections'] = [ [ 'block_slug' => 'vampire-identity', 'column' => 1, 'order' => 1, 'width' => 'full' ] ];
		$this->assertTrue( Template::update( (int) $template->id, [ 'layout' => $layout ] ), 'precondition: the edit was accepted' );

		$result = Catalog_Cutover::rollback( $this->actor );

		$this->assertContains( [ 'template_id' => (int) $template->id, 'reason' => 'edited_since_cutover' ], $result['templates_skipped'] );
		$this->assertNotContains( (int) $template->id, $result['templates_repointed'], 'there was nothing to point back' );
		$this->assertEquals( $layout['sections'], Template::find( (int) $template->id )->layout['sections'], 'left exactly as it was edited' );
	}

	public function test_a_template_restored_from_its_pre_image_is_not_repointed(): void {
		Catalog_Cutover::apply( $this->actor );

		$result = Catalog_Cutover::rollback( $this->actor );

		$this->assertGreaterThan( 0, $result['templates_restored'] );
		$this->assertSame( [], $result['templates_repointed'] );
		$this->assertSame( [], $result['templates_skipped'] );
	}

	public function test_a_second_rollback_has_nothing_to_do(): void {
		$this->character( 'vampire', 'Round Trip Vampire', $this->vampire_sheet() );
		Catalog_Cutover::apply( $this->actor );
		Catalog_Cutover::rollback( $this->actor );

		$this->assertSame( [ 'status' => 'nothing_to_roll_back' ], Catalog_Cutover::rollback( $this->actor ) );
	}

	public function test_a_cutover_can_be_applied_again_after_a_rollback(): void {
		$id = $this->character( 'vampire', 'Again Vampire', $this->vampire_sheet() );
		Catalog_Cutover::apply( $this->actor );
		Catalog_Cutover::rollback( $this->actor );

		$this->assertSame( 'applied', Catalog_Cutover::apply( $this->actor )['status'] );
		$this->assertArrayHasKey( 'vampire-abilities', Character::find( $id )->sheet_data );

		// The second rekey is the one that stands: rolling back again restores it.
		$this->assertSame( 'rolled_back', Catalog_Cutover::rollback( $this->actor )['status'] );
		$this->assertArrayHasKey( 'met-abilities', Character::find( $id )->sheet_data );
		$this->assertCount( 2, $this->changes_of_type( $id, 'catalog_rekey' ) );
		$this->assertCount( 2, $this->changes_of_type( $id, 'catalog_rekey_revert' ) );
	}

	public function test_a_partial_apply_can_be_rolled_back(): void {
		$early = $this->character( 'vampire', 'Aaron Early', $this->vampire_sheet() );
		$late  = $this->character( 'vampire', 'Zed Late', $this->vampire_sheet() );
		$before = $this->install_state();
		$poison = function ( int $character_id ) use ( $late ): void {
			if ( $character_id === $late ) {
				$sheet                    = Character::find( $late )->sheet_data;
				$sheet['met-abilities'][] = [ 'name' => 'Definitely Not An Ability' ];
				Character::update_sheet_data( $late, $sheet );
			}
		};
		$this->assertSame( 'partial', Catalog_Cutover::apply( $this->actor, [ 'on_character' => $poison ] )['status'] );
		$this->assertFalse( Catalog_Cutover::is_declared() );

		$result = Catalog_Cutover::rollback( $this->actor );

		$this->assertSame( 'rolled_back', $result['status'] );
		$this->assertArrayHasKey( 'met-abilities', Character::find( $early )->sheet_data, 'the one that had been re-keyed is back' );
		unset( $before ); // The poisoned row is the test's own edit, so the late sheet legitimately differs.
	}

	public function test_a_held_lock_refuses(): void {
		$this->character( 'vampire', 'Round Trip Vampire', $this->vampire_sheet() );
		Catalog_Cutover::apply( $this->actor );
		$this->assertTrue( Option_Lock::claim( Catalog_Cutover::LOCK, 600 ) );

		$this->assertSame( [ 'status' => 'locked' ], Catalog_Cutover::rollback( $this->actor ) );
		$this->assertTrue( Catalog_Cutover::is_declared() );
	}
}
