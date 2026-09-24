<?php

namespace BeyondElysium\Tests\Unit;

use BeyondElysium\Services\Layout_Generator;
use PHPUnit\Framework\TestCase;

/**
 * `Layout_Generator.php` (authoritative) and `generateLayout.ts` (client-side "no template at all" path) must agree.
 */
class LayoutGeneratorParityTest extends TestCase {

	private function fixture( string $name ) {
		$path = BE_PLUGIN_ROOT . '/tests/fixtures/' . $name;
		return json_decode( file_get_contents( $path ) );
	}

	public function test_generate_matches_the_shared_fixture(): void {
		$input    = $this->fixture( 'layout-generator-input.json' );
		$expected = $this->fixture( 'layout-generator-expected.json' );

		$blocks = (array) $input->blocks;
		$layout = Layout_Generator::generate( $input->stack, $blocks );

		$this->assertSame(
			json_decode( json_encode( $expected ), true ),
			$layout
		);
	}
}
