<?php

namespace BeyondElysium\Tests\Unit;

use BeyondElysium\Services\Catalog_Validator;
use PHPUnit\Framework\TestCase;

/**
 * A declared catalog file is rejected, not silently degraded: each case feeds a deliberately broken file and asserts
 * the specific complaint.
 */
class CatalogValidatorTest extends TestCase {

	/** @param array<string,mixed> $definition */
	private function block( string $section_type, array $definition, string $slug = 'test-block' ): array {
		return [ 'slug' => $slug, 'section_type' => $section_type, 'definition' => $definition ];
	}

	/**
	 * A family filling a 2/2/1 ladder correctly.
	 */
	private function ladder_family( string $name = 'Animalism' ): array {
		return [
			'name'   => $name,
			'levels' => [
				[ 'level' => 1, 'tier' => 'basic', 'power_name' => 'One' ],
				[ 'level' => 2, 'tier' => 'basic', 'power_name' => 'Two' ],
				[ 'level' => 3, 'tier' => 'intermediate', 'power_name' => 'Three' ],
				[ 'level' => 4, 'tier' => 'intermediate', 'power_name' => 'Four' ],
				[ 'level' => 5, 'tier' => 'advanced', 'power_name' => 'Five' ],
			],
		];
	}

	/** @param array<int,array<string,mixed>> $powers */
	private function tiered( array $powers, array $meta_overrides = [] ): array {
		$meta = array_merge( [
			'ranks'  => [ 'basic', 'intermediate', 'advanced', 'elder', 'master' ],
			'ladder' => [ 'basic' => 2, 'intermediate' => 2, 'advanced' => 1 ],
			'costs'  => [ 'basic' => 3, 'intermediate' => 6, 'advanced' => 9, 'elder' => 12, 'master' => 15 ],
		], $meta_overrides );
		return $this->block( 'tiered_power', [ '_meta' => $meta, 'powers' => $powers ] );
	}

	private function assertRejects( array $data, string $needle, string $stem = 'test-block' ): void {
		$errors = Catalog_Validator::validate_block( $data, $stem );
		$this->assertNotEmpty( $errors, 'expected this file to be rejected' );
		$this->assertStringContainsString(
			$needle,
			implode( ' | ', $errors ),
			'rejected, but not for the reason under test'
		);
	}

	// --- Rule 1/2: identity and shape -----------------------------------------

	public function test_the_slug_must_equal_the_filename_stem(): void {
		$this->assertRejects( $this->tiered( [ $this->ladder_family() ] ), 'they must match', 'a-different-name' );
	}

	public function test_an_unknown_section_type_is_rejected(): void {
		$this->assertRejects( $this->block( 'spreadsheet', [ 'items' => [] ] ), 'is not one of' );
	}

	public function test_a_block_missing_its_payload_key_is_rejected(): void {
		$this->assertRejects( $this->block( 'tiered_power', [ '_meta' => [ 'ranks' => [ 'basic' ] ] ] ), 'needs a `definition.powers` array' );
	}

	// --- Rule 3: trait_list ---------------------------------------------------

	public function test_a_trait_list_item_needs_a_name(): void {
		$this->assertRejects(
			$this->block( 'trait_list', [ 'items' => [ [ 'tier' => null, 'group' => null, 'subgroup' => null ] ] ] ),
			'has no `name`'
		);
	}

	public function test_an_omitted_facet_is_rejected_even_though_null_is_fine(): void {
		// "Nobody set this" and "this file predates the field" must stay distinguishable.
		$this->assertRejects(
			$this->block( 'trait_list', [ 'items' => [ [ 'name' => 'Mother\'s Touch', 'tier' => null, 'group' => null ] ] ] ),
			'is missing `subgroup`'
		);
	}

	public function test_a_numeric_cost_is_rejected_because_a_cost_is_free_text(): void {
		$this->assertRejects(
			$this->block( 'trait_list', [ 'items' => [ [ 'name' => 'Iron Will', 'tier' => null, 'group' => null, 'subgroup' => null, 'cost' => 3 ] ] ] ),
			'not a number'
		);
	}

	// --- Rule 4: tiered_power -------------------------------------------------

	public function test_an_empty_rank_vocabulary_is_rejected(): void {
		$this->assertRejects( $this->tiered( [ $this->ladder_family() ], [ 'ranks' => [] ] ), '`_meta.ranks` is empty' );
	}

	public function test_unknown_is_not_a_rank(): void {
		// It records an unclassified note, not a rank.
		$this->assertRejects(
			$this->tiered( [ $this->ladder_family() ], [ 'ranks' => [ 'basic', 'intermediate', 'advanced', 'unknown' ] ] ),
			'that is not a rank'
		);
	}

	public function test_a_ladder_rank_outside_the_vocabulary_is_rejected(): void {
		$this->assertRejects(
			$this->tiered( [ $this->ladder_family() ], [ 'ladder' => [ 'basic' => 2, 'legendary' => 3 ] ] ),
			'not in `_meta.ranks`'
		);
	}

	public function test_a_family_that_does_not_fill_its_ladder_is_rejected(): void {
		$short = $this->ladder_family();
		array_pop( $short['levels'] );
		$this->assertRejects( $this->tiered( [ $short ] ), 'against a declared ceiling of 5' );
	}

	public function test_non_consecutive_rung_numbering_is_rejected(): void {
		$odd = $this->ladder_family();
		$odd['levels'][4]['level'] = 7;
		$this->assertRejects( $this->tiered( [ $odd ] ), 'must run 1..5 consecutively' );
	}

	public function test_a_rung_carrying_a_pick_rank_is_rejected(): void {
		$bad = $this->ladder_family();
		$bad['levels'][4]['tier'] = 'elder';
		$this->assertRejects( $this->tiered( [ $bad ] ), 'contributes no rungs' );
	}

	public function test_picks_filed_under_a_ladder_rank_are_rejected(): void {
		// A rank is rungs or picks.
		$bad            = $this->ladder_family();
		$bad['elder']   = [ 'basic' => [ [ 'tier' => 'basic', 'power_name' => 'Wrong Home' ] ] ];
		$this->assertRejects( $this->tiered( [ $bad ] ), 'never both' );
	}

	public function test_a_pick_without_a_power_name_is_rejected(): void {
		$bad          = $this->ladder_family();
		$bad['elder'] = [ 'elder' => [ [ 'tier' => 'elder' ] ] ];
		$this->assertRejects( $this->tiered( [ $bad ] ), 'has no `power_name`' );
	}

	// --- The runtime list shape, the only accepted one ------------------------------

	public function test_powers_keyed_by_family_name_are_rejected(): void {
		$data                          = $this->tiered( [] );
		$data['definition']['powers'] = [ 'Animalism' => $this->ladder_family() ];
		$this->assertRejects( $data, '`definition.powers` must be a list' );
	}

	public function test_a_family_without_a_string_name_is_rejected(): void {
		$bad = $this->ladder_family();
		unset( $bad['name'] );
		$this->assertRejects( $this->tiered( [ $bad ] ), 'has no string `name`' );

		$bad['name'] = 7;
		$this->assertRejects( $this->tiered( [ $bad ] ), 'has no string `name`' );
	}

	public function test_a_rung_named_with_name_instead_of_power_name_is_rejected(): void {
		$bad                        = $this->ladder_family();
		$bad['levels'][0]['name']  = $bad['levels'][0]['power_name'];
		unset( $bad['levels'][0]['power_name'] );
		$this->assertRejects( $this->tiered( [ $bad ] ), 'levels[0] has no `power_name`' );
	}

	public function test_a_bare_string_pick_is_rejected(): void {
		$bad          = $this->ladder_family();
		$bad['elder'] = [ 'elder' => [ 'Animal Succulence' ] ];
		$this->assertRejects( $this->tiered( [ $bad ] ), 'is not an object' );
	}

	public function test_an_untiered_level_needs_a_power_name_too(): void {
		$this->assertRejects(
			$this->untiered( [ 'cost_per_level' => 2 ], [ [ 'level' => 1, 'name' => 'One' ] ] ),
			'levels[0] has no `power_name`'
		);
	}

	// --- Rule 5: no orphans ---------------------------------------------------

