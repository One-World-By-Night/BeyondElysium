<?php

namespace BeyondElysium\Tests\Unit;

use BeyondElysium\Models\Release_Batch;
use PHPUnit\Framework\TestCase;

/**
 * `Release_Batch::is_out_row()` is the one rule the whole release-batch visibility gate rests.
 */
class ReleaseBatchUnitTest extends TestCase {

	/** @param array<string,mixed> $fields */
	private function batch( array $fields ): object {
		return (object) array_merge( [
			'status'     => 'draft',
			'release_at' => null,
		], $fields );
	}

	public function test_a_draft_batch_is_never_out(): void {
		$batch = $this->batch( [ 'status' => 'draft', 'release_at' => null ] );
		$this->assertFalse( Release_Batch::is_out_row( $batch, '2026-09-16 12:00:00' ) );
	}

	public function test_a_draft_batch_with_a_release_at_is_still_never_out(): void {
		// A batch can hold a release_at while still draft (name set, date not yet committed to).
		$batch = $this->batch( [ 'status' => 'draft', 'release_at' => '2020-01-01 00:00:00' ] );
		$this->assertFalse( Release_Batch::is_out_row( $batch, '2026-09-16 12:00:00' ) );
	}

	public function test_a_scheduled_batch_with_a_future_release_at_is_not_out(): void {
		$batch = $this->batch( [ 'status' => 'scheduled', 'release_at' => '2026-09-16 18:00:00' ] );
		$this->assertFalse( Release_Batch::is_out_row( $batch, '2026-09-16 17:59:59' ) );
	}

	public function test_a_scheduled_batch_is_out_at_the_exact_moment_of_release_at(): void {
		// Visibility never waits for cron: due at 5pm means out at 5:00:00.
		$batch = $this->batch( [ 'status' => 'scheduled', 'release_at' => '2026-09-16 17:00:00' ] );
		$this->assertTrue( Release_Batch::is_out_row( $batch, '2026-09-16 17:00:00' ) );
	}

	public function test_a_scheduled_batch_with_a_past_release_at_is_out(): void {
		$batch = $this->batch( [ 'status' => 'scheduled', 'release_at' => '2026-09-16 17:00:00' ] );
		$this->assertTrue( Release_Batch::is_out_row( $batch, '2026-09-16 17:00:01' ) );
	}

	public function test_a_scheduled_batch_with_no_release_at_is_not_out(): void {
		// Should not arise through the normal write path (update() requires a release_at to reach scheduled).
		$batch = $this->batch( [ 'status' => 'scheduled', 'release_at' => null ] );
		$this->assertFalse( Release_Batch::is_out_row( $batch, '2026-09-16 12:00:00' ) );
	}

	public function test_a_released_batch_is_always_out(): void {
		$batch = $this->batch( [ 'status' => 'released', 'release_at' => '2099-01-01 00:00:00' ] );
		$this->assertTrue( Release_Batch::is_out_row( $batch, '2026-09-16 12:00:00' ) );
	}

	public function test_a_released_batch_with_no_release_at_is_still_always_out(): void {
		$batch = $this->batch( [ 'status' => 'released', 'release_at' => null ] );
		$this->assertTrue( Release_Batch::is_out_row( $batch, '2026-09-16 12:00:00' ) );
	}
}
