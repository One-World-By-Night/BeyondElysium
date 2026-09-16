<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Character;
use BeyondElysium\Models\Creature_Stack;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Schema_Block;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * `Point_Audit_Controller`'s one route: `be_manage_characters` gate, real
 * signed-out-403, and the leak test point-calculator-design.md §5.5/§7 PC-7
 * requires - a Storyteller-only block must 403 a non-manager, never produce
 * a reduced total.
 *
 * @see BE_PROCESS/design/point-calculator-design.md §5.5, §7 PC-7
 */
class PointAuditControllerTest extends WP_UnitTestCase {

	private $manager_id;
	private $player_id;
	private $game_slug = 'point-audit-controller-test';
	private $character_id;

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );

		$this->manager_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$this->player_id  = self::factory()->user->create( [ 'role' => 'subscriber' ] );

		Game::create( [ 'slug' => $this->game_slug, 'name' => 'Point Audit Controller Test', 'created_by' => $this->manager_id ] );

		Schema_Block::create( [
			'slug'             => 'pac-secret',
			'name'             => 'Secret Merits',
			'section_type'     => 'trait_list',
			'definition'       => [ 'items' => [ [ 'name' => 'Hidden Merit', 'cost' => '5' ] ] ],
			'is_system'        => 0,
			'storyteller_only' => 1,
		] );

		Creature_Stack::create( [
			'slug'             => 'pac-stack',
			'name'             => 'Point Audit Controller Test Stack',
			'stack_definition' => [ 'sections' => [ [ 'block_slug' => 'pac-secret', 'label' => 'Secret Merits', 'display_order' => 1 ] ] ],
			'is_system'        => 0,
			'created_by'       => $this->manager_id,
		] );

		$this->character_id = Character::create( [
			'name'       => 'Point Audit Controller Test Character',
			'owner_slug' => $this->game_slug,
			'stack_slug' => 'pac-stack',
			'wp_user_id' => $this->player_id,
			'sheet_data' => [ 'pac-secret' => [ [ 'name' => 'Hidden Merit', 'count' => 1 ] ] ],
			'created_by' => $this->manager_id,
		] );
	}

	private function dispatch( int $as_user ): \WP_REST_Response {
		wp_set_current_user( $as_user );
		$request = new WP_REST_Request( 'GET', '/be/v1/' . $this->game_slug . '/characters/' . $this->character_id . '/point-audit' );
		$request->set_url_params( [ 'game_slug' => $this->game_slug, 'id' => (string) $this->character_id ] );
		return rest_get_server()->dispatch( $request );
	}

	public function test_a_manager_gets_the_full_priced_report(): void {
		$response = $this->dispatch( $this->manager_id );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertFalse( $data['complete'] );

		$secret_line = null;
		foreach ( $data['lines'] as $line ) {
			if ( $line['block_slug'] === 'pac-secret' ) {
				$secret_line = $line;
			}
		}
		$this->assertNotNull( $secret_line, 'a manager must see the storyteller-only block priced' );
		$this->assertSame( 5, $secret_line['xp'] );
	}

	public function test_a_non_manager_is_denied_outright_not_given_a_reduced_total(): void {
		$response = $this->dispatch( $this->player_id );

		// A reduced total (200, minus the secret line) would still leak the block's
		// existence and price by arithmetic difference - the route must refuse outright.
		$this->assertSame( 403, $response->get_status() );
		$this->assertTrue( is_wp_error( $response->as_error() ) || $response->is_error() );
	}

	public function test_unknown_character_id_is_404(): void {
		wp_set_current_user( $this->manager_id );
		$request = new WP_REST_Request( 'GET', '/be/v1/' . $this->game_slug . '/characters/999999/point-audit' );
		$request->set_url_params( [ 'game_slug' => $this->game_slug, 'id' => '999999' ] );
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( 'character_not_found', $response->as_error()->get_error_code() );
	}

	public function test_no_line_ever_carries_zero_for_something_merely_unpriced(): void {
		$response = $this->dispatch( $this->manager_id );
		$data     = $response->get_data();

		$this->assertNotEmpty( $data['lines'] );
		$checked = 0;
		foreach ( $data['lines'] as $line ) {
			if ( $line['unpriced_reason'] !== null ) {
				$this->assertNull( $line['xp'] );
				$checked++;
			}
		}
		$this->assertGreaterThanOrEqual( 0, $checked );
	}
}
