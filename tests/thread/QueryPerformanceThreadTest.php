<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Character;
use BeyondElysium\Services\Query_Engine;
use WP_UnitTestCase;

/**
 * 1.0.0-review F-027: sorting query results called resolve_value() for both sides of every
 * comparison, and each call re-loaded the block definition from the database - n log n round
 * trips, each decoding a whole catalog block (653 queries and 846 ms to sort 34 vampires by
 * Disciplines). Sort values are now resolved once per row, and block lookups are remembered for
 * the length of one query run.
 */
class QueryPerformanceThreadTest extends WP_UnitTestCase {

	private string $slug = 'thread-query-performance';

	public function setUp(): void {
		parent::setUp();

		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'be_games', [
			'slug' => $this->slug, 'name' => $this->slug, 'settings' => '{}',
			'created_by' => 1, 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ),
		] );

		for ( $i = 1; $i <= 20; $i++ ) {
			Character::create( [
				'name' => "Scholar {$i}", 'stack_slug' => 'vampire',
				'owner_type' => 'chronicle', 'owner_slug' => $this->slug,
				'sheet_data' => [ 'met-abilities' => array_map(
					static fn( $n ) => [ 'name' => "Skill {$n}", 'count' => 1 ],
					range( 1, ( $i % 5 ) + 1 )
				) ],
			] );
		}
	}

	public function test_sorting_by_a_list_field_does_not_query_per_comparison(): void {
		global $wpdb;
		$before = $wpdb->num_queries;

		$result = Query_Engine::execute(
			$this->slug,
			[ [ 'field' => 'abilities', 'operator' => 'totals_at_least', 'value' => 0 ] ],
			'AND',
			[ 'per_page' => 100, 'sort' => [ 'field' => 'abilities', 'direction' => 'desc' ] ]
		);

		$this->assertSame( 20, $result['total'] );
		// One row fetch, a handful of block lookups, and - since 1.2.0 - Catalog_Translator::
		// map()'s own one-time cold-cache cost (a version-option read, the transient miss,
		// Translation::map_for_locale()'s join, and the two writes that cache it) the first time
		// any block decodes in a request. Measured directly (SAVEQUERIES + a real query dump,
		// not guessed): 1 characters + 4 pre-existing block lookups + 5 for map()'s cold miss =
		// 10, exactly - never more, since map() is transient-cached for the rest of the request
		// after that. 15 leaves real headroom above that ceiling while still catching a genuine
		// regression back to one query per row (20+).
		$this->assertLessThan( 15, $wpdb->num_queries - $before );
	}

	public function test_sorted_results_are_still_in_order(): void {
		$result = Query_Engine::execute(
			$this->slug,
			[],
			'AND',
			[ 'per_page' => 100, 'sort' => [ 'field' => 'name', 'direction' => 'asc' ] ]
		);

		$names  = array_column( $result['results'], 'name' );
		$sorted = $names;
		sort( $sorted );
		$this->assertSame( $sorted, $names );
	}
}
