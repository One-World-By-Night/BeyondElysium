<?php

namespace BeyondElysium\Tests\Unit\Display;

use BeyondElysium\Services\Display\Cross_Block_Ref;
use PHPUnit\Framework\TestCase;

/**
 * `Cross_Block_Ref.php` (signed PDF export) and `resolveCrossBlockRef.ts` (on-screen
 * character sheet) must agree - same ref/section/pool and sheet data in, same resolved
 * value out. Both read the same fixture and are checked against the same expected
 * output; this half proves the PHP side, `src/lib/resolveCrossBlockRef.test.ts` proves
 * the TypeScript side.
 */
class CrossBlockRefParityTest extends TestCase {

	private function fixture( string $name ) {
		$path = BE_PLUGIN_ROOT . '/tests/fixtures/' . $name;
		return json_decode( file_get_contents( $path ) );
	}

	/** Deep-converts a decoded stdClass tree into a plain nested array. */
	private function to_array( $decoded ): array {
		return json_decode( json_encode( $decoded ), true );
	}

	public function test_resolve_cross_block_value_matches_the_shared_fixture(): void {
		$input    = $this->fixture( 'cross-block-ref-input.json' );
		$expected = $this->fixture( 'cross-block-ref-expected.json' );

		foreach ( $input->resolveCrossBlockValue as $i => $case ) {
			$actual = Cross_Block_Ref::resolve_cross_block_value(
				$case->ref,
				$this->to_array( $case->sheetData )
			);

			$this->assertSame( $expected->resolveCrossBlockValue[ $i ], $actual, $case->name );
		}
	}

	public function test_resolve_section_title_matches_the_shared_fixture(): void {
		$input    = $this->fixture( 'cross-block-ref-input.json' );
		$expected = $this->fixture( 'cross-block-ref-expected.json' );

		foreach ( $input->resolveSectionTitle as $i => $case ) {
			$actual = Cross_Block_Ref::resolve_section_title(
				$case->section,
				$this->to_array( $case->sheetData )
			);

			$this->assertSame( $expected->resolveSectionTitle[ $i ], $actual, $case->name );
		}
	}

	public function test_resolve_pool_name_matches_the_shared_fixture(): void {
		$input    = $this->fixture( 'cross-block-ref-input.json' );
		$expected = $this->fixture( 'cross-block-ref-expected.json' );

		foreach ( $input->resolvePoolName as $i => $case ) {
			$actual = Cross_Block_Ref::resolve_pool_name(
				$case->pool,
				$this->to_array( $case->sheetData )
			);

			$this->assertSame( $expected->resolvePoolName[ $i ], $actual, $case->name );
		}
	}
}
