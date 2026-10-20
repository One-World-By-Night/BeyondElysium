<?php

namespace BeyondElysium\Tests\Workflow;

use BeyondElysium\Models\Change;
use BeyondElysium\Models\Character;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Game_Member;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * A player sends two homebrew Rituals in one save. The Storyteller opens the queue, finds the pair waiting together,
 * prices one at nothing and the other at four, files the second in the section it belongs in, leaves a note, and
 * approves the pair. The player's sheet, history and experience show it.
 */
class QueueEditWorkflowTest extends WP_UnitTestCase {

	private string $slug = 'queue-edit-workflow';

	private function dispatch( int $as, string $method, string $route, array $body = [] ) {
		wp_set_current_user( $as );
		$request = new WP_REST_Request( $method, $route );
		if ( $body ) {
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( wp_json_encode( $body ) );
		}
		$response = rest_get_server()->dispatch( $request );
		wp_set_current_user( 0 );
		return $response;
	}

	public function test_a_storyteller_prices_files_and_approves_a_pair_the_player_sent_together(): void {
		do_action( 'rest_api_init' );

		$game_id = (int) Game::create( [ 'slug' => $this->slug, 'name' => 'Queue Edit Workflow' ] );
		$st      = self::factory()->user->create( [ 'role' => 'editor', 'display_name' => 'Head Storyteller' ] );
		Game_Member::set_role( $game_id, $st, 'hst' );
		$player = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		Game_Member::set_role( $game_id, $player, 'player' );
		$character_id = (int) Character::create( [
			'name' => 'Amaru', 'stack_slug' => 'vampire', 'owner_type' => 'chronicle',
			'owner_slug' => $this->slug, 'wp_user_id' => $player, 'status' => 'active', 'created_by' => $st,
		] );
		Character::update_xp( $character_id, 30, 5 );

		// The player sends both rituals in one save.
		$saved = $this->dispatch( $player, 'POST', "/be/v1/{$this->slug}/characters/{$character_id}/changes/submit", [
			'changes' => [
				[
					'change_type' => 'add_trait', 'category' => 'vampire-rituals',
					'change_data' => [ 'block_slug' => 'vampire-rituals', 'trait' => [ 'name' => 'Circle Ward', 'note' => 'R1 from learning the Primary Path', 'count' => 1, 'custom' => true ] ],
				],
				[
					'change_type' => 'add_trait', 'category' => 'vampire-rituals',
					'change_data' => [ 'block_slug' => 'vampire-rituals', 'trait' => [ 'name' => 'Fettered Minion', 'note' => 'R1 Necromancy ( 4 xp)', 'count' => 1, 'custom' => true ] ],
				],
			],
		] );
		$this->assertSame( 201, $saved->get_status() );
		[ $ward, $minion ] = array_map( static fn( $c ) => (int) $c->id, $saved->get_data()['changes'] );

		// The queue shows both, with what the editor needs.
		$queue = $this->dispatch( $st, 'GET', "/be/v1/{$this->slug}/changes" )->get_data();
		$this->assertCount( 2, $queue );
		foreach ( $queue as $item ) {
			$this->assertSame( 'Rituals', $item->block_name );
			$this->assertTrue( $item->price_basis['homebrew'] );
			$this->assertSame( $saved->get_data()['submission_id'], $item->submission_id );
		}

		// Approve all cannot apply them yet.
		$tokens = [];
		foreach ( [ $ward, $minion ] as $id ) {
			$tokens[ $id ] = Change::review_token( Change::find( $id ) );
		}
		$early = $this->dispatch( $st, 'POST', "/be/v1/{$this->slug}/changes/batch-approve", [ 'change_ids' => [ $ward, $minion ], 'review_tokens' => $tokens ] )->get_data();
		$this->assertSame( [], $early['approved'] );
		$this->assertEqualsCanonicalizing( [ $ward, $minion ], $early['needs_cost'] );

		// The Storyteller prices one at nothing and the other at four, files the second as a Combination Discipline, and notes it.
		$this->assertSame( 200, $this->dispatch( $st, 'PATCH', "/be/v1/{$this->slug}/changes/{$ward}", [
			'review_token' => $tokens[ $ward ], 'price' => 0, 'price_per' => 'pick',
		] )->get_status() );
		$moved = $this->dispatch( $st, 'PATCH', "/be/v1/{$this->slug}/changes/{$minion}", [
			'review_token' => $tokens[ $minion ], 'fields' => [ 'block_slug' => 'vampire-combo-disciplines' ],
			'review_notes' => 'This is a combo, not a ritual.',
		] );
		$this->assertSame( 200, $moved->get_status(), wp_json_encode( $moved->get_data() ) );
		$this->assertSame( 'Combination Disciplines', $moved->get_data()->block_name );
		$priced = $this->dispatch( $st, 'PATCH', "/be/v1/{$this->slug}/changes/{$minion}", [
			'review_token' => Change::review_token( Change::find( $minion ) ), 'price' => 4, 'price_per' => 'pick',
		] );
		$this->assertSame( 200, $priced->get_status() );

		// Nothing has been decided by editing.
		$this->assertSame( 'pending', Change::find( $ward )->status );
		$this->assertSame( 'pending', Change::find( $minion )->status );
		$this->assertSame( 5, (int) Character::find( $character_id )->xp_unspent );

		// Approve all now applies the pair.
		$tokens = [];
		foreach ( [ $ward, $minion ] as $id ) {
			$tokens[ $id ] = Change::review_token( Change::find( $id ) );
		}
		$done = $this->dispatch( $st, 'POST', "/be/v1/{$this->slug}/changes/batch-approve", [ 'change_ids' => [ $ward, $minion ], 'review_tokens' => $tokens ] )->get_data();
		$this->assertEqualsCanonicalizing( [ $ward, $minion ], $done['approved'] );
		$this->assertSame( [], $done['held'] );

		// The player's sheet has each entry in the section it was filed in, at its price, and the experience is right.
		$sheet = Character::find( $character_id )->sheet_data;
		$this->assertSame( 'Circle Ward', $sheet['vampire-rituals'][0]['name'] );
		$this->assertSame( 0, $sheet['vampire-rituals'][0]['chosen_cost'] );
		$this->assertSame( 'Fettered Minion', $sheet['vampire-combo-disciplines'][0]['name'] );
		$this->assertSame( 4, $sheet['vampire-combo-disciplines'][0]['chosen_cost'] );
		$this->assertSame( 4, $sheet['vampire-combo-disciplines'][0]['count'], 'a combo\'s number is its price' );
		$this->assertCount( 1, $sheet['vampire-rituals'], 'the combo is not also a ritual' );
		$this->assertSame( 1, (int) Character::find( $character_id )->xp_unspent );

		// The player's history shows the note and that a Storyteller changed it.
		$history = $this->dispatch( $player, 'GET', "/be/v1/{$this->slug}/characters/{$character_id}/changes" )->get_data();
		$mine    = array_values( array_filter( $history, static fn( $c ) => (int) $c->id === $minion ) )[0];
		$this->assertSame( 'approved', $mine->status );
		$this->assertSame( 'This is a combo, not a ritual.', $mine->review_notes );
		$this->assertArrayHasKey( 'st_edit', $mine->change_data );
	}
}
