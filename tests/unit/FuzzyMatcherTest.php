<?php

namespace BeyondElysium\Tests\Unit;

use BeyondElysium\Services\Fuzzy_Matcher;
use PHPUnit\Framework\TestCase;

/**
 * `Fuzzy_Matcher` (workflow-0.8.md Step 5) - normalization, edit-distance thresholds,
 * and ranked suggestions, independent of any real import.
 *
 * @see BE_PROCESS/workflow-0.8.md Step 5
 */
class FuzzyMatcherTest extends TestCase {

	public function test_normalize_collapses_whitespace_and_punctuation(): void {
		$this->assertSame( 'body wise', Fuzzy_Matcher::normalize( 'Body-Wise' ) );
		$this->assertSame( 'melee', Fuzzy_Matcher::normalize( 'Melee ' ) );
		$this->assertSame( 'melee', Fuzzy_Matcher::normalize( 'MELEE' ) );
	}

	public function test_threshold_is_two_under_ten_characters(): void {
		// "Brawl" (5) vs "Crawl": distance 1, well within the threshold-2 band.
		$this->assertTrue( Fuzzy_Matcher::within_threshold( 'brawl', 'crawl' ) );
		// distance 3 on a 5-character string exceeds the under-10 threshold of 2.
		$this->assertFalse( Fuzzy_Matcher::within_threshold( 'brawl', 'crown' ) );
	}

	public function test_threshold_is_three_at_or_above_ten_characters(): void {
		$a = 'academicscholar'; // 15 chars
		$b = 'academicscholaz'; // distance 1
		$this->assertTrue( Fuzzy_Matcher::within_threshold( $a, $b ) );
	}

	public function test_suggest_returns_up_to_three_ranked_candidates(): void {
		$suggestions = Fuzzy_Matcher::suggest( 'Crawl', [ 'Brawl', 'Crawling', 'Occult', 'Melee' ] );

		$this->assertLessThanOrEqual( 3, count( $suggestions ) );
		$this->assertContains( 'Brawl', $suggestions );
		$this->assertNotContains( 'Occult', $suggestions, 'Occult is nowhere near the edit-distance threshold' );
	}

	public function test_suggest_returns_empty_for_no_real_candidates(): void {
		$this->assertSame( [], Fuzzy_Matcher::suggest( 'Anything', [] ) );
	}

	public function test_suggest_never_returns_more_than_three(): void {
		$candidates  = [ 'Brawl', 'Brawn', 'Brawk', 'Brawx', 'Braws' ];
		$suggestions = Fuzzy_Matcher::suggest( 'Brawy', $candidates );

		$this->assertLessThanOrEqual( 3, count( $suggestions ) );
	}
}
