<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Database\Schema;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Game_Member;
use BeyondElysium\Models\Join_Request;
use PHPUnit\Framework\TestCase;
use WP_REST_Request;
use WP_User;

/**
 * A network account asking to join a chronicle on a site where it holds no role: it becomes a subscriber on that site
 * and nothing more until a Storyteller approves, on a real autocommit connection with a real second site, removed
 * afterwards.
 *
 * @group multisite
 */
class JoinRequestMultisiteThreadTest extends TestCase {

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
	}

	protected function tearDown(): void {
		global $wpdb;
		wp_set_current_user( 0 );
		require_once ABSPATH . 'wp-admin/includes/ms.php';
		if ( ! empty( $this->made['blog'] ) ) {
			wpmu_delete_blog( (int) $this->made['blog'], true );
		}
		foreach ( (array) ( $this->made['users'] ?? [] ) as $id ) {
			wpmu_delete_user( $id );
		}
		$value = $this->prior_autocommit === '0' ? '0' : '1';
		$wpdb->query( "SET autocommit = {$value};" );
	}

	private function make_user( string $label, string $suffix ): int {
		$id = wp_insert_user( [
			'user_login' => "ms-join-{$label}-{$suffix}",
			'user_pass'  => wp_generate_password(),
			'user_email' => "ms-join-{$label}-{$suffix}@example.test",
		] );
		$this->assertIsInt( $id );
		$this->made['users'][] = $id;
		return $id;
	}

	/**
	 * A second site with one chronicle on it. Returns the blog id and the chronicle.
	 *
	 * @return array{0:int,1:object}
	 */
	private function make_site( string $suffix ): array {
		$owner = $this->make_user( 'owner', $suffix );
		$blog  = (int) wpmu_create_blog( (string) get_network()->domain, "/ms-join-{$suffix}/", 'Join Site', $owner );
		$this->assertGreaterThan( 0, $blog );
		$this->made['blog'] = $blog;

		switch_to_blog( $blog );
		Schema::create_tables();
		$game = Game::find( (int) Game::create( [ 'name' => 'Join Chronicle', 'slug' => "ms-join-{$suffix}" ] ) );
		restore_current_blog();

		return [ $blog, $game ];
	}

	/**
	 * Asks to join, as `$applicant`, on the other site.
	 *
	 * @return array{0:int,1:array<string,mixed>}
	 */
	private function ask_to_join( int $applicant, int $blog, string $slug ): array {
		wp_set_current_user( $applicant );
		switch_to_blog( $blog );
		$request = new WP_REST_Request( 'POST', "/be/v1/{$slug}/join" );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( [ 'message' => 'Please let me play.' ] ) );
		$response = rest_get_server()->dispatch( $request );
		$state    = [
			'waiting' => Join_Request::find_waiting( (int) Game::find_by_slug( $slug )->id, $applicant ),
			'member'  => Game_Member::find( (int) Game::find_by_slug( $slug )->id, $applicant ),
			'roles'   => array_values( ( new WP_User( $applicant, '', $blog ) )->roles ),
		];
		restore_current_blog();
		return [ $response->get_status(), $state ];
	}

	public function test_an_account_with_no_role_on_the_site_becomes_a_subscriber_and_nothing_more(): void {
		$suffix = strtolower( wp_generate_password( 6, false ) );
		[ $blog, $game ] = $this->make_site( $suffix );
		$applicant       = $this->make_user( 'applicant', $suffix );
		$this->assertFalse( is_user_member_of_blog( $applicant, $blog ), 'the account holds no role on the site it applies to' );

		[ $status, $state ] = $this->ask_to_join( $applicant, $blog, (string) $game->slug );

		$this->assertSame( 201, $status );
		$this->assertTrue( is_user_member_of_blog( $applicant, $blog ), 'asking to join puts the account on the site' );
		$this->assertSame( [ 'subscriber' ], $state['roles'] );
		$this->assertNotNull( $state['waiting'], 'the request is waiting for a Storyteller' );
		$this->assertNull( $state['member'], 'no chronicle membership before approval' );
	}

	public function test_an_account_already_on_the_site_keeps_the_role_it_has(): void {
		$suffix = strtolower( wp_generate_password( 6, false ) );
		[ $blog, $game ] = $this->make_site( $suffix );
		$applicant       = $this->make_user( 'editor', $suffix );
		add_user_to_blog( $blog, $applicant, 'editor' );

		[ $status, $state ] = $this->ask_to_join( $applicant, $blog, (string) $game->slug );

		$this->assertSame( 201, $status );
		$this->assertSame( [ 'editor' ], $state['roles'], 'a role already held on the site is not replaced' );
		$this->assertNull( $state['member'] );
	}
}
