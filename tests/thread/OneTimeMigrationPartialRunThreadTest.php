<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Database\Schema;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Schema_Block;
use WP_UnitTestCase;

/**
 * 1.0.0-review F-065 (Pass H intake `t3-schema-seeder`). Two one-time migrations rewrite one
 * block at a time and then checked `$wpdb->last_error` once - which only knows about the last
 * query. A block whose rewrite failed ahead of one that succeeded was marked done with the rest,
 * and never looked at again: a chronicle's Disciplines priced flat for good, or a coordinator
 * rule left standing. A migration is now marked done only when every block it rewrote was
 * written.
 */
class OneTimeMigrationPartialRunThreadTest extends WP_UnitTestCase {

	private int $broken_id = 0;

	public function setUp(): void {
		parent::setUp();
		foreach ( [ 'thread-partial-a', 'thread-partial-b' ] as $slug ) {
			Game::create( [ 'slug' => $slug, 'name' => $slug ] );
		}
	}

	public function tearDown(): void {
		remove_filter( 'query', [ $this, 'break_one_block' ] );
		parent::tearDown();
	}

	/** Fails the update of one block row, the way a lock timeout or a dropped connection would. */
	public function break_one_block( string $query ): string {
		return str_starts_with( $query, 'UPDATE' ) && str_contains( $query, 'be_schema_blocks' ) && preg_match( "/`id` = '?{$this->broken_id}'?(?!\\d)/", $query )
			? 'UPDATE be_thread_no_such_table SET id = 1'
			: $query;
	}

	private function run_with_block_broken( int $id, callable $migration ): void {
		global $wpdb;
		$this->broken_id = $id;
		add_filter( 'query', [ $this, 'break_one_block' ] );
		$quiet = $wpdb->suppress_errors( true );
		$migration();
		$wpdb->suppress_errors( $quiet );
		remove_filter( 'query', [ $this, 'break_one_block' ] );
	}

	private function copy_id( string $slug, string $game_slug ): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}be_schema_blocks WHERE slug = %s AND game_slug = %s", $slug, $game_slug ) );
	}

	public function test_flat_priced_copies_are_not_marked_done_while_one_failed(): void {
		foreach ( [ 'thread-partial-a', 'thread-partial-b' ] as $game_slug ) {
			$fork       = Schema_Block::find_or_create_fork_for_game( 'vampire-disciplines', $game_slug );
			$definition = json_decode( wp_json_encode( $fork->definition ), true );
			$definition['sequential'] = false;
			Schema_Block::update( 'vampire-disciplines', [ 'definition' => $definition ], $game_slug );
		}
		delete_option( 'be_power_ladders_cumulative' );

		// The first copy fails, the second - read after it - succeeds.
		$this->run_with_block_broken( $this->copy_id( 'vampire-disciplines', 'thread-partial-a' ), [ Schema::class, 'make_power_ladders_cumulative' ] );

		$this->assertFalse( get_option( 'be_power_ladders_cumulative' ) );
		Schema::make_power_ladders_cumulative();
		$this->assertTrue( ! empty( Schema_Block::find_for_game( 'vampire-disciplines', 'thread-partial-a' )->definition->sequential ), 'retried on the next upgrade' );
		$this->assertSame( '1', (string) get_option( 'be_power_ladders_cumulative' ) );
	}

	public function test_coordinator_rules_are_not_marked_retired_while_one_rewrite_failed(): void {
		foreach ( [ 'thread-partial-first', 'thread-partial-second' ] as $slug ) {
			Schema_Block::create( [ 'slug' => $slug, 'name' => $slug, 'section_type' => 'trait_list', 'is_system' => 1, 'definition' => [ 'items' => [ [ 'name' => 'Rule', 'approval' => 'coordinator' ] ] ] ] );
		}
		delete_option( 'be_coordinator_tier_removed' );

		$this->run_with_block_broken( $this->copy_id( 'thread-partial-first', '' ), [ Schema::class, 'remove_coordinator_approvals' ] );

		$this->assertFalse( get_option( 'be_coordinator_tier_removed' ) );
		Schema::remove_coordinator_approvals();
		$this->assertSame( 'st', Schema_Block::find_by_slug( 'thread-partial-first' )->definition->items[0]->approval );
	}
}
