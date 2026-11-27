<?php

namespace BeyondElysium\Tests\Thread;

require_once __DIR__ . '/../support/CoordinatorTestFixture.php';

use BeyondElysium\Services\Coordinator_Send;
use BeyondElysium\Tests\Support\CoordinatorTestFixture;
use WP_UnitTestCase;

/**
 * The HST's switch for sending Coordinator approvals to the Archivist Toolkit: who may set it, where, and how the
 * checklist row and the chronicle's send mode read it.
 */
class CoordinatorSendSettingThreadTest extends WP_UnitTestCase {

	use CoordinatorTestFixture;

	public function tearDown(): void {
		$this->connect_toolkit( false );
		parent::tearDown();
	}

	public function test_an_hst_turns_it_on_where_the_chronicle_is_connected(): void {
		$this->make_chronicle();
		$this->connect_toolkit();

		$response = $this->put_setup( $this->hst_id, [ 'coordinator_send' => true ] );

		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$this->assertTrue( $this->game()->settings->coordinator_send );
		$this->assertSame( Coordinator_Send::LIVE, Coordinator_Send::mode( $this->game() ) );
	}

	public function test_it_is_off_until_an_hst_turns_it_on(): void {
		$this->make_chronicle();
		$this->connect_toolkit();

		$this->assertNull( Coordinator_Send::mode( $this->game() ) );
	}

	public function test_it_can_always_be_turned_off(): void {
		$this->make_chronicle( 'chronicle/kony', [ 'coordinator_send' => true ] );
		$this->connect_toolkit( false );

		$response = $this->put_setup( $this->hst_id, [ 'coordinator_send' => false ] );

		$this->assertSame( 200, $response->get_status() );
		$this->assertFalse( $this->game()->settings->coordinator_send );
	}

	public function test_it_is_refused_where_the_network_has_no_toolkit_connection(): void {
		$this->make_chronicle();
		$this->connect_toolkit( false );

		$response = $this->put_setup( $this->hst_id, [ 'coordinator_send' => true ] );

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'oat_not_connected', $response->get_data()['code'] );
		$this->assertNotTrue( $this->game()->settings->coordinator_send ?? false );
	}

	public function test_it_is_refused_for_a_chronicle_with_no_owbn_path(): void {
		$this->make_chronicle( '' );
		$this->connect_toolkit();

		$response = $this->put_setup( $this->hst_id, [ 'coordinator_send' => true ] );

		$this->assertSame( 409, $response->get_status() );
		$this->assertStringContainsString( 'OWbN chronicle path', $response->get_data()['message'] );
	}

	public function test_switched_on_it_still_does_nothing_where_the_connection_has_gone(): void {
		$this->make_chronicle( 'chronicle/kony', [ 'coordinator_send' => true ] );
		$this->connect_toolkit( false );

		$this->assertNull( Coordinator_Send::mode( $this->game() ) );
	}

	public function test_an_assistant_storyteller_cannot_set_it(): void {
		$this->make_chronicle();
		$this->connect_toolkit();

		$response = $this->put_setup( $this->ast_id, [ 'coordinator_send' => true ] );

		$this->assertContains( $response->get_status(), [ 401, 403 ] );
		$this->assertNotTrue( $this->game()->settings->coordinator_send ?? false );
	}

	public function test_a_player_cannot_set_it(): void {
		$this->make_chronicle();
		$this->connect_toolkit();

		$response = $this->put_setup( $this->player_id, [ 'coordinator_send' => true ] );

		$this->assertContains( $response->get_status(), [ 401, 403 ] );
	}

	public function test_a_demo_chronicle_previews_without_a_connection(): void {
		$this->make_chronicle( '', [ 'demo' => [ 'on' => true ] ] );
		$this->connect_toolkit( false );

		$response = $this->put_setup( $this->hst_id, [ 'coordinator_send' => true ] );

		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$this->assertSame( Coordinator_Send::PREVIEW, Coordinator_Send::mode( $this->game() ) );
	}

	public function test_a_demo_chronicle_never_sends_even_with_a_connection(): void {
		$this->make_chronicle( 'chronicle/kony', [ 'demo' => [ 'on' => true ], 'coordinator_send' => true ] );
		$this->connect_toolkit();

		$this->assertSame( Coordinator_Send::PREVIEW, Coordinator_Send::mode( $this->game() ) );
	}

	public function test_the_checklist_row_says_where_it_stands(): void {
		$this->make_chronicle();

		$this->connect_toolkit( false );
		$row = $this->setup_row( 'coordinator_send' );
		$this->assertSame( 'info', $row['status'] );
		$this->assertFalse( $row['coordinator']['available'] );
		$this->assertSame( 'no_connection', $row['coordinator']['reason'] );
		$this->assertStringContainsString( 'no Archivist Toolkit connection', $row['detail'] );

		$this->connect_toolkit();
		$row = $this->setup_row( 'coordinator_send' );
		$this->assertSame( 'info', $row['status'] );
		$this->assertTrue( $row['coordinator']['available'] );
		$this->assertSame( 'archivist.example.test', $row['coordinator']['host'] );
		$this->assertStringContainsString( 'Off', $row['detail'] );

		$this->put_setup( $this->hst_id, [ 'coordinator_send' => true ] );
		$row = $this->setup_row( 'coordinator_send' );
		$this->assertSame( 'ok', $row['status'] );
		$this->assertStringContainsString( 'archivist.example.test', $row['detail'] );
	}

	public function test_the_checklist_row_for_a_demo_says_it_sends_nothing(): void {
		$this->make_chronicle( '', [ 'demo' => [ 'on' => true ], 'coordinator_send' => true ] );

		$row = $this->setup_row( 'coordinator_send' );

		$this->assertTrue( $row['coordinator']['demo'] );
		$this->assertStringContainsString( 'sends nothing', $row['detail'] );
	}

	public function test_the_key_is_in_no_setup_answer(): void {
		$this->make_chronicle();
		$this->connect_toolkit();
		$this->put_setup( $this->hst_id, [ 'coordinator_send' => true ] );

		$json = (string) wp_json_encode( [
			$this->rest( $this->hst_id, 'GET', "{$this->slug}/setup-status" )->get_data(),
			$this->rest( $this->hst_id, 'GET', "games/{$this->slug}" )->get_data(),
		] );

		$this->assertStringNotContainsString( $this->toolkit_key, $json );
	}
}
