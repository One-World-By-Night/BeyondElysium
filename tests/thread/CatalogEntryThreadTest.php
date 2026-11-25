<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Schema_Block;
use BeyondElysium\Services\Catalog_Entry;
use BeyondElysium\Services\Cost_Engine;
use WP_UnitTestCase;

/**
 * An entry added to a chronicle's own copy of a catalog block: a trait-list item, a power family on a ladder, and a pick
 * under a family.
 */
class CatalogEntryThreadTest extends WP_UnitTestCase {

	private string $game = 'thread-test-catalog-entry';
	private string $other = 'thread-test-catalog-entry-other';

	public function setUp(): void {
		parent::setUp();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
	}

	/** @return array<string,mixed> */
	private function copy( string $slug, string $game ): array {
		$copy = Schema_Block::copy_for_game( $slug, $game );
		$this->assertNotNull( $copy, "{$game} has a copy of {$slug}" );
		return $copy;
	}

	private function family( string $slug, string $name, ?string $game = null ): ?array {
		$definition = $this->copy( $slug, $game ?? $this->game )['definition'];
		foreach ( $definition['powers'] ?? [] as $power ) {
			if ( strcasecmp( (string) $power['name'], $name ) === 0 ) {
				return $power;
			}
		}
		return null;
	}

	public function test_an_item_joins_only_the_chronicles_own_copy(): void {
		$before = count( Schema_Block::find_by_slug( 'vampire-rituals' )->definition->items );

		$this->assertSame( Catalog_Entry::ADDED, Catalog_Entry::add_item( 'vampire-rituals', $this->game, 'Ward of Ash', 2, 'Necromancy' ) );

		$items = $this->copy( 'vampire-rituals', $this->game )['definition']['items'];
		$added = array_values( array_filter( $items, static fn( $item ) => $item['name'] === 'Ward of Ash' ) );
		$this->assertEquals( [ [ 'name' => 'Ward of Ash', 'cost' => '2', 'group' => 'Necromancy' ] ], $added );
		$this->assertSame( $before, count( Schema_Block::find_by_slug( 'vampire-rituals' )->definition->items ), 'the book block is untouched' );
		$this->assertNotEmpty( $this->copy( 'vampire-rituals', $this->game )['changes'], 'the chronicle records the addition as its own change' );
	}

	public function test_an_item_with_no_price_or_group_is_a_name_alone(): void {
		Catalog_Entry::add_item( 'vampire-rituals', $this->game, 'Plain Rite' );

		$items = $this->copy( 'vampire-rituals', $this->game )['definition']['items'];
		$added = array_values( array_filter( $items, static fn( $item ) => $item['name'] === 'Plain Rite' ) );
		$this->assertEquals( [ [ 'name' => 'Plain Rite' ] ], $added );
	}

	public function test_an_item_already_there_is_left_alone_whatever_its_case(): void {
		Catalog_Entry::add_item( 'vampire-rituals', $this->game, 'Ward of Ash', 2 );

		$this->assertSame( Catalog_Entry::PRESENT, Catalog_Entry::add_item( 'vampire-rituals', $this->game, 'WARD OF ASH', 5 ) );

		$items = $this->copy( 'vampire-rituals', $this->game )['definition']['items'];
		$named = array_values( array_filter( $items, static fn( $item ) => strcasecmp( $item['name'], 'Ward of Ash' ) === 0 ) );
		$this->assertCount( 1, $named );
		$this->assertSame( '2', $named[0]['cost'], 'the first price stands' );
	}

	public function test_another_chronicle_does_not_see_the_item(): void {
		Catalog_Entry::add_item( 'vampire-rituals', $this->game, 'Ward of Ash', 2 );

		$this->assertNull( Schema_Block::copy_for_game( 'vampire-rituals', $this->other ) );
		$names = array_column( (array) Schema_Block::find_for_game( 'vampire-rituals', $this->other )->definition->items, 'name' );
		$this->assertNotContains( 'Ward of Ash', $names );
	}

