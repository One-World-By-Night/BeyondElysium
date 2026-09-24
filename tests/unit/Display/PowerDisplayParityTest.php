<?php

namespace BeyondElysium\Tests\Unit\Display;

use BeyondElysium\Services\Display\Power_Display;
use PHPUnit\Framework\TestCase;

/**
 * `Power_Display.php` (authoritative for the signed-PDF exporter) and `TieredPowerRenderer.tsx`'s label helpers
 * (authoritative for the on-screen character sheet) must agree.
 */
class PowerDisplayParityTest extends TestCase {

	private function fixture( string $name ) {
		$path = BE_PLUGIN_ROOT . '/tests/fixtures/' . $name;
		return json_decode( file_get_contents( $path ) );
	}

	public function test_label_helpers_match_the_shared_fixture(): void {
		$input    = $this->fixture( 'power-display-input.json' );
		$expected = $this->fixture( 'power-display-expected.json' );

		foreach ( $input->cases as $index => $case ) {
			$held   = (array) $case->held;
			$output = self::invoke( $input->definition, $case, $held );

			$this->assertSame( $expected[ $index ]->output, $output, $case->name );
		}
	}

	/**
	 * Dispatches one fixture case to the Power_Display method it names.
	 *
	 * @param array<string,mixed> $held
	 * @return string|string[]
	 */
	private static function invoke( object $definition, object $case, array $held ) {
		$level = $case->level ?? null;

		switch ( $case->method ) {
			case 'with_tradition':
				$label = 'named_label' === $case->label_method
					? Power_Display::named_label( $definition, $held, $level )
					: Power_Display::numeric_label( $definition, $held );
				return Power_Display::with_tradition( $held, $label );

			case 'elder_label':
				return Power_Display::elder_label( $definition, $held );

			case 'numeric_label':
				return Power_Display::numeric_label( $definition, $held );

			case 'named_label':
				return Power_Display::named_label( $definition, $held, $level );

			case 'named_mode_rows':
				return Power_Display::named_mode_rows( $definition, $held );

			default:
				throw new \RuntimeException( 'Unknown fixture method: ' . $case->method );
		}
	}
}
