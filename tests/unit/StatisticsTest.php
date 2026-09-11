<?php

namespace BeyondElysium\Tests\Unit;

use BeyondElysium\Services\Query_Engine;
use PHPUnit\Framework\TestCase;

/**
 * Port of `QueryEngineClass.GetStatistics`. One fixture set, all five statistics,
 * hand-calculated expected values.
 *
 * @see BE_PROCESS/workflow-0.6.md Step 5j
 * @see BE_PROCESS/GV-SOURCEMAP.md "Statistics"
 */
class StatisticsTest extends TestCase {

	/**
	 * Four vampires' Disciplines, hand-counted:
	 *   Marcus:    Celerity x3, Fortitude x2       (2 distinct disciplines, 5 total dots)
	 *   Lucretia:  Celerity x2, Obtenebration x4   (2 distinct, 6 total dots)
	 *   Sara:      Fortitude x1                    (1 distinct, 1 total dot)
	 *   Tomas:     (none)                          (0 distinct, 0 total dots)
	 */
	private function disciplines_fixture(): array {
		return [
			[ 'name' => 'Marcus', 'value' => [
				[ 'name' => 'Celerity', 'count' => 3 ],
				[ 'name' => 'Fortitude', 'count' => 2 ],
			] ],
			[ 'name' => 'Lucretia', 'value' => [
				[ 'name' => 'Celerity', 'count' => 2 ],
				[ 'name' => 'Obtenebration', 'count' => 4 ],
			] ],
			[ 'name' => 'Sara', 'value' => [
				[ 'name' => 'Fortitude', 'count' => 1 ],
			] ],
			[ 'name' => 'Tomas', 'value' => [] ],
		];
	}

	// -------------------------------------------------------------------------
	// distribution — list: bucket by list LENGTH
	// -------------------------------------------------------------------------

	public function test_distribution_on_a_list_buckets_by_length(): void {
		$result = Query_Engine::aggregate( $this->disciplines_fixture(), 'list', 'Disciplines', 'distribution', true, null, 4 );

		// Marcus=2, Lucretia=2, Sara=1, Tomas=0 -> buckets: "2 Disciplines"=2, "1 Disciplines"=1, "0 Disciplines"=1
		$this->assertSame( 2.0, $result['buckets']['2 Disciplines'] );
		$this->assertSame( 1.0, $result['buckets']['1 Disciplines'] );
		$this->assertSame( 1.0, $result['buckets']['0 Disciplines'] );
		$this->assertSame( 4.0, $result['total'] );
		$this->assertSame( 2.0, $result['maximum'] );
		$this->assertEqualsCanonicalizing( [ 'Marcus', 'Lucretia' ], $result['match_sets']['2 Disciplines'] );
	}

	public function test_distribution_ok_zero_false_excludes_the_zero_bucket(): void {
		$result = Query_Engine::aggregate( $this->disciplines_fixture(), 'list', 'Disciplines', 'distribution', false, null, 4 );

		$this->assertArrayNotHasKey( '0 Disciplines', $result['buckets'] );
		// Total only counts characters actually bucketed - Tomas (0) is excluded.
		$this->assertSame( 3.0, $result['total'] );
	}

	public function test_distribution_on_a_field_key_does_not_append_the_title(): void {
		$resolved = [
			[ 'name' => 'Marcus', 'value' => 'vampire' ],
			[ 'name' => 'Sara', 'value' => 'werewolf' ],
			[ 'name' => 'Lucretia', 'value' => 'vampire' ],
		];
		$result = Query_Engine::aggregate( $resolved, 'field', 'Race', 'distribution', true, null, 3 );

		$this->assertSame( 2.0, $result['buckets']['vampire'] );
		$this->assertSame( 1.0, $result['buckets']['werewolf'] );
		$this->assertArrayNotHasKey( 'vampire Race', $result['buckets'] );
	}

	// -------------------------------------------------------------------------
	// distinct_distribution — bucket by trait NAME, each occurrence counts 1
	// -------------------------------------------------------------------------

	public function test_distinct_distribution_buckets_by_name_each_occurrence_counts_one(): void {
		$result = Query_Engine::aggregate( $this->disciplines_fixture(), 'list', 'Disciplines', 'distinct_distribution', true, null, 4 );

		$this->assertSame( 2.0, $result['buckets']['Celerity'], 'Marcus and Lucretia both hold Celerity' );
		$this->assertSame( 2.0, $result['buckets']['Fortitude'], 'Marcus and Sara both hold Fortitude' );
		$this->assertSame( 1.0, $result['buckets']['Obtenebration'] );
		// Total = characters examined, NOT the sum of buckets (which would be 5).
		$this->assertSame( 4.0, $result['total'] );
		$this->assertEqualsCanonicalizing( [ 'Marcus', 'Sara' ], $result['match_sets']['Fortitude'] );
	}

	// -------------------------------------------------------------------------
	// specific_distribution — bucket by the level of one named trait; absent = 0
	// -------------------------------------------------------------------------

	public function test_specific_distribution_buckets_by_level_of_one_trait_absent_is_zero(): void {
		$result = Query_Engine::aggregate( $this->disciplines_fixture(), 'list', 'Disciplines', 'specific_distribution', true, 'Celerity', 4 );

		// Marcus=3, Lucretia=2, Sara=0 (absent), Tomas=0 (absent) -> relabeled "Celerity x<N>".
		$this->assertSame( 1.0, $result['buckets']['Celerity x3'] );
		$this->assertSame( 1.0, $result['buckets']['Celerity x2'] );
		$this->assertSame( 2.0, $result['buckets']['Celerity x0'] );
		$this->assertEqualsCanonicalizing( [ 'Sara', 'Tomas' ], $result['match_sets']['Celerity x0'] );
	}

