<?php

namespace BeyondElysium\Tests\Unit;

use BeyondElysium\Services\Trait_Alias_Resolver;
use PHPUnit\Framework\TestCase;

/**
 * `Services\Trait_Alias_Resolver` in isolation.
 */
class TraitAliasResolverTest extends TestCase {

	/** @return object */
	private static function power( array $data ) {
		return json_decode( (string) json_encode( $data ) );
	}

	// -------------------------------------------------------------------------------
	// find_power_by_name(): direct name, alias, split_from, ambiguity.
	// -------------------------------------------------------------------------------

	public function test_a_direct_name_match_never_touches_alias_logic(): void {
		$powers = [ self::power( [ 'name' => 'Animalism' ] ) ];

		$found = Trait_Alias_Resolver::find_power_by_name( $powers, 'Animalism' );

		$this->assertSame( 'Animalism', $found->name );
	}

	public function test_a_family_resolves_by_its_own_recorded_alias(): void {
		$powers = [
			self::power( [ 'name' => 'Path of the Dry Nile', 'aliases' => [ 'Path of Dry Nile' ] ] ),
		];

		$found = Trait_Alias_Resolver::find_power_by_name( $powers, 'Path of Dry Nile' );

		$this->assertNotNull( $found );
		$this->assertSame( 'Path of the Dry Nile', $found->name );
	}

	public function test_a_family_resolves_by_its_own_split_from(): void {
		$powers = [
			self::power( [ 'name' => 'Animalism (Dark Ages)', 'split_from' => 'Animalism' ] ),
		];

		$found = Trait_Alias_Resolver::find_power_by_name( $powers, 'Animalism' );

		$this->assertNotNull( $found );
		$this->assertSame( 'Animalism (Dark Ages)', $found->name );
	}

	/**
	 * The class's own hard rule: three real Kuei-Jin families share `split_from: "Black Wind"` with nothing else to
	 * distinguish them.
	 */
	public function test_an_ambiguous_split_from_resolves_to_nothing_not_a_guess(): void {
		$powers = [
			self::power( [ 'name' => "Black Wind: Hell's Howling Typhoon", 'split_from' => 'Black Wind' ] ),
			self::power( [ 'name' => 'Black Wind: Ten Thousand Steps', 'split_from' => 'Black Wind' ] ),
			self::power( [ 'name' => 'Black Wind: Tiger Slashing Heaven', 'split_from' => 'Black Wind' ] ),
		];

		$this->assertNull( Trait_Alias_Resolver::find_power_by_name( $powers, 'Black Wind' ) );
	}

	public function test_an_ordinary_unrenamed_family_is_found_directly_and_an_unknown_one_resolves_to_nothing(): void {
		$powers = [ self::power( [ 'name' => 'Celerity' ] ) ];

		$this->assertSame( 'Celerity', Trait_Alias_Resolver::find_power_by_name( $powers, 'Celerity' )->name );
		$this->assertNull( Trait_Alias_Resolver::find_power_by_name( $powers, 'Not A Real Discipline' ) );
	}

	// -------------------------------------------------------------------------------
	// find_item_by_name(): direct name, alias.
	// -------------------------------------------------------------------------------

	public function test_an_item_resolves_by_its_own_recorded_alias(): void {
		$items = [ self::power( [ 'name' => 'Meditation', 'aliases' => [ 'Meditiation' ] ] ) ];

		$found = Trait_Alias_Resolver::find_item_by_name( $items, 'Meditiation' );

		$this->assertNotNull( $found );
		$this->assertSame( 'Meditation', $found->name );
	}

	public function test_an_item_with_no_alias_record_does_not_resolve_from_a_near_miss(): void {
		$items = [ self::power( [ 'name' => 'Meditation' ] ) ];

		$this->assertNull( Trait_Alias_Resolver::find_item_by_name( $items, 'Meditiation' ) );
	}

	// -------------------------------------------------------------------------------
	// find_level_by_name(): a rung/pick's own aliases.
	// -------------------------------------------------------------------------------

	public function test_a_rung_resolves_by_its_own_recorded_alias(): void {
		$levels = [
			self::power( [ 'power_name' => "Dissolve the Flesh", 'aliases' => [ 'Disolve', 'Hand of Flame' ] ] ),
		];

		$found = Trait_Alias_Resolver::find_level_by_name( $levels, 'Disolve' );

		$this->assertNotNull( $found );
		$this->assertSame( 'Dissolve the Flesh', $found->power_name );
	}

