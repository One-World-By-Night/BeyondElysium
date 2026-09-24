<?php

namespace BeyondElysium\Tests\Unit;

use BeyondElysium\Services\Custom_Rekey;
use PHPUnit\Framework\TestCase;

/**
 * The re-key planner - block move, the six ordered matching tiers, the five guards, the write shape and the per-row
 * records.
 */
class CustomRekeyTest extends TestCase {

	/** @param array<string,mixed> $data */
	private static function def( array $data ): object {
		return json_decode( json_encode( $data ) );
	}

	/**
	 * @param array<int,array<string,mixed>> $items
	 * @param array<string,mixed>            $flags
	 */
	private static function block( array $items, array $flags = [] ): object {
		return self::def( $flags + [ 'items' => $items ] );
	}

	private static function rituals(): object {
		return self::def( [
			'atomic'                => true,
			'name_canonicalization' => [
				'form'          => 'group_name_tier',
				'tier_words'    => [ 'b' => 'Basic', 'basic' => 'Basic', 'int' => 'Intermediate', 'intermediate' => 'Intermediate', 'adv' => 'Advanced', 'advanced' => 'Advanced' ],
				'group_aliases' => [ 'Koldunic' => 'Koldunism', 'Thaum' => 'Thaumaturgy' ],
			],
			'items'                 => [
				[ 'name' => 'Thaumaturgy: Blood Walk (basic)' ],
				[ 'name' => 'Thaumaturgy: Open Passage (basic)' ],
				[ 'name' => 'Koldunism: Song of the Deep (Intermediate)' ],
			],
		] );
	}

	/**
	 * One block, one custom row, no moves.
	 *
	 * @param array<string,mixed> $row
	 * @return array<string,mixed>
	 */
	private function plan_one( object $block, array $row, array $options = [ 'suggestions' => false ] ): array {
		return Custom_Rekey::plan_character( [ 'b' => [ $row ] ], [ 'b' => $block ], [], $options );
	}

	/** @param array<string,mixed> $result @return array<string,mixed> */
	private static function only_record( array $result ): array {
		self::assertCount( 1, $result['records'] );
		return $result['records'][0];
	}

	private static function custom( string $name, array $extra = [] ): array {
		return [ 'name' => $name, 'custom' => true ] + $extra;
	}

	// --- block move -----------------------------------------------------------

	public function test_rows_move_in_order_to_the_replacement_and_the_retired_key_goes(): void {
		$sheet  = [ 'met-abilities' => [ [ 'name' => 'Brawl', 'count' => 3 ], [ 'name' => 'Melee', 'count' => 2 ] ] ];
		$result = Custom_Rekey::plan_character( $sheet, [], [ 'met-abilities' => 'vampire-abilities' ] );

		$this->assertSame( [ 'vampire-abilities' => $sheet['met-abilities'] ], $result['sheet_data'] );
		$this->assertTrue( $result['changed'] );
		$this->assertSame( 2, $result['counts']['moved_rows'] );
	}

	public function test_moved_rows_append_after_whatever_the_replacement_already_holds(): void {
		$sheet  = [
			'met-abilities'     => [ [ 'name' => 'Brawl' ] ],
			'vampire-abilities' => [ [ 'name' => 'Occult' ] ],
		];
		$result = Custom_Rekey::plan_character( $sheet, [], [ 'met-abilities' => 'vampire-abilities' ] );

		$this->assertSame( [ 'Occult', 'Brawl' ], array_column( $result['sheet_data']['vampire-abilities'], 'name' ) );
	}

	public function test_a_catalog_row_is_never_altered_even_with_no_definition_supplied(): void {
		$row    = [ 'name' => 'Brawl', 'count' => 3, 'specialization' => 'Boxing', 'note' => 'n' ];
		$result = Custom_Rekey::plan_character( [ 'met-abilities' => [ $row ] ], [], [ 'met-abilities' => 'vampire-abilities' ] );

		$this->assertSame( $row, $result['sheet_data']['vampire-abilities'][0] );
		$this->assertSame( [], $result['records'] );
	}

	public function test_a_key_with_no_replacement_and_a_non_list_value_are_left_alone(): void {
		$sheet  = [
			'demon-lores'         => [ [ 'name' => 'Vampire' ] ],
			'met-abilities'       => [ 'not' => 'a list' ],
			'vampire-identity'    => [ 'Clan' => 'Tremere' ],
			'vampire-resources'   => [ 'Willpower' => [ 'permanent' => 5 ] ],
		];
		$result = Custom_Rekey::plan_character( $sheet, [], [ 'met-abilities' => 'vampire-abilities' ] );

		$this->assertSame( $sheet, $result['sheet_data'] );
		$this->assertFalse( $result['changed'] );
	}

	public function test_a_retired_key_that_is_absent_changes_nothing(): void {
		$result = Custom_Rekey::plan_character( [ 'vampire-identity' => [ 'Clan' => 'Ventrue' ] ], [], [ 'met-abilities' => 'vampire-abilities' ] );

		$this->assertFalse( $result['changed'] );
	}

	public function test_a_moved_custom_row_is_matched_against_the_replacement_block(): void {
		$sheet  = [ 'met-abilities' => [ self::custom( 'Occult' ), [ 'name' => 'Brawl' ] ] ];
		$result = Custom_Rekey::plan_character(
			$sheet,
			[ 'vampire-abilities' => self::block( [ [ 'name' => 'Occult' ], [ 'name' => 'Brawl' ] ] ) ],
			[ 'met-abilities' => 'vampire-abilities' ]
		);

		$record = self::only_record( $result );
		$this->assertSame( 'met-abilities', $record['block_from'] );
		$this->assertSame( 'vampire-abilities', $record['block_to'] );
		$this->assertSame( 0, $record['index'] );
		$this->assertSame( 'exact', $record['tier'] );
	}

