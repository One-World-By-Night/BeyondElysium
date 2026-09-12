<?php

namespace BeyondElysium\Tests\Unit;

use BeyondElysium\Services\Background_Ledger;
use PHPUnit\Framework\TestCase;

/**
 * apply_spends() is pure and total - no database access, the same pattern as
 * Action_Allocator::resolve_common_subactions() (ActionAllocatorTest). Covers
 * the debit rule exactly as specified: base comes from unused, not total, so
 * carry_unused keeps working unchanged; over_budget is a flag, never a clamp.
 *
 * @see BE_PROCESS/background-ledger-apr-design.md §5.4
 */
class BackgroundLedgerTest extends TestCase {

	private function subaction( string $name, int $unused, int $total = 10 ): array {
		return [ 'name' => $name, 'level' => 1, 'total' => $total, 'unused' => $unused, 'growth' => 0 ];
	}

	public function test_no_entries_leaves_every_subaction_unchanged(): void {
		$subactions = [ $this->subaction( 'Bureaucracy', 4 ) ];
		$result     = Background_Ledger::apply_spends( $subactions, [] );

		$this->assertSame( 4, $result['subactions'][0]['unused'] );
		$this->assertSame( 0, $result['subactions'][0]['spent'] );
		$this->assertFalse( $result['subactions'][0]['over_budget'] );
		$this->assertSame( [], $result['unbudgeted_spends'] );
	}

	public function test_a_spend_under_budget_debits_unused_and_is_not_over_budget(): void {
		$subactions = [ $this->subaction( 'Bureaucracy', 4 ) ];
		$entries    = [ [ 'name' => 'Bureaucracy', 'cost' => 1 ] ];

		$result = Background_Ledger::apply_spends( $subactions, $entries );

		$this->assertSame( 3, $result['subactions'][0]['unused'] );
		$this->assertSame( 1, $result['subactions'][0]['spent'] );
		$this->assertFalse( $result['subactions'][0]['over_budget'] );
	}

	public function test_multiple_entries_for_the_same_name_sum_their_cost(): void {
		$subactions = [ $this->subaction( 'Bureaucracy', 4 ) ];
		$entries    = [
			[ 'name' => 'Bureaucracy', 'cost' => 1 ],
			[ 'name' => 'Bureaucracy', 'cost' => 2 ],
		];

		$result = Background_Ledger::apply_spends( $subactions, $entries );

		$this->assertSame( 1, $result['subactions'][0]['unused'] );
		$this->assertSame( 3, $result['subactions'][0]['spent'] );
	}

	public function test_an_entry_with_no_explicit_cost_defaults_to_one(): void {
		$subactions = [ $this->subaction( 'Bureaucracy', 4 ) ];
		$entries    = [ [ 'name' => 'Bureaucracy' ] ];

		$result = Background_Ledger::apply_spends( $subactions, $entries );

		$this->assertSame( 1, $result['subactions'][0]['spent'] );
	}

	public function test_spending_past_the_budget_clamps_unused_to_zero_and_flags_over_budget(): void {
		$subactions = [ $this->subaction( 'Bureaucracy', 2 ) ];
		$entries    = [ [ 'name' => 'Bureaucracy', 'cost' => 5 ] ];

		$result = Background_Ledger::apply_spends( $subactions, $entries );

		$this->assertSame( 0, $result['subactions'][0]['unused'], 'unused never goes negative for display' );
		$this->assertSame( 5, $result['subactions'][0]['spent'], 'the real spend total is preserved even though it exceeds the budget' );
		$this->assertTrue( $result['subactions'][0]['over_budget'] );
	}

	public function test_base_is_taken_from_unused_not_total_so_carry_unused_keeps_working(): void {
		// unused (3) already reflects a prior week's partial spend, distinct from total (10).
		$subactions = [ $this->subaction( 'Bureaucracy', 3, 10 ) ];
		$entries    = [ [ 'name' => 'Bureaucracy', 'cost' => 1 ] ];

		$result = Background_Ledger::apply_spends( $subactions, $entries );

		$this->assertSame( 2, $result['subactions'][0]['unused'], 'debits against unused (3), not total (10)' );
		$this->assertSame( 10, $result['subactions'][0]['total'], 'total is never touched' );
	}

	public function test_an_entry_matching_no_subaction_is_reported_as_unbudgeted_and_debits_nothing(): void {
		$subactions = [ $this->subaction( 'Bureaucracy', 4 ) ];
		$entries    = [ [ 'name' => 'Allies', 'cost' => 2 ] ];

		$result = Background_Ledger::apply_spends( $subactions, $entries );

		$this->assertSame( 4, $result['subactions'][0]['unused'], 'Bureaucracy is untouched by a spend under a different name' );
		$this->assertSame( 0, $result['subactions'][0]['spent'] );
		$this->assertSame( [ 'Allies' => 2 ], $result['unbudgeted_spends'] );
	}

	public function test_multiple_subactions_are_debited_independently(): void {
		$subactions = [ $this->subaction( 'Personal', 3 ), $this->subaction( 'Bureaucracy', 4 ) ];
		$entries    = [ [ 'name' => 'Bureaucracy', 'cost' => 2 ] ];

		$result  = Background_Ledger::apply_spends( $subactions, $entries );
		$by_name = array_column( $result['subactions'], null, 'name' );

		$this->assertSame( 3, $by_name['Personal']['unused'], 'Personal is untouched' );
		$this->assertSame( 2, $by_name['Bureaucracy']['unused'] );
	}
}
