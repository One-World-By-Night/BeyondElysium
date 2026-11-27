<?php

namespace BeyondElysium\Tests\Support;

require_once __DIR__ . '/../thread/fixtures/fake-owc-oat-connection.php';

use BeyondElysium\Models\Game;
use BeyondElysium\Models\Game_Member;
use WP_REST_Request;

/**
 * A chronicle on an OWbN path with an HST, an AST, a Narrator and a player, the Toolkit connection switched on or
 * off, and helpers for the REST calls the Coordinator tests make.
 */
trait CoordinatorTestFixture {

	protected string $slug = 'thread-coordinator';
	protected int $game_id = 0;
	protected int $hst_id = 0;
	protected int $ast_id = 0;
	protected int $narrator_id = 0;
	protected int $player_id = 0;

	protected string $toolkit_base = 'https://archivist.example.test/wp-json/owbn/v1/';
	protected string $toolkit_key  = 'k-coordinator-secret-0123456789abcdef';

	/**
	 * Every call the fake Toolkit saw: route, decoded body, headers.
	 *
	 * @var array<int,array{route:string,body:array<mixed>,headers:array<string,string>}>
	 */
	protected array $toolkit_calls = [];

	/**
	 * Where each request the fake Toolkit filed stands, by entry id: status, current_step, originator email.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	protected array $toolkit_entries = [];

	/**
	 * What the fake Toolkit does with the next call to a route: 'timeout', 'challenge', 'html', or an array
	 * `[ status, body ]` for its own refusal. Keyed by route, used once.
	 *
	 * @var array<string,mixed>
	 */
	protected array $toolkit_fail = [];

	/**
	 * Answers for the search routes.
	 *
	 * @var array<string,mixed>
	 */
	protected array $toolkit_search = [ 'characters/search' => [], 'rules/search' => [] ];

	protected int $toolkit_next_entry = 33012;

	/**
	 * @param array<string,mixed> $settings
	 */
	protected function make_chronicle( string $asc_role_path = 'chronicle/kony', array $settings = [] ): void {
		global $wpdb;
		do_action( 'rest_api_init' );
		$wpdb->insert( $wpdb->prefix . 'be_games', [
			'slug'          => $this->slug,
			'name'          => 'Coordinator Chronicle',
			'asc_role_path' => $asc_role_path === '' ? null : $asc_role_path,
			'created_by'    => 1,
			'created_at'    => current_time( 'mysql' ),
			'updated_at'    => current_time( 'mysql' ),
		] );
		$this->game_id = (int) $wpdb->insert_id;
		if ( $settings !== [] ) {
			Game::update( $this->slug, [ 'settings' => $settings ] );
		}

		$make = function ( string $role, string $wp_role, string $name ): int {
			$id = self::factory()->user->create( [ 'role' => $wp_role, 'user_email' => "{$name}@example.org", 'display_name' => ucfirst( $name ) ] );
			Game_Member::set_role( $this->game_id, $id, $role );
			return $id;
		};
		$this->player_id   = $make( 'player', 'subscriber', 'player' );
		$this->hst_id      = $make( 'hst', 'editor', 'hst' );
		$this->ast_id      = $make( 'ast', 'editor', 'ast' );
		$this->narrator_id = $make( 'narrator', 'editor', 'narrator' );
	}

