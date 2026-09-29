<?php

namespace BeyondElysium\Tests\Unit;

use BeyondElysium\Services\Cost_Engine;
use PHPUnit\Framework\TestCase;

/**
 * The cost expressions a block declares: per-rank modifiers on both sides, an untiered track's cost per level, a list's
 * costs derived from its items' prerequisites, and a pool priced per level.
 */
class CostEvaluatorsTest extends TestCase {

	private static function definition( array $data ) {
		return json_decode( json_encode( $data ) );
	}

	/**
	 * Mage Spheres' shape: five rungs in three price bands, the out-of-type modifier scaling by band.
	 */
	private static function spheres(): object {
		$levels = [];
		foreach ( [ 1 => 'basic', 2 => 'basic', 3 => 'intermediate', 4 => 'intermediate', 5 => 'advanced' ] as $level => $tier ) {
			$levels[] = [ 'level' => $level, 'tier' => $tier, 'power_name' => "Forces {$level}", 'cost' => (string) [ 'basic' => 4, 'intermediate' => 8, 'advanced' => 12 ][ $tier ] ];
		}
		return self::definition( [
			'_meta'      => [
				'ranks'       => [ 'basic', 'intermediate', 'advanced' ],
				'ladder'      => [ 'basic' => 2, 'intermediate' => 2, 'advanced' => 1 ],
				'costs'       => [ 'basic' => 4, 'intermediate' => 8, 'advanced' => 12 ],
				'out_of_type' => [ 'basic' => '+1', 'intermediate' => '+2', 'advanced' => '+3' ],
			],
			'sequential' => true,
			'powers'     => [ [ 'name' => 'Forces', 'levels' => $levels, 'elder' => [] ] ],
		] );
	}

	/**
	 * Wraith Arcanoi's shape: Innate picks below a five-rung ladder, the in-type side one cheaper except Innate.
	 */
	private static function arcanoi(): object {
		$levels = [];
		foreach ( [ 1 => 'basic', 2 => 'basic', 3 => 'intermediate', 4 => 'intermediate', 5 => 'advanced' ] as $level => $tier ) {
			$levels[] = [ 'level' => $level, 'tier' => $tier, 'power_name' => "Castigate {$level}", 'cost' => (string) [ 'basic' => 4, 'intermediate' => 6, 'advanced' => 9 ][ $tier ] ];
		}
		return self::definition( [
			'_meta'      => [
				'ranks'   => [ 'innate', 'basic', 'intermediate', 'advanced' ],
				'ladder'  => [ 'basic' => 2, 'intermediate' => 2, 'advanced' => 1 ],
				'costs'   => [ 'innate' => 2, 'basic' => 4, 'intermediate' => 6, 'advanced' => 9 ],
				'in_type' => [ 'innate' => '+0', 'basic' => '-1', 'intermediate' => '-1', 'advanced' => '-1' ],
			],
			'sequential' => true,
			'powers'     => [
				[
					'name'   => 'Castigate',
					'levels' => $levels,
					'elder'  => [ 'innate' => [ [ 'level' => null, 'tier' => 'innate', 'power_name' => 'House Cleaning', 'aliases' => [ 'Housecleaning' ], 'cost' => '2' ] ] ],
				],
			],
		] );
	}

	public function test_modifier_expressions_add_subtract_and_multiply_and_never_go_below_zero(): void {
		$this->assertSame( 5, Cost_Engine::apply_modifier( 4, '+1' ) );
		$this->assertSame( 3, Cost_Engine::apply_modifier( 4, '-1' ) );
		$this->assertSame( 4, Cost_Engine::apply_modifier( 4, '+0' ) );
		$this->assertSame( 8, Cost_Engine::apply_modifier( 4, '×2' ) );
		$this->assertSame( 8, Cost_Engine::apply_modifier( 4, 'x2' ) );
		$this->assertSame( 12, Cost_Engine::apply_modifier( 4, '*3' ) );
		$this->assertSame( 0, Cost_Engine::apply_modifier( 3, '-5' ), 'a discount never pays the player' );
		$this->assertSame( 4, Cost_Engine::apply_modifier( 4, null ) );
		$this->assertSame( 4, Cost_Engine::apply_modifier( 4, 'double' ) );
	}

