<?php

namespace BeyondElysium\Tests\Thread;

require_once __DIR__ . '/../support/CoordinatorTestFixture.php';

use BeyondElysium\Database\Manager;
use BeyondElysium\Models\Change;
use BeyondElysium\Models\Character;
use BeyondElysium\Models\Coordinator_Request;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Notice;
use BeyondElysium\Services\Change_Engine;
use BeyondElysium\Services\Coordinator_Sender;
use BeyondElysium\Tests\Support\CoordinatorTestFixture;
use WP_UnitTestCase;

/**
 * A Storyteller sending a waiting change to the Archivist Toolkit as themselves: what is filed, what a lost answer
 * does, what a refusal leaves behind, and the demo's preview.
 */
class CoordinatorSenderThreadTest extends WP_UnitTestCase {

	use CoordinatorTestFixture;

	private const TOOLKIT_UUID = 'a3f1c0de-0000-4000-8000-000000000001';
	private const REASON       = 'Coordinator Approval — Assamite Coordinator [OWBN Character Bylaws 10.m.v.10, clause 8992]';

	private int $character_id = 0;

	public function setUp(): void {
		parent::setUp();
		$this->make_chronicle( 'chronicle/kony', [ 'coordinator_send' => true ] );
		$this->connect_toolkit();
		$this->fake_toolkit();
		$this->character_id = Character::create( [
			'name' => 'Isolde Marchetti', 'stack_slug' => 'vampire',
			'owner_type' => 'chronicle', 'owner_slug' => $this->slug, 'wp_user_id' => $this->player_id,
			'sheet_data' => [ 'vampire-merits' => [] ],
		] );
		Character::update_xp( $this->character_id, 20, 20 );
		Character::set_oat_link( $this->character_id, self::TOOLKIT_UUID );
	}

	public function tearDown(): void {
		$this->connect_toolkit( false );
		remove_all_filters( 'pre_http_request' );
		parent::tearDown();
	}

	private function waiting( string $name = 'Dual-Blooded', int $count = 2 ): int {
		$id = Change_Engine::submit( $this->character_id, [
			'change_type' => 'add_trait',
			'category'    => 'vampire-merits',
			'change_data' => [ 'block_slug' => 'vampire-merits', 'trait' => [ 'name' => $name, 'count' => $count ] ],
			'xp_cost'     => $count,
			'notes'       => 'Her sire was a Ravnos.',
		], $this->player_id );
		Manager::update( 'character_changes', [ 'reason' => self::REASON ], [ 'id' => $id ] );
		$this->assertSame( 'pending', Change::find( $id )->status );
		return $id;
	}

	/**
	 * @param int[]               $ids
	 * @param array<string,mixed> $options
	 * @return array<string,mixed>|\WP_Error
	 */
	private function send( array $ids, array $options = [], ?int $as = null ) {
		wp_set_current_user( $as ?? $this->hst_id );
		return Coordinator_Sender::send(
			$this->game(),
			get_user_by( 'id', $as ?? $this->hst_id ),
			Character::find( $this->character_id ),
			$ids,
			array_merge( [ 'rules' => [ [ 'id' => 4125, 'pc_level' => 'coordinator_approval', 'npc_level' => 'coordinator_approval' ] ], 'coordinators' => [ 'assamite' ], 'note' => 'Please look at the Assamite side.' ], $options )
		);
	}

	private function unspent(): int {
		return (int) Character::find( $this->character_id )->xp_unspent;
	}

	public function test_a_send_files_one_request_as_the_storyteller_and_keeps_the_change_waiting(): void {
		$change = $this->waiting();

		$sent = $this->send( [ $change ] );

		$this->assertSame( 'live', $sent['mode'] );
		$this->assertSame( 'sent', $sent['results'][0]['state'] );
		$calls = $this->toolkit_calls_to( 'submit' );
		$this->assertCount( 1, $calls );
		$this->assertSame( 'hst@example.org', $calls[0]['headers']['x-oat-user-email'] );
		$this->assertSame( $this->toolkit_key, $calls[0]['headers']['x-api-key'] );
		$body = $calls[0]['body'];
		$this->assertSame( 'cl_ru_request', $body['form_slug'] );
		$this->assertSame( 'kony', $body['meta']['chronicle_slug'] );
		$this->assertSame( self::TOOLKIT_UUID, $body['meta']['character_name'] );
		$this->assertSame( [ 4125 ], $body['rules'] );
		$this->assertSame( '["assamite"]', $body['meta']['involved_coordinators'] );
		$this->assertTrue( $body['full_chain'] );
		$this->assertStringContainsString( 'Dual-Blooded', $body['title'] );
		$this->assertStringContainsString( 'Please look at the Assamite side.', $body['note'] );

		$request = Coordinator_Request::for_change( $change );
		$this->assertSame( 'sent', $request->state );
		$this->assertSame( 33012, $request->oat_entry_id );
		$this->assertSame( 'staff_review', $request->oat_step );
		$this->assertSame( $this->hst_id, $request->sent_by );
		$this->assertSame( $request->uuid, $body['idempotency_key'] );

		$this->assertSame( 'pending', Change::find( $change )->status, 'The change is not decided by sending it.' );
		$this->assertSame( 20, $this->unspent(), 'No XP moves until the Coordinator approves.' );
	}

