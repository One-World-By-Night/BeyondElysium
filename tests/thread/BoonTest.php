<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Character;
use BeyondElysium\Models\Connection;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * The two-connection invariant, self-boon rejection, and transactional creation
 * rolling back cleanly (workflow-0.7.md Step 3f). Lives in `tests/thread/`, not
 * `tests/unit/` as the workflow doc names it - boon creation touches `$wpdb` directly
 * (`Boons_Controller::create_item()`), which TESTING.md defines as the thread layer's
 * boundary, the same correction already made for `ConnectionTest.php` in 0.5.
 *
 * @see BE_PROCESS/workflow-0.7.md Step 3
 */
class BoonTest extends WP_UnitTestCase {

	private string $game_slug = 'thread-test-boon-game';
	private int $game_id;
	private int $debtor_id;
	private int $creditor_id;

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );

		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'be_games', [
			'slug' => $this->game_slug, 'name' => 'Thread Test Boon Game',
			'created_by' => 1, 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ),
		] );
		$this->game_id = (int) $wpdb->insert_id;

		$this->debtor_id   = Character::create( [ 'name' => 'Debtor', 'stack_slug' => 'vampire', 'owner_type' => 'chronicle', 'owner_slug' => $this->game_slug ] );
		$this->creditor_id = Character::create( [ 'name' => 'Creditor', 'stack_slug' => 'vampire', 'owner_type' => 'chronicle', 'owner_slug' => $this->game_slug ] );
	}

	private function dispatch( WP_REST_Request $request ) {
		return rest_get_server()->dispatch( $request );
	}

	public function test_creating_a_boon_makes_exactly_two_connections_with_the_right_labels(): void {
		$admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin );

		$request = new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/boons" );
		$request->set_param( 'owed_by_character_id', $this->debtor_id );
		$request->set_param( 'owed_to_character_id', $this->creditor_id );
		$request->set_param( 'boon_level', 'major' );
		$response = $this->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 201, $response->get_status() );

		$connections = Connection::for_source( 'world_object', $data['id'] );
		$this->assertCount( 2, $connections );

		$labels = array_column( $connections, 'label' );
		$this->assertEqualsCanonicalizing( [ 'owed_by', 'owed_to' ], $labels );
	}

	public function test_self_boon_is_rejected(): void {
		$admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin );

		$request = new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/boons" );
		$request->set_param( 'owed_by_character_id', $this->debtor_id );
		$request->set_param( 'owed_to_character_id', $this->debtor_id );
		$request->set_param( 'boon_level', 'minor' );
		$response = $this->dispatch( $request );

		$this->assertSame( 400, $response->get_status() );

		global $wpdb;
		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}be_world_objects WHERE object_type = 'boon'" );
		$this->assertSame( 0, $count, 'a rejected self-boon must not create a half-written object' );
	}

	public function test_creation_rolls_back_cleanly_when_the_creditor_does_not_exist(): void {
		$admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin );

		$request = new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/boons" );
		$request->set_param( 'owed_by_character_id', $this->debtor_id );
		$request->set_param( 'owed_to_character_id', 999999 );
		$request->set_param( 'boon_level', 'minor' );
		$response = $this->dispatch( $request );

		$this->assertSame( 400, $response->get_status() );

		global $wpdb;
		$objects     = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}be_world_objects WHERE object_type = 'boon'" );
		$connections = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}be_connections WHERE source_type = 'world_object'" );
		$this->assertSame( 0, $objects, 'no orphan world object row after a failed creation' );
		$this->assertSame( 0, $connections, 'no single-sided boon connection after a failed creation' );
	}

	public function test_creditor_deleted_mid_flow_rolls_back_with_no_orphans(): void {
		// Step 7's edge-case trace: the creditor is deleted between the client
		// loading the form and submitting.
		$admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin );

		Character::delete( $this->creditor_id );

		$request = new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/boons" );
		$request->set_param( 'owed_by_character_id', $this->debtor_id );
		$request->set_param( 'owed_to_character_id', $this->creditor_id );
		$request->set_param( 'boon_level', 'major' );
		$response = $this->dispatch( $request );

		$this->assertSame( 400, $response->get_status() );

		global $wpdb;
		$objects = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}be_world_objects WHERE object_type = 'boon'" );
		$this->assertSame( 0, $objects );
	}

	public function test_ledger_shows_both_parties_and_filters_by_either_side(): void {
		$admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin );

		$create = new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/boons" );
		$create->set_param( 'owed_by_character_id', $this->debtor_id );
		$create->set_param( 'owed_to_character_id', $this->creditor_id );
		$create->set_param( 'boon_level', 'major' );
		$this->dispatch( $create );

		foreach ( [ $this->debtor_id, $this->creditor_id ] as $character_id ) {
			$ledger_req = new WP_REST_Request( 'GET', "/be/v1/{$this->game_slug}/boons" );
			$ledger_req->set_param( 'character_id', $character_id );
			$ledger = $this->dispatch( $ledger_req )->get_data();
			$this->assertCount( 1, $ledger, "character {$character_id} must see the boon from either side" );
			$this->assertSame( 'Debtor', $ledger[0]['owed_by']['name'] );
			$this->assertSame( 'Creditor', $ledger[0]['owed_to']['name'] );
		}
	}

	public function test_repaying_a_boon_keeps_it_in_the_ledger_with_new_status(): void {
		$admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin );

		$create = new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/boons" );
		$create->set_param( 'owed_by_character_id', $this->debtor_id );
		$create->set_param( 'owed_to_character_id', $this->creditor_id );
		$create->set_param( 'boon_level', 'minor' );
		$boon = $this->dispatch( $create )->get_data();

		$repay_req = new WP_REST_Request( 'PUT', "/be/v1/{$this->game_slug}/boons/{$boon['id']}/repay" );
		$repaid    = $this->dispatch( $repay_req )->get_data();
		$this->assertSame( 'repaid', $repaid->properties['status'] );
		$this->assertNotEmpty( $repaid->properties['repaid_date'] );

		$ledger_req = new WP_REST_Request( 'GET', "/be/v1/{$this->game_slug}/boons" );
		$ledger     = $this->dispatch( $ledger_req )->get_data();
		$this->assertCount( 1, $ledger, 'a repaid boon stays in the ledger, it is not deleted' );
	}

	public function test_player_can_view_but_not_create_boons(): void {
		$player = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		// Step 1.5: the GET below now also needs chronicle membership, not just
		// be_view_characters site-wide. The POST stays 403 regardless - subscriber never
		// holds be_manage_world_objects site-wide, membership or not.
		\BeyondElysium\Models\Game_Member::set_role( $this->game_id, $player, 'player' );
		wp_set_current_user( $player );

		$create = new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/boons" );
		$create->set_param( 'owed_by_character_id', $this->debtor_id );
		$create->set_param( 'owed_to_character_id', $this->creditor_id );
		$create->set_param( 'boon_level', 'minor' );
		$this->assertSame( 403, $this->dispatch( $create )->get_status() );

		$ledger_req = new WP_REST_Request( 'GET', "/be/v1/{$this->game_slug}/boons" );
		$this->assertSame( 200, $this->dispatch( $ledger_req )->get_status() );
	}
}