	public function test_a_non_empty_overflow_is_rejected_as_an_uncommitted_D67_family(): void {
		// The rule that earns the format: under the flat shape these were invisible and simply mis-priced.
		$bad             = $this->ladder_family();
		$bad['overflow'] = [ [ 'tier' => 'basic', 'power_name' => 'Second Ladder One' ] ];
		$this->assertRejects( $this->tiered( [ $bad ] ), 'two ladders merged' );
	}

	// --- The other direction: valid files must pass ---------------------------

	public function test_a_correct_tiered_power_file_passes(): void {
		$family          = $this->ladder_family();
		$family['elder'] = [
			'elder'  => [ [ 'tier' => 'elder', 'power_name' => 'Animal Succulence' ] ],
			'master' => [ [ 'tier' => 'master', 'power_name' => 'Stampede' ] ],
		];
		$this->assertSame( [], Catalog_Validator::validate_block( $this->tiered( [ $family ] ), 'test-block' ) );
	}

	public function test_a_correct_trait_list_file_passes(): void {
		$data = $this->block( 'trait_list', [
			'items' => [
				[ 'name' => 'Mother\'s Touch', 'tier' => 'basic', 'group' => 'Theurge', 'subgroup' => null, 'cost' => '3' ],
				[ 'name' => 'Uncosted Thing', 'tier' => null, 'group' => null, 'subgroup' => null, 'cost' => null ],
			],
		] );
		$this->assertSame( [], Catalog_Validator::validate_block( $data, 'test-block' ) );
	}

	public function test_a_list_may_declare_paid_from_as_block_slug_dot_pool_name(): void {
		$data = $this->block( 'trait_list', [
			'items' => [
				[ 'name' => 'Freudian Slip', 'tier' => null, 'group' => null, 'subgroup' => null, 'cost' => '5' ],
			],
			'_meta' => [ 'paid_from' => 'wraith-shadow.Shadow XP' ],
		] );
		$this->assertSame( [], Catalog_Validator::validate_block( $data, 'test-block' ) );
	}

	public function test_paid_from_with_no_dot_is_rejected(): void {
		$data = $this->block( 'trait_list', [
			'items' => [ [ 'name' => 'Thing', 'tier' => null, 'group' => null, 'subgroup' => null, 'cost' => '1' ] ],
			'_meta' => [ 'paid_from' => 'wraith-shadow' ],
		] );
		$this->assertRejects( $data, '`_meta.paid_from` must be' );
	}

	public function test_paid_from_with_an_empty_side_is_rejected(): void {
		$data = $this->block( 'trait_list', [
			'items' => [ [ 'name' => 'Thing', 'tier' => null, 'group' => null, 'subgroup' => null, 'cost' => '1' ] ],
			'_meta' => [ 'paid_from' => '.Shadow XP' ],
		] );
		$this->assertRejects( $data, '`_meta.paid_from` must be' );
	}

	/** @param array<string,mixed> $overrides */
	private function canonicalization( array $overrides = [] ): array {
		return array_merge( [
			'form'          => 'group_name_tier',
			'tier_words'    => [ 'b' => 'Basic', 'int' => 'Intermediate' ],
			'group_aliases' => [ 'Koldunic' => 'Koldunism' ],
		], $overrides );
	}

	/** @param mixed $rule */
	private function trait_list_with_canonicalization( $rule ): array {
		return $this->block( 'trait_list', [
			'name_canonicalization' => $rule,
			'items'                 => [ [ 'name' => 'Thaumaturgy: Blood Walk (basic)', 'tier' => 'basic', 'group' => 'Thaumaturgy', 'subgroup' => null ] ],
		] );
	}

	public function test_a_trait_list_may_declare_name_canonicalization(): void {
		$this->assertSame( [], Catalog_Validator::validate_block( $this->trait_list_with_canonicalization( $this->canonicalization() ), 'test-block' ) );
	}

	public function test_group_aliases_is_optional(): void {
		$rule = $this->canonicalization();
		unset( $rule['group_aliases'] );
		$this->assertSame( [], Catalog_Validator::validate_block( $this->trait_list_with_canonicalization( $rule ), 'test-block' ) );
	}

	public function test_name_canonicalization_must_be_an_object(): void {
		$this->assertRejects( $this->trait_list_with_canonicalization( 'group_name_tier' ), '`name_canonicalization` must be an object' );
	}

	public function test_name_canonicalization_form_must_be_a_recognized_value(): void {
		$this->assertRejects(
			$this->trait_list_with_canonicalization( $this->canonicalization( [ 'form' => 'group_tier_name' ] ) ),
			'"group_tier_name" is not one of: group_name_tier'
		);
	}

	public function test_name_canonicalization_form_is_required(): void {
		$rule = $this->canonicalization();
		unset( $rule['form'] );
		$this->assertRejects( $this->trait_list_with_canonicalization( $rule ), 'is not one of: group_name_tier' );
	}

	public function test_a_tier_words_entry_must_be_a_non_empty_string(): void {
		$this->assertRejects(
			$this->trait_list_with_canonicalization( $this->canonicalization( [ 'tier_words' => [ 'b' => '' ] ] ) ),
			'`name_canonicalization.tier_words["b"]` must be a non-empty string'
		);
	}

	public function test_a_group_aliases_entry_must_be_a_non_empty_string(): void {
		$this->assertRejects(
			$this->trait_list_with_canonicalization( $this->canonicalization( [ 'group_aliases' => [ 'Koldunic' => 3 ] ] ) ),
			'`name_canonicalization.group_aliases["Koldunic"]` must be a non-empty string'
		);
	}

	public function test_a_canonicalization_map_must_be_an_object(): void {
		$this->assertRejects(
			$this->trait_list_with_canonicalization( $this->canonicalization( [ 'tier_words' => 'not a map' ] ) ),
			'`name_canonicalization.tier_words` must be an object'
		);
	}

	public function test_group_name_tier_requires_tier_words(): void {
		$rule = $this->canonicalization();
		unset( $rule['tier_words'] );
		$this->assertRejects( $this->trait_list_with_canonicalization( $rule ), 'needs `tier_words`' );
	}

	public function test_a_family_with_no_picks_at_all_is_fine(): void {
		// mage-spheres and changeling-realms have none; an absent `elder` is not an error.
		$this->assertSame( [], Catalog_Validator::validate_block( $this->tiered( [ $this->ladder_family() ] ), 'test-block' ) );
	}

	// --- An untiered track has no ranks, by design ----------------------------

	/** @param array<string,mixed> $untiered */
	private function untiered( array $untiered, array $levels = null ): array {
		$levels = $levels ?? [
			[ 'level' => 1, 'power_name' => 'One' ],
			[ 'level' => 2, 'power_name' => 'Two' ],
		];
		return $this->block( 'tiered_power', [
			'_meta'  => [ 'ranks' => [], 'ladder' => [], 'costs' => [], 'untiered' => $untiered ],
			'powers' => [ [ 'name' => 'Actor', 'levels' => $levels ] ],
		] );
	}

	public function test_a_flat_untiered_track_passes_with_no_ranks_at_all(): void {
		// Changeling Realms: 2 flat per level, no tier vocabulary anywhere.
		$this->assertSame( [], Catalog_Validator::validate_block( $this->untiered( [ 'cost_per_level' => 2 ] ), 'test-block' ) );
	}

	public function test_a_track_deriving_its_costs_is_rejected(): void {
		$this->assertRejects( $this->untiered( [ 'derived_from' => 'mage-spheres', 'per_level' => 1 ] ), 'a track has none' );
	}

	public function test_an_untiered_track_stating_no_rule_is_rejected(): void {
		$this->assertRejects( $this->untiered( [] ), 'states no rule' );
	}

	public function test_an_untiered_track_stating_two_rules_is_rejected(): void {
		$this->assertRejects( $this->untiered( [ 'cost_per_level' => 2, 'derived_from' => 'mage-spheres' ] ), 'not two' );
	}

	public function test_a_derived_track_without_per_level_is_rejected(): void {
		$this->assertRejects( $this->untiered( [ 'derived_from' => 'mage-spheres' ] ), 'needs `per_level`' );
	}

