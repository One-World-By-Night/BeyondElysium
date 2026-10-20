<?php

namespace BeyondElysium\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * A power block's in-type and out-of-type modifiers only change a price where the creature type's section for that block
 * declares an in-type test; with none, every purchase is in-type and the modifier never applies. So a section whose
 * block carries modifiers either declares a test or is named here with the reason it does not.
 */
class InertModifiersTest extends TestCase {

	private const CATALOG = BE_PLUGIN_PATH . '/data/catalog/';

	/**
	 * Sections with modifiers on their block and no test, each with why.
	 */
	private const NO_TEST = [
		'mortal/werewolf-gifts' => "A kinfolk's Gifts are bought from the kinfolk's own pool; the species book that prices them is not in the catalog's sources.",
		'mortal/fera-gifts'     => "A kinfolk's Gifts are bought from the kinfolk's own pool; the species book that prices them is not in the catalog's sources.",
	];

	/** Creature types that are Storyteller-only and hold any block: there is no type to be in or out of. */
	private const ANY_BLOCK = [ 'various' ];

	/**
	 * @return array<string,mixed>
	 */
	private static function read( string $path ): array {
		$decoded = json_decode( (string) file_get_contents( $path ), true );
		self::assertIsArray( $decoded, $path );
		return $decoded;
	}

	public function test_a_section_whose_block_has_modifiers_declares_an_in_type_test_or_is_listed(): void {
		$with_modifiers = [];
		foreach ( glob( self::CATALOG . 'blocks/*.json' ) ?: [] as $path ) {
			$block = self::read( $path );
			$meta  = $block['definition']['_meta'] ?? [];
			if ( ! empty( $meta['in_type'] ) || ! empty( $meta['out_of_type'] ) ) {
				$with_modifiers[ $block['slug'] ] = true;
			}
		}

		$untested = [];
		foreach ( glob( self::CATALOG . 'stacks/*.json' ) ?: [] as $path ) {
			$stack = self::read( $path );
			if ( in_array( $stack['slug'], self::ANY_BLOCK, true ) ) {
				continue;
			}
			foreach ( $stack['definition']['sections'] as $section ) {
				if ( isset( $with_modifiers[ $section['block_slug'] ] ) && empty( $section['in_type'] ) ) {
					$untested[] = $stack['slug'] . '/' . $section['block_slug'];
				}
			}
		}
		sort( $untested );
		$listed = array_keys( self::NO_TEST );
		sort( $listed );

		$this->assertSame( $listed, $untested );
	}
}
