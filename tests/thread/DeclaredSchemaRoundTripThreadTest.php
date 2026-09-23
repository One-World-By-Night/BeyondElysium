<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Schema_Block;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * 1.2.10 E6 - every field the declared schema added survives a real REST round trip, on the
 * shared catalog block and on a chronicle's own fork of it.
 *
 * **There is no whitelist to extend, which is exactly why this test exists.**
 * `Schema_Blocks_Controller::validate_definition()` checks only that the section_type's own
 * required top-level key is present and rejects nothing else, and `sanitize_definition()` is
 * passthrough-with-narrowing - it runs `description` through `Rich_Text_Sanitizer` and the
 * three facet fields through `sanitize_facets()`, and returns every other key untouched. So
 * `_meta`, `category_values`, `untiered` and `sliding_cost` survive *by construction* rather
 * than by anything deliberate. That is a property worth pinning: the moment someone adds a
 * real whitelist - a reasonable thing to want - these fields would be dropped silently, and
 * the catalog would seed one shape while the editor saved another.
 *
 * Sibling of `CatalogFacetRoundTripThreadTest`, which proves the same for 1.2.9's facets.
 */
class DeclaredSchemaRoundTripThreadTest extends WP_UnitTestCase {

	private string $tiered = 'thread-test-declared-tiered';
	private string $pool   = 'thread-test-declared-pool';

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		Schema_Block::create( [
			'slug'         => $this->tiered,
			'name'         => 'Thread Test Declared Tiered',
			'section_type' => 'tiered_power',
			'definition'   => [ 'powers' => [ [ 'name' => 'Animalism', 'levels' => [] ] ] ],
			'is_system'    => 1,
		] );

