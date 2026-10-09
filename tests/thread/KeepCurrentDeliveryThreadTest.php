<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Attestation;
use BeyondElysium\Models\Character;
use BeyondElysium\Models\Transfer;
use BeyondElysium\Services\Keep_Current;
use WP_UnitTestCase;

/**
 * The change hook and the scheduled delivery it drives for a kept-current visit.
 */
class KeepCurrentDeliveryThreadTest extends WP_UnitTestCase {

	private string $home_slug = 'thread-keep-current-home';
	private string $host_slug = 'thread-keep-current-host';
	private int $character_id;
	private object $character;

	public function setUp(): void {
		parent::setUp();

		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'be_games', [
			'slug' => $this->home_slug, 'name' => 'Keep Current Home',
			'created_by' => 1, 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ),
			'settings' => wp_json_encode( [] ),
		] );

		$this->character_id = Character::create( [
			'name' => 'Kept Current Vampire', 'stack_slug' => 'vampire',
			'owner_type' => 'chronicle', 'owner_slug' => $this->home_slug, 'status' => 'active',
		] );
		$this->character = Character::find( $this->character_id );
	}

	private function kept_current_visit(): int {
		return Transfer::create( [
			'character_uuid' => $this->character->uuid, 'character_id' => $this->character_id,
			'direction' => 'outbound', 'state' => 'visiting', 'home_slug' => $this->home_slug,
			'home_site' => home_url(), 'home_chronicle' => 'Keep Current Home',
			'host_slug' => $this->host_slug, 'host_site' => 'https://this-host-does-not-resolve.invalid',
			'host_chronicle' => 'Keep Current Host', 'payload_hash' => str_repeat( 'a', 64 ),
			'initiated_by' => 1, 'keep_current' => true,
		] );
	}

	private function scheduled_count_for( int $visit_id ): int {
		$count = 0;
		foreach ( _get_cron_array() ?: [] as $hooks ) {
			foreach ( $hooks[ Keep_Current::DELIVER_HOOK ] ?? [] as $event ) {
				if ( ( $event['args'][0] ?? null ) === $visit_id ) {
					$count++;
				}
			}
		}
		return $count;
	}

	public function test_two_changes_inside_five_minutes_make_one_delivery(): void {
		$visit_id = $this->kept_current_visit();
		Transfer::transition( $visit_id, 'visiting', [ 'keep_current_accepted' => 1 ] );

		Character::update_sheet_data( $this->character_id, [ 'vampire-disciplines' => [ [ 'name' => 'Celerity', 'level' => 1 ] ] ] );
		Character::update_sheet_data( $this->character_id, [ 'vampire-disciplines' => [ [ 'name' => 'Celerity', 'level' => 2 ] ] ] );

		$this->assertSame( 1, $this->scheduled_count_for( $visit_id ), 'a batch of changes still schedules exactly one delivery' );
		$this->assertSame( 2, (int) Transfer::find( $visit_id )->sequence, 'each change still raises the sequence' );
	}

	public function test_approving_three_changes_in_a_batch_makes_one(): void {
		$visit_id = $this->kept_current_visit();
		Transfer::transition( $visit_id, 'visiting', [ 'keep_current_accepted' => 1 ] );

		Character::update_xp( $this->character_id, 1, 1 );
		Character::update_xp( $this->character_id, 1, 1 );
		Character::update_xp( $this->character_id, 1, 1 );

		$this->assertSame( 1, $this->scheduled_count_for( $visit_id ) );
		$this->assertSame( 3, (int) Transfer::find( $visit_id )->sequence );
	}

	public function test_a_character_with_no_kept_current_visit_schedules_nothing(): void {
		Character::update_sheet_data( $this->character_id, [ 'vampire-disciplines' => [] ] );

		$found = false;
		foreach ( _get_cron_array() ?: [] as $hooks ) {
			if ( ! empty( $hooks[ Keep_Current::DELIVER_HOOK ] ) ) {
				$found = true;
			}
		}
		$this->assertFalse( $found );
	}

	public function test_a_failed_delivery_is_retried_by_the_sweep_with_the_newest_sheet(): void {
		$visit_id = $this->kept_current_visit();
		Transfer::transition( $visit_id, 'visiting', [ 'keep_current_accepted' => 1 ] );

		$captured = [];
		$callback = function ( $preempt, $args, $url ) use ( &$captured ) {
			if ( strpos( $url, '/from-home' ) === false ) {
				return $preempt;
			}
			$captured[] = json_decode( (string) ( $args['body'] ?? '{}' ), true );
			return new \WP_Error( 'http_request_failed', 'simulated failure' );
		};
		add_filter( 'pre_http_request', $callback, 10, 3 );

		Character::update_sheet_data( $this->character_id, [ 'vampire-disciplines' => [ [ 'name' => 'Celerity', 'level' => 1 ] ] ] );
		$this->assertFalse( Keep_Current::deliver( $visit_id ) );

		Character::update_sheet_data( $this->character_id, [ 'vampire-disciplines' => [ [ 'name' => 'Celerity', 'level' => 2 ] ] ] );
		Keep_Current::sweep();

		remove_filter( 'pre_http_request', $callback, 10 );

		$this->assertCount( 2, $captured );
		$this->assertSame( 1, $captured[0]['sequence'] );
		$this->assertSame( 2, $captured[1]['sequence'], 'the retry carries the sheet as it is now, not as it was' );
		$this->assertSame( 0, (int) Transfer::find( $visit_id )->delivered_sequence, 'still unconfirmed' );
	}

	public function test_a_later_delivery_revokes_the_earlier_code(): void {
		$visit_id = $this->kept_current_visit();
		Transfer::transition( $visit_id, 'visiting', [ 'keep_current_accepted' => 1 ] );

		add_filter( 'pre_http_request', '__return_null', 10, 3 );

		Character::update_sheet_data( $this->character_id, [ 'vampire-disciplines' => [] ] );
		Keep_Current::deliver( $visit_id );
		$first_code_id = (int) Transfer::find( $visit_id )->last_code_id;
		$this->assertGreaterThan( 0, $first_code_id );
		$this->assertNull( Attestation::find( $first_code_id )->revoked_at );

		Character::update_sheet_data( $this->character_id, [ 'vampire-disciplines' => [ [ 'name' => 'Celerity', 'level' => 1 ] ] ] );
		Keep_Current::deliver( $visit_id );

		remove_filter( 'pre_http_request', '__return_null', 10 );

		$this->assertNotNull( Attestation::find( $first_code_id )->revoked_at );
	}
}
