<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Change;
use BeyondElysium\Models\Character;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Game_Member;
use BeyondElysium\Models\Player_Invite;
use BeyondElysium\Services\Player_Invites;
use WP_UnitTestCase;

/**
 * Inviting a player by email with their characters, and what happens when the account with that email signs in.
 */
class PlayerInvitesThreadTest extends WP_UnitTestCase {

	private object $game;
	private int $st_id;

	/** @var int[] */
	private array $characters = [];

	public function setUp(): void {
		parent::setUp();
		$id          = (int) Game::create( [ 'name' => 'Invites by Night', 'slug' => 'thread-invites', 'asc_role_path' => 'chronicle/thread-invites' ] );
		$this->game  = Game::find( $id );
		$this->st_id = self::factory()->user->create( [ 'role' => 'editor' ] );
		Game_Member::set_role( $id, $this->st_id, 'hst' );
		foreach ( [ 'Ada Vane', 'Bram Cole', 'Cass Reed' ] as $name ) {
			$this->characters[] = (int) Character::create( [
				'name' => $name, 'stack_slug' => 'vampire', 'owner_type' => 'chronicle', 'owner_slug' => 'thread-invites',
			] );
		}
		delete_site_option( Player_Invites::INDEX_OPTION );
	}

	private function character( int $index ): object {
		return Character::find( $this->characters[ $index ] );
	}

	/** @return object[] */
	private function links_on( int $character_id ): array {
		return array_values( array_filter(
			Change::for_character( $character_id ),
			static fn( $change ) => $change->change_type === 'player_link'
		) );
	}

	public function test_an_invite_for_an_email_with_no_account_waits_and_holds_its_characters(): void {
		$result = Player_Invites::invite( $this->game, 'New.Player@Example.com', [ $this->characters[0], $this->characters[1] ], $this->st_id );

		$this->assertSame( 'invited', $result['status'] );
		$invite = Player_Invite::find( (int) $result['invite_id'] );
		$this->assertSame( 'new.player@example.com', $invite->email );
		$this->assertSame( 'new.player@example.com', $this->character( 0 )->pending_player_email );
		$this->assertSame( 'new.player@example.com', $this->character( 1 )->pending_player_email );
		$this->assertNull( $this->character( 2 )->pending_player_email );
		$this->assertSame( [ get_current_blog_id() ], Player_Invites::sites_for( 'NEW.player@example.com' ) );
	}

	public function test_an_invite_for_an_existing_account_links_it_now(): void {
		$player = self::factory()->user->create( [ 'user_email' => 'already@example.com' ] );

		$result = Player_Invites::invite( $this->game, 'already@example.com', [ $this->characters[0], $this->characters[2] ], $this->st_id );

		$this->assertSame( 'linked', $result['status'] );
		$this->assertSame( 'player', Game_Member::find( (int) $this->game->id, $player )->role );
		$this->assertSame( $player, (int) $this->character( 0 )->wp_user_id );
		$this->assertSame( $player, (int) $this->character( 2 )->wp_user_id );
		$this->assertCount( 1, $this->links_on( $this->characters[0] ) );
		$this->assertSame( [], Player_Invites::sites_for( 'already@example.com' ), 'nothing waits' );
	}

	public function test_an_existing_account_is_found_whatever_the_case_of_its_email(): void {
		$player = self::factory()->user->create( [ 'user_email' => 'MixedCase@Example.com' ] );

		$result = Player_Invites::invite( $this->game, 'mixedcase@EXAMPLE.com', [ $this->characters[1] ], $this->st_id );

		$this->assertSame( 'linked', $result['status'] );
		$this->assertSame( $player, (int) $this->character( 1 )->wp_user_id );
	}

	public function test_signing_in_accepts_the_invite_and_links_its_characters(): void {
		Player_Invites::invite( $this->game, 'later@example.com', [ $this->characters[0], $this->characters[1] ], $this->st_id );
		remove_action( 'user_register', [ Player_Invites::class, 'on_register' ] );
		$player = self::factory()->user->create( [ 'user_email' => 'Later@Example.com' ] );
		add_action( 'user_register', [ Player_Invites::class, 'on_register' ] );
		$this->assertNull( $this->character( 0 )->wp_user_id, 'nothing links before the sign-in' );

		do_action( 'wp_login', 'later', get_userdata( $player ) );

		$this->assertSame( 'player', Game_Member::find( (int) $this->game->id, $player )->role );
		$this->assertSame( $player, (int) $this->character( 0 )->wp_user_id );
		$this->assertSame( $player, (int) $this->character( 1 )->wp_user_id );
		$this->assertNull( $this->character( 0 )->pending_player_email );
		$this->assertSame( [], Player_Invite::open_for_game( (int) $this->game->id ) );
		$this->assertSame( [], Player_Invites::sites_for( 'later@example.com' ), 'the email leaves the index' );
		$this->assertCount( 1, $this->links_on( $this->characters[1] ) );
	}

	public function test_an_account_created_with_the_email_is_linked_at_once(): void {
		Player_Invites::invite( $this->game, 'brand.new@example.com', [ $this->characters[2] ], $this->st_id );

		$player = self::factory()->user->create( [ 'user_email' => 'brand.new@example.com' ] );

		$this->assertSame( $player, (int) $this->character( 2 )->wp_user_id );
	}

