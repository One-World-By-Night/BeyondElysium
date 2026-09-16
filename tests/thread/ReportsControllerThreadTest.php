<?php

namespace BeyondElysium\Tests\Thread;

require_once __DIR__ . '/../support/PdfSigningTestFixture.php';

use BeyondElysium\Models\Character;
use BeyondElysium\Models\Connection;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Game_Member;
use BeyondElysium\Models\Schema_Block;
use BeyondElysium\Models\Creature_Stack;
use BeyondElysium\Models\World_Object;
use BeyondElysium\Tests\Support\PdfSigningTestFixture;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * `Reports_Controller`'s two routes, dispatched as real REST requests -
 * mirrors `SheetsControllerThreadTest`'s own shape (reports-cards-batch-design.md).
 *
 * @see BE_PROCESS/reports-cards-batch-design.md §3.5
 */
class ReportsControllerThreadTest extends WP_UnitTestCase {

	private $manager_id;
	private $game_slug = 'reports-controller-test';

	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		PdfSigningTestFixture::ensure();
	}

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );

		$this->manager_id = self::factory()->user->create( [ 'role' => 'administrator' ] );

		Game::create( [
			'slug'       => $this->game_slug,
			'name'       => 'Reports Controller Test',
			'created_by' => $this->manager_id,
		] );

		Schema_Block::create( [
			'slug'         => 'rc-identity',
			'name'         => 'Identity',
			'section_type' => 'identity_field',
			'definition'   => [ 'fields' => [ [ 'name' => 'Clan', 'field_type' => 'text', 'required' => false ] ] ],
			'is_system'    => 0,
		] );
		Creature_Stack::create( [
			'slug'             => 'rc-stack',
			'name'             => 'Reports Controller Test Stack',
			'stack_definition' => [ 'sections' => [ [ 'block_slug' => 'rc-identity' ] ] ],
			'is_system'        => 0,
			'created_by'       => $this->manager_id,
		] );

		Character::create( [
			'name'       => 'Roster Test Character',
			'owner_slug' => $this->game_slug,
			'stack_slug' => 'rc-stack',
			'wp_user_id' => $this->manager_id,
			'sheet_data' => [ 'rc-identity' => [ 'Clan' => 'Ventrue' ] ],
			'created_by' => $this->manager_id,
		] );
	}

	private function dispatch( string $path, array $params = [] ): \WP_REST_Response {
		wp_set_current_user( $this->manager_id );
		$request = new WP_REST_Request( 'GET', $path );
		$request->set_url_params( [ 'game_slug' => $this->game_slug ] );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		return rest_get_server()->dispatch( $request );
	}

	public function test_get_items_lists_all_twenty_reports(): void {
		$response = $this->dispatch( '/be/v1/' . $this->game_slug . '/reports' );

		$this->assertSame( 200, $response->get_status() );
		$this->assertCount( 20, $response->get_data() );
	}

	public function test_character_roster_generates_real_signed_pdf_bytes(): void {
		$response = $this->dispatch( '/be/v1/' . $this->game_slug . '/reports/character-roster/pdf' );

		$this->assertSame( 200, $response->get_status() );
		$this->assertStringStartsWith( '%PDF-', $response->get_data()['bytes'] );
	}

	/** 1.0.0-review F-042: with no signing certificate a report still prints, marked unsigned. */
	public function test_with_no_signing_certificate_a_report_prints_marked_unsigned(): void {
		$cert_path = BE_PDF_SIGNING_CERT;
		rename( $cert_path, $cert_path . '.hidden' );
		try {
			$response = $this->dispatch( '/be/v1/' . $this->game_slug . '/reports/character-roster/pdf' );
		} finally {
			rename( $cert_path . '.hidden', $cert_path );
		}

		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$this->assertStringStartsWith( '%PDF-', (string) $response->get_data()['bytes'] );
		$this->assertStringNotContainsString( '/ByteRange', (string) $response->get_data()['bytes'] );
		$this->assertStringEndsWith( '-unsigned.pdf', $response->get_data()['filename'] );
		if ( shell_exec( 'command -v pdftotext' ) ) {
			$path = wp_tempnam( 'be-unsigned-report' );
			file_put_contents( $path, $response->get_data()['bytes'] );
			$this->assertStringContainsString( 'UNSIGNED', (string) shell_exec( 'pdftotext ' . escapeshellarg( $path ) . ' - 2>/dev/null' ) );
			unlink( $path );
		}
	}

	public function test_item_cards_shape_generates_real_pdf_bytes_with_zero_items(): void {
		$response = $this->dispatch( '/be/v1/' . $this->game_slug . '/reports/item-cards/pdf' );

		$this->assertSame( 200, $response->get_status() );
		$this->assertStringStartsWith( '%PDF-', $response->get_data()['bytes'] );
	}

	public function test_statistics_report_generates_real_pdf_bytes(): void {
		$response = $this->dispatch(
			'/be/v1/' . $this->game_slug . '/reports/statistics-report/pdf',
			[ 'stat_field' => 'merits', 'stat_type' => 'distinct_distribution' ]
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertStringStartsWith( '%PDF-', $response->get_data()['bytes'] );
	}

	public function test_game_calendar_renders_the_honest_empty_state_not_a_fatal(): void {
		$response = $this->dispatch( '/be/v1/' . $this->game_slug . '/reports/game-calendar/pdf' );

		$this->assertSame( 200, $response->get_status() );
		$this->assertStringStartsWith( '%PDF-', $response->get_data()['bytes'] );
	}

	public function test_unknown_report_key_is_404(): void {
		$response = $this->dispatch( '/be/v1/' . $this->game_slug . '/reports/not-a-real-report/pdf' );

		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( 'report_not_found', $response->as_error()->get_error_code() );
	}

	public function test_master_action_report_generates_real_pdf_bytes_with_a_real_action_entry(): void {
		$game_id = Game::find_by_slug( $this->game_slug )->id;
		$character = Character::find_by_name_in_game( 'Roster Test Character', $this->game_slug );

		$plot_id = \BeyondElysium\Models\Plot::create( [
			'game_id'      => $game_id,
			'title'        => 'Action Allocation - 2026-09-13',
			'initiated_by' => 'player',
			'created_by'   => $character->wp_user_id,
		] );
		\BeyondElysium\Models\Plot_Entry::create( [
			'plot_id'    => $plot_id,
			'author_id'  => $character->wp_user_id,
			'entry_type' => 'action',
			'content'    => 'Investigate the docks',
			'event_date' => '2026-09-13',
		] );

		$response = $this->dispatch( '/be/v1/' . $this->game_slug . '/reports/master-action-report/pdf' );

		$this->assertSame( 200, $response->get_status() );
		$this->assertStringStartsWith( '%PDF-', $response->get_data()['bytes'] );
	}

	public function test_master_rumor_report_generates_real_pdf_bytes_with_a_real_rumor(): void {
		$game_id = (int) Game::find_by_slug( $this->game_slug )->id;
		$plot_id = \BeyondElysium\Models\Plot::create( [
			'game_id'      => $game_id,
			'title'        => 'A rumor spreads',
			'description'  => 'Someone saw a stranger at the docks last night.',
			'initiated_by' => 'st',
			'created_by'   => $this->manager_id,
		] );
		\BeyondElysium\Services\Rumor_Generator::tag_as_rumor( $plot_id, $game_id );

		$response = $this->dispatch( '/be/v1/' . $this->game_slug . '/reports/master-rumor-report/pdf' );

		$this->assertSame( 200, $response->get_status() );
		$this->assertStringStartsWith( '%PDF-', $response->get_data()['bytes'] );
	}

	public function test_plot_report_narrative_shape_generates_real_pdf_bytes(): void {
		$response = $this->dispatch( '/be/v1/' . $this->game_slug . '/reports/plot-report/pdf' );

		$this->assertSame( 200, $response->get_status() );
		$this->assertStringStartsWith( '%PDF-', $response->get_data()['bytes'] );
	}

	public function test_player_roster_generates_real_pdf_bytes(): void {
		Game_Member::ensure_player( (int) Game::find_by_slug( $this->game_slug )->id, $this->manager_id );

		$response = $this->dispatch( '/be/v1/' . $this->game_slug . '/reports/player-roster/pdf' );

		$this->assertSame( 200, $response->get_status() );
		$this->assertStringStartsWith( '%PDF-', $response->get_data()['bytes'] );
	}

	public function test_a_player_role_can_reach_the_route_via_be_view_reports(): void {
		$player_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		Game_Member::ensure_player( (int) Game::find_by_slug( $this->game_slug )->id, $player_id );

		wp_set_current_user( $player_id );
		$request = new WP_REST_Request( 'GET', '/be/v1/' . $this->game_slug . '/reports' );
		$request->set_url_params( [ 'game_slug' => $this->game_slug ] );
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
	}

	// -------------------------------------------------------------------------
	// house-rules (v0.99.19, Decision 094) - the first report with no
	// Grapevine counterpart, and the first to read the whole catalog rather
	// than one entity.
	// -------------------------------------------------------------------------

	public function test_house_rules_pdf_renders_a_real_description(): void {
		Schema_Block::create( [
			'slug'         => 'rc-house-rule-block',
			'name'         => 'RC House Rule Block',
			'section_type' => 'trait_list',
			'definition'   => [ 'items' => [ [
				'name'        => 'Occult',
				'description' => [ 'description' => '<p>House rule text</p>' ],
			] ] ],
			'is_system'    => 0,
		] );

		$response = $this->dispatch( '/be/v1/' . $this->game_slug . '/reports/house-rules/pdf' );

		$this->assertSame( 200, $response->get_status() );
		$this->assertStringStartsWith( '%PDF-', $response->get_data()['bytes'] );
	}

	public function test_house_rules_pdf_renders_the_honest_empty_state_when_no_catalog_item_has_a_description(): void {
		$response = $this->dispatch( '/be/v1/' . $this->game_slug . '/reports/house-rules/pdf' );

		$this->assertSame( 200, $response->get_status() );
		$this->assertStringStartsWith( '%PDF-', $response->get_data()['bytes'] );
	}

	public function test_get_document_returns_the_plain_json_form_for_a_live_widget(): void {
		Schema_Block::create( [
			'slug'         => 'rc-house-rule-block-json',
			'name'         => 'RC House Rule Block JSON',
			'section_type' => 'trait_list',
			'definition'   => [ 'items' => [ [
				'name'        => 'Occult',
				'description' => [ 'reference' => '<p>Book, p.42</p>' ],
			] ] ],
			'is_system'    => 0,
		] );

		$response = $this->dispatch( '/be/v1/' . $this->game_slug . '/reports/house-rules' );

		$this->assertSame( 200, $response->get_status() );
		$data  = $response->get_data();
		$group = current( array_filter( $data['groups'], fn( $g ) => $g['block_name'] === 'RC House Rule Block JSON' ) );
		$this->assertNotFalse( $group, 'the JSON form must include the real block group, not just the PDF form' );
		$this->assertSame( '<p>Book, p.42</p>', $group['entries'][0]['sections']['reference'] );
	}

	public function test_get_document_never_returns_pdf_bytes(): void {
		$response = $this->dispatch( '/be/v1/' . $this->game_slug . '/reports/house-rules' );

		$this->assertSame( 200, $response->get_status() );
		$this->assertArrayNotHasKey( 'bytes', (array) $response->get_data(), 'the JSON route must never carry PDF bytes or need signing configured' );
	}

	public function test_a_player_role_can_reach_the_house_rules_document_route(): void {
		$player_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		Game_Member::ensure_player( (int) Game::find_by_slug( $this->game_slug )->id, $player_id );

		wp_set_current_user( $player_id );
		$request = new WP_REST_Request( 'GET', '/be/v1/' . $this->game_slug . '/reports/house-rules' );
		$request->set_url_params( [ 'game_slug' => $this->game_slug ] );
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 200, $response->get_status(), 'the whole point of this report is that a plain player can see it' );
	}

	// -------------------------------------------------------------------------
	// item-cards character_id scoping (item-cards-design.md) - narrows the
	// chronicle-wide item-cards report to only the items connected to one
	// character, via Connection::for_source(), never the conditions system.
	// -------------------------------------------------------------------------

	private function make_owned_character_with_items(): array {
		$game_id = (int) Game::find_by_slug( $this->game_slug )->id;

		$player_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		Game_Member::ensure_player( $game_id, $player_id );

		$character_id = Character::create( [
			'name'       => 'Item Owner',
			'owner_slug' => $this->game_slug,
			'stack_slug' => 'rc-stack',
			'wp_user_id' => $player_id,
			'sheet_data' => [],
			'created_by' => $this->manager_id,
		] );

		$held_item_id   = World_Object::create( [ 'game_id' => $game_id, 'object_type' => 'item', 'name' => 'Silver Dagger' ] );
		$unheld_item_id = World_Object::create( [ 'game_id' => $game_id, 'object_type' => 'item', 'name' => 'Warding Amulet' ] );

		Connection::create( [
			'game_id'     => $game_id,
			'source_type' => 'character',
			'source_id'   => $character_id,
			'target_type' => 'world_object',
			'target_id'   => $held_item_id,
			'label'       => 'Sidearm',
		] );

		return [ 'player_id' => $player_id, 'character_id' => $character_id, 'held_item_id' => $held_item_id, 'unheld_item_id' => $unheld_item_id ];
	}

	public function test_item_cards_scoped_to_a_character_returns_only_that_characters_connected_items(): void {
		$fixture = $this->make_owned_character_with_items();

		$response = $this->dispatch(
			'/be/v1/' . $this->game_slug . '/reports/item-cards',
			[ 'character_id' => $fixture['character_id'] ]
		);

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertCount( 1, $data['cards'] );
		$names = array_column( $data['cards'][0], 1 );
		$this->assertContains( 'Silver Dagger', $names );
	}

	public function test_a_player_can_request_their_own_characters_item_cards(): void {
		$fixture = $this->make_owned_character_with_items();

		wp_set_current_user( $fixture['player_id'] );
		$request = new WP_REST_Request( 'GET', '/be/v1/' . $this->game_slug . '/reports/item-cards' );
		$request->set_url_params( [ 'game_slug' => $this->game_slug ] );
		$request->set_param( 'character_id', $fixture['character_id'] );
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertCount( 1, $response->get_data()['cards'] );
	}

	public function test_a_player_cannot_request_another_characters_item_cards(): void {
		$fixture       = $this->make_owned_character_with_items();
		$other_player  = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		Game_Member::ensure_player( (int) Game::find_by_slug( $this->game_slug )->id, $other_player );

		wp_set_current_user( $other_player );
		$request = new WP_REST_Request( 'GET', '/be/v1/' . $this->game_slug . '/reports/item-cards' );
		$request->set_url_params( [ 'game_slug' => $this->game_slug ] );
		$request->set_param( 'character_id', $fixture['character_id'] );
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'ownership_denied', $response->as_error()->get_error_code() );
	}

	public function test_a_manager_can_request_any_characters_item_cards(): void {
		$fixture = $this->make_owned_character_with_items();

		$response = $this->dispatch(
			'/be/v1/' . $this->game_slug . '/reports/item-cards/pdf',
			[ 'character_id' => $fixture['character_id'] ]
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertStringStartsWith( '%PDF-', $response->get_data()['bytes'] );
	}

	public function test_a_character_id_from_another_game_is_404(): void {
		$response = $this->dispatch(
			'/be/v1/' . $this->game_slug . '/reports/item-cards',
			[ 'character_id' => 999999 ]
		);

		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( 'character_not_found', $response->as_error()->get_error_code() );
	}
}
