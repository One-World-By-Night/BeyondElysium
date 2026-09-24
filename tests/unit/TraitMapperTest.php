<?php

namespace BeyondElysium\Tests\Unit;

use BeyondElysium\Services\Fuzzy_Matcher;
use BeyondElysium\Services\Trait_Mapper;
use PHPUnit\Framework\TestCase;

/**
 * `Trait_Mapper`'s five-outcome trait resolution and per-list classification. Pure-function tests against hand-built
 * block fixtures: no database, no real import data.
 */
class TraitMapperTest extends TestCase {

	/**
	 * @param string   $slug
	 * @param string[] $items
	 * @param bool     $allow_custom
	 * @return object
	 */
	private function block( string $slug, array $items, bool $allow_custom = false ) {
		return (object) [
			'slug'       => $slug,
			'definition' => (object) [
				'items'        => array_map( static fn( $name ) => (object) [ 'name' => $name ], $items ),
				'allow_custom' => $allow_custom,
			],
		];
	}

	// -------------------------------------------------------------------------
	// The five per-trait outcomes
	// -------------------------------------------------------------------------

	public function test_exact_match(): void {
		$block  = $this->block( 'met-abilities', [ 'Brawl', 'Melee', 'Occult' ] );
		$result = Trait_Mapper::resolve_trait( 'Melee', [ $block ] );

		$this->assertSame( 'exact', $result['outcome'] );
		$this->assertSame( 'met-abilities', $result['block_slug'] );
	}

	public function test_normalized_match_on_whitespace_and_case(): void {
		$block  = $this->block( 'met-abilities', [ 'Melee' ] );
		$result = Trait_Mapper::resolve_trait( 'melee ', [ $block ] );

		$this->assertSame( 'normalized', $result['outcome'] );
		$this->assertSame( 'Melee', $result['matched_name'] );
	}

	public function test_fuzzy_match_within_threshold_never_auto_applies(): void {
		$block  = $this->block( 'met-abilities', [ 'Brawl' ] );
		$result = Trait_Mapper::resolve_trait( 'Crawl', [ $block ] );

		$this->assertSame( 'fuzzy', $result['outcome'] );
		$this->assertContains( 'Brawl', $result['suggestions'] );
		$this->assertArrayNotHasKey( 'block_slug', $result, 'a fuzzy match must never resolve directly - Step 5e' );
	}

	public function test_no_match_with_allow_custom_imports_as_custom(): void {
		$block  = $this->block( 'met-abilities', [ 'Brawl' ], true );
		$result = Trait_Mapper::resolve_trait( 'Underwater Basket Weaving', [ $block ] );

		$this->assertSame( 'custom', $result['outcome'] );
		$this->assertSame( 'met-abilities', $result['block_slug'] );
	}

	public function test_no_match_without_allow_custom_is_unresolved(): void {
		$block  = $this->block( 'met-physical-traits', [ 'Brawny' ], false );
		$result = Trait_Mapper::resolve_trait( 'Underwater Basket Weaving', [ $block ] );

		$this->assertSame( 'unresolved', $result['outcome'] );
	}

	// -------------------------------------------------------------------------
	// Ambiguity
	// -------------------------------------------------------------------------

	public function test_a_name_matching_two_different_blocks_is_flagged_not_silently_picked(): void {
		$a = $this->block( 'block-a', [ 'Resources' ] );
		$b = $this->block( 'block-b', [ 'Resources' ] );

		$result = Trait_Mapper::resolve_trait( 'Resources', [ $a, $b ] );

		$this->assertSame( 'ambiguous', $result['outcome'] );
		$this->assertEqualsCanonicalizing( [ 'block-a', 'block-b' ], $result['candidates'] );
	}

	// -------------------------------------------------------------------------
	// Whole-list classification
	// -------------------------------------------------------------------------

	public function test_shared_universal_list_classifies_as_a_sheet_block(): void {
		$result = Trait_Mapper::classify_list( 'vampire', 'Abilities' );
		$this->assertSame( 'sheet_block', $result['outcome'] );
		$this->assertSame( 'vampire-abilities', $result['block_slug'] );
	}

