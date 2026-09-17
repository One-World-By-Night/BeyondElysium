<?php

namespace BeyondElysium\Tests\Unit;

use BeyondElysium\Services\Query_Engine;
use PHPUnit\Framework\TestCase;

/**
 * `Query_Engine::validate_conditions()`'s own 'derived'-field guard: a field whose
 * field-map source is 'derived' is normally refused here, since most of them
 * (`physicalmax`, `socialmax`, ...) have nothing `resolve_value()` can actually compute
 * for a stored condition. `random` was the one pre-existing exception; 1.1.0 F1/F2 added
 * `group`/`position` as a second, real one - both are fully computed by
 * `resolve_value()`'s own 'derived' case, and blocking them here would make a
 * Storyteller's "Restricted to Faction contains <coterie>" (1.1.0 §7 trace 5, a real,
 * load-bearing audience rule) permanently unusable. Found writing
 * `CoterieWorkflowTest`, which failed with exactly this 400 before the fix.
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
	 * The guard itself must still catch a genuinely unusable derived field - this proves
	 * the fix widened the exception list, not removed the check outright.
	 */
	public function test_an_unrelated_derived_field_is_still_refused(): void {
		$problem = Query_Engine::validate_conditions( [
			[ 'field' => 'physicalmax', 'operator' => 'equals', 'value' => 3 ],
		] );

		$this->assertNotNull( $problem );
		$this->assertStringContainsString( 'not a stored value', $problem['message'] );
	}
}
