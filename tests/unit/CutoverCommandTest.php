<?php

namespace BeyondElysium\Tests\Unit;

use BeyondElysium\CLI\Cutover_Command;
use PHPUnit\Framework\TestCase;

/**
 * `wp be cutover`'s reports, without a shell: the numbers the owner reads at a dry run, the CSV a
 * Storyteller can open, and the words a refusal uses. The command itself only wires these to
 * `Catalog_Cutover`; what it does is proved there and exercised against a real install in R8.
 */
class CutoverCommandTest extends TestCase {

	/** @return array<string,mixed> */
	private function plan(): array {
		return [
			'available'             => true,
			'declared'              => false,
			'characters'            => 278,
			'characters_changed'    => 190,
			'totals'                => [
				'moved_rows'    => 7891,
				'rekeyed'       => 1204,
				'respelled'     => 38,
				'kept_custom'   => 456,
				'tiered_custom' => 12,
				'by_tier'       => [ 'exact' => 900, 'label' => 304 ],
				'by_reason'     => [ 'no_match' => 300, 'has_custom_price' => 156 ],
			],
			'by_game'               => [
				'kony'   => [ 'characters' => 200, 'changed' => 150, 'rekeyed' => 900, 'kept_custom' => 300, 'untouched' => 120 ],
				'boston' => [ 'characters' => 78, 'changed' => 40, 'rekeyed' => 304, 'kept_custom' => 156, 'untouched' => 90 ],
			],
			'retention_gaps'        => [ [ 'character_id' => 1 ] ],
			'duplicates'            => [ [], [] ],
			'unmapped_retired_data' => [ [] ],
			'pending_changes'       => [ [], [], [], [] ],
			'untouched'             => [ 'characters' => 210, 'with_player' => 12 ],
		];
	}

	/** @param array<int,array{what:string,count:string}> $rows @return array<string,string> */
	private function by_label( array $rows ): array {
		return array_column( $rows, 'count', 'what' );
	}

	public function test_the_summary_carries_every_number_read_at_a_dry_run(): void {
		$rows = $this->by_label( Cutover_Command::summary_rows( $this->plan() ) );

		$this->assertSame( '278', $rows['Characters'] );
		$this->assertSame( '190', $rows['Characters that would change'] );
		$this->assertSame( '7891', $rows['Rows moved to their new blocks'] );
		$this->assertSame( '1204', $rows['Custom entries matched to the catalog'] );
		$this->assertSame( '38', $rows['Catalog rows renamed to the declared spelling'] );
		$this->assertSame( '456', $rows['Custom entries left custom'] );
		$this->assertSame( '12', $rows['Custom powers (left as they are)'] );
		$this->assertSame( '1', $rows['Retention gaps (apply refuses while any exist)'] );
		$this->assertSame( '2', $rows['Identities already doubled before anything moves'] );
		$this->assertSame( '1', $rows['Rows held under a retired block with no new home'] );
		$this->assertSame( '4', $rows['Pending changes that would be rewritten'] );
	}

	public function test_why_entries_were_left_custom_is_listed_beneath_the_total(): void {
		$labels = array_column( Cutover_Command::summary_rows( $this->plan() ), 'what' );

		$kept = array_search( 'Custom entries left custom', $labels, true );
		$this->assertSame( '  no_match', $labels[ $kept + 1 ] );
		$this->assertSame( '  has_custom_price', $labels[ $kept + 2 ] );
	}

	public function test_a_plan_from_before_respelling_existed_reads_zero_not_an_error(): void {
		$plan = $this->plan();
		unset( $plan['totals']['respelled'] );

		$rows = $this->by_label( Cutover_Command::summary_rows( $plan ) );

		$this->assertSame( '0', $rows['Catalog rows renamed to the declared spelling'] );
	}

	public function test_the_untouched_line_says_how_many_will_need_a_player_put_back(): void {
		$rows = $this->by_label( Cutover_Command::summary_rows( $this->plan() ) );

		$this->assertSame( '210 (12 with a player assigned)', $rows['Exactly as imported, nothing attached (could be re-imported instead)'] );
	}

	public function test_a_plan_from_before_the_untouched_count_existed_reads_zero_not_an_error(): void {
		$plan = $this->plan();
		unset( $plan['untouched'] );

		$rows = $this->by_label( Cutover_Command::summary_rows( $plan ) );

		$this->assertSame( '0 (0 with a player assigned)', $rows['Exactly as imported, nothing attached (could be re-imported instead)'] );
	}

