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
		$this->assertRejects( $this->tiered( [ $bad ] ), 'This is D67' );
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

	public function test_a_derived_untiered_track_passes(): void {
		// Mage Rotes: 1 XP per Sphere level invoked.
		$data = $this->untiered( [ 'derived_from' => 'mage-spheres', 'per_level' => 1 ] );
		$this->assertSame( [], Catalog_Validator::validate_block( $data, 'test-block' ) );
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
				[ 'block_slug' => 'werewolf-gifts', 'label' => 'Gifts', 'display_order' => 60, 'required' => true, 'in_type_source' => 'werewolf-tribes.Tribe' ],
				[ 'block_slug' => 'met-physical-traits', 'label' => 'Physical', 'display_order' => 50, 'required' => true, 'negative_block_slug' => 'met-physical-traits-neg' ],
			],
			'creation_rules' => [ 'steps' => [ [ 'step' => 3, 'sections' => [ 'met-abilities' ] ] ] ],
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

	public function test_a_malformed_in_type_join_is_rejected(): void {
		$def = $this->stack_definition();
		$def['sections'][1]['in_type_source'] = 'Tribe';
		$this->assertStringContainsString( '"block_slug.Field" join', implode( ' | ', Catalog_Validator::validate_file( $this->file( 'stack', 'werewolf', $def ), 'werewolf' ) ) );
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
		$every = [ 'werewolf-identity', 'werewolf-gifts', 'werewolf-tribes', 'met-physical-traits', 'met-physical-traits-neg', 'met-abilities' ];
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
}