	public function test_modifier_grammar(): void {
		foreach ( [ '+1', '-1', '+0', '×2', 'x2', '*2', ' +3 ' ] as $good ) {
			$this->assertTrue( Cost_Engine::is_modifier( $good ), $good );
		}
		foreach ( [ '1', '+', '+1.5', 'double', '', '++1' ] as $bad ) {
			$this->assertFalse( Cost_Engine::is_modifier( $bad ), $bad );
		}
	}

	public function test_an_out_of_type_sphere_pays_each_rungs_own_modifier(): void {
		$trait = [ 'block_slug' => 'mage-spheres', 'trait' => [ 'name' => 'Forces', 'level' => 5 ] ];

		$this->assertSame( 36, Cost_Engine::price_tiered_power_change( [], self::spheres(), 'add_trait', $trait, true ), '4+4+8+8+12' );
		$this->assertSame( 45, Cost_Engine::price_tiered_power_change( [], self::spheres(), 'add_trait', $trait, false ), '5+5+10+10+15' );
	}

	public function test_raising_an_out_of_type_sphere_pays_only_the_new_rungs(): void {
		$sheet = [ 'mage-spheres' => [ [ 'name' => 'Forces', 'level' => 2 ] ] ];
		$trait = [ 'block_slug' => 'mage-spheres', 'trait' => [ 'name' => 'Forces', 'level' => 4 ] ];

		$this->assertSame( 20, Cost_Engine::price_tiered_power_change( $sheet, self::spheres(), 'modify_trait', $trait, false ), '10+10' );
	}

	public function test_a_held_sphere_is_audited_at_its_sides_price(): void {
		$held = [ 'name' => 'Forces', 'level' => 3 ];

		$this->assertSame( 16, Cost_Engine::price_held_tiered_power( self::spheres(), $held, true )['xp'] );
		$this->assertSame( 20, Cost_Engine::price_held_tiered_power( self::spheres(), $held, false )['xp'] );
		$this->assertSame( 16, Cost_Engine::price_held_tiered_power( self::spheres(), $held, null )['xp'], 'no side asked for, no modifier' );
	}

	public function test_an_in_type_arcanos_is_one_cheaper_on_every_rung_and_innate_is_not(): void {
		$trait = [ 'block_slug' => 'wraith-arcanoi', 'trait' => [ 'name' => 'Castigate', 'level' => 3 ] ];
		$pick  = [ 'block_slug' => 'wraith-arcanoi', 'trait' => [ 'name' => 'Castigate', 'power_name' => 'House Cleaning' ] ];

		$this->assertSame( 11, Cost_Engine::price_tiered_power_change( [], self::arcanoi(), 'add_trait', $trait, true ), '3+3+5' );
		$this->assertSame( 14, Cost_Engine::price_tiered_power_change( [], self::arcanoi(), 'add_trait', $trait, false ), '4+4+6' );
		$this->assertSame( 2, Cost_Engine::price_tiered_power_change( [], self::arcanoi(), 'add_trait', $pick, true ) );
	}

	public function test_a_pick_held_under_an_alias_is_priced_as_its_pick(): void {
		$result = Cost_Engine::price_held_tiered_power( self::arcanoi(), [ 'name' => 'Castigate', 'power_name' => 'Housecleaning' ], false );

		$this->assertSame( 2, $result['xp'] );
	}

	public function test_a_multiplying_modifier_doubles_an_out_of_type_pick(): void {
		$definition = self::definition( [
			'_meta'  => [
				'ranks'       => [ 'basic', 'intermediate', 'advanced' ],
				'ladder'      => [],
				'costs'       => [ 'basic' => 3, 'intermediate' => 6, 'advanced' => 9 ],
				'out_of_type' => [ 'basic' => '×2', 'intermediate' => '×2', 'advanced' => '×2' ],
			],
			'powers' => [
				[
					'name'   => 'Form',
					'levels' => [],
					'elder'  => [ 'intermediate' => [ [ 'level' => null, 'tier' => 'intermediate', 'power_name' => 'Claws', 'cost' => '6' ] ] ],
				],
			],
		] );
		$pick = [ 'block_slug' => 'demon-lores', 'trait' => [ 'name' => 'Form', 'power_name' => 'Claws' ] ];

		$this->assertSame( 12, Cost_Engine::price_tiered_power_change( [], $definition, 'add_trait', $pick, false ) );
		$this->assertSame( 6, Cost_Engine::price_tiered_power_change( [], $definition, 'add_trait', $pick, true ) );
	}

