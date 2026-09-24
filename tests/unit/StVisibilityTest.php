<?php

namespace BeyondElysium\Tests\Unit;

use BeyondElysium\Services\St_Visibility;
use PHPUnit\Framework\TestCase;

/**
 * `St_Visibility` in isolation, with no WordPress and no database.
 */
class StVisibilityTest extends TestCase {

	private function character(): object {
		return (object) [
			'rp_notes'   => 'a real storyteller note',
			'biography'  => 'Public text. [ST]Secret plotting.[/ST] More public text.',
			'notes'      => '[ST]Only the ST should see this.[/ST]',
			'sheet_data' => [
				'npc-roleplaying-notes' => [ 'motive' => 'wants revenge' ],
				'met-abilities'         => [ 'Academics' => 3 ],
			],
		];
	}

	private function layout(): array {
		return [
			'sections' => [
				[ 'block_slug' => 'met-abilities', 'title' => 'Abilities' ],
				[ 'block_slug' => 'npc-roleplaying-notes', 'title' => 'ST Notes' ],
			],
		];
	}

	public function test_filter_character_leaves_a_manager_completely_untouched(): void {
		$character = $this->character();
		$before    = clone $character;

		St_Visibility::filter_character( $character, null, true, [ 'npc-roleplaying-notes' ] );

		$this->assertEquals( $before, $character );
	}

	public function test_filter_character_unsets_rp_notes_for_a_non_manager(): void {
		$character = $this->character();

		St_Visibility::filter_character( $character, null, false, [] );

		$this->assertFalse( property_exists( $character, 'rp_notes' ) );
	}

	public function test_filter_character_strips_st_marked_biography_and_notes_for_a_non_manager(): void {
		$character = $this->character();

		St_Visibility::filter_character( $character, null, false, [] );

		$this->assertSame( 'Public text.  More public text.', $character->biography );
		$this->assertSame( '', $character->notes );
	}

	public function test_filter_character_removes_a_storyteller_only_blocks_values_from_sheet_data(): void {
		$character = $this->character();

		St_Visibility::filter_character( $character, null, false, [ 'npc-roleplaying-notes' ] );

		$this->assertArrayNotHasKey( 'npc-roleplaying-notes', $character->sheet_data );
		$this->assertArrayHasKey( 'met-abilities', $character->sheet_data );
	}

	public function test_filter_layout_leaves_a_manager_completely_untouched(): void {
		$layout = $this->layout();

		$result = St_Visibility::filter_layout( $layout, true, '', [ 'npc-roleplaying-notes' ] );

		$this->assertSame( $layout, $result );
	}

	public function test_filter_layout_removes_a_storyteller_only_section_for_a_non_manager(): void {
		$result = St_Visibility::filter_layout( $this->layout(), false, '', [ 'npc-roleplaying-notes' ] );

		$slugs = array_column( $result['sections'], 'block_slug' );
		$this->assertSame( [ 'met-abilities' ], $slugs );
	}

	public function test_filter_layout_is_a_no_op_when_nothing_is_hidden(): void {
		$layout = $this->layout();

		$result = St_Visibility::filter_layout( $layout, false, '', [] );

		$this->assertSame( $layout, $result );
	}

	public function test_filtering_only_the_layout_does_not_protect_sheet_data(): void {
		$character = $this->character();
		$hidden    = [ 'npc-roleplaying-notes' ];

		St_Visibility::filter_layout( $this->layout(), false, '', $hidden );

		$this->assertArrayHasKey( 'npc-roleplaying-notes', $character->sheet_data );
	}

	/**
	 * Applying both operations with the same hidden-slug list closes the leak from both directions at once.
	 */
	public function test_applying_both_operations_together_closes_the_leak_from_both_directions(): void {
		$character = $this->character();
		$hidden    = [ 'npc-roleplaying-notes' ];

		St_Visibility::filter_character( $character, null, false, $hidden );
		$layout = St_Visibility::filter_layout( $this->layout(), false, '', $hidden );

		$this->assertArrayNotHasKey( 'npc-roleplaying-notes', $character->sheet_data );
		$this->assertNotContains( 'npc-roleplaying-notes', array_column( $layout['sections'], 'block_slug' ) );
	}
}
