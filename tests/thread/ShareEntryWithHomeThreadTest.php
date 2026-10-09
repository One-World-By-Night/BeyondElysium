<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Database\Manager;
use BeyondElysium\Models\Change;
use BeyondElysium\Models\Character;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Plot_Entry;
use BeyondElysium\Models\Transfer;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * A host's own plot entry about a visiting character, shared back to its real home chronicle as a note - on any
 * online visit, kept current or not, while it's open and for a grace window after it ends.
 */
class ShareEntryWithHomeThreadTest extends WP_UnitTestCase {

	private string $home_slug = 'thread-share-home';
	private string $host_slug = 'thread-share-host';
	private int $home_character_id;
	private object $home_character;
	private int $host_character_id;
	private int $home_visit_id;
	private int $host_visit_id;
	private int $plot_id;

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );

		Game::create( [ 'slug' => $this->home_slug, 'name' => 'Share Home' ] );
		Game::create( [ 'slug' => $this->host_slug, 'name' => 'Share Host' ] );

		$this->home_character_id = Character::create( [
			'name' => 'Shared Visitor', 'stack_slug' => 'vampire',
			'owner_type' => 'chronicle', 'owner_slug' => $this->home_slug, 'status' => 'active',
		] );
		$this->home_character = Character::find( $this->home_character_id );

		$this->home_visit_id = Transfer::create( [
			'character_uuid' => $this->home_character->uuid, 'character_id' => $this->home_character_id,
			'direction' => 'outbound', 'state' => 'visiting', 'home_slug' => $this->home_slug,
			'home_site' => home_url(), 'home_chronicle' => 'Share Home',
			'host_slug' => $this->host_slug, 'host_site' => home_url(), 'host_chronicle' => 'Share Host',
			'payload_hash' => str_repeat( 'a', 64 ), 'initiated_by' => 1,
		] );

		$this->host_character_id = Character::create( [
			'name' => 'Shared Visitor', 'stack_slug' => 'vampire',
			'owner_type' => 'chronicle', 'owner_slug' => $this->host_slug, 'status' => 'active',
		] );

		$this->host_visit_id = Transfer::create( [
			'character_uuid' => $this->home_character->uuid, 'character_id' => $this->host_character_id,
			'direction' => 'inbound', 'state' => 'visiting', 'home_slug' => $this->home_slug,
			'home_site' => home_url(), 'home_chronicle' => 'Share Home',
			'host_slug' => $this->host_slug, 'host_site' => home_url(), 'host_chronicle' => 'Share Host',
			'payload_hash' => str_repeat( 'b', 64 ), 'initiated_by' => 1,
		] );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$this->plot_id = (int) Character::ensure_plot( $this->host_character_id );
	}

	/**
	 * Routes a `from-host` call (and the `/verify/` callback it makes) to a real internal dispatch.
	 */
	private function loopback(): callable {
		$callback = function ( $preempt, $args, $url ) {
			if ( strpos( $url, '/verify/' ) !== false ) {
				$code = rawurldecode( substr( $url, strrpos( $url, '/' ) + 1 ) );
				return $this->dispatched_response( new WP_REST_Request( 'GET', '/be/v1/verify/' . $code ) );
			}
			if ( preg_match( '#/([a-z0-9\-]+)/transfers/([^/]+)/from-host#', $url, $m ) ) {
				$body    = json_decode( (string) ( $args['body'] ?? '{}' ), true );
				$request = new WP_REST_Request( 'POST', "/be/v1/{$m[1]}/transfers/{$m[2]}/from-host" );
				foreach ( (array) $body as $key => $value ) {
					$request->set_param( $key, $value );
				}
				return $this->dispatched_response( $request );
			}
			return $preempt;
		};
		add_filter( 'pre_http_request', $callback, 10, 3 );
		return $callback;
	}

	private function dispatched_response( WP_REST_Request $request ): array {
		$response = rest_get_server()->dispatch( $request );
		return [
			'response' => [ 'code' => $response->get_status(), 'message' => '' ],
			'body'     => wp_json_encode( $response->get_data() ),
			'headers'  => [], 'cookies' => [], 'filename' => null,
		];
	}

	/**
	 * @return object[]
	 */
	private function pending_visit_notes(): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT id FROM {$wpdb->prefix}be_character_changes WHERE character_id = %d AND change_type = 'visit_note' AND status = 'pending' ORDER BY id ASC",
			$this->home_character_id
		) );
		return array_map( static fn( $row ) => Change::find( (int) $row->id ), $rows );
	}

	private function create_entry( string $content, bool $share = true ) {
		$request = new WP_REST_Request( 'POST', "/be/v1/{$this->host_slug}/plots/{$this->plot_id}/entries" );
		$request->set_param( 'entry_type', 'note' );
		$request->set_param( 'content', $content );
		$request->set_param( 'share_with_home', $share );
		return rest_get_server()->dispatch( $request );
	}

	private function update_entry( int $entry_id, string $content, bool $share = true ) {
		$request = new WP_REST_Request( 'PUT', "/be/v1/{$this->host_slug}/entries/{$entry_id}" );
		$request->set_param( 'content', $content );
		$request->set_param( 'share_with_home', $share );
		return rest_get_server()->dispatch( $request );
	}

	public function test_offered_on_an_open_visit(): void {
		$callback = $this->loopback();
		$response = $this->create_entry( 'Quiet night at the Chantry.' );
		remove_filter( 'pre_http_request', $callback, 10 );

		$this->assertSame( 201, $response->get_status() );
		$this->assertNotNull( $response->get_data()->shared_at ?? null );

		$notes = $this->pending_visit_notes();
		$this->assertCount( 1, $notes );
		$this->assertSame( 'Quiet night at the Chantry.', $notes[0]->change_data['note'] );
		$this->assertSame( $this->home_visit_id, (int) $notes[0]->source_visit_id );
	}

	public function test_offered_20_days_after_the_end(): void {
		Transfer::transition( $this->host_visit_id, 'ended' );
		Manager::update( 'character_transfers', [
			'returned_at' => gmdate( 'Y-m-d H:i:s', time() - 20 * DAY_IN_SECONDS ),
		], [ 'id' => $this->host_visit_id ] );

		$callback = $this->loopback();
		$response = $this->create_entry( 'Still settling in at 20 days.' );
		remove_filter( 'pre_http_request', $callback, 10 );

		$this->assertNotNull( $response->get_data()->shared_at ?? null );
		$this->assertCount( 1, $this->pending_visit_notes() );
	}

	public function test_not_offered_at_31_days(): void {
		Transfer::transition( $this->host_visit_id, 'ended' );
		Manager::update( 'character_transfers', [
			'returned_at' => gmdate( 'Y-m-d H:i:s', time() - 31 * DAY_IN_SECONDS ),
		], [ 'id' => $this->host_visit_id ] );

		$callback = $this->loopback();
		$response = $this->create_entry( 'Too late to share.' );
		remove_filter( 'pre_http_request', $callback, 10 );

		$this->assertNull( $response->get_data()->shared_at ?? null );
		$this->assertCount( 0, $this->pending_visit_notes() );
	}

	public function test_not_offered_on_a_hand_carried_copy(): void {
		// No transfer row at all names this character - a hand-carried import, not a real visit.
		$plain_character_id = Character::create( [
			'name' => 'Hand Carried', 'stack_slug' => 'vampire',
			'owner_type' => 'chronicle', 'owner_slug' => $this->host_slug, 'status' => 'active',
		] );
		$plot_id = (int) Character::ensure_plot( $plain_character_id );

		$request = new WP_REST_Request( 'POST', "/be/v1/{$this->host_slug}/plots/{$plot_id}/entries" );
		$request->set_param( 'entry_type', 'note' );
		$request->set_param( 'content', 'No home to tell.' );
		$request->set_param( 'share_with_home', true );

		$callback = $this->loopback();
		$response = rest_get_server()->dispatch( $request );
		remove_filter( 'pre_http_request', $callback, 10 );

		$this->assertSame( 201, $response->get_status() );
		$this->assertNull( $response->get_data()->shared_at ?? null );
	}

	public function test_a_shared_edit_arrives_as_a_second_note(): void {
		$callback = $this->loopback();
		$created  = $this->create_entry( 'First telling.' );
		remove_filter( 'pre_http_request', $callback, 10 );
		$entry_id = $created->get_data()->id;

		$callback = $this->loopback();
		$updated  = $this->update_entry( $entry_id, 'Second telling, revised.' );
		remove_filter( 'pre_http_request', $callback, 10 );

		$this->assertSame( 200, $updated->get_status() );
		$notes = $this->pending_visit_notes();
		$this->assertCount( 2, $notes, 'each share sends its own note, not a replacement' );
		$this->assertSame( 'First telling.', $notes[0]->change_data['note'] );
		$this->assertSame( 'Second telling, revised.', $notes[1]->change_data['note'] );
	}
}
