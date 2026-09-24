<?php

namespace BeyondElysium\Tests\Unit;

use BeyondElysium\Services\Sheet_Document;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * A non-atomic trait_list section whose held entries all carry a numeric count shows its own total after the section
 * title, in the signed PDF exactly as on screen (CharacterSheet.tsx carries the identical rule).
 */
class SheetDocumentSectionTotalTest extends TestCase {

	private function sections( array $sections, array $blocks, array $sheet_data ): array {
		$method = new ReflectionMethod( Sheet_Document::class, 'build_sections' );
		$method->setAccessible( true );
		return $method->invoke( null, $sections, $blocks, $sheet_data, [] );
	}

	public function test_a_non_atomic_section_with_a_fully_counted_hold_shows_its_total(): void {
		$sections = [ [ 'block_slug' => 'met-abilities', 'title' => 'Abilities' ] ];
		$blocks   = [
			'met-abilities' => (object) [
				'section_type' => 'trait_list',
				'definition'   => (object) [ 'items' => [], 'atomic' => false ],
			],
		];
		$sheet_data = [
			'met-abilities' => [
				[ 'name' => 'Occult', 'total' => 3 ],
				[ 'name' => 'Melee', 'total' => 2 ],
			],
		];

		$entry = $this->sections( $sections, $blocks, $sheet_data )[0];
		$this->assertSame( "Abilities \u{00B7} 5", $entry['title'] );
	}

	public function test_an_atomic_section_never_shows_a_total(): void {
		$sections = [ [ 'block_slug' => 'vampire-rituals', 'title' => 'Rituals' ] ];
		$blocks   = [
			'vampire-rituals' => (object) [
				'section_type' => 'trait_list',
				'definition'   => (object) [ 'items' => [], 'atomic' => true ],
			],
		];
		$sheet_data = [
			'vampire-rituals' => [
				[ 'name' => 'Blood Ties', 'note' => 'Practiced' ],
			],
		];

		$entry = $this->sections( $sections, $blocks, $sheet_data )[0];
		$this->assertSame( 'Rituals', $entry['title'] );
	}

	public function test_a_section_with_any_uncounted_entry_shows_no_total(): void {
		$sections = [ [ 'block_slug' => 'met-abilities', 'title' => 'Abilities' ] ];
		$blocks   = [
			'met-abilities' => (object) [
				'section_type' => 'trait_list',
				'definition'   => (object) [ 'items' => [], 'atomic' => false ],
			],
		];
		$sheet_data = [
			'met-abilities' => [
				[ 'name' => 'Occult', 'total' => 3 ],
				[ 'name' => 'Custom Skill' ],
			],
		];

		$entry = $this->sections( $sections, $blocks, $sheet_data )[0];
		$this->assertSame( 'Abilities', $entry['title'] );
	}
}
