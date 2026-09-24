<?php

namespace BeyondElysium\Tests\Unit;

use BeyondElysium\Services\Query_Engine;
use PHPUnit\Framework\TestCase;

/**
 * `Query_Engine::validate_conditions()`'s own 'derived'-field guard: a field whose field-map source is 'derived' is
 * normally refused here.
 */
class QueryConditionValidationTest extends TestCase {

	public function test_group_is_a_valid_audience_rule_condition(): void {
		$problem = Query_Engine::validate_conditions( [
			[ 'field' => 'group', 'operator' => 'contains', 'find' => 'Coterie of Thorns' ],
		] );

		$this->assertNull( $problem );
	}

	public function test_position_is_a_valid_audience_rule_condition(): void {
		$problem = Query_Engine::validate_conditions( [
			[ 'field' => 'position', 'operator' => 'equals', 'find' => 'Prince' ],
		] );

		$this->assertNull( $problem );
	}

	public function test_random_is_still_a_valid_condition(): void {
		$problem = Query_Engine::validate_conditions( [
			[ 'field' => 'random', 'operator' => 'at_least', 'value' => 50 ],
		] );

		$this->assertNull( $problem );
	}

	/**
	 * The guard itself must still catch a genuinely unusable derived field.
	 */
	public function test_an_unrelated_derived_field_is_still_refused(): void {
		$problem = Query_Engine::validate_conditions( [
			[ 'field' => 'physicalmax', 'operator' => 'equals', 'value' => 3 ],
		] );

		$this->assertNotNull( $problem );
		$this->assertStringContainsString( 'not a stored value', $problem['message'] );
	}
}
