<?php

namespace BeyondElysium\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * A dev build's version string must sort below the release it leads up to, and dev builds must sort among themselves by number, not alphabetically.
 */
class DevVersionCompareTest extends TestCase {

	public function test_dev_builds_sort_between_the_prior_release_and_the_next_one(): void {
		$ordered = array( '1.4.0', '1.5.0-dev.1', '1.5.0-dev.2', '1.5.0-dev.10', '1.5.0' );
		for ( $i = 0; $i < count( $ordered ) - 1; $i++ ) {
			$this->assertSame(
				-1,
				version_compare( $ordered[ $i ], $ordered[ $i + 1 ] ),
				"{$ordered[$i]} must sort below {$ordered[$i + 1]}"
			);
		}
	}
}
