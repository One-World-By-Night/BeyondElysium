<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Attestation;
use BeyondElysium\Models\Character;
use BeyondElysium\Models\Game_Member;
use BeyondElysium\Models\Transfer;
use BeyondElysium\Services\Character_Exporter;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * 1.0.0-review F-003, F-005, F-006. Owner ruling 2026-09-14: "we need approval on BOTH sides."
 *
 * The home side was already a Storyteller's action. The host side was not: `inbound` - an
 * unauthenticated route - verified the payload against the claimed home site and then imported
 * it on the spot, overwriting any character on the whole install that carried the same uuid. A
 * payload needing review was parked in a one-hour transient nobody was told about. Now every
 * offer waits in its transfer row until a Storyteller of the receiving chronicle reviews and
 * accepts it, with the same explicit decisions an ordinary import needs; the host staff are
 * emailed; the home side's cancel revokes the offer; and the host can send a visitor home or
 * keep it.
 *
 * Both chronicles live on this one test install, so the character's uuid always exists here
 * already - at home. Tests that need the host to hold the character first move it there.
 */
class TransferHostApprovalThreadTest extends WP_UnitTestCase {

	private string $home_slug = 'thread-approval-home';
	private string $host_slug = 'thread-approval-host';
	private int $host_game_id;
	private int $character_id;
	private object $character;
	private array $mail = [];

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );

		global $wpdb;
		foreach ( [ $this->home_slug => 'Approval Home', $this->host_slug => 'Approval Host' ] as $slug => $name ) {
			$wpdb->insert( $wpdb->prefix . 'be_games', [
				'slug' => $slug, 'name' => $name, 'settings' => '{}',
				'created_by' => 1, 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ),
			] );
		}
		$this->host_game_id = (int) $wpdb->insert_id;

		$this->character_id = Character::create( [
			'name' => 'Travelling Vampire', 'stack_slug' => 'vampire',
			'owner_type' => 'chronicle', 'owner_slug' => $this->home_slug, 'status' => 'active',
		] );
		$this->character = Character::find( $this->character_id );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$this->mail = [];
		add_filter( 'pre_wp_mail', [ $this, 'capture_mail' ], 10, 2 );
		add_filter( 'pre_http_request', [ $this, 'loopback' ], 10, 3 );
	}

	public function tearDown(): void {
		remove_filter( 'pre_wp_mail', [ $this, 'capture_mail' ], 10 );
		remove_filter( 'pre_http_request', [ $this, 'loopback' ], 10 );
		parent::tearDown();
	}

	public function capture_mail( $pre, $atts ) {
		$this->mail[] = $atts;
		return true;
	}

	/** Answers the host's verify callback, and home's POST to the host, through real REST dispatch. */
	public function loopback( $preempt, $args, $url ) {
		if ( strpos( $url, '/verify/' ) !== false ) {
			$response = rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/be/v1/verify/' . rawurldecode( substr( $url, strrpos( $url, '/' ) + 1 ) ) ) );
		} elseif ( strpos( $url, '/transfers/inbound' ) !== false ) {
			$request = new WP_REST_Request( 'POST', "/be/v1/{$this->host_slug}/transfers/inbound" );
			foreach ( (array) json_decode( (string) ( $args['body'] ?? '{}' ), true ) as $key => $value ) {
				$request->set_param( $key, $value );
			}
			$response = rest_get_server()->dispatch( $request );
		} else {
			return $preempt;
		}
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

	private function transfer_document(): array {
		$export = Character_Exporter::export( $this->character_id, [ 'as_transfer' => true ] );
		preg_match( '/code=([A-Za-z0-9-]+)/', $export['xml'], $m );
		return [ 'xml' => $export['xml'], 'code' => $m[1] ];
	}

	private function offer( array $document ) {
		return $this->post( "/be/v1/{$this->host_slug}/transfers/inbound", [
			'payload'        => $document['xml'],
			'short_code'     => $document['code'],
			'home_site'      => home_url(),
			'home_slug'      => $this->home_slug,
			'home_chronicle' => 'Approval Home',
			'character_uuid' => $this->character->uuid,
		] );
	}

	/** Export at home, then leave only the host's own earlier copy of the character holding its uuid. */
	private function host_already_holds_the_character(): array {
		$document = $this->transfer_document();
		Character::delete( $this->character_id );
		$host_copy = Character::create( [
			'name' => 'Old Host Copy', 'stack_slug' => 'vampire', 'owner_type' => 'chronicle',
			'owner_slug' => $this->host_slug, 'status' => 'active', 'uuid' => $this->character->uuid,
		] );
		return [ $document, $host_copy ];
	}

	public function test_an_offer_waits_for_the_host_and_writes_no_character(): void {
		[ $document, $host_copy ] = $this->host_already_holds_the_character();

		$response = $this->offer( $document );

		$this->assertSame( 202, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$this->assertTrue( $response->get_data()['pending_review'] );
		$row = Transfer::find_open( $this->character->uuid, 'inbound' );
		$this->assertSame( 'offered', $row->state );
		$this->assertSame( 'Travelling Vampire', $row->character_name );
		$this->assertSame( $document['xml'], $row->payload );
		$this->assertSame( 'Old Host Copy', Character::find( $host_copy )->name, 'nothing is written before a host Storyteller accepts' );
	}

	public function test_the_host_storyteller_accepts_with_an_explicit_decision(): void {
		[ $document, $host_copy ] = $this->host_already_holds_the_character();
		$this->offer( $document );
		$row = Transfer::find_open( $this->character->uuid, 'inbound' );

		$undecided = $this->post( "/be/v1/{$this->host_slug}/transfers/{$row->id}/accept" );
		$this->assertSame( 409, $undecided->get_status() );
		$this->assertSame( 'unresolved_duplicates', $undecided->as_error()->get_error_code() );
		$this->assertSame( 'Old Host Copy', Character::find( $host_copy )->name );

		$review = rest_get_server()->dispatch( new WP_REST_Request( 'GET', "/be/v1/{$this->host_slug}/transfers/{$row->id}/review" ) );
		$this->assertSame( 200, $review->get_status() );
		$this->assertSame( 'uuid', $review->get_data()['preview']['duplicates'][0]['matched_by'] );

		$accepted = $this->post( "/be/v1/{$this->host_slug}/transfers/{$row->id}/accept", [
			'resolutions' => [ 'duplicates' => [ 'Travelling Vampire' => 'overwrite' ] ],
		] );

		$this->assertSame( 200, $accepted->get_status(), wp_json_encode( $accepted->get_data() ) );
		$this->assertSame( 'Travelling Vampire', Character::find( $host_copy )->name, 'updated in place' );
		global $wpdb;
		$this->assertSame( 1, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}be_characters WHERE uuid = %s", $this->character->uuid ) ), 'no second row for the uuid' );
		$after = Transfer::find( (int) $row->id );
		$this->assertSame( 'visiting', $after->state );
		$this->assertSame( $host_copy, (int) $after->character_id );
		$this->assertNull( $after->payload );
	}

	/**
	 * 1.0.0-review F-070. Accepting checks the offer is still offered, asks the home chronicle to
	 * verify it - a network call - and then imports. A second Storyteller's accept that finishes
	 * inside that call left the first one importing the character again. The offer is checked a
	 * second time, row-locked, before anything is written.
	 */
	public function test_an_accept_that_overlaps_one_that_already_finished_imports_nothing(): void {
		[ $document, $host_copy ] = $this->host_already_holds_the_character();
		$this->offer( $document );
		$row = Transfer::find_open( $this->character->uuid, 'inbound' );

		// The other Storyteller's accept lands while this one waits on the home chronicle.
		$overlap = function ( $preempt, $args, $url ) use ( $row ) {
			if ( strpos( $url, '/verify/' ) !== false && Transfer::find( (int) $row->id )->state === 'offered' ) {
				Transfer::transition( (int) $row->id, 'visiting', [ 'character_id' => 999999, 'payload' => null ] );
			}
			return $preempt;
		};
		add_filter( 'pre_http_request', $overlap, 5, 3 );
		$accepted = $this->post( "/be/v1/{$this->host_slug}/transfers/{$row->id}/accept", [
			'resolutions' => [ 'duplicates' => [ 'Travelling Vampire' => 'overwrite' ] ],
		] );
		remove_filter( 'pre_http_request', $overlap, 5 );

		$this->assertSame( 409, $accepted->get_status(), wp_json_encode( $accepted->get_data() ) );
		$this->assertSame( 'Old Host Copy', Character::find( $host_copy )->name, 'not imported a second time' );
		$this->assertSame( 999999, (int) Transfer::find( (int) $row->id )->character_id );
	}

	public function test_refusing_an_offer_writes_nothing(): void {
		$this->offer( $this->transfer_document() );
		$row = Transfer::find_open( $this->character->uuid, 'inbound' );

		$response = $this->post( "/be/v1/{$this->host_slug}/transfers/{$row->id}/refuse" );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'declined', Transfer::find( (int) $row->id )->state );
		$this->assertNull( Transfer::find( (int) $row->id )->payload );
		$this->assertSame( 0, Character::count_for_game( $this->host_slug ) );
	}

	public function test_a_character_that_lives_in_another_chronicle_is_never_overwritten(): void {
		$this->offer( $this->transfer_document() );
		$row = Transfer::find_open( $this->character->uuid, 'inbound' );

		$overwrite = $this->post( "/be/v1/{$this->host_slug}/transfers/{$row->id}/accept", [
			'resolutions' => [ 'duplicates' => [ 'Travelling Vampire' => 'overwrite' ] ],
		] );
		$this->assertSame( 409, $overwrite->get_status() );
		$this->assertSame( $this->home_slug, Character::find( $this->character_id )->owner_slug );

		$copy = $this->post( "/be/v1/{$this->host_slug}/transfers/{$row->id}/accept", [
			'resolutions' => [ 'duplicates' => [ 'Travelling Vampire' => 'import_as_new' ] ],
		] );
		$this->assertSame( 200, $copy->get_status(), wp_json_encode( $copy->get_data() ) );
		$visitor = Character::find( (int) Transfer::find( (int) $row->id )->character_id );
		$this->assertSame( $this->host_slug, $visitor->owner_slug );
		$this->assertNotSame( $this->character->uuid, $visitor->uuid );
		$this->assertSame( $this->character->uuid, Character::find( $this->character_id )->uuid, 'the home character is untouched' );
	}

	public function test_cancelling_at_home_revokes_the_offer_so_the_host_cannot_accept_it(): void {
		$initiated = $this->post( "/be/v1/{$this->home_slug}/transfers/outbound", [
			'character_id' => $this->character_id, 'host_site' => home_url(), 'host_slug' => $this->host_slug,
		] );
		$home_row = $initiated->get_data()['transfer'];
		$this->assertSame( 'pending', $home_row->state, 'the host has not accepted yet' );
		$offer = Transfer::find_open( $this->character->uuid, 'inbound' );
		$this->assertSame( 'offered', $offer->state );

		$this->post( "/be/v1/{$this->home_slug}/transfers/{$home_row->id}/decline" );
		$this->assertNotNull( Attestation::find( (int) Transfer::find( (int) $home_row->id )->attestation_id )->revoked_at );

		$accept = $this->post( "/be/v1/{$this->host_slug}/transfers/{$offer->id}/accept", [
			'resolutions' => [ 'duplicates' => [ 'Travelling Vampire' => 'import_as_new' ] ],
		] );
		$this->assertSame( 400, $accept->get_status() );
		$this->assertSame( 'verify_failed', $accept->as_error()->get_error_code() );
		$this->assertSame( 'offered', Transfer::find( (int) $offer->id )->state );
		$this->assertSame( 0, Character::count_for_game( $this->host_slug ) );
	}

	public function test_the_host_sends_a_visitor_home_or_keeps_it(): void {
		$visiting = function (): int {
			return Transfer::create( [
				'character_uuid' => wp_generate_uuid4(), 'direction' => 'inbound', 'state' => 'visiting',
				'home_slug' => 'far-home', 'home_site' => 'https://far.example', 'home_chronicle' => 'Far Home',
				'host_slug' => $this->host_slug, 'host_site' => home_url(), 'host_chronicle' => 'Approval Host',
				'payload_hash' => str_repeat( 'd', 64 ), 'initiated_by' => 1,
			] );
		};
		$first  = $visiting();
		$second = $visiting();

		$this->assertSame( 'sent_home', $this->post( "/be/v1/{$this->host_slug}/transfers/{$first}/send-home" )->get_data()->state );
		$this->assertSame( 'retained', $this->post( "/be/v1/{$this->host_slug}/transfers/{$second}/retain" )->get_data()->state );
		$this->assertSame( 409, $this->post( "/be/v1/{$this->host_slug}/transfers/{$first}/retain" )->get_status() );
	}

	public function test_the_host_storytellers_are_told_an_offer_is_waiting(): void {
		$hst = self::factory()->user->create( [ 'role' => 'editor', 'user_email' => 'host-hst@example.test' ] );
		Game_Member::set_role( $this->host_game_id, $hst, 'hst' );
		$player = self::factory()->user->create( [ 'role' => 'subscriber', 'user_email' => 'host-player@example.test' ] );
		Game_Member::set_role( $this->host_game_id, $player, 'player' );

		$this->offer( $this->transfer_document() );

		$this->assertCount( 1, $this->mail );
		$this->assertSame( 'host-hst@example.test', $this->mail[0]['to'] );
		$this->assertStringContainsString( 'Travelling Vampire', $this->mail[0]['message'] );
	}

	public function test_a_second_offer_waits_behind_the_first_instead_of_failing(): void {
		$document = $this->transfer_document();
		$this->offer( $document );

		$again = $this->offer( $document );

		$this->assertSame( 409, $again->get_status() );
		$this->assertSame( 'already_offered', $again->as_error()->get_error_code() );
	}

	public function test_only_the_host_chronicles_storytellers_review_offers(): void {
		$this->offer( $this->transfer_document() );
		$row    = Transfer::find_open( $this->character->uuid, 'inbound' );
		$player = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		Game_Member::set_role( $this->host_game_id, $player, 'player' );
		wp_set_current_user( $player );

		$this->assertSame( 403, rest_get_server()->dispatch( new WP_REST_Request( 'GET', "/be/v1/{$this->host_slug}/transfers/{$row->id}/review" ) )->get_status() );
		$this->assertSame( 403, $this->post( "/be/v1/{$this->host_slug}/transfers/{$row->id}/accept" )->get_status() );
		$this->assertSame( 403, $this->post( "/be/v1/{$this->host_slug}/transfers/{$row->id}/refuse" )->get_status() );
	}
}
