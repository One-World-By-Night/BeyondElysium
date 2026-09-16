<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Game;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * 1.0.0-review F-066 (Pass H intake `t3-remaining-components`). Chronicle Setup saves one
 * sub-faction restriction at a time - Clan, then Sect - each built on the settings the page
 * loaded with, and the server replaced the whole `enabled_factions` map with whatever came in.
 * Saving Sect erased the Clan restriction saved a moment before, with nothing on screen to say
 * so. A write now changes only the stacks and fields it names.
 */
class FactionRestrictionMergeThreadTest extends WP_UnitTestCase {

	private string $slug = 'thread-faction-merge';

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );
		Game::create( [ 'slug' => $this->slug, 'name' => 'Faction Merge', 'settings' => [ 'apr' => [ 'public_rumors' => true ] ] ] );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
	}

	private function save( array $enabled_factions ): void {
		$request = new WP_REST_Request( 'PUT', "/be/v1/games/{$this->slug}" );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( [ 'settings' => [ 'enabled_factions' => $enabled_factions ] ] ) );
		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
	}

	private function stored(): array {
		return json_decode( wp_json_encode( Game::find_by_slug( $this->slug )->settings ), true );
	}

	public function test_saving_a_second_field_keeps_the_first(): void {
		$this->save( [ 'vampire' => [ 'Clan' => [ 'Brujah', 'Toreador' ] ] ] );
		$this->save( [ 'vampire' => [ 'Sect' => [ 'Camarilla' ] ] ] );

		$this->assertSame( [ 'Clan' => [ 'Brujah', 'Toreador' ], 'Sect' => [ 'Camarilla' ] ], $this->stored()['enabled_factions']['vampire'] );
	}

	public function test_saving_another_stack_keeps_the_first_stack(): void {
		$this->save( [ 'vampire' => [ 'Clan' => [ 'Brujah' ] ] ] );
		$this->save( [ 'werewolf' => [ 'Tribe' => [ 'Glass Walkers' ] ] ] );

		$stored = $this->stored()['enabled_factions'];
		$this->assertSame( [ 'Brujah' ], $stored['vampire']['Clan'] );
		$this->assertSame( [ 'Glass Walkers' ], $stored['werewolf']['Tribe'] );
	}

	public function test_saving_a_field_again_replaces_that_field_and_an_empty_list_lifts_it(): void {
		$this->save( [ 'vampire' => [ 'Clan' => [ 'Brujah' ], 'Sect' => [ 'Camarilla' ] ] ] );
		$this->save( [ 'vampire' => [ 'Clan' => [ 'Toreador', 'Ventrue' ] ] ] );
		$this->save( [ 'vampire' => [ 'Sect' => [] ] ] );

		$this->assertSame( [ 'Clan' => [ 'Toreador', 'Ventrue' ], 'Sect' => [] ], $this->stored()['enabled_factions']['vampire'] );
		$this->assertTrue( $this->stored()['apr']['public_rumors'], 'every other setting stays as it was' );
	}
}