	// --- the write shape ------------------------------------------------------

	public function test_a_rekeyed_row_takes_the_catalog_name_drops_custom_and_records_what_was_written(): void {
		$result = $this->plan_one(
			self::block( [ [ 'name' => 'Occult' ] ] ),
			self::custom( 'occult', [ 'count' => 4, 'note' => 'kept' ] )
		);

		$this->assertSame(
			[ 'name' => 'Occult', 'count' => 4, 'note' => 'kept', 'rekeyed_from' => 'occult' ],
			$result['sheet_data']['b'][0]
		);
		$this->assertSame( 1, $result['counts']['rekeyed'] );
	}

	public function test_a_record_carries_from_to_tier_and_where_the_row_sits(): void {
		$record = self::only_record( $this->plan_one( self::block( [ [ 'name' => 'Occult' ] ] ), self::custom( 'occult' ) ) );

		$this->assertSame(
			[ 'block_from' => 'b', 'block_to' => 'b', 'index' => 0, 'from' => 'occult', 'outcome' => 'rekeyed', 'to' => 'Occult', 'tier' => 'normalized', 'home' => null, 'label' => null ],
			$record
		);
	}

	// --- the six tiers --------------------------------------------------------

	public function test_tier_exact_clears_a_stale_custom_flag_on_a_real_catalog_name(): void {
		$result = $this->plan_one( self::block( [ [ 'name' => 'Occult' ] ] ), self::custom( 'Occult' ) );

		$this->assertSame( 'exact', self::only_record( $result )['tier'] );
		$this->assertArrayNotHasKey( 'custom', $result['sheet_data']['b'][0] );
		$this->assertSame( 'Occult', $result['sheet_data']['b'][0]['rekeyed_from'] );
	}

	public function test_tier_normalized_ignores_case_and_punctuation(): void {
		$result = $this->plan_one( self::block( [ [ 'name' => 'Animal Ken' ] ] ), self::custom( 'ANIMAL-ken' ) );

		$this->assertSame( 'normalized', self::only_record( $result )['tier'] );
		$this->assertSame( 'Animal Ken', $result['sheet_data']['b'][0]['name'] );
	}

	public function test_tier_alias_matches_a_recorded_former_name(): void {
		$block = self::block( [ [ 'name' => 'Meditation', 'aliases' => [ 'Meditiation' ] ] ] );

		$exact      = $this->plan_one( $block, self::custom( 'Meditiation' ) );
		$normalized = $this->plan_one( $block, self::custom( 'meditiation' ) );

		$this->assertSame( 'alias', self::only_record( $exact )['tier'] );
		$this->assertSame( 'alias', self::only_record( $normalized )['tier'] );
		$this->assertSame( 'Meditation', $exact['sheet_data']['b'][0]['name'] );
	}

	/**
	 * `Fuzzy_Matcher::normalize()` folds every run of punctuation to a space.
	 *
	 * @dataProvider symbol_decorations
	 */
	public function test_a_trailing_symbol_is_folded_away_by_the_normalized_tier( string $raw ): void {
		$result = $this->plan_one( self::block( [ [ 'name' => 'Occult' ] ] ), self::custom( $raw ) );

		$this->assertSame( 'normalized', self::only_record( $result )['tier'], $raw );
		$this->assertSame( 'Occult', $result['sheet_data']['b'][0]['name'] );
		$this->assertSame( $raw, $result['sheet_data']['b'][0]['rekeyed_from'] );
	}

	/** @return array<string,array<int,string>> */
	public static function symbol_decorations(): array {
		return [
			'asterisk'     => [ 'Occult*' ],
			'caret'        => [ 'Occult^' ],
			'dagger'       => [ 'Occult†' ],
			'hash'         => [ 'Occult #' ],
			'lower + star' => [ 'occult*' ],
		];
	}

	/**
	 * A bracket group keeps its words through normalize().
	 */
	public function test_tier_decoration_strips_a_trailing_bracket_group_then_matches(): void {
		$block = self::block( [ [ 'name' => 'Occult' ] ] );

		$plain  = $this->plan_one( $block, self::custom( 'Occult [Sabbat]' ) );
		$mixed  = $this->plan_one( $block, self::custom( 'Occult* [Sabbat]' ) );
		$folded = $this->plan_one( $block, self::custom( 'occult [x]' ) );

		$this->assertSame( 'decoration', self::only_record( $plain )['tier'] );
		$this->assertSame( 'decoration', self::only_record( $mixed )['tier'] );
		$this->assertSame( 'decoration', self::only_record( $folded )['tier'] );
		$this->assertSame( 'Occult', $plain['sheet_data']['b'][0]['name'] );
		$this->assertSame( 'Occult [Sabbat]', $plain['sheet_data']['b'][0]['rekeyed_from'] );
	}

	public function test_a_name_that_is_only_decoration_matches_nothing(): void {
		$result = $this->plan_one( self::block( [ [ 'name' => 'Occult' ] ] ), self::custom( '***' ) );

		$this->assertSame( 'no_match', self::only_record( $result )['reason'] );
	}

	public function test_tier_canonical_matches_an_abbreviated_tier_word(): void {
		$result = $this->plan_one( self::rituals(), self::custom( 'Thaumaturgy: Blood Walk (b)' ) );

		$this->assertSame( 'canonical', self::only_record( $result )['tier'] );
		$this->assertSame( 'Thaumaturgy: Blood Walk (basic)', $result['sheet_data']['b'][0]['name'] );
	}

	public function test_tier_canonical_follows_a_declared_group_synonym(): void {
		$thaum = $this->plan_one( self::rituals(), self::custom( 'Thaum: Open Passage (basic)' ) );
		$kold  = $this->plan_one( self::rituals(), self::custom( 'Koldunic: Song of the Deep (int)' ) );

		$this->assertSame( 'Thaumaturgy: Open Passage (basic)', $thaum['sheet_data']['b'][0]['name'] );
		$this->assertSame( 'Koldunism: Song of the Deep (Intermediate)', $kold['sheet_data']['b'][0]['name'] );
		$this->assertSame( 'canonical', self::only_record( $kold )['tier'] );
	}

