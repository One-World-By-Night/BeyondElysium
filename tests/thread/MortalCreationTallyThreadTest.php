<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Game;
use BeyondElysium\Models\Game_Member;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Mortal's own creation rules, tallied through the real routes against the real seeded book: Attributes 6/4/3,
 * Abilities 5, Backgrounds 3, a flat Humanity and Willpower start, and 10 Free Traits, the only creature type in
 * this catalog whose baseline is smaller than a supernatural's own.
 */
class MortalCreationTallyThreadTest extends WP_UnitTestCase {

	private string $slug = 'thread-mortal-tally';
	private int $game_id;
	private int $hst;

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );

		$this->game_id = (int) Game::create( [ 'slug' => $this->slug, 'name' => 'Tallied Precinct' ] );
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

	private function base_sheet(): array {
		$physical    = array_map( [ $this, 'row' ], [ 'Agile', 'Brawny', 'Dexterous', 'Enduring', 'Ferocious', 'Graceful' ] );
		$social      = array_map( [ $this, 'row' ], [ 'Alluring', 'Charismatic', 'Charming', 'Dignified' ] );
		$mental      = array_map( [ $this, 'row' ], [ 'Alert', 'Attentive', 'Clever' ] );
		$abilities   = array_map( [ $this, 'row' ], [ 'Academics', 'Animal Ken', 'Athletics', 'Awareness', 'Brawl' ] );
		$backgrounds = array_map( [ $this, 'row' ], [ 'Allies', 'Contacts', 'Fame' ] );

		return [
			'met-physical-traits' => $physical,
			'met-social-traits'   => $social,
			'met-mental-traits'   => $mental,
			'mortal-abilities'    => $abilities,
			'mortal-backgrounds'  => $backgrounds,
		];
	}

	public function test_a_mortal_re_expressed_needs_no_further_xp(): void {
		$response = $this->send( 'POST', "/be/v1/{$this->slug}/creation-tally", [
			'stack_slug' => 'mortal',
			'sheet_data' => $this->base_sheet(),
		] );
		$tally = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 0, $tally['xp']['needed'], '6/4/3 attributes, 5 Abilities and 3 Backgrounds are fully covered' );
	}

	public function test_a_mortal_starts_at_the_books_own_humanity_and_willpower(): void {
		$response = $this->send( 'POST', "/be/v1/{$this->slug}/creation-tally", [
			'stack_slug' => 'mortal',
			'sheet_data' => $this->base_sheet(),
		] );
		$tally = $response->get_data();

		$humanity = array_values( array_filter( $tally['steps'], static fn( $s ) => $s['kind'] === 'start' && $s['target'] === 'mortal-resources.Humanity' && $s['applies'] ) )[0];
		$this->assertSame( 4.0, $humanity['value'], "the book's own \"approximates that of most 'normal' humans\" rating" );

		$willpower = array_values( array_filter( $tally['steps'], static fn( $s ) => $s['kind'] === 'start' && $s['target'] === 'mortal-resources.Willpower' && $s['applies'] ) )[0];
		$this->assertSame( 3.0, $willpower['value'], "\"Hunters begin play with a permanent Willpower of three Traits\"" );
	}

	public function test_a_basic_numina_is_covered_by_free_traits(): void {
		$sheet                    = $this->base_sheet();
		$sheet['mortal-psychic']  = [
			[ 'name' => 'Animal Psychic', 'power_name' => 'Communication' ],
		];

		$response = $this->send( 'POST', "/be/v1/{$this->slug}/creation-tally", [
			'stack_slug' => 'mortal',
			'sheet_data' => $sheet,
		] );

		$this->assertSame( 0, $response->get_data()['xp']['needed'], "a basic Numina costs 3 of the 10 Free Traits, still fully covered" );
	}

	public function test_free_traits_run_out_before_a_fourth_basic_numina(): void {
		$sheet                   = $this->base_sheet();
		$sheet['mortal-psychic'] = [
			[ 'name' => 'Animal Psychic', 'power_name' => 'Communication' ],
			[ 'name' => 'Anti-Psychic', 'power_name' => '5-yard radius' ],
			[ 'name' => 'Astral Projection', 'power_name' => 'Seeker' ],
			[ 'name' => 'Biocontrol', 'power_name' => 'Self Control' ],
		];

		$response = $this->send( 'POST', "/be/v1/{$this->slug}/creation-tally", [
			'stack_slug' => 'mortal',
			'sheet_data' => $sheet,
		] );

		$this->assertSame( 3, $response->get_data()['xp']['needed'], "4 basic Numina at 3 Free Traits each is 12 against a 10-Trait pool; the 10th through 12th points price as the book's own basic cost, 3 XP" );
	}
}
