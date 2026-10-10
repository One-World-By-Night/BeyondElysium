<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Character;
use BeyondElysium\Models\Game;
use BeyondElysium\Services\Catalog_Cutover;
use BeyondElysium\Services\Catalog_Reader;
use BeyondElysium\Services\Field_Registry;
use BeyondElysium\Services\Query_Engine;
use WP_UnitTestCase;

/**
 * The Query Tool finds who holds a Bond, a Guanxi, a Passion, a Fetter, a Thorn, a P'o archetype, a Background or a
 * Health Level, each read from the block the character's own creature type keeps.
 */
class QueryListFieldsThreadTest extends WP_UnitTestCase {

	private string $game_slug = 'thread-query-list-fields';

	public function setUp(): void {
		parent::setUp();
		if ( ! Catalog_Reader::available() ) {
			$this->markTestSkipped( 'no declared catalog in this checkout' );
		}
		delete_option( Catalog_Cutover::OPTION );
		Game::create( [ 'slug' => $this->game_slug, 'name' => 'Thread Query List Fields' ] );

		$this->character( 'Query Vampire', 'vampire', [
			'vampire-bonds'       => [ [ 'name' => 'Gabriel Deveraux', 'count' => 7 ] ],
			'vampire-backgrounds' => [ [ 'name' => 'Allies', 'count' => 2 ], [ 'name' => 'Bureaucracy', 'count' => 3 ] ],
			'vampire-health'      => [ [ 'name' => 'Bruised', 'count' => 1 ] ],
		] );
		$this->character( 'Query Kuei-Jin', 'kueijin', [
			'kueijin-guanxi'    => [ [ 'name' => 'Old Master Chen', 'count' => 2 ] ],
			'kueijin-identity'  => [ "P'o Archetype" => 'Demon' ],
		] );
		$this->character( 'Query Wraith', 'wraith', [
			'wraith-passions' => [ [ 'name' => 'Tenderness', 'count' => 4 ] ],
			'wraith-fetters'  => [ [ 'name' => 'The Old Clock', 'count' => 3 ] ],
			'wraith-thorns'   => [ [ 'name' => 'Haunted by the Dead', 'count' => 1 ] ],
		] );
		// A Bête keeps Fera's Backgrounds and Werewolf's Health.
		$this->character( 'Query Bete', 'bete', [
			'fera-backgrounds' => [ [ 'name' => 'Allies', 'count' => 1 ], [ 'name' => 'Bureaucracy', 'count' => 2 ] ],
			'werewolf-health'  => [ [ 'name' => 'Bruised', 'count' => 1 ] ],
		] );
		$this->character( 'Query Mage', 'mage', [ 'mage-resources' => [] ] );
	}

	/** @param array<string,mixed> $sheet */
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

	public function test_bonds_guanxi_and_the_wraith_lists_find_their_holders(): void {
		$this->assertSame( [ 'Query Vampire' ], $this->matching( 'bonds', 'Gabriel Deveraux' ) );
		$this->assertSame( [ 'Query Kuei-Jin' ], $this->matching( 'guanxi', 'Old Master Chen' ) );
		$this->assertSame( [ 'Query Wraith' ], $this->matching( 'passions', 'Tenderness' ) );
		$this->assertSame( [ 'Query Wraith' ], $this->matching( 'fetters', 'The Old Clock' ) );
		$this->assertSame( [ 'Query Wraith' ], $this->matching( 'thorns', 'Haunted by the Dead' ) );
	}

	public function test_a_name_nobody_holds_finds_no_one(): void {
		$this->assertSame( [], $this->matching( 'bonds', 'Nobody Here' ) );
	}

	public function test_the_po_archetype_finds_the_kuei_jin_who_chose_it(): void {
		$this->assertSame( [ 'Query Kuei-Jin' ], $this->matching( 'poarchetype', 'Demon' ) );
		$this->assertSame( [], $this->matching( 'poarchetype', 'Judge' ) );
	}

	public function test_backgrounds_leave_out_influences_and_influences_leave_out_backgrounds(): void {
		// Allies is a Background; Bureaucracy is an Influence. A Bête holds Fera's block, a Vampire its own.
		$this->assertSame( [ 'Query Bete', 'Query Vampire' ], $this->matching( 'backgrounds', 'Allies' ) );
		$this->assertSame( [], $this->matching( 'backgrounds', 'Bureaucracy' ) );
		$this->assertSame( [ 'Query Bete', 'Query Vampire' ], $this->matching( 'influences', 'Bureaucracy' ) );
		$this->assertSame( [], $this->matching( 'influences', 'Allies' ) );
	}

	public function test_health_levels_read_the_whole_block_including_the_one_a_bete_shares(): void {
		$this->assertSame( [ 'Query Bete', 'Query Vampire' ], $this->matching( 'healthlevels', 'Bruised' ) );
	}

	public function test_a_character_without_the_block_matches_nothing_for_any_of_them(): void {
		foreach ( [ 'bonds', 'guanxi', 'passions', 'fetters', 'thorns', 'poarchetype', 'backgrounds', 'healthlevels' ] as $field ) {
			$this->assertNotContains( 'Query Mage', $this->matching( $field, 'Bruised' ), $field );
		}
	}

	public function test_every_one_of_them_is_offered_as_searchable(): void {
		foreach ( [ 'bonds', 'guanxi', 'passions', 'fetters', 'thorns', 'poarchetype', 'backgrounds', 'healthlevels' ] as $field ) {
			$this->assertTrue( Field_Registry::is_mapped( $field ), "{$field} is not mapped" );
		}
	}
}
