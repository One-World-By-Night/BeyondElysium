<?php

namespace BeyondElysium\Tests\Unit;

use BeyondElysium\Services\Oat_Request_Builder;
use PHPUnit\Framework\TestCase;

/**
 * The R&U Request BE files in the Archivist Toolkit for one change.
 */
class OatRequestBuilderTest extends TestCase {

	/**
	 * @param array<string,mixed> $over
	 * @return array<string,mixed>
	 */
	private function input( array $over = [] ): array {
		return array_replace( [
			'chronicle'      => 'kony',
			'uuid'           => '6f1d0a52-1111-4222-8333-444455556666',
			'character_uuid' => 'a3f1c0de-0000-4000-8000-000000000001',
			'character_name' => 'Isolde Marchetti',
			'is_npc'         => false,
			'character_status' => 'active',
			'sender_name'    => 'Alice Morgan',
			'sender_email'   => 'alice@example.org',
			'line'           => [
				'label'       => 'Dual-Blooded',
				'section'     => 'Merits',
				'detail'      => '2 points',
				'xp'          => 2,
				'reason'      => "Coordinator Approval — Assamite Coordinator [OWBN Character Bylaws 10.m.v.10, clause 8992]",
				'player_note' => 'Her sire was a Ravnos.',
			],
			'rules'          => [ [ 'id' => 4125, 'pc_level' => 'coordinator_approval', 'npc_level' => 'coordinator_approval' ] ],
			'coordinators'   => [],
			'note'           => 'Please look at the Assamite side.',
			'purpose'        => 'approval',
			'source'         => 'chronicles.owbn.net/kony/change/812',
			'prior'          => [],
		], $over );
	}

	public function test_an_approval_files_an_ru_request_that_must_go_through_every_step(): void {
		$body = Oat_Request_Builder::build( $this->input() );

		$this->assertSame( 'character_lifecycle', $body['domain'] );
		$this->assertSame( 'cl_ru_request', $body['form_slug'] );
		$this->assertSame( 'kony', $body['chronicle_slug'] );
		$this->assertSame( [ 4125 ], $body['rules'] );
		$this->assertSame( '6f1d0a52-1111-4222-8333-444455556666', $body['idempotency_key'] );
		$this->assertTrue( $body['full_chain'] );
		$this->assertArrayNotHasKey( 'batch', $body );
		$this->assertSame( 'Isolde Marchetti: Dual-Blooded', $body['title'] );
	}

	public function test_the_form_fields_are_the_ones_the_toolkits_own_form_sends(): void {
		$meta = Oat_Request_Builder::build( $this->input() )['meta'];

		$this->assertSame( 'kony', $meta['chronicle_slug'] );
		$this->assertSame( 'Alice Morgan', $meta['submitter_name'] );
		$this->assertSame( 'alice@example.org', $meta['submitter_email'] );
		$this->assertSame( 'a3f1c0de-0000-4000-8000-000000000001', $meta['character_name'] );
		$this->assertSame( 'active', $meta['ru_sub_status'] );
		$this->assertSame( 'ru_request', $meta['action_type'] );
		$this->assertSame( '1', $meta['requires_coord'] );
		$this->assertSame( 'coordinator_approval', $meta['regulation_level'] );
		$this->assertSame( 'chronicles.owbn.net/kony/change/812', $meta['be_source'] );
	}

	public function test_a_request_with_a_rule_leaves_what_it_is_about_to_the_toolkit(): void {
		$this->assertArrayNotHasKey( 'item_description', Oat_Request_Builder::build( $this->input() )['meta'] );
	}

	public function test_a_request_with_no_rule_says_what_it_is_about_itself_and_still_needs_a_coordinator(): void {
		$body = Oat_Request_Builder::build( $this->input( [ 'rules' => [] ] ) );

		$this->assertSame( [], $body['rules'] );
		$this->assertSame( 'Merits: Dual-Blooded', $body['meta']['item_description'] );
		$this->assertSame( '1', $body['meta']['requires_coord'] );
		$this->assertSame( 'coordinator_approval', $body['meta']['regulation_level'] );
	}

	public function test_the_highest_level_among_the_rules_is_the_regulation_level(): void {
		$body = Oat_Request_Builder::build( $this->input( [ 'rules' => [
			[ 'id' => 1, 'pc_level' => 'coordinator_notify', 'npc_level' => 'coordinator_notify' ],
			[ 'id' => 2, 'pc_level' => 'council_vote', 'npc_level' => 'coordinator_approval' ],
			[ 'id' => 3, 'pc_level' => 'coordinator_approval', 'npc_level' => 'coordinator_approval' ],
		] ] ) );

		$this->assertSame( 'council_vote', $body['meta']['regulation_level'] );
		$this->assertSame( [ 1, 2, 3 ], $body['rules'] );
	}

	public function test_an_npc_reads_each_rules_npc_level(): void {
		$rules = [ [ 'id' => 2, 'pc_level' => 'council_vote', 'npc_level' => 'coordinator_approval' ] ];

		$this->assertSame( 'council_vote', Oat_Request_Builder::build( $this->input( [ 'rules' => $rules ] ) )['meta']['regulation_level'] );
		$this->assertSame( 'coordinator_approval', Oat_Request_Builder::build( $this->input( [ 'rules' => $rules, 'is_npc' => true ] ) )['meta']['regulation_level'] );
	}

