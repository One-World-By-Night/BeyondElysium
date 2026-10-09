<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Transfer;
use WP_UnitTestCase;

/**
 * `Transfer`'s own model-level contract: the visit model (offered/visiting/ended, one open row per host).
 */
class TransferThreadTest extends WP_UnitTestCase {

	private function base_row( array $overrides = [] ): array {
		return array_merge( [
			'character_uuid' => wp_generate_uuid4(),
			'direction'      => 'outbound',
			'state'          => 'offered',
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
		$this->assertSame( 'offered', $row->state );
		$this->assertSame( 'outbound', $row->direction );
	}

	public function test_create_refuses_a_second_open_row_with_no_host_for_the_same_uuid_and_direction(): void {
		$uuid = wp_generate_uuid4();
		Transfer::create( $this->base_row( [ 'character_uuid' => $uuid ] ) );

		$this->expectException( \RuntimeException::class );
		Transfer::create( $this->base_row( [ 'character_uuid' => $uuid ] ) );
	}

	public function test_create_refuses_a_second_open_row_to_the_same_host(): void {
		$uuid = wp_generate_uuid4();
		Transfer::create( $this->base_row( [
			'character_uuid' => $uuid, 'host_slug' => 'thread-test-transfer-host', 'host_site' => 'https://host.example',
		] ) );

		$this->expectException( \RuntimeException::class );
		Transfer::create( $this->base_row( [
			'character_uuid' => $uuid, 'host_slug' => 'thread-test-transfer-host', 'host_site' => 'https://host.example',
		] ) );
	}

	public function test_create_allows_a_second_open_row_to_a_different_host(): void {
		$uuid = wp_generate_uuid4();
		$id1  = Transfer::create( $this->base_row( [
			'character_uuid' => $uuid, 'host_slug' => 'thread-test-transfer-host-a', 'host_site' => 'https://host-a.example',
		] ) );

		// No exception - any number of visits can be open at once, one per host.
		$id2 = Transfer::create( $this->base_row( [
			'character_uuid' => $uuid, 'host_slug' => 'thread-test-transfer-host-b', 'host_site' => 'https://host-b.example',
		] ) );
		$this->assertNotSame( $id1, $id2 );
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

	public function test_find_open_visit_does_not_cross_directions_on_the_same_site_by_default(): void {
		// On a single install acting as both ends of a loopback, home's own outbound row to a host and that host's
		// own inbound row from home can share the exact same host_site/host_slug pair without being the same visit;
		// the unscoped lookup does not conflate them.
		$uuid = wp_generate_uuid4();
		Transfer::create( $this->base_row( [
			'character_uuid' => $uuid, 'direction' => 'outbound',
			'host_slug' => 'thread-test-same-site-host', 'host_site' => 'https://same-site.example',
		] ) );

		$inbound = Transfer::create( array_merge( $this->base_row( [ 'character_uuid' => $uuid, 'direction' => 'inbound' ] ), [
			'host_slug' => 'thread-test-same-site-host', 'host_site' => 'https://same-site.example',
			'home_slug' => 'thread-test-same-site-home-2', 'home_chronicle' => 'Thread Test Home 2',
		] ) );
		$this->assertNotNull( Transfer::find( $inbound ) );

		$this->assertNotNull( Transfer::find_open_visit( $uuid, 'https://same-site.example', 'thread-test-same-site-host', 'outbound' ) );
		$this->assertNotNull( Transfer::find_open_visit( $uuid, 'https://same-site.example', 'thread-test-same-site-host', 'inbound' ) );
	}

	public function test_find_open_visit_is_scoped_by_host(): void {
		$uuid = wp_generate_uuid4();
		Transfer::create( $this->base_row( [
			'character_uuid' => $uuid, 'host_slug' => 'thread-test-transfer-host-a', 'host_site' => 'https://host-a.example',
		] ) );

		$this->assertNotNull( Transfer::find_open_visit( $uuid, 'https://host-a.example', 'thread-test-transfer-host-a' ) );
		$this->assertNull( Transfer::find_open_visit( $uuid, 'https://host-b.example', 'thread-test-transfer-host-b' ) );
	}

	public function test_open_visits_for_character_returns_every_direction(): void {
		$uuid = wp_generate_uuid4();
		Transfer::create( $this->base_row( [
			'character_uuid' => $uuid, 'direction' => 'outbound', 'host_slug' => 'thread-test-visits-host-a', 'host_site' => 'https://host-a.example',
		] ) );
		Transfer::create( $this->base_row( [
			'character_uuid' => $uuid, 'direction' => 'outbound', 'host_slug' => 'thread-test-visits-host-b', 'host_site' => 'https://host-b.example',
		] ) );

		$this->assertCount( 2, Transfer::open_visits_for_character( $uuid ) );
	}

	public function test_open_visits_for_character_omits_terminal_rows(): void {
		$uuid = wp_generate_uuid4();
		$id   = Transfer::create( $this->base_row( [ 'character_uuid' => $uuid ] ) );
		Transfer::transition( $id, 'released' );

		$this->assertSame( [], Transfer::open_visits_for_character( $uuid ) );
	}

	public function test_transition_to_visiting_stamps_acknowledged_at(): void {
		$id = Transfer::create( $this->base_row() );
		$this->assertNull( Transfer::find( $id )->acknowledged_at );

		Transfer::transition( $id, 'visiting' );
		$this->assertNotNull( Transfer::find( $id )->acknowledged_at );
		$this->assertSame( 'visiting', Transfer::find( $id )->state );
	}

	public function test_transition_to_ended_stamps_returned_at(): void {
		$id = Transfer::create( $this->base_row( [ 'state' => 'visiting' ] ) );
		Transfer::transition( $id, 'ended' );

		$row = Transfer::find( $id );
		$this->assertSame( 'ended', $row->state );
		$this->assertNotNull( $row->returned_at );
	}

	public function test_transition_merges_extra_columns(): void {
		$id = Transfer::create( $this->base_row() );
		Transfer::transition( $id, 'visiting', [ 'host_chronicle' => 'Confirmed Host' ] );

		$this->assertSame( 'Confirmed Host', Transfer::find( $id )->host_chronicle );
	}

	public function test_open_states_for_game_lists_every_open_visit_per_uuid(): void {
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
		$queries_used        = get_num_queries() - $num_queries_before;

		$this->assertSame( 1, $queries_used, 'one query regardless of how many transfers touch this game' );
		$this->assertSame( 'outbound', $states[ $outbound_uuid ][0]['direction'] );
		$this->assertSame( 'The Other Chronicle', $states[ $outbound_uuid ][0]['chronicle'] );
		$this->assertSame( 'inbound', $states[ $inbound_uuid ][0]['direction'] );
		$this->assertSame( 'Somewhere Else', $states[ $inbound_uuid ][0]['chronicle'] );
	}

	public function test_open_states_for_game_lists_several_hosts_for_one_character(): void {
		$home_slug = 'thread-test-transfer-multi-host';
		$uuid      = wp_generate_uuid4();

		Transfer::create( $this->base_row( [
			'character_uuid' => $uuid, 'home_slug' => $home_slug,
			'host_slug' => 'thread-test-multi-host-a', 'host_site' => 'https://multi-host-a.example', 'host_chronicle' => 'Host A',
		] ) );
		Transfer::create( $this->base_row( [
			'character_uuid' => $uuid, 'home_slug' => $home_slug,
			'host_slug' => 'thread-test-multi-host-b', 'host_site' => 'https://multi-host-b.example', 'host_chronicle' => 'Host B',
		] ) );

		$states = Transfer::open_states_for_game( $home_slug );
		$this->assertCount( 2, $states[ $uuid ] );
		$chronicles = array_column( $states[ $uuid ], 'chronicle' );
		$this->assertContains( 'Host A', $chronicles );
		$this->assertContains( 'Host B', $chronicles );
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
