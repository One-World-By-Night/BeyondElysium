<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Creature_Stack;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Game_Member;
use BeyondElysium\Models\Schema_Block;
use BeyondElysium\Models\Translation;
use BeyondElysium\Models\Translation_String;
use BeyondElysium\Services\Catalog_Translator;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * The compendium: the chronicle's catalog as a member may read it, a creature type and a section at a time.
 */
class CompendiumThreadTest extends WP_UnitTestCase {

	private string $slug = 'thread-compendium';
	private int $game_id;
	private int $player;
	private int $hst;

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );

		$this->game_id = (int) Game::create( [ 'slug' => $this->slug, 'name' => 'Compendium' ] );
		$this->player  = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		$this->hst     = self::factory()->user->create( [ 'role' => 'editor' ] );
		Game_Member::set_role( $this->game_id, $this->player, 'player' );
		Game_Member::set_role( $this->game_id, $this->hst, 'hst' );

		foreach ( [ 'Cmp Occult' => 'Ocultismo', 'Cmp Heightened Senses' => 'Sentidos Aguçados' ] as $term => $translation ) {
			$string_id = (int) Translation_String::create( [ 'source_text' => $term ] );
			Translation::create( [ 'string_id' => $string_id, 'locale' => get_locale(), 'translation' => $translation ] );
		}
		Catalog_Translator::bust_cache();

		Schema_Block::create( [
			'slug' => 'cmp-merits', 'name' => 'CMP Merits', 'section_type' => 'trait_list', 'is_system' => 0,
			'definition' => [ 'items' => [
				[
					'name' => 'Cmp Occult', 'cost' => '2', 'group' => 'Knowledges', 'subgroup' => 'Lore', 'tier' => null,
					'source' => 'GVM: Merits; met-mechanics.csv: Laws of the Night Revised',
					'description' => [ 'description' => '<p>Open note.</p>[ST]Storyteller secret.[/ST]', 'source' => 'House book', 'reference' => 'p. 4' ],
					'prerequisites' => [ [ 'block_slug' => 'cmp-power', 'power' => 'Auspex', 'min_level' => 2 ] ],
				],
				[ 'name' => 'Aardvark Lore', 'cost' => '1', 'source' => 'Vampire' ],
			] ],
		] );
		Schema_Block::create( [
			'slug' => 'cmp-power', 'name' => 'CMP Power', 'section_type' => 'tiered_power', 'is_system' => 0,
			'definition' => [ 'powers' => [ [
				'name' => 'Auspex', 'source' => 'OWbN Ventrue Packet',
				'levels' => [
					[ 'level' => 1, 'tier' => 'basic', 'power_name' => 'Cmp Heightened Senses', 'cost' => '3' ],
					[ 'level' => 2, 'tier' => 'basic', 'power_name' => 'Aura Perception', 'cost' => '3', 'description' => [ 'description' => 'Reads auras.' ] ],
				],
				'elder' => [ 'elder' => [ [ 'level' => null, 'tier' => 'elder', 'power_name' => 'Spirit Touch', 'cost' => '12' ] ] ],
			] ] ],
		] );
		Schema_Block::create( [
			'slug' => 'cmp-hidden-block', 'name' => 'CMP Hidden Block', 'section_type' => 'trait_list', 'is_system' => 0, 'storyteller_only' => 1,
			'definition' => [ 'items' => [ [ 'name' => 'Dark Matter', 'description' => [ 'description' => 'For Storytellers only.' ] ] ] ],
		] );
		Schema_Block::create( [
			'slug' => 'cmp-empty', 'name' => 'CMP Empty', 'section_type' => 'trait_list', 'is_system' => 0,
			'definition' => [ 'items' => [] ],
		] );
		Schema_Block::create( [
			'slug' => 'cmp-pool', 'name' => 'CMP Pool', 'section_type' => 'resource_pool', 'is_system' => 0,
			'definition' => [ 'pools' => [ [ 'name' => 'Blood', 'value_type' => 'integer' ] ] ],
		] );
		Creature_Stack::create( [
			'slug' => 'cmp-stack', 'name' => 'CMP Creature', 'is_system' => 0, 'created_by' => 1,
			'stack_definition' => [ 'sections' => [
				[ 'block_slug' => 'cmp-merits', 'label' => 'Merits', 'display_order' => 10 ],
				[ 'block_slug' => 'cmp-power', 'label' => 'Powers', 'display_order' => 20 ],
				[ 'block_slug' => 'cmp-hidden-block', 'label' => 'Hidden Block', 'display_order' => 30 ],
				[ 'block_slug' => 'cmp-empty', 'label' => 'Empty', 'display_order' => 40 ],
				[ 'block_slug' => 'cmp-pool', 'label' => 'Pools', 'display_order' => 50 ],
				[ 'block_slug' => 'cmp-merits', 'label' => 'Closed Merits', 'display_order' => 60, 'hidden' => true ],
			] ],
		] );
		Creature_Stack::create( [
			'slug' => 'cmp-st-stack', 'name' => 'CMP Storyteller Creature', 'is_system' => 0, 'created_by' => 1,
			'stack_definition' => [ 'storyteller_only' => true, 'sections' => [ [ 'block_slug' => 'cmp-merits', 'label' => 'Merits', 'display_order' => 10 ] ] ],
		] );
	}

	private function get( int $as, string $route, array $query = [] ) {
		wp_set_current_user( $as );
		$request = new WP_REST_Request( 'GET', "/be/v1/{$this->slug}/compendium{$route}" );
		$request->set_query_params( $query );
		return rest_get_server()->dispatch( $request );
	}

	private function json( $response ): string {
		return (string) wp_json_encode( $response->get_data() );
	}

	// -- types --------------------------------------------------------------------------------

	public function test_a_player_sees_the_types_the_chronicle_has_on_and_not_a_storyteller_only_one(): void {
		$slugs = array_column( $this->get( $this->player, '' )->get_data(), 'slug' );

		$this->assertContains( 'cmp-stack', $slugs );
		$this->assertContains( 'vampire', $slugs );
		$this->assertNotContains( 'cmp-st-stack', $slugs );
		$this->assertNotContains( 'various', $slugs );
	}

	public function test_a_storyteller_sees_a_storyteller_only_type_too(): void {
		$slugs = array_column( $this->get( $this->hst, '' )->get_data(), 'slug' );

		$this->assertContains( 'cmp-st-stack', $slugs );
	}

	public function test_a_chronicle_that_chose_its_types_lists_only_those(): void {
		Game::update( $this->slug, [ 'settings' => [ 'enabled_stacks' => [ 'cmp-stack' ] ] ] );

		$this->assertSame( [ 'cmp-stack' ], array_column( $this->get( $this->player, '' )->get_data(), 'slug' ) );
	}

	public function test_someone_outside_the_chronicle_is_refused(): void {
		$outsider = self::factory()->user->create( [ 'role' => 'subscriber' ] );

		$this->assertSame( 403, $this->get( $outsider, '' )->get_status() );
	}

	// -- sections -----------------------------------------------------------------------------

	public function test_a_player_sees_the_sections_that_hold_entries_with_their_counts(): void {
		$data = $this->get( $this->player, '/cmp-stack' )->get_data();

		$this->assertSame( 'CMP Creature', $data['name'] );
		$this->assertSame(
			[
				[ 'block_slug' => 'cmp-merits', 'label' => 'Merits', 'kind' => 'items', 'count' => 2 ],
				[ 'block_slug' => 'cmp-power', 'label' => 'Powers', 'kind' => 'powers', 'count' => 1 ],
			],
			$data['sections']
		);
	}

	public function test_a_storyteller_also_sees_a_storyteller_only_block_and_a_closed_section(): void {
		$labels = array_column( $this->get( $this->hst, '/cmp-stack' )->get_data()['sections'], 'label' );

		$this->assertSame( [ 'Merits', 'Powers', 'Hidden Block', 'Closed Merits' ], $labels );
	}

	public function test_a_player_gets_nothing_for_a_storyteller_only_type_or_an_unknown_one(): void {
		$this->assertSame( 404, $this->get( $this->player, '/cmp-st-stack' )->get_status() );
		$this->assertSame( 404, $this->get( $this->player, '/no-such-type' )->get_status() );
	}

	// -- entries ------------------------------------------------------------------------------

	public function test_a_list_entry_carries_its_cost_book_note_and_needs(): void {
		$response = $this->get( $this->player, '/cmp-stack/cmp-merits' );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'items', $data['kind'] );
		$occult = $data['entries'][0];
		$this->assertSame( 'Cmp Occult', $occult['name'] );
		$this->assertSame( 'Ocultismo', $occult['name_pt'] );
		$this->assertSame( '2', $occult['cost'] );
		$this->assertSame( 'Knowledges', $occult['group'] );
		$this->assertSame( 'Lore', $occult['subgroup'] );
		$this->assertSame( 'Laws of the Night Revised', $occult['book'] );
		$this->assertSame( '<p>Open note.</p>', $occult['description'] );
		$this->assertSame( 'House book, p. 4', $occult['citation'] );
		$this->assertSame( 'Auspex', $occult['prerequisites'][0]->power ?? $occult['prerequisites'][0]['power'] );
	}

	public function test_an_entry_with_no_book_or_note_says_so(): void {
		$aardvark = $this->get( $this->player, '/cmp-stack/cmp-merits' )->get_data()['entries'][1];

		$this->assertNull( $aardvark['book'] );
		$this->assertNull( $aardvark['description'] );
		$this->assertSame( '', $aardvark['citation'] );
	}

	public function test_a_power_family_carries_every_level_and_pick(): void {
		$family = $this->get( $this->player, '/cmp-stack/cmp-power' )->get_data()['entries'][0];

		$this->assertSame( 'Auspex', $family['name'] );
		$this->assertSame( 'OWbN Ventrue Packet', $family['book'] );
		$names = array_column( $family['levels'], 'power_name' );
		$this->assertSame( [ 'Cmp Heightened Senses', 'Aura Perception', 'Spirit Touch' ], $names );
		$this->assertSame( 'Sentidos Aguçados', $family['levels'][0]['power_name_pt'] );
		$this->assertSame( '3', $family['levels'][0]['cost'] );
		$this->assertSame( 'Reads auras.', $family['levels'][1]['description'] );
		$this->assertSame( 'elder', $family['levels'][2]['tier'] );
	}

	public function test_no_player_response_carries_storyteller_text(): void {
		foreach ( [ '/cmp-stack/cmp-merits', '/cmp-stack', '' ] as $route ) {
			$json = $this->json( $this->get( $this->player, $route ) );
			$this->assertStringNotContainsString( '[ST]', $json, $route );
			$this->assertStringNotContainsString( 'Storyteller secret', $json, $route );
		}
		$this->assertSame( 404, $this->get( $this->player, '/cmp-stack/cmp-hidden-block' )->get_status() );
		$this->assertStringContainsString( 'Storyteller secret', $this->json( $this->get( $this->hst, '/cmp-stack/cmp-merits' ) ) );
	}

	public function test_a_section_the_type_does_not_have_is_not_found(): void {
		$this->assertSame( 404, $this->get( $this->player, '/cmp-stack/cmp-pool' )->get_status() );
		$this->assertSame( 404, $this->get( $this->player, '/cmp-stack/cmp-empty' )->get_status() );
		$this->assertSame( 404, $this->get( $this->player, '/cmp-st-stack/cmp-merits' )->get_status() );
	}

	// -- search -------------------------------------------------------------------------------

	public function test_search_finds_entries_and_power_names_across_sections_in_any_case(): void {
		$found = $this->get( $this->player, '/search', [ 'stack' => 'cmp-stack', 'q' => 'AU' ] )->get_data();

		$names = array_column( $found, 'name' );
		$this->assertContains( 'Aura Perception', $names );
		$this->assertContains( 'Auspex', $names );
		$this->assertNotContains( 'Cmp Occult', $names );
		$level = current( array_filter( $found, static fn( $row ) => $row['name'] === 'Aura Perception' ) );
		$this->assertSame( 'Auspex', $level['family'] );
		$this->assertSame( 'cmp-power', $level['block_slug'] );
	}

	public function test_search_matches_a_portuguese_name(): void {
		$found = $this->get( $this->player, '/search', [ 'stack' => 'cmp-stack', 'q' => 'ocult' ] )->get_data();

		$this->assertSame( [ 'Cmp Occult' ], array_column( $found, 'name' ) );
	}

	public function test_search_needs_two_letters_and_never_lists_a_hidden_block(): void {
		$this->assertSame( [], $this->get( $this->player, '/search', [ 'stack' => 'cmp-stack', 'q' => 'a' ] )->get_data() );
		$this->assertSame( [], $this->get( $this->player, '/search', [ 'stack' => 'cmp-stack', 'q' => 'Dark Matter' ] )->get_data() );
		$this->assertCount( 1, $this->get( $this->hst, '/search', [ 'stack' => 'cmp-stack', 'q' => 'Dark Matter' ] )->get_data() );
	}

	public function test_search_stops_at_fifty_names(): void {
		$items = [];
		for ( $i = 1; $i <= 60; $i++ ) {
			$items[] = [ 'name' => "Needle {$i}", 'cost' => '1' ];
		}
		Schema_Block::update( 'cmp-merits', [ 'definition' => [ 'items' => $items ] ] );

		$found = $this->get( $this->player, '/search', [ 'stack' => 'cmp-stack', 'q' => 'needle' ] )->get_data();

		$this->assertCount( 50, $found );
	}

	public function test_search_in_a_type_the_person_may_not_see_is_not_found(): void {
		$this->assertSame( 404, $this->get( $this->player, '/search', [ 'stack' => 'cmp-st-stack', 'q' => 'Occult' ] )->get_status() );
	}
}
