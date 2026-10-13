<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Core\Maintenance;
use BeyondElysium\Database\Manager;
use BeyondElysium\Models\Bot_Audit;
use BeyondElysium\Models\Bot_Connect_Code;
use BeyondElysium\Models\Bot_Connection;
use BeyondElysium\Models\Bot_Nonce;
use BeyondElysium\Models\Discord_Link;
use BeyondElysium\Models\Game;
use WP_UnitTestCase;

/**
 * The daily sweep's pruning of bot and link rows, and what deleting a chronicle takes with it.
 */
class BotMaintenanceThreadTest extends WP_UnitTestCase {

	private int $admin;

	public function setUp(): void {
		parent::setUp();
		$this->admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
	}

	private function game( string $slug ): object {
		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'be_games', [
			'slug' => $slug, 'name' => $slug, 'settings' => '{}',
			'created_by' => 1, 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ),
		] );
		return Game::find_by_slug( $slug );
	}

	private function rows( string $table, string $where = '1=1', ...$args ): int {
		global $wpdb;
		$sql = "SELECT COUNT(*) FROM {$wpdb->prefix}be_{$table} WHERE {$where}";
		return (int) $wpdb->get_var( $args ? $wpdb->prepare( $sql, ...$args ) : $sql );
	}

	public function test_a_nonce_older_than_an_hour_is_pruned_a_newer_one_survives(): void {
		Manager::insert( 'bot_nonces', [ 'connection_id' => 1, 'nonce' => 'old', 'created_at' => wp_date( 'Y-m-d H:i:s', time() - 2 * HOUR_IN_SECONDS ) ] );
		Manager::insert( 'bot_nonces', [ 'connection_id' => 1, 'nonce' => 'new', 'created_at' => current_time( 'mysql' ) ] );

		Bot_Nonce::prune();

		$this->assertSame( 0, $this->rows( 'bot_nonces', 'nonce = %s', 'old' ) );
		$this->assertSame( 1, $this->rows( 'bot_nonces', 'nonce = %s', 'new' ) );
	}

	public function test_a_code_expired_over_a_day_ago_is_pruned(): void {
		$game = $this->game( 'be-bot-maint-codes' );
		Manager::insert( 'bot_connect_codes', [
			'game_id' => (int) $game->id, 'code_hash' => str_repeat( 'a', 64 ), 'created_by' => $this->admin,
			'created_at' => current_time( 'mysql' ), 'expires_at' => wp_date( 'Y-m-d H:i:s', time() - 2 * DAY_IN_SECONDS ), 'failures' => 0,
		] );

		Bot_Connect_Code::prune();

		$this->assertSame( 0, $this->rows( 'bot_connect_codes', 'game_id = %d', (int) $game->id ) );
	}

	public function test_a_requested_link_whose_token_expired_a_day_ago_is_pruned_with_its_characters(): void {
		$game = $this->game( 'be-bot-maint-links' );
		$id   = Manager::insert( 'discord_links', [
			'game_id' => (int) $game->id, 'discord_user_id' => '1', 'discord_username' => 'a', 'status' => Discord_Link::REQUESTED,
			'token_hash' => str_repeat( 'b', 64 ), 'token_expires_at' => wp_date( 'Y-m-d H:i:s', time() - 2 * DAY_IN_SECONDS ),
			'requested_at' => current_time( 'mysql' ),
		] );
		Manager::insert( 'discord_link_characters', [ 'link_id' => $id, 'character_id' => 1 ] );

		Discord_Link::prune_expired_requests();

		$this->assertSame( 0, $this->rows( 'discord_links', 'id = %d', $id ) );
		$this->assertSame( 0, $this->rows( 'discord_link_characters', 'link_id = %d', $id ) );
	}

	public function test_an_audit_row_older_than_90_days_is_pruned(): void {
		Manager::insert( 'bot_audit', [
			'connection_id' => 1, 'discord_user_id' => '1', 'wp_user_id' => 0, 'method' => 'GET', 'route' => '/x',
			'status' => 200, 'created_at' => wp_date( 'Y-m-d H:i:s', time() - 91 * DAY_IN_SECONDS ),
		] );

		Bot_Audit::prune();

		$this->assertSame( 0, $this->rows( 'bot_audit', 'route = %s', '/x' ) );
	}

	public function test_the_daily_sweep_runs_every_prune(): void {
		Manager::insert( 'bot_nonces', [ 'connection_id' => 1, 'nonce' => 'sweep', 'created_at' => wp_date( 'Y-m-d H:i:s', time() - 2 * HOUR_IN_SECONDS ) ] );

		Maintenance::run();

		$this->assertSame( 0, $this->rows( 'bot_nonces', 'nonce = %s', 'sweep' ) );
	}

	public function test_deleting_a_chronicle_takes_its_connection_codes_nonces_links_and_audit_rows(): void {
		$game    = $this->game( 'be-bot-maint-delete' );
		$game_id = (int) $game->id;

		$connect = Bot_Connection::connect( $game_id, '1', 'Guild', $this->admin );
		$connection_id = $connect['id'];

		Manager::insert( 'bot_connect_codes', [
			'game_id' => $game_id, 'code_hash' => str_repeat( 'c', 64 ), 'created_by' => $this->admin,
			'created_at' => current_time( 'mysql' ), 'expires_at' => current_time( 'mysql' ), 'failures' => 0,
		] );
		Manager::insert( 'bot_nonces', [ 'connection_id' => $connection_id, 'nonce' => 'keep', 'created_at' => current_time( 'mysql' ) ] );
		Bot_Audit::record( $connection_id, '1', $this->admin, 'GET', '/x', 200 );

		$link = Discord_Link::start_or_renew( $game_id, '1', 'player' );
		Manager::insert( 'discord_link_characters', [ 'link_id' => $link['link_id'], 'character_id' => 1 ] );

		Game::delete_with_content( 'be-bot-maint-delete' );

		$this->assertSame( 0, $this->rows( 'bot_connections', 'game_id = %d', $game_id ) );
		$this->assertSame( 0, $this->rows( 'bot_connect_codes', 'game_id = %d', $game_id ) );
		$this->assertSame( 0, $this->rows( 'bot_nonces', 'connection_id = %d', $connection_id ) );
		$this->assertSame( 0, $this->rows( 'bot_audit', 'connection_id = %d', $connection_id ) );
		$this->assertSame( 0, $this->rows( 'discord_links', 'game_id = %d', $game_id ) );
		$this->assertSame( 0, $this->rows( 'discord_link_characters', 'link_id = %d', $link['link_id'] ) );
	}
}
