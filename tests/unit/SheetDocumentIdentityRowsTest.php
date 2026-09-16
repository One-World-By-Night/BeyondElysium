<?php

namespace BeyondElysium\Tests\Unit;

use BeyondElysium\Services\Sheet_Document;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * 1.0.0-review F-078 (Pass H intake `t2-rendering`). A multiselect identity field holds a list,
 * and the signed sheet cast every identity value to a string - so a character's chosen options
 * printed on the signed, official document as the word "Array". It prints the choices, joined
 * the way the on-screen sheet joins them.
 */
class SheetDocumentIdentityRowsTest extends TestCase {

	private function rows( array $values, array $field_names ): array {
		$method = new ReflectionMethod( Sheet_Document::class, 'identity_field_rows' );
		$method->setAccessible( true );
		$definition = (object) [ 'fields' => array_map( static fn( $name ) => (object) [ 'name' => $name ], $field_names ) ];
		return $method->invoke( null, $values, $definition );
	}

	public function test_a_multiselect_prints_its_choices_not_the_word_array(): void {
		$this->assertSame( [ 'Favored Disciplines: Celerity, Fortitude' ], $this->rows( [ 'Favored Disciplines' => [ 'Celerity', 'Fortitude' ] ], [ 'Favored Disciplines' ] ) );
	}

	public function test_an_empty_choice_prints_a_dash(): void {
		$this->assertSame( [ 'Favored Disciplines: —', 'Clan: —' ], $this->rows( [ 'Favored Disciplines' => [], 'Clan' => '' ], [ 'Favored Disciplines', 'Clan' ] ) );
	}

	public function test_a_plain_value_prints_as_it_always_did(): void {
		$this->assertSame( [ 'Clan: Toreador', 'Generation: 9' ], $this->rows( [ 'Clan' => 'Toreador', 'Generation' => 9 ], [ 'Clan', 'Generation' ] ) );
	}
}
