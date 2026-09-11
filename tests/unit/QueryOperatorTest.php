<?php

namespace BeyondElysium\Tests\Unit;

use BeyondElysium\Services\Query_Engine;
use PHPUnit\Framework\TestCase;

/**
 * Port of `QueryEngineClass.ProcessClause` (GV301Source/Code/QueryEngineClass.cls) - a
 * spec test, not a fixture test. One case per operator per applicable type, every
 * inapplicable pairing, every operator negated, a missing block, an empty list, the
 * `contains_less` presence rule, and an atomic list where only the second duplicate
 * satisfies the comparison.
 *
 * @see BE_PROCESS/workflow-0.6.md Step 2l
 * @see BE_PROCESS/GV-SOURCEMAP.md "Query Engine"
 */
class QueryOperatorTest extends TestCase {

	// -------------------------------------------------------------------------
	// field
	// -------------------------------------------------------------------------

	public function test_field_contains(): void {
		$this->assertTrue( Query_Engine::evaluate_clause( 'field', 'Marcus Vitel', [ 'operator' => 'contains', 'find' => 'vitel' ] )['match'] );
		$this->assertFalse( Query_Engine::evaluate_clause( 'field', 'Marcus Vitel', [ 'operator' => 'contains', 'find' => 'xyz' ] )['match'] );
	}

	public function test_field_equals_is_case_insensitive(): void {
		$this->assertTrue( Query_Engine::evaluate_clause( 'field', 'Vampire', [ 'operator' => 'equals', 'find' => 'vampire' ] )['match'] );
		$this->assertFalse( Query_Engine::evaluate_clause( 'field', 'Vampire', [ 'operator' => 'equals', 'find' => 'werewolf' ] )['match'] );
	}

	public function test_field_inapplicable_operator_is_a_non_match(): void {
		$this->assertFalse( Query_Engine::evaluate_clause( 'field', 'Vampire', [ 'operator' => 'at_least', 'value' => 3 ] )['match'] );
	}

	public function test_field_negated_inapplicable_operator_is_still_a_non_match(): void {
		// GV-SOURCEMAP.md: "the And is applied outside the Xor" - a negated
		// inapplicable clause does not become a match.
		$this->assertFalse( Query_Engine::evaluate_clause( 'field', 'Vampire', [ 'operator' => 'at_least', 'value' => 3, 'not' => true ] )['match'] );
	}

	public function test_field_negated_applicable_operator_flips_correctly(): void {
		$this->assertFalse( Query_Engine::evaluate_clause( 'field', 'Vampire', [ 'operator' => 'equals', 'find' => 'Vampire', 'not' => true ] )['match'] );
		$this->assertTrue( Query_Engine::evaluate_clause( 'field', 'Vampire', [ 'operator' => 'equals', 'find' => 'Werewolf', 'not' => true ] )['match'] );
	}

	// -------------------------------------------------------------------------
	// num
	// -------------------------------------------------------------------------

	public function test_num_equals(): void {
		$this->assertTrue( Query_Engine::evaluate_clause( 'num', 5, [ 'operator' => 'equals', 'value' => 5 ] )['match'] );
		$this->assertFalse( Query_Engine::evaluate_clause( 'num', 5, [ 'operator' => 'equals', 'value' => 6 ] )['match'] );
	}

	public function test_num_at_least(): void {
		$this->assertTrue( Query_Engine::evaluate_clause( 'num', 5, [ 'operator' => 'at_least', 'value' => 5 ] )['match'] );
		$this->assertFalse( Query_Engine::evaluate_clause( 'num', 4, [ 'operator' => 'at_least', 'value' => 5 ] )['match'] );
	}

	public function test_num_greater(): void {
		$this->assertTrue( Query_Engine::evaluate_clause( 'num', 6, [ 'operator' => 'greater', 'value' => 5 ] )['match'] );
		$this->assertFalse( Query_Engine::evaluate_clause( 'num', 5, [ 'operator' => 'greater', 'value' => 5 ] )['match'] );
	}

	public function test_num_less(): void {
		$this->assertTrue( Query_Engine::evaluate_clause( 'num', 4, [ 'operator' => 'less', 'value' => 5 ] )['match'] );
		$this->assertFalse( Query_Engine::evaluate_clause( 'num', 5, [ 'operator' => 'less', 'value' => 5 ] )['match'] );
	}

	public function test_num_no_more(): void {
		$this->assertTrue( Query_Engine::evaluate_clause( 'num', 5, [ 'operator' => 'no_more', 'value' => 5 ] )['match'] );
		$this->assertFalse( Query_Engine::evaluate_clause( 'num', 6, [ 'operator' => 'no_more', 'value' => 5 ] )['match'] );
	}

