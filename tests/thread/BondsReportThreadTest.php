<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Character;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Game_Member;
use BeyondElysium\Models\Schema_Block;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * The bonds printout: one row per bond, Guanxi included. A Storyteller sees every bond; anyone else sees only the bonds
 * on their own characters, and none from a list the chronicle made Storyteller-only.
 */
class BondsReportThreadTest extends WP_UnitTestCase {

	private string $slug = 'thread-bonds-report';
	private int $game_id;
	private int $hst;
	private int $player;
	private int $other_player;

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );

		$this->game_id      = (int) Game::create( [ 'slug' => $this->slug, 'name' => 'Bonds Report' ] );
		$this->hst          = $this->member( 'hst', 'editor', 'Hilda Storyteller' );
		$this->player       = $this->member( 'player', 'subscriber', 'Pia Player' );
		$this->other_player = $this->member( 'player', 'subscriber', 'Otto Other' );

		$this->character( 'Isolde Marchetti', $this->player, 'vampire', [ 'vampire-bonds' => [
			[ 'name' => 'Gabriel Deveraux', 'count' => 7 ],
			[ 'name' => 'Ana Costa', 'count' => 3 ],
		] ] );
		$this->character( 'Marcus Reyes', $this->other_player, 'vampire', [ 'vampire-bonds' => [ [ 'name' => 'Gabriel Deveraux', 'count' => 2 ] ] ] );
		$this->character( 'Lian Wu', $this->other_player, 'kueijin', [ 'kueijin-guanxi' => [ [ 'name' => 'Master Chen', 'count' => 5 ] ] ] );
		$this->character( 'Sabbat NPC', $this->hst, 'vampire', [ 'vampire-bonds' => [ [ 'name' => 'Gabriel Deveraux', 'count' => 9 ] ] ], [ 'is_npc' => 1 ] );
		$this->character( 'Retired Vampire', $this->player, 'vampire', [ 'vampire-bonds' => [ [ 'name' => 'Old Regnant', 'count' => 1 ] ] ], [ 'status' => 'retired' ] );
		$this->character( 'No Bonds', $this->player, 'vampire', [ 'vampire-merits' => [ [ 'name' => 'Iron Will', 'count' => 1 ] ] ] );
	}

	private function member( string $role, string $wp_role, string $name ): int {
		$user = self::factory()->user->create( [ 'role' => $wp_role, 'display_name' => $name ] );
		Game_Member::set_role( $this->game_id, $user, $role );
		return $user;
	}

	private function character( string $name, int $owner, string $stack, array $sheet, array $extra = [] ): int {
		return (int) Character::create( array_merge( [
			'name' => $name, 'stack_slug' => $stack, 'owner_slug' => $this->slug, 'wp_user_id' => $owner,
			'status' => 'active', 'created_by' => 1, 'sheet_data' => $sheet,
		], $extra ) );
	}

	private function report( int $as, array $query = [] ) {
		wp_set_current_user( $as );
		$request = new WP_REST_Request( 'GET', "/be/v1/{$this->slug}/reports/bonds" );
		$request->set_query_params( $query );
		return rest_get_server()->dispatch( $request );
	}

	/**
	 * @return array<int,string[]>
	 */
	private function rows( $response ): array {
		return $response->get_data()['rows'];
	}

	public function test_a_storyteller_sees_every_active_characters_bonds_and_guanxi_with_their_players(): void {
		$response = $this->report( $this->hst );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 'Bonds', $data['title'] );
		$this->assertSame( [ 'Character', 'Player', 'Bonded to', 'Rating', 'Kind' ], $data['columns'] );
		$this->assertSame(
			[
				[ 'Isolde Marchetti', 'Pia Player', 'Ana Costa', '3', 'Vampire Bonds' ],
				[ 'Isolde Marchetti', 'Pia Player', 'Gabriel Deveraux', '7', 'Vampire Bonds' ],
				[ 'Lian Wu', 'Otto Other', 'Master Chen', '5', 'Guanxi' ],
				[ 'Marcus Reyes', 'Otto Other', 'Gabriel Deveraux', '2', 'Vampire Bonds' ],
				[ 'Sabbat NPC', 'Hilda Storyteller', 'Gabriel Deveraux', '9', 'Vampire Bonds' ],
			],
			$data['rows']
		);
	}

	public function test_a_storyteller_can_order_by_who_they_are_bonded_to(): void {
		$bonded_to = array_column( $this->rows( $this->report( $this->hst, [ 'order' => 'bonded_to' ] ) ), 2 );

		$this->assertSame( [ 'Ana Costa', 'Gabriel Deveraux', 'Gabriel Deveraux', 'Gabriel Deveraux', 'Master Chen' ], $bonded_to );
		$characters = array_column( $this->rows( $this->report( $this->hst, [ 'order' => 'bonded_to' ] ) ), 0 );
		$this->assertSame( [ 'Isolde Marchetti', 'Isolde Marchetti', 'Marcus Reyes', 'Sabbat NPC', 'Lian Wu' ], $characters );
	}

	public function test_a_player_sees_only_the_bonds_on_their_own_characters_and_no_player_column(): void {
		$response = $this->report( $this->player );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( [ 'Character', 'Bonded to', 'Rating', 'Kind' ], $data['columns'] );
		$this->assertSame(
			[
				[ 'Isolde Marchetti', 'Ana Costa', '3', 'Vampire Bonds' ],
				[ 'Isolde Marchetti', 'Gabriel Deveraux', '7', 'Vampire Bonds' ],
			],
			$data['rows']
		);
		$json = (string) wp_json_encode( $data );
		$this->assertStringNotContainsString( 'Marcus Reyes', $json );
		$this->assertStringNotContainsString( 'Old Regnant', $json );
		$this->assertStringNotContainsString( 'Sabbat NPC', $json );
	}

	public function test_a_player_is_always_ordered_by_character_and_sees_only_their_own(): void {
		$rows = $this->rows( $this->report( $this->other_player, [ 'order' => 'bonded_to' ] ) );

		$this->assertSame(
			[ [ 'Lian Wu', 'Master Chen', '5', 'Guanxi' ], [ 'Marcus Reyes', 'Gabriel Deveraux', '2', 'Vampire Bonds' ] ],
			$rows
		);
	}

	public function test_a_list_the_chronicle_made_storyteller_only_gives_a_player_nothing_and_a_storyteller_everything(): void {
		Schema_Block::find_or_create_fork_for_game( 'vampire-bonds', $this->slug );
		Schema_Block::update( 'vampire-bonds', [ 'storyteller_only' => 1 ], $this->slug );

		$this->assertSame( [], $this->rows( $this->report( $this->player ) ) );
		$this->assertCount( 5, $this->rows( $this->report( $this->hst ) ) );
		$this->assertCount( 1, $this->rows( $this->report( $this->other_player ) ), 'the Guanxi list is not Storyteller-only' );
	}

	public function test_a_chronicle_with_no_bonds_gives_an_empty_table_with_its_words(): void {
		$empty = (int) Game::create( [ 'slug' => 'thread-bonds-empty', 'name' => 'No Bonds Here' ] );
		$user  = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		Game_Member::set_role( $empty, $user, 'player' );

		wp_set_current_user( $user );
		$data = rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/be/v1/thread-bonds-empty/reports/bonds' ) )->get_data();

		$this->assertSame( [], $data['rows'] );
		$this->assertSame( 'No bonds are recorded.', $data['note'] );
	}

	public function test_the_printout_is_a_pdf_for_a_player_too(): void {
		wp_set_current_user( $this->player );
		$response = rest_get_server()->dispatch( new WP_REST_Request( 'GET', "/be/v1/{$this->slug}/reports/bonds/pdf" ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertStringStartsWith( '%PDF-', $response->get_data()['bytes'] );
	}

	public function test_someone_outside_the_chronicle_is_refused(): void {
		$outsider = self::factory()->user->create( [ 'role' => 'subscriber' ] );

		$this->assertSame( 403, $this->report( $outsider )->get_status() );
	}

	public function test_a_player_is_offered_the_bonds_report_in_the_list(): void {
		wp_set_current_user( $this->player );
		$list = rest_get_server()->dispatch( new WP_REST_Request( 'GET', "/be/v1/{$this->slug}/reports" ) )->get_data();

		$this->assertContains( 'bonds', array_column( $list, 'key' ) );
	}
}