	public function test_an_untiered_track_that_also_declares_ranks_is_rejected(): void {
		// Ranked or not - claiming both means nothing downstream can tell which rule applies.
		$data = $this->block( 'tiered_power', [
			'_meta'  => [ 'ranks' => [ 'basic' ], 'ladder' => [], 'costs' => [], 'untiered' => [ 'cost_per_level' => 2 ] ],
			'powers' => [ [ 'name' => 'Actor', 'levels' => [ [ 'level' => 1, 'power_name' => 'One' ] ] ] ],
		] );
		$this->assertRejects( $data, 'contradict each other' );
	}

	public function test_an_untiered_track_still_numbers_its_levels_consecutively(): void {
		$this->assertRejects(
			$this->untiered( [ 'cost_per_level' => 2 ], [ [ 'level' => 1, 'power_name' => 'One' ], [ 'level' => 4, 'power_name' => 'Four' ] ] ),
			'runs 1..N consecutively'
		);
	}

	public function test_an_untiered_track_may_not_carry_picks(): void {
		$data = $this->untiered( [ 'cost_per_level' => 2 ] );
		$data['definition']['powers'][0]['elder'] = [ 'elder' => [ [ 'power_name' => 'Nope' ] ] ];
		$this->assertRejects( $data, 'no ranks to file them under' );
	}
	// --- A pick-only track: ranks, no rating (Gifts) --------------------------

	/** @param array<int,array<string,mixed>> $powers */
	private function pick_only( array $powers, bool $declare_ladder = true ): array {
		$meta = [
			'ranks'  => [ 'basic', 'intermediate', 'advanced' ],
			'costs'  => [ 'basic' => 3, 'intermediate' => 6, 'advanced' => 9 ],
			'levels' => [ 'basic' => 1, 'intermediate' => 3, 'advanced' => 5 ],
		];
		if ( $declare_ladder ) {
			$meta['ladder'] = [];
		}
		return $this->block( 'tiered_power', [ '_meta' => $meta, 'powers' => $powers ] );
	}

	private function gift_family(): array {
		// Two Intermediate Gifts and no Basic one: legal, and must not be flagged.
		return [
			'name'            => 'Bone Gnawer',
			'category_values' => [ 'tribe' => 'Bone Gnawer' ],
			'levels'          => [],
			'elder'           => [
				'intermediate' => [
					[ 'tier' => 'intermediate', 'power_name' => 'Cooking' ],
					[ 'tier' => 'intermediate', 'power_name' => 'Tagalong' ],
				],
			],
		];
	}

	public function test_a_pick_only_track_declaring_an_empty_ladder_passes(): void {
		$this->assertSame( [], Catalog_Validator::validate_block( $this->pick_only( [ $this->gift_family() ] ), 'test-block' ) );
	}

	public function test_a_track_that_omits_its_ladder_is_still_rejected(): void {
		// Silence is not a declaration: only an explicit `{}` means "no rungs".
		$this->assertRejects( $this->pick_only( [ $this->gift_family() ], false ), 'sums to zero' );
	}

	public function test_a_pick_only_family_may_not_carry_rungs(): void {
		$bad           = $this->gift_family();
		$bad['levels'] = [ [ 'level' => 1, 'tier' => 'basic', 'power_name' => 'Smell of Man' ] ];
		$this->assertRejects( $this->pick_only( [ $bad ] ), 'against a declared ceiling of 0' );
	}
	// --- Whole files: the envelope, stacks, templates, presets, references ------

	/** @param array<string,mixed> $definition */
	private function file( string $kind, string $slug, array $definition, array $extra = [] ): array {
		return array_merge( [
			'format'     => 1,
			'slug'       => $slug,
			'name'       => 'Test',
			'kind'       => $kind,
			'provenance' => [ 'sources' => [ 'a real book, p. 1' ] ],
			'definition' => $definition,
		], $extra );
	}

	private function stack_definition(): array {
		return [
			'game_line'      => 'met',
			'sections'       => [
				[ 'block_slug' => 'werewolf-identity', 'label' => 'Identity', 'display_order' => 1, 'required' => true ],
				[ 'block_slug' => 'werewolf-gifts', 'label' => 'Gifts', 'display_order' => 60, 'required' => true, 'in_type' => [ [ 'kind' => 'names', 'values' => [ 'map' => 'werewolf-tribes.tribe_gifts', 'by' => [ 'Tribe' ] ] ] ] ],
				[ 'block_slug' => 'met-physical-traits', 'label' => 'Physical', 'display_order' => 50, 'required' => true, 'negative_block_slug' => 'met-physical-traits-neg' ],
			],
			'creation_rules' => [ 'steps' => [ [ 'kind' => 'budget', 'label' => 'Abilities', 'section' => 'met-physical-traits', 'count' => 5 ] ] ],
		];
	}

	/** @param string[] $blocks */
	private function assertRefErrors( array $data, string $stem, array $blocks, array $stacks, string $needle ): void {
		$errors = Catalog_Validator::validate_references( $data, $stem, $blocks, $stacks );
		$this->assertStringContainsString( $needle, implode( ' | ', $errors ) );
	}

	public function test_an_unknown_format_is_rejected(): void {
		$data = $this->file( 'preset', 'position-presets', [ 'A' => [ 'B' ] ], [ 'format' => 2 ] );
		$this->assertStringContainsString( '`format` must be', implode( ' | ', Catalog_Validator::validate_file( $data, 'position-presets' ) ) );
	}

	public function test_a_file_with_no_provenance_is_rejected(): void {
		$data = $this->file( 'preset', 'position-presets', [ 'A' => [ 'B' ] ], [ 'provenance' => [] ] );
		$this->assertStringContainsString( 'provenance.sources', implode( ' | ', Catalog_Validator::validate_file( $data, 'position-presets' ) ) );
	}

	public function test_an_unknown_kind_is_rejected(): void {
		$data = $this->file( 'spreadsheet', 'x', [ 'a' ] );
		$this->assertStringContainsString( 'is not one of', implode( ' | ', Catalog_Validator::validate_file( $data, 'x' ) ) );
	}

	public function test_a_valid_stack_file_passes(): void {
		$this->assertSame( [], Catalog_Validator::validate_file( $this->file( 'stack', 'werewolf', $this->stack_definition() ), 'werewolf' ) );
	}

	public function test_a_stack_section_without_an_order_is_rejected(): void {
		$def = $this->stack_definition();
		unset( $def['sections'][0]['display_order'] );
		$this->assertStringContainsString( 'integer `display_order`', implode( ' | ', Catalog_Validator::validate_file( $this->file( 'stack', 'werewolf', $def ), 'werewolf' ) ) );
	}

	public function test_the_retired_in_type_join_is_rejected(): void {
		$def = $this->stack_definition();
		$def['sections'][1]['in_type_source'] = 'werewolf-identity.Tribe';
		$this->assertStringContainsString( '`in_type_source`, which is retired', implode( ' | ', Catalog_Validator::validate_file( $this->file( 'stack', 'werewolf', $def ), 'werewolf' ) ) );
	}

	/**
	 * @param array<int,mixed> $in_type
	 */
	private function in_type_errors( $in_type ): string {
		$def                            = $this->stack_definition();
		$def['sections'][1]['in_type'] = $in_type;
		return implode( ' | ', Catalog_Validator::validate_file( $this->file( 'stack', 'werewolf', $def ), 'werewolf' ) );
	}

	public function test_every_kind_of_in_type_test_passes_in_its_own_shape(): void {
		$this->assertSame( '', $this->in_type_errors( [
			[ 'kind' => 'names', 'values' => [ 'field' => [ 'werewolf-identity.Breed', 'werewolf-identity.Auspice', 'werewolf-identity.Tribe' ] ] ],
			[ 'kind' => 'names', 'values' => [ 'constant' => [ 'Potence' ] ], 'when' => [ 'field' => 'werewolf-identity.Tribe', 'set' => false ] ],
			[ 'kind' => 'chosen', 'values' => [ 'field' => 'werewolf-identity.chosen_gifts' ], 'when' => [ 'field' => 'werewolf-identity.Tribe', 'is' => [ 'Ronin' ] ] ],
			[ 'kind' => 'all', 'tests' => [
				[ 'kind' => 'facet', 'facet' => 'group', 'values' => [ 'map' => 'werewolf-tribes.species', 'by' => [ 'Fera Type' ] ] ],
				[ 'kind' => 'facet', 'facet' => 'subgroup', 'values' => [ 'field' => 'werewolf-identity.Breed' ] ],
			] ],
		] ) );
	}

