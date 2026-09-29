<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Game;
use BeyondElysium\Models\Game_Member;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Mage's own creation rules, tallied through the real routes against the real seeded book: attributes, budgeted
 * Abilities/Backgrounds/Spheres, a Sphere ceiling tied to Arete, and the Tradition's own specialty Sphere pricing
 * in rather than out of type.
 */
class MageCreationTallyThreadTest extends WP_UnitTestCase {

	private string $slug = 'thread-mage-tally';
	private int $game_id;
	private int $hst;

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );

		$this->game_id = (int) Game::create( [ 'slug' => $this->slug, 'name' => 'Tallied Chantry' ] );
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
	 * An Akashic Brotherhood apprentice with 7/5/3 Attributes, 5 Abilities, 7 Backgrounds, and 6 Sphere levels
	 * (Mind, the Tradition's own specialty, plus five others), each held at level 1 to match a starting Arete of 1.
	 *
	 * @return array<string,mixed>
	 */
	private function apprentice_sheet(): array {
		$physical    = array_map( [ $this, 'row' ], [ 'Agile', 'Brawny', 'Dexterous', 'Enduring', 'Ferocious', 'Graceful', 'Lithe' ] );
		$social      = array_map( [ $this, 'row' ], [ 'Alluring', 'Charismatic', 'Charming', 'Dignified', 'Elegant' ] );
		$mental      = array_map( [ $this, 'row' ], [ 'Alert', 'Attentive', 'Clever' ] );
		$abilities   = array_map( [ $this, 'row' ], [ 'Academics', 'Alertness', 'Athletics', 'Awareness', 'Brawl' ] );
		$backgrounds = array_map( [ $this, 'row' ], [ 'Allies', 'Contacts', 'Fame', 'Influence', 'Mentor', 'Resources', 'Retainers' ] );

		return [
			'mage-identity'       => [ 'Tradition' => 'Akashic Brotherhood' ],
			'met-physical-traits' => $physical,
			'met-social-traits'   => $social,
			'met-mental-traits'   => $mental,
			'mage-abilities'      => $abilities,
			'mage-backgrounds'    => $backgrounds,
			'mage-spheres'        => [
				[ 'name' => 'Mind', 'level' => 1 ],
				[ 'name' => 'Forces', 'level' => 1 ],
				[ 'name' => 'Prime', 'level' => 1 ],
				[ 'name' => 'Correspondence', 'level' => 1 ],
				[ 'name' => 'Time', 'level' => 1 ],
				[ 'name' => 'Life', 'level' => 1 ],
			],
		];
	}

	public function test_a_mage_re_expressed_needs_no_further_xp(): void {
		$response = $this->send( 'POST', "/be/v1/{$this->slug}/creation-tally", [
			'stack_slug' => 'mage',
			'sheet_data' => $this->apprentice_sheet(),
		] );
		$tally = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 0, $tally['xp']['needed'], '7/5/3 attributes, 5 Abilities, 7 Backgrounds and 6 Sphere levels are fully covered' );
	}

	public function test_a_seventh_sphere_level_outside_specialty_prices_at_five(): void {
		$sheet                   = $this->apprentice_sheet();
		$sheet['mage-spheres'][] = [ 'name' => 'Matter', 'level' => 1 ];

		$response = $this->send( 'POST', "/be/v1/{$this->slug}/creation-tally", [
			'stack_slug' => 'mage',
			'sheet_data' => $sheet,
		] );

		$this->assertSame( 5, $response->get_data()['xp']['needed'], "a seventh Sphere level, uncovered and outside Mind (Akashic Brotherhood's own specialty), prices at the book's non-specialty 5 XP" );
	}

	public function test_a_seventh_sphere_level_inside_specialty_prices_at_four(): void {
		$sheet = $this->apprentice_sheet();
		// Mind moved last so its own second rung, not another family's first, is the seventh, uncovered unit.
		$mind                     = array_shift( $sheet['mage-spheres'] );
		$sheet['mage-spheres'][]  = [ 'name' => $mind['name'], 'level' => 2 ];

		$response = $this->send( 'POST', "/be/v1/{$this->slug}/creation-tally", [
			'stack_slug' => 'mage',
			'sheet_data' => $sheet,
		] );

		$this->assertSame( 4, $response->get_data()['xp']['needed'], "Mind's second level, uncovered, prices at the book's own specialty rate of 4 XP" );
	}

	public function test_a_sphere_level_that_outranks_arete_is_flagged_not_blocked(): void {
		$sheet                    = $this->apprentice_sheet();
		$sheet['mage-spheres'][0] = [ 'name' => 'Mind', 'level' => 2 ];

		$response = $this->send( 'POST', "/be/v1/{$this->slug}/creation-tally", [
			'stack_slug' => 'mage',
			'sheet_data' => $sheet,
		] );
		$tally = $response->get_data();

		$this->assertNotEmpty( $tally['limits'], 'Mind at level 2 outranks a starting Arete of 1' );
		$this->assertSame( 'mage-spheres.Mind', $tally['limits'][0]['target'] );
	}

	public function test_an_orphan_prices_their_own_chosen_sphere_in_type(): void {
		$sheet                  = $this->apprentice_sheet();
		$sheet['mage-identity'] = [ 'Tradition' => 'Orphan', 'Chosen Specialty Sphere' => 'Correspondence' ];
		// Correspondence moved last so its own second rung, not another family's first, is the seventh, uncovered unit.
		$sheet['mage-spheres'] = array_values( array_filter( $sheet['mage-spheres'], static fn( $row ) => $row['name'] !== 'Correspondence' ) );
		$sheet['mage-spheres'][] = [ 'name' => 'Correspondence', 'level' => 2 ];

		$response = $this->send( 'POST', "/be/v1/{$this->slug}/creation-tally", [
			'stack_slug' => 'mage',
			'sheet_data' => $sheet,
		] );

		$this->assertSame( 4, $response->get_data()['xp']['needed'], "the Orphan's own chosen specialty Sphere prices its uncovered second level at the in-type 4 XP" );
	}
}
