<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Change;
use BeyondElysium\Models\Character;
use BeyondElysium\Models\Game_Member;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * A waiting change holds its XP: a player's set is refused when its total runs over what is free to spend, a character
 * reports what is held, and a Storyteller cannot approve a charge the character cannot pay.
 */
class XpHoldsThreadTest extends WP_UnitTestCase {

	private string $slug = 'thread-xp-holds';
	private int $game_id;
	private int $character_id;
	private int $player_id;
	private int $st_id;

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );

		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'be_games', [
			'slug' => $this->slug, 'name' => 'Thread XP Holds',
			'created_by' => 1, 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ),
		] );
		$this->game_id = (int) $wpdb->insert_id;

		$this->player_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		Game_Member::set_role( $this->game_id, $this->player_id, 'player' );
		$this->st_id = self::factory()->user->create( [ 'role' => 'administrator' ] );

		$this->character_id = Character::create( [
			'name' => 'Hold Test Character', 'stack_slug' => 'vampire',
			'owner_type' => 'chronicle', 'owner_slug' => $this->slug, 'wp_user_id' => $this->player_id,
			'sheet_data' => [ 'vampire-backgrounds' => [] ],
		] );
		Character::update_xp( $this->character_id, 4, 4 );
	}

	private function dispatch( WP_REST_Request $request ) {
		return rest_get_server()->dispatch( $request );
	}

	/**
	 * A fixed-price merit as an add change: Daredevil and Lucky cost 3 each, Common Sense 1.
	 *
	 * @return array<string,mixed>
	 */
	private function merit( string $name ): array {
		return [
			'change_type' => 'add_trait',
			'category'    => 'vampire-merits',
			'change_data' => [ 'block_slug' => 'vampire-merits', 'trait' => [ 'name' => $name ] ],
		];
	}

	private function submit_one( array $change, ?int $as = null ) {
		wp_set_current_user( $as ?? $this->player_id );
		$request = new WP_REST_Request( 'POST', "/be/v1/{$this->slug}/characters/{$this->character_id}/changes" );
		foreach ( $change as $key => $value ) {
			$request->set_param( $key, $value );
		}
		return $this->dispatch( $request );
	}

	/** @param array<int,array<string,mixed>> $changes */
	private function submit_set( array $changes ) {
		wp_set_current_user( $this->player_id );
		$request = new WP_REST_Request( 'POST', "/be/v1/{$this->slug}/characters/{$this->character_id}/changes/submit" );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( [ 'changes' => $changes ] ) );
		return $this->dispatch( $request );
	}

	/** @param array<string,mixed> $params */
	private function review( int $change_id, array $params ) {
		wp_set_current_user( $this->st_id );
		$request = new WP_REST_Request( 'PUT', "/be/v1/{$this->slug}/changes/{$change_id}" );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		return $this->dispatch( $request );
	}

	private function read_character() {
		wp_set_current_user( $this->player_id );
		return $this->dispatch( new WP_REST_Request( 'GET', "/be/v1/{$this->slug}/characters/{$this->character_id}" ) )->get_data();
	}

	private function unspent(): int {
		return (int) Character::find( $this->character_id )->xp_unspent;
	}

	private function waiting_change( int $cost ): int {
		return (int) Change::create( [
			'character_id' => $this->character_id,
			'change_type'  => 'add_trait',
			'category'     => 'vampire-merits',
			'change_data'  => [ 'block_slug' => 'vampire-merits', 'trait' => [ 'name' => 'Daredevil' ] ],
			'xp_cost'      => $cost,
			'status'       => 'pending',
			'submitted_by' => $this->player_id,
		] );
	}

	public function test_a_set_over_the_free_xp_is_refused_whole_though_each_change_fits(): void {
		$response = $this->submit_set( [ $this->merit( 'Daredevil' ), $this->merit( 'Lucky' ) ] );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'insufficient_xp', $response->as_error()->get_error_code() );
		$this->assertSame( 'These cost 6 XP; 4 is free to spend.', $response->as_error()->get_error_message() );
		$this->assertSame( 0, Change::count_for_character( $this->character_id ), 'nothing from a refused set is written' );
		$this->assertSame( 4, $this->unspent() );
	}

	public function test_a_set_that_fits_is_accepted_and_holds_its_cost(): void {
		Character::update_xp( $this->character_id, 2, 2 );

		$response = $this->submit_set( [ $this->merit( 'Daredevil' ), $this->merit( 'Lucky' ) ] );

		$this->assertSame( 201, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$this->assertSame( 6, (int) $this->read_character()->xp_held );
	}

	public function test_a_waiting_change_holds_its_cost_and_a_later_purchase_cannot_spend_it(): void {
		$this->assertSame( 201, $this->submit_one( $this->merit( 'Daredevil' ) )->get_status() );

		$character = $this->read_character();
		$this->assertSame( 3, (int) $character->xp_held );
		$this->assertSame( 4, (int) $character->xp_unspent );

		$refused = $this->submit_one( $this->merit( 'Lucky' ) );
		$this->assertSame( 400, $refused->get_status() );
		$this->assertSame( 'insufficient_xp', $refused->as_error()->get_error_code() );
		$this->assertSame(
			'This costs 3 XP; 1 is free to spend (3 is held for changes still waiting).',
			$refused->as_error()->get_error_message()
		);

		$this->assertSame( 201, $this->submit_one( $this->merit( 'Common Sense' ) )->get_status(), 'what is still free can be spent' );
		$this->assertSame( 4, (int) $this->read_character()->xp_held );
	}

	public function test_resubmitting_a_waiting_change_replaces_its_hold_rather_than_adding_a_second(): void {
		$this->submit_one( $this->merit( 'Daredevil' ) );

		$again = $this->submit_one( $this->merit( 'Daredevil' ) );

		$this->assertSame( 201, $again->get_status(), wp_json_encode( $again->get_data() ) );
		$this->assertSame( 3, (int) $this->read_character()->xp_held );
		$this->assertSame( 1, Change::count_for_character( $this->character_id, [ 'status' => 'pending' ] ) );
	}

	public function test_refusing_a_waiting_change_releases_its_hold(): void {
		$change = $this->submit_one( $this->merit( 'Daredevil' ) )->get_data();
		$this->assertSame( 3, (int) $this->read_character()->xp_held );

		$this->assertSame( 200, $this->review( (int) $change->id, [ 'status' => 'rejected' ] )->get_status() );

		$this->assertSame( 0, (int) $this->read_character()->xp_held );
	}

	public function test_approving_a_change_spends_the_xp_it_held(): void {
		$change = $this->submit_one( $this->merit( 'Daredevil' ) )->get_data();

		$this->assertSame( 200, $this->review( (int) $change->id, [ 'status' => 'approved' ] )->get_status() );

		$character = $this->read_character();
		$this->assertSame( 0, (int) $character->xp_held );
		$this->assertSame( 1, (int) $character->xp_unspent );
	}

	public function test_a_storyteller_cannot_approve_a_charge_the_character_cannot_pay(): void {
		$id = $this->waiting_change( 8 );

		$response = $this->review( $id, [ 'status' => 'approved' ] );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'insufficient_xp', $response->as_error()->get_error_code() );
		$this->assertSame( 'This costs 8 XP; Hold Test Character has 4 unspent.', $response->as_error()->get_error_message() );
		$this->assertSame( 'pending', Change::find( $id )->status );
		$this->assertSame( 4, $this->unspent() );
	}

	public function test_the_storyteller_can_approve_it_once_the_xp_is_awarded(): void {
		$id = $this->waiting_change( 8 );
		Character::update_xp( $this->character_id, 4, 4 );

		$this->assertSame( 200, $this->review( $id, [ 'status' => 'approved' ] )->get_status() );
		$this->assertSame( 0, $this->unspent() );
	}

	public function test_a_homebrew_waiting_for_a_price_holds_nothing_and_is_refused_a_price_above_what_is_left(): void {
		$homebrew = $this->submit_one( [
			'change_type' => 'add_trait',
			'category'    => 'vampire-backgrounds',
			'change_data' => [ 'block_slug' => 'vampire-backgrounds', 'trait' => [ 'name' => 'Occult Library', 'count' => 3, 'custom' => true ] ],
		] )->get_data();
		$this->assertTrue( $homebrew->change_data['cost_pending'] );
		$this->assertSame( 0, (int) $this->read_character()->xp_held );

		$refused = $this->review( (int) $homebrew->id, [ 'status' => 'approved', 'xp_cost' => 2 ] );
		$this->assertSame( 400, $refused->get_status() );
		$this->assertSame( 'insufficient_xp', $refused->as_error()->get_error_code() );
		$this->assertSame( 'This costs 6 XP; Hold Test Character has 4 unspent.', $refused->as_error()->get_error_message() );
		$this->assertSame( 'pending', Change::find( (int) $homebrew->id )->status );
		$this->assertSame( 4, $this->unspent() );

		$this->assertSame( 200, $this->review( (int) $homebrew->id, [ 'status' => 'approved', 'xp_cost' => 1 ] )->get_status() );
		$this->assertSame( 1, $this->unspent() );
	}

	public function test_a_manager_may_still_go_negative_on_their_own_submission(): void {
		$first = $this->submit_one( $this->merit( 'Daredevil' ), $this->st_id );
		$this->assertSame( 201, $first->get_status() );

		$second = $this->submit_one( $this->merit( 'Lucky' ), $this->st_id );
		$this->assertSame( 201, $second->get_status(), 'an ST correcting a sheet may legitimately go negative' );
	}

	public function test_the_batch_approval_names_the_changes_the_character_cannot_pay(): void {
		$id = $this->waiting_change( 8 );

		wp_set_current_user( $this->st_id );
		$request = new WP_REST_Request( 'POST', "/be/v1/{$this->slug}/changes/batch-approve" );
		$request->set_param( 'change_ids', [ $id ] );
		$data = $this->dispatch( $request )->get_data();

		$this->assertSame( [], $data['approved'] );
		$this->assertSame( [ $id ], $data['skipped'] );
		$this->assertSame( [ $id ], $data['short_of_xp'] );
	}

	public function test_the_preview_counts_the_xp_a_waiting_change_holds(): void {
		Character::update_xp( $this->character_id, 2, 2 );
		$this->submit_one( $this->merit( 'Daredevil' ) );

		wp_set_current_user( $this->player_id );
		$request = new WP_REST_Request( 'POST', "/be/v1/{$this->slug}/characters/{$this->character_id}/preview-changes" );
		$request->set_param( 'changes', [ [ 'change_type' => 'add_trait', 'change_data' => [ 'block_slug' => 'vampire-merits', 'trait' => [ 'name' => 'Common Sense' ] ] ] ] );
		$data = $this->dispatch( $request )->get_data();

		$this->assertSame( 2, (int) $data['running_xp_unspent'], '6 unspent, 3 held, 1 for this' );
		$this->assertSame( 3, (int) $data['xp_held'] );
	}

	public function test_the_preview_does_not_count_the_hold_of_a_change_the_set_would_replace(): void {
		Character::update_xp( $this->character_id, 2, 2 );
		$this->submit_one( $this->merit( 'Daredevil' ) );

		wp_set_current_user( $this->player_id );
		$request = new WP_REST_Request( 'POST', "/be/v1/{$this->slug}/characters/{$this->character_id}/preview-changes" );
		$request->set_param( 'changes', [ [ 'change_type' => 'add_trait', 'change_data' => [ 'block_slug' => 'vampire-merits', 'trait' => [ 'name' => 'Daredevil' ] ] ] ] );
		$data = $this->dispatch( $request )->get_data();

		$this->assertSame( 3, (int) $data['running_xp_unspent'], 'the same change again replaces the waiting one' );
		$this->assertSame( 0, (int) $data['xp_held'] );
	}
}
