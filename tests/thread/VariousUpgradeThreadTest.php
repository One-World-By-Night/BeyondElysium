<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Database\Schema;
use BeyondElysium\Models\Creature_Stack;
use BeyondElysium\Models\Schema_Block;
use BeyondElysium\Models\Template;
use BeyondElysium\Services\Template_Titles;
use WP_UnitTestCase;

/**
 * A Various sheet with a short section list gains every section the Various creature type declares, and its
 * free-text powers section is renamed.
 */
class VariousUpgradeThreadTest extends WP_UnitTestCase {

	/**
	 * A Various full sheet with the short section list: [ block slug => title ].
	 */
	private const OLD_SHEET = [
		'various-identity'        => 'Various Identity',
		'met-archetypes'          => 'Archetypes',
		'various-tempers'         => 'Various Tempers',
		'various-health'          => 'Various Health',
		'met-physical-traits'     => 'Physical Traits (Positive)',
		'met-social-traits'       => 'Social Traits (Positive)',
		'met-mental-traits'       => 'Mental Traits (Positive)',
		'met-physical-traits-neg' => 'Physical Traits (Negative)',
		'met-social-traits-neg'   => 'Social Traits (Negative)',
		'met-mental-traits-neg'   => 'Mental Traits (Negative)',
		'various-abilities'       => 'Various Abilities',
		'various-backgrounds'     => 'Various Backgrounds',
		'various-powers'          => 'Various Powers',
	];

	private function install_old_sheet( string $type ): object {
		$template = Template::globals( [ 'stack_slug' => 'various', 'template_type' => $type ] )[0];
		$sections = [];
		$order    = 1;
		foreach ( self::OLD_SHEET as $slug => $title ) {
			$sections[] = [
				'block_slug' => $slug, 'title' => $title, 'width' => 'half', 'column' => 1,
				'display' => null, 'collapsed' => false, 'order' => $order++,
			];
		}
		$layout             = $template->layout;
		$layout['sections'] = $sections;
		Template::update( (int) $template->id, [ 'layout' => $layout ] );
		return Template::find( (int) $template->id );
	}

	/**
	 * @return string[]
	 */
	private function declared(): array {
		$slugs = [];
		foreach ( Creature_Stack::find_by_slug( 'various' )->stack_definition->sections as $section ) {
			$slugs[] = (string) $section->block_slug;
			if ( ! empty( $section->negative_block_slug ) ) {
				$slugs[] = (string) $section->negative_block_slug;
			}
		}
		return $slugs;
	}

	public function test_an_old_various_sheet_gains_every_declared_section_once(): void {
		$template = $this->install_old_sheet( 'sheet_full' );

		Schema::complete_full_sheet_templates();

		$shown = array_column( Template::find( (int) $template->id )->layout['sections'], 'block_slug' );
		$this->assertSame( [], array_diff( $this->declared(), $shown ), 'a declared section is still missing' );
		$this->assertCount( count( $shown ), array_unique( $shown ), 'a section appears twice' );
		$this->assertSame( array_keys( self::OLD_SHEET ), array_slice( array_values( array_intersect( $shown, array_keys( self::OLD_SHEET ) ) ), 0, 13 ), 'the old sections keep their order' );
	}

	public function test_merits_flaws_and_derangements_land_right_after_backgrounds(): void {
		$template = $this->install_old_sheet( 'sheet_full' );

		Schema::complete_full_sheet_templates();

		$shown = array_column( Template::find( (int) $template->id )->layout['sections'], 'block_slug' );
		$at    = array_search( 'various-backgrounds', $shown, true );
		$this->assertSame( [ 'various-merits', 'various-flaws', 'met-derangements' ], array_slice( $shown, $at + 1, 3 ) );
	}

	public function test_an_old_various_npc_sheet_keeps_roleplaying_notes_last(): void {
		$template = $this->install_old_sheet( 'npc_full' );
		$layout   = $template->layout;
		$layout['sections'][] = [
			'block_slug' => 'npc-roleplaying-notes', 'title' => 'Roleplaying Notes', 'width' => 'full', 'column' => 1,
			'display' => null, 'collapsed' => false, 'order' => 14,
		];
		Template::update( (int) $template->id, [ 'layout' => $layout ] );

		Schema::complete_full_sheet_templates();

		$shown = array_column( Template::find( (int) $template->id )->layout['sections'], 'block_slug' );
		$this->assertSame( 'npc-roleplaying-notes', end( $shown ) );
		$this->assertContains( 'vampire-disciplines', $shown );
	}

	public function test_the_sections_an_old_layout_titled_with_various_take_the_plain_titles(): void {
		$template = $this->install_old_sheet( 'sheet_full' );

		Template_Titles::run();

		$titles = array_column( Template::find( (int) $template->id )->layout['sections'], 'title', 'block_slug' );
		$this->assertSame( 'Identity', $titles['various-identity'] );
		$this->assertSame( 'Tempers', $titles['various-tempers'] );
		$this->assertSame( 'Health', $titles['various-health'] );
		$this->assertSame( 'Abilities', $titles['various-abilities'] );
		$this->assertSame( 'Backgrounds', $titles['various-backgrounds'] );
		$this->assertSame( 'Other Powers', $titles['various-powers'] );
		$this->assertSame( 'Archetypes', $titles['met-archetypes'], 'a title that already reads as declared is left alone' );
	}

	public function test_the_free_text_powers_section_is_renamed_from_its_plain_old_title_too(): void {
		$template = $this->install_old_sheet( 'sheet_full' );
		$layout   = $template->layout;
		foreach ( $layout['sections'] as &$section ) {
			if ( $section['block_slug'] === 'various-powers' ) {
				$section['title'] = 'Powers';
			}
		}
		unset( $section );
		Template::update( (int) $template->id, [ 'layout' => $layout ] );

		Template_Titles::run();

		$titles = array_column( Template::find( (int) $template->id )->layout['sections'], 'title', 'block_slug' );
		$this->assertSame( 'Other Powers', $titles['various-powers'] );
	}

	public function test_a_chronicle_that_retitled_the_section_keeps_its_own_title(): void {
		$template = $this->install_old_sheet( 'sheet_full' );
		$layout   = $template->layout;
		foreach ( $layout['sections'] as &$section ) {
			if ( $section['block_slug'] === 'various-powers' ) {
				$section['title'] = 'Weird Stuff';
			}
		}
		unset( $section );
		Template::update( (int) $template->id, [ 'layout' => $layout ] );

		Template_Titles::run();

		$titles = array_column( Template::find( (int) $template->id )->layout['sections'], 'title', 'block_slug' );
		$this->assertSame( 'Weird Stuff', $titles['various-powers'] );
	}

	public function test_every_block_the_various_stack_declares_is_seeded(): void {
		foreach ( $this->declared() as $slug ) {
			$this->assertNotNull( Schema_Block::find_by_slug( $slug ), $slug );
		}
	}
}
