<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Character;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Game_Member;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Granting a trait_list item paid for from a named resource pool (`_meta.paid_from`) rather than the character's own
 * XP - a Storyteller-only action, proven against Wraith's real Thorns catalog and Shadow XP pool.
 */
class PoolSpendThreadTest extends WP_UnitTestCase {

	private string $slug = 'thread-pool-spend';
	private int $game_id;
	private int $hst;
	private int $player;
	private int $character_id;

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );

		$this->game_id = (int) Game::create( [ 'slug' => $this->slug, 'name' => 'Spent Underworld' ] );
		$this->hst     = self::factory()->user->create( [ 'role' => 'editor' ] );
		Game_Member::set_role( $this->game_id, $this->hst, 'hst' );
		$this->player = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		Game_Member::set_role( $this->game_id, $this->player, 'player' );

		$this->character_id = (int) Character::create( [
			'name'       => 'Marlowe',
			'stack_slug' => 'wraith',
			'owner_slug' => $this->slug,
			'wp_user_id' => $this->player,
			'sheet_data' => [
				'wraith-shadow' => [ 'Shadow XP' => 5 ],
				'wraith-thorns' => [],
			],
		] );
	}

	private function send( string $method, string $route, array $body, int $as ): \WP_REST_Response {
		wp_set_current_user( $as );
		$request = new WP_REST_Request( $method, $route );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( (string) wp_json_encode( $body ) );
		return rest_get_server()->dispatch( $request );
	}

	private function route(): string {
		return "/be/v1/{$this->slug}/characters/{$this->character_id}/pool-purchases";
	}

	public function test_a_storyteller_grants_a_thorn_from_shadow_xp(): void {
		$response = $this->send( 'POST', $this->route(), [ 'block_slug' => 'wraith-thorns', 'name' => 'Freudian Slip' ], $this->hst );

		$this->assertSame( 201, $response->get_status() );

		$character = Character::find( $this->character_id );
		$this->assertSame( 0, $character->sheet_data['wraith-shadow']['Shadow XP'], '5-point Thorn spent the whole 5-point balance' );
		$this->assertCount( 1, $character->sheet_data['wraith-thorns'] );
		$this->assertSame( 'Freudian Slip', $character->sheet_data['wraith-thorns'][0]['name'] );
	}

	public function test_an_insufficient_balance_is_refused_and_grants_nothing(): void {
		$response = $this->send( 'POST', $this->route(), [ 'block_slug' => 'wraith-thorns', 'name' => 'Freudian Slip' ], $this->hst );
		$this->assertSame( 201, $response->get_status() );

		// The balance is now 0; a second Five-Point Thorn cannot be afforded.
		$second = $this->send( 'POST', $this->route(), [ 'block_slug' => 'wraith-thorns', 'name' => 'Shadow Life' ], $this->hst );
		$this->assertSame( 400, $second->get_status() );
		$this->assertSame( 'insufficient_balance', $second->get_data()['code'] );

		$character = Character::find( $this->character_id );
		$this->assertCount( 1, $character->sheet_data['wraith-thorns'], 'the refused purchase granted nothing' );
	}

	public function test_a_player_cannot_spend_the_pool_themselves(): void {
		$response = $this->send( 'POST', $this->route(), [ 'block_slug' => 'wraith-thorns', 'name' => 'Spectre Prestige' ], $this->player );
		$this->assertSame( 403, $response->get_status() );
	}

	public function test_an_unknown_item_is_refused(): void {
		$response = $this->send( 'POST', $this->route(), [ 'block_slug' => 'wraith-thorns', 'name' => 'Made Up Thorn' ], $this->hst );
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'unknown_item', $response->get_data()['code'] );
	}

	public function test_a_block_with_no_paid_from_pool_is_refused(): void {
		$response = $this->send( 'POST', $this->route(), [ 'block_slug' => 'wraith-abilities', 'name' => 'Academics' ], $this->hst );
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'not_pool_funded', $response->get_data()['code'] );
	}

	public function test_a_missing_block_slug_or_name_is_refused(): void {
		$no_block = $this->send( 'POST', $this->route(), [ 'name' => 'Freudian Slip' ], $this->hst );
		$this->assertSame( 400, $no_block->get_status() );
		$this->assertSame( 'invalid_param', $no_block->get_data()['code'] );

		$no_name = $this->send( 'POST', $this->route(), [ 'block_slug' => 'wraith-thorns' ], $this->hst );
		$this->assertSame( 400, $no_name->get_status() );
		$this->assertSame( 'invalid_param', $no_name->get_data()['code'] );
	}

	public function test_a_character_from_another_chronicle_is_not_found(): void {
		$other_slug = 'thread-pool-spend-other';
		Game::create( [ 'slug' => $other_slug, 'name' => 'A Different Underworld' ] );
		$other_character = (int) Character::create( [
			'name'       => 'Elsewhere',
			'stack_slug' => 'wraith',
			'owner_slug' => $other_slug,
			'sheet_data' => [ 'wraith-shadow' => [ 'Shadow XP' => 5 ] ],
		] );

		wp_set_current_user( $this->hst );
		$request = new WP_REST_Request( 'POST', "/be/v1/{$this->slug}/characters/{$other_character}/pool-purchases" );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( (string) wp_json_encode( [ 'block_slug' => 'wraith-thorns', 'name' => 'Spectre Prestige' ] ) );
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 404, $response->get_status() );
	}
}
