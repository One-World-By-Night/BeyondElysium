<?php

namespace BeyondElysium\Tests\Unit;

use BeyondElysium\Services\Change_Validator;
use PHPUnit\Framework\TestCase;

/**
 * 1.3.2's alias-routing item, at `Change_Validator`'s own lookup step - "accepting a
 * modify/remove against something already held" (the brief's own description of this
 * consumer). See `Services\Trait_Alias_Resolver` and
 * `BE_PROCESS/releases/1.3.2-design-workflow.md`.
 *
 * The one rule this file exists to prove both ways: a rename may resolve a NEW reference
 * (nothing held yet under either spelling) to the catalog's current name, but it must NEVER
 * repoint the identity of a row `sheet_data` already stores under the OLD spelling - that
 * row is still found and still edited under the name it was actually saved as, exactly as
 * it was before this release, or a rename would silently strand every existing holding.
 *
 * Pure: no database, no WordPress - matches `ChangeValidatorTest`'s own established fixture
 * style.
 */
class ChangeValidatorAliasRoutingTest extends TestCase {

	/** @param bool $with_alias Whether the family/item declares its real `aliases`/`split_from` - the revert-test toggle. */
	private function blocks( bool $with_alias ): array {
		$dry_nile = [ 'name' => 'Path of the Dry Nile' ];
		if ( $with_alias ) {
			$dry_nile['aliases'] = [ 'Path of Dry Nile' ];
		}

		$meditation = [ 'name' => 'Meditation', 'cost' => '2' ];
		if ( $with_alias ) {
			$meditation['aliases'] = [ 'Meditiation' ];
		}

		return [
			'blood-magic' => (object) [
				'section_type' => 'tiered_power',
				'definition'   => (object) [
					'allow_custom' => false,
					'powers'       => [ (object) $dry_nile ],
				],
			],
			'abilities'   => (object) [
				'section_type' => 'trait_list',
				'definition'   => (object) [
					'allow_custom' => false,
					'items'        => [ (object) $meditation, (object) [ 'name' => 'Occult', 'cost' => '2' ] ],
				],
			],
		];
	}

	private function check( string $type, array $data, array $sheet, bool $with_alias ): array {
		return Change_Validator::validate(
			[ 'change_type' => $type, 'change_data' => $data ],
			$this->blocks( $with_alias ),
			$sheet,
			false
		);
	}

	// ---------------------------------------------------------------------------------
	// The fix: a fresh reference under the old name resolves to the catalog's current one.
	// ---------------------------------------------------------------------------------

	public function test_a_new_power_reference_under_its_old_family_name_resolves_to_the_current_one(): void {
		$result = $this->check(
			'add_trait',
			[ 'block_slug' => 'blood-magic', 'trait' => [ 'name' => 'Path of Dry Nile', 'level' => 1 ] ],
			[],
			true
		);

		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'Path of the Dry Nile', $result['change_data']['trait']['name'] );
	}

	public function test_a_new_item_reference_under_its_old_name_resolves_to_the_current_one(): void {
		$result = $this->check(
			'add_trait',
			[ 'block_slug' => 'abilities', 'trait' => [ 'name' => 'Meditiation', 'count' => 3 ] ],
			[],
			true
		);

		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'Meditation', $result['change_data']['trait']['name'] );
	}

	/**
	 * Revert-test, watched failing first: with the same block declaring no `aliases` at all
	 * (the pre-1.3.2 shape), the identical request reproduces the pre-fix defect - refused,
	 * not silently priced against the wrong name.
	 */
	public function test_without_the_declared_alias_the_same_request_is_refused_not_silently_wrong(): void {
		$result = $this->check(
			'add_trait',
			[ 'block_slug' => 'blood-magic', 'trait' => [ 'name' => 'Path of Dry Nile', 'level' => 1 ] ],
			[],
			false
		);

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'unknown_power', $result['code'] );
	}

	// ---------------------------------------------------------------------------------
	// Non-destructive: a row already held under the OLD spelling keeps that identity.
	// ---------------------------------------------------------------------------------

	/**
	 * The dangerous case this class's own docblock warns about: a family the catalog
	 * renamed, held under sheet_data's OLD spelling. A `remove_trait` must still find and
	 * remove THAT row - if the alias check ran unconditionally and rewrote `trait.name` to
	 * the catalog's current spelling first, `Change_Engine::apply_to_sheet()` would then
	 * search for a row that does not exist under that name and silently do nothing.
	 */
	public function test_removing_a_row_already_held_under_the_old_name_keeps_the_old_name(): void {
		$sheet = [ 'blood-magic' => [ [ 'name' => 'Path of Dry Nile', 'level' => 3 ] ] ];

		$result = $this->check(
			'remove_trait',
			[ 'block_slug' => 'blood-magic', 'trait' => [ 'name' => 'Path of Dry Nile' ] ],
			$sheet,
			true
		);

		$this->assertTrue( $result['ok'] );
		$this->assertSame(
			'Path of Dry Nile',
			$result['change_data']['trait']['name'],
			'the held row is still stored under the old spelling - renaming it here would orphan it'
		);
	}

	public function test_removing_a_trait_list_row_already_held_under_the_old_name_keeps_the_old_name(): void {
		$sheet = [ 'abilities' => [ [ 'name' => 'Meditiation', 'count' => 4 ] ] ];

		$result = $this->check(
			'remove_trait',
			[ 'block_slug' => 'abilities', 'trait' => [ 'name' => 'Meditiation' ] ],
			$sheet,
			true
		);

		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'Meditiation', $result['change_data']['trait']['name'] );
	}

	// ---------------------------------------------------------------------------------
	// Over-correction guard: an ordinary, unrenamed name is completely unaffected.
	// ---------------------------------------------------------------------------------

	public function test_an_ordinary_catalog_name_validates_exactly_as_before(): void {
		$result = $this->check(
			'add_trait',
			[ 'block_slug' => 'abilities', 'trait' => [ 'name' => 'Occult', 'count' => 2 ] ],
			[],
			true
		);

		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'Occult', $result['change_data']['trait']['name'] );
	}

	public function test_a_name_matching_nothing_at_all_is_still_refused(): void {
		$result = $this->check(
			'add_trait',
			[ 'block_slug' => 'abilities', 'trait' => [ 'name' => 'Not A Real Ability' ] ],
			[],
			true
		);

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'unknown_trait', $result['code'] );
	}
}
