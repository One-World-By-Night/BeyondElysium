<?php

namespace BeyondElysium\Services;

use BeyondElysium\Core\Mailer;
use BeyondElysium\Core\Page_Provisioner;
use BeyondElysium\Database\Manager;
use BeyondElysium\Models\Change;
use BeyondElysium\Models\Character;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Mail_Log;
use BeyondElysium\Models\Player_Invite;

defined( 'ABSPATH' ) || exit;

/**
 * Invites a player to a chronicle by email with their characters, links an account that already exists, and accepts
 * every waiting invite for an account when it signs in: on any site of the network that holds one.
 */
class Player_Invites {

	/**
	 * The network-wide index of emails with an open invite: email => the ids of the sites that hold one.
	 */
	public const INDEX_OPTION = 'be_pending_invites';

	/**
	 * Set once every character's pending email has an open invite.
	 */
	private const CONVERTED_OPTION = 'be_pending_emails_converted';

	/**
	 * Accounts already checked against the index in this request.
	 *
	 * @var array<int,bool>
	 */
	private static array $checked = [];

	// The network index.

	/**
	 * The index: email => site ids.
	 *
	 * @return array<string,int[]>
	 */
	public static function index(): array {
		$index = get_site_option( self::INDEX_OPTION, [] );
		return is_array( $index ) ? $index : [];
	}

	/**
	 * The sites holding an open invite for an email.
	 *
	 * @return int[]
	 */
	public static function sites_for( string $email ): array {
		return array_map( 'intval', self::index()[ Player_Invite::normalize( $email ) ] ?? [] );
	}

	/**
	 * Records that a site holds an open invite for an email.
	 */
	public static function index_add( string $email, int $blog_id ): void {
		$index = self::index();
		$key   = Player_Invite::normalize( $email );
		$sites = array_map( 'intval', $index[ $key ] ?? [] );
		if ( ! in_array( $blog_id, $sites, true ) ) {
			$sites[]       = $blog_id;
			$index[ $key ] = $sites;
			update_site_option( self::INDEX_OPTION, $index );
		}
	}

	/**
	 * Records that a site no longer holds an open invite for an email; the email leaves the index with its last site.
	 */
	public static function index_remove( string $email, int $blog_id ): void {
		$index = self::index();
		$key   = Player_Invite::normalize( $email );
		if ( ! isset( $index[ $key ] ) ) {
			return;
		}
		$sites = array_values( array_diff( array_map( 'intval', $index[ $key ] ), [ $blog_id ] ) );
		if ( $sites === [] ) {
			unset( $index[ $key ] );
		} else {
			$index[ $key ] = $sites;
		}
		update_site_option( self::INDEX_OPTION, $index );
	}

	// Invites.

	/**
	 * Invites an email to play in a chronicle with the given characters. An account with that email anywhere on the
	 * network is made a player and linked now; otherwise the invite waits, its characters holding the email.
	 *
	 * @param int[] $character_ids
	 * @return array{status:string,invite_id?:int,player?:array<string,mixed>,wp_user_id?:int,display_name?:string,linked:array<int,array<string,mixed>>,skipped:array<int,array<string,mixed>>}
	 *         `status` is `linked` (an account existed) or `invited`.
	 */
	public static function invite( object $game, string $email, array $character_ids, int $invited_by ): array {
		$email = Player_Invite::normalize( $email );
		$user  = get_user_by( 'email', $email );

		if ( $user ) {
			$player = Chronicle_Players::add( $game, (int) $user->ID );
			$result = self::link_characters( $game, (int) $user->ID, $character_ids, null, $invited_by );
			return [ 'status' => 'linked', 'player' => $player, 'wp_user_id' => (int) $user->ID, 'display_name' => (string) $user->display_name ] + $result;
		}

		$invite_id = self::hold( $game, $email, $invited_by );
		return [ 'status' => 'invited', 'invite_id' => $invite_id ] + self::hold_characters( $game, $email, $character_ids );
	}

	/**
	 * Opens, or finds, the chronicle's invite for an email and records it in the index.
	 *
	 * @return int The invite's id.
	 */
	public static function hold( object $game, string $email, int $invited_by ): int {
		$invite_id = Player_Invite::open( (int) $game->id, $email, $invited_by );
		if ( $invite_id > 0 ) {
			self::index_add( $email, get_current_blog_id() );
		}
		return $invite_id;
	}