	public function test_num_inapplicable_operator(): void {
		$this->assertFalse( Query_Engine::evaluate_clause( 'num', 5, [ 'operator' => 'contains', 'find' => '5' ] )['match'] );
	}

	// -------------------------------------------------------------------------
	// date
	// -------------------------------------------------------------------------

	public function test_date_equals(): void {
		$this->assertTrue( Query_Engine::evaluate_clause( 'date', '2026-01-01', [ 'operator' => 'equals', 'find' => '2026-01-01' ] )['match'] );
		$this->assertFalse( Query_Engine::evaluate_clause( 'date', '2026-01-01', [ 'operator' => 'equals', 'find' => '2026-01-02' ] )['match'] );
	}

	public function test_date_at_least(): void {
		$this->assertTrue( Query_Engine::evaluate_clause( 'date', '2026-01-02', [ 'operator' => 'at_least', 'find' => '2026-01-01' ] )['match'] );
	}

	public function test_date_greater(): void {
		$this->assertTrue( Query_Engine::evaluate_clause( 'date', '2026-01-02', [ 'operator' => 'greater', 'find' => '2026-01-01' ] )['match'] );
		$this->assertFalse( Query_Engine::evaluate_clause( 'date', '2026-01-01', [ 'operator' => 'greater', 'find' => '2026-01-01' ] )['match'] );
	}

	public function test_date_less(): void {
		$this->assertTrue( Query_Engine::evaluate_clause( 'date', '2026-01-01', [ 'operator' => 'less', 'find' => '2026-01-02' ] )['match'] );
	}

	public function test_date_no_more(): void {
		$this->assertTrue( Query_Engine::evaluate_clause( 'date', '2026-01-01', [ 'operator' => 'no_more', 'find' => '2026-01-01' ] )['match'] );
	}

	public function test_date_unparsable_comparison_value_is_inapplicable(): void {
		$this->assertFalse( Query_Engine::evaluate_clause( 'date', '2026-01-01', [ 'operator' => 'equals', 'find' => 'not-a-date' ] )['match'] );
	}

	// -------------------------------------------------------------------------
	// bool
	// -------------------------------------------------------------------------

	public function test_bool_is_true(): void {
		$this->assertTrue( Query_Engine::evaluate_clause( 'bool', true, [ 'operator' => 'is_true' ] )['match'] );
		$this->assertFalse( Query_Engine::evaluate_clause( 'bool', false, [ 'operator' => 'is_true' ] )['match'] );
	}

	public function test_bool_is_false(): void {
		$this->assertTrue( Query_Engine::evaluate_clause( 'bool', false, [ 'operator' => 'is_false' ] )['match'] );
		$this->assertFalse( Query_Engine::evaluate_clause( 'bool', true, [ 'operator' => 'is_false' ] )['match'] );
	}

	public function test_bool_inapplicable_operator(): void {
		$this->assertFalse( Query_Engine::evaluate_clause( 'bool', true, [ 'operator' => 'equals', 'find' => 'true' ] )['match'] );
	}

	// -------------------------------------------------------------------------
	// list
	// -------------------------------------------------------------------------

	private function traits( array $names_and_counts ): array {
		return array_map( static fn( $pair ) => [ 'name' => $pair[0], 'count' => $pair[1] ], $names_and_counts );
	}

	public function test_list_contains(): void {
		$list = $this->traits( [ [ 'Celerity', 3 ] ] );
		$this->assertTrue( Query_Engine::evaluate_clause( 'list', $list, [ 'operator' => 'contains', 'find' => 'Celerity' ] )['match'] );
		$this->assertFalse( Query_Engine::evaluate_clause( 'list', $list, [ 'operator' => 'contains', 'find' => 'Fortitude' ] )['match'] );
	}

	public function test_list_contains_note(): void {
		$list = [ [ 'name' => 'Herd', 'count' => 2, 'note' => 'The docks crew' ] ];
		$this->assertTrue( Query_Engine::evaluate_clause( 'list', $list, [ 'operator' => 'contains_note', 'find' => 'docks' ] )['match'] );
		$this->assertFalse( Query_Engine::evaluate_clause( 'list', $list, [ 'operator' => 'contains_note', 'find' => 'nothing' ] )['match'] );
	}

	public function test_list_contains_exactly(): void {
		$list = $this->traits( [ [ 'Celerity', 3 ] ] );
		$this->assertTrue( Query_Engine::evaluate_clause( 'list', $list, [ 'operator' => 'contains_exactly', 'find' => 'Celerity', 'value' => 3 ] )['match'] );
		$this->assertFalse( Query_Engine::evaluate_clause( 'list', $list, [ 'operator' => 'contains_exactly', 'find' => 'Celerity', 'value' => 2 ] )['match'] );
	}

