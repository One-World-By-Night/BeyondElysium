<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Change;
use BeyondElysium\Models\Character;
use BeyondElysium\Models\Game_Member;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * A player, or a Storyteller, withdraws a change that is still waiting: nothing is applied, the XP it held is free
 * again, the queue forgets it and the history keeps it.
 */
class ChangeWithdrawThreadTest extends WP_UnitTestCase {

	private string $slug = 'thread-change-withdraw';
	private int $game_id;
	private int $character_id;
	private int $player_id;
	private int $other_id;
	private int $st_id;

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );

		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'be_games', [
			'slug' => $this->slug, 'name' => 'Thread Change Withdraw',
			'created_by' => 1, 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ),
		] );
		$this->game_id = (int) $wpdb->insert_id;

		$this->player_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		Game_Member::set_role( $this->game_id, $this->player_id, 'player' );
		$this->other_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		Game_Member::set_role( $this->game_id, $this->other_id, 'player' );
		$this->st_id = self::factory()->user->create( [ 'role' => 'administrator' ] );

		$this->character_id = Character::create( [
			'name' => 'Withdraw Test Character', 'stack_slug' => 'vampire',
			'owner_type' => 'chronicle', 'owner_slug' => $this->slug, 'wp_user_id' => $this->player_id,
			'sheet_data' => [ 'vampire-merits' => [] ],
		] );
		Character::update_xp( $this->character_id, 6, 6 );
	}

	private function dispatch( WP_REST_Request $request ) {
		return rest_get_server()->dispatch( $request );
	}

	/** @return array<string,mixed> */
	private function merit( string $name ): array {
		return [
			'change_type' => 'add_trait',
			'category'    => 'vampire-merits',
			'change_data' => [ 'block_slug' => 'vampire-merits', 'trait' => [ 'name' => $name ] ],
		];
	}

	/** @return array<int,object> the changes of one submitted set */
	private function submit_set( array $names ): array {
		wp_set_current_user( $this->player_id );
		$request = new WP_REST_Request( 'POST', "/be/v1/{$this->slug}/characters/{$this->character_id}/changes/submit" );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( [ 'changes' => array_map( fn( $n ) => $this->merit( $n ), $names ) ] ) );
		$data = $this->dispatch( $request )->get_data();
		return [ $data['submission_id'], $data['changes'] ];
	}

	private function withdraw_change( int $as, int $change_id ) {
		wp_set_current_user( $as );
		return $this->dispatch( new WP_REST_Request( 'POST', "/be/v1/{$this->slug}/changes/{$change_id}/withdraw" ) );
	}

	private function withdraw_set( int $as, string $submission_id ) {
		wp_set_current_user( $as );
		return $this->dispatch( new WP_REST_Request( 'POST', "/be/v1/{$this->slug}/characters/{$this->character_id}/submissions/{$submission_id}/withdraw" ) );
	}

	private function held(): int {
		return Change::held_xp( $this->character_id );
	}

	public function test_the_owner_withdraws_a_waiting_change(): void {
		[ , $changes ] = $this->submit_set( [ 'Daredevil' ] );
		$id            = (int) $changes[0]->id;
		$this->assertSame( 3, $this->held() );

		$response = $this->withdraw_change( $this->player_id, $id );

		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$this->assertSame( 'withdrawn', $response->get_data()->status );
		$this->assertSame( 'withdrawn', Change::find( $id )->status );
		$this->assertSame( 0, $this->held(), 'the hold is released' );
		$this->assertSame( 6, (int) Character::find( $this->character_id )->xp_unspent );
		$this->assertSame( [], Character::find( $this->character_id )->sheet_data['vampire-merits'], 'nothing is applied' );
	}

	public function test_a_withdrawn_change_leaves_the_queue_and_stays_in_the_history(): void {
		[ , $changes ] = $this->submit_set( [ 'Daredevil' ] );
		$id            = (int) $changes[0]->id;
		$this->withdraw_change( $this->player_id, $id );

		wp_set_current_user( $this->st_id );
		$queue = $this->dispatch( new WP_REST_Request( 'GET', "/be/v1/{$this->slug}/changes" ) )->get_data();
		$this->assertNotContains( $id, array_map( static fn( $c ) => (int) $c->id, $queue['items'] ?? $queue ) );

		$history = $this->dispatch( new WP_REST_Request( 'GET', "/be/v1/{$this->slug}/characters/{$this->character_id}/changes" ) )->get_data();
		$row     = array_values( array_filter( $history, static fn( $c ) => (int) $c->id === $id ) )[0] ?? null;
		$this->assertNotNull( $row );
		$this->assertSame( 'withdrawn', $row->status );
	}

	public function test_the_owner_withdraws_a_whole_set(): void {
		[ $submission_id, $changes ] = $this->submit_set( [ 'Daredevil', 'Lucky' ] );
		$this->assertSame( 6, $this->held() );

		$response = $this->withdraw_set( $this->player_id, $submission_id );

		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$ids = array_map( static fn( $c ) => (int) $c->id, $changes );
		$this->assertEqualsCanonicalizing( $ids, $response->get_data()['withdrawn'] );
		foreach ( $ids as $id ) {
			$this->assertSame( 'withdrawn', Change::find( $id )->status );
		}
		$this->assertSame( 0, $this->held() );
	}

	public function test_a_set_withdraws_only_what_is_still_waiting(): void {
		[ $submission_id, $changes ] = $this->submit_set( [ 'Daredevil', 'Lucky' ] );
		wp_set_current_user( $this->st_id );
		$decided = new WP_REST_Request( 'PUT', "/be/v1/{$this->slug}/changes/{$changes[0]->id}" );
		$decided->set_param( 'status', 'approved' );
		$this->assertSame( 200, $this->dispatch( $decided )->get_status() );

		$response = $this->withdraw_set( $this->player_id, $submission_id );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( [ (int) $changes[1]->id ], $response->get_data()['withdrawn'] );
		$this->assertSame( 'approved', Change::find( (int) $changes[0]->id )->status );
	}

	public function test_another_player_may_not_withdraw_someone_elses_change(): void {
		[ $submission_id, $changes ] = $this->submit_set( [ 'Daredevil' ] );

		$this->assertSame( 403, $this->withdraw_change( $this->other_id, (int) $changes[0]->id )->get_status() );
		$this->assertSame( 403, $this->withdraw_set( $this->other_id, $submission_id )->get_status() );
		$this->assertSame( 'pending', Change::find( (int) $changes[0]->id )->status );
	}

	public function test_a_storyteller_may_withdraw_a_players_waiting_change(): void {
		[ , $changes ] = $this->submit_set( [ 'Daredevil' ] );

		$this->assertSame( 200, $this->withdraw_change( $this->st_id, (int) $changes[0]->id )->get_status() );
		$this->assertSame( 'withdrawn', Change::find( (int) $changes[0]->id )->status );
	}

	public function test_a_change_that_is_no_longer_waiting_is_refused(): void {
		[ , $changes ] = $this->submit_set( [ 'Daredevil' ] );
		wp_set_current_user( $this->st_id );
		$decided = new WP_REST_Request( 'PUT', "/be/v1/{$this->slug}/changes/{$changes[0]->id}" );
		$decided->set_param( 'status', 'rejected' );
		$this->dispatch( $decided );

		$response = $this->withdraw_change( $this->player_id, (int) $changes[0]->id );

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'change_not_pending', $response->as_error()->get_error_code() );
		$this->assertSame( 'rejected', Change::find( (int) $changes[0]->id )->status );
	}

	public function test_a_set_with_nothing_waiting_is_refused(): void {
		[ $submission_id, $changes ] = $this->submit_set( [ 'Daredevil' ] );
		$this->withdraw_change( $this->player_id, (int) $changes[0]->id );

		$response = $this->withdraw_set( $this->player_id, $submission_id );

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'change_not_pending', $response->as_error()->get_error_code() );
	}

	public function test_a_change_from_another_chronicle_is_not_found(): void {
		[ , $changes ] = $this->submit_set( [ 'Daredevil' ] );

		wp_set_current_user( $this->st_id );
		$response = $this->dispatch( new WP_REST_Request( 'POST', "/be/v1/no-such-chronicle/changes/{$changes[0]->id}/withdraw" ) );

		$this->assertGreaterThanOrEqual( 400, $response->get_status() );
		$this->assertSame( 'pending', Change::find( (int) $changes[0]->id )->status );
	}

	public function test_the_withdrawn_status_reads_as_withdrawn_in_a_players_changes_across_chronicles(): void {
		[ , $changes ] = $this->submit_set( [ 'Daredevil' ] );
		$this->withdraw_change( $this->player_id, (int) $changes[0]->id );

		wp_set_current_user( $this->player_id );
		$rows = $this->dispatch( new WP_REST_Request( 'GET', '/be/v1/my/changes' ) )->get_data();

		$mine = array_values( array_filter( $rows, static fn( $r ) => (int) $r->id === (int) $changes[0]->id ) );
		$this->assertCount( 1, $mine );
		$this->assertSame( 'withdrawn', $mine[0]->display_status );
	}
}
