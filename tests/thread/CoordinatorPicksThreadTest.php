<?php

namespace BeyondElysium\Tests\Thread;

require_once __DIR__ . '/../support/CoordinatorTestFixture.php';

use BeyondElysium\Models\Character;
use BeyondElysium\Models\Coordinator_Request;
use BeyondElysium\Models\Notice;
use BeyondElysium\Services\Bylaws;
use BeyondElysium\Services\Coordinator_Reader;
use BeyondElysium\Tests\Support\CoordinatorTestFixture;
use WP_UnitTestCase;

/**
 * A new character's picks that the rules send to a Coordinator: the Storyteller sends them from the pending
 * character, and the character becomes active once every one of them is approved.
 */
class CoordinatorPicksThreadTest extends WP_UnitTestCase {

	use CoordinatorTestFixture;

	private int $character_id = 0;

	public function setUp(): void {
		parent::setUp();
		$this->make_chronicle( 'chronicle/kony', [ 'coordinator_send' => true, 'owbn_bylaws' => true, 'starting_xp' => 30 ] );
		$this->connect_toolkit();
		$this->fake_toolkit();
		Bylaws::upload( [
			'rules'       => [
				[ 'clause_id' => 8992, 'path' => '10.m.v.10', 'subject' => 'Dual-Blooded', 'pc' => 'Coordinator Approval', 'npc' => 'Coordinator Approval', 'coordinators' => [ 'Assamite' ] ],
				[ 'clause_id' => 9031, 'path' => '10.m.v.23', 'subject' => 'Shakari Enemy', 'pc' => 'Coordinator Approval', 'npc' => 'Coordinator Approval', 'coordinators' => [ 'Assamite' ] ],
				[ 'clause_id' => 7838, 'path' => '10.e.v', 'subject' => 'True Faith', 'pc' => 'Coordinator Notify', 'npc' => 'Unregulated', 'coordinators' => [ 'Hunter' ] ],
			],
			'attachments' => [
				[ 'clause_id' => 8992, 'family' => 'merits', 'name' => 'Dual-Blooded' ],
				[ 'clause_id' => 9031, 'family' => 'merits', 'name' => 'Shakari Enemy' ],
				[ 'clause_id' => 7838, 'family' => 'merits', 'name' => 'True Faith' ],
			],
		] );
	}

	public function tearDown(): void {
		Bylaws::reset_cache();
		delete_option( Bylaws::OVERRIDE_OPTION );
		$this->connect_toolkit( false );
		remove_all_filters( 'pre_http_request' );
		parent::tearDown();
	}

