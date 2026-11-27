<?php

namespace BeyondElysium\Tests\Unit;

use BeyondElysium\Services\Bylaw_Reason;
use BeyondElysium\Services\Bylaws;
use BeyondElysium\Services\Coordinator_Signal;
use PHPUnit\Framework\TestCase;

/**
 * Reads a change's reason text for a Coordinator Approval or a Coordinator Notify.
 */
class CoordinatorSignalTest extends TestCase {

	/**
	 * Every distinct PC or NPC tier in the shipped bylaws and what it reads as.
	 *
	 * @return array<string,?string>
	 */
	private function pinned_tiers(): array {
		return [
			'. PC Coordinator Notify'                                      => null,
			'2/3 Majority Vote'                                            => null,
			'Approval'                                                     => null,
			'Coord Approval'                                               => 'approval',
			'Coordinator'                                                  => null,
			'Coordinator Approval'                                         => 'approval',
			'Coordinator Approval for each level'                          => 'approval',
			'Coordinator Approval – Setite Coordinator'                    => 'approval',
			'Coordinator Approval,'                                        => 'approval',
			'Coordinator Notify'                                           => 'notify',
			'Coordinator Notify Coordinator Changing Breeds'               => 'notify',
			'Coordinator Notify or Approval as Appropriate'                => 'notify',
			'Coordinator approval'                                         => 'approval',
			'Coordinator approval Wraith Coordinator and Giovanni Coordinator. NPC' => 'approval',
			'Council Vote'                                                 => null,
			'Disallowed'                                                   => null,
			'Disallowed (as you can not be an Initiate of just the bloodline)' => null,
			'Disallowed (as you cannot be a convert of just the bloodline)' => null,
			'Disallowed for Non-Brujah'                                    => null,
			'Disallowed.'                                                  => null,
			'Majority Vote'                                                => null,
			'N/A'                                                          => null,
			'None'                                                         => null,
			'Notify'                                                       => null,
			'Simple Majority Vote'                                         => null,
			'Storyteller Approval'                                         => null,
			'Storyteller Approval – Storyteller: Varies Storyteller(s) that controls the canonical NPC' => null,
			'Tremere Coordinator Controlled'                               => null,
			'Unregulated'                                                  => null,
			'Unrestricted'                                                 => null,
			'Varies'                                                       => null,
			'Varies (See Below)'                                           => null,
			'resurrections may do so as part of their Coordinator duties, specified in the Coordinator Bylaws, Section 3.C.iii.' => null,
			's Teaching Advanced Chimerstry'                               => null,
			's Teaching Advanced Thanatosis'                               => null,
			's of 1st – 13th Generation: Disallowed'                       => null,
			's of 8th – 13th Generation: Disallowed'                       => null,
			's: Coordinator Approval'                                      => 'approval',
			's: Unregulated'                                               => null,
		];
	}

	public function test_every_tier_in_the_shipped_bylaws_is_pinned_here(): void {
		$tiers = [];
		foreach ( Bylaws::shipped()['rules'] as $rule ) {
			foreach ( [ 'pc', 'npc' ] as $axis ) {
				$tier = trim( (string) ( $rule[ $axis ] ?? '' ) );
				if ( $tier !== '' ) {
					$tiers[ $tier ] = true;
				}
			}
		}
		$shipped = array_keys( $tiers );
		sort( $shipped, SORT_STRING );
		$pinned = array_keys( $this->pinned_tiers() );
		sort( $pinned, SORT_STRING );

		$this->assertSame(
			$pinned,
			$shipped,
			'A tier spelling was added to or removed from the shipped bylaws: class it in pinned_tiers() as approval, notify or null.'
		);
	}

	public function test_each_pinned_tier_reads_as_pinned(): void {
		foreach ( $this->pinned_tiers() as $tier => $kind ) {
			$this->assertSame( $kind, Bylaw_Reason::tier_kind( $tier ), $tier );
		}
	}

	public function test_a_bylaw_line_that_needs_a_coordinator_is_an_approval_naming_the_coordinator(): void {
		$signal = Coordinator_Signal::of( 'Coordinator Approval — Assamite Coordinator [OWBN Character Bylaws 10.m.v.10, clause 8992]' );

		$this->assertSame( 'approval', $signal['kind'] );
		$this->assertSame( [ 'Assamite' ], $signal['coordinators'] );
		$this->assertCount( 1, $signal['lines'] );
	}

