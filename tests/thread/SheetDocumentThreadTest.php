<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Database\Manager;
use BeyondElysium\Models\Change;
use BeyondElysium\Models\Character;
use BeyondElysium\Models\Creature_Stack;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Schema_Block;
use BeyondElysium\Models\Template;
use BeyondElysium\Services\Display\Temper_Display;
use BeyondElysium\Services\Sheet_Document;
use WP_UnitTestCase;

/**
 * `Sheet_Document::for_characters()` against a real seeded character, stack, and template.
 */
class SheetDocumentThreadTest extends WP_UnitTestCase {

	private $manager_id;
	private $character_id;

	public function setUp(): void {
		parent::setUp();

		$this->manager_id = self::factory()->user->create( [ 'role' => 'administrator' ] );

		Game::create( [
			'slug'       => 'sheetdoc-test',
			'name'       => 'Sheet Document Test',
			'created_by' => $this->manager_id,
		] );

		Schema_Block::create( [
			'slug'         => 'sheetdoc-abilities',
			'name'         => 'Abilities',
			'section_type' => 'trait_list',
			'definition'   => [ 'items' => [ [ 'name' => 'Occult' ], [ 'name' => 'Larceny' ] ], 'display' => 'multiplier' ],
			'is_system'    => 0,
		] );

		Schema_Block::create( [
			'slug'         => 'sheetdoc-disciplines',
			'name'         => 'Disciplines',
			'section_type' => 'tiered_power',
			'definition'   => [ 'powers' => [ [ 'name' => 'Celerity', 'levels' => [
				[ 'level' => 1, 'power_name' => 'Alacrity' ],
				[ 'level' => 2, 'power_name' => 'Swiftness' ],
			] ] ] ],
			'is_system'    => 0,
		] );

		Schema_Block::create( [
			'slug'         => 'sheetdoc-resources',
			'name'         => 'Resources',
			'section_type' => 'resource_pool',
			'definition'   => [ 'pools' => [ [ 'name' => 'Blood', 'default_start' => 10 ] ] ],
			'is_system'    => 0,
		] );

		Schema_Block::create( [
			'slug'         => 'sheetdoc-identity',
			'name'         => 'Identity',
			'section_type' => 'identity_field',
			'definition'   => [ 'fields' => [ [ 'name' => 'Clan', 'field_type' => 'text', 'required' => false ] ] ],
			'is_system'    => 0,
		] );

		Schema_Block::create( [
			'slug'             => 'sheetdoc-secret',
			'name'             => 'Secret',
			'section_type'     => 'identity_field',
			'definition'       => [ 'fields' => [ [ 'name' => 'Hook', 'field_type' => 'textarea', 'required' => false ] ] ],
			'is_system'        => 0,
			'storyteller_only' => 1,
		] );

		Schema_Block::create( [
			'slug'         => 'sheetdoc-mystery',
			'name'         => 'Mystery',
			'section_type' => 'identity_field',
			'definition'   => [ 'fields' => [] ],
			'is_system'    => 0,
		] );
		Manager::update( 'schema_blocks', [ 'section_type' => 'legacy_type' ], [ 'slug' => 'sheetdoc-mystery' ] );

		// A dedicated stack, not the real 'vampire' one.
		Creature_Stack::create( [
			'slug'             => 'sheetdoc-stack',
			'name'             => 'Sheetdoc Test Stack',
			'stack_definition' => [ 'sections' => [
				[ 'block_slug' => 'sheetdoc-abilities' ],
				[ 'block_slug' => 'sheetdoc-disciplines' ],
				[ 'block_slug' => 'sheetdoc-resources' ],
				[ 'block_slug' => 'sheetdoc-identity' ],
				[ 'block_slug' => 'sheetdoc-secret' ],
				[ 'block_slug' => 'sheetdoc-mystery' ],
			] ],
			'is_system'        => 0,
			'created_by'       => $this->manager_id,
		] );

		Template::create( [
			'stack_slug'    => 'sheetdoc-stack',
			'name'          => 'Sheet Document Test Layout',
			'template_type' => 'sheet_full',
			'layout'        => [
				'version'  => 1,
				'columns'  => 3,
				'sections' => [
					[ 'block_slug' => 'sheetdoc-mystery', 'column' => 3, 'order' => 1, 'title' => 'Mystery', 'display' => null, 'collapsed' => false ],
					[ 'block_slug' => 'sheetdoc-resources', 'column' => 1, 'order' => 3, 'title' => 'Resources', 'display' => null, 'collapsed' => false ],
					[ 'block_slug' => 'sheetdoc-disciplines', 'column' => 2, 'order' => 1, 'title' => 'Disciplines', 'display' => null, 'collapsed' => false ],
					[ 'block_slug' => 'sheetdoc-secret', 'column' => 1, 'order' => 2, 'title' => 'Secret', 'display' => null, 'collapsed' => false ],
					[ 'block_slug' => 'sheetdoc-identity', 'column' => 1, 'order' => 1, 'title' => 'Identity', 'display' => null, 'collapsed' => false ],
					[ 'block_slug' => 'sheetdoc-abilities', 'column' => 2, 'order' => 2, 'title' => 'Abilities', 'display' => null, 'collapsed' => false ],
				],
			],
			'is_system'     => 0,
			'created_by'    => $this->manager_id,
		] );

		$this->character_id = Character::create( [
			'name'       => 'Test Character',
			'owner_slug' => 'sheetdoc-test',
			'stack_slug' => 'sheetdoc-stack',
			'status'     => 'active',
			'sheet_data' => [
				'sheetdoc-abilities'   => [ [ 'name' => 'Occult', 'count' => 3 ], [ 'name' => 'Larceny', 'count' => 1 ] ],
				'sheetdoc-disciplines' => [ [ 'name' => 'Celerity', 'level' => 2 ] ],
				'sheetdoc-resources'   => [ 'Blood' => [ 'permanent' => 10, 'temporary' => 7 ] ],
				'sheetdoc-identity'    => [ 'Clan' => 'Tremere' ],
				'sheetdoc-secret'      => [ 'Hook' => 'Working for the Sabbat' ],
				'sheetdoc-mystery'     => [ 'Whatever' => 'value' ],
			],
			'created_by' => $this->manager_id,
		] );

		Change::create( [
			'character_id' => $this->character_id,
			'change_type'  => 'xp_earn',
			'change_data'  => [ 'amount' => 5, 'reason' => 'Game attendance' ],
			'status'       => 'approved',
		] );
	}