	/**
	 * Cancels an open invite: its characters stop holding the email, and the index forgets this site for it when no other
	 * invite here waits for that email.
	 */
	public static function cancel( object $game, int $invite_id ): bool {
		$invite = Player_Invite::find( $invite_id );
		if ( ! $invite || (int) $invite->game_id !== (int) $game->id || $invite->accepted_at !== null || $invite->cancelled_at !== null ) {
			return false;
		}
		Player_Invite::cancel( $invite_id );
		foreach ( self::held_character_ids( $game, (string) $invite->email ) as $id ) {
			Character::update_header( $id, [ 'pending_player_email' => null ] );
		}
		if ( Player_Invite::open_for_email( (string) $invite->email ) === [] ) {
			self::index_remove( (string) $invite->email, get_current_blog_id() );
		}
		return true;
	}

	/**
	 * The ids of a chronicle's characters that hold an email and are linked to no account.
	 *
	 * @return int[]
	 */
	public static function held_character_ids( object $game, string $email ): array {
		return array_map(
			'intval',
			array_column(
				Manager::get_results(
					'SELECT id FROM ' . Manager::table( 'characters' ) . ' WHERE owner_slug = %s AND wp_user_id IS NULL AND LOWER(pending_player_email) = %s ORDER BY name ASC',
					(string) $game->slug,
					Player_Invite::normalize( $email )
				),
				'id'
			)
		);
	}

	// Characters.

	/**
	 * Links a chronicle's characters to an account, clearing their pending email, with one history line each. A character
	 * linked to someone else is skipped and named; one that is not a player character of this chronicle is skipped.
	 *
	 * @param int[] $character_ids
	 * @return array{linked:array<int,array{id:int,name:string}>,skipped:array<int,array{id:int,name?:string,linked_to?:string,reason:string}>}
	 */
	public static function link_characters( object $game, int $wp_user_id, array $character_ids, ?int $invite_id = null, ?int $actor_id = null ): array {
		$linked  = [];
		$skipped = [];
		$player  = get_userdata( $wp_user_id );
		foreach ( array_unique( array_map( 'intval', $character_ids ) ) as $id ) {
			$character = Character::find( $id );
			if ( ! $character || (string) $character->owner_slug !== (string) $game->slug || ! empty( $character->is_npc ) ) {
				$skipped[] = [ 'id' => $id, 'reason' => 'not_found' ];
				continue;
			}
			$holder = (int) ( $character->wp_user_id ?? 0 );
			if ( $holder === $wp_user_id ) {
				$skipped[] = [ 'id' => $id, 'name' => (string) $character->name, 'reason' => 'already_linked' ];
				continue;
			}
			if ( $holder > 0 ) {
				$other     = get_userdata( $holder );
				$skipped[] = [ 'id' => $id, 'name' => (string) $character->name, 'linked_to' => $other ? $other->display_name : '', 'reason' => 'linked_elsewhere' ];
				continue;
			}
			if ( ! Character::update_header( $id, [ 'wp_user_id' => $wp_user_id, 'pending_player_email' => null ] ) ) {
				$skipped[] = [ 'id' => $id, 'name' => (string) $character->name, 'reason' => 'not_saved' ];
				continue;
			}
			self::record_link( $id, $wp_user_id, $player ? $player->display_name : '', $invite_id, $actor_id, false );
			$linked[] = [ 'id' => $id, 'name' => (string) $character->name ];
		}
		return [ 'linked' => $linked, 'skipped' => $skipped ];
	}

	/**
	 * Unlinks one of a chronicle's characters from the account it is linked to, with a history line.
	 */
	public static function unlink_character( object $game, int $wp_user_id, int $character_id ): bool {
		$character = Character::find( $character_id );
		if ( ! $character || (string) $character->owner_slug !== (string) $game->slug || (int) ( $character->wp_user_id ?? 0 ) !== $wp_user_id ) {
			return false;
		}
		if ( ! Character::update_header( $character_id, [ 'wp_user_id' => null ] ) ) {
			return false;
		}
		$player = get_userdata( $wp_user_id );
		self::record_link( $character_id, $wp_user_id, $player ? $player->display_name : '', null, get_current_user_id(), true );
		return true;
	}

