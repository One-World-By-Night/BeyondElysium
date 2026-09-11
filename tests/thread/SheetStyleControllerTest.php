<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Character;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Decision 041: one cosmetic override per character - `CharacterSheet.css` stays the
 * real default, this only ever adds a layer on top, gated by `be_customize_sheet`.
 *
 * @see BE_PROCESS/DECISIONLOG.md Decision 041
 */
class SheetStyleControllerTest extends WP_UnitTestCase {

	private string $game_slug = 'thread-test-sheet-style-game';
	private int $character_id;
	private int $st_id;
	private int $player_id;

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );

		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'be_games', [
			'slug' => $this->game_slug, 'name' => 'Thread Test Sheet Style Game',
			'created_by' => 1, 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ),
		] );
		$game_id = (int) $wpdb->insert_id;

		$this->player_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		$this->st_id     = self::factory()->user->create( [ 'role' => 'editor' ] );
		\BeyondElysium\Models\Game_Member::set_role( $game_id, $this->player_id, 'player' );
		\BeyondElysium\Models\Game_Member::set_role( $game_id, $this->st_id, 'hst' );

		$this->character_id = Character::create( [
			'name' => 'Sheet Style Test Character', 'stack_slug' => 'vampire',
			'owner_type' => 'chronicle', 'owner_slug' => $this->game_slug, 'wp_user_id' => $this->player_id,
		] );
	}

	private function dispatch( string $method, array $params = [] ) {
		$request = new WP_REST_Request( $method, "/be/v1/{$this->game_slug}/characters/{$this->character_id}/sheet-style" );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		return rest_get_server()->dispatch( $request );
	}

	public function test_get_with_no_override_returns_an_empty_object_not_a_404(): void {
		wp_set_current_user( $this->player_id );
		$response = $this->dispatch( 'GET' );

		$this->assertSame( 200, $response->get_status() );
		$this->assertEquals( new \stdClass(), $response->get_data() );
	}

	public function test_a_player_cannot_set_a_style_even_for_their_own_character(): void {
		wp_set_current_user( $this->player_id );
		$response = $this->dispatch( 'PUT', [ 'font_family' => 'Georgia, serif' ] );

		$this->assertSame( 403, $response->get_status(), 'be_customize_sheet is ST-level only right now, not owner-level.' );
	}

	public function test_an_st_can_set_and_read_back_a_style(): void {
		wp_set_current_user( $this->st_id );
		$response = $this->dispatch( 'PUT', [
			'font_family'      => 'Georgia, serif',
			'accent_color'     => '#8b0000',
			'background_color' => '#1a1a1a',
		] );
		$data = $response->get_data();

		$this->assertSame( 200, $response->get_status(), wp_json_encode( $data ) );
		$this->assertSame( 'Georgia, serif', $data->font_family );
		$this->assertSame( '#8b0000', $data->accent_color );

		// A player can still READ the style, once it exists - they need it to render.
		wp_set_current_user( $this->player_id );
		$get = $this->dispatch( 'GET' )->get_data();
		$this->assertSame( '#8b0000', $get->accent_color );
	}

	public function test_saving_twice_replaces_not_duplicates(): void {
		wp_set_current_user( $this->st_id );
		$this->dispatch( 'PUT', [ 'font_family' => 'Georgia, serif' ] );
		$this->dispatch( 'PUT', [ 'font_family' => 'Arial, sans-serif' ] );

		global $wpdb;
		$count = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->prefix}be_character_sheet_styles WHERE character_id = %d",
			$this->character_id
		) );
		$this->assertSame( 1, $count, 'One row per character (uq_character), a second save must update it, not insert again.' );

		$data = $this->dispatch( 'GET' )->get_data();
		$this->assertSame( 'Arial, sans-serif', $data->font_family );
	}

	public function test_an_unlisted_font_is_rejected(): void {
		wp_set_current_user( $this->st_id );
		$response = $this->dispatch( 'PUT', [ 'font_family' => 'Comic Sans MS, cursive' ] );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'invalid_param', $response->get_data()['code'] );
	}

	public function test_a_non_hex_color_is_rejected(): void {
		wp_set_current_user( $this->st_id );
		$response = $this->dispatch( 'PUT', [
			'font_family'  => '',
			'accent_color' => 'red; } body { display:none} /*',
		] );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'invalid_param', $response->get_data()['code'] );
	}

	public function test_a_non_attachment_background_image_id_is_rejected(): void {
		wp_set_current_user( $this->st_id );
		// A real post, but not an attachment.
		$post_id = self::factory()->post->create( [ 'post_type' => 'post' ] );

		$response = $this->dispatch( 'PUT', [
			'font_family'         => '',
			'background_image_id' => $post_id,
		] );

		$this->assertSame( 400, $response->get_status() );
	}

	public function test_delete_reverts_to_the_default_not_a_blank_row(): void {
		wp_set_current_user( $this->st_id );
		$this->dispatch( 'PUT', [ 'font_family' => 'Georgia, serif' ] );

		$response = $this->dispatch( 'DELETE' );
		$this->assertSame( 204, $response->get_status() );

		global $wpdb;
		$count = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->prefix}be_character_sheet_styles WHERE character_id = %d",
			$this->character_id
		) );
		$this->assertSame( 0, $count );

		$data = $this->dispatch( 'GET' )->get_data();
		$this->assertEquals( new \stdClass(), $data );
	}
}
