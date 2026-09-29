<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Game;
use BeyondElysium\Models\Game_Member;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Kuei-Jin's own creation rules, tallied through the real routes against the real seeded book: attributes, budgeted
 * Abilities/Backgrounds/basic Disciplines with a Demon Art quota, and the Chi Virtue split between Yin and Yang.
 */
class KueijinCreationTallyThreadTest extends WP_UnitTestCase {

	private string $slug = 'thread-kueijin-tally';
	private int $game_id;
	private int $hst;

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );

		$this->game_id = (int) Game::create( [ 'slug' => $this->slug, 'name' => 'Tallied Middle Kingdom' ] );
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
	 * A Kuei-jin with 7/5/3 Attributes, 5 Abilities, 5 Backgrounds, one basic Demon Art (Black Wind) plus two other
	 * basic Disciplines, and a 4-point Chi Virtue split (Yin 2 / Yang 4, one already in each) matching the book's own
	 * worked example.
	 *
	 * @return array<string,mixed>
	 */
	private function jo_loung_sheet(): array {
		$physical    = array_map( [ $this, 'row' ], [ 'Nimble', 'Robust', 'Wiry', 'Quick', 'Enduring', 'Steady', 'Vigorous' ] );
		$social      = array_map( [ $this, 'row' ], [ 'Charismatic', 'Witty', 'Persuasive', 'Empathetic', 'Charming' ] );
		$mental      = array_map( [ $this, 'row' ], [ 'Clever', 'Creative', 'Wise' ] );
		$abilities   = array_map( [ $this, 'row' ], [ 'Brawl', 'Dodge', 'Athletics', 'Empathy', 'Medicine' ] );
		$backgrounds = array_map( [ $this, 'row' ], [ 'Contacts', 'Contacts', 'Allies', 'Horoscope', 'Horoscope' ] );

		return [
			'kueijin-identity'    => [ 'Dharma' => 'Dance of the Thrashing Dragon' ],
			'met-physical-traits' => $physical,
			'met-social-traits'   => $social,
			'met-mental-traits'   => $mental,
			'kueijin-abilities'   => $abilities,
			'kueijin-backgrounds' => $backgrounds,
			'kueijin-disciplines' => [
				[ 'name' => "Black Wind: Hell's Howling Typhoon", 'level' => 1 ],
				[ 'name' => 'Yang Prana', 'level' => 1 ],
				[ 'name' => 'Flesh Shintai', 'level' => 1 ],
			],
			'kueijin-virtues'     => [ 'Yin Chi' => 2, 'Yang Chi' => 4 ],
		];
	}

	public function test_a_kuei_jin_re_expressed_needs_no_further_xp(): void {
		$response = $this->send( 'POST', "/be/v1/{$this->slug}/creation-tally", [
			'stack_slug' => 'kueijin',
			'sheet_data' => $this->jo_loung_sheet(),
		] );
		$tally = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 0, $tally['xp']['needed'], 'attributes, abilities, backgrounds, all 3 basic Disciplines and the full 4-point Chi split are fully covered' );
	}

	public function test_the_demon_art_quota_is_met_by_black_wind(): void {
		$response = $this->send( 'POST', "/be/v1/{$this->slug}/creation-tally", [
			'stack_slug' => 'kueijin',
			'sheet_data' => $this->jo_loung_sheet(),
		] );
		$tally = $response->get_data();

		$disciplines = array_values( array_filter( $tally['steps'], static fn( $s ) => $s['kind'] === 'budget' && $s['section'] === 'kueijin-disciplines' ) )[0];
		$this->assertTrue( $disciplines['quotas'][0]['ok'], 'Black Wind is a Demon Art, satisfying the quota' );
		$this->assertSame( 1, $disciplines['quotas'][0]['met'] );
	}

	public function test_three_ordinary_disciplines_fail_the_demon_art_quota(): void {
		$sheet                         = $this->jo_loung_sheet();
		$sheet['kueijin-disciplines']   = [
			[ 'name' => 'Yang Prana', 'level' => 1 ],
			[ 'name' => 'Flesh Shintai', 'level' => 1 ],
			[ 'name' => 'Cultivation', 'level' => 1 ],
		];

		$response = $this->send( 'POST', "/be/v1/{$this->slug}/creation-tally", [
			'stack_slug' => 'kueijin',
			'sheet_data' => $sheet,
		] );
		$tally = $response->get_data();

		$disciplines = array_values( array_filter( $tally['steps'], static fn( $s ) => $s['kind'] === 'budget' && $s['section'] === 'kueijin-disciplines' ) )[0];
		$this->assertFalse( $disciplines['quotas'][0]['ok'], 'none of the three is a Demon Art' );
		$this->assertSame( 0, $disciplines['quotas'][0]['met'] );
	}

	public function test_a_fourth_basic_discipline_is_paid_by_a_free_trait_not_raw_xp(): void {
		$sheet                          = $this->jo_loung_sheet();
		$sheet['kueijin-disciplines'][] = [ 'name' => 'Cultivation', 'level' => 1 ];

		$response = $this->send( 'POST', "/be/v1/{$this->slug}/creation-tally", [
			'stack_slug' => 'kueijin',
			'sheet_data' => $sheet,
		] );
		$tally = $response->get_data();

		$this->assertSame( 0, $tally['xp']['needed'], 'four of the five Free Traits buy the fourth Discipline\'s Basic level outright' );
		$this->assertSame( 4, $tally['pools']['Free Traits']['spent'] );
	}

	public function test_a_chi_point_beyond_both_the_budget_and_the_free_traits_prices_as_xp(): void {
		$sheet                                = $this->jo_loung_sheet();
		$sheet['kueijin-disciplines'][]        = [ 'name' => 'Cultivation', 'level' => 1 ];
		$sheet['kueijin-virtues']['Yang Chi']  = 5;

		$response = $this->send( 'POST', "/be/v1/{$this->slug}/creation-tally", [
			'stack_slug' => 'kueijin',
			'sheet_data' => $sheet,
		] );
		$tally = $response->get_data();

		// The fourth Discipline (section order: kueijin-disciplines before kueijin-virtues) spends the
		// Free Traits pool down to 1 remaining, one short of the 3 a fifth Chi point needs.
		$this->assertSame( 4, $tally['pools']['Free Traits']['spent'] );
		$this->assertSame( 3, $tally['xp']['needed'], 'the fifth Yang Chi dot, uncovered by budget and unaffordable to the one Free Trait left, prices at 3 XP' );
	}

	public function test_hun_and_po_start_at_their_own_book_values(): void {
		$response = $this->send( 'POST', "/be/v1/{$this->slug}/creation-tally", [
			'stack_slug' => 'kueijin',
			'sheet_data' => $this->jo_loung_sheet(),
		] );

		$this->assertSame( 0, $response->get_data()['xp']['needed'], 'Hun 1 and P\'o 3 are the seeded defaults, needing no further budget or XP' );
	}
}
