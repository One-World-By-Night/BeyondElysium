<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Character;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Game_Member;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * What a player's new character may start with: a Hunter's free Virtue dots stop at the three the book gives, since
 * Virtues are only raised later, with Conviction. A Storyteller sets any rating.
 */
class CreationLimitsThreadTest extends WP_UnitTestCase {

	private string $slug = 'thread-creation-limits';
	private int $game_id;
	private int $player;
	private int $hst;

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );

		$this->game_id = (int) Game::create( [ 'slug' => $this->slug, 'name' => 'Creation Limits' ] );
		$this->player  = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		$this->hst     = self::factory()->user->create( [ 'role' => 'editor' ] );
		Game_Member::set_role( $this->game_id, $this->player, 'player' );
		Game_Member::set_role( $this->game_id, $this->hst, 'hst' );
	}

	/** @return array<string,mixed> */
	private function hunter( int $mercy, int $vision, int $zeal ): array {
		$pool = static fn( int $n ): array => [ 'permanent' => $n, 'temporary' => $n ];
		return [
			'hunter-identity' => [ 'Creed' => 'Martyrdom' ],
			'hunter-virtues'  => [ 'Mercy' => $pool( $mercy ), 'Vision' => $pool( $vision ), 'Zeal' => $pool( $zeal ) ],
		];
	}

	private function create( int $user, array $sheet, array $extra = [] ): \WP_REST_Response {
		wp_set_current_user( $user );
		$request = new WP_REST_Request( 'POST', "/be/v1/{$this->slug}/characters" );
		$request->set_param( 'name', 'Tested Hunter' );
		$request->set_param( 'stack_slug', 'hunter' );
		$request->set_param( 'sheet_data', $sheet );
		foreach ( $extra as $key => $value ) {
			$request->set_param( $key, $value );
		}
		return rest_get_server()->dispatch( $request );
	}

	public function test_a_player_sets_the_three_free_virtue_dots(): void {
		$response = $this->create( $this->player, $this->hunter( 2, 1, 0 ) );

		$this->assertSame( 201, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$sheet = Character::find( $response->get_data()->id )->sheet_data;
		$this->assertSame( 2, (int) $sheet['hunter-virtues']['Mercy']['permanent'] );
		$this->assertSame( 1, (int) $sheet['hunter-virtues']['Vision']['permanent'] );
	}

	public function test_a_player_cannot_set_a_fourth_virtue_dot(): void {
		$response = $this->create( $this->player, $this->hunter( 2, 2, 0 ) );

		$this->assertSame( 400, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 'creation_limit', $data['code'] );
		$this->assertStringContainsString( 'Vision', $data['message'] );
		$this->assertSame( 0, (int) $this->count_characters(), 'a refused build leaves no character behind' );
	}

	public function test_a_storyteller_sets_any_virtue_rating(): void {
		$response = $this->create( $this->hst, $this->hunter( 6, 3, 2 ) );

		$this->assertSame( 201, $response->get_status(), wp_json_encode( $response->get_data() ) );
	}

	public function test_an_existing_character_a_storyteller_enters_keeps_its_ratings(): void {
		$response = $this->create( $this->hst, $this->hunter( 9, 9, 9 ), [ 'existing_character' => true ] );

		$this->assertSame( 201, $response->get_status() );
	}

	public function test_a_player_cannot_claim_to_be_entering_an_existing_character(): void {
		$response = $this->create( $this->player, $this->hunter( 5, 0, 0 ), [ 'existing_character' => true ] );

		$this->assertSame( 400, $response->get_status() );
	}

	public function test_a_virtue_above_the_creeds_primary_still_creates_and_is_only_flagged(): void {
		// Martyrdom's primary Virtue is Mercy; Zeal at 2 outranks Mercy at 1 and is flagged, not refused.
		$response = $this->create( $this->player, $this->hunter( 1, 0, 2 ) );

		$this->assertSame( 201, $response->get_status(), wp_json_encode( $response->get_data() ) );
	}

	private function count_characters(): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}be_characters WHERE owner_slug = %s", $this->slug ) );
	}
}