	/**
	 * Marks a chronicle's characters as waiting for an email. A character linked to an account is skipped and named.
	 *
	 * @param int[] $character_ids
	 * @return array{linked:array<int,array{id:int,name:string}>,skipped:array<int,array<string,mixed>>,held:array<int,array{id:int,name:string}>}
	 */
	private static function hold_characters( object $game, string $email, array $character_ids ): array {
		$held    = [];
		$skipped = [];
		foreach ( array_unique( array_map( 'intval', $character_ids ) ) as $id ) {
			$character = Character::find( $id );
			if ( ! $character || (string) $character->owner_slug !== (string) $game->slug || ! empty( $character->is_npc ) ) {
				$skipped[] = [ 'id' => $id, 'reason' => 'not_found' ];
				continue;
			}
			$holder = (int) ( $character->wp_user_id ?? 0 );
			if ( $holder > 0 ) {
				$other     = get_userdata( $holder );
				$skipped[] = [ 'id' => $id, 'name' => (string) $character->name, 'linked_to' => $other ? $other->display_name : '', 'reason' => 'linked_elsewhere' ];
				continue;
			}
			Character::update_header( $id, [ 'pending_player_email' => $email ] );
			$held[] = [ 'id' => $id, 'name' => (string) $character->name ];
		}
		return [ 'linked' => [], 'skipped' => $skipped, 'held' => $held ];
	}

	/**
	 * Writes one approved history line on a character naming the account it was linked to or unlinked from.
	 */
	private static function record_link( int $character_id, int $wp_user_id, string $player_name, ?int $invite_id, ?int $actor_id, bool $unlinked ): void {
		$actor     = $actor_id ?? get_current_user_id();
		$change_id = Change::create( [
			'character_id' => $character_id,
			'change_type'  => 'player_link',
			'category'     => 'player',
			'change_data'  => array_filter(
				[
					'wp_user_id' => $wp_user_id,
					'player'     => $player_name,
					'invite_id'  => $invite_id,
					'unlinked'   => $unlinked ?: null,
				],
				static fn( $value ) => $value !== null
			),
			'xp_cost'      => 0,
			'status'       => 'approved',
			'submitted_by' => $actor,
		] );
		if ( $change_id ) {
			Change::update_status( $change_id, 'approved', $actor, null );
		}
	}

	// Acceptance.

	/**
	 * Hooks acceptance onto an account's sign-in, its creation, and its first request on a site running the plugin.
	 */
	public static function register(): void {
		add_action( 'wp_login', [ self::class, 'on_login' ], 10, 2 );
		add_action( 'user_register', [ self::class, 'on_register' ] );
		add_action( 'init', [ self::class, 'maybe_accept_current_user' ], 20 );
	}

	/**
	 * Accepts a signing-in account's waiting invites.
	 *
	 * @param string   $login
	 * @param \WP_User $user
	 */
	public static function on_login( $login, $user ): void {
		if ( $user instanceof \WP_User ) {
			self::accept_if_invited( $user );
		}
	}

	/**
	 * Accepts a new account's waiting invites.
	 *
	 * @param int $user_id
	 */
	public static function on_register( $user_id ): void {
		$user = get_userdata( (int) $user_id );
		if ( $user ) {
			self::accept_if_invited( $user );
		}
	}

	/**
	 * Accepts the signed-in account's waiting invites once per request, when its email is in the index.
	 */
	public static function maybe_accept_current_user(): void {
		if ( ! is_user_logged_in() ) {
			return;
		}
		$user = wp_get_current_user();
		if ( ! $user->exists() ) {
			return;
		}
		self::accept_if_invited( $user );
	}

	/**
	 * Accepts an account's waiting invites when its email is in the index, once per account per request.
	 *
	 * @return int How many invites were accepted.
	 */
	public static function accept_if_invited( \WP_User $user ): int {
		if ( isset( self::$checked[ (int) $user->ID ] ) ) {
			return 0;
		}
		if ( self::sites_for( (string) $user->user_email ) === [] ) {
			return 0;
		}
		self::$checked[ (int) $user->ID ] = true;
		return self::accept_for_user( $user );
	}

