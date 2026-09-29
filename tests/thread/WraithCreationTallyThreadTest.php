<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Game;
use BeyondElysium\Models\Game_Member;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Wraith's own creation rules, tallied through the real routes against the real seeded book: attributes, budgeted
 * Abilities/Arcanoi/Passions/Fetters, the Thorns points pool, the Status grant, and the Guild's own Arcanos discount.
 */
class WraithCreationTallyThreadTest extends WP_UnitTestCase {

	private string $slug = 'thread-wraith-tally';
	private int $game_id;
	private int $hst;

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );

		$this->game_id = (int) Game::create( [ 'slug' => $this->slug, 'name' => 'Tallied Underworld' ] );
		$this->hst     = self::factory()->user->create( [ 'role' => 'editor' ] );
		Game_Member::set_role( $this->game_id, $this->hst, 'hst' );
	}

	private function send( string $method, string $route, array $body = [] ): \WP_REST_Response {
		wp_set_current_user( $this->hst );
		$request = new WP_REST_Request( $method, $route );
		$request->set_header( 'Content-Type', 'application/json' );
		if ( $method === 'GET' ) {
			$request->set_query_params( $body );
		} else {
			$request->set_body( (string) wp_json_encode( $body ) );
		}
		return rest_get_server()->dispatch( $request );
	}

	private function row( string $name ): array {
		return [ 'name' => $name ];
	}

	/**
	 * A Harbinger with 7/5/3 Attributes, 5 Abilities, Argos to intermediate (3 rungs) and Castigate basic (2 rungs) - 5
	 * Arcanoi rungs exactly, 6 Passions, 4 Fetters, and a Two-Point Thorn.
	 *
	 * @return array<string,mixed>
	 */
	private function harbinger_sheet(): array {
		$physical  = array_map( [ $this, 'row' ], [ 'Aggressive', 'Agile', 'Athletic', 'Brawny', 'Brutal', 'Deadly', 'Dexterous' ] );
		$social    = array_map( [ $this, 'row' ], [ 'Alluring', 'Beguiling', 'Callous', 'Charismatic', 'Charming' ] );
		$mental    = array_map( [ $this, 'row' ], [ 'Alert', 'Analytical', 'Astute' ] );
		$abilities = array_map( [ $this, 'row' ], [ 'Academics', 'Animal Ken', 'Archery', 'Athletics', 'Awareness' ] );

		return [
			'wraith-identity'      => [ 'Guild' => 'Harbinger' ],
			'met-physical-traits'  => $physical,
			'met-social-traits'    => $social,
			'met-mental-traits'    => $mental,
			'wraith-abilities'     => $abilities,
			'wraith-arcanoi'       => [
				[ 'name' => 'Argos', 'level' => 3 ],
				[ 'name' => 'Castigate', 'level' => 2 ],
			],
			'wraith-passions'      => [
				[ 'name' => 'Protect my sister', 'count' => 1, 'custom' => true ],
				[ 'name' => 'Solve my own murder', 'count' => 1, 'custom' => true ],
				[ 'name' => 'Punish the guilty', 'count' => 1, 'custom' => true ],
				[ 'name' => 'Return home', 'count' => 1, 'custom' => true ],
				[ 'name' => 'Watch over my children', 'count' => 1, 'custom' => true ],
				[ 'name' => 'Feed on their fear', 'count' => 1, 'custom' => true ],
			],
			'wraith-fetters'       => [
				[ 'name' => 'My wedding ring', 'count' => 1, 'custom' => true ],
				[ 'name' => 'My old house', 'count' => 1, 'custom' => true ],
				[ 'name' => "My daughter's locket", 'count' => 1, 'custom' => true ],
				[ 'name' => 'My unfinished novel', 'count' => 1, 'custom' => true ],
			],
			'wraith-thorns'        => [
				[ 'name' => 'Shadow Traits' ],
			],
		];
	}

	public function test_a_harbinger_re_expressed_needs_no_further_xp(): void {
		$response = $this->send( 'POST', "/be/v1/{$this->slug}/creation-tally", [
			'stack_slug' => 'wraith',
			'sheet_data' => $this->harbinger_sheet(),
		] );
		$tally = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 0, $tally['xp']['needed'], 'attributes, abilities, all 5 Arcanoi rungs, 6 Passions, 4 Fetters and the Two-Point Thorn are all fully covered' );
	}

	/**
	 * Replaces the base sheet's Argos level-3 row with level 4, adding one more rung (Jump, intermediate, cost 6) - a
	 * sixth rung the 5-slot budget cannot cover.
	 *
	 * @return array<string,mixed>
	 */
	private function harbinger_sheet_with_a_sixth_rung(): array {
		$sheet = $this->harbinger_sheet();
		foreach ( $sheet['wraith-arcanoi'] as &$entry ) {
			if ( $entry['name'] === 'Argos' ) {
				$entry['level'] = 4;
			}
		}
		unset( $entry );
		return $sheet;
	}

	public function test_a_sixth_arcanoi_rung_prices_at_full_rate_out_of_guild(): void {
		$response = $this->send( 'POST', "/be/v1/{$this->slug}/creation-tally", [
			'stack_slug' => 'wraith',
			'sheet_data' => $this->harbinger_sheet_with_a_sixth_rung(),
		] );
		$tally = $response->get_data();

		// Argos's own 4 rungs (levels 1-4) are read first and fill 4 of the 5 slots; Castigate's rung 1 is the 5th.
		// Castigate's rung 2 (Dark Secrets, basic, cost 4) is the sixth, uncovered unit - and Castigate is not the
		// Harbinger's own Guild Arcanos, so no discount applies: full 4.
		$this->assertSame( 4, $tally['xp']['needed'] );
	}

	public function test_the_same_uncovered_rung_gets_the_guild_discount_when_it_matches(): void {
		$sheet                    = $this->harbinger_sheet_with_a_sixth_rung();
		$sheet['wraith-identity'] = [ 'Guild' => 'Pardoner' ];

		$response = $this->send( 'POST', "/be/v1/{$this->slug}/creation-tally", [
			'stack_slug' => 'wraith',
			'sheet_data' => $sheet,
		] );
		$tally = $response->get_data();

		// Castigate is the Pardoner's own Guild Arcanos, so the same uncovered rung's -1 discount now applies: 4 - 1 = 3.
		$this->assertSame( 3, $tally['xp']['needed'] );
	}
}
