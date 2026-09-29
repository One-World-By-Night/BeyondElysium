<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Change;
use BeyondElysium\Models\Character;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Game_Member;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Vampire's own creation rules, tallied through the real routes against the real seeded book: attributes, budgeted
 * Disciplines and Backgrounds, the Free Traits pool, starting XP, the build charge, and the Caitiff/Pander field.
 */
class CreationTallyThreadTest extends WP_UnitTestCase {

	private string $slug = 'thread-creation-tally';
	private int $game_id;
	private int $hst;
	private int $player;

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );

		$this->game_id = (int) Game::create( [ 'slug' => $this->slug, 'name' => 'Tallied by Night' ] );
		$this->hst     = self::factory()->user->create( [ 'role' => 'editor' ] );
		Game_Member::set_role( $this->game_id, $this->hst, 'hst' );
		$this->player = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		Game_Member::set_role( $this->game_id, $this->player, 'player' );
	}

	private function send( string $method, string $route, array $body = [], ?int $as = null ): \WP_REST_Response {
		wp_set_current_user( $as ?? $this->hst );
		$request = new WP_REST_Request( $method, $route );
		$request->set_header( 'Content-Type', 'application/json' );
		if ( $method === 'GET' ) {
			$request->set_query_params( $body );
		} else {
			$request->set_body( (string) wp_json_encode( $body ) );
		}
		return rest_get_server()->dispatch( $request );
	}

	/**
	 * A Brujah with 7/5/3 Attributes, 5 Abilities, Backgrounds and one in-clan and one out-of-clan basic Discipline.
	 *
	 * @return array<string,mixed>
	 */
	private function brujah_sheet(): array {
		$row      = static fn( string $name ): array => [ 'name' => $name ];
		$physical = array_map( $row, [ 'Aggressive', 'Agile', 'Athletic', 'Brawny', 'Brutal', 'Deadly', 'Dexterous' ] );
		$social   = array_map( $row, [ 'Alluring', 'Beguiling', 'Callous', 'Charismatic', 'Charming' ] );
		$mental   = array_map( $row, [ 'Alert', 'Analytical', 'Astute' ] );
		$abilities = array_map( $row, [ 'Academics', 'Alertness', 'Athletics', 'Brawl', 'Awareness' ] );
		$backgrounds = array_map( $row, [ 'Allies', 'Contacts', 'Fame', 'Mentor', 'Resources' ] );

		return [
			'vampire-identity'    => [ 'Clan' => 'Brujah' ],
			'met-physical-traits' => $physical,
			'met-social-traits'   => $social,
			'met-mental-traits'   => $mental,
			'vampire-abilities'   => $abilities,
			'vampire-backgrounds' => $backgrounds,
			'vampire-disciplines' => [ [ 'name' => 'Celerity', 'level' => 1 ], [ 'name' => 'Obfuscate', 'level' => 1 ] ],
		];
	}

	/**
	 * @return array<string,mixed>
	 */
	private function draft_tally( array $sheet ): array {
		$response = $this->send( 'POST', "/be/v1/{$this->slug}/creation-tally", [ 'stack_slug' => 'vampire', 'sheet_data' => $sheet ] );
		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		return $response->get_data();
	}

	/**
	 * @param array<string,mixed> $tally
	 * @return array<string,mixed>|null
	 */
	private function step( array $tally, string $label ): ?array {
		foreach ( $tally['steps'] as $step ) {
			if ( $step['label'] === $label ) {
				return $step;
			}
		}
		return null;
	}

	public function test_vampire_re_expressed_against_the_real_book(): void {
		$tally = $this->draft_tally( $this->brujah_sheet() );

		$attributes = $this->step( $tally, 'Attributes' );
		$this->assertNotNull( $attributes );
		$by_section = [];
		foreach ( $attributes['sections'] as $section ) {
			$by_section[ $section['section'] ] = $section;
		}
		$this->assertSame( 7, $by_section['met-physical-traits']['used'] );
		$this->assertSame( 7, $by_section['met-physical-traits']['allowed'] );
		$this->assertFalse( $by_section['met-physical-traits']['over'] );
		$this->assertSame( 5, $by_section['met-social-traits']['allowed'] );
		$this->assertSame( 3, $by_section['met-mental-traits']['allowed'] );

		$disciplines = $this->step( $tally, 'Disciplines' );
		$this->assertNotNull( $disciplines );
		$this->assertTrue( $disciplines['applies'], 'a Brujah is neither Caitiff nor Pander' );
		$this->assertSame( 1, $disciplines['used'], 'only Celerity, in-clan, passes the filter' );
		$this->assertSame( 3, $disciplines['allowed'] );

		$caitiff_step = $this->step( $tally, 'Disciplines (Caitiff/Pander)' );
		$this->assertNotNull( $caitiff_step );
		$this->assertFalse( $caitiff_step['applies'], 'a Brujah does not take the Caitiff/Pander step' );

		$free = $this->step( $tally, 'Free Traits' );
		$this->assertNotNull( $free );
		$this->assertSame( 3, $free['spent'], "Obfuscate's basic rung, uncovered by budget, paid at the book's flat rate" );
		$this->assertSame( 2, $tally['pools']['Free Traits']['left'] );

		$this->assertSame( 0, $tally['xp']['needed'], 'everything is covered by a budget or the free pool' );
	}

	public function test_an_uncovered_discipline_prices_with_its_out_of_type_surcharge(): void {
		$sheet = $this->brujah_sheet();
		// A second out-of-clan basic Discipline: the free pool can cover only one of the two at 3 each.
		$sheet['vampire-disciplines'][] = [ 'name' => 'Dementation', 'level' => 1 ];

		$tally = $this->draft_tally( $sheet );

		$this->assertSame( 2, $tally['pools']['Free Traits']['left'], 'one of the two uncovered rungs is paid, the other is not' );
		$this->assertSame( 4, $tally['xp']['needed'], "the base 3 plus the book's +1 out-of-type modifier" );
	}

	public function test_caitiff_can_budget_any_three_basic_disciplines(): void {
		$sheet                        = $this->brujah_sheet();
		$sheet['vampire-identity']    = [ 'Clan' => 'Caitiff' ];
		$sheet['vampire-disciplines'] = [ [ 'name' => 'Obfuscate', 'level' => 1 ], [ 'name' => 'Dementation', 'level' => 1 ] ];

		$tally = $this->draft_tally( $sheet );

		$this->assertFalse( $this->step( $tally, 'Disciplines' )['applies'] );
		$caitiff_step = $this->step( $tally, 'Disciplines (Caitiff/Pander)' );
		$this->assertTrue( $caitiff_step['applies'] );
		$this->assertSame( 2, $caitiff_step['used'], 'neither pick need be in-clan for a Caitiff' );
		$this->assertSame( 0, $tally['xp']['needed'], 'both rungs fit inside the Caitiff budget' );
	}

	public function test_creating_a_character_grants_starting_xp_and_charges_the_build(): void {
		$response = $this->send( 'PUT', "/be/v1/{$this->slug}/chronicle-setup", [ 'starting_xp' => 20 ] );
		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );

		$sheet                           = $this->brujah_sheet();
		$sheet['vampire-disciplines'][]  = [ 'name' => 'Dementation', 'level' => 1 ];
		$response                        = $this->send( 'POST', "/be/v1/{$this->slug}/characters", [
			'name' => 'Fresh Fledgling', 'stack_slug' => 'vampire', 'sheet_data' => $sheet,
		] );
		$this->assertSame( 201, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$character_id = (int) $response->get_data()->id;

		$character = Character::find( $character_id );
		$this->assertSame( 20, (int) $character->xp_earned, "the chronicle's starting experience" );
		$this->assertSame( 16, (int) $character->xp_unspent, '20 granted, 4 spent on the one uncovered rung' );

		$earned = Change::for_character( $character_id, [ 'change_type' => 'xp_earn' ] );
		$this->assertCount( 1, $earned );
		$this->assertSame( 'approved', $earned[0]->status );
		$this->assertSame( 20, (int) $earned[0]->change_data['amount'] );

		$spend = Change::for_character( $character_id, [ 'change_type' => 'creation_spend' ] );
		$this->assertCount( 1, $spend );
		$this->assertSame( 'approved', $spend[0]->status );
		$this->assertSame( 4, (int) $spend[0]->xp_cost );
	}

	public function test_existing_character_skips_starting_xp_and_the_build_charge(): void {
		$this->send( 'PUT', "/be/v1/{$this->slug}/chronicle-setup", [ 'starting_xp' => 20 ] );

		// A build that would need 4 XP if the starting grant and charge were not both skipped.
		$sheet                          = $this->brujah_sheet();
		$sheet['vampire-disciplines'][] = [ 'name' => 'Dementation', 'level' => 1 ];

		$response = $this->send( 'POST', "/be/v1/{$this->slug}/characters", [
			'name' => 'Already Sired', 'stack_slug' => 'vampire', 'sheet_data' => $sheet,
			'existing_character' => true,
		] );
		$this->assertSame( 201, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$character_id = (int) $response->get_data()->id;

		$character = Character::find( $character_id );
		$this->assertSame( 0, (int) $character->xp_earned );
		$this->assertSame( 0, (int) $character->xp_unspent );
		$this->assertCount( 0, Change::for_character( $character_id, [ 'change_type' => 'xp_earn' ] ) );
		$this->assertCount( 0, Change::for_character( $character_id, [ 'change_type' => 'creation_spend' ] ) );
	}

	public function test_a_player_cannot_mark_their_own_character_existing(): void {
		$this->send( 'PUT', "/be/v1/{$this->slug}/chronicle-setup", [ 'starting_xp' => 20 ] );

		$sheet                           = $this->brujah_sheet();
		$sheet['vampire-disciplines'][]  = [ 'name' => 'Dementation', 'level' => 1 ];
		$response = $this->send(
			'POST',
			"/be/v1/{$this->slug}/characters",
			[ 'name' => 'Hopeful Neonate', 'stack_slug' => 'vampire', 'sheet_data' => $sheet, 'existing_character' => true ],
			$this->player
		);
		$this->assertSame( 201, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$character_id = (int) $response->get_data()->id;

		$character = Character::find( $character_id );
		$this->assertSame( 20, (int) $character->xp_earned, 'existing_character is manager-only and is ignored from a player' );
		$this->assertSame( 16, (int) $character->xp_unspent );
	}

	public function test_no_starting_xp_change_is_recorded_when_the_chronicle_sets_none(): void {
		$response = $this->send( 'POST', "/be/v1/{$this->slug}/characters", [
			'name' => 'No Grant', 'stack_slug' => 'vampire', 'sheet_data' => $this->brujah_sheet(),
		] );
		$this->assertSame( 201, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$character_id = (int) $response->get_data()->id;

		$character = Character::find( $character_id );
		$this->assertSame( 0, (int) $character->xp_earned, 'this chronicle never set a starting experience' );
		$this->assertCount( 0, Change::for_character( $character_id, [ 'change_type' => 'xp_earn' ] ) );
	}

	public function test_a_storyteller_reads_a_pending_characters_own_tally(): void {
		$character_id = (int) Character::create( [
			'name' => 'Pending Neonate', 'stack_slug' => 'vampire', 'owner_type' => 'chronicle', 'owner_slug' => $this->slug,
			'status' => 'pending', 'sheet_data' => $this->brujah_sheet(),
		] );

		$response = $this->send( 'GET', "/be/v1/{$this->slug}/characters/{$character_id}/creation-tally" );
		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$tally = $response->get_data();

		$this->assertSame( 0, $tally['xp']['needed'], 'the same saved sheet the draft route would tally identically' );
		$this->assertSame( 1, $this->step( $tally, 'Disciplines' )['used'] );
	}

	public function test_the_setup_status_row_reports_starting_xp(): void {
		$response = $this->send( 'GET', "/be/v1/{$this->slug}/setup-status" );
		$this->assertSame( 200, $response->get_status() );
		$rows = $response->get_data()['items'];
		$row  = current( array_filter( $rows, static fn( $r ) => $r['id'] === 'starting_xp' ) );
		$this->assertNotFalse( $row, 'the checklist names a starting-experience row' );
		$this->assertSame( 'info', $row['status'], 'no starting XP set yet' );

		$this->send( 'PUT', "/be/v1/{$this->slug}/chronicle-setup", [ 'starting_xp' => 15 ] );

		$response = $this->send( 'GET', "/be/v1/{$this->slug}/setup-status" );
		$rows     = $response->get_data()['items'];
		$row      = current( array_filter( $rows, static fn( $r ) => $r['id'] === 'starting_xp' ) );
		$this->assertSame( 'ok', $row['status'] );
		$this->assertStringContainsString( '15', $row['detail'] );
	}

	public function test_the_in_clan_disciplines_field_resolves_its_options_from_the_disciplines_block(): void {
		$response = $this->send( 'GET', '/be/v1/creature-stacks/vampire', [ 'resolve' => 'true', 'game_slug' => $this->slug ] );
		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$identity = $response->get_data()['blocks']['vampire-identity'];

		$field = current( array_filter( $identity->definition->fields, static fn( $f ) => $f->name === 'In-clan Disciplines' ) );
		$this->assertNotFalse( $field );
		$this->assertContains( 'Celerity', $field->options );
		$this->assertContains( 'Obfuscate', $field->options );
	}
}
