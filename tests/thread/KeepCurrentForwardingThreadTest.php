<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Change;
use BeyondElysium\Models\Character;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Plot_Entry;
use BeyondElysium\Models\Transfer;
use BeyondElysium\Services\Change_Engine;
use BeyondElysium\Services\Keep_Current;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * A host's own change or XP award, or a free-text note, forwarded to a visiting character's real home instead of
 * applied locally.
 */
class KeepCurrentForwardingThreadTest extends WP_UnitTestCase {

	private string $home_slug = 'thread-keep-fwd-home';
	private string $host_slug = 'thread-keep-fwd-host';
	private int $home_character_id;
	private object $home_character;
	private int $host_character_id;
	private int $home_visit_id;
	private int $host_visit_id;
	private ?int $last_from_host_status = null;

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );

		Game::create( [ 'slug' => $this->home_slug, 'name' => 'Keep Fwd Home' ] );
		Game::create( [ 'slug' => $this->host_slug, 'name' => 'Keep Fwd Host' ] );

		$this->home_character_id = Character::create( [
			'name' => 'Kept Current Vampire', 'stack_slug' => 'vampire',
			'owner_type' => 'chronicle', 'owner_slug' => $this->home_slug, 'status' => 'active',
		] );
		Character::update_xp( $this->home_character_id, 20, 10 );
		$this->home_character = Character::find( $this->home_character_id );

		$this->home_visit_id = Transfer::create( [
			'character_uuid' => $this->home_character->uuid, 'character_id' => $this->home_character_id,
			'direction' => 'outbound', 'state' => 'visiting', 'home_slug' => $this->home_slug,
			'home_site' => home_url(), 'home_chronicle' => 'Keep Fwd Home',
			'host_slug' => $this->host_slug, 'host_site' => home_url(), 'host_chronicle' => 'Keep Fwd Host',
			'payload_hash' => str_repeat( 'a', 64 ), 'initiated_by' => 1, 'keep_current' => true,
		] );
		Transfer::transition( $this->home_visit_id, 'visiting', [ 'keep_current_accepted' => 1 ] );

		$this->host_character_id = Character::create( [
			'name' => 'Kept Current Vampire', 'stack_slug' => 'vampire',
			'owner_type' => 'chronicle', 'owner_slug' => $this->host_slug, 'status' => 'active',
		] );

		$this->host_visit_id = Transfer::create( [
			'character_uuid' => $this->home_character->uuid, 'character_id' => $this->host_character_id,
			'direction' => 'inbound', 'state' => 'visiting', 'home_slug' => $this->home_slug,
			'home_site' => home_url(), 'home_chronicle' => 'Keep Fwd Home',
			'host_slug' => $this->host_slug, 'host_site' => home_url(), 'host_chronicle' => 'Keep Fwd Host',
			'payload_hash' => str_repeat( 'b', 64 ), 'initiated_by' => 1, 'keep_current' => true,
		] );
		Transfer::transition( $this->host_visit_id, 'visiting', [ 'keep_current_accepted' => 1 ] );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
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
				$response                     = rest_get_server()->dispatch( $request );
				$this->last_from_host_status  = $response->get_status();
				return [
					'response' => [ 'code' => $response->get_status(), 'message' => '' ],
					'body'     => wp_json_encode( $response->get_data() ),
					'headers'  => [], 'cookies' => [], 'filename' => null,
				];
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

	private function post( string $path, array $params = [] ) {
		$request = new WP_REST_Request( 'POST', $path );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		return rest_get_server()->dispatch( $request );
	}

	private function pending_forwarded_change(): ?object {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}be_character_changes WHERE character_id = %d AND status = 'pending' ORDER BY id DESC LIMIT 1",
			$this->home_character_id
		) );
		return $row ? Change::find( (int) $row->id ) : null;
	}

	public function test_a_forwarded_change_of_a_kind_no_one_can_submit_is_refused(): void {
		$callback = $this->loopback();
		Keep_Current::forward_change( Transfer::find( $this->host_visit_id ), (object) [
			'change_type' => 'visit_pairing',
			'change_data' => [ 'host_site' => 'https://elsewhere.example' ],
			'notes'       => null,
		] );
		remove_filter( 'pre_http_request', $callback, 10 );

		$this->assertSame( 400, $this->last_from_host_status );
		$this->assertNull( $this->pending_forwarded_change() );
	}

	public function test_markup_in_a_forwarded_change_is_cleaned(): void {
		$callback = $this->loopback();
		Keep_Current::forward_change( Transfer::find( $this->host_visit_id ), (object) [
			'change_type' => 'xp_earn',
			'change_data' => [ 'amount' => 2, 'reason' => 'Night<script>alert(1)</script>' ],
			'notes'       => '<b>Good</b><img src=x onerror=alert(1)>',
		] );
		remove_filter( 'pre_http_request', $callback, 10 );

		$pending = $this->pending_forwarded_change();
		$this->assertNotNull( $pending );
		$this->assertStringNotContainsString( '<script', (string) $pending->change_data['reason'] );
		$this->assertStringNotContainsString( 'onerror', (string) $pending->host_note );
		$this->assertStringContainsString( '<b>Good</b>', (string) $pending->host_note );
	}

	public function test_a_host_xp_award_arrives_pending_at_home_and_changes_nothing_at_the_host(): void {
		$callback = $this->loopback();
		$result   = Change_Engine::apply_xp( $this->host_character_id, 5, 'A good night', 1 );
		remove_filter( 'pre_http_request', $callback, 10 );

		$this->assertTrue( $result['applied'] );
		$this->assertTrue( $result['forwarded'] ?? false );
		$this->assertSame( 0, (int) Character::find( $this->host_character_id )->xp_earned, 'nothing applied at the host' );

		$pending = $this->pending_forwarded_change();
		$this->assertNotNull( $pending );
		$this->assertSame( 'pending', $pending->status );
		$this->assertSame( 0, (int) $pending->submitted_by );
		$this->assertSame( $this->home_visit_id, (int) $pending->source_visit_id );
		$this->assertSame( 5, (int) $pending->change_data['amount'] );
	}

	public function test_approving_it_raises_homes_xp_and_schedules_the_next_delivery(): void {
		$callback = $this->loopback();
		Change_Engine::apply_xp( $this->host_character_id, 5, 'A good night', 1 );
		remove_filter( 'pre_http_request', $callback, 10 );
		$pending = $this->pending_forwarded_change();

		$this->assertTrue( Change_Engine::approve( (int) $pending->id, 1, null ) );
		$this->assertSame( 25, (int) Character::find( $this->home_character_id )->xp_earned );

		$scheduled = false;
		foreach ( _get_cron_array() ?: [] as $hooks ) {
			foreach ( $hooks['be_keep_current_deliver'] ?? [] as $event ) {
				if ( ( $event['args'][0] ?? null ) === $this->home_visit_id ) {
					$scheduled = true;
				}
			}
		}
		$this->assertTrue( $scheduled, 'approving it at home schedules a delivery back to the host' );
	}

	public function test_a_note_approved_lands_on_the_characters_plot_storytellers_only(): void {
		$callback = $this->loopback();
		$response = $this->post( "/be/v1/{$this->host_slug}/transfers/{$this->host_visit_id}/note", [ 'note' => 'Quiet night at the Chantry.' ] );
		remove_filter( 'pre_http_request', $callback, 10 );
		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );

		$pending = $this->pending_forwarded_change();
		$this->assertSame( 'visit_note', $pending->change_type );
		$this->assertSame( 'Quiet night at the Chantry.', $pending->change_data['note'] );

		$this->assertTrue( Change_Engine::approve( (int) $pending->id, 1, null ) );

		$plot_id = Character::plot_id( $this->home_character_id );
		$this->assertNotNull( $plot_id );
		$entries = Plot_Entry::for_plot( (int) $plot_id );
		$note_entry = null;
		foreach ( $entries as $entry ) {
			if ( $entry->content === 'Quiet night at the Chantry.' ) {
				$note_entry = $entry;
			}
		}
		$this->assertNotNull( $note_entry );
		$this->assertSame( Plot_Entry::AUDIENCE_STORYTELLERS, $note_entry->audience );
	}

	public function test_a_refused_note_records_nothing(): void {
		$callback = $this->loopback();
		$this->post( "/be/v1/{$this->host_slug}/transfers/{$this->host_visit_id}/note", [ 'note' => 'Never mind.' ] );
		remove_filter( 'pre_http_request', $callback, 10 );
		$pending = $this->pending_forwarded_change();

		$this->assertTrue( Change_Engine::reject( (int) $pending->id, 1, null ) );

		$plot_id = Character::plot_id( $this->home_character_id );
		if ( $plot_id !== null ) {
			foreach ( Plot_Entry::for_plot( (int) $plot_id ) as $entry ) {
				$this->assertNotSame( 'Never mind.', $entry->content );
			}
		}
	}

	public function test_the_51st_waiting_item_from_one_host_is_refused(): void {
		global $wpdb;
		for ( $i = 0; $i < 50; $i++ ) {
			Change::create( [
				'character_id'    => $this->home_character_id,
				'change_type'     => 'xp_earn',
				'category'        => 'experience',
				'change_data'     => [ 'amount' => 1 ],
				'status'          => 'pending',
				'submitted_by'    => 0,
				'source_visit_id' => $this->home_visit_id,
			] );
		}

		$callback = $this->loopback();
		$response = $this->post( "/be/v1/{$this->host_slug}/transfers/{$this->host_visit_id}/note", [ 'note' => 'One too many.' ] );
		remove_filter( 'pre_http_request', $callback, 10 );

		// The host's own /note action is fire-and-forget (the local side has nothing to apply either way), so its
		// own response always reads ok; home's real refusal is observed on the call it actually received.
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 429, $this->last_from_host_status );
	}

	public function test_auto_approve_on_at_home_still_waits(): void {
		Game::update( $this->home_slug, [ 'settings' => [ 'auto_approve' => true ] ] );

		$callback = $this->loopback();
		Change_Engine::apply_xp( $this->host_character_id, 3, 'Reason', 1 );
		remove_filter( 'pre_http_request', $callback, 10 );

		$pending = $this->pending_forwarded_change();
		$this->assertNotNull( $pending );
		$this->assertSame( 'pending', $pending->status, 'a forwarded change is never auto-approved regardless of chronicle policy' );
	}
}
