<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Character;
use BeyondElysium\Models\Game;
use BeyondElysium\Services\Query_Engine;
use BeyondElysium\Services\Rumor_Generator;
use WP_UnitTestCase;

/**
 * Group and subgroup rumors: one rumor per group and subgroup among active characters, such as a vampire's Clan and
 * Sect.
 */
class GroupRumorsThreadTest extends WP_UnitTestCase {

	private string $slug = 'thread-group-rumors';
	private int $game_id;

	public function setUp(): void {
		parent::setUp();
		$this->game_id = (int) Game::create( [
			'slug' => $this->slug, 'name' => 'Group Rumors',
			'settings' => [ 'apr' => [ 'group_rumors' => true, 'subgroup_rumors' => true, 'public_rumors' => false, 'influence_rumors' => false, 'previous_rumors' => false ] ],
		] );
		$this->character( 'Brujah One', 'vampire', [ 'vampire-identity' => [ 'Clan' => 'Brujah', 'Sect' => 'Anarch' ] ] );
		$this->character( 'Brujah Two', 'vampire', [ 'vampire-identity' => [ 'Clan' => 'Brujah', 'Sect' => 'Camarilla' ] ] );
		$this->character( 'Retired Toreador', 'vampire', [ 'vampire-identity' => [ 'Clan' => 'Toreador', 'Sect' => 'Camarilla' ] ], 'retired' );
		$this->character( 'Galliard', 'werewolf', [ 'werewolf-identity' => [ 'Tribe' => 'Fianna', 'Auspice' => 'Galliard' ] ] );
		$this->character( 'Corax Kin', 'fera', [ 'fera-identity' => [ 'Fera Type' => 'Corax', 'Auspice' => 'Galliard' ] ] );
		$this->character( 'Quiet Wraith', 'wraith', [ 'wraith-identity' => [ 'Guild' => 'Haunters' ] ] );
	}

	private function character( string $name, string $stack, array $sheet, string $status = 'active' ): void {
		Character::create( [ 'name' => $name, 'stack_slug' => $stack, 'owner_type' => 'chronicle', 'owner_slug' => $this->slug, 'status' => $status, 'sheet_data' => $sheet ] );
	}

	private function reached( array $candidate ): array {
		$names = array_map( static fn( int $id ) => Character::find( $id )->name, Query_Engine::resolve_target_query( $this->slug, $candidate['target_query'] ) );
		sort( $names );
		return $names;
	}

	public function test_each_group_and_subgroup_among_active_characters_gets_one_rumor(): void {
		$titles = array_column( Rumor_Generator::generate( $this->game_id, '2026-10-01' ), 'title' );
		sort( $titles );

		$this->assertSame( [ 'Anarch', 'Brujah', 'Camarilla', 'Corax', 'Fianna', 'Galliard', 'Haunters' ], $titles, 'no Toreador rumor: only active characters count' );
	}

	public function test_a_group_rumor_reaches_everyone_in_the_group_and_no_one_else(): void {
		$by_title = array_column( Rumor_Generator::generate( $this->game_id, '2026-10-01' ), null, 'title' );

		$this->assertSame( [ 'Brujah One', 'Brujah Two' ], $this->reached( $by_title['Brujah'] ) );
		$this->assertSame( [ 'Corax Kin', 'Galliard' ], $this->reached( $by_title['Galliard'] ), 'an Auspice rumor reaches every type that has one' );
	}

	public function test_generating_again_for_the_same_date_adds_nothing(): void {
		Rumor_Generator::generate( $this->game_id, '2026-10-01', true );

		$this->assertSame( [], Rumor_Generator::generate( $this->game_id, '2026-10-01' ) );
	}
}
