<?php

namespace BeyondElysium\Tests\Unit;

use BeyondElysium\Models\Change;
use PHPUnit\Framework\TestCase;

/**
 * Change::review_token() identifies exactly what a reviewer was shown.
 */
class ChangeReviewTokenTest extends TestCase {

	private function change( array $overrides = [] ): object {
		return (object) array_merge( [
			'change_type'  => 'add_trait',
			'change_data'  => [ 'block_slug' => 'met-abilities', 'trait' => [ 'name' => 'Occult', 'count' => 3 ] ],
			'xp_cost'      => '6.00',
			'submitted_at' => '2026-09-14 20:00:00',
		], $overrides );
	}

	public function test_the_same_content_always_gives_the_same_token(): void {
		$this->assertSame( Change::review_token( $this->change() ), Change::review_token( $this->change() ) );
	}

	public function test_a_different_purchase_gives_a_different_token(): void {
		$bigger = $this->change( [ 'change_data' => [ 'block_slug' => 'met-abilities', 'trait' => [ 'name' => 'Occult', 'count' => 5 ] ] ] );

		$this->assertNotSame( Change::review_token( $this->change() ), Change::review_token( $bigger ) );
	}

	public function test_a_different_cost_gives_a_different_token(): void {
		$this->assertNotSame( Change::review_token( $this->change() ), Change::review_token( $this->change( [ 'xp_cost' => '15.00' ] ) ) );
	}

	public function test_a_resubmission_moment_gives_a_different_token(): void {
		$this->assertNotSame(
			Change::review_token( $this->change() ),
			Change::review_token( $this->change( [ 'submitted_at' => '2026-09-14 20:00:05' ] ) )
		);
	}

	public function test_the_stored_cost_format_does_not_change_the_token(): void {
		// The database returns decimal(10,2) as "6.00"; a caller holding 6 or 6.0 means the same cost.
		$this->assertSame( Change::review_token( $this->change() ), Change::review_token( $this->change( [ 'xp_cost' => 6 ] ) ) );
	}
}