	public function test_abilities_merits_flaws_and_rites_land_on_each_creature_types_own_lists(): void {
		if ( ! \BeyondElysium\Services\Catalog_Reader::available() ) {
			$this->markTestSkipped( 'no declared catalog in this checkout' );
		}

		$expected = [
			'vampire'    => [ 'Abilities' => 'vampire-abilities', 'Merits' => 'vampire-merits', 'Flaws' => 'vampire-flaws' ],
			'werewolf'   => [ 'Abilities' => 'werewolf-abilities', 'Merits' => 'werewolf-merits', 'Flaws' => 'werewolf-flaws', 'Rites' => 'werewolf-rites' ],
			'fera'       => [ 'Abilities' => 'fera-abilities', 'Merits' => 'fera-merits', 'Flaws' => 'fera-flaws', 'Rites' => 'fera-rites' ],
			'bete'       => [ 'Abilities' => 'fera-abilities', 'Merits' => 'fera-merits', 'Flaws' => 'fera-flaws', 'Rites' => 'fera-rites' ],
			'mage'       => [ 'Abilities' => 'mage-abilities', 'Merits' => 'mage-merits', 'Flaws' => 'mage-flaws' ],
			'changeling' => [ 'Abilities' => 'changeling-abilities', 'Merits' => 'changeling-merits', 'Flaws' => 'changeling-flaws' ],
			'wraith'     => [ 'Abilities' => 'wraith-abilities', 'Merits' => 'wraith-merits', 'Flaws' => 'wraith-flaws' ],
			'mortal'     => [ 'Abilities' => 'mortal-abilities', 'Merits' => 'mortal-merits', 'Flaws' => 'mortal-flaws' ],
			'mummy'      => [ 'Abilities' => 'mummy-abilities', 'Merits' => 'mummy-merits', 'Flaws' => 'mummy-flaws' ],
			'kueijin'    => [ 'Abilities' => 'kueijin-abilities', 'Merits' => 'kueijin-merits', 'Flaws' => 'kueijin-flaws' ],
			'demon'      => [ 'Abilities' => 'demon-abilities', 'Merits' => 'demon-merits', 'Flaws' => 'demon-flaws' ],
		];

		foreach ( $expected as $stack => $lists ) {
			foreach ( $lists as $list => $block ) {
				$result = Trait_Mapper::classify_list( $stack, $list );
				$this->assertSame( 'sheet_block', $result['outcome'], "{$stack} {$list}" );
				$this->assertSame( $block, $result['block_slug'], "{$stack} {$list}" );
			}
		}
	}

	/**
	 * The block classification names for each of these lists is a block the stack declares, for every shipped stack.
	 */
	public function test_every_block_the_import_names_is_one_its_stack_declares(): void {
		if ( ! \BeyondElysium\Services\Catalog_Reader::available() ) {
			$this->markTestSkipped( 'no declared catalog in this checkout' );
		}

		foreach ( \BeyondElysium\Services\Catalog_Reader::stacks_to_seed() as $stack => $data ) {
			$declared = array_column( $data['stack_definition']['sections'], 'block_slug' );
			foreach ( [ 'Abilities', 'Merits', 'Flaws', 'Rites' ] as $list ) {
				$result = Trait_Mapper::classify_list( $stack, $list );
				if ( ( $result['outcome'] ?? '' ) !== 'sheet_block' ) {
					continue; // Lists with no block on this stack are kept as a note.
				}
				$this->assertContains( $result['block_slug'], $declared, "{$stack} {$list} -> {$result['block_slug']} is not a block the stack declares" );
			}
		}
	}

	public function test_influences_and_backgrounds_both_resolve_to_the_merged_stack_block(): void {
		$influences  = Trait_Mapper::classify_list( 'werewolf', 'Influences' );
		$backgrounds = Trait_Mapper::classify_list( 'werewolf', 'Backgrounds' );

		$this->assertSame( 'werewolf-backgrounds', $influences['block_slug'] );
		$this->assertSame( 'werewolf-backgrounds', $backgrounds['block_slug'] );
	}

	public function test_health_levels_resolves_to_the_stacks_own_health_block(): void {
		// HealthList is a plain LinkedTraitList in Grapevine's own source, same construct as Influences/Backgrounds.
		$vampire  = Trait_Mapper::classify_list( 'vampire', 'Health Levels' );
		$werewolf = Trait_Mapper::classify_list( 'werewolf', 'Health Levels' );

		$this->assertSame( 'sheet_block', $vampire['outcome'] );
		$this->assertSame( 'vampire-health', $vampire['block_slug'] );
		$this->assertSame( 'sheet_block', $werewolf['outcome'] );
		$this->assertSame( 'werewolf-health', $werewolf['block_slug'] );
	}

