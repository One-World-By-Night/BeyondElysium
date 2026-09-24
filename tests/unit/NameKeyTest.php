<?php

namespace BeyondElysium\Tests\Unit;

use BeyondElysium\Services\Name_Key;
use PHPUnit\Framework\TestCase;

/**
 * `Name_Key`, the normalization used throughout catalog term translation.
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
	 * Only a LEADING article is special.
	 */
	public function test_article_in_the_middle_of_a_phrase_is_not_stripped(): void {
		$this->assertSame( 'gift of the beast', Name_Key::for( 'Gift of the Beast' ) );
	}

	/**
	 * The real point of the whole rule: three different real-world spellings of the same MET term collapse to one
	 * identical key.
	 */
	public function test_differently_spelled_real_world_variants_collapse_to_one_key(): void {
		$variants = [ 'Fortitude', 'The Fortitude', '  fortitude  ', 'FORTITUDE' ];
		$keys     = array_map( [ Name_Key::class, 'for' ], $variants );
		$this->assertCount( 1, array_unique( $keys ) );
	}

	public function test_empty_string_does_not_throw(): void {
		$this->assertSame( '', Name_Key::for( '' ) );
	}

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
