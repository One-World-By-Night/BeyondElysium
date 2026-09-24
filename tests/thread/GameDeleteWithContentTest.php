<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\After_Game_Report;
use BeyondElysium\Models\Attendance;
use BeyondElysium\Models\Character;
use BeyondElysium\Models\Faction;
use BeyondElysium\Models\Faction_Member;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Game_Session;
use BeyondElysium\Models\Item_Event;
use BeyondElysium\Models\Notification_Queue;
use BeyondElysium\Models\Npc_Casting;
use BeyondElysium\Models\Plot;
use BeyondElysium\Models\Position;
use BeyondElysium\Models\Position_History;
use BeyondElysium\Models\Release_Batch;
use BeyondElysium\Models\Saved_Query;
use BeyondElysium\Models\Secret;
use BeyondElysium\Models\Secret_Reveal;
use BeyondElysium\Models\Template;
use BeyondElysium\Models\World_Object;
use WP_UnitTestCase;

/**
 * `Game::delete_with_content()` deletes a chronicle and everything stored under it.
 */
class GameDeleteWithContentTest extends WP_UnitTestCase {

	private function make_game( string $slug ): int {
		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'be_games', [
			'slug' => $slug, 'name' => 'Delete With Content Test Game',
			'created_by' => 1, 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ),
		] );
		return (int) $wpdb->insert_id;
	}

	public function test_delete_with_content_removes_every_kind_of_owned_content(): void {
		$slug    = 'thread-test-delete-with-content-' . wp_generate_password( 8, false );
		$game_id = $this->make_game( $slug );

		$character_id = Character::create( [
			'name' => 'Cascade Test Character', 'stack_slug' => 'vampire',
			'owner_type' => 'chronicle', 'owner_slug' => $slug,
		] );
		$plot_id = Plot::create( [ 'game_id' => $game_id, 'title' => 'Cascade Test Plot' ] );
		$item_id = World_Object::create( [
			'game_id' => $game_id, 'object_type' => 'item', 'name' => 'Cascade Test Item',
		] );
		$template_id = Template::create( [
			'game_id' => $game_id, 'stack_slug' => 'vampire', 'name' => 'Cascade Test Template',
			'template_type' => 'sheet_full', 'layout' => [ 'version' => 1, 'columns' => 3, 'sections' => [] ], 'created_by' => 1,
		] );
		$query_id = Saved_Query::create( [
			'game_id' => $game_id, 'name' => 'Cascade Test Query', 'inventory' => 'char',
			'conditions' => [], 'created_by' => 1,
		] );

		$this->assertNotNull( Character::find( $character_id ), 'fixture sanity check' );
		$this->assertNotNull( Plot::find( $plot_id ), 'fixture sanity check' );
		$this->assertNotNull( World_Object::find( $item_id ), 'fixture sanity check' );
		$this->assertNotNull( Template::find( $template_id ), 'fixture sanity check' );
		$this->assertNotNull( Saved_Query::find( $query_id ), 'fixture sanity check' );

		$this->assertTrue( Game::delete_with_content( $slug ) );

		$this->assertNull( Character::find( $character_id ), 'character must be gone' );
		$this->assertNull( Plot::find( $plot_id ), 'plot must be gone' );
		$this->assertNull( World_Object::find( $item_id ), 'world object must be gone' );
		$this->assertNull( Template::find( $template_id ), 'template must be gone' );
		$this->assertNull( Saved_Query::find( $query_id ), 'saved query must be gone' );
		$this->assertNull( Game::find_by_slug( $slug ), 'the game itself must be gone' );
	}

	/**
	 * Nine real content kinds `delete_with_content()` silently left behind.
	 */
	public function test_delete_with_content_removes_the_nine_kinds_d1_found_missing(): void {
		$slug    = 'thread-test-d1-delete-with-content-' . wp_generate_password( 8, false );
		$game_id = $this->make_game( $slug );

		$user_id = self::factory()->user->create();

		$character_id = Character::create( [
			'name' => 'D1 Test Character', 'stack_slug' => 'vampire',
			'owner_type' => 'chronicle', 'owner_slug' => $slug, 'wp_user_id' => $user_id,
		] );

		$faction_id = Faction::create( [ 'game_id' => $game_id, 'name' => 'D1 Test Faction', 'faction_type' => 'sect', 'created_by' => 1 ] );
		$this->assertNotFalse( $faction_id, 'fixture sanity check' );
		$member_ok = Faction_Member::add( (int) $faction_id, (int) $character_id, 1 );
		$this->assertNotFalse( $member_ok, 'fixture sanity check' );

		$position_id = Position::create( [ 'game_id' => $game_id, 'title' => 'D1 Test Position', 'created_by' => 1 ] );
		$this->assertNotFalse( $position_id, 'fixture sanity check' );
		$this->assertTrue( Position::set_holder( (int) $position_id, (int) $character_id ), 'fixture sanity check' );

		$secret_id = Secret::create( [
			'game_id' => $game_id, 'entity_type' => 'plot', 'entity_id' => 1,
			'title' => 'D1 Test Secret', 'created_by' => 1,
		] );
		$this->assertNotFalse( $secret_id, 'fixture sanity check' );
		$reveal_id = Secret_Reveal::create( [ 'secret_id' => $secret_id, 'character_id' => $character_id, 'revealed_by' => 1 ] );
		$this->assertNotFalse( $reveal_id, 'fixture sanity check' );

		$session_id = Game_Session::create( [ 'game_id' => $game_id, 'game_date' => '2026-09-19', 'created_by' => 1 ] );
		$this->assertNotFalse( $session_id, 'fixture sanity check' );

		$attendance_id = Attendance::record( (int) $session_id, $game_id, [ 'character_id' => $character_id, 'recorded_by' => 1 ] );
		$this->assertNotFalse( $attendance_id, 'fixture sanity check' );

		$batch_id = Release_Batch::create( [ 'game_id' => $game_id, 'name' => 'D1 Test Batch', 'created_by' => 1 ] );
		$this->assertNotFalse( $batch_id, 'fixture sanity check' );

		$notification_id = Notification_Queue::create( $user_id, $game_id, 'daily_digest', [ 'note' => 'test' ] );
		$this->assertNotFalse( $notification_id, 'fixture sanity check' );

		$casting_id = Npc_Casting::create( [
			'game_id' => $game_id, 'session_id' => $session_id, 'character_id' => $character_id,
			'wp_user_id' => $user_id, 'created_by' => 1,
		] );
		$this->assertNotFalse( $casting_id, 'fixture sanity check' );

		$report_id = After_Game_Report::create( [
			'game_id' => $game_id, 'session_id' => $session_id, 'character_id' => $character_id,
			'wp_user_id' => $user_id, 'did' => 'Tested D1.',
		] );
		$this->assertNotFalse( $report_id, 'fixture sanity check' );

		// content_counts() is the exact gate delete_item() refuses a plain delete.
		$game   = Game::find_by_slug( $slug );
		$counts = Game::content_counts( $game );
		$this->assertGreaterThan( 0, $counts['factions'] );
		$this->assertGreaterThan( 0, $counts['positions'] );
		$this->assertGreaterThan( 0, $counts['secrets'] );
		$this->assertGreaterThan( 0, $counts['game_sessions'] );
		$this->assertGreaterThan( 0, $counts['attendance'] );
		$this->assertGreaterThan( 0, $counts['release_batches'] );
		$this->assertGreaterThan( 0, $counts['notification_queue'] );
		$this->assertGreaterThan( 0, $counts['npc_castings'] );
		$this->assertGreaterThan( 0, $counts['after_game_reports'] );

		$this->assertTrue( Game::delete_with_content( $slug ) );

		global $wpdb;
		$this->assertSame( '0', $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}be_faction_members WHERE faction_id = %d", $faction_id ) ), 'faction_members must cascade' );
		$this->assertNull( Faction_Member::find_for( (int) $faction_id, (int) $character_id ) );
		$this->assertSame( [], Position_History::for_position( (int) $position_id ), 'position_history must cascade' );
		$this->assertSame( [], Secret_Reveal::for_secret( (int) $secret_id ), 'secret_reveals must cascade' );

		$this->assertNull( self::table_find( 'factions', $faction_id ) );
		$this->assertNull( self::table_find( 'positions', $position_id ) );
		$this->assertNull( self::table_find( 'secrets', $secret_id ) );
		$this->assertNull( self::table_find( 'game_sessions', $session_id ) );
		$this->assertNull( self::table_find( 'attendance', $attendance_id ) );
		$this->assertNull( self::table_find( 'release_batches', $batch_id ) );
		$this->assertNull( self::table_find( 'notification_queue', $notification_id ) );
		$this->assertNull( self::table_find( 'npc_castings', $casting_id ) );
		$this->assertNull( self::table_find( 'after_game_reports', $report_id ) );
	}

	public function test_world_object_delete_removes_its_own_item_events(): void {
		$slug    = 'thread-test-d1-item-events-' . wp_generate_password( 8, false );
		$game_id = $this->make_game( $slug );

		$item_id = World_Object::create( [ 'game_id' => $game_id, 'object_type' => 'item', 'name' => 'D1 Test Item' ] );
		$this->assertNotFalse( $item_id, 'fixture sanity check' );
		$event_id = Item_Event::record( [ 'game_id' => $game_id, 'world_object_id' => $item_id, 'event' => 'copied', 'recorded_by' => 1 ] );
		$this->assertNotFalse( $event_id, 'fixture sanity check' );
		$this->assertNotEmpty( Item_Event::for_object( (int) $item_id ) );

		$this->assertTrue( World_Object::delete( (int) $item_id ) );

		$this->assertSame( [], Item_Event::for_object( (int) $item_id ) );
	}

	/** @return object|null One row by id, from any table this test file touches. */
	private static function table_find( string $table, $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare(
			'SELECT * FROM ' . $wpdb->prefix . 'be_' . $table . ' WHERE id = %d',
			$id
		) );
	}

	/**
	 * The plain `delete()` must never destroy content, and must never orphan it either: while the chronicle holds content
	 * it refuses, and the game and its characters stay exactly as they were.
	 */
	public function test_plain_delete_refuses_while_the_chronicle_holds_content(): void {
		$slug_a = 'thread-test-plain-delete-' . wp_generate_password( 8, false );
		$this->make_game( $slug_a );
		$character_a = Character::create( [
			'name' => 'Survives Plain Delete', 'stack_slug' => 'vampire',
			'owner_type' => 'chronicle', 'owner_slug' => $slug_a,
		] );

		$this->assertFalse( Game::delete( $slug_a ) );

		$this->assertNotNull( Game::find_by_slug( $slug_a ), 'the game must still exist' );
		$this->assertNotNull( Character::find( $character_a ), 'its character must still exist, still owned by it' );
	}
}
