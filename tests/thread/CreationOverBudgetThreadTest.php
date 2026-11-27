<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Change;
use BeyondElysium\Models\Character;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Game_Member;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * A new character's build is refused when it costs more than the chronicle's starting experience, for a player and for a
 * Storyteller alike, unless the Storyteller is entering a sheet that already exists.
 */
class CreationOverBudgetThreadTest extends WP_UnitTestCase {

	private string $slug = 'thread-over-budget';
	private int $game_id;
	private int $hst;
	private int $player;

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );

		$this->game_id = (int) Game::create( [ 'slug' => $this->slug, 'name' => 'Over Budget by Night' ] );
		$this->hst     = self::factory()->user->create( [ 'role' => 'editor' ] );
		Game_Member::set_role( $this->game_id, $this->hst, 'hst' );
		$this->player = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		Game_Member::set_role( $this->game_id, $this->player, 'player' );
	}

	private function send( string $method, string $route, array $body = [], ?int $as = null ): \WP_REST_Response {
		wp_set_current_user( $as ?? $this->hst );
		$request = new WP_REST_Request( $method, $route );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( (string) wp_json_encode( $body ) );
		return rest_get_server()->dispatch( $request );
	}

	private function starting_xp( int $amount ): void {
		$response = $this->send( 'PUT', "/be/v1/{$this->slug}/chronicle-setup", [ 'starting_xp' => $amount ] );
		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
	}

	/**
	 * A Brujah whose build costs exactly 4 XP: the second out-of-clan Discipline is the one uncovered rung.
	 *
	 * @return array<string,mixed>
	 */
	private function four_xp_sheet(): array {
		$row = static fn( string $name ): array => [ 'name' => $name ];
		return [
			'vampire-identity'    => [ 'Clan' => 'Brujah' ],
			'met-physical-traits' => array_map( $row, [ 'Aggressive', 'Agile', 'Athletic', 'Brawny', 'Brutal', 'Deadly', 'Dexterous' ] ),
			'met-social-traits'   => array_map( $row, [ 'Alluring', 'Beguiling', 'Callous', 'Charismatic', 'Charming' ] ),
			'met-mental-traits'   => array_map( $row, [ 'Alert', 'Analytical', 'Astute' ] ),
			'vampire-abilities'   => array_map( $row, [ 'Academics', 'Alertness', 'Athletics', 'Brawl', 'Awareness' ] ),
			'vampire-backgrounds' => array_map( $row, [ 'Allies', 'Contacts', 'Fame', 'Mentor', 'Resources' ] ),
			'vampire-disciplines' => [
				[ 'name' => 'Celerity', 'level' => 1 ],
				[ 'name' => 'Obfuscate', 'level' => 1 ],
				[ 'name' => 'Dementation', 'level' => 1 ],
			],
		];
	}

	private function character_count(): int {
		return Character::count_for_game( $this->slug );
	}

	private function create_as( ?int $as, array $extra = [] ): \WP_REST_Response {
		return $this->send( 'POST', "/be/v1/{$this->slug}/characters", $extra + [
			'name' => 'Costly Neonate', 'stack_slug' => 'vampire', 'sheet_data' => $this->four_xp_sheet(),
		], $as );
	}

	public function test_a_player_build_over_the_starting_xp_is_refused_with_both_numbers(): void {
		$this->starting_xp( 3 );

		$response = $this->create_as( $this->player );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'creation_over_budget', $response->get_data()['code'] );
		$this->assertSame( 'This build needs 4 XP and a new character here starts with 3.', $response->get_data()['message'] );
		$this->assertSame( 0, $this->character_count(), 'Nothing is created.' );
	}

	public function test_a_build_exactly_at_the_starting_xp_is_created_with_nothing_left(): void {
		$this->starting_xp( 4 );

		$response = $this->create_as( $this->player );

		$this->assertSame( 201, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$character = Character::find( (int) $response->get_data()->id );
		$this->assertSame( 4, (int) $character->xp_earned );
		$this->assertSame( 0, (int) $character->xp_unspent );
		$spend = Change::for_character( (int) $character->id, [ 'change_type' => 'creation_spend' ] );
		$this->assertSame( 4, (int) $spend[0]->xp_cost );
	}

	public function test_a_chronicle_that_starts_characters_with_no_xp_refuses_a_priced_build(): void {
		$response = $this->create_as( $this->player );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'creation_over_budget', $response->get_data()['code'] );
		$this->assertSame( 'This build needs 4 XP and a new character here starts with 0.', $response->get_data()['message'] );
	}

	public function test_a_storytellers_over_budget_build_is_refused_and_says_how_to_enter_an_existing_sheet(): void {
		$this->starting_xp( 3 );

		$response = $this->create_as( $this->hst );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'creation_over_budget', $response->get_data()['code'] );
		$this->assertStringContainsString( 'Tick Existing character for a sheet that already exists.', $response->get_data()['message'] );
		$this->assertSame( 0, $this->character_count() );
	}

	public function test_an_existing_character_is_created_and_charged_nothing(): void {
		$this->starting_xp( 3 );

		$response = $this->create_as( $this->hst, [ 'existing_character' => true ] );

		$this->assertSame( 201, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$character = Character::find( (int) $response->get_data()->id );
		$this->assertSame( 0, (int) $character->xp_earned );
		$this->assertSame( 0, (int) $character->xp_unspent );
		$this->assertCount( 0, Change::for_character( (int) $character->id, [ 'change_type' => 'creation_spend' ] ) );
	}

	public function test_no_created_character_was_charged_more_than_it_was_given(): void {
		foreach ( [ 4, 5, 20 ] as $starting ) {
			$this->starting_xp( $starting );
			$response = $this->create_as( $this->player );
			$this->assertSame( 201, $response->get_status(), wp_json_encode( $response->get_data() ) );
			$id        = (int) $response->get_data()->id;
			$character = Character::find( $id );
			$spend     = Change::for_character( $id, [ 'change_type' => 'creation_spend' ] );
			$this->assertGreaterThanOrEqual( 0, (int) $character->xp_unspent );
			$this->assertSame( $starting - 4, (int) $character->xp_unspent );
			$this->assertSame( 4, (int) $spend[0]->xp_cost, 'History records exactly what the balance paid.' );
		}
	}
}
