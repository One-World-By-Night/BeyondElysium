<?php

namespace BeyondElysium\Tests\Thread;

require_once __DIR__ . '/../support/CoordinatorTestFixture.php';

use BeyondElysium\Database\Manager;
use BeyondElysium\Database\Schema;
use BeyondElysium\Models\Character;
use BeyondElysium\Models\Coordinator_Request;
use BeyondElysium\Models\Game;
use BeyondElysium\Tests\Support\CoordinatorTestFixture;
use WP_UnitTestCase;

/**
 * The record of a send to the Archivist Toolkit: its table, its states, which rows the hourly read-back takes, and
 * its removal with the chronicle.
 */
class CoordinatorRequestThreadTest extends WP_UnitTestCase {

	use CoordinatorTestFixture;

	private int $character_id = 0;

	public function setUp(): void {
		parent::setUp();
		$this->make_chronicle();
		$this->character_id = Character::create( [
			'name' => 'Isolde Marchetti', 'stack_slug' => 'vampire',
			'owner_type' => 'chronicle', 'owner_slug' => $this->slug, 'wp_user_id' => $this->player_id,
			'sheet_data' => [],
		] );
	}

	/**
	 * @param array<string,mixed> $over
	 */
	private function request( array $over = [] ): int {
		return Coordinator_Request::create( array_merge( [
			'uuid'         => wp_generate_uuid4(),
			'game_id'      => $this->game_id,
			'character_id' => $this->character_id,
			'change_id'    => null,
			'purpose'      => 'approval',
			'sent_by'      => $this->hst_id,
			'payload'      => [ 'title' => 'Isolde Marchetti: Dual-Blooded' ],
		], $over ) );
	}

	private function stale( int $id, int $minutes ): void {
		Coordinator_Request::mark( $id, [ 'state' => 'sent', 'checked_at' => wp_date( 'Y-m-d H:i:s', time() - $minutes * MINUTE_IN_SECONDS ) ] );
	}

	public function test_the_table_and_the_characters_link_column_exist(): void {
		global $wpdb;
		$this->assertContains( 'coordinator_requests', Schema::TABLES );
		$this->assertSame( [], array_intersect( [ 'coordinator_requests' ], Schema::missing_tables() ) );
		$count = static fn(): int => (int) $wpdb->get_var( "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = '{$wpdb->prefix}be_characters' AND column_name = 'oat_character_uuid'" );
		$this->assertSame( 1, $count() );
		Schema::add_oat_character_uuid_to_characters();
		$this->assertSame( 1, $count(), 'Adding it again changes nothing.' );
	}

	public function test_a_request_is_kept_and_read_back_with_its_parts_decoded(): void {
		$id = $this->request( [ 'change_id' => 77, 'pick' => [ 'name' => 'Dual-Blooded', 'section' => 'Merits' ] ] );

		$row = Coordinator_Request::find( $id );

		$this->assertSame( 'sending', $row->state );
		$this->assertSame( 77, $row->change_id );
		$this->assertSame( 'approval', $row->purpose );
		$this->assertSame( [ 'name' => 'Dual-Blooded', 'section' => 'Merits' ], $row->pick );
		$this->assertSame( 'Isolde Marchetti: Dual-Blooded', $row->payload['title'] );
		$this->assertSame( $this->hst_id, $row->sent_by );
		$this->assertNull( $row->oat_entry_id );
	}

	public function test_the_same_key_cannot_be_kept_twice(): void {
		$uuid = wp_generate_uuid4();
		$this->assertGreaterThan( 0, $this->request( [ 'uuid' => $uuid ] ) );
		$this->assertSame( 0, $this->request( [ 'uuid' => $uuid ] ) );
		$this->assertSame( $uuid, Coordinator_Request::find_by_uuid( $uuid )->uuid );
	}