		Schema_Block::create( [
			'slug'         => $this->pool,
			'name'         => 'Thread Test Declared Pool',
			'section_type' => 'resource_pool',
			'definition'   => [ 'pools' => [ [ 'name' => 'Balance', 'value_type' => 'integer', 'default_start' => 0 ] ] ],
			'is_system'    => 1,
		] );
	}

	/**
	 * @param array<string,mixed> $definition
	 */
	private function save( string $slug, array $definition, string $game_slug = '' ) {
		// A chronicle's own block is written through that chronicle's route, never a
		// `game_slug` in the body - `write_scope()` rejects the latter outright.
		$route   = '' === $game_slug
			? '/be/v1/schema-blocks/' . $slug
			: '/be/v1/' . $game_slug . '/schema-blocks/' . $slug;
		$request = new WP_REST_Request( 'PUT', $route );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( (string) wp_json_encode( [ 'definition' => $definition ] ) );
		return rest_get_server()->dispatch( $request );
	}

	/**
	 * The whole `_meta` block (S1/S5/S7): ranks, ladder, costs, out_of_type, the rank-to-level
	 * mapping a genre with non-consecutive ranks needs, and an untiered track's declaration.
	 */
	public function test_the_declared_meta_survives_a_save(): void {
		$response = $this->save( $this->tiered, [
			'_meta'  => [
				'ranks'       => [ 'basic', 'intermediate', 'advanced', 'elder' ],
				'ladder'      => [ 'basic' => 2, 'intermediate' => 2, 'advanced' => 1 ],
				'costs'       => [ 'basic' => 3, 'intermediate' => 6, 'advanced' => 9, 'elder' => 12 ],
				'out_of_type' => [ 'basic' => '+1', 'elder' => '×2' ],
				'levels'      => [ 'basic' => 1, 'intermediate' => 3, 'advanced' => 5 ],
				'categories'  => [ 'breed', 'tribe', 'auspice' ],
			],
			'powers' => [ [ 'name' => 'Animalism', 'levels' => [] ] ],
		] );

		$this->assertSame( 200, $response->get_status() );
		$meta = $response->get_data()->definition->_meta;

		$this->assertSame( [ 'basic', 'intermediate', 'advanced', 'elder' ], $meta->ranks );
		$this->assertSame( 2, $meta->ladder->basic );
		$this->assertSame( 12, $meta->costs->elder );
		// The modifier is an expression, not a number - Demon's ×2 must survive as typed.
		$this->assertSame( '×2', $meta->out_of_type->elder );
		$this->assertSame( 5, $meta->levels->advanced, 'S5: Gifts sit at levels 1/3/5, not 1/2/3' );
		$this->assertSame( [ 'breed', 'tribe', 'auspice' ], $meta->categories );
	}

	/** S7: an untiered track declares its own cost rather than relying on a fallback. */
	public function test_an_untiered_declaration_survives_a_save(): void {
		$response = $this->save( $this->tiered, [
			'_meta'  => [
				'ranks'    => [],
				'ladder'   => [],
				'costs'    => [],
				'untiered' => [ 'cost_per_level' => 2 ],
			],
			'powers' => [ [ 'name' => 'Actor', 'levels' => [] ] ],
		] );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 2, $response->get_data()->definition->_meta->untiered->cost_per_level );

		$derived = $this->save( $this->tiered, [
			'_meta'  => [
				'ranks'    => [],
				'ladder'   => [],
				'costs'    => [],
				'untiered' => [ 'derived_from' => 'mage-spheres', 'per_level' => 1 ],
			],
			'powers' => [ [ 'name' => 'Rote', 'levels' => [] ] ],
		] );

		$this->assertSame( 200, $derived->get_status() );
		$untiered = $derived->get_data()->definition->_meta->untiered;
		$this->assertSame( 'mage-spheres', $untiered->derived_from );
		$this->assertSame( 1, $untiered->per_level );
	}

	/** S2/S2b/S4: the three containers, and the axis a family is filed under. */
	public function test_the_three_containers_and_category_values_survive_a_save(): void {
		$response = $this->save( $this->tiered, [
			'_meta'  => [
				'ranks'      => [ 'basic', 'intermediate', 'advanced', 'elder' ],
				'ladder'     => [ 'basic' => 2, 'intermediate' => 2, 'advanced' => 1 ],
				'costs'      => [ 'basic' => 3 ],
				'categories' => [ 'species', 'subgroup' ],
			],
			'powers' => [
				[
					'name'            => 'Bagheera',
					'levels'          => [ [ 'level' => 1, 'tier' => 'basic', 'power_name' => 'Feral Whispers' ] ],
					'elder'           => [ 'elder' => [ [ 'tier' => 'elder', 'power_name' => 'Animal Succulence' ] ] ],
					'overflow'        => [ [ 'tier' => 'basic', 'power_name' => 'Beast Within' ] ],
					'category_values' => [ 'species' => 'Bastet', 'subgroup' => 'Bagheera' ],
				],
			],
		] );

		$this->assertSame( 200, $response->get_status() );
		$power = $response->get_data()->definition->powers[0];

		$this->assertCount( 1, $power->levels );
		// `elder` is the section; the keys inside it are ranks. The two depths must not flatten.
		$this->assertSame( 'Animal Succulence', $power->elder->elder[0]->power_name );
		$this->assertCount( 1, $power->overflow );
		$this->assertSame( 'Bastet', $power->category_values->species );
		$this->assertSame( 'Bagheera', $power->category_values->subgroup );
	}

	/** S8: Mummy Balance's sliding cost, which `cost_per_dot` structurally cannot express. */
	public function test_a_sliding_pool_cost_survives_a_save(): void {
		$response = $this->save( $this->pool, [
			'pools' => [
				[
					'name'          => 'Balance',
					'value_type'    => 'integer',
					'default_start' => 0,
					'sliding_cost'  => [ 'equals_level' => true ],
				],
			],
		] );

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $response->get_data()->definition->pools[0]->sliding_cost->equals_level );
	}

	/**
	 * A chronicle that forks the block keeps its own declared meta, and the shared catalog is
	 * never written through the fork (1.0.0-review F-109). This is the case that matters for a
	 * house rule: a chronicle repricing its own ladder must not reprice everyone's.
	 */
	public function test_a_chronicle_fork_keeps_its_own_declared_meta(): void {
		$this->save( $this->tiered, [
			'_meta'  => [
				'ranks'  => [ 'basic' ],
				'ladder' => [ 'basic' => 2 ],
				'costs'  => [ 'basic' => 3 ],
			],
			'powers' => [ [ 'name' => 'Animalism', 'levels' => [] ] ],
		] );

		$forked = $this->save(
			$this->tiered,
			[
				'_meta'  => [
					'ranks'  => [ 'basic' ],
					'ladder' => [ 'basic' => 2 ],
					'costs'  => [ 'basic' => 5 ],
				],
				'powers' => [ [ 'name' => 'Animalism', 'levels' => [] ] ],
			],
			'declared-meta-test-game'
		);
		$this->assertSame( 200, $forked->get_status() );

		$for_game = Schema_Block::find_for_game( $this->tiered, 'declared-meta-test-game' );
		$this->assertSame( 5, $for_game->definition->_meta->costs->basic, 'the fork holds its own house rate' );

		$global = Schema_Block::find_by_slug( $this->tiered );
		$this->assertSame( 3, $global->definition->_meta->costs->basic, 'the shared catalog is untouched' );
	}

	/** Nothing is invented: a block that declares no meta does not acquire one on save. */
	public function test_a_block_with_no_meta_does_not_gain_one(): void {
		$response = $this->save( $this->tiered, [ 'powers' => [ [ 'name' => 'Animalism', 'levels' => [] ] ] ] );

		$this->assertSame( 200, $response->get_status() );
		$this->assertFalse( property_exists( $response->get_data()->definition, '_meta' ) );
	}
}
