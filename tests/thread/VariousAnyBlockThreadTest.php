<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Character;
use BeyondElysium\Models\Creature_Stack;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Game_Member;
use BeyondElysium\Services\Sheet_Document;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Various is the Storyteller-only creature type: offered to a Storyteller in every chronicle and to no one else, and
 * able to hold any block from any creature type.
 */
class VariousAnyBlockThreadTest extends WP_UnitTestCase {

	private string $slug = 'thread-various-any-block';
	private int $game_id;
	private int $manager;
	private int $player;
	private int $bystander;
	private int $character;

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );

		$this->game_id = (int) Game::create( [ 'slug' => $this->slug, 'name' => 'Thread Various Any Block' ] );
		Game::update( $this->slug, [ 'settings' => [ 'auto_approve' => true, 'enabled_stacks' => [ 'vampire' ] ] ] );

		$this->manager   = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$this->player    = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		$this->bystander = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		Game_Member::set_role( $this->game_id, $this->player, 'player' );
		Game_Member::set_role( $this->game_id, $this->bystander, 'player' );

		$this->character = Character::create( [
			'name'       => 'Various Holder',
			'stack_slug' => 'various',
			'owner_type' => 'chronicle',
			'owner_slug' => $this->slug,
			'wp_user_id' => $this->player,
			'sheet_data' => [
				'various-identity' => [ 'Class' => 'Construct' ],
				'vampire-merits'   => [ [ 'name' => 'Iron Will' ] ],
			],
		] );
		Character::update_xp( $this->character, 50, 50 );
	}

	private function dispatch( WP_REST_Request $request ) {
		return rest_get_server()->dispatch( $request );
	}

	private function json( string $method, string $route, array $body ): WP_REST_Request {
		$request = new WP_REST_Request( $method, $route );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( (string) wp_json_encode( $body ) );
		return $request;
	}

	private function change( int $as, array $change ) {
		wp_set_current_user( $as );
		return $this->dispatch( $this->json( 'POST', "/be/v1/{$this->slug}/characters/{$this->character}/changes", array_merge( [ 'category' => 'test' ], $change ) ) );
	}

	private function stack_slugs( bool $can_manage, bool $include_disabled = false ): array {
		return array_column( Creature_Stack::all_for_game( $this->slug, [], $include_disabled, $can_manage ), 'slug' );
	}

	public function test_a_player_is_never_offered_various(): void {
		$this->assertNotContains( 'various', $this->stack_slugs( false ) );
		$this->assertNotContains( 'various', $this->stack_slugs( false, true ), 'not even in the list of every creature type' );

		Game::update( $this->slug, [ 'settings' => [ 'enabled_stacks' => [ 'vampire', 'various' ] ] ] );
		$this->assertNotContains( 'various', $this->stack_slugs( false ), 'naming it in the chronicle\'s own list does not offer it to a player' );
	}

	public function test_a_storyteller_is_offered_various_whatever_the_chronicle_enabled(): void {
		$this->assertContains( 'various', $this->stack_slugs( true ), 'enabled_stacks lists only vampire' );
		$this->assertContains( 'various', $this->stack_slugs( true, true ) );

		$fresh = 'thread-various-never-chose';
		Game::create( [ 'slug' => $fresh, 'name' => 'Never Chose' ] );
		$this->assertContains( 'various', array_column( Creature_Stack::all_for_game( $fresh, [], false, true ), 'slug' ) );
		$this->assertNotContains( 'various', array_column( Creature_Stack::all_for_game( $fresh, [], false, false ), 'slug' ) );
	}

	public function test_the_creature_type_list_route_offers_various_to_a_storyteller_only(): void {
		wp_set_current_user( $this->player );
		$request = new WP_REST_Request( 'GET', '/be/v1/creature-stacks' );
		$request->set_param( 'game_slug', $this->slug );
		$this->assertNotContains( 'various', array_column( $this->dispatch( $request )->get_data(), 'slug' ) );

		wp_set_current_user( $this->manager );
		$request = new WP_REST_Request( 'GET', '/be/v1/creature-stacks' );
		$request->set_param( 'game_slug', $this->slug );
		$this->assertContains( 'various', array_column( $this->dispatch( $request )->get_data(), 'slug' ) );
	}

	public function test_a_player_cannot_create_a_various_character(): void {
		wp_set_current_user( $this->player );
		$response = $this->dispatch( $this->json( 'POST', "/be/v1/{$this->slug}/characters", [ 'name' => 'Sneaky', 'stack_slug' => 'various' ] ) );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'invalid_param', $response->as_error()->get_error_code() );
	}

	public function test_a_file_a_player_sends_naming_various_is_refused_and_a_storytellers_is_not(): void {
		$check = new \ReflectionMethod( \BeyondElysium\REST\Submissions_Controller::class, 'creation_check' );
		$check->setAccessible( true );
		$controller = new \BeyondElysium\REST\Submissions_Controller();
		$game       = Game::find_by_slug( $this->slug );

		wp_set_current_user( $this->player );
		$this->assertNotNull( $check->invoke( $controller, $game, [ 'race' => 'various' ] ) );

		wp_set_current_user( $this->manager );
		$this->assertNull( $check->invoke( $controller, $game, [ 'race' => 'various' ] ) );
	}

	public function test_a_storyteller_creates_a_various_character_holding_blocks_from_other_creature_types(): void {
		wp_set_current_user( $this->manager );
		$response = $this->dispatch( $this->json( 'POST', "/be/v1/{$this->slug}/characters", [
			'name'       => 'Everything Spirit',
			'stack_slug' => 'various',
			'is_npc'     => true,
			'sheet_data' => [
				'various-identity'    => [ 'Class' => 'Spirit' ],
				'vampire-merits'      => [ [ 'name' => 'Iron Will' ] ],
				'vampire-disciplines' => [ [ 'name' => 'Auspex', 'level' => 1, 'power_name' => 'Heightened Senses' ] ],
			],
		] ) );

		$this->assertSame( 201, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$data      = $response->get_data();
		$character = Character::find( (int) ( is_array( $data ) ? $data['id'] : $data->id ) );
		$this->assertSame( 'various', $character->stack_slug );
		$this->assertArrayHasKey( 'vampire-merits', $character->sheet_data );
		$this->assertArrayHasKey( 'vampire-disciplines', $character->sheet_data );
	}

	public function test_any_other_creature_type_still_refuses_a_block_it_does_not_list(): void {
		wp_set_current_user( $this->manager );
		$response = $this->dispatch( $this->json( 'POST', "/be/v1/{$this->slug}/characters", [
			'name'       => 'Vampire With Gifts',
			'stack_slug' => 'vampire',
			'sheet_data' => [ 'werewolf-gifts' => [ [ 'name' => 'Persuasion', 'level' => 1, 'power_name' => 'Persuasion' ] ] ],
		] ) );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'invalid_param', $response->as_error()->get_error_code() );
	}

	public function test_a_storyteller_can_add_to_a_block_the_character_does_not_hold_yet(): void {
		$response = $this->change( $this->manager, [
			'change_type' => 'add_trait',
			'change_data' => [ 'block_slug' => 'vampire-flaws', 'trait' => [ 'name' => 'Nightmares' ] ],
		] );

		$this->assertSame( 201, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$this->assertContains(
			'Nightmares',
			array_column( Character::find( $this->character )->sheet_data['vampire-flaws'] ?? [], 'name' ),
			'the entry reached the sheet'
		);
	}

	public function test_a_storyteller_can_add_an_entry_no_catalog_lists_to_a_block_outside_the_template(): void {
		$response = $this->change( $this->manager, [
			'change_type' => 'add_trait',
			'change_data' => [ 'block_slug' => 'vampire-flaws', 'trait' => [ 'name' => 'Clockwork Heart', 'custom' => true, 'chosen_cost' => 2 ] ],
		] );

		$this->assertSame( 201, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$change = $response->get_data();
		$this->assertSame( 'pending', $change->status, 'a custom entry waits in the queue for a Storyteller to approve it' );

		$approve = new WP_REST_Request( 'PUT', "/be/v1/{$this->slug}/changes/{$change->id}" );
		$approve->set_param( 'status', 'approved' );
		$this->assertSame( 200, $this->dispatch( $approve )->get_status() );
		$this->assertContains( 'Clockwork Heart', array_column( Character::find( $this->character )->sheet_data['vampire-flaws'], 'name' ) );
	}

	public function test_a_player_changes_only_the_blocks_the_character_holds(): void {
		$refused = $this->change( $this->player, [
			'change_type' => 'add_trait',
			'change_data' => [ 'block_slug' => 'vampire-flaws', 'trait' => [ 'name' => 'Nightmares' ] ],
		] );
		$this->assertSame( 400, $refused->get_status() );
		$this->assertSame( 'unknown_block', $refused->as_error()->get_error_code() );

		$allowed = $this->change( $this->player, [
			'change_type' => 'add_trait',
			'change_data' => [ 'block_slug' => 'vampire-merits', 'trait' => [ 'name' => 'Eidetic Memory' ] ],
		] );
		$this->assertSame( 201, $allowed->get_status(), wp_json_encode( $allowed->get_data() ) );
	}

	public function test_a_cost_preview_reads_a_block_the_character_does_not_hold_for_a_storyteller_only(): void {
		$body = [ 'changes' => [ [ 'change_type' => 'add_trait', 'change_data' => [ 'block_slug' => 'vampire-flaws', 'trait' => [ 'name' => 'Nightmares' ] ] ] ] ];

		wp_set_current_user( $this->manager );
		$results = $this->dispatch( $this->json( 'POST', "/be/v1/{$this->slug}/characters/{$this->character}/preview-changes", $body ) )->get_data()['results'];
		$this->assertArrayNotHasKey( 'invalid', $results[0] );

		wp_set_current_user( $this->player );
		$results = $this->dispatch( $this->json( 'POST', "/be/v1/{$this->slug}/characters/{$this->character}/preview-changes", $body ) )->get_data()['results'];
		$this->assertTrue( $results[0]['invalid'] ?? false );
	}

	public function test_the_sheet_lists_a_section_for_every_block_the_character_holds(): void {
		$documents = Sheet_Document::for_characters( [ $this->character ], $this->slug, [ 'can_manage' => true ] );
		$sections  = array_column( $documents[0]['sections'], 'block_slug' );

		$this->assertContains( 'vampire-merits', $sections, 'a block outside the Various template still prints' );
		$this->assertContains( [ 'Class', 'Construct' ], $documents[0]['header'], 'the template\'s own identity block still heads the sheet' );
	}

	public function test_resolving_with_a_character_adds_the_blocks_it_holds_for_a_storyteller_or_its_player(): void {
		$resolve = function ( int $as, bool $with_character ): array {
			wp_set_current_user( $as );
			$request = new WP_REST_Request( 'GET', '/be/v1/creature-stacks/various' );
			$request->set_param( 'resolve', true );
			$request->set_param( 'game_slug', $this->slug );
			if ( $with_character ) {
				$request->set_param( 'character_id', $this->character );
			}
			return array_keys( (array) $this->dispatch( $request )->get_data()['blocks'] );
		};

		$this->assertNotContains( 'vampire-merits', $resolve( $this->manager, false ) );
		$this->assertContains( 'vampire-merits', $resolve( $this->manager, true ) );
		$this->assertContains( 'vampire-merits', $resolve( $this->player, true ) );
		$this->assertNotContains( 'vampire-merits', $resolve( $this->bystander, true ), 'someone else\'s character shows nothing extra' );
	}

	public function test_another_creature_type_ignores_blocks_it_does_not_list(): void {
		$resolved = Creature_Stack::resolve( 'vampire', $this->slug, [ 'werewolf-gifts' ] );

		$this->assertArrayNotHasKey( 'werewolf-gifts', $resolved['blocks'] );
		$this->assertArrayHasKey( 'werewolf-gifts', Creature_Stack::resolve( 'various', $this->slug, [ 'werewolf-gifts' ] )['blocks'] );
	}
}
