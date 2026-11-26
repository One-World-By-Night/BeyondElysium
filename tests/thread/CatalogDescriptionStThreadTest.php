<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Creature_Stack;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Game_Member;
use BeyondElysium\Models\Schema_Block;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * A catalog description reaches a player without its Storyteller-only passages, and a Storyteller-only block's
 * descriptions never reach one.
 */
class CatalogDescriptionStThreadTest extends WP_UnitTestCase {

	private string $slug = 'thread-catalog-st';
	private int $player;
	private int $hst;

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );

		$game_id      = (int) Game::create( [ 'slug' => $this->slug, 'name' => 'Catalog St' ] );
		$this->player = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		$this->hst    = self::factory()->user->create( [ 'role' => 'editor' ] );
		Game_Member::set_role( $game_id, $this->player, 'player' );
		Game_Member::set_role( $game_id, $this->hst, 'hst' );

		Schema_Block::create( [
			'slug'         => 'cst-merits',
			'name'         => 'CST Merits',
			'section_type' => 'trait_list',
			'definition'   => [ 'items' => [ [
				'name'        => 'Occult',
				'description' => [
					'description' => '<p>Open text.</p>[ST]Secret about Occult.[/ST]',
					'reference'   => 'Book p.4 [ST]secret reference[/ST]',
				],
			] ] ],
			'is_system'    => 0,
		] );
		Schema_Block::create( [
			'slug'         => 'cst-power',
			'name'         => 'CST Power',
			'section_type' => 'tiered_power',
			'definition'   => [ 'powers' => [ [
				'name'        => 'Auspex',
				'description' => [ 'description' => 'Family text. [ST]Secret family.[/ST]' ],
				'levels'      => [ [
					'level'       => 1,
					'power_name'  => 'Heightened Senses',
					'cost'        => 3,
					'description' => [ 'description' => 'Level text. [ST]Secret level.[/ST]' ],
				] ],
			] ] ],
			'is_system'    => 0,
		] );
		Schema_Block::create( [
			'slug'             => 'cst-hidden',
			'name'             => 'CST Hidden',
			'section_type'     => 'trait_list',
			'storyteller_only' => 1,
			'definition'       => [ 'items' => [ [
				'name'        => 'Dark Secret',
				'description' => [ 'description' => 'Everything here is for Storytellers.' ],
			] ] ],
			'is_system'        => 0,
		] );
		Creature_Stack::create( [
			'slug'             => 'cst-stack',
			'name'             => 'CST Stack',
			'is_system'        => 0,
			'created_by'       => 1,
			'stack_definition' => [ 'sections' => [
				[ 'block_slug' => 'cst-merits', 'label' => 'Merits', 'display_order' => 10 ],
				[ 'block_slug' => 'cst-power', 'label' => 'Powers', 'display_order' => 20 ],
				[ 'block_slug' => 'cst-hidden', 'label' => 'Hidden', 'display_order' => 30 ],
			] ],
		] );
	}

	private function get( int $as, string $route, array $query = [] ) {
		wp_set_current_user( $as );
		$request = new WP_REST_Request( 'GET', $route );
		$request->set_query_params( $query );
		return rest_get_server()->dispatch( $request );
	}

	private function json( $response ): string {
		return (string) wp_json_encode( $response->get_data() );
	}

	public function test_a_player_reads_a_block_without_its_storyteller_passages(): void {
		$response = $this->get( $this->player, '/be/v1/schema-blocks/cst-merits', [ 'game_slug' => $this->slug ] );

		$this->assertSame( 200, $response->get_status() );
		$json = $this->json( $response );
		$this->assertStringContainsString( 'Open text.', $json );
		$this->assertStringNotContainsString( '[ST]', $json );
		$this->assertStringNotContainsString( 'Secret about Occult', $json );
		$this->assertStringNotContainsString( 'secret reference', $json );
		$this->assertStringContainsString( 'Book p.4', $json );
	}

	public function test_a_player_reads_a_power_block_without_its_storyteller_passages(): void {
		$json = $this->json( $this->get( $this->player, '/be/v1/schema-blocks/cst-power', [ 'game_slug' => $this->slug ] ) );

		$this->assertStringContainsString( 'Family text.', $json );
		$this->assertStringContainsString( 'Level text.', $json );
		$this->assertStringNotContainsString( 'Secret family', $json );
		$this->assertStringNotContainsString( 'Secret level', $json );
	}

	public function test_a_storyteller_reads_every_passage(): void {
		$json = $this->json( $this->get( $this->hst, '/be/v1/schema-blocks/cst-merits', [ 'game_slug' => $this->slug ] ) );

		$this->assertStringContainsString( 'Secret about Occult', $json );
		$this->assertStringContainsString( 'secret reference', $json );
	}

	public function test_a_player_gets_no_description_from_a_storyteller_only_block(): void {
		$json = $this->json( $this->get( $this->player, '/be/v1/schema-blocks/cst-hidden', [ 'game_slug' => $this->slug ] ) );

		$this->assertStringContainsString( 'Dark Secret', $json );
		$this->assertStringNotContainsString( 'Everything here is for Storytellers', $json );
	}

	public function test_a_storyteller_reads_a_storyteller_only_blocks_descriptions(): void {
		$json = $this->json( $this->get( $this->hst, '/be/v1/schema-blocks/cst-hidden', [ 'game_slug' => $this->slug ] ) );

		$this->assertStringContainsString( 'Everything here is for Storytellers', $json );
	}

	public function test_the_block_list_is_filtered_the_same_way(): void {
		$response = $this->get( $this->player, '/be/v1/schema-blocks', [ 'game_slug' => $this->slug, 'search' => 'CST', 'per_page' => 50 ] );

		$this->assertSame( 200, $response->get_status() );
		$json = $this->json( $response );
		$this->assertStringContainsString( 'Open text.', $json );
		$this->assertStringNotContainsString( '[ST]', $json );
		$this->assertStringNotContainsString( 'Secret about Occult', $json );
		$this->assertStringNotContainsString( 'Secret family', $json );
		$this->assertStringNotContainsString( 'Secret level', $json );
		$this->assertStringNotContainsString( 'Everything here is for Storytellers', $json );
	}

	public function test_a_resolved_stack_carries_the_same_filtered_blocks(): void {
		$as_player = $this->json( $this->get( $this->player, '/be/v1/creature-stacks/cst-stack', [ 'resolve' => 1, 'game_slug' => $this->slug ] ) );
		$this->assertStringContainsString( 'Open text.', $as_player );
		$this->assertStringNotContainsString( '[ST]', $as_player );
		$this->assertStringNotContainsString( 'Secret about Occult', $as_player );
		$this->assertStringNotContainsString( 'Secret family', $as_player );
		$this->assertStringNotContainsString( 'Everything here is for Storytellers', $as_player );

		$as_hst = $this->json( $this->get( $this->hst, '/be/v1/creature-stacks/cst-stack', [ 'resolve' => 1, 'game_slug' => $this->slug ] ) );
		$this->assertStringContainsString( 'Secret about Occult', $as_hst );
		$this->assertStringContainsString( 'Everything here is for Storytellers', $as_hst );
	}

	public function test_a_chronicle_copy_keeps_nothing_hidden_in_its_recorded_changes(): void {
		Schema_Block::find_or_create_fork_for_game( 'cst-merits', $this->slug );
		Schema_Block::update( 'cst-merits', [ 'definition' => [ 'items' => [ [
			'name'        => 'Occult',
			'description' => [ 'description' => 'Edited. [ST]Edited secret.[/ST]' ],
		] ] ] ], $this->slug );

		$json = $this->json( $this->get( $this->player, '/be/v1/schema-blocks/cst-merits', [ 'game_slug' => $this->slug ] ) );

		$this->assertStringContainsString( 'Edited.', $json );
		$this->assertStringNotContainsString( 'Edited secret', $json );
		$this->assertStringNotContainsString( '[ST]', $json );
	}

	public function test_house_rules_for_a_player_leave_out_storyteller_text(): void {
		$response = $this->get( $this->player, "/be/v1/{$this->slug}/reports/house-rules" );

		$this->assertSame( 200, $response->get_status() );
		$json = $this->json( $response );
		$this->assertStringContainsString( 'Open text.', $json );
		$this->assertStringNotContainsString( 'Secret about Occult', $json );
		$this->assertStringNotContainsString( 'Secret family', $json );
		$this->assertStringNotContainsString( '[ST]', $json );
		$this->assertStringNotContainsString( 'Everything here is for Storytellers', $json );
	}

	public function test_house_rules_for_a_storyteller_keep_everything(): void {
		$json = $this->json( $this->get( $this->hst, "/be/v1/{$this->slug}/reports/house-rules" ) );

		$this->assertStringContainsString( 'Secret about Occult', $json );
		$this->assertStringContainsString( 'Everything here is for Storytellers', $json );
	}
}
