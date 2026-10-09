<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Game;
use BeyondElysium\Models\Join_Request;
use WP_UnitTestCase;

/**
 * `Join_Request` tracks one account's ask to join a chronicle, from open through approval, refusal, or withdrawal.
 */
class JoinRequestTest extends WP_UnitTestCase {

	private int $game_id;
	private int $applicant_id;

	public function setUp(): void {
		parent::setUp();
		$slug = 'thread-join-request-' . wp_generate_password( 8, false );
		Game::create( [ 'name' => 'Join Request Test', 'slug' => $slug ] );
		$this->game_id      = (int) Game::find_by_slug( $slug )->id;
		$this->applicant_id = self::factory()->user->create();
	}

	public function test_open_creates_a_waiting_request(): void {
		$id = Join_Request::open( $this->game_id, $this->applicant_id, 'I would like to join.' );

		$this->assertIsInt( $id );
		$row = Join_Request::find( $id );
		$this->assertSame( 'waiting', $row->status );
		$this->assertSame( 'I would like to join.', $row->message );
	}

	public function test_a_second_open_request_from_the_same_account_is_refused(): void {
		Join_Request::open( $this->game_id, $this->applicant_id, 'First ask.' );

		$this->assertFalse( Join_Request::open( $this->game_id, $this->applicant_id, 'Second ask.' ) );
	}

	public function test_a_second_request_is_allowed_once_the_first_is_resolved(): void {
		$first = Join_Request::open( $this->game_id, $this->applicant_id, 'First ask.' );
		Join_Request::refuse( $first, 1, 'Not this time.' );

		$second = Join_Request::open( $this->game_id, $this->applicant_id, 'Second ask.' );

		$this->assertIsInt( $second );
		$this->assertNotSame( $first, $second );
	}

	public function test_find_waiting_returns_null_once_resolved(): void {
		$id = Join_Request::open( $this->game_id, $this->applicant_id, 'Ask.' );
		$this->assertNotNull( Join_Request::find_waiting( $this->game_id, $this->applicant_id ) );

		Join_Request::approve( $id, 1 );

		$this->assertNull( Join_Request::find_waiting( $this->game_id, $this->applicant_id ) );
	}

	public function test_approve_records_the_reviewer_and_time(): void {
		$id       = Join_Request::open( $this->game_id, $this->applicant_id, 'Ask.' );
		$reviewer = self::factory()->user->create();

		Join_Request::approve( $id, $reviewer );

		$row = Join_Request::find( $id );
		$this->assertSame( 'approved', $row->status );
		$this->assertSame( $reviewer, (int) $row->reviewed_by );
		$this->assertNotNull( $row->reviewed_at );
	}

	public function test_refuse_records_the_note(): void {
		$id = Join_Request::open( $this->game_id, $this->applicant_id, 'Ask.' );

		Join_Request::refuse( $id, 1, 'The chronicle is full.' );

		$row = Join_Request::find( $id );
		$this->assertSame( 'refused', $row->status );
		$this->assertSame( 'The chronicle is full.', $row->note );
	}

	public function test_withdraw(): void {
		$id = Join_Request::open( $this->game_id, $this->applicant_id, 'Ask.' );

		Join_Request::withdraw( $id );

		$this->assertSame( 'withdrawn', Join_Request::find( $id )->status );
	}

	public function test_tie_character_and_tie_submission(): void {
		$id = Join_Request::open( $this->game_id, $this->applicant_id, 'Ask.' );

		Join_Request::tie_character( $id, 42 );
		$this->assertSame( 42, (int) Join_Request::find( $id )->character_id );

		$id2 = Join_Request::open( $this->game_id, self::factory()->user->create(), 'Ask.' );
		Join_Request::tie_submission( $id2, 99 );
		$this->assertSame( 99, (int) Join_Request::find( $id2 )->submission_id );
	}

	public function test_for_game_filters_by_status(): void {
		$a = Join_Request::open( $this->game_id, $this->applicant_id, 'A' );
		$b = Join_Request::open( $this->game_id, self::factory()->user->create(), 'B' );
		Join_Request::refuse( $b, 1 );

		$this->assertCount( 1, Join_Request::for_game( $this->game_id, 'waiting' ) );
		$this->assertCount( 1, Join_Request::for_game( $this->game_id, 'refused' ) );
		$this->assertCount( 2, Join_Request::for_game( $this->game_id ) );
	}
}
