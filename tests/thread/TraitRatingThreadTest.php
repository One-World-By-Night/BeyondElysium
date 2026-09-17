<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Character;
use BeyondElysium\Models\Game;
use BeyondElysium\Services\Query_Engine;
use WP_UnitTestCase;

/**
 * `Query_Engine::trait_rating()` (1.1.0 §3.4 item 4) - a character's held count in a named
 * entry of a trait-list-shaped block, the primitive `Audience::can_see_entry()`'s rumor-level
 * gate is built on. Goes through `resolve_value()` like every other reader of a block's held
 * list, so this is a thread test (that path resolves a block's definition/fork through
 * `Schema_Block::find_for_game()`), not a pure unit test.
 *
 * @see BE_PROCESS/releases/1.1.0-design-workflow.md §3.4 item 4
 */
class TraitRatingThreadTest extends WP_UnitTestCase {

	private string $game_slug = 'thread-trait-rating';

	public function setUp(): void {
		parent::setUp();
		Game::create( [ 'slug' => $this->game_slug, 'name' => 'Thread Trait Rating' ] );
	}

	private function make_character( array $sheet_data ): object {
		$id = (int) Character::create( [
			'name'       => 'Trait Rating Character',
			'stack_slug' => 'vampire',
			'owner_slug' => $this->game_slug,
			'status'     => 'active',
			'created_by' => 1,
		] );
		if ( ! empty( $sheet_data ) ) {
			Character::update_sheet_data( $id, $sheet_data );
		}
		return Character::find( $id );
	}

	public function test_the_held_count_of_a_named_entry_is_returned(): void {
		$character = $this->make_character( [ 'met-abilities' => [ [ 'name' => 'Occult', 'count' => 4 ] ] ] );
		$this->assertSame( 4, Query_Engine::trait_rating( $character, 'abilities', 'Occult' ) );
	}

	public function test_the_match_is_case_insensitive(): void {
		$character = $this->make_character( [ 'met-abilities' => [ [ 'name' => 'Occult', 'count' => 4 ] ] ] );
		$this->assertSame( 4, Query_Engine::trait_rating( $character, 'abilities', 'OCCULT' ) );
	}

	public function test_an_entry_not_held_at_all_reads_zero(): void {
		$character = $this->make_character( [ 'met-abilities' => [ [ 'name' => 'Occult', 'count' => 4 ] ] ] );
		$this->assertSame( 0, Query_Engine::trait_rating( $character, 'abilities', 'Larceny' ) );
	}

	public function test_a_block_the_character_holds_nothing_in_reads_zero(): void {
		$character = $this->make_character( [] );
		$this->assertSame( 0, Query_Engine::trait_rating( $character, 'abilities', 'Occult' ) );
	}

	public function test_an_unrecognized_field_map_key_reads_zero(): void {
		$character = $this->make_character( [] );
		$this->assertSame( 0, Query_Engine::trait_rating( $character, 'no-such-field-anywhere', 'Occult' ) );
	}
}
