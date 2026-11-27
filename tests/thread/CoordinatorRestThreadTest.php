<?php

namespace BeyondElysium\Tests\Thread;

require_once __DIR__ . '/../support/CoordinatorTestFixture.php';

use BeyondElysium\Database\Manager;
use BeyondElysium\Models\Change;
use BeyondElysium\Models\Character;
use BeyondElysium\Models\Coordinator_Request;
use BeyondElysium\Services\Change_Engine;
use BeyondElysium\Tests\Support\CoordinatorTestFixture;
use WP_UnitTestCase;

/**
 * The routes a Storyteller uses to link a character, search the Toolkit, send and check: who may, what they get, and
 * that the key never leaves.
 */
class CoordinatorRestThreadTest extends WP_UnitTestCase {

	use CoordinatorTestFixture;

	private int $character_id = 0;

	public function setUp(): void {
		parent::setUp();
		global $be_test_coordinators;
		$be_test_coordinators = [ [ 'slug' => 'assamite', 'title' => 'Assamite Coordinator' ], [ 'slug' => 'brujah', 'title' => 'Brujah Coordinator' ] ];
		$this->make_chronicle( 'chronicle/kony', [ 'coordinator_send' => true ] );
		$this->connect_toolkit();
		$this->fake_toolkit();
		$this->character_id = Character::create( [
			'name' => 'Isolde Marchetti', 'stack_slug' => 'vampire',
			'owner_type' => 'chronicle', 'owner_slug' => $this->slug, 'wp_user_id' => $this->player_id,
			'sheet_data' => [ 'vampire-merits' => [] ],
		] );
		Character::update_xp( $this->character_id, 20, 20 );
	}

	public function tearDown(): void {
		global $be_test_coordinators;
		$be_test_coordinators = [];
		$this->connect_toolkit( false );
		remove_all_filters( 'pre_http_request' );
		parent::tearDown();
	}

	private function waiting(): int {
		$id = Change_Engine::submit( $this->character_id, [
			'change_type' => 'add_trait', 'category' => 'vampire-merits',
			'change_data' => [ 'block_slug' => 'vampire-merits', 'trait' => [ 'name' => 'Dual-Blooded', 'count' => 2 ] ], 'xp_cost' => 2,
		], $this->player_id );
		Manager::update( 'character_changes', [ 'reason' => 'Coordinator Approval — Assamite Coordinator [OWBN Character Bylaws 10.m.v.10, clause 8992]' ], [ 'id' => $id ] );
		return $id;
	}

	private function link(): void {
		Character::set_oat_link( $this->character_id, 'a3f1c0de-0000-4000-8000-000000000001' );
	}

	/**
	 * @return array<string,mixed>
	 */
	private function send_body( int $change ): array {
		return [ 'character_id' => $this->character_id, 'change_ids' => [ $change ], 'rules' => [ [ 'id' => 4125, 'pc_level' => 'coordinator_approval', 'npc_level' => 'coordinator_approval' ] ], 'coordinators' => [ 'assamite' ], 'note' => 'Please look.' ];
	}

	public function test_the_status_tells_a_storyteller_how_a_send_goes_here(): void {
		$data = $this->rest( $this->hst_id, 'GET', "{$this->slug}/coordinator" )->get_data();

		$this->assertSame( 'live', $data['mode'] );
		$this->assertSame( 'archivist.example.test', $data['host'] );
		$this->assertSame( 'kony', $data['chronicle'] );
		$this->assertSame( 'Hst', $data['sender']['name'] );
		$this->assertSame( 'hst@example.org', $data['sender']['email'] );
	}

	public function test_the_status_says_off_when_the_switch_is_off(): void {
		$this->set_settings( [ 'coordinator_send' => false ] );

		$this->assertNull( $this->rest( $this->hst_id, 'GET', "{$this->slug}/coordinator" )->get_data()['mode'] );
	}

	public function test_an_assistant_storyteller_may_use_every_route(): void {
		$this->link();
		$change = $this->waiting();

		$this->assertSame( 200, $this->rest( $this->ast_id, 'GET', "{$this->slug}/coordinator" )->get_status() );
		$this->assertSame( 200, $this->rest( $this->ast_id, 'POST', "{$this->slug}/coordinator/send", $this->send_body( $change ) )->get_status() );
	}

