<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Game;
use BeyondElysium\Models\Game_Member;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * The Chronicle Setup route saves the three purchase-list switches.
 */
class ChroniclePurchaseScopeRouteThreadTest extends WP_UnitTestCase {

	private string $slug = 'thread-purchase-route';
	private int $hst;
	private int $player;

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

		$this->hst    = self::factory()->user->create( [ 'role' => 'editor' ] );
		$this->player = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		Game_Member::set_role( $game_id, $this->hst, 'hst' );
		Game_Member::set_role( $game_id, $this->player, 'player' );
	}

	public function tearDown(): void {
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	private function put( int $user, array $body ) {
		wp_set_current_user( $user );
		$request = new WP_REST_Request( 'PUT', "/be/v1/{$this->slug}/chronicle-setup" );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( $body ) );
		return rest_get_server()->dispatch( $request );
	}

	/** @return array<string,bool> */
	private function stored(): array {
		return (array) ( Game::find_by_slug( $this->slug )->settings->purchase_scope ?? [] );
	}

	public function test_an_hst_switches_an_area_on_and_the_rest_stay_off(): void {
		$response = $this->put( $this->hst, [ 'purchase_scope' => [ 'abilities' => true ] ] );

		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$this->assertSame( [ 'abilities' => true, 'backgrounds' => false, 'merits_flaws' => false ], $this->stored() );
	}

	public function test_a_write_carrying_one_area_leaves_the_others_as_they_were(): void {
		$this->put( $this->hst, [ 'purchase_scope' => [ 'abilities' => true ] ] );
		$this->put( $this->hst, [ 'purchase_scope' => [ 'merits_flaws' => true ] ] );

		$this->assertSame( [ 'abilities' => true, 'backgrounds' => false, 'merits_flaws' => true ], $this->stored() );

		$this->put( $this->hst, [ 'purchase_scope' => [ 'abilities' => false ] ] );

		$this->assertSame( [ 'abilities' => false, 'backgrounds' => false, 'merits_flaws' => true ], $this->stored() );
	}

	public function test_the_chronicles_other_settings_are_kept(): void {
		$this->put( $this->hst, [ 'purchase_scope' => [ 'backgrounds' => true ] ] );

		$this->assertTrue( Game::find_by_slug( $this->slug )->settings->auto_approve );
	}

	public function test_an_unknown_area_or_a_value_that_is_not_on_or_off_is_refused_and_nothing_is_stored(): void {
		foreach ( [ [ 'combat' => true ], [ 'abilities' => 'maybe' ], [ 'abilities' => [ 1 ] ], [] ] as $bad ) {
			$response = $this->put( $this->hst, [ 'purchase_scope' => $bad ] );

			$this->assertSame( 400, $response->get_status(), wp_json_encode( $bad ) );
			$this->assertSame( 'invalid_param', $response->as_error()->get_error_code() );
		}
		$this->assertSame( [], $this->stored() );
	}

	public function test_a_player_cannot_switch_anything(): void {
		$response = $this->put( $this->player, [ 'purchase_scope' => [ 'abilities' => true ] ] );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( [], $this->stored() );
	}
}
