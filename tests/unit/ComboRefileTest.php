<?php

namespace BeyondElysium\Tests\Unit;

use BeyondElysium\Services\Combo_Refile;
use BeyondElysium\Services\Trait_Mapper;
use PHPUnit\Framework\TestCase;

/**
 * Reading a combo out of its label, and moving combos held as picks in a power list into the combo list beside it.
 */
class ComboRefileTest extends TestCase {

	private const PAIRS = [ 'vampire-disciplines' => 'vampire-combo-disciplines' ];

	/**
	 * @return array<string,array{0:string,1:?string}>
	 */
	public static function labels(): array {
		return [
			'label and colon'            => [ 'Combo: Draw Fire', 'Draw Fire' ],
			'short label'                => [ 'Combi: Draw Fire', 'Draw Fire' ],
			'long label'                 => [ 'Combination: Draw Fire', 'Draw Fire' ],
			'label with a noun'          => [ 'Combi. Discipline: Draw Fire', 'Draw Fire' ],
			'label run into the name'    => [ 'Combo Draw Fire', 'Draw Fire' ],
			'label then a dash'          => [ 'Combo - Quicken Sight', 'Quicken Sight' ],
			'label then an en dash'      => [ "Combo \u{2013} Quicken Sight", 'Quicken Sight' ],
			'plural label'               => [ 'Combinations Armory of the Abyss (Fortitude 3)', 'Armory of the Abyss (Fortitude 3)' ],
			'misspelled label'           => [ 'Combiniation', '' ],
			'label in capitals'          => [ 'COMBO', '' ],
			'bare stem'                  => [ 'Comb', '' ],
			'label and noun alone'       => [ 'Combo Dis', '' ],
			'divider row'                => [ 'Combo Powers-----------------------', '' ],
			'hash divider'               => [ 'combos #', '' ],
			'a word starting like combo' => [ 'Combat Senses', null ],
			'no label at all'            => [ 'Celerity', null ],
		];
	}

	/**
	 * @dataProvider labels
	 */
	public function test_combo_name_reads_the_name_after_the_label( string $raw, ?string $expected ): void {
		$this->assertSame( $expected, Trait_Mapper::combo_name( $raw ) );
	}

	public function test_a_custom_pick_labelled_as_a_combo_names_its_power(): void {
		$this->assertSame( 'Spy Master (&)', Combo_Refile::combo_of( [ 'name' => 'Combo', 'power_name' => 'Spy Master (&)', 'tier' => '***', 'custom' => true ] ) );
		$this->assertSame( 'Draw Fire', Combo_Refile::combo_of( [ 'name' => 'Combo Draw Fire', 'power_name' => 'Combo Draw Fire', 'custom' => true ] ) );
	}

	public function test_a_divider_a_catalog_pick_and_an_ordinary_power_are_not_combos(): void {
		$this->assertNull( Combo_Refile::combo_of( [ 'name' => 'Combo Powers-----', 'power_name' => 'Combo Powers-----', 'custom' => true ] ) );
		$this->assertNull( Combo_Refile::combo_of( [ 'name' => 'Combo', 'power_name' => 'Draw Fire' ] ), 'only a custom pick' );
		$this->assertNull( Combo_Refile::combo_of( [ 'name' => 'Celerity', 'power_name' => 'Blink', 'custom' => true ] ) );
		$this->assertNull( Combo_Refile::combo_of( [ 'name' => 'Celerity', 'level' => 3 ] ) );
	}

	public function test_combos_move_with_a_held_level_as_their_cost_and_everything_else_stays(): void {
		$sheet = [
			'vampire-disciplines'       => [
				[ 'name' => 'Celerity', 'level' => 5 ],
				[ 'name' => 'Combo', 'power_name' => 'Mortal Skin', 'tier' => '***', 'level' => 5, 'custom' => true ],
				[ 'name' => 'Combi', 'power_name' => 'Spy Master (&)', 'tier' => '***', 'custom' => true ],
				[ 'name' => 'Combo Powers-----', 'power_name' => 'Combo Powers-----', 'tier' => '***', 'custom' => true ],
			],
			'vampire-combo-disciplines' => [ [ 'name' => 'Draw Fire', 'count' => 12 ] ],
		];

		$result = Combo_Refile::refile_sheet( $sheet, self::PAIRS );

		$this->assertSame(
			[
				[ 'name' => 'Celerity', 'level' => 5 ],
				[ 'name' => 'Combo Powers-----', 'power_name' => 'Combo Powers-----', 'tier' => '***', 'custom' => true ],
			],
			$result['sheet_data']['vampire-disciplines']
		);
		$this->assertSame(
			[
				[ 'name' => 'Draw Fire', 'count' => 12 ],
				[ 'name' => 'Mortal Skin', 'count' => 5, 'custom' => true ],
				[ 'name' => 'Spy Master (&)', 'custom' => true ],
			],
			$result['sheet_data']['vampire-combo-disciplines']
		);
		$this->assertSame( [ 'moved', 'moved' ], array_column( $result['records'], 'outcome' ) );
		$this->assertSame( [ 5, null ], array_column( $result['records'], 'cost' ) );
	}

	public function test_a_combo_the_combo_list_already_holds_is_dropped_not_doubled(): void {
		$sheet = [
			'vampire-disciplines'       => [ [ 'name' => 'Combo', 'power_name' => 'Draw Fire', 'level' => 4, 'custom' => true ] ],
			'vampire-combo-disciplines' => [ [ 'name' => 'Draw Fire', 'count' => 12 ] ],
		];

		$result = Combo_Refile::refile_sheet( $sheet, self::PAIRS );

		$this->assertSame( [], $result['sheet_data']['vampire-disciplines'] );
		$this->assertSame( [ [ 'name' => 'Draw Fire', 'count' => 12 ] ], $result['sheet_data']['vampire-combo-disciplines'] );
		$this->assertSame( 'duplicate', $result['records'][0]['outcome'] );
	}

	public function test_a_sheet_with_nothing_to_move_comes_back_unchanged(): void {
		$sheet = [ 'vampire-disciplines' => [ [ 'name' => 'Celerity', 'level' => 2 ] ] ];

		$result = Combo_Refile::refile_sheet( $sheet, self::PAIRS );

		$this->assertSame( $sheet, $result['sheet_data'] );
		$this->assertSame( [], $result['records'] );
	}

	public function test_the_pairs_come_from_the_import_map(): void {
		$this->assertSame( 'vampire-combo-disciplines', Combo_Refile::pairs()['vampire']['vampire-disciplines'] ?? null );
	}
}