	public function test_one_line_per_chronicle(): void {
		$this->assertSame(
			[
				[ 'chronicle' => 'kony', 'characters' => 200, 'changed' => 150, 'matched' => 900, 'kept_custom' => 300, 'untouched' => 120 ],
				[ 'chronicle' => 'boston', 'characters' => 78, 'changed' => 40, 'matched' => 304, 'kept_custom' => 156, 'untouched' => 90 ],
			],
			Cutover_Command::chronicle_rows( $this->plan() )
		);
	}

	public function test_the_csv_has_a_header_and_a_line_for_each_outcome(): void {
		$lines = Cutover_Command::csv_lines( [
			[
				'character_id' => 7, 'character' => 'Hitchens', 'game' => 'kony', 'block_from' => 'met-abilities', 'block_to' => 'vampire-abilities',
				'from' => 'Lore: Kindred', 'outcome' => 'rekeyed', 'to' => 'Lore', 'tier' => 'canonical', 'home' => 'specialization', 'label' => 'Kindred',
			],
			[
				'character_id' => 7, 'character' => 'Hitchens', 'game' => 'kony', 'block_from' => 'met-abilities', 'block_to' => 'vampire-abilities',
				'from' => 'Basket Weaving', 'outcome' => 'kept', 'reason' => 'no_match', 'suggestions' => [ 'Basketry', 'Weaving' ],
			],
			[
				'character_id' => 8, 'character' => 'Ashford', 'game' => 'kony', 'block_from' => 'met-merits', 'block_to' => 'vampire-merits',
				'from' => 'Iron Will', 'outcome' => 'kept', 'reason' => 'collision', 'would_be' => 'Iron Will (Sabbat)',
			],
			[
				'character_id' => 9, 'character' => 'Marlowe', 'game' => 'boston', 'block_from' => 'met-abilities', 'block_to' => 'vampire-abilities',
				'from' => 'Fortune-telling', 'outcome' => 'rekeyed', 'to' => 'Fortune-Telling', 'tier' => 'normalized', 'home' => null, 'label' => null, 'catalog' => true,
			],
		] );

		$this->assertCount( 5, $lines );
		$this->assertSame( [ 'character_id', 'character', 'chronicle', 'from_block', 'to_block', 'entry', 'outcome', 'becomes', 'why', 'label_goes_to', 'label', 'suggestions', 'entry_kind' ], $lines[0] );
		$this->assertSame( [ '7', 'Hitchens', 'kony', 'met-abilities', 'vampire-abilities', 'Lore: Kindred', 'rekeyed', 'Lore', 'canonical', 'specialization', 'Kindred', '', 'custom' ], $lines[1] );
		$this->assertSame( [ '7', 'Hitchens', 'kony', 'met-abilities', 'vampire-abilities', 'Basket Weaving', 'kept', '', 'no_match', '', '', 'Basketry | Weaving', 'custom' ], $lines[2] );
		$this->assertSame( [ '8', 'Ashford', 'kony', 'met-merits', 'vampire-merits', 'Iron Will', 'kept', 'Iron Will (Sabbat)', 'collision', '', '', '', 'custom' ], $lines[3] );
		$this->assertSame( [ '9', 'Marlowe', 'boston', 'met-abilities', 'vampire-abilities', 'Fortune-telling', 'rekeyed', 'Fortune-Telling', 'normalized', '', '', '', 'catalog' ], $lines[4] );
	}

	public function test_an_empty_plan_is_still_a_header(): void {
		$this->assertCount( 1, Cutover_Command::csv_lines( [] ) );
	}

	public function test_every_refusal_says_what_and_that_nothing_changed(): void {
		$cases = [
			[ [ 'reason' => 'no_declared_catalog' ], 'no declared catalog' ],
			[ [ 'reason' => 'declared_blocks_missing', 'missing' => [ 'vampire -> vampire-abilities', 'mage -> mage-spheres' ] ], 'vampire -> vampire-abilities; mage -> mage-spheres' ],
			[ [ 'reason' => 'retention_gaps', 'retention_gaps' => [ [], [], [] ] ], '3 catalog rows would be lost' ],
			[ [ 'reason' => 'characters_outside_any_chronicle', 'planned' => 270, 'on_install' => 278 ], '278 characters exist but only 270' ],
			[ [ 'reason' => 'something_new' ], 'refused' ],
		];
		foreach ( $cases as [ $result, $expected ] ) {
			$message = Cutover_Command::refusal( $result );

			$this->assertStringContainsString( $expected, $message );
			$this->assertStringContainsString( 'Nothing was changed', $message );
		}
	}
}
