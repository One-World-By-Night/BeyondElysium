<?php

namespace BeyondElysium\Tests\Thread;

require_once __DIR__ . '/../support/CoordinatorTestFixture.php';

use BeyondElysium\Core\Maintenance;
use BeyondElysium\Database\Manager;
use BeyondElysium\Models\Change;
use BeyondElysium\Models\Character;
use BeyondElysium\Models\Coordinator_Request;
use BeyondElysium\Models\Notice;
use BeyondElysium\Services\Change_Engine;
use BeyondElysium\Services\Coordinator_Reader;
use BeyondElysium\Services\Coordinator_Sender;
use BeyondElysium\Services\Notices;
use BeyondElysium\Tests\Support\CoordinatorTestFixture;
use WP_UnitTestCase;

/**
 * Reading where the Toolkit's request stands, as the Storyteller who sent it, and what BE does with the answer.
 */
class CoordinatorReaderThreadTest extends WP_UnitTestCase {

	use CoordinatorTestFixture;

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
		Character::set_oat_link( $this->character_id, 'a3f1c0de-0000-4000-8000-000000000001' );
	}

	public function tearDown(): void {
		$this->connect_toolkit( false );
		remove_all_filters( 'pre_http_request' );
		parent::tearDown();
	}

	/**
	 * A change waiting and sent to the Toolkit; returns [ change id, request row ].
	 *
	 * @return array{0:int,1:object}
	 */
	private function sent( string $name = 'Dual-Blooded', int $count = 2 ): array {
		$id = Change_Engine::submit( $this->character_id, [
			'change_type' => 'add_trait',
			'category'    => 'vampire-merits',
			'change_data' => [ 'block_slug' => 'vampire-merits', 'trait' => [ 'name' => $name, 'count' => $count ] ],
			'xp_cost'     => $count,
		], $this->player_id );
		Manager::update( 'character_changes', [ 'reason' => 'Coordinator Approval — Assamite Coordinator [OWBN Character Bylaws 10.m.v.10, clause 8992]' ], [ 'id' => $id ] );
		wp_set_current_user( $this->hst_id );
		Coordinator_Sender::send( $this->game(), get_user_by( 'id', $this->hst_id ), Character::find( $this->character_id ), [ $id ], [ 'rules' => [], 'coordinators' => [ 'assamite' ] ] );
		$row = Coordinator_Request::for_change( $id );
		$this->assertSame( 'sent', $row->state );
		return [ $id, $row ];
	}

	private function read( object $request, ?int $as = null ): array {
		return Coordinator_Reader::read( Coordinator_Request::find( $request->id ), get_user_by( 'id', $as ?? $this->hst_id ) );
	}

	private function unspent(): int {
		return (int) Character::find( $this->character_id )->xp_unspent;
	}

	public function test_a_request_still_with_the_storyteller_stays_sent_and_records_where_it_is(): void {
		[ $change, $request ] = $this->sent();

		$result = $this->read( $request );

		$this->assertFalse( $result['stop'] );
		$row = Coordinator_Request::find( $request->id );
		$this->assertSame( 'sent', $row->state );
		$this->assertSame( 'staff_review', $row->oat_step );
		$this->assertNotNull( $row->checked_at );
		$this->assertSame( 'pending', Change::find( $change )->status );
	}

	public function test_it_is_read_as_the_storyteller_who_sent_it(): void {
		[ , $request ] = $this->sent();
		$this->toolkit_calls = [];

		$this->read( $request, $this->hst_id );

		$call = $this->toolkit_calls_to( 'entry/' . $request->oat_entry_id )[0];
		$this->assertSame( 'hst@example.org', $call['headers']['x-oat-user-email'] );
	}

	public function test_while_the_coordinator_has_it_the_step_says_so(): void {
		[ , $request ] = $this->sent();
		$this->toolkit_moves( (int) $request->oat_entry_id, 'coord_review' );

		$this->read( $request );

		$row = Coordinator_Request::find( $request->id );
		$this->assertSame( 'sent', $row->state );
		$this->assertSame( 'coord_review', $row->oat_step );
		$this->assertSame( 'With the Coordinator', Coordinator_Request::step_label( $row->oat_step ) );
	}

	public function test_when_the_coordinator_has_approved_the_change_is_applied_by_the_sender(): void {
		[ $change, $request ] = $this->sent();
		$this->toolkit_moves( (int) $request->oat_entry_id, 'archivist' );

		$this->read( $request );

		$row = Coordinator_Request::find( $request->id );
		$this->assertSame( 'approved', $row->state );
		$this->assertNotNull( $row->answered_at );
		$applied = Change::find( $change );
		$this->assertSame( 'approved', $applied->status );
		$this->assertSame( $this->hst_id, (int) $applied->reviewed_by );
		$this->assertStringContainsString( 'Approved by the Coordinator in the Archivist Toolkit (R&U #' . $request->oat_entry_id . ')', (string) $applied->review_notes );
		$this->assertSame( 18, $this->unspent() );
	}

	public function test_a_request_the_toolkit_marks_approved_is_the_same(): void {
		[ $change, $request ] = $this->sent();
		$this->toolkit_moves( (int) $request->oat_entry_id, 'archivist', 'approved' );

		$this->read( $request );

		$this->assertSame( 'approved', Change::find( $change )->status );
	}

	public function test_the_sender_is_told_the_answer_and_the_player_hears_of_the_decision(): void {
		[ , $request ] = $this->sent();
		$this->toolkit_moves( (int) $request->oat_entry_id, 'archivist' );

		$this->read( $request );
		Notices::flush_decided();

		$told = Notice::of_kind( $this->game_id, $this->hst_id, 'coordinator_answered' );
		$this->assertCount( 1, $told );
		$this->assertSame( 'approved', $told[0]->payload['outcome'] );
		$this->assertSame( 'Dual-Blooded', $told[0]->payload['text'] );
		$this->assertNotSame( [], Notice::for_person( $this->game_id, $this->player_id ), 'The player hears of the decision as for any change.' );
	}

	public function test_a_denial_rejects_the_change_and_keeps_the_xp(): void {
		[ $change, $request ] = $this->sent();
		$this->toolkit_moves( (int) $request->oat_entry_id, 'coord_review', 'denied' );

		$this->read( $request );

		$this->assertSame( 'denied', Coordinator_Request::find( $request->id )->state );
		$rejected = Change::find( $change );
		$this->assertSame( 'rejected', $rejected->status );
		$this->assertStringContainsString( 'Not approved by the Coordinator in the Archivist Toolkit (R&U #' . $request->oat_entry_id . ')', (string) $rejected->review_notes );
		$this->assertSame( 20, $this->unspent() );
	}

	public function test_an_automatic_denial_is_a_denial(): void {
		[ $change, $request ] = $this->sent();
		$this->toolkit_moves( (int) $request->oat_entry_id, 'coord_review', 'auto_denied' );

		$this->read( $request );

		$this->assertSame( 'denied', Coordinator_Request::find( $request->id )->state );
		$this->assertSame( 'rejected', Change::find( $change )->status );
	}

	public function test_a_cancelled_request_rejects_the_change_with_its_own_words(): void {
		[ $change, $request ] = $this->sent();
		$this->toolkit_moves( (int) $request->oat_entry_id, 'staff_review', 'cancelled' );

		$this->read( $request );

		$this->assertSame( 'cancelled', Coordinator_Request::find( $request->id )->state );
		$this->assertStringContainsString( 'Cancelled in the Archivist Toolkit', (string) Change::find( $change )->review_notes );
		$this->assertSame( 'rejected', Change::find( $change )->status );
	}

	public function test_a_change_a_storyteller_already_approved_is_left_alone(): void {
		[ $change, $request ] = $this->sent();
		Change_Engine::approve( $change, $this->hst_id, 'The Coordinator told me by email.' );
		$this->assertSame( 18, $this->unspent() );
		$this->toolkit_moves( (int) $request->oat_entry_id, 'archivist' );

		$this->read( $request );

		$this->assertSame( 'approved', Coordinator_Request::find( $request->id )->state );
		$this->assertSame( 18, $this->unspent(), 'The XP is taken once.' );
		$this->assertSame( 'The Coordinator told me by email.', Change::find( $change )->review_notes );
	}

	public function test_a_change_a_storyteller_already_rejected_is_left_alone(): void {
		[ $change, $request ] = $this->sent();
		Change_Engine::reject( $change, $this->hst_id, 'No.' );
		$this->toolkit_moves( (int) $request->oat_entry_id, 'archivist' );

		$this->read( $request );

		$this->assertSame( 'rejected', Change::find( $change )->status );
		$this->assertSame( 20, $this->unspent() );
	}

	public function test_an_approval_that_cannot_be_applied_leaves_the_change_waiting_and_says_why(): void {
		[ $change, $request ] = $this->sent();
		Character::update_xp( $this->character_id, 0, -20 );
		$this->toolkit_moves( (int) $request->oat_entry_id, 'archivist' );

		$this->read( $request );

		$row = Coordinator_Request::find( $request->id );
		$this->assertSame( 'approved', $row->state );
		$this->assertSame( 'pending', Change::find( $change )->status );
		$this->assertStringContainsString( 'could not be applied', (string) $row->check_error );
	}

	public function test_no_answer_leaves_it_as_it_was_and_stops(): void {
		[ , $request ] = $this->sent();
		$this->toolkit_fail[ 'entry/' . $request->oat_entry_id ] = 'timeout';

		$result = $this->read( $request );

		$this->assertTrue( $result['stop'] );
		$row = Coordinator_Request::find( $request->id );
		$this->assertSame( 'sent', $row->state );
		$this->assertStringContainsString( "didn't answer", (string) $row->check_error );
		$this->assertNotNull( $row->checked_at );
	}

	public function test_a_request_the_toolkit_does_not_have_is_noted_and_kept(): void {
		[ , $request ] = $this->sent();
		unset( $this->toolkit_entries[ (int) $request->oat_entry_id ] );

		$result = $this->read( $request );

		$this->assertTrue( $result['stop'] );
		$this->assertSame( 'sent', Coordinator_Request::find( $request->id )->state );
		$this->assertStringContainsString( 'not found', strtolower( (string) Coordinator_Request::find( $request->id )->check_error ) );
	}

	public function test_a_good_read_clears_an_earlier_error(): void {
		[ , $request ] = $this->sent();
		Coordinator_Request::mark( $request->id, [ 'check_error' => 'Old trouble.' ] );

		$this->read( $request );

		$this->assertNull( Coordinator_Request::find( $request->id )->check_error );
	}

	public function test_the_job_reads_every_due_request_and_reports_how_many(): void {
		[ , $first ]  = $this->sent( 'Dual-Blooded' );
		[ , $second ] = $this->sent( 'Extinct Animal Form', 1 );
		$this->toolkit_moves( (int) $second->oat_entry_id, 'archivist' );
		Coordinator_Request::mark( $first->id, [ 'checked_at' => wp_date( 'Y-m-d H:i:s', time() - 3 * HOUR_IN_SECONDS ) ] );
		Coordinator_Request::mark( $second->id, [ 'checked_at' => wp_date( 'Y-m-d H:i:s', time() - 2 * HOUR_IN_SECONDS ) ] );
		$this->toolkit_calls = [];

		$ran = Coordinator_Reader::run( 0 );

		$this->assertSame( 2, $ran['read'] );
		$this->assertSame( [ 'entry/' . $first->oat_entry_id, 'entry/' . $second->oat_entry_id ], array_column( $this->toolkit_calls, 'route' ), 'The longest unread goes first.' );
		$this->assertSame( 'approved', Coordinator_Request::find( $second->id )->state );
	}

	public function test_the_job_skips_what_was_read_in_the_last_55_minutes(): void {
		[ , $request ] = $this->sent();
		$this->toolkit_calls = [];

		$ran = Coordinator_Reader::run( 0 );

		$this->assertSame( 0, $ran['read'] );
		$this->assertSame( [], $this->toolkit_calls );
		$this->assertSame( $request->id, Coordinator_Request::for_change( $request->change_id )->id );
	}

	public function test_the_job_stops_at_the_first_failure(): void {
		[ , $first ]  = $this->sent( 'Dual-Blooded' );
		[ , $second ] = $this->sent( 'Extinct Animal Form', 1 );
		foreach ( [ $first, $second ] as $request ) {
			Coordinator_Request::mark( $request->id, [ 'checked_at' => wp_date( 'Y-m-d H:i:s', time() - 3 * HOUR_IN_SECONDS ) ] );
		}
		$this->toolkit_fail['*'] = 'challenge';
		$this->toolkit_calls     = [];

		$ran = Coordinator_Reader::run( 0 );

		$this->assertSame( 1, $ran['read'] );
		$this->assertTrue( $ran['stopped'] );
		$this->assertCount( 1, $this->toolkit_calls );
	}

	public function test_the_job_reads_at_most_twenty(): void {
		for ( $i = 0; $i < 22; $i++ ) {
			$id = Coordinator_Request::create( [ 'uuid' => wp_generate_uuid4(), 'game_id' => $this->game_id, 'character_id' => $this->character_id, 'change_id' => 9000 + $i, 'purpose' => 'approval', 'sent_by' => $this->hst_id ] );
			Coordinator_Request::mark( $id, [ 'state' => 'sent', 'oat_entry_id' => 40000 + $id ] );
			$this->toolkit_entries[ 40000 + $id ] = [ 'status' => 'pending', 'current_step' => 'staff_review' ];
		}

		$ran = Coordinator_Reader::run( 0 );

		$this->assertSame( 20, $ran['read'] );
		$this->assertFalse( $ran['stopped'] );
	}

	public function test_the_job_does_not_read_a_chronicle_that_has_switched_it_off(): void {
		[ , $request ] = $this->sent();
		Coordinator_Request::mark( $request->id, [ 'checked_at' => wp_date( 'Y-m-d H:i:s', time() - 3 * HOUR_IN_SECONDS ) ] );
		$this->set_settings( [ 'coordinator_send' => false ] );
		$this->toolkit_calls = [];

		$ran = Coordinator_Reader::run( 0 );

		$this->assertSame( 0, $ran['read'] );
		$this->assertSame( [], $this->toolkit_calls );
	}

	public function test_the_job_notes_a_sender_who_no_longer_has_an_account_and_goes_on(): void {
		[ , $gone ]  = $this->sent( 'Dual-Blooded' );
		[ , $other ] = $this->sent( 'Extinct Animal Form', 1 );
		Manager::update( 'coordinator_requests', [ 'sent_by' => 999999 ], [ 'id' => $gone->id ] );
		foreach ( [ $gone, $other ] as $request ) {
			Coordinator_Request::mark( $request->id, [ 'checked_at' => wp_date( 'Y-m-d H:i:s', time() - 3 * HOUR_IN_SECONDS ) ] );
		}

		$ran = Coordinator_Reader::run( 0 );

		$this->assertSame( 1, $ran['read'] );
		$this->assertStringContainsString( 'account', (string) Coordinator_Request::find( $gone->id )->check_error );
	}

	public function test_a_notification_is_never_read_back(): void {
		[ , $request ] = $this->sent();
		Coordinator_Request::mark( $request->id, [ 'state' => 'filed', 'purpose' => 'notify' ] );

		$result = $this->read( $request );

		$this->assertFalse( $result['stop'] );
		$this->assertSame( 'filed', Coordinator_Request::find( $request->id )->state );
		$this->assertSame( [], $this->toolkit_calls_to( 'entry/' . $request->oat_entry_id ) );
	}

	public function test_check_now_reads_as_the_person_who_asks_even_when_it_was_read_a_minute_ago(): void {
		[ , $request ] = $this->sent();
		$this->toolkit_calls = [];

		$this->read( $request, $this->ast_id );

		$this->assertSame( 'ast@example.org', $this->toolkit_calls_to( 'entry/' . $request->oat_entry_id )[0]['headers']['x-oat-user-email'] );
	}

	public function test_the_hourly_job_is_scheduled(): void {
		wp_clear_scheduled_hook( Maintenance::COORDINATOR_HOOK );

		Maintenance::schedule();

		$this->assertNotFalse( wp_next_scheduled( Maintenance::COORDINATOR_HOOK ) );
		$this->assertSame( 'hourly', wp_get_scheduled_event( Maintenance::COORDINATOR_HOOK )->schedule );
		Maintenance::unschedule();
		$this->assertFalse( wp_next_scheduled( Maintenance::COORDINATOR_HOOK ) );
	}
}
