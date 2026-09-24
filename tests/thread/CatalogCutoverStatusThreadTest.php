<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Database\Manager;
use BeyondElysium\Database\Option_Lock;
use BeyondElysium\Models\Change;
use BeyondElysium\Models\Character;
use BeyondElysium\Models\Game;
use BeyondElysium\Services\Catalog_Cutover;
use BeyondElysium\Services\Catalog_Reader;
use WP_UnitTestCase;

/**
 * R8: `Catalog_Cutover::status()` - what `wp be cutover status` and the subsite runner report.
 * It reads what `apply()` recorded, so each case drives the real `apply()` rather than writing
 * the option by hand.
 */
class CatalogCutoverStatusThreadTest extends WP_UnitTestCase {

	private int $actor;

	public function setUp(): void {
		parent::setUp();
		if ( ! Catalog_Reader::available() ) {
			$this->markTestSkipped( 'no declared catalog in this checkout' );
		}
		// apply() covers the whole install and this database's plugin tables outlive a run, so each
		// case sees only the characters it builds (the delete is rolled back with the test).
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . Manager::table( 'characters' ) );

		$this->actor = self::factory()->user->create( [ 'role' => 'administrator' ] );
		Game::create( [ 'slug' => 'cutover-status', 'name' => 'Cutover Status' ] );
	}

	public function tearDown(): void {
		delete_option( Catalog_Cutover::OPTION );
		delete_option( Catalog_Cutover::RECORD_OPTION );
		Option_Lock::release( Catalog_Cutover::LOCK );
		Catalog_Cutover::reset_cache();
		parent::tearDown();
	}

	private function character( string $name ): int {
		return (int) Character::create( [
			'name' => $name, 'stack_slug' => 'vampire', 'owner_type' => 'chronicle', 'owner_slug' => 'cutover-status', 'status' => 'active',
			'sheet_data' => [
				'met-abilities' => [ [ 'name' => 'Brawl', 'count' => 3 ], [ 'name' => 'Lore: Kindred', 'count' => 2, 'custom' => true ] ],
				'met-merits'    => [ [ 'name' => 'Iron Will' ] ],
			],
		] );
	}

	public function test_a_fresh_install_reads_as_not_applied(): void {
		$status = Catalog_Cutover::status();

		$this->assertTrue( $status['available'] );
		$this->assertFalse( $status['declared'] );
		$this->assertFalse( $status['locked'] );
		$this->assertNull( $status['applied_at'] );
		$this->assertNull( $status['actor'] );
		$this->assertNull( $status['plugin_version'] );
		$this->assertSame( 0, $status['characters_rekeyed'] );
		$this->assertSame( 0, $status['templates_changed'] );
		$this->assertSame( 0, $status['pending_rewritten'] );
	}

	public function test_after_apply_it_says_when_by_whom_and_what_a_rollback_would_undo(): void {
		$one = $this->character( 'One' );
		$this->character( 'Two' );
		Change::create( [ 'character_id' => $one, 'change_type' => 'add_trait', 'category' => 'met-merits', 'change_data' => [ 'block_slug' => 'met-merits', 'trait' => [ 'name' => 'Eat Food' ] ], 'submitted_by' => $this->actor ] );
		$this->assertSame( 'applied', Catalog_Cutover::apply( $this->actor )['status'] );

		$status = Catalog_Cutover::status();

		$this->assertTrue( $status['declared'] );
		$this->assertFalse( $status['locked'], 'apply() releases its lock' );
		$this->assertNotFalse( strtotime( (string) $status['applied_at'] ) );
		$this->assertSame( $this->actor, $status['actor'] );
		$this->assertSame( BE_VERSION, $status['plugin_version'] );
		$this->assertSame( 2, $status['characters_rekeyed'] );
		$this->assertGreaterThan( 0, $status['templates_changed'] );
		$this->assertSame( 1, $status['pending_rewritten'] );
	}

	public function test_it_says_when_no_declared_catalog_ships(): void {
		$without = new class() extends Catalog_Cutover {
			protected static function catalog_available(): bool {
				return false;
			}
		};

		$this->assertFalse( $without::status()['available'] );
	}

	public function test_a_held_lock_shows_and_a_stale_one_does_not(): void {
		$this->assertTrue( Option_Lock::claim( Catalog_Cutover::LOCK, 600 ) );
		$this->assertTrue( Catalog_Cutover::status()['locked'] );

		update_option( Catalog_Cutover::LOCK, (string) ( time() - 4000 ) );

		$this->assertFalse( Catalog_Cutover::status()['locked'], 'held longer than the lock lives is a run that died' );
	}

	public function test_after_a_rollback_it_reads_as_not_applied_again(): void {
		$this->character( 'One' );
		Catalog_Cutover::apply( $this->actor );
		Catalog_Cutover::rollback( $this->actor );

		$status = Catalog_Cutover::status();

		$this->assertFalse( $status['declared'] );
		$this->assertNull( $status['applied_at'] );
		$this->assertSame( 0, $status['characters_rekeyed'], 'the re-key has been undone, so nothing stands' );
	}
}