	/**
	 * Puts a fake Archivist Toolkit behind `pre_http_request`. `oat/submit` files a request and honours the idempotency
	 * key the way the real gateway does; `oat/entry/{id}` reads from `$toolkit_entries`; the search routes read from
	 * `$toolkit_search`.
	 */
	protected function fake_toolkit(): void {
		$this->toolkit_calls = [];
		add_filter( 'pre_http_request', function ( $pre, $args, $url ) {
			if ( strpos( $url, $this->toolkit_base ) !== 0 ) {
				return $pre;
			}
			$route = substr( $url, strlen( $this->toolkit_base ) );
			$route = preg_replace( '#^oat/#', '', (string) $route );
			$body  = json_decode( (string) ( $args['body'] ?? '' ), true );
			$this->toolkit_calls[] = [ 'route' => $route, 'body' => is_array( $body ) ? $body : [], 'headers' => (array) ( $args['headers'] ?? [] ) ];

			$reply = fn( int $code, $data, array $headers = [] ) => [
				'headers'  => $headers,
				'body'     => is_string( $data ) ? $data : (string) wp_json_encode( $data ),
				'response' => [ 'code' => $code, 'message' => '' ],
				'cookies'  => [],
				'filename' => null,
			];

			$fail = $this->toolkit_fail[ $route ] ?? $this->toolkit_fail['*'] ?? null;
			if ( $fail !== null ) {
				unset( $this->toolkit_fail[ $route ], $this->toolkit_fail['*'] );
				if ( $fail === 'timeout' ) {
					return new \WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' );
				}
				if ( $fail === 'challenge' ) {
					return $reply( 202, '<html>wait</html>', [ 'sg-captcha' => 'challenge' ] );
				}
				if ( $fail === 'html' ) {
					return $reply( 200, '<html>maintenance</html>' );
				}
				return $reply( (int) $fail[0], $fail[1] );
			}

			if ( $route === 'submit' ) {
				$key = (string) ( $body['idempotency_key'] ?? '' );
				foreach ( $this->toolkit_entries as $id => $entry ) {
					if ( $key !== '' && ( $entry['key'] ?? '' ) === $key ) {
						return $reply( 200, [ 'entry_id' => $id, 'status' => $entry['status'], 'current_step' => $entry['current_step'], 'created' => false, 'message' => 'Entry already filed.' ] );
					}
				}
				$id = $this->toolkit_next_entry++;
				$this->toolkit_entries[ $id ] = [ 'status' => 'pending', 'current_step' => 'staff_review', 'key' => $key, 'body' => $body ];
				return $reply( 200, [ 'entry_id' => $id, 'status' => 'pending', 'current_step' => 'staff_review', 'created' => true, 'message' => 'Entry created successfully.' ] );
			}

			if ( preg_match( '#^entry/(\d+)$#', (string) $route, $m ) ) {
				$entry = $this->toolkit_entries[ (int) $m[1] ] ?? null;
				if ( $entry === null ) {
					return $reply( 404, [ 'code' => 'oat_entry_not_found', 'message' => 'Entry not found.' ] );
				}
				return $reply( 200, [ 'entry' => [ 'id' => (int) $m[1], 'status' => $entry['status'], 'current_step' => $entry['current_step'] ], 'step_label' => ucwords( str_replace( '_', ' ', (string) $entry['current_step'] ) ) ] );
			}

			if ( isset( $this->toolkit_search[ $route ] ) ) {
				return $reply( 200, $this->toolkit_search[ $route ] );
			}
			return $reply( 404, [ 'code' => 'rest_no_route', 'message' => 'No route.' ] );
		}, 10, 3 );
	}

	/**
	 * Moves a request the fake Toolkit holds to a step and status, as its people would.
	 */
	protected function toolkit_moves( int $entry_id, string $step, string $status = 'pending' ): void {
		$this->toolkit_entries[ $entry_id ]['current_step'] = $step;
		$this->toolkit_entries[ $entry_id ]['status']       = $status;
	}

	/**
	 * @return array<int,array{route:string,body:array<mixed>,headers:array<string,string>}>
	 */
	protected function toolkit_calls_to( string $route ): array {
		return array_values( array_filter( $this->toolkit_calls, static fn( $call ) => $call['route'] === $route ) );
	}

	protected function game(): object {
		$game = Game::find_by_slug( $this->slug );
		$this->assertNotNull( $game );
		return $game;
	}

	/**
	 * @param array<string,mixed> $settings
	 */
	protected function set_settings( array $settings ): void {
		Game::update( $this->slug, [ 'settings' => array_merge( (array) $this->game()->settings, $settings ) ] );
	}

	protected function connect_toolkit( bool $on = true ): void {
		global $be_test_oat;
		$be_test_oat = $on ? [ '*' => [ 'mode' => 'remote', 'base' => $this->toolkit_base, 'key' => $this->toolkit_key ] ] : [];
	}

	/**
	 * @param array<string,mixed>|null $body
	 * @return \WP_REST_Response
	 */
	protected function rest( int $as, string $method, string $path, ?array $body = null ) {
		wp_set_current_user( $as );
		$query = '';
		if ( strpos( $path, '?' ) !== false ) {
			[ $path, $query ] = explode( '?', $path, 2 );
		}
		$request = new WP_REST_Request( $method, '/be/v1/' . ltrim( $path, '/' ) );
		if ( $query !== '' ) {
			parse_str( $query, $params );
			$request->set_query_params( $params );
		}
		if ( $body !== null ) {
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( (string) wp_json_encode( $body ) );
		}
		return rest_get_server()->dispatch( $request );
	}

	/**
	 * @param array<string,mixed> $body
	 * @return \WP_REST_Response
	 */
	protected function put_setup( int $as, array $body ) {
		return $this->rest( $as, 'PUT', "{$this->slug}/chronicle-setup", $body );
	}

	/**
	 * @return array<string,mixed>
	 */
	protected function setup_row( string $id ): array {
		$response = $this->rest( $this->hst_id, 'GET', "{$this->slug}/setup-status" );
		foreach ( (array) ( $response->get_data()['items'] ?? [] ) as $row ) {
			if ( $row['id'] === $id ) {
				return $row;
			}
		}
		$this->fail( "No checklist row {$id}." );
	}
}
