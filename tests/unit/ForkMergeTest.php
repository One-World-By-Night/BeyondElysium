<?php

namespace BeyondElysium\Tests\Unit;

use BeyondElysium\Database\Fork_Merge;
use PHPUnit\Framework\TestCase;

/**
 * 1.0.0-review F-034. A chronicle's copy of a catalog block was a full copy frozen on the day it
 * was made: no later catalog fix reached it. A copy now records what the chronicle changed as it
 * changes it (`stamp()`), and a catalog update rebuilds the copy from the new catalog with those
 * changes laid back over it (`merge()`).
 */
class ForkMergeTest extends TestCase {

	/**
	 * @return array<string,mixed>
	 */
	private function catalog(): array {
		return [
			'alphabetize' => true,
			'items'       => [
				[ 'name' => 'Allies', 'cost' => '1', 'note' => 'Friends' ],
				[ 'name' => 'Resources', 'cost' => '1', 'note' => 'Money' ],
				[ 'name' => 'Iron Will', 'cost' => '3-5' ],
			],
		];
	}

	/**
	 * @return array<string,mixed>
	 */
	private function disciplines(): array {
		return [
			'powers' => [
				[
					'name'   => 'Celerity',
					'levels' => [
						[ 'level' => 1, 'power_name' => 'Alacrity', 'cost' => '3' ],
						[ 'level' => 2, 'power_name' => 'Swiftness', 'cost' => '3' ],
					],
				],
			],
		];
	}

	/**
	 * @param array<string,mixed> $definition
	 * @return array<string,array<string,mixed>>
	 */
	private function by_name( array $definition, string $list = 'items' ): array {
		return array_column( $definition[ $list ], null, 'name' );
	}

	public function test_an_untouched_copy_takes_every_catalog_change(): void {
		$copy    = $this->catalog();
		$updated = $this->catalog();
		$updated['items'][0]['note'] = 'Friends in useful places';
		$updated['items'][]          = [ 'name' => 'Contacts', 'cost' => '1' ];

		$merged = Fork_Merge::merge( $updated, $copy, Fork_Merge::NO_CHANGES );

		$this->assertSame( $updated, $merged );
	}

	public function test_a_value_the_chronicle_changed_survives_a_catalog_change_to_it(): void {
		$stored   = $this->catalog();
		$incoming = $this->catalog();
		$incoming['items'][1]['cost'] = '2';
		$changes  = Fork_Merge::stamp( $stored, $incoming, Fork_Merge::NO_CHANGES );

		$updated = $this->catalog();
		$updated['items'][1]['cost'] = '4';
		$updated['items'][1]['note'] = 'Wealth';

		$resources = $this->by_name( Fork_Merge::merge( $updated, $incoming, $changes ) )['Resources'];
		$this->assertSame( '2', $resources['cost'], "the chronicle's own cost" );
		$this->assertSame( 'Wealth', $resources['note'], 'the catalog fix it never touched' );
	}

	public function test_an_entry_the_chronicle_added_is_kept(): void {
		$stored   = $this->catalog();
		$incoming = $this->catalog();
		$incoming['items'][] = [ 'name' => 'Kindred Contacts', 'cost' => '2' ];
		$changes  = Fork_Merge::stamp( $stored, $incoming, Fork_Merge::NO_CHANGES );

		$merged = $this->by_name( Fork_Merge::merge( $this->catalog(), $incoming, $changes ) );
		$this->assertSame( '2', $merged['Kindred Contacts']['cost'] );
	}

	public function test_an_entry_the_chronicle_removed_stays_removed(): void {
		$stored   = $this->catalog();
		$incoming = $this->catalog();
		array_splice( $incoming['items'], 2, 1 );
		$changes  = Fork_Merge::stamp( $stored, $incoming, Fork_Merge::NO_CHANGES );

		$updated = $this->catalog();
		$updated['items'][2]['note'] = 'Unshakeable';

		$this->assertArrayNotHasKey( 'Iron Will', $this->by_name( Fork_Merge::merge( $updated, $incoming, $changes ) ) );
	}

	public function test_an_entry_the_catalog_drops_goes_unless_the_chronicle_changed_it(): void {
		$stored   = $this->catalog();
		$incoming = $this->catalog();
		$incoming['items'][0]['note'] = 'Our allies';
		$changes  = Fork_Merge::stamp( $stored, $incoming, Fork_Merge::NO_CHANGES );

		$updated = $this->catalog();
		$updated['items'] = [ $updated['items'][2] ];

		$merged = $this->by_name( Fork_Merge::merge( $updated, $incoming, $changes ) );
		$this->assertSame( [ 'Iron Will', 'Allies' ], array_keys( $merged ) );
		$this->assertSame( 'Our allies', $merged['Allies']['note'] );
	}

	public function test_a_block_setting_the_chronicle_changed_survives(): void {
		$stored   = $this->catalog();
		$incoming = $this->catalog();
		$incoming['clan_disciplines'] = [ 'Our Bloodline' => [ 'Celerity' ] ];
		$changes  = Fork_Merge::stamp( $stored, $incoming, Fork_Merge::NO_CHANGES );

		$updated                = $this->catalog();
		$updated['alphabetize'] = false;

		$merged = Fork_Merge::merge( $updated, $incoming, $changes );
		$this->assertSame( [ 'Our Bloodline' => [ 'Celerity' ] ], $merged['clan_disciplines'] );
		$this->assertFalse( $merged['alphabetize'], 'a setting the chronicle never touched follows the catalog' );
	}

