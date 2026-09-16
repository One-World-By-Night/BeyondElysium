<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Services\Action_Allocator;
use BeyondElysium\Services\Query_Engine;
use WP_UnitTestCase;

/**
 * 1.0.0-review F-020. PHPStan at level 8 found 75 places that used a lookup or an encode result
 * without allowing for its null or false. Most are guarded now and held there by the
 * `phpstan-null-safety.neon` gate in `bin/verify`. These are the ones a caller can reach, each of
 * which threw or warned where the method promised an answer.
 */
class NullFalseHandlingThreadTest extends WP_UnitTestCase {

	private function plot_count( string $title ): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}be_plots WHERE title = %s", $title ) );
	}

	public function test_allocating_for_a_character_that_is_gone_writes_nothing(): void {
		$this->assertSame( 0, Action_Allocator::persist( PHP_INT_MAX, '2026-11-01' ), 'persist() answers 0 when nothing was kept' );
	}

	public function test_a_character_in_no_chronicle_gets_no_allocation_plot(): void {
		$character = (object) [ 'id' => 1, 'name' => 'Nowhere', 'owner_slug' => 'thread-no-such-chronicle' ];

		$this->assertNull( Action_Allocator::create_own_plot( $character, '2026-11-01' ) );
		$this->assertSame( 0, $this->plot_count( '2026-11-01 Nowhere' ), 'not a plot in no chronicle at all' );
	}

	public function test_a_query_on_an_inventory_that_does_not_exist_matches_nothing(): void {
		$this->assertSame( [ 'results' => [], 'total' => 0 ], Query_Engine::execute( 'be-demo', [], 'AND', [], 'thread-no-such-inventory' ) );
	}
}