	public function test_a_facet_test_names_a_tiered_powers_own_category_axis(): void {
		$this->assertSame( '', $this->in_type_errors( [
			[ 'kind' => 'facet', 'facet' => 'breed', 'values' => [ 'field' => 'werewolf-identity.Breed' ] ],
		] ) );
	}

	public function test_an_empty_in_type_list_is_rejected(): void {
		$this->assertStringContainsString( 'must be a non-empty list of tests', $this->in_type_errors( [] ) );
	}

	public function test_malformed_in_type_tests_are_rejected_for_their_own_reason(): void {
		$cases = [
			'`kind` "maybe"'                        => [ [ 'kind' => 'maybe', 'values' => [ 'constant' => [ 'A' ] ] ] ],
			'exactly one of `field`, `map`'        => [ [ 'kind' => 'names', 'values' => [ 'constant' => [ 'A' ], 'field' => 'werewolf-identity.Tribe' ] ] ],
			'names no source'                      => [ [ 'kind' => 'names', 'values' => [] ] ],
			'is missing'                           => [ [ 'kind' => 'names' ] ],
			'.field must be'                       => [ [ 'kind' => 'names', 'values' => [ 'field' => 'Tribe' ] ] ],
			'.by must list'                        => [ [ 'kind' => 'names', 'values' => [ 'map' => 'werewolf-tribes.tribe_gifts' ] ] ],
			'.constant must be'                    => [ [ 'kind' => 'names', 'values' => [ 'constant' => [] ] ] ],
			'needs a non-empty `facet` name'       => [ [ 'kind' => 'facet', 'values' => [ 'constant' => [ 'A' ] ] ] ],
			'reads the character\'s picks'         => [ [ 'kind' => 'chosen', 'values' => [ 'map' => 'werewolf-tribes.tribe_gifts', 'by' => [ 'Tribe' ] ] ] ],
			'needs a non-empty `tests` list'       => [ [ 'kind' => 'all', 'tests' => [] ] ],
			'exactly one of `is`'                  => [ [ 'kind' => 'names', 'values' => [ 'constant' => [ 'A' ] ], 'when' => [ 'field' => 'werewolf-identity.Tribe', 'is' => [ 'A' ], 'set' => true ] ] ],
			'.set must be true or false'           => [ [ 'kind' => 'names', 'values' => [ 'constant' => [ 'A' ] ], 'when' => [ 'field' => 'werewolf-identity.Tribe', 'set' => 'yes' ] ] ],
			'tests[0] has `kind`'                  => [ [ 'kind' => 'all', 'tests' => [ [ 'kind' => 'nope' ] ] ] ],
		];
		foreach ( $cases as $needle => $in_type ) {
			$this->assertStringContainsString( $needle, $this->in_type_errors( $in_type ), $needle );
		}
	}

	public function test_in_type_tests_name_fields_and_maps_their_identity_blocks_declare(): void {
		$identity = $this->file( 'block', 'test-identity', [
			'fields'   => [ [ 'name' => 'Clan' ], [ 'name' => 'Sect' ] ],
			'clan_map' => [ 'Brujah' => [ 'Celerity' ] ],
		] );
		$stack = function ( array $in_type ): array {
			return $this->file( 'stack', 'test-stack', [ 'sections' => [ [ 'block_slug' => 'test-powers', 'label' => 'Powers', 'display_order' => 1, 'in_type' => $in_type ] ] ] );
		};
		$blocks = [ 'test-identity' => $identity ];

		$this->assertSame( [], Catalog_Validator::validate_in_type_refs( $stack( [
			[ 'kind' => 'names', 'values' => [ 'map' => 'test-identity.clan_map', 'by' => [ 'Clan' ] ] ],
			[ 'kind' => 'chosen', 'values' => [ 'field' => 'test-identity.chosen_in_clan' ], 'when' => [ 'field' => 'test-identity.Clan', 'is' => [ 'Caitiff' ] ] ],
		] ), $blocks ), 'a character\'s own picks are a sheet record, not a declared field' );

		$field = implode( ' | ', Catalog_Validator::validate_in_type_refs( $stack( [ [ 'kind' => 'names', 'values' => [ 'field' => 'test-identity.Bloodline' ] ] ] ), $blocks ) );
		$this->assertStringContainsString( '"test-identity.Bloodline", which is not a declared field', $field );

		$map = implode( ' | ', Catalog_Validator::validate_in_type_refs( $stack( [ [ 'kind' => 'names', 'values' => [ 'map' => 'test-identity.sect_map', 'by' => [ 'Sect' ] ] ] ] ), $blocks ) );
		$this->assertStringContainsString( 'the map "test-identity.sect_map", which "test-identity" does not keep', $map );

		$by = implode( ' | ', Catalog_Validator::validate_in_type_refs( $stack( [ [ 'kind' => 'names', 'values' => [ 'map' => 'test-identity.clan_map', 'by' => [ 'Lineage' ] ] ] ] ), $blocks ) );
		$this->assertStringContainsString( '"test-identity.Lineage", which is not a declared field', $by );

		$when = implode( ' | ', Catalog_Validator::validate_in_type_refs( $stack( [ [ 'kind' => 'names', 'values' => [ 'constant' => [ 'A' ] ], 'when' => [ 'field' => 'test-identity.Path', 'set' => true ] ] ] ), $blocks ) );
		$this->assertStringContainsString( '"test-identity.Path", which is not a declared field', $when );
	}

	public function test_a_section_may_declare_replaces(): void {
		$def = $this->stack_definition();
		$def['sections'][0]['replaces'] = [ 'met-identity' ];
		$this->assertSame( [], Catalog_Validator::validate_file( $this->file( 'stack', 'werewolf', $def ), 'werewolf' ) );
	}

	public function test_replaces_must_be_a_non_empty_list(): void {
		$def = $this->stack_definition();
		$def['sections'][0]['replaces'] = 'met-identity';
		$this->assertStringContainsString( '`replaces` must be a non-empty list', implode( ' | ', Catalog_Validator::validate_file( $this->file( 'stack', 'werewolf', $def ), 'werewolf' ) ) );

		$def2 = $this->stack_definition();
		$def2['sections'][0]['replaces'] = [];
		$this->assertStringContainsString( '`replaces` must be a non-empty list', implode( ' | ', Catalog_Validator::validate_file( $this->file( 'stack', 'werewolf', $def2 ), 'werewolf' ) ) );
	}

	public function test_replaces_entries_must_be_non_empty_strings(): void {
		$def = $this->stack_definition();
		$def['sections'][0]['replaces'] = [ '' ];
		$this->assertStringContainsString( '`replaces` entries must be non-empty slug strings', implode( ' | ', Catalog_Validator::validate_file( $this->file( 'stack', 'werewolf', $def ), 'werewolf' ) ) );
	}

	public function test_replaces_cannot_name_a_slug_this_stack_still_declares(): void {
		$def = $this->stack_definition();
		$def['sections'][0]['replaces'] = [ 'werewolf-gifts' ];
		$this->assertStringContainsString( 'still declares as a section', implode( ' | ', Catalog_Validator::validate_file( $this->file( 'stack', 'werewolf', $def ), 'werewolf' ) ) );
	}

	public function test_a_slug_can_only_be_replaced_once_per_stack(): void {
		$def = $this->stack_definition();
		$def['sections'][0]['replaces'] = [ 'met-abilities' ];
		$def['sections'][1]['replaces'] = [ 'met-abilities' ];
		$this->assertStringContainsString( 'already claimed by section', implode( ' | ', Catalog_Validator::validate_file( $this->file( 'stack', 'werewolf', $def ), 'werewolf' ) ) );
	}