	public function test_list_contains_at_least(): void {
		$list = $this->traits( [ [ 'Celerity', 3 ] ] );
		$this->assertTrue( Query_Engine::evaluate_clause( 'list', $list, [ 'operator' => 'contains_at_least', 'find' => 'Celerity', 'value' => 3 ] )['match'] );
		$this->assertFalse( Query_Engine::evaluate_clause( 'list', $list, [ 'operator' => 'contains_at_least', 'find' => 'Celerity', 'value' => 4 ] )['match'] );
	}

	public function test_list_contains_more(): void {
		$list = $this->traits( [ [ 'Celerity', 3 ] ] );
		$this->assertTrue( Query_Engine::evaluate_clause( 'list', $list, [ 'operator' => 'contains_more', 'find' => 'Celerity', 'value' => 2 ] )['match'] );
		$this->assertFalse( Query_Engine::evaluate_clause( 'list', $list, [ 'operator' => 'contains_more', 'find' => 'Celerity', 'value' => 3 ] )['match'] );
	}

	public function test_list_totals(): void {
		$list = $this->traits( [ [ 'Celerity', 3 ], [ 'Fortitude', 2 ] ] );
		$this->assertTrue( Query_Engine::evaluate_clause( 'list', $list, [ 'operator' => 'totals', 'value' => 2 ] )['match'] );
		$this->assertFalse( Query_Engine::evaluate_clause( 'list', $list, [ 'operator' => 'totals', 'value' => 3 ] )['match'] );
	}

	public function test_list_totals_at_least(): void {
		$list = $this->traits( [ [ 'Celerity', 3 ], [ 'Fortitude', 2 ] ] );
		$this->assertTrue( Query_Engine::evaluate_clause( 'list', $list, [ 'operator' => 'totals_at_least', 'value' => 2 ] )['match'] );
		$this->assertFalse( Query_Engine::evaluate_clause( 'list', $list, [ 'operator' => 'totals_at_least', 'value' => 3 ] )['match'] );
	}

	public function test_list_totals_more(): void {
		$one = $this->traits( [ [ 'Celerity', 3 ] ] );
		$this->assertFalse( Query_Engine::evaluate_clause( 'list', $one, [ 'operator' => 'totals_more', 'value' => 1 ] )['match'] );

		$two = $this->traits( [ [ 'A', 1 ], [ 'B', 1 ] ] );
		$this->assertTrue( Query_Engine::evaluate_clause( 'list', $two, [ 'operator' => 'totals_more', 'value' => 1 ] )['match'] );
	}

	public function test_list_totals_no_more(): void {
		$list = $this->traits( [ [ 'Celerity', 3 ] ] );
		$this->assertTrue( Query_Engine::evaluate_clause( 'list', $list, [ 'operator' => 'totals_no_more', 'value' => 1 ] )['match'] );
	}

	public function test_list_totals_less(): void {
		$list = $this->traits( [ [ 'Celerity', 3 ] ] );
		$this->assertTrue( Query_Engine::evaluate_clause( 'list', $list, [ 'operator' => 'totals_less', 'value' => 2 ] )['match'] );
		$this->assertFalse( Query_Engine::evaluate_clause( 'list', $list, [ 'operator' => 'totals_less', 'value' => 1 ] )['match'] );
	}

	/**
	 * Totals operate on list LENGTH, not the sum of counts (Step 2g) - two entries
	 * totaling 100 combined dots must not satisfy `totals_at_least 3`.
	 */
	public function test_totals_is_length_not_sum_of_counts(): void {
		$list = $this->traits( [ [ 'Celerity', 50 ], [ 'Fortitude', 50 ] ] );
		$this->assertFalse( Query_Engine::evaluate_clause( 'list', $list, [ 'operator' => 'totals_at_least', 'value' => 3 ] )['match'] );
	}

	/**
	 * `contains_less` and `contains_no_more` require the trait to be present (Step 2h) -
	 * the single most likely thing to get wrong. A missing trait is not "≤ N".
	 */
	public function test_contains_less_requires_presence(): void {
		$empty = [];
		$this->assertFalse( Query_Engine::evaluate_clause( 'list', $empty, [ 'operator' => 'contains_less', 'find' => 'Celerity', 'value' => 3 ] )['match'] );
	}

	public function test_contains_no_more_requires_presence(): void {
		$empty = [];
		$this->assertFalse( Query_Engine::evaluate_clause( 'list', $empty, [ 'operator' => 'contains_no_more', 'find' => 'Celerity', 'value' => 2 ] )['match'] );
	}