	public function test_equipment_and_locations_route_to_world_objects(): void {
		$equipment = Trait_Mapper::classify_list( 'mage', 'Equipment' );
		$locations = Trait_Mapper::classify_list( 'mage', 'Locations' );

		$this->assertSame( 'world_object', $equipment['outcome'] );
		$this->assertSame( 'item', $equipment['object_type'] );
		$this->assertSame( 'world_object', $locations['outcome'] );
		$this->assertSame( 'location', $locations['object_type'] );
	}

	public function test_fera_singular_location_spelling_also_routes_to_world_objects(): void {
		// FeraClass.Class_Initialize spells this "Location".
		$result = Trait_Mapper::classify_list( 'fera', 'Location' );
		$this->assertSame( 'world_object', $result['outcome'] );
	}

	public function test_bonds_has_no_be_model_and_is_preserved_not_dropped(): void {
		$result = Trait_Mapper::classify_list( 'vampire', 'Bonds' );
		$this->assertSame( 'preserve_as_note', $result['outcome'] );
	}

	public function test_stack_specific_power_list_classifies_correctly(): void {
		$this->assertSame( 'vampire-disciplines', Trait_Mapper::classify_list( 'vampire', 'Disciplines' )['block_slug'] );
		$this->assertSame( 'werewolf-gifts', Trait_Mapper::classify_list( 'werewolf', 'Gifts' )['block_slug'] );
		$this->assertSame( 'mage-spheres', Trait_Mapper::classify_list( 'mage', 'Spheres' )['block_slug'] );
	}

	/**
	 * Fera and Bete keep Gifts in fera-gifts and Health Levels in werewolf-health, and a Bete's Backgrounds live in
	 * fera-backgrounds.
	 */
	public function test_fera_and_bete_lists_route_to_the_blocks_those_stacks_hold(): void {
		foreach ( [ 'fera', 'bete' ] as $stack ) {
			$this->assertSame( 'fera-gifts', Trait_Mapper::classify_list( $stack, 'Gifts' )['block_slug'], $stack );
			$this->assertSame( 'werewolf-health', Trait_Mapper::classify_list( $stack, 'Health Levels' )['block_slug'], $stack );
			$this->assertSame( 'fera-backgrounds', Trait_Mapper::classify_list( $stack, 'Backgrounds' )['block_slug'], $stack );
			$this->assertSame( 'fera-backgrounds', Trait_Mapper::classify_list( $stack, 'Influences' )['block_slug'], $stack );
		}
	}

	public function test_an_unmapped_list_name_is_preserved_not_dropped(): void {
		$result = Trait_Mapper::classify_list( 'vampire', 'Some Future List Nobody Declared' );
		$this->assertSame( 'preserve_as_note', $result['outcome'] );
		$this->assertNotEmpty( $result['note'] );
	}

	public function test_a_renown_component_is_flagged_needs_design_not_guessed_at(): void {
		$result = Trait_Mapper::classify_list( 'werewolf', 'Honor' );
		$this->assertSame( 'needs_design', $result['outcome'] );
	}

	// -------------------------------------------------------------------------
	// Tiered_power resolution
	// -------------------------------------------------------------------------

	/**
	 * @return object A decoded tiered_power Schema_Block shaped like the real seeder
	 *                output: `Fortitude` with a real 1-5 ladder plus two Elder picks.
	 */
	private function tiered_power_block(): object {
		$level = static fn( $level, $tier, $power_name ) => (object) [
			'level' => $level, 'tier' => $tier, 'power_name' => $power_name,
		];

		return (object) [
			'slug'       => 'vampire-disciplines',
			'section_type' => 'tiered_power',
			'definition' => (object) [
				'powers' => [
					(object) [
						'name'   => 'Fortitude',
						'levels' => [
							$level( 1, 'basic', 'Enduring' ),
							$level( 2, 'basic', 'Ox Hide' ),
							$level( 3, 'intermediate', 'Enhanced Vigor' ),
							$level( 4, 'intermediate', 'Draught of Endurance' ),
							$level( 5, 'advanced', 'Prowess from Pain' ),
							$level( null, 'elder', 'Personal Armor' ),
							$level( null, 'elder', 'Fortitude of the Wolf' ),
						],
					],
					(object) [
						'name'   => 'Celerity',
						'levels' => [ $level( 1, 'basic', 'Alacrity' ) ],
					],
				],
			],
		];
	}

