<?php

namespace BeyondElysium\Tests\Unit;

use BeyondElysium\Database\Seeder;
use PHPUnit\Framework\TestCase;

/**
 * The NPC blocks: the roleplaying-notes block carries three agenda fields ahead of its eight notes fields, and the
 * npc-quick-stats block, which is not storyteller-only, backs the condensed npc_quick template.
 */
class SeederNpcTest extends TestCase {

	/** @var array<string,array> Built blocks, keyed by slug, loaded once. */
	private static $blocks;

	public static function setUpBeforeClass(): void {
		self::$blocks = [];
		foreach ( Seeder::get_blocks_to_seed() as $block ) {
			self::$blocks[ $block['slug'] ] = $block;
		}
	}

	private static function field_names( string $slug ): array {
		return array_column( self::$blocks[ $slug ]['definition']['fields'], 'name' );
	}

	// -------------------------------------------------------------------------
	// npc-roleplaying-notes: agenda fields prepended, original eight kept.
	// -------------------------------------------------------------------------

	public function test_roleplaying_notes_has_eleven_fields_agenda_first(): void {
		$this->assertSame(
			[
				'Wants',
				'Knows',
				'Will Do If Unopposed',
				'Voice & Tone',
				'Emotional Range',
				'Posture & Movement',
				'Public Behavior',
				'Private Behavior',
				'Combat Style',
				'Philosophy & Beliefs',
				'Theme Statement',
			],
			self::field_names( 'npc-roleplaying-notes' )
		);
	}

	public function test_roleplaying_notes_is_storyteller_only(): void {
		$this->assertSame( 1, self::$blocks['npc-roleplaying-notes']['storyteller_only'] );
	}

	public function test_roleplaying_notes_fields_are_all_optional_textareas(): void {
		foreach ( self::$blocks['npc-roleplaying-notes']['definition']['fields'] as $field ) {
			$this->assertSame( 'textarea', $field['field_type'], $field['name'] );
			$this->assertFalse( $field['required'], $field['name'] );
		}
	}

	// -------------------------------------------------------------------------
	// npc-quick-stats: a new, shared, non-storyteller-only block.
	// -------------------------------------------------------------------------

	public function test_quick_stats_block_exists_with_nine_fields(): void {
		$this->assertArrayHasKey( 'npc-quick-stats', self::$blocks );
		$this->assertCount( 9, self::$blocks['npc-quick-stats']['definition']['fields'] );
	}

	public function test_quick_stats_is_not_storyteller_only(): void {
		$this->assertArrayNotHasKey( 'storyteller_only', self::$blocks['npc-quick-stats'] );
	}

	public function test_quick_stats_field_names_and_types(): void {
		$fields = self::$blocks['npc-quick-stats']['definition']['fields'];
		$by_name = [];
		foreach ( $fields as $field ) {
			$by_name[ $field['name'] ] = $field['field_type'];
		}

		$this->assertSame( [
			'Physical'      => 'number',
			'Social'        => 'number',
			'Mental'        => 'number',
			'Willpower'     => 'number',
			'Health'        => 'text',
			'Key Abilities' => 'textarea',
			'Powers'        => 'textarea',
			'Equipment'     => 'textarea',
			'Notes'         => 'textarea',
		], $by_name );
	}

	public function test_quick_stats_is_an_identity_field_section(): void {
		$this->assertSame( 'identity_field', self::$blocks['npc-quick-stats']['section_type'] );
	}
}