	/**
	 * A negated `contains_no_more`/`contains_less` against an ABSENT trait DOES match -
	 * `Match = Applicable(true) And (false Xor CompNot(true)) = true`. A faithful port
	 * preserves this exactly as GV computes it, not the "intuitive" reading.
	 */
	public function test_negated_contains_no_more_matches_when_trait_absent(): void {
		$empty = [];
		$this->assertTrue( Query_Engine::evaluate_clause( 'list', $empty, [ 'operator' => 'contains_no_more', 'find' => 'Celerity', 'value' => 2, 'not' => true ] )['match'] );
	}

	public function test_empty_list_totals_zero(): void {
		$this->assertTrue( Query_Engine::evaluate_clause( 'list', [], [ 'operator' => 'totals', 'value' => 0 ] )['match'] );
	}

	public function test_missing_block_is_null_and_inapplicable(): void {
		// A character without this block at all - value resolves to null upstream of
		// evaluate_clause(), which must treat it exactly like GV's IsNull(CharData).
		$result = Query_Engine::evaluate_clause( 'list', null, [ 'operator' => 'contains', 'find' => 'Celerity' ] );
		$this->assertFalse( $result['match'] );
		$this->assertSame( 'N/A', $result['match_value'] );
	}

	/**
	 * Atomic lists must be scanned for every duplicate (Step 2k) - GV's loop:
	 * `Loop Until Match Or TraitList.Atomic = False Or TraitList.Off`. A non-atomic
	 * list stops at the FIRST matching-named entry regardless of outcome; only an
	 * atomic list keeps walking to a later duplicate.
	 */
	public function test_non_atomic_list_stops_at_first_matching_named_entry(): void {
		// First "Celerity" is level 1 (fails >= 3); a second "Celerity" at level 5
		// exists but must never be reached on a non-atomic list.
		$list = $this->traits( [ [ 'Celerity', 1 ], [ 'Celerity', 5 ] ] );
		$this->assertFalse( Query_Engine::evaluate_clause( 'list', $list, [ 'operator' => 'contains_at_least', 'find' => 'Celerity', 'value' => 3 ], false )['match'] );
	}

	public function test_atomic_list_walks_to_a_later_duplicate_that_satisfies_the_comparison(): void {
		$list = $this->traits( [ [ 'Celerity', 1 ], [ 'Celerity', 5 ] ] );
		$result = Query_Engine::evaluate_clause( 'list', $list, [ 'operator' => 'contains_at_least', 'find' => 'Celerity', 'value' => 3 ], true );
		$this->assertTrue( $result['match'] );
		$this->assertSame( 'Celerity x5', $result['match_value'] );
	}

	public function test_atomic_list_with_no_duplicate_satisfying_comparison_is_a_non_match(): void {
		$list = $this->traits( [ [ 'Celerity', 1 ], [ 'Celerity', 2 ] ] );
		$this->assertFalse( Query_Engine::evaluate_clause( 'list', $list, [ 'operator' => 'contains_at_least', 'find' => 'Celerity', 'value' => 3 ], true )['match'] );
	}

	public function test_list_inapplicable_operator(): void {
		$list = $this->traits( [ [ 'Celerity', 3 ] ] );
		$this->assertFalse( Query_Engine::evaluate_clause( 'list', $list, [ 'operator' => 'is_true' ] )['match'] );
	}

	// -------------------------------------------------------------------------
	// Applicability table (Step 8a: the UI must never offer what the server rejects)
	// -------------------------------------------------------------------------

	public function test_is_applicable_matches_the_full_table(): void {
		$this->assertTrue( Query_Engine::is_applicable( 'field', 'contains' ) );
		$this->assertTrue( Query_Engine::is_applicable( 'field', 'equals' ) );
		$this->assertFalse( Query_Engine::is_applicable( 'field', 'at_least' ) );

		foreach ( [ 'equals', 'at_least', 'greater', 'less', 'no_more' ] as $op ) {
			$this->assertTrue( Query_Engine::is_applicable( 'num', $op ) );
			$this->assertTrue( Query_Engine::is_applicable( 'date', $op ) );
		}
		$this->assertFalse( Query_Engine::is_applicable( 'num', 'contains' ) );

		$this->assertTrue( Query_Engine::is_applicable( 'bool', 'is_true' ) );
		$this->assertTrue( Query_Engine::is_applicable( 'bool', 'is_false' ) );
		$this->assertFalse( Query_Engine::is_applicable( 'bool', 'contains' ) );

		foreach ( [ 'contains', 'contains_note', 'contains_exactly', 'contains_at_least', 'contains_more', 'contains_less', 'contains_no_more', 'totals', 'totals_at_least', 'totals_more', 'totals_no_more', 'totals_less' ] as $op ) {
			$this->assertTrue( Query_Engine::is_applicable( 'list', $op ), $op );
		}
		$this->assertFalse( Query_Engine::is_applicable( 'list', 'is_true' ) );
	}
}
