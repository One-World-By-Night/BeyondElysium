<?php

namespace BeyondElysium\Tests\Workflow;

use BeyondElysium\Models\Character;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Game_Member;
use BeyondElysium\Models\Transfer;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * A character travels between two chronicles with a Storyteller's approval on both sides.
 */
class TransferApprovalWorkflowTest extends WP_UnitTestCase {

	private string $home = 'transfer-workflow-home';
	private string $host = 'transfer-workflow-host';
	private array $mail  = [];

	public function capture_mail( $pre, $atts ) {
		$this->mail[] = $atts;
		return true;
	}

	/**
	 * The two chronicles talk to each other over HTTP.
	 */
	public function loopback( $preempt, $args, $url ) {
		if ( strpos( $url, '/verify/' ) !== false ) {
			$response = rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/be/v1/verify/' . rawurldecode( substr( $url, strrpos( $url, '/' ) + 1 ) ) ) );
		} elseif ( strpos( $url, '/transfers/inbound' ) !== false ) {
			// The other site's request carries no login of ours.
			$as = get_current_user_id();
			wp_set_current_user( 0 );
			$request = new WP_REST_Request( 'POST', "/be/v1/{$this->host}/transfers/inbound" );
			foreach ( (array) json_decode( (string) ( $args['body'] ?? '{}' ), true ) as $key => $value ) {
				$request->set_param( $key, $value );
			}
			$response = rest_get_server()->dispatch( $request );
			wp_set_current_user( $as );
		} else {
			return $preempt;
		}
		return [
			'response' => [ 'code' => $response->get_status(), 'message' => '' ],
			'body'     => wp_json_encode( $response->get_data() ),
			'headers'  => [], 'cookies' => [], 'filename' => null,
		];
	}

	private function request( string $method, string $route, array $params = [] ) {
		$request = new WP_REST_Request( $method, $route );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		return rest_get_server()->dispatch( $request );
	}

	public function test_a_character_travels_with_a_storyteller_approving_each_side(): void {
		do_action( 'rest_api_init' );
		add_filter( 'pre_wp_mail', [ $this, 'capture_mail' ], 10, 2 );
		add_filter( 'pre_http_request', [ $this, 'loopback' ], 10, 3 );

		$home_id = (int) Game::create( [ 'slug' => $this->home, 'name' => 'Home by Night' ] );
		$host_id = (int) Game::create( [ 'slug' => $this->host, 'name' => 'Host by Night' ] );

		$home_st = self::factory()->user->create( [ 'role' => 'editor' ] );
		$host_st = self::factory()->user->create( [ 'role' => 'editor', 'user_email' => 'host-st@example.test' ] );
		$host_player = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		Game_Member::set_role( $home_id, $home_st, 'hst' );
		Game_Member::set_role( $host_id, $host_st, 'hst' );
		Game_Member::set_role( $host_id, $host_player, 'player' );

		$traveller = Character::create( [
			'name' => 'Road Warden', 'stack_slug' => 'vampire', 'owner_type' => 'chronicle',
			'owner_slug' => $this->home, 'status' => 'active',
		] );

		// The home Storyteller sends Road Warden to Host by Night.
		wp_set_current_user( $home_st );
		$sent = $this->request( 'POST', "/be/v1/{$this->home}/transfers/outbound", [
			'character_id' => $traveller, 'host_site' => home_url(), 'host_slug' => $this->host,
		] );
		$this->assertSame( 200, $sent->get_status(), wp_json_encode( $sent->get_data() ) );
		$home_row = $sent->get_data()['transfer'];
		$this->assertSame( 'pending', $home_row->state );

		// Host by Night has an offer, not a character; its Storyteller is told, its player is not.
		$offer = Transfer::find_open( Character::find( $traveller )->uuid, 'inbound' );
		$this->assertSame( 'offered', $offer->state );
		$this->assertSame( 0, Character::count_for_game( $this->host ) );
		$this->assertSame( [ 'host-st@example.test' ], array_column( $this->mail, 'to' ) );

		wp_set_current_user( $host_player );
		$this->assertSame( 403, $this->request( 'POST', "/be/v1/{$this->host}/transfers/{$offer->id}/accept" )->get_status() );

		// The host Storyteller reviews it, sees it is already on this site in another chronicle, and accepts a copy.
		wp_set_current_user( $host_st );
		$review = $this->request( 'GET', "/be/v1/{$this->host}/transfers/{$offer->id}/review" );
		$this->assertSame( 'uuid_elsewhere', $review->get_data()['preview']['duplicates'][0]['matched_by'] );
		$accepted = $this->request( 'POST', "/be/v1/{$this->host}/transfers/{$offer->id}/accept", [
			'resolutions' => [ 'duplicates' => [ 'Road Warden' => 'import_as_new' ] ],
		] );
		$this->assertSame( 200, $accepted->get_status(), wp_json_encode( $accepted->get_data() ) );
		$this->assertSame( 1, Character::count_for_game( $this->host ) );
		$this->assertSame( $this->home, Character::find( $traveller )->owner_slug, 'the home character never moves' );

		// The home Storyteller marks it received abroad.
		wp_set_current_user( $home_st );
		$this->assertSame( 'abroad', $this->request( 'POST', "/be/v1/{$this->home}/transfers/{$home_row->id}/acknowledge" )->get_data()->state );

		// The visit ends.
		wp_set_current_user( $host_st );
		$this->assertSame( 'sent_home', $this->request( 'POST', "/be/v1/{$this->host}/transfers/{$offer->id}/send-home" )->get_data()->state );

		remove_filter( 'pre_http_request', [ $this, 'loopback' ], 10 );
		remove_filter( 'pre_wp_mail', [ $this, 'capture_mail' ], 10 );
	}
}
