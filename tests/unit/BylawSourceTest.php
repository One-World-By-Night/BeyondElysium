<?php

namespace BeyondElysium\Tests\Unit;

use BeyondElysium\Services\Bylaw_Source;
use PHPUnit\Framework\TestCase;

/**
 * Parses one council.owbn.net bylaw clause into a rule, against real clause text shapes.
 */
class BylawSourceTest extends TestCase {

	private function clause( string $slug, string $text, ?string $link = null ): array {
		return [
			'id'       => 100,
			'slug'     => $slug,
			'link'     => $link ?? "https://council.owbn.net/en/bylaw-clause/character/{$slug}/",
			'content'  => [ 'rendered' => '<p>' . $text . '</p>' ],
			'modified' => '2026-10-01T08:00:00',
		];
	}

	public function test_a_plain_clause_with_an_en_dash_and_one_coordinator(): void {
		$rule = Bylaw_Source::parse_clause( $this->clause(
			'10_h_i_7',
			'Stigma &#8211; PC: Disallowed &#8211; NPC: Coordinator Notify &#8211; Coordinator: Changeling'
		) );

		$this->assertSame( '10.h.i.7', $rule['path'] );
		$this->assertSame( 'Stigma', $rule['subject'] );
		$this->assertSame( 'Disallowed', $rule['pc'] );
		$this->assertSame( 'Coordinator Notify', $rule['npc'] );
		$this->assertSame( [ 'Changeling' ], $rule['coordinators'] );
		$this->assertSame( '2026-10-01', $rule['modified'] );
	}

	public function test_two_coordinators_joined_by_and_are_split_into_two(): void {
		$rule = Bylaw_Source::parse_clause( $this->clause(
			'10_m_ix_4_a_i_2',
			'Tyranny of the Wyrm – PC: Disallowed – NPC: Coordinator Approval – Coordinator: Demon and Malkavian'
		) );

		$this->assertSame( [ 'Demon', 'Malkavian' ], $rule['coordinators'] );
	}

	public function test_a_varies_tier_passes_through_as_plain_text(): void {
		$rule = Bylaw_Source::parse_clause( $this->clause(
			'10_x_1',
			'Something Rare – PC: Varies – NPC: Varies – Coordinator: Varies'
		) );

		$this->assertSame( 'Varies', $rule['pc'] );
		$this->assertSame( 'Varies', $rule['npc'] );
		$this->assertSame( [ 'Varies' ], $rule['coordinators'] );
	}

	public function test_an_empty_subject_takes_the_parent_text_passed_in(): void {
		$rule = Bylaw_Source::parse_clause(
			$this->clause( '10_g_iv_a', '– PC: Coordinator Approval – NPC: Unregulated' ),
			'Merits'
		);

		$this->assertSame( 'Merits', $rule['subject'] );
	}

	public function test_a_subject_is_never_overwritten_when_the_clause_has_its_own(): void {
		$rule = Bylaw_Source::parse_clause(
			$this->clause( '10_g_iv_a', 'Auspicious Birth – PC: Coordinator Approval – NPC: Unregulated' ),
			'Merits'
		);

		$this->assertSame( 'Auspicious Birth', $rule['subject'] );
	}

	public function test_a_clause_with_no_pc_or_npc_marker_is_not_a_rule(): void {
		$this->assertNull( Bylaw_Source::parse_clause( $this->clause( '10_h_i_5_a', 'Autumn Way' ) ) );
	}

	public function test_a_missing_colon_after_the_label_is_still_read(): void {
		$rule = Bylaw_Source::parse_clause( $this->clause(
			'10_m_ii_79',
			'Rakta-Radhu Bloodline – PC: Coordinator Approval – NPC Coordinator Approval – Coordinator Ravnos'
		) );

		$this->assertSame( 'Coordinator Approval', $rule['npc'] );
		$this->assertSame( [ 'Ravnos' ], $rule['coordinators'] );
	}

	public function test_build_rules_fills_an_empty_subject_from_its_nearest_ancestor(): void {
		$rules = Bylaw_Source::build_rules( [
			$this->clause( '10_g_iv', 'Merits', 'https://council.owbn.net/en/bylaw-clause/character/10_g_iv/' ),
			$this->clause( '10_g_iv_a', '– PC: Coordinator Approval – NPC: Unregulated', 'https://council.owbn.net/en/bylaw-clause/character/10_g_iv_a/' ),
		] );

		$this->assertCount( 1, $rules, 'the pure label node is not itself a rule' );
		$this->assertSame( 'Merits', $rules[0]['subject'] );
	}

	public function test_build_rules_drops_every_blood_magic_clause(): void {
		$rules = Bylaw_Source::build_rules( [
			$this->clause( '6_c_i_2_a', 'Custom Paradigms – PC: Disallowed – NPC: Disallowed – Coordinator: Tremere', 'https://council.owbn.net/en/bylaw-clause/character/6_c_i_2_a/' ),
			$this->clause( '10_h_i_7', 'Stigma – PC: Disallowed – NPC: Coordinator Notify – Coordinator: Changeling', 'https://council.owbn.net/en/bylaw-clause/character/10_h_i_7/' ),
		] );

		$this->assertCount( 1, $rules );
		$this->assertSame( '10.h.i.7', $rules[0]['path'] );
	}

	public function test_build_rules_drops_every_non_character_group(): void {
		$rules = Bylaw_Source::build_rules( [
			$this->clause( '1_a', 'Coordinator PCs – PC: Coordinator Approval – NPC: Unregulated', 'https://council.owbn.net/en/bylaw-clause/coordinator/1_a/' ),
		] );

		$this->assertSame( [], $rules );
	}

	public function test_build_rules_deduplicates_by_clause_id(): void {
		$one = $this->clause( '10_h_i_7', 'Stigma – PC: Disallowed – NPC: Coordinator Notify – Coordinator: Changeling', 'https://council.owbn.net/en/bylaw-clause/character/10_h_i_7/' );
		$rules = Bylaw_Source::build_rules( [ $one, $one ] );

		$this->assertCount( 1, $rules );
	}

	public function test_the_bylaw_group_is_read_from_the_permalink(): void {
		$this->assertSame(
			'character',
			Bylaw_Source::section_of( [ 'link' => 'https://council.owbn.net/en/bylaw-clause/character/10_g_iv_a/' ] )
		);
		$this->assertSame(
			'administrative',
			Bylaw_Source::section_of( [ 'link' => 'https://council.owbn.net/en/bylaw-clause/administrative/1_a/' ] )
		);
	}

	public function test_the_path_is_read_from_the_link_not_the_slug(): void {
		$rule = Bylaw_Source::parse_clause( $this->clause(
			'10_m_viii_88_e-2',
			'Sawafi\'s Form – PC: Coordinator Approval – NPC: Unregulated',
			'https://council.owbn.net/en/bylaw-clause/character/10_m_viii_88_e/'
		) );

		$this->assertSame( '10.m.viii.88.e', $rule['path'] );
	}

	public function test_a_stale_slug_never_leaks_into_the_path(): void {
		// Real council data: a clause's slug is frozen at creation and can go stale under a renumbering, while its
		// permalink always reflects the current numbering.
		$rule = Bylaw_Source::parse_clause( $this->clause(
			'6_a',
			'Vampire PCs – PC: Coordinator Approval – NPC: Unregulated',
			'https://council.owbn.net/en/bylaw-clause/mechanics/10_b/'
		) );

		$this->assertSame( '10.b', $rule['path'] );
	}
}
