<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Character;
use BeyondElysium\Models\Schema_Block;
use BeyondElysium\Services\Change_Engine;
use WP_UnitTestCase;

/**
 * `Change_Engine::resolve_approval_level()`'s per-value/per-level approval
 * schedule, added across every section_type: a trait_list item's
 * `approval_by_value` (ranges keyed on the submitted count), a tiered_power
 * level's own `approval` (each level is already a discrete catalog row, no
 * range needed), a resource_pool's `approval_by_value` (ranges keyed on the
 * pool's PERMANENT value, never temporary), and an identity_field's
 * `approval_by_option` (keyed on the exact selected string, checked across
 * every value in a multiselect). Resolved against the resulting value only -
 * never a diff against the character's prior value.
 */
class ChangeEngineApprovalByValueTest extends WP_UnitTestCase {

	private string $trait_list_slug   = 'thread-test-abv-trait-list';
	private string $tiered_power_slug = 'thread-test-abv-tiered-power';
	private string $resource_slug     = 'thread-test-abv-resource';
	private string $identity_slug     = 'thread-test-abv-identity';

	public function setUp(): void {
		parent::setUp();

		Schema_Block::create( [
			'slug'         => $this->trait_list_slug,
			'name'         => 'Thread Test ABV Trait List',
			'section_type' => 'trait_list',
			'definition'   => [
				'items' => [
					[
						'name'              => 'Occult',
						'approval'          => 'st', // Fallback for a count no range covers.
						'approval_by_value' => [
							[ 'from' => 1, 'to' => 3, 'approval' => 'auto' ],
							[ 'from' => 4, 'to' => 5, 'approval' => 'coordinator', 'reason' => 'Occult 4+ needs Coordinator review.' ],
						],
					],
					[ 'name' => 'Plain Ability' ],
				],
			],
			'is_system'    => 1,
		] );

		Schema_Block::create( [
			'slug'         => $this->tiered_power_slug,
			'name'         => 'Thread Test ABV Tiered Power',
			'section_type' => 'tiered_power',
			'definition'   => [
				'powers' => [
					[
						'name'   => 'Celerity',
						'levels' => [
							[ 'level' => 1, 'tier' => 'basic', 'power_name' => 'Alacrity' ],
							[ 'level' => 4, 'tier' => 'intermediate', 'power_name' => 'Fleetness', 'approval' => 'coordinator' ],
						],
					],
				],
			],
			'is_system'    => 1,
		] );

		Schema_Block::create( [
			'slug'         => $this->resource_slug,
			'name'         => 'Thread Test ABV Resource',
			'section_type' => 'resource_pool',
			'definition'   => [
				'pools' => [
					[
						'name'              => 'Willpower',
						'value_type'        => 'integer',
						'default_start'     => 1,
						'approval_by_value' => [
							[ 'from' => 8, 'to' => 10, 'approval' => 'coordinator', 'reason' => 'Willpower 8+ needs Coordinator review.' ],
						],
					],
				],
			],
			'is_system'    => 1,
		] );

		Schema_Block::create( [
			'slug'         => $this->identity_slug,
			'name'         => 'Thread Test ABV Identity',
			'section_type' => 'identity_field',
			'definition'   => [
				'fields' => [
					[
						'name'               => 'Generation',
						'field_type'         => 'select',
						'required'           => false,
						'options'            => [ 'Neonate', 'Ancilla', 'Antediluvian' ],
						'approval_by_option' => [
							'Antediluvian' => [ 'approval' => 'coordinator', 'reason' => 'Antediluvian generation needs Coordinator review.' ],
						],
					],
				],
			],
			'is_system'    => 1,
		] );
	}

	private function make_character(): object {
		$id = Character::create( [
			'name'       => 'ABV Test Character',
			'stack_slug' => 'vampire',
			'owner_type' => 'chronicle',
			'owner_slug' => 'thread-test-abv-game',
		] );
		return Character::find( $id );
	}

	// -------------------------------------------------------------------------
	// trait_list: approval_by_value
	// -------------------------------------------------------------------------

	public function test_a_count_within_the_auto_range_resolves_auto(): void {
		$character = $this->make_character();
		$resolved  = Change_Engine::resolve_approval_level( $character, (object) [
			'change_type' => 'modify_trait',
			'change_data' => [ 'block_slug' => $this->trait_list_slug, 'trait' => [ 'name' => 'Occult', 'count' => 2 ] ],
		] );

		$this->assertSame( 'auto', $resolved['level'] );
		$this->assertNull( $resolved['reason'] );
	}

