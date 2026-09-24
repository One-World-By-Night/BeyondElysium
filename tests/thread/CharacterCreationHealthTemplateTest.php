<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Character;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * A `trait_list` block's `default_held` template is applied automatically by `Characters_Controller::create_item()`
 * on a bare create.
 */
class CharacterCreationHealthTemplateTest extends WP_UnitTestCase {

	private string $game_slug = 'thread-test-health-template-game';

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );

		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'be_games', [
			'slug' => $this->game_slug, 'name' => 'Thread Test Health Template Game',
			'created_by' => 1, 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ),
		] );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
	}

	private function create_request( string $stack_slug, ?array $sheet_data = null ): WP_REST_Request {
		$request = new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/characters" );
		$request->set_param( 'name', 'Health Template Test' );
		$request->set_param( 'stack_slug', $stack_slug );
		if ( $sheet_data !== null ) {
			$request->set_param( 'sheet_data', $sheet_data );
		}
		return $request;
	}

	public function test_a_bare_create_gets_the_real_extended_health_template(): void {
		$data      = rest_get_server()->dispatch( $this->create_request( 'vampire' ) )->get_data();
		$character = Character::find( (int) $data->id );

		$health  = $character->sheet_data['vampire-health'] ?? null;
		$this->assertNotNull( $health, 'a bare create must apply the vampire-health default_held template' );

		$by_name = [];
		foreach ( $health as $item ) {
			$by_name[ $item['name'] ] = $item['count'];
		}
		$this->assertSame(
			[ 'Healthy' => 2, 'Bruised' => 3, 'Wounded' => 2, 'Incapacitated' => 1, 'Torpor' => 1 ],
			$by_name
		);
	}

	public function test_mummys_eleven_box_template_applies_too(): void {
		$data      = rest_get_server()->dispatch( $this->create_request( 'mummy' ) )->get_data();
		$character = Character::find( (int) $data->id );

		$health = $character->sheet_data['mummy-health'] ?? null;
		$this->assertNotNull( $health );
		$this->assertSame( 11, array_sum( array_column( $health, 'count' ) ) );
	}

	public function test_wraith_has_no_health_block_and_gets_no_template(): void {
		$data      = rest_get_server()->dispatch( $this->create_request( 'wraith' ) )->get_data();
		$character = Character::find( (int) $data->id );

		$this->assertArrayNotHasKey( 'wraith-health', $character->sheet_data );
	}

	public function test_a_hand_supplied_health_block_is_never_overwritten_by_the_template(): void {
		$data      = rest_get_server()->dispatch( $this->create_request( 'vampire', [
			'vampire-health' => [ [ 'name' => 'Healthy', 'count' => 1 ] ],
		] ) )->get_data();
		$character = Character::find( (int) $data->id );

		$this->assertSame(
			[ [ 'name' => 'Healthy', 'count' => 1 ] ],
			$character->sheet_data['vampire-health']
		);
	}

	public function test_health_boxes_are_never_priced_against_the_new_characters_xp(): void {
		$data      = rest_get_server()->dispatch( $this->create_request( 'vampire' ) )->get_data();
		$character = Character::find( (int) $data->id );

		// The starting sheet is written directly.
		$this->assertSame( 0, (int) $character->xp_earned );
		$this->assertSame( 0, (int) $character->xp_unspent );
	}
}
