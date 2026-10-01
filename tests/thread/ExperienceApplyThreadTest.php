<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Change;
use BeyondElysium\Models\Character;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Game_Member;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Applying a different, Storyteller-chosen XP amount to each of several characters in one call through the real
 * REST server: a positive amount awards, a negative amount takes XP back and is refused rather than taking XP
 * Earned below zero, each row stands on its own, and a malformed request is refused whole before anything is
 * written.
 */
class ExperienceApplyThreadTest extends WP_UnitTestCase {

	private string $slug = 'thread-experience-apply';
	private string $other_slug = 'thread-experience-apply-other';
	private int $game_id;
	private int $hst;
	private int $character_a;
	private int $character_b;
	private int $other_character;
	private array $mail = [];

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );

		global $wpdb;
		foreach ( [ $this->slug, $this->other_slug ] as $slug ) {
			$wpdb->insert( $wpdb->prefix . 'be_games', [
				'slug' => $slug, 'name' => $slug,
				'created_by' => 1, 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ),
			] );
		}
		$this->game_id = (int) Game::find_by_slug( $this->slug )->id;

		$this->hst = self::factory()->user->create( [ 'role' => 'editor', 'user_email' => 'apply-xp-hst@example.test' ] );
		Game_Member::set_role( $this->game_id, $this->hst, 'hst' );

		$player = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		$this->character_a = Character::create( [
			'name' => 'Apply Test A', 'stack_slug' => 'vampire',
			'owner_type' => 'chronicle', 'owner_slug' => $this->slug, 'wp_user_id' => $player,
		] );
		Character::update_xp( $this->character_a, 10, 10 );

		$this->character_b = Character::create( [
			'name' => 'Apply Test B', 'stack_slug' => 'vampire',
			'owner_type' => 'chronicle', 'owner_slug' => $this->slug, 'wp_user_id' => $player,
		] );
		Character::update_xp( $this->character_b, 2, 2 );

		$this->other_character = Character::create( [
			'name' => 'Apply Test Elsewhere', 'stack_slug' => 'vampire',
			'owner_type' => 'chronicle', 'owner_slug' => $this->other_slug,
		] );
		Character::update_xp( $this->other_character, 10, 10 );

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

	private function apply( array $awards, string $reason = 'Session attendance' ) {
		wp_set_current_user( $this->hst );
		$request = new WP_REST_Request( 'POST', "/be/v1/{$this->slug}/experience/apply" );
		$request->set_param( 'reason', $reason );
		$request->set_param( 'awards', $awards );
		return $this->dispatch( $request );
	}

	public function test_a_positive_amount_awards_and_moves_both_totals(): void {
		$response = $this->apply( [ [ 'character_id' => $this->character_a, 'amount' => 5 ] ] );

		$this->assertSame( 200, $response->get_status() );
		$results = $response->get_data()['results'];
		$this->assertTrue( $results[0]['applied'] );
		$this->assertSame( 15, $results[0]['xp_earned'] );
		$this->assertSame( 15, $results[0]['xp_unspent'] );

		$character = Character::find( $this->character_a );
		$this->assertSame( 15, (int) $character->xp_earned );
		$this->assertSame( 15, (int) $character->xp_unspent );
	}

	public function test_a_negative_amount_takes_xp_back(): void {
		$response = $this->apply( [ [ 'character_id' => $this->character_a, 'amount' => -3 ] ] );

		$results = $response->get_data()['results'];
		$this->assertTrue( $results[0]['applied'] );

		$character = Character::find( $this->character_a );
		$this->assertSame( 7, (int) $character->xp_earned );
		$this->assertSame( 7, (int) $character->xp_unspent );
	}

	public function test_the_change_shows_in_history_with_its_reason_and_sends_no_email(): void {
		$this->apply( [ [ 'character_id' => $this->character_a, 'amount' => 5 ] ], 'XP award, 2026-10-01' );

		$changes = Change::for_character( $this->character_a, [ 'change_type' => 'xp_earn' ] );
		$this->assertCount( 1, $changes );
		$this->assertSame( 'approved', $changes[0]->status );
		$this->assertSame( 'XP award, 2026-10-01', $changes[0]->change_data['reason'] );
		$this->assertSame( 0, count( $this->mail ) );
	}

	public function test_a_negative_amount_larger_than_earned_is_refused_while_others_apply(): void {
		$response = $this->apply( [
			[ 'character_id' => $this->character_a, 'amount' => 5 ],
			[ 'character_id' => $this->character_b, 'amount' => -5 ],
		] );

		$this->assertSame( 200, $response->get_status() );
		$results = $response->get_data()['results'];

		$this->assertTrue( $results[0]['applied'] );
		$this->assertFalse( $results[1]['applied'] );
		$this->assertSame( 'below_zero', $results[1]['code'] );

		$this->assertSame( 15, (int) Character::find( $this->character_a )->xp_earned );
		$this->assertSame( 2, (int) Character::find( $this->character_b )->xp_earned );
	}

	public function test_a_character_from_another_chronicle_is_refused_while_others_apply(): void {
		$response = $this->apply( [
			[ 'character_id' => $this->character_a, 'amount' => 5 ],
			[ 'character_id' => $this->other_character, 'amount' => 5 ],
		] );

		$results = $response->get_data()['results'];
		$this->assertTrue( $results[0]['applied'] );
		$this->assertFalse( $results[1]['applied'] );
		$this->assertSame( 'not_in_chronicle', $results[1]['code'] );

		$this->assertSame( 10, (int) Character::find( $this->other_character )->xp_earned );
	}

	public function test_results_come_back_in_request_order(): void {
		$response = $this->apply( [
			[ 'character_id' => $this->character_b, 'amount' => 1 ],
			[ 'character_id' => $this->character_a, 'amount' => 1 ],
		] );

		$results = $response->get_data()['results'];
		$this->assertSame( $this->character_b, $results[0]['character_id'] );
		$this->assertSame( $this->character_a, $results[1]['character_id'] );
	}

	public function test_zero_is_refused_whole_and_writes_nothing(): void {
		$response = $this->apply( [
			[ 'character_id' => $this->character_a, 'amount' => 5 ],
			[ 'character_id' => $this->character_b, 'amount' => 0 ],
		] );
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 10, (int) Character::find( $this->character_a )->xp_earned );
	}

	public function test_over_the_cap_is_refused(): void {
		$response = $this->apply( [ [ 'character_id' => $this->character_a, 'amount' => 10001 ] ] );
		$this->assertSame( 400, $response->get_status() );
	}

	public function test_under_the_floor_is_refused(): void {
		$response = $this->apply( [ [ 'character_id' => $this->character_a, 'amount' => -10001 ] ] );
		$this->assertSame( 400, $response->get_status() );
	}

	public function test_a_decimal_amount_is_refused(): void {
		$response = $this->apply( [ [ 'character_id' => $this->character_a, 'amount' => 2.5 ] ] );
		$this->assertSame( 400, $response->get_status() );
	}

	public function test_a_missing_reason_is_refused(): void {
		$response = $this->apply( [ [ 'character_id' => $this->character_a, 'amount' => 5 ] ], '' );
		$this->assertSame( 400, $response->get_status() );
	}

	public function test_an_empty_awards_list_is_refused(): void {
		$response = $this->apply( [] );
		$this->assertSame( 400, $response->get_status() );
	}

	public function test_over_500_rows_is_refused(): void {
		$awards = [];
		for ( $i = 0; $i < 501; $i++ ) {
			$awards[] = [ 'character_id' => $i + 1, 'amount' => 1 ];
		}
		$response = $this->apply( $awards );
		$this->assertSame( 400, $response->get_status() );
	}

	public function test_a_repeated_character_is_refused(): void {
		$response = $this->apply( [
			[ 'character_id' => $this->character_a, 'amount' => 5 ],
			[ 'character_id' => $this->character_a, 'amount' => 3 ],
		] );
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 10, (int) Character::find( $this->character_a )->xp_earned );
	}

	public function test_an_ast_can_apply(): void {
		$ast = self::factory()->user->create( [ 'role' => 'editor' ] );
		Game_Member::set_role( $this->game_id, $ast, 'ast' );
		wp_set_current_user( $ast );

		$request = new WP_REST_Request( 'POST', "/be/v1/{$this->slug}/experience/apply" );
		$request->set_param( 'reason', 'Session attendance' );
		$request->set_param( 'awards', [ [ 'character_id' => $this->character_a, 'amount' => 5 ] ] );
		$this->assertSame( 200, $this->dispatch( $request )->get_status() );
	}

	public function test_a_narrator_cannot_apply(): void {
		$narrator = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		Game_Member::set_role( $this->game_id, $narrator, 'narrator' );
		wp_set_current_user( $narrator );

		$request = new WP_REST_Request( 'POST', "/be/v1/{$this->slug}/experience/apply" );
		$request->set_param( 'reason', 'Session attendance' );
		$request->set_param( 'awards', [ [ 'character_id' => $this->character_a, 'amount' => 5 ] ] );
		$this->assertSame( 403, $this->dispatch( $request )->get_status() );
	}

	public function test_a_player_cannot_apply(): void {
		$player = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		Game_Member::set_role( $this->game_id, $player, 'player' );
		wp_set_current_user( $player );

		$request = new WP_REST_Request( 'POST', "/be/v1/{$this->slug}/experience/apply" );
		$request->set_param( 'reason', 'Session attendance' );
		$request->set_param( 'awards', [ [ 'character_id' => $this->character_a, 'amount' => 5 ] ] );
		$this->assertSame( 403, $this->dispatch( $request )->get_status() );
	}
}
