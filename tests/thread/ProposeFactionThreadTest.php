<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Change;
use BeyondElysium\Models\Character;
use BeyondElysium\Models\Faction;
use BeyondElysium\Models\Faction_Member;
use BeyondElysium\Models\Game_Member;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * A player proposes a coterie/pack/cabal/motley for their own character.
 */
class ProposeFactionThreadTest extends WP_UnitTestCase {

	private string $game_slug = 'thread-propose-faction';
	private int $game_id;
	private int $player;
	private int $character_id;

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );

		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'be_games', [
			'slug' => $this->game_slug, 'name' => 'Thread Propose Faction', 'created_by' => 1,
			'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ),
			'settings' => wp_json_encode( [] ),
		] );
		$this->game_id = (int) $wpdb->insert_id;

		$this->player = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		Game_Member::set_role( $this->game_id, $this->player, 'player' );

		$this->character_id = (int) Character::create( [
			'owner_slug' => $this->game_slug, 'name' => 'The Founder', 'stack_slug' => 'vampire',
			'wp_user_id' => $this->player, 'created_by' => $this->player,
		] );
	}

	private function propose( array $data = [] ) {
		wp_set_current_user( $this->player );
		$request = new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/characters/{$this->character_id}/changes" );
		$request->set_body_params( array_merge( [
			'change_type' => 'propose_faction',
			'category'    => 'faction',
			'change_data' => [
				'faction_type' => 'coterie',
				'name'         => 'Coterie of Thorns',
				'goals'        => 'Survive the week.',
			],
		], $data ) );
		return rest_get_server()->dispatch( $request );
	}

	private function reviewer( string $role ): int {
		$user = self::factory()->user->create( [ 'role' => 'editor' ] );
		Game_Member::set_role( $this->game_id, $user, $role );
		return $user;
	}

	private function approve( int $user_id, int $change_id ) {
		wp_set_current_user( $user_id );
		$request = new WP_REST_Request( 'PUT', "/be/v1/{$this->game_slug}/changes/{$change_id}" );
		$request->set_body_params( [ 'status' => 'approved' ] );
		return rest_get_server()->dispatch( $request );
	}

	public function test_a_player_can_propose_a_faction_for_their_own_character(): void {
		$response = $this->propose();

		$this->assertSame( 201, $response->get_status() );
		$change = (array) $response->get_data();
		$this->assertSame( 'propose_faction', $change['change_type'] );
		$this->assertSame( 'pending', $change['status'] );
	}

	public function test_a_disallowed_faction_type_is_refused_on_the_way_in(): void {
		$response = $this->propose( [ 'change_data' => [ 'faction_type' => 'sect', 'name' => 'The Camarilla' ] ] );

		// 'sect' is real, but Storyteller-only (Faction::PLAYER_PROPOSABLE_TYPES).
		$this->assertSame( 400, $response->get_status() );
	}

	public function test_a_proposal_with_no_name_is_refused(): void {
		$response = $this->propose( [ 'change_data' => [ 'faction_type' => 'coterie', 'name' => '   ' ] ] );

		$this->assertSame( 400, $response->get_status() );
	}

	public function test_approving_creates_the_faction_with_the_proposer_as_its_leader(): void {
		$change_id = (int) ( (array) $this->propose()->get_data() )['id'];

		$hst = $this->reviewer( 'hst' );
		$this->assertSame( 200, $this->approve( $hst, $change_id )->get_status() );

		$factions = Faction::for_game( $this->game_id );
		$this->assertCount( 1, $factions );
		$this->assertSame( 'Coterie of Thorns', $factions[0]->name );
		$this->assertTrue( $factions[0]->created_via_proposal );

		$member = Faction_Member::find_for( (int) $factions[0]->id, $this->character_id );
		$this->assertNotNull( $member );
		$this->assertTrue( $member->is_leader );
	}

	/**
	 * A reviewer without faction rights cannot approve a faction proposal.
	 */
	public function test_a_reviewer_without_faction_rights_cannot_approve_it(): void {
		$change_id = (int) ( (array) $this->propose()->get_data() )['id'];

		$reviewer = self::factory()->user->create( [ 'role' => 'editor' ] );
		Game_Member::set_role( $this->game_id, $reviewer, 'hst' );

		$user = new \WP_User( $reviewer );
		$user->add_cap( 'be_manage_factions', false );

		$response = $this->approve( $reviewer, $change_id );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'faction_capability_denied', $response->get_data()['code'] );
		$this->assertSame( 'pending', (string) Change::find( $change_id )->status );
		$this->assertCount( 0, Faction::for_game( $this->game_id ) );
	}

	public function test_rejecting_creates_no_faction(): void {
		$change_id = (int) ( (array) $this->propose()->get_data() )['id'];

		$hst = $this->reviewer( 'hst' );
		wp_set_current_user( $hst );
		$request = new WP_REST_Request( 'PUT', "/be/v1/{$this->game_slug}/changes/{$change_id}" );
		$request->set_body_params( [ 'status' => 'rejected', 'notes' => 'Not this week.' ] );
		rest_get_server()->dispatch( $request );

		$this->assertCount( 0, Faction::for_game( $this->game_id ) );
	}

	public function test_auto_approve_chronicle_still_requires_manual_review(): void {
		global $wpdb;
		$wpdb->update( $wpdb->prefix . 'be_games', [ 'settings' => wp_json_encode( [ 'auto_approve' => true ] ) ], [ 'id' => $this->game_id ] );

		$response = $this->propose();

		$this->assertSame( 201, $response->get_status() );
		$this->assertSame( 'pending', ( (array) $response->get_data() )['status'] );
		$this->assertCount( 0, Faction::for_game( $this->game_id ) );
	}
}