	public function test_an_item_is_refused_on_a_power_list(): void {
		$this->assertSame( Catalog_Entry::FAILED, Catalog_Entry::add_item( 'vampire-disciplines', $this->game, 'Hollow Vein' ) );
	}

	public function test_a_family_on_a_ladder_gets_every_rung_at_the_sections_costs(): void {
		$this->assertSame( Catalog_Entry::ADDED, Catalog_Entry::add_family( 'vampire-disciplines', $this->game, 'Hollow Vein', 2, 'Ward of Ash' ) );

		$family = $this->family( 'vampire-disciplines', 'Hollow Vein' );
		$this->assertNotNull( $family );
		$this->assertEquals(
			[
				[ 'cost' => '3', 'tier' => 'basic', 'level' => 1, 'power_name' => '' ],
				[ 'cost' => '3', 'tier' => 'basic', 'level' => 2, 'power_name' => 'Ward of Ash' ],
				[ 'cost' => '6', 'tier' => 'intermediate', 'level' => 3, 'power_name' => '' ],
				[ 'cost' => '6', 'tier' => 'intermediate', 'level' => 4, 'power_name' => '' ],
				[ 'cost' => '9', 'tier' => 'advanced', 'level' => 5, 'power_name' => '' ],
			],
			$family['levels']
		);
		$this->assertNull( $this->family_in_book( 'vampire-disciplines', 'Hollow Vein' ), 'the book block is untouched' );
	}

	public function test_a_family_follows_its_own_sections_ladder_and_costs(): void {
		$book   = Schema_Block::find_by_slug( 'wraith-arcanoi' )->definition;
		$ladder = (array) $book->_meta->ladder;
		$costs  = (array) $book->_meta->costs;

		$this->assertSame( Catalog_Entry::ADDED, Catalog_Entry::add_family( 'wraith-arcanoi', $this->game, 'Hollow Song', 1, 'Echo' ) );

		$family = $this->family( 'wraith-arcanoi', 'Hollow Song' );
		$this->assertCount( array_sum( $ladder ), $family['levels'] );
		foreach ( $family['levels'] as $row ) {
			$this->assertSame( (string) $costs[ $row['tier'] ], $row['cost'] );
			$this->assertSame( Cost_Engine::tier_for_rank( $book, $row['level'] ), $row['tier'] );
		}
		$this->assertSame( 'Echo', $family['levels'][0]['power_name'] );
	}

	public function test_the_import_entry_point_gives_an_arcanos_its_own_sections_ladder(): void {
		$book  = Schema_Block::find_by_slug( 'wraith-arcanoi' )->definition;
		$costs = (array) $book->_meta->costs;

		$this->assertSame( Catalog_Entry::ADDED, Catalog_Entry::add_family_at_tier( 'wraith-arcanoi', $this->game, 'Hollow Song', 'advanced', 'Final Echo' ) );

		$family = $this->family( 'wraith-arcanoi', 'Hollow Song' );
		$this->assertCount( array_sum( (array) $book->_meta->ladder ), $family['levels'] );
		$named = array_values( array_filter( $family['levels'], static fn( $row ) => $row['power_name'] === 'Final Echo' ) );
		$this->assertCount( 1, $named );
		$this->assertSame( 'advanced', $named[0]['tier'] );
		$this->assertSame( (string) $costs['advanced'], $named[0]['cost'] );
	}

	public function test_a_family_already_there_only_gets_an_unnamed_level_named(): void {
		Catalog_Entry::add_family( 'vampire-disciplines', $this->game, 'Hollow Vein', 2, 'Ward of Ash' );

		$this->assertSame( Catalog_Entry::ADDED, Catalog_Entry::add_family( 'vampire-disciplines', $this->game, 'hollow vein', 4, 'Gate of Bone' ) );
		$this->assertSame( Catalog_Entry::PRESENT, Catalog_Entry::add_family( 'vampire-disciplines', $this->game, 'Hollow Vein', 2, 'Something Else' ) );

		$names = array_column( $this->family( 'vampire-disciplines', 'Hollow Vein' )['levels'], 'power_name', 'level' );
		$this->assertSame( [ 1 => '', 2 => 'Ward of Ash', 3 => '', 4 => 'Gate of Bone', 5 => '' ], $names );
	}

