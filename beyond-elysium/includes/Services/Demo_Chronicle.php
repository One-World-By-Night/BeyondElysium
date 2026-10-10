<?php

namespace BeyondElysium\Services;

use BeyondElysium\Database\Manager;
use BeyondElysium\Database\Seeder;
use BeyondElysium\Database\Transaction;
use BeyondElysium\Models\After_Game_Report;
use BeyondElysium\Models\Attachment;
use BeyondElysium\Models\Attendance;
use BeyondElysium\Models\Character;
use BeyondElysium\Models\Connection;
use BeyondElysium\Models\Faction;
use BeyondElysium\Models\Faction_Member;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Game_Member;
use BeyondElysium\Models\Game_Session;
use BeyondElysium\Models\Item_Event;
use BeyondElysium\Models\Mail_Log;
use BeyondElysium\Models\Npc_Casting;
use BeyondElysium\Models\Plot;
use BeyondElysium\Models\Plot_Entry;
use BeyondElysium\Models\Position;
use BeyondElysium\Models\Release_Batch;
use BeyondElysium\Models\Saved_Query;
use BeyondElysium\Models\Schema_Block;
use BeyondElysium\Models\Secret;
use BeyondElysium\Models\Secret_Reveal;
use BeyondElysium\Models\Sheet_Style;
use BeyondElysium\Models\World_Object;

defined( 'ABSPATH' ) || exit;

/**
 * Resets a chronicle flagged as a demo back to its declared content, on a schedule, with a companion chronicle for
 * seeing what joining a chronicle looks like from the outside.
 */
class Demo_Chronicle {

	/**
	 * Cron hook a demo chronicle's own reset fires on. The game id is its one argument.
	 */
	const RESET_HOOK = 'be_demo_reset';

	/**
	 * Option holding the last reset's time and counts, per game id.
	 */
	const LAST_RESET_OPTION = 'be_demo_last_reset';

	/**
	 * Cadences a demo chronicle may choose, in hours.
	 */
	const CADENCES = [ 1, 3, 6, 12, 24 ];

	/**
	 * Whether a chronicle is flagged as a demo.
	 *
	 * @param object|null $game A games row with `settings` already decoded.
	 * @return bool
	 */
	public static function is_demo( $game ): bool {
		if ( ! is_object( $game ) ) {
			return false;
		}
		$settings = $game->settings ?? null;
		return is_object( $settings ) && ! empty( $settings->demo->on ?? false );
	}

	/**
	 * The user id behind one of a demo chronicle's two roles, or null when unset.
	 *
	 * @param object $game
	 * @param string $role 'storyteller' or 'player'.
	 * @return int|null
	 */
	public static function account_id( object $game, string $role ): ?int {
		$id = (int) ( $game->settings->demo->accounts->{$role} ?? 0 );
		return $id ?: null;
	}

	/**
	 * Corrects a demo account's own password back to the value declared in `settings.demo.accounts_password`, if one
	 * is declared and the account has drifted from it - never otherwise. A chronicle declaring none for a role leaves
	 * that account's password untouched.
	 *
	 * @param object $game
	 * @param int    $user_id
	 * @param string $role 'storyteller' or 'player'.
	 * @return void
	 */
	private static function ensure_account_password( object $game, int $user_id, string $role ): void {
		$password = $game->settings->demo->accounts_password->{$role} ?? null;
		if ( ! $password || self::is_privileged_account( $user_id ) ) {
			return;
		}
		$user = get_userdata( $user_id );
		if ( ! $user || wp_check_password( $password, $user->user_pass, $user_id ) ) {
			return;
		}
		wp_set_password( $password, $user_id );
	}

	/**
	 * Whether an account is a super admin or can manage this site or its users.
	 *
	 * @param int $user_id
	 * @return bool
	 */
	public static function is_privileged_account( int $user_id ): bool {
		return is_super_admin( $user_id )
			|| user_can( $user_id, 'manage_options' )
			|| user_can( $user_id, 'edit_users' )
			|| user_can( $user_id, 'promote_users' );
	}

