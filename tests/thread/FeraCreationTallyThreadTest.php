<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Game;
use BeyondElysium\Models\Game_Member;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Fera's own creation rules, tallied through the real routes against the real seeded book: attributes and
 * Abilities/Backgrounds/Gifts inherited from Laws of the Wild, with Gnosis, Rage and Willpower set per species and
 * breed rather than the Garou breed/auspice/tribe table.
 */
class FeraCreationTallyThreadTest extends WP_UnitTestCase {

	private string $slug = 'thread-fera-tally';
	private int $game_id;
	private int $hst;

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );

		$this->game_id = (int) Game::create( [ 'slug' => $this->slug, 'name' => 'Tallied Protectorate' ] );
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
		$physical    = array_map( [ $this, 'row' ], [ 'Agile', 'Brawny', 'Dexterous', 'Enduring', 'Ferocious', 'Graceful', 'Lithe' ] );
		$social      = array_map( [ $this, 'row' ], [ 'Alluring', 'Charismatic', 'Charming', 'Dignified', 'Elegant' ] );
		$mental      = array_map( [ $this, 'row' ], [ 'Alert', 'Attentive', 'Clever' ] );
		$abilities   = array_map( [ $this, 'row' ], [ 'Academics', 'Animal Ken', 'Athletics', 'Awareness', 'Brawl' ] );
		$backgrounds = array_map( [ $this, 'row' ], [ 'Allies', 'Contacts', 'Fame', 'Influence', 'Mentor' ] );

		return [
			'met-physical-traits' => $physical,
			'met-social-traits'   => $social,
			'met-mental-traits'   => $mental,
			'fera-abilities'      => $abilities,
			'fera-backgrounds'    => $backgrounds,
		];
	}

	/**
	 * A Homid Nuwisha with 3 basic Gifts (Homid's own, Nuwisha general, and one more Nuwisha general).
	 */
	private function nuwisha_sheet(): array {
		$sheet                 = $this->base_sheet();
		$sheet['fera-identity'] = [ 'Fera Type' => 'Nuwisha', 'Breed' => 'Homid' ];
		$sheet['fera-gifts']    = [
			[ 'name' => 'Nuwisha', 'power_name' => 'Bad Joke' ],
			[ 'name' => 'Nuwisha', 'power_name' => 'Dance of Dionysis' ],
			[ 'name' => 'Nuwisha', 'power_name' => 'Gift of the Porcupine' ],
		];
		return $sheet;
	}

	/**
	 * A Homid Bagheera Bastet with 3 basic Gifts drawn from breed and general.
	 */
	private function bagheera_sheet(): array {
		$sheet                 = $this->base_sheet();
		$sheet['fera-identity'] = [ 'Fera Type' => 'Bagheera', 'Breed' => 'Homid' ];
		$sheet['fera-gifts']    = [
			[ 'name' => 'Bastet: Bagheera', 'power_name' => "Humbaba's Escape" ],
			[ 'name' => 'Bastet', 'power_name' => 'Banish Sickness' ],
			[ 'name' => 'Bastet', 'power_name' => 'Call Spirits' ],
		];
		return $sheet;
	}

	/**
	 * A Homid Gurahl with 3 basic Gifts drawn from its own breed and the general list.
	 */
	private function gurahl_sheet(): array {
		$sheet                  = $this->base_sheet();
		$sheet['fera-identity'] = [ 'Fera Type' => 'Gurahl', 'Breed' => 'Homid' ];
		$sheet['fera-gifts']    = [
			[ 'name' => 'Gurahl: Homid', 'power_name' => 'Climate Control' ],
			[ 'name' => 'Gurahl', 'power_name' => 'Calm' ],
			[ 'name' => 'Gurahl', 'power_name' => 'Fiddlefish' ],
		];
		return $sheet;
	}

	public function test_a_gurahl_re_expressed_needs_no_further_xp(): void {
		$response = $this->send( 'POST', "/be/v1/{$this->slug}/creation-tally", [
			'stack_slug' => 'fera',
			'sheet_data' => $this->gurahl_sheet(),
		] );
		$tally = $response->get_data();

		$this->assertSame( 0, $tally['xp']['needed'], 'the same base budgets, with a breed and a general Gift both in type for Gurahl' );
	}

	public function test_a_homid_gurahl_starts_at_its_own_pools(): void {
		$response = $this->send( 'POST', "/be/v1/{$this->slug}/creation-tally", [
			'stack_slug' => 'fera',
			'sheet_data' => $this->gurahl_sheet(),
		] );
		$tally = $response->get_data();

		$gnosis = array_values( array_filter( $tally['steps'], static fn( $s ) => $s['kind'] === 'start' && $s['target'] === 'werewolf-resources.Gnosis' && $s['applies'] ) )[0];
		$this->assertSame( 4.0, $gnosis['value'], "a Homid Gurahl's own book value" );

		$rage = array_values( array_filter( $tally['steps'], static fn( $s ) => $s['kind'] === 'start' && $s['target'] === 'werewolf-resources.Rage' && $s['applies'] ) )[0];
		$this->assertSame( 3.0, $rage['value'], "a Homid Gurahl's own book value" );

		$willpower = array_values( array_filter( $tally['steps'], static fn( $s ) => $s['kind'] === 'start' && $s['target'] === 'werewolf-resources.Willpower' && $s['applies'] ) )[0];
		$this->assertSame( 6.0, $willpower['value'], "Gurahl's flat Adamant Will value, regardless of breed" );
	}

	public function test_a_nuwisha_re_expressed_needs_no_further_xp(): void {
		$response = $this->send( 'POST', "/be/v1/{$this->slug}/creation-tally", [
			'stack_slug' => 'fera',
			'sheet_data' => $this->nuwisha_sheet(),
		] );
		$tally = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 0, $tally['xp']['needed'], '7/5/3 attributes, 5 Abilities, 5 Backgrounds and 3 basic Gifts are fully covered' );
	}

	public function test_a_homid_nuwisha_starts_gnosis_at_one(): void {
		$response = $this->send( 'POST', "/be/v1/{$this->slug}/creation-tally", [
			'stack_slug' => 'fera',
			'sheet_data' => $this->nuwisha_sheet(),
		] );
		$tally = $response->get_data();

		$gnosis = array_values( array_filter( $tally['steps'], static fn( $s ) => $s['kind'] === 'start' && $s['target'] === 'werewolf-resources.Gnosis' && $s['applies'] ) )[0];
		$this->assertSame( 1.0, $gnosis['value'], "a Homid Nuwisha's own book value" );
	}

	public function test_a_bagheera_re_expressed_needs_no_further_xp(): void {
		$response = $this->send( 'POST', "/be/v1/{$this->slug}/creation-tally", [
			'stack_slug' => 'fera',
			'sheet_data' => $this->bagheera_sheet(),
		] );
		$tally = $response->get_data();

		$this->assertSame( 0, $tally['xp']['needed'], 'the same base budgets, with a breed and a general Gift both in type for Bastet' );
	}

	public function test_a_bagheera_starts_at_its_own_willpower_and_rage(): void {
		$response = $this->send( 'POST', "/be/v1/{$this->slug}/creation-tally", [
			'stack_slug' => 'fera',
			'sheet_data' => $this->bagheera_sheet(),
		] );
		$tally = $response->get_data();

		$willpower = array_values( array_filter( $tally['steps'], static fn( $s ) => $s['kind'] === 'start' && $s['target'] === 'werewolf-resources.Willpower' && $s['applies'] ) )[0];
		$this->assertSame( 3.0, $willpower['value'] );

		$rage = array_values( array_filter( $tally['steps'], static fn( $s ) => $s['kind'] === 'start' && $s['target'] === 'werewolf-resources.Rage' && $s['applies'] ) )[0];
		$this->assertSame( 1.0, $rage['value'] );

		$gnosis = array_values( array_filter( $tally['steps'], static fn( $s ) => $s['kind'] === 'start' && $s['target'] === 'werewolf-resources.Gnosis' && $s['applies'] ) )[0];
		$this->assertSame( 2.0, $gnosis['value'], "a Homid Bastet's own book value, different from a Homid Nuwisha's" );
	}

	public function test_a_fourth_gift_outside_species_carries_the_surcharge(): void {
		$sheet                  = $this->nuwisha_sheet();
		$sheet['fera-gifts'][]  = [ 'name' => 'Corax', 'power_name' => "Carrion's Call" ];

		$response = $this->send( 'POST', "/be/v1/{$this->slug}/creation-tally", [
			'stack_slug' => 'fera',
			'sheet_data' => $sheet,
		] );

		$this->assertSame( 4, $response->get_data()['xp']['needed'], "a Corax Gift on a Nuwisha character, uncovered and out of species, prices at 3 basic plus the 1 XP surcharge" );
	}
}
