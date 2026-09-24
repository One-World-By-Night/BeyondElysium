<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Schema_Block;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * `group`, `subgroup` and `tier` survive a real REST round trip, on the shared catalog block and on a chronicle's own
 * fork of it.
 */
class CatalogFacetRoundTripThreadTest extends WP_UnitTestCase {

	private string $slug = 'thread-test-facet-block';

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		Schema_Block::create( [
			'slug'         => $this->slug,
			'name'         => 'Thread Test Facet Block',
			'section_type' => 'trait_list',
			'definition'   => [ 'items' => [ [ 'name' => 'Mother\'s Touch' ] ] ],
			'is_system'    => 1,
		] );
	}

	/**
	 * @param array<int,array<string,mixed>> $items
	 */
	private function save( array $items, string $game_slug = '' ) {
		// A chronicle's own block is written through that chronicle's route.
		$route   = '' === $game_slug
			? '/be/v1/schema-blocks/' . $this->slug
			: '/be/v1/' . $game_slug . '/schema-blocks/' . $this->slug;
		$request = new WP_REST_Request( 'PUT', $route );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( (string) wp_json_encode( [ 'definition' => [ 'items' => $items ] ] ) );
		return rest_get_server()->dispatch( $request );
	}

	public function test_all_three_facets_survive_a_save_on_the_catalog_block(): void {
		$response = $this->save( [
			[
				'name'     => 'Mother\'s Touch',
				'group'    => 'Theurge',
				'subgroup' => 'Healing',
				'tier'     => 'basic',
			],
		] );

		$this->assertSame( 200, $response->get_status() );
		$item = $response->get_data()->definition->items[0];
		$this->assertSame( 'Theurge', $item->group );
		$this->assertSame( 'Healing', $item->subgroup );
		$this->assertSame( 'basic', $item->tier );
	}

	public function test_a_chronicle_fork_keeps_its_own_facets_and_leaves_the_catalog_alone(): void {
		$this->save( [
			[ 'name' => 'Mother\'s Touch', 'group' => 'Theurge', 'tier' => 'basic' ],
		] );

		$forked = $this->save(
			[ [ 'name' => 'Mother\'s Touch', 'group' => 'House Rule', 'tier' => 'intermediate' ] ],
			'facet-test-game'
		);
		$this->assertSame( 200, $forked->get_status() );

		$for_game = Schema_Block::find_for_game( $this->slug, 'facet-test-game' );
		$this->assertSame( 'House Rule', $for_game->definition->items[0]->group );
		$this->assertSame( 'intermediate', $for_game->definition->items[0]->tier );

		// The shared catalog is never written through a fork.
		$global = Schema_Block::find_by_slug( $this->slug );
		$this->assertSame( 'Theurge', $global->definition->items[0]->group );
		$this->assertSame( 'basic', $global->definition->items[0]->tier );
	}

	public function test_a_facet_is_stored_as_plain_text_never_markup(): void {
		// Catalog vocabulary, not rich text.
		$response = $this->save( [
			[ 'name' => 'Mother\'s Touch', 'group' => '<script>alert(1)</script>Theurge' ],
		] );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame(
			'Theurge',
			$response->get_data()->definition->items[0]->group,
			'a script tag and its contents must not survive into the catalog'
		);
	}

	public function test_an_emptied_facet_is_removed_rather_than_stored_blank(): void {
		$this->save( [ [ 'name' => 'Mother\'s Touch', 'group' => 'Theurge' ] ] );

		$response = $this->save( [ [ 'name' => 'Mother\'s Touch', 'group' => '   ' ] ] );

		$this->assertSame( 200, $response->get_status() );
		$this->assertFalse( property_exists( $response->get_data()->definition->items[0], 'group' ) );
	}

	public function test_an_untouched_facet_is_not_invented(): void {
		$response = $this->save( [ [ 'name' => 'Mother\'s Touch' ] ] );

		$this->assertSame( 200, $response->get_status() );
		$item = $response->get_data()->definition->items[0];
		$this->assertFalse( property_exists( $item, 'group' ) );
		$this->assertFalse( property_exists( $item, 'subgroup' ) );
		$this->assertFalse( property_exists( $item, 'tier' ) );
	}
}