	public function test_canonical_never_matches_an_unknown_tier_word(): void {
		$result = $this->plan_one( self::rituals(), self::custom( 'Thaumaturgy: Blood Walk (zz)' ) );

		$this->assertSame( 'no_match', self::only_record( $result )['reason'] );
		$this->assertTrue( isset( $result['sheet_data']['b'][0]['custom'] ) );
	}

	public function test_canonical_never_matches_a_tier_that_disagrees_with_the_catalogs(): void {
		// A tier drives the price: the catalog's Blood Walk is Basic, so an Advanced one is not it.
		$result = $this->plan_one( self::rituals(), self::custom( 'Thaumaturgy: Blood Walk (adv)' ) );

		$this->assertSame( 'no_match', self::only_record( $result )['reason'] );
	}

	public function test_canonical_needs_the_block_to_declare_the_rule(): void {
		$plain = self::block( [ [ 'name' => 'Thaumaturgy: Blood Walk (basic)' ] ], [ 'atomic' => true ] );

		$result = $this->plan_one( $plain, self::custom( 'Thaumaturgy: Blood Walk (b)' ) );

		$this->assertSame( 'no_match', self::only_record( $result )['reason'] );
	}

	public function test_tier_label_writes_a_specialization_where_the_block_takes_them(): void {
		$block  = self::block( [ [ 'name' => 'Brawl' ] ], [ 'has_specializations' => true ] );
		$colon  = $this->plan_one( $block, self::custom( 'Brawl: Wrestling', [ 'count' => 3 ] ) );
		$parens = $this->plan_one( $block, self::custom( 'Brawl (Boxing)' ) );

		$this->assertSame( [ 'name' => 'Brawl', 'count' => 3, 'specialization' => 'Wrestling', 'rekeyed_from' => 'Brawl: Wrestling' ], $colon['sheet_data']['b'][0] );
		$this->assertSame( 'Boxing', $parens['sheet_data']['b'][0]['specialization'] );
		$this->assertSame( 'specialization', self::only_record( $colon )['home'] );
		$this->assertSame( 'label', self::only_record( $colon )['tier'] );
	}

	public function test_tier_label_writes_a_specialization_for_an_item_that_allows_multiples(): void {
		$block  = self::block( [ [ 'name' => 'Lore', 'allow_multiples' => true ], [ 'name' => 'Brawl' ] ] );
		$result = $this->plan_one( $block, self::custom( 'Lore: Kindred' ) );

		$this->assertSame( 'Kindred', $result['sheet_data']['b'][0]['specialization'] );
		$this->assertSame( 'specialization', self::only_record( $result )['home'] );
	}

	public function test_a_label_on_a_single_instance_item_goes_to_the_note_per_q1(): void {
		$block = self::block( [ [ 'name' => 'Observant' ] ] );

		$bare = $this->plan_one( $block, self::custom( 'Observant (Per)' ) );
		$held = $this->plan_one( $block, self::custom( 'Observant (Per)', [ 'note' => 'sharp eyes' ] ) );

		$this->assertSame( 'Per', $bare['sheet_data']['b'][0]['note'] );
		$this->assertSame( 'Per; sharp eyes', $held['sheet_data']['b'][0]['note'] );
		$this->assertSame( 'note', self::only_record( $bare )['home'] );
		$this->assertArrayNotHasKey( 'specialization', $bare['sheet_data']['b'][0] );
	}

	public function test_a_colon_split_is_tried_before_a_parenthesised_one(): void {
		$block  = self::block( [ [ 'name' => 'Lore', 'allow_multiples' => true ] ] );
		$result = $this->plan_one( $block, self::custom( 'Lore: Kindred (Sabbat)' ) );

		$this->assertSame( 'Kindred (Sabbat)', $result['sheet_data']['b'][0]['specialization'] );
	}

	public function test_the_colon_reading_wins_when_both_readings_resolve_to_real_items(): void {
		$block  = self::block( [ [ 'name' => 'Lore', 'allow_multiples' => true ], [ 'name' => 'Lore: Kindred', 'allow_multiples' => true ] ] );
		$result = $this->plan_one( $block, self::custom( 'Lore: Kindred (Sabbat)' ) );

		$this->assertSame( 'Lore', $result['sheet_data']['b'][0]['name'] );
		$this->assertSame( 'Kindred (Sabbat)', $result['sheet_data']['b'][0]['specialization'] );
	}

	public function test_the_label_base_resolves_through_the_first_three_tiers_only(): void {
		$block = self::block( [ [ 'name' => 'Occult' ] ] );

		$result = $this->plan_one( $block, self::custom( 'Occult [Sabbat]: Voodoo' ) );

		$this->assertSame( 'no_match', self::only_record( $result )['reason'] );
	}

	public function test_tiers_run_in_order_so_an_exact_hit_beats_an_ambiguous_normalized_one(): void {
		$block = self::block( [ [ 'name' => 'Read Lips' ], [ 'name' => 'Read-Lips' ] ] );

		$exact     = $this->plan_one( $block, self::custom( 'Read Lips' ) );
		$ambiguous = $this->plan_one( $block, self::custom( 'read lips' ) );

		$this->assertSame( 'exact', self::only_record( $exact )['tier'] );
		$this->assertSame( 'ambiguous', self::only_record( $ambiguous )['reason'] );
		$this->assertTrue( isset( $ambiguous['sheet_data']['b'][0]['custom'] ) );
	}

	// --- the guards -----------------------------------------------------------

