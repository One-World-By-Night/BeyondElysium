<?php

namespace BeyondElysium\Tests\Thread;

require_once __DIR__ . '/../support/CoordinatorTestFixture.php';

use BeyondElysium\Database\Manager;
use BeyondElysium\Models\Change;
use BeyondElysium\Models\Character;
use BeyondElysium\Models\Coordinator_Request;
use BeyondElysium\Services\Change_Engine;
use BeyondElysium\Services\Storyteller_Home;
use BeyondElysium\Tests\Support\CoordinatorTestFixture;
use WP_UnitTestCase;

/**
 * The Approval Queue, a player's own views and the cart's preview where a chronicle sends to the Toolkit: what each row
 * carries, what a bulk approve leaves for a Storyteller to decide, and what a send takes away from the player.
 */
class CoordinatorQueueThreadTest extends WP_UnitTestCase {

	use CoordinatorTestFixture;

	private const APPROVAL = 'Coordinator Approval — Assamite Coordinator [OWBN Character Bylaws 10.m.v.10, clause 8992]';
	private const NOTIFY   = 'Coordinator Notify — Hunter Coordinator [OWBN Character Bylaws 10.e.v, clause 7838]';

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
		Character::update_xp( $this->character_id, 40, 40 );
		Character::set_oat_link( $this->character_id, 'a3f1c0de-0000-4000-8000-000000000001' );
	}

	public function tearDown(): void {
		$this->connect_toolkit( false );
		remove_all_filters( 'pre_http_request' );
		parent::tearDown();
	}

	private function waiting( string $name, ?string $reason, ?string $submission = null, int $count = 1 ): int {
		$id = Change_Engine::submit( $this->character_id, [
			'change_type'   => 'add_trait', 'category' => 'vampire-merits',
			'change_data'   => [ 'block_slug' => 'vampire-merits', 'trait' => [ 'name' => $name, 'count' => $count ] ],
			'xp_cost'       => $count,
			'submission_id' => $submission,
		], $this->player_id );
		Manager::update( 'character_changes', [ 'reason' => $reason ], [ 'id' => $id ] );
		return $id;
	}

	private function send( int $change, array $over = [] ) {
		return $this->rest( $this->hst_id, 'POST', "{$this->slug}/coordinator/send", array_merge( [ 'character_id' => $this->character_id, 'change_ids' => [ $change ], 'rules' => [], 'coordinators' => [ 'assamite' ] ], $over ) );
	}

	/**
	 * @return array<int,array<string,mixed>> the queue's rows by change id
	 */
	private function queue( int $as = 0 ): array {
		$rows = [];
		foreach ( $this->rest( $as ?: $this->hst_id, 'GET', "{$this->slug}/changes" )->get_data() as $row ) {
			$rows[ (int) ( is_object( $row ) ? $row->id : $row['id'] ) ] = json_decode( (string) wp_json_encode( $row ), true );
		}
		return $rows;
	}

	public function test_a_queue_row_says_what_its_reason_asks_of_a_coordinator(): void {
		$approval = $this->waiting( 'Dual-Blooded', self::APPROVAL );
		$notify   = $this->waiting( 'True Faith', self::NOTIFY );
		$plain    = $this->waiting( 'Occult', null );

		$rows = $this->queue();

		$this->assertSame( 'approval', $rows[ $approval ]['coordinator']['signal']['kind'] );
		$this->assertSame( [ 'Assamite' ], $rows[ $approval ]['coordinator']['signal']['coordinators'] );
		$this->assertNull( $rows[ $approval ]['coordinator']['request'] );
		$this->assertSame( 'notify', $rows[ $notify ]['coordinator']['signal']['kind'] );
		$this->assertNull( $rows[ $plain ]['coordinator']['signal']['kind'] );
	}

	public function test_a_queue_row_carries_the_request_once_it_is_sent(): void {
		$change = $this->waiting( 'Dual-Blooded', self::APPROVAL );
		$this->send( $change );

		$request = $this->queue()[ $change ]['coordinator']['request'];

		$this->assertSame( 'sent', $request['state'] );
		$this->assertSame( 33012, $request['oat_entry_id'] );
		$this->assertSame( 'staff_review', $request['step'] );
		$this->assertArrayHasKey( 'entry_url', $request );
	}

	public function test_with_the_switch_off_a_row_carries_nothing_about_a_coordinator(): void {
		$change = $this->waiting( 'Dual-Blooded', self::APPROVAL );
		$this->set_settings( [ 'coordinator_send' => false ] );

		$this->assertArrayNotHasKey( 'coordinator', $this->queue()[ $change ] );
	}

	public function test_a_bulk_approve_leaves_a_coordinator_approval_for_a_storyteller_to_decide(): void {
		$approval = $this->waiting( 'Dual-Blooded', self::APPROVAL );
		$plain    = $this->waiting( 'Occult', null );

		$data = $this->rest( $this->hst_id, 'POST', "{$this->slug}/changes/batch-approve", [ 'change_ids' => [ $approval, $plain ] ] )->get_data();

		$this->assertSame( [ $plain ], $data['approved'] );
		$this->assertSame( [ $approval ], $data['needs_coordinator'] );
		$this->assertSame( 'pending', Change::find( $approval )->status );
		$this->assertSame( 'approved', Change::find( $plain )->status );
	}

	public function test_a_set_with_a_coordinator_approval_is_held_whole(): void {
		$submission = wp_generate_uuid4();
		$approval   = $this->waiting( 'Dual-Blooded', self::APPROVAL, $submission );
		$plain      = $this->waiting( 'Occult', null, $submission );

		$data = $this->rest( $this->hst_id, 'POST', "{$this->slug}/changes/batch-approve", [ 'change_ids' => [ $approval, $plain ] ] )->get_data();

		$this->assertSame( [], $data['approved'] );
		$this->assertSame( [ $approval ], $data['needs_coordinator'] );
		$this->assertSame( [ $plain ], $data['held'] );
		$this->assertSame( 'pending', Change::find( $plain )->status );
	}

	public function test_a_bulk_approve_still_approves_a_notification(): void {
		$notify = $this->waiting( 'True Faith', self::NOTIFY );

		$data = $this->rest( $this->hst_id, 'POST', "{$this->slug}/changes/batch-approve", [ 'change_ids' => [ $notify ] ] )->get_data();

		$this->assertSame( [ $notify ], $data['approved'] );
		$this->assertSame( [], $data['needs_coordinator'] );
	}

	public function test_where_the_switch_is_off_a_bulk_approve_is_as_it_was(): void {
		$approval = $this->waiting( 'Dual-Blooded', self::APPROVAL );
		$this->set_settings( [ 'coordinator_send' => false ] );

		$data = $this->rest( $this->hst_id, 'POST', "{$this->slug}/changes/batch-approve", [ 'change_ids' => [ $approval ] ] )->get_data();

		$this->assertSame( [ $approval ], $data['approved'] );
		$this->assertSame( [], $data['needs_coordinator'] );
	}

	public function test_approving_a_coordinator_approval_one_at_a_time_is_the_storytellers_call(): void {
		$change = $this->waiting( 'Dual-Blooded', self::APPROVAL );

		$response = $this->rest( $this->hst_id, 'PUT', "{$this->slug}/changes/{$change}", [ 'status' => 'approved' ] );

		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$this->assertSame( 'approved', Change::find( $change )->status );
	}

	public function test_a_storyteller_can_still_approve_or_reject_a_change_already_sent(): void {
		$first  = $this->waiting( 'Dual-Blooded', self::APPROVAL );
		$second = $this->waiting( 'Rune-Wise', self::APPROVAL );
		$this->send( $first );
		$this->send( $second );

		$approved = $this->rest( $this->hst_id, 'PUT', "{$this->slug}/changes/{$first}", [ 'status' => 'approved' ] );
		$rejected = $this->rest( $this->hst_id, 'PUT', "{$this->slug}/changes/{$second}", [ 'status' => 'rejected', 'notes' => 'No.' ] );

		$this->assertSame( 200, $approved->get_status() );
		$this->assertSame( 200, $rejected->get_status() );
		$this->assertSame( 'approved', Change::find( $first )->status );
		$this->assertSame( 'rejected', Change::find( $second )->status );
	}

	public function test_a_player_cannot_withdraw_a_change_that_is_with_a_coordinator(): void {
		$change = $this->waiting( 'Dual-Blooded', self::APPROVAL );
		$this->send( $change );

		$response = $this->rest( $this->player_id, 'POST', "{$this->slug}/changes/{$change}/withdraw" );

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'with_coordinator', $response->get_data()['code'] );
		$this->assertSame( 'pending', Change::find( $change )->status );
	}

	public function test_a_player_can_withdraw_before_it_is_sent(): void {
		$change = $this->waiting( 'Dual-Blooded', self::APPROVAL );

		$response = $this->rest( $this->player_id, 'POST', "{$this->slug}/changes/{$change}/withdraw" );

		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$this->assertSame( 'withdrawn', Change::find( $change )->status );
	}

	public function test_a_player_cannot_withdraw_a_whole_set_with_a_change_that_is_with_a_coordinator(): void {
		$submission = wp_generate_uuid4();
		$sent       = $this->waiting( 'Dual-Blooded', self::APPROVAL, $submission );
		$other      = $this->waiting( 'Occult', null, $submission );
		$this->send( $sent );

		$response = $this->rest( $this->player_id, 'POST', "{$this->slug}/characters/{$this->character_id}/submissions/{$submission}/withdraw" );

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'with_coordinator', $response->get_data()['code'] );
		$this->assertSame( 'pending', Change::find( $other )->status );
	}

	public function test_a_storyteller_may_still_withdraw_one(): void {
		$change = $this->waiting( 'Dual-Blooded', self::APPROVAL );
		$this->send( $change );

		$this->assertSame( 200, $this->rest( $this->hst_id, 'POST', "{$this->slug}/changes/{$change}/withdraw" )->get_status() );
	}

	public function test_a_change_that_is_with_a_coordinator_is_not_edited(): void {
		$change = $this->waiting( 'Dual-Blooded', self::APPROVAL );
		$this->send( $change );
		$row = $this->queue()[ $change ];

		$response = $this->rest( $this->hst_id, 'PATCH', "{$this->slug}/changes/{$change}", [ 'review_token' => $row['review_token'], 'fields' => [], 'review_notes' => 'Changed my mind.' ] );

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'with_coordinator', $response->get_data()['code'] );
	}

	public function test_a_player_sees_where_their_change_is_and_nothing_of_what_was_sent(): void {
		$change = $this->waiting( 'Dual-Blooded', self::APPROVAL );
		$this->send( $change );

		$pending = json_decode( (string) wp_json_encode( $this->rest( $this->player_id, 'GET', "{$this->slug}/my/changes" )->get_data() ), true );
		$history = json_decode( (string) wp_json_encode( $this->rest( $this->player_id, 'GET', "{$this->slug}/characters/{$this->character_id}/changes" )->get_data() ), true );

		foreach ( [ 'pending list' => $pending, 'history' => $history ] as $where => $rows ) {
			$request = null;
			foreach ( $rows as $row ) {
				if ( (int) $row['id'] === $change ) {
					$request = $row['coordinator']['request'] ?? null;
				}
			}
			$this->assertNotNull( $request, $where );
			$this->assertSame( 'sent', $request['state'], $where );
			$this->assertSame( 33012, $request['oat_entry_id'], $where );
			foreach ( [ 'payload', 'entry_url', 'check_error', 'sent_by' ] as $private ) {
				$this->assertArrayNotHasKey( $private, $request, "{$where}: {$private}" );
			}
		}
	}

	public function test_the_storyteller_home_counts_what_is_with_a_coordinator(): void {
		$first = $this->waiting( 'Dual-Blooded', self::APPROVAL );
		$this->waiting( 'Rune-Wise', self::APPROVAL );
		$this->send( $first );

		$approvals = Storyteller_Home::build( $this->game(), $this->hst_id, true, true, true )['waiting']['approvals'];

		$this->assertSame( 1, $approvals['with_coordinators'] );
		$this->assertSame( 2, $approvals['changes'] );
	}

	public function test_a_chronicle_that_does_not_send_counts_none(): void {
		$first = $this->waiting( 'Dual-Blooded', self::APPROVAL );
		$this->send( $first );
		$this->set_settings( [ 'coordinator_send' => false ] );

		$approvals = Storyteller_Home::build( $this->game(), $this->hst_id, true, true, true )['waiting']['approvals'];

		$this->assertSame( 0, $approvals['with_coordinators'] );
	}

	public function test_the_cart_preview_says_a_storyteller_may_send_it(): void {
		$response = $this->rest( $this->player_id, 'POST', "{$this->slug}/characters/{$this->character_id}/preview-changes", [ 'changes' => [ [
			'change_type' => 'add_trait', 'category' => 'vampire-merits',
			'change_data' => [ 'block_slug' => 'vampire-merits', 'trait' => [ 'name' => 'Dual-Blooded', 'count' => 1 ] ],
		] ] ] );

		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$result = $response->get_data()['results'][0];
		$this->assertArrayHasKey( 'coordinator', $result );
	}

	public function test_a_chronicle_without_the_switch_previews_nothing_about_a_coordinator(): void {
		$this->set_settings( [ 'coordinator_send' => false ] );

		$response = $this->rest( $this->player_id, 'POST', "{$this->slug}/characters/{$this->character_id}/preview-changes", [ 'changes' => [ [
			'change_type' => 'add_trait', 'category' => 'vampire-merits',
			'change_data' => [ 'block_slug' => 'vampire-merits', 'trait' => [ 'name' => 'Dual-Blooded', 'count' => 1 ] ],
		] ] ] );

		$this->assertArrayNotHasKey( 'coordinator', $response->get_data()['results'][0] );
	}
}
