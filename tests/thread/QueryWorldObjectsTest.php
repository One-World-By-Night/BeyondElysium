<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Character;
use BeyondElysium\Models\World_Object;
use BeyondElysium\Services\Query_Engine;
use WP_UnitTestCase;

/**
 * Real item/location/rote rows through the real query engine - the
 * assertions that cannot be derived from anything already in the codebase,
 * because they depend on real GV ground truth (atomic vs not) and on a real
 * storage asymmetry (a rote's description) that only shows up against real
 * rows.
 *
 * @see BE_PROCESS/query-beyond-characters-design.md §12
 */
class QueryWorldObjectsTest extends WP_UnitTestCase {

	private int $game_id;
	private string $game_slug = 'thread-query-wo-game';

	public function setUp(): void {
		parent::setUp();

		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'be_games', [
			'slug' => $this->game_slug, 'name' => 'Query World Objects Test',
			'created_by' => 1, 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ),
		] );
		$this->game_id = (int) $wpdb->insert_id;
	}

	private function run_query( array $conditions, string $logic, string $inventory ): array {
		return Query_Engine::execute( $this->game_slug, $conditions, $logic, [], $inventory )['results'];
	}

	public function test_a_properties_clause_matches_and_a_non_matching_one_does_not(): void {
		World_Object::create( [ 'game_id' => $this->game_id, 'object_type' => 'item', 'name' => 'Silver Dagger', 'properties' => [ 'item_type' => 'Weapon' ] ] );
		World_Object::create( [ 'game_id' => $this->game_id, 'object_type' => 'item', 'name' => 'Warding Amulet', 'properties' => [ 'item_type' => 'Talisman' ] ] );

		$matches = $this->run_query( [ [ 'field' => 'type', 'operator' => 'equals', 'find' => 'Weapon' ] ], 'AND', 'item' );

		$this->assertCount( 1, $matches );
		$this->assertSame( 'Silver Dagger', $matches[0]->name );
	}

	public function test_a_column_clause_matches_name_and_notes_reads_the_description_column(): void {
		World_Object::create( [
			'game_id' => $this->game_id, 'object_type' => 'item', 'name' => 'Notable Fetish',
			'description' => 'A carved bone whistle that hums.', 'properties' => [],
		] );

		$by_name  = $this->run_query( [ [ 'field' => 'name', 'operator' => 'equals', 'find' => 'Notable Fetish' ] ], 'AND', 'item' );
		$by_notes = $this->run_query( [ [ 'field' => 'notes', 'operator' => 'contains', 'find' => 'hums' ] ], 'AND', 'item' );

		$this->assertCount( 1, $by_name );
		$this->assertCount( 1, $by_notes );
		$this->assertSame( 'Notable Fetish', $by_notes[0]->name );
	}

	public function test_a_rote_description_reads_the_property_while_the_column_stays_null(): void {
		World_Object::create( [
			'game_id' => $this->game_id, 'object_type' => 'rote', 'name' => 'Access This',
			'description' => null, 'properties' => [ 'description' => 'Correspondence: Initiate, Forces: Initiate' ],
		] );

		$matches = $this->run_query( [ [ 'field' => 'description', 'operator' => 'contains', 'find' => 'Correspondence' ] ], 'AND', 'rote' );

		$this->assertCount( 1, $matches );
		$this->assertNull( $matches[0]->description, 'the description COLUMN is never populated for a rote - reading it would return null forever' );
	}

	public function test_links_contains_at_least_on_a_location_behaves_atomically(): void {
		// The first "Tunnel" entry fails the count comparison; only an atomic walk reaches
		// the second one that satisfies it.
		World_Object::create( [
			'game_id' => $this->game_id, 'object_type' => 'location', 'name' => 'Deep Warren',
			'properties' => [ 'links' => [
				[ 'name' => 'Tunnel', 'count' => 1 ],
				[ 'name' => 'Tunnel', 'count' => 3 ],
			] ],
		] );

		$matches = $this->run_query( [ [ 'field' => 'links', 'operator' => 'contains_at_least', 'find' => 'Tunnel', 'value' => 2 ] ], 'AND', 'loc' );

		$this->assertCount( 1, $matches, 'LocationClass.cls:301 - Links is atomic, so the walk must continue past the first failing entry' );
	}

	public function test_abilities_contains_at_least_on_an_item_does_not_behave_atomically(): void {
		// Same shape as the location case above, but Item Abilities is NOT atomic
		// (ItemClass.cls:313) - the walk must stop at the first entry and never reach
		// the second, satisfying one.
		World_Object::create( [
			'game_id' => $this->game_id, 'object_type' => 'item', 'name' => 'Layered Charm',
			'properties' => [ 'abilities' => [
				[ 'name' => 'Melee', 'count' => 1 ],
				[ 'name' => 'Melee', 'count' => 3 ],
			] ],
		] );

		$matches = $this->run_query( [ [ 'field' => 'abilities', 'operator' => 'contains_at_least', 'find' => 'Melee', 'value' => 2 ] ], 'AND', 'item' );

		$this->assertCount( 0, $matches, 'ItemClass.cls:313 - Abilities is NOT atomic, so the walk stops at the first (failing) entry' );
	}

	public function test_powers_contains_on_an_item_does_not_throw(): void {
		// qkdata.gvd types 'powers' as list; ItemClass.cls:131 stores it as a String.
		// Without the type override this reaches evaluate_list(string) - an uncaught
		// TypeError, not a wrong answer.
		World_Object::create( [
			'game_id' => $this->game_id, 'object_type' => 'item', 'name' => 'Fortifying Fetish',
			'properties' => [ 'powers' => 'Grants Fortitude 2 while worn.' ],
		] );

		$matches = $this->run_query( [ [ 'field' => 'powers', 'operator' => 'contains', 'find' => 'Fortitude' ] ], 'AND', 'item' );

		$this->assertCount( 1, $matches );
	}

	public function test_distribution_statistic_over_item_level(): void {
		World_Object::create( [ 'game_id' => $this->game_id, 'object_type' => 'item', 'name' => 'Low Fetish', 'properties' => [ 'level' => 1 ] ] );
		World_Object::create( [ 'game_id' => $this->game_id, 'object_type' => 'item', 'name' => 'High Fetish', 'properties' => [ 'level' => 5 ] ] );

		$result = Query_Engine::statistics( $this->game_slug, [], 'AND', 'level', 'distribution', true, null, 'item' );

		// A non-'field'-type distribution relabels its buckets "{value} {title}" (Query_Engine
		// ::relabel_buckets()) - 'level' is registry type 'num' with title 'Level'.
		$this->assertArrayHasKey( '1 Level', $result['buckets'] );
		$this->assertArrayHasKey( '5 Level', $result['buckets'] );
	}

	public function test_distinct_distribution_statistic_over_location_links_carries_object_names(): void {
		World_Object::create( [ 'game_id' => $this->game_id, 'object_type' => 'location', 'name' => 'North Warren', 'properties' => [ 'links' => [ [ 'name' => 'Tunnel', 'count' => 1 ] ] ] ] );

		$result = Query_Engine::statistics( $this->game_slug, [], 'AND', 'links', 'distinct_distribution', true, null, 'loc' );

		$this->assertContains( 'North Warren', $result['match_sets']['Tunnel'] ?? [], 'match_sets must carry the object\'s own name' );
	}

	public function test_an_item_query_never_returns_locations_even_matching_the_same_clause(): void {
		World_Object::create( [ 'game_id' => $this->game_id, 'object_type' => 'item', 'name' => 'Shared Name Item', 'properties' => [] ] );
		World_Object::create( [ 'game_id' => $this->game_id, 'object_type' => 'location', 'name' => 'Shared Name Item', 'properties' => [] ] );

		$item_matches = $this->run_query( [ [ 'field' => 'name', 'operator' => 'equals', 'find' => 'Shared Name Item' ] ], 'AND', 'item' );

		$this->assertCount( 1, $item_matches, 'the object_type predicate must exclude the identically-named location row' );
	}

	public function test_a_character_query_in_the_same_game_returns_identical_results_to_baseline(): void {
		Character::create( [
			'name' => 'Query WO Baseline Character', 'stack_slug' => 'vampire',
			'owner_type' => 'chronicle', 'owner_slug' => $this->game_slug,
		] );

		$matches = $this->run_query( [ [ 'field' => 'name', 'operator' => 'equals', 'find' => 'Query WO Baseline Character' ] ], 'AND', 'char' );

		$this->assertCount( 1, $matches, 'the default char inventory must keep working exactly as before, alongside real world-object rows in the same game' );
	}
}
