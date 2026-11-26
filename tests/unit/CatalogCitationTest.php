<?php

namespace BeyondElysium\Tests\Unit;

use BeyondElysium\Services\Catalog_Citation;
use PHPUnit\Framework\TestCase;

/**
 * The book a shipped entry names is a book, never how the entry was built.
 */
class CatalogCitationTest extends TestCase {

	public function test_it_reads_the_book_after_the_csv_marker(): void {
		$this->assertSame( 'OWbN Ventrue Packet', Catalog_Citation::book_of( 'GVM: Merits; met-mechanics.csv: OWbN Ventrue Packet' ) );
		$this->assertSame( 'Guide to the Low Clans', Catalog_Citation::book_of( 'met-mechanics.csv: Guide to the Low Clans' ) );
		$this->assertSame( 'Laws of the Night Revised', Catalog_Citation::book_of( 'GVM: Abilities; met-mechanics.csv: Laws of the Night Revised' ) );
	}

	public function test_it_keeps_a_source_that_is_a_book_or_packet_title(): void {
		$this->assertSame( 'OWBN Fallen Genre Packet (2021)', Catalog_Citation::book_of( 'OWBN Fallen Genre Packet (2021)' ) );
		$this->assertSame( 'DA Core', Catalog_Citation::book_of( 'DA Core' ) );
		$this->assertSame( 'Clanbook: Nosferatu Revised', Catalog_Citation::book_of( 'Clanbook: Nosferatu Revised' ) );
	}

	public function test_it_gives_nothing_for_how_an_entry_was_built(): void {
		foreach ( [ 'GVM: Flaws', 'GVM: Sorcery, Levels', 'met-mechanics.csv:', 'samples/research/hunter/mortal/resolved.json', 'Influences' ] as $source ) {
			$this->assertNull( Catalog_Citation::book_of( $source ), $source );
		}
	}

	public function test_it_gives_nothing_for_a_creature_type_or_a_list_of_them(): void {
		foreach ( [ 'Vampire', 'Mage', 'Fera, Werewolf', 'Mortal, Changeling, Demon, Fera, Kuei-Jin, Mage, Mummy, Vampire' ] as $source ) {
			$this->assertNull( Catalog_Citation::book_of( $source ), $source );
		}
	}

	public function test_it_reads_every_case_of_the_shared_fixture(): void {
		$cases = json_decode( (string) file_get_contents( BE_PLUGIN_ROOT . '/tests/fixtures/catalog-book.json' ), true );

		foreach ( $cases as $case ) {
			$this->assertSame( $case['book'], Catalog_Citation::book_of( $case['source'] ), (string) $case['source'] );
		}
	}

	public function test_it_gives_nothing_for_no_source(): void {
		$this->assertNull( Catalog_Citation::book_of( null ) );
		$this->assertNull( Catalog_Citation::book_of( '' ) );
		$this->assertNull( Catalog_Citation::book_of( [ 'a' ] ) );
	}

	public function test_no_shipped_source_gives_build_text_or_a_creature_type(): void {
		$types = [];
		foreach ( glob( BE_PLUGIN_DIR . 'data/catalog/stacks/*.json' ) ?: [] as $file ) {
			$stack   = json_decode( (string) file_get_contents( $file ), true );
			$types[] = strtolower( (string) ( $stack['name'] ?? '' ) );
		}

		$seen = 0;
		foreach ( glob( BE_PLUGIN_DIR . 'data/catalog/blocks/*.json' ) ?: [] as $file ) {
			$block = json_decode( (string) file_get_contents( $file ), true );
			$rows  = array_merge( $block['definition']['items'] ?? [], $block['definition']['powers'] ?? [] );
			foreach ( $rows as $row ) {
				$book = Catalog_Citation::book_of( $row['source'] ?? null );
				if ( $book === null ) {
					continue;
				}
				++$seen;
				$this->assertDoesNotMatchRegularExpression( '/GVM|\.csv|samples\/|\.pdf|resolved\.json/i', $book, "{$file}: {$book}" );
				$this->assertNotContains( strtolower( $book ), $types, "{$file}: {$book} is a creature type" );
			}
		}
		$this->assertGreaterThan( 1000, $seen, 'a large share of the shipped entries name a book' );
	}
}