	public function test_a_rule_with_no_npc_level_falls_back_to_its_pc_level_for_an_npc(): void {
		$rules = [ [ 'id' => 2, 'pc_level' => 'disallowed', 'npc_level' => '' ] ];

		$this->assertSame( 'disallowed', Oat_Request_Builder::build( $this->input( [ 'rules' => $rules, 'is_npc' => true ] ) )['meta']['regulation_level'] );
	}

	public function test_an_approval_sent_on_a_notify_level_rule_is_still_an_approval(): void {
		$rules = [ [ 'id' => 1, 'pc_level' => 'coordinator_notify', 'npc_level' => 'coordinator_notify' ] ];
		$meta  = Oat_Request_Builder::build( $this->input( [ 'rules' => $rules ] ) )['meta'];

		$this->assertSame( '1', $meta['requires_coord'] );
		$this->assertSame( 'coordinator_approval', $meta['regulation_level'] );
	}

	public function test_a_notification_does_not_ask_a_coordinator_to_approve(): void {
		$rules = [ [ 'id' => 1, 'pc_level' => 'coordinator_notify', 'npc_level' => 'coordinator_notify' ] ];
		$meta  = Oat_Request_Builder::build( $this->input( [ 'rules' => $rules, 'purpose' => 'notify' ] ) )['meta'];

		$this->assertSame( '0', $meta['requires_coord'] );
		$this->assertSame( 'coordinator_notify', $meta['regulation_level'] );
	}

	public function test_the_offices_ticked_go_along_once_each(): void {
		$meta = Oat_Request_Builder::build( $this->input( [ 'coordinators' => [ 'Assamite', 'brujah', 'assamite', '' ] ] ) )['meta'];

		$this->assertSame( '["assamite","brujah"]', $meta['involved_coordinators'] );
	}

	public function test_with_no_office_ticked_none_is_sent(): void {
		$this->assertArrayNotHasKey( 'involved_coordinators', Oat_Request_Builder::build( $this->input() )['meta'] );
	}

	public function test_requests_sent_together_are_filed_as_one_list(): void {
		$body = Oat_Request_Builder::build( $this->input( [ 'prior' => [ 33012, 33013 ] ] ) );

		$this->assertSame( [ 'prior' => [ 33012, 33013 ], 'mode' => 'list' ], $body['batch'] );
	}

	public function test_the_characters_status_is_the_toolkits_nearest_one(): void {
		foreach ( [ 'active' => 'active', 'pending' => 'active', 'dead' => 'dead', 'inactive' => 'inactive', 'retired' => 'inactive', '' => 'active' ] as $mine => $theirs ) {
			$meta = Oat_Request_Builder::build( $this->input( [ 'character_status' => $mine ] ) )['meta'];
			$this->assertSame( $theirs, $meta['ru_sub_status'], "'{$mine}'" );
		}
	}

	public function test_the_note_tells_the_coordinator_what_is_asked_and_why(): void {
		$note = Oat_Request_Builder::build( $this->input() )['note'];

		$this->assertStringContainsString( 'Sent from Beyond Elysium by Alice Morgan', $note );
		$this->assertStringContainsString( 'Dual-Blooded (Merits): 2 points, 2 XP', $note );
		$this->assertStringContainsString( 'Coordinator Approval — Assamite Coordinator [OWBN Character Bylaws 10.m.v.10, clause 8992]', $note );
		$this->assertStringContainsString( "Player's note: Her sire was a Ravnos.", $note );
		$this->assertStringContainsString( "Storyteller's note: Please look at the Assamite side.", $note );
	}

	public function test_the_description_is_the_same_account_without_the_senders_note_twice(): void {
		$body = Oat_Request_Builder::build( $this->input() );

		$this->assertStringContainsString( 'Dual-Blooded (Merits): 2 points, 2 XP', $body['description'] );
	}

	public function test_a_line_with_no_reason_or_notes_leaves_those_parts_out(): void {
		$body = Oat_Request_Builder::build( $this->input( [
			'line' => [ 'label' => 'Occult', 'section' => 'Abilities', 'detail' => 'to 4', 'xp' => 0, 'reason' => null, 'player_note' => null ],
			'note' => '',
		] ) );

		$this->assertStringNotContainsString( "Player's note", $body['note'] );
		$this->assertStringNotContainsString( "Storyteller's note", $body['note'] );
		$this->assertStringNotContainsString( 'Reason in Beyond Elysium', $body['note'] );
		$this->assertStringContainsString( 'Occult (Abilities): to 4', $body['note'] );
	}

	public function test_a_note_over_a_thousand_characters_is_cut_there(): void {
		$body = Oat_Request_Builder::build( $this->input( [ 'note' => str_repeat( 'x', 1500 ) ] ) );

		$this->assertSame( 1000, substr_count( $body['note'], 'x' ) );
	}
}
