<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Game;
use BeyondElysium\Models\Game_Member;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Hunter's own creation rules, tallied through the real route against the real seeded book: Conviction starting by
 * creed, and a held Virtue never outranking the creed's own primary Virtue (Mercy, Vision or Zeal).
 */
class HunterCreationTallyThreadTest extends WP_UnitTestCase {

	private string $slug = 'thread-hunter-tally';
	private int $game_id;
	private int $hst;

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );

		$this->game_id = (int) Game::create( [ 'slug' => $this->slug, 'name' => 'Tallied Hunt' ] );
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
	 * @return array<string,mixed>
	 */
	private function sheet( string $creed, array $virtues ): array {
		return [
			'hunter-identity'  => [ 'Creed' => $creed ],
			'hunter-virtues'   => $virtues,
			'hunter-resources' => [ 'Willpower' => [ 'permanent' => 2, 'temporary' => 2 ] ],
		];
	}

	public function test_a_martyrdom_hunter_starts_at_conviction_4_and_a_judgment_one_at_3(): void {
		$martyr = $this->send( 'POST', "/be/v1/{$this->slug}/creation-tally", [
			'stack_slug' => 'hunter',
			'sheet_data' => $this->sheet( 'Martyrdom', [] ),
		] )->get_data();
		$judge = $this->send( 'POST', "/be/v1/{$this->slug}/creation-tally", [
			'stack_slug' => 'hunter',
			'sheet_data' => $this->sheet( 'Judgment', [] ),
		] )->get_data();

		$conviction_step = static fn( $tally ) => current( array_filter( $tally['steps'], static fn( $s ) => $s['kind'] === 'start' && $s['label'] === 'Conviction' ) );

		$this->assertEquals( 4, $conviction_step( $martyr )['value'] );
		$this->assertEquals( 3, $conviction_step( $judge )['value'] );
	}

	public function test_an_innocence_hunters_primary_virtue_is_mercy_vision_at_two_outranks_mercy_at_one(): void {
		$sheet = $this->sheet( 'Innocence', [
			'Mercy'  => [ 'permanent' => 1, 'temporary' => 0 ],
			'Vision' => [ 'permanent' => 2, 'temporary' => 0 ],
			'Zeal'   => [ 'permanent' => 0, 'temporary' => 0 ],
		] );

		$response = $this->send( 'POST', "/be/v1/{$this->slug}/creation-tally", [
			'stack_slug' => 'hunter',
			'sheet_data' => $sheet,
		] );
		$tally = $response->get_data();

		$this->assertSame( 200, $response->get_status(), wp_json_encode( $tally ) );
		$targets = array_column( $tally['limits'], 'target' );
		$this->assertContains( 'hunter-virtues.Vision', $targets, "Vision (2) outranks Mercy (1), Innocence's own primary Virtue" );
	}

	public function test_an_innocence_hunter_with_mercy_as_the_highest_virtue_is_not_flagged(): void {
		$sheet = $this->sheet( 'Innocence', [
			'Mercy'  => [ 'permanent' => 2, 'temporary' => 0 ],
			'Vision' => [ 'permanent' => 1, 'temporary' => 0 ],
			'Zeal'   => [ 'permanent' => 0, 'temporary' => 0 ],
		] );

		$response = $this->send( 'POST', "/be/v1/{$this->slug}/creation-tally", [
			'stack_slug' => 'hunter',
			'sheet_data' => $sheet,
		] );
		$tally = $response->get_data();

		$targets = array_column( $tally['limits'], 'target' );
		$this->assertNotContains( 'hunter-virtues.Vision', $targets );
		$this->assertNotContains( 'hunter-virtues.Zeal', $targets );
	}

	/**
	 * Mind's Eye Theatre: Laws of the Reckoning (WW05037), "Sample Character Creation", read directly: Jimmy, a
	 * Martyr. Social 6/Physical 4/Mental 3; Abilities Expression 1, Performance 2, Streetwise 1, Brawl 1 (5);
	 * Backgrounds Contacts 1, Allies 1, Exposure 1, Resources 1, Fame 1 (5); Conviction 4 (a Martyr's own start);
	 * Willpower 2; Virtues Mercy 2 (Martyrdom's own primary) and Zeal 1; edges Demand and Project (both Martyrdom,
	 * his own primary path) and Ward (Defense, a second path that only ties his primary's own count, never
	 * outranking it) - the book's own prose calls these "Gifted", but the Virtue Trait math (three Virtue Traits
	 * held, three spent) only balances if they are Touched at 1 Trait each, matching this catalog's own reading of
	 * the Edges chapter directly; one Negative Trait (Impatient) and two Flaws (Eccentric Appearance, Nightmares)
	 * for three extra Free Traits, eight in total, spent as two on named Physical/Mental Traits (Tenacious,
	 * Observant), three on Abilities (Athletics, Stealth, Intuition) and three (by value) on two Merits (Good Right
	 * Hook 1, Best Friend 2).
	 *
	 * @return array<string,mixed>
	 */
	private function jimmy_sheet(): array {
		$physical = array_map( [ $this, 'row' ], [ 'Athletic', 'Wiry', 'Energetic', 'Tough', 'Tenacious' ] );
		$social   = array_map( [ $this, 'row' ], [ 'Expressive', 'Witty', 'Charismatic', 'Eloquent', 'Gorgeous', 'Friendly' ] );
		$mental   = array_map( [ $this, 'row' ], [ 'Creative', 'Intuitive', 'Alert', 'Observant' ] );

		return [
			'hunter-identity'  => [ 'Creed' => 'Martyrdom' ],
			'met-physical-traits' => $physical,
			'met-social-traits'   => $social,
			'met-mental-traits'   => $mental,
			'met-mental-traits-neg' => [ $this->row( 'Impatient' ) ],
			'hunter-abilities' => [
				$this->row( 'Expression' ),
				[ 'name' => 'Performance', 'count' => 2, 'specialization' => 'Singing' ],
				$this->row( 'Streetwise' ), $this->row( 'Brawl' ),
				$this->row( 'Athletics' ), $this->row( 'Stealth' ), $this->row( 'Intuition' ),
			],
			'hunter-backgrounds' => array_map( [ $this, 'row' ], [ 'Contacts', 'Allies', 'Exposure', 'Resources', 'Fame' ] ),
			'hunter-virtues'   => [
				'Mercy' => [ 'permanent' => 2, 'temporary' => 0 ],
				'Vision' => [ 'permanent' => 0, 'temporary' => 0 ],
				'Zeal'  => [ 'permanent' => 1, 'temporary' => 0 ],
			],
			'hunter-resources' => [
				'Willpower'  => [ 'permanent' => 2, 'temporary' => 2 ],
				'Conviction' => [ 'permanent' => 4, 'temporary' => 4 ],
			],
			'hunter-edges' => [
				[ 'name' => 'Martyrdom Path', 'power_name' => 'Demand', 'spent_rank' => 'touched' ],
				[ 'name' => 'Martyrdom Path', 'power_name' => 'Project', 'spent_rank' => 'touched' ],
				[ 'name' => 'Defense Path', 'power_name' => 'Ward', 'spent_rank' => 'touched' ],
			],
			'hunter-merits' => [ $this->row( 'Good Right/Left Hook' ), $this->row( 'Best Friend' ) ],
			'hunter-flaws'  => [ $this->row( 'Eccentric Appearance' ), $this->row( 'Nightmares' ) ],
		];
	}

	public function test_jimmys_build_from_the_books_own_worked_example_needs_no_further_xp(): void {
		$response = $this->send( 'POST', "/be/v1/{$this->slug}/creation-tally", [
			'stack_slug' => 'hunter',
			'sheet_data' => $this->jimmy_sheet(),
		] );
		$tally = $response->get_data();

		$this->assertSame( 200, $response->get_status(), wp_json_encode( $tally ) );
		$this->assertEmpty( $tally['limits'], 'Mercy (2) is Martyrdom\'s own primary Virtue and outranks Zeal (1)' );
		$this->assertSame( 0, $tally['xp']['needed'], wp_json_encode( $tally ) );
	}

	public function test_a_defense_hunters_primary_virtue_is_zeal_not_mercy(): void {
		$sheet = $this->sheet( 'Defense', [
			'Mercy'  => [ 'permanent' => 2, 'temporary' => 0 ],
			'Vision' => [ 'permanent' => 0, 'temporary' => 0 ],
			'Zeal'   => [ 'permanent' => 1, 'temporary' => 0 ],
		] );

		$response = $this->send( 'POST', "/be/v1/{$this->slug}/creation-tally", [
			'stack_slug' => 'hunter',
			'sheet_data' => $sheet,
		] );
		$tally = $response->get_data();

		$targets = array_column( $tally['limits'], 'target' );
		$this->assertContains( 'hunter-virtues.Mercy', $targets, "for a Defender, Mercy (2) outranking Zeal (1), the creed's own primary, is what's flagged" );
	}
}
