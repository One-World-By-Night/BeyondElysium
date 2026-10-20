<?php

namespace BeyondElysium\Tests\Unit;

use BeyondElysium\Services\Change_Edit;
use PHPUnit\Framework\TestCase;

/**
 * Which fields a Storyteller may change on a waiting change, by what the change is and what it carries.
 */
class ChangeEditFieldsTest extends TestCase {

	/**
	 * @param array<string,mixed> $data
	 * @return string[]
	 */
	private static function fields( string $type, array $data, ?string $section_type ): array {
		return Change_Edit::editable_fields( $type, $data, $section_type );
	}

	public function test_a_homebrew_trait_added_to_a_trait_list_can_be_renamed_refiled_and_noted(): void {
		$data = [ 'block_slug' => 'vampire-rituals', 'trait' => [ 'name' => 'Wax Ward', 'count' => 1, 'custom' => true ] ];

		$this->assertSame( [ 'block_slug', 'name', 'count', 'note' ], self::fields( 'add_trait', $data, 'trait_list' ) );
	}

	public function test_a_trait_the_book_already_knows_stays_in_its_section(): void {
		$data = [ 'block_slug' => 'vampire-abilities', 'trait' => [ 'name' => 'Alertness', 'count' => 2 ] ];

		$this->assertSame( [ 'name', 'count', 'note' ], self::fields( 'add_trait', $data, 'trait_list' ) );
	}

	public function test_a_specialization_is_editable_only_where_the_change_carries_one(): void {
		$with    = [ 'block_slug' => 'vampire-abilities', 'trait' => [ 'name' => 'Craft', 'count' => 1, 'specialization' => 'Glass' ] ];
		$without = [ 'block_slug' => 'vampire-abilities', 'trait' => [ 'name' => 'Craft', 'count' => 1 ] ];

		$this->assertSame( [ 'name', 'count', 'specialization', 'note' ], self::fields( 'add_trait', $with, 'trait_list' ) );
		$this->assertSame( [ 'name', 'count', 'note' ], self::fields( 'add_trait', $without, 'trait_list' ) );
	}

	public function test_a_change_to_a_held_trait_can_change_its_dots_and_note_but_not_its_name_or_section(): void {
		$data = [ 'block_slug' => 'vampire-abilities', 'trait' => [ 'name' => 'Occult', 'count' => 4, 'custom' => true ] ];

		$this->assertSame( [ 'count', 'note' ], self::fields( 'modify_trait', $data, 'trait_list' ) );
	}

	public function test_a_power_change_edits_its_level_and_a_homebrew_power_also_its_names_and_section(): void {
		$modify   = [ 'block_slug' => 'vampire-disciplines', 'trait' => [ 'name' => 'Celerity', 'level' => 3 ] ];
		$homebrew = [ 'block_slug' => 'vampire-disciplines', 'trait' => [ 'name' => 'Mine', 'power_name' => 'Mine One', 'level' => 2, 'custom' => true ] ];
		$pick     = [ 'block_slug' => 'vampire-disciplines', 'trait' => [ 'name' => 'Celerity', 'power_name' => 'Precision', 'level' => null ] ];

		$this->assertSame( [ 'level' ], self::fields( 'modify_trait', $modify, 'tiered_power' ) );
		$this->assertSame( [ 'block_slug', 'name', 'power_name', 'level' ], self::fields( 'add_trait', $homebrew, 'tiered_power' ) );
		$this->assertSame( [], self::fields( 'add_trait', $pick, 'tiered_power' ), 'a pick has no level to change' );
	}

	public function test_a_pool_rating_is_editable_unless_it_was_paid_for_in_temporary_points(): void {
		$plain  = [ 'block_slug' => 'vampire-resources', 'values' => [ 'Willpower' => [ 'permanent' => 7 ] ] ];
		$bare   = [ 'block_slug' => 'vampire-resources', 'values' => [ 'Willpower' => 7 ] ];
		$raised = [ 'block_slug' => 'hunter-virtues', 'values' => [ 'Mercy' => [ 'permanent' => 2, 'raised_cost' => 10, 'raised_from' => 'Conviction' ] ] ];
		$two    = [ 'block_slug' => 'vampire-resources', 'values' => [ 'Willpower' => 7, 'Blood' => 11 ] ];

		$this->assertSame( [ 'permanent' ], self::fields( 'modify_resource', $plain, 'resource_pool' ) );
		$this->assertSame( [ 'permanent' ], self::fields( 'modify_resource', $bare, 'resource_pool' ) );
		$this->assertSame( [], self::fields( 'modify_resource', $raised, 'resource_pool' ) );
		$this->assertSame( [], self::fields( 'modify_resource', $two, 'resource_pool' ) );
	}

	public function test_an_identity_change_edits_a_single_plain_value(): void {
		$one   = [ 'block_slug' => 'vampire-identity', 'fields' => [ 'Title' => 'Regent' ] ];
		$many  = [ 'block_slug' => 'vampire-identity', 'fields' => [ 'In-clan Disciplines' => [ 'Auspex', 'Fortitude' ] ] ];
		$pair  = [ 'block_slug' => 'vampire-identity', 'fields' => [ 'Title' => 'Regent', 'Sire' => 'Isolde' ] ];

		$this->assertSame( [ 'value' ], self::fields( 'modify_identity', $one, 'identity_field' ) );
		$this->assertSame( [], self::fields( 'modify_identity', $many, 'identity_field' ), 'a list of choices has no single value' );
		$this->assertSame( [], self::fields( 'modify_identity', $pair, 'identity_field' ) );
	}

	public function test_an_experience_change_edits_its_amount_and_reason(): void {
		$this->assertSame( [ 'amount', 'reason' ], self::fields( 'xp_earn', [ 'amount' => 5 ], null ) );
		$this->assertSame( [ 'amount', 'reason' ], self::fields( 'xp_adjust', [ 'amount' => -2 ], null ) );
	}

	public function test_every_other_kind_of_change_takes_a_note_only(): void {
		foreach ( [ 'remove_trait', 'log_knowledge', 'pass_secret', 'propose_faction', 'visit_note', 'import_note' ] as $type ) {
			$this->assertSame( [], self::fields( $type, [ 'block_slug' => 'vampire-rituals', 'trait' => [ 'name' => 'X' ] ], 'trait_list' ), $type );
		}
	}

	public function test_a_section_type_that_takes_trait_changes_but_is_not_one_of_the_two_edits_nothing(): void {
		$data = [ 'block_slug' => 'vampire-identity', 'trait' => [ 'name' => 'X', 'count' => 1 ] ];

		$this->assertSame( [], self::fields( 'add_trait', $data, 'identity_field' ) );
		$this->assertSame( [], self::fields( 'add_trait', $data, null ) );
	}
}
