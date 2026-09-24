<?php

namespace BeyondElysium\Tests\Unit;

use BeyondElysium\Services\Cost_Engine;
use BeyondElysium\Services\Trait_Identity;
use PHPUnit\Framework\TestCase;

/**
 * The one rule every consumer reads: a held `trait_list` row's identity is its `name` alone, unless the item (or, as
 * a default, the block) carries `allow_multiples`, in which case the specialization label is part of it.
 */
class TraitIdentityTest extends TestCase {

	/**
	 * Real nested-stdClass shape, matching how Schema_Block::decode_definition() decodes.
	 */
	private static function definition( array $data ) {
		return json_decode( json_encode( $data ) );
	}

	/**
	 * Backgrounds-shaped: Retainers is repeatable, Generation is not.
	 */
	private static function backgrounds() {
		return self::definition( [
			'has_specializations' => true,
			'items'               => [
				[ 'name' => 'Retainers', 'cost' => '1', 'allow_multiples' => true ],
				[ 'name' => 'Generation', 'cost' => '1' ],
			],
		] );
	}

	/**
	 * Abilities-shaped: specializations, no multiples anywhere.
	 */
	private static function abilities() {
		return self::definition( [
			'has_specializations' => true,
			'items'               => [ [ 'name' => 'Brawl', 'cost' => '1' ] ],
		] );
	}

	public function test_an_item_states_its_own_rule(): void {
		$this->assertTrue( Trait_Identity::allows_multiples( self::backgrounds(), 'Retainers' ) );
		$this->assertFalse( Trait_Identity::allows_multiples( self::backgrounds(), 'Generation' ) );
	}

	public function test_an_item_with_no_rule_takes_the_block_default(): void {
		$permissive = self::definition( [ 'allow_multiples' => true, 'items' => [ [ 'name' => 'Lore' ] ] ] );
		$this->assertTrue( Trait_Identity::allows_multiples( $permissive, 'Lore' ) );
		$this->assertFalse( Trait_Identity::allows_multiples( self::abilities(), 'Brawl' ) );
	}

	public function test_an_item_overrides_a_permissive_block(): void {
		$mixed = self::definition( [ 'allow_multiples' => true, 'items' => [ [ 'name' => 'Brawl', 'allow_multiples' => false ] ] ] );
		$this->assertFalse( Trait_Identity::allows_multiples( $mixed, 'Brawl' ) );
	}

	public function test_the_label_is_ignored_where_it_is_not_part_of_the_identity(): void {
		$this->assertSame(
			Trait_Identity::of( self::abilities(), 'Brawl', 'Wrestling' ),
			Trait_Identity::of( self::abilities(), 'Brawl', 'Boxing' )
		);
	}

	public function test_two_labels_are_two_holdings_where_the_item_allows_multiples(): void {
		$this->assertNotSame(
			Trait_Identity::of( self::backgrounds(), 'Retainers', 'John Doe' ),
			Trait_Identity::of( self::backgrounds(), 'Retainers', 'Sue Smith' )
		);
	}

	public function test_index_of_finds_the_row_a_change_names(): void {
		$held = [
			[ 'name' => 'Retainers', 'count' => 3, 'specialization' => 'John Doe' ],
			[ 'name' => 'Retainers', 'count' => 2, 'specialization' => 'Sue Smith' ],
		];
		$definition = self::backgrounds();

		$this->assertSame( 1, Trait_Identity::index_of( $definition, $held, Trait_Identity::of( $definition, 'Retainers', 'Sue Smith' ) ) );
		$this->assertSame( 0, Trait_Identity::index_of( $definition, $held, Trait_Identity::of( $definition, 'Retainers', 'John Doe' ) ) );
		$this->assertNull( Trait_Identity::index_of( $definition, $held, Trait_Identity::of( $definition, 'Retainers', 'Nobody' ) ) );
	}

	public function test_a_relabel_addresses_the_row_by_the_label_it_had_before(): void {
		$definition = self::backgrounds();
		$target     = Trait_Identity::target_of(
			$definition,
			[ 'name' => 'Retainers', 'specialization' => 'Sue Smith' ],
			[ 'name' => 'Retainers', 'specialization' => 'John Doe' ]
		);

		$this->assertSame( Trait_Identity::of( $definition, 'Retainers', 'John Doe' ), $target );
	}

	public function test_without_a_previous_snapshot_a_change_addresses_its_own_label(): void {
		$definition = self::backgrounds();
		$this->assertSame(
			Trait_Identity::of( $definition, 'Retainers', 'Sue Smith' ),
			Trait_Identity::target_of( $definition, [ 'name' => 'Retainers', 'specialization' => 'Sue Smith' ], null )
		);
	}

	// -----------------------------------------------------------------------
	// Consumer 2 - Cost_Engine prices the holding the change names
	// -----------------------------------------------------------------------

	/**
	 * Two Retainers, Sue second: raising Sue must price Sue's own dots.
	 */
	public function test_raising_the_second_retainer_prices_that_retainer(): void {
		$sheet = [ 'backgrounds' => [
			[ 'name' => 'Retainers', 'count' => 3, 'specialization' => 'John Doe' ],
			[ 'name' => 'Retainers', 'count' => 2, 'specialization' => 'Sue Smith' ],
		] ];

		$cost = Cost_Engine::price_trait_list_change(
			$sheet,
			self::backgrounds(),
			'backgrounds',
			'modify_trait',
			[ 'block_slug' => 'backgrounds', 'trait' => [ 'name' => 'Retainers', 'count' => 3, 'specialization' => 'Sue Smith' ] ]
		);

		// Sue goes 2 -> 3: one dot at 1 XP.
		$this->assertSame( 1, $cost );
	}

	/**
	 * Removing the second Retainer refunds that Retainer's dots.
	 */
	public function test_removing_the_second_retainer_prices_that_retainer(): void {
		$sheet = [ 'backgrounds' => [
			[ 'name' => 'Retainers', 'count' => 3, 'specialization' => 'John Doe' ],
			[ 'name' => 'Retainers', 'count' => 2, 'specialization' => 'Sue Smith' ],
		] ];

		$cost = Cost_Engine::price_trait_list_change(
			$sheet,
			self::backgrounds(),
			'backgrounds',
			'remove_trait',
			[ 'block_slug' => 'backgrounds', 'trait' => [ 'name' => 'Retainers', 'specialization' => 'Sue Smith' ] ]
		);

		// Sue's 2 dots leave: -2.
		$this->assertSame( -2, $cost );
	}

	public function test_an_ordinary_traits_identity_never_depends_on_its_label(): void {
		$definition = self::abilities();
		$this->assertSame(
			Trait_Identity::of( $definition, 'Brawl', 'Wrestling' ),
			Trait_Identity::target_of( $definition, [ 'name' => 'Brawl', 'specialization' => 'Boxing' ], [ 'specialization' => 'Wrestling' ] )
		);
	}

	/**
	 * A block with no multiples is unchanged: the label is not part of the identity.
	 */
	public function test_a_label_never_splits_a_holding_that_cannot_be_held_twice(): void {
		$sheet = [ 'abilities' => [ [ 'name' => 'Brawl', 'count' => 5, 'specialization' => 'Wrestling' ] ] ];

		$cost = Cost_Engine::price_trait_list_change(
			$sheet,
			self::abilities(),
			'abilities',
			'modify_trait',
			[ 'block_slug' => 'abilities', 'trait' => [ 'name' => 'Brawl', 'count' => 6, 'specialization' => 'Boxing' ] ]
		);

		$this->assertSame( 1, $cost );
	}
}