	public function test_guard_has_custom_price_keeps_a_storytellers_price(): void {
		$row    = self::custom( 'Occult', [ 'chosen_cost' => 2, 'count' => 1 ] );
		$result = $this->plan_one( self::block( [ [ 'name' => 'Occult' ] ] ), $row );

		$this->assertSame( 'has_custom_price', self::only_record( $result )['reason'] );
		$this->assertSame( $row, $result['sheet_data']['b'][0] );
		$this->assertFalse( $result['changed'] );
	}

	public function test_guard_removed_keeps_an_editor_working_copy_marker(): void {
		$row    = self::custom( 'Occult', [ '_removed' => true ] );
		$result = $this->plan_one( self::block( [ [ 'name' => 'Occult' ] ] ), $row );

		$this->assertSame( 'removed', self::only_record( $result )['reason'] );
		$this->assertSame( $row, $result['sheet_data']['b'][0] );
	}

	public function test_guard_label_conflict_keeps_a_row_with_a_different_specialization(): void {
		$block = self::block( [ [ 'name' => 'Brawl' ] ], [ 'has_specializations' => true ] );
		$row   = self::custom( 'Brawl (Boxing)', [ 'specialization' => 'Wrestling' ] );

		$conflict = $this->plan_one( $block, $row );
		$same     = $this->plan_one( $block, self::custom( 'Brawl (Boxing)', [ 'specialization' => 'Boxing' ] ) );

		$this->assertSame( 'label_conflict', self::only_record( $conflict )['reason'] );
		$this->assertSame( $row, $conflict['sheet_data']['b'][0] );
		$this->assertSame( 'label', self::only_record( $same )['tier'], 'the same label is no conflict' );
	}

	public function test_guard_ambiguous_keeps_a_row_the_deciding_tier_matched_twice(): void {
		$result = $this->plan_one( self::block( [ [ 'name' => 'Read Lips' ], [ 'name' => 'Read-Lips' ] ] ), self::custom( 'read lips' ) );

		$this->assertSame( 'ambiguous', self::only_record( $result )['reason'] );
		$this->assertSame( 0, $result['counts']['rekeyed'] );
	}

	public function test_guard_collision_keeps_a_row_that_would_duplicate_a_held_one(): void {
		// A character holding the catalog Clever x4, plus a custom "Clever (AfVisc)".
		$block  = self::block( [ [ 'name' => 'Clever' ] ] );
		$sheet  = [ 'b' => [ [ 'name' => 'Clever', 'count' => 4 ], self::custom( 'Clever (AfVisc)' ) ] ];
		$result = Custom_Rekey::plan_character( $sheet, [ 'b' => $block ], [], [ 'suggestions' => false ] );

		$this->assertSame( $sheet, $result['sheet_data'] );
		$this->assertSame( 'collision', $result['records'][0]['reason'] );
		$this->assertSame( 'Clever', $result['records'][0]['would_be'] );
		$this->assertFalse( $result['changed'] );
	}

	public function test_guard_collision_keeps_every_candidate_that_shares_an_identity(): void {
		$block  = self::block( [ [ 'name' => 'Clever' ] ] );
		$sheet  = [ 'b' => [ self::custom( 'Clever (A)' ), self::custom( 'Clever (B)' ) ] ];
		$result = Custom_Rekey::plan_character( $sheet, [ 'b' => $block ], [], [ 'suggestions' => false ] );

		$this->assertSame( $sheet, $result['sheet_data'] );
		$this->assertSame( [ 'collision', 'collision' ], array_column( $result['records'], 'reason' ) );
	}

	public function test_a_lone_candidate_with_no_twin_is_rekeyed(): void {
		$result = $this->plan_one( self::block( [ [ 'name' => 'Clever' ] ] ), self::custom( 'Clever (AfVisc)' ) );

		$this->assertSame( 'Clever', $result['sheet_data']['b'][0]['name'] );
		$this->assertSame( 'AfVisc', $result['sheet_data']['b'][0]['note'] );
	}

	public function test_guard_collision_sees_labels_when_the_item_allows_multiples(): void {
		// Retainers x3 (John) and a fresh "Retainers (Sue)" are different holdings by declaration.
		$block  = self::block( [ [ 'name' => 'Retainers', 'allow_multiples' => true ] ] );
		$sheet  = [ 'b' => [ [ 'name' => 'Retainers', 'specialization' => 'John', 'count' => 3 ], self::custom( 'Retainers (Sue)' ) ] ];
		$result = Custom_Rekey::plan_character( $sheet, [ 'b' => $block ], [], [ 'suggestions' => false ] );

		$this->assertSame( 'Sue', $result['sheet_data']['b'][1]['specialization'] );
		$this->assertSame( 1, $result['counts']['rekeyed'] );
	}

	public function test_an_atomic_block_is_exempt_from_the_collision_guard(): void {
		// Each purchase of a Merit is its own row by declaration.
		$block  = self::block( [ [ 'name' => 'Iron Will' ] ], [ 'atomic' => true ] );
		$sheet  = [ 'b' => [ [ 'name' => 'Iron Will' ], self::custom( 'Iron Will*' ) ] ];
		$result = Custom_Rekey::plan_character( $sheet, [ 'b' => $block ], [], [ 'suggestions' => false ] );

		$this->assertSame( [ 'Iron Will', 'Iron Will' ], array_column( $result['sheet_data']['b'], 'name' ) );
		$this->assertSame( 1, $result['counts']['rekeyed'] );
	}

	// --- rows the planner does not decide -------------------------------------

	public function test_a_custom_row_matching_nothing_stays_and_is_reported_with_suggestions(): void {
		$row    = self::custom( 'Dextrous' );
		$result = $this->plan_one( self::block( [ [ 'name' => 'Dexterous' ] ] ), $row, [] );

		$record = self::only_record( $result );
		$this->assertSame( 'kept', $record['outcome'] );
		$this->assertSame( 'no_match', $record['reason'] );
		$this->assertContains( 'Dexterous', $record['suggestions'] );
		$this->assertSame( $row, $result['sheet_data']['b'][0], 'a suggestion is never applied' );
	}