	public function test_numbered_rung_resolves_exactly(): void {
		$result = Trait_Mapper::resolve_tiered_power_trait( 'Fortitude', '3', $this->tiered_power_block() );

		$this->assertSame( 'exact', $result['outcome'] );
		$this->assertSame( 'Fortitude', $result['family'] );
		$this->assertSame( 3, $result['level'] );
	}

	public function test_numbered_rung_family_name_normalizes(): void {
		$result = Trait_Mapper::resolve_tiered_power_trait( ' fortitude ', '1', $this->tiered_power_block() );

		$this->assertSame( 'normalized', $result['outcome'] );
		$this->assertSame( 'Fortitude', $result['family'] );
	}

	public function test_a_level_the_family_does_not_have_is_unresolved(): void {
		$result = Trait_Mapper::resolve_tiered_power_trait( 'Fortitude', '9', $this->tiered_power_block() );

		$this->assertSame( 'unresolved', $result['outcome'] );
	}

	public function test_elder_pick_resolves_by_its_own_self_describing_name(): void {
		$result = Trait_Mapper::resolve_tiered_power_trait(
			'Fortitude: Personal Armor (elder)', '1', $this->tiered_power_block()
		);

		$this->assertSame( 'exact', $result['outcome'] );
		$this->assertSame( 'Fortitude', $result['family'] );
		$this->assertSame( 'Personal Armor', $result['power_name'] );
		$this->assertSame( 'elder', $result['tier'] );
	}

	public function test_a_second_distinct_elder_pick_in_the_same_family_also_resolves(): void {
		// A character can hold several Elder-and-above picks in the same family at once.
		$result = Trait_Mapper::resolve_tiered_power_trait(
			'Fortitude: Fortitude of the Wolf (elder)', '1', $this->tiered_power_block()
		);

		$this->assertSame( 'exact', $result['outcome'] );
		$this->assertSame( 'Fortitude of the Wolf', $result['power_name'] );
	}

	public function test_elder_pick_name_normalizes(): void {
		$result = Trait_Mapper::resolve_tiered_power_trait(
			'Fortitude: personal armor (elder)', '1', $this->tiered_power_block()
		);

		$this->assertSame( 'normalized', $result['outcome'] );
		$this->assertSame( 'Personal Armor', $result['power_name'] );
	}

	public function test_elder_pick_against_an_unknown_family_is_unresolved(): void {
		$result = Trait_Mapper::resolve_tiered_power_trait(
			'Obfuscate: Cloak of Shadows (elder)', '1', $this->tiered_power_block()
		);

		$this->assertSame( 'unresolved', $result['outcome'] );
	}

	public function test_elder_pick_with_a_typo_is_fuzzy_not_silently_dropped(): void {
		$result = Trait_Mapper::resolve_tiered_power_trait(
			'Fortitude: Personal Armour (elder)', '1', $this->tiered_power_block()
		);

		$this->assertSame( 'fuzzy', $result['outcome'] );
		$this->assertContains( 'Personal Armor', $result['suggestions'] );
	}

	// -------------------------------------------------------------------------

	public function test_a_basic_tier_power_resolves_without_any_tier_parenthetical(): void {
		// The exact shape Sabbat.gex carries: note="basic", total="3" (a cost, not a level).
		$result = Trait_Mapper::resolve_tiered_power_trait(
			'Fortitude: Enduring', '3', $this->tiered_power_block()
		);

		$this->assertSame( 'exact', $result['outcome'] );
		$this->assertSame( 'Fortitude', $result['family'] );
		$this->assertSame( 'Enduring', $result['power_name'] );
		$this->assertSame( 'basic', $result['tier'] );
	}

	public function test_an_elder_tier_power_resolves_without_a_parenthetical_too(): void {
		// Tier vocabulary is never consulted for matching.
		$result = Trait_Mapper::resolve_tiered_power_trait(
			'Fortitude: Personal Armor', '12', $this->tiered_power_block()
		);

		$this->assertSame( 'exact', $result['outcome'] );
		$this->assertSame( 'Personal Armor', $result['power_name'] );
		$this->assertSame( 'elder', $result['tier'], 'tier is read back from the catalog, not parsed from the name' );
	}

