<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Character;
use BeyondElysium\Models\Game;
use BeyondElysium\Services\Catalog_Cutover;
use BeyondElysium\Services\Catalog_Reader;
use BeyondElysium\Services\Query_Engine;
use WP_UnitTestCase;

/**
 * The Query Tool's Abilities, Merits, Flaws and Rites fields read the block the character's creature type keeps:
 * `vampire-abilities` for a Vampire, `fera-abilities` for a Fera and a Bete, `werewolf-rites` for a Werewolf and
 * `fera-rites` for a Fera and a Bete.
 */
class QueryOwnListsThreadTest extends WP_UnitTestCase {

	private string $game_slug = 'thread-query-own-lists';

	public function setUp(): void {
		parent::setUp();
		if ( ! Catalog_Reader::available() ) {
			$this->markTestSkipped( 'no declared catalog in this checkout' );
		}
		// No install state is set.
		delete_option( Catalog_Cutover::OPTION );
		Game::create( [ 'slug' => $this->game_slug, 'name' => 'Thread Query Own Lists' ] );
	}

	public function tearDown(): void {
		parent::tearDown();
	}

	/** @param array<string,array<int,array<string,mixed>>> $sheet */
	private function character( string $name, string $stack, array $sheet ): void {
		Character::create( [
			'name' => $name, 'stack_slug' => $stack, 'owner_type' => 'chronicle', 'owner_slug' => $this->game_slug,
			'status' => 'active', 'sheet_data' => $sheet,
		] );
	}

	/** @return string[] The names of the characters the query matched. */
	private function matching( string $field, string $find ): array {
		$result = Query_Engine::execute(
			$this->game_slug,
			[ [ 'field' => $field, 'operator' => 'contains', 'find' => $find, 'value' => $find ] ],
			'AND'
		);
		$names = array_map( static fn( $row ) => $row->name, $result['results'] );
		sort( $names );
		return $names;
	}

	public function test_abilities_merits_and_flaws_read_each_creature_types_own_list(): void {
		$this->character( 'Query Vampire', 'vampire', [
			'vampire-abilities' => [ [ 'name' => 'Occult', 'count' => 3 ] ],
			'vampire-merits'    => [ [ 'name' => 'Iron Will', 'count' => 1 ] ],
			'vampire-flaws'     => [ [ 'name' => 'Nightmares', 'count' => 1 ] ],
		] );
		$this->character( 'Query Bete', 'bete', [
			'fera-abilities' => [ [ 'name' => 'Occult', 'count' => 2 ] ],
			'fera-merits'    => [ [ 'name' => 'Iron Will', 'count' => 1 ] ],
			'fera-flaws'     => [ [ 'name' => 'Nightmares', 'count' => 1 ] ],
		] );
		$this->character( 'Query Werewolf', 'werewolf', [
			'werewolf-abilities' => [ [ 'name' => 'Occult', 'count' => 1 ] ],
		] );

		$this->assertSame( [ 'Query Bete', 'Query Vampire', 'Query Werewolf' ], $this->matching( 'abilities', 'Occult' ) );
		$this->assertSame( [ 'Query Bete', 'Query Vampire' ], $this->matching( 'merits', 'Iron Will' ) );
		$this->assertSame( [ 'Query Bete', 'Query Vampire' ], $this->matching( 'flaws', 'Nightmares' ) );
	}

	public function test_a_character_holding_only_the_retired_shared_list_matches_nothing(): void {
		// A shared block is not read.
		$this->character( 'Query Stale Vampire', 'vampire', [ 'met-abilities' => [ [ 'name' => 'Occult', 'count' => 3 ] ] ] );

		$this->assertSame( [], $this->matching( 'abilities', 'Occult' ) );
	}

	public function test_rites_read_werewolfs_own_list_and_the_one_fera_and_bete_moved_to(): void {
		$this->character( 'Query Garou', 'werewolf', [ 'werewolf-rites' => [ [ 'name' => 'Rite of Passage', 'count' => 1 ] ] ] );
		$this->character( 'Query Fera', 'fera', [ 'fera-rites' => [ [ 'name' => 'Rite of Passage', 'count' => 1 ] ] ] );
		$this->character( 'Query Bete Rites', 'bete', [ 'fera-rites' => [ [ 'name' => 'Rite of Passage', 'count' => 1 ] ] ] );

		$this->assertSame( [ 'Query Bete Rites', 'Query Fera', 'Query Garou' ], $this->matching( 'rites', 'Rite of Passage' ) );
	}
}
