<?php

namespace BeyondElysium\Tests\Unit\Display;

use BeyondElysium\Services\Display\Temper_Display;
use PHPUnit\Framework\TestCase;

/**
 * `Temper_Display.php` (authoritative) and `displayTemper.ts` (the on-screen sheet's own
 * rendering) must agree - same permanent/temporary pair in, same glyph string out. Both
 * read the same fixture and are checked against the same expected output; this half
 * proves the PHP side, `src/lib/displayTemper.test.ts` proves the TypeScript side.
 *
 * @see BE_PROCESS/design/signed-pdf-design.md
 */
class TemperDisplayParityTest extends TestCase {

	private function fixture( string $name ) {
		$path = BE_PLUGIN_ROOT . '/tests/fixtures/' . $name;
		return json_decode( file_get_contents( $path ) );
	}

	public function test_display_matches_the_shared_fixture_for_every_case(): void {
		$input    = $this->fixture( 'temper-display-input.json' );
		$expected = $this->fixture( 'temper-display-expected.json' );

		$this->assertSame(
			count( $expected ),
			count( $input ),
			'input/expected fixture case count must match'
		);

		foreach ( $input as $i => $case ) {
			$actual = Temper_Display::display( $case->permanent, $case->temporary );

			$this->assertSame(
				$expected[ $i ]->output,
				$actual,
				sprintf(
					'case "%s" (permanent=%d, temporary=%d)',
					$case->name,
					$case->permanent,
					$case->temporary
				)
			);
		}
	}

	/**
	 * Dedicated case for the fixture entry named "temporary above permanent renders
	 * overflow glyphs, not clamped" - a pool's temporary value is never clamped down to
	 * its permanent value; the excess renders as overflow glyphs past the permanent
	 * track. Getting this backwards (clamping instead of overflowing) is the one wrong
	 * turn that would still pass a careless glyph-count check, so it gets its own
	 * assertion beyond the fixture loop above.
	 */
	public function test_temporary_above_permanent_renders_overflow_not_clamped(): void {
		$input    = $this->fixture( 'temper-display-input.json' );
		$expected = $this->fixture( 'temper-display-expected.json' );

		$case_index = null;
		foreach ( $input as $i => $case ) {
			if ( $case->name === 'temporary above permanent renders a ringed dot for each extra point, not clamped' ) {
				$case_index = $i;
				break;
			}
		}
		$this->assertNotNull( $case_index, 'fixture is missing the temporary-above-permanent overflow case' );

		$case   = $input[ $case_index ];
		$actual = Temper_Display::display( $case->permanent, $case->temporary );

		$this->assertSame( 3, $case->permanent );
		$this->assertSame( 5, $case->temporary );
		$this->assertSame( $expected[ $case_index ]->output, $actual );

		// Built from codepoints rather than typed as literals, so the assertion can't
		// silently pass due to a transcription slip matching a transcription bug.
		$dot            = mb_chr( 0x25CF, 'UTF-8' ); // ●
		$overflow_glyph = mb_chr( 0x25C9, 'UTF-8' ); // ◉
		$spent_glyph    = mb_chr( 0x25CB, 'UTF-8' ); // ○

		// Not clamped to 3 filled dots: temporary - permanent = 2 overflow dots follow.
		$this->assertSame( str_repeat( $dot, 3 ) . str_repeat( $overflow_glyph, 2 ), $actual );
		$this->assertStringNotContainsString( $spent_glyph, $actual, 'overflow must never also render spent glyphs' );
	}
}
