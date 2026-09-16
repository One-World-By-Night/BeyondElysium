<?php

namespace BeyondElysium\Tests\Unit;

use BeyondElysium\Services\Change_Validator;
use PHPUnit\Framework\TestCase;

/**
 * 1.0.0-review F-030: Change_Validator normalizes and refuses submitted change shapes the
 * engines would otherwise trust - misspelled or made-up names priced at 0 XP, custom entries on
 * blocks that don't allow them, multi-key pool and field changes judged by their first key,
 * string levels that dodged per-level rules, awarded pools bought for free, and a cleared Clan.
 */
class ChangeValidatorTest extends TestCase {

	private function blocks(): array {
		return [
			'abilities'   => (object) [ 'section_type' => 'trait_list', 'definition' => (object) [
				'items' => [ (object) [ 'name' => 'Occult', 'cost' => '2' ], (object) [ 'name' => 'Academics', 'cost' => '2' ] ],
			] ],
			'merits'      => (object) [ 'section_type' => 'trait_list', 'definition' => (object) [
				'allow_custom' => true,
				'items'        => [ (object) [ 'name' => 'Iron Will', 'cost' => '3' ] ],
			] ],
			'disciplines' => (object) [ 'section_type' => 'tiered_power', 'definition' => (object) [
				'powers' => [ (object) [ 'name' => 'Celerity', 'levels' => [
					(object) [ 'level' => 1, 'power_name' => 'Alacrity' ],
					(object) [ 'level' => 5, 'power_name' => 'Fleetness' ],
					(object) [ 'level' => null, 'power_name' => 'Precision', 'tier' => 'elder' ],
				] ] ],
			] ],
			'pools'       => (object) [ 'section_type' => 'resource_pool', 'definition' => (object) [
				'pools' => [
					(object) [ 'name' => 'Willpower', 'cost_per_dot' => 3, 'default_start' => 2 ],
					(object) [ 'name' => 'Glory' ],
				],
			] ],
			'identity'    => (object) [ 'section_type' => 'identity_field', 'definition' => (object) [
				'fields' => [
					(object) [ 'name' => 'Clan', 'field_type' => 'select', 'options' => [ 'Brujah', 'Tremere' ] ],
					(object) [ 'name' => 'Nature', 'field_type' => 'text' ],
				],
			] ],
		];
	}

	private function check( string $type, array $data, array $sheet = [], bool $manager = false, array $protected = [] ): array {
		return Change_Validator::validate( [ 'change_type' => $type, 'change_data' => $data ], $this->blocks(), $sheet, $manager, $protected );
	}