	public function test_a_pick_resolves_by_its_own_recorded_alias(): void {
		$levels = [
			self::power( [ 'level' => null, 'tier' => 'elder', 'power_name' => 'Cadaverous Animation', 'aliases' => [ 'Call the Homuncular Servant', 'Call of Athanatos' ] ] ),
		];

		$found = Trait_Alias_Resolver::find_level_by_name( $levels, 'Call of Athanatos' );

		$this->assertNotNull( $found );
		$this->assertSame( 'Cadaverous Animation', $found->power_name );
	}

	// -------------------------------------------------------------------------------
	// find_moved_power() / find_moved_item(): moved_from, both real JSON shapes.
	// -------------------------------------------------------------------------------

	/**
	 * The real tiered_power shape: `moved_from` is a LIST of `{block, name}` pairs.
	 */
	public function test_a_family_is_found_across_blocks_by_a_list_shaped_moved_from(): void {
		$blocks = [
			'vampire-disciplines' => (object) [ 'section_type' => 'tiered_power', 'definition' => (object) [ 'powers' => [] ] ],
			'vampire-blood-magic' => (object) [
				'section_type' => 'tiered_power',
				'definition'   => (object) [
					'powers' => [
						self::power( [
							'name'       => 'Lure of Flames',
							'moved_from' => [ [ 'block' => 'vampire-disciplines', 'name' => 'Creo Ignem' ] ],
						] ),
					],
				],
			],
		];

		$found = Trait_Alias_Resolver::find_moved_power( $blocks, 'vampire-disciplines', 'Creo Ignem' );

		$this->assertNotNull( $found );
		$this->assertSame( 'vampire-blood-magic', $found['block_slug'] );
		$this->assertSame( 'Lure of Flames', $found['power']->name );
	}

	/**
	 * The real trait_list shape: `moved_from` is a bare `{block, name}` OBJECT.
	 */
	public function test_an_item_is_found_across_blocks_by_an_object_shaped_moved_from(): void {
		$blocks = [
			'vampire-gargoyle-powers' => (object) [
				'section_type' => 'trait_list',
				'definition'   => (object) [
					'items' => [
						self::power( [
							'name'       => 'Extra Arms',
							'moved_from' => [ 'block' => 'vampire-disciplines', 'name' => 'Gargoyle Powers' ],
						] ),
					],
				],
			],
		];

		$found = Trait_Alias_Resolver::find_moved_item( $blocks, 'vampire-disciplines', 'Gargoyle Powers' );

		$this->assertNotNull( $found );
		$this->assertSame( 'vampire-gargoyle-powers', $found['block_slug'] );
		$this->assertSame( 'Extra Arms', $found['item']->name );
	}

	public function test_a_name_absent_from_every_block_finds_nothing(): void {
		$blocks = [
			'vampire-blood-magic' => (object) [ 'section_type' => 'tiered_power', 'definition' => (object) [ 'powers' => [] ] ],
		];

		$this->assertNull( Trait_Alias_Resolver::find_moved_power( $blocks, 'vampire-disciplines', 'Grave\'s Decay' ) );
	}

	/**
	 * Two families cannot both claim to have absorbed the identical prior name.
	 */
	public function test_two_families_claiming_the_same_moved_from_pair_resolve_to_nothing(): void {
		$blocks = [
			'vampire-blood-magic' => (object) [
				'section_type' => 'tiered_power',
				'definition'   => (object) [
					'powers' => [
						self::power( [ 'name' => 'Family A', 'moved_from' => [ 'block' => 'vampire-disciplines', 'name' => 'Old Name' ] ] ),
						self::power( [ 'name' => 'Family B', 'moved_from' => [ 'block' => 'vampire-disciplines', 'name' => 'Old Name' ] ] ),
					],
				],
			],
		];

		$this->assertNull( Trait_Alias_Resolver::find_moved_power( $blocks, 'vampire-disciplines', 'Old Name' ) );
	}

	public function test_find_moved_power_skips_a_block_of_the_wrong_section_type(): void {
		$blocks = [
			'some-trait-list' => (object) [
				'section_type' => 'trait_list',
				'definition'   => (object) [
					'items' => [
						self::power( [ 'name' => 'Not A Power', 'moved_from' => [ 'block' => 'vampire-disciplines', 'name' => 'Creo Ignem' ] ] ),
					],
				],
			],
		];

		$this->assertNull( Trait_Alias_Resolver::find_moved_power( $blocks, 'vampire-disciplines', 'Creo Ignem' ) );
	}
}
