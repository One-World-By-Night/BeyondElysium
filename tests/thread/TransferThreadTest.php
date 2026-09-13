<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Transfer;
use WP_UnitTestCase;

/**
 * GX-8/9: `Transfer`'s own model-level contract - the one-open-row-per-
 * (uuid, direction) rule enforced in `create()` rather than a fighting
 * unique index, `find_open()`, the timestamp stamping `transition()` does
 * for specific destination states, and `open_states_for_game()` merging
 * both directions into one badge lookup without an N+1 (§7.3).
 *
 * @see BE_PROCESS/gex-export-transfer-design.md GX-8, GX-9, §7.2, §7.3, §8.1
 */
class TransferThreadTest extends WP_UnitTestCase {

	private function base_row( array $overrides = [] ): array {
		return array_merge( [
			'character_uuid' => wp_generate_uuid4(),
			'direction'      => 'outbound',
			'state'          => 'pending',
			'home_slug'      => 'thread-test-transfer-home',
			'home_site'      => 'https://home.example',
			'home_chronicle' => 'Thread Test Home',
			'payload_hash'   => hash( 'sha256', 'x' ),
			'initiated_by'   => 1,
		], $overrides );
	}

	public function test_create_inserts_a_row_and_find_returns_it(): void {
		$id  = Transfer::create( $this->base_row() );
		$row = Transfer::find( $id );

		$this->assertNotNull( $row );
		$this->assertSame( 'pending', $row->state );
		$this->assertSame( 'outbound', $row->direction );
	}

	public function test_create_refuses_a_second_open_row_for_the_same_uuid_and_direction(): void {
		$uuid = wp_generate_uuid4();
		Transfer::create( $this->base_row( [ 'character_uuid' => $uuid ] ) );

		$this->expectException( \RuntimeException::class );
		Transfer::create( $this->base_row( [ 'character_uuid' => $uuid ] ) );
	}

	public function test_create_allows_a_new_row_once_the_prior_one_is_terminal(): void {
		$uuid = wp_generate_uuid4();
		$id   = Transfer::create( $this->base_row( [ 'character_uuid' => $uuid ] ) );
		Transfer::transition( $id, 'declined' );

		// No exception - the prior row is terminal, so this uuid+direction is open again.
		$second = Transfer::create( $this->base_row( [ 'character_uuid' => $uuid ] ) );
		$this->assertNotSame( $id, $second );
	}

	public function test_find_open_returns_null_when_nothing_is_open(): void {
		$uuid = wp_generate_uuid4();
		$this->assertNull( Transfer::find_open( $uuid, 'outbound' ) );

		$id = Transfer::create( $this->base_row( [ 'character_uuid' => $uuid ] ) );
		Transfer::transition( $id, 'released' );
		$this->assertNull( Transfer::find_open( $uuid, 'outbound' ), 'a terminal row is not open' );
	}

	public function test_find_open_is_scoped_by_direction_independently(): void {
		$uuid = wp_generate_uuid4();
		Transfer::create( $this->base_row( [ 'character_uuid' => $uuid, 'direction' => 'outbound' ] ) );
		Transfer::create( $this->base_row( [
			'character_uuid' => $uuid, 'direction' => 'inbound', 'state' => 'offered',
			'host_slug' => 'thread-test-transfer-host', 'host_site' => 'https://host.example', 'host_chronicle' => 'Thread Test Host',
		] ) );

		$this->assertNotNull( Transfer::find_open( $uuid, 'outbound' ) );
		$this->assertNotNull( Transfer::find_open( $uuid, 'inbound' ) );
	}

	public function test_transition_to_abroad_stamps_acknowledged_at(): void {
		$id = Transfer::create( $this->base_row() );
		$this->assertNull( Transfer::find( $id )->acknowledged_at );

		Transfer::transition( $id, 'abroad' );
		$this->assertNotNull( Transfer::find( $id )->acknowledged_at );
		$this->assertSame( 'abroad', Transfer::find( $id )->state );
	}

	public function test_transition_to_returned_stamps_returned_at(): void {
		$id = Transfer::create( $this->base_row( [ 'state' => 'abroad' ] ) );
		Transfer::transition( $id, 'returned' );

		$row = Transfer::find( $id );
		$this->assertSame( 'returned', $row->state );
		$this->assertNotNull( $row->returned_at );
	}

	public function test_transition_merges_extra_columns(): void {
		$id = Transfer::create( $this->base_row() );
		Transfer::transition( $id, 'abroad', [ 'host_chronicle' => 'Confirmed Host' ] );

		$this->assertSame( 'Confirmed Host', Transfer::find( $id )->host_chronicle );
	}

	public function test_open_states_for_game_merges_both_directions_without_an_n_plus_one(): void {
		$home_slug = 'thread-test-transfer-badge-home';
		$host_slug = 'thread-test-transfer-badge-host';

		$outbound_uuid = wp_generate_uuid4();
		$inbound_uuid  = wp_generate_uuid4();

		Transfer::create( $this->base_row( [
			'character_uuid' => $outbound_uuid, 'direction' => 'outbound', 'home_slug' => $home_slug,
			'host_slug' => $host_slug, 'host_chronicle' => 'The Other Chronicle',
		] ) );
		Transfer::create( $this->base_row( [
			'character_uuid' => $inbound_uuid, 'direction' => 'inbound', 'state' => 'visiting',
			'home_slug' => 'somewhere-else', 'home_chronicle' => 'Somewhere Else', 'host_slug' => $home_slug,
		] ) );

		$num_queries_before = get_num_queries();
		$states             = Transfer::open_states_for_game( $home_slug );
		$queries_used       = get_num_queries() - $num_queries_before;

		$this->assertSame( 1, $queries_used, 'one query regardless of how many transfers touch this game' );
		$this->assertSame( 'outbound', $states[ $outbound_uuid ]['direction'] );
		$this->assertSame( 'The Other Chronicle', $states[ $outbound_uuid ]['chronicle'] );
		$this->assertSame( 'inbound', $states[ $inbound_uuid ]['direction'] );
		$this->assertSame( 'Somewhere Else', $states[ $inbound_uuid ]['chronicle'] );
	}

	public function test_open_states_for_game_omits_terminal_transfers(): void {
		$home_slug = 'thread-test-transfer-terminal-home';
		$id        = Transfer::create( $this->base_row( [ 'home_slug' => $home_slug ] ) );
		Transfer::transition( $id, 'released' );

		$this->assertSame( [], Transfer::open_states_for_game( $home_slug ) );
	}

	public function test_history_for_character_returns_every_row_newest_first(): void {
		$uuid = wp_generate_uuid4();
		$id1  = Transfer::create( $this->base_row( [ 'character_uuid' => $uuid ] ) );
		Transfer::transition( $id1, 'released' );
		$id2 = Transfer::create( $this->base_row( [ 'character_uuid' => $uuid ] ) );

		$history = Transfer::history_for_character( $uuid );
		$this->assertCount( 2, $history );
		$this->assertSame( $id2, (int) $history[0]->id );
		$this->assertSame( $id1, (int) $history[1]->id );
	}
}