	public function test_replaces_may_name_a_slug_that_is_a_live_block_for_another_stack(): void {
		$def = $this->stack_definition();
		unset( $def['creation_rules'] );
		$def['sections'][0]['replaces'] = [ 'werewolf-rites' ];
		$data = $this->file( 'stack', 'fera', $def );
		$this->assertSame( [], Catalog_Validator::validate_references( $data, 'fera', [ 'werewolf-identity', 'werewolf-gifts', 'werewolf-tribes', 'met-physical-traits', 'met-physical-traits-neg', 'werewolf-rites' ], [ 'fera' ] ) );
	}

	public function test_a_template_slug_must_name_a_real_template_type(): void {
		$data = $this->file( 'template', 'werewolf.poster', [ 'sections' => [ [ 'block_slug' => 'werewolf-identity' ] ] ] );
		$this->assertStringContainsString( '"<stack>.<type>"', implode( ' | ', Catalog_Validator::validate_file( $data, 'werewolf.poster' ) ) );
	}

	public function test_a_valid_template_and_preset_pass(): void {
		$tpl = $this->file( 'template', 'werewolf.sheet_full', [ 'columns' => 2, 'sections' => [ [ 'block_slug' => 'werewolf-identity', 'column' => 1 ] ] ] );
		$this->assertSame( [], Catalog_Validator::validate_file( $tpl, 'werewolf.sheet_full' ) );
		$this->assertSame( [], Catalog_Validator::validate_file( $this->file( 'preset', 'approval-reason-presets', [ 'Coordinator Approval' ] ), 'approval-reason-presets' ) );
	}

	public function test_a_template_section_may_list_its_former_titles(): void {
		$tpl = $this->file( 'template', 'werewolf.sheet_full', [ 'sections' => [ [ 'block_slug' => 'werewolf-identity', 'title' => 'Identity', 'former_titles' => [ 'Old Identity', 'Older Identity' ] ] ] ] );
		$this->assertSame( [], Catalog_Validator::validate_file( $tpl, 'werewolf.sheet_full' ) );
	}

	public function test_former_titles_must_be_a_non_empty_list_of_titles(): void {
		foreach ( [ 'Old Identity', [], [ '' ], [ 'Old Identity', 7 ], [ 'a' => 'Old Identity' ] ] as $former ) {
			$tpl = $this->file( 'template', 'werewolf.sheet_full', [ 'sections' => [ [ 'block_slug' => 'werewolf-identity', 'former_titles' => $former ] ] ] );
			$this->assertStringContainsString( '`former_titles` must be a non-empty list of titles', implode( ' | ', Catalog_Validator::validate_file( $tpl, 'werewolf.sheet_full' ) ), wp_json_encode( $former ) );
		}
	}

	public function test_a_block_still_answers_to_every_block_rule_through_validate_file(): void {
		$data = array_merge( $this->tiered( [ $this->ladder_family() ], [ 'ranks' => [] ] ), [ 'format' => 1, 'name' => 'T', 'kind' => 'block', 'provenance' => [ 'sources' => [ 'x' ] ] ] );
		$this->assertStringContainsString( '`_meta.ranks` is empty', implode( ' | ', Catalog_Validator::validate_file( $data, 'test-block' ) ) );
	}

	public function test_every_block_a_stack_names_must_have_a_file(): void {
		$data  = $this->file( 'stack', 'werewolf', $this->stack_definition() );
		$every = [ 'werewolf-identity', 'werewolf-gifts', 'werewolf-tribes', 'met-physical-traits', 'met-physical-traits-neg' ];
		$this->assertSame( [], Catalog_Validator::validate_references( $data, 'werewolf', $every, [ 'werewolf' ] ) );
		foreach ( $every as $slug ) {
			$this->assertRefErrors( $data, 'werewolf', array_values( array_diff( $every, [ $slug ] ) ), [ 'werewolf' ], "\"{$slug}\"" );
		}
	}

	public function test_a_template_needs_its_stack_file(): void {
		$tpl = $this->file( 'template', 'werewolf.sheet_full', [ 'sections' => [ [ 'block_slug' => 'werewolf-identity' ] ] ] );
		$this->assertRefErrors( $tpl, 'werewolf.sheet_full', [ 'werewolf-identity' ], [], 'stack "werewolf"' );
	}

	public function test_a_variant_needs_its_base_file(): void {
		$v = $this->file( 'block', 'owbn-wraith_arcanoi', [ 'powers' => [] ], [ 'variant' => [ 'of' => 'wraith-arcanoi', 'id' => 'owbn', 'mode' => 'replace' ] ] );
		$this->assertRefErrors( $v, 'owbn-wraith_arcanoi', [ 'owbn-wraith_arcanoi' ], [], 'block "wraith-arcanoi"' );
	}

	/**
	 * @param array<int,array<string,mixed>> $powers
	 * @return array<string,mixed>
	 */
	private static function variant_file( string $slug, array $powers, string $mode = '' ): array {
		$data = [ 'slug' => $slug, 'kind' => 'block', 'section_type' => 'tiered_power', 'definition' => [ 'powers' => $powers ] ];
		if ( $mode !== '' ) {
			$data['variant'] = [ 'of' => 'base-gifts', 'id' => 'packet', 'label' => 'Packet', 'mode' => $mode ];
		}
		return $data;
	}

	public function test_an_add_variant_with_ladder_levels_in_a_base_family_is_refused(): void {
		$base    = self::variant_file( 'base-gifts', [ [ 'name' => 'Homid', 'levels' => [], 'elder' => [] ] ] );
		$variant = self::variant_file( 'packet-gifts', [ [ 'name' => 'Homid', 'levels' => [ [ 'level' => 1, 'power_name' => 'Jam Gun', 'cost' => '3' ] ], 'elder' => [] ] ], 'add' );

		$errors = Catalog_Validator::validate_variant( $variant, $base );

		$this->assertCount( 1, $errors );
		$this->assertStringContainsString( '"Homid"', $errors[0] );
	}

	public function test_an_add_variant_with_picks_in_a_base_family_or_a_family_of_its_own_passes(): void {
		$base    = self::variant_file( 'base-gifts', [ [ 'name' => 'Homid', 'levels' => [], 'elder' => [] ] ] );
		$variant = self::variant_file( 'packet-gifts', [
			[ 'name' => 'Homid', 'levels' => [], 'elder' => [ 'advanced' => [ [ 'power_name' => 'Weave of Steel', 'cost' => '9' ] ] ] ],
			[ 'name' => 'Metis', 'levels' => [ [ 'level' => 1, 'power_name' => 'Sense Wyrm', 'cost' => '3' ] ], 'elder' => [] ],
		], 'add' );

		$this->assertSame( [], Catalog_Validator::validate_variant( $variant, $base ) );
		$this->assertSame( [], Catalog_Validator::validate_variant( self::variant_file( 'owbn-gifts', [ [ 'name' => 'Homid', 'levels' => [ [ 'level' => 1 ] ] ] ], 'replace' ), $base ), 'a replacing variant is its own whole block' );
	}

	// --- Cost expressions -------------------------------------------------------

	public function test_per_rank_modifiers_on_both_sides_pass(): void {
		$data = $this->tiered( [ $this->ladder_family() ], [
			'in_type'     => [ 'basic' => '-1', 'intermediate' => '-1', 'advanced' => '+0' ],
			'out_of_type' => [ 'basic' => '+1', 'intermediate' => '+2', 'advanced' => '×2' ],
		] );
		$this->assertSame( [], Catalog_Validator::validate_block( $data, 'test-block' ) );
	}

	public function test_a_modifier_for_a_rank_the_block_lacks_is_rejected(): void {
		$this->assertRejects( $this->tiered( [ $this->ladder_family() ], [ 'out_of_type' => [ 'legend' => '+1' ] ] ), 'rank "legend", which is not in `_meta.ranks`' );
	}

	public function test_a_modifier_that_is_not_an_expression_is_rejected(): void {
		$this->assertRejects( $this->tiered( [ $this->ladder_family() ], [ 'in_type' => [ 'basic' => 'half' ] ] ), 'a modifier is +N, -N or ×N' );
		$this->assertRejects( $this->tiered( [ $this->ladder_family() ], [ 'out_of_type' => [ 'basic' => 1 ] ] ), 'a modifier is +N, -N or ×N' );
	}

