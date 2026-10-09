<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\REST\Import_Controller;
use WP_UnitTestCase;

/**
 * `Import_Controller::gv_query_to_target_query()` converts Grapevine's raw query shape into Beyond Elysium's own
 * single-condition target_query, or refuses with a reason.
 */
class GvQueryToTargetQueryThreadTest extends WP_UnitTestCase {

	private function convert( ?array $query ): array {
		$method = new \ReflectionMethod( Import_Controller::class, 'gv_query_to_target_query' );
		$method->setAccessible( true );
		return $method->invoke( null, $query );
	}

	public function test_no_query_at_all_converts_to_no_target_and_no_reason(): void {
		$this->assertSame( [ 'target_query' => null, 'reason' => null ], $this->convert( null ) );
	}

	public function test_a_query_with_no_clauses_converts_to_no_target_and_no_reason(): void {
		$this->assertSame( [ 'target_query' => null, 'reason' => null ], $this->convert( [ 'clauses' => [] ] ) );
	}

	public function test_a_single_text_equals_clause_converts(): void {
		$result = $this->convert( [
			'clauses' => [
				[ 'key' => 'clan', 'comparison' => 1, 'comp_not' => false, 'find' => 'Toreador', 'number' => 0.0 ],
			],
		] );

		$this->assertSame(
			[ 'field' => 'clan', 'operator' => 'equals', 'value' => 'Toreador' ],
			$result['target_query']
		);
		$this->assertNull( $result['reason'] );
	}

	public function test_a_single_numeric_at_least_clause_reads_the_number_not_the_find_string(): void {
		$result = $this->convert( [
			'clauses' => [
				[ 'key' => 'generation', 'comparison' => 2, 'comp_not' => false, 'find' => '', 'number' => 9.0 ],
			],
		] );

		$this->assertSame(
			[ 'field' => 'generation', 'operator' => 'at_least', 'value' => 9.0 ],
			$result['target_query']
		);
	}

	public function test_more_than_one_clause_refuses_with_a_reason(): void {
		$result = $this->convert( [
			'clauses' => [
				[ 'key' => 'clan', 'comparison' => 1, 'comp_not' => false, 'find' => 'Toreador', 'number' => 0.0 ],
				[ 'key' => 'generation', 'comparison' => 4, 'comp_not' => false, 'find' => '', 'number' => 10.0 ],
			],
		] );

		$this->assertNull( $result['target_query'] );
		$this->assertStringContainsString( 'more than one condition', $result['reason'] );
	}

	public function test_a_negated_clause_refuses_with_a_reason(): void {
		$result = $this->convert( [
			'clauses' => [
				[ 'key' => 'clan', 'comparison' => 1, 'comp_not' => true, 'find' => 'Toreador', 'number' => 0.0 ],
			],
		] );

		$this->assertNull( $result['target_query'] );
		$this->assertStringContainsString( 'NOT', $result['reason'] );
	}

	public function test_an_unknown_comparison_code_refuses_with_a_reason(): void {
		$result = $this->convert( [
			'clauses' => [
				[ 'key' => 'clan', 'comparison' => 99, 'comp_not' => false, 'find' => 'Toreador', 'number' => 0.0 ],
			],
		] );

		$this->assertNull( $result['target_query'] );
		$this->assertStringContainsString( "doesn't recognize", $result['reason'] );
	}

	public function test_a_named_count_operator_refuses_since_target_query_holds_only_one_value(): void {
		$result = $this->convert( [
			'clauses' => [
				[ 'key' => 'disciplines', 'comparison' => 6, 'comp_not' => false, 'find' => 'Celerity', 'number' => 3.0 ],
			],
		] );

		$this->assertNull( $result['target_query'] );
		$this->assertStringContainsString( 'trait name and a count', $result['reason'] );
	}

	public function test_an_unknown_field_refuses_with_query_engines_own_message(): void {
		$result = $this->convert( [
			'clauses' => [
				[ 'key' => 'no_such_field', 'comparison' => 1, 'comp_not' => false, 'find' => 'X', 'number' => 0.0 ],
			],
		] );

		$this->assertNull( $result['target_query'] );
		$this->assertStringContainsString( 'Unknown field', $result['reason'] );
	}
}
