<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Services\Character_Diff;
use WP_UnitTestCase;

/**
 * `Character_Diff::compare()` on parsed exchange characters: only real differences.
 */
class CharacterDiffThreadTest extends WP_UnitTestCase {

	private function character( array $overrides = [] ): array {
		return array_replace( [
			'race'          => 'vampire',
			'name'          => 'Marcus',
			'clan'          => 'Brujah',
			'generation'    => 10,
			'temp_honor'    => 2.5,
			'is_npc'        => false,
			'id'            => '',
			'last_modified' => '2026-09-01 10:00:00',
			'physical_max'  => 3,
			'experience'    => [ 'earned' => 20.0, 'unspent' => 5.0, 'history' => [] ],
			'trait_lists'   => [
				'Abilities' => [ 'name' => 'Abilities', 'traits' => [
					[ 'name' => 'Brawl', 'total' => '2', 'note' => '' ],
					[ 'name' => 'Occult', 'total' => '1', 'note' => '' ],
				] ],
				'Backgrounds' => [ 'name' => 'Backgrounds', 'traits' => [
					[ 'name' => 'Retainers', 'total' => '1', 'note' => 'Ghoul driver' ],
				] ],
			],
			'boons'         => [],
		], $overrides );
	}

	public function test_the_same_character_shows_no_changes(): void {
		$this->assertSame( [], Character_Diff::compare( $this->character(), $this->character() ) );
	}

	public function test_trait_order_is_not_a_change(): void {
		$here     = $this->character();
		$arriving = $this->character();
		$arriving['trait_lists']['Abilities']['traits'] = array_reverse( $arriving['trait_lists']['Abilities']['traits'] );

		$this->assertSame( [], Character_Diff::compare( $here, $arriving ) );
	}

	public function test_the_documents_own_identity_and_derived_counts_are_not_changes(): void {
		$arriving = $this->character( [
			'id' => 'https://home.example/be-verify/?code=ABC', 'uuid' => '0190c4d2-0000-7000-8000-000000000001',
			'last_modified' => '2026-09-14 12:00:00', 'physical_max' => 7, 'player' => 'Someone Else', 'narrator' => 'Another ST',
			'boons' => [ [ 'boon_type' => 'Minor', 'char_name' => 'Julian', 'is_owed' => true, 'boon_date' => null, 'description' => '' ] ],
		] );
		$arriving['experience']['history'] = [ [ 'when' => '2026-09-10', 'change' => 2.0, 'change_type' => 0, 'reason' => 'Game', 'earned' => 0.0, 'unspent' => 0.0 ] ];

		$this->assertSame( [], Character_Diff::compare( $this->character(), $arriving ) );
	}

	public function test_a_trait_is_known_by_its_name_and_note(): void {
		$arriving = $this->character();
		$arriving['trait_lists']['Backgrounds']['traits'] = [ [ 'name' => 'Retainers', 'total' => '1', 'note' => 'Ghoul bodyguard' ] ];

		$this->assertSame( [
			[ 'section' => 'Backgrounds', 'entry' => 'Retainers (Ghoul bodyguard)', 'here' => null, 'arriving' => '1' ],
			[ 'section' => 'Backgrounds', 'entry' => 'Retainers (Ghoul driver)', 'here' => '1', 'arriving' => null ],
		], Character_Diff::compare( $this->character(), $arriving ) );
	}

	public function test_a_list_only_one_side_carries_reports_each_trait(): void {
		$arriving = $this->character();
		unset( $arriving['trait_lists']['Backgrounds'] );
		$arriving['trait_lists']['Merits'] = [ 'name' => 'Merits', 'traits' => [ [ 'name' => 'Iron Will', 'total' => '1', 'note' => '' ] ] ];

		$this->assertEqualsCanonicalizing( [
			[ 'section' => 'Merits', 'entry' => 'Iron Will', 'here' => null, 'arriving' => '1' ],
			[ 'section' => 'Backgrounds', 'entry' => 'Retainers (Ghoul driver)', 'here' => '1', 'arriving' => null ],
		], Character_Diff::compare( $this->character(), $arriving ) );
	}

	public function test_details_and_experience_totals_read_as_a_person_would_write_them(): void {
		$arriving = $this->character( [
			'clan' => 'Toreador', 'generation' => 9, 'temp_honor' => 3.0, 'is_npc' => true,
			'experience' => [ 'earned' => 22.5, 'unspent' => 5.0, 'history' => [] ],
		] );

		$this->assertSame( [
			[ 'section' => 'Details', 'entry' => 'Clan', 'here' => 'Brujah', 'arriving' => 'Toreador' ],
			[ 'section' => 'Details', 'entry' => 'Generation', 'here' => '10', 'arriving' => '9' ],
			[ 'section' => 'Details', 'entry' => 'Temp Honor', 'here' => '2.5', 'arriving' => '3' ],
			[ 'section' => 'Details', 'entry' => 'NPC', 'here' => 'No', 'arriving' => 'Yes' ],
			[ 'section' => 'Experience', 'entry' => 'Earned', 'here' => '20', 'arriving' => '22.5' ],
		], Character_Diff::compare( $this->character(), $arriving ) );
	}

	public function test_long_text_is_shortened(): void {
		$arriving = $this->character( [ 'notes' => str_repeat( 'Long notes. ', 40 ) ] );

		$row = Character_Diff::compare( $this->character(), $arriving )[0];

		$this->assertSame( 'Notes', $row['entry'] );
		$this->assertSame( '', $row['here'] );
		$this->assertSame( 120, mb_strlen( $row['arriving'] ) );
		$this->assertStringEndsWith( "\u{2026}", $row['arriving'] );
	}
}
