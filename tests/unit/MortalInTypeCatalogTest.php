<?php

namespace BeyondElysium\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The Mortal creature type's Disciplines section prices a ghoul's Potence in-type and every other Discipline one more,
 * and a revenant's own family Disciplines in-type; the Revenant Family map names Disciplines the book has, and every
 * family the field offers has an entry or is named here as one no source available to the catalog gives Disciplines for.
 */
class MortalInTypeCatalogTest extends TestCase {

	private const CATALOG = BE_PLUGIN_PATH . '/data/catalog/';

	/**
	 * Revenant Family options with no Discipline list in the books and packets the catalog was built from. A family on
	 * this list reads every Discipline as in-type, through `unmapped: pass`, until a source gives it a list.
	 */
	private const UNCONFIRMED = [ 'Kairouan Brotherhood', 'Rossellini', 'Servants of Anushin-Rawan' ];

	/**
	 * @return array<string,mixed>
	 */
	private static function read( string $relative ): array {
		$decoded = json_decode( (string) file_get_contents( self::CATALOG . $relative ), true );
		self::assertIsArray( $decoded, $relative );
		return $decoded;
	}

	/**
	 * @return array<string,string[]>
	 */
	private static function revenant_map(): array {
		return self::read( 'blocks/mortal-identity.json' )['definition']['revenant_disciplines'] ?? [];
	}

	/**
	 * @return string[]
	 */
	private static function revenant_families(): array {
		foreach ( self::read( 'blocks/mortal-identity.json' )['definition']['fields'] as $field ) {
			if ( $field['name'] === 'Revenant Family' ) {
				return $field['options'];
			}
		}
		return [];
	}

	public function test_the_disciplines_section_prices_a_ghouls_potence_and_a_revenants_family_disciplines_in_type(): void {
		foreach ( self::read( 'stacks/mortal.json' )['definition']['sections'] as $section ) {
			if ( $section['block_slug'] !== 'vampire-disciplines' ) {
				continue;
			}
			$this->assertSame( [
				[
					'kind'   => 'names',
					'values' => [ 'constant' => [ 'Potence' ] ],
					'when'   => [ 'field' => 'mortal-identity.Revenant Family', 'set' => false ],
				],
				[
					'kind'   => 'names',
					'values' => [ 'map' => 'mortal-identity.revenant_disciplines', 'by' => [ 'Revenant Family' ], 'unmapped' => 'pass' ],
					'when'   => [ 'field' => 'mortal-identity.Revenant Family', 'set' => true ],
				],
			], $section['in_type'] ?? null );
			return;
		}
		$this->fail( 'the Mortal creature type has no Disciplines section' );
	}

	public function test_every_revenant_family_has_its_disciplines_or_is_named_as_unconfirmed(): void {
		$without = array_values( array_diff( self::revenant_families(), array_keys( self::revenant_map() ) ) );
		sort( $without );
		$unconfirmed = self::UNCONFIRMED;
		sort( $unconfirmed );

		$this->assertSame( $unconfirmed, $without );
	}

	public function test_every_mapped_discipline_is_one_the_book_has(): void {
		// Thaumaturgy is filed as Blood Magic in the catalog; the books list it among a family's Disciplines, as the
		// Vampire clan map does for the Tremere.
		$book = [ 'Thaumaturgy' ];
		foreach ( self::read( 'blocks/vampire-disciplines.json' )['definition']['powers'] as $power ) {
			$book[] = $power['name'];
		}

		foreach ( self::revenant_map() as $family => $disciplines ) {
			$this->assertNotSame( [], $disciplines, $family );
			foreach ( $disciplines as $discipline ) {
				$this->assertContains( $discipline, $book, "$family: $discipline" );
			}
		}
	}
}
