<?php

namespace BeyondElysium\Services;

use BeyondElysium\Database\Transaction;
use BeyondElysium\Models\Character;
use BeyondElysium\Models\Change;
use BeyondElysium\Models\Snapshot;
use BeyondElysium\Models\Schema_Block;
use BeyondElysium\Models\Creature_Stack;
use BeyondElysium\Models\Connection;
use BeyondElysium\Models\Faction;
use BeyondElysium\Models\Faction_Member;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Item_Event;
use BeyondElysium\Models\Plot_Entry;
use BeyondElysium\Models\Secret;
use BeyondElysium\Models\Secret_Reveal;
use BeyondElysium\Models\Transfer;
use BeyondElysium\Models\World_Object;

defined( 'ABSPATH' ) || exit;

/**
 * Change Engine — submit, approve, reject, and apply changes to characters.
 */
class Change_Engine {

	/**
	 * Number of approved changes between automatic snapshots.
	 */
	const SNAPSHOT_THRESHOLD = 25;

	/**
	 * Submits a change for a character.
	 *
	 * @param int                 $character_id
	 * @param array<string,mixed> $change_data  Must include: change_type, category, change_data, xp_cost (optional).
	 * @param int                 $submitted_by
	 * @return int Change ID on success, 0 on failure.
	 */
	public static function submit( int $character_id, array $change_data, int $submitted_by ): int {
		$character = Character::find( $character_id );
		if ( ! $character ) {
			return 0;
		}

		$stack = Creature_Stack::find_by_slug( $character->stack_slug );
		if ( ! $stack ) {
			return 0;
		}

		$resolved = self::resolve_approval_level( $character, (object) $change_data );
		$level    = $resolved['level'];
		$reason   = $resolved['reason'];

		// A chronicle whose removal/lowering switch is on, and a change this classifies as one, always waits.
		if ( ! empty( $change_data['force_level'] ) ) {
			$level  = self::strictest( $level, (string) $change_data['force_level'] );
			$reason = $reason ?? ( $change_data['force_reason'] ?? null );
		}

		// Every change is created pending.
		$insert = [
			'character_id'  => $character_id,
			'change_type'   => $change_data['change_type'],
			'category'      => $change_data['category'] ?? null,
			'change_data'   => $change_data['change_data'] ?? [],
			'xp_cost'       => $change_data['xp_cost'] ?? 0,
			'status'        => 'pending',
			'submitted_by'  => $submitted_by,
			'notes'         => $change_data['notes'] ?? null,
			'reason'        => $reason,
			'submission_id' => $change_data['submission_id'] ?? null,
			'auto_approved' => $level === 'auto',
		];

		// A still-pending resubmission of the same trait/field overwrites the one existing row.
		if ( $level !== 'auto' ) {
			$owner_slug    = (string) ( $character->owner_slug ?? '' );
			$duplicate_key = self::pending_duplicate_key( $change_data['change_type'], (array) ( $change_data['change_data'] ?? [] ), $owner_slug );
			if ( $duplicate_key !== null ) {
				$existing_id = self::find_pending_duplicate( $character_id, $change_data['change_type'], $duplicate_key, $owner_slug );
				if ( $existing_id !== null ) {
					return Change::update_pending_data( $existing_id, $insert ) ? $existing_id : 0;
				}
			}
		}

		$change_id = Change::create( $insert );
		if ( ! $change_id ) {
			return 0;
		}

		// Auto-approve immediately if eligible.
		if ( $level === 'auto' ) {
			self::approve( $change_id, $submitted_by, null );
		}

		return $change_id;
	}

	/**
	 * Submits a whole set of changes from the editor as one transaction, under one shared submission id. On a chronicle
	 * with `settings.approval_on_removal` on, any change in the set that `catches_removal_rule()` catches forces every
	 * change in the set to wait for a Storyteller, with a shared reason - never decided from what the client sent.
	 *
	 * @param int                      $character_id
	 * @param array<int,array<string,mixed>> $changes Each shaped as submit()'s own `$change_data`.
	 * @param int                      $submitted_by
	 * @return array{submission_id:?string,change_ids:int[]} An empty `change_ids` means the whole set failed and nothing
	 *                                                        was written.
	 */
	public static function submit_set( int $character_id, array $changes, int $submitted_by ): array {
		if ( $changes === [] ) {
			return [ 'submission_id' => null, 'change_ids' => [] ];
		}

		$character = Character::find( $character_id );
		if ( ! $character ) {
			return [ 'submission_id' => null, 'change_ids' => [] ];
		}

		$game      = Game::find_by_slug( (string) ( $character->owner_slug ?? '' ) );
		$switch_on = $game && ( $game->settings->approval_on_removal ?? false ) === true;

		$any_caught = false;
		if ( $switch_on ) {
			foreach ( $changes as $change ) {
				if ( self::catches_removal_rule( $character, $change ) ) {
					$any_caught = true;
					break;
				}
			}
		}

		$submission_id = wp_generate_uuid4();
		$unit          = Transaction::begin( 'be_changes_submit_set' );
		$change_ids    = [];

		foreach ( $changes as $change ) {
			$change['submission_id'] = $submission_id;
			if ( $any_caught ) {
				$change['force_level']  = 'st';
				$change['force_reason'] = __( 'Part of a change that removes, lowers or renames something.', 'beyond-elysium' );
			}
			$change_id = self::submit( $character_id, $change, $submitted_by );
			if ( ! $change_id ) {
				Transaction::rollback( $unit );
				return [ 'submission_id' => null, 'change_ids' => [] ];
			}
			$change_ids[] = $change_id;
		}

		Transaction::commit( $unit );
		return [ 'submission_id' => $submission_id, 'change_ids' => $change_ids ];
	}