	/**
	 * A settings write with `demo.accounts_password` replaced by the stored value, or removed when none is stored.
	 *
	 * @param array<string,mixed> $incoming
	 * @param array<string,mixed> $existing
	 * @return array<string,mixed>
	 */
	public static function keep_stored_passwords( array $incoming, array $existing ): array {
		if ( ! isset( $incoming['demo'] ) || ! is_array( $incoming['demo'] ) ) {
			return $incoming;
		}
		$demo = $incoming['demo'];
		unset( $demo['accounts_password'] );
		$stored_demo = $existing['demo'] ?? null;
		$stored      = is_object( $stored_demo ) ? ( $stored_demo->accounts_password ?? null )
			: ( is_array( $stored_demo ) ? ( $stored_demo['accounts_password'] ?? null ) : null );
		if ( $stored !== null ) {
			$demo['accounts_password'] = $stored;
		}
		$incoming['demo'] = $demo;
		return $incoming;
	}

	/**
	 * Removes `demo.accounts_password` from decoded settings.
	 *
	 * @param \stdClass $settings
	 * @return void
	 */
	public static function redact_passwords( \stdClass $settings ): void {
		if ( isset( $settings->demo ) && is_object( $settings->demo ) ) {
			unset( $settings->demo->accounts_password );
		}
	}

	/**
	 * This chronicle's reset cadence in hours, defaulting to 6.
	 *
	 * @param object $game
	 * @return int
	 */
	public static function cadence_hours( object $game ): int {
		$hours = (int) ( $game->settings->demo->reset_hours ?? 6 );
		return in_array( $hours, self::CADENCES, true ) ? $hours : 6;
	}

	/**
	 * The last reset recorded for a game id: `{at: int, counts: array}`, or null if it has never run.
	 *
	 * @param int $game_id
	 * @return array{at:int,counts:array<string,int>}|null
	 */
	public static function last_reset( int $game_id ): ?array {
		$all = get_option( self::LAST_RESET_OPTION, [] );
		return is_array( $all ) ? ( $all[ $game_id ] ?? null ) : null;
	}

	/**
	 * Schedules this chronicle's next reset at its own cadence, replacing any already scheduled.
	 *
	 * @param object $game
	 * @return void
	 */
	public static function schedule( object $game ): void {
		self::unschedule( (int) $game->id );
		wp_schedule_single_event( time() + self::cadence_hours( $game ) * HOUR_IN_SECONDS, self::RESET_HOOK, [ (int) $game->id ] );
	}