	public function test_a_narrator_and_a_player_may_use_none(): void {
		$this->link();
		$change = $this->waiting();

		foreach ( [ $this->narrator_id, $this->player_id ] as $who ) {
			$this->assertContains( $this->rest( $who, 'GET', "{$this->slug}/coordinator" )->get_status(), [ 401, 403 ] );
			$this->assertContains( $this->rest( $who, 'POST', "{$this->slug}/coordinator/send", $this->send_body( $change ) )->get_status(), [ 401, 403 ] );
			$this->assertContains( $this->rest( $who, 'PUT', "{$this->slug}/characters/{$this->character_id}/toolkit-link", [ 'uuid' => 'a3f1c0de-0000-4000-8000-000000000009' ] )->get_status(), [ 401, 403 ] );
			$this->assertContains( $this->rest( $who, 'GET', "{$this->slug}/coordinator/rules?term=dual" )->get_status(), [ 401, 403 ] );
		}
		$this->assertSame( [], $this->toolkit_calls, 'Nothing reached the Toolkit.' );
	}

	public function test_a_storyteller_links_and_unlinks_a_character(): void {
		$uuid = 'a3f1c0de-0000-4000-8000-000000000001';

		$put = $this->rest( $this->hst_id, 'PUT', "{$this->slug}/characters/{$this->character_id}/toolkit-link", [ 'uuid' => $uuid ] );
		$this->assertSame( 200, $put->get_status() );
		$this->assertSame( $uuid, $put->get_data()['uuid'] );
		$this->assertSame( $uuid, Character::find( $this->character_id )->oat_character_uuid );

		$get = $this->rest( $this->hst_id, 'GET', "{$this->slug}/characters/{$this->character_id}/toolkit-link" );
		$this->assertSame( $uuid, $get->get_data()['uuid'] );

		$this->assertSame( 200, $this->rest( $this->hst_id, 'DELETE', "{$this->slug}/characters/{$this->character_id}/toolkit-link" )->get_status() );
		$this->assertNull( Character::find( $this->character_id )->oat_character_uuid );
	}

	public function test_a_link_must_be_a_uuid(): void {
		foreach ( [ '', 'isolde', '12345', 'zzzzzzzz-0000-4000-8000-000000000001' ] as $bad ) {
			$response = $this->rest( $this->hst_id, 'PUT', "{$this->slug}/characters/{$this->character_id}/toolkit-link", [ 'uuid' => $bad ] );
			$this->assertSame( 400, $response->get_status(), "'{$bad}'" );
		}
	}

	public function test_a_character_of_another_chronicle_is_not_found(): void {
		$other = Character::create( [ 'name' => 'Elsewhere', 'stack_slug' => 'vampire', 'owner_type' => 'chronicle', 'owner_slug' => 'some-other-chronicle', 'wp_user_id' => $this->player_id, 'sheet_data' => [] ] );

		$this->assertSame( 404, $this->rest( $this->hst_id, 'PUT', "{$this->slug}/characters/{$other}/toolkit-link", [ 'uuid' => 'a3f1c0de-0000-4000-8000-000000000001' ] )->get_status() );
	}

	public function test_the_toolkit_search_runs_as_the_storyteller_and_marks_an_exact_name(): void {
		$this->toolkit_search['characters/search'] = [
			[ 'uuid' => 'a3f1c0de-0000-4000-8000-000000000001', 'character_name' => 'Isolde Marchetti', 'chronicle_slug' => 'kony', 'chronicle_title' => 'Kings of New York', 'player_name' => 'Pat', 'pc_npc' => 'pc' ],
			[ 'uuid' => 'a3f1c0de-0000-4000-8000-000000000002', 'character_name' => 'Isolde Marchetti-Roy', 'chronicle_slug' => 'kony', 'chronicle_title' => 'Kings of New York', 'player_name' => 'Sam', 'pc_npc' => 'npc' ],
		];

		$response = $this->rest( $this->hst_id, 'GET', "{$this->slug}/characters/{$this->character_id}/toolkit-search?term=Isolde" );

		$this->assertSame( 200, $response->get_status() );
		$rows = $response->get_data()['results'];
		$this->assertSame( [ true, false ], array_column( $rows, 'exact' ) );
		$this->assertSame( 'Isolde Marchetti', $rows[0]['name'] );
		$this->assertSame( 'Pat', $rows[0]['player'] );
		$call = $this->toolkit_calls_to( 'characters/search' )[0];
		$this->assertSame( 'hst@example.org', $call['headers']['x-oat-user-email'] );
		$this->assertSame( 'Isolde', $call['body']['term'] );
		$this->assertSame( 'staff', $call['body']['scope'] );
		$this->assertSame( 'kony', $call['body']['chronicle_slug'] );
	}

