<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Game;
use BeyondElysium\Models\Game_Member;
use BeyondElysium\Services\Bylaws;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * The bylaws reference list, refresh-from-council, and file-upload routes.
 */
class BylawRoutesThreadTest extends WP_UnitTestCase {

	private string $slug = 'thread-bylaw-routes';
	private int $hst;
	private int $narrator;
	private int $admin;

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );

		Game::create( [ 'slug' => $this->slug, 'name' => 'Bylaw Routes Game' ] );
		$game_id = (int) Game::find_by_slug( $this->slug )->id;

		$this->hst      = self::factory()->user->create( [ 'role' => 'editor' ] );
		$this->narrator  = self::factory()->user->create( [ 'role' => 'editor' ] );
		$this->admin    = self::factory()->user->create( [ 'role' => 'administrator' ] );
		Game_Member::set_role( $game_id, $this->hst, 'hst' );
		Game_Member::set_role( $game_id, $this->narrator, 'narrator' );

		Bylaws::upload( [
			'rules' => [
				[ 'clause_id' => 1, 'path' => '10.e.v', 'subject' => 'True Faith', 'pc' => 'Coordinator Notify', 'npc' => 'Unregulated', 'coordinators' => [ 'Hunter' ] ],
				[ 'clause_id' => 2, 'path' => '10.f.ii', 'subject' => 'Something Else', 'pc' => 'Disallowed', 'npc' => 'Disallowed', 'coordinators' => [] ],
			],
			'attachments' => [ [ 'clause_id' => 1, 'family' => 'merits', 'name' => 'True Faith' ] ],
		] );
	}

	public function tearDown(): void {
		Bylaws::reset_cache();
		delete_option( Bylaws::OVERRIDE_OPTION );
		remove_all_filters( 'pre_http_request' );
		parent::tearDown();
	}

	private function dispatch( int $user, string $method, string $route, array $body = [] ): \WP_REST_Response {
		wp_set_current_user( $user );
		$request = new WP_REST_Request( $method, $route );
		if ( $body ) {
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( wp_json_encode( $body ) );
		}
		return rest_get_server()->dispatch( $request );
	}

	public function test_get_bylaws_lists_every_rule_with_its_attachments(): void {
		$response = $this->dispatch( $this->hst, 'GET', "/be/v1/{$this->slug}/bylaws" );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$rows = array_filter( $data, 'is_array' );
		$this->assertCount( 2, $rows );

		$true_faith = null;
		foreach ( $rows as $row ) {
			if ( $row['clause_id'] === 1 ) {
				$true_faith = $row;
			}
		}
		$this->assertNotNull( $true_faith );
		$this->assertCount( 1, $true_faith['attachments'] );
		$this->assertSame( 'https://council.owbn.net/?p=1', $true_faith['link'] );
	}

	public function test_get_bylaws_filters_by_search(): void {
		$response = $this->dispatch( $this->hst, 'GET', "/be/v1/{$this->slug}/bylaws?search=true+faith" );

		$rows = array_filter( $response->get_data(), 'is_array' );
		$this->assertCount( 1, $rows );
	}

	public function test_get_bylaws_filters_by_attached(): void {
		$attached = array_filter( $this->dispatch( $this->hst, 'GET', "/be/v1/{$this->slug}/bylaws?attached=true" )->get_data(), 'is_array' );
		$this->assertCount( 1, $attached );

		$unattached = array_filter( $this->dispatch( $this->hst, 'GET', "/be/v1/{$this->slug}/bylaws?attached=false" )->get_data(), 'is_array' );
		$this->assertCount( 1, $unattached );
	}

	public function test_a_narrator_cannot_read_the_bylaws_list(): void {
		$response = $this->dispatch( $this->narrator, 'GET', "/be/v1/{$this->slug}/bylaws" );

		$this->assertSame( 403, $response->get_status() );
	}

	public function test_get_bylaws_response_encodes_as_a_json_array(): void {
		$response = $this->dispatch( $this->hst, 'GET', "/be/v1/{$this->slug}/bylaws" );

		$encoded = wp_json_encode( $response->get_data() );

		$this->assertStringStartsWith( '[', $encoded );
	}

	public function test_refresh_pulls_from_council_and_keeps_surviving_attachments(): void {
		add_filter( 'pre_http_request', static function () {
			return [
				'headers'  => [],
				'body'     => wp_json_encode( [
					[
						'id'       => 1,
						'slug'     => '10_e_v',
						'link'     => 'https://council.owbn.net/en/bylaw-clause/character/10_e_v/',
						'content'  => [ 'rendered' => '<p>True Faith – PC: Coordinator Notify – NPC: Unregulated – Coordinator: Hunter</p>' ],
						'modified' => '2026-10-01T00:00:00',
					],
					[
						'id'       => 3,
						'slug'     => '10_g_i',
						'link'     => 'https://council.owbn.net/en/bylaw-clause/character/10_g_i/',
						'content'  => [ 'rendered' => '<p>A Brand New Rule – PC: Disallowed – NPC: Unregulated</p>' ],
						'modified' => '2026-10-02T00:00:00',
					],
				] ),
				'response' => [ 'code' => 200, 'message' => 'OK' ],
				'cookies'  => [],
				'filename' => null,
			];
		}, 10, 3 );

		$response = $this->dispatch( $this->hst, 'POST', "/be/v1/{$this->slug}/bylaws/refresh" );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 2, $data['rule_count'] );
		$this->assertSame( 1, $data['attachment_count'], 'clause 1 keeps its attachment, clause 2 is gone and clause 3 is new' );
		$this->assertSame( 1, $data['added'] );
		$this->assertSame( 1, $data['removed'] );

		$effective = Bylaws::effective();
		$this->assertSame( [ 1 ], array_column( $effective['attachments'], 'clause_id' ) );
	}

	public function test_refresh_reports_a_network_failure_without_changing_anything(): void {
		add_filter( 'pre_http_request', static fn() => new \WP_Error( 'http_request_failed', 'blocked' ), 10, 3 );

		$response = $this->dispatch( $this->hst, 'POST', "/be/v1/{$this->slug}/bylaws/refresh" );

		$this->assertSame( 502, $response->get_status() );
		$this->assertCount( 2, Bylaws::effective()['rules'], 'nothing changed on a failed refresh' );
	}

	public function test_upload_is_an_administrators_route_not_the_storytellers(): void {
		$response = $this->dispatch( $this->hst, 'POST', '/be/v1/bylaws/upload' );

		$this->assertSame( 403, $response->get_status() );
	}

	public function test_upload_refuses_a_file_with_no_rules_array(): void {
		wp_set_current_user( $this->admin );
		$request = new WP_REST_Request( 'POST', '/be/v1/bylaws/upload' );
		$request->set_file_params( [ 'file' => [ 'tmp_name' => $this->write_temp_json( [ 'attachments' => [] ] ) ] ] );

		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 400, $response->get_status() );
		$this->assertCount( 2, Bylaws::effective()['rules'], 'a refused upload changes nothing' );
	}

	public function test_upload_accepts_a_well_formed_file(): void {
		wp_set_current_user( $this->admin );
		$request = new WP_REST_Request( 'POST', '/be/v1/bylaws/upload' );
		$request->set_file_params( [ 'file' => [ 'tmp_name' => $this->write_temp_json( [
			'rules'       => [ [ 'clause_id' => 9, 'path' => '1.a', 'subject' => 'A Rule', 'pc' => 'Disallowed', 'npc' => 'Disallowed', 'coordinators' => [] ] ],
			'attachments' => [],
		] ) ] ] );

		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertCount( 1, Bylaws::effective()['rules'] );
	}

	private function write_temp_json( array $data ): string {
		$path = sys_get_temp_dir() . '/be-bylaw-upload-test-' . wp_generate_password( 8, false ) . '.json';
		file_put_contents( $path, wp_json_encode( $data ) );
		return $path;
	}
}
