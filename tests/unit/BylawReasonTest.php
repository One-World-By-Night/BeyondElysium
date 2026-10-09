<?php

namespace BeyondElysium\Tests\Unit;

use BeyondElysium\Services\Bylaw_Reason;
use PHPUnit\Framework\TestCase;

/**
 * Formats one bylaw rule's citation and reason text.
 */
class BylawReasonTest extends TestCase {

	private function rule( array $overrides = [] ): array {
		return array_merge( [
			'clause_id'    => 7838,
			'path'         => '10.e.v',
			'subject'      => 'True Faith 1-5',
			'pc'           => 'Coordinator Notify',
			'npc'          => 'Unregulated',
			'coordinators' => [ 'Hunter' ],
		], $overrides );
	}

	public function test_a_coordinator_notify_tier_cites_its_coordinator_and_scope(): void {
		$text = Bylaw_Reason::format( $this->rule(), 'pc', 'True Faith' );

		$this->assertSame(
			'Coordinator Notify — Hunter Coordinator — applies to: "True Faith 1-5" [OWBN Character Bylaws 10.e.v, clause 7838]',
			$text
		);
	}

	public function test_an_unregulated_axis_has_no_reason(): void {
		$this->assertNull( Bylaw_Reason::format( $this->rule(), 'npc', 'True Faith' ) );
	}

	public function test_scope_is_omitted_when_the_subject_matches_the_catalog_name_exactly(): void {
		$text = Bylaw_Reason::format( $this->rule( [ 'subject' => 'True Faith' ] ), 'pc', 'True Faith' );

		$this->assertSame(
			'Coordinator Notify — Hunter Coordinator [OWBN Character Bylaws 10.e.v, clause 7838]',
			$text
		);
	}

	public function test_scope_is_omitted_when_the_subject_only_differs_by_case_or_space(): void {
		$text = Bylaw_Reason::format( $this->rule( [ 'subject' => '  true   faith ' ] ), 'pc', 'True Faith' );

		$this->assertStringNotContainsString( 'applies to', $text );
	}

	public function test_a_coordinator_name_already_saying_coordinator_is_not_doubled(): void {
		$text = Bylaw_Reason::format( $this->rule( [
			'coordinators' => [ 'Relevant Vampire Clan Coordinator' ],
		] ), 'pc', 'True Faith 1-5' );

		$this->assertStringContainsString( 'Relevant Vampire Clan Coordinator', $text );
		$this->assertStringNotContainsString( 'Relevant Vampire Clan Coordinator Coordinator', $text );
	}

	public function test_two_coordinators_join_with_and(): void {
		$text = Bylaw_Reason::format( $this->rule( [ 'coordinators' => [ 'Demon', 'Malkavian' ] ] ), 'pc', 'True Faith 1-5' );

		$this->assertStringContainsString( 'Demon Coordinator and Malkavian Coordinator', $text );
	}

	public function test_a_vote_tier_prefixes_owbn_council(): void {
		$text = Bylaw_Reason::format( $this->rule( [
			'pc'           => 'Majority Vote',
			'coordinators' => [ 'Changing Breeds', 'Relevant Vampire Clan Coordinator', 'Relevant Sect Coordinator' ],
		] ), 'pc', 'True Faith 1-5' );

		$this->assertStringContainsString(
			'OWBN Council, with Changing Breeds Coordinator and Relevant Vampire Clan Coordinator and Relevant Sect Coordinator',
			$text
		);
	}

	public function test_a_vote_tier_with_no_coordinator_names_owbn_council_alone(): void {
		$text = Bylaw_Reason::format( $this->rule( [ 'pc' => 'Majority Vote', 'coordinators' => [] ] ), 'pc', 'True Faith 1-5' );

		$this->assertStringContainsString( 'Majority Vote — OWBN Council', $text );
	}

	public function test_varies_as_a_coordinator_name_reads_as_a_plain_note(): void {
		$text = Bylaw_Reason::format( $this->rule( [ 'coordinators' => [ 'Varies' ] ] ), 'pc', 'True Faith 1-5' );

		$this->assertStringContainsString( 'coordinator varies, see the clause', $text );
	}

	public function test_a_long_subject_is_truncated_at_a_word_boundary(): void {
		$subject = str_repeat( 'Kiasyd Alchemy Levels one two three four five ', 5 );
		$text    = Bylaw_Reason::format( $this->rule( [ 'subject' => trim( $subject ) ] ), 'pc', 'Something Else' );

		$this->assertMatchesRegularExpression( '/applies to: "[^"]{1,161}…"/', $text );
		$this->assertStringContainsString( 'Levels…', $text, 'truncation must land on a whole word, not mid-word' );
	}

	public function test_the_citation_is_always_last_and_names_the_path_and_clause_id(): void {
		$text = Bylaw_Reason::format( $this->rule(), 'pc', 'True Faith' );

		$this->assertStringEndsWith( '[OWBN Character Bylaws 10.e.v, clause 7838]', $text );
	}

	public function test_format_all_joins_several_rules_sorted_by_path(): void {
		$rules = [
			$this->rule( [ 'path' => '10.f.ii', 'pc' => 'Disallowed', 'clause_id' => 2 ] ),
			$this->rule( [ 'path' => '10.e.v', 'pc' => 'Coordinator Notify', 'clause_id' => 1 ] ),
		];

		$text = Bylaw_Reason::format_all( $rules, 'pc', 'True Faith' );
		$lines = explode( "\n", $text );

		$this->assertCount( 2, $lines );
		$this->assertStringContainsString( '10.e.v', $lines[0] );
		$this->assertStringContainsString( '10.f.ii', $lines[1] );
	}

	public function test_format_all_is_null_when_no_rule_has_anything_to_say_on_this_axis(): void {
		$rules = [ $this->rule( [ 'npc' => 'Unregulated' ] ) ];

		$this->assertNull( Bylaw_Reason::format_all( $rules, 'npc', 'True Faith' ) );
	}
}
