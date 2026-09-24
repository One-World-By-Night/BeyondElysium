<?php

namespace BeyondElysium\Tests\Workflow;

use BeyondElysium\Models\Character;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Game_Member;
use BeyondElysium\Models\World_Object;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Trace 7: a Storyteller copies Silver Dagger for a character (restricted, "Based on Silver Dagger").
 */
class ItemLifeWorkflowTest extends WP_UnitTestCase {

	private string $slug = 'item-life-workflow';

	private function dispatch( string $method, string $route, array $body = [] ) {
		$request = new WP_REST_Request( $method, $route );
		if ( $body ) {
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( wp_json_encode( $body ) );
		}
		return rest_get_server()->dispatch( $request );
	}

	private function print_verify_line(): ?string {
		$response = $this->dispatch( 'GET', "/be/v1/{$this->slug}/reports/item-cards" );
		$data     = (array) $response->get_data();

		foreach ( $data['cards'] as $card ) {
			foreach ( $card as [ $label, $value ] ) {
				if ( $label === 'Verify' ) {
					return $value;
				}
			}
		}
		return null;
	}

	private function code_from_verify_line( string $line ): string {
		preg_match( '/code=([A-Z0-9%-]+)/i', $line, $m );
		return rawurldecode( $m[1] ?? '' );
	}

	public function test_an_items_life_from_copy_to_theft_to_reprint(): void {
		do_action( 'rest_api_init' );

		$game_id = (int) Game::create( [ 'slug' => $this->slug, 'name' => 'Item Life Workflow' ] );
		$hst_id  = self::factory()->user->create( [ 'role' => 'editor' ] );
		Game_Member::set_role( $game_id, $hst_id, 'hst' );

		$player_id    = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		Game_Member::set_role( $game_id, $player_id, 'player' );
		$character_id = (int) Character::create( [
			'name' => 'The Holder', 'stack_slug' => 'vampire', 'owner_type' => 'chronicle',
			'owner_slug' => $this->slug, 'wp_user_id' => $player_id, 'created_by' => $hst_id,
		] );
		$thief_id = (int) Character::create( [
			'name' => 'The Thief', 'stack_slug' => 'vampire', 'owner_type' => 'chronicle',
			'owner_slug' => $this->slug, 'created_by' => $hst_id,
		] );

		// The chronicle's own catalog copy, three uses, never held by anyone directly.
		wp_set_current_user( $hst_id );
		$catalog_id = (int) World_Object::create( [
			'game_id' => $game_id, 'object_type' => 'item', 'name' => 'Silver Dagger',
			'properties' => [ 'uses_max' => 3, 'uses_left' => 3 ], 'created_by' => $hst_id,
		] );

		// A Storyteller copies Silver Dagger for a character (restricted, "Based on Silver Dagger").
		$copy = $this->dispatch( 'POST', "/be/v1/{$this->slug}/world-objects/{$catalog_id}/copy-for-character", [
			'character_id' => $character_id,
			'name'         => 'Based on Silver Dagger',
		] );
		$this->assertSame( 201, $copy->get_status() );
		$copy_data = $copy->get_data();
		$item_id   = (int) $copy_data->id;
		$this->assertSame( 'restricted', $copy_data->audience );
		$this->assertSame( 'Based on Silver Dagger', $copy_data->name );

		// The player marks two of three uses.
		wp_set_current_user( $player_id );
		$first_use = $this->dispatch( 'POST', "/be/v1/{$this->slug}/world-objects/{$item_id}/use", [ 'character_id' => $character_id ] );
		$this->assertSame( 200, $first_use->get_status() );
		$this->assertSame( 2, $first_use->get_data()->properties['uses_left'] );

		$second_use = $this->dispatch( 'POST', "/be/v1/{$this->slug}/world-objects/{$item_id}/use", [ 'character_id' => $character_id ] );
		$this->assertSame( 200, $second_use->get_status() );
		$this->assertSame( 1, $second_use->get_data()->properties['uses_left'] );

		// Printed while the original character still holds it - this is "the old card."
		wp_set_current_user( $hst_id );
		$old_code = $this->code_from_verify_line( $this->print_verify_line() );
		$this->assertNotSame( '', $old_code );

		// A Storyteller records it stolen by another character.
		$stolen = $this->dispatch( 'POST', "/be/v1/{$this->slug}/world-objects/{$item_id}/transfer", [
			'how' => 'stolen', 'to_character_id' => $thief_id,
		] );
		$this->assertSame( 200, $stolen->get_status() );

		// History reads copied, used, used, stolen.
		$events = $this->dispatch( 'GET', "/be/v1/{$this->slug}/world-objects/{$item_id}/events" )->get_data();
		$this->assertSame( [ 'copied', 'used', 'used', 'stolen' ], array_column( $events, 'event' ) );

		// The old printed card's code verifies with a holder mismatch.
		$old_verify = $this->dispatch( 'GET', "/be/v1/verify/{$old_code}" );
		$this->assertSame( 200, $old_verify->get_status() );
		$old_verify_data = $old_verify->get_data();
		$this->assertFalse( $old_verify_data['revoked'] );
		$this->assertFalse( $old_verify_data['still_matches']['holder'] );

		// A reprint for the new holder issues a new code, which verifies with no mismatch.
		$new_code = $this->code_from_verify_line( $this->print_verify_line() );
		$this->assertNotSame( $old_code, $new_code );

		$new_verify = $this->dispatch( 'GET', "/be/v1/verify/{$new_code}" );
		$this->assertSame( 200, $new_verify->get_status() );
		$new_verify_data = $new_verify->get_data();
		$this->assertFalse( $new_verify_data['revoked'] );
		$this->assertTrue( $new_verify_data['still_matches']['holder'] );
	}
}