	public function test_the_retired_flat_modifier_is_not_read(): void {
		$definition = self::definition( [
			'out_of_type_cost_modifier' => 5,
			'sequential'                => true,
			'powers'                    => [ [ 'name' => 'Celerity', 'levels' => [ [ 'level' => 1, 'cost' => '3' ] ] ] ],
		] );
		$trait = [ 'block_slug' => 'disciplines', 'trait' => [ 'name' => 'Celerity', 'level' => 1 ] ];

		$this->assertSame( 3, Cost_Engine::price_tiered_power_change( [], $definition, 'add_trait', $trait, false ) );
	}

	/**
	 * Changeling Realms' shape: no ranks, a flat cost for every level.
	 */
	private static function realms(): object {
		$levels = [];
		foreach ( range( 1, 5 ) as $level ) {
			$levels[] = [ 'level' => $level, 'tier' => null, 'power_name' => "Actor {$level}" ];
		}
		return self::definition( [
			'_meta'      => [ 'ranks' => [], 'ladder' => [], 'costs' => [], 'untiered' => [ 'cost_per_level' => 2 ] ],
			'sequential' => true,
			'powers'     => [ [ 'name' => 'Actor', 'levels' => $levels ] ],
		] );
	}

	public function test_an_untiered_track_costs_its_cost_per_level_for_each_level(): void {
		$trait = [ 'block_slug' => 'changeling-realms', 'trait' => [ 'name' => 'Actor', 'level' => 3 ] ];

		$this->assertSame( 6, Cost_Engine::price_tiered_power_change( [], self::realms(), 'add_trait', $trait, true ) );
	}

	public function test_a_held_untiered_level_is_priced_and_one_past_its_levels_is_not(): void {
		$three = Cost_Engine::price_held_tiered_power( self::realms(), [ 'name' => 'Actor', 'level' => 3 ], true );
		$six   = Cost_Engine::price_held_tiered_power( self::realms(), [ 'name' => 'Actor', 'level' => 6 ], true );

		$this->assertSame( 6, $three['xp'] );
		$this->assertNull( $three['unpriced_reason'] );
		$this->assertNull( $six['xp'] );
	}

	/**
	 * Mage Rotes' shape: an atomic list whose items cost one per Sphere level their prerequisites name.
	 */
	private static function rotes( int $per_level = 1 ): object {
		return self::definition( [
			'_meta'  => [ 'untiered' => [ 'derived_from' => 'mage-spheres', 'per_level' => $per_level ] ],
			'atomic' => true,
			'items'  => [
				[
					'name'          => 'Access This',
					'cost'          => null,
					'prerequisites' => [
						[ 'block_slug' => 'mage-spheres', 'power' => 'Correspondence', 'min_level' => 2 ],
						[ 'block_slug' => 'mage-spheres', 'power' => 'Forces', 'min_level' => 2 ],
					],
				],
				[
					'name'          => 'Borrowed Rite',
					'cost'          => null,
					'prerequisites' => [
						[ 'block_slug' => 'mage-spheres', 'power' => 'Spirit', 'min_level' => 3 ],
						[ 'block_slug' => 'vampire-disciplines', 'power' => 'Auspex', 'min_level' => 2 ],
					],
				],
				[ 'name' => 'Priced Rote', 'cost' => '7', 'prerequisites' => [ [ 'block_slug' => 'mage-spheres', 'power' => 'Life', 'min_level' => 1 ] ] ],
				[ 'name' => 'Unstated Rote', 'cost' => null, 'prerequisites' => [] ],
			],
		] );
	}

	public function test_a_rote_costs_one_for_each_sphere_level_its_prerequisites_name(): void {
		$add = static fn( string $name ): array => [ 'block_slug' => 'mage-rotes', 'trait' => [ 'name' => $name, 'count' => 1 ] ];

		$this->assertSame( 4, Cost_Engine::price_trait_list_change( [], self::rotes(), 'mage-rotes', 'add_trait', $add( 'Access This' ) ) );
		$this->assertSame( 3, Cost_Engine::price_trait_list_change( [], self::rotes(), 'mage-rotes', 'add_trait', $add( 'Borrowed Rite' ) ), 'only the Spheres count' );
		$this->assertSame( 7, Cost_Engine::price_trait_list_change( [], self::rotes(), 'mage-rotes', 'add_trait', $add( 'Priced Rote' ) ), 'an item\'s own cost stands' );
		$this->assertSame( 8, Cost_Engine::price_trait_list_change( [], self::rotes( 2 ), 'mage-rotes', 'add_trait', $add( 'Access This' ) ) );
	}

