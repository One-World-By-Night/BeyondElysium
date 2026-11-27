<?php

namespace BeyondElysium\Tests\Unit;

use BeyondElysium\Services\Coordinator_Line;
use PHPUnit\Framework\TestCase;

/**
 * One waiting change as the line the Toolkit's request carries: its name, its section, what changes, what it costs.
 */
class CoordinatorLineTest extends TestCase {

	/**
	 * @param array<string,mixed> $over
	 */
	private function change( string $type, array $data, array $over = [] ): object {
		return (object) array_merge( [
			'change_type' => $type,
			'change_data' => $data,
			'xp_cost'     => '0.00',
			'reason'      => null,
			'notes'       => null,
		], $over );
	}

	public function test_a_trait_added_with_a_count_reads_as_a_count(): void {
		$line = Coordinator_Line::of( $this->change( 'add_trait', [ 'block_slug' => 'vampire-merits', 'trait' => [ 'name' => 'Dual-Blooded', 'count' => 2 ] ], [ 'xp_cost' => '2.00' ] ), 'Merits' );

		$this->assertSame( 'Dual-Blooded', $line['label'] );
		$this->assertSame( 'Merits', $line['section'] );
		$this->assertSame( 'x2', $line['detail'] );
		$this->assertSame( 2, $line['xp'] );
	}

	public function test_a_trait_added_once_has_no_detail(): void {
		$line = Coordinator_Line::of( $this->change( 'add_trait', [ 'trait' => [ 'name' => 'Rune-Wise' ] ] ), 'Merits' );

		$this->assertSame( '', $line['detail'] );
		$this->assertSame( 0, $line['xp'] );
	}

	public function test_a_power_added_at_a_level_reads_as_that_level(): void {
		$line = Coordinator_Line::of( $this->change( 'add_trait', [ 'trait' => [ 'name' => 'Obtenebration', 'level' => 4 ] ] ), 'Disciplines' );

		$this->assertSame( 'Obtenebration', $line['label'] );
		$this->assertSame( 'level 4', $line['detail'] );
	}

	public function test_an_elder_pick_is_named_by_its_power(): void {
		$line = Coordinator_Line::of( $this->change( 'add_trait', [ 'trait' => [ 'name' => 'Obtenebration', 'power_name' => 'Shadow Step', 'level' => 7 ] ] ), 'Disciplines' );

		$this->assertSame( 'Shadow Step', $line['label'] );
	}

	public function test_a_trait_raised_reads_from_to(): void {
		$level = Coordinator_Line::of( $this->change( 'modify_trait', [ 'trait' => [ 'name' => 'Celerity', 'level' => 4 ], 'previous' => [ 'level' => 2 ] ] ), 'Disciplines' );
		$count = Coordinator_Line::of( $this->change( 'modify_trait', [ 'trait' => [ 'name' => 'Occult', 'count' => 5 ], 'previous' => [ 'count' => 3 ] ] ), 'Abilities' );

		$this->assertSame( 'from level 2 to level 4', $level['detail'] );
		$this->assertSame( 'from x3 to x5', $count['detail'] );
	}

	public function test_a_trait_removed_says_so(): void {
		$line = Coordinator_Line::of( $this->change( 'remove_trait', [ 'trait' => [ 'name' => 'Clan Enmity' ] ] ), 'Flaws' );

		$this->assertSame( 'Clan Enmity', $line['label'] );
		$this->assertSame( 'removed', $line['detail'] );
	}

	public function test_a_pool_raised_reads_as_its_new_rating(): void {
		$line = Coordinator_Line::of( $this->change( 'modify_resource', [ 'values' => [ 'Balance' => [ 'permanent' => 6, 'temporary' => 6 ] ] ] ), 'Resources' );

		$this->assertSame( 'Balance', $line['label'] );
		$this->assertSame( 'to 6', $line['detail'] );
	}

	public function test_an_identity_field_changed_reads_as_its_new_value(): void {
		$line = Coordinator_Line::of( $this->change( 'modify_identity', [ 'fields' => [ 'Generation' => 9 ] ] ), 'Identity' );

		$this->assertSame( 'Generation', $line['label'] );
		$this->assertSame( 'to 9', $line['detail'] );
	}

	public function test_a_multiselect_value_reads_as_its_choices(): void {
		$line = Coordinator_Line::of( $this->change( 'modify_identity', [ 'fields' => [ 'Paths' => [ 'Cathari', 'Death' ] ] ] ), 'Identity' );

		$this->assertSame( 'to Cathari, Death', $line['detail'] );
	}

	public function test_the_reason_and_the_players_note_are_carried(): void {
		$line = Coordinator_Line::of( $this->change( 'add_trait', [ 'trait' => [ 'name' => 'Dual-Blooded' ] ], [ 'reason' => 'Coordinator Approval', 'notes' => ' Her sire was a Ravnos. ' ] ), 'Merits' );

		$this->assertSame( 'Coordinator Approval', $line['reason'] );
		$this->assertSame( 'Her sire was a Ravnos.', $line['player_note'] );
	}

	public function test_an_empty_reason_or_note_is_null(): void {
		$line = Coordinator_Line::of( $this->change( 'add_trait', [ 'trait' => [ 'name' => 'X' ] ], [ 'reason' => '', 'notes' => '  ' ] ), '' );

		$this->assertNull( $line['reason'] );
		$this->assertNull( $line['player_note'] );
		$this->assertSame( '', $line['section'] );
	}

	public function test_a_json_string_of_change_data_is_read_too(): void {
		$line = Coordinator_Line::of( (object) [ 'change_type' => 'add_trait', 'change_data' => '{"trait":{"name":"Dual-Blooded","count":2}}', 'xp_cost' => '2.00', 'reason' => null, 'notes' => null ], 'Merits' );

		$this->assertSame( 'Dual-Blooded', $line['label'] );
		$this->assertSame( 'x2', $line['detail'] );
	}
}