	public function test_suggestions_can_be_switched_off(): void {
		$result = $this->plan_one( self::block( [ [ 'name' => 'Dexterous' ] ] ), self::custom( 'Dextrous' ), [ 'suggestions' => false ] );

		$this->assertArrayNotHasKey( 'suggestions', self::only_record( $result ) );
	}

	public function test_a_custom_row_in_a_tiered_block_is_counted_and_never_rekeyed(): void {
		$tiered = self::def( [ 'powers' => [ [ 'name' => 'Animalism' ] ] ] );
		$sheet  = [ 'b' => [ self::custom( 'Animalism' ) ] ];
		$result = Custom_Rekey::plan_character( $sheet, [ 'b' => $tiered ], [] );

		$this->assertSame( $sheet, $result['sheet_data'] );
		$this->assertSame( 1, $result['counts']['tiered_custom'] );
		$this->assertSame( [], $result['records'] );
	}

	public function test_a_custom_row_in_a_block_with_no_definition_is_kept_and_reported(): void {
		$sheet  = [ 'b' => [ self::custom( 'Occult' ) ] ];
		$result = Custom_Rekey::plan_character( $sheet, [], [] );

		$this->assertSame( $sheet, $result['sheet_data'] );
		$this->assertSame( 'block_unresolved', self::only_record( $result )['reason'] );
	}

	public function test_custom_rows_are_matched_in_blocks_that_are_not_moved_too(): void {
		$sheet  = [ 'vampire-backgrounds' => [ self::custom( 'Allies' ) ] ];
		$result = Custom_Rekey::plan_character( $sheet, [ 'vampire-backgrounds' => self::block( [ [ 'name' => 'Allies' ] ] ) ], [ 'met-abilities' => 'vampire-abilities' ] );

		$record = self::only_record( $result );
		$this->assertSame( 'vampire-backgrounds', $record['block_from'] );
		$this->assertSame( 'rekeyed', $record['outcome'] );
	}

	public function test_a_second_plan_of_the_planned_sheet_changes_nothing(): void {
		$blocks = [ 'vampire-abilities' => self::block( [ [ 'name' => 'Occult' ], [ 'name' => 'Lore', 'allow_multiples' => true ] ] ) ];
		$map    = [ 'met-abilities' => 'vampire-abilities' ];
		$sheet  = [ 'met-abilities' => [ self::custom( 'occult' ), self::custom( 'Lore: Kindred' ), self::custom( 'Nothing Like It' ) ] ];

		$first  = Custom_Rekey::plan_character( $sheet, $blocks, $map, [ 'suggestions' => false ] );
		$second = Custom_Rekey::plan_character( $first['sheet_data'], $blocks, $map, [ 'suggestions' => false ] );

		$this->assertTrue( $first['changed'] );
		$this->assertFalse( $second['changed'] );
		$this->assertSame( $first['sheet_data'], $second['sheet_data'] );
	}

	public function test_only_sheet_data_is_planned_so_no_xp_key_is_ever_read_or_written(): void {
		$sheet  = [ 'met-abilities' => [ self::custom( 'Occult' ) ], 'xp_earned' => 90, 'xp_unspent' => 4 ];
		$result = Custom_Rekey::plan_character( $sheet, [ 'vampire-abilities' => self::block( [ [ 'name' => 'Occult' ] ] ) ], [ 'met-abilities' => 'vampire-abilities' ] );

		$this->assertSame( 90, $result['sheet_data']['xp_earned'] );
		$this->assertSame( 4, $result['sheet_data']['xp_unspent'] );
	}

	// --- Retention gaps and pre-existing duplicates ---------------------------

	private const MOVE = [ 'met-abilities' => 'vampire-abilities' ];

	public function test_a_moved_catalog_row_the_replacement_carries_is_no_gap(): void {
		$result = Custom_Rekey::plan_character(
			[ 'met-abilities' => [ [ 'name' => 'Brawl', 'count' => 3 ] ] ],
			[ 'vampire-abilities' => self::block( [ [ 'name' => 'Brawl' ] ] ) ],
			self::MOVE
		);

		$this->assertSame( [], $result['retention_gaps'] );
	}

	public function test_a_decorated_catalog_name_still_resolves_after_the_decoration_strip(): void {
		$result = Custom_Rekey::plan_character(
			[ 'met-abilities' => [ [ 'name' => 'Brawl*' ], [ 'name' => 'Occult [Sabbat]' ] ] ],
			[ 'vampire-abilities' => self::block( [ [ 'name' => 'Brawl' ], [ 'name' => 'Occult' ] ] ) ],
			self::MOVE
		);

		$this->assertSame( [], $result['retention_gaps'] );
	}

	public function test_a_moved_catalog_row_the_replacement_lacks_is_a_retention_gap(): void {
		$result = Custom_Rekey::plan_character(
			[ 'met-abilities' => [ [ 'name' => 'Brawl' ], [ 'name' => 'Basket Weaving', 'count' => 2 ] ] ],
			[ 'vampire-abilities' => self::block( [ [ 'name' => 'Brawl' ] ] ) ],
			self::MOVE
		);

		$this->assertSame(
			[ [ 'block_from' => 'met-abilities', 'block_to' => 'vampire-abilities', 'index' => 1, 'name' => 'Basket Weaving', 'reason' => 'not_in_replacement' ] ],
			$result['retention_gaps']
		);
		// The row still moves - a gap blocks apply(), it does not change the plan's shape.
		$this->assertSame( 'Basket Weaving', $result['sheet_data']['vampire-abilities'][1]['name'] );
	}

