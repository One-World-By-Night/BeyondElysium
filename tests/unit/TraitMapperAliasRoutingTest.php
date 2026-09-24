<?php

namespace BeyondElysium\Tests\Unit;

use BeyondElysium\Services\Trait_Mapper;
use PHPUnit\Framework\TestCase;

/**
 * Alias routing at `Trait_Mapper`'s lookup step, for import matching. Pure: no database.
 */
class TraitMapperAliasRoutingTest extends TestCase {

	/** @return object A tiered_power block whose one family carries a real recorded alias. */
	private function renamed_family_block( bool $with_alias ): object {
		$family = [
			'name'   => 'Path of the Dry Nile',
			'levels' => [
				(object) [ 'level' => 1, 'tier' => 'basic', 'power_name' => 'Beauty Fades' ],
				(object) [ 'level' => 4, 'tier' => 'intermediate', 'power_name' => 'Hope Dissolves', 'aliases' => [ 'HopeDisolves' ] ],
			],
		];
		if ( $with_alias ) {
			$family['aliases'] = [ 'Path of Dry Nile' ];
		}

		return (object) [
			'slug'       => 'vampire-blood-magic',
			'definition' => (object) [ 'powers' => [ (object) $family ] ],
		];
	}

	/** @return object A trait_list block whose one item carries a real recorded alias. */
	private function renamed_item_block( bool $with_alias ): object {
		$item = [ 'name' => 'Meditation' ];
		if ( $with_alias ) {
			$item['aliases'] = [ 'Meditiation' ];
		}

		return (object) [
			'slug'       => 'vampire-abilities',
			'definition' => (object) [ 'items' => [ (object) $item ] ],
		];
	}

	// ---------------------------------------------------------------------------------
	// A raw import name resolves via a recorded alias.
	// ---------------------------------------------------------------------------------

	public function test_a_numbered_rung_import_resolves_a_family_by_its_recorded_alias(): void {
		$result = Trait_Mapper::resolve_tiered_power_trait( 'Path of Dry Nile', '4', $this->renamed_family_block( true ) );

		$this->assertSame( 'exact', $result['outcome'] );
		$this->assertSame( 'Path of the Dry Nile', $result['family'] );
		$this->assertSame( 4, $result['level'] );
	}

	public function test_a_trait_list_import_resolves_an_item_by_its_recorded_alias(): void {
		$block  = $this->renamed_item_block( true );
		$result = Trait_Mapper::resolve_trait( 'Meditiation', [ $block ] );

		$this->assertSame( 'exact', $result['outcome'] );
		$this->assertSame( 'vampire-abilities', $result['block_slug'] );
		$this->assertSame( 'Meditation', $result['matched_name'] );
	}

	/**
	 * A named-pick import (`"{Family}: {Power}"`) resolves the RUNG by its own recorded alias once the family itself is
	 * found.
	 */
	public function test_a_named_pick_import_resolves_a_rung_by_its_recorded_alias(): void {
		$result = Trait_Mapper::resolve_tiered_power_trait( 'Path of the Dry Nile: HopeDisolves', '6', $this->renamed_family_block( true ) );

		$this->assertSame( 'exact', $result['outcome'] );
		$this->assertSame( 'Hope Dissolves', $result['power_name'] );
	}

	// ---------------------------------------------------------------------------------

	public function test_without_the_declared_alias_the_family_import_falls_through_to_fuzzy_or_unresolved(): void {
		$result = Trait_Mapper::resolve_tiered_power_trait( 'Path of Dry Nile', '4', $this->renamed_family_block( false ) );

		$this->assertNotSame( 'exact', $result['outcome'] );
		$this->assertNotSame( 'Path of the Dry Nile', $result['family'] ?? null );
	}

	public function test_without_the_declared_alias_the_item_import_falls_through_to_fuzzy_or_unresolved(): void {
		$block  = $this->renamed_item_block( false );
		$result = Trait_Mapper::resolve_trait( 'Meditiation', [ $block ] );

		$this->assertNotSame( 'exact', $result['outcome'] );
	}

	// ---------------------------------------------------------------------------------
	// Over-correction guard: an ordinary, unrenamed import is unaffected.
	// ---------------------------------------------------------------------------------

	public function test_an_ordinary_family_name_still_resolves_exactly(): void {
		$result = Trait_Mapper::resolve_tiered_power_trait( 'Path of the Dry Nile', '1', $this->renamed_family_block( true ) );

		$this->assertSame( 'exact', $result['outcome'] );
		$this->assertSame( 'Path of the Dry Nile', $result['family'] );
	}

	public function test_a_genuinely_unresolved_name_stays_unresolved(): void {
		$result = Trait_Mapper::resolve_tiered_power_trait( 'Not A Real Path At All Zzz', '1', $this->renamed_family_block( true ) );

		$this->assertNotSame( 'exact', $result['outcome'] );
		$this->assertNotSame( 'normalized', $result['outcome'] );
	}

	/**
	 * Kuei-Jin's real ambiguous `split_from`: three families all split from `Black Wind` with nothing else to distinguish
	 * them.
	 */
	public function test_an_ambiguous_split_from_family_does_not_resolve_during_import(): void {
		$block = (object) [
			'slug'       => 'kueijin-disciplines',
			'definition' => (object) [
				'powers' => [
					(object) [ 'name' => "Black Wind: Hell's Howling Typhoon", 'split_from' => 'Black Wind', 'levels' => [ (object) [ 'level' => 1, 'tier' => 'basic', 'power_name' => 'A' ] ] ],
					(object) [ 'name' => 'Black Wind: Ten Thousand Steps', 'split_from' => 'Black Wind', 'levels' => [ (object) [ 'level' => 1, 'tier' => 'basic', 'power_name' => 'B' ] ] ],
					(object) [ 'name' => 'Black Wind: Tiger Slashing Heaven', 'split_from' => 'Black Wind', 'levels' => [ (object) [ 'level' => 1, 'tier' => 'basic', 'power_name' => 'C' ] ] ],
				],
			],
		];

		$result = Trait_Mapper::resolve_tiered_power_trait( 'Black Wind', '2', $block );

		$this->assertNotSame( 'exact', $result['outcome'], 'an ambiguous split must never resolve to one guessed family' );
	}
}
