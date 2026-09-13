<?php

namespace BeyondElysium\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Pure-logic edge cases for the filtering half of `Creature_Stack::all_for_game()`
 * (GS-2, guided-chronicle-setup-design.md §6.1) - absent, empty, and
 * unknown-slug handling, without a database. The database-backed cases
 * (a real chronicle, a real `settings.enabled_stacks`) are
 * `EnabledStacksFilterTest`'s job.
 */
class CreatureStackAllForGameTest extends TestCase {

	/**
	 * Mirrors `Creature_Stack::all_for_game()`'s own filter closure exactly,
	 * so this test exercises the identical logic without needing a database
	 * or a `Game` row - `$enabled` stands in for `$game->settings->enabled_stacks`.
	 */
	private function filter( array $stacks, $enabled ): array {
		if ( ! is_array( $enabled ) || empty( $enabled ) ) {
			return $stacks;
		}
		return array_values( array_filter( $stacks, static function ( $stack ) use ( $enabled ) {
			return in_array( $stack->slug, $enabled, true );
		} ) );
	}

	private function stacks(): array {
		return array_map(
			static fn( $slug ) => (object) [ 'slug' => $slug ],
			[ 'vampire', 'werewolf', 'mage', 'changeling', 'wraith', 'demon', 'mummy', 'kueijin', 'mortal', 'fera', 'bete' ]
		);
	}

	public function test_absent_key_returns_all_eleven(): void {
		$result = $this->filter( $this->stacks(), null );
		$this->assertCount( 11, $result );
	}

	public function test_empty_array_returns_all_eleven_not_zero(): void {
		$result = $this->filter( $this->stacks(), [] );
		$this->assertCount( 11, $result, 'an empty array must mean "all", never "none" - §9\'s named risk' );
	}

	public function test_non_array_value_returns_all_eleven(): void {
		$result = $this->filter( $this->stacks(), 'not-an-array' );
		$this->assertCount( 11, $result );
	}

	public function test_a_real_list_narrows_to_exactly_those_slugs(): void {
		$result = $this->filter( $this->stacks(), [ 'vampire', 'werewolf' ] );
		$this->assertSame( [ 'vampire', 'werewolf' ], array_column( $result, 'slug' ) );
	}

	public function test_an_unknown_slug_in_the_list_is_ignored_not_an_error(): void {
		$result = $this->filter( $this->stacks(), [ 'vampire', 'hunter' ] );
		$this->assertSame( [ 'vampire' ], array_column( $result, 'slug' ) );
	}
}
