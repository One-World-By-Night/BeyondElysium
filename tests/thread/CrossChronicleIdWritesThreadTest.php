<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Change;
use BeyondElysium\Models\Character;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Game_Member;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * 1.0.0-review F-008 and F-029: routes that take a row id in the URL or body must
 * refuse a row that belongs to a different chronicle than the one in the URL.
 * Change ids and character ids are sequential integers, so a Storyteller of one
 * chronicle could approve another chronicle's pending changes or grant its
 * characters XP just by guessing numbers.
 */
class CrossChronicleIdWritesThreadTest extends WP_UnitTestCase {

	private string $home = 'thread-ids-home';
	private string $other = 'thread-ids-other';
	private int $storyteller;
	private int $home_character;
	private int $other_character;
	private int $other_change;

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );

		global $wpdb;
		foreach ( [ $this->home, $this->other ] as $slug ) {
			$wpdb->insert( $wpdb->prefix . 'be_games', [
				'slug' => $slug, 'name' => $slug, 'settings' => '{}',
				'created_by' => 1, 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ),
			] );
		}

		$this->storyteller = self::factory()->user->create( [ 'role' => 'editor' ] );
		Game_Member::set_role( (int) Game::find_by_slug( $this->home )->id, $this->storyteller, 'hst' );

		$this->home_character = Character::create( [
			'name' => 'Home Character', 'stack_slug' => 'test-stack',
			'owner_type' => 'chronicle', 'owner_slug' => $this->home,
		] );
		$this->other_character = Character::create( [
			'name' => 'Other Chronicle Character', 'stack_slug' => 'test-stack',
			'owner_type' => 'chronicle', 'owner_slug' => $this->other,
		] );
		$this->other_change = Change::create( [
			'character_id' => $this->other_character,
			'change_type'  => 'xp_earn',
			'category'     => 'experience',
			'change_data'  => [ 'amount' => 7 ],
			'status'       => 'pending',
			'submitted_by' => 1,
		] );
	}

	private function dispatch( string $method, string $route, array $body = [] ) {
		$request = new WP_REST_Request( $method, $route );
		if ( $body ) {
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( wp_json_encode( $body ) );
		}
		return rest_get_server()->dispatch( $request );
	}

	public function test_approving_another_chronicles_change_by_id_is_refused(): void {
		wp_set_current_user( $this->storyteller );

		$response = $this->dispatch( 'PUT', "/be/v1/{$this->home}/changes/{$this->other_change}", [ 'status' => 'approved' ] );

		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( 'pending', Change::find( $this->other_change )->status );
		$this->assertSame( 0, (int) Character::find( $this->other_character )->xp_earned );
	}

	public function test_batch_approve_skips_another_chronicles_change(): void {
		wp_set_current_user( $this->storyteller );

		$response = $this->dispatch( 'POST', "/be/v1/{$this->home}/changes/batch-approve", [ 'change_ids' => [ $this->other_change ] ] );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( [], $response->get_data()['approved'] );
		$this->assertSame( [ $this->other_change ], $response->get_data()['skipped'] );
		$this->assertSame( 'pending', Change::find( $this->other_change )->status );
	}

	public function test_bulk_xp_award_only_reaches_this_chronicles_characters(): void {
		wp_set_current_user( $this->storyteller );

		$response = $this->dispatch( 'POST', "/be/v1/{$this->home}/experience/bulk-award", [
			'character_ids' => [ $this->home_character, $this->other_character, 999999 ],
			'amount'        => 5,
			'reason'        => 'Session attendance',
		] );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 1, $response->get_data()['awarded'] );
		$this->assertSame( 5, (int) Character::find( $this->home_character )->xp_earned );
		$this->assertSame( 0, (int) Character::find( $this->other_character )->xp_earned );
		$this->assertSame( 0, Change::count_for_character( 999999 ) );
		$this->assertSame( 0, Change::count_for_character( $this->other_character, [ 'status' => 'approved' ] ) );
	}

	public function test_bulk_xp_award_refuses_an_implausible_amount(): void {
		wp_set_current_user( $this->storyteller );

		$response = $this->dispatch( 'POST', "/be/v1/{$this->home}/experience/bulk-award", [
			'character_ids' => [ $this->home_character ],
			'amount'        => 2147483647,
			'reason'        => 'Typo',
		] );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 0, (int) Character::find( $this->home_character )->xp_earned );
	}
}