	public function test_a_held_rote_is_audited_at_its_derived_cost(): void {
		$held = Cost_Engine::price_held_trait_list_item( self::rotes(), [ 'name' => 'Access This', 'count' => 1 ] );
		$none = Cost_Engine::price_held_trait_list_item( self::rotes(), [ 'name' => 'Unstated Rote', 'count' => 1 ] );

		$this->assertSame( 4, $held['xp'] );
		$this->assertNull( $none['xp'], 'a rote with nothing to derive from is unpriced, never free' );
		$this->assertSame( 'catalog_item_has_no_cost', $none['unpriced_reason'] );
	}

	/**
	 * Mummy Balance's shape: each dot costs its own level.
	 */
	private static function balance( ?int $free = null ): object {
		$pool = [ 'name' => 'Balance', 'default_start' => 1, 'max' => 10, 'sliding_cost' => [ 'equals_level' => true ] ];
		if ( $free !== null ) {
			$pool['free_dots'] = $free;
		}
		return self::definition( [ 'pools' => [ $pool ] ] );
	}

	public function test_raising_balance_costs_each_new_dots_own_level(): void {
		$sheet = [ 'mummy-resources' => [ 'Balance' => [ 'permanent' => 1, 'temporary' => 1 ] ] ];

		$this->assertSame( 5, Cost_Engine::price_resource_pool_change( $sheet, self::balance( 1 ), 'mummy-resources', [ 'values' => [ 'Balance' => [ 'permanent' => 3 ] ] ] ), '2+3' );
		$this->assertSame( 0, Cost_Engine::price_resource_pool_change( $sheet, self::balance( 1 ), 'mummy-resources', [ 'values' => [ 'Balance' => [ 'permanent' => 1, 'temporary' => 0 ] ] ] ), 'spending temporary Balance costs nothing' );
	}

	public function test_lowering_balance_returns_the_same_levels(): void {
		$sheet = [ 'mummy-resources' => [ 'Balance' => [ 'permanent' => 4, 'temporary' => 4 ] ] ];

		$this->assertSame( -7, Cost_Engine::price_resource_pool_change( $sheet, self::balance( 1 ), 'mummy-resources', [ 'values' => [ 'Balance' => [ 'permanent' => 2 ] ] ] ), '3+4' );
	}

	public function test_held_balance_is_audited_from_its_free_dots(): void {
		$this->assertSame( 6, Cost_Engine::price_held_resource_pool( self::balance(), 'Balance', [ 'permanent' => 3 ] )['xp'], '1+2+3' );
		$this->assertSame( 5, Cost_Engine::price_held_resource_pool( self::balance( 1 ), 'Balance', [ 'permanent' => 3 ] )['xp'], '2+3' );
	}

	/**
	 * A buy-down pool's shape: Torment, priced by how far it drops below its own starting value.
	 */
	private static function torment(): object {
		return self::definition( [ 'pools' => [ [ 'name' => 'Torment', 'default_start' => 4, 'max' => 10, 'cost_per_dot' => 5, 'buy_down' => true ] ] ] );
	}

	public function test_lowering_a_buy_down_pool_costs_each_point_dropped(): void {
		$sheet = [ 'demon-resources' => [ 'Torment' => [ 'permanent' => 4, 'temporary' => 4 ] ] ];

		$this->assertSame( 10, Cost_Engine::price_resource_pool_change( $sheet, self::torment(), 'demon-resources', [ 'values' => [ 'Torment' => [ 'permanent' => 2 ] ] ] ), '2 points at 5 each' );
	}

	public function test_raising_a_buy_down_pool_back_toward_its_start_costs_nothing(): void {
		$sheet = [ 'demon-resources' => [ 'Torment' => [ 'permanent' => 1, 'temporary' => 1 ] ] ];

		$this->assertSame( 0, Cost_Engine::price_resource_pool_change( $sheet, self::torment(), 'demon-resources', [ 'values' => [ 'Torment' => [ 'permanent' => 3 ] ] ] ), 'never a refund' );
	}

	public function test_held_buy_down_pool_is_audited_from_its_starting_value(): void {
		$this->assertSame( 10, Cost_Engine::price_held_resource_pool( self::torment(), 'Torment', [ 'permanent' => 2 ] )['xp'], '2 points below start, at 5 each' );
		$this->assertSame( 0, Cost_Engine::price_held_resource_pool( self::torment(), 'Torment', [ 'permanent' => 4 ] )['xp'], 'at its own start' );
	}
}