	private function document( array $options ): array {
		$documents = Sheet_Document::for_characters( [ $this->character_id ], 'sheetdoc-test', $options );
		$this->assertCount( 1, $documents );
		return $documents[0];
	}

	public function test_sections_are_returned_in_column_then_order_flow_sequence(): void {
		$document = $this->document( [ 'can_manage' => true ] );

		$this->assertSame(
			[ 'sheetdoc-identity', 'sheetdoc-secret', 'sheetdoc-resources', 'sheetdoc-disciplines', 'sheetdoc-abilities', 'sheetdoc-mystery' ],
			array_column( $document['sections'], 'block_slug' )
		);
	}

	public function test_every_row_is_an_already_final_string(): void {
		$document = $this->document( [ 'can_manage' => true ] );
		$sections = array_column( $document['sections'], null, 'block_slug' );

		$this->assertSame( [ [ 'label' => null, 'rows' => [ 'Occult x3', 'Larceny' ] ] ], $sections['sheetdoc-abilities']['groups'] );
		$this->assertSame( [ 'Celerity 2' ], $sections['sheetdoc-disciplines']['rows'] );
		$this->assertSame( [ 'Clan: Tremere' ], $sections['sheetdoc-identity']['rows'] );
		$this->assertSame(
			[ 'Blood: ' . Temper_Display::display( 10, 7 ) ],
			$sections['sheetdoc-resources']['rows']
		);

		foreach ( array_merge( $sections['sheetdoc-abilities']['groups'][0]['rows'], $sections['sheetdoc-disciplines']['rows'] ) as $row ) {
			$this->assertIsString( $row );
		}
	}

	public function test_full_power_names_option_expands_named_rungs(): void {
		$document = $this->document( [ 'can_manage' => true, 'full_power_names' => true ] );
		$sections = array_column( $document['sections'], null, 'block_slug' );

		$this->assertSame( [ 'Alacrity, Swiftness' ], $sections['sheetdoc-disciplines']['rows'] );
	}

