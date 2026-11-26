<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Change;
use BeyondElysium\Models\Character;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Game_Member;
use BeyondElysium\Services\Change_Engine;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Spending a permanent point of a pool the books spend permanently: a 0 XP change that never refunds, applied at once
 * unless the chronicle holds changes that lower a rating, in which case it waits for a Storyteller.
 */
class PermanentSpendThreadTest extends WP_UnitTestCase {

	private string $slug = 'thread-permanent-spend';
	private int $game_id;
	private int $player;
	private int $hst;
	private int $character_id;

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );

		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'be_games', [
			'slug'       => $this->slug,
			'name'       => 'Thread Permanent Spend',
			'created_by' => 1,
			'created_at' => current_time( 'mysql' ),
			'updated_at' => current_time( 'mysql' ),
			'settings'   => wp_json_encode( [] ),
		] );
		$this->game_id = (int) Game::find_by_slug( $this->slug )->id;

		$this->player = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		Game_Member::set_role( $this->game_id, $this->player, 'player' );
		$this->hst = self::factory()->user->create( [ 'role' => 'editor' ] );
		Game_Member::set_role( $this->game_id, $this->hst, 'hst' );

		$this->character_id = (int) Character::create( [
			'name'       => 'Permanent Tester',
			'stack_slug' => 'vampire',
			'owner_type' => 'chronicle',
			'owner_slug' => $this->slug,
			'wp_user_id' => $this->player,
			'sheet_data' => [
				'vampire-resources' => [
					'Blood'     => [ 'permanent' => 10, 'temporary' => 6 ],
					'Willpower' => [ 'permanent' => 5, 'temporary' => 5 ],
				],
			],
		] );
		Character::update_xp( $this->character_id, 90, 20 );
	}

	private function hold_removals(): void {
		global $wpdb;
		$wpdb->update( $wpdb->prefix . 'be_games', [ 'settings' => wp_json_encode( [ 'approval_on_removal' => true ] ) ], [ 'id' => $this->game_id ] );
	}

	private function spend( int $as, string $pool = 'Willpower', ?int $character = null ) {
		wp_set_current_user( $as );
		$request = new WP_REST_Request( 'POST', '/be/v1/' . $this->slug . '/characters/' . ( $character ?? $this->character_id ) . '/permanent/pool' );
		$request->set_param( 'block_slug', 'vampire-resources' );
		$request->set_param( 'pool_name', $pool );
		$request->set_param( 'reason', 'Attuned a relic' );
		return rest_get_server()->dispatch( $request );
	}

	private function pool( string $name = 'Willpower' ): array {
		return Character::find( $this->character_id )->sheet_data['vampire-resources'][ $name ];
	}

	private function changes( string $type = 'pool_permanent_spent' ): array {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}be_character_changes WHERE character_id = %d AND change_type = %s ORDER BY id", $this->character_id, $type ) ) ?: [];
	}

	public function test_a_player_spends_a_permanent_point_at_once_on_a_chronicle_that_holds_no_removals(): void {
		$response = $this->spend( $this->player );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( [ 'applied' => true, 'waiting' => false, 'permanent' => 4, 'temporary' => 4 ], $response->get_data() );
		$this->assertSame( [ 'permanent' => 4, 'temporary' => 4 ], $this->pool() );
	}

	public function test_the_temporary_rating_comes_down_only_when_it_was_higher(): void {
		$sheet = Character::find( $this->character_id )->sheet_data;
		$sheet['vampire-resources']['Willpower'] = [ 'permanent' => 5, 'temporary' => 2 ];
		Character::update_sheet_data( $this->character_id, $sheet );

		$this->assertSame( 200, $this->spend( $this->player )->get_status() );
		$this->assertSame( [ 'permanent' => 4, 'temporary' => 2 ], $this->pool() );
	}

	public function test_the_spend_is_logged_at_no_xp_and_never_refunds(): void {
		$this->spend( $this->player );

		$rows = $this->changes();
		$this->assertCount( 1, $rows );
		$this->assertSame( 'approved', $rows[0]->status );
		$this->assertEquals( 0, $rows[0]->xp_cost );
		$this->assertSame( 'Attuned a relic', $rows[0]->notes );
		$data = json_decode( (string) $rows[0]->change_data, true );
		$this->assertEquals( [ 'block_slug' => 'vampire-resources', 'pool_name' => 'Willpower', 'from' => 5, 'to' => 4 ], $data );

		$character = Character::find( $this->character_id );
		$this->assertEquals( 90, $character->xp_earned );
		$this->assertEquals( 20, $character->xp_unspent );
	}

	public function test_a_chronicle_that_holds_removals_makes_a_players_spend_wait(): void {
		$this->hold_removals();
		$response = $this->spend( $this->player );

		$this->assertSame( 202, $response->get_status() );
		$data = $response->get_data();
		$this->assertFalse( $data['applied'] );
		$this->assertTrue( $data['waiting'] );
		$this->assertSame( [ 'permanent' => 5, 'temporary' => 5 ], $this->pool(), 'the rating is unchanged until it is approved' );

		$rows = $this->changes();
		$this->assertCount( 1, $rows );
		$this->assertSame( 'pending', $rows[0]->status );
		$this->assertSame( (int) $data['change_id'], (int) $rows[0]->id );
		$this->assertNotEmpty( $rows[0]->reason );
	}

	public function test_approving_a_waiting_spend_lowers_the_rating(): void {
		$this->hold_removals();
		$change_id = (int) $this->spend( $this->player )->get_data()['change_id'];

		$this->assertTrue( Change_Engine::approve( $change_id, $this->hst, null ) );

		$this->assertSame( [ 'permanent' => 4, 'temporary' => 4 ], $this->pool() );
		$this->assertSame( 'approved', Change::find( $change_id )->status );
		$this->assertEquals( 20, Character::find( $this->character_id )->xp_unspent );
	}

	public function test_refusing_a_waiting_spend_changes_nothing(): void {
		$this->hold_removals();
		$change_id = (int) $this->spend( $this->player )->get_data()['change_id'];

		$this->assertTrue( Change_Engine::reject( $change_id, $this->hst, 'Not this month' ) );

		$this->assertSame( [ 'permanent' => 5, 'temporary' => 5 ], $this->pool() );
		$this->assertSame( 'rejected', Change::find( $change_id )->status );
	}

	public function test_a_storytellers_own_spend_applies_at_once_even_when_removals_are_held(): void {
		$this->hold_removals();
		$response = $this->spend( $this->hst );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( [ 'permanent' => 4, 'temporary' => 4 ], $this->pool() );
	}

	public function test_a_waiting_spend_is_refused_at_approval_once_the_rating_is_gone(): void {
		$this->hold_removals();
		$change_id = (int) $this->spend( $this->player )->get_data()['change_id'];

		$sheet = Character::find( $this->character_id )->sheet_data;
		$sheet['vampire-resources']['Willpower'] = [ 'permanent' => 0, 'temporary' => 0 ];
		Character::update_sheet_data( $this->character_id, $sheet );

		$this->assertFalse( Change_Engine::approve( $change_id, $this->hst, null ) );
		$this->assertSame( 'pending', Change::find( $change_id )->status );
		$this->assertSame( [ 'permanent' => 0, 'temporary' => 0 ], $this->pool() );
	}

	public function test_a_pool_the_books_do_not_spend_permanently_is_refused(): void {
		$response = $this->spend( $this->player, 'Blood' );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'not_spendable', $response->as_error()->get_error_code() );
		$this->assertSame( [ 'permanent' => 10, 'temporary' => 6 ], $this->pool( 'Blood' ) );
		$this->assertSame( 'not_spendable', $this->spend( $this->player, 'Rage' )->as_error()->get_error_code() );
		$this->assertSame( [], $this->changes() );
	}

	public function test_a_pool_with_no_rating_left_is_refused(): void {
		$sheet = Character::find( $this->character_id )->sheet_data;
		$sheet['vampire-resources']['Willpower'] = [ 'permanent' => 0, 'temporary' => 0 ];
		Character::update_sheet_data( $this->character_id, $sheet );

		$response = $this->spend( $this->player );
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'nothing_to_spend', $response->as_error()->get_error_code() );
	}

	public function test_a_pool_the_character_does_not_hold_is_refused(): void {
		$bare = (int) Character::create( [
			'name' => 'No Pools', 'stack_slug' => 'vampire', 'owner_type' => 'chronicle', 'owner_slug' => $this->slug,
			'wp_user_id' => $this->player, 'sheet_data' => [],
		] );

		$response = $this->spend( $this->player, 'Willpower', $bare );
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'pool_not_found', $response->as_error()->get_error_code() );
	}

	public function test_another_player_cannot_spend_someone_elses_rating(): void {
		$other = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		Game_Member::set_role( $this->game_id, $other, 'player' );

		$this->assertSame( 403, $this->spend( $other )->get_status() );
		$this->assertSame( [ 'permanent' => 5, 'temporary' => 5 ], $this->pool() );
	}

	public function test_a_visitor_who_is_not_signed_in_is_refused(): void {
		$this->assertContains( $this->spend( 0 )->get_status(), [ 401, 403 ] );
	}
}
