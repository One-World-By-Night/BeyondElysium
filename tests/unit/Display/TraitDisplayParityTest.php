<?php

namespace BeyondElysium\Tests\Unit\Display;

use BeyondElysium\Services\Display\Trait_Display;
use PHPUnit\Framework\TestCase;

/**
 * `Trait_Display.php` (authoritative) and `displayTrait.ts` (the on-screen character
 * sheet's renderer) must agree - same trait, mode, and dot glyph in, same formatted
 * string out. Both read the same fixture and are checked against the same expected
 * output; this half proves the PHP side, `src/lib/displayTrait.test.ts` proves the
 * TypeScript side.
 *
 * @see BE_PROCESS/signed-pdf-design.md SP-3
 */
class TraitDisplayParityTest extends TestCase {

	private function fixture( string $name ) {
		$path = BE_PLUGIN_ROOT . '/tests/fixtures/' . $name;
		return json_decode( file_get_contents( $path ) );
	}

	public function test_display_trait_matches_every_shared_fixture_case(): void {
		$input    = $this->fixture( 'trait-display-input.json' );
		$expected = $this->fixture( 'trait-display-expected.json' );

		$this->assertSame(
			count( $expected ),
			count( $input ),
			'Fixture files have drifted out of alignment.'
		);

		foreach ( $input as $i => $case ) {
			$this->assertSame(
				$case->name,
				$expected[ $i ]->name,
				"Fixture case order drifted at index {$i}."
			);

			$actual = Trait_Display::display_trait( $case->trait, $case->mode, $case->dot ?? '•' );

			$this->assertSame( $expected[ $i ]->output, $actual, $case->name );
		}
	}

	/**
	 * The specific regression this project has already been bitten by once (D25,
	 * a sibling bridging bug): signed-pdf-design.md SP-3 calls out this exact
	 * leading-integer parse as a required direct assertion, not just an indirect
	 * one via display_trait()'s simple_number mode.
	 */
	public function test_parse_total_reads_the_leading_integer_and_ignores_the_rest(): void {
		$this->assertSame( 3, Trait_Display::parse_total( '3 (borrowed)' ) );
	}
}
