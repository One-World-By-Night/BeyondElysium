<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Game;
use BeyondElysium\Models\Game_Member;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Demon's own creation rules, tallied through the real routes against the real seeded book: attributes, budgeted
 * Abilities/Backgrounds/Lores/Virtues, House-derived starting Torment, Virtue-derived starting Willpower, and the
 * House's own Lore discount.
 */
class DemonCreationTallyThreadTest extends WP_UnitTestCase {

	private string $slug = 'thread-demon-tally';
	private int $game_id;
	private int $hst;

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );

		$this->game_id = (int) Game::create( [ 'slug' => $this->slug, 'name' => 'Tallied Fall' ] );
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
	 * A Devil with 7/5/3 Attributes, 5 Abilities, 5 Backgrounds, 3 basic Lore rungs (Flame to 2, Radiance to 1, both
	 * in-House), Virtues at 3/2/1 (3 dots above the 1-each floor), Willpower and Torment left for the engine to start.
	 *
	 * @return array<string,mixed>
	 */
	private function devil_sheet(): array {
		$physical  = array_map( [ $this, 'row' ], [ 'Aggressive', 'Agile', 'Athletic', 'Brawny', 'Brutal', 'Deadly', 'Dexterous' ] );
		$social    = array_map( [ $this, 'row' ], [ 'Alluring', 'Beguiling', 'Callous', 'Charismatic', 'Charming' ] );
		$mental    = array_map( [ $this, 'row' ], [ 'Alert', 'Analytical', 'Astute' ] );
		$abilities = array_map( [ $this, 'row' ], [ 'Academics', 'Animal Ken', 'Archery', 'Athletics', 'Awareness' ] );
		$backgrounds = array_map( [ $this, 'row' ], [ 'Allies', 'Contacts', 'Fame', 'Influence', 'Mentor' ] );

		return [
			'demon-identity'     => [ 'House' => 'Devil' ],
			'met-physical-traits' => $physical,
			'met-social-traits'   => $social,
			'met-mental-traits'   => $mental,
			'demon-abilities'     => $abilities,
			'demon-backgrounds'   => $backgrounds,
			'demon-evocations'    => [
				[ 'name' => 'Lore of Flame', 'level' => 2 ],
				[ 'name' => 'Lore of Radiance', 'level' => 1 ],
			],
			'demon-virtues' => [ 'Conscience' => 3, 'Conviction' => 2, 'Courage' => 1 ],
		];
	}

	public function test_a_devil_re_expressed_needs_no_further_xp(): void {
		$response = $this->send( 'POST', "/be/v1/{$this->slug}/creation-tally", [
			'stack_slug' => 'demon',
			'sheet_data' => $this->devil_sheet(),
		] );
		$tally = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 0, $tally['xp']['needed'], 'attributes, abilities, backgrounds, all 3 basic Lore rungs and all 3 extra Virtue dots are fully covered' );
	}

	public function test_willpower_starts_at_the_two_highest_virtues(): void {
		$response = $this->send( 'POST', "/be/v1/{$this->slug}/creation-tally", [
			'stack_slug' => 'demon',
			'sheet_data' => $this->devil_sheet(),
		] );
		$tally = $response->get_data();

		$start = array_values( array_filter( $tally['steps'], static fn( $s ) => $s['kind'] === 'start' && $s['target'] === 'demon-resources.Willpower' ) )[0];
		$this->assertSame( 5.0, $start['value'], 'Conscience 3 + Conviction 2, the two highest of 3/2/1' );
	}

	public function test_torment_starts_at_the_characters_own_house_value(): void {
		$response = $this->send( 'POST', "/be/v1/{$this->slug}/creation-tally", [
			'stack_slug' => 'demon',
			'sheet_data' => $this->devil_sheet(),
		] );
		$tally = $response->get_data();

		$start = array_values( array_filter( $tally['steps'], static fn( $s ) => $s['kind'] === 'start' && $s['target'] === 'demon-resources.Torment' ) )[0];
		$this->assertSame( 4.0, $start['value'], 'Devils start at Torment 4' );
	}

	public function test_a_fourth_lore_rung_prices_full_in_house_and_surcharged_out_of_house(): void {
		$in_house = $this->devil_sheet();
		$in_house['demon-evocations'][] = [ 'name' => 'Lore of the Celestials', 'level' => 1 ];

		$response = $this->send( 'POST', "/be/v1/{$this->slug}/creation-tally", [
			'stack_slug' => 'demon',
			'sheet_data' => $in_house,
		] );
		// Flame's 2 rungs and Radiance's rung are covered by the 3-slot budget; Celestials' rung is the fourth,
		// uncovered - but it is a Devil's own House Lore, so it prices at the plain basic rate of 3, no surcharge.
		$this->assertSame( 3, $response->get_data()['xp']['needed'] );

		$out_of_house = $this->devil_sheet();
		$out_of_house['demon-evocations'][] = [ 'name' => 'Lore of Death', 'level' => 1 ];

		$response = $this->send( 'POST', "/be/v1/{$this->slug}/creation-tally", [
			'stack_slug' => 'demon',
			'sheet_data' => $out_of_house,
		] );
		// Lore of Death is a Slayer Lore, out of a Devil's own House: 3 + the 1-point surcharge = 4.
		$this->assertSame( 4, $response->get_data()['xp']['needed'] );
	}

	public function test_a_common_lore_is_never_surcharged_regardless_of_house(): void {
		$sheet = $this->devil_sheet();
		$sheet['demon-evocations'][] = [ 'name' => 'Lore of Humanity', 'level' => 1 ];

		$response = $this->send( 'POST', "/be/v1/{$this->slug}/creation-tally", [
			'stack_slug' => 'demon',
			'sheet_data' => $sheet,
		] );

		$this->assertSame( 3, $response->get_data()['xp']['needed'], 'a Common Lore is never out of type, whatever the House' );
	}
}
