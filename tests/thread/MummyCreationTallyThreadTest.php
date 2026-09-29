<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Game;
use BeyondElysium\Models\Game_Member;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Mummy's own creation rules, tallied through the real routes against the real seeded book: attributes split across
 * two stages, budgeted Abilities/Backgrounds/basic Hekau with a primary-path quota, and Balance/Willpower/Sekhem's
 * own fixed starting values.
 */
class MummyCreationTallyThreadTest extends WP_UnitTestCase {

	private string $slug = 'thread-mummy-tally';
	private int $game_id;
	private int $hst;

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );

		$this->game_id = (int) Game::create( [ 'slug' => $this->slug, 'name' => 'Tallied Duat' ] );
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
	 * A Mesektet with 6/4/3 Attributes plus the 2-point Rebirth bonus (physical and mental), 10 Abilities, 5
	 * Backgrounds, 3 basic Hekau picks (Celestial, the Mesektet's own primary path, plus Alchemy and Necromancy),
	 * each held at level 1 so no pick outranks a starting Balance of 1.
	 *
	 * @return array<string,mixed>
	 */
	private function brian_sheet(): array {
		$physical    = array_map( [ $this, 'row' ], [ 'Agile', 'Brawny', 'Dexterous', 'Enduring', 'Ferocious', 'Graceful', 'Lithe' ] );
		$social      = array_map( [ $this, 'row' ], [ 'Alluring', 'Charismatic', 'Charming', 'Dignified' ] );
		$mental      = array_map( [ $this, 'row' ], [ 'Astute', 'Clever', 'Knowledgeable', 'Wise' ] );
		$abilities   = array_map( [ $this, 'row' ], [ 'Academics', 'Cosmology', 'Divination', 'Medicine', 'Meditation', 'Occult', 'Computer', 'Finance', 'Firearms', 'Subterfuge' ] );
		$backgrounds = array_map( [ $this, 'row' ], [ 'Ba', 'Ka', 'Allies', 'Contacts', 'Resources' ] );

		return [
			'mummy-identity'      => [ 'Amenti' => 'Mesektet' ],
			'met-physical-traits' => $physical,
			'met-social-traits'   => $social,
			'met-mental-traits'   => $mental,
			'mummy-abilities'     => $abilities,
			'mummy-backgrounds'   => $backgrounds,
			'mummy-hekau'         => [
				[ 'name' => 'Celestial', 'level' => 1 ],
				[ 'name' => 'Alchemy', 'level' => 1 ],
				[ 'name' => 'Necromancy', 'level' => 1 ],
			],
		];
	}

	public function test_a_mummy_re_expressed_needs_no_further_xp(): void {
		$response = $this->send( 'POST', "/be/v1/{$this->slug}/creation-tally", [
			'stack_slug' => 'mummy',
			'sheet_data' => $this->brian_sheet(),
		] );
		$tally = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 0, $tally['xp']['needed'], '7/4/4 attributes (6/4/3 plus the 2-point Rebirth bonus), 10 Abilities, 5 Backgrounds and 3 basic Hekau picks are fully covered' );
	}

	public function test_the_primary_path_quota_is_met_by_celestial(): void {
		$response = $this->send( 'POST', "/be/v1/{$this->slug}/creation-tally", [
			'stack_slug' => 'mummy',
			'sheet_data' => $this->brian_sheet(),
		] );
		$tally = $response->get_data();

		$hekau = array_values( array_filter( $tally['steps'], static fn( $s ) => $s['kind'] === 'budget' && $s['section'] === 'mummy-hekau' ) )[0];
		$this->assertTrue( $hekau['quotas'][0]['ok'], "Celestial is the Mesektet's own primary Hekau path" );
		$this->assertSame( 1, $hekau['quotas'][0]['met'] );
	}

	public function test_two_ordinary_paths_alone_fail_the_primary_path_quota(): void {
		$sheet                   = $this->brian_sheet();
		$sheet['mummy-hekau']    = [
			[ 'name' => 'Alchemy', 'level' => 1 ],
			[ 'name' => 'Necromancy', 'level' => 1 ],
			[ 'name' => 'Amulets', 'level' => 1 ],
		];

		$response = $this->send( 'POST', "/be/v1/{$this->slug}/creation-tally", [
			'stack_slug' => 'mummy',
			'sheet_data' => $sheet,
		] );
		$tally = $response->get_data();

		$hekau = array_values( array_filter( $tally['steps'], static fn( $s ) => $s['kind'] === 'budget' && $s['section'] === 'mummy-hekau' ) )[0];
		$this->assertFalse( $hekau['quotas'][0]['ok'], "none of the three is Celestial, the Mesektet's own primary path" );
		$this->assertSame( 0, $hekau['quotas'][0]['met'] );
	}

	public function test_an_amenti_with_no_single_primary_path_is_never_short_the_quota(): void {
		$sheet                = $this->brian_sheet();
		$sheet['mummy-identity']['Amenti'] = 'Udja-Sen';

		$response = $this->send( 'POST', "/be/v1/{$this->slug}/creation-tally", [
			'stack_slug' => 'mummy',
			'sheet_data' => $sheet,
		] );
		$tally = $response->get_data();

		$hekau = array_values( array_filter( $tally['steps'], static fn( $s ) => $s['kind'] === 'budget' && $s['section'] === 'mummy-hekau' ) )[0];
		$this->assertTrue( $hekau['quotas'][0]['ok'], "Udja-Sen's own primary Hekau is Any, mapped to every real path, so any pick satisfies the quota" );
	}

	public function test_a_hekau_pick_that_outranks_balance_is_flagged_not_blocked(): void {
		$sheet                    = $this->brian_sheet();
		$sheet['mummy-hekau'][0]  = [ 'name' => 'Celestial', 'level' => 2 ];

		$response = $this->send( 'POST', "/be/v1/{$this->slug}/creation-tally", [
			'stack_slug' => 'mummy',
			'sheet_data' => $sheet,
		] );
		$tally = $response->get_data();

		$this->assertNotEmpty( $tally['limits'], "a level 2 Hekau pick outranks a starting Balance of 1" );
		$this->assertSame( 'mummy-hekau.Celestial', $tally['limits'][0]['target'] );
	}

	public function test_a_fourth_basic_hekau_path_outside_the_primary_carries_the_surcharge(): void {
		$sheet                  = $this->brian_sheet();
		$sheet['mummy-hekau'][] = [ 'name' => 'Amulets', 'level' => 1 ];

		$response = $this->send( 'POST', "/be/v1/{$this->slug}/creation-tally", [
			'stack_slug' => 'mummy',
			'sheet_data' => $sheet,
		] );

		$this->assertSame( 4, $response->get_data()['xp']['needed'], 'the fourth path, uncovered and out of the Mesektet\'s own primary path, prices at 3 XP plus the 1 XP out-of-path surcharge' );
	}
}
