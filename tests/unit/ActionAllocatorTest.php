<?php

namespace BeyondElysium\Tests\Unit;

use BeyondElysium\Services\Action_Allocator;
use PHPUnit\Framework\TestCase;

/**
 * Port of `ActionClass.AddCommonActions` (GV301Source/Code/ActionClass.cls). Exercises
 * the pure computation directly - `resolve_common_subactions()`, `build_common_subaction()`
 * and `build_personal_subaction()` take plain arrays and touch no database, the same
 * pattern as `Cost_Engine::is_in_type_pure()` (CostEngineTest).
 *
 * @see BE_PROCESS/workflow-0.5.md Step 4g
 * @see BE_PROCESS/GV-SOURCEMAP.md "Action allocation"
 */
class ActionAllocatorTest extends TestCase {

	private function apr( array $overrides = [] ): array {
		$game = (object) [
			'settings' => (object) [
				'apr' => (object) array_merge( [
					'personal_actions'   => 3,
					'carry_unused'       => true,
					'add_common'         => true,
					'background_actions' => [ 'Allies', 'Contacts', 'Resources' ],
					'actions_per_level'  => [],
				], $overrides ),
			],
		];
		return Action_Allocator::apr_config( $game );
	}

	// -------------------------------------------------------------------------
	// apr_config() defaults
	// -------------------------------------------------------------------------

	public function test_apr_config_falls_back_to_gv_defaults_when_unset(): void {
		$game   = (object) [ 'settings' => (object) [] ];
		$config = Action_Allocator::apr_config( $game );

		$this->assertSame( 3, $config['personal_actions'] );
		$this->assertTrue( $config['carry_unused'] );
		$this->assertTrue( $config['add_common'] );
		$this->assertSame( [], $config['background_actions'] );
		$this->assertSame( [], $config['actions_per_level'] );
	}

	// -------------------------------------------------------------------------
	// Personal subaction
	// -------------------------------------------------------------------------

	public function test_personal_subaction_seeds_at_personal_actions_with_no_prior(): void {
		$subaction = Action_Allocator::build_personal_subaction( $this->apr(), [] );

		$this->assertSame( 'Personal', $subaction['name'] );
		$this->assertSame( 0, $subaction['level'] );
		$this->assertSame( 3, $subaction['total'] );
		$this->assertSame( 3, $subaction['unused'] );
		$this->assertSame( 0, $subaction['growth'] );
	}

	public function test_personal_subaction_carries_unused_and_growth_from_prior(): void {
		$prior     = [ 'Personal' => [ 'total' => 3, 'unused' => 1, 'growth' => 2 ] ];
		$subaction = Action_Allocator::build_personal_subaction( $this->apr(), $prior );

		$this->assertSame( 3, $subaction['total'], 'total is always personal_actions, never carried' );
		$this->assertSame( 1, $subaction['unused'] );
		$this->assertSame( 2, $subaction['growth'] );
	}

	public function test_personal_subaction_growth_carries_even_when_carry_unused_is_false(): void {
		$prior     = [ 'Personal' => [ 'total' => 3, 'unused' => 1, 'growth' => 2 ] ];
		$subaction = Action_Allocator::build_personal_subaction( $this->apr( [ 'carry_unused' => false ] ), $prior );

		$this->assertSame( 3, $subaction['unused'], 'unused resets to total, not carried' );
		$this->assertSame( 2, $subaction['growth'], 'growth carries regardless of carry_unused' );
	}

	// -------------------------------------------------------------------------
	// Default 2x rule and table override
	// -------------------------------------------------------------------------

	public function test_default_rule_is_two_actions_per_dot(): void {
		$subaction = Action_Allocator::build_common_subaction( 'Herd', 3, $this->apr(), [] );

		$this->assertSame( 6, $subaction['total'] );
		$this->assertSame( 6, $subaction['unused'] );
		$this->assertSame( 3, $subaction['level'] );
	}

	public function test_actions_per_level_table_override_wins_over_the_default_rule(): void {
		$apr       = $this->apr( [ 'actions_per_level' => [ '3' => 10 ] ] );
		$subaction = Action_Allocator::build_common_subaction( 'Herd', 3, $apr, [] );

		$this->assertSame( 10, $subaction['total'], 'table entry must win over 2x default' );
	}

	public function test_actions_per_level_lookup_is_string_keyed_by_level(): void {
		// array_key_exists('3', [...]) must find an int-keyed 3 too - PHP normalizes
		// numeric string array keys to int, so this also guards against a regression
		// that switches to a strict identical-type lookup.
		$apr       = $this->apr( [ 'actions_per_level' => [ 3 => 10 ] ] );
		$subaction = Action_Allocator::build_common_subaction( 'Herd', 3, $apr, [] );

		$this->assertSame( 10, $subaction['total'] );
	}

