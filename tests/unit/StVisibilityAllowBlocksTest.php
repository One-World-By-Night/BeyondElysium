<?php

namespace BeyondElysium\Tests\Unit;

use BeyondElysium\Services\St_Visibility;
use PHPUnit\Framework\TestCase;

/**
 * The NPC casting brief's own carve-out (1.1.0 §3.8): `St_Visibility::filter_layout()`'s
 * `$allow_blocks` param and the new `filter_sheet_data_blocks()` helper both hide every
 * Storyteller-only block except the ones named, rather than every one unconditionally.
 * Pure array manipulation - no WordPress dependency, so this runs as a plain unit test
 * rather than needing a real seeded stack the way the full integration test does.
 *
 * @see BE_PROCESS/releases/1.1.0-design-workflow.md §3.8
 */
class StVisibilityAllowBlocksTest extends TestCase {

	private const HIDDEN = [ 'npc-roleplaying-notes', 'vampire-secret-plots' ];

	public function test_filter_layout_hides_every_storyteller_only_block_with_no_allow_list(): void {
		$layout = [
			'sections' => [
				[ 'block_slug' => 'vampire-identity' ],
				[ 'block_slug' => 'npc-roleplaying-notes' ],
				[ 'block_slug' => 'vampire-secret-plots' ],
			],
		];

		$filtered = St_Visibility::filter_layout( $layout, false, 'a-game', self::HIDDEN );

		$this->assertSame(
			[ 'vampire-identity' ],
			array_column( $filtered['sections'], 'block_slug' )
		);
	}

	public function test_filter_layout_keeps_an_allowed_block_but_still_hides_the_rest(): void {
		$layout = [
			'sections' => [
				[ 'block_slug' => 'vampire-identity' ],
				[ 'block_slug' => 'npc-roleplaying-notes' ],
				[ 'block_slug' => 'vampire-secret-plots' ],
			],
		];

		$filtered = St_Visibility::filter_layout( $layout, false, 'a-game', self::HIDDEN, [ 'npc-roleplaying-notes' ] );

		$this->assertSame(
			[ 'vampire-identity', 'npc-roleplaying-notes' ],
			array_column( $filtered['sections'], 'block_slug' )
		);
	}

	public function test_filter_layout_allow_blocks_is_ignored_for_a_manager(): void {
		$layout = [
			'sections' => [
				[ 'block_slug' => 'vampire-identity' ],
				[ 'block_slug' => 'npc-roleplaying-notes' ],
				[ 'block_slug' => 'vampire-secret-plots' ],
			],
		];

		// A manager already sees everything regardless of $hidden/$allow_blocks - can_manage
		// short-circuits before either list is even consulted.
		$filtered = St_Visibility::filter_layout( $layout, true, 'a-game', self::HIDDEN, [] );

		$this->assertSame(
			[ 'vampire-identity', 'npc-roleplaying-notes', 'vampire-secret-plots' ],
			array_column( $filtered['sections'], 'block_slug' )
		);
	}

	public function test_filter_sheet_data_blocks_strips_every_hidden_block_with_no_allow_list(): void {
		$sheet_data = [
			'vampire-identity'      => [ 'Clan' => 'Toreador' ],
			'npc-roleplaying-notes' => [ 'Wants' => 'Free the city' ],
			'vampire-secret-plots'  => [ 'Notes' => 'A real secret' ],
		];

		$filtered = St_Visibility::filter_sheet_data_blocks( $sheet_data, self::HIDDEN );

		$this->assertSame( [ 'vampire-identity' ], array_keys( $filtered ) );
	}

	public function test_filter_sheet_data_blocks_keeps_only_the_allowed_block(): void {
		$sheet_data = [
			'vampire-identity'      => [ 'Clan' => 'Toreador' ],
			'npc-roleplaying-notes' => [ 'Wants' => 'Free the city' ],
			'vampire-secret-plots'  => [ 'Notes' => 'A real secret' ],
		];

		$filtered = St_Visibility::filter_sheet_data_blocks( $sheet_data, self::HIDDEN, [ 'npc-roleplaying-notes' ] );

		$this->assertSame(
			[ 'vampire-identity', 'npc-roleplaying-notes' ],
			array_keys( $filtered )
		);
		$this->assertSame( [ 'Wants' => 'Free the city' ], $filtered['npc-roleplaying-notes'] );
	}
}