	public function test_a_power_level_is_merged_by_its_own_name(): void {
		$stored   = $this->disciplines();
		$incoming = $this->disciplines();
		$incoming['powers'][0]['levels'][0]['approval'] = 'st';
		$changes  = Fork_Merge::stamp( $stored, $incoming, Fork_Merge::NO_CHANGES );

		$updated = $this->disciplines();
		$updated['powers'][0]['levels'][0]['cost'] = '4';
		$updated['powers'][0]['levels'][]          = [ 'level' => 3, 'power_name' => 'Rapidity', 'cost' => '3' ];

		$levels = array_column( Fork_Merge::merge( $updated, $incoming, $changes )['powers'][0]['levels'], null, 'power_name' );
		$this->assertSame( 'st', $levels['Alacrity']['approval'] );
		$this->assertSame( '4', $levels['Alacrity']['cost'] );
		$this->assertArrayHasKey( 'Rapidity', $levels );
	}

	public function test_later_edits_add_to_what_was_recorded(): void {
		$first  = $this->catalog();
		$first['items'][0]['cost'] = '2';
		$changes = Fork_Merge::stamp( $this->catalog(), $first, Fork_Merge::NO_CHANGES );

		$second = $first;
		$second['items'][1]['note'] = 'Cash';
		$changes = Fork_Merge::stamp( $first, $second, $changes );

		$updated = $this->catalog();
		$updated['items'][0]['cost'] = '5';
		$updated['items'][1]['note'] = 'Assets';

		$merged = $this->by_name( Fork_Merge::merge( $updated, $second, $changes ) );
		$this->assertSame( '2', $merged['Allies']['cost'] );
		$this->assertSame( 'Cash', $merged['Resources']['note'] );
	}

	public function test_key_order_is_not_a_change(): void {
		$stored   = $this->catalog();
		$incoming = $this->catalog();
		$incoming['items'][0] = [ 'note' => 'Friends', 'cost' => '1', 'name' => 'Allies' ];

		$this->assertSame( Fork_Merge::NO_CHANGES, Fork_Merge::stamp( $stored, $incoming, Fork_Merge::NO_CHANGES ) );
	}

	public function test_a_copy_made_before_this_records_its_differences_as_its_own(): void {
		$copy = $this->catalog();
		$copy['items'][0]['cost'] = '2';
		$copy['items'][]          = [ 'name' => 'Kindred Contacts', 'cost' => '2' ];
		array_splice( $copy['items'], 1, 1 );

		$changes = Fork_Merge::changes_against( $this->catalog(), $copy );

		$updated = $this->catalog();
		$updated['items'][0]['cost'] = '9';
		$merged  = $this->by_name( Fork_Merge::merge( $updated, $copy, $changes ) );

		$this->assertSame( '2', $merged['Allies']['cost'], 'a difference found is kept' );
		$this->assertArrayHasKey( 'Kindred Contacts', $merged );
		$this->assertArrayHasKey( 'Resources', $merged, 'what the copy lacks arrives - it may be a catalog addition made since' );
	}
	public function test_a_level_the_chronicle_removed_stays_removed(): void {
		$stored   = $this->disciplines();
		$incoming = $this->disciplines();
		array_splice( $incoming['powers'][0]['levels'], 1, 1 );
		$changes  = Fork_Merge::stamp( $stored, $incoming, Fork_Merge::NO_CHANGES );

		$updated = $this->disciplines();
		$updated['powers'][0]['levels'][1]['cost'] = '4';

		$levels = array_column( Fork_Merge::merge( $updated, $incoming, $changes )['powers'][0]['levels'], null, 'power_name' );
		$this->assertSame( [ 'Alacrity' ], array_keys( $levels ) );
	}

	public function test_an_entry_removed_then_added_back_is_the_chronicles_own(): void {
		$removed  = $this->catalog();
		array_splice( $removed['items'], 0, 1 );
		$changes  = Fork_Merge::stamp( $this->catalog(), $removed, Fork_Merge::NO_CHANGES );

		$restored = $removed;
		$restored['items'][] = [ 'name' => 'Allies', 'cost' => '2' ];
		$changes  = Fork_Merge::stamp( $removed, $restored, $changes );

		$merged = $this->by_name( Fork_Merge::merge( $this->catalog(), $restored, $changes ) );
		$this->assertSame( '2', $merged['Allies']['cost'] );
	}

	public function test_an_entry_the_chronicle_added_then_removed_leaves_nothing_behind(): void {
		$added   = $this->catalog();
		$added['items'][] = [ 'name' => 'Kindred Contacts', 'cost' => '2' ];
		$changes = Fork_Merge::stamp( $this->catalog(), $added, Fork_Merge::NO_CHANGES );
		$changes = Fork_Merge::stamp( $added, $this->catalog(), $changes );

		$this->assertSame( Fork_Merge::NO_CHANGES, $changes );
	}

	public function test_a_value_the_chronicle_cleared_stays_cleared(): void {
		$stored   = $this->catalog();
		$incoming = $this->catalog();
		unset( $incoming['items'][0]['note'] );
		$changes  = Fork_Merge::stamp( $stored, $incoming, Fork_Merge::NO_CHANGES );

		$updated = $this->catalog();
		$updated['items'][0]['note'] = 'Friends everywhere';

		$this->assertArrayNotHasKey( 'note', $this->by_name( Fork_Merge::merge( $updated, $incoming, $changes ) )['Allies'] );
	}
	public function test_a_copy_made_before_this_takes_values_the_catalog_added_since(): void {
		$copy    = $this->disciplines();
		$catalog = $this->disciplines();
		$catalog['powers'][0]['name_pt']                   = 'Rapidez';
		$catalog['powers'][0]['levels'][0]['power_name_pt'] = 'Presteza';
		$catalog['traditions']                             = [ 'Thaumaturgy' ];

		$merged = Fork_Merge::merge( $catalog, $copy, Fork_Merge::changes_against( $catalog, $copy ) );

		$this->assertSame( $catalog, $merged );
	}
}