	/**
	 * @param array<int,array<string,mixed>> $merits
	 */
	private function pending( array $merits, string $status = 'pending' ): void {
		$this->character_id = Character::create( [
			'name' => 'Isolde Marchetti', 'stack_slug' => 'vampire', 'status' => $status,
			'owner_type' => 'chronicle', 'owner_slug' => $this->slug, 'wp_user_id' => $this->player_id,
			'sheet_data' => [ 'vampire-merits' => $merits ],
		] );
		Character::set_oat_link( $this->character_id, 'a3f1c0de-0000-4000-8000-000000000001' );
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	private function picks( int $as = 0 ): array {
		$response = $this->rest( $as ?: $this->hst_id, 'GET', "{$this->slug}/characters/{$this->character_id}/coordinator-picks" );
		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		return $response->get_data()['picks'];
	}

	/**
	 * @param string[] $keys
	 */
	private function send( array $keys, array $over = [] ) {
		return $this->rest( $this->hst_id, 'POST', "{$this->slug}/coordinator/send", array_merge( [ 'character_id' => $this->character_id, 'pick_keys' => $keys, 'rules' => [], 'coordinators' => [ 'assamite' ] ], $over ) );
	}

	private function status(): string {
		return (string) Character::find( $this->character_id )->status;
	}

	public function test_a_pending_characters_picks_that_need_a_coordinator_are_listed_with_the_office(): void {
		$this->pending( [ [ 'name' => 'Dual-Blooded', 'count' => 1 ], [ 'name' => 'Iron Will', 'count' => 1 ] ] );

		$picks = $this->picks();

		$this->assertCount( 1, $picks );
		$this->assertSame( 'Dual-Blooded', $picks[0]['name'] );
		$this->assertSame( 'approval', $picks[0]['signal']['kind'] );
		$this->assertSame( [ 'Assamite' ], $picks[0]['signal']['coordinators'] );
		$this->assertNull( $picks[0]['request'] );
		$this->assertNotSame( '', $picks[0]['key'] );
	}

	public function test_a_notification_pick_is_listed_as_one(): void {
		$this->pending( [ [ 'name' => 'True Faith', 'count' => 1 ] ] );

		$this->assertSame( 'notify', $this->picks()[0]['signal']['kind'] );
	}

	public function test_nothing_is_listed_for_a_character_that_is_not_pending_or_where_the_switch_is_off(): void {
		$this->pending( [ [ 'name' => 'Dual-Blooded', 'count' => 1 ] ], 'active' );
		$this->assertSame( [], $this->picks() );

		$this->pending( [ [ 'name' => 'Dual-Blooded', 'count' => 1 ] ] );
		$this->set_settings( [ 'coordinator_send' => false ] );
		$this->assertSame( [], $this->picks() );
	}

	public function test_only_a_storyteller_lists_them(): void {
		$this->pending( [ [ 'name' => 'Dual-Blooded', 'count' => 1 ] ] );

		foreach ( [ $this->player_id, $this->narrator_id ] as $who ) {
			$this->assertContains( $this->rest( $who, 'GET', "{$this->slug}/characters/{$this->character_id}/coordinator-picks" )->get_status(), [ 401, 403 ] );
		}
	}

	public function test_sending_a_pick_files_a_request_with_no_change_behind_it(): void {
		$this->pending( [ [ 'name' => 'Dual-Blooded', 'count' => 1 ] ] );
		$key = $this->picks()[0]['key'];

		$response = $this->send( [ $key ] );

		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$this->assertSame( 'sent', $response->get_data()['results'][0]['state'] );
		$call = $this->toolkit_calls_to( 'submit' )[0];
		$this->assertSame( 'Isolde Marchetti: Dual-Blooded', $call['body']['title'] );
		$this->assertSame( 'hst@example.org', $call['headers']['x-oat-user-email'] );
		$this->assertStringContainsString( 'Coordinator Approval', $call['body']['note'] );
		$request = Coordinator_Request::for_character( $this->character_id )[0];
		$this->assertNull( $request->change_id );
		$this->assertSame( 'Dual-Blooded', $request->pick['name'] );
		$this->assertSame( 'sent', $request->state );
		$this->assertSame( 'pending', $this->status() );
		$this->assertSame( 'sent', $this->picks()[0]['request']['state'] );
	}

	public function test_the_server_works_out_what_is_asked_and_not_the_page(): void {
		$this->pending( [ [ 'name' => 'Dual-Blooded', 'count' => 1 ] ] );

		$response = $this->send( [ 'not-a-pick' ] );

		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( [], $this->toolkit_calls );
	}

	public function test_a_pick_that_was_sent_is_not_sent_twice(): void {
		$this->pending( [ [ 'name' => 'Dual-Blooded', 'count' => 1 ] ] );
		$key = $this->picks()[0]['key'];
		$this->send( [ $key ] );

		$again = $this->send( [ $key ] );

		$this->assertSame( 409, $again->get_status() );
		$this->assertSame( 'with_coordinator', $again->get_data()['code'] );
		$this->assertCount( 1, $this->toolkit_calls_to( 'submit' ) );
	}

	public function test_a_lost_answer_is_sent_again_under_the_same_key_and_files_once(): void {
		$this->pending( [ [ 'name' => 'Dual-Blooded', 'count' => 1 ] ] );
		$key = $this->picks()[0]['key'];
		$this->toolkit_fail['submit'] = 'timeout';
		$first = $this->send( [ $key ] );
		$this->assertSame( 'unconfirmed', $first->get_data()['results'][0]['state'] );
		$uuid = Coordinator_Request::for_character( $this->character_id )[0]->uuid;
		$this->toolkit_entries[33012] = [ 'status' => 'pending', 'current_step' => 'staff_review', 'key' => $uuid ];
		$this->toolkit_next_entry     = 33013;

		$second = $this->send( [ $key ] );

		$this->assertSame( 'sent', $second->get_data()['results'][0]['state'] );
		$this->assertCount( 1, Coordinator_Request::for_character( $this->character_id ) );
		$this->assertCount( 1, $this->toolkit_entries );
	}

	public function test_two_picks_are_filed_as_one_list(): void {
		$this->pending( [ [ 'name' => 'Dual-Blooded', 'count' => 1 ], [ 'name' => 'Shakari Enemy', 'count' => 1 ] ] );
		$keys = array_column( $this->picks(), 'key' );

		$this->send( $keys );

		$calls = $this->toolkit_calls_to( 'submit' );
		$this->assertCount( 2, $calls );
		$this->assertSame( [ 'prior' => [ 33012 ], 'mode' => 'list' ], $calls[1]['body']['batch'] );
	}

	public function test_the_character_goes_active_when_the_coordinator_approves_its_only_pick(): void {
		$this->pending( [ [ 'name' => 'Dual-Blooded', 'count' => 1 ] ] );
		$this->send( [ $this->picks()[0]['key'] ] );
		$this->toolkit_moves( 33012, 'archivist' );

		Coordinator_Reader::run( 0 );
		$request = Coordinator_Request::for_character( $this->character_id )[0];
		Coordinator_Reader::read( $request, get_user_by( 'id', $this->hst_id ) );

		$this->assertSame( 'active', $this->status() );
	}

	public function test_it_waits_while_a_pick_is_still_with_a_coordinator(): void {
		$this->pending( [ [ 'name' => 'Dual-Blooded', 'count' => 1 ], [ 'name' => 'Shakari Enemy', 'count' => 1 ] ] );
		$this->send( array_column( $this->picks(), 'key' ) );
		$this->toolkit_moves( 33012, 'archivist' );

		Coordinator_Reader::read( Coordinator_Request::find( Coordinator_Request::for_character( $this->character_id )[1]->id ), get_user_by( 'id', $this->hst_id ) );
		$this->assertSame( 'pending', $this->status(), 'One of two is not enough.' );

		$this->toolkit_moves( 33013, 'archivist' );
		Coordinator_Reader::read( Coordinator_Request::find( Coordinator_Request::for_character( $this->character_id )[0]->id ), get_user_by( 'id', $this->hst_id ) );
		$this->assertSame( 'active', $this->status() );
	}

	public function test_a_refused_pick_leaves_the_character_pending(): void {
		$this->pending( [ [ 'name' => 'Dual-Blooded', 'count' => 1 ] ] );
		$this->send( [ $this->picks()[0]['key'] ] );
		$this->toolkit_moves( 33012, 'coord_review', 'denied' );

		Coordinator_Reader::read( Coordinator_Request::for_character( $this->character_id )[0], get_user_by( 'id', $this->hst_id ) );

		$this->assertSame( 'pending', $this->status() );
		$this->assertSame( 'denied', Coordinator_Request::for_character( $this->character_id )[0]->state );
		$this->assertCount( 1, Notice::of_kind( $this->game_id, $this->hst_id, 'coordinator_answered' ) );
	}

	public function test_a_character_a_storyteller_already_made_active_is_left_alone(): void {
		$this->pending( [ [ 'name' => 'Dual-Blooded', 'count' => 1 ] ] );
		$this->send( [ $this->picks()[0]['key'] ] );
		Character::update_header( $this->character_id, [ 'status' => 'dead' ] );
		$this->toolkit_moves( 33012, 'archivist' );

		Coordinator_Reader::read( Coordinator_Request::for_character( $this->character_id )[0], get_user_by( 'id', $this->hst_id ) );

		$this->assertSame( 'dead', $this->status() );
	}

	public function test_a_pick_added_after_sending_keeps_the_character_pending(): void {
		$this->pending( [ [ 'name' => 'Dual-Blooded', 'count' => 1 ] ] );
		$this->send( [ $this->picks()[0]['key'] ] );
		Character::update_sheet_data( $this->character_id, [ 'vampire-merits' => [ [ 'name' => 'Dual-Blooded', 'count' => 1 ], [ 'name' => 'Shakari Enemy', 'count' => 1 ] ] ] );
		$this->toolkit_moves( 33012, 'archivist' );

		Coordinator_Reader::read( Coordinator_Request::for_character( $this->character_id )[0], get_user_by( 'id', $this->hst_id ) );

		$this->assertSame( 'pending', $this->status() );
	}

	public function test_notification_picks_are_filed_and_never_hold_the_character(): void {
		$this->pending( [ [ 'name' => 'True Faith', 'count' => 1 ] ] );

		$response = $this->send( [ $this->picks()[0]['key'] ], [ 'purpose' => 'notify' ] );

		$this->assertSame( 'filed', $response->get_data()['results'][0]['state'] );
		$this->assertSame( '0', $this->toolkit_calls_to( 'submit' )[0]['body']['meta']['requires_coord'] );
		$this->assertSame( 'pending', $this->status() );
	}

	public function test_the_demo_shows_a_picks_request_and_sends_nothing(): void {
		$this->set_settings( [ 'demo' => [ 'on' => true ] ] );
		$this->connect_toolkit( false );
		$this->pending( [ [ 'name' => 'Dual-Blooded', 'count' => 1 ] ] );
		Character::set_oat_link( $this->character_id, null );

		$response = $this->send( [ $this->picks()[0]['key'] ] );

		$this->assertSame( 'preview', $response->get_data()['mode'] );
		$this->assertSame( [], $this->toolkit_calls );
		$this->assertSame( [], Coordinator_Request::for_character( $this->character_id ) );
	}
}