	public function test_a_gaps_index_is_its_position_in_the_replacement_list(): void {
		$result = Custom_Rekey::plan_character(
			[ 'met-abilities' => [ [ 'name' => 'Nope' ] ], 'vampire-abilities' => [ [ 'name' => 'Brawl' ], [ 'name' => 'Occult' ] ] ],
			[ 'vampire-abilities' => self::block( [ [ 'name' => 'Brawl' ], [ 'name' => 'Occult' ] ] ) ],
			self::MOVE
		);

		$this->assertSame( 2, $result['retention_gaps'][0]['index'] );
	}

	public function test_a_missing_replacement_definition_is_one_gap_for_the_block_not_one_per_row(): void {
		$result = Custom_Rekey::plan_character(
			[ 'met-abilities' => [ [ 'name' => 'Brawl' ], [ 'name' => 'Occult' ] ] ],
			[],
			self::MOVE
		);

		$this->assertSame(
			[ [ 'block_from' => 'met-abilities', 'block_to' => 'vampire-abilities', 'index' => null, 'name' => null, 'reason' => 'block_unresolved' ] ],
			$result['retention_gaps']
		);
	}

	public function test_a_custom_row_is_never_a_retention_gap(): void {
		$result = Custom_Rekey::plan_character(
			[ 'met-abilities' => [ self::custom( 'Basket Weaving' ) ] ],
			[ 'vampire-abilities' => self::block( [ [ 'name' => 'Brawl' ] ] ) ],
			self::MOVE,
			[ 'suggestions' => false ]
		);

		$this->assertSame( [], $result['retention_gaps'] );
	}

	public function test_only_moved_blocks_are_audited_for_gaps(): void {
		// A catalog row in a block that did not move is exactly where it was.
		$result = Custom_Rekey::plan_character(
			[ 'vampire-backgrounds' => [ [ 'name' => 'Not In This Catalog' ] ] ],
			[ 'vampire-backgrounds' => self::block( [ [ 'name' => 'Allies' ] ] ) ],
			self::MOVE
		);

		$this->assertSame( [], $result['retention_gaps'] );
	}

	public function test_a_tiered_replacement_has_no_item_list_to_audit(): void {
		$result = Custom_Rekey::plan_character(
			[ 'old-tiered' => [ [ 'name' => 'Animalism', 'level' => 3 ] ] ],
			[ 'new-tiered' => self::def( [ 'powers' => [ [ 'name' => 'Animalism' ] ] ] ) ],
			[ 'old-tiered' => 'new-tiered' ]
		);

		$this->assertSame( [], $result['retention_gaps'] );
	}

	public function test_moved_catalog_rows_already_sharing_an_identity_are_reported_and_left_alone(): void {
		$rows   = [ [ 'name' => 'Brawl', 'count' => 2 ], [ 'name' => 'Brawl', 'count' => 3 ], [ 'name' => 'Occult' ] ];
		$result = Custom_Rekey::plan_character(
			[ 'met-abilities' => $rows ],
			[ 'vampire-abilities' => self::block( [ [ 'name' => 'Brawl' ], [ 'name' => 'Occult' ] ] ) ],
			self::MOVE
		);

		$this->assertSame(
			[ [ 'block_to' => 'vampire-abilities', 'name' => 'Brawl', 'specialization' => null, 'indices' => [ 0, 1 ] ] ],
			$result['duplicates']
		);
		$this->assertSame( $rows, $result['sheet_data']['vampire-abilities'], 'never merged, never touched' );
	}

	public function test_an_atomic_block_reports_no_duplicates(): void {
		$result = Custom_Rekey::plan_character(
			[ 'met-merits' => [ [ 'name' => 'Iron Will' ], [ 'name' => 'Iron Will' ] ] ],
			[ 'vampire-merits' => self::block( [ [ 'name' => 'Iron Will' ] ], [ 'atomic' => true ] ) ],
			[ 'met-merits' => 'vampire-merits' ]
		);

		$this->assertSame( [], $result['duplicates'] );
	}

	public function test_a_label_keeps_two_moved_rows_distinct_where_the_item_allows_multiples(): void {
		$result = Custom_Rekey::plan_character(
			[ 'met-abilities' => [ [ 'name' => 'Lore', 'specialization' => 'Kindred' ], [ 'name' => 'Lore', 'specialization' => 'Garou' ] ] ],
			[ 'vampire-abilities' => self::block( [ [ 'name' => 'Lore', 'allow_multiples' => true ] ] ) ],
			self::MOVE
		);

		$this->assertSame( [], $result['duplicates'] );
	}

	public function test_a_doubled_labelled_row_names_its_label_in_the_report(): void {
		$rows   = [ [ 'name' => 'Lore', 'specialization' => 'Kindred' ], [ 'name' => 'Lore', 'specialization' => 'Kindred' ] ];
		$result = Custom_Rekey::plan_character(
			[ 'met-abilities' => $rows ],
			[ 'vampire-abilities' => self::block( [ [ 'name' => 'Lore', 'allow_multiples' => true ] ] ) ],
			self::MOVE
		);

		$this->assertSame( 'Kindred', $result['duplicates'][0]['specialization'] );
	}

	public function test_a_clean_sheet_reports_nothing(): void {
		$result = Custom_Rekey::plan_character(
			[ 'met-abilities' => [ [ 'name' => 'Brawl' ], [ 'name' => 'Occult' ] ] ],
			[ 'vampire-abilities' => self::block( [ [ 'name' => 'Brawl' ], [ 'name' => 'Occult' ] ] ) ],
			self::MOVE
		);

		$this->assertSame( [], $result['retention_gaps'] );
		$this->assertSame( [], $result['duplicates'] );
	}

	// --- A catalog row spelled another way is respelled, not a gap --------------

