<?php

namespace BeyondElysium\Tests\Unit;

use BeyondElysium\Services\Fuzzy_Matcher;
use BeyondElysium\Services\Trait_Mapper;
use PHPUnit\Framework\TestCase;

/**
 * `Trait_Mapper`'s five-outcome trait resolution and Decision 036's per-list
 * classification (workflow-0.8.md Step 4). Pure-function tests against hand-built
 * block fixtures - no DB, no real import data.
 *
 * @see BE_PROCESS/workflow-0.8.md Step 4
 * @see BE_PROCESS/DECISIONLOG.md Decision 036
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
	// Ambiguity (Step 4g)
	// -------------------------------------------------------------------------

	public function test_a_name_matching_two_different_blocks_is_flagged_not_silently_picked(): void {
		$a = $this->block( 'block-a', [ 'Resources' ] );
		$b = $this->block( 'block-b', [ 'Resources' ] );

		$result = Trait_Mapper::resolve_trait( 'Resources', [ $a, $b ] );

		$this->assertSame( 'ambiguous', $result['outcome'] );
		$this->assertEqualsCanonicalizing( [ 'block-a', 'block-b' ], $result['candidates'] );
	}

	// -------------------------------------------------------------------------
	// Decision 036: whole-list classification
	// -------------------------------------------------------------------------

	public function test_shared_universal_list_classifies_as_a_sheet_block(): void {
		$result = Trait_Mapper::classify_list( 'vampire', 'Abilities' );
		$this->assertSame( 'sheet_block', $result['outcome'] );
		$this->assertSame( 'met-abilities', $result['block_slug'] );
	}

	public function test_influences_and_backgrounds_both_resolve_to_the_merged_stack_block(): void {
		$influences  = Trait_Mapper::classify_list( 'werewolf', 'Influences' );
		$backgrounds = Trait_Mapper::classify_list( 'werewolf', 'Backgrounds' );

		$this->assertSame( 'werewolf-backgrounds', $influences['block_slug'] );
		$this->assertSame( 'werewolf-backgrounds', $backgrounds['block_slug'] );
	}

	public function test_health_levels_has_no_be_model_and_is_preserved_not_dropped(): void {
		// Real per-character box-count data (confirmed against a real .gex sample) - BE has
		// no health resource_pool block yet, so this must not be discard_derived.
		$result = Trait_Mapper::classify_list( 'vampire', 'Health Levels' );
		$this->assertSame( 'preserve_as_note', $result['outcome'] );
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
		// FeraClass.Class_Initialize spells this "Location", not "Locations" -
		// GV301Source/Code/FeraClass.cls line 906.
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
	// Step 4d: tiered_power resolution. Confirmed directly against a real Grapevine
	// record, not guessed - a numbered rung is the bare family name plus a numeric
	// Total; an Elder-and-above pick is a self-describing "Family: Power (tier)" name,
	// picked from a short menu of choices a character can hold several of at once.
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
		// Decision 037: a character can hold several Elder-and-above picks in the same
		// family at once - each is its own independent trait, not a conflicting level.
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
	// The shape real Grapevine binary exports actually use, verified against the real
	// `Sabbat.gex` sample: "{Family}: {Power}" at EVERY tier, no trailing "(tier)"
	// parenthetical (the tier rides in the trait's separate `note`), and `Total` carrying
	// the level's cost rather than its level number. All 27 of that file's held
	// Disciplines were `unresolved` before this - the old parser required the
	// parenthetical, so every one fell through to the numbered-rung path and missed.
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
		// Tier vocabulary is never consulted for matching - the tier comes back FROM the
		// catalog - so elder/master/ascended/methuselah (costs 12/15/18/21) need no
		// special handling beyond what basic/int./adv. already get.
		$result = Trait_Mapper::resolve_tiered_power_trait(
			'Fortitude: Personal Armor', '12', $this->tiered_power_block()
		);

		$this->assertSame( 'exact', $result['outcome'] );
		$this->assertSame( 'Personal Armor', $result['power_name'] );
		$this->assertSame( 'elder', $result['tier'], 'tier is read back from the catalog, not parsed from the name' );
	}

	public function test_a_family_name_containing_a_colon_is_not_split_at_the_first_colon(): void {
		// Over a hundred real seeded families contain their own colon ("Akhu: Path of
		// Blood", "Wanga: Ash Path"). A blind first-colon split mis-parses every one.
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
	// workflow-0.9.md Step 0d - tradition-prefixed numbered rungs, export decorations
	// -------------------------------------------------------------------------

	public function test_a_tradition_prefixed_family_resolves_as_a_numbered_rung_not_an_unresolved_named_pick(): void {
		// The real reported failure: "Thaumaturgy: Focused Mind" never resolved, even
		// though a sorcery path (here standing in as "Fortitude") is a real, directly
		// seeded top-level family (Decision 064) - the tradition prefix is not itself a
		// seeded family and must never be treated as one.
		$result = Trait_Mapper::resolve_tiered_power_trait( 'Thaumaturgy: Fortitude', '3', $this->tiered_power_block() );

		$this->assertSame( 'exact', $result['outcome'] );
		$this->assertSame( 'Fortitude', $result['family'] );
		$this->assertSame( 3, $result['level'] );
		$this->assertSame( 'Thaumaturgy', $result['tradition'] );
	}

	public function test_a_tradition_prefix_is_not_applied_when_the_family_name_itself_contains_a_colon(): void {
		// Must not fire for the pre-existing "Akhu: Path of Blood" shape (a real family
		// name that itself contains a colon) - the text after the FIRST colon
		// ("Path of Blood: Taste of Vitae") is not itself a seeded family, so this stays on
		// the existing longest-prefix path, unaffected by the new check running first.
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
	 * Real Grapevine export decoration, confirmed against actual `.gex` samples, not
	 * guessed - a trailing `*` on a bare family name, a power name, or right before a
	 * colon. Checked directly: no real seeded family name contains a literal `*`.
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
	 * Found running all four real GEX files this project has through the real import
	 * pipeline (2026-09-11, "we need some better matching too") - `sanitize a leading
	 * "The " on the suffix, tried only after the exact check fails. Confirmed against the
	 * real seeded catalog first, not guessed: "Ash Path" and "Path of Blood" are both real
	 * bare family names with no "The", but Grapevine's export text sometimes carries one
	 * ("Mortis: The Ash Path", "Thaumaturgy: The Path of Blood").
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
		// The one real seeded family that DOES start with "The " - the fallback must never
		// fire for it, since the exact check above already matches it on the first try.
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
	// Blood magic (BE_PROCESS/0.99.2-workflow.md, BM-7) - tradition-label normalization
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
	 * Confirmed against a real .gex export (Chase Ashford, 2026-09-11): the file spells the
	 * tradition "Dur-An-Ki" throughout, never "Dur An Ki" - a hyphen where the catalog uses a
	 * space. Case/whitespace/punctuation-insensitive normalization resolves this exactly,
	 * with no fuzzy matching needed.
	 */
	public function test_a_hyphenated_tradition_spelling_normalizes_to_the_catalog_form(): void {
		$result = Trait_Mapper::resolve_tiered_power_trait( 'Dur-An-Ki: Path of Blood', '1', $this->blood_magic_block() );

		$this->assertSame( 'exact', $result['outcome'] );
		$this->assertSame( 'Dur An Ki', $result['tradition'] );
	}

	/**
	 * Confirmed against the same real file: "Sadhanna" (one stray letter) for what the
	 * catalog spells "Sadhana". A single, unambiguous fuzzy match against the known
	 * tradition list is trusted; nothing else in a 6-item list is anywhere close to it.
	 */
	public function test_a_single_letter_typo_in_a_tradition_name_fuzzy_normalizes(): void {
		$result = Trait_Mapper::resolve_tiered_power_trait( 'Sadhanna: Path of Blood', '1', $this->blood_magic_block() );

		$this->assertSame( 'exact', $result['outcome'] );
		$this->assertSame( 'Sadhana', $result['tradition'] );
	}

	/**
	 * Confirmed against the same real file: "Eastern Necromancy" for what the catalog
	 * simply calls "Necromancy" - a genuinely different phrasing, not a typo, failing both
	 * the normalized-exact check and the fuzzy check's own first-letter/length window. The
	 * power and level this raw name resolves against are real either way, so the trait
	 * still resolves - but its tradition is stored exactly as the source file wrote it
	 * rather than silently guessed at, since a wrong guess is worse than an honest,
	 * human-correctable one.
	 */
	public function test_an_unrecognized_tradition_phrasing_is_kept_verbatim_not_guessed(): void {
		$result = Trait_Mapper::resolve_tiered_power_trait( 'Eastern Necromancy: Path of Blood', '1', $this->blood_magic_block() );

		$this->assertSame( 'exact', $result['outcome'], 'the path and level are real regardless of the tradition label' );
		$this->assertSame( 'Eastern Necromancy', $result['tradition'] );
	}

	/**
	 * A block that is not blood-magic flagged (every tiered_power block before this
	 * feature, and every other creature type's Discipline-shaped list today) must never
	 * have its tradition text touched - normalize_blood_magic_tradition() is a no-op there.
	 * Regression guard: this is exactly the ordinary-discipline shape
	 * test_a_tradition_prefixed_family_resolves_as_a_numbered_rung_not_an_unresolved_named_pick
	 * already covers, asserted here explicitly against a deliberately mis-cased/hyphenated
	 * label that WOULD normalize if the block were blood-magic flagged.
	 */
	public function test_tradition_normalization_never_runs_against_a_non_blood_magic_block(): void {
		$result = Trait_Mapper::resolve_tiered_power_trait( 'thau-maturgy: Fortitude', '3', $this->tiered_power_block() );

		$this->assertSame( 'exact', $result['outcome'] );
		$this->assertSame( 'thau-maturgy', $result['tradition'], 'an ordinary tiered_power block never normalizes a tradition label' );
	}

	/**
	 * Found the same pass as the "The " fallback above - the single largest real failure
	 * class by volume across the four real files (22 of ~73 combined unresolved traits).
	 * `vampire-combo-disciplines`' own catalog is bare ("Blood Sight", "Animal
	 * Magnetism"), confirmed directly, not assumed; Grapevine's export text prefixes a
	 * combo with "Combo: "/"Combination: " and often appends a trailing
	 * constituent-disciplines note the catalog never carries.
	 */
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

	/**
	 * The `*` decoration (Step 0d) is deliberately NOT stripped by `resolve_trait()` the
	 * way `resolve_tiered_power_trait()` strips it - checked directly, not assumed by
	 * analogy: `vampire-rituals` has 73 real seeded names that genuinely contain a literal
	 * `*` as part of the catalog data itself ("Thaumaturgy: Alter Blood* (Basic)").
	 * Stripping it here would turn that real exact match into a false fuzzy/unresolved
	 * result - this asserts the non-combo path is left alone.
	 */
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
	// resolve_chosen() - applying an ST's picked suggestion (Decision 061)
	// -------------------------------------------------------------------------

	public function test_resolve_chosen_applies_a_trait_list_suggestion(): void {
		$block  = $this->block( 'met-abilities', [ 'Brawl', 'Melee', 'Occult' ] );
		$result = Trait_Mapper::resolve_chosen( 'Occlt', '1', 'Occult', null, [ $block ] );

		$this->assertSame( 'exact', $result['outcome'] );
		$this->assertSame( 'Occult', $result['matched_name'] );
	}

	public function test_resolve_chosen_applies_a_numbered_rung_family_suggestion(): void {
		// The raw name was the typo the ST is correcting; the level (raw_total) is
		// unaffected by a family-name fix and must still resolve against it.
		$result = Trait_Mapper::resolve_chosen(
			'Fortitde', '3', 'Fortitude', $this->tiered_power_block(), []
		);

		$this->assertSame( 'exact', $result['outcome'] );
		$this->assertSame( 'Fortitude', $result['family'] );
		$this->assertSame( 3, $result['level'] );
	}

	public function test_resolve_chosen_applies_an_elder_pick_power_name_suggestion(): void {
		// raw_name keeps its real family + tier ("Fortitude: ... (elder)") - only the
		// fuzzy middle segment is replaced by the chosen suggestion.
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
