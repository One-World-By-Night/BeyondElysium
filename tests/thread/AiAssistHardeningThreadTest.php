<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Game;
use BeyondElysium\Models\Game_Member;
use BeyondElysium\REST\Ai_Assist_Controller;
use BeyondElysium\Services\Ai_Assist;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * AI Assist settings are an HST's or AST's to set, and a chronicle's endpoint URL is requested only where it is safe
 * to.
 */
class AiAssistHardeningThreadTest extends WP_UnitTestCase {

	private string $slug = 'thread-ai-hardening';
	private int $hst;
	private array $outbound = [];

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );

		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'be_games', [
			'slug' => $this->slug, 'name' => $this->slug, 'settings' => '{}',
			'created_by' => 1, 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ),
		] );
		$this->hst = self::factory()->user->create( [ 'role' => 'editor' ] );
		Game_Member::set_role( (int) Game::find_by_slug( $this->slug )->id, $this->hst, 'hst' );

		// Every outbound call is captured and answered locally - no network in tests.
		$this->outbound = [];
		add_filter( 'pre_http_request', [ $this, 'capture' ], 10, 3 );
	}

	public function tearDown(): void {
		remove_filter( 'pre_http_request', [ $this, 'capture' ], 10 );
		parent::tearDown();
	}

	public function capture( $preempt, $args, $url ) {
		$this->outbound[] = [ 'url' => $url, 'args' => $args ];
		return [
			'headers'  => [],
			'body'     => wp_json_encode( [ 'choices' => [ [ 'message' => [ 'content' => 'A suggestion.' ] ] ] ] ),
			'response' => [ 'code' => 200, 'message' => 'OK' ],
			'cookies'  => [],
			'filename' => null,
		];
	}

	private function dispatch( string $method, string $route, array $body = [] ) {
		$request = new WP_REST_Request( $method, $route );
		if ( $body ) {
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( wp_json_encode( $body ) );
		}
		return rest_get_server()->dispatch( $request );
	}

	private function configure_chronicle_endpoint( string $base_url ): void {
		wp_set_current_user( $this->hst );
		$this->dispatch( 'PUT', "/be/v1/{$this->slug}/ai-assist/settings", [
			'enabled' => true, 'provider' => 'openai', 'openai_key' => 'sk-chronicle', 'openai_base_url' => $base_url,
		] );
	}

	public function test_a_chronicles_own_endpoint_is_requested_only_as_a_safe_url(): void {
		$this->configure_chronicle_endpoint( 'http://10.0.0.5:8080/v1/chat/completions' );

		$this->dispatch( 'POST', "/be/v1/{$this->slug}/ai-assist", [ 'field_context' => 'plot_description', 'instruction' => 'A heist' ] );

		$this->assertCount( 1, $this->outbound );
		$this->assertTrue( ! empty( $this->outbound[0]['args']['reject_unsafe_urls'] ) );
	}

	public function test_the_chronicle_connection_test_is_safe_and_reports_only_connected_or_not(): void {
		wp_set_current_user( $this->hst );
		remove_filter( 'pre_http_request', [ $this, 'capture' ], 10 );
		add_filter( 'pre_http_request', static fn( $pre, $args, $url ) => new \WP_Error( 'http_request_failed', 'blocked' ), 10, 3 );

		$response = $this->dispatch( 'POST', "/be/v1/{$this->slug}/ai-assist/test", [
			'provider' => 'openai', 'key' => 'sk-test', 'base_url' => 'http://127.0.0.1:22/',
		] );

		$this->assertSame( 502, $response->get_status() );
		$this->assertSame( 'ai_connection_failed', $response->as_error()->get_error_code() );
	}

	public function test_an_administrators_site_endpoint_may_still_be_a_private_server(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$this->dispatch( 'PUT', '/be/v1/ai-assist/settings', [
			'provider' => 'openai', 'openai_key' => 'sk-site', 'openai_base_url' => 'http://192.168.1.20:11434/v1/chat/completions',
		] );

		$this->dispatch( 'POST', '/be/v1/ai-assist', [ 'field_context' => 'credits_text', 'instruction' => 'Thanks' ] );

		$this->assertCount( 1, $this->outbound );
		$this->assertTrue( empty( $this->outbound[0]['args']['reject_unsafe_urls'] ) );
	}

	public function test_an_unknown_field_context_is_refused_before_anything_is_sent(): void {
		wp_set_current_user( 0 );

		$response = $this->dispatch( 'POST', "/be/v1/{$this->slug}/ai-assist", [ 'field_context' => 'not_a_real_field' ] );

		$this->assertGreaterThanOrEqual( 400, $response->get_status() );
		$this->assertLessThan( 500, $response->get_status() );
		$this->assertSame( [], $this->outbound );
	}

	public function test_every_field_context_has_a_capability_and_every_capability_a_context(): void {
		$this->assertSame(
			array_keys( Ai_Assist::FIELD_CONTEXTS ),
			array_keys( Ai_Assist_Controller::FIELD_CAPABILITIES )
		);
	}

	public function test_the_instruction_is_capped_before_it_is_sent(): void {
		$this->configure_chronicle_endpoint( '' );

		$this->dispatch( 'POST', "/be/v1/{$this->slug}/ai-assist", [
			'field_context' => 'plot_description',
			'instruction'   => str_repeat( 'long ', 5000 ),
		] );

		$body = json_decode( (string) $this->outbound[0]['args']['body'], true );
		$this->assertLessThan( Ai_Assist::MAX_INSTRUCTION_CHARS + 500, mb_strlen( $body['messages'][1]['content'] ) );
	}

	public function test_one_user_cannot_call_generate_without_limit(): void {
		$this->configure_chronicle_endpoint( '' );

		$statuses = [];
		for ( $i = 0; $i <= Ai_Assist::RATE_LIMIT_PER_MINUTE; $i++ ) {
			$statuses[] = $this->dispatch( 'POST', "/be/v1/{$this->slug}/ai-assist", [ 'field_context' => 'plot_description', 'instruction' => "Try {$i}" ] )->get_status();
		}

		$this->assertSame( 200, $statuses[0] );
		$this->assertSame( 429, end( $statuses ) );
		$this->assertCount( Ai_Assist::RATE_LIMIT_PER_MINUTE, $this->outbound );
	}
}