	public function test_a_family_name_containing_a_colon_is_not_split_at_the_first_colon(): void {
		// Over a hundred real seeded families contain their own colon ("Akhu: Path of Blood", "Wanga: Ash Path").
		$block = (object) [
			'slug'         => 'vampire-disciplines',
			'section_type' => 'tiered_power',
			'definition'   => (object) [
				'powers' => [
					(object) [
						'name'   => 'Akhu: Path of Blood',
						'levels' => [ (object) [ 'level' => 1, 'tier' => 'basic', 'power_name' => 'Taste of Vitae' ] ],
					],
					(object) [ 'name' => 'Akhu', 'levels' => [] ],
				],
			],
		];

		$result = Trait_Mapper::resolve_tiered_power_trait( 'Akhu: Path of Blood: Taste of Vitae', '3', $block );

		$this->assertSame( 'exact', $result['outcome'] );
		$this->assertSame( 'Akhu: Path of Blood', $result['family'], 'the longest real matching family wins, not the first colon' );
		$this->assertSame( 'Taste of Vitae', $result['power_name'] );
	}

	// -------------------------------------------------------------------------

	public function test_a_tradition_prefixed_family_resolves_as_a_numbered_rung_not_an_unresolved_named_pick(): void {
		// The real reported failure: "Thaumaturgy: Focused Mind" never resolved.
		$result = Trait_Mapper::resolve_tiered_power_trait( 'Thaumaturgy: Fortitude', '3', $this->tiered_power_block() );

		$this->assertSame( 'exact', $result['outcome'] );
		$this->assertSame( 'Fortitude', $result['family'] );
		$this->assertSame( 3, $result['level'] );
		$this->assertSame( 'Thaumaturgy', $result['tradition'] );
	}

	public function test_a_tradition_prefix_is_not_applied_when_the_family_name_itself_contains_a_colon(): void {
		$block = (object) [
			'slug'         => 'vampire-disciplines',
			'section_type' => 'tiered_power',
			'definition'   => (object) [
				'powers' => [
					(object) [
						'name'   => 'Akhu: Path of Blood',
						'levels' => [ (object) [ 'level' => 1, 'tier' => 'basic', 'power_name' => 'Taste of Vitae' ] ],
					],
					(object) [ 'name' => 'Akhu', 'levels' => [] ],
				],
			],
		];

		$result = Trait_Mapper::resolve_tiered_power_trait( 'Akhu: Path of Blood: Taste of Vitae', '3', $block );

		$this->assertSame( 'exact', $result['outcome'] );
		$this->assertSame( 'Akhu: Path of Blood', $result['family'] );
		$this->assertArrayNotHasKey( 'tradition', $result );
	}

	/**
	 * Real Grapevine export decoration, confirmed against actual `.gex` samples.
	 */
	public function test_a_trailing_star_decoration_is_stripped_before_matching(): void {
		$bare = Trait_Mapper::resolve_tiered_power_trait( 'Fortitude*', '1', $this->tiered_power_block() );
		$this->assertSame( 'exact', $bare['outcome'] );
		$this->assertSame( 'Fortitude', $bare['family'] );

		$named = Trait_Mapper::resolve_tiered_power_trait(
			'Fortitude: Personal Armor*', '1', $this->tiered_power_block()
		);
		$this->assertSame( 'exact', $named['outcome'] );
		$this->assertSame( 'Personal Armor', $named['power_name'] );

		$traditioned = Trait_Mapper::resolve_tiered_power_trait(
			'Thaumaturgy*: Fortitude', '2', $this->tiered_power_block()
		);
		$this->assertSame( 'exact', $traditioned['outcome'] );
		$this->assertSame( 'Fortitude', $traditioned['family'] );
		$this->assertSame( 'Thaumaturgy', $traditioned['tradition'] );
	}

