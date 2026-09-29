<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Database\Schema;
use BeyondElysium\Models\Character;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Game_Member;
use BeyondElysium\Services\Player_Invites;
use PHPUnit\Framework\TestCase;

/**
 * One sign-in accepts the invites for its email on every site of a network, on a real autocommit connection with a
 * real second site, removed afterwards.
 */
class PlayerInvitesMultisiteThreadTest extends TestCase {

	private string $prior_autocommit = '1';

	/** @var array<string,mixed> */
	private array $made = [];

	public static function setUpBeforeClass(): void {
		if ( ! defined( 'BE_WP_TESTS_AVAILABLE' ) || ! BE_WP_TESTS_AVAILABLE ) {
			self::markTestSkipped( 'WP_TESTS_DIR is not set.' );
		}
		if ( ! is_multisite() ) {
			self::markTestSkipped( 'Needs a multisite network (WP_MULTISITE=1).' );
		}
	}

	protected function setUp(): void {
		global $wpdb;
		$this->prior_autocommit = (string) $wpdb->get_var( 'SELECT @@autocommit' );
		$wpdb->query( 'SET autocommit = 1;' );
		delete_site_option( Player_Invites::INDEX_OPTION );
	}

	protected function tearDown(): void {
		global $wpdb;
		if ( ! empty( $this->made['blog'] ) ) {
			require_once ABSPATH . 'wp-admin/includes/ms.php';
			wpmu_delete_blog( (int) $this->made['blog'], true );
		}
		foreach ( (array) ( $this->made['characters'] ?? [] ) as $id ) {
			$wpdb->delete( $wpdb->prefix . 'be_character_changes', [ 'character_id' => $id ] );
			$wpdb->delete( $wpdb->prefix . 'be_characters', [ 'id' => $id ] );
		}
		if ( ! empty( $this->made['game'] ) ) {
			$wpdb->delete( $wpdb->prefix . 'be_player_invites', [ 'game_id' => (int) $this->made['game'] ] );
			$wpdb->delete( $wpdb->prefix . 'be_game_members', [ 'game_id' => (int) $this->made['game'] ] );
			$wpdb->delete( $wpdb->prefix . 'be_games', [ 'id' => (int) $this->made['game'] ] );
		}
		require_once ABSPATH . 'wp-admin/includes/ms.php';
		foreach ( (array) ( $this->made['users'] ?? [] ) as $id ) {
			wpmu_delete_user( $id );
		}
		delete_site_option( Player_Invites::INDEX_OPTION );
		$value = $this->prior_autocommit === '0' ? '0' : '1';
		$wpdb->query( "SET autocommit = {$value};" );
	}

	public function test_one_sign_in_accepts_the_invites_for_its_email_on_every_site(): void {
		$suffix = strtolower( wp_generate_password( 6, false ) );
		$st     = wp_insert_user( [ 'user_login' => "ms-st-{$suffix}", 'user_pass' => wp_generate_password(), 'user_email' => "ms-st-{$suffix}@example.test" ] );
		$this->made['users'][] = $st;

		$main  = get_current_blog_id();
		$other = (int) wpmu_create_blog( (string) get_network()->domain, "/ms-far-{$suffix}/", 'Far Site', $st );
		$this->assertGreaterThan( 0, $other );
		$this->made['blog'] = $other;

		switch_to_blog( $other );
		Schema::create_tables();
		$far_game = Game::find( (int) Game::create( [ 'name' => 'Far Chronicle', 'slug' => "ms-far-{$suffix}" ] ) );
		$far_char = (int) Character::create( [ 'name' => 'Far Walker', 'stack_slug' => 'vampire', 'owner_type' => 'chronicle', 'owner_slug' => "ms-far-{$suffix}" ] );
		Player_Invites::invite( $far_game, "ms-player-{$suffix}@example.test", [ $far_char ], $st );
		restore_current_blog();

		$game               = Game::find( (int) Game::create( [ 'name' => 'Near Chronicle', 'slug' => "ms-near-{$suffix}" ] ) );
		$this->made['game'] = (int) $game->id;
		$near_char          = (int) Character::create( [ 'name' => 'Near Walker', 'stack_slug' => 'vampire', 'owner_type' => 'chronicle', 'owner_slug' => "ms-near-{$suffix}" ] );
		$this->made['characters'][] = $near_char;
		Player_Invites::invite( $game, "MS-Player-{$suffix}@Example.test", [ $near_char ], $st );
		$this->assertEqualsCanonicalizing( [ $main, $other ], Player_Invites::sites_for( "ms-player-{$suffix}@example.test" ) );

		remove_action( 'user_register', [ Player_Invites::class, 'on_register' ] );
		$player = wp_insert_user( [ 'user_login' => "ms-player-{$suffix}", 'user_pass' => wp_generate_password(), 'user_email' => "ms-player-{$suffix}@example.test" ] );
		add_action( 'user_register', [ Player_Invites::class, 'on_register' ] );
		$this->made['users'][] = $player;
		do_action( 'wp_login', "ms-player-{$suffix}", get_userdata( $player ) );

		$this->assertSame( $player, (int) Character::find( $near_char )->wp_user_id );
		switch_to_blog( $other );
		$far_linked = (int) Character::find( $far_char )->wp_user_id;
		$far_member = Game_Member::find( (int) $far_game->id, $player );
		$on_blog    = is_user_member_of_blog( $player, $other );
		restore_current_blog();
		$this->assertSame( $player, $far_linked );
		$this->assertSame( 'player', $far_member->role ?? null );
		$this->assertTrue( $on_blog, 'the account joins the second site' );
		$this->assertSame( $main, get_current_blog_id(), 'the request ends on the site it began on' );
		$this->assertSame( [], Player_Invites::sites_for( "ms-player-{$suffix}@example.test" ) );
	}
}