	/**
	 * Legacy spellings of a declared item: a respelling, not a gap.
	 *
	 * @return array<string,array{0:string,1:string,2:string}> legacy name, declared name, tier.
	 */
	public static function respellings(): array {
		return [
			'case'              => [ 'Fortune-telling', 'Fortune-Telling', 'normalized' ],
			'case in a phrase'  => [ 'Repelled by Crosses', 'Repelled By Crosses', 'normalized' ],
			'space for hyphen'  => [ 'Light Sensitive', 'Light-Sensitive', 'normalized' ],
			'curly apostrophe'  => [ "Poseidon\u{2019}s Call", "Poseidon's Call", 'normalized' ],
			'curly and a case'  => [ "Dracon\u{2019}s Temperament", "Dracon's temperament", 'normalized' ],
		];
	}

	/** @dataProvider respellings */
	public function test_a_moved_catalog_row_spelled_another_way_is_respelled_not_a_gap( string $legacy, string $declared, string $tier ): void {
		$result = Custom_Rekey::plan_character(
			[ 'met-flaws' => [ [ 'name' => $legacy, 'count' => 2, 'note' => 'kept' ] ] ],
			[ 'vampire-flaws' => self::block( [ [ 'name' => $declared ] ] ) ],
			[ 'met-flaws' => 'vampire-flaws' ]
		);

		$this->assertSame( [], $result['retention_gaps'] );
		$this->assertSame(
			[ [ 'name' => $declared, 'count' => 2, 'note' => 'kept', 'rekeyed_from' => $legacy ] ],
			$result['sheet_data']['vampire-flaws']
		);
		$record = self::only_record( $result );
		$this->assertSame( 'rekeyed', $record['outcome'] );
		$this->assertSame( $tier, $record['tier'] );
		$this->assertSame( $declared, $record['to'] );
		$this->assertTrue( $record['catalog'], 'the record says it was a catalog row, not a custom entry' );
	}

	public function test_a_respelled_row_counts_as_respelled_and_never_as_a_custom_match(): void {
		$result = Custom_Rekey::plan_character(
			[ 'met-abilities' => [ [ 'name' => 'Fortune-telling' ], self::custom( 'Fortune-Telling' ) ] ],
			[ 'vampire-abilities' => self::block( [ [ 'name' => 'Fortune-Telling' ] ], [ 'atomic' => true ] ) ],
			self::MOVE
		);

		$this->assertSame( 1, $result['counts']['respelled'] );
		$this->assertSame( 1, $result['counts']['rekeyed'], 'the custom row still counts as the custom match it is' );
		$this->assertSame( [ 'exact' => 1 ], $result['counts']['by_tier'], 'by_tier stays a custom-entry breakdown' );
		$this->assertSame( 0, $result['counts']['kept_custom'] );
	}

	public function test_a_recorded_former_name_respells_a_moved_catalog_row(): void {
		$result = Custom_Rekey::plan_character(
			[ 'met-abilities' => [ [ 'name' => 'Meditiation', 'count' => 3 ] ] ],
			[ 'vampire-abilities' => self::block( [ [ 'name' => 'Meditation', 'aliases' => [ 'Meditiation' ] ] ] ) ],
			self::MOVE
		);

		$this->assertSame( [], $result['retention_gaps'] );
		$this->assertSame( 'Meditation', $result['sheet_data']['vampire-abilities'][0]['name'] );
		$this->assertSame( 'alias', self::only_record( $result )['tier'] );
	}

	public function test_a_decorated_name_that_only_matches_after_the_strip_and_a_normalize_is_respelled(): void {
		$result = Custom_Rekey::plan_character(
			[ 'met-abilities' => [ [ 'name' => 'Fortune-telling [Sabbat]' ] ] ],
			[ 'vampire-abilities' => self::block( [ [ 'name' => 'Fortune-Telling' ] ] ) ],
			self::MOVE
		);

		$this->assertSame( [], $result['retention_gaps'] );
		$this->assertSame( 'Fortune-Telling', $result['sheet_data']['vampire-abilities'][0]['name'] );
		$this->assertSame( 'decoration', self::only_record( $result )['tier'] );
	}

	public function test_a_respelled_row_keeps_every_key_it_had(): void {
		$row    = [ 'name' => 'Fortune-telling', 'count' => 3, 'specialization' => 'Tarot', 'note' => 'n', 'temp' => 1, 'source' => 'catalog' ];
		$result = Custom_Rekey::plan_character(
			[ 'met-abilities' => [ $row ] ],
			[ 'vampire-abilities' => self::block( [ [ 'name' => 'Fortune-Telling' ] ], [ 'atomic' => true ] ) ],
			self::MOVE
		);

		$this->assertSame(
			$row + [ 'rekeyed_from' => 'Fortune-telling' ],
			array_merge( $result['sheet_data']['vampire-abilities'][0], [ 'name' => 'Fortune-telling' ] )
		);
	}

	public function test_a_name_that_only_looks_close_is_still_a_gap(): void {
		$result = Custom_Rekey::plan_character(
			[ 'met-abilities' => [ [ 'name' => 'Fortune-tellin' ], [ 'name' => 'Basket Weaving' ] ] ],
			[ 'vampire-abilities' => self::block( [ [ 'name' => 'Fortune-Telling' ] ] ) ],
			self::MOVE
		);

		$this->assertSame( [ 0, 1 ], array_column( $result['retention_gaps'], 'index' ), 'a typo is not a spelling variant; only the fuzzy suggestion tier would guess, and a catalog row never gets one' );
		$this->assertSame( 'Fortune-tellin', $result['sheet_data']['vampire-abilities'][0]['name'] );
		$this->assertSame( 0, $result['counts']['respelled'] );
	}

