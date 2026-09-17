<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Character;
use BeyondElysium\Models\Game_Member;
use BeyondElysium\Services\Change_Engine;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * 1.1.0 D4: a `player_order`-flagged block's held entries can be reordered
 * directly, with no approval and no XP - `PUT /{game}/characters/{id}/order/
 * {block_slug}`. Uses `vampire-rituals` (the seeded trait_list half of the two
 * flagged blocks; `vampire-blood-magic` is the tiered_power half, covered by
 * the same controller logic since it reads `sheet_data` generically).
 *
 * @see BE_PROCESS/releases/1.1.0-design-workflow.md §3.19
 */
class PlayerOrderThreadTest extends WP_UnitTestCase {

	private string $slug = 'thread-player-order';
	private int $game_id;
	private int $owner;
	private int $other_player;
	private int $character;

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );

		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'be_games', [
			'slug' => $this->slug, 'name' => 'Player Order', 'created_by' => 1,
			'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ),
		] );
		$this->game_id = (int) $wpdb->insert_id;

		$this->owner        = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		$this->other_player = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		Game_Member::set_role( $this->game_id, $this->owner, 'player' );
		Game_Member::set_role( $this->game_id, $this->other_player, 'player' );

		$this->character = Character::create( [
			'name' => 'Order Vampire', 'stack_slug' => 'vampire', 'owner_type' => 'chronicle',
			'owner_slug' => $this->slug, 'status' => 'active', 'wp_user_id' => $this->owner,
			'sheet_data' => [
				'vampire-rituals' => [
					[ 'name' => 'Ritual A' ],
					[ 'name' => 'Ritual B' ],
					[ 'name' => 'Ritual C' ],
				],
				'met-merits' => [
					[ 'name' => 'Common Sense', 'count' => 1 ],
				],
			],
		] );
	}

	private function put_order( int $user, string $block_slug, array $body ) {
		wp_set_current_user( $user );
		$request = new WP_REST_Request( 'PUT', "/be/v1/{$this->slug}/characters/{$this->character}/order/{$block_slug}" );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( $body ) );
		return rest_get_server()->dispatch( $request );
	}

	public function test_a_valid_permutation_saves(): void {
		$response = $this->put_order( $this->owner, 'vampire-rituals', [
			'order' => [ 2, 0, 1 ],
			'names' => [ 'Ritual C', 'Ritual A', 'Ritual B' ],
		] );

		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );

		$character = Character::find( $this->character );
		$names     = array_column( $character->sheet_data['vampire-rituals'], 'name' );
		$this->assertSame( [ 'Ritual C', 'Ritual A', 'Ritual B' ], $names );
	}

	public function test_a_stale_list_gets_409(): void {
		// The names don't match what's actually at those indexes - another tab (or an
		// approved change) already changed the list since this client loaded it.
		$response = $this->put_order( $this->owner, 'vampire-rituals', [
			'order' => [ 2, 0, 1 ],
			'names' => [ 'Ritual A', 'Ritual B', 'Ritual C' ],
		] );

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'sheet_changed', $response->as_error()->get_error_code() );

		// Refused, not partially applied.
		$character = Character::find( $this->character );
		$this->assertSame(
			[ 'Ritual A', 'Ritual B', 'Ritual C' ],
			array_column( $character->sheet_data['vampire-rituals'], 'name' )
		);
	}

	public function test_another_player_gets_403(): void {
		$response = $this->put_order( $this->other_player, 'vampire-rituals', [
			'order' => [ 1, 0, 2 ],
			'names' => [ 'Ritual B', 'Ritual A', 'Ritual C' ],
		] );

		$this->assertSame( 403, $response->get_status() );
	}

	public function test_a_non_flagged_block_gets_400(): void {
		$response = $this->put_order( $this->owner, 'met-merits', [
			'order' => [ 0 ],
			'names' => [ 'Common Sense' ],
		] );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'not_player_order', $response->as_error()->get_error_code() );
	}

	/**
	 * Regression check, not this route's own behavior: confirms D4's changes didn't
	 * disturb `Change_Engine::apply_to_sheet()`'s existing append-only order-preserving
	 * behavior for a player_order block.
	 */
	public function test_adding_a_ritual_still_appends(): void {
		$character = Character::find( $this->character );
		$change    = (object) [
			'change_type' => 'add_trait',
			'change_data' => [
				'block_slug' => 'vampire-rituals',
				'trait'      => [ 'name' => 'Ritual D' ],
			],
		];

		$sheet = Change_Engine::apply_to_sheet( $character, $change );

		$this->assertSame(
			[ 'Ritual A', 'Ritual B', 'Ritual C', 'Ritual D' ],
			array_column( $sheet['vampire-rituals'], 'name' )
		);
	}
}