	public function test_modifiers_as_a_list_are_rejected(): void {
		$this->assertRejects( $this->tiered( [ $this->ladder_family() ], [ 'out_of_type' => [ '+1', '+1', '+1' ] ] ), 'must be a map from rank to modifier' );
	}

	public function test_an_untiered_track_carrying_modifiers_is_rejected(): void {
		$data                                        = $this->untiered( [ 'cost_per_level' => 2 ] );
		$data['definition']['_meta']['out_of_type'] = [ 'basic' => '+1' ];
		$this->assertRejects( $data, 'no ranks for a modifier to name' );
	}

	public function test_the_retired_flat_modifier_is_rejected(): void {
		$data                                              = $this->tiered( [ $this->ladder_family() ] );
		$data['definition']['out_of_type_cost_modifier'] = 1;
		$this->assertRejects( $data, '`out_of_type_cost_modifier` is retired' );
	}

	/**
	 * @param array<string,mixed> $untiered
	 * @param array<int,array<string,mixed>> $items
	 */
	private function derived_list( array $untiered, array $items ): array {
		return $this->block( 'trait_list', [ '_meta' => [ 'untiered' => $untiered ], 'items' => $items ] );
	}

	private function rote( string $name, array $prerequisites, ?string $cost = null ): array {
		return [ 'name' => $name, 'cost' => $cost, 'tier' => null, 'group' => null, 'subgroup' => null, 'prerequisites' => $prerequisites ];
	}

	private function needs( string $power, int $level, string $block = 'mage-spheres' ): array {
		return [ 'block_slug' => $block, 'power' => $power, 'min_level' => $level ];
	}

	public function test_a_list_deriving_its_costs_passes(): void {
		$data = $this->derived_list( [ 'derived_from' => 'mage-spheres', 'per_level' => 1 ], [
			$this->rote( 'Access This', [ $this->needs( 'Correspondence', 2 ), $this->needs( 'Forces', 2 ) ] ),
			$this->rote( 'Priced', [], '5' ),
		] );
		$this->assertSame( [], Catalog_Validator::validate_block( $data, 'test-block' ) );
	}

	public function test_a_list_item_with_nothing_to_price_it_is_rejected(): void {
		$data = $this->derived_list( [ 'derived_from' => 'mage-spheres', 'per_level' => 1 ], [ $this->rote( 'Unstated', [ $this->needs( 'Auspex', 2, 'vampire-disciplines' ) ] ) ] );
		$this->assertRejects( $data, '"Unstated" has no `cost` and no prerequisite in "mage-spheres"' );
	}

	public function test_a_list_states_where_its_costs_derive_from_and_how_much(): void {
		$this->assertRejects( $this->derived_list( [ 'per_level' => 1 ], [ $this->rote( 'A', [], '1' ) ] ), 'needs `derived_from`' );
		$this->assertRejects( $this->derived_list( [ 'derived_from' => 'mage-spheres', 'per_level' => 0 ], [ $this->rote( 'A', [], '1' ) ] ), 'untiered.per_level` must be a whole number from 1' );
		$this->assertRejects( $this->derived_list( [ 'derived_from' => 'mage-spheres', 'per_level' => 1, 'cost_per_level' => 2 ], [ $this->rote( 'A', [], '1' ) ] ), 'only a track has a cost per level' );
	}

	public function test_a_malformed_prerequisite_is_rejected(): void {
		$data = $this->derived_list( [ 'derived_from' => 'mage-spheres', 'per_level' => 1 ], [
			$this->rote( 'A', [ [ 'block_slug' => 'mage-spheres', 'power' => 'Forces', 'min_level' => '2' ] ], '1' ),
		] );
		$this->assertRejects( $data, 'a prerequisite that is not {block_slug, power, min_level}' );
	}

	private function spheres_file(): array {
		return $this->tiered( [ $this->ladder_family( 'Forces' ) ] );
	}

	public function test_derived_prerequisites_name_real_families_at_real_levels(): void {
		$good = $this->derived_list( [ 'derived_from' => 'mage-spheres', 'per_level' => 1 ], [ $this->rote( 'A', [ $this->needs( 'Forces', 5 ) ] ) ] );
		$this->assertSame( [], Catalog_Validator::validate_derived( $good, $this->spheres_file() ) );

		$unknown = $this->derived_list( [ 'derived_from' => 'mage-spheres', 'per_level' => 1 ], [ $this->rote( 'A', [ $this->needs( 'Data', 2 ) ] ) ] );
		$this->assertStringContainsString( 'not a family of "mage-spheres"', implode( ' | ', Catalog_Validator::validate_derived( $unknown, $this->spheres_file() ) ) );

		$too_high = $this->derived_list( [ 'derived_from' => 'mage-spheres', 'per_level' => 1 ], [ $this->rote( 'A', [ $this->needs( 'Forces', 6 ) ] ) ] );
		$this->assertStringContainsString( 'a level "mage-spheres" does not have', implode( ' | ', Catalog_Validator::validate_derived( $too_high, $this->spheres_file() ) ) );

		$this->assertStringContainsString( 'which the catalog does not have', implode( ' | ', Catalog_Validator::validate_derived( $good, null ) ) );
	}

	public function test_a_pool_priced_per_level_says_so_one_way(): void {
		$good = $this->block( 'resource_pool', [ 'pools' => [ [ 'name' => 'Balance', 'sliding_cost' => [ 'equals_level' => true ] ] ] ] );
		$this->assertSame( [], Catalog_Validator::validate_block( $good, 'test-block' ) );

		$this->assertRejects( $this->block( 'resource_pool', [ 'pools' => [ [ 'name' => 'Balance', 'sliding_cost' => [ 'per_level' => 1 ] ] ] ] ), 'not {"equals_level": true}' );
		$this->assertRejects( $this->block( 'resource_pool', [ 'pools' => [ [ 'name' => 'Balance', 'cost_per_dot' => 3, 'sliding_cost' => [ 'equals_level' => true ] ] ] ] ), 'one cost rule, not two' );
	}

	public function test_a_buy_down_pool_needs_cost_per_dot_and_carries_no_free_dots_or_sliding_cost(): void {
		$good = $this->block( 'resource_pool', [ 'pools' => [ [ 'name' => 'Torment', 'default_start' => 4, 'cost_per_dot' => 5, 'buy_down' => true ] ] ] );
		$this->assertSame( [], Catalog_Validator::validate_block( $good, 'test-block' ) );

		$this->assertRejects( $this->block( 'resource_pool', [ 'pools' => [ [ 'name' => 'Torment', 'buy_down' => true ] ] ] ), 'needs `cost_per_dot`' );
		$this->assertRejects( $this->block( 'resource_pool', [ 'pools' => [ [ 'name' => 'Torment', 'cost_per_dot' => 5, 'buy_down' => true, 'sliding_cost' => [ 'equals_level' => true ] ] ] ] ), 'prices one way, not two' );
		$this->assertRejects( $this->block( 'resource_pool', [ 'pools' => [ [ 'name' => 'Torment', 'cost_per_dot' => 5, 'buy_down' => true, 'free_dots' => 1 ] ] ] ), 'nothing free to raise past' );
	}

	// --- identity_field: options or options_ref -----------------------------

	public function test_a_select_field_needs_options_or_options_ref_not_both_not_neither(): void {
		$this->assertSame( [], Catalog_Validator::validate_block( $this->block( 'identity_field', [ 'fields' => [ [ 'name' => 'Clan', 'field_type' => 'select', 'options' => [ 'A' ] ] ] ] ), 'test-block' ) );
		$this->assertSame( [], Catalog_Validator::validate_block( $this->block( 'identity_field', [ 'fields' => [ [ 'name' => 'In-clan Disciplines', 'field_type' => 'multiselect', 'options_ref' => 'vampire-disciplines' ] ] ] ), 'test-block' ) );

		$this->assertRejects( $this->block( 'identity_field', [ 'fields' => [ [ 'name' => 'Clan', 'field_type' => 'select' ] ] ] ), 'exactly one of `options`' );
		$this->assertRejects( $this->block( 'identity_field', [ 'fields' => [ [ 'name' => 'Clan', 'field_type' => 'select', 'options' => [ 'A' ], 'options_ref' => 'vampire-disciplines' ] ] ] ), 'exactly one of `options`' );
	}