	public function test_several_changes_are_filed_one_after_another_as_one_list(): void {
		$first  = $this->waiting( 'Dual-Blooded' );
		$second = $this->waiting( 'Extinct Animal Form', 1 );

		$sent = $this->send( [ $first, $second ] );

		$this->assertSame( [ 'sent', 'sent' ], array_column( $sent['results'], 'state' ) );
		$calls = $this->toolkit_calls_to( 'submit' );
		$this->assertCount( 2, $calls );
		$this->assertArrayNotHasKey( 'batch', $calls[0]['body'] );
		$this->assertSame( [ 'prior' => [ 33012 ], 'mode' => 'list' ], $calls[1]['body']['batch'] );
	}

	public function test_a_lost_answer_is_sent_again_under_the_same_key_and_files_once(): void {
		$change = $this->waiting();
		$this->toolkit_fail['submit'] = 'timeout';

		$first = $this->send( [ $change ] );

		$this->assertSame( 'unconfirmed', $first['results'][0]['state'] );
		$row = Coordinator_Request::for_change( $change );
		$this->assertSame( 'unconfirmed', $row->state );
		$this->assertNull( $row->oat_entry_id );

		// The Toolkit did file it before the answer was lost.
		$this->toolkit_entries[ 33012 ] = [ 'status' => 'pending', 'current_step' => 'staff_review', 'key' => $row->uuid ];
		$this->toolkit_next_entry       = 33013;

		$second = $this->send( [ $change ] );

		$this->assertSame( 'sent', $second['results'][0]['state'] );
		$again = Coordinator_Request::for_change( $change );
		$this->assertSame( $row->id, $again->id, 'The same row is used, so there is one record.' );
		$this->assertSame( $row->uuid, $again->uuid );
		$this->assertSame( 33012, $again->oat_entry_id );
		$this->assertCount( 1, $this->toolkit_entries, 'Nothing was filed twice.' );
	}

	public function test_a_hosting_challenge_leaves_it_unconfirmed(): void {
		$change = $this->waiting();
		$this->toolkit_fail['submit'] = 'challenge';

		$sent = $this->send( [ $change ] );

		$this->assertSame( 'unconfirmed', $sent['results'][0]['state'] );
		$this->assertSame( 'unconfirmed', Coordinator_Request::for_change( $change )->state );
	}

	public function test_the_toolkits_refusal_is_shown_and_nothing_is_kept(): void {
		$change = $this->waiting();
		$this->toolkit_fail['submit'] = [ 422, [ 'code' => 'oat_character_not_yours', 'message' => "You can't file this for that character." ] ];

		$sent = $this->send( [ $change ] );

		$this->assertSame( 'refused', $sent['results'][0]['state'] );
		$this->assertSame( 'oat_character_not_yours', $sent['results'][0]['code'] );
		$this->assertSame( "You can't file this for that character.", $sent['results'][0]['message'] );
		$this->assertNull( Coordinator_Request::for_change( $change ), 'A refused send leaves no row.' );
		$this->assertSame( 'pending', Change::find( $change )->status );
	}

	public function test_after_a_failure_the_lines_that_follow_are_not_sent(): void {
		$first  = $this->waiting( 'Dual-Blooded' );
		$second = $this->waiting( 'Extinct Animal Form', 1 );
		$this->toolkit_fail['submit'] = 'timeout';

		$sent = $this->send( [ $first, $second ] );

		$this->assertSame( [ 'unconfirmed', 'not_sent' ], array_column( $sent['results'], 'state' ) );
		$this->assertNull( Coordinator_Request::for_change( $second ) );
		$this->assertCount( 1, $this->toolkit_calls_to( 'submit' ) );
	}

	public function test_a_character_not_linked_to_the_toolkit_is_refused_before_any_call(): void {
		Character::set_oat_link( $this->character_id, null );

		$sent = $this->send( [ $this->waiting() ] );

		$this->assertWPError( $sent );
		$this->assertSame( 'oat_character_not_linked', $sent->get_error_code() );
		$this->assertSame( [], $this->toolkit_calls );
	}

	public function test_a_chronicle_with_the_switch_off_does_not_send(): void {
		$change = $this->waiting();
		$this->set_settings( [ 'coordinator_send' => false ] );

		$sent = $this->send( [ $change ] );

		$this->assertWPError( $sent );
		$this->assertSame( 'coordinator_send_off', $sent->get_error_code() );
		$this->assertSame( [], $this->toolkit_calls );
	}

	public function test_a_change_already_with_a_coordinator_is_not_sent_twice(): void {
		$change = $this->waiting();
		$this->send( [ $change ] );

		$again = $this->send( [ $change ] );

		$this->assertWPError( $again );
		$this->assertSame( 'with_coordinator', $again->get_error_code() );
		$this->assertCount( 1, $this->toolkit_calls_to( 'submit' ) );
	}

