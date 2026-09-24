<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Game;
use BeyondElysium\Models\Game_Member;
use BeyondElysium\Models\Schema_Block;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * The Approval Rules form opens with its level "(unset)", and a value-range rule saved that way was created as
 * auto-approve.
 */
class ApprovalRuleDefaultsThreadTest extends WP_UnitTestCase {

	private string $slug = 'thread-approval-defaults';
	private int $hst;

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );

		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'be_games', [
			'slug' => $this->slug, 'name' => $this->slug, 'settings' => '{}',
			'created_by' => 1, 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ),
		] );
		$this->hst = self::factory()->user->create( [ 'role' => 'editor' ] );
		Game_Member::set_role( (int) Game::find_by_slug( $this->slug )->id, $this->hst, 'hst' );

		Schema_Block::create( [
			'slug' => 'tard-abilities', 'name' => 'Abilities', 'section_type' => 'trait_list', 'is_system' => 0,
			'definition' => [ 'items' => [ [ 'name' => 'Occult', 'cost' => '2' ] ] ],
		] );
		Schema_Block::create( [
			'slug' => 'tard-disciplines', 'name' => 'Disciplines', 'section_type' => 'tiered_power', 'is_system' => 0,
			'definition' => [ 'powers' => [ [ 'name' => 'Celerity', 'levels' => [ [ 'level' => 1, 'power_name' => 'Alacrity', 'cost' => '3' ] ] ] ] ],
		] );
	}

	private function create_rule( array $body ) {
		wp_set_current_user( $this->hst );
		$request = new WP_REST_Request( 'POST', "/be/v1/{$this->slug}/approval-rules" );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( $body ) );
		return rest_get_server()->dispatch( $request );
	}

	public function test_a_range_rule_saved_with_no_level_requires_a_storyteller(): void {
		$response = $this->create_rule( [
			'block_slug' => 'tard-abilities', 'target_type' => 'item_range', 'target_name' => 'Occult',
			'from' => 4, 'to' => 5, 'approval' => '', 'reason' => 'Chronicle house rule 12',
		] );

		$this->assertSame( 201, $response->get_status() );
		$this->assertSame( 'st', $response->get_data()['approval'] );
	}

	public function test_a_discipline_level_can_carry_its_own_approval_and_the_page_lists_it(): void {
		$created = $this->create_rule( [
			'block_slug' => 'tard-disciplines', 'target_type' => 'level', 'target_name' => 'Celerity',
			'level' => 1, 'approval' => 'auto',
		] );
		$this->assertSame( 201, $created->get_status() );
		$this->assertSame( 'auto', $created->get_data()['approval'] );

		$list  = rest_get_server()->dispatch( new WP_REST_Request( 'GET', "/be/v1/{$this->slug}/approval-rules" ) )->get_data();
		$level = array_values( array_filter( $list, static fn( $rule ) => $rule['target_type'] === 'level' && $rule['target_name'] === 'Celerity' ) );

		$this->assertCount( 1, $level );
		$this->assertSame( 'auto', $level[0]['approval'] );
	}

	public function test_a_rule_write_that_fails_leaves_no_fork_behind(): void {
		$missing = $this->create_rule( [
			'block_slug' => 'tard-abilities', 'target_type' => 'item', 'target_name' => 'Not In This Catalog', 'approval' => 'st',
		] );
		$stale_id = rtrim( strtr( base64_encode( (string) wp_json_encode( [ 'tard-abilities', 'item', 'Long Gone', null, null ] ) ), '+/', '-_' ), '=' );
		$stale    = rest_get_server()->dispatch( new WP_REST_Request( 'DELETE', "/be/v1/{$this->slug}/approval-rules/{$stale_id}" ) );

		$this->assertSame( 404, $missing->get_status() );
		$this->assertSame( 404, $stale->get_status() );
		global $wpdb;
		$forks = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->prefix}be_schema_blocks WHERE slug = %s AND game_slug = %s",
			'tard-abilities',
			$this->slug
		) );
		$this->assertSame( 0, $forks );
	}

	public function test_clearing_a_level_rule_removes_its_approval_too(): void {
		$created = $this->create_rule( [
			'block_slug' => 'tard-disciplines', 'target_type' => 'level', 'target_name' => 'Celerity',
			'level' => 1, 'approval' => 'st', 'reason' => 'Elder power',
		] );
		$id = $created->get_data()['id'];

		rest_get_server()->dispatch( new WP_REST_Request( 'DELETE', "/be/v1/{$this->slug}/approval-rules/{$id}" ) );

		$fork = Schema_Block::find_for_game( 'tard-disciplines', $this->slug );
		$rung = $fork->definition->powers[0]->levels[0];
		$this->assertObjectNotHasProperty( 'approval', $rung );
		$this->assertObjectNotHasProperty( 'reason', $rung );
	}
}