	public function test_options_ref_must_name_a_real_block(): void {
		$stack = [ 'kind' => 'block', 'section_type' => 'identity_field', 'definition' => [ 'fields' => [ [ 'name' => 'X', 'field_type' => 'multiselect', 'options_ref' => 'vampire-disciplines' ] ] ] ];
		$this->assertSame( [], Catalog_Validator::validate_identity_field_refs( $stack, [ 'vampire-disciplines' => [] ] ) );
		$this->assertStringContainsString( 'which the catalog does not have', implode( ' | ', Catalog_Validator::validate_identity_field_refs( $stack, [] ) ) );
	}

	// --- creation_rules -------------------------------------------------------

	/**
	 * @param array<int,mixed> $steps
	 */
	private function creation_errors( array $steps ): string {
		$def                    = $this->stack_definition();
		$def['creation_rules']  = [ 'steps' => $steps ];
		return implode( ' | ', Catalog_Validator::validate_file( $this->file( 'stack', 'werewolf', $def ), 'werewolf' ) );
	}

	public function test_every_step_kind_passes_in_its_own_shape(): void {
		$this->assertSame( '', $this->creation_errors( [
			[ 'kind' => 'prioritized', 'label' => 'Attributes', 'sections' => [ 'met-physical-traits' ], 'amounts' => [ 7 ] ],
			[ 'kind' => 'budget', 'label' => 'Gifts', 'section' => 'werewolf-gifts', 'count' => 3, 'filter' => [ 'in_type' => true, 'tier' => 'basic', 'test' => [ 'kind' => 'names', 'values' => [ 'constant' => [ 'X' ] ] ] ], 'quotas' => [ [ 'label' => 'One breed', 'min' => 1, 'test' => [ 'kind' => 'names', 'values' => [ 'constant' => [ 'Homid' ] ] ] ] ] ],
			[ 'kind' => 'free', 'label' => 'Free', 'pool' => 'Free Traits', 'points' => 5, 'rates' => [ 'met-physical-traits' => 1, 'werewolf-gifts' => 'value' ] ],
			[ 'kind' => 'earned', 'label' => 'Earned', 'pool' => 'Free Traits', 'sources' => [ [ 'section' => 'met-physical-traits', 'rate' => 1, 'max' => 5 ] ], 'max' => 5 ],
			[ 'kind' => 'limit', 'label' => 'Cap', 'section' => 'met-physical-traits', 'max_points' => 20, 'max_rating' => [ 'base' => 10, 'plus' => 'werewolf-identity.Rage', 'cap' => 20 ], 'min_rating' => 0, 'ceiling' => [ 'named_by' => 'werewolf-identity.Tribe' ] ],
			[ 'kind' => 'start', 'label' => 'Start', 'target' => 'werewolf-identity.Rage', 'lookup' => [ 'map' => 'werewolf-identity.rage_by_auspice', 'by' => [ 'Auspice' ] ] ],
			[ 'kind' => 'start', 'label' => 'Formula', 'target' => 'werewolf-identity.X', 'formula' => 'average_up', 'of' => [ 'werewolf-identity.Y', 'werewolf-identity.Z' ] ],
			[ 'kind' => 'grant', 'label' => 'Grant', 'section' => 'werewolf-gifts', 'entries' => [ [ 'name' => 'Homid', 'level' => 1 ] ] ],
			[ 'kind' => 'grant', 'label' => 'Grant from map', 'section' => 'werewolf-gifts', 'from' => [ 'map' => 'werewolf-identity.specialty', 'by' => [ 'Tribe' ] ], 'level' => 1 ],
		] ) );
	}

	public function test_an_unknown_step_kind_is_rejected(): void {
		$this->assertStringContainsString( '`kind` "mystery"', $this->creation_errors( [ [ 'kind' => 'mystery', 'label' => 'X' ] ] ) );
	}

	public function test_a_step_with_no_label_is_rejected(): void {
		$this->assertStringContainsString( 'has no `label`', $this->creation_errors( [ [ 'kind' => 'budget', 'section' => 'met-physical-traits', 'count' => 1 ] ] ) );
	}

	public function test_a_step_naming_a_section_not_on_the_stack_is_rejected(): void {
		$this->assertStringContainsString( 'not one of this creature type', $this->creation_errors( [ [ 'kind' => 'budget', 'label' => 'X', 'section' => 'no-such-section', 'count' => 1 ] ] ) );
	}

	public function test_prioritized_needs_matching_sections_and_amounts_largest_first(): void {
		$this->assertStringContainsString( '.sections must be a non-empty list', $this->creation_errors( [ [ 'kind' => 'prioritized', 'label' => 'X', 'sections' => [], 'amounts' => [] ] ] ) );
		$this->assertStringContainsString( 'as many non-negative whole numbers as sections', $this->creation_errors( [ [ 'kind' => 'prioritized', 'label' => 'X', 'sections' => [ 'met-physical-traits' ], 'amounts' => [ 1, 2 ] ] ] ) );
		$this->assertStringContainsString( '.amounts must be largest first', $this->creation_errors( [ [ 'kind' => 'prioritized', 'label' => 'X', 'sections' => [ 'met-physical-traits', 'werewolf-identity' ], 'amounts' => [ 3, 7 ] ] ] ) );
	}

	public function test_budget_needs_a_count_and_a_well_shaped_filter_and_quotas(): void {
		$this->assertStringContainsString( '.count must be a non-negative whole number', $this->creation_errors( [ [ 'kind' => 'budget', 'label' => 'X', 'section' => 'met-physical-traits' ] ] ) );
		$this->assertStringContainsString( '.filter.in_type must be true or false', $this->creation_errors( [ [ 'kind' => 'budget', 'label' => 'X', 'section' => 'met-physical-traits', 'count' => 1, 'filter' => [ 'in_type' => 'yes' ] ] ] ) );
		$this->assertStringContainsString( 'has `kind` "mystery"', $this->creation_errors( [ [ 'kind' => 'budget', 'label' => 'X', 'section' => 'met-physical-traits', 'count' => 1, 'filter' => [ 'test' => [ 'kind' => 'mystery' ] ] ] ] ), "a malformed filter.test is checked as an in-type test" );
		$this->assertStringContainsString( 'quotas[0] has no `label`', $this->creation_errors( [ [ 'kind' => 'budget', 'label' => 'X', 'section' => 'met-physical-traits', 'count' => 1, 'quotas' => [ [ 'min' => 1, 'test' => [ 'kind' => 'names', 'values' => [ 'constant' => [ 'A' ] ] ] ] ] ] ] ) );
		$this->assertStringContainsString( 'quotas[0].min must be a whole number from 1', $this->creation_errors( [ [ 'kind' => 'budget', 'label' => 'X', 'section' => 'met-physical-traits', 'count' => 1, 'quotas' => [ [ 'label' => 'Q', 'min' => 0, 'test' => [ 'kind' => 'names', 'values' => [ 'constant' => [ 'A' ] ] ] ] ] ] ] ) );
	}

	public function test_free_needs_a_pool_points_and_non_empty_rates_over_real_sections(): void {
		$this->assertStringContainsString( '.pool is required', $this->creation_errors( [ [ 'kind' => 'free', 'label' => 'X', 'points' => 1, 'rates' => [ 'met-physical-traits' => 1 ] ] ] ) );
		$this->assertStringContainsString( '.rates must be a non-empty object', $this->creation_errors( [ [ 'kind' => 'free', 'label' => 'X', 'pool' => 'P', 'points' => 1, 'rates' => [] ] ] ) );
		$this->assertStringContainsString( 'not one of this creature type', $this->creation_errors( [ [ 'kind' => 'free', 'label' => 'X', 'pool' => 'P', 'points' => 1, 'rates' => [ 'no-such-section' => 1 ] ] ] ) );
		$this->assertStringContainsString( 'must be a non-negative whole number or "value"', $this->creation_errors( [ [ 'kind' => 'free', 'label' => 'X', 'pool' => 'P', 'points' => 1, 'rates' => [ 'met-physical-traits' => 'a lot' ] ] ] ) );
	}