	public function test_a_change_the_coordinator_already_answered_is_not_sent_again(): void {
		$change = $this->waiting();
		$this->send( [ $change ] );
		Coordinator_Request::mark( Coordinator_Request::for_change( $change )->id, [ 'state' => 'denied' ] );

		$again = $this->send( [ $change ] );

		$this->assertWPError( $again );
		$this->assertSame( 'coordinator_answered', $again->get_error_code() );
	}

	public function test_a_change_that_is_no_longer_waiting_is_not_sent_for_approval(): void {
		$change = $this->waiting();
		Change_Engine::reject( $change, $this->hst_id, 'No.' );

		$sent = $this->send( [ $change ] );

		$this->assertWPError( $sent );
		$this->assertSame( 'not_waiting', $sent->get_error_code() );
	}

	public function test_a_change_of_another_character_is_not_found(): void {
		$other = Character::create( [ 'name' => 'Other', 'stack_slug' => 'vampire', 'owner_type' => 'chronicle', 'owner_slug' => $this->slug, 'wp_user_id' => $this->player_id, 'sheet_data' => [] ] );
		$id    = Change_Engine::submit( $other, [ 'change_type' => 'add_trait', 'category' => 'vampire-merits', 'change_data' => [ 'block_slug' => 'vampire-merits', 'trait' => [ 'name' => 'X' ] ], 'xp_cost' => 1 ], $this->player_id );

		$sent = $this->send( [ $id ] );

		$this->assertWPError( $sent );
		$this->assertSame( 'not_found', $sent->get_error_code() );
	}

	public function test_a_notification_approves_the_change_first_and_files_it_as_a_notification(): void {
		$change = $this->waiting();

		$sent = $this->send( [ $change ], [ 'purpose' => 'notify', 'rules' => [ [ 'id' => 9, 'pc_level' => 'coordinator_notify', 'npc_level' => 'coordinator_notify' ] ] ] );

		$this->assertSame( 'filed', $sent['results'][0]['state'] );
		$this->assertSame( 'approved', Change::find( $change )->status );
		$this->assertSame( 18, $this->unspent(), 'The ordinary approval took its XP.' );
		$body = $this->toolkit_calls_to( 'submit' )[0]['body'];
		$this->assertSame( '0', $body['meta']['requires_coord'] );
		$this->assertSame( 'coordinator_notify', $body['meta']['regulation_level'] );
		$this->assertSame( 'filed', Coordinator_Request::for_change( $change )->state );
		$this->assertSame( 'notify', Coordinator_Request::for_change( $change )->purpose );
	}

	public function test_a_notification_that_fails_to_file_leaves_the_change_approved_and_can_be_sent_again(): void {
		$change = $this->waiting();
		$this->toolkit_fail['submit'] = 'timeout';

		$first = $this->send( [ $change ], [ 'purpose' => 'notify' ] );

		$this->assertSame( 'unconfirmed', $first['results'][0]['state'] );
		$this->assertSame( 'approved', Change::find( $change )->status );

		$second = $this->send( [ $change ], [ 'purpose' => 'notify' ] );

		$this->assertSame( 'filed', $second['results'][0]['state'] );
		$this->assertSame( 18, $this->unspent(), 'The XP was taken once.' );
	}

	public function test_the_player_is_told_it_went(): void {
		$change = $this->waiting();

		$this->send( [ $change ] );

		$notices = Notice::of_kind( $this->game_id, $this->player_id, 'coordinator_sent' );
		$this->assertCount( 1, $notices );
		$this->assertSame( 'Isolde Marchetti', $notices[0]->payload['character_name'] );
		$this->assertSame( 'Dual-Blooded', $notices[0]->payload['text'] );
		$this->assertSame( [ 'Assamite' ], $notices[0]->payload['offices'] );
	}

	public function test_the_demo_shows_what_would_be_sent_and_sends_nothing(): void {
		$this->set_settings( [ 'demo' => [ 'on' => true ] ] );
		$this->connect_toolkit( false );
		Character::set_oat_link( $this->character_id, null );
		$change = $this->waiting();

		$sent = $this->send( [ $change ] );

		$this->assertSame( 'preview', $sent['mode'] );
		$this->assertSame( [], $this->toolkit_calls );
		$this->assertNull( Coordinator_Request::for_change( $change ) );
		$this->assertSame( 'pending', Change::find( $change )->status );
		$preview = $sent['previews'][0];
		$this->assertSame( $change, $preview['change_id'] );
		$this->assertSame( 'cl_ru_request', $preview['body']['form_slug'] );
		$this->assertStringContainsString( 'Dual-Blooded', $preview['body']['title'] );
		$this->assertSame( [ 4125 ], $preview['body']['rules'] );
		$this->assertSame( [], Notice::of_kind( $this->game_id, $this->player_id, 'coordinator_sent' ) );
	}

	public function test_the_key_is_in_nothing_a_send_returns_or_keeps(): void {
		$change = $this->waiting();

		$sent = $this->send( [ $change ] );

		$this->assertStringNotContainsString( $this->toolkit_key, (string) wp_json_encode( [ $sent, Coordinator_Request::for_change( $change ) ] ) );
	}
}
