<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Character;
use BeyondElysium\Models\Game;
use BeyondElysium\Services\Query_Engine;
use WP_UnitTestCase;

/**
 * `Query_Engine::trait_rating()`.
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
		$character = $this->make_character( [ 'vampire-abilities' => [ [ 'name' => 'Occult', 'count' => 4 ] ] ] );
		$this->assertSame( 4, Query_Engine::trait_rating( $character, 'abilities', 'Occult' ) );
	}

	public function test_the_match_is_case_insensitive(): void {
		$character = $this->make_character( [ 'vampire-abilities' => [ [ 'name' => 'Occult', 'count' => 4 ] ] ] );
		$this->assertSame( 4, Query_Engine::trait_rating( $character, 'abilities', 'OCCULT' ) );
	}

	public function test_an_entry_not_held_at_all_reads_zero(): void {
		$character = $this->make_character( [ 'vampire-abilities' => [ [ 'name' => 'Occult', 'count' => 4 ] ] ] );
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
