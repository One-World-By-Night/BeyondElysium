<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Character;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Background and Notes are meant to be real rich text (a htmlarea in the editor).
 */
class CharactersControllerRichTextTest extends WP_UnitTestCase {

	private string $game_slug = 'thread-test-richtext-game';

	public function setUp(): void {
		parent::setUp();

		do_action( 'rest_api_init' );

		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'be_games', [
			'slug' => $this->game_slug, 'name' => 'Thread Test Rich Text Game',
			'created_by' => 1, 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ),
		] );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
	}

	public function test_real_formatting_survives_create(): void {
		$request = new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/characters" );
		$request->set_param( 'name', 'Rich Text Test' );
		$request->set_param( 'stack_slug', 'vampire' );
		$request->set_param( 'biography', '<p>Born in <strong>Prague</strong>.</p><ul><li>Exiled</li></ul>' );
		$request->set_param( 'notes', '<em>Handle with care.</em>' );

		$data = rest_get_server()->dispatch( $request )->get_data();

		$this->assertSame( '<p>Born in <strong>Prague</strong>.</p><ul><li>Exiled</li></ul>', $data->biography );
		$this->assertSame( '<em>Handle with care.</em>', $data->notes );
	}

	public function test_script_tags_are_stripped_but_formatting_is_not_on_create(): void {
		$request = new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/characters" );
		$request->set_param( 'name', 'XSS Attempt' );
		$request->set_param( 'stack_slug', 'vampire' );
		$request->set_param( 'biography', '<p>Safe text</p><script>alert(1)</script>' );

		$data = rest_get_server()->dispatch( $request )->get_data();

		$this->assertStringContainsString( '<p>Safe text</p>', $data->biography );
		$this->assertStringNotContainsString( '<script', $data->biography );
	}

	public function test_real_formatting_survives_update(): void {
		$character_id = Character::create( [
			'name' => 'Update Rich Text Test', 'stack_slug' => 'test-stack',
			'owner_type' => 'chronicle', 'owner_slug' => $this->game_slug,
			'wp_user_id' => get_current_user_id(),
		] );

		$request = new WP_REST_Request( 'PUT', "/be/v1/{$this->game_slug}/characters/{$character_id}" );
		$request->set_param( 'biography', '<p>Updated <b>bold</b> background.</p>' );
		$data = rest_get_server()->dispatch( $request )->get_data();

		$this->assertSame( '<p>Updated <b>bold</b> background.</p>', $data->biography );
	}

	public function test_script_tags_are_stripped_on_update(): void {
		$character_id = Character::create( [
			'name' => 'Update XSS Test', 'stack_slug' => 'test-stack',
			'owner_type' => 'chronicle', 'owner_slug' => $this->game_slug,
			'wp_user_id' => get_current_user_id(),
		] );

		$request = new WP_REST_Request( 'PUT', "/be/v1/{$this->game_slug}/characters/{$character_id}" );
		$request->set_param( 'notes', '<img src=x onerror="alert(1)">Gotcha' );
		$data = rest_get_server()->dispatch( $request )->get_data();

		$this->assertStringNotContainsString( 'onerror', $data->notes );
	}
}
