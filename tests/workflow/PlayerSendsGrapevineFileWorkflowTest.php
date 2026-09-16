<?php

namespace BeyondElysium\Tests\Workflow;

use BeyondElysium\Models\Character;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Game_Member;
use BeyondElysium\Models\Transfer;
use BeyondElysium\Services\Character_Exporter;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * A player sends their own Grapevine file straight to a chronicle, with no Storyteller on the
 * sending end at all (F-122, 1.0.0-review). A signed-in non-member sends their verified sheet
 * as a visitor; the chronicle's HST sees it waiting, reviews it - checking the embedded
 * verification code and keeping one trait the catalog doesn't recognize exactly as written -
 * and accepts. The sender is now a player there, sees the character on their own list, and the
 * character itself is a real visit; the HST sends it home.
 *
 * @see BE_PROCESS/design/player-grapevine-file-design.md §14
 */
class PlayerSendsGrapevineFileWorkflowTest extends WP_UnitTestCase {

	private string $host = 'grapevine-file-workflow-host';
	private array $mail  = [];

	public function capture_mail( $pre, $atts ) {
		$this->mail[] = $atts;
		return true;
	}

	/** The verification check calls out to the issuing site's own /verify/{code} route - here, this same site, over a real internal REST dispatch rather than a genuine second install. */
	public function loopback( $preempt, $args, $url ) {
		if ( strpos( $url, '/verify/' ) === false ) {
			return $preempt;
		}
		$code     = rawurldecode( substr( $url, strrpos( $url, '/' ) + 1 ) );
		$response = rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/be/v1/verify/' . $code ) );
		return [
			'response' => [ 'code' => $response->get_status(), 'message' => '' ],
			'body'     => wp_json_encode( $response->get_data() ),
			'headers'  => [], 'cookies' => [], 'filename' => null,
		];
	}

	private function dispatch( WP_REST_Request $request ) {
		return rest_get_server()->dispatch( $request );
	}

	private function post( string $route, array $params = [] ) {
		$request = new WP_REST_Request( 'POST', $route );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		return $this->dispatch( $request );
	}

