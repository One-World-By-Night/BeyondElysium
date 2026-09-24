<?php

namespace BeyondElysium\Tests\Thread;

require_once __DIR__ . '/../support/LegacyInstall.php';

use BeyondElysium\Core\Health_Notice;
use BeyondElysium\Database\Manager;
use BeyondElysium\Database\Option_Lock;
use BeyondElysium\Database\Schema;
use BeyondElysium\Models\Change;
use BeyondElysium\Models\Character;
use BeyondElysium\Models\Creature_Stack;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Schema_Block;
use BeyondElysium\Models\Snapshot;
use BeyondElysium\Models\Template;
use BeyondElysium\Services\Catalog_Cutover;
use BeyondElysium\Services\Catalog_Reader;
use BeyondElysium\Tests\Support\LegacyInstall;
use WP_UnitTestCase;

/**
 * What the upgrade does with the switch to per-creature lists: an install already on them is left as it is, an empty
 * one is marked, one holding characters on the shared lists has them moved and recorded, and one whose move is
 * refused is left unchanged.
 */
class UpgradeSwitchThreadTest extends WP_UnitTestCase {

	private string $game_slug = 'thread-upgrade-switch';

	public function setUp(): void {
		parent::setUp();
		if ( ! Catalog_Reader::available() ) {
			$this->markTestSkipped( 'no declared catalog in this checkout' );
		}
		do_action( 'rest_api_init' );

		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . Manager::table( 'characters' ) );
		Game::create( [ 'slug' => $this->game_slug, 'name' => 'Thread Upgrade Switch' ] );
	}

	public function tearDown(): void {
		global $wpdb;

		// An upgrade's table changes commit the test's transaction.
		$characters = Manager::table( 'characters' );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$characters} WHERE owner_slug IN (%s, %s)", $this->game_slug, 'no-such-chronicle' ) );
		foreach ( [ 'character_changes', 'character_snapshots' ] as $table ) {
			$wpdb->query( 'DELETE FROM ' . Manager::table( $table ) . " WHERE character_id NOT IN (SELECT id FROM {$characters})" );
		}
		$wpdb->delete( Manager::table( 'games' ), [ 'slug' => $this->game_slug ] );
		delete_option( Schema::UPGRADE_ERROR_OPTION );
		Option_Lock::release( Schema::UPGRADE_LOCK_OPTION );
		Option_Lock::release( Catalog_Cutover::LOCK );
		LegacyInstall::restore();

		parent::tearDown();
	}

	/**
	 * Runs the upgrade as the first request after an update would: an older version recorded, no lock held.
	 */
	private function upgrade(): void {
		update_option( Schema::VERSION_OPTION, '1.3.3' );
		delete_option( Schema::UPGRADE_ERROR_OPTION );
		$this->set_lock( null );
		Schema::maybe_upgrade();
	}

	/**
	 * Writes the lock row directly, the way another request holding it would have.
	 */
	private function set_lock( ?int $since ): void {
		global $wpdb;
		$wpdb->delete( $wpdb->options, [ 'option_name' => Schema::UPGRADE_LOCK_OPTION ] );
		if ( $since !== null ) {
			$wpdb->insert( $wpdb->options, [ 'option_name' => Schema::UPGRADE_LOCK_OPTION, 'option_value' => (string) $since, 'autoload' => 'off' ] );
		}
		wp_cache_delete( Schema::UPGRADE_LOCK_OPTION, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
	}

	private function installed_version(): string {
		global $wpdb;
		return (string) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", Schema::VERSION_OPTION ) );
	}

	/** @param array<string,mixed> $sheet */
	private function character( string $stack, string $name, array $sheet, ?string $owner_slug = null ): int {
		$id = (int) Character::create( [
			'name' => $name, 'stack_slug' => $stack, 'owner_type' => 'chronicle',
			'owner_slug' => $owner_slug ?? $this->game_slug, 'status' => 'active', 'sheet_data' => $sheet,
		] );
		Character::update_xp( $id, 40, 7 );
		return $id;
	}

	/** @return string[] */
	private function sections( string $stack ): array {
		return array_column( (array) Creature_Stack::find_by_slug( $stack )->stack_definition->sections, 'block_slug' );
	}

	/** @return string[] The names a block lists. */
	private function names( string $block_slug ): array {
		return array_map( static fn( $item ) => $item->name, Schema_Block::find_by_slug( $block_slug )->definition->items );
	}

	/** @return object[] */
	private function rekeys( int $character_id ): array {
		return array_values( array_filter( Change::for_character( $character_id ), static fn( $change ) => $change->change_type === 'catalog_rekey' ) );
	}

	/** @return array<int,array{xp_earned:string,xp_unspent:string}> */
	private function xp_columns(): array {
		$out = [];
		foreach ( Manager::get_results( 'SELECT id, xp_earned, xp_unspent FROM ' . Manager::table( 'characters' ) . ' ORDER BY id' ) as $row ) {
			$out[ (int) $row->id ] = [ 'xp_earned' => (string) $row->xp_earned, 'xp_unspent' => (string) $row->xp_unspent ];
		}
		return $out;
	}

	public function test_an_install_already_on_the_per_creature_lists_is_left_as_it_is(): void {
		update_option( Catalog_Cutover::OPTION, 'declared' );
		$id     = $this->character( 'vampire', 'Already Moved', [ 'vampire-abilities' => [ [ 'name' => 'Brawl', 'count' => 3 ] ] ] );
		$before = Character::find( $id )->sheet_data;

		$this->upgrade();

		$this->assertSame( Schema::DB_VERSION, $this->installed_version() );
		$this->assertFalse( get_option( Schema::UPGRADE_ERROR_OPTION ) );
		$this->assertEquals( $before, Character::find( $id )->sheet_data );
		$this->assertSame( [], $this->rekeys( $id ), 'no history entry is written for a character that did not move' );
	}

	public function test_an_empty_install_is_marked_and_upgraded(): void {
		LegacyInstall::put_in_place();
		$this->assertFalse( Catalog_Cutover::is_declared() );

		$this->upgrade();

		$this->assertTrue( Catalog_Cutover::is_declared() );
		$this->assertSame( Schema::DB_VERSION, $this->installed_version() );
		$this->assertContains( 'vampire-abilities', $this->sections( 'vampire' ) );
		$this->assertNotContains( 'met-abilities', $this->sections( 'vampire' ) );

		$vampire = array_column( Template::resolve( 'vampire', 'sheet_full', null )->layout['sections'], 'block_slug' );
		$this->assertContains( 'vampire-abilities', $vampire, 'the default templates name the per-creature blocks' );
		$this->assertNotContains( 'met-abilities', $vampire );
		$this->assertNull( Schema_Block::find_by_slug( 'met-abilities' ), 'and nothing keeps the shared block' );
	}

	public function test_an_install_whose_characters_already_moved_but_has_no_marker_is_marked(): void {
		$id     = $this->character( 'vampire', 'Moved Without A Marker', [ 'vampire-abilities' => [ [ 'name' => 'Brawl', 'count' => 3 ] ] ] );
		$before = Character::find( $id )->sheet_data;
		delete_option( Catalog_Cutover::OPTION );

		$this->upgrade();

		$this->assertTrue( Catalog_Cutover::is_declared() );
		$this->assertSame( Schema::DB_VERSION, $this->installed_version() );
		$this->assertEquals( $before, Character::find( $id )->sheet_data );
		$this->assertSame( [], $this->rekeys( $id ) );
	}

	public function test_an_install_holding_characters_on_the_shared_lists_is_moved_by_the_upgrade(): void {
		LegacyInstall::put_in_place();
		$shared_rite = array_values( array_intersect( $this->names( 'werewolf-rites' ), $this->names( 'fera-rites' ) ) );
		$this->assertNotEmpty( $shared_rite, 'a Rite both lists carry' );

		$vampire = $this->character( 'vampire', 'Shared Vampire', [
			'met-abilities' => [ [ 'name' => 'Brawl', 'count' => 3 ], [ 'name' => 'Basket Weaving', 'count' => 1, 'custom' => true ] ],
			'met-merits'    => [ [ 'name' => 'Iron Will' ] ],
		] );
		$fera    = $this->character( 'fera', 'Shared Fera', [
			'met-abilities'  => [ [ 'name' => 'Brawl', 'count' => 2 ] ],
			'werewolf-rites' => [ [ 'name' => $shared_rite[0] ] ],
		] );
		$xp      = $this->xp_columns();
		$snapshots = [ $vampire => Snapshot::count_for_character( $vampire ), $fera => Snapshot::count_for_character( $fera ) ];

		$this->upgrade();

		$this->assertSame( Schema::DB_VERSION, $this->installed_version() );
		$this->assertFalse( get_option( Schema::UPGRADE_ERROR_OPTION ) );
		$this->assertTrue( Catalog_Cutover::is_declared() );
		$this->assertFalse( Catalog_Cutover::switching(), 'the move released its lock' );

		$sheet = Character::find( $vampire )->sheet_data;
		$this->assertArrayNotHasKey( 'met-abilities', $sheet );
		$this->assertArrayNotHasKey( 'met-merits', $sheet );
		$this->assertContains( 'Brawl', array_column( $sheet['vampire-abilities'], 'name' ) );
		$this->assertContains( 'Basket Weaving', array_column( $sheet['vampire-abilities'], 'name' ), 'a custom entry moves with the rest' );
		$this->assertSame( [ 'Iron Will' ], array_column( $sheet['vampire-merits'], 'name' ) );

		$sheet = Character::find( $fera )->sheet_data;
		$this->assertArrayNotHasKey( 'werewolf-rites', $sheet );
		$this->assertSame( [ $shared_rite[0] ], array_column( $sheet['fera-rites'], 'name' ) );
		$this->assertSame( [ 'Brawl' ], array_column( $sheet['fera-abilities'], 'name' ) );

		foreach ( [ $vampire, $fera ] as $id ) {
			$rekeys = $this->rekeys( $id );
			$this->assertCount( 1, $rekeys, 'one history entry each' );
			$this->assertSame( 0, (int) $rekeys[0]->submitted_by, 'recorded as the upgrade, not as a person' );
			$this->assertSame( 'approved', $rekeys[0]->status );
			$this->assertSame( $snapshots[ $id ] + 1, Snapshot::count_for_character( $id ), 'and a snapshot of the sheet as it was' );
		}
		$this->assertSame( $xp, $this->xp_columns(), 'XP is never written' );

		$this->assertContains( 'vampire-abilities', $this->sections( 'vampire' ) );
		$this->assertNotContains( 'met-abilities', $this->sections( 'vampire' ) );
	}

	public function test_a_refused_move_leaves_the_install_on_its_old_version_and_says_why(): void {
		LegacyInstall::put_in_place();
		$gap = array_values( array_diff( $this->names( 'werewolf-rites' ), $this->names( 'fera-rites' ) ) );
		$this->assertNotEmpty( $gap, 'a Garou Rite the Fera list does not carry' );

		$id     = $this->character( 'fera', 'Refused Fera', [
			'met-abilities'  => [ [ 'name' => 'Brawl', 'count' => 2 ] ],
			'werewolf-rites' => [ [ 'name' => $gap[0] ] ],
		] );
		$before = Character::find( $id )->sheet_data;

		$this->upgrade();

		$this->assertSame( '1.3.3', $this->installed_version(), 'the version is not recorded' );
		$this->assertFalse( Catalog_Cutover::is_declared() );
		$this->assertEquals( $before, Character::find( $id )->sheet_data, 'nothing moved' );
		$this->assertSame( [], $this->rekeys( $id ) );
		$this->assertContains( 'met-abilities', $this->sections( 'vampire' ), 'the stacks were not reseeded' );

		$message = (string) ( get_option( Schema::UPGRADE_ERROR_OPTION )['message'] ?? '' );
		$this->assertStringContainsString( 'Refused Fera', $message );
		$this->assertStringContainsString( $gap[0], $message );
		$this->assertStringContainsString( 'fera-rites', $message );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		ob_start();
		Health_Notice::render();
		$this->assertStringContainsString( $gap[0], (string) ob_get_clean(), 'administrators are shown what to fix' );
	}

	public function test_a_refused_move_is_tried_again_once_its_lock_goes_stale_and_completes_when_the_gap_is_fixed(): void {
		LegacyInstall::put_in_place();
		$gap = array_values( array_diff( $this->names( 'werewolf-rites' ), $this->names( 'fera-rites' ) ) );
		$id  = $this->character( 'fera', 'Retried Fera', [
			'met-abilities'  => [ [ 'name' => 'Brawl', 'count' => 2 ] ],
			'werewolf-rites' => [ [ 'name' => $gap[0] ] ],
		] );

		$this->upgrade();
		$this->assertSame( '1.3.3', $this->installed_version() );

		// The next request does not run it again straight away.
		Character::update_sheet_data( $id, [ 'met-abilities' => [ [ 'name' => 'Brawl', 'count' => 2 ] ] ] );
		Schema::maybe_upgrade();
		$this->assertSame( '1.3.3', $this->installed_version(), 'the lock is still held' );

		$this->set_lock( time() - HOUR_IN_SECONDS );
		Schema::maybe_upgrade();

		$this->assertSame( Schema::DB_VERSION, $this->installed_version() );
		$this->assertFalse( get_option( Schema::UPGRADE_ERROR_OPTION ) );
		$this->assertTrue( Catalog_Cutover::is_declared() );
		$this->assertSame( [ 'Brawl' ], array_column( Character::find( $id )->sheet_data['fera-abilities'], 'name' ) );
	}

	public function test_a_character_in_no_chronicle_refuses_the_move(): void {
		LegacyInstall::put_in_place();
		$this->character( 'vampire', 'Homeless Vampire', [ 'met-abilities' => [ [ 'name' => 'Brawl', 'count' => 1 ] ] ], 'no-such-chronicle' );

		$this->upgrade();

		$this->assertSame( '1.3.3', $this->installed_version() );
		$this->assertFalse( Catalog_Cutover::is_declared() );
		$this->assertStringContainsString( 'belong to a chronicle', (string) ( get_option( Schema::UPGRADE_ERROR_OPTION )['message'] ?? '' ) );
	}

	public function test_the_retired_blocks_are_removed_once_the_move_is_done(): void {
		LegacyInstall::put_in_place();
		$this->character( 'vampire', 'Removal Vampire', [ 'met-abilities' => [ [ 'name' => 'Brawl', 'count' => 3 ] ] ] );
		$this->assertNotNull( Schema_Block::find_by_slug( 'met-abilities' ) );
		$this->assertNotNull( Schema_Block::find_by_slug( 'demon-lores' ) );

		$this->upgrade();

		foreach ( [ 'met-abilities', 'met-merits', 'met-flaws', 'demon-lores', 'mortal-numina' ] as $slug ) {
			$this->assertNull( Schema_Block::find_by_slug( $slug ), "{$slug} is gone" );
		}
		$this->assertNotNull( Schema_Block::find_by_slug( 'werewolf-rites' ), "Werewolf's own Rites list stays" );

		foreach ( Template::globals() as $template ) {
			$slugs = array_column( $template->layout['sections'] ?? [], 'block_slug' );
			$this->assertSame( [], array_values( array_intersect( $slugs, [ 'met-abilities', 'met-merits', 'met-flaws', 'demon-lores', 'mortal-numina' ] ) ), "template {$template->id}" );
		}
		$demon = Template::resolve( 'demon', 'sheet_full', null );
		$this->assertContains( 'demon-evocations', array_column( $demon->layout['sections'], 'block_slug' ) );
	}

	public function test_a_system_template_naming_a_retired_block_keeps_its_other_rows_as_they_are(): void {
		update_option( Catalog_Cutover::OPTION, 'declared' );
		LegacyInstall::stand_in_blocks();

		$template = Template::resolve( 'demon', 'sheet_full', null );
		$layout   = $template->layout;
		foreach ( $layout['sections'] as &$section ) {
			if ( 'demon-evocations' === $section['block_slug'] ) {
				$section['title'] = 'Lores (Evocations)';
			}
		}
		unset( $section );
		$layout['sections'][] = [ 'block_slug' => 'demon-lores', 'title' => 'Demon Lores', 'column' => 1, 'order' => 99, 'width' => 'full', 'display' => null, 'collapsed' => false ];
		$this->assertTrue( Template::update( (int) $template->id, [ 'layout' => $layout ] ) );
		$expected = array_values( array_filter( $layout['sections'], static fn( $s ) => 'demon-lores' !== $s['block_slug'] ) );

		$this->upgrade();

		$after = Template::find( (int) $template->id )->layout['sections'];
		$this->assertSame( array_column( $expected, 'block_slug' ), array_column( $after, 'block_slug' ), 'the rows that stay keep their order' );
		$this->assertSame( array_column( $expected, 'title' ), array_column( $after, 'title' ), 'the rows that stay keep their titles' );
	}

	public function test_a_retired_block_a_character_still_holds_entries_under_is_kept(): void {
		LegacyInstall::put_in_place();
		$id = $this->character( 'demon', 'Lore Keeper', [
			'met-abilities' => [ [ 'name' => 'Brawl', 'count' => 1 ] ],
			'demon-lores'   => [ [ 'name' => 'Vampire' ] ],
		] );

		$this->upgrade();

		$this->assertSame( Schema::DB_VERSION, $this->installed_version() );
		$this->assertNull( Schema_Block::find_by_slug( 'met-abilities' ) );
		$this->assertNotNull( Schema_Block::find_by_slug( 'demon-lores' ), 'a character still holds entries under it' );
		$this->assertNotEmpty( Character::find( $id )->sheet_data['demon-lores'] );
	}
}