	public function test_a_signed_in_accounts_first_request_accepts_its_invite(): void {
		Player_Invites::invite( $this->game, 'first.request@example.com', [ $this->characters[0] ], $this->st_id );
		remove_action( 'user_register', [ Player_Invites::class, 'on_register' ] );
		$player = self::factory()->user->create( [ 'user_email' => 'first.request@example.com' ] );
		add_action( 'user_register', [ Player_Invites::class, 'on_register' ] );
		wp_set_current_user( $player );

		Player_Invites::maybe_accept_current_user();

		$this->assertSame( $player, (int) $this->character( 0 )->wp_user_id );
	}

	public function test_an_account_with_a_different_email_never_matches(): void {
		$result = Player_Invites::invite( $this->game, 'invited@example.com', [ $this->characters[0] ], $this->st_id );
		$other  = self::factory()->user->create( [ 'user_email' => 'someone.else@example.com' ] );

		do_action( 'wp_login', 'someone', get_userdata( $other ) );

		$this->assertNull( $this->character( 0 )->wp_user_id );
		$this->assertNull( Player_Invite::find( (int) $result['invite_id'] )->accepted_at );
		$this->assertNull( Game_Member::find( (int) $this->game->id, $other ) );
	}

	public function test_cancelling_an_invite_clears_its_characters_pending_email(): void {
		$result = Player_Invites::invite( $this->game, 'cancel.me@example.com', [ $this->characters[0], $this->characters[1] ], $this->st_id );

		$this->assertTrue( Player_Invites::cancel( $this->game, (int) $result['invite_id'] ) );

		$this->assertNull( $this->character( 0 )->pending_player_email );
		$this->assertNull( $this->character( 1 )->pending_player_email );
		$this->assertNotNull( Player_Invite::find( (int) $result['invite_id'] )->cancelled_at );
		$this->assertSame( [], Player_Invites::sites_for( 'cancel.me@example.com' ) );
	}

	public function test_a_character_linked_to_someone_else_is_skipped_and_named(): void {
		$holder = self::factory()->user->create( [ 'display_name' => 'Holder Person' ] );
		Character::update_header( $this->characters[0], [ 'wp_user_id' => $holder ] );

		$result = Player_Invites::invite( $this->game, 'newcomer@example.com', [ $this->characters[0], $this->characters[1] ], $this->st_id );

		$this->assertSame( $holder, (int) $this->character( 0 )->wp_user_id, 'never moved' );
		$this->assertNull( $this->character( 0 )->pending_player_email );
		$this->assertSame( 'newcomer@example.com', $this->character( 1 )->pending_player_email );
		$this->assertSame(
			[ [ 'id' => $this->characters[0], 'name' => 'Ada Vane', 'linked_to' => 'Holder Person', 'reason' => 'linked_elsewhere' ] ],
			$result['skipped']
		);
	}

	public function test_linking_skips_a_character_already_linked_to_someone_else(): void {
		$holder = self::factory()->user->create( [ 'display_name' => 'First Owner' ] );
		$player = self::factory()->user->create( [ 'user_email' => 'second@example.com' ] );
		Character::update_header( $this->characters[1], [ 'wp_user_id' => $holder ] );

		$result = Player_Invites::invite( $this->game, 'second@example.com', [ $this->characters[1], $this->characters[2] ], $this->st_id );

		$this->assertSame( $holder, (int) $this->character( 1 )->wp_user_id, 'never moved' );
		$this->assertSame( $player, (int) $this->character( 2 )->wp_user_id );
		$this->assertSame( 'First Owner', $result['skipped'][0]['linked_to'] );
	}

	public function test_many_characters_link_in_one_go_and_one_unlinks(): void {
		$player = self::factory()->user->create();

		$result = Player_Invites::link_characters( $this->game, $player, $this->characters, null, $this->st_id );
		$this->assertCount( 3, $result['linked'] );

		$this->assertTrue( Player_Invites::unlink_character( $this->game, $player, $this->characters[1] ) );
		$this->assertNull( $this->character( 1 )->wp_user_id );
		$this->assertSame( $player, (int) $this->character( 0 )->wp_user_id );
		$this->assertCount( 2, $this->links_on( $this->characters[1] ), 'one line for the link, one for the unlink' );
	}

	public function test_a_character_from_another_chronicle_is_never_linked(): void {
		$elsewhere = (int) Character::create( [ 'name' => 'Far Away', 'stack_slug' => 'vampire', 'owner_type' => 'chronicle', 'owner_slug' => 'some-other-chronicle' ] );
		$player    = self::factory()->user->create();

		$result = Player_Invites::link_characters( $this->game, $player, [ $elsewhere ], null, $this->st_id );

		$this->assertSame( [], $result['linked'] );
		$this->assertNull( Character::find( $elsewhere )->wp_user_id );
	}

	public function test_the_upgrade_gives_each_pending_email_an_open_invite_once(): void {
		Character::update_header( $this->characters[2], [ 'pending_player_email' => 'Old.Pending@Example.com' ] );
		delete_option( 'be_pending_emails_converted' );

		Player_Invites::convert_pending_emails();
		Player_Invites::convert_pending_emails();

		$open = Player_Invite::open_for_game( (int) $this->game->id );
		$this->assertCount( 1, $open );
		$this->assertSame( 'old.pending@example.com', $open[0]->email );
		$this->assertSame( [ get_current_blog_id() ], Player_Invites::sites_for( 'old.pending@example.com' ) );
	}
}
