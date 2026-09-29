<?php

namespace BeyondElysium\Tests\Unit;

use BeyondElysium\Database\Fork_Merge;
use PHPUnit\Framework\TestCase;

/**
 * A chronicle's copy of a catalog block records what the chronicle changed as it changes it (`stamp()`), and a
 * catalog update merges over those changes.
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
	 * A tiered power block with its cost table and a family's Elder and Master picks.
	 *
	 * @return array<string,mixed>
	 */
	private function ruled(): array {
		return [
			'_meta'  => [
				'ranks'       => [ 'basic', 'intermediate', 'advanced', 'elder', 'master' ],
				'costs'       => [ 'basic' => 3, 'intermediate' => 6, 'advanced' => 9, 'elder' => 12, 'master' => 15 ],
				'out_of_type' => [ 'basic' => '+1', 'elder' => '+1' ],
			],
			'powers' => [
				[
					'name'   => 'Animalism',
					'levels' => [
						[ 'level' => 1, 'tier' => 'basic', 'power_name' => 'Feral Whispers', 'cost' => '3' ],
						[ 'level' => 2, 'tier' => 'basic', 'power_name' => 'Beckoning', 'cost' => '3' ],
					],
					'elder'  => [
						'elder'  => [
							[ 'level' => null, 'tier' => 'elder', 'power_name' => 'Species Speech', 'cost' => '12' ],
						],
						'master' => [
							[ 'level' => null, 'tier' => 'master', 'power_name' => 'Conquer the Beast', 'cost' => '15' ],
							[ 'level' => null, 'tier' => 'master', 'power_name' => 'Stampede', 'cost' => '15' ],
						],
					],
				],
			],
		];
	}

	/**
	 * The chronicle's Elder cost of 10 over the book's 12, and the book correcting it to 15.
	 *
	 * @return array{0:array<string,mixed>,1:array<string,mixed>,2:array<string,mixed>} The chronicle's rebuilt copy,
	 *                                                                                    its changes, the corrected book.
	 */
	private function house_rule_then_correction(): array {
		$book = $this->ruled();
		$mine = $book;
		$mine['_meta']['costs']['elder'] = 10;
		$changes = Fork_Merge::stamp( $book, $mine, Fork_Merge::NO_CHANGES, $book );

		$corrected = $book;
		$corrected['_meta']['costs']['elder'] = 15;
		return [ Fork_Merge::merge( $corrected, $mine, $changes ), $changes, $corrected ];
	}

	/**
	 * The chronicle's cost and approval on Stampede, and the book removing Stampede.
	 *
	 * @return array{0:array<string,mixed>,1:array<string,mixed>,2:array<string,mixed>} The chronicle's rebuilt copy,
	 *                                                                                    its changes, the book after.
	 */
	private function changed_pick_then_removed(): array {
		$book = $this->ruled();
		$mine = $book;
		$mine['powers'][0]['elder']['master'][1]['cost']     = '10';
		$mine['powers'][0]['elder']['master'][1]['approval'] = 'st';
		$changes = Fork_Merge::stamp( $book, $mine, Fork_Merge::NO_CHANGES, $book );

		$later = $book;
		array_splice( $later['powers'][0]['elder']['master'], 1, 1 );
		return [ Fork_Merge::merge( $later, $mine, $changes ), $changes, $later ];
	}

	/**
	 * @param array<string,mixed> $definition
	 * @return array<string,string>
	 */
	private static function master_costs( array $definition ): array {
		return array_column( $definition['powers'][0]['elder']['master'], 'cost', 'power_name' );
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
		$changes  = Fork_Merge::stamp( $stored, $incoming, Fork_Merge::NO_CHANGES, $stored );

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
		$changes  = Fork_Merge::stamp( $stored, $incoming, Fork_Merge::NO_CHANGES, $stored );

		$merged = $this->by_name( Fork_Merge::merge( $this->catalog(), $incoming, $changes ) );
		$this->assertSame( '2', $merged['Kindred Contacts']['cost'] );
	}

	public function test_an_entry_the_chronicle_removed_stays_removed(): void {
		$stored   = $this->catalog();
		$incoming = $this->catalog();
		array_splice( $incoming['items'], 2, 1 );
		$changes  = Fork_Merge::stamp( $stored, $incoming, Fork_Merge::NO_CHANGES, $stored );

		$updated = $this->catalog();
		$updated['items'][2]['note'] = 'Unshakeable';

		$this->assertArrayNotHasKey( 'Iron Will', $this->by_name( Fork_Merge::merge( $updated, $incoming, $changes ) ) );
	}

	public function test_an_entry_the_catalog_drops_goes_unless_the_chronicle_changed_it(): void {
		$stored   = $this->catalog();
		$incoming = $this->catalog();
		$incoming['items'][0]['note'] = 'Our allies';
		$changes  = Fork_Merge::stamp( $stored, $incoming, Fork_Merge::NO_CHANGES, $stored );

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
		$changes  = Fork_Merge::stamp( $stored, $incoming, Fork_Merge::NO_CHANGES, $stored );

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
		$changes  = Fork_Merge::stamp( $stored, $incoming, Fork_Merge::NO_CHANGES, $stored );

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
		$changes = Fork_Merge::stamp( $this->catalog(), $first, Fork_Merge::NO_CHANGES, $this->catalog() );

		$second = $first;
		$second['items'][1]['note'] = 'Cash';
		$changes = Fork_Merge::stamp( $first, $second, $changes, $this->catalog() );

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

		$this->assertSame( Fork_Merge::NO_CHANGES, Fork_Merge::stamp( $stored, $incoming, Fork_Merge::NO_CHANGES, $stored ) );
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
		$changes  = Fork_Merge::stamp( $stored, $incoming, Fork_Merge::NO_CHANGES, $stored );

		$updated = $this->disciplines();
		$updated['powers'][0]['levels'][1]['cost'] = '4';

		$levels = array_column( Fork_Merge::merge( $updated, $incoming, $changes )['powers'][0]['levels'], null, 'power_name' );
		$this->assertSame( [ 'Alacrity' ], array_keys( $levels ) );
	}

	public function test_an_entry_removed_then_added_back_is_the_chronicles_own(): void {
		$removed  = $this->catalog();
		array_splice( $removed['items'], 0, 1 );
		$changes  = Fork_Merge::stamp( $this->catalog(), $removed, Fork_Merge::NO_CHANGES, $this->catalog() );

		$restored = $removed;
		$restored['items'][] = [ 'name' => 'Allies', 'cost' => '2' ];
		$changes  = Fork_Merge::stamp( $removed, $restored, $changes, $this->catalog() );

		$merged = $this->by_name( Fork_Merge::merge( $this->catalog(), $restored, $changes ) );
		$this->assertSame( '2', $merged['Allies']['cost'] );
	}

	public function test_an_entry_the_chronicle_added_then_removed_leaves_nothing_behind(): void {
		$added   = $this->catalog();
		$added['items'][] = [ 'name' => 'Kindred Contacts', 'cost' => '2' ];
		$changes = Fork_Merge::stamp( $this->catalog(), $added, Fork_Merge::NO_CHANGES, $this->catalog() );
		$changes = Fork_Merge::stamp( $added, $this->catalog(), $changes, $this->catalog() );

		$this->assertSame( Fork_Merge::NO_CHANGES, $changes );
	}

	public function test_a_value_the_chronicle_cleared_stays_cleared(): void {
		$stored   = $this->catalog();
		$incoming = $this->catalog();
		unset( $incoming['items'][0]['note'] );
		$changes  = Fork_Merge::stamp( $stored, $incoming, Fork_Merge::NO_CHANGES, $stored );

		$updated = $this->catalog();
		$updated['items'][0]['note'] = 'Friends everywhere';

		$this->assertArrayNotHasKey( 'note', $this->by_name( Fork_Merge::merge( $updated, $incoming, $changes ) )['Allies'] );
	}

	public function test_a_copy_compared_as_wholly_its_own_keeps_out_what_it_left_out(): void {
		$copy = $this->catalog();
		array_splice( $copy['items'], 1, 1 );

		$changes = Fork_Merge::changes_against( $this->catalog(), $copy, true );

		$updated = $this->catalog();
		$updated['items'][1]['note'] = 'Wealth';
		$this->assertArrayNotHasKey( 'Resources', $this->by_name( Fork_Merge::merge( $updated, $copy, $changes ) ) );
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

	public function test_a_cost_the_chronicle_changed_leaves_the_rest_of_the_cost_table_following_the_book(): void {
		$book = $this->ruled();
		$mine = $book;
		$mine['_meta']['costs']['elder'] = 10;
		$changes = Fork_Merge::stamp( $book, $mine, Fork_Merge::NO_CHANGES, $book );

		$corrected = $book;
		$corrected['_meta']['costs']['master']       = 16;
		$corrected['_meta']['out_of_type']['elder'] = '+2';

		$meta = Fork_Merge::merge( $corrected, $mine, $changes )['_meta'];
		$this->assertSame( 10, $meta['costs']['elder'], "the chronicle's own Elder cost" );
		$this->assertSame( 16, $meta['costs']['master'], 'the correction to a rank it never touched' );
		$this->assertSame( '+2', $meta['out_of_type']['elder'] );
	}

	public function test_one_elder_pick_the_chronicle_changed_leaves_the_familys_other_picks_following_the_book(): void {
		$book = $this->ruled();
		$mine = $book;
		$mine['powers'][0]['elder']['master'][1]['cost'] = '10';
		$changes = Fork_Merge::stamp( $book, $mine, Fork_Merge::NO_CHANGES, $book );

		$corrected = $book;
		$corrected['powers'][0]['elder']['master'][0]['cost'] = '16';
		$corrected['powers'][0]['elder']['master'][]          = [ 'level' => null, 'tier' => 'master', 'power_name' => 'Shared Soul', 'cost' => '15' ];

		$this->assertSame(
			[ 'Conquer the Beast' => '16', 'Stampede' => '10', 'Shared Soul' => '15' ],
			self::master_costs( Fork_Merge::merge( $corrected, $mine, $changes ) )
		);
	}

	public function test_a_correction_under_a_chronicles_change_is_flagged_with_the_old_the_new_and_the_chronicles_value(): void {
		[ $copy, $changes, $corrected ] = $this->house_rule_then_correction();

		$flags = Fork_Merge::flags( $corrected, $copy, $changes );

		$this->assertCount( 1, $flags );
		$this->assertSame( [ '_meta', 'costs', 'elder' ], $flags[0]['path'] );
		$this->assertSame( [ 12, 15, 10 ], [ $flags[0]['was'], $flags[0]['now'], $flags[0]['yours'] ] );
		$this->assertFalse( $flags[0]['removed'] );
		$this->assertSame( 10, $copy['_meta']['costs']['elder'], 'the chronicle keeps its value until it decides' );
	}

	public function test_a_correction_the_chronicle_never_changed_raises_no_flag(): void {
		$book = $this->ruled();
		$mine = $book;
		$mine['_meta']['costs']['elder'] = 10;
		$changes = Fork_Merge::stamp( $book, $mine, Fork_Merge::NO_CHANGES, $book );

		$corrected = $book;
		$corrected['_meta']['costs']['master'] = 16;

		$this->assertSame( [], Fork_Merge::flags( $corrected, Fork_Merge::merge( $corrected, $mine, $changes ), $changes ) );
	}

	public function test_a_correction_to_the_chronicles_own_value_raises_no_flag(): void {
		$book = $this->ruled();
		$mine = $book;
		$mine['_meta']['costs']['elder'] = 10;
		$changes = Fork_Merge::stamp( $book, $mine, Fork_Merge::NO_CHANGES, $book );

		$agreed = $book;
		$agreed['_meta']['costs']['elder'] = 10;

		$this->assertSame( [], Fork_Merge::flags( $agreed, Fork_Merge::merge( $agreed, $mine, $changes ), $changes ) );
	}

	public function test_keep_mine_records_the_new_value_underneath_and_keeps_the_chronicles(): void {
		[ $copy, $changes, $corrected ] = $this->house_rule_then_correction();

		$kept = Fork_Merge::keep( $corrected, $copy, $changes, [ '_meta', 'costs', 'elder' ] );

		$this->assertSame( [], Fork_Merge::flags( $corrected, $copy, $kept ) );
		$this->assertSame( 10, Fork_Merge::merge( $corrected, $copy, $kept )['_meta']['costs']['elder'] );
	}

	public function test_use_the_books_drops_the_change(): void {
		[ $copy, $changes, $corrected ] = $this->house_rule_then_correction();

		$taken = Fork_Merge::take( $changes, [ '_meta', 'costs', 'elder' ] );

		$this->assertSame( Fork_Merge::NO_CHANGES, $taken );
		$this->assertSame( 15, Fork_Merge::merge( $corrected, $copy, $taken )['_meta']['costs']['elder'] );
	}

	public function test_editing_a_flagged_value_clears_its_flag(): void {
		[ $copy, $changes, $corrected ] = $this->house_rule_then_correction();
		$edited = $copy;
		$edited['_meta']['costs']['elder'] = 11;

		$changes = Fork_Merge::stamp( $copy, $edited, $changes, $corrected );

		$this->assertSame( [], Fork_Merge::flags( $corrected, $edited, $changes ) );
		$this->assertSame( 11, Fork_Merge::merge( $corrected, $edited, $changes )['_meta']['costs']['elder'] );
	}

	public function test_a_value_set_back_to_the_books_records_nothing(): void {
		$book = $this->ruled();
		$mine = $book;
		$mine['_meta']['costs']['elder'] = 10;
		$changes = Fork_Merge::stamp( $book, $mine, Fork_Merge::NO_CHANGES, $book );

		$this->assertSame( Fork_Merge::NO_CHANGES, Fork_Merge::stamp( $mine, $book, $changes, $book ) );
	}

	public function test_an_entry_the_book_removes_after_the_chronicle_changed_it_is_kept_and_flagged_once(): void {
		[ $copy, $changes, $later ] = $this->changed_pick_then_removed();

		$this->assertSame( [ 'Conquer the Beast' => '15', 'Stampede' => '10' ], self::master_costs( $copy ), 'the chronicle keeps its pick until it decides' );
		$flags = Fork_Merge::flags( $later, $copy, $changes );
		$this->assertCount( 1, $flags );
		$this->assertTrue( $flags[0]['removed'] );
		$this->assertSame( [ 'powers', [ 'Animalism' ], 'elder', 'master', [ 'Stampede' ] ], $flags[0]['path'] );
		$this->assertSame( '10', $flags[0]['yours']['cost'] );
		$this->assertSame(
			[ [ 'cost' ], [ 'approval' ] ],
			array_map( static fn( array $change ): array => array_slice( $change['path'], 5 ), $flags[0]['changes'] )
		);
	}

	public function test_keep_mine_on_an_entry_the_book_removed_makes_it_the_chronicles_own(): void {
		[ $copy, $changes, $later ] = $this->changed_pick_then_removed();

		$kept = Fork_Merge::keep( $later, $copy, $changes, [ 'powers', [ 'Animalism' ], 'elder', 'master', [ 'Stampede' ] ] );

		$this->assertSame( [], Fork_Merge::flags( $later, $copy, $kept ) );
		$fixed = $later;
		$fixed['powers'][0]['elder']['master'][0]['cost'] = '16';
		$this->assertSame( [ 'Conquer the Beast' => '16', 'Stampede' => '10' ], self::master_costs( Fork_Merge::merge( $fixed, $copy, $kept ) ) );
	}

	public function test_use_the_books_on_an_entry_the_book_removed_drops_it(): void {
		[ $copy, $changes, $later ] = $this->changed_pick_then_removed();

		$taken = Fork_Merge::take( $changes, [ 'powers', [ 'Animalism' ], 'elder', 'master', [ 'Stampede' ] ] );

		$this->assertSame( Fork_Merge::NO_CHANGES, $taken );
		$this->assertSame( [ 'Conquer the Beast' => '15' ], self::master_costs( Fork_Merge::merge( $later, $copy, $taken ) ) );
	}

	public function test_an_entry_the_chronicle_added_is_flagged_when_the_book_adds_one_by_the_same_name(): void {
		$book = $this->catalog();
		$mine = $book;
		$mine['items'][] = [ 'name' => 'Contacts', 'cost' => '2' ];
		$changes = Fork_Merge::stamp( $book, $mine, Fork_Merge::NO_CHANGES, $book );

		$later = $book;
		$later['items'][] = [ 'name' => 'Contacts', 'cost' => '1', 'note' => 'People you know' ];
		$copy  = Fork_Merge::merge( $later, $mine, $changes );

		$this->assertSame( [ 'name' => 'Contacts', 'cost' => '2' ], $this->by_name( $copy )['Contacts'] );
		$flags = Fork_Merge::flags( $later, $copy, $changes );
		$this->assertCount( 1, $flags );
		$this->assertSame( [ 'items', [ 'Contacts' ] ], $flags[0]['path'] );
		$this->assertSame( [ false, true, true ], [ $flags[0]['was_set'], $flags[0]['now_set'], $flags[0]['yours_set'] ] );
	}

	public function test_removing_an_entry_the_chronicle_had_changed_keeps_it_removed_through_a_book_change(): void {
		$book    = $this->catalog();
		$changed = $book;
		$changed['items'][0]['cost'] = '2';
		$changes = Fork_Merge::stamp( $book, $changed, Fork_Merge::NO_CHANGES, $book );
		$removed = $changed;
		array_splice( $removed['items'], 0, 1 );
		$changes = Fork_Merge::stamp( $changed, $removed, $changes, $book );

		$later = $book;
		$later['items'][0]['note'] = 'Friends in high places';

		$this->assertArrayNotHasKey( 'Allies', $this->by_name( Fork_Merge::merge( $later, $removed, $changes ) ) );
		$flags = Fork_Merge::flags( $later, $removed, $changes );
		$this->assertSame( [ [ 'items', [ 'Allies' ] ] ], array_column( $flags, 'path' ), 'the book changed an entry the chronicle removed' );
		$this->assertFalse( $flags[0]['yours_set'] );
	}

	public function test_keep_all_mine_clears_every_flag_and_changes_no_value(): void {
		$book = $this->ruled();
		$mine = $book;
		$mine['_meta']['costs']['elder']                  = 10;
		$mine['powers'][0]['elder']['master'][1]['cost'] = '10';
		$changes = Fork_Merge::stamp( $book, $mine, Fork_Merge::NO_CHANGES, $book );

		$later = $book;
		$later['_meta']['costs']['elder']                  = 15;
		$later['powers'][0]['elder']['master'][1]['cost'] = '18';
		$copy  = Fork_Merge::merge( $later, $mine, $changes );
		$this->assertCount( 2, Fork_Merge::flags( $later, $copy, $changes ) );

		$kept = Fork_Merge::keep_all( $later, $copy, $changes );

		$this->assertSame( [], Fork_Merge::flags( $later, $copy, $kept ) );
		$this->assertSame( $copy, Fork_Merge::merge( $later, $copy, $kept ) );
	}

	public function test_a_change_record_stored_as_json_merges_the_same(): void {
		$book            = $this->catalog();
		$book['items'][] = [ 'name' => '1812', 'cost' => '1' ];
		$mine            = $book;
		$mine['items'][3]['cost']        = '4';
		$mine['clan_disciplines']['Ravnos'] = [ 'Animalism', 'Chimerstry' ];
		$changes = json_decode( (string) json_encode( Fork_Merge::stamp( $book, $mine, Fork_Merge::NO_CHANGES, $book ) ), true );

		$later = $book;
		$later['items'][3]['note'] = 'A year';

		$merged = Fork_Merge::merge( $later, $mine, $changes );
		$this->assertSame( [ 'name' => '1812', 'cost' => '4', 'note' => 'A year' ], $this->by_name( $merged )['1812'] );
		$this->assertSame( [ 'Ravnos' => [ 'Animalism', 'Chimerstry' ] ], $merged['clan_disciplines'] );
		$this->assertSame( [], Fork_Merge::flags( $later, $merged, $changes ) );
	}

	public function test_labels_name_each_entry_a_path_steps_into_from_the_copy_or_else_the_book(): void {
		$book = $this->ruled();
		$book['sections'] = [ [ 'block_slug' => 'vampire-blood-magic', 'label' => 'Blood Magic' ] ];
		$copy = $book;
		array_splice( $copy['powers'][0]['elder']['master'], 1, 1 );

		$this->assertSame(
			[ null, 'Animalism', null, null, 'Stampede', null ],
			Fork_Merge::labels( $copy, $book, [ 'powers', [ 'Animalism' ], 'elder', 'master', [ 'Stampede' ], 'cost' ] ),
			'a pick the copy dropped is named from the book'
		);
		$this->assertSame( [ null, 'Blood Magic', null ], Fork_Merge::labels( $copy, $book, [ 'sections', [ 'vampire-blood-magic' ], 'hidden' ] ) );
		$this->assertSame( [ null, 'Nowhere' ], Fork_Merge::labels( $copy, $book, [ 'items', [ 'Nowhere' ] ] ), 'an entry neither has keeps its identity' );
	}
}