	public function test_a_catalog_row_only_a_rule_based_reading_would_match_is_still_a_gap(): void {
		$rules = Custom_Rekey::plan_character(
			[ 'old-rituals' => [ [ 'name' => 'Thaum: Blood Walk (b)' ] ] ],
			[ 'vampire-rituals' => self::rituals() ],
			[ 'old-rituals' => 'vampire-rituals' ]
		);
		$this->assertSame( [ 'not_in_replacement' ], array_column( $rules['retention_gaps'], 'reason' ) );
		$this->assertSame( 'Thaum: Blood Walk (b)', $rules['sheet_data']['vampire-rituals'][0]['name'] );

		$label = Custom_Rekey::plan_character(
			[ 'met-abilities' => [ [ 'name' => 'Brawl: Boxing' ] ] ],
			[ 'vampire-abilities' => self::block( [ [ 'name' => 'Brawl' ] ] ) ],
			self::MOVE
		);
		$this->assertSame( [ 'not_in_replacement' ], array_column( $label['retention_gaps'], 'reason' ) );
		$this->assertSame( 'Brawl: Boxing', $label['sheet_data']['vampire-abilities'][0]['name'] );
	}

	public function test_a_name_two_declared_items_share_is_an_ambiguous_gap_and_left_alone(): void {
		$result = Custom_Rekey::plan_character(
			[ 'met-abilities' => [ [ 'name' => 'FIRE WALK' ] ] ],
			[ 'vampire-abilities' => self::block( [ [ 'name' => 'Fire-Walk' ], [ 'name' => 'Fire Walk' ] ] ) ],
			self::MOVE
		);

		$this->assertSame( [ 'ambiguous' ], array_column( $result['retention_gaps'], 'reason' ) );
		$this->assertSame( 'FIRE WALK', $result['sheet_data']['vampire-abilities'][0]['name'] );
	}

	public function test_a_catalog_row_with_a_chosen_cost_is_not_respelled(): void {
		$result = Custom_Rekey::plan_character(
			[ 'met-abilities' => [ [ 'name' => 'Fortune-telling', 'chosen_cost' => 2 ] ] ],
			[ 'vampire-abilities' => self::block( [ [ 'name' => 'Fortune-Telling' ] ] ) ],
			self::MOVE
		);

		$this->assertSame( [ 'has_custom_price' ], array_column( $result['retention_gaps'], 'reason' ) );
		$this->assertSame( 'Fortune-telling', $result['sheet_data']['vampire-abilities'][0]['name'] );
	}

	public function test_a_catalog_row_carrying_the_removed_marker_is_not_respelled(): void {
		$result = Custom_Rekey::plan_character(
			[ 'met-abilities' => [ [ 'name' => 'Fortune-telling', '_removed' => true ] ] ],
			[ 'vampire-abilities' => self::block( [ [ 'name' => 'Fortune-Telling' ] ] ) ],
			self::MOVE
		);

		$this->assertSame( [ 'removed' ], array_column( $result['retention_gaps'], 'reason' ) );
	}

	public function test_a_respelling_that_would_double_a_held_row_is_a_collision_gap_and_neither_row_changes(): void {
		$rows   = [ [ 'name' => 'Fortune-Telling', 'count' => 1 ], [ 'name' => 'Fortune-telling', 'count' => 2 ] ];
		$result = Custom_Rekey::plan_character(
			[ 'met-abilities' => $rows ],
			[ 'vampire-abilities' => self::block( [ [ 'name' => 'Fortune-Telling' ] ] ) ],
			self::MOVE
		);

		$this->assertSame( [ [ 'block_from' => 'met-abilities', 'block_to' => 'vampire-abilities', 'index' => 1, 'name' => 'Fortune-telling', 'reason' => 'collision' ] ], $result['retention_gaps'] );
		$this->assertSame( $rows, $result['sheet_data']['vampire-abilities'], 'never merged' );
		$this->assertSame( [], $result['records'], 'a blocked catalog row is a gap, not a kept custom entry' );
		$this->assertSame( 0, $result['counts']['kept_custom'] );
	}

	public function test_an_atomic_block_respells_both_when_two_rows_share_a_declared_name(): void {
		$result = Custom_Rekey::plan_character(
			[ 'met-flaws' => [ [ 'name' => 'Light Sensitive' ], [ 'name' => 'Light-sensitive' ] ] ],
			[ 'vampire-flaws' => self::block( [ [ 'name' => 'Light-Sensitive' ] ], [ 'atomic' => true ] ) ],
			[ 'met-flaws' => 'vampire-flaws' ]
		);

		$this->assertSame( [], $result['retention_gaps'] );
		$this->assertSame( [ 'Light-Sensitive', 'Light-Sensitive' ], array_column( $result['sheet_data']['vampire-flaws'], 'name' ) );
		$this->assertSame( 2, $result['counts']['respelled'] );
	}

	public function test_a_catalog_row_in_a_block_that_did_not_move_is_never_respelled(): void {
		$result = Custom_Rekey::plan_character(
			[ 'vampire-backgrounds' => [ [ 'name' => 'allies' ] ] ],
			[ 'vampire-backgrounds' => self::block( [ [ 'name' => 'Allies' ] ] ) ],
			self::MOVE
		);

		$this->assertSame( 'allies', $result['sheet_data']['vampire-backgrounds'][0]['name'] );
		$this->assertSame( [], $result['records'] );
	}

	public function test_a_second_plan_of_a_respelled_sheet_changes_nothing(): void {
		$blocks = [ 'vampire-abilities' => self::block( [ [ 'name' => 'Fortune-Telling' ] ] ) ];
		$first  = Custom_Rekey::plan_character( [ 'met-abilities' => [ [ 'name' => 'Fortune-telling' ] ] ], $blocks, self::MOVE );
		$second = Custom_Rekey::plan_character( $first['sheet_data'], $blocks, self::MOVE );

		$this->assertSame( 'Fortune-Telling', $first['sheet_data']['vampire-abilities'][0]['name'] );
		$this->assertFalse( $second['changed'] );
		$this->assertSame( $first['sheet_data'], $second['sheet_data'] );
		$this->assertSame( [], $second['retention_gaps'] );
	}
}
