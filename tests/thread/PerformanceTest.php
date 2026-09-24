<?php

namespace BeyondElysium\Tests\Thread;

use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Every user-facing path against a realistic dataset (220 characters, 60 plots, 120 world objects, 2 games),
 * dispatched through the real REST server.
 */
class PerformanceTest extends WP_UnitTestCase {

	private const GAME_SLUG = 'perf-test-09-a';

	private function dispatch( string $method, string $route, array $params = [] ) {
		$request = new WP_REST_Request( $method, $route );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		return rest_get_server()->dispatch( $request );
	}

	/**
	 * @return float Milliseconds.
	 */
	private function time_ms( callable $fn ): float {
		$start = microtime( true );
		$response = $fn();
		$elapsed = ( microtime( true ) - $start ) * 1000;

		$this->assertNotSame( 500, $response->get_status(), 'The measured call itself must not error.' );

		return $elapsed;
	}

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );
		wp_set_current_user( 1 );

		global $wpdb;
		$count = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->prefix}be_characters WHERE owner_slug = %s", self::GAME_SLUG
		) );
		if ( $count < 100 ) {
			$this->markTestSkipped(
				'Run `wp eval-file tests/fixtures/seed-0.9-performance-dataset.php` first - '
				. "found only {$count} characters in " . self::GAME_SLUG . ', need 100+.'
			);
		}
	}

	public function test_character_sheet_render_under_300ms(): void {
		global $wpdb;
		$id = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$wpdb->prefix}be_characters WHERE owner_slug = %s LIMIT 1", self::GAME_SLUG
		) );
		$g  = self::GAME_SLUG;

		$ms = $this->time_ms( fn() => $this->dispatch( 'GET', "/be/v1/{$g}/characters/{$id}" ) );
		$this->assertLessThan( 300, $ms, "Character sheet render took {$ms}ms, target <300ms." );
	}

	public function test_character_list_20_per_page_under_200ms(): void {
		$g = self::GAME_SLUG;
		$ms = $this->time_ms( fn() => $this->dispatch( 'GET', "/be/v1/{$g}/characters", [ 'per_page' => 20 ] ) );
		$this->assertLessThan( 200, $ms, "Character list took {$ms}ms, target <200ms." );
	}

	public function test_character_list_has_no_n_plus_one(): void {
		if ( ! defined( 'SAVEQUERIES' ) ) {
			$this->markTestSkipped( 'SAVEQUERIES not defined in this environment.' );
		}
		global $wpdb;
		$wpdb->queries = [];
		$g = self::GAME_SLUG;
		$this->dispatch( 'GET', "/be/v1/{$g}/characters", [ 'per_page' => 20 ] );
		$this->assertLessThan( 10, count( $wpdb->queries ), 'Character list query count suggests an N+1.' );
	}

	public function test_query_indexed_condition_under_50ms(): void {
		$g = self::GAME_SLUG;
		$ms = $this->time_ms( fn() => $this->dispatch( 'POST', "/be/v1/{$g}/query", [
			'conditions' => [ [ 'field' => 'race', 'operator' => 'equals', 'find' => 'vampire' ] ],
		] ) );
		$this->assertLessThan( 50, $ms, "Indexed-column query took {$ms}ms, target <50ms." );
	}

	public function test_query_json_trait_condition_under_300ms(): void {
		$g = self::GAME_SLUG;
		$ms = $this->time_ms( fn() => $this->dispatch( 'POST', "/be/v1/{$g}/query", [
			'conditions' => [ [ 'field' => 'nature', 'operator' => 'equals', 'find' => 'Survivor' ] ],
		] ) );
		$this->assertLessThan( 300, $ms, "JSON-trait query took {$ms}ms, target <300ms." );
	}

	/**
	 * find_matches() for a world-object inventory is a full-table SELECT * evaluated in PHP, same as the character path.
	 */
	public function test_world_object_query_under_300ms(): void {
		$g  = self::GAME_SLUG;
		$ms = $this->time_ms( fn() => $this->dispatch( 'POST', "/be/v1/{$g}/query", [
			'inventory'  => 'item',
			'conditions' => [ [ 'field' => 'level', 'operator' => 'at_least', 'value' => 0 ] ],
		] ) );
		$this->assertLessThan( 300, $ms, "World-object query took {$ms}ms, target <300ms." );
	}

	public function test_statistics_over_200_characters_under_1s(): void {
		$g = self::GAME_SLUG;
		$ms = $this->time_ms( fn() => $this->dispatch( 'POST', "/be/v1/{$g}/statistics", [
			'stat_type' => 'distribution', 'key' => 'race',
		] ) );
		$this->assertLessThan( 1000, $ms, "Statistics took {$ms}ms, target <1000ms." );
	}

	public function test_my_plots_under_1s(): void {
		$g = self::GAME_SLUG;
		$ms = $this->time_ms( fn() => $this->dispatch( 'GET', "/be/v1/{$g}/my/plots" ) );
		$this->assertLessThan( 1000, $ms, "/my/plots took {$ms}ms, target <1000ms." );
	}

	public function test_plots_list_has_no_n_plus_one(): void {
		if ( ! defined( 'SAVEQUERIES' ) ) {
			$this->markTestSkipped( 'SAVEQUERIES not defined in this environment.' );
		}
		global $wpdb;
		$wpdb->queries = [];
		$g = self::GAME_SLUG;
		$this->dispatch( 'GET', "/be/v1/{$g}/plots", [ 'per_page' => 20 ] );
		$this->assertLessThan( 10, count( $wpdb->queries ), 'Plot list query count suggests an N+1 (per-plot connection resolution).' );
	}

	public function test_approval_queue_under_300ms(): void {
		$g = self::GAME_SLUG;
		$ms = $this->time_ms( fn() => $this->dispatch( 'GET', "/be/v1/{$g}/changes" ) );
		$this->assertLessThan( 300, $ms, "Approval queue took {$ms}ms, target <300ms." );
	}

	public function test_boon_ledger_under_200ms(): void {
		$g = self::GAME_SLUG;
		$ms = $this->time_ms( fn() => $this->dispatch( 'GET', "/be/v1/{$g}/boons" ) );
		$this->assertLessThan( 200, $ms, "Boon ledger took {$ms}ms, target <200ms." );
	}
}
