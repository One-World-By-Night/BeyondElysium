<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Game;
use BeyondElysium\Models\Game_Member;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Werewolf's own creation rules, tallied through the real routes against the real seeded book: attributes,
 * budgeted Abilities/Backgrounds/basic Gifts with one-each breed/auspice/tribe quotas, and Rage/Gnosis/Willpower/
 * Renown all set from the character's own identity.
 */
class WerewolfCreationTallyThreadTest extends WP_UnitTestCase {

	private string $slug = 'thread-werewolf-tally';
	private int $game_id;
	private int $hst;

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );

		$this->game_id = (int) Game::create( [ 'slug' => $this->slug, 'name' => 'Tallied Sept' ] );
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
	 * A Homid Ragabash of the Black Furies: 7/5/3 Attributes, 5 Abilities, 5 Backgrounds, one basic Gift from each
	 * of breed/auspice/tribe, and Beginning Renown split one each across Honor/Glory/Wisdom.
	 *
	 * @return array<string,mixed>
	 */
	private function cub_sheet(): array {
		$physical    = array_map( [ $this, 'row' ], [ 'Agile', 'Brawny', 'Dexterous', 'Enduring', 'Ferocious', 'Graceful', 'Lithe' ] );
		$social      = array_map( [ $this, 'row' ], [ 'Alluring', 'Charismatic', 'Charming', 'Dignified', 'Elegant' ] );
		$mental      = array_map( [ $this, 'row' ], [ 'Alert', 'Attentive', 'Clever' ] );
		$abilities   = array_map( [ $this, 'row' ], [ 'Academics', 'Animal Ken', 'Athletics', 'Awareness', 'Brawl' ] );
		$backgrounds = array_map( [ $this, 'row' ], [ 'Allies', 'Contacts', 'Fetish', 'Influence', 'Kinfolk' ] );

		return [
			'werewolf-identity'   => [ 'Breed' => 'Homid', 'Auspice' => 'Ragabash', 'Tribe' => 'Black Furies' ],
			'met-physical-traits' => $physical,
			'met-social-traits'   => $social,
			'met-mental-traits'   => $mental,
			'werewolf-abilities'  => $abilities,
			'werewolf-backgrounds' => $backgrounds,
			'werewolf-gifts'      => [
				[ 'name' => 'Homid', 'power_name' => 'Master of Fire' ],
				[ 'name' => 'Ragabash', 'power_name' => 'Alter Scent' ],
				[ 'name' => 'Black Furies', 'power_name' => 'Breath of the Wyld' ],
			],
			'werewolf-renown'     => [ 'Honor' => 1, 'Glory' => 1, 'Wisdom' => 1 ],
		];
	}

	public function test_a_werewolf_re_expressed_needs_no_further_xp(): void {
		$response = $this->send( 'POST', "/be/v1/{$this->slug}/creation-tally", [
			'stack_slug' => 'werewolf',
			'sheet_data' => $this->cub_sheet(),
		] );
		$tally = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 0, $tally['xp']['needed'], '7/5/3 attributes, 5 Abilities, 5 Backgrounds, one Gift per category and a 3-point Renown split are fully covered' );
	}

	public function test_all_three_gift_quotas_are_met(): void {
		$response = $this->send( 'POST', "/be/v1/{$this->slug}/creation-tally", [
			'stack_slug' => 'werewolf',
			'sheet_data' => $this->cub_sheet(),
		] );
		$tally = $response->get_data();

		$gifts = array_values( array_filter( $tally['steps'], static fn( $s ) => $s['kind'] === 'budget' && $s['section'] === 'werewolf-gifts' ) )[0];
		foreach ( $gifts['quotas'] as $quota ) {
			$this->assertTrue( $quota['ok'], $quota['label'] );
			$this->assertSame( 1, $quota['met'], $quota['label'] );
		}
	}

	public function test_two_tribe_gifts_leave_the_breed_quota_short(): void {
		$sheet                    = $this->cub_sheet();
		$sheet['werewolf-gifts']  = [
			[ 'name' => 'Ragabash', 'power_name' => 'Alter Scent' ],
			[ 'name' => 'Black Furies', 'power_name' => 'Breath of the Wyld' ],
			[ 'name' => 'Black Furies', 'power_name' => "Kali's Tongue" ],
		];

		$response = $this->send( 'POST', "/be/v1/{$this->slug}/creation-tally", [
			'stack_slug' => 'werewolf',
			'sheet_data' => $sheet,
		] );
		$tally = $response->get_data();

		$gifts = array_values( array_filter( $tally['steps'], static fn( $s ) => $s['kind'] === 'budget' && $s['section'] === 'werewolf-gifts' ) )[0];
		$breed = array_values( array_filter( $gifts['quotas'], static fn( $q ) => $q['label'] === 'One breed Gift' ) )[0];
		$this->assertFalse( $breed['ok'] );
		$this->assertSame( 0, $breed['met'] );
	}

	public function test_starting_pools_come_from_auspice_breed_and_tribe(): void {
		$response = $this->send( 'POST', "/be/v1/{$this->slug}/creation-tally", [
			'stack_slug' => 'werewolf',
			'sheet_data' => $this->cub_sheet(),
		] );
		$tally = $response->get_data();

		$rage = array_values( array_filter( $tally['steps'], static fn( $s ) => $s['kind'] === 'start' && $s['target'] === 'werewolf-resources.Rage' ) )[0];
		$this->assertSame( 1.0, $rage['value'], "a Ragabash begins with one Rage" );

		$gnosis = array_values( array_filter( $tally['steps'], static fn( $s ) => $s['kind'] === 'start' && $s['target'] === 'werewolf-resources.Gnosis' ) )[0];
		$this->assertSame( 1.0, $gnosis['value'], "a Homid begins with one Gnosis" );
	}

	public function test_a_bone_gnawer_starts_willpower_at_four(): void {
		$sheet                                = $this->cub_sheet();
		$sheet['werewolf-identity']['Tribe']  = 'Bone Gnawers';
		$sheet['werewolf-gifts'][2]           = [ 'name' => 'Bone Gnawers', 'power_name' => 'Cooking' ];

		$response = $this->send( 'POST', "/be/v1/{$this->slug}/creation-tally", [
			'stack_slug' => 'werewolf',
			'sheet_data' => $sheet,
		] );
		$tally = $response->get_data();

		$willpower = array_values( array_filter( $tally['steps'], static fn( $s ) => $s['kind'] === 'start' && $s['target'] === 'werewolf-resources.Willpower' ) )[0];
		$this->assertSame( 4.0, $willpower['value'], 'Bone Gnawers begin with four Willpower' );
	}

	public function test_a_fourth_out_of_type_gift_carries_the_surcharge(): void {
		$sheet                    = $this->cub_sheet();
		$sheet['werewolf-gifts'][] = [ 'name' => 'Uktena', 'power_name' => 'Sense Magic' ];

		$response = $this->send( 'POST', "/be/v1/{$this->slug}/creation-tally", [
			'stack_slug' => 'werewolf',
			'sheet_data' => $sheet,
		] );

		$this->assertSame( 4, $response->get_data()['xp']['needed'], 'a fourth, out-of-type Gift prices at 3 basic plus the 1 XP out-of-type surcharge' );
	}
}
