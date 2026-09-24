<?php

namespace BeyondElysium\Tests\Workflow;

use BeyondElysium\Models\Change;
use BeyondElysium\Models\Character;
use BeyondElysium\Models\Creature_Stack;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Game_Member;
use BeyondElysium\Models\Schema_Block;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * A chronicle auto-approves by default.
 */
class AutoApproveCatalogWorkflowTest extends WP_UnitTestCase {

	private string $slug = 'auto-approve-catalog-workflow';

	private function dispatch( string $method, string $route, array $body = [] ) {
		$request = new WP_REST_Request( $method, $route );
		if ( $body ) {
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( wp_json_encode( $body ) );
		}
		return rest_get_server()->dispatch( $request );
	}

	public function test_auto_approval_applies_catalog_purchases_but_never_an_unpriced_custom_entry(): void {
		do_action( 'rest_api_init' );

		$game_id = Game::create( [
			'slug' => $this->slug, 'name' => 'Auto-Approve Catalog Workflow', 'created_by' => 1,
			'settings' => [ 'auto_approve' => true ],
		] );
		Schema_Block::create( [
			'slug' => 'aacw-abilities', 'name' => 'Abilities', 'section_type' => 'trait_list', 'is_system' => 0,
			'definition' => [ 'items' => [ [ 'name' => 'Academics', 'cost' => '2' ] ] ],
		] );
		Creature_Stack::create( [
			'slug' => 'aacw-stack', 'name' => 'Workflow Creature', 'is_system' => 0, 'created_by' => 1,
			'stack_definition' => [ 'sections' => [ [ 'block_slug' => 'aacw-abilities' ] ] ],
		] );

		$storyteller = self::factory()->user->create( [ 'role' => 'editor' ] );
		$player      = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		Game_Member::set_role( (int) $game_id, $storyteller, 'hst' );
		Game_Member::set_role( (int) $game_id, $player, 'player' );

		$character = Character::create( [
			'name' => 'Scholar', 'stack_slug' => 'aacw-stack',
			'owner_type' => 'chronicle', 'owner_slug' => $this->slug, 'wp_user_id' => $player,
		] );
		Character::update_xp( $character, 30, 30 );

		// The player buys "academics" x3 - applied immediately, as Academics, for 6 XP.
		wp_set_current_user( $player );
		$purchase = $this->dispatch( 'POST', "/be/v1/{$this->slug}/characters/{$character}/changes", [
			'change_type' => 'add_trait',
			'category'    => 'aacw-abilities',
			'change_data' => [ 'block_slug' => 'aacw-abilities', 'trait' => [ 'name' => 'academics', 'count' => 3 ] ],
		] );
		$this->assertSame( 'approved', $purchase->get_data()->status );

		$after_purchase = Character::find( $character );
		$this->assertSame( 'Academics', $after_purchase->sheet_data['aacw-abilities'][0]['name'] );
		$this->assertSame( 24, (int) $after_purchase->xp_unspent );

		$made_up = $this->dispatch( 'POST', "/be/v1/{$this->slug}/characters/{$character}/changes", [
			'change_type' => 'add_trait',
			'category'    => 'aacw-abilities',
			'change_data' => [ 'block_slug' => 'aacw-abilities', 'trait' => [ 'name' => 'Free Knowledge', 'count' => 5 ] ],
		] );
		$this->assertSame( 400, $made_up->get_status() );

		// A Storyteller adds a homebrew ability. Auto-approval does not apply it.
		wp_set_current_user( $storyteller );
		$homebrew = $this->dispatch( 'POST', "/be/v1/{$this->slug}/characters/{$character}/changes", [
			'change_type' => 'add_trait',
			'category'    => 'aacw-abilities',
			'change_data' => [ 'block_slug' => 'aacw-abilities', 'trait' => [ 'name' => 'Chronicle Lore', 'count' => 1 ] ],
		] );
		$this->assertSame( 'pending', $homebrew->get_data()->status );
		$this->assertCount( 1, Character::find( $character )->sheet_data['aacw-abilities'] );

		// It shows in the queue.
		$queue = $this->dispatch( 'GET', "/be/v1/{$this->slug}/changes" )->get_data();
		$this->assertSame( 'Chronicle Lore', $queue[0]->change_data['trait']['name'] );

		$unpriced = $this->dispatch( 'PUT', "/be/v1/{$this->slug}/changes/{$queue[0]->id}", [
			'status'       => 'approved',
			'review_token' => $queue[0]->review_token,
		] );
		$this->assertSame( 400, $unpriced->get_status() );
		$this->assertSame( 'cost_required', $unpriced->as_error()->get_error_code() );
		$this->assertSame( 'pending', Change::find( (int) $queue[0]->id )->status );
		$this->assertSame( 24, (int) Character::find( $character )->xp_unspent );

		$this->dispatch( 'PUT', "/be/v1/{$this->slug}/changes/{$queue[0]->id}", [
			'status'       => 'approved',
			'review_token' => $queue[0]->review_token,
			'xp_cost'      => 2,
		] );

		$this->assertSame( 'approved', Change::find( (int) $queue[0]->id )->status );
		$this->assertCount( 2, Character::find( $character )->sheet_data['aacw-abilities'] );
		$this->assertSame( 22, (int) Character::find( $character )->xp_unspent );
	}
}