	public function test_a_family_can_be_added_at_the_first_open_rung_of_a_tier(): void {
		$this->assertSame( Catalog_Entry::ADDED, Catalog_Entry::add_family_at_tier( 'vampire-disciplines', $this->game, 'Hollow Vein', 'intermediate', 'Ward of Ash' ) );
		$this->assertSame( Catalog_Entry::ADDED, Catalog_Entry::add_family_at_tier( 'vampire-disciplines', $this->game, 'Hollow Vein', 'intermediate', 'Gate of Bone' ) );

		$names = array_column( $this->family( 'vampire-disciplines', 'Hollow Vein' )['levels'], 'power_name', 'level' );
		$this->assertSame( [ 1 => '', 2 => '', 3 => 'Ward of Ash', 4 => 'Gate of Bone', 5 => '' ], $names );
	}

	public function test_a_tier_with_no_open_rung_or_none_in_the_ladder_is_refused(): void {
		Catalog_Entry::add_family_at_tier( 'vampire-disciplines', $this->game, 'Hollow Vein', 'advanced', 'Only Rung' );

		$this->assertSame( Catalog_Entry::FAILED, Catalog_Entry::add_family_at_tier( 'vampire-disciplines', $this->game, 'Hollow Vein', 'advanced', 'No Room' ) );
		$this->assertSame( Catalog_Entry::FAILED, Catalog_Entry::add_family_at_tier( 'vampire-disciplines', $this->game, 'Hollow Vein', 'elder', 'Past The Ladder' ) );
	}

	public function test_a_level_beyond_the_ladder_is_refused(): void {
		$this->assertSame( Catalog_Entry::FAILED, Catalog_Entry::add_family( 'vampire-disciplines', $this->game, 'Hollow Vein', 6, 'Too High' ) );
		$this->assertSame( Catalog_Entry::FAILED, Catalog_Entry::add_family( 'vampire-disciplines', $this->game, 'Hollow Vein', 0, 'Too Low' ) );
		$this->assertNull( Schema_Block::copy_for_game( 'vampire-disciplines', $this->game ), 'a refused add leaves no copy of the block behind' );
	}

	public function test_a_family_is_refused_where_there_is_no_ladder(): void {
		$this->assertSame( Catalog_Entry::FAILED, Catalog_Entry::add_family( 'werewolf-gifts', $this->game, 'Hollow Vein', 1, 'Nope' ) );
		$this->assertSame( Catalog_Entry::FAILED, Catalog_Entry::add_family( 'vampire-rituals', $this->game, 'Hollow Vein', 1, 'Nope' ) );
	}

	public function test_a_pick_joins_an_existing_family_at_its_rank_and_cost(): void {
		$this->assertSame( Catalog_Entry::ADDED, Catalog_Entry::add_pick( 'werewolf-gifts', $this->game, 'Philodox', 'Oath of Silence', 'intermediate' ) );

		$picks = $this->family( 'werewolf-gifts', 'Philodox' )['elder']['intermediate'];
		$this->assertEquals(
			[ 'cost' => '6', 'tier' => 'intermediate', 'level' => null, 'source' => 'Philodox', 'power_name' => 'Oath of Silence' ],
			end( $picks )
		);
	}

	public function test_a_pick_for_a_new_family_creates_it(): void {
		$this->assertSame( Catalog_Entry::ADDED, Catalog_Entry::add_pick( 'werewolf-gifts', $this->game, 'Moon Keepers', 'First Howl', 'basic' ) );

		$family = $this->family( 'werewolf-gifts', 'Moon Keepers' );
		$this->assertSame( [], $family['levels'] );
		$this->assertEquals( [ [ 'cost' => '3', 'tier' => 'basic', 'level' => null, 'source' => 'Moon Keepers', 'power_name' => 'First Howl' ] ], $family['elder']['basic'] );
		$this->assertArrayNotHasKey( 'category_values', $family, 'a new family is out of type for everyone until an HST says otherwise' );
	}

