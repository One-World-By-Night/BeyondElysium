<?php

namespace BeyondElysium\Tests\Unit;

use BeyondElysium\Services\Catalog_Validator;
use PHPUnit\Framework\TestCase;

/**
 * `reference/CATALOG-JSON-FORMAT.md` §7 - a declared catalog file is rejected, not silently
 * degraded.
 *
 * Every test here feeds a **deliberately broken** file and asserts the specific complaint,
 * because a validator that passes everything is indistinguishable from no validator at all.
 * The valid-file cases at the end are what stop it drifting the other way and rejecting
 * good data.
 */
class CatalogValidatorTest extends TestCase {

	/** @param array<string,mixed> $definition */
	private function block( string $section_type, array $definition, string $slug = 'test-block' ): array {
		return [ 'slug' => $slug, 'section_type' => $section_type, 'definition' => $definition ];
	}

	/** A family filling a 2/2/1 ladder correctly. */
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
		// Narrowing "1 or 3" to an integer is how a range silently becomes its own floor.
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
		// 596 levels carry it today. It records an unclassified note, not a rank.
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
		// A rank is rungs or picks, never both - flattening the two is what caused D68.
		$bad            = $this->ladder_family();
		$bad['elder']   = [ 'basic' => [ [ 'tier' => 'basic', 'power_name' => 'Wrong Home' ] ] ];
		$this->assertRejects( $this->tiered( [ $bad ] ), 'never both' );
	}

	public function test_a_pick_without_a_power_name_is_rejected(): void {
		$bad          = $this->ladder_family();
		$bad['elder'] = [ 'elder' => [ [ 'tier' => 'elder' ] ] ];
		$this->assertRejects( $this->tiered( [ $bad ] ), 'has no `power_name`' );
	}

	// --- Rule 5: no orphans ---------------------------------------------------

	public function test_a_non_empty_overflow_is_rejected_as_an_uncommitted_D67_family(): void {
		// The rule that earns the format: under the flat shape these were invisible and
		// simply mis-priced. Here the family cannot be committed until it has its ruling.
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

	public function test_a_family_with_no_picks_at_all_is_fine(): void {
		// mage-spheres and changeling-realms have none; an absent `elder` is not an error.
		$this->assertSame( [], Catalog_Validator::validate_block( $this->tiered( [ $this->ladder_family() ] ), 'test-block' ) );
	}

	// --- S7: an untiered track has no ranks, by design ------------------------

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
		// Changeling Realms: 2 flat per level, no tier vocabulary anywhere. Before S7 this
		// reached the right answer through a no-tier fallback nobody could explain.
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
}
