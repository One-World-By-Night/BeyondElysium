<?php

namespace BeyondElysium\Tests\Workflow;

use BeyondElysium\Models\Character;
use BeyondElysium\Models\Creature_Stack;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Game_Member;
use BeyondElysium\Models\Schema_Block;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * A player buys a merit, a Storyteller opens the approval queue, the player changes their mind
 * and resubmits a bigger purchase, and the Storyteller - still looking at the first version -
 * clicks Approve (1.0.0-review F-031). The stale approval is refused; after reloading, the
 * Storyteller approves what is actually there, once, and a second click changes nothing (F-015).
 */
class ChangeReviewWorkflowTest extends WP_UnitTestCase {

	private string $slug = 'change-review-workflow';

	private function dispatch( string $method, string $route, array $body = [] ) {
		$request = new WP_REST_Request( $method, $route );
		if ( $body ) {
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( wp_json_encode( $body ) );
		}
		return rest_get_server()->dispatch( $request );
	}

	public function test_a_storyteller_approves_what_the_player_actually_submitted_exactly_once(): void {
		do_action( 'rest_api_init' );

		// The chronicle: one priced merit list, one creature type, a Storyteller and a player.
		$game_id = Game::create( [ 'slug' => $this->slug, 'name' => 'Change Review Workflow', 'created_by' => 1 ] );
		Schema_Block::create( [
			'slug'         => 'crw-merits',
			'name'         => 'Merits',
			'section_type' => 'trait_list',
			'definition'   => [ 'items' => [ [ 'name' => 'Iron Will', 'cost' => '3' ] ] ],
			'is_system'    => 0,
		] );
		Creature_Stack::create( [
			'slug'             => 'crw-stack',
			'name'             => 'Workflow Creature',
			'stack_definition' => [ 'sections' => [ [ 'block_slug' => 'crw-merits' ] ] ],
			'is_system'        => 0,
			'created_by'       => 1,
		] );

		$storyteller = self::factory()->user->create( [ 'role' => 'editor' ] );
		$player      = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		Game_Member::set_role( (int) $game_id, $storyteller, 'hst' );
		Game_Member::set_role( (int) $game_id, $player, 'player' );

		$character = Character::create( [
			'name' => 'Workflow Hero', 'stack_slug' => 'crw-stack',
			'owner_type' => 'chronicle', 'owner_slug' => $this->slug, 'wp_user_id' => $player,
		] );
		Character::update_xp( $character, 20, 20 );

		// The player buys Iron Will x1 (3 XP); it waits for a Storyteller.
		wp_set_current_user( $player );
		$submitted = $this->dispatch( 'POST', "/be/v1/{$this->slug}/characters/{$character}/changes", [
			'change_type' => 'add_trait',
			'category'    => 'merit',
			'change_data' => [ 'block_slug' => 'crw-merits', 'trait' => [ 'name' => 'Iron Will', 'count' => 1 ] ],
		] );
		$this->assertSame( 201, $submitted->get_status() );
		$this->assertSame( 'pending', $submitted->get_data()->status );
		$change_id = (int) $submitted->get_data()->id;

		// The Storyteller opens the queue and sees Iron Will x1 for 3 XP.
		wp_set_current_user( $storyteller );
		$shown = $this->dispatch( 'GET', "/be/v1/{$this->slug}/changes" )->get_data();
		$this->assertCount( 1, $shown );
		$this->assertSame( 3.0, (float) $shown[0]->xp_cost );

		// The player resubmits Iron Will x2 (6 XP) into the same pending change.
		wp_set_current_user( $player );
		$resubmitted = $this->dispatch( 'POST', "/be/v1/{$this->slug}/characters/{$character}/changes", [
			'change_type' => 'add_trait',
			'category'    => 'merit',
			'change_data' => [ 'block_slug' => 'crw-merits', 'trait' => [ 'name' => 'Iron Will', 'count' => 2 ] ],
		] );
		$this->assertSame( $change_id, (int) $resubmitted->get_data()->id );

		// The Storyteller approves the version they were looking at - refused.
		wp_set_current_user( $storyteller );
		$stale = $this->dispatch( 'PUT', "/be/v1/{$this->slug}/changes/{$change_id}", [
			'status'       => 'approved',
			'review_token' => $shown[0]->review_token,
		] );
		$this->assertSame( 409, $stale->get_status() );
		$this->assertSame( 20, (int) Character::find( $character )->xp_unspent );

		// After reloading, they see x2 for 6 XP and approve it.
		$current = $this->dispatch( 'GET', "/be/v1/{$this->slug}/changes" )->get_data();
		$this->assertSame( 6.0, (float) $current[0]->xp_cost );
		$approved = $this->dispatch( 'PUT', "/be/v1/{$this->slug}/changes/{$change_id}", [
			'status'       => 'approved',
			'review_token' => $current[0]->review_token,
		] );
		$this->assertSame( 200, $approved->get_status() );

		// A second click - a double-click, or a second Storyteller - changes nothing.
		$again = $this->dispatch( 'PUT', "/be/v1/{$this->slug}/changes/{$change_id}", [
			'status'       => 'approved',
			'review_token' => $current[0]->review_token,
		] );
		$this->assertSame( 400, $again->get_status() );

		$final = Character::find( $character );
		$this->assertSame( 14, (int) $final->xp_unspent, 'The 6 XP came off exactly once.' );
		$this->assertCount( 1, $final->sheet_data['crw-merits'] );
		$this->assertSame( 2, (int) $final->sheet_data['crw-merits'][0]['count'] );
	}
}