	// -------------------------------------------------------------------------
	// Carry-forward: replaces, does not add; growth carries unconditionally
	// -------------------------------------------------------------------------

	public function test_carry_unused_replaces_the_pool_rather_than_adding_to_it(): void {
		$prior     = [ 'Herd' => [ 'total' => 6, 'unused' => 4, 'growth' => 0 ] ];
		$subaction = Action_Allocator::build_common_subaction( 'Herd', 3, $this->apr(), $prior );

		$this->assertSame( 6, $subaction['total'], 'total recomputed fresh from current dots' );
		$this->assertSame( 4, $subaction['unused'], 'unused is REPLACED by the prior value, not summed with the new total' );
	}

	public function test_growth_carries_when_carry_unused_is_false(): void {
		$prior     = [ 'Herd' => [ 'total' => 6, 'unused' => 4, 'growth' => 2 ] ];
		$subaction = Action_Allocator::build_common_subaction( 'Herd', 3, $this->apr( [ 'carry_unused' => false ] ), $prior );

		$this->assertSame( 6, $subaction['unused'], 'unused resets to the fresh total when carry_unused is off' );
		$this->assertSame( 2, $subaction['growth'], 'growth carries regardless of carry_unused' );
	}

	public function test_no_prior_allocation_seeds_unused_at_total(): void {
		$subaction = Action_Allocator::build_common_subaction( 'Herd', 3, $this->apr(), [] );

		$this->assertSame( 6, $subaction['unused'] );
		$this->assertSame( 0, $subaction['growth'] );
	}

	public function test_edge_case_influence_dropped_since_prior_allocation(): void {
		// Prior allocation was at Influence 3 (total 6, 4 unused); the character is now
		// Influence 1. carry_unused replaces the pool with the stale 4, even though a
		// fresh total at level 1 would only be 2 - this is Grapevine's actual behavior
		// (Step 4c / pre-deploy trace edge case), not a bug to guard against.
		$prior     = [ 'Herd' => [ 'total' => 6, 'unused' => 4, 'growth' => 0 ] ];
		$subaction = Action_Allocator::build_common_subaction( 'Herd', 1, $this->apr(), $prior );

		$this->assertSame( 2, $subaction['total'], 'total reflects the character as they are now' );
		$this->assertSame( 4, $subaction['unused'], 'unused still carries the stale prior value unchanged' );
	}

	// -------------------------------------------------------------------------
	// Influence vs. Background source filtering
	// -------------------------------------------------------------------------

	public function test_influence_sourced_entry_is_always_included(): void {
		$chosen  = [ [ 'name' => 'Bureaucracy', 'count' => 2 ] ];
		$sources = [ 'Bureaucracy' => 'Influences' ];
		$apr     = $this->apr( [ 'background_actions' => [] ] ); // not in the configured list at all

		$result = Action_Allocator::resolve_common_subactions( $chosen, $sources, $apr, [] );

		$this->assertCount( 1, $result );
		$this->assertSame( 'Bureaucracy', $result[0]['name'] );
	}

	public function test_background_entry_included_only_when_named_in_background_actions(): void {
		$chosen  = [
			[ 'name' => 'Herd', 'count' => 2 ],
			[ 'name' => 'Resources', 'count' => 3 ],
		];
		$sources = [ 'Herd' => 'Backgrounds, Vampire', 'Resources' => 'Backgrounds' ];
		$apr     = $this->apr( [ 'background_actions' => [ 'Resources' ] ] );

		$result = Action_Allocator::resolve_common_subactions( $chosen, $sources, $apr, [] );

		$this->assertCount( 1, $result );
		$this->assertSame( 'Resources', $result[0]['name'] );
	}

	public function test_a_character_with_no_influences_or_matching_backgrounds_gets_no_common_subactions(): void {
		$chosen  = [ [ 'name' => 'Herd', 'count' => 2 ] ];
		$sources = [ 'Herd' => 'Backgrounds, Vampire' ];
		$apr     = $this->apr( [ 'background_actions' => [ 'Resources' ] ] );

		$result = Action_Allocator::resolve_common_subactions( $chosen, $sources, $apr, [] );

		$this->assertSame( [], $result );
	}

	public function test_duplicate_name_is_not_added_twice(): void {
		$chosen  = [
			[ 'name' => 'Bureaucracy', 'count' => 2 ],
			[ 'name' => 'Bureaucracy', 'count' => 2 ],
		];
		$sources = [ 'Bureaucracy' => 'Influences' ];

		$result = Action_Allocator::resolve_common_subactions( $chosen, $sources, $this->apr(), [] );

		$this->assertCount( 1, $result );
	}
}
