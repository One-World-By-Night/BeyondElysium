<?php

namespace BeyondElysium\Tests\Unit;

use BeyondElysium\Services\Cost_Engine;
use PHPUnit\Framework\TestCase;

/**
 * 1.0.0-review F-107. The editor offers a variable-cost item's choices from `src/lib/costChoices.ts`;
 * the server prices it by `Cost_Engine::parse_cost_rule()`. Both read the same cases, so the
 * editor never offers a cost the server would price differently.
 */
class CostChoicesParityTest extends TestCase {

	public function test_the_server_reads_every_cost_as_the_editor_offers_it(): void {
		$cases = json_decode( (string) file_get_contents( BE_PLUGIN_ROOT . '/tests/fixtures/cost-choices.json' ), true );
		$this->assertNotEmpty( $cases );

		foreach ( $cases as $case ) {
			$rule = Cost_Engine::parse_cost_rule( $case['cost'] );
			if ( $case['choices'] === null ) {
				$this->assertTrue(
					$rule['type'] === 'fixed' || ( $rule['type'] === 'range' && $rule['values'][0] > $rule['values'][1] ),
					"\"{$case['cost']}\" offers no choice, so the server must price it one way"
				);
				continue;
			}

			$offered = $rule['type'] === 'range' ? range( $rule['values'][0], $rule['values'][1] ) : $rule['values'];
			$this->assertSame( $case['choices'], $offered, "\"{$case['cost']}\"" );
			foreach ( $case['choices'] as $choice ) {
				$this->assertSame( $choice, Cost_Engine::price_item_cost( $case['cost'], $choice ), "\"{$case['cost']}\" at {$choice}" );
			}
		}
	}
}
