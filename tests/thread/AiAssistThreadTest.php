<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Game;
use BeyondElysium\Models\Game_Member;
use BeyondElysium\Services\Ai_Assist;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Covers the AI writing-assist tool (ai-writing-assist-design.md): key
 * encryption/resolution precedence (chronicle override, else site-wide,
 * else not configured), that a stored key is never echoed back by any
 * route, the field_context -> capability map's real enforcement (an
 * unrelated capability, or a site-wide/chronicle-scoped route mismatch,
 * must not reach generation), and generate()'s own provider dispatch and
 * failure handling via pre_http_request mocking - no real API key or
 * network call needed.
 */
class AiAssistThreadTest extends WP_UnitTestCase {

	private string $game_slug = 'thread-test-ai-assist';
	private int $game_id;
	private int $admin_id;
	private int $hst_id;
	private int $narrator_id;
	private int $subscriber_id;

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );

		delete_option( Ai_Assist::SITE_OPENAI_KEY_OPTION );
		delete_option( Ai_Assist::SITE_CLAUDE_KEY_OPTION );
		delete_option( Ai_Assist::SITE_PROVIDER_OPTION );

		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'be_games', [
			'slug' => $this->game_slug, 'name' => 'AI Assist Test Game',
			'created_by' => 1, 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ),
			'settings' => wp_json_encode( [] ),
		] );
		$this->game_id = (int) $wpdb->insert_id;

		$this->admin_id      = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$this->hst_id        = self::factory()->user->create( [ 'role' => 'editor' ] );
		$this->narrator_id   = self::factory()->user->create( [ 'role' => 'editor' ] );
		$this->subscriber_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );

		Game_Member::set_role( $this->game_id, $this->hst_id, 'hst' );
		Game_Member::set_role( $this->game_id, $this->narrator_id, 'narrator' );
	}

	private function dispatch( WP_REST_Request $request ) {
		return rest_get_server()->dispatch( $request );
	}

	// --- key resolution ---

	public function test_resolve_key_returns_null_when_nothing_is_configured_anywhere(): void {
		$this->assertNull( Ai_Assist::resolve_key( null ) );
		$this->assertNull( Ai_Assist::resolve_key( $this->game_slug ) );
	}

	public function test_site_wide_call_uses_the_site_key_regardless_of_any_chronicle(): void {
		update_option( Ai_Assist::SITE_OPENAI_KEY_OPTION, Ai_Assist::encrypt( 'sk-site-openai' ) );

		$resolved = Ai_Assist::resolve_key( null );
		$this->assertSame( [ 'provider' => 'openai', 'key' => 'sk-site-openai', 'scope' => 'site', 'base_url' => '', 'model' => '' ], $resolved );
	}

	public function test_a_chronicle_that_has_not_opted_in_gets_nothing_even_with_a_site_key(): void {
		update_option( Ai_Assist::SITE_OPENAI_KEY_OPTION, Ai_Assist::encrypt( 'sk-site-openai' ) );
		$this->assertNull( Ai_Assist::resolve_key( $this->game_slug ), 'ai_assist_enabled was never set for this chronicle' );
	}

	public function test_an_opted_in_chronicle_falls_back_to_the_site_key_with_no_key_of_its_own(): void {
		update_option( Ai_Assist::SITE_OPENAI_KEY_OPTION, Ai_Assist::encrypt( 'sk-site-openai' ) );
		Game::update( $this->game_slug, [ 'settings' => [ 'ai_assist_enabled' => true ] ] );

		$resolved = Ai_Assist::resolve_key( $this->game_slug );
		$this->assertSame( [ 'provider' => 'openai', 'key' => 'sk-site-openai', 'scope' => 'site', 'base_url' => '', 'model' => '' ], $resolved );
	}

	public function test_a_chronicles_own_key_wins_over_the_site_key(): void {
		update_option( Ai_Assist::SITE_OPENAI_KEY_OPTION, Ai_Assist::encrypt( 'sk-site-openai' ) );
		Game::update( $this->game_slug, [ 'settings' => [
			'ai_assist_enabled' => true,
			'ai_openai_key'     => Ai_Assist::encrypt( 'sk-chronicle-own' ),
		] ] );

		$resolved = Ai_Assist::resolve_key( $this->game_slug );
		$this->assertSame( [ 'provider' => 'openai', 'key' => 'sk-chronicle-own', 'base_url' => '', 'model' => '', 'scope' => 'chronicle' ], $resolved );
	}

	public function test_a_chronicle_can_choose_claude_independent_of_the_site_default(): void {
		update_option( Ai_Assist::SITE_PROVIDER_OPTION, 'openai' );
		Game::update( $this->game_slug, [ 'settings' => [
			'ai_assist_enabled' => true,
			'ai_provider'       => 'claude',
			'ai_claude_key'     => Ai_Assist::encrypt( 'sk-ant-chronicle' ),
		] ] );

		$resolved = Ai_Assist::resolve_key( $this->game_slug );
		$this->assertSame( [ 'provider' => 'claude', 'key' => 'sk-ant-chronicle', 'base_url' => '', 'model' => '', 'scope' => 'chronicle' ], $resolved );
	}

	public function test_site_wide_call_picks_up_the_site_wide_base_url_and_model_override(): void {
		update_option( Ai_Assist::SITE_OPENAI_KEY_OPTION, Ai_Assist::encrypt( 'sk-site-openai' ) );
		update_option( Ai_Assist::SITE_OPENAI_BASE_URL_OPTION, 'http://localhost:11434/v1/chat/completions' );
		update_option( Ai_Assist::SITE_OPENAI_MODEL_OPTION, 'llama3' );

		$resolved = Ai_Assist::resolve_key( null );
		$this->assertSame( 'http://localhost:11434/v1/chat/completions', $resolved['base_url'] );
		$this->assertSame( 'llama3', $resolved['model'] );
	}

	public function test_a_chronicle_using_its_own_key_uses_its_own_base_url_and_model_not_the_sites(): void {
		update_option( Ai_Assist::SITE_OPENAI_KEY_OPTION, Ai_Assist::encrypt( 'sk-site-openai' ) );
		update_option( Ai_Assist::SITE_OPENAI_BASE_URL_OPTION, 'http://site-wide-should-not-appear' );
		Game::update( $this->game_slug, [ 'settings' => [
			'ai_assist_enabled'    => true,
			'ai_openai_key'        => Ai_Assist::encrypt( 'sk-chronicle-own' ),
			'ai_openai_base_url'   => 'http://chronicle-own-endpoint',
			'ai_openai_model'      => 'chronicle-model',
		] ] );

		$resolved = Ai_Assist::resolve_key( $this->game_slug );
		$this->assertSame( 'http://chronicle-own-endpoint', $resolved['base_url'] );
		$this->assertSame( 'chronicle-model', $resolved['model'] );
	}

	public function test_a_chronicle_falling_back_to_the_site_key_also_gets_the_site_wide_endpoint_override(): void {
		update_option( Ai_Assist::SITE_OPENAI_KEY_OPTION, Ai_Assist::encrypt( 'sk-site-openai' ) );
		update_option( Ai_Assist::SITE_OPENAI_BASE_URL_OPTION, 'http://site-wide-endpoint' );
		update_option( Ai_Assist::SITE_OPENAI_MODEL_OPTION, 'site-model' );
		Game::update( $this->game_slug, [ 'settings' => [ 'ai_assist_enabled' => true ] ] );

		$resolved = Ai_Assist::resolve_key( $this->game_slug );
		$this->assertSame( 'sk-site-openai', $resolved['key'], 'no chronicle key of its own - falls back to the site key' );
		$this->assertSame( 'http://site-wide-endpoint', $resolved['base_url'], 'and to the matching site-wide endpoint, never left blank or mismatched' );
		$this->assertSame( 'site-model', $resolved['model'] );
	}

	// --- settings write/read helpers ---

	public function test_merge_settings_write_encrypts_a_real_value(): void {
		$merged = Ai_Assist::merge_settings_write( [ 'ai_openai_key' => 'sk-plain' ], [] );
		$this->assertNotSame( 'sk-plain', $merged['ai_openai_key'], 'a real key must never be stored in plaintext' );
	}

	public function test_merge_settings_write_clears_on_empty_string_rather_than_storing_an_encrypted_empty(): void {
		$existing = [ 'ai_openai_key' => Ai_Assist::encrypt( 'sk-old' ), 'unrelated' => 'kept' ];
		$merged   = Ai_Assist::merge_settings_write( [ 'ai_openai_key' => '' ], $existing );

		$this->assertArrayNotHasKey( 'ai_openai_key', $merged );
		$this->assertSame( 'kept', $merged['unrelated'], 'clearing a key must not disturb an unrelated setting' );
	}

	public function test_merge_settings_write_leaves_untouched_fields_alone(): void {
		$existing = [ 'apr' => [ 'personal_actions' => 3 ] ];
		$merged   = Ai_Assist::merge_settings_write( [ 'ai_assist_enabled' => true ], $existing );

		$this->assertSame( [ 'personal_actions' => 3 ], $merged['apr'] );
		$this->assertTrue( $merged['ai_assist_enabled'] );
	}

	public function test_redact_settings_read_strips_the_key_and_adds_a_boolean(): void {
		$settings = (object) [ 'ai_openai_key' => 'anything-at-all', 'unrelated' => 'kept' ];
		Ai_Assist::redact_settings_read( $settings );

		$this->assertObjectNotHasProperty( 'ai_openai_key', $settings );
		$this->assertTrue( $settings->has_own_openai_key );
		$this->assertFalse( $settings->has_own_claude_key );
		$this->assertSame( 'kept', $settings->unrelated );
	}

	// --- generate(): provider dispatch and failure modes ---

	private function mock_openai_success( string $suggestion ): void {
		add_filter( 'pre_http_request', function ( $preempt, $args, $url ) use ( $suggestion ) {
			if ( strpos( $url, 'api.openai.com' ) === false ) {
				return $preempt;
			}
			return [
				'response' => [ 'code' => 200, 'message' => '' ],
				'body'     => wp_json_encode( [ 'choices' => [ [ 'message' => [ 'content' => $suggestion ] ] ] ] ),
				'headers'  => [], 'cookies' => [], 'filename' => null,
			];
		}, 10, 3 );
	}

	private function mock_claude_success( string $suggestion ): void {
		add_filter( 'pre_http_request', function ( $preempt, $args, $url ) use ( $suggestion ) {
			if ( strpos( $url, 'api.anthropic.com' ) === false ) {
				return $preempt;
			}
			return [
				'response' => [ 'code' => 200, 'message' => '' ],
				'body'     => wp_json_encode( [ 'content' => [ [ 'type' => 'text', 'text' => $suggestion ] ] ] ),
				'headers'  => [], 'cookies' => [], 'filename' => null,
			];
		}, 10, 3 );
	}

	public function test_generate_returns_ai_not_configured_with_no_key_anywhere(): void {
		$result = Ai_Assist::generate( 'character_biography', '', 'a stoic Brujah', null );
		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'ai_not_configured', $result['code'] );
	}

	public function test_generate_rejects_an_unknown_field_context(): void {
		update_option( Ai_Assist::SITE_OPENAI_KEY_OPTION, Ai_Assist::encrypt( 'sk-site' ) );
		$result = Ai_Assist::generate( 'not_a_real_field', '', 'x', null );
		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'unknown_field_context', $result['code'] );
	}

	public function test_generate_extracts_the_openai_suggestion(): void {
		update_option( Ai_Assist::SITE_OPENAI_KEY_OPTION, Ai_Assist::encrypt( 'sk-site' ) );
		$this->mock_openai_success( 'A stoic Brujah who lost everything in the Anarch Revolt.' );

		$result = Ai_Assist::generate( 'character_biography', '', 'a stoic Brujah', null );
		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'A stoic Brujah who lost everything in the Anarch Revolt.', $result['suggestion'] );
	}

	public function test_generate_extracts_the_claude_suggestion(): void {
		update_option( Ai_Assist::SITE_PROVIDER_OPTION, 'claude' );
		update_option( Ai_Assist::SITE_CLAUDE_KEY_OPTION, Ai_Assist::encrypt( 'sk-ant-site' ) );
		$this->mock_claude_success( 'A weary Gangrel drifting between cities.' );

		$result = Ai_Assist::generate( 'character_biography', '', 'a weary Gangrel', null );
		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'A weary Gangrel drifting between cities.', $result['suggestion'] );
	}

	public function test_generate_surfaces_a_provider_error_on_a_non_2xx_response(): void {
		update_option( Ai_Assist::SITE_OPENAI_KEY_OPTION, Ai_Assist::encrypt( 'sk-bad' ) );
		add_filter( 'pre_http_request', function ( $preempt, $args, $url ) {
			if ( strpos( $url, 'api.openai.com' ) === false ) {
				return $preempt;
			}
			return [
				'response' => [ 'code' => 401, 'message' => 'Unauthorized' ],
				'body'     => wp_json_encode( [ 'error' => [ 'message' => 'Invalid API key' ] ] ),
				'headers'  => [], 'cookies' => [], 'filename' => null,
			];
		}, 10, 3 );

		$result = Ai_Assist::generate( 'character_biography', '', 'x', null );
		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'ai_provider_error', $result['code'] );
	}

	public function test_generate_polishes_existing_text_rather_than_drafting_from_the_instruction(): void {
		update_option( Ai_Assist::SITE_OPENAI_KEY_OPTION, Ai_Assist::encrypt( 'sk-site' ) );
		$captured_body = null;
		add_filter( 'pre_http_request', function ( $preempt, $args, $url ) use ( &$captured_body ) {
			if ( strpos( $url, 'api.openai.com' ) === false ) {
				return $preempt;
			}
			$captured_body = json_decode( (string) $args['body'], true );
			return [
				'response' => [ 'code' => 200, 'message' => '' ],
				'body'     => wp_json_encode( [ 'choices' => [ [ 'message' => [ 'content' => 'Polished.' ] ] ] ] ),
				'headers'  => [], 'cookies' => [], 'filename' => null,
			];
		}, 10, 3 );

		Ai_Assist::generate( 'character_biography', 'Existing rough draft text.', 'ignored when text exists', null );

		$user_message = $captured_body['messages'][1]['content'] ?? '';
		$this->assertStringContainsString( 'Existing rough draft text.', $user_message );
		$this->assertStringNotContainsString( 'ignored when text exists', $user_message );
	}

	// --- test_connection(): tests a draft value directly, never resolve_key() ---

	public function test_connection_rejects_a_blank_key_without_ever_making_a_request(): void {
		$called = false;
		add_filter( 'pre_http_request', function ( $preempt, $args, $url ) use ( &$called ) {
			$called = true;
			return $preempt;
		}, 10, 3 );

		$result = Ai_Assist::test_connection( 'openai', '', '', '' );
		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'ai_not_configured', $result['code'] );
		$this->assertFalse( $called, 'a blank key must never reach the network' );
	}

	public function test_connection_succeeds_against_a_mocked_openai_response(): void {
		$this->mock_openai_success( 'OK' );
		$result = Ai_Assist::test_connection( 'openai', 'sk-typed-not-saved', '', '' );
		$this->assertTrue( $result['ok'] );
	}

	public function test_connection_succeeds_against_a_mocked_claude_response(): void {
		$this->mock_claude_success( 'OK' );
		$result = Ai_Assist::test_connection( 'claude', 'sk-ant-typed-not-saved', '', '' );
		$this->assertTrue( $result['ok'] );
	}

	public function test_connection_surfaces_a_provider_error_on_a_bad_key(): void {
		add_filter( 'pre_http_request', function ( $preempt, $args, $url ) {
			if ( strpos( $url, 'api.openai.com' ) === false ) {
				return $preempt;
			}
			return [
				'response' => [ 'code' => 401, 'message' => 'Unauthorized' ],
				'body'     => wp_json_encode( [ 'error' => [ 'message' => 'Invalid API key' ] ] ),
				'headers'  => [], 'cookies' => [], 'filename' => null,
			];
		}, 10, 3 );

		$result = Ai_Assist::test_connection( 'openai', 'sk-bad', '', '' );
		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'ai_provider_error', $result['code'] );
	}

	public function test_connection_uses_the_given_base_url_and_model_never_a_saved_one(): void {
		$captured_url   = null;
		$captured_model = null;
		add_filter( 'pre_http_request', function ( $preempt, $args, $url ) use ( &$captured_url, &$captured_model ) {
			$captured_url   = $url;
			$captured_model = json_decode( (string) $args['body'], true )['model'] ?? null;
			return [
				'response' => [ 'code' => 200, 'message' => '' ],
				'body'     => wp_json_encode( [ 'choices' => [ [ 'message' => [ 'content' => 'OK' ] ] ] ] ),
				'headers'  => [], 'cookies' => [], 'filename' => null,
			];
		}, 10, 3 );

		Ai_Assist::test_connection( 'openai', 'sk-draft', 'http://localhost:1234/v1/chat/completions', 'custom-model' );

		$this->assertSame( 'http://localhost:1234/v1/chat/completions', $captured_url );
		$this->assertSame( 'custom-model', $captured_model );
	}

	// --- REST: base_url/model are plain text, not secrets ---

	public function test_site_settings_echoes_base_url_and_model_back_in_plain_text(): void {
		wp_set_current_user( $this->admin_id );
		$request = new WP_REST_Request( 'PUT', '/be/v1/ai-assist/settings' );
		$request->set_body_params( [
			'openai_base_url' => 'http://localhost:11434/v1/chat/completions',
			'openai_model'    => 'llama3',
		] );
		$response = $this->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 'http://localhost:11434/v1/chat/completions', $data['openai_base_url'] );
		$this->assertSame( 'llama3', $data['openai_model'] );
	}

	public function test_chronicle_settings_echoes_base_url_and_model_back_in_plain_text(): void {
		wp_set_current_user( $this->hst_id );
		$request = new WP_REST_Request( 'PUT', "/be/v1/{$this->game_slug}/ai-assist/settings" );
		$request->set_url_params( [ 'game_slug' => $this->game_slug ] );
		$request->set_body_params( [
			'claude_base_url' => 'http://chronicle-own-endpoint',
			'claude_model'    => 'chronicle-model',
		] );
		$response = $this->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 'http://chronicle-own-endpoint', $data['claude_base_url'] );
		$this->assertSame( 'chronicle-model', $data['claude_model'] );
	}

	// --- REST: /test routes, gated identically to their own settings routes ---

	private function test_request( string $provider, ?string $game_slug ): WP_REST_Request {
		$path    = $game_slug ? "/be/v1/{$game_slug}/ai-assist/test" : '/be/v1/ai-assist/test';
		$request = new WP_REST_Request( 'POST', $path );
		if ( $game_slug ) {
			$request->set_url_params( [ 'game_slug' => $game_slug ] );
		}
		$request->set_body_params( [ 'provider' => $provider, 'key' => 'sk-draft-value' ] );
		return $request;
	}

	public function test_site_test_route_is_denied_to_a_subscriber(): void {
		wp_set_current_user( $this->subscriber_id );
		$response = $this->dispatch( $this->test_request( 'openai', null ) );
		$this->assertSame( 403, $response->get_status() );
	}

	public function test_site_test_route_succeeds_for_an_administrator(): void {
		$this->mock_openai_success( 'OK' );
		wp_set_current_user( $this->admin_id );
		$response = $this->dispatch( $this->test_request( 'openai', null ) );
		$this->assertSame( 200, $response->get_status() );
	}

	public function test_site_test_route_is_denied_to_an_hst_be_manage_games_is_administrator_only(): void {
		wp_set_current_user( $this->hst_id );
		$response = $this->dispatch( $this->test_request( 'openai', null ) );
		$this->assertSame( 403, $response->get_status() );
	}

	public function test_chronicle_test_route_succeeds_for_an_hst_via_be_manage_apr(): void {
		$this->mock_openai_success( 'OK' );
		wp_set_current_user( $this->hst_id );
		$response = $this->dispatch( $this->test_request( 'openai', $this->game_slug ) );
		$this->assertSame( 200, $response->get_status() );
	}

	public function test_chronicle_test_route_is_denied_to_a_narrator(): void {
		wp_set_current_user( $this->narrator_id );
		$response = $this->dispatch( $this->test_request( 'openai', $this->game_slug ) );
		$this->assertSame( 403, $response->get_status(), 'be_manage_apr is not granted to narrator' );
	}

	public function test_test_route_surfaces_a_failed_connection_as_a_502_not_a_500(): void {
		add_filter( 'pre_http_request', function ( $preempt, $args, $url ) {
			if ( strpos( $url, 'api.openai.com' ) === false ) {
				return $preempt;
			}
			return [
				'response' => [ 'code' => 401, 'message' => 'Unauthorized' ],
				'body'     => wp_json_encode( [ 'error' => [ 'message' => 'Invalid API key' ] ] ),
				'headers'  => [], 'cookies' => [], 'filename' => null,
			];
		}, 10, 3 );

		wp_set_current_user( $this->admin_id );
		$response = $this->dispatch( $this->test_request( 'openai', null ) );
		$this->assertSame( 502, $response->get_status() );
	}

	// --- REST: field_context/capability enforcement ---

	private function generate_request( string $field_context, ?string $game_slug = null, string $current_text = '' ): WP_REST_Request {
		$path    = $game_slug ? "/be/v1/{$game_slug}/ai-assist" : '/be/v1/ai-assist';
		$request = new WP_REST_Request( 'POST', $path );
		if ( $game_slug ) {
			$request->set_url_params( [ 'game_slug' => $game_slug ] );
		}
		$request->set_body_params( [ 'field_context' => $field_context, 'current_text' => $current_text, 'instruction' => 'x' ] );
		return $request;
	}

	public function test_a_subscriber_is_denied_on_a_chronicle_scoped_field(): void {
		wp_set_current_user( $this->subscriber_id );
		$response = $this->dispatch( $this->generate_request( 'character_biography', $this->game_slug ) );
		$this->assertSame( 403, $response->get_status() );
	}

	public function test_an_hst_can_reach_a_chronicle_scoped_field(): void {
		update_option( Ai_Assist::SITE_OPENAI_KEY_OPTION, Ai_Assist::encrypt( 'sk-site' ) );
		Game::update( $this->game_slug, [ 'settings' => [ 'ai_assist_enabled' => true ] ] );
		$this->mock_openai_success( 'Suggestion.' );

		wp_set_current_user( $this->hst_id );
		$response = $this->dispatch( $this->generate_request( 'character_biography', $this->game_slug ) );
		$this->assertSame( 200, $response->get_status() );
	}

	public function test_a_narrator_is_denied_on_a_field_gated_by_be_manage_characters(): void {
		wp_set_current_user( $this->narrator_id );
		$response = $this->dispatch( $this->generate_request( 'character_biography', $this->game_slug ) );
		$this->assertSame( 403, $response->get_status(), 'be_manage_characters is not granted to narrator - narrator only manages plots' );
	}

	public function test_a_narrator_can_reach_a_field_gated_by_be_manage_plots(): void {
		update_option( Ai_Assist::SITE_OPENAI_KEY_OPTION, Ai_Assist::encrypt( 'sk-site' ) );
		Game::update( $this->game_slug, [ 'settings' => [ 'ai_assist_enabled' => true ] ] );
		$this->mock_openai_success( 'Suggestion.' );

		wp_set_current_user( $this->narrator_id );
		$response = $this->dispatch( $this->generate_request( 'plot_description', $this->game_slug ) );
		$this->assertSame( 200, $response->get_status() );
	}

	public function test_a_site_wide_field_reached_through_the_chronicle_scoped_route_is_denied(): void {
		wp_set_current_user( $this->admin_id );
		$response = $this->dispatch( $this->generate_request( 'credits_text', $this->game_slug ) );
		$this->assertSame( 403, $response->get_status(), 'credits_text belongs to no chronicle - it must not be reachable via a game_slug route' );
	}

	public function test_a_chronicle_scoped_field_reached_through_the_site_wide_route_is_denied(): void {
		wp_set_current_user( $this->admin_id );
		$response = $this->dispatch( $this->generate_request( 'character_biography', null ) );
		$this->assertSame( 403, $response->get_status(), 'character_biography belongs to a chronicle - it must not be reachable via the bare site-wide route' );
	}

	public function test_a_site_wide_field_works_correctly_through_the_site_wide_route(): void {
		update_option( Ai_Assist::SITE_OPENAI_KEY_OPTION, Ai_Assist::encrypt( 'sk-site' ) );
		$this->mock_openai_success( 'A short credits line.' );

		wp_set_current_user( $this->admin_id );
		$response = $this->dispatch( $this->generate_request( 'credits_text', null ) );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'A short credits line.', $response->get_data()['suggestion'] );
	}

	// --- REST: settings routes never leak key material ---

	public function test_site_settings_never_echo_the_key_material(): void {
		wp_set_current_user( $this->admin_id );
		$request = new WP_REST_Request( 'PUT', '/be/v1/ai-assist/settings' );
		$request->set_body_params( [ 'openai_key' => 'sk-real-secret-value' ] );
		$response = $this->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertArrayNotHasKey( 'openai_key', $data );
		$this->assertTrue( $data['has_openai_key'] );
		$this->assertStringNotContainsString( 'sk-real-secret-value', wp_json_encode( $data ) );
	}

	public function test_site_settings_clears_a_key_on_empty_string(): void {
		update_option( Ai_Assist::SITE_OPENAI_KEY_OPTION, Ai_Assist::encrypt( 'sk-existing' ) );

		wp_set_current_user( $this->admin_id );
		$request = new WP_REST_Request( 'PUT', '/be/v1/ai-assist/settings' );
		$request->set_body_params( [ 'openai_key' => '' ] );
		$response = $this->dispatch( $request );

		$this->assertFalse( $response->get_data()['has_openai_key'] );
	}

	public function test_an_hst_can_manage_their_own_chronicles_ai_settings(): void {
		wp_set_current_user( $this->hst_id );
		$request = new WP_REST_Request( 'PUT', "/be/v1/{$this->game_slug}/ai-assist/settings" );
		$request->set_url_params( [ 'game_slug' => $this->game_slug ] );
		$request->set_body_params( [ 'enabled' => true, 'openai_key' => 'sk-chronicle-secret' ] );
		$response = $this->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertTrue( $data['enabled'] );
		$this->assertTrue( $data['has_openai_key'] );
		$this->assertStringNotContainsString( 'sk-chronicle-secret', wp_json_encode( $data ) );
	}

	public function test_the_general_games_route_never_leaks_a_chronicles_ai_key_either(): void {
		Game::update( $this->game_slug, [ 'settings' => [
			'ai_assist_enabled' => true,
			'ai_openai_key'     => Ai_Assist::encrypt( 'sk-should-never-appear' ),
		] ] );

		wp_set_current_user( $this->admin_id );
		$response = $this->dispatch( new WP_REST_Request( 'GET', "/be/v1/games/{$this->game_slug}" ) );

		$this->assertSame( 200, $response->get_status() );
		$body = wp_json_encode( $response->get_data() );
		// The stored value is already encrypted, so it never contains the plaintext regardless
		// of redaction - the real proof is that the field NAME itself is gone from the response.
		$this->assertStringNotContainsString( 'ai_openai_key', $body, 'the raw settings field must be stripped, not merely encrypted-but-still-present' );
	}
}
