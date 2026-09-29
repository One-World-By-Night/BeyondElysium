<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Game;
use BeyondElysium\Models\Game_Member;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Changeling's own creation rules, tallied through the real routes against the real seeded book: attributes,
 * budgeted Abilities/Backgrounds/Arts/Realms, Seeming-derived Glamour/Willpower/Banality, and Flaws earning a pool
 * that pays for anything beyond the base budgets.
 */
class ChangelingCreationTallyThreadTest extends WP_UnitTestCase {

	private string $slug = 'thread-changeling-tally';
	private int $game_id;
	private int $hst;

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );

		$this->game_id = (int) Game::create( [ 'slug' => $this->slug, 'name' => 'Tallied Freehold' ] );
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
	 * A Childling with 7/5/3 Attributes, 5 Abilities, 5 Backgrounds, Chicanery to 2 and Wayfare to 1 (3 Art levels),
	 * Actor to 3 and Fae to 2 (5 Realm levels), and no Negative Traits or Flaws.
	 *
	 * @return array<string,mixed>
	 */
	private function cybelle_sheet(): array {
		$physical    = array_map( [ $this, 'row' ], [ 'Agile', 'Brawny', 'Dexterous', 'Enduring', 'Ferocious', 'Graceful', 'Lithe' ] );
		$social      = array_map( [ $this, 'row' ], [ 'Alluring', 'Charismatic', 'Charming', 'Dignified', 'Elegant' ] );
		$mental      = array_map( [ $this, 'row' ], [ 'Alert', 'Attentive', 'Clever' ] );
		$abilities   = array_map( [ $this, 'row' ], [ 'Academics', 'Kenning', 'Melee', 'Streetwise', 'Subterfuge' ] );
		$backgrounds = array_map( [ $this, 'row' ], [ 'Allies', 'Contacts', 'Remembrance', 'Resources', 'Treasure' ] );

		return [
			'changeling-identity' => [ 'Seeming' => 'Childling' ],
			'met-physical-traits' => $physical,
			'met-social-traits'   => $social,
			'met-mental-traits'   => $mental,
			'changeling-abilities'   => $abilities,
			'changeling-backgrounds' => $backgrounds,
			'changeling-arts'  => [
				[ 'name' => 'Chicanery', 'level' => 2 ],
				[ 'name' => 'Wayfare', 'level' => 1 ],
			],
			'changeling-realms' => [
				[ 'name' => 'Actor', 'level' => 3 ],
				[ 'name' => 'Fae', 'level' => 2 ],
			],
		];
	}

	public function test_a_changeling_re_expressed_needs_no_further_xp(): void {
		$response = $this->send( 'POST', "/be/v1/{$this->slug}/creation-tally", [
			'stack_slug' => 'changeling',
			'sheet_data' => $this->cybelle_sheet(),
		] );
		$tally = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 0, $tally['xp']['needed'], '7/5/3 attributes, 5 Abilities, 5 Backgrounds, 3 Art levels and 5 Realm levels are fully covered' );
	}

	public function test_a_childling_starts_at_the_books_own_temper_traits(): void {
		$response = $this->send( 'POST', "/be/v1/{$this->slug}/creation-tally", [
			'stack_slug' => 'changeling',
			'sheet_data' => $this->cybelle_sheet(),
		] );
		$tally = $response->get_data();

		$glamour = array_values( array_filter( $tally['steps'], static fn( $s ) => $s['kind'] === 'start' && $s['target'] === 'changeling-resources.Glamour' && $s['applies'] ) )[0];
		$this->assertSame( 5.0, $glamour['value'] );

		$willpower = array_values( array_filter( $tally['steps'], static fn( $s ) => $s['kind'] === 'start' && $s['target'] === 'changeling-resources.Willpower' && $s['applies'] ) )[0];
		$this->assertSame( 1.0, $willpower['value'] );

		$banality = array_values( array_filter( $tally['steps'], static fn( $s ) => $s['kind'] === 'start' && $s['target'] === 'changeling-resources.Banality' && $s['applies'] ) )[0];
		$this->assertSame( 1.0, $banality['value'] );
	}

	public function test_a_grump_starts_at_the_opposite_end_of_the_scale(): void {
		$sheet = $this->cybelle_sheet();
		$sheet['changeling-identity']['Seeming'] = 'Grump';

		$response = $this->send( 'POST', "/be/v1/{$this->slug}/creation-tally", [
			'stack_slug' => 'changeling',
			'sheet_data' => $sheet,
		] );
		$tally = $response->get_data();

		$banality = array_values( array_filter( $tally['steps'], static fn( $s ) => $s['kind'] === 'start' && $s['target'] === 'changeling-resources.Banality' && $s['applies'] ) )[0];
		$this->assertSame( 5.0, $banality['value'], 'a Grump begins with five Banality' );
	}

	public function test_a_flaw_earns_its_own_value_toward_a_fourth_art_level(): void {
		$sheet                       = $this->cybelle_sheet();
		$sheet['changeling-flaws']   = [ [ 'name' => 'Bad Moon', 'count' => 5 ] ];
		$sheet['changeling-arts'][]  = [ 'name' => 'Chicanery', 'level' => 3 ];

		$response = $this->send( 'POST', "/be/v1/{$this->slug}/creation-tally", [
			'stack_slug' => 'changeling',
			'sheet_data' => $sheet,
		] );
		$tally = $response->get_data();

		$this->assertSame( 5, $tally['pools']['Flaw Points']['earned'], "Bad Moon's own 5-Trait value" );
		$this->assertSame( 0, $tally['xp']['needed'], 'the fourth Art level (3 points, Intermediate Chicanery) is paid from the Flaw, 2 points left unspent' );
	}

	public function test_an_uncovered_art_level_with_nothing_earned_prices_as_xp(): void {
		$sheet                      = $this->cybelle_sheet();
		$sheet['changeling-arts'][] = [ 'name' => 'Chicanery', 'level' => 3 ];

		$response = $this->send( 'POST', "/be/v1/{$this->slug}/creation-tally", [
			'stack_slug' => 'changeling',
			'sheet_data' => $sheet,
		] );

		$this->assertSame( 6, $response->get_data()['xp']['needed'], 'Chicanery\'s third level, Intermediate, prices at 6 XP with nothing earned to cover it' );
	}
}
