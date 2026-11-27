<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Character;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Game_Member;
use BeyondElysium\Models\Notice;
use BeyondElysium\Services\Bylaws;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * The picks a chronicle's own rules hand to a Storyteller reach a new character: the build reports them, and a member's
 * character that holds one starts pending even where new characters otherwise start active.
 */
class CreationApprovalThreadTest extends WP_UnitTestCase {

	private string $slug = 'thread-creation-approval';
	private int $game_id;
	private int $hst;
	private int $ast;
	private int $player;
	private int $outsider;

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );

		$this->game_id = (int) Game::create( [ 'slug' => $this->slug, 'name' => 'Approved by Night', 'settings' => [ 'starting_xp' => 10 ] ] );
		$this->hst     = $this->person( 'hst', 'editor' );
		$this->ast     = $this->person( 'ast', 'editor' );
		$this->player  = $this->person( 'player', 'subscriber' );
		$this->outsider = self::factory()->user->create( [ 'role' => 'subscriber' ] );
	}

	private function person( string $role, string $wp_role ): int {
		$user = self::factory()->user->create( [ 'role' => $wp_role ] );
		Game_Member::set_role( $this->game_id, $user, $role );
		return $user;
	}

	private function send( int $as, string $method, string $route, array $body = [] ): \WP_REST_Response {
		wp_set_current_user( $as );
		$request = new WP_REST_Request( $method, "/be/v1/{$this->slug}/{$route}" );
		$request->set_header( 'Content-Type', 'application/json' );
		if ( $method === 'GET' ) {
			$request->set_query_params( $body );
		} else {
			$request->set_body( (string) wp_json_encode( $body ) );
		}
		return rest_get_server()->dispatch( $request );
	}

	private function rule( array $body ): void {
		$response = $this->send( $this->hst, 'POST', 'approval-rules', $body );
		$this->assertSame( 201, $response->get_status(), wp_json_encode( $response->get_data() ) );
	}

	/**
	 * @param array<string,mixed> $extra
	 * @return array<string,mixed>
	 */
	private function sheet( array $extra = [] ): array {
		return $extra + [ 'vampire-identity' => [ 'Clan' => 'Tremere' ], 'vampire-abilities' => [ [ 'name' => 'Occult' ] ] ];
	}

	private function tally( int $as, array $sheet ): array {
		$response = $this->send( $as, 'POST', 'creation-tally', [ 'stack_slug' => 'vampire', 'sheet_data' => $sheet ] );
		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		return $response->get_data();
	}

	private function create( int $as, array $sheet, array $extra = [] ): \WP_REST_Response {
		return $this->send( $as, 'POST', 'characters', $extra + [ 'name' => 'Isolde', 'stack_slug' => 'vampire', 'sheet_data' => $sheet ] );
	}

	private function occult_needs_a_storyteller(): void {
		$this->rule( [
			'block_slug' => 'vampire-abilities', 'target_type' => 'item', 'target_name' => 'Occult',
			'approval' => 'st', 'reason' => 'The chronicle reviews Occult.',
		] );
	}

	public function test_an_item_rule_makes_a_players_new_character_pending_with_the_reason(): void {
		$this->occult_needs_a_storyteller();

		$tally = $this->tally( $this->player, $this->sheet() );
		$this->assertCount( 1, $tally['needs_storyteller'] );
		$this->assertSame( 'Occult', $tally['needs_storyteller'][0]['name'] );
		$this->assertSame( 'vampire-abilities', $tally['needs_storyteller'][0]['section'] );
		$this->assertNotSame( '', $tally['needs_storyteller'][0]['section_label'] );
		$this->assertSame( 'The chronicle reviews Occult.', $tally['needs_storyteller'][0]['reason'] );
		$this->assertSame( [ 'status' => 'pending', 'reasons' => [ 'rules' ] ], $tally['starts'] );

		$response = $this->create( $this->player, $this->sheet() );
		$this->assertSame( 201, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$this->assertSame( 'pending', Character::find( (int) $response->get_data()->id )->status );
	}

	public function test_the_chronicles_default_alone_does_not_make_a_character_pending(): void {
		$tally = $this->tally( $this->player, $this->sheet() );

		$this->assertSame( [], $tally['needs_storyteller'] );
		$this->assertSame( [ 'status' => 'active', 'reasons' => [] ], $tally['starts'] );
		$response = $this->create( $this->player, $this->sheet() );
		$this->assertSame( 'active', Character::find( (int) $response->get_data()->id )->status );
	}

	public function test_an_auto_approve_chronicle_with_no_rule_gives_an_active_character(): void {
		Game::update( $this->slug, [ 'settings' => [ 'starting_xp' => 10, 'auto_approve' => true ] ] );

		$response = $this->create( $this->player, $this->sheet() );

		$this->assertSame( 'active', Character::find( (int) $response->get_data()->id )->status );
	}

	public function test_an_auto_approve_chronicle_with_an_item_rule_still_waits(): void {
		Game::update( $this->slug, [ 'settings' => [ 'starting_xp' => 10, 'auto_approve' => true ] ] );
		$this->occult_needs_a_storyteller();

		$response = $this->create( $this->player, $this->sheet() );

		$this->assertSame( 'pending', Character::find( (int) $response->get_data()->id )->status );
	}

	public function test_the_chronicles_new_character_switch_is_a_reason_of_its_own(): void {
		Game::update( $this->slug, [ 'settings' => [ 'starting_xp' => 10, 'require_new_character_approval' => true ] ] );

		$tally = $this->tally( $this->player, $this->sheet() );

		$this->assertSame( [ 'status' => 'pending', 'reasons' => [ 'chronicle' ] ], $tally['starts'] );

		$this->occult_needs_a_storyteller();
		$this->assertSame( [ 'status' => 'pending', 'reasons' => [ 'chronicle', 'rules' ] ], $this->tally( $this->player, $this->sheet() )['starts'] );
	}

	public function test_a_bylaw_cited_pick_waits_and_cites_the_clause(): void {
		Bylaws::upload( [
			'rules'       => [ [ 'clause_id' => 1, 'path' => '10.e.v', 'subject' => 'True Faith', 'pc' => 'Coordinator Notify', 'npc' => 'Unregulated', 'coordinators' => [ 'Hunter' ] ] ],
			'attachments' => [ [ 'clause_id' => 1, 'family' => 'merits', 'name' => 'True Faith' ] ],
		] );
		Game::update( $this->slug, [ 'settings' => [ 'starting_xp' => 10, 'owbn_bylaws' => true ] ] );
		$sheet = $this->sheet( [ 'vampire-merits' => [ [ 'name' => 'True Faith', 'count' => 1 ] ] ] );

		$tally = $this->tally( $this->player, $sheet );

		$this->assertCount( 1, $tally['needs_storyteller'] );
		$this->assertSame( 'True Faith', $tally['needs_storyteller'][0]['name'] );
		$this->assertStringContainsString( '10.e.v', (string) $tally['needs_storyteller'][0]['reason'] );
	}

	public function test_a_custom_entry_waits(): void {
		$sheet = $this->sheet( [ 'vampire-abilities' => [ [ 'name' => 'Parkour', 'custom' => true ] ] ] );

		$tally = $this->tally( $this->player, $sheet );

		$this->assertCount( 1, $tally['needs_storyteller'] );
		$this->assertSame( 'Parkour', $tally['needs_storyteller'][0]['name'] );
		$this->assertNotEmpty( $tally['needs_storyteller'][0]['reason'] );
	}

	public function test_an_out_of_clan_discipline_waits_under_an_out_of_type_rule_and_an_in_clan_one_does_not(): void {
		$this->rule( [ 'block_slug' => 'vampire-disciplines', 'target_type' => 'block_in_type', 'in_type' => 'auto', 'out_of_type' => 'st' ] );

		$in_clan  = $this->sheet( [ 'vampire-disciplines' => [ [ 'name' => 'Auspex', 'level' => 1 ] ] ] );
		$out_clan = $this->sheet( [ 'vampire-disciplines' => [ [ 'name' => 'Celerity', 'level' => 1 ] ] ] );

		$this->assertSame( [], $this->tally( $this->player, $in_clan )['needs_storyteller'], 'Auspex is a Tremere Discipline.' );
		$waiting = $this->tally( $this->player, $out_clan )['needs_storyteller'];
		$this->assertCount( 1, $waiting );
		$this->assertStringStartsWith( 'Celerity', $waiting[0]['name'] );
	}

	public function test_a_storytellers_own_create_is_never_forced_pending(): void {
		$this->occult_needs_a_storyteller();

		$response = $this->create( $this->hst, $this->sheet(), [ 'status' => 'active' ] );

		$this->assertSame( 201, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$this->assertSame( 'active', Character::find( (int) $response->get_data()->id )->status );
		$this->assertSame( 'active', $this->tally( $this->hst, $this->sheet() )['starts']['status'] );
	}

	public function test_a_join_stays_a_join(): void {
		$this->occult_needs_a_storyteller();

		$tally = $this->tally( $this->outsider, $this->sheet() );
		$this->assertSame( [ 'status' => 'pending', 'reasons' => [ 'join', 'rules' ] ], $tally['starts'] );

		$response = $this->create( $this->outsider, $this->sheet() );
		$this->assertSame( 201, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$this->assertTrue( (bool) $response->get_data()->join_pending );
		$this->assertCount( 0, Notice::of_kind( $this->game_id, $this->hst, 'character_waiting' ), 'A joiner is told through the join request.' );
	}

	public function test_each_hst_and_ast_hears_once_of_a_members_waiting_character(): void {
		$this->occult_needs_a_storyteller();

		$response = $this->create( $this->player, $this->sheet() );
		$this->assertSame( 201, $response->get_status() );

		foreach ( [ $this->hst, $this->ast ] as $person ) {
			$notices = Notice::of_kind( $this->game_id, $person, 'character_waiting' );
			$this->assertCount( 1, $notices );
			$payload = $notices[0]->payload;
			$this->assertSame( 'Isolde', $payload['character_name'] );
			$this->assertSame( 1, $payload['picks'] );
		}
		$this->assertCount( 0, Notice::of_kind( $this->game_id, $this->player, 'character_waiting' ), 'The player is not told.' );
	}

	public function test_an_active_character_tells_nobody(): void {
		$this->create( $this->player, $this->sheet() );

		$this->assertCount( 0, Notice::of_kind( $this->game_id, $this->hst, 'character_waiting' ) );
	}

	public function test_the_storyteller_home_counts_the_new_character(): void {
		$this->occult_needs_a_storyteller();
		$this->create( $this->player, $this->sheet() );

		$home = $this->send( $this->hst, 'GET', 'home/storyteller' )->get_data();

		$this->assertSame( 1, $home['waiting']['approvals']['new_characters'] );
	}

	public function test_a_joiners_waiting_character_is_not_counted_as_a_new_character(): void {
		$this->create( $this->outsider, $this->sheet() );

		$home = $this->send( $this->hst, 'GET', 'home/storyteller' )->get_data();

		$this->assertSame( 0, $home['waiting']['approvals']['new_characters'] );
	}

	public function test_a_pending_characters_tally_lists_the_same_picks_read_from_the_saved_sheet(): void {
		$this->occult_needs_a_storyteller();
		$id = (int) $this->create( $this->player, $this->sheet() )->get_data()->id;

		$response = $this->send( $this->hst, 'GET', "characters/{$id}/creation-tally" );

		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$this->assertSame( 'Occult', $response->get_data()['needs_storyteller'][0]['name'] );
		$this->assertSame( 'The chronicle reviews Occult.', $response->get_data()['needs_storyteller'][0]['reason'] );
	}
}
