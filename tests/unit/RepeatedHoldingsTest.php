<?php

namespace BeyondElysium\Tests\Unit;

use BeyondElysium\Services\Repeated_Holdings;
use PHPUnit\Framework\TestCase;

/**
 * A path held under one tradition spelled two ways is a repeat; rows for each level of a path are not.
 */
class RepeatedHoldingsTest extends TestCase {

	public function test_a_path_held_under_two_spellings_of_one_tradition_is_reported(): void {
		$found = Repeated_Holdings::in_sheet( [
			'vampire-blood-magic' => [
				[ 'name' => 'Awakening of the Steel', 'level' => 5, 'tradition' => 'Dur An Ki', 'custom' => true ],
				[ 'name' => 'Alchemy', 'level' => 2, 'tradition' => 'Sadhana' ],
				[ 'name' => 'Awakening of the Steel', 'level' => 4, 'tradition' => 'Dur-An-Ki' ],
			],
		] );

		$this->assertSame(
			[ [ 'block_slug' => 'vampire-blood-magic', 'name' => 'Awakening of the Steel', 'traditions' => [ 'Dur An Ki', 'Dur-An-Ki' ], 'levels' => [ 5, 4 ] ] ],
			$found
		);
	}

	public function test_a_misspelling_the_catalog_names_counts_as_the_same_tradition(): void {
		$sheet = [ 'vampire-blood-magic' => [
			[ 'name' => 'Alchemy', 'level' => 2, 'tradition' => 'Sadhana' ],
			[ 'name' => 'Alchemy', 'level' => 2, 'tradition' => 'Sadhanna' ],
		] ];

		$this->assertSame( [], Repeated_Holdings::in_sheet( $sheet ), 'with no catalog to consult, the two spellings read as two traditions' );
		$found = Repeated_Holdings::in_sheet( $sheet, [ 'Sadhana' ], [ 'Sadhanna' => 'Sadhana' ] );
		$this->assertSame( [ 'Sadhana', 'Sadhanna' ], $found[0]['traditions'] );
	}

	public function test_rows_for_each_level_of_one_path_are_not_repeats(): void {
		$this->assertSame( [], Repeated_Holdings::in_sheet( [
			'vampire-blood-magic' => [
				[ 'name' => 'Sepulchre Path', 'level' => 1, 'tradition' => 'Necromancy' ],
				[ 'name' => 'Sepulchre Path', 'level' => 2, 'tradition' => 'Necromancy' ],
			],
			'vampire-disciplines' => [
				[ 'name' => 'Animalism', 'level' => 1 ],
				[ 'name' => 'Animalism', 'level' => 2 ],
			],
		] ) );
	}

	public function test_the_same_path_in_two_different_traditions_is_not_a_repeat(): void {
		$this->assertSame( [], Repeated_Holdings::in_sheet( [
			'vampire-blood-magic' => [
				[ 'name' => 'Path of Blood', 'level' => 3, 'tradition' => 'Thaumaturgy (Camarilla)' ],
				[ 'name' => 'Path of Blood', 'level' => 2, 'tradition' => 'Thaumaturgy (Anarch)' ],
			],
		] ) );
	}

	public function test_a_sheet_with_nothing_to_compare_reports_nothing(): void {
		$this->assertSame( [], Repeated_Holdings::in_sheet( [] ) );
		$this->assertSame( [], Repeated_Holdings::in_sheet( [ 'vampire-identity' => [ 'Clan' => 'Brujah' ], 'vampire-merits' => [ [ 'name' => 'Iron Will' ] ] ] ) );
	}
}