	public function test_a_toolkit_search_with_less_than_two_letters_asks_nothing(): void {
		$response = $this->rest( $this->hst_id, 'GET', "{$this->slug}/characters/{$this->character_id}/toolkit-search?term=I" );

		$this->assertSame( [], $response->get_data()['results'] );
		$this->assertSame( [], $this->toolkit_calls );
	}

	public function test_a_toolkit_that_does_not_answer_a_search_says_so(): void {
		$this->toolkit_fail['characters/search'] = 'timeout';

		$response = $this->rest( $this->hst_id, 'GET', "{$this->slug}/characters/{$this->character_id}/toolkit-search?term=Isolde" );

		$this->assertSame( 502, $response->get_status() );
		$this->assertStringContainsString( "didn't answer", $response->get_data()['message'] );
	}

	public function test_the_rule_search_returns_the_toolkits_rules_with_their_levels(): void {
		$this->toolkit_search['rules/search'] = [
			[ 'id' => 4125, 'label' => 'Assamite — Merits — Dual-Blooded', 'genre' => 'assamite', 'category' => 'Merits', 'subcategory' => '', 'condition' => 'Dual-Blooded', 'pc_level' => 'coordinator_approval', 'npc_level' => 'coordinator_approval', 'coordinator' => 'assamite' ],
		];

		$rows = $this->rest( $this->hst_id, 'GET', "{$this->slug}/coordinator/rules?term=dual" )->get_data()['results'];

		$this->assertSame( 4125, $rows[0]['id'] );
		$this->assertSame( 'Assamite — Merits — Dual-Blooded', $rows[0]['label'] );
		$this->assertSame( 'coordinator_approval', $rows[0]['pc_level'] );
		$this->assertSame( 'assamite', $rows[0]['coordinator'] );
		$this->assertSame( 'dual', $this->toolkit_calls_to( 'rules/search' )[0]['body']['q'] );
	}

	public function test_the_offices_come_from_owbn_core_by_name(): void {
		$rows = $this->rest( $this->hst_id, 'GET', "{$this->slug}/coordinator/offices" )->get_data()['offices'];

		$this->assertSame( [ [ 'slug' => 'assamite', 'name' => 'Assamite Coordinator' ], [ 'slug' => 'brujah', 'name' => 'Brujah Coordinator' ] ], $rows );
	}

	public function test_a_send_files_the_change_and_answers_with_its_request(): void {
		$this->link();
		$change = $this->waiting();

		$response = $this->rest( $this->hst_id, 'POST', "{$this->slug}/coordinator/send", $this->send_body( $change ) );

		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$data = $response->get_data();
		$this->assertSame( 'live', $data['mode'] );
		$this->assertSame( 'sent', $data['results'][0]['state'] );
		$this->assertSame( 33012, $data['results'][0]['request']['oat_entry_id'] );
		$this->assertStringContainsString( 'oat-entry', (string) $data['results'][0]['request']['entry_url'] );
		$this->assertSame( 'With the chronicle’s Storyteller', $data['results'][0]['request']['step_label'] );
	}

	public function test_a_refusal_comes_back_as_an_error_with_the_toolkits_words(): void {
		$this->link();
		$change = $this->waiting();
		$this->toolkit_fail['submit'] = [ 422, [ 'code' => 'oat_character_not_yours', 'message' => "You can't file this for that character." ] ];

		$response = $this->rest( $this->hst_id, 'POST', "{$this->slug}/coordinator/send", $this->send_body( $change ) );

		$this->assertSame( 422, $response->get_status() );
		$this->assertSame( 'oat_character_not_yours', $response->get_data()['code'] );
		$this->assertSame( "You can't file this for that character.", $response->get_data()['message'] );
	}

