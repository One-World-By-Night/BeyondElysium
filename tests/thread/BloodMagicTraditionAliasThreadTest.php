<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Change;
use BeyondElysium\Models\Character;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Game_Member;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * A held blood-magic power carrying Grapevine's legacy "Sadhanna" spelling validates and prices a level raise
 * correctly, the same as the catalog's own canonical "Sadhana".
 */
class BloodMagicTraditionAliasThreadTest extends WP_UnitTestCase {

	private string $slug = 'thread-bm-tradition-alias';
	private int $player;

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );

		Game::create( [ 'slug' => $this->slug, 'name' => 'Blood Magic Tradition Alias' ] );
		$this->player = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		Game_Member::set_role( (int) Game::find_by_slug( $this->slug )->id, $this->player, 'player' );
	}

	private function character_holding_alchemy_1( string $tradition ): int {
		$id = (int) Character::create( [
			'name' => 'Legacy Import Test', 'stack_slug' => 'vampire', 'owner_type' => 'chronicle',
			'owner_slug' => $this->slug, 'wp_user_id' => $this->player,
			'sheet_data' => [
				'vampire-blood-magic' => [
					[ 'name' => 'Alchemy', 'level' => 1, 'tradition' => $tradition ],
				],
			],
		] );
		Character::update_xp( $id, 50, 50 );
		return $id;
	}

	private function raise_to_level_2( int $character_id, string $tradition ) {
		wp_set_current_user( $this->player );
		$request = new WP_REST_Request( 'POST', "/be/v1/{$this->slug}/characters/{$character_id}/changes" );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( [
			'category'    => 'test',
			'change_type' => 'modify_trait',
			'change_data' => [
				'block_slug' => 'vampire-blood-magic',
				'trait'      => [ 'name' => 'Alchemy', 'level' => 2, 'tradition' => $tradition ],
			],
		] ) );
		return rest_get_server()->dispatch( $request );
	}

	public function test_a_held_sadhanna_tradition_validates_and_prices_a_level_raise(): void {
		$character_id = $this->character_holding_alchemy_1( 'Sadhanna' );

		$response = $this->raise_to_level_2( $character_id, 'Sadhanna' );

		$this->assertSame( 201, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$this->assertSame( 3.0, (float) $response->get_data()->xp_cost, 'raising level 1 to 2 prices level 2 alone' );

		$change = Change::find( (int) $response->get_data()->id );
		$this->assertSame(
			'Sadhana',
			$change->change_data['trait']['tradition'],
			'the legacy spelling is canonicalized going forward, not merely tolerated'
		);
	}

	public function test_the_canonical_spelling_still_works_unchanged(): void {
		$character_id = $this->character_holding_alchemy_1( 'Sadhana' );

		$response = $this->raise_to_level_2( $character_id, 'Sadhana' );

		$this->assertSame( 201, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$this->assertSame( 3.0, (float) $response->get_data()->xp_cost );
	}

	public function test_a_genuinely_unknown_tradition_is_still_refused(): void {
		$character_id = $this->character_holding_alchemy_1( 'Sadhana' );

		$response = $this->raise_to_level_2( $character_id, 'Not A Real Tradition' );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'unknown_tradition', $response->as_error()->get_error_code() );
	}
}