	public function test_earned_needs_sources_each_with_a_section_rate_and_max(): void {
		$this->assertStringContainsString( '.sources must be a non-empty list', $this->creation_errors( [ [ 'kind' => 'earned', 'label' => 'X', 'pool' => 'P', 'sources' => [] ] ] ) );
		$this->assertStringContainsString( '.rate must be a whole number or "value"', $this->creation_errors( [ [ 'kind' => 'earned', 'label' => 'X', 'pool' => 'P', 'sources' => [ [ 'section' => 'met-physical-traits', 'rate' => 'lots', 'max' => 1 ] ] ] ] ) );
		$this->assertStringContainsString( '.max must be a whole number from 1', $this->creation_errors( [ [ 'kind' => 'earned', 'label' => 'X', 'pool' => 'P', 'sources' => [ [ 'section' => 'met-physical-traits', 'rate' => 1 ] ] ] ] ) );
	}

	public function test_limit_needs_at_least_one_kind_of_limit(): void {
		$this->assertStringContainsString( 'needs at least one of', $this->creation_errors( [ [ 'kind' => 'limit', 'label' => 'X', 'section' => 'met-physical-traits' ] ] ) );
	}

	public function test_limit_ceiling_must_be_a_field_reference_or_named_by(): void {
		$this->assertSame( '', $this->creation_errors( [ [ 'kind' => 'limit', 'label' => 'X', 'section' => 'met-physical-traits', 'ceiling' => 'werewolf-identity.Rage' ] ] ) );
		$this->assertStringContainsString( '.ceiling must be "block.Name"', $this->creation_errors( [ [ 'kind' => 'limit', 'label' => 'X', 'section' => 'met-physical-traits', 'ceiling' => 'Rage' ] ] ) );
		$this->assertStringContainsString( '.ceiling.named_by must be "block.Field"', $this->creation_errors( [ [ 'kind' => 'limit', 'label' => 'X', 'section' => 'met-physical-traits', 'ceiling' => [ 'named_by' => 'Rage' ] ] ] ) );
	}

	public function test_start_needs_exactly_one_of_value_lookup_or_formula(): void {
		$this->assertStringContainsString( 'names none', $this->creation_errors( [ [ 'kind' => 'start', 'label' => 'X', 'target' => 'a.B' ] ] ) );
		$this->assertStringContainsString( 'names value and lookup', $this->creation_errors( [ [ 'kind' => 'start', 'label' => 'X', 'target' => 'a.B', 'value' => 1, 'lookup' => [ 'map' => 'a.b', 'by' => [ 'C' ] ] ] ] ) );
		$this->assertStringContainsString( '.formula must be one of', $this->creation_errors( [ [ 'kind' => 'start', 'label' => 'X', 'target' => 'a.B', 'formula' => 'mystery', 'of' => [ 'a.C' ] ] ] ) );
	}

	public function test_start_equal_needs_exactly_one_of_target(): void {
		$this->assertSame( '', $this->creation_errors( [ [ 'kind' => 'start', 'label' => 'X', 'target' => 'a.B', 'formula' => 'equal', 'of' => [ 'a.C' ] ] ] ) );
		$this->assertStringContainsString( 'exactly one target', $this->creation_errors( [ [ 'kind' => 'start', 'label' => 'X', 'target' => 'a.B', 'formula' => 'equal', 'of' => [ 'a.C', 'a.D' ] ] ] ) );
	}

	public function test_grant_needs_exactly_one_of_entries_or_from(): void {
		$this->assertStringContainsString( 'needs exactly one of `entries` or `from`', $this->creation_errors( [ [ 'kind' => 'grant', 'label' => 'X', 'section' => 'met-physical-traits' ] ] ) );
		$this->assertStringContainsString( 'entries[0] needs a `name`', $this->creation_errors( [ [ 'kind' => 'grant', 'label' => 'X', 'section' => 'met-physical-traits', 'entries' => [ [ 'level' => 1 ] ] ] ] ) );
		$this->assertStringContainsString( '.level must be a whole number', $this->creation_errors( [ [ 'kind' => 'grant', 'label' => 'X', 'section' => 'met-physical-traits', 'from' => [ 'map' => 'a.b', 'by' => [ 'C' ] ] ] ] ) );
	}

	public function test_step_when_supports_is_not_set_and_a_list_that_must_all_hold(): void {
		$base = static fn( array $when ): array => [ 'kind' => 'budget', 'label' => 'X', 'section' => 'met-physical-traits', 'count' => 1, 'when' => $when ];
		$this->assertSame( '', $this->creation_errors( [ $base( [ 'field' => 'werewolf-identity.Tribe', 'is' => [ 'A' ] ] ) ] ) );
		$this->assertSame( '', $this->creation_errors( [ $base( [ 'field' => 'werewolf-identity.Tribe', 'not' => [ 'A' ] ] ) ] ) );
		$this->assertSame( '', $this->creation_errors( [ $base( [ 'field' => 'werewolf-identity.Tribe', 'set' => true ] ) ] ) );
		$this->assertSame( '', $this->creation_errors( [ $base( [ [ 'field' => 'werewolf-identity.Tribe', 'is' => [ 'A' ] ], [ 'field' => 'werewolf-identity.Breed', 'set' => true ] ] ) ] ) );
		$this->assertStringContainsString( 'exactly one of `is`, `not` or `set`', $this->creation_errors( [ $base( [ 'field' => 'werewolf-identity.Tribe' ] ) ] ) );
		$this->assertStringContainsString( 'must be a non-empty list of tests', $this->creation_errors( [ $base( [] ) ] ) );
		$this->assertStringContainsString( '.when.is must be a non-empty list of values', $this->creation_errors( [ $base( [ 'field' => 'werewolf-identity.Tribe', 'is' => [] ] ) ] ) );
		$this->assertStringContainsString( '.when.not must be a non-empty list of values', $this->creation_errors( [ $base( [ 'field' => 'werewolf-identity.Tribe', 'not' => [ 1 ] ] ) ] ) );
	}

	public function test_creation_rules_needs_a_non_empty_steps_list(): void {
		$def                   = $this->stack_definition();
		$def['creation_rules'] = [ 'steps' => [] ];
		$this->assertStringContainsString( 'must be a non-empty list', implode( ' | ', Catalog_Validator::validate_file( $this->file( 'stack', 'werewolf', $def ), 'werewolf' ) ) );
	}

	public function test_creation_rules_refs_check_maps_and_names_are_kept(): void {
		$identity = $this->file( 'block', 'werewolf-identity', [ 'fields' => [ [ 'name' => 'Tribe' ] ], 'rage_by_auspice' => [ 'A' => 1 ] ] );
		$blocks   = [ 'werewolf-identity' => $identity ];
		$stack    = static function ( array $step ) {
			return [ 'kind' => 'stack', 'definition' => [ 'creation_rules' => [ 'steps' => [ $step ] ] ] ];
		};

		$this->assertSame( [], Catalog_Validator::validate_creation_rules_refs( $stack( [ 'kind' => 'start', 'target' => 'werewolf-identity.Rage', 'lookup' => [ 'map' => 'werewolf-identity.rage_by_auspice', 'by' => [ 'Tribe' ] ] ] ), $blocks ) );

		$missing = implode( ' | ', Catalog_Validator::validate_creation_rules_refs( $stack( [ 'kind' => 'start', 'target' => 'werewolf-identity.Rage', 'lookup' => [ 'map' => 'werewolf-identity.no_such_map', 'by' => [ 'Tribe' ] ] ] ), $blocks ) );
		$this->assertStringContainsString( 'which "werewolf-identity" does not keep', $missing );

		$budget = $stack( [ 'kind' => 'budget', 'section' => 'werewolf-gifts', 'count' => 1, 'filter' => [ 'test' => [ 'kind' => 'names', 'values' => [ 'field' => 'werewolf-identity.NoSuchField' ] ] ] ] );
		$this->assertStringContainsString( 'not a declared field', implode( ' | ', Catalog_Validator::validate_creation_rules_refs( $budget, $blocks ) ) );
	}
}