	/**
	 * Removes a chronicle's scheduled reset, if one is pending.
	 *
	 * @param int $game_id
	 * @return void
	 */
	public static function unschedule( int $game_id ): void {
		$timestamp = wp_next_scheduled( self::RESET_HOOK, [ $game_id ] );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::RESET_HOOK, [ $game_id ] );
		}
	}

	/**
	 * The cron callback: re-reads the chronicle, resets it if it is still flagged as a demo, and schedules the next
	 * run. A flag turned off lets the schedule lapse.
	 *
	 * @param int $game_id
	 * @return void
	 */
	public static function handle_cron( int $game_id ): void {
		$game = Game::find( $game_id );
		if ( ! $game || ! self::is_demo( $game ) ) {
			return;
		}
		self::reset( $game );
		self::schedule( $game );
	}

	/**
	 * Resets a demo chronicle - and its companion - to their declared content: everything under each is cleared and
	 * rebuilt as the storyteller account, with every email suppressed, in one transaction.
	 *
	 * @param object $game A games row with `settings` already decoded, flagged as a demo.
	 * @return bool
	 */
	public static function reset( object $game ): bool {
		$game_id = (int) $game->id;
		$lock    = 'be_demo_reset_lock_' . $game_id;
		if ( get_transient( $lock ) ) {
			return false;
		}
		set_transient( $lock, 1, 5 * MINUTE_IN_SECONDS );

		try {
			$storyteller_id = self::account_id( $game, 'storyteller' );
			$player_id      = self::account_id( $game, 'player' );
			if ( ! $storyteller_id ) {
				return false;
			}

			self::ensure_account_password( $game, $storyteller_id, 'storyteller' );
			if ( $player_id ) {
				self::ensure_account_password( $game, $player_id, 'player' );
			}

			// Demonstrates players logging and passing secrets, on their own default ("Needs a Storyteller").
			$existing_settings = (array) ( $game->settings ?? [] );
			if ( ! isset( $existing_settings['secret_passing'] ) ) {
				Game::update( (string) $game->slug, [ 'settings' => array_merge( $existing_settings, [ 'secret_passing' => 'approval' ] ) ] );
			}

			$companion = self::ensure_companion( $game );

			$unit = Transaction::begin( 'be_demo_chronicle_reset' );

			if ( ! Game::clear_content( $game_id ) || ( $companion && ! Game::clear_content( (int) $companion->id ) ) ) {
				Transaction::rollback( $unit );
				return false;
			}

			// Demonstrates a chronicle choosing its own order for a block the book never flags that way. Runs after
			// content is cleared.
			self::ensure_player_order( (string) $game->slug, 'vampire-disciplines' );

			$previous_user = get_current_user_id();
			wp_set_current_user( $storyteller_id );

			$counts = self::build( $game, $companion, $storyteller_id, $player_id );

			Game_Member::set_role( $game_id, $storyteller_id, 'hst' );
			if ( $player_id ) {
				Game_Member::set_role( $game_id, $player_id, 'player' );
			}
			if ( $companion ) {
				Game_Member::set_role( (int) $companion->id, $storyteller_id, 'hst' );
			}

			wp_set_current_user( $previous_user );

			Transaction::commit( $unit );

			$all             = get_option( self::LAST_RESET_OPTION, [] );
			$all[ $game_id ] = [ 'at' => time(), 'counts' => $counts ];
			update_option( self::LAST_RESET_OPTION, $all );

			error_log( sprintf( 'Beyond Elysium: demo chronicle %d reset, %s', $game_id, wp_json_encode( $counts ) ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions

			return true;
		} finally {
			delete_transient( $lock );
		}
	}

	/**
	 * Forks a chronicle's own copy of a block and flags it as `player_order`, if it isn't already.
	 *
	 * @param string $game_slug
	 * @param string $block_slug
	 */
	private static function ensure_player_order( string $game_slug, string $block_slug ): void {
		$block = Schema_Block::find_for_game( $block_slug, $game_slug );
		if ( $block && (string) $block->game_slug === $game_slug && ! empty( $block->definition->player_order ) ) {
			return;
		}

		$fork = Schema_Block::find_or_create_fork_for_game( $block_slug, $game_slug );
		if ( ! $fork ) {
			return;
		}
		$definition = (array) $fork->definition;
		$definition['player_order'] = true;
		Schema_Block::update( $block_slug, [ 'definition' => $definition ], $game_slug );
	}

	/**
	 * Finds or creates the companion chronicle, flagged as a demo itself so it is locked and silent the same way.
	 *
	 * @param object $game
	 * @return object|null
	 */
	private static function ensure_companion( object $game ): ?object {
		$declaration = require __DIR__ . '/../Database/demo-chronicle.php';
		$definition  = $declaration['chronicles']['companion'] ?? null;
		if ( ! $definition ) {
			return null;
		}

		$slug = $game->slug . '-companion';
		$companion = Game::find_by_slug( $slug );
		if ( $companion ) {
			return $companion;
		}

		$id = Game::create( [
			'name'        => $definition['name'],
			'slug'        => $slug,
			'game_type'   => $definition['game_type'] ?? 'met',
			'description' => $definition['description'] ?? '',
			'settings'    => [ 'demo' => [ 'on' => true, 'companion_of' => (int) $game->id ] ],
		] );
		return $id ? Game::find( (int) $id ) : null;
	}

	/**
	 * Walks the declaration, creating every entry in fixed order. Returns a count per content kind.
	 *
	 * @param object      $game
	 * @param object|null $companion
	 * @param int         $storyteller_id
	 * @param int|null    $player_id
	 * @return array<string,int>
	 */
	private static function build( object $game, ?object $companion, int $storyteller_id, ?int $player_id ): array {
		$d = require __DIR__ . '/../Database/demo-chronicle.php';

		$game_id_for   = static fn( string $chronicle ) => $chronicle === 'companion' && $companion ? (int) $companion->id : (int) $game->id;
		$slug_for      = static fn( string $chronicle ) => $chronicle === 'companion' && $companion ? (string) $companion->slug : (string) $game->slug;
		$account_id_of = static fn( ?string $role ) => $role === 'storyteller' ? $storyteller_id : ( $role === 'player' ? $player_id : null );

		$character_ids = [];
		$counts        = [ 'characters' => 0, 'sessions' => 0, 'factions' => 0, 'positions' => 0, 'plots' => 0, 'secrets' => 0, 'world_objects' => 0, 'npcs' => 0, 'connections' => 0 ];

		// Characters, from demo_fixtures() into the primary chronicle, owned per character_owners.
		foreach ( Seeder::demo_fixtures() as $fixture ) {
			$owner_role = $d['character_owners'][ $fixture['name'] ] ?? null;
			$id         = Character::create( [
				'name' => $fixture['name'], 'stack_slug' => $fixture['stack_slug'],
				'owner_type' => 'chronicle', 'owner_slug' => $slug_for( 'primary' ),
				'wp_user_id' => $account_id_of( $owner_role ),
				'player_name' => $owner_role ? null : ( $fixture['player_name'] ?? 'Stock Sheet' ),
				'sheet_data' => $fixture['sheet_data'] ?? [],
			] );
			if ( $id ) {
				Character::update_xp( $id, (int) ( $fixture['xp_earned'] ?? 0 ), (int) ( $fixture['xp_unspent'] ?? 0 ) );
				$character_ids[ $fixture['name'] ] = $id;
				++$counts['characters'];
			}
		}

		// Characters declared directly (the companion's own cast).
		foreach ( $d['extra_characters'] as $extra ) {
			$id = Character::create( [
				'name' => $extra['name'], 'stack_slug' => $extra['stack_slug'],
				'owner_type' => 'chronicle', 'owner_slug' => $slug_for( $extra['chronicle'] ),
				'player_name' => $extra['player_name'] ?? 'Storyteller-Run',
				'sheet_data' => $extra['sheet_data'] ?? [],
			] );
			if ( $id ) {
				Character::update_xp( $id, (int) ( $extra['xp_earned'] ?? 0 ), (int) ( $extra['xp_unspent'] ?? 0 ) );
				$character_ids[ $extra['name'] ] = $id;
				++$counts['characters'];
			}
		}

		// NPCs.
		foreach ( $d['npcs'] as $npc ) {
			$id = Character::create( [
				'name' => $npc['name'], 'stack_slug' => $npc['stack_slug'],
				'owner_type' => 'chronicle', 'owner_slug' => $slug_for( $npc['chronicle'] ),
				'is_npc' => 1, 'npc_detail' => $npc['npc_detail'] ?? 'full',
				'player_name' => 'Storyteller-Run',
				'sheet_data' => $npc['sheet_data'] ?? [],
			] );
			if ( $id ) {
				$character_ids[ $npc['name'] ] = $id;
				++$counts['npcs'];
			}
		}

		// Who's Who profiles on a character already created above.
		foreach ( $d['character_profiles'] ?? [] as $profile ) {
			$character_id = $character_ids[ $profile['character'] ] ?? null;
			if ( ! $character_id ) {
				continue;
			}
			$fields = [
				'profile_audience'     => $profile['profile_audience'],
				'profile_show_player'  => $profile['profile_show_player'] ? 1 : 0,
			];
			if ( isset( $profile['public_description'] ) ) {
				$fields['public_description'] = $profile['public_description'];
			}
			Character::update_header( $character_id, $fields );
		}

		// Character-to-character connections.
		foreach ( $d['connections'] ?? [] as $connection ) {
			$a_id = $character_ids[ $connection['a'] ] ?? null;
			$b_id = $character_ids[ $connection['b'] ] ?? null;
			if ( ! $a_id || ! $b_id ) {
				continue;
			}
			$connection_id = Connection::create( [
				'game_id'     => $game_id_for( 'primary' ),
				'source_type' => 'character', 'source_id' => $a_id,
				'target_type' => 'character', 'target_id' => $b_id,
				'label'       => $connection['label'] ?? null,
				'notes'       => $connection['notes'] ?? null,
				'created_by'  => $storyteller_id,
			] );
			if ( $connection_id ) {
				++$counts['connections'];
			}
		}

		// Pending changes. A declared 'review' of 'approve' has the Storyteller review it right away; 'auto' does the
		// same and then flags the row auto_approved, standing in for a chronicle whose rules would have cleared it on
		// submission.
		foreach ( $d['pending_changes'] as $change ) {
			$character_id = $character_ids[ $change['character'] ] ?? null;
			if ( ! $character_id ) {
				continue;
			}
			$submitted_by = $account_id_of( $change['account'] ) ?: $storyteller_id;
			$change_id    = Change_Engine::submit( $character_id, [
				'change_type' => $change['change_type'], 'category' => $change['category'],
				'change_data' => $change['change_data'], 'xp_cost' => $change['xp_cost'], 'notes' => $change['notes'],
			], $submitted_by );

			$review = $change_id ? ( $change['review'] ?? null ) : null;
			if ( $review === 'approve' || $review === 'auto' ) {
				Change_Engine::approve( $change_id, $storyteller_id, null );
				if ( $review === 'auto' ) {
					Manager::update( 'character_changes', [ 'auto_approved' => 1 ], [ 'id' => $change_id ] );
				}
			}
		}

		// Sessions: the two past ones plus six more, roughly monthly going forward.
		$session_ids = [];
		foreach ( $d['sessions'] as $key => $session ) {
			$id = Game_Session::create( [
				'game_id' => $game_id_for( $session['chronicle'] ),
				'game_date' => gmdate( 'Y-m-d', (int) strtotime( "-{$session['days_ago']} days" ) ),
				'start_time' => '19:00:00', 'place' => $session['place'], 'notes' => $session['notes'],
			] );
			if ( $id ) {
				$session_ids[ $key ] = $id;
				++$counts['sessions'];
				if ( ! empty( $session['recap'] ) ) {
					Game_Session::update( $id, [ 'recap' => $session['recap'] ] );
				}
			}
		}
		foreach ( $d['upcoming_days_out'] as $days_out ) {
			if ( Game_Session::create( [
				'game_id' => $game_id_for( 'primary' ), 'game_date' => gmdate( 'Y-m-d', (int) strtotime( "+{$days_out} days" ) ),
				'start_time' => '19:00:00', 'place' => $d['upcoming_place'], 'notes' => $d['upcoming_notes'],
			] ) ) {
				++$counts['sessions'];
			}
		}

		// Attendance.
		foreach ( $d['attendance'] as $entry ) {
			$session_id = $session_ids[ $entry['session'] ] ?? null;
			if ( ! $session_id ) {
				continue;
			}
			foreach ( $entry['characters'] as $name ) {
				if ( isset( $character_ids[ $name ] ) ) {
					Attendance::record( $session_id, $game_id_for( 'primary' ), [ 'character_id' => $character_ids[ $name ] ] );
				}
			}
		}

		// Downtime actions, each assigned to a different staff member.
		foreach ( $d['downtime_actions'] as $action ) {
			$character_id = $character_ids[ $action['character'] ] ?? null;
			$session_id   = $session_ids[ $action['session'] ] ?? null;
			if ( ! $character_id || ! $session_id ) {
				continue;
			}
			$session = Game_Session::find( $session_id );
			if ( ! $session ) {
				continue;
			}
			$plot_id     = Action_Allocator::persist( $character_id, (string) $session->game_date );
			$assigned_to = $account_id_of( $action['assigned_to'] );
			if ( $plot_id && $assigned_to ) {
				Plot::update( $plot_id, [ 'assigned_to' => $assigned_to ] );
			}

			// An answered downtime, saying whether it cost the character an action.
			if ( $plot_id && isset( $action['answer'] ) ) {
				$charge = [ 'charged' => false ];
				if ( ! empty( $action['answer']['charge'] ) ) {
					$use = Background_Ledger::record( $character_id, (string) $session->game_date, [
						'name' => $action['answer']['charge']['name'],
						'cost' => $action['answer']['charge']['cost'],
						'text' => wp_trim_words( wp_strip_all_tags( $action['answer']['text'] ), 12, '…' ),
					] );
					if ( ! is_wp_error( $use ) ) {
						$charge = [ 'charged' => true, 'name' => $action['answer']['charge']['name'], 'cost' => $action['answer']['charge']['cost'], 'use_id' => (int) $use['id'] ];
					}
				}
				Plot_Entry::create( [
					'plot_id' => $plot_id, 'author_id' => $storyteller_id, 'entry_type' => 'response',
					'content' => $action['answer']['text'], 'audience' => 'plot', 'held' => false, 'action_charge' => $charge,
				] );
			}
		}

		// Factions.
		$faction_ids = [];
		foreach ( $d['factions'] as $key => $faction ) {
			$id = Faction::create( [
				'game_id' => $game_id_for( $faction['chronicle'] ), 'name' => $faction['name'],
				'faction_type' => $faction['faction_type'], 'description' => $faction['description'] ?? '',
				'audience' => $faction['audience'] ?? Audience::EVERYONE,
			] );
			if ( $id ) {
				$faction_ids[ $key ] = $id;
				++$counts['factions'];
				foreach ( $faction['members'] ?? [] as $member ) {
					if ( isset( $character_ids[ $member['character'] ] ) ) {
						Faction_Member::add( $id, $character_ids[ $member['character'] ], $storyteller_id, (bool) $member['is_leader'], $member['rank'] ?? null, (bool) ( $member['is_public'] ?? true ) );
					}
				}
			}
		}

		// Positions.
		foreach ( $d['positions'] as $position ) {
			$id = Position::create( [
				'game_id' => $game_id_for( $position['chronicle'] ), 'title' => $position['title'],
				'faction_id' => isset( $position['faction'] ) ? ( $faction_ids[ $position['faction'] ] ?? null ) : null,
				'holder_public' => (bool) ( $position['holder_public'] ?? false ),
			] );
			if ( $id ) {
				++$counts['positions'];
				if ( isset( $character_ids[ $position['holder'] ] ) ) {
					Position::set_holder( $id, $character_ids[ $position['holder'] ] );
				}
			}
		}

		// Plots and entries.
		$plot_ids = [];
		foreach ( $d['plots'] as $key => $plot ) {
			$id = Plot::create( [
				'game_id' => $game_id_for( $plot['chronicle'] ), 'title' => $plot['title'],
				'description' => $plot['description'] ?? '', 'status' => $plot['status'] ?? 'active',
				'initiated_by' => $plot['initiated_by'] ?? 'st', 'plot_category' => $plot['plot_category'] ?? 'arc',
				'parent_plot_id' => isset( $plot['parent'] ) ? ( $plot_ids[ $plot['parent'] ] ?? null ) : null,
				'first_introduced' => gmdate( 'Y-m-d', (int) strtotime( "-{$plot['days_ago_introduced']} days" ) ),
				'start_date' => gmdate( 'Y-m-d', (int) strtotime( "-{$plot['days_ago_start']} days" ) ),
			] );
			if ( $id ) {
				$plot_ids[ $key ] = $id;
				++$counts['plots'];
			}
		}
		foreach ( $d['plot_entries'] as $entry ) {
			$plot_id = $plot_ids[ $entry['plot'] ] ?? null;
			if ( ! $plot_id ) {
				continue;
			}
			Plot_Entry::create( [
				'plot_id' => $plot_id, 'author_id' => $storyteller_id, 'entry_type' => $entry['entry_type'],
				'content' => $entry['content'], 'event_date' => gmdate( 'Y-m-d', (int) strtotime( "-{$entry['days_ago']} days" ) ),
				'audience' => $entry['audience'],
			] );
		}

		// World objects.
		$object_ids = [];
		foreach ( $d['world_objects'] as $key => $object ) {
			$id = World_Object::create( [
				'game_id' => $game_id_for( $object['chronicle'] ), 'object_type' => $object['object_type'],
				'name' => $object['name'], 'description' => $object['description'] ?? '',
				'parent_id' => isset( $object['parent'] ) ? ( $object_ids[ $object['parent'] ] ?? null ) : null,
				'properties' => $object['properties'] ?? [],
			] );
			if ( $id ) {
				$object_ids[ $key ] = $id;
				++$counts['world_objects'];
				if ( ! empty( $object['picture'] ) ) {
					self::attach_placeholder_image( $game_id_for( $object['chronicle'] ), (int) $id, $storyteller_id );
				}
			}
		}
		foreach ( $d['item_events'] as $event ) {
			$object_id    = $object_ids[ $event['object'] ] ?? null;
			$character_id = $character_ids[ $event['character'] ] ?? null;
			if ( $object_id ) {
				Item_Event::record( [
					'game_id' => $game_id_for( 'primary' ), 'world_object_id' => $object_id, 'event' => $event['event'],
					'character_id' => $character_id, 'note' => $event['note'],
				] );
			}
		}

		// Secrets, on a plot or an item.
		foreach ( $d['secrets'] as $secret ) {
			$entity_id = $secret['entity_type'] === 'plot'
				? ( $plot_ids[ $secret['entity'] ] ?? null )
				: ( $object_ids[ $secret['entity'] ] ?? null );
			if ( $entity_id ) {
				Secret::create( [
					'game_id' => $game_id_for( 'primary' ), 'entity_type' => $secret['entity_type'], 'entity_id' => $entity_id,
					'title' => $secret['title'], 'content' => $secret['content'], 'audience' => $secret['audience'],
				] );
				++$counts['secrets'];
			}
		}
		foreach ( $d['secret_reveals'] as $reveal ) {
			$entity_id = $reveal['secret_entity_type'] === 'plot'
				? ( $plot_ids[ $reveal['secret_entity'] ] ?? null )
				: ( $object_ids[ $reveal['secret_entity'] ] ?? null );
			$character_id = $character_ids[ $reveal['character'] ] ?? null;
			if ( ! $entity_id || ! $character_id ) {
				continue;
			}
			$secret_id = self::find_secret_id( $game_id_for( 'primary' ), $reveal['secret_entity_type'], $entity_id );
			if ( $secret_id ) {
				Secret_Reveal::create( [ 'secret_id' => $secret_id, 'character_id' => $character_id, 'how' => $reveal['how'], 'note' => $reveal['note'] ] );
			}
		}

		// NPC castings.
		foreach ( $d['npc_castings'] as $casting ) {
			$npc_id     = $character_ids[ $d['npcs'][ $casting['npc'] ]['name'] ] ?? null;
			$session_id = $session_ids[ $casting['session'] ] ?? null;
			$account_id = $account_id_of( $casting['account'] );
			if ( $npc_id && $session_id && $account_id ) {
				Npc_Casting::create( [
					'game_id' => $game_id_for( 'primary' ), 'session_id' => $session_id, 'character_id' => $npc_id,
					'wp_user_id' => $account_id, 'brief' => $casting['brief'],
				] );
			}
		}

		// After-game reports.
		foreach ( $d['after_game_reports'] as $report ) {
			$character_id = $character_ids[ $report['character'] ] ?? null;
			$session_id   = $session_ids[ $report['session'] ] ?? null;
			$account_id   = $account_id_of( $report['account'] );
			if ( $character_id && $session_id && $account_id ) {
				After_Game_Report::create( [
					'game_id' => $game_id_for( 'primary' ), 'session_id' => $session_id, 'character_id' => $character_id,
					'wp_user_id' => $account_id, 'did' => $report['did'], 'wants' => $report['wants'], 'to_staff' => $report['to_staff'],
				] );
			}
		}

		// The mail log: each email a demo would have sent, shown as not sent.
		foreach ( $d['mail_log'] ?? [] as $mail ) {
			$account_id = $account_id_of( $mail['account'] );
			$user       = $account_id ? get_userdata( $account_id ) : false;
			Mail_Log::record( [
				'game_id'         => $game_id_for( 'primary' ),
				'wp_user_id'      => (int) $account_id,
				'recipient_name'  => $user ? $user->display_name : '',
				'recipient_email' => $user ? $user->user_email : '',
				'kind'            => $mail['kind'],
				'subject'         => $mail['subject'],
				'result'          => Mail_Log::SKIPPED,
				'reason'          => Mail_Log::REASON_DEMO,
				'entity_type'     => isset( $mail['plot'] ) ? 'plot' : '',
				'entity_id'       => isset( $mail['plot'] ) ? (int) ( $plot_ids[ $mail['plot'] ] ?? 0 ) : 0,
				'created_at'      => wp_date( 'Y-m-d H:i:s', time() - (int) $mail['hours_ago'] * HOUR_IN_SECONDS ),
			] );
		}

		// Release batches, saved queries, sheet styles.
		foreach ( $d['release_batches'] as $batch ) {
			Release_Batch::create( [ 'game_id' => $game_id_for( $batch['chronicle'] ), 'name' => $batch['name'] ] );
		}
		foreach ( $d['saved_queries'] as $query ) {
			Saved_Query::create( [
				'game_id' => $game_id_for( $query['chronicle'] ), 'name' => $query['name'], 'inventory' => $query['inventory'],
				'match_all' => $query['match_all'] ?? true, 'conditions' => $query['conditions'],
			] );
		}
		foreach ( $d['sheet_styles'] as $style ) {
			$character_id = $character_ids[ $style['character'] ] ?? null;
			if ( $character_id ) {
				Sheet_Style::save( $character_id, [
					'font_family' => $style['font_family'], 'accent_color' => $style['accent_color'],
					'background_color' => $style['background_color'], 'text_color' => $style['text_color'],
				] );
			}
		}

		return $counts;
	}

	/**
	 * A tiny generated PNG, attached to a world object, standing in for a real uploaded picture.
	 *
	 * @param int $game_id
	 * @param int $object_id
	 * @param int $created_by
	 * @return void
	 */
	private static function attach_placeholder_image( int $game_id, int $object_id, int $created_by ): void {
		$path = tempnam( sys_get_temp_dir(), 'be-demo-chronicle-' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$image = imagecreate( 2, 2 );
		imagecolorallocate( $image, 150, 140, 120 );
		imagepng( $image, $path );
		$stored = Attachment_Storage::store( [
			'tmp_name' => $path, 'name' => 'object.png', 'error' => 0, 'size' => filesize( $path ), 'type' => 'image/png',
		] );
		if ( is_array( $stored ) ) {
			Attachment::create( [
				'game_id' => $game_id, 'entity_type' => 'item', 'entity_id' => $object_id,
				'original_name' => $stored['original_name'], 'stored_name' => $stored['stored_name'],
				'mime' => $stored['mime'], 'bytes' => $stored['bytes'], 'created_by' => $created_by,
			] );
		}
	}

	/**
	 * Finds a just-created secret's id by its entity, for linking a reveal to it.
	 *
	 * @param int    $game_id
	 * @param string $entity_type
	 * @param int    $entity_id
	 * @return int|null
	 */
	private static function find_secret_id( int $game_id, string $entity_type, int $entity_id ): ?int {
		$rows = Secret::for_entity( $game_id, $entity_type, $entity_id );
		$row  = $rows[0] ?? null;
		return $row ? (int) $row->id : null;
	}
}
