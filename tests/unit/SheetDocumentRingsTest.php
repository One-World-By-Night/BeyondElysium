<?php

namespace BeyondElysium\Tests\Unit;

use BeyondElysium\Services\Sheet_Document;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * A rated trait line in the sheet document: its text in Grapevine's multiplier form and the rating the writer draws as
 * empty rings; a negative trait, a name-only list and a price carry no rings.
 */
class SheetDocumentRingsTest extends TestCase {

	private function invoke( string $method, array $args ): mixed {
		$reflection = new ReflectionMethod( Sheet_Document::class, $method );
		$reflection->setAccessible( true );
		return $reflection->invokeArgs( null, $args );
	}

	/**
	 * @param array<int,object>  $items
	 * @param array<string,mixed> $definition
	 */
	private function rows( array $held, string $display, array $items = [], array $definition = [] ): array {
		$sections = $this->invoke(
			'build_sections',
			[
				[ [ 'block_slug' => 'abilities', 'title' => 'Abilities', 'display' => $display ] ],
				[ 'abilities' => (object) [ 'section_type' => 'trait_list', 'definition' => (object) array_merge( [ 'items' => $items, 'atomic' => false ], $definition ) ] ],
				[ 'abilities' => $held ],
				[],
			]
		);
		return $sections[0]['groups'][0]['rows'];
	}

	private function ring( string $text, int $circles ): array {
		return [ 'text' => $text, 'indent' => 0, 'circles' => $circles ];
	}

	public function test_a_rated_line_is_the_name_and_multiplier_with_the_rating_as_its_rings(): void {
		$rows = $this->rows(
			[ [ 'name' => 'Occult', 'count' => 3, 'specialization' => 'Rituals' ], [ 'name' => 'Brawl', 'count' => 5 ] ],
			'multiplier_dot'
		);

		$this->assertSame( [ $this->ring( 'Occult x3 (Rituals)', 3 ), $this->ring( 'Brawl x5', 5 ) ], $rows );
	}

	public function test_a_rating_of_one_hides_its_multiplier_and_keeps_its_ring(): void {
		$this->assertSame( [ $this->ring( 'Alertness', 1 ) ], $this->rows( [ [ 'name' => 'Alertness', 'count' => 1 ] ], 'multiplier_dot' ) );
	}

	public function test_a_line_with_no_rating_has_no_multiplier_and_no_rings_but_keeps_the_text_column(): void {
		$this->assertSame(
			[ $this->ring( 'Alertness (Sight)', 0 ) ],
			$this->rows( [ [ 'name' => 'Alertness', 'note' => 'Sight' ] ], 'multiplier_dot' )
		);
	}

	public function test_every_dot_and_multiplier_display_prints_rings(): void {
		foreach ( [ 'dot', 'dot_separate', 'simple_dots', 'multiplier', 'multiplier_dot' ] as $display ) {
			$this->assertSame( [ $this->ring( 'Occult x3', 3 ) ], $this->rows( [ [ 'name' => 'Occult', 'count' => 3 ] ], $display ), $display );
		}
	}

	public function test_a_name_only_list_and_a_price_print_plain_text(): void {
		$this->assertSame( [ 'Occult' ], $this->rows( [ [ 'name' => 'Occult', 'count' => 3 ] ], 'simple' ) );
		$this->assertSame( [ 'Occult (3)' ], $this->rows( [ [ 'name' => 'Occult', 'count' => 3 ] ], 'cost_only' ) );
		$this->assertSame(
			[ 'Draw Fire (12 XP)' ],
			$this->rows( [ [ 'name' => 'Draw Fire', 'count' => 12 ] ], 'multiplier_dot', [], [ 'count_is_cost' => true ] )
		);
	}

	public function test_a_negative_trait_is_never_ringed_even_under_a_ring_display(): void {
		$this->assertSame(
			[ 'Clumsy x2' ],
			$this->rows( [ [ 'name' => 'Clumsy', 'count' => 2 ] ], 'multiplier_dot', [], [ 'negative' => true ] )
		);
	}

	public function test_a_block_that_declares_print_rings_false_prints_the_number_alone(): void {
		$held = [ [ 'name' => 'Hitchens: Chosen', 'count' => 3 ], [ 'name' => 'Adept at Science', 'count' => 1 ] ];

		$this->assertSame( [ 'Hitchens: Chosen x3', 'Adept at Science' ], $this->rows( $held, 'multiplier_dot', [], [ 'print_rings' => false ] ) );
	}

	public function test_a_block_that_declares_print_rings_true_or_says_nothing_prints_rings(): void {
		$held = [ [ 'name' => 'Occult', 'count' => 3 ] ];

		foreach ( [ [], [ 'print_rings' => true ] ] as $definition ) {
			$this->assertSame( [ $this->ring( 'Occult x3', 3 ) ], $this->rows( $held, 'multiplier_dot', [], $definition ), wp_json_encode( $definition ) );
		}
	}