	public function test_no_answer_comes_back_as_unconfirmed_with_the_row_to_send_again(): void {
		$this->link();
		$change = $this->waiting();
		$this->toolkit_fail['submit'] = 'timeout';

		$response = $this->rest( $this->hst_id, 'POST', "{$this->slug}/coordinator/send", $this->send_body( $change ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'unconfirmed', $response->get_data()['results'][0]['state'] );
		$this->assertSame( 'unconfirmed', Coordinator_Request::for_change( $change )->state );
	}

	public function test_an_unlinked_character_is_a_409_before_anything_is_sent(): void {
		$change = $this->waiting();

		$response = $this->rest( $this->hst_id, 'POST', "{$this->slug}/coordinator/send", $this->send_body( $change ) );

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'oat_character_not_linked', $response->get_data()['code'] );
	}

	public function test_a_send_needs_its_parts(): void {
		$this->link();

		$this->assertSame( 400, $this->rest( $this->hst_id, 'POST', "{$this->slug}/coordinator/send", [ 'character_id' => $this->character_id ] )->get_status() );
		$this->assertSame( 404, $this->rest( $this->hst_id, 'POST', "{$this->slug}/coordinator/send", [ 'character_id' => 999999, 'change_ids' => [ 1 ] ] )->get_status() );
	}

	public function test_the_demo_answers_with_a_preview(): void {
		$this->set_settings( [ 'demo' => [ 'on' => true ] ] );
		$this->connect_toolkit( false );
		$change = $this->waiting();

		$response = $this->rest( $this->hst_id, 'POST', "{$this->slug}/coordinator/send", $this->send_body( $change ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'preview', $response->get_data()['mode'] );
		$this->assertSame( [], $this->toolkit_calls );
		$this->assertSame( 'pending', Change::find( $change )->status );
	}

	public function test_check_now_reads_the_request_and_returns_where_it_stands(): void {
		$this->link();
		$change = $this->waiting();
		$this->rest( $this->hst_id, 'POST', "{$this->slug}/coordinator/send", $this->send_body( $change ) );
		$request = Coordinator_Request::for_change( $change );
		$this->toolkit_moves( 33012, 'coord_review' );

		$response = $this->rest( $this->ast_id, 'POST', "{$this->slug}/coordinator/requests/{$request->id}/check" );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'coord_review', $response->get_data()['request']['step'] );
		$this->assertSame( 'With the Coordinator', $response->get_data()['request']['step_label'] );
		$this->assertSame( 'ast@example.org', $this->toolkit_calls_to( 'entry/33012' )[0]['headers']['x-oat-user-email'] );
	}

	public function test_check_now_on_a_request_of_another_chronicle_is_not_found(): void {
		$id = Coordinator_Request::create( [ 'uuid' => wp_generate_uuid4(), 'game_id' => $this->game_id + 999, 'character_id' => $this->character_id, 'change_id' => 5, 'sent_by' => $this->hst_id ] );

		$this->assertSame( 404, $this->rest( $this->hst_id, 'POST', "{$this->slug}/coordinator/requests/{$id}/check" )->get_status() );
	}

	public function test_the_link_to_the_toolkit_is_for_storytellers_and_not_the_player(): void {
		$this->link();

		$player = $this->rest( $this->player_id, 'GET', "{$this->slug}/characters/{$this->character_id}" )->get_data();
		$hst    = $this->rest( $this->hst_id, 'GET', "{$this->slug}/characters/{$this->character_id}" )->get_data();

		$this->assertArrayNotHasKey( 'oat_character_uuid', json_decode( (string) wp_json_encode( $player ), true ) );
		$this->assertSame( 'a3f1c0de-0000-4000-8000-000000000001', json_decode( (string) wp_json_encode( $hst ), true )['oat_character_uuid'] );
	}

	public function test_the_key_is_in_no_answer(): void {
		$this->link();
		$change = $this->waiting();
		$answers = [
			$this->rest( $this->hst_id, 'GET', "{$this->slug}/coordinator" )->get_data(),
			$this->rest( $this->hst_id, 'POST', "{$this->slug}/coordinator/send", $this->send_body( $change ) )->get_data(),
			$this->rest( $this->hst_id, 'GET', "{$this->slug}/characters/{$this->character_id}/toolkit-link" )->get_data(),
			$this->rest( $this->hst_id, 'GET', "{$this->slug}/coordinator/offices" )->get_data(),
		];

		$this->assertStringNotContainsString( $this->toolkit_key, (string) wp_json_encode( $answers ) );
	}
}