	/**
	 * Accepts every open invite for an account's email on every site that holds one: the account joins the site, becomes
	 * a player in the chronicle, and its held characters are linked to it.
	 *
	 * @return int How many invites were accepted.
	 */
	public static function accept_for_user( \WP_User $user ): int {
		$email    = Player_Invite::normalize( (string) $user->user_email );
		$accepted = 0;
		foreach ( self::sites_for( $email ) as $blog_id ) {
			$switched = is_multisite() && $blog_id !== get_current_blog_id();
			if ( $switched ) {
				switch_to_blog( $blog_id );
			}
			$accepted += self::accept_on_this_site( $user );
			if ( Player_Invite::open_for_email( $email ) === [] || ! self::tables_exist() ) {
				self::index_remove( $email, $blog_id );
			}
			if ( $switched ) {
				restore_current_blog();
			}
		}
		return $accepted;
	}

	/**
	 * Accepts every open invite for an account's email on the current site.
	 *
	 * @return int How many invites were accepted.
	 */
	public static function accept_on_this_site( \WP_User $user ): int {
		if ( ! self::tables_exist() ) {
			return 0;
		}
		$accepted = 0;
		foreach ( Player_Invite::open_for_email( (string) $user->user_email ) as $invite ) {
			$game = Game::find( (int) $invite->game_id );
			if ( ! $game ) {
				Player_Invite::cancel( (int) $invite->id );
				continue;
			}
			Chronicle_Players::add( $game, (int) $user->ID );
			self::link_characters( $game, (int) $user->ID, self::held_character_ids( $game, (string) $invite->email ), (int) $invite->id, (int) $invite->invited_by ?: (int) $user->ID );
			Player_Invite::accept( (int) $invite->id, (int) $user->ID );
			++$accepted;
		}
		return $accepted;
	}

	/**
	 * Whether this site holds the plugin's invite and character tables.
	 */
	private static function tables_exist(): bool {
		global $wpdb;
		$table = Manager::table( 'player_invites' );
		return (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
	}

	// The invitation email.

	/**
	 * Emails an invitation: the chronicle's name, who invited them, and an OWbN sign-in link to the chronicle.
	 */
	public static function send_invitation( object $game, string $email, int $invited_by ): bool {
		$about = [ 'game_id' => (int) $game->id, 'kind' => 'invite', 'email' => Player_Invite::normalize( $email ) ];
		if ( Demo_Chronicle::is_demo( $game ) ) {
			Mailer::skipped( $about, Mail_Log::REASON_DEMO );
			return false;
		}
		$inviter = get_userdata( $invited_by );
		$args    = [ 'game_slug' => (string) $game->slug ];
		if ( \BeyondElysium\Core\Authorization::asc_role_path( $game, 'player' ) !== null ) {
			$args['auth'] = 'sso';
		}
		$link = add_query_arg( $args, home_url( '/' . Page_Provisioner::PLAYER_SLUG . '/' ) );
		/* translators: %s: the chronicle's name */
		$subject = sprintf( __( 'You are invited to play in %s', 'beyond-elysium' ), (string) $game->name );
		$message = sprintf(
			/* translators: 1: who sent the invitation, 2: the chronicle's name, 3: the email address invited, 4: the sign-in link */
			__( "%1\$s added you as a player in %2\$s.\n\nSign in with your OWbN account, or create one with this email address (%3\$s), and your characters will be waiting:\n\n%4\$s\n", 'beyond-elysium' ),
			$inviter ? $inviter->display_name : __( 'A Storyteller', 'beyond-elysium' ),
			(string) $game->name,
			Player_Invite::normalize( $email ),
			$link
		);
		return Mailer::send( Player_Invite::normalize( $email ), $subject, $message, $about + [ 'subject' => $subject ] );
	}

	// The upgrade.

	/**
	 * Gives every character's pending email an open invite in its chronicle, recorded in the index, once.
	 */
	public static function convert_pending_emails(): void {
		if ( get_option( self::CONVERTED_OPTION ) ) {
			return;
		}
		$rows = Manager::get_results(
			'SELECT DISTINCT owner_slug, LOWER(pending_player_email) AS email FROM ' . Manager::table( 'characters' )
			. " WHERE wp_user_id IS NULL AND pending_player_email IS NOT NULL AND pending_player_email <> ''"
		);
		foreach ( $rows as $row ) {
			$game = Game::find_by_slug( (string) $row->owner_slug );
			if ( $game && is_email( (string) $row->email ) ) {
				self::hold( $game, (string) $row->email, 0 );
			}
		}
		update_option( self::CONVERTED_OPTION, 1 );
	}
}