	public function test_a_misspelled_catalog_name_is_normalized_to_the_catalog_spelling(): void {
		$result = $this->check( 'add_trait', [ 'block_slug' => 'abilities', 'trait' => [ 'name' => ' occult ', 'count' => 3 ] ] );

		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'Occult', $result['change_data']['trait']['name'] );
	}

	public function test_a_made_up_name_is_refused_where_custom_entries_are_not_allowed(): void {
		$result = $this->check( 'add_trait', [ 'block_slug' => 'abilities', 'trait' => [ 'name' => 'Basket Weaving' ] ] );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'unknown_trait', $result['code'] );
	}

	public function test_claiming_custom_does_not_bypass_a_block_that_refuses_custom_entries(): void {
		$result = $this->check( 'add_trait', [ 'block_slug' => 'abilities', 'trait' => [ 'name' => 'Basket Weaving', 'custom' => true ] ] );

		$this->assertFalse( $result['ok'] );
	}

	public function test_a_storyteller_may_add_a_custom_entry_to_any_section(): void {
		$result = $this->check( 'add_trait', [ 'block_slug' => 'abilities', 'trait' => [ 'name' => 'Chronicle Lore' ] ], [], true );

		$this->assertTrue( $result['ok'] );
		$this->assertTrue( $result['change_data']['trait']['custom'] );
	}

	public function test_a_made_up_name_becomes_a_custom_entry_where_the_block_allows_one(): void {
		$result = $this->check( 'add_trait', [ 'block_slug' => 'merits', 'trait' => [ 'name' => 'Lucky Coin' ] ] );

		$this->assertTrue( $result['ok'] );
		$this->assertTrue( $result['change_data']['trait']['custom'] );
	}

	public function test_custom_is_stripped_from_a_catalog_name_so_it_is_priced(): void {
		$result = $this->check( 'add_trait', [ 'block_slug' => 'merits', 'trait' => [ 'name' => 'iron will', 'custom' => true ] ] );

		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'Iron Will', $result['change_data']['trait']['name'] );
		$this->assertArrayNotHasKey( 'custom', $result['change_data']['trait'] );
	}

	public function test_counts_are_cast_to_integers_and_nonsense_is_refused(): void {
		$ok  = $this->check( 'modify_trait', [ 'block_slug' => 'abilities', 'trait' => [ 'name' => 'Occult', 'count' => '4' ] ] );
		$bad = $this->check( 'modify_trait', [ 'block_slug' => 'abilities', 'trait' => [ 'name' => 'Occult', 'count' => '4.5' ] ] );

		$this->assertSame( 4, $ok['change_data']['trait']['count'] );
		$this->assertFalse( $bad['ok'] );
	}

	public function test_unknown_trait_keys_are_dropped(): void {
		$result = $this->check( 'add_trait', [ 'block_slug' => 'abilities', 'trait' => [ 'name' => 'Occult', 'xp_cost' => 0, 'approval' => 'auto' ] ] );

		$this->assertSame( [ 'name' => 'Occult' ], $result['change_data']['trait'] );
	}

	public function test_removing_a_held_entry_the_catalog_no_longer_has_is_allowed(): void {
		$sheet  = [ 'abilities' => [ [ 'name' => 'Retired Skill', 'count' => 2 ] ] ];
		$result = $this->check( 'remove_trait', [ 'block_slug' => 'abilities', 'trait' => [ 'name' => 'Retired Skill' ] ], $sheet );

		$this->assertTrue( $result['ok'] );
	}

	public function test_a_power_level_sent_as_a_string_becomes_an_integer(): void {
		$result = $this->check( 'modify_trait', [ 'block_slug' => 'disciplines', 'trait' => [ 'name' => 'celerity', 'level' => '5' ] ] );

		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'Celerity', $result['change_data']['trait']['name'] );
		$this->assertSame( 5, $result['change_data']['trait']['level'] );
	}

	public function test_a_made_up_elder_power_is_refused_and_a_real_one_is_normalized(): void {
		$fake = $this->check( 'add_trait', [ 'block_slug' => 'disciplines', 'trait' => [ 'name' => 'Celerity', 'power_name' => 'Not A Real Power' ] ] );
		$real = $this->check( 'add_trait', [ 'block_slug' => 'disciplines', 'trait' => [ 'name' => 'Celerity', 'power_name' => 'precision' ] ] );

		$this->assertFalse( $fake['ok'] );
		$this->assertSame( 'unknown_power_pick', $fake['code'] );
		$this->assertSame( 'Precision', $real['change_data']['trait']['power_name'] );
	}

	public function test_a_resource_change_naming_two_pools_is_refused(): void {
		$result = $this->check( 'modify_resource', [ 'block_slug' => 'pools', 'values' => [
			'Willpower' => [ 'temporary' => 1 ],
			'Glory'     => [ 'permanent' => 10 ],
		] ] );

		$this->assertFalse( $result['ok'] );
	}

	public function test_an_identity_change_naming_two_fields_is_refused(): void {
		$result = $this->check( 'modify_identity', [ 'block_slug' => 'identity', 'fields' => [ 'Nature' => 'Survivor', 'Clan' => 'Tremere' ] ] );

		$this->assertFalse( $result['ok'] );
	}

	public function test_a_player_cannot_set_an_awarded_pools_permanent_rating(): void {
		$sheet = [ 'pools' => [ 'Glory' => [ 'permanent' => 1, 'temporary' => 1 ] ] ];

		$raise  = $this->check( 'modify_resource', [ 'block_slug' => 'pools', 'values' => [ 'Glory' => [ 'permanent' => 5, 'temporary' => 1 ] ] ], $sheet );
		$spend  = $this->check( 'modify_resource', [ 'block_slug' => 'pools', 'values' => [ 'Glory' => [ 'permanent' => 1, 'temporary' => 0 ] ] ], $sheet );
		$staff  = $this->check( 'modify_resource', [ 'block_slug' => 'pools', 'values' => [ 'Glory' => [ 'permanent' => 5, 'temporary' => 5 ] ] ], $sheet, true );

		$this->assertSame( 'pool_not_purchasable', $raise['code'] );
		$this->assertTrue( $spend['ok'] );
		$this->assertTrue( $staff['ok'] );
	}

	public function test_a_player_may_buy_a_priced_pools_permanent_rating(): void {
		$result = $this->check( 'modify_resource', [ 'block_slug' => 'pools', 'values' => [ 'willpower' => [ 'permanent' => '4', 'temporary' => '4' ] ] ] );

		$this->assertTrue( $result['ok'] );
		$this->assertSame( [ 'Willpower' => [ 'permanent' => 4, 'temporary' => 4 ] ], $result['change_data']['values'] );
	}

	public function test_a_select_field_accepts_only_its_options(): void {
		$ok  = $this->check( 'modify_identity', [ 'block_slug' => 'identity', 'fields' => [ 'Clan' => 'tremere' ] ] );
		$bad = $this->check( 'modify_identity', [ 'block_slug' => 'identity', 'fields' => [ 'Clan' => 'Gangrel' ] ] );

		$this->assertSame( [ 'Clan' => 'Tremere' ], $ok['change_data']['fields'] );
		$this->assertSame( 'unknown_option', $bad['code'] );
	}

	public function test_a_player_cannot_clear_the_field_discipline_pricing_reads_but_a_storyteller_can(): void {
		$protected = [ 'identity.Clan' ];

		$player = $this->check( 'modify_identity', [ 'block_slug' => 'identity', 'fields' => [ 'Clan' => '' ] ], [], false, $protected );
		$staff  = $this->check( 'modify_identity', [ 'block_slug' => 'identity', 'fields' => [ 'Clan' => '' ] ], [], true, $protected );

		$this->assertSame( 'field_required', $player['code'] );
		$this->assertTrue( $staff['ok'] );
	}

	public function test_change_types_sections_and_blocks_are_checked(): void {
		$this->assertSame( 'invalid_change_type', $this->check( 'import_note', [ 'block_slug' => 'abilities' ] )['code'] );
		$this->assertSame( 'unknown_block', $this->check( 'add_trait', [ 'block_slug' => 'not-on-this-stack', 'trait' => [ 'name' => 'Occult' ] ] )['code'] );
		$this->assertSame( 'wrong_section_type', $this->check( 'modify_resource', [ 'block_slug' => 'abilities', 'values' => [ 'Occult' => 1 ] ] )['code'] );
	}

	public function test_an_xp_change_needs_a_whole_nonzero_amount(): void {
		$ok  = $this->check( 'xp_adjust', [ 'amount' => '5', 'reason' => '<b>Session</b>' ] );
		$bad = $this->check( 'xp_adjust', [ 'amount' => 0 ] );

		$this->assertSame( [ 'amount' => 5, 'reason' => 'Session' ], $ok['change_data'] );
		$this->assertFalse( $bad['ok'] );
	}

	public function test_protected_fields_come_from_the_stacks_in_type_sources(): void {
		$stack = (object) [ 'stack_definition' => (object) [ 'sections' => [
			(object) [ 'block_slug' => 'vampire-disciplines', 'in_type_source' => 'vampire-identity.Clan' ],
			(object) [ 'block_slug' => 'met-abilities' ],
		] ] ];

		$this->assertSame( [ 'vampire-identity.Clan' ], Change_Validator::protected_fields( $stack ) );
	}
}