	public function test_two_coordinators_are_both_named(): void {
		$signal = Coordinator_Signal::of( 'Coordinator Approval — Assamite Coordinator and Brujah Coordinator [OWBN Character Bylaws 10.m.v.10, clause 8992]' );

		$this->assertSame( [ 'Assamite', 'Brujah' ], $signal['coordinators'] );
	}

	public function test_a_notify_line_is_a_notification(): void {
		$signal = Coordinator_Signal::of( 'Coordinator Notify — Hunter Coordinator — applies to: "True Faith 1-5" [OWBN Character Bylaws 10.e.v, clause 7838]' );

		$this->assertSame( 'notify', $signal['kind'] );
		$this->assertSame( [ 'Hunter' ], $signal['coordinators'] );
	}

	public function test_an_approval_outranks_a_notification_in_the_same_reason(): void {
		$signal = Coordinator_Signal::of(
			"Coordinator Notify — Hunter Coordinator [OWBN Character Bylaws 10.e.v, clause 7838]\n" .
			'Coordinator Approval — Assamite Coordinator [OWBN Character Bylaws 10.m.v.10, clause 8992]'
		);

		$this->assertSame( 'approval', $signal['kind'] );
		$this->assertSame( [ 'Assamite' ], $signal['coordinators'], 'Only the lines that made it an approval name who approves.' );
		$this->assertCount( 1, $signal['lines'] );
	}

	public function test_the_chronicles_own_preset_reads_the_same_way(): void {
		$this->assertSame( 'approval', Coordinator_Signal::of( 'Coordinator Approval' )['kind'] );
		$this->assertSame( 'notify', Coordinator_Signal::of( 'Coordinator Notify' )['kind'] );
		$this->assertSame( [], Coordinator_Signal::of( 'Coordinator Approval' )['coordinators'] );
	}

	public function test_a_chronicle_reason_that_begins_with_the_words_still_counts(): void {
		$this->assertSame( 'approval', Coordinator_Signal::of( 'Coordinator approval: ask Jesse first' )['kind'] );
	}

	public function test_the_chronicles_reason_and_a_bylaw_line_can_share_a_reason(): void {
		$signal = Coordinator_Signal::of( "Needs a look\nCoordinator Approval — Gangrel Coordinator [OWBN Character Bylaws 10.m.v.11, clause 8993]" );

		$this->assertSame( 'approval', $signal['kind'] );
		$this->assertSame( [ 'Gangrel' ], $signal['coordinators'] );
	}

	public function test_other_tiers_are_not_a_coordinator_signal(): void {
		foreach ( [
			'Disallowed — Brujah Coordinator [OWBN Character Bylaws 9.a.iv.1, clause 7760]',
			'Majority Vote — OWBN Council [OWBN Character Bylaws 10.a.1, clause 100]',
			'2/3 Majority Vote — OWBN Council, with Gangrel Coordinator [OWBN Character Bylaws 10.m.v.12, clause 8994]',
			'Storyteller Approval',
			'Needs a Storyteller to look at it',
			'',
		] as $reason ) {
			$signal = Coordinator_Signal::of( $reason );
			$this->assertNull( $signal['kind'], $reason );
			$this->assertSame( [], $signal['coordinators'], $reason );
		}
	}

	public function test_no_reason_is_no_signal(): void {
		$this->assertNull( Coordinator_Signal::of( null )['kind'] );
	}

	public function test_a_varying_coordinator_is_not_named_as_one(): void {
		$signal = Coordinator_Signal::of( 'Coordinator Approval — coordinator varies, see the clause [OWBN Character Bylaws 10.m.v.8, clause 8989]' );

		$this->assertSame( 'approval', $signal['kind'] );
		$this->assertSame( [], $signal['coordinators'] );
	}

	public function test_a_coordinator_named_twice_is_named_once(): void {
		$signal = Coordinator_Signal::of(
			"Coordinator Approval — Assamite Coordinator [OWBN Character Bylaws 10.m.v.10, clause 8992]\n" .
			'Coordinator Approval — Assamite Coordinator [OWBN Character Bylaws 10.m.v.22, clause 9030]'
		);

		$this->assertSame( [ 'Assamite' ], $signal['coordinators'] );
		$this->assertCount( 2, $signal['lines'] );
	}

	public function test_a_coordinator_approval_is_what_is_coordinator_approval_says(): void {
		$this->assertTrue( Bylaw_Reason::is_coordinator_approval( 'Coord Approval' ) );
		$this->assertFalse( Bylaw_Reason::is_coordinator_approval( 'Coordinator Notify' ) );
		$this->assertFalse( Bylaw_Reason::is_coordinator_approval( 'Storyteller Approval' ) );
	}
}
