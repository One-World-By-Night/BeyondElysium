<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Database\Schema;
use BeyondElysium\Models\Character;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Game_Member;
use BeyondElysium\Models\Schema_Block;
use BeyondElysium\Services\Change_Engine;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * 1.0.0-review F-043 (Decision 085). Owner ruling 2026-09-14: remove the `coordinator` approval
 * tier; existing coordinator rules become Storyteller rules.
 *
 * An approval rule could require a "coordinator", and the engine computed that level, but nothing
 * enforced it - every HST approved such a change - and a plain WordPress install has no
 * coordinator role at all. A rule that looked stricter than Storyteller review behaved exactly
 * like it.
 */
class CoordinatorTierRemovedThreadTest extends WP_UnitTestCase {

	private string $slug = 'thread-no-coordinator';

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );
		Game::create( [ 'slug' => $this->slug, 'name' => 'No Coordinator' ] );
	}

	private function block( string $slug, string $section_type, array $definition ): void {
		Schema_Block::create( [ 'slug' => $slug, 'name' => $slug, 'section_type' => $section_type, 'definition' => $definition, 'is_system' => 1 ] );
	}

	private function definition( string $slug ): array {
		return json_decode( wp_json_encode( Schema_Block::find_by_slug( $slug )->definition ), true );
	}

	public function test_a_rule_still_stored_as_coordinator_is_a_storytellers_decision(): void {
		$this->block( 'thread-legacy-rule', 'trait_list', [ 'items' => [ [ 'name' => 'Legacy Rule', 'approval' => 'coordinator' ] ] ] );
		$character = Character::find( Character::create( [
			'name' => 'Rule Tester', 'stack_slug' => 'vampire', 'owner_type' => 'chronicle', 'owner_slug' => $this->slug,
		] ) );

		$resolved = Change_Engine::resolve_approval_level( $character, (object) [
			'change_type' => 'add_trait',
			'change_data' => [ 'block_slug' => 'thread-legacy-rule', 'trait' => [ 'name' => 'Legacy Rule' ] ],
		] );

		$this->assertSame( 'st', $resolved['level'] );
	}

	public function test_approval_rules_offer_and_accept_only_auto_and_storyteller(): void {
		$hst = self::factory()->user->create( [ 'role' => 'editor' ] );
		Game_Member::set_role( (int) Game::find_by_slug( $this->slug )->id, $hst, 'hst' );
		wp_set_current_user( $hst );
		$this->block( 'thread-rule-target', 'trait_list', [ 'items' => [ [ 'name' => 'Occult' ] ] ] );

		$options = rest_get_server()->dispatch( new WP_REST_Request( 'GET', "/be/v1/{$this->slug}/approval-rules/options" ) );
		$this->assertSame( [ 'auto', 'st' ], $options->get_data()['approval_levels'] );

		$request = new WP_REST_Request( 'POST', "/be/v1/{$this->slug}/approval-rules" );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( [ 'block_slug' => 'thread-rule-target', 'target_type' => 'item', 'target_name' => 'Occult', 'approval' => 'coordinator' ] ) );
		$this->assertSame( 400, rest_get_server()->dispatch( $request )->get_status() );
	}

	public function test_the_upgrade_turns_every_stored_coordinator_rule_into_a_storyteller_rule(): void {
		$this->block( 'thread-mig-list', 'trait_list', [
			'items'          => [ [ 'name' => 'Occult', 'approval' => 'coordinator', 'approval_by_value' => [ [ 'from' => 4, 'to' => 5, 'approval' => 'coordinator' ] ] ] ],
			'approval_rules' => [ 'default' => 'coordinator', 'in_type' => 'auto', 'out_of_type' => 'coordinator' ],
		] );
		$this->block( 'thread-mig-powers', 'tiered_power', [
			'powers' => [ [ 'name' => 'Celerity', 'approval_override' => 'coordinator', 'levels' => [ [ 'level' => 1, 'power_name' => 'Alacrity', 'approval' => 'coordinator' ] ] ] ],
		] );
		$this->block( 'thread-mig-pools', 'resource_pool', [
			'pools' => [ [ 'name' => 'Willpower', 'value_type' => 'integer', 'default_start' => 1, 'approval_by_value' => [ [ 'from' => 8, 'to' => 10, 'approval' => 'coordinator' ] ] ] ],
		] );
		$this->block( 'thread-mig-fields', 'identity_field', [
			'fields' => [ [ 'name' => 'Generation', 'field_type' => 'select', 'required' => false, 'approval_by_option' => [ 'Antediluvian' => [ 'approval' => 'coordinator', 'reason' => 'Ask first' ] ] ] ],
		] );
		delete_option( 'be_coordinator_tier_removed' );

		Schema::remove_coordinator_approvals();

		$list = $this->definition( 'thread-mig-list' );
		$this->assertSame( 'st', $list['items'][0]['approval'] );
		$this->assertSame( 'st', $list['items'][0]['approval_by_value'][0]['approval'] );
		$this->assertSame( 'st', $list['approval_rules']['default'] );
		$this->assertSame( 'auto', $list['approval_rules']['in_type'] );
		$this->assertSame( 'st', $list['approval_rules']['out_of_type'] );

		$powers = $this->definition( 'thread-mig-powers' );
		$this->assertSame( 'st', $powers['powers'][0]['approval_override'] );
		$this->assertSame( 'st', $powers['powers'][0]['levels'][0]['approval'] );

		$this->assertSame( 'st', $this->definition( 'thread-mig-pools' )['pools'][0]['approval_by_value'][0]['approval'] );
		$field = $this->definition( 'thread-mig-fields' )['fields'][0]['approval_by_option']['Antediluvian'];
		$this->assertSame( 'st', $field['approval'] );
		$this->assertSame( 'Ask first', $field['reason'] );

		$this->assertNotEmpty( get_option( 'be_coordinator_tier_removed' ) );
	}
}