	/**
	 * Found running all four real GEX files this project has through the real import pipeline ("we need some better
	 * matching too").
	 */
	public function test_a_leading_the_on_the_family_suffix_is_tried_as_a_fallback(): void {
		$block = (object) [
			'slug'         => 'vampire-disciplines',
			'section_type' => 'tiered_power',
			'definition'   => (object) [
				'powers' => [
					(object) [ 'name' => 'Ash Path', 'levels' => [ (object) [ 'level' => 1, 'tier' => 'basic', 'power_name' => 'First Rung' ] ] ],
					(object) [ 'name' => 'The Green Path', 'levels' => [ (object) [ 'level' => 1, 'tier' => 'basic', 'power_name' => 'First Rung' ] ] ],
				],
			],
		];

		$result = Trait_Mapper::resolve_tiered_power_trait( 'Mortis: The Ash Path', '1', $block );
		$this->assertSame( 'exact', $result['outcome'] );
		$this->assertSame( 'Ash Path', $result['family'], 'the leading "The " is stripped from the suffix, not treated as part of the family' );
		$this->assertSame( 'Mortis', $result['tradition'] );
	}

	public function test_a_family_genuinely_named_the_something_is_not_broken_by_the_fallback(): void {
		// The one real seeded family that DOES start with "The ".
		$block = (object) [
			'slug'         => 'vampire-disciplines',
			'section_type' => 'tiered_power',
			'definition'   => (object) [
				'powers' => [
					(object) [ 'name' => 'The Green Path', 'levels' => [ (object) [ 'level' => 1, 'tier' => 'basic', 'power_name' => 'First Rung' ] ] ],
				],
			],
		];

		$result = Trait_Mapper::resolve_tiered_power_trait( 'Sadhana: The Green Path', '1', $block );
		$this->assertSame( 'exact', $result['outcome'] );
		$this->assertSame( 'The Green Path', $result['family'] );
	}

	// -------------------------------------------------------------------------
	// Blood magic - tradition-label normalization
	// -------------------------------------------------------------------------

	/**
	 * @return object A tiered_power block shaped like the real vampire-blood-magic - flagged
	 *                 blood_magic with a real tradition list, one bare canonical path.
	 */
	private function blood_magic_block(): object {
		return (object) [
			'slug'         => 'vampire-blood-magic',
			'section_type' => 'tiered_power',
			'definition'   => (object) [
				'blood_magic' => true,
				'traditions'  => [ 'Akhu', 'Bacaban', 'Dur An Ki', 'Necromancy', 'Sadhana', 'Wanga' ],
				'powers'      => [
					(object) [
						'name'       => 'Path of Blood',
						'traditions' => (object) [ 'Dur An Ki' => "Path of Life's Water", 'Sadhana' => 'Path of Kali' ],
						'levels'     => [
							(object) [ 'level' => 1, 'tier' => 'basic', 'power_name' => 'Taste for Blood' ],
							(object) [ 'level' => 2, 'tier' => 'basic', 'power_name' => 'Blood Rage' ],
						],
					],
				],
			],
		];
	}

	/**
	 * Confirmed against a real.gex export (Chase Ashford): the file spells the tradition "Dur-An-Ki" throughout.
	 */
	public function test_a_hyphenated_tradition_spelling_normalizes_to_the_catalog_form(): void {
		$result = Trait_Mapper::resolve_tiered_power_trait( 'Dur-An-Ki: Path of Blood', '1', $this->blood_magic_block() );

		$this->assertSame( 'exact', $result['outcome'] );
		$this->assertSame( 'Dur An Ki', $result['tradition'] );
	}

	/**
	 * Confirmed against the same real file: "Sadhanna" (one stray letter) for what the catalog spells "Sadhana".
	 */
	public function test_a_single_letter_typo_in_a_tradition_name_fuzzy_normalizes(): void {
		$result = Trait_Mapper::resolve_tiered_power_trait( 'Sadhanna: Path of Blood', '1', $this->blood_magic_block() );

		$this->assertSame( 'exact', $result['outcome'] );
		$this->assertSame( 'Sadhana', $result['tradition'] );
	}

	/**
	 * Confirmed against the same real file: "Eastern Necromancy" for what the catalog simply calls "Necromancy".
	 */
	public function test_an_unrecognized_tradition_phrasing_is_kept_verbatim_not_guessed(): void {
		$result = Trait_Mapper::resolve_tiered_power_trait( 'Eastern Necromancy: Path of Blood', '1', $this->blood_magic_block() );

		$this->assertSame( 'exact', $result['outcome'], 'the path and level are real regardless of the tradition label' );
		$this->assertSame( 'Eastern Necromancy', $result['tradition'] );
	}