	public function test_a_count_within_the_coordinator_range_resolves_coordinator_with_its_reason(): void {
		$character = $this->make_character();
		$resolved  = Change_Engine::resolve_approval_level( $character, (object) [
			'change_type' => 'modify_trait',
			'change_data' => [ 'block_slug' => $this->trait_list_slug, 'trait' => [ 'name' => 'Occult', 'count' => 5 ] ],
		] );

		$this->assertSame( 'coordinator', $resolved['level'] );
		$this->assertSame( 'Occult 4+ needs Coordinator review.', $resolved['reason'] );
	}

	public function test_resolution_is_by_resulting_value_only_regardless_of_the_starting_point(): void {
		// Jumping straight to 5 (skipping 1-3 entirely in one submission) resolves
		// identically to reaching 5 one dot at a time - state-based, never a diff.
		$character = $this->make_character();
		$resolved  = Change_Engine::resolve_approval_level( $character, (object) [
			'change_type' => 'modify_trait',
			'change_data' => [ 'block_slug' => $this->trait_list_slug, 'trait' => [ 'name' => 'Occult', 'count' => 5 ] ],
		] );

		$this->assertSame( 'coordinator', $resolved['level'] );
	}

	public function test_a_count_outside_every_range_falls_back_to_the_flat_approval(): void {
		$character = $this->make_character();
		$resolved  = Change_Engine::resolve_approval_level( $character, (object) [
			'change_type' => 'modify_trait',
			'change_data' => [ 'block_slug' => $this->trait_list_slug, 'trait' => [ 'name' => 'Occult', 'count' => 99 ] ],
		] );

		$this->assertSame( 'st', $resolved['level'], 'no range covers 99 - the item\'s own flat approval must still apply' );
	}

	public function test_an_item_with_no_schedule_at_all_is_unaffected(): void {
		$character = $this->make_character();
		$resolved  = Change_Engine::resolve_approval_level( $character, (object) [
			'change_type' => 'modify_trait',
			'change_data' => [ 'block_slug' => $this->trait_list_slug, 'trait' => [ 'name' => 'Plain Ability', 'count' => 3 ] ],
		] );

		$this->assertSame( 'st', $resolved['level'] );
	}

	// -------------------------------------------------------------------------
	// tiered_power: per-level approval
	// -------------------------------------------------------------------------

	public function test_a_tiered_power_level_with_no_approval_override_resolves_the_safe_default(): void {
		$character = $this->make_character();
		$resolved  = Change_Engine::resolve_approval_level( $character, (object) [
			'change_type' => 'add_trait',
			'change_data' => [ 'block_slug' => $this->tiered_power_slug, 'trait' => [ 'name' => 'Celerity', 'level' => 1 ] ],
		] );

		$this->assertSame( 'st', $resolved['level'] );
	}

	public function test_a_tiered_power_level_with_its_own_approval_resolves_that_level(): void {
		$character = $this->make_character();
		$resolved  = Change_Engine::resolve_approval_level( $character, (object) [
			'change_type' => 'add_trait',
			'change_data' => [ 'block_slug' => $this->tiered_power_slug, 'trait' => [ 'name' => 'Celerity', 'level' => 4 ] ],
		] );

		$this->assertSame( 'coordinator', $resolved['level'] );
	}

	// -------------------------------------------------------------------------
	// resource_pool: approval_by_value (keyed on the permanent value)
	// -------------------------------------------------------------------------

	public function test_a_permanent_resource_value_within_range_resolves_its_approval(): void {
		$character = $this->make_character();
		$resolved  = Change_Engine::resolve_approval_level( $character, (object) [
			'change_type' => 'modify_resource',
			'change_data' => [ 'block_slug' => $this->resource_slug, 'values' => [ 'Willpower' => [ 'permanent' => 9, 'temporary' => 9 ] ] ],
		] );

		$this->assertSame( 'coordinator', $resolved['level'] );
		$this->assertSame( 'Willpower 8+ needs Coordinator review.', $resolved['reason'] );
	}

	public function test_a_permanent_resource_value_below_every_range_is_unaffected(): void {
		$character = $this->make_character();
		$resolved  = Change_Engine::resolve_approval_level( $character, (object) [
			'change_type' => 'modify_resource',
			'change_data' => [ 'block_slug' => $this->resource_slug, 'values' => [ 'Willpower' => [ 'permanent' => 5, 'temporary' => 5 ] ] ],
		] );

		$this->assertSame( 'st', $resolved['level'] );
	}