	public function test_a_manager_sees_the_storyteller_only_section_and_its_value(): void {
		$document = $this->document( [ 'can_manage' => true ] );
		$sections = array_column( $document['sections'], null, 'block_slug' );

		$this->assertArrayHasKey( 'sheetdoc-secret', $sections );
		$this->assertSame( [ 'Hook: Working for the Sabbat' ], $sections['sheetdoc-secret']['rows'] );
	}

	/**
	 * `Hook` is a `textarea` identity field.
	 */
	public function test_a_rich_text_identity_field_is_flattened_not_printed_as_tags(): void {
		Character::update_sheet_data( $this->character_id, [
			'sheetdoc-secret' => [
				'Hook' => '<p>Working for the Sabbat.</p><p>Reports to <strong>Vykos</strong>.</p>',
			],
		] );

		$document = $this->document( [ 'can_manage' => true ] );
		$sections = array_column( $document['sections'], null, 'block_slug' );
		$row      = $sections['sheetdoc-secret']['rows'][0];

		$this->assertStringNotContainsString( '<p>', $row, 'A signed sheet must not print tags.' );
		$this->assertStringNotContainsString( '<strong>', $row );
		$this->assertStringContainsString( 'Working for the Sabbat.', $row );
		$this->assertStringContainsString( 'Vykos', $row );
		$this->assertStringContainsString( "\n", $row, 'Two paragraphs must not run together.' );
	}

	public function test_a_non_manager_never_receives_the_storyteller_only_section(): void {
		$document = $this->document( [ 'can_manage' => false ] );
		$slugs    = array_column( $document['sections'], 'block_slug' );

		$this->assertNotContains( 'sheetdoc-secret', $slugs, 'the section itself must be absent, not merely empty' );
		$this->assertContains( 'sheetdoc-identity', $slugs, 'an ordinary section is untouched' );
	}

	public function test_an_unrecognized_section_type_is_surfaced_not_dropped(): void {
		$document = $this->document( [ 'can_manage' => true ] );
		$sections = array_column( $document['sections'], null, 'block_slug' );

		$this->assertArrayHasKey( 'sheetdoc-mystery', $sections, 'an unrecognized section_type must still appear in the list' );
		$this->assertSame( 'legacy_type', $sections['sheetdoc-mystery']['section_type'] );
		$this->assertArrayNotHasKey( 'rows', $sections['sheetdoc-mystery'] );
		$this->assertArrayNotHasKey( 'groups', $sections['sheetdoc-mystery'] );
	}

	public function test_header_portrait_and_provenance(): void {
		$document = $this->document( [ 'can_manage' => true ] );

		$this->assertSame( 'Test Character', $document['title'] );
		$this->assertContains( [ 'Name', 'Test Character' ], $document['header'] );
		$this->assertContains( [ 'Type', 'Sheetdoc Test Stack' ], $document['header'] );
		$this->assertNull( $document['portrait_path'], 'no image was ever attached to this character' );

		$character = Character::find( $this->character_id );
		$this->assertSame( $character->uuid, $document['provenance_lines'][0] );
		$this->assertStringContainsString( 'sheetdoc-test', $document['provenance_lines'][1] );
	}

	public function test_prose_and_xp_history_are_gated_by_options(): void {
		$without = $this->document( [ 'can_manage' => true ] );
		$this->assertSame( [], $without['prose'] );
		$this->assertSame( [], $without['xp_history'] );

		$with = $this->document( [ 'can_manage' => true, 'background' => true, 'xp_history' => true ] );
		$this->assertSame( [ [ 'Background', '' ] ], $with['prose'] );
		$this->assertCount( 1, $with['xp_history'] );
		$this->assertSame( '+5 XP (Game attendance)', $with['xp_history'][0][1] );
		$this->assertSame( '+5', $with['xp_history'][0][2] );
	}

	public function test_an_unknown_character_id_is_silently_skipped(): void {
		$documents = Sheet_Document::for_characters( [ $this->character_id, 999999 ], 'sheetdoc-test', [ 'can_manage' => true ] );

		$this->assertCount( 1, $documents );
	}
}
