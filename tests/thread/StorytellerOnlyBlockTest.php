<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Character;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Schema_Block;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Covers the storyteller_only block flag and both of the places it is
 * enforced: a character's sheet_data and a resolved template layout. Hiding
 * the section alone is not enough - the block's stored values would still
 * ship inside the character payload - so both are asserted separately.
 */
class StorytellerOnlyBlockTest extends WP_UnitTestCase {

	private $game_id;
	private $manager_id;
	private $player_id;
	private $character_id;

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );

		$this->manager_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$this->player_id  = self::factory()->user->create( [ 'role' => 'subscriber' ] );

		$this->game_id = Game::create( [
			'slug'       => 'st-only-test',
			'name'       => 'ST Only Test',
			'created_by' => $this->manager_id,
		] );

		Schema_Block::create( [
			'slug'             => 'secret-notes',
			'name'             => 'Secret Notes',
			'section_type'     => 'identity_field',
			'definition'       => [ 'fields' => [ [ 'name' => 'Hook', 'field_type' => 'textarea', 'required' => false ] ] ],
			'is_system'        => 0,
			'storyteller_only' => 1,
		] );

		Schema_Block::create( [
			'slug'         => 'open-notes',
			'name'         => 'Open Notes',
			'section_type' => 'identity_field',
			'definition'   => [ 'fields' => [ [ 'name' => 'Concept', 'field_type' => 'text', 'required' => false ] ] ],
			'is_system'    => 0,
		] );

		$this->character_id = Character::create( [
			'name'       => 'Owned Character',
			'owner_slug' => 'st-only-test',
			'stack_slug' => 'vampire',
			'wp_user_id' => $this->player_id,
			'sheet_data' => [
				'secret-notes' => [ 'Hook' => 'Secretly working for the Sabbat' ],
				'open-notes'   => [ 'Concept' => 'Brooding detective' ],
			],
			'created_by' => $this->manager_id,
		] );
	}

	private function get_character( int $as_user ) {
		wp_set_current_user( $as_user );
		$request = new WP_REST_Request( 'GET', '/be/v1/st-only-test/characters/' . $this->character_id );
		$request->set_url_params( [ 'game_slug' => 'st-only-test', 'id' => $this->character_id ] );
		return rest_get_server()->dispatch( $request );
	}

	public function test_a_manager_still_sees_the_storyteller_only_block(): void {
		$data = $this->get_character( $this->manager_id )->get_data();

		$this->assertArrayHasKey( 'secret-notes', $data->sheet_data );
		$this->assertSame( 'Secretly working for the Sabbat', $data->sheet_data['secret-notes']['Hook'] );
	}

	public function test_the_owning_player_never_receives_the_storyteller_only_block(): void {
		$data = $this->get_character( $this->player_id )->get_data();

		$this->assertArrayNotHasKey(
			'secret-notes',
			$data->sheet_data,
			'the block data itself must be stripped, not merely hidden from the layout'
		);
		$this->assertArrayHasKey( 'open-notes', $data->sheet_data, 'an ordinary block is untouched' );
	}

	public function test_a_resolved_layout_drops_storyteller_only_sections_for_a_player(): void {
		$layout = [
			'version'  => 1,
			'columns'  => 3,
			'sections' => [
				[ 'block_slug' => 'open-notes', 'column' => 1, 'order' => 1, 'title' => 'Open', 'display' => null, 'collapsed' => false ],
				[ 'block_slug' => 'secret-notes', 'column' => 1, 'order' => 2, 'title' => 'Secret', 'display' => null, 'collapsed' => false ],
			],
		];
		\BeyondElysium\Models\Template::create( [
			'stack_slug'    => 'vampire',
			'name'          => 'ST Only Layout',
			'template_type' => 'st_only_test',
			'layout'        => $layout,
			'is_system'     => 0,
			'created_by'    => $this->manager_id,
		] );

		wp_set_current_user( $this->player_id );
		$request = new WP_REST_Request( 'GET', '/be/v1/st-only-test/templates/resolve' );
		$request->set_url_params( [ 'game_slug' => 'st-only-test' ] );
		$request->set_param( 'stack_slug', 'vampire' );
		$request->set_param( 'template_type', 'st_only_test' );
		$player_sections = rest_get_server()->dispatch( $request )->get_data()['template']['layout']['sections'];

		$this->assertCount( 1, $player_sections );
		$this->assertSame( 'open-notes', $player_sections[0]['block_slug'] );
		// A filtered list must stay a JSON array, not become a keyed object.
		$this->assertSame( [ 0 ], array_keys( $player_sections ) );

		wp_set_current_user( $this->manager_id );
		$manager_sections = rest_get_server()->dispatch( $request )->get_data()['template']['layout']['sections'];
		$this->assertCount( 2, $manager_sections, 'a manager keeps every section' );
	}

	public function test_creating_a_block_through_the_rest_route_persists_the_flag(): void {
		wp_set_current_user( $this->manager_id );

		$request = new WP_REST_Request( 'POST', '/be/v1/schema-blocks' );
		$request->set_body_params( [
			'slug'             => 'made-via-rest',
			'name'             => 'Made Via Rest',
			'section_type'     => 'identity_field',
			'storyteller_only' => 1,
			'definition'       => [ 'fields' => [ [ 'name' => 'Hook', 'field_type' => 'textarea', 'required' => false ] ] ],
		] );
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 201, $response->get_status() );
		$this->assertSame(
			1,
			(int) Schema_Block::find_by_slug( 'made-via-rest' )->storyteller_only,
			'the create route must carry storyteller_only through, not silently drop it'
		);
	}

	public function test_storyteller_only_slugs_reports_only_flagged_blocks(): void {
		$slugs = Schema_Block::storyteller_only_slugs();

		$this->assertContains( 'secret-notes', $slugs );
		$this->assertNotContains( 'open-notes', $slugs );
	}
}
