<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Character;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Game_Member;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * The joining half.
 */
class JoiningAChronicleThreadTest extends WP_UnitTestCase {

	private string $slug = 'thread-joining';
	private int $game_id;
	private int $hst;
	private array $mail = [];

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );
		$this->game_id = (int) Game::create( [ 'slug' => $this->slug, 'name' => 'Joining by Night' ] );
		$this->hst     = self::factory()->user->create( [ 'role' => 'editor', 'user_email' => 'joining-hst@example.test' ] );
		Game_Member::set_role( $this->game_id, $this->hst, 'hst' );

		$this->mail = [];
		add_filter( 'pre_wp_mail', [ $this, 'capture_mail' ], 10, 2 );
	}

	public function tearDown(): void {
		remove_filter( 'pre_wp_mail', [ $this, 'capture_mail' ], 10 );
		parent::tearDown();
	}

	public function capture_mail( $pre, $atts ) {
		$this->mail[] = $atts;
		return true;
	}

	private function dispatch( string $method, string $route, array $body = [] ) {
		$request = new WP_REST_Request( $method, $route );
		if ( $body ) {
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( wp_json_encode( $body ) );
		}
		return rest_get_server()->dispatch( $request );
	}

	private function start_character( int $as, string $name ) {
		wp_set_current_user( $as );
		return $this->dispatch( 'POST', "/be/v1/{$this->slug}/characters", [ 'name' => $name, 'stack_slug' => 'vampire' ] );
	}

	public function test_a_newcomers_first_character_is_a_join_request_the_storytellers_hear_about(): void {
		$newcomer = self::factory()->user->create( [ 'role' => 'subscriber' ] );

		$response = $this->start_character( $newcomer, 'Hopeful Neonate' );

		$this->assertSame( 201, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$this->assertSame( 'pending', $response->get_data()->status );
		$this->assertTrue( $response->get_data()->join_pending );
		$this->assertNull( Game_Member::find( $this->game_id, $newcomer ), 'not a member until a Storyteller approves' );
		$this->assertSame( 403, $this->dispatch( 'GET', "/be/v1/{$this->slug}/characters" )->get_status() );
		$this->assertSame( [ 'joining-hst@example.test' ], array_column( $this->mail, 'to' ) );
		$this->assertStringContainsString( 'Hopeful Neonate', $this->mail[0]['message'] );
	}

	public function test_one_join_request_at_a_time(): void {
		$newcomer = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		$this->start_character( $newcomer, 'First Try' );

		$second = $this->start_character( $newcomer, 'Second Try' );

		$this->assertSame( 409, $second->get_status() );
		$this->assertSame( 'join_already_requested', $second->as_error()->get_error_code() );
	}

	public function test_a_storyteller_approving_the_character_makes_its_player_a_member(): void {
		$newcomer  = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		$character = (int) $this->start_character( $newcomer, 'Approved Neonate' )->get_data()->id;

		wp_set_current_user( $this->hst );
		$this->assertSame( 200, $this->dispatch( 'PUT', "/be/v1/{$this->slug}/characters/{$character}", [ 'status' => 'active' ] )->get_status() );

		$member = Game_Member::find( $this->game_id, $newcomer );
		$this->assertNotNull( $member );
		$this->assertSame( 'player', $member->role );
		wp_set_current_user( $newcomer );
		$this->assertSame( 200, $this->dispatch( 'GET', "/be/v1/{$this->slug}/characters/{$character}" )->get_status() );
	}

	public function test_an_existing_player_starts_characters_as_before(): void {
		$player = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		Game_Member::set_role( $this->game_id, $player, 'player' );

		$response = $this->start_character( $player, 'Second Character' );

		$this->assertSame( 201, $response->get_status() );
		$this->assertSame( 'active', $response->get_data()->status );
		$this->assertObjectNotHasProperty( 'join_pending', $response->get_data() );
		$this->assertSame( [], $this->mail );
	}

	public function test_a_storyteller_assigning_a_character_to_someone_still_makes_them_a_member_at_once(): void {
		$player    = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		$character = Character::create( [ 'name' => 'Assigned', 'stack_slug' => 'vampire', 'owner_type' => 'chronicle', 'owner_slug' => $this->slug ] );

		wp_set_current_user( $this->hst );
		$this->dispatch( 'PUT', "/be/v1/{$this->slug}/characters/{$character}", [ 'wp_user_id' => $player ] );

		$this->assertSame( 'player', Game_Member::find( $this->game_id, $player )->role ?? null );
	}
}
