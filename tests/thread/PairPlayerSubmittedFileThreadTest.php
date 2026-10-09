<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Change;
use BeyondElysium\Models\Character;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Game_Member;
use BeyondElysium\Models\Transfer;
use BeyondElysium\Services\Character_Exporter;
use BeyondElysium\Services\Change_Engine;
use BeyondElysium\Services\Keep_Current;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Pairing a player's own Grapevine file: the host's request to keep a submitted character current with its real
 * home, home's own check of that request, and what approving or refusing it does.
 */
class PairPlayerSubmittedFileThreadTest extends WP_UnitTestCase {

	private string $home_slug = 'thread-pair-home';
	private string $host_slug = 'thread-pair-host';
	private int $host_game_id;
	private int $hst;
	private int $sender;

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );

		Game::create( [ 'slug' => $this->home_slug, 'name' => 'Pair Home' ] );
		$this->host_game_id = (int) Game::create( [ 'slug' => $this->host_slug, 'name' => 'Pair Host' ] );

		$this->hst    = self::factory()->user->create( [ 'role' => 'editor', 'user_email' => 'pair-hst@example.test' ] );
		Game_Member::set_role( $this->host_game_id, $this->hst, 'hst' );
		$this->sender = self::factory()->user->create( [ 'role' => 'subscriber', 'user_email' => 'pair-sender@example.test' ] );
	}

	/**
	 * Routes `/verify/`, `/from-host` and `/from-home` calls to real internal dispatches, simulating two sites in
	 * one process.
	 */
	private function loopback(): callable {
		$callback = function ( $preempt, $args, $url ) {
			if ( strpos( $url, '/verify/' ) !== false ) {
				$code = rawurldecode( substr( $url, strrpos( $url, '/' ) + 1 ) );
				return $this->dispatched_response( new WP_REST_Request( 'GET', '/be/v1/verify/' . $code ) );
			}
			if ( preg_match( '#/([a-z0-9\-]+)/transfers/([^/]+)/(from-host|from-home)#', $url, $m ) ) {
				$body    = json_decode( (string) ( $args['body'] ?? '{}' ), true );
				$request = new WP_REST_Request( 'POST', "/be/v1/{$m[1]}/transfers/{$m[2]}/{$m[3]}" );
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
	 * Exports a real home character with a verify URL embedded, uploads it to the host as a visiting,
	 * keep-current submission, and returns its submission id.
	 */
	private function submit_with_keep_current(): int {
		$home_character_id = Character::create( [
			'name' => 'Paired Vampire', 'stack_slug' => 'vampire',
			'owner_type' => 'chronicle', 'owner_slug' => $this->home_slug, 'status' => 'active',
		] );
		$xml = Character_Exporter::export( $home_character_id, [ 'verify' => true ] )['xml'];

		wp_set_current_user( $this->sender );
		$tmp = tempnam( sys_get_temp_dir(), 'be-pairing-test' );
		file_put_contents( $tmp, $xml );
		$request = new WP_REST_Request( 'POST', "/be/v1/{$this->host_slug}/submissions" );
		$request->set_file_params( [
			'file' => [ 'tmp_name' => $tmp, 'name' => 'sheet.gex', 'error' => 0, 'size' => strlen( $xml ), 'type' => 'application/octet-stream' ],
		] );
		$request->set_param( 'arrival', 'visiting' );
		$request->set_param( 'keep_current', true );
		$response = rest_get_server()->dispatch( $request );
		return (int) $response->get_data()['id'];
	}

	private function accept( int $submission_id ) {
		wp_set_current_user( $this->hst );
		return rest_get_server()->dispatch( new WP_REST_Request( 'POST', "/be/v1/{$this->host_slug}/submissions/{$submission_id}/accept" ) );
	}

	private function pending_pairing_request(): ?object {
		global $wpdb;
		$row = $wpdb->get_row( "SELECT id FROM {$wpdb->prefix}be_character_changes WHERE change_type = 'visit_pairing' AND status = 'pending' ORDER BY id DESC LIMIT 1" );
		return $row ? Change::find( (int) $row->id ) : null;
	}

	private function accepted_character_id( $response ): int {
		return (int) $response->get_data()['character']['id'];
	}

	private function find_outbound_visit( int $character_id ): ?object {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT id FROM {$wpdb->prefix}be_character_transfers WHERE character_id = %d AND direction = 'outbound' ORDER BY id DESC LIMIT 1",
			$character_id
		) );
		return $row ? Transfer::find( (int) $row->id ) : null;
	}

	public function test_approve_pairs_and_the_next_change_delivers(): void {
		$submission_id = $this->submit_with_keep_current();

		$callback = $this->loopback();
		$accepted = $this->accept( $submission_id );
		remove_filter( 'pre_http_request', $callback, 10 );
		$this->assertSame( 200, $accepted->get_status(), wp_json_encode( $accepted->get_data() ) );

		$pending = $this->pending_pairing_request();
		$this->assertNotNull( $pending );
		$this->assertSame( 'thread-pair-host', $pending->change_data['host_slug'] );

		$callback = $this->loopback();
		$this->assertTrue( Change_Engine::approve( (int) $pending->id, $this->hst, null ) );
		remove_filter( 'pre_http_request', $callback, 10 );

		$home_row = $this->find_outbound_visit( (int) $pending->character_id );
		$this->assertNotNull( $home_row );
		$this->assertTrue( (bool) $home_row->keep_current );
		$this->assertTrue( (bool) $home_row->keep_current_accepted );

		$host_character_id = $this->accepted_character_id( $accepted );
		$host_uuid = Character::find( $host_character_id )->uuid;
		$host_row = Transfer::find_open_visit_from_home( $host_uuid, home_url(), $this->home_slug, $this->host_slug );
		$this->assertNotNull( $host_row );
		$this->assertTrue( (bool) $host_row->keep_current_accepted, 'the host learns home agreed too' );

		Character::update_xp( (int) $pending->character_id, 5, 0 );
		$callback = $this->loopback();
		$this->assertTrue( Keep_Current::deliver( (int) $home_row->id ) );
		remove_filter( 'pre_http_request', $callback, 10 );

		$this->assertSame( 5, (int) Character::find( $host_character_id )->xp_earned );
	}

	public function test_a_repeat_request_from_the_same_site_is_refused_while_one_is_waiting(): void {
		$submission_id = $this->submit_with_keep_current();

		$captured = null;
		$capture  = function ( $preempt, $args, $url ) use ( &$captured ) {
			if ( strpos( $url, '/from-host' ) !== false ) {
				$captured = [ $url, json_decode( (string) ( $args['body'] ?? '{}' ), true ) ];
			}
			return $preempt;
		};
		add_filter( 'pre_http_request', $capture, 9, 3 );
		$callback = $this->loopback();
		$this->accept( $submission_id );
		remove_filter( 'pre_http_request', $callback, 10 );
		remove_filter( 'pre_http_request', $capture, 9 );
		$this->assertNotNull( $captured );
		$this->assertNotNull( $this->pending_pairing_request() );

		preg_match( '#/([a-z0-9\-]+)/transfers/([^/]+)/from-host#', $captured[0], $m );
		$repeat = new WP_REST_Request( 'POST', "/be/v1/{$m[1]}/transfers/{$m[2]}/from-host" );
		foreach ( (array) $captured[1] as $key => $value ) {
			$repeat->set_param( $key, $value );
		}
		$callback = $this->loopback();
		$response = rest_get_server()->dispatch( $repeat );
		remove_filter( 'pre_http_request', $callback, 10 );

		$this->assertSame( 409, $response->get_status(), wp_json_encode( $response->get_data() ) );
		global $wpdb;
		$this->assertSame( 1, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}be_character_changes WHERE change_type = 'visit_pairing' AND status = 'pending'" ) );
	}

	public function test_refuse_leaves_it_unpaired(): void {
		$submission_id = $this->submit_with_keep_current();

		$callback = $this->loopback();
		$accepted = $this->accept( $submission_id );
		remove_filter( 'pre_http_request', $callback, 10 );

		$pending = $this->pending_pairing_request();
		$this->assertNotNull( $pending );

		$this->assertTrue( Change_Engine::reject( (int) $pending->id, $this->hst, null ) );

		$host_character_id = $this->accepted_character_id( $accepted );
		$host_row = Transfer::find_open_visit( Character::find( $host_character_id )->uuid, home_url(), $this->host_slug, 'inbound' );
		$this->assertNotNull( $host_row );
		$this->assertTrue( (bool) $host_row->keep_current );
		$this->assertFalse( (bool) $host_row->keep_current_accepted, 'refused stays unpaired, not removed' );

		$this->assertNull( $this->find_outbound_visit( (int) $pending->character_id ) );
	}

	public function test_a_request_whose_verify_names_another_issuer_is_refused(): void {
		$submission_id = $this->submit_with_keep_current();

		$callback = function ( $preempt, $args, $url ) {
			if ( strpos( $url, '/verify/' ) !== false ) {
				$code = rawurldecode( substr( $url, strrpos( $url, '/' ) + 1 ) );
				$real = rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/be/v1/verify/' . $code ) );
				$data = (array) $real->get_data();
				if ( isset( $data['issuer'] ) ) {
					$data['issuer']['site'] = 'https://not-the-real-site.example';
				}
				return [
					'response' => [ 'code' => $real->get_status(), 'message' => '' ],
					'body'     => wp_json_encode( $data ),
					'headers'  => [], 'cookies' => [], 'filename' => null,
				];
			}
			if ( preg_match( '#/([a-z0-9\-]+)/transfers/([^/]+)/(from-host|from-home)#', $url, $m ) ) {
				$body    = json_decode( (string) ( $args['body'] ?? '{}' ), true );
				$request = new WP_REST_Request( 'POST', "/be/v1/{$m[1]}/transfers/{$m[2]}/{$m[3]}" );
				foreach ( (array) $body as $key => $value ) {
					$request->set_param( $key, $value );
				}
				return $this->dispatched_response( $request );
			}
			return $preempt;
		};
		add_filter( 'pre_http_request', $callback, 10, 3 );
		$this->accept( $submission_id );
		remove_filter( 'pre_http_request', $callback, 10 );

		$this->assertNull( $this->pending_pairing_request(), 'a mismatched issuer never files a pairing request' );
	}

	public function test_the_60_day_expiry(): void {
		$submission_id = $this->submit_with_keep_current();

		$callback = $this->loopback();
		$this->accept( $submission_id );
		remove_filter( 'pre_http_request', $callback, 10 );

		$pending = $this->pending_pairing_request();
		$this->assertNotNull( $pending );

		global $wpdb;
		$wpdb->update( $wpdb->prefix . 'be_character_changes', [
			'submitted_at' => gmdate( 'Y-m-d H:i:s', time() - 61 * DAY_IN_SECONDS ),
		], [ 'id' => $pending->id ] );

		$this->assertSame( 1, Change::expire_stale_visit_pairings( Transfer::OFFER_TTL_DAYS ) );
		$this->assertSame( 'rejected', Change::find( (int) $pending->id )->status );
		$this->assertNull( $this->find_outbound_visit( (int) $pending->character_id ) );
	}
}
