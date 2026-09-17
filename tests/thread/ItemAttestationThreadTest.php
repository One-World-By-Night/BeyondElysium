<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Attestation;
use BeyondElysium\Models\Character;
use BeyondElysium\Models\Connection;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Game_Member;
use BeyondElysium\Models\Item_Attestation;
use BeyondElysium\Models\World_Object;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * 1.1.0 §3.13: a verification code for a printed Item Card - `Item_Attestation`, the item
 * sibling of `Attestation` (GX-7), sharing `Services\Short_Code` so the two tables' codes can
 * never collide, and `Verify_Controller` checking both.
 *
 * Delete revokes an item's codes outright (nothing left to verify against); a transfer
 * deliberately does NOT auto-revoke, since the design's own test list treats "a transfer
 * shows a holder mismatch" and "revoke" as two separate things - an old card stays live and
 * reports the mismatch through `still_matches`, rather than vanishing into a revoked state
 * with nothing left to report.
 *
 * @see BE_PROCESS/releases/1.1.0-design-workflow.md §3.13
 */
class ItemAttestationThreadTest extends WP_UnitTestCase {

	private string $slug = 'thread-item-attestation';
	private int $game_id;
	private int $storyteller_id;
	private int $character_id;
	private int $other_character_id;

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );

		$this->game_id = (int) Game::create( [ 'slug' => $this->slug, 'name' => 'Item Attestation' ] );

		$this->storyteller_id = self::factory()->user->create( [ 'role' => 'editor' ] );
		Game_Member::set_role( $this->game_id, $this->storyteller_id, 'hst' );

		$this->character_id = (int) Character::create( [
			'name' => 'Holder', 'stack_slug' => 'vampire', 'owner_type' => 'chronicle',
			'owner_slug' => $this->slug, 'created_by' => $this->storyteller_id,
		] );
		$this->other_character_id = (int) Character::create( [
			'name' => 'Someone Else', 'stack_slug' => 'vampire', 'owner_type' => 'chronicle',
			'owner_slug' => $this->slug, 'created_by' => $this->storyteller_id,
		] );
	}

	private function make_item( array $properties = [] ): int {
		return (int) World_Object::create( [
			'game_id' => $this->game_id, 'object_type' => 'item', 'name' => 'A Relic',
			'properties' => $properties, 'created_by' => $this->storyteller_id,
		] );
	}

	private function hold( int $item_id, int $character_id ): void {
		Connection::create( [
			'game_id' => $this->game_id, 'source_type' => 'character', 'source_id' => $character_id,
			'target_type' => 'world_object', 'target_id' => $item_id, 'label' => 'holds', 'created_by' => $this->storyteller_id,
		] );
	}

	/** Prints the item-cards report (the plain JSON route, not the PDF one) and returns its "Verify" line for the named item. */
	private function print_verify_line( int $item_id ): ?string {
		wp_set_current_user( $this->storyteller_id );
		$request  = new WP_REST_Request( 'GET', "/be/v1/{$this->slug}/reports/item-cards" );
		$response = rest_get_server()->dispatch( $request );
		$data     = (array) $response->get_data();

		foreach ( $data['cards'] as $card ) {
			foreach ( $card as [ $label, $value ] ) {
				if ( $label === 'Verify' ) {
					// The last card printed for this run belongs to whichever item is currently
					// in scope; every test in this file prints exactly one item at a time.
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

	private function verify( string $code ) {
		$request = new WP_REST_Request( 'GET', "/be/v1/verify/{$code}" );
		return rest_get_server()->dispatch( $request );
	}

	// -------------------------------------------------------------------------
	// Printing issues a code; reprinting reuses it when nothing changed.
	// -------------------------------------------------------------------------

	public function test_printing_an_item_card_issues_a_verification_code(): void {
		$item_id = $this->make_item();

		$line = $this->print_verify_line( $item_id );

		$this->assertNotNull( $line );
		$this->assertStringContainsString( 'be-verify', $line );
		$code = $this->code_from_verify_line( $line );
		$this->assertMatchesRegularExpression( '/^[23456789ABCDEFGHJKMNPQRSTUVWXYZ]{4}-[23456789ABCDEFGHJKMNPQRSTUVWXYZ]{4}$/', $code );
	}

	public function test_reprinting_reuses_the_same_code_when_nothing_changed(): void {
		$item_id = $this->make_item();

		$first  = $this->code_from_verify_line( $this->print_verify_line( $item_id ) );
		$second = $this->code_from_verify_line( $this->print_verify_line( $item_id ) );

		$this->assertSame( $first, $second );
	}

	public function test_reprinting_after_a_uses_change_issues_a_new_code(): void {
		$item_id = $this->make_item( [ 'uses_max' => 3, 'uses_left' => 3 ] );

		$first = $this->code_from_verify_line( $this->print_verify_line( $item_id ) );

		wp_set_current_user( $this->storyteller_id );
		$update = new WP_REST_Request( 'PUT', "/be/v1/{$this->slug}/world-objects/{$item_id}" );
		$update->set_body_params( [ 'properties' => [ 'uses_max' => 3, 'uses_left' => 1 ] ] );
		rest_get_server()->dispatch( $update );

		$second = $this->code_from_verify_line( $this->print_verify_line( $item_id ) );

		$this->assertNotSame( $first, $second );
	}

	public function test_reprinting_after_a_new_holder_issues_a_new_code(): void {
		$item_id = $this->make_item();
		$this->hold( $item_id, $this->character_id );

		$first = $this->code_from_verify_line( $this->print_verify_line( $item_id ) );

		wp_set_current_user( $this->storyteller_id );
		$transfer = new WP_REST_Request( 'POST', "/be/v1/{$this->slug}/world-objects/{$item_id}/transfer" );
		$transfer->set_body_params( [ 'to_character_id' => $this->other_character_id, 'how' => 'given' ] );
		rest_get_server()->dispatch( $transfer );

		$second = $this->code_from_verify_line( $this->print_verify_line( $item_id ) );

		$this->assertNotSame( $first, $second );
	}

	// -------------------------------------------------------------------------
	// Verifying a code.
	// -------------------------------------------------------------------------

	public function test_verifying_a_real_code_returns_the_attested_name(): void {
		$item_id = $this->make_item();
		$code    = $this->code_from_verify_line( $this->print_verify_line( $item_id ) );

		$response = $this->verify( $code );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 'item', $data['kind'] );
		$this->assertSame( 'A Relic', $data['name'] );
		$this->assertFalse( $data['revoked'] );
	}

	public function test_an_unknown_code_is_a_bare_404(): void {
		$response = $this->verify( 'ZZZZ-ZZZZ' );
		$this->assertSame( 404, $response->get_status() );
	}

	public function test_a_transfer_shows_a_holder_mismatch_on_the_old_card(): void {
		$item_id = $this->make_item();
		$this->hold( $item_id, $this->character_id );
		$code = $this->code_from_verify_line( $this->print_verify_line( $item_id ) );

		wp_set_current_user( $this->storyteller_id );
		$transfer = new WP_REST_Request( 'POST', "/be/v1/{$this->slug}/world-objects/{$item_id}/transfer" );
		$transfer->set_body_params( [ 'to_character_id' => $this->other_character_id, 'how' => 'traded' ] );
		rest_get_server()->dispatch( $transfer );

		// The OLD code, from before the transfer, is still live - not revoked - but its own
		// attested holder no longer matches who holds the item now.
		$response = $this->verify( $code );
		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertFalse( $data['revoked'] );
		$this->assertFalse( $data['still_matches']['holder'] );
	}

	public function test_expired_and_used_up_report_the_items_live_state(): void {
		$item_id = $this->make_item( [ 'uses_max' => 3, 'uses_left' => 0, 'expires_on' => '2000-01-01' ] );
		$code    = $this->code_from_verify_line( $this->print_verify_line( $item_id ) );

		$response = $this->verify( $code );
		$data     = $response->get_data();

		$this->assertTrue( $data['current']['used_up'] );
		$this->assertTrue( $data['current']['expired'] );
	}

	// -------------------------------------------------------------------------
	// Revoking.
	// -------------------------------------------------------------------------

	public function test_revoke_cards_makes_the_code_report_revoked(): void {
		$item_id = $this->make_item();
		$code    = $this->code_from_verify_line( $this->print_verify_line( $item_id ) );

		wp_set_current_user( $this->storyteller_id );
		$revoke = new WP_REST_Request( 'POST', "/be/v1/{$this->slug}/world-objects/{$item_id}/revoke-cards" );
		$revoked = rest_get_server()->dispatch( $revoke )->get_data();
		$this->assertSame( 1, $revoked['revoked'] );

		$response = $this->verify( $code );
		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertTrue( $data['revoked'] );
		$this->assertArrayNotHasKey( 'still_matches', $data );
	}

	public function test_deleting_an_item_revokes_its_own_codes(): void {
		$item_id = $this->make_item();
		$code    = $this->code_from_verify_line( $this->print_verify_line( $item_id ) );

		wp_set_current_user( $this->storyteller_id );
		$delete = new WP_REST_Request( 'DELETE', "/be/v1/{$this->slug}/world-objects/{$item_id}" );
		rest_get_server()->dispatch( $delete );

		$response = $this->verify( $code );
		$this->assertTrue( $response->get_data()['revoked'] );
	}

	public function test_a_player_may_not_revoke_cards(): void {
		$item_id = $this->make_item();
		$player  = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		Game_Member::set_role( $this->game_id, $player, 'player' );

		wp_set_current_user( $player );
		$request  = new WP_REST_Request( 'POST', "/be/v1/{$this->slug}/world-objects/{$item_id}/revoke-cards" );
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 403, $response->get_status() );
	}

	// -------------------------------------------------------------------------
	// Uniqueness across both attestation tables.
	// -------------------------------------------------------------------------

	public function test_an_item_code_never_resolves_as_a_character_code_or_vice_versa(): void {
		$item_id      = $this->make_item();
		$item_code    = $this->code_from_verify_line( $this->print_verify_line( $item_id ) );

		$character       = Character::find( $this->character_id );
		$character_attestation = Attestation::issue( $character, 'gex', hash( 'sha256', 'doc' ) );

		$this->assertNull( Attestation::resolve( $item_code ) );
		$this->assertNull( Item_Attestation::resolve( $character_attestation->short_code ) );
	}
}