	public function test_spending_only_the_temporary_value_never_triggers_the_permanent_schedule(): void {
		// Temporary dropping to 0 from spending, permanent unchanged at a safe value -
		// approval must key off `permanent`, never `temporary`.
		$character = $this->make_character();
		$resolved  = Change_Engine::resolve_approval_level( $character, (object) [
			'change_type' => 'modify_resource',
			'change_data' => [ 'block_slug' => $this->resource_slug, 'values' => [ 'Willpower' => [ 'permanent' => 5, 'temporary' => 0 ] ] ],
		] );

		$this->assertSame( 'st', $resolved['level'] );
	}

	// -------------------------------------------------------------------------
	// identity_field: approval_by_option
	// -------------------------------------------------------------------------

	public function test_an_unflagged_option_resolves_the_safe_default(): void {
		$character = $this->make_character();
		$resolved  = Change_Engine::resolve_approval_level( $character, (object) [
			'change_type' => 'modify_identity',
			'change_data' => [ 'block_slug' => $this->identity_slug, 'fields' => [ 'Generation' => 'Neonate' ] ],
		] );

		$this->assertSame( 'st', $resolved['level'] );
		$this->assertNull( $resolved['reason'] );
	}

	public function test_a_flagged_option_resolves_its_own_approval_and_reason(): void {
		$character = $this->make_character();
		$resolved  = Change_Engine::resolve_approval_level( $character, (object) [
			'change_type' => 'modify_identity',
			'change_data' => [ 'block_slug' => $this->identity_slug, 'fields' => [ 'Generation' => 'Antediluvian' ] ],
		] );

		$this->assertSame( 'coordinator', $resolved['level'] );
		$this->assertSame( 'Antediluvian generation needs Coordinator review.', $resolved['reason'] );
	}

	public function test_a_multiselect_checks_every_selected_value_and_strictest_wins(): void {
		$character = $this->make_character();
		$resolved  = Change_Engine::resolve_approval_level( $character, (object) [
			'change_type' => 'modify_identity',
			'change_data' => [ 'block_slug' => $this->identity_slug, 'fields' => [ 'Generation' => [ 'Neonate', 'Antediluvian' ] ] ],
		] );

		$this->assertSame( 'coordinator', $resolved['level'], 'one flagged value among several must still win' );
	}

	// -------------------------------------------------------------------------
	// Regression: a genuine, previously-undetected bug found while writing the
	// tests above. The running accumulator inside resolve_approval_level()
	// started hardcoded at the string 'st', so ANY explicit 'auto' override -
	// a flat item approval, a tiered_power family's approval_override, or (as
	// found here) one of this feature's own new ranges - could never actually
	// win: strictest('st', 'auto') is 'st' under the pre-existing ranking.
	// Fixed by starting the accumulator at null ("no signal yet") instead.
	// -------------------------------------------------------------------------

	public function test_a_flat_item_approval_of_auto_with_no_reason_now_genuinely_resolves_auto(): void {
		Schema_Block::create( [
			'slug'         => 'thread-test-abv-flat-auto',
			'name'         => 'Thread Test ABV Flat Auto',
			'section_type' => 'trait_list',
			'definition'   => [ 'items' => [ [ 'name' => 'Freebie Merit', 'approval' => 'auto' ] ] ],
			'is_system'    => 1,
		] );

		$character = $this->make_character();
		$resolved  = Change_Engine::resolve_approval_level( $character, (object) [
			'change_type' => 'add_trait',
			'change_data' => [ 'block_slug' => 'thread-test-abv-flat-auto', 'trait' => [ 'name' => 'Freebie Merit' ] ],
		] );

		$this->assertSame( 'auto', $resolved['level'], 'an explicit auto override must actually resolve to auto' );
	}

	public function test_a_tiered_power_family_approval_override_of_auto_now_genuinely_resolves_auto(): void {
		Schema_Block::create( [
			'slug'         => 'thread-test-abv-flat-auto-power',
			'name'         => 'Thread Test ABV Flat Auto Power',
			'section_type' => 'tiered_power',
			'definition'   => [
				'powers' => [
					[ 'name' => 'Innate Gift', 'approval_override' => 'auto', 'levels' => [ [ 'level' => 1, 'tier' => 'innate', 'power_name' => 'Innate Gift' ] ] ],
				],
			],
			'is_system'    => 1,
		] );

		$character = $this->make_character();
		$resolved  = Change_Engine::resolve_approval_level( $character, (object) [
			'change_type' => 'add_trait',
			'change_data' => [ 'block_slug' => 'thread-test-abv-flat-auto-power', 'trait' => [ 'name' => 'Innate Gift', 'level' => 1 ] ],
		] );

		$this->assertSame( 'auto', $resolved['level'] );
	}
}