	public function test_a_pick_already_in_the_family_adds_nothing(): void {
		Catalog_Entry::add_pick( 'werewolf-gifts', $this->game, 'Moon Keepers', 'First Howl', 'basic' );

		$this->assertSame( Catalog_Entry::PRESENT, Catalog_Entry::add_pick( 'werewolf-gifts', $this->game, 'Moon Keepers', 'first howl', 'advanced' ) );
		$this->assertCount( 1, $this->family( 'werewolf-gifts', 'Moon Keepers' )['elder']['basic'] );
		$this->assertArrayNotHasKey( 'advanced', $this->family( 'werewolf-gifts', 'Moon Keepers' )['elder'] );
	}

	public function test_a_pick_needs_one_of_the_sections_own_ranks_and_no_ladder(): void {
		$this->assertSame( Catalog_Entry::FAILED, Catalog_Entry::add_pick( 'werewolf-gifts', $this->game, 'Philodox', 'Bad Rank', 'elder' ) );
		$this->assertSame( Catalog_Entry::FAILED, Catalog_Entry::add_pick( 'vampire-disciplines', $this->game, 'Hollow Vein', 'Too Far', 'elder' ) );
		$this->assertSame( Catalog_Entry::FAILED, Catalog_Entry::add_pick( 'vampire-rituals', $this->game, 'Hollow Vein', 'Not Powers', 'basic' ) );
	}

	public function test_what_kind_of_entry_a_section_takes(): void {
		$this->assertSame( Catalog_Entry::ITEM, Catalog_Entry::kind( Schema_Block::find_by_slug( 'vampire-rituals' ) ) );
		$this->assertSame( Catalog_Entry::FAMILY, Catalog_Entry::kind( Schema_Block::find_by_slug( 'vampire-disciplines' ) ) );
		$this->assertSame( Catalog_Entry::FAMILY, Catalog_Entry::kind( Schema_Block::find_by_slug( 'wraith-arcanoi' ) ) );
		$this->assertSame( Catalog_Entry::PICK, Catalog_Entry::kind( Schema_Block::find_by_slug( 'werewolf-gifts' ) ) );
		$this->assertNull( Catalog_Entry::kind( Schema_Block::find_by_slug( 'vampire-resources' ) ), 'a pool takes no entry' );
		$this->assertNull( Catalog_Entry::kind( null ) );
	}

	public function test_an_unpriced_list_and_a_spent_from_block_take_no_entry(): void {
		Schema_Block::create( [ 'slug' => 'ce-test-unpriced', 'name' => 'Unpriced', 'section_type' => 'trait_list', 'definition' => [ 'items' => [], 'unpriced' => true ], 'is_system' => 0 ] );
		Schema_Block::create( [ 'slug' => 'ce-test-spent', 'name' => 'Spent', 'section_type' => 'tiered_power', 'definition' => [ 'powers' => [], '_meta' => [ 'spent_from' => 'pool', 'ladder' => [ 'basic' => 1 ] ] ], 'is_system' => 0 ] );

		$this->assertNull( Catalog_Entry::kind( Schema_Block::find_by_slug( 'ce-test-unpriced' ) ) );
		$this->assertNull( Catalog_Entry::kind( Schema_Block::find_by_slug( 'ce-test-spent' ) ) );
	}

	private function family_in_book( string $slug, string $name ): ?array {
		foreach ( Schema_Block::find_by_slug( $slug )->definition->powers ?? [] as $power ) {
			if ( strcasecmp( (string) $power->name, $name ) === 0 ) {
				return (array) $power;
			}
		}
		return null;
	}
}