	/**
	 * A block that is not blood-magic flagged (every other tiered_power block, and every other creature type's
	 * Discipline-shaped list) must never have its tradition text touched.
	 */
	public function test_tradition_normalization_never_runs_against_a_non_blood_magic_block(): void {
		$result = Trait_Mapper::resolve_tiered_power_trait( 'thau-maturgy: Fortitude', '3', $this->tiered_power_block() );

		$this->assertSame( 'exact', $result['outcome'] );
		$this->assertSame( 'thau-maturgy', $result['tradition'], 'an ordinary tiered_power block never normalizes a tradition label' );
	}

	public function test_a_combo_prefix_and_trailing_annotation_are_stripped_before_matching(): void {
		$combo_block = $this->block( 'vampire-combo-disciplines', [ 'Blood Sight', 'Animal Magnetism', 'Shroud of Absence' ], true );

		$paren = Trait_Mapper::resolve_trait( 'Combo: Blood Sight (Aus 3, PoB 1)', [ $combo_block ] );
		$this->assertSame( 'exact', $paren['outcome'] );
		$this->assertSame( 'vampire-combo-disciplines', $paren['block_slug'] );

		$combination = Trait_Mapper::resolve_trait( 'Combination: Animal Magnetism', [ $combo_block ] );
		$this->assertSame( 'exact', $combination['outcome'] );

		$bracket = Trait_Mapper::resolve_trait( 'Combo: Shroud of Absence [Obf/Obt]', [ $combo_block ] );
		$this->assertSame( 'exact', $bracket['outcome'] );
	}

	public function test_a_star_decoration_is_not_stripped_on_the_non_combo_trait_list_path(): void {
		$ritual_block = $this->block( 'vampire-rituals', [ 'Thaumaturgy: Alter Blood* (Basic)' ], true );

		$result = Trait_Mapper::resolve_trait( 'Thaumaturgy: Alter Blood* (Basic)', [ $ritual_block ] );

		$this->assertSame( 'exact', $result['outcome'], 'the literal * is part of this real catalog name and must still exact-match verbatim' );
	}

	public function test_resolve_chosen_carries_tradition_through_for_a_traditioned_rung(): void {
		$block = $this->tiered_power_block();

		$result = Trait_Mapper::resolve_chosen( 'Thaumaturgy: Celerity', '1', 'Celerity', $block, [] );

		$this->assertSame( 'exact', $result['outcome'] );
		$this->assertSame( 'Celerity', $result['family'] );
		$this->assertSame( 'Thaumaturgy', $result['tradition'] );
	}

	// -------------------------------------------------------------------------
	// resolve_chosen() - applying an ST's picked suggestion
	// -------------------------------------------------------------------------

	public function test_resolve_chosen_applies_a_trait_list_suggestion(): void {
		$block  = $this->block( 'met-abilities', [ 'Brawl', 'Melee', 'Occult' ] );
		$result = Trait_Mapper::resolve_chosen( 'Occlt', '1', 'Occult', null, [ $block ] );

		$this->assertSame( 'exact', $result['outcome'] );
		$this->assertSame( 'Occult', $result['matched_name'] );
	}

	public function test_resolve_chosen_applies_a_numbered_rung_family_suggestion(): void {
		// The raw name was the typo the ST is correcting.
		$result = Trait_Mapper::resolve_chosen(
			'Fortitde', '3', 'Fortitude', $this->tiered_power_block(), []
		);

		$this->assertSame( 'exact', $result['outcome'] );
		$this->assertSame( 'Fortitude', $result['family'] );
		$this->assertSame( 3, $result['level'] );
	}

	public function test_resolve_chosen_applies_an_elder_pick_power_name_suggestion(): void {
		$result = Trait_Mapper::resolve_chosen(
			'Fortitude: Personal Armour (elder)', '1', 'Personal Armor',
			$this->tiered_power_block(), []
		);

		$this->assertSame( 'exact', $result['outcome'] );
		$this->assertSame( 'Fortitude', $result['family'] );
		$this->assertSame( 'Personal Armor', $result['power_name'] );
		$this->assertSame( 'elder', $result['tier'] );
	}
}
