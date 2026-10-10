<?php

namespace BeyondElysium\Tests\Unit;

use BeyondElysium\Services\Creation_Tally;
use PHPUnit\Framework\TestCase;

/**
 * The creation tally names what no starting experience can buy: the dots of a pool that is only ever raised in play,
 * past the free dots the creation rules give it.
 */
class CreationTallyUnbuyableTest extends TestCase {

	private static function obj( array $data ): object {
		return json_decode( (string) json_encode( $data ) );
	}

	/** @param array<int,array<string,mixed>> $steps */
	private static function stack( array $steps ): object {
		return self::obj( [
			'slug'             => 'test-hunt',
			'stack_definition' => [ 'sections' => [
				[ 'block_slug' => 'test-virtues', 'label' => 'Virtues', 'display_order' => 1 ],
				[ 'block_slug' => 'test-resources', 'label' => 'Resources', 'display_order' => 2 ],
			] ],
			'creation_rules'   => [ 'steps' => $steps ],
		] );
	}

	private static function blocks(): array {
		$raised = [ 'from' => 'test-resources.Conviction', 'temporary' => 10 ];
		return [
			'test-virtues'   => self::obj( [ 'section_type' => 'resource_pool', 'definition' => [ 'pools' => [
				[ 'name' => 'Mercy', 'default_start' => 0, 'max' => 10, 'raised_by' => $raised ],
				[ 'name' => 'Vision', 'default_start' => 0, 'max' => 10, 'raised_by' => $raised ],
				[ 'name' => 'Zeal', 'default_start' => 0, 'max' => 10, 'raised_by' => $raised ],
			] ] ] ),
			'test-resources' => self::obj( [ 'section_type' => 'resource_pool', 'definition' => [ 'pools' => [
				[ 'name' => 'Conviction', 'default_start' => 0, 'max' => 10, 'cost_per_dot' => 1 ],
			] ] ] ),
		];
	}

	private static function virtues( int $mercy, int $vision, int $zeal ): array {
		$pool = static fn( int $n ): array => [ 'permanent' => $n, 'temporary' => $n ];
		return [ 'test-virtues' => [ 'Mercy' => $pool( $mercy ), 'Vision' => $pool( $vision ), 'Zeal' => $pool( $zeal ) ] ];
	}

	private static function free_virtues(): array {
		return [ [ 'kind' => 'budget', 'label' => 'Virtues', 'section' => 'test-virtues', 'count' => 3 ] ];
	}

	public function test_the_free_dots_the_creation_rules_give_are_not_unbuyable(): void {
		$tally = Creation_Tally::for_stack( self::stack( self::free_virtues() ), self::blocks(), self::virtues( 2, 1, 0 ) );

		$this->assertSame( [], $tally['unbuyable'] );
	}

	public function test_a_dot_past_the_free_ones_is_unbuyable_and_names_its_pool(): void {
		$tally = Creation_Tally::for_stack( self::stack( self::free_virtues() ), self::blocks(), self::virtues( 3, 1, 0 ) );

		$this->assertSame(
			[ [ 'kind' => 'raised_by', 'section' => 'test-virtues', 'pool' => 'Vision', 'dots' => 1 ] ],
			$tally['unbuyable']
		);
	}

	public function test_a_pool_no_step_covers_is_unbuyable_in_full(): void {
		$tally = Creation_Tally::for_stack( self::stack( [] ), self::blocks(), self::virtues( 0, 0, 2 ) );

		$this->assertSame(
			[ [ 'kind' => 'raised_by', 'section' => 'test-virtues', 'pool' => 'Zeal', 'dots' => 2 ] ],
			$tally['unbuyable']
		);
	}

	public function test_a_pool_bought_with_experience_is_never_unbuyable(): void {
		$sheet = [ 'test-resources' => [ 'Conviction' => [ 'permanent' => 6, 'temporary' => 6 ] ] ];

		$tally = Creation_Tally::for_stack( self::stack( [] ), self::blocks(), $sheet );

		$this->assertSame( [], $tally['unbuyable'] );
	}

	private static function balance_blocks(): array {
		return [
			'test-virtues' => self::obj( [ 'section_type' => 'resource_pool', 'definition' => [ 'pools' => [
				[ 'name' => 'Balance', 'default_start' => 1, 'max' => 10, 'book_max' => 5, 'sliding_cost' => [ 'equals_level' => true ] ],
			] ] ] ),
		];
	}

	public function test_a_pool_above_its_book_maximum_is_unbuyable_and_names_the_limit(): void {
		$sheet = [ 'test-virtues' => [ 'Balance' => [ 'permanent' => 6, 'temporary' => 6 ] ] ];

		$tally = Creation_Tally::for_stack( self::stack( [] ), self::balance_blocks(), $sheet );

		$this->assertSame(
			[ [ 'kind' => 'book_max', 'section' => 'test-virtues', 'pool' => 'Balance', 'value' => 6, 'max' => 5 ] ],
			$tally['unbuyable']
		);
	}

	public function test_a_pool_at_its_book_maximum_is_not_unbuyable(): void {
		$sheet = [ 'test-virtues' => [ 'Balance' => [ 'permanent' => 5, 'temporary' => 5 ] ] ];

		$tally = Creation_Tally::for_stack( self::stack( [] ), self::balance_blocks(), $sheet );

		$this->assertSame( [], $tally['unbuyable'] );
	}

	public function test_a_creature_type_with_no_creation_rules_reports_none(): void {
		$stack = self::obj( [ 'slug' => 'test-hunt', 'stack_definition' => [ 'sections' => [] ] ] );

		$tally = Creation_Tally::for_stack( $stack, self::blocks(), self::virtues( 9, 9, 9 ) );

		$this->assertSame( [], $tally['unbuyable'] );
	}
}