	/**
	 * Whether a proposed change removes something, lowers a held rating or a permanent pool, or relabels/renames a held
	 * row - decided from the character's own currently held sheet, never from what the client's change claims to be.
	 *
	 * @param object               $character
	 * @param array<string,mixed>  $change    Shaped as submit()'s own `$change_data`: change_type, change_data.
	 * @return bool
	 */
	public static function catches_removal_rule( object $character, array $change ): bool {
		$type = $change['change_type'] ?? '';
		$data = is_array( $change['change_data'] ?? null ) ? $change['change_data'] : [];
		$sheet = is_array( $character->sheet_data ?? null ) ? $character->sheet_data : [];

		if ( $type === 'remove_trait' ) {
			return true;
		}

		// A claimed add_trait that actually names an already-held row is checked as a modify.
		if ( $type === 'modify_trait' || $type === 'add_trait' ) {
			$block_slug = $data['block_slug'] ?? null;
			$trait      = is_array( $data['trait'] ?? null ) ? $data['trait'] : [];
			if ( ! is_string( $block_slug ) || $block_slug === '' || ! isset( $trait['name'] ) ) {
				return false;
			}

			// A tiered_power pick or ladder rung: caught when the new level/pick is a lower rank than the held one.
			if ( array_key_exists( 'level', $trait ) || array_key_exists( 'power_name', $trait ) ) {
				$held = null;
				foreach ( ( $sheet[ $block_slug ] ?? [] ) as $row ) {
					if ( ! is_array( $row ) || ( $row['name'] ?? null ) !== $trait['name'] ) {
						continue;
					}
					$row_power_name = ( $row['power_name'] ?? '' ) !== '' ? $row['power_name'] : null;
					$want_power_name = ( $trait['power_name'] ?? '' ) !== '' ? $trait['power_name'] : null;
					if ( $row_power_name === $want_power_name ) {
						$held = $row;
						break;
					}
				}
				if ( $held === null ) {
					// Nothing held under this name/pick yet - a genuine addition, never caught.
					return false;
				}
				$old_level = (int) ( $held['level'] ?? 0 );
				$new_level = array_key_exists( 'level', $trait ) ? (int) $trait['level'] : $old_level;
				return $new_level < $old_level;
			}

			// A trait_list row: caught when the new count is lower, or - a modify alone - the name or label it
			// addresses has changed. An add_trait with no held match is a genuine addition, never caught.
			$previous   = is_array( $data['previous'] ?? null ) ? $data['previous'] : null;
			$definition = self::block_definition( (string) ( $character->owner_slug ?? '' ), $block_slug );

			// A renamed row is addressed by what it was held as, not by its new name - previous's own identity finds it.
			if ( $type === 'modify_trait' && is_string( $previous['name'] ?? null ) && $previous['name'] !== '' ) {
				$previous_label = is_string( $previous['specialization'] ?? null ) ? $previous['specialization'] : '';
				$identity       = Trait_Identity::of( $definition, $previous['name'], $previous_label );
			} else {
				$identity = Trait_Identity::target_of( $definition, $trait, $previous );
			}
			if ( $identity === null ) {
				return false;
			}
			$held_row = null;
			foreach ( ( $sheet[ $block_slug ] ?? [] ) as $row ) {
				if ( is_array( $row ) && Trait_Identity::of_row( $definition, $row ) === $identity ) {
					$held_row = $row;
					break;
				}
			}
			if ( $held_row === null ) {
				return false;
			}
			$old_count = (int) ( $held_row['count'] ?? 1 );
			$new_count = array_key_exists( 'count', $trait ) ? (int) $trait['count'] : $old_count;
			if ( $new_count < $old_count ) {
				return true;
			}
			if ( $type !== 'modify_trait' ) {
				return false;
			}
			$old_name = $held_row['name'] ?? null;
			$new_name = $trait['name'];
			if ( $old_name !== null && $old_name !== $new_name ) {
				return true;
			}
			$old_label = $held_row['specialization'] ?? '';
			$new_label = $trait['specialization'] ?? '';
			return (string) $old_label !== (string) $new_label;
		}

		if ( ! empty( $data['values'] ) ) {
			$block_slug  = $data['block_slug'] ?? null;
			$held_values = is_string( $block_slug ) && $block_slug !== '' ? ( $sheet[ $block_slug ] ?? [] ) : [];
			foreach ( (array) $data['values'] as $pool_name => $new_value ) {
				$new_permanent = is_array( $new_value ) ? ( $new_value['permanent'] ?? null ) : $new_value;
				if ( $new_permanent === null ) {
					continue;
				}
				$old_value     = is_array( $held_values ) ? ( $held_values[ $pool_name ] ?? null ) : null;
				$old_permanent = is_array( $old_value ) ? ( $old_value['permanent'] ?? null ) : $old_value;
				if ( $old_permanent !== null && (int) $new_permanent < (int) $old_permanent ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * The field(s) that identify WHICH trait/resource/identity-field a change targets, joined into one comparison key.
	 *
	 * @param string               $change_type
	 * @param array<string,mixed>  $inner_data change_data's own nested payload (block_slug plus a trait/values/fields key).
	 * @param string               $owner_slug The character's chronicle, so the block's own definition - the chronicle's fork where one exists - decides what identifies a holding.
	 */
	private static function pending_duplicate_key( string $change_type, array $inner_data, string $owner_slug = '' ): ?string {
		$block_slug = $inner_data['block_slug'] ?? null;
		if ( ! is_string( $block_slug ) || $block_slug === '' ) {
			return null;
		}

		switch ( $change_type ) {
			case 'add_trait':
			case 'remove_trait':
			case 'modify_trait':
				// Keyed by the holding, not the name.
				$trait = is_array( $inner_data['trait'] ?? null ) ? $inner_data['trait'] : [];
				if ( ! is_string( $trait['name'] ?? null ) || $trait['name'] === '' ) {
					return null;
				}
				$previous = is_array( $inner_data['previous'] ?? null ) ? $inner_data['previous'] : null;
				$identity = Trait_Identity::target_of( self::block_definition( $owner_slug, $block_slug ), $trait, $previous );
				if ( $identity === null ) {
					return null;
				}
				// The same path under another tradition is another holding.
				$tradition = Trait_Identity::tradition_of_target( $trait, $previous );
				return "{$block_slug}:{$identity}" . ( $tradition === null ? '' : "\0{$tradition}" );

			case 'modify_resource':
				$keys = array_keys( (array) ( $inner_data['values'] ?? [] ) );
				return $keys !== [] ? "{$block_slug}:" . implode( ',', $keys ) : null;

			case 'modify_identity':
				$keys = array_keys( (array) ( $inner_data['fields'] ?? [] ) );
				return $keys !== [] ? "{$block_slug}:" . implode( ',', $keys ) : null;

			default:
				return null;
		}
	}

	/**
	 * Finds this character's own existing pending change targeting the same block/trait-or-field, if one exists.
	 *
	 * @return int|null The existing change's id, or null when there is no duplicate.
	 */
	private static function find_pending_duplicate( int $character_id, string $change_type, string $duplicate_key, string $owner_slug = '' ): ?int {
		foreach ( Change::for_character( $character_id, [ 'status' => 'pending', 'change_type' => $change_type ] ) as $candidate ) {
			$candidate_key = self::pending_duplicate_key( $change_type, (array) ( $candidate->change_data ?? [] ), $owner_slug );
			if ( $candidate_key === $duplicate_key ) {
				return (int) $candidate->id;
			}
		}
		return null;
	}

	/**
	 * One block's definition for this chronicle.
	 *
	 * @param string $owner_slug
	 * @param string $block_slug
	 * @return object|null
	 */
	private static function block_definition( string $owner_slug, string $block_slug ) {
		$block = Purchase_Scope::widen( Schema_Block::find_for_game( $block_slug, $owner_slug ), $owner_slug );
		return $block->definition ?? null;
	}

	/**
	 * Approves a pending change and applies it to the character's sheet: merges its effect into sheet_data, adjusts XP
	 * counters for XP-related change types, marks it approved, and creates a snapshot when the approved-change count
	 * crosses the threshold.
	 *
	 * @param int         $change_id
	 * @param int         $reviewed_by
	 * @param string|null $notes
	 * @param string|null $expected_token The review_token() the reviewer was shown, if any.
	 * @param int|null    $set_cost       What the reviewer says a purchase waiting for a price costs - a whole
	 *                                    number of XP, 0 allowed, per dot for a trait list and per pick for a
	 *                                    power. Required for a change with `cost_pending` and read for no other.
	 * @param array<string,mixed>|null $secret_choice Required for a `log_knowledge` change: either `{secret_id}` to
	 *                                    tie it to an existing secret, or `{entity_type, entity_id, title?, content?}`
	 *                                    to create one. Read for no other change type.
	 * @return bool False when the change is missing, no longer pending, changed since the token was taken, could not be written, or is waiting for a price and was given none.
	 */
	public static function approve( int $change_id, int $reviewed_by, $notes, ?string $expected_token = null, ?int $set_cost = null, ?array $secret_choice = null ): bool {
		$savepoint = Transaction::begin( 'be_change_approve' );

		$change = Change::find_for_update( $change_id );
		if ( ! self::reviewable( $change, $expected_token ) ) {
			Transaction::rollback( $savepoint );
			return false;
		}

		Character::lock( (int) $change->character_id );
		$character = Character::find( (int) $change->character_id );
		if ( ! $character ) {
			Transaction::rollback( $savepoint );
			return false;
		}

		// A proposed catalog item writes a be_world_objects row and connects it to the proposing character.
		if ( $change->change_type === 'propose_world_object' ) {
			if ( ! self::create_proposed_object( $character, $change ) ) {
				Transaction::rollback( $savepoint );
				return false;
			}
			if ( ! Change::update_status( $change_id, 'approved', $reviewed_by, $notes ) ) {
				Transaction::rollback( $savepoint );
				return false;
			}
			Transaction::commit( $savepoint );
			return true;
		}

		// A proposed faction is not sheet data either.
		if ( $change->change_type === 'propose_faction' ) {
			if ( ! self::create_proposed_faction( $character, $change ) ) {
				Transaction::rollback( $savepoint );
				return false;
			}
			if ( ! Change::update_status( $change_id, 'approved', $reviewed_by, $notes ) ) {
				Transaction::rollback( $savepoint );
				return false;
			}
			Transaction::commit( $savepoint );
			return true;
		}

		// A logged knowledge claim writes the secret it belongs to - existing or new - and an approved reveal.
		if ( $change->change_type === 'log_knowledge' ) {
			if ( ! self::approve_log_knowledge( $change_id, $character, $change, $secret_choice ) ) {
				Transaction::rollback( $savepoint );
				return false;
			}
			if ( ! Change::update_status( $change_id, 'approved', $reviewed_by, $notes ) ) {
				Transaction::rollback( $savepoint );
				return false;
			}
			Transaction::commit( $savepoint );
			return true;
		}

		// A secret pass writes, or confirms, an approved reveal naming who told the recipient.
		if ( $change->change_type === 'pass_secret' ) {
			if ( ! self::approve_pass_secret( $character, $change ) ) {
				Transaction::rollback( $savepoint );
				return false;
			}
			if ( ! Change::update_status( $change_id, 'approved', $reviewed_by, $notes ) ) {
				Transaction::rollback( $savepoint );
				return false;
			}
			Transaction::commit( $savepoint );
			return true;
		}

		// A note shared from a visiting copy's own host has no sheet effect - approved, it lands on the character's
		// own plot as a Storytellers-only entry; refused, nothing is recorded.
		if ( $change->change_type === 'visit_note' ) {
			$plot_id = Character::ensure_plot( (int) $character->id );
			if ( $plot_id !== null ) {
				Plot_Entry::create( [
					'plot_id'    => $plot_id,
					'author_id'  => $reviewed_by,
					'entry_type' => 'note',
					'content'    => (string) ( $change->change_data['note'] ?? '' ),
					'audience'   => Plot_Entry::AUDIENCE_STORYTELLERS,
				] );
			}
			if ( ! Change::update_status( $change_id, 'approved', $reviewed_by, $notes ) ) {
				Transaction::rollback( $savepoint );
				return false;
			}
			Transaction::commit( $savepoint );
			return true;
		}

		// A host's own request to pair a player-submitted character with its real home - approved, this chronicle's
		// own outbound visit row is created, both keep-current flags on; refused, nothing is written and the host's
		// own copy stays as it already was: `keep_current` set, `keep_current_accepted` never agreed to.
		if ( $change->change_type === 'visit_pairing' ) {
			$data = (array) $change->change_data;
			$game = Game::find_by_slug( (string) ( $character->owner_slug ?? '' ) );
			if ( ! $game ) {
				Transaction::rollback( $savepoint );
				return false;
			}
			$visit_id = Transfer::create( [
				'character_uuid' => $character->uuid,
				'peer_uuid'      => (string) ( $data['host_uuid'] ?? '' ),
				'character_id'   => (int) $character->id,
				'character_name' => (string) $character->name,
				'direction'      => 'outbound',
				'state'          => 'visiting',
				'home_slug'      => (string) $game->slug,
				'home_site'      => home_url(),
				'home_chronicle' => (string) $game->name,
				'host_slug'      => (string) ( $data['host_slug'] ?? '' ),
				'host_site'      => (string) ( $data['host_site'] ?? '' ),
				'host_chronicle' => (string) ( $data['host_chronicle'] ?? '' ),
				'payload_hash'   => hash( 'sha256', $character->uuid . '|' . current_time( 'mysql', true ) ),
				'initiated_by'   => $reviewed_by,
				'keep_current'          => 1,
				'keep_current_accepted' => 1,
				'notes'          => __( 'Paired from a Grapevine file its player sent to the host chronicle.', 'beyond-elysium' ),
			] );
			if ( ! Change::update_status( $change_id, 'approved', $reviewed_by, $notes ) ) {
				Transaction::rollback( $savepoint );
				return false;
			}
			Transaction::commit( $savepoint );
			$visit = Transfer::find( $visit_id );
			if ( $visit !== null ) {
				Keep_Current::notify_pairing_accepted( $visit, (string) ( $data['host_uuid'] ?? '' ) );
			}
			return true;
		}

		// A change on a visiting copy whose own home keeps it current is forwarded there instead of applied here.
		$forwarding_visit = Keep_Current::inbound_kept_current_visit( (int) $character->id );
		if ( $forwarding_visit !== null ) {
			if ( ! Change::update_status( $change_id, 'forwarded', $reviewed_by, $notes ) ) {
				Transaction::rollback( $savepoint );
				return false;
			}
			Transaction::commit( $savepoint );
			Keep_Current::forward_change( $forwarding_visit, Change::find( $change_id ) );
			return true;
		}

		// A purchase with no price is priced here by the reviewer before anything is written.
		$priced_xp = null;
		if ( ! empty( $change->change_data['cost_pending'] ) ) {
			$priced = self::price_pending( $character, $change, $set_cost );
			if ( $priced === null || ! Change::update_xp_cost( $change_id, $priced['xp'], $priced['change_data'] ) ) {
				Transaction::rollback( $savepoint );
				return false;
			}
			$change->change_data = $priced['change_data'];
			$priced_xp           = $priced['xp'];
		}

		// Apply change to sheet_data.
		$new_sheet = self::apply_to_sheet( $character, $change );
		$written   = Character::update_sheet_data( (int) $character->id, $new_sheet );

		// Handle XP adjustments.
		$xp_cost = (float) ( $priced_xp ?? $change->xp_cost ?? 0 );
		if ( $change->change_type === 'xp_earn' ) {
			$amount  = (int) ( $change->change_data['amount'] ?? 0 );
			$written = $written && Character::update_xp( (int) $character->id, $amount, $amount );
		} elseif ( $change->change_type === 'xp_adjust' ) {
			$amount  = (int) ( $change->change_data['amount'] ?? 0 );
			$written = $written && Character::update_xp( (int) $character->id, $amount, $amount );
		} elseif ( $xp_cost > 0 ) {
			$written = $written && Character::update_xp( (int) $character->id, 0, -(int) $xp_cost );
		}

		// The sheet, XP and status change together or not at all.
		if ( ! $written || ! Change::update_status( $change_id, 'approved', $reviewed_by, $notes ) ) {
			Transaction::rollback( $savepoint );
			return false;
		}

		// Auto-snapshot every N approved changes.
		$approved_count = Change::count_for_character( (int) $character->id, [ 'status' => 'approved' ] );
		if ( $approved_count % self::SNAPSHOT_THRESHOLD === 0 ) {
			Snapshot::create( (int) $character->id, $change_id );
		}

		Transaction::commit( $savepoint );
		return true;
	}

	/**
	 * The change data and signed total once a Storyteller has priced a purchase that was waiting for one, or null when it
	 * cannot be priced.
	 *
	 * @param object   $character
	 * @param object   $change
	 * @param int|null $set_cost
	 * @return array{change_data:array<string,mixed>,xp:int}|null
	 */
	private static function price_pending( $character, $change, ?int $set_cost ): ?array {
		if ( $set_cost === null || $set_cost < 0 || $set_cost > Cost_Engine::MAX_CUSTOM_PRICE ) {
			return null;
		}
		$change_data = is_array( $change->change_data ) ? $change->change_data : [];
		$block_slug  = is_string( $change_data['block_slug'] ?? null ) ? $change_data['block_slug'] : '';
		if ( $block_slug === '' || ! is_array( $change_data['trait'] ?? null ) ) {
			return null;
		}

		$definition = self::block_definition( (string) ( $character->owner_slug ?? '' ), $block_slug );
		if ( ! is_object( $definition ) ) {
			return null;
		}

		$sheet = is_array( $character->sheet_data ) ? $character->sheet_data : [];
		return Cost_Engine::apply_set_price( $sheet, $definition, $block_slug, (string) $change->change_type, $change_data, $set_cost );
	}

	/**
	 * Approves every change in a set together, in one transaction: a removal or a lowered rating or pool first, then
	 * the rest. A removal's own xp_cost is never applied. Any failure rolls the whole set back - nothing partially
	 * applies.
	 *
	 * @param int[]                    $change_ids
	 * @param int                      $reviewed_by
	 * @param array<int,string>        $tokens      Optional per-change review_token(), keyed by change id.
	 * @param string|null              $notes
	 * @return bool
	 */
	public static function approve_group( array $change_ids, int $reviewed_by, array $tokens = [], $notes = null ): bool {
		if ( $change_ids === [] ) {
			return true;
		}

		$unit = Transaction::begin( 'be_change_approve_group' );

		$rows = [];
		foreach ( $change_ids as $id ) {
			$row = Change::find( (int) $id );
			if ( ! $row || $row->status !== 'pending' ) {
				Transaction::rollback( $unit );
				return false;
			}
			$rows[] = $row;
		}

		// Refunds (a negative or zero xp_cost) before charges.
		usort( $rows, static fn( $a, $b ) => ( (float) $a->xp_cost ) <=> ( (float) $b->xp_cost ) );

		foreach ( $rows as $row ) {
			$token = $tokens[ (int) $row->id ] ?? null;
			if ( ! self::approve( (int) $row->id, $reviewed_by, $notes, $token ) ) {
				Transaction::rollback( $unit );
				return false;
			}
		}

		Transaction::commit( $unit );
		return true;
	}

	/**
	 * Rejects a pending change.
	 *
	 * @param int         $change_id
	 * @param int         $reviewed_by
	 * @param string|null $notes
	 * @param string|null $expected_token
	 * @return bool
	 */
	public static function reject( int $change_id, int $reviewed_by, $notes, ?string $expected_token = null ): bool {
		$savepoint = Transaction::begin( 'be_change_reject' );

		$change = Change::find_for_update( $change_id );
		if ( ! self::reviewable( $change, $expected_token ) ) {
			Transaction::rollback( $savepoint );
			return false;
		}

		// An Immediate-mode secret pass already wrote an unapproved reveal at submit time; refusing removes it.
		if ( $change->change_type === 'pass_secret' ) {
			$data     = is_array( $change->change_data ) ? $change->change_data : [];
			$existing = Secret_Reveal::find_for( (int) ( $data['secret_id'] ?? 0 ), (int) ( $data['to_character_id'] ?? 0 ) );
			if ( $existing && empty( $existing->approved ) ) {
				Secret_Reveal::delete( (int) $existing->id );
			}
		}

		$ok = Change::update_status( $change_id, 'rejected', $reviewed_by, $notes );
		if ( ! $ok ) {
			Transaction::rollback( $savepoint );
			return false;
		}

		Transaction::commit( $savepoint );
		return true;
	}

	/**
	 * Whether a locked change row may be reviewed: it exists, is still pending.
	 *
	 * @param object|null $change
	 * @param string|null $expected_token
	 * @return bool
	 * @phpstan-assert-if-true object $change
	 */
	private static function reviewable( $change, ?string $expected_token ): bool {
		if ( ! $change || $change->status !== 'pending' ) {
			return false;
		}
		return $expected_token === null || hash_equals( Change::review_token( $change ), $expected_token );
	}

	/**
	 * Applies a change to a character's sheet_data and returns the updated array.
	 *
	 * @param object $character Character row with decoded sheet_data.
	 * @param object $change    Change row with decoded change_data.
	 * @return array Updated sheet_data.
	 */
	/**
	 * Writes an approved player proposal into the catalog and ties it to the character that proposed it.
	 *
	 * @param object $character The proposing character.
	 * @param object $change
	 * @return bool
	 */
	private static function create_proposed_object( $character, $change ): bool {
		$data = is_array( $change->change_data ) ? $change->change_data : [];

		$game = Game::find_by_slug( (string) $character->owner_slug );
		if ( ! $game ) {
			return false;
		}

		$object_id = World_Object::create( [
			'game_id'     => (int) $game->id,
			'object_type' => $data['object_type'] ?? '',
			'name'        => $data['name'] ?? '',
			'description' => $data['description'] ?? null,
			'limitations' => $data['limitations'] ?? null,
			'rarity'      => $data['rarity'] ?? null,
			'cost'        => $data['cost'] ?? null,
			'properties'  => is_array( $data['properties'] ?? null ) ? $data['properties'] : [],
			'created_by'  => (int) ( $change->submitted_by ?? 0 ),
		] );

		if ( ! $object_id ) {
			return false;
		}

		$connected = (bool) Connection::create( [
			'game_id'     => (int) $game->id,
			'source_type' => 'character',
			'source_id'   => (int) $character->id,
			'target_type' => 'world_object',
			'target_id'   => (int) $object_id,
			'label'       => 'owns',
		] );

		if ( $connected && ( $data['object_type'] ?? '' ) === 'item' ) {
			Item_Event::record( [
				'game_id'         => (int) $game->id,
				'world_object_id' => (int) $object_id,
				'event'           => 'proposed',
				'character_id'    => (int) $character->id,
				'recorded_by'     => (int) ( $change->submitted_by ?? 0 ),
			] );
		}

		return $connected;
	}

	/**
	 * Writes an approved faction proposal: the faction row itself (`active`, `audience = restricted`,
	 * `created_via_proposal = 1`).
	 *
	 * @param object $character The proposing character.
	 * @param object $change
	 * @return bool
	 */
	private static function create_proposed_faction( $character, $change ): bool {
		$data = is_array( $change->change_data ) ? $change->change_data : [];

		$game = Game::find_by_slug( (string) $character->owner_slug );
		if ( ! $game ) {
			return false;
		}

		$submitted_by = (int) ( $change->submitted_by ?? 0 );

		$faction_id = Faction::create( [
			'game_id'              => (int) $game->id,
			'name'                 => $data['name'] ?? '',
			'faction_type'         => $data['faction_type'] ?? 'other',
			'description'          => $data['description'] ?? null,
			'goals'                => $data['goals'] ?? null,
			'audience'             => 'restricted',
			'created_via_proposal' => true,
			'created_by'           => $submitted_by,
		] );

		if ( ! $faction_id ) {
			return false;
		}

		return (bool) Faction_Member::add( (int) $faction_id, (int) $character->id, $submitted_by, true );
	}

	/**
	 * Writes the secret a logged knowledge claim belongs to - an existing one chosen by the reviewer, or a new one
	 * built from the reviewer's own entity/title/content - and an approved reveal carrying the logged how and teller.
	 *
	 * @param int                      $change_id
	 * @param object                   $character
	 * @param object                   $change
	 * @param array<string,mixed>|null $secret_choice
	 * @return bool
	 */
	private static function approve_log_knowledge( int $change_id, $character, $change, ?array $secret_choice ): bool {
		if ( $secret_choice === null ) {
			return false;
		}

		$data = is_array( $change->change_data ) ? $change->change_data : [];
		$game = Game::find_by_slug( (string) $character->owner_slug );
		if ( ! $game ) {
			return false;
		}

		$secret_id = null;
		if ( ! empty( $secret_choice['secret_id'] ) ) {
			$secret = Secret::find( (int) $secret_choice['secret_id'] );
			if ( ! $secret || (int) $secret->game_id !== (int) $game->id ) {
				return false;
			}
			$secret_id = (int) $secret->id;
		} elseif ( ! empty( $secret_choice['entity_type'] ) && ! empty( $secret_choice['entity_id'] ) ) {
			$secret_id = (int) Secret::create( [
				'game_id'     => (int) $game->id,
				'entity_type' => (string) $secret_choice['entity_type'],
				'entity_id'   => (int) $secret_choice['entity_id'],
				'title'       => (string) ( $secret_choice['title'] ?? ( $data['title'] ?? '' ) ),
				'content'     => $secret_choice['content'] ?? ( $data['details'] ?? null ),
				'audience'    => 'restricted',
				'created_by'  => (int) ( $change->submitted_by ?? 0 ),
			] );
			if ( ! $secret_id ) {
				return false;
			}
		} elseif ( ! empty( $secret_choice['title'] ) ) {
			$secret_id = (int) Secret::create( [
				'game_id'    => (int) $game->id,
				'title'      => (string) $secret_choice['title'],
				'content'    => $secret_choice['content'] ?? ( $data['details'] ?? null ),
				'audience'   => 'restricted',
				'created_by' => (int) ( $change->submitted_by ?? 0 ),
			] );
			if ( ! $secret_id ) {
				return false;
			}
		}

		if ( ! $secret_id || Secret_Reveal::already_revealed( $secret_id, (int) $character->id ) ) {
			return false;
		}

		$how  = in_array( $data['how'] ?? null, Secret_Reveal::HOW_VALUES, true ) ? $data['how'] : 'other';
		$note = ! empty( $data['teller_character_id'] ) || empty( $data['teller_name'] )
			? ( $data['details'] ?? null )
			: sprintf( '%s: %s', $data['teller_name'], $data['details'] ?? '' );

		$reveal_id = Secret_Reveal::create( [
			'secret_id'         => $secret_id,
			'character_id'      => (int) $character->id,
			'how'               => $how,
			'note'              => $note,
			'from_character_id' => ! empty( $data['teller_character_id'] ) ? (int) $data['teller_character_id'] : null,
			'revealed_by'       => (int) ( $change->submitted_by ?? 0 ),
			'approved'          => true,
		] );
		if ( ! $reveal_id ) {
			return false;
		}

		$data['secret_id'] = $secret_id;
		return Change::update_change_data( $change_id, $data );
	}

	/**
	 * Writes, or confirms, the approved reveal a secret pass creates: an Immediate-mode pass already wrote an
	 * unapproved reveal at submit time and this only approves it; a Needs-a-Storyteller pass re-checks the teller
	 * still holds an approved reveal of the secret before writing one for the recipient.
	 *
	 * @param object $character The telling character - the change's own `character_id`.
	 * @param object $change
	 * @return bool
	 */
	private static function approve_pass_secret( $character, $change ): bool {
		$data      = is_array( $change->change_data ) ? $change->change_data : [];
		$secret_id = (int) ( $data['secret_id'] ?? 0 );
		$from_id   = (int) ( $data['from_character_id'] ?? 0 );
		$to_id     = (int) ( $data['to_character_id'] ?? 0 );
		if ( ! $secret_id || ! $from_id || ! $to_id || $from_id !== (int) $character->id ) {
			return false;
		}

		$existing = Secret_Reveal::find_for( $secret_id, $to_id );
		if ( $existing ) {
			// Immediate mode: the reveal already exists, unapproved. Approve it in place.
			return Secret_Reveal::update( (int) $existing->id, [ 'approved' => true ] );
		}

		// Needs a Storyteller: the teller must still hold an approved reveal of the secret.
		$teller_reveal = Secret_Reveal::find_for( $secret_id, $from_id );
		if ( ! $teller_reveal || empty( $teller_reveal->approved ) ) {
			return false;
		}

		return (bool) Secret_Reveal::create( [
			'secret_id'         => $secret_id,
			'character_id'      => $to_id,
			'how'               => 'told',
			'note'              => $data['note'] ?? null,
			'from_character_id' => $from_id,
			'revealed_by'       => (int) ( $change->submitted_by ?? 0 ),
			'approved'          => true,
		] );
	}

	/**
	 * The character's sheet data with one change applied; nothing is saved.
	 *
	 * @param object $character
	 * @param object $change
	 * @return array<string,mixed>
	 */
	public static function apply_to_sheet( $character, $change ): array {
		$sheet       = is_array( $character->sheet_data ) ? $character->sheet_data : [];
		$change_data = is_array( $change->change_data ) ? $change->change_data : [];
		$block_slug  = $change_data['block_slug'] ?? null;

		switch ( $change->change_type ) {
			case 'add_trait':
				if ( $block_slug ) {
					if ( ! isset( $sheet[ $block_slug ] ) || ! is_array( $sheet[ $block_slug ] ) ) {
						$sheet[ $block_slug ] = [];
					}
					$trait                  = $change_data['trait'] ?? $change_data;
					$sheet[ $block_slug ][] = $trait;

					$definition = self::block_definition( (string) ( $character->owner_slug ?? '' ), $block_slug );
					$cost       = self::spent_from_cost( $definition, $trait );
					if ( $cost !== null ) {
						$sheet = self::adjust_spent_dots( $sheet, $definition, $trait, $cost );
					}
				}
				break;

			case 'remove_trait':
				if ( $block_slug && isset( $sheet[ $block_slug ] ) && is_array( $sheet[ $block_slug ] ) ) {
					$trait      = is_array( $change_data['trait'] ?? null ) ? $change_data['trait'] : $change_data;
					$definition = self::block_definition( (string) ( $character->owner_slug ?? '' ), $block_slug );
					$previous   = is_array( $change_data['previous'] ?? null ) ? $change_data['previous'] : null;
					$identity   = Trait_Identity::target_of( $definition, $trait, $previous );
					if ( $identity !== null ) {
						$leaving = Trait_Identity::addressed_positions( $definition, $sheet[ $block_slug ], $identity, Trait_Identity::tradition_of_target( $trait, $previous ) );
						$sheet[ $block_slug ] = array_values(
							array_filter(
								array_values( $sheet[ $block_slug ] ),
								static fn( $item, int $position ): bool => ! in_array( $position, $leaving, true ),
								ARRAY_FILTER_USE_BOTH
							)
						);

						$cost = self::spent_from_cost( $definition, $trait );
						if ( $cost !== null ) {
							$sheet = self::adjust_spent_dots( $sheet, $definition, $trait, -$cost );
						}
					}
				}
				break;

			case 'modify_trait':
				if ( $block_slug && isset( $sheet[ $block_slug ] ) && is_array( $sheet[ $block_slug ] ) && is_array( $change_data['trait'] ?? null ) ) {
					$definition = self::block_definition( (string) ( $character->owner_slug ?? '' ), $block_slug );
					$previous   = is_array( $change_data['previous'] ?? null ) ? $change_data['previous'] : null;
					$identity   = Trait_Identity::target_of( $definition, $change_data['trait'], $previous );
					if ( $identity !== null ) {
						$sheet[ $block_slug ] = array_values( $sheet[ $block_slug ] );
						$addressed            = Trait_Identity::addressed_positions( $definition, $sheet[ $block_slug ], $identity, Trait_Identity::tradition_of_target( $change_data['trait'], $previous ) );
						if ( $addressed !== [] ) {
							$sheet[ $block_slug ][ $addressed[0] ] = array_merge( $sheet[ $block_slug ][ $addressed[0] ], $change_data['trait'] );
						}
					}
				}
				break;

			case 'modify_resource':
				if ( $block_slug ) {
					if ( ! isset( $sheet[ $block_slug ] ) || ! is_array( $sheet[ $block_slug ] ) ) {
						$sheet[ $block_slug ] = [];
					}
					$definition = self::block_definition( (string) ( $character->owner_slug ?? '' ), $block_slug );
					foreach ( (array) ( $change_data['values'] ?? [] ) as $pool_name => $new_value ) {
						$sheet = self::convert_temporary_for_raise( $sheet, $definition, $block_slug, (string) $pool_name, $sheet[ $block_slug ][ $pool_name ] ?? null, $new_value );
					}
					$sheet[ $block_slug ] = array_merge( $sheet[ $block_slug ], $change_data['values'] ?? [] );
				}
				break;

			case 'modify_identity':
				if ( $block_slug ) {
					if ( ! isset( $sheet[ $block_slug ] ) || ! is_array( $sheet[ $block_slug ] ) ) {
						$sheet[ $block_slug ] = [];
					}
					$sheet[ $block_slug ] = array_merge( $sheet[ $block_slug ], $change_data['fields'] ?? [] );
				}
				break;

			case 'pool_spend':
				if ( $block_slug ) {
					if ( ! isset( $sheet[ $block_slug ] ) || ! is_array( $sheet[ $block_slug ] ) ) {
						$sheet[ $block_slug ] = [];
					}
					$sheet[ $block_slug ][] = $change_data['trait'] ?? [];

					$pool_block = (string) ( $change_data['pool_block'] ?? '' );
					$pool_field = (string) ( $change_data['pool_field'] ?? '' );
					$amount     = (int) ( $change_data['amount'] ?? 0 );
					if ( $pool_block !== '' && $pool_field !== '' ) {
						if ( ! isset( $sheet[ $pool_block ] ) || ! is_array( $sheet[ $pool_block ] ) ) {
							$sheet[ $pool_block ] = [];
						}
						$current                            = (int) ( $sheet[ $pool_block ][ $pool_field ] ?? 0 );
						$sheet[ $pool_block ][ $pool_field ] = $current - $amount;
					}
				}
				break;

			case 'xp_earn':
			case 'xp_adjust':
			case 'import_note':
			case 'catalog_rekey':
			case 'catalog_rekey_revert':
				// No sheet_data change.
				break;
		}

		return $sheet;
	}

	/**
	 * The number of Virtue (or similar) dots a `spent_from` block's trait spends, when the trait names a real pick at
	 * a real rank - `null` for anything else, so the caller knows there is nothing to spend or free.
	 *
	 * @param object|null          $definition
	 * @param array<string,mixed>  $trait
	 */
	private static function spent_from_cost( $definition, array $trait ): ?int {
		$spent_from = $definition->_meta->spent_from ?? null;
		if ( $spent_from === null ) {
			return null;
		}
		$family_name = (string) ( $trait['name'] ?? '' );
		$power_name  = (string) ( $trait['power_name'] ?? '' );
		if ( $family_name === '' || $power_name === '' ) {
			return null;
		}
		$family = null;
		foreach ( ( $definition->powers ?? [] ) as $power ) {
			if ( isset( $power->name ) && $power->name === $family_name ) {
				$family = $power;
				break;
			}
		}
		if ( $family === null ) {
			return null;
		}
		foreach ( (array) ( $family->elder ?? [] ) as $rank_name => $picks ) {
			foreach ( (array) $picks as $pick ) {
				if ( isset( $pick->power_name ) && $pick->power_name === $power_name ) {
					$cost = $spent_from->rank_cost->{$rank_name} ?? null;
					return is_numeric( $cost ) ? (int) $cost : null;
				}
			}
		}
		return null;
	}

	/**
	 * Marks (or frees) dots spent in the pool a `spent_from` block's trait draws from, without lowering the pool's
	 * own rating - buying an edge never lowers a Virtue, and removing one never raises it.
	 *
	 * @param array<string,mixed> $sheet
	 * @param object|null         $definition
	 * @param array<string,mixed> $trait
	 * @param int                 $delta Positive to mark dots spent, negative to free them.
	 * @return array<string,mixed>
	 */
	private static function adjust_spent_dots( array $sheet, $definition, array $trait, int $delta ): array {
		$spent_from = $definition->_meta->spent_from ?? null;
		$field      = is_string( $spent_from->by_family_field ?? null ) ? $spent_from->by_family_field : '';
		$pool_block = is_string( $spent_from->pool_block ?? null ) ? $spent_from->pool_block : '';
		$family     = null;
		foreach ( ( $definition->powers ?? [] ) as $power ) {
			if ( isset( $power->name ) && $power->name === ( $trait['name'] ?? null ) ) {
				$family = $power;
				break;
			}
		}
		$pool_field = $family !== null && $field !== '' && is_string( $family->{$field} ?? null ) ? $family->{$field} : '';
		if ( $pool_block === '' || $pool_field === '' ) {
			return $sheet;
		}

		$current = $sheet[ $pool_block ][ $pool_field ] ?? null;
		$row     = is_array( $current ) ? $current : [ 'permanent' => (int) ( $current ?? 0 ) ];
		$row['spent'] = max( 0, (int) ( $row['spent'] ?? 0 ) + $delta );

		if ( ! isset( $sheet[ $pool_block ] ) || ! is_array( $sheet[ $pool_block ] ) ) {
			$sheet[ $pool_block ] = [];
		}
		$sheet[ $pool_block ][ $pool_field ] = $row;
		return $sheet;
	}

	/**
	 * Converts a `raised_by` pool's named source pool's temporary points when a change genuinely raises the pool's
	 * permanent rating by one dot - the source pool is never touched for a lowering, an unchanged value, or a pool
	 * with no `raised_by` rule.
	 *
	 * @param array<string,mixed> $sheet
	 * @param object|null         $definition
	 * @param mixed               $old_value
	 * @param mixed               $new_value
	 * @return array<string,mixed>
	 */
	private static function convert_temporary_for_raise( array $sheet, $definition, string $block_slug, string $pool_name, $old_value, $new_value ): array {
		$pool_def = null;
		foreach ( ( $definition->pools ?? [] ) as $pool ) {
			if ( isset( $pool->name ) && $pool->name === $pool_name ) {
				$pool_def = $pool;
				break;
			}
		}
		if ( $pool_def === null || ! isset( $pool_def->raised_by ) ) {
			return $sheet;
		}

		// A change without `raised_cost` (a Storyteller's direct override) charges nothing.
		if ( ! is_array( $new_value ) || ! isset( $new_value['raised_cost'] ) ) {
			return $sheet;
		}

		$old_permanent = is_array( $old_value ) ? (int) ( $old_value['permanent'] ?? ( $pool_def->default_start ?? 0 ) ) : (int) ( $old_value ?? ( $pool_def->default_start ?? 0 ) );
		$new_permanent = (int) ( $new_value['permanent'] ?? $old_permanent );
		if ( $new_permanent - $old_permanent !== 1 ) {
			return $sheet; // Validated elsewhere; apply_to_sheet only ever converts a genuine single-dot raise.
		}

		$raised_by = $pool_def->raised_by;
		[ $from_block, $from_field ] = array_pad( explode( '.', (string) ( $raised_by->from ?? '' ), 2 ), 2, '' );
		$needed = (int) ( $raised_by->temporary ?? 0 );
		if ( $from_block === '' || $from_field === '' || $needed < 1 ) {
			return $sheet;
		}

		$from_current = $sheet[ $from_block ][ $from_field ] ?? null;
		$from_row     = is_array( $from_current ) ? $from_current : [ 'permanent' => (int) ( $from_current ?? 0 ) ];
		$from_row['temporary'] = max( 0, (int) ( $from_row['temporary'] ?? 0 ) - $needed );

		if ( ! isset( $sheet[ $from_block ] ) || ! is_array( $sheet[ $from_block ] ) ) {
			$sheet[ $from_block ] = [];
		}
		$sheet[ $from_block ][ $from_field ] = $from_row;
		return $sheet;
	}

	/**
	 * Determines the approval level required for a change, and any citation explaining why: 'auto' or 'st', alongside an
	 * optional reason.
	 *
	 * @param object $character
	 * @param object $change    Plain object with change_type, change_data['block_slug'].
	 * @return array{level: string, reason: ?string} level is 'auto' | 'st'.
	 */
	public static function resolve_approval_level( $character, $change ): array {
		$resolved          = self::resolve_rule_level( $character, $change );
		$resolved['level'] = $resolved['level'] === 'auto' ? 'auto' : 'st';
		return $resolved;
	}

	/**
	 * The level the rules themselves resolve to: checks per-item and block-level approval rules for the affected trait,
	 * applies any game-level auto-approve setting, and returns the strictest level found, alongside an optional reason
	 * string.
	 *
	 * @param object $character
	 * @param object $change    Plain object with change_type, change_data['block_slug'].
	 * @return array{level: string, reason: ?string}
	 */
	private static function resolve_rule_level( $character, $change ): array {
		$change_data = is_array( $change->change_data ) ? $change->change_data : [];
		$block_slug  = $change_data['block_slug'] ?? null;

		// XP earn/adjust submitted by a narrator/ST defaults to auto.
		if ( in_array( $change->change_type, [ 'xp_earn', 'xp_adjust', 'import_note' ], true ) ) {
			if ( \BeyondElysium\Core\Authorization::can( 'be_manage_characters' ) ) {
				return [ 'level' => 'auto', 'reason' => null ];
			}
			return [ 'level' => 'st', 'reason' => null ];
		}

		// A proposed catalog item or faction, a logged knowledge claim, or a secret pass always waits for a Storyteller.
		if ( in_array( $change->change_type, [ 'propose_world_object', 'propose_faction', 'log_knowledge', 'pass_secret' ], true ) ) {
			return [ 'level' => 'st', 'reason' => null ];
		}

		// Unset until a rule decides.
		$level  = null;
		$reason = null;

		// A custom entry has no catalog price or rule of its own.
		if ( ! empty( $change_data['trait']['custom'] ) ) {
			$level = 'st';
		}

		if ( ! empty( $change_data['cost_pending'] ) ) {
			$level = 'st';
		}

		if ( $block_slug ) {
			// Prefer this character's chronicle-specific fork of the block, if one exists.
			$block = Purchase_Scope::widen( Schema_Block::find_for_game( $block_slug, (string) ( $character->owner_slug ?? '' ) ), (string) ( $character->owner_slug ?? '' ) );
			// $block->definition decodes as a plain object, not an associative array.
			if ( $block && $block->definition ) {
				$definition = $block->definition;
				$trait_name = $change_data['trait']['name'] ?? null;

				// Check per-item override first (trait_list blocks: Merits, Backgrounds, Abilities...).
				if ( $trait_name && ! empty( $definition->items ) ) {
					foreach ( $definition->items as $item ) {
						if ( ( $item->name ?? null ) === $trait_name ) {
							// Per-count schedule ("Occult 1-3 auto, 4-5 st") is more specific than the flat approval below.
							if ( ! empty( $item->approval_by_value ) ) {
								$new_count = $change_data['trait']['count'] ?? null;
								$range     = self::find_approval_range( $item->approval_by_value, $new_count );
								if ( $range ) {
									if ( ! empty( $range->reason ) ) {
										$reason = $range->reason;
									}
									return [ 'level' => self::strictest( $level, $range->approval ), 'reason' => $reason ];
								}
							}
							if ( ! empty( $item->reason ) ) {
								$reason = $item->reason;
								$level  = self::strictest( $level, 'st' );
							}
							if ( isset( $item->approval ) ) {
								return [ 'level' => self::strictest( $level, $item->approval ), 'reason' => $reason ];
							}
							break;
						}
					}
				}

				// Check the matched power's own level ladder (tiered_power blocks: Disciplines, Gifts, Arcanoi...).
				if ( $trait_name && ! empty( $definition->powers ) ) {
					// Compared as integers: a level sent as the string "5" is the same rung as 5.
					$held_level = isset( $change_data['trait']['level'] ) && is_numeric( $change_data['trait']['level'] ) ? (int) $change_data['trait']['level'] : null;
					foreach ( $definition->powers as $power ) {
						if ( ( $power->name ?? null ) !== $trait_name ) {
							continue;
						}
						if ( isset( $power->approval_override ) ) {
							$level = self::strictest( $level, $power->approval_override );
						}
						foreach ( $power->levels ?? [] as $rung ) {
							if ( ! isset( $rung->level ) || $held_level === null || (int) $rung->level !== $held_level ) {
								continue;
							}
							if ( ! empty( $rung->reason ) ) {
								$reason = $rung->reason;
								$level  = self::strictest( $level, 'st' );
							}
							// Each level is already its own catalog row.
							if ( isset( $rung->approval ) ) {
								$level = self::strictest( $level, $rung->approval );
							}
						}
						break;
					}
				}

				// Check the matched resource pool's own per-value schedule (Willpower, Blood, Rage...).
				if ( ! empty( $change_data['values'] ) && ! empty( $definition->pools ) ) {
					foreach ( (array) $change_data['values'] as $pool_name => $new_value ) {
						$permanent = is_array( $new_value ) ? ( $new_value['permanent'] ?? null ) : $new_value;
						foreach ( $definition->pools as $pool ) {
							if ( ( $pool->name ?? null ) !== $pool_name || empty( $pool->approval_by_value ) ) {
								continue;
							}
							$range = self::find_approval_range( $pool->approval_by_value, $permanent );
							if ( $range ) {
								if ( ! empty( $range->reason ) ) {
									$reason = $range->reason;
								}
								$level = self::strictest( $level, $range->approval );
							}
							break;
						}
					}
				}

				// Checks the matched identity field's own per-option schedule.
				if ( ! empty( $change_data['fields'] ) && ! empty( $definition->fields ) ) {
					foreach ( (array) $change_data['fields'] as $field_name => $new_value ) {
						$selected = is_array( $new_value ) ? $new_value : [ $new_value ];
						foreach ( $definition->fields as $field ) {
							if ( ( $field->name ?? null ) !== $field_name || empty( $field->approval_by_option ) ) {
								continue;
							}
							$schedule = (array) $field->approval_by_option;
							foreach ( $selected as $option ) {
								if ( ! is_string( $option ) || ! isset( $schedule[ $option ] ) ) {
									continue;
								}
								$entry = $schedule[ $option ];
								if ( ! empty( $entry->reason ) ) {
									$reason = $entry->reason;
								}
								if ( isset( $entry->approval ) ) {
									$level = self::strictest( $level, $entry->approval );
								}
							}
							break;
						}
					}
				}

				// Check block-level approval rules.
				if ( ! empty( $definition->approval_rules ) ) {
					$rules = $definition->approval_rules;
					$rule  = $rules->default ?? 'st';

					// tiered_power in-type/out-of-type is resolved via Cost_Engine, not client input.
					if ( $block->section_type === 'tiered_power' && isset( $rules->in_type ) ) {
						$in_type = $trait_name && Cost_Engine::is_in_type( $character, $block_slug, $trait_name );
						$rule    = $in_type ? ( $rules->in_type ?? $rule ) : ( $rules->out_of_type ?? $rule );
					}

					$level = self::strictest( $level, $rule );
				}
			}
		}

		// The chronicle's own reason, if any, comes first; every OWBN Character Bylaw attached to this entry
		// follows, one per line, on a chronicle with the switch on.
		$game = \BeyondElysium\Models\Game::find_by_slug( $character->owner_slug );
		if ( $block_slug && isset( $trait_name ) && $trait_name && $game && ( $game->settings->owbn_bylaws ?? false ) === true ) {
			$axis         = ! empty( $character->is_npc ) ? 'npc' : 'pc';
			$family       = Bylaws::family_of_block( $block_slug );
			$held_level   = isset( $change_data['trait']['level'] ) && is_numeric( $change_data['trait']['level'] ) ? (int) $change_data['trait']['level'] : null;
			$new_count    = isset( $change_data['trait']['count'] ) && is_numeric( $change_data['trait']['count'] ) ? (int) $change_data['trait']['count'] : null;
			// A purchase named by its own power_name, rather than a numbered level, is exactly how this engine
			// already represents an Elder-and-above pick (Cost_Engine's own 'elder_pick' pricing basis).
			$is_named_pick = ! empty( $change_data['trait']['power_name'] );
			$bylaw_rules  = Bylaws::rules_for( $family, $trait_name, $held_level, $is_named_pick, $new_count );
			$bylaw_reason = Bylaw_Reason::format_all( $bylaw_rules, $axis, $trait_name );
			if ( $bylaw_reason !== null ) {
				$reason = $reason !== null ? ( $reason . "\n" . $bylaw_reason ) : $bylaw_reason;
				$level  = self::strictest( $level, 'st' );
			}
		}

		// Falls back to the chronicle's own default approval setting.
		$chronicle_default = ( $game && ( $game->settings->auto_approve ?? false ) === true ) ? 'auto' : 'st';
		$level = $level ?? $chronicle_default;

		return [ 'level' => $level, 'reason' => $reason ];
	}

	/**
	 * Awards XP to multiple characters in one call.
	 *
	 * @param array<int,int> $character_ids
	 * @param int            $amount
	 * @param string         $reason
	 * @param int            $awarded_by
	 * @return int Number of awards successfully created.
	 */
	public static function bulk_award_xp( array $character_ids, int $amount, string $reason, int $awarded_by ): int {
		$count = 0;
		foreach ( $character_ids as $character_id ) {
			$character_id = (int) $character_id;
			if ( ! Character::find( $character_id ) ) {
				continue;
			}

			$savepoint = Transaction::begin( 'be_bulk_award_xp' );
			$visit     = Keep_Current::inbound_kept_current_visit( $character_id );
			$change_id = Change::create( [
				'character_id' => $character_id,
				'change_type'  => 'xp_earn',
				'category'     => 'experience',
				'change_data'  => [
					'amount' => $amount,
					'reason' => $reason,
				],
				'xp_cost'      => 0,
				'status'       => $visit !== null ? 'forwarded' : 'approved',
				'submitted_by' => $awarded_by,
				'notes'        => $reason,
			] );

			if ( ! $change_id ) {
				Transaction::rollback( $savepoint );
				continue;
			}

			if ( $visit === null && ! Character::update_xp( $character_id, $amount, $amount ) ) {
				Transaction::rollback( $savepoint );
				continue;
			}

			Transaction::commit( $savepoint );
			if ( $visit !== null ) {
				Keep_Current::forward_change( $visit, Change::find( $change_id ) );
			}
			$count++;
		}
		return $count;
	}

	/**
	 * Applies one XP award or correction to a character, with a shared reason recorded against it. A positive amount
	 * writes an approved xp_earn change; a negative amount writes an approved xp_adjust change and refuses rather than
	 * taking XP Earned below zero.
	 *
	 * @param int    $character_id
	 * @param int    $amount A whole number, never zero.
	 * @param string $reason
	 * @param int    $applied_by
	 * @return array{applied: bool, code?: string, message?: string, amount?: int, xp_earned?: int, xp_unspent?: int}
	 */
	public static function apply_xp( int $character_id, int $amount, string $reason, int $applied_by ): array {
		$savepoint = Transaction::begin( 'be_apply_xp' );

		Character::lock( $character_id );
		$character = Character::find( $character_id );
		if ( ! $character ) {
			Transaction::rollback( $savepoint );
			return [
				'applied' => false,
				'code'    => 'not_found',
				'message' => __( 'That character no longer exists.', 'beyond-elysium' ),
			];
		}

		if ( $amount < 0 && (int) $character->xp_earned + $amount < 0 ) {
			Transaction::rollback( $savepoint );
			return [
				'applied' => false,
				'code'    => 'below_zero',
				'message' => __( 'That would take XP Earned below zero.', 'beyond-elysium' ),
			];
		}

		$visit     = Keep_Current::inbound_kept_current_visit( $character_id );
		$change_id = Change::create( [
			'character_id' => $character_id,
			'change_type'  => $amount > 0 ? 'xp_earn' : 'xp_adjust',
			'category'     => 'experience',
			'change_data'  => [
				'amount' => $amount,
				'reason' => $reason,
			],
			'xp_cost'      => 0,
			'status'       => $visit !== null ? 'forwarded' : 'approved',
			'submitted_by' => $applied_by,
			'notes'        => $reason,
		] );

		if ( ! $change_id || ( $visit === null && ! Character::update_xp( $character_id, $amount, $amount ) ) ) {
			Transaction::rollback( $savepoint );
			return [
				'applied' => false,
				'code'    => 'write_failed',
				'message' => __( 'The award could not be saved.', 'beyond-elysium' ),
			];
		}

		Transaction::commit( $savepoint );

		if ( $visit !== null ) {
			Keep_Current::forward_change( $visit, Change::find( $change_id ) );
			return [
				'applied'   => true,
				'forwarded' => true,
				'amount'    => $amount,
				'xp_earned' => (int) $character->xp_earned,
				'xp_unspent' => (int) $character->xp_unspent,
			];
		}

		$updated = Character::find( $character_id );
		return [
			'applied'    => true,
			'amount'     => $amount,
			'xp_earned'  => (int) ( $updated->xp_earned ?? 0 ),
			'xp_unspent' => (int) ( $updated->xp_unspent ?? 0 ),
		];
	}

	/**
	 * Grants one trait_list item to a character, paid for from the resource pool its block names in `_meta.paid_from`
	 * rather than the character's own XP - a Storyteller-only action, never reachable through the ordinary purchase
	 * flow.
	 *
	 * @return array{ok: bool, error?: string, change_id?: int}
	 */
	public static function spend_pool_on_trait( int $character_id, string $block_slug, string $name, int $spent_by ): array {
		$character = Character::find( $character_id );
		if ( ! $character ) {
			return [ 'ok' => false, 'error' => 'not_found' ];
		}

		$definition = self::block_definition( (string) ( $character->owner_slug ?? '' ), $block_slug );
		$paid_from  = is_object( $definition ) ? ( $definition->_meta->paid_from ?? null ) : null;
		if ( ! is_string( $paid_from ) || $paid_from === '' ) {
			return [ 'ok' => false, 'error' => 'not_pool_funded' ];
		}
		[ $pool_block, $pool_field ] = array_pad( explode( '.', $paid_from, 2 ), 2, '' );
		if ( $pool_block === '' || $pool_field === '' ) {
			return [ 'ok' => false, 'error' => 'not_pool_funded' ];
		}

		$item = Trait_Alias_Resolver::find_item_by_name( (array) ( $definition->items ?? [] ), $name );
		if ( $item === null ) {
			return [ 'ok' => false, 'error' => 'unknown_item' ];
		}
		$amount = Cost_Engine::price_item_cost( (string) ( $item->cost ?? '0' ), null );

		$sheet    = is_array( $character->sheet_data ) ? $character->sheet_data : [];
		$pool_raw = $sheet[ $pool_block ][ $pool_field ] ?? 0;
		$balance  = is_array( $pool_raw ) ? (int) ( $pool_raw['permanent'] ?? 0 ) : (int) $pool_raw;
		if ( $balance < $amount ) {
			return [ 'ok' => false, 'error' => 'insufficient_balance' ];
		}

		$change_id = Change::create( [
			'character_id' => $character_id,
			'change_type'  => 'pool_spend',
			'category'     => $block_slug,
			'change_data'  => [
				'block_slug' => $block_slug,
				'trait'      => [ 'name' => $item->name ?? $name ],
				'pool_block' => $pool_block,
				'pool_field' => $pool_field,
				'amount'     => $amount,
			],
			'xp_cost'      => 0,
			'status'       => 'pending',
			'submitted_by' => $spent_by,
		] );
		if ( ! $change_id ) {
			return [ 'ok' => false, 'error' => 'change_failed' ];
		}

		if ( ! self::approve( $change_id, $spent_by, null ) ) {
			return [ 'ok' => false, 'error' => 'approve_failed' ];
		}

		return [ 'ok' => true, 'change_id' => $change_id ];
	}

	/**
	 * Returns the stricter of two approval levels.
	 *
	 * @param ?string $a Null means "nothing has applied yet" - returns `$b` outright.
	 * @param string  $b
	 * @return string
	 */
	private static function strictest( ?string $a, string $b ): string {
		if ( $a === null ) {
			return $b;
		}
		$order = [ 'auto' => 0, 'st' => 1 ];
		$a_val = $order[ $a ] ?? 1;
		$b_val = $order[ $b ] ?? 1;
		return $a_val >= $b_val ? $a : $b;
	}

	/**
	 * Finds the first `{from, to, approval, reason?}` range covering `$value` (inclusive both ends) in an
	 * `approval_by_value` schedule.
	 *
	 * @param array<int,object> $ranges
	 * @param mixed             $value
	 * @return object|null
	 */
	private static function find_approval_range( array $ranges, $value ): ?object {
		if ( ! is_numeric( $value ) ) {
			return null;
		}
		foreach ( $ranges as $range ) {
			if ( isset( $range->from, $range->to, $range->approval ) && $value >= $range->from && $value <= $range->to ) {
				return $range;
			}
		}
		return null;
	}
}
