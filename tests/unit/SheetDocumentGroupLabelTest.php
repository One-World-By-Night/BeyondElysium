<?php

namespace BeyondElysium\Tests\Unit;

use BeyondElysium\Services\Sheet_Document;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * A trait_list section's groups in the sheet document: each group named when there are several, the only one unnamed.
 */
class SheetDocumentGroupLabelTest extends TestCase {

	private function groups( array $held ): array {
		$method = new ReflectionMethod( Sheet_Document::class, 'build_sections' );
		$method->setAccessible( true );
		$sections = $method->invoke(
			null,
			[ [ 'block_slug' => 'vampire-merits', 'title' => 'Merits' ] ],
			[
				'vampire-merits' => (object) [
					'section_type' => 'trait_list',
					'definition'   => (object) [
						'atomic' => true,
						'items'  => [
							(object) [ 'name' => 'Attuned Taste', 'group' => 'Tremere', 'cost' => '1' ],
							(object) [ 'name' => 'Ambidextrous', 'group' => null, 'cost' => '1' ],
						],
					],
				],
			],
			[ 'vampire-merits' => $held ],
			[]
		);
		return $sections[0]['groups'];
	}

	public function test_the_only_group_prints_without_a_label(): void {
		$groups = $this->groups( [ [ 'name' => 'Ambidextrous', 'count' => 1 ] ] );

		$this->assertCount( 1, $groups );
		$this->assertNull( $groups[0]['label'] );
	}

	public function test_several_groups_each_print_their_label(): void {
		$groups = $this->groups( [ [ 'name' => 'Ambidextrous', 'count' => 1 ], [ 'name' => 'Attuned Taste', 'count' => 1 ] ] );

		$this->assertSame( [ 'Other', 'Tremere' ], array_column( $groups, 'label' ) );
	}
}
