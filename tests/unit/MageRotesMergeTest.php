<?php

namespace BeyondElysium\Tests\Unit;

use BeyondElysium\Database\Seeder;
use PHPUnit\Framework\TestCase;

/**
 * `Seeder::merge_grimoire_rows()` - the pure core behind
 * `merge_grimoire_rotes()`, exercised directly via reflection against
 * constructed base/CSV-row fixtures rather than the real 201+670-row data,
 * per mage-rotes-grimoire-design.md §5.5's three structural guards: named
 * dispositions, a tally, and category backfill as a distinct, assertable
 * pass. Decision 043's tie rule is the specific failure mode under test -
 * `build_met_merge_trait_list()`'s own real bug (D-adjacent, fixed in
 * v0.99.15) was a match on the identity key treated as a match on the
 * whole record, silently discarding a field. These tests assert the tally
 * makes that class of mistake visible rather than invisible in a `continue`.
 *
 * @see BE_PROCESS/mage-rotes-grimoire-design.md §5.5, §8.4
 */
class MageRotesMergeTest extends TestCase {

	private function row( string $name, string $group = '', string $subgroup = '' ): array {
		return [
			'name'     => $name,
			'note'     => 'Entropy 2',
			'source'   => 'Some Book page 1; Enlightened Grimoire p. 1',
			'group'    => $group,
			'subgroup' => $subgroup,
		];
	}

	private function merge( array $base, array $rows ): array {
		$method = new \ReflectionMethod( Seeder::class, 'merge_grimoire_rows' );
		$method->setAccessible( true );
		return $method->invoke( null, $base, $rows );
	}

	private function keys( string $name ): array {
		$method = new \ReflectionMethod( Seeder::class, 'grimoire_match_keys' );
		$method->setAccessible( true );
		return $method->invoke( null, $name );
	}

	public function test_a_net_new_row_is_appended_with_all_five_fields(): void {
		$base   = [ [ 'name' => 'Balance the Scales', 'note' => 'Entropy 2 or 3', 'source' => 'x' ] ];
		$merged = $this->merge( $base, [ $this->row( 'Brand New Rote', 'Computers', 'Digital Web' ) ] );

		$this->assertCount( 2, $merged );
		$added = $merged[1];
		$this->assertSame( 'Brand New Rote', $added['name'] );
		$this->assertSame( 'Entropy 2', $added['note'] );
		$this->assertSame( 'Computers', $added['group'] );
		$this->assertSame( 'Digital Web', $added['subgroup'] );
	}

	public function test_an_exact_name_match_backfills_group_but_never_touches_note_or_source(): void {
		$base   = [ [ 'name' => 'Balance the Scales', 'note' => 'Level 2, Correspondence: Initiate', 'source' => 'Laws of Ascension p. 5' ] ];
		$merged = $this->merge( $base, [ $this->row( 'Balance the Scales', 'Blessings and Curses', 'Flexible Use' ) ] );

		$this->assertCount( 1, $merged, 'a matched CSV row must never become a second item' );
		$this->assertSame( 'Level 2, Correspondence: Initiate', $merged[0]['note'], 'note must stay the GEX system\'s own value' );
		$this->assertSame( 'Laws of Ascension p. 5', $merged[0]['source'], 'source must stay the GEX system\'s own value' );
		$this->assertSame( 'Blessings and Curses', $merged[0]['group'] );
		$this->assertSame( 'Flexible Use', $merged[0]['subgroup'] );
	}

	public function test_a_matched_item_that_already_has_a_group_is_not_overwritten(): void {
		$base   = [ [ 'name' => 'Balance the Scales', 'note' => 'n', 'source' => 's', 'group' => 'Custom Category' ] ];
		$merged = $this->merge( $base, [ $this->row( 'Balance the Scales', 'Blessings and Curses' ) ] );

		$this->assertSame( 'Custom Category', $merged[0]['group'] );
	}

	/** K1 - a bare trailing-s plural on the final word is the same rote. */
	public function test_k1_trailing_s_equivalence_matches_not_adds(): void {
		$base   = [ [ 'name' => 'Ball of Abysmal Flame', 'note' => 'n', 'source' => 's' ] ];
		$merged = $this->merge( $base, [ $this->row( 'Ball of Abysmal Flames', 'Elemental Magick' ) ] );

		$this->assertCount( 1, $merged, 'K1 must match, not add a near-duplicate' );
		$this->assertSame( 'Elemental Magick', $merged[0]['group'] );
	}

	/** K2 - a slash-joined Grimoire name matches a single-named GEX base item on either side. */
	public function test_k2_slash_joined_name_matches_a_single_sided_base_item(): void {
		$base   = [ [ 'name' => 'Blight', 'note' => 'n', 'source' => 's' ] ];
		$merged = $this->merge( $base, [ $this->row( "Blight/Farmer's Favor", 'Blessings and Curses' ) ] );

		$this->assertCount( 1, $merged );
		$this->assertSame( 'Blessings and Curses', $merged[0]['group'] );
	}

	/**
	 * Decision 043's tie rule: a K2 name whose two halves each match a
	 * DIFFERENT base item is a genuine ambiguity, left unmerged entirely -
	 * neither base item is touched, and the Grimoire row is not added as a
	 * third item either.
	 */
	public function test_a_genuine_k2_tie_is_left_unmerged_not_guessed(): void {
		$base = [
			[ 'name' => 'Blight', 'note' => 'a', 'source' => 'sa' ],
			[ 'name' => "Farmer's Favor", 'note' => 'b', 'source' => 'sb' ],
		];
		$merged = $this->merge( $base, [ $this->row( "Blight/Farmer's Favor", 'Blessings and Curses' ) ] );

		$this->assertCount( 2, $merged, 'the tie must not add a third row' );
		$this->assertArrayNotHasKey( 'group', $merged[0], 'neither tied candidate is touched' );
		$this->assertArrayNotHasKey( 'group', $merged[1], 'neither tied candidate is touched' );
	}

	public function test_two_csv_rows_normalizing_to_the_same_key_do_not_double_add(): void {
		$base   = [];
		$merged = $this->merge( $base, [
			$this->row( 'Some New Rote', 'Computers' ),
			$this->row( 'Some New Rotes' ), // K1-equivalent to the row above
		] );

		$this->assertCount( 1, $merged, 'the second row must match the first newly-added row, not add a duplicate' );
	}

	public function test_grimoire_match_keys_includes_the_base_key_and_k1_variant(): void {
		$keys = $this->keys( 'Ball of Abysmal Flame' );

		$this->assertContains( 'ball of abysmal flame', $keys );
		$this->assertContains( 'ball of abysmal flames', $keys );
	}

	public function test_grimoire_match_keys_for_a_slash_name_includes_both_sides_and_the_whole_string(): void {
		$keys = $this->keys( "Blight/Farmer's Favor" );

		$this->assertContains( "blight/farmer's favor", $keys );
		$this->assertContains( 'blight', $keys );
		$this->assertContains( "farmer's favor", $keys );
	}
}
