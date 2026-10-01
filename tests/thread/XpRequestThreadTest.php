<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Change;
use BeyondElysium\Models\Character;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Game_Member;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * A player's own XP request, submitted through the ordinary change route with no Storyteller capability: it waits
 * for a Storyteller even on an auto-approve chronicle, carries a server-built reason naming where it was earned,
 * and a Storyteller's review of it emails the player exactly like any other reviewed change.
 */
class XpRequestThreadTest extends WP_UnitTestCase {

	private string $slug = 'thread-xp-request';
	private int $game_id;
	private int $player;
	private int $character;
	private int $hst;
	private array $mail = [];

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );

		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'be_games', [
			'slug' => $this->slug, 'name' => $this->slug,
			'settings' => wp_json_encode( [ 'auto_approve' => true ] ),
			'created_by' => 1, 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ),
		] );
		$this->game_id = (int) Game::find_by_slug( $this->slug )->id;

		$this->player = self::factory()->user->create( [ 'role' => 'subscriber', 'user_email' => 'xp-request-player@example.test' ] );
		Game_Member::set_role( $this->game_id, $this->player, 'player' );

		$this->character = Character::create( [
			'name' => 'Request Tester', 'stack_slug' => 'vampire',
			'owner_type' => 'chronicle', 'owner_slug' => $this->slug, 'wp_user_id' => $this->player,
		] );
		Character::update_xp( $this->character, 10, 10 );

		$this->hst = self::factory()->user->create( [ 'role' => 'editor', 'user_email' => 'xp-request-hst@example.test' ] );
		Game_Member::set_role( $this->game_id, $this->hst, 'hst' );

		$this->mail = [];
		add_filter( 'pre_wp_mail', [ $this, 'capture_mail' ], 10, 2 );
	}

	public function tearDown(): void {
		remove_filter( 'pre_wp_mail', [ $this, 'capture_mail' ], 10 );
		parent::tearDown();
	}

	public function capture_mail( $pre, $atts ) {
		$this->mail[] = $atts;
		return true;
	}

	private function dispatch( WP_REST_Request $request ) {
		return rest_get_server()->dispatch( $request );
	}

	private function request_xp( array $change_data, ?int $character_id = null ) {
		wp_set_current_user( $this->player );
		$request = new WP_REST_Request(
			'POST',
			"/be/v1/{$this->slug}/characters/" . ( $character_id ?? $this->character ) . '/changes'
		);
		$request->set_param( 'change_type', 'xp_earn' );
		$request->set_param( 'category', 'experience' );
		$request->set_param( 'change_data', $change_data );
		return $this->dispatch( $request );
	}

	private function review( int $change_id, string $status, ?string $notes = null ) {
		wp_set_current_user( $this->hst );
		$request = new WP_REST_Request( 'PUT', "/be/v1/{$this->slug}/changes/{$change_id}" );
		$request->set_param( 'status', $status );
		if ( $notes !== null ) {
			$request->set_param( 'notes', $notes );
		}
		return $this->dispatch( $request );
	}

	public function test_a_request_lands_pending_with_the_built_reason_and_details(): void {
		$response = $this->request_xp( [
			'amount' => 3,
			'request' => [ 'where' => 'Kings of Chicago', 'date' => '2026-09-27', 'note' => 'Ran a scene for the Prince.' ],
		] );

		$this->assertSame( 201, $response->get_status() );
		$change = Change::find( (int) $response->get_data()->id );
		$this->assertSame( 'pending', $change->status );
		$this->assertSame( 'xp_earn', $change->change_type );
		$this->assertSame( 'Requested: Kings of Chicago, 2026-09-27', $change->change_data['reason'] );
		$this->assertSame( 'Kings of Chicago', $change->change_data['request']['where'] );
		$this->assertSame( '2026-09-27', $change->change_data['request']['date'] );
		$this->assertSame( 'Ran a scene for the Prince.', $change->change_data['request']['note'] );

		$character = Character::find( $this->character );
		$this->assertSame( 10, (int) $character->xp_earned );
		$this->assertSame( 10, (int) $character->xp_unspent );
	}

	public function test_a_request_with_no_date_still_builds_a_reason(): void {
		$response = $this->request_xp( [
			'amount' => 2,
			'request' => [ 'where' => 'A home chronicle game' ],
		] );

		$this->assertSame( 201, $response->get_status() );
		$change = Change::find( (int) $response->get_data()->id );
		$this->assertSame( 'Requested: A home chronicle game', $change->change_data['reason'] );
		$this->assertNull( $change->change_data['request']['date'] );
	}

	public function test_a_request_waits_even_on_an_auto_approve_chronicle(): void {
		$response = $this->request_xp( [
			'amount' => 5,
			'request' => [ 'where' => 'Kings of Chicago' ],
		] );

		$change = Change::find( (int) $response->get_data()->id );
		$this->assertSame( 'pending', $change->status );
	}

	public function test_an_hst_approving_a_request_raises_both_totals_and_emails_the_player(): void {
		$response = $this->request_xp( [
			'amount' => 3,
			'request' => [ 'where' => 'Kings of Chicago', 'date' => '2026-09-27' ],
		] );
		$change_id = (int) $response->get_data()->id;

		$this->mail = [];
		$review = $this->review( $change_id, 'approved' );
		$this->assertSame( 200, $review->get_status() );

		$character = Character::find( $this->character );
		$this->assertSame( 13, (int) $character->xp_earned );
		$this->assertSame( 13, (int) $character->xp_unspent );

		$approved = $review->get_data();
		$this->assertSame( 'approved', $approved->status );

		$this->assertCount( 1, $this->mail );
		$this->assertSame( 'xp-request-player@example.test', $this->mail[0]['to'] );
	}

	public function test_refusing_a_request_leaves_xp_alone_and_emails_the_player(): void {
		$response = $this->request_xp( [
			'amount' => 3,
			'request' => [ 'where' => 'Kings of Chicago' ],
		] );
		$change_id = (int) $response->get_data()->id;

		$this->mail = [];
		$review = $this->review( $change_id, 'rejected', 'No way to confirm this.' );
		$this->assertSame( 200, $review->get_status() );

		$character = Character::find( $this->character );
		$this->assertSame( 10, (int) $character->xp_earned );
		$this->assertSame( 10, (int) $character->xp_unspent );

		$this->assertCount( 1, $this->mail );
	}

	public function test_two_requests_stay_two_pending_changes(): void {
		$first = $this->request_xp( [ 'amount' => 3, 'request' => [ 'where' => 'Kings of Chicago' ] ] );
		$second = $this->request_xp( [ 'amount' => 2, 'request' => [ 'where' => 'A one-shot game' ] ] );

		$this->assertNotSame(
			$first->get_data()->id,
			$second->get_data()->id
		);

		$pending = Change::for_game( $this->slug, [ 'status' => 'pending', 'character_id' => $this->character ] );
		$this->assertCount( 2, $pending );
	}

	public function test_a_missing_where_is_refused(): void {
		$response = $this->request_xp( [ 'amount' => 3, 'request' => [] ] );
		$this->assertSame( 400, $response->get_status() );
	}

	public function test_a_blank_where_is_refused(): void {
		$response = $this->request_xp( [ 'amount' => 3, 'request' => [ 'where' => '   ' ] ] );
		$this->assertSame( 400, $response->get_status() );
	}

	public function test_zero_is_refused(): void {
		$response = $this->request_xp( [ 'amount' => 0, 'request' => [ 'where' => 'Kings of Chicago' ] ] );
		$this->assertSame( 400, $response->get_status() );
	}

	public function test_a_negative_amount_is_refused(): void {
		$response = $this->request_xp( [ 'amount' => -3, 'request' => [ 'where' => 'Kings of Chicago' ] ] );
		$this->assertSame( 400, $response->get_status() );
	}

	public function test_an_amount_over_the_cap_is_refused(): void {
		$response = $this->request_xp( [ 'amount' => 10001, 'request' => [ 'where' => 'Kings of Chicago' ] ] );
		$this->assertSame( 400, $response->get_status() );
	}

	public function test_a_decimal_amount_is_refused(): void {
		$response = $this->request_xp( [ 'amount' => 2.5, 'request' => [ 'where' => 'Kings of Chicago' ] ] );
		$this->assertSame( 400, $response->get_status() );
	}

	public function test_a_date_after_today_is_refused(): void {
		$future = gmdate( 'Y-m-d', strtotime( '+1 year' ) );
		$response = $this->request_xp( [
			'amount' => 3,
			'request' => [ 'where' => 'Kings of Chicago', 'date' => $future ],
		] );
		$this->assertSame( 400, $response->get_status() );
	}

	public function test_a_malformed_date_is_refused(): void {
		$response = $this->request_xp( [
			'amount' => 3,
			'request' => [ 'where' => 'Kings of Chicago', 'date' => 'not a date' ],
		] );
		$this->assertSame( 400, $response->get_status() );
	}

	public function test_a_note_over_the_cap_is_refused(): void {
		$response = $this->request_xp( [
			'amount' => 3,
			'request' => [ 'where' => 'Kings of Chicago', 'note' => str_repeat( 'x', 2001 ) ],
		] );
		$this->assertSame( 400, $response->get_status() );
	}

	public function test_a_player_cannot_adjust_xp_directly(): void {
		wp_set_current_user( $this->player );
		$request = new WP_REST_Request( 'POST', "/be/v1/{$this->slug}/characters/{$this->character}/changes" );
		$request->set_param( 'change_type', 'xp_adjust' );
		$request->set_param( 'category', 'experience' );
		$request->set_param( 'change_data', [ 'amount' => -2 ] );
		$this->assertSame( 400, $this->dispatch( $request )->get_status() );
	}

	public function test_a_player_cannot_request_xp_for_someone_elses_character(): void {
		$other_player = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		$other_character = Character::create( [
			'name' => 'Someone Else', 'stack_slug' => 'vampire',
			'owner_type' => 'chronicle', 'owner_slug' => $this->slug, 'wp_user_id' => $other_player,
		] );

		$response = $this->request_xp(
			[ 'amount' => 3, 'request' => [ 'where' => 'Kings of Chicago' ] ],
			$other_character
		);
		$this->assertSame( 403, $response->get_status() );
	}

	public function test_an_hst_can_still_award_directly_with_no_request(): void {
		wp_set_current_user( $this->hst );
		$request = new WP_REST_Request( 'POST', "/be/v1/{$this->slug}/characters/{$this->character}/changes" );
		$request->set_param( 'change_type', 'xp_earn' );
		$request->set_param( 'category', 'experience' );
		$request->set_param( 'change_data', [ 'amount' => 5, 'reason' => 'Session attendance' ] );
		$response = $this->dispatch( $request );

		$this->assertSame( 201, $response->get_status() );
		$change = Change::find( (int) $response->get_data()->id );
		$this->assertSame( 'approved', $change->status );

		$character = Character::find( $this->character );
		$this->assertSame( 15, (int) $character->xp_earned );
		$this->assertSame( 15, (int) $character->xp_unspent );
	}
}