	public function test_a_state_that_is_not_one_is_refused(): void {
		$id = $this->request();

		$this->assertFalse( Coordinator_Request::mark( $id, [ 'state' => 'approved-ish' ] ) );
		$this->assertSame( 'sending', Coordinator_Request::find( $id )->state );
		$this->assertTrue( Coordinator_Request::mark( $id, [ 'state' => 'sent', 'oat_entry_id' => 33012, 'oat_step' => 'staff_review', 'oat_status' => 'pending' ] ) );
		$this->assertSame( 33012, Coordinator_Request::find( $id )->oat_entry_id );
	}

	public function test_a_change_finds_its_newest_request(): void {
		$first  = $this->request( [ 'change_id' => 5 ] );
		$second = $this->request( [ 'change_id' => 5 ] );
		$other  = $this->request( [ 'change_id' => 6 ] );

		$this->assertSame( $second, Coordinator_Request::for_change( 5 )->id );
		$map = Coordinator_Request::for_changes( [ 5, 6, 7 ] );
		$this->assertSame( [ 5, 6 ], array_keys( $map ) );
		$this->assertSame( $second, $map[5]->id );
		$this->assertSame( $other, $map[6]->id );
		$this->assertNotSame( $first, $map[5]->id );
		$this->assertNull( Coordinator_Request::for_change( 99 ) );
	}

	public function test_the_read_back_takes_sent_approvals_not_read_in_the_last_55_minutes_oldest_first(): void {
		$never   = $this->request();
		$old     = $this->request();
		$older   = $this->request();
		$recent  = $this->request();
		$notify  = $this->request( [ 'purpose' => 'notify' ] );
		$unsure  = $this->request();
		$done    = $this->request();

		Coordinator_Request::mark( $never, [ 'state' => 'sent' ] );
		$this->stale( $old, 120 );
		$this->stale( $older, 300 );
		$this->stale( $recent, 10 );
		Coordinator_Request::mark( $notify, [ 'state' => 'filed' ] );
		Coordinator_Request::mark( $unsure, [ 'state' => 'unconfirmed' ] );
		Coordinator_Request::mark( $done, [ 'state' => 'approved' ] );

		$due = array_map( static fn( $r ) => $r->id, Coordinator_Request::due( 20 ) );

		$this->assertSame( [ $never, $older, $old ], $due, 'Never read first, then the longest unread.' );
		$this->assertSame( [ $never ], array_map( static fn( $r ) => $r->id, Coordinator_Request::due( 1 ) ) );
	}

	public function test_a_chronicle_counts_what_is_with_a_coordinator(): void {
		$sent = $this->request();
		$this->request();
		Coordinator_Request::mark( $sent, [ 'state' => 'sent' ] );

		$this->assertSame( 1, Coordinator_Request::with_coordinator_count( $this->game_id ) );
		$this->assertSame( 0, Coordinator_Request::with_coordinator_count( $this->game_id + 999 ) );
	}

	public function test_requests_go_with_the_chronicle_and_are_counted_first(): void {
		$this->request();
		$this->request();

		$counts = Game::content_counts( $this->game() );
		$this->assertSame( 2, $counts['coordinator_requests'] );

		$this->assertTrue( Game::delete_with_content( $this->slug ) );
		$this->assertSame( 0, (int) Manager::get_var( 'SELECT COUNT(*) FROM ' . Manager::table( 'coordinator_requests' ) . ' WHERE game_id = %d', $this->game_id ) );
	}

	public function test_a_character_is_linked_to_and_unlinked_from_its_toolkit_record(): void {
		$this->assertNull( Character::find( $this->character_id )->oat_character_uuid ?? null );

		$this->assertTrue( Character::set_oat_link( $this->character_id, 'a3f1c0de-0000-4000-8000-000000000001' ) );
		$this->assertSame( 'a3f1c0de-0000-4000-8000-000000000001', Character::find( $this->character_id )->oat_character_uuid );

		$this->assertTrue( Character::set_oat_link( $this->character_id, null ) );
		$this->assertNull( Character::find( $this->character_id )->oat_character_uuid );
	}
}
