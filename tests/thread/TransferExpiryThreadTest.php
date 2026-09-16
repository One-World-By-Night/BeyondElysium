<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Core\Maintenance;
use BeyondElysium\Models\Attestation;
use BeyondElysium\Models\Character;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Transfer;
use BeyondElysium\Services\Character_Exporter;
use WP_UnitTestCase;

/**
 * 1.0.0-review F-014, F-006. Nothing ever expired: no code was issued with an expiry, the sweep
 * that revokes expired codes had no caller, and no transfer ever reached its `expired` state. An
 * offer nobody reviewed sat in the host's queue - holding its whole character document - forever,
 * and fifty of them closed the queue to every new offer.
 *
 * A transfer's code and an unreviewed offer now last 60 days, two monthly game cycles, and a daily
 * sweep closes whatever outlived that. A printed or exported sheet's code still never expires.
 */
class TransferExpiryThreadTest extends WP_UnitTestCase {

	private string $slug = 'thread-transfer-expiry';
	private int $character;

	public function setUp(): void {
		parent::setUp();
		Game::create( [ 'slug' => $this->slug, 'name' => 'Transfer Expiry' ] );
		$this->character = Character::create( [ 'name' => 'Slow Traveller', 'stack_slug' => 'vampire', 'owner_type' => 'chronicle', 'owner_slug' => $this->slug, 'status' => 'active' ] );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
	}

	private function row( string $direction, string $state, int $days_old, array $extra = [] ): int {
		$id = Transfer::create( array_merge( [
			'character_uuid' => wp_generate_uuid4(), 'direction' => $direction, 'state' => $state,
			'home_slug' => $this->slug, 'home_site' => home_url(), 'home_chronicle' => 'Transfer Expiry',
			'payload_hash' => str_repeat( 'b', 64 ), 'initiated_by' => get_current_user_id(), 'payload' => '<grapevine/>',
		], $extra ) );
		global $wpdb;
		$wpdb->update( $wpdb->prefix . 'be_character_transfers', [ 'initiated_at' => gmdate( 'Y-m-d H:i:s', time() - $days_old * DAY_IN_SECONDS ) ], [ 'id' => $id ] );
		return $id;
	}

	public function test_an_offer_nobody_reviewed_in_sixty_days_expires_and_its_document_is_dropped(): void {
		$stale = $this->row( 'inbound', 'offered', 61 );
		$fresh = $this->row( 'inbound', 'offered', 59 );

		Maintenance::run();

		$this->assertSame( 'expired', Transfer::find( $stale )->state );
		$this->assertNull( Transfer::find( $stale )->payload );
		$this->assertSame( 'offered', Transfer::find( $fresh )->state );
	}

	public function test_a_pending_transfer_expires_along_with_its_code(): void {
		$export = Character_Exporter::export( $this->character, [ 'as_transfer' => true ] );
		$stale  = $this->row( 'outbound', 'pending', 61, [ 'attestation_id' => $export['attestation_id'] ] );
		$abroad = $this->row( 'outbound', 'abroad', 400 );

		Maintenance::run();

		$this->assertSame( 'expired', Transfer::find( $stale )->state );
		$this->assertNotNull( Attestation::find( (int) $export['attestation_id'] )->revoked_at );
		$this->assertSame( 'abroad', Transfer::find( $abroad )->state, 'a character already accepted somewhere is travelling, not stale' );
	}

	public function test_a_transfer_code_lasts_sixty_days_and_a_printed_sheets_code_never_expires(): void {
		$transfer = Attestation::find( (int) Character_Exporter::export( $this->character, [ 'as_transfer' => true ] )['attestation_id'] );
		$printed  = Attestation::find( (int) Character_Exporter::export( $this->character, [ 'verify' => true ] )['attestation_id'] );

		$this->assertEqualsWithDelta( time() + 60 * DAY_IN_SECONDS, strtotime( $transfer->expires_at . ' UTC' ), 120 );
		$this->assertNull( $printed->expires_at );
	}

	public function test_the_daily_sweep_is_scheduled_and_removed_on_deactivation(): void {
		Maintenance::unschedule();
		$this->assertFalse( wp_next_scheduled( Maintenance::HOOK ) );

		do_action( 'init' );

		$this->assertNotFalse( wp_next_scheduled( Maintenance::HOOK ) );
		$this->assertSame( 'daily', wp_get_schedule( Maintenance::HOOK ) );
		$this->assertStringContainsString( 'Maintenance::unschedule()', (string) file_get_contents( BE_PLUGIN_DIR . 'includes/Core/Deactivator.php' ) );

		Maintenance::unschedule();
		$this->assertFalse( wp_next_scheduled( Maintenance::HOOK ) );
	}
}
