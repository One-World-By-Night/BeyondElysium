<?php

namespace BeyondElysium\Tests\Unit;

use BeyondElysium\Services\Name_Key;
use PHPUnit\Framework\TestCase;

/**
 * T1 (1.2.0 releases/1.2.0-design-workflow.md §9): Name_Key is the only normalization used
 * throughout 1.2.0, and until now had no dedicated test of its own despite already being a
 * shared production dependency (Seeder's CSV/GVM merge, 1.1.0's rote-card matching per its own
 * class docblock) - Models\Translation_String and Models\Translation (B2) are two more call
 * sites now resting on this exact, previously-unpinned contract.
 *
 * @see BE_PROCESS/releases/1.2.0-design-workflow.md §4, §9 T1
 */
class NameKeyTest extends TestCase {

	public function test_plain_term_is_only_lowercased(): void {
		$this->assertSame( 'fortitude', Name_Key::for( 'Fortitude' ) );
	}

	public function test_leading_article_a_is_stripped(): void {
		$this->assertSame( 'weak blood', Name_Key::for( 'A Weak Blood' ) );
	}

	public function test_leading_article_an_is_stripped(): void {
		$this->assertSame( 'unusual gift', Name_Key::for( 'An Unusual Gift' ) );
	}

	public function test_leading_article_the_is_stripped(): void {
		$this->assertSame( 'fortitude', Name_Key::for( 'The Fortitude' ) );
	}

	public function test_leading_article_match_is_case_insensitive(): void {
		$this->assertSame( 'fortitude', Name_Key::for( 'THE Fortitude' ) );
		$this->assertSame( 'fortitude', Name_Key::for( 'the fortitude' ) );
	}

	public function test_surrounding_whitespace_is_trimmed(): void {
		$this->assertSame( 'fortitude', Name_Key::for( '  Fortitude  ' ) );
	}

	public function test_a_leading_article_plus_surrounding_whitespace_together(): void {
		$this->assertSame( 'weak blood', Name_Key::for( '  The Weak Blood  ' ) );
	}

	/**
	 * Only a LEADING article is special. "the" appearing mid-phrase is real content and must
	 * survive - "Gift of the Beast" is not "Gift of Beast".
	 */
	public function test_article_in_the_middle_of_a_phrase_is_not_stripped(): void {
		$this->assertSame( 'gift of the beast', Name_Key::for( 'Gift of the Beast' ) );
	}

	/**
	 * The real point of the whole rule: three different real-world spellings of the same MET
	 * term collapse to one identical key, which is what lets a single catalog row and a single
	 * translation row stand for all three.
	 */
	public function test_differently_spelled_real_world_variants_collapse_to_one_key(): void {
		$variants = [ 'Fortitude', 'The Fortitude', '  fortitude  ', 'FORTITUDE' ];
		$keys     = array_map( [ Name_Key::class, 'for' ], $variants );
		$this->assertCount( 1, array_unique( $keys ) );
	}

	public function test_empty_string_does_not_throw(): void {
		$this->assertSame( '', Name_Key::for( '' ) );
	}

	/**
	 * Found live in real `vampire-blood-magic` catalog data: "tainted" and a zero-width-space
	 * prefixed variant are visually identical and MySQL's utf8mb4_unicode_520_ci collation
	 * already treats them as the same string - but before this fix, Name_Key::for() did not,
	 * so PHP silently counted two "distinct" keys the database only ever stored as one,
	 * breaking Catalog_Translator::rescan()'s added/updated bookkeeping.
	 */
	public function test_a_zero_width_space_does_not_produce_a_distinct_key(): void {
		$this->assertSame( Name_Key::for( 'Tainted' ), Name_Key::for( "\u{200B}Tainted" ) );
	}

	public function test_other_invisible_formatting_characters_are_also_stripped(): void {
		$plain = Name_Key::for( 'Invisible Chars' );
		foreach ( [ "\u{200B}", "\u{200C}", "\u{200D}", "\u{FEFF}", "\u{00AD}", "\u{2060}" ] as $char ) {
			$this->assertSame( $plain, Name_Key::for( $char . 'Invisible Chars' ), "U+" . dechex( mb_ord( $char ) ) . ' was not stripped' );
		}
	}
}
