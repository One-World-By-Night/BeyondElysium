<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Change;
use BeyondElysium\Models\Character;
use BeyondElysium\Models\Creature_Stack;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Game_Member;
use BeyondElysium\Models\Schema_Block;
use BeyondElysium\Services\Display\Power_Display;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * 1.1.0 D5: the owner's Blood Magic ruling - any power in a flagged set prompts for a
 * Tradition when taken, from the block's WHOLE traditions list, never narrowed to a
 * power's own catalog-listed teachers. Reported 2026-09-16: Hunter's Wind (which the
 * real catalog only lists as taught by Thaumaturgy (Camarilla)) couldn't be taken as
 * Dur An Ki, a real paradigm the block otherwise offers.
 *
 * @see BE_PROCESS/releases/1.1.0-design-workflow.md §3.20
 */
class BloodMagicParadigmThreadTest extends WP_UnitTestCase {

	private string $slug = 'thread-bm-paradigm';
	private int $player;
	private int $character;

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );

		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'be_games', [
			'slug' => $this->slug, 'name' => $this->slug,
			'settings' => wp_json_encode( [ 'auto_approve' => true ] ),
			'created_by' => 1, 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ),
		] );
		$game_id = (int) Game::find_by_slug( $this->slug )->id;

		// A real shape: Hunter's Wind's own catalog `traditions` map lists only its
		// narrow teaching tradition, but the block's own whole list is wider.
		Schema_Block::create( [
			'slug' => 'bmp-blood-magic', 'name' => 'Blood Magic', 'section_type' => 'tiered_power', 'is_system' => 0,
			'definition' => [
				'blood_magic' => true,
				'traditions'  => [ 'Dur An Ki', 'Thaumaturgy (Camarilla)', 'Wanga' ],
				'powers'      => [
					[
						'name'       => "Hunter's Wind",
						'traditions' => [ 'Thaumaturgy (Camarilla)' => null ],
						'levels'     => [
							[ 'level' => 1, 'power_name' => 'Catch the Scent', 'tier' => 'basic', 'cost' => '3' ],
						],
					],
				],
			],
		] );
		Creature_Stack::create( [
			'slug' => 'bmp-stack', 'name' => 'Blood Magic Test Creature', 'is_system' => 0, 'created_by' => 1,
			'stack_definition' => [ 'sections' => [ [ 'block_slug' => 'bmp-blood-magic' ] ] ],
		] );

		$this->player = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		Game_Member::set_role( $game_id, $this->player, 'player' );

		$this->character = Character::create( [
			'name' => 'Paradigm Tester', 'stack_slug' => 'bmp-stack',
			'owner_type' => 'chronicle', 'owner_slug' => $this->slug, 'wp_user_id' => $this->player,
		] );
		Character::update_xp( $this->character, 50, 50 );
	}

	private function submit( array $change ) {
		wp_set_current_user( $this->player );
		$request = new WP_REST_Request( 'POST', "/be/v1/{$this->slug}/characters/{$this->character}/changes" );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( array_merge( [ 'category' => 'test' ], $change ) ) );
		return rest_get_server()->dispatch( $request );
	}

	public function test_hunters_wind_is_accepted_and_displayed_as_dur_an_ki(): void {
		$response = $this->submit( [
			'change_type' => 'add_trait',
			'change_data' => [
				'block_slug' => 'bmp-blood-magic',
				'trait'      => [ 'name' => "Hunter's Wind", 'level' => 1, 'tradition' => 'Dur An Ki' ],
			],
		] );

		$this->assertSame( 201, $response->get_status(), wp_json_encode( $response->get_data() ) );

		$change = Change::find( (int) $response->get_data()->id );
		$this->assertSame( 'Dur An Ki', $change->change_data['trait']['tradition'] );

		$character = Character::find( $this->character );
		$held      = $character->sheet_data['bmp-blood-magic'][0];
		$this->assertSame( 'Dur An Ki', $held['tradition'] );
		$this->assertSame(
			"Dur An Ki: Hunter's Wind",
			Power_Display::with_tradition( $held, $held['name'] )
		);
	}

	public function test_a_paradigm_outside_the_blocks_list_is_refused(): void {
		$response = $this->submit( [
			'change_type' => 'add_trait',
			'change_data' => [
				'block_slug' => 'bmp-blood-magic',
				'trait'      => [ 'name' => "Hunter's Wind", 'level' => 1, 'tradition' => 'Not A Real Paradigm' ],
			],
		] );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'unknown_tradition', $response->as_error()->get_error_code() );
		$this->assertSame( 0, Change::count_for_character( $this->character ) );
	}

	public function test_a_paradigm_is_never_required_to_hold_a_power(): void {
		$response = $this->submit( [
			'change_type' => 'add_trait',
			'change_data' => [
				'block_slug' => 'bmp-blood-magic',
				'trait'      => [ 'name' => "Hunter's Wind", 'level' => 1 ],
			],
		] );

		$this->assertSame( 201, $response->get_status(), wp_json_encode( $response->get_data() ) );
	}
}
