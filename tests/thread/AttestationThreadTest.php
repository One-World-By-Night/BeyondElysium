<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Attestation;
use BeyondElysium\Models\Character;
use WP_UnitTestCase;

/**
 * GX-7: `Attestation::issue()`/`resolve()`/`revoke()`/`find()`/`sweep_expired()`
 * against a real character row and a real `be_character_attestations` table -
 * the token/short-code generation, the stored snapshot, the `check_count`/
 * `last_checked_at` bookkeeping on every `resolve()` call regardless of
 * outcome, and expiry sweeping.
 *
 * @see BE_PROCESS/gex-export-transfer-design.md GX-7, §6.2
 */
class AttestationThreadTest extends WP_UnitTestCase {

	private string $game_slug = 'thread-test-attestation-game';
	private int $character_id;
	private object $character;

	public function setUp(): void {
		parent::setUp();

		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'be_games', [
			'slug' => $this->game_slug, 'name' => 'Thread Test Attestation Game',
			'created_by' => 1, 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ),
			'settings' => wp_json_encode( [] ),
		] );

		$this->character_id = Character::create( [
			'name' => 'Attestation Test Vampire', 'stack_slug' => 'vampire',
			'owner_type' => 'chronicle', 'owner_slug' => $this->game_slug,
			'status' => 'active',
		] );
		$this->character = Character::find( $this->character_id );
	}

	public function test_issue_creates_a_row_with_a_real_token_and_short_code(): void {
		$row = Attestation::issue( $this->character, 'gex', hash( 'sha256', 'canonical-doc' ) );

		$this->assertNotEmpty( $row->id );
		$this->assertSame( 43, strlen( $row->token ) );
		$this->assertMatchesRegularExpression( '/^[A-Za-z0-9_-]{43}$/', $row->token, 'base64url, no padding' );
		$this->assertMatchesRegularExpression( '/^[23456789ABCDEFGHJKMNPQRSTUVWXYZ]{4}-[23456789ABCDEFGHJKMNPQRSTUVWXYZ]{4}$/', $row->short_code );
	}

	public function test_short_code_alphabet_never_contains_visually_ambiguous_characters(): void {
		for ( $i = 0; $i < 15; $i++ ) {
			$row = Attestation::issue( $this->character, 'gex', hash( 'sha256', "doc-{$i}" ) );
			$this->assertDoesNotMatchRegularExpression( '/[0O1IL]/', $row->short_code );
		}
	}

	public function test_issue_stores_a_snapshot_of_the_character_at_issue_time(): void {
		$row = Attestation::issue( $this->character, 'gex', 'deadbeef' );

		$this->assertSame( 'Attestation Test Vampire', $row->attested['name'] );
		$this->assertSame( 'vampire', $row->attested['stack'] );
		$this->assertSame( 'active', $row->attested['status'] );
		$this->assertSame( 'deadbeef', $row->attested['sheet_hash'] );
		$this->assertSame( (int) $this->character->xp_earned, $row->attested['xp_earned'] );
	}

	public function test_resolve_finds_by_short_code_regardless_of_case_or_surrounding_whitespace(): void {
		$row = Attestation::issue( $this->character, 'gex', 'abc123' );

		$found = Attestation::resolve( ' ' . strtolower( $row->short_code ) . ' ' );

		$this->assertNotNull( $found );
		$this->assertSame( $row->id, $found->id );
	}

	public function test_resolve_returns_null_for_an_unknown_code(): void {
		$this->assertNull( Attestation::resolve( 'ZZZZ-ZZZZ' ) );
	}

	public function test_resolve_increments_check_count_and_stamps_last_checked_at_on_every_lookup(): void {
		$row = Attestation::issue( $this->character, 'gex', 'abc123' );
		$this->assertSame( 0, (int) $row->check_count );

		Attestation::resolve( $row->short_code );
		$after_one = Attestation::find( (int) $row->id );
		$this->assertSame( 1, (int) $after_one->check_count );
		$this->assertNotNull( $after_one->last_checked_at );

		Attestation::resolve( $row->short_code );
		$after_two = Attestation::find( (int) $row->id );
		$this->assertSame( 2, (int) $after_two->check_count );
	}

	public function test_revoke_sets_revoked_at_and_is_idempotent(): void {
		$row = Attestation::issue( $this->character, 'gex', 'abc123' );
		$this->assertNull( $row->revoked_at );

		$this->assertTrue( Attestation::revoke( (int) $row->id ) );
		$revoked = Attestation::find( (int) $row->id );
		$this->assertNotNull( $revoked->revoked_at );

		// Revoking again does not error, and leaves the original timestamp's presence intact.
		Attestation::revoke( (int) $row->id );
		$this->assertNotNull( Attestation::find( (int) $row->id )->revoked_at );
	}

	public function test_find_returns_null_for_an_unknown_id(): void {
		$this->assertNull( Attestation::find( 999999999 ) );
	}

	public function test_sweep_expired_revokes_only_rows_past_their_own_expiry(): void {
		$expired = Attestation::issue( $this->character, 'gex', 'abc123', gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ) );
		$future  = Attestation::issue( $this->character, 'gex', 'def456', gmdate( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS ) );
		$never   = Attestation::issue( $this->character, 'gex', 'ghi789' );

		$swept = Attestation::sweep_expired();

		$this->assertSame( 1, $swept );
		$this->assertNotNull( Attestation::find( (int) $expired->id )->revoked_at );
		$this->assertNull( Attestation::find( (int) $future->id )->revoked_at );
		$this->assertNull( Attestation::find( (int) $never->id )->revoked_at );
	}
}