	public function test_a_visitor_sends_a_file_is_reviewed_and_accepted_and_sent_home(): void {
		do_action( 'rest_api_init' );
		add_filter( 'pre_wp_mail', [ $this, 'capture_mail' ], 10, 2 );
		add_filter( 'pre_http_request', [ $this, 'loopback' ], 10, 3 );

		$host_id = (int) Game::create( [ 'slug' => $this->host, 'name' => 'Host by Night' ] );
		$hst     = self::factory()->user->create( [ 'role' => 'editor', 'user_email' => 'grapevine-file-hst@example.test' ] );
		Game_Member::set_role( $host_id, $hst, 'hst' );

		$sender = self::factory()->user->create( [ 'role' => 'subscriber', 'user_email' => 'grapevine-file-sender@example.test' ] );

		// The player's own character, wherever it actually lives - a "Not A Real Discipline At
		// All" held power stands in for a homebrew Discipline this chronicle's catalog has never
		// seeded (matching this codebase's own "keep_custom" fixture convention: a tiered_power
		// name with no catalog match has no auto-custom fallback the way a trait_list block can,
		// so it lands genuinely unresolved, not silently accepted), so review has a real unmatched
		// trait to resolve rather than a clean, uninteresting duplicate-free file.
		$source_id = Character::create( [
			'name' => 'Traveling Player', 'stack_slug' => 'vampire', 'owner_type' => 'chronicle',
			'owner_slug' => 'grapevine-file-workflow-source', 'status' => 'active',
			'sheet_data' => [ 'vampire-disciplines' => [ [ 'name' => 'Not A Real Discipline At All', 'level' => 3 ] ] ],
		] );
		$xml = Character_Exporter::export( $source_id, [ 'hide_st' => true, 'verify' => true ] )['xml'];

		// The player sends their verified sheet in, asking to visit for a game.
		wp_set_current_user( $sender );
		$tmp = tempnam( sys_get_temp_dir(), 'be-grapevine-file-workflow' );
		file_put_contents( $tmp, $xml );
		$send = new WP_REST_Request( 'POST', "/be/v1/{$this->host}/submissions" );
		$send->set_file_params( [
			'file' => [ 'tmp_name' => $tmp, 'name' => 'sheet.gex', 'error' => 0, 'size' => strlen( $xml ), 'type' => 'application/octet-stream' ],
		] );
		$send->set_param( 'arrival', 'visiting' );
		$send->set_param( 'home_chronicle', 'Some Other Chronicle by Night' );
		$sent = $this->dispatch( $send );
		$this->assertSame( 201, $sent->get_status(), wp_json_encode( $sent->get_data() ) );
		$submission_id = (int) $sent->get_data()['id'];

		$this->assertContains( 'grapevine-file-hst@example.test', array_column( $this->mail, 'to' ), 'every HST is told a sheet is waiting' );

		// The HST sees it waiting.
		wp_set_current_user( $hst );
		$waiting = $this->dispatch( new WP_REST_Request( 'GET', "/be/v1/{$this->host}/submissions" ) );
		$this->assertSame( 200, $waiting->get_status() );
		$this->assertCount( 1, $waiting->get_data() );

		// Reviewing it shows the one trait the catalog can't place, and the automatic
		// verification check against the sheet's own issuing site.
		$review = $this->dispatch( new WP_REST_Request( 'GET', "/be/v1/{$this->host}/submissions/{$submission_id}/review" ) );
		$this->assertSame( 200, $review->get_status(), wp_json_encode( $review->get_data() ) );
		$unresolved = $review->get_data()['preview']['unresolved'];
		$this->assertNotEmpty( $unresolved, 'the homebrew merit must genuinely be unresolved for this test to mean anything' );
		$entry = $unresolved[0];

		$verification = $this->dispatch( new WP_REST_Request( 'GET', "/be/v1/{$this->host}/submissions/{$submission_id}/verification" ) );
		$this->assertSame( 200, $verification->get_status(), wp_json_encode( $verification->get_data() ) );
		$this->assertSame( 'unchanged', $verification->get_data()['status'], 'a fresh, untouched export should read back as unchanged' );

		// The HST keeps the homebrew merit as written and accepts, as a visitor - the sender's
		// own choice, not overridden here.
		$accepted = $this->post( "/be/v1/{$this->host}/submissions/{$submission_id}/accept", [
			'resolutions' => [
				'traits' => [
					[ 'character' => $entry['character'], 'block' => $entry['block'], 'raw' => $entry['raw'], 'action' => 'keep_custom' ],
				],
			],
		] );
		$this->assertSame( 200, $accepted->get_status(), wp_json_encode( $accepted->get_data() ) );

		$character = Character::find( (int) $accepted->get_data()['character']['id'] );
		$this->assertSame( $sender, (int) $character->wp_user_id );
		$this->assertSame( 'active', $character->status );
		$this->assertSame( 0, (int) $character->is_npc );
		$this->assertNull( $character->narrator );
		$held = $character->sheet_data['vampire-disciplines'];
		$this->assertSame( 'Not A Real Discipline At All', $held[0]['name'] );
		$this->assertTrue( $held[0]['custom'] );

		// The sender is now a player at the host chronicle - visiting, not a hand-built
		// member - and sees the character on their own list there.
		$this->assertSame( 'player', Game_Member::find( $host_id, $sender )->role );
		wp_set_current_user( $sender );
		$my_characters = $this->dispatch( new WP_REST_Request( 'GET', "/be/v1/{$this->host}/my/characters" ) );
		$this->assertSame( [ $character->id ], array_column( $my_characters->get_data(), 'id' ) );

		// The character itself is a real visit, carrying the Visiting badge.
		$transfer = Transfer::find_open( $character->uuid, 'inbound' );
		$this->assertNotNull( $transfer );
		$this->assertSame( 'visiting', $transfer->state );
		$badges = Transfer::open_states_for_game( $this->host );
		$this->assertSame( 'visiting', $badges[ $character->uuid ]['state'] );

		$this->assertContains( 'grapevine-file-sender@example.test', array_column( $this->mail, 'to' ), 'the sender is told their sheet was accepted' );

		// The HST sends the visitor home.
		wp_set_current_user( $hst );
		$sent_home = $this->post( "/be/v1/{$this->host}/transfers/{$transfer->id}/send-home" );
		$this->assertSame( 'sent_home', $sent_home->get_data()->state );

		remove_filter( 'pre_http_request', [ $this, 'loopback' ], 10 );
		remove_filter( 'pre_wp_mail', [ $this, 'capture_mail' ], 10 );
	}
}