	public function test_a_trait_whose_catalog_entry_lists_specializations_names_the_one_held(): void {
		$items = [ (object) [ 'name' => 'Lore', 'specializations' => [ 'Black Hand', 'Nod' ] ] ];

		$this->assertSame(
			[ $this->ring( 'Lore: Black Hand x4', 4 ), $this->ring( 'Lore: Nod (Elder)', 1 ) ],
			$this->rows(
				[
					[ 'name' => 'Lore', 'count' => 4, 'specialization' => 'Black Hand' ],
					[ 'name' => 'Lore', 'count' => 1, 'specialization' => 'Nod', 'note' => 'Elder' ],
				],
				'multiplier_dot',
				$items
			)
		);
	}

	public function test_a_specialization_on_any_other_trait_stays_in_parentheses(): void {
		$items = [ (object) [ 'name' => 'Crafts' ] ];

		$this->assertSame(
			[ $this->ring( 'Crafts x5 (Body Crafts)', 5 ) ],
			$this->rows( [ [ 'name' => 'Crafts', 'count' => 5, 'specialization' => 'Body Crafts' ] ], 'multiplier_dot', $items )
		);
	}

	public function test_a_pool_named_from_another_field_prints_a_ringed_line_per_pool(): void {
		$sections = $this->invoke(
			'build_sections',
			[
				[ [ 'block_slug' => 'virtues', 'title' => 'Virtues' ] ],
				[ 'virtues' => (object) [
					'section_type' => 'resource_pool',
					'definition'   => (object) [ 'pools' => [ (object) [ 'name' => 'Conscience', 'default_start' => 1 ], (object) [ 'name' => 'Courage', 'default_start' => 1 ] ] ],
				] ],
				[ 'virtues' => [ 'Conscience' => [ 'permanent' => 4, 'temporary' => 2 ], 'Courage' => [ 'permanent' => 5, 'temporary' => 5 ] ] ],
				[],
			]
		);

		$this->assertSame( [ $this->ring( 'Conscience x4', 4 ), $this->ring( 'Courage x5', 5 ) ], $sections[0]['rows'] );
	}

	public function test_a_header_pool_carries_its_rating_for_the_writer_and_an_identity_field_does_not(): void {
		$pool_block = (object) [
			'section_type' => 'resource_pool',
			'definition'   => (object) [ 'pools' => [ (object) [ 'name' => 'Willpower', 'default_start' => 3 ], (object) [ 'name' => 'Blood', 'default_start' => 10 ] ] ],
		];
		$pairs      = $this->invoke( 'header_pairs_for', [ $pool_block, [ 'Willpower' => [ 'permanent' => 12, 'temporary' => 9 ] ], [] ] );

		$this->assertSame( [ [ 'Willpower', 'x12', 12 ], [ 'Blood', 'x10', 10 ] ], $pairs );

		$identity_block = (object) [
			'section_type' => 'identity_field',
			'definition'   => (object) [ 'fields' => [ (object) [ 'name' => 'Clan', 'field_type' => 'text' ] ] ],
		];
		$this->assertSame( [ [ 'Clan', 'Tremere' ] ], $this->invoke( 'header_pairs_for', [ $identity_block, [ 'Clan' => 'Tremere' ], [] ] ) );
	}

	public function test_an_attribute_band_rings_its_positive_traits_and_leaves_the_negative_half_plain(): void {
		$blocks = [
			'physical'     => (object) [ 'section_type' => 'trait_list', 'definition' => (object) [ 'items' => [], 'atomic' => false, 'display' => 'simple' ] ],
			'physical-neg' => (object) [ 'section_type' => 'trait_list', 'definition' => (object) [ 'items' => [], 'atomic' => false, 'display' => 'simple', 'negative' => true ] ],
		];
		$stack  = (object) [ 'stack_definition' => (object) [ 'sections' => [
			(object) [ 'block_slug' => 'physical', 'negative_block_slug' => 'physical-neg', 'label' => 'Physical' ],
		] ] ];

		[ , $sections ] = $this->invoke(
			'header_and_body',
			[
				[
					[ 'block_slug' => 'physical', 'title' => 'Physical Traits (Positive)', 'display' => 'multiplier_dot' ],
					[ 'block_slug' => 'physical-neg', 'title' => 'Physical Traits (Negative)' ],
				],
				$blocks,
				[ 'physical' => [ [ 'name' => 'Dexterous', 'count' => 4 ] ], 'physical-neg' => [ [ 'name' => 'Clumsy', 'count' => 1 ] ] ],
				[],
				$stack,
			]
		);

		$this->assertCount( 1, $sections );
		$this->assertTrue( $sections[0]['band'] );
		$this->assertSame(
			[
				[ 'label' => null, 'rows' => [ $this->ring( 'Dexterous x4', 4 ) ] ],
				[ 'label' => 'Negative', 'rows' => [ 'Clumsy' ] ],
			],
			$sections[0]['groups']
		);
	}
}
