<?php

namespace BeyondElysium\Tests\Unit\Display;

use BeyondElysium\Services\Display\Change_Description;
use PHPUnit\Framework\TestCase;

/**
 * `Change_Description.php` (authoritative) and `describeChange.ts` (the on-screen approval queue / change history's
 * own rendering) must agree.
 */
class ChangeDescriptionParityTest extends TestCase {

	private function fixture( string $name ) {
		$path = BE_PLUGIN_ROOT . '/tests/fixtures/' . $name;
		return json_decode( file_get_contents( $path ) );
	}

	public function test_describe_matches_the_shared_fixture_for_every_case(): void {
		$input    = $this->fixture( 'change-description-input.json' );
		$expected = $this->fixture( 'change-description-expected.json' );

		$this->assertSame(
			count( $expected ),
			count( $input ),
			'input/expected fixture case count must match'
		);

		foreach ( $input as $i => $case ) {
			$actual = Change_Description::describe( $case->change_type, (array) $case->change_data );

			$this->assertSame(
				$expected[ $i ]->output,
				$actual,
				sprintf( 'case "%s" (change_type=%s)', $case->name, $case->change_type )
			);
		}
	}

	public function test_xp_delta_reads_a_signed_amount_directly_for_earn_and_adjust(): void {
		$this->assertSame( 3, Change_Description::xp_delta( 'xp_earn', [ 'amount' => 3 ], 0.0 ) );
		$this->assertSame( -2, Change_Description::xp_delta( 'xp_adjust', [ 'amount' => -2 ], 0.0 ) );
	}

	public function test_xp_delta_negates_a_positive_cost_for_every_other_costed_change(): void {
		$this->assertSame( -6, Change_Description::xp_delta( 'add_trait', [], 6.0 ) );
	}

	public function test_xp_delta_is_zero_for_an_uncosted_change(): void {
		$this->assertSame( 0, Change_Description::xp_delta( 'import_note', [], 0.0 ) );
	}
}