	public function test_specific_distribution_ok_zero_false_excludes_absent_characters(): void {
		$result = Query_Engine::aggregate( $this->disciplines_fixture(), 'list', 'Disciplines', 'specific_distribution', false, 'Celerity', 4 );

		$this->assertArrayNotHasKey( 'Celerity x0', $result['buckets'] );
	}

	// -------------------------------------------------------------------------
	// maxima — per bucket keep the highest value; total recomputed AFTER the loop
	// -------------------------------------------------------------------------

	public function test_maxima_on_a_list_keeps_the_highest_value_per_trait_name(): void {
		$resolved = [
			[ 'name' => 'Marcus', 'value' => [ [ 'name' => 'Celerity', 'count' => 3 ] ] ],
			[ 'name' => 'Lucretia', 'value' => [ [ 'name' => 'Celerity', 'count' => 5 ] ] ],
			[ 'name' => 'Sara', 'value' => [ [ 'name' => 'Celerity', 'count' => 1 ] ] ],
		];
		$result = Query_Engine::aggregate( $resolved, 'list', 'Disciplines', 'maxima', true, null, 3 );

		$this->assertSame( 5.0, $result['buckets']['Celerity'] );
		// Total is the SUM of the (one) maxima bucket, recomputed after the loop -
		// not 3+5+1=9 accumulated during it.
		$this->assertSame( 5.0, $result['total'] );
		$this->assertSame( 5.0, $result['maximum'] );
	}

	public function test_maxima_total_is_the_sum_of_maxima_across_multiple_buckets(): void {
		$resolved = [
			[ 'name' => 'Marcus', 'value' => [ [ 'name' => 'Celerity', 'count' => 3 ], [ 'name' => 'Fortitude', 'count' => 2 ] ] ],
			[ 'name' => 'Lucretia', 'value' => [ [ 'name' => 'Celerity', 'count' => 5 ], [ 'name' => 'Fortitude', 'count' => 1 ] ] ],
		];
		$result = Query_Engine::aggregate( $resolved, 'list', 'Disciplines', 'maxima', true, null, 2 );

		// Celerity max = 5, Fortitude max = 2 -> total = 7, recomputed after the loop
		// (never 3+2+5+1=11, the inline accumulation).
		$this->assertSame( 5.0, $result['buckets']['Celerity'] );
		$this->assertSame( 2.0, $result['buckets']['Fortitude'] );
		$this->assertSame( 7.0, $result['total'] );
	}

	public function test_maxima_on_a_scalar_field_has_exactly_one_bucket(): void {
		$resolved = [
			[ 'name' => 'Marcus', 'value' => 12 ],
			[ 'name' => 'Lucretia', 'value' => 40 ],
			[ 'name' => 'Sara', 'value' => 8 ],
		];
		$result = Query_Engine::aggregate( $resolved, 'num', 'XP Earned', 'maxima', true, null, 3 );

		$this->assertCount( 1, $result['buckets'] );
		$this->assertSame( 40.0, $result['buckets']['XP Earned'] );
		$this->assertSame( 40.0, $result['total'] );
	}

	// -------------------------------------------------------------------------
	// sums — per bucket accumulate
	// -------------------------------------------------------------------------

	public function test_sums_on_a_list_accumulates_per_trait_name(): void {
		$resolved = [
			[ 'name' => 'Marcus', 'value' => [ [ 'name' => 'Celerity', 'count' => 3 ] ] ],
			[ 'name' => 'Lucretia', 'value' => [ [ 'name' => 'Celerity', 'count' => 5 ] ] ],
		];
		$result = Query_Engine::aggregate( $resolved, 'list', 'Disciplines', 'sums', true, null, 2 );

		$this->assertSame( 8.0, $result['buckets']['Celerity'] );
		$this->assertSame( 8.0, $result['total'] );
	}

	public function test_sums_on_a_scalar_field_accumulates_into_one_bucket(): void {
		$resolved = [
			[ 'name' => 'Marcus', 'value' => 12 ],
			[ 'name' => 'Lucretia', 'value' => 40 ],
			[ 'name' => 'Sara', 'value' => 8 ],
		];
		$result = Query_Engine::aggregate( $resolved, 'num', 'XP Earned', 'sums', true, null, 3 );

		$this->assertSame( 60.0, $result['buckets']['XP Earned'] );
		$this->assertSame( 60.0, $result['total'] );
	}

	// -------------------------------------------------------------------------
	// Null values ("N/A keys don't count")
	// -------------------------------------------------------------------------

	public function test_null_values_are_skipped_entirely(): void {
		$resolved = [
			[ 'name' => 'Marcus', 'value' => 12 ],
			[ 'name' => 'NoData', 'value' => null ],
		];
		$result = Query_Engine::aggregate( $resolved, 'num', 'XP Earned', 'sums', true, null, 2 );

		$this->assertSame( 12.0, $result['total'] );
		$this->assertArrayNotHasKey( 'NoData', $result['match_sets']['XP Earned'] ?? [] );
	}
}
