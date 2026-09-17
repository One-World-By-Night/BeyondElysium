<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Character;
use BeyondElysium\Models\Game;
use BeyondElysium\Services\Query_Engine;
use WP_UnitTestCase;

/**
 * D54: `Query_Engine::resolve_target_query()` used to memoize per `(game_slug, target_query)`
 * with no entity id in the key, so two plots (or two calls) sharing an identical target_query
 * would collide - a static property, so the collision would outlive one PHPUnit test method
 * as easily as it would outlive one production request, the exact bug class `resolve_audience_rules()`'s
 * own sibling was already found and fixed for in U2 (`AudienceThreadTest`). Fixed in 1.1.0 S4
 * by removing the cache entirely.
 *
 * @see BE_PROCESS/releases/1.1.0-design-workflow.md §3.4 item 6
 * @see BE_PROCESS/now/roadmap.md D54
 */
class ResolveTargetQueryCacheThreadTest extends WP_UnitTestCase {

	private string $game_slug = 'thread-target-query-cache';
	private int $game_id;

	public function setUp(): void {
		parent::setUp();
		$this->game_id = (int) Game::create( [ 'slug' => $this->game_slug, 'name' => 'Thread Target Query Cache' ] );
	}

	public function test_two_calls_with_the_same_target_query_return_fresh_results_each_time(): void {
		$character_id = (int) Character::create( [
			'name'       => 'Marcus Vitel',
			'stack_slug' => 'vampire',
			'owner_slug' => $this->game_slug,
			'status'     => 'active',
			'created_by' => 1,
		] );

		$target_query = [ 'field' => 'name', 'operator' => 'equals', 'value' => 'Marcus Vitel' ];

		$this->assertSame(
			[ $character_id ],
			Query_Engine::resolve_target_query( $this->game_slug, $target_query ),
			'the character matches before the rename'
		);

		// The character no longer matches the identical target_query object.
		Character::update_header( $character_id, [ 'name' => 'Someone Else Now' ] );

		$this->assertSame(
			[],
			Query_Engine::resolve_target_query( $this->game_slug, $target_query ),
			'a second call with the same (game_slug, target_query) must re-query, not return the first call\'s stale result'
		);
	}
}
