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
	 * The change types that alter a sheet or XP and so can be undone.
	 *
	 * @var string[]
	 */
	public const UNDOABLE_TYPES = [
		'add_trait',
		'remove_trait',
		'modify_trait',
		'modify_resource',
		'modify_identity',
		'pool_spend',
		'pool_permanent_spent',
		'xp_earn',
		'xp_adjust',
	];

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
			self::approve( $change_id, $submitted_by, null, null, null, null, false );
		}

		return $change_id;
	}

	/**
	 * Submits one change, and for a player refuses it - writing nothing - when its cost is more than the character
	 * has free to spend: unspent XP less what the character's other waiting changes hold.
	 *
	 * @param int                 $character_id
	 * @param array<string,mixed> $change        As submit()'s `$change_data`.
	 * @param int                 $submitted_by
	 * @param bool                $guard_xp      True for a player; a Storyteller's own change is never refused for its cost.
	 * @return array{change_id:int,refused:?array{cost:int,free:int,held:int}} A `change_id` of 0 with no `refused` is a failed write.
	 */
	public static function submit_checked( int $character_id, array $change, int $submitted_by, bool $guard_xp ): array {
		$written = self::within_budget(
			$character_id,
			[ $change ],
			$guard_xp,
			static function () use ( $character_id, $change, $submitted_by ): array {
				$change_id = self::submit( $character_id, $change, $submitted_by );
				return [ 'ok' => $change_id > 0, 'ids' => [ $change_id ] ];
			}
		);
		return [ 'change_id' => $written['ok'] ? (int) $written['ids'][0] : 0, 'refused' => $written['refused'] ];
	}

	/**
	 * Runs a write of changes under the character's row lock, and rolls every write back when `$guard_xp` is set and
	 * the writes leave the character with less than nothing free to spend (unspent XP less what waiting changes hold),
	 * having made it worse. A set that costs nothing is never refused.
	 *
	 * @param int                              $character_id
	 * @param array<int,array<string,mixed>>   $changes  The proposed changes, as submit()'s `$change_data`.
	 * @param bool                             $guard_xp
	 * @param callable():array{ok:bool,ids:int[]} $write
	 * @return array{ok:bool,ids:int[],refused:?array{cost:int,free:int,held:int}}
	 */
	private static function within_budget( int $character_id, array $changes, bool $guard_xp, callable $write ): array {
		$unit = Transaction::begin( 'be_changes_within_budget' );
		Character::lock( $character_id );
		$before = self::xp_position( $character_id );

		$result = $write();
		if ( ! $result['ok'] ) {
			Transaction::rollback( $unit );
			return [ 'ok' => false, 'ids' => [], 'refused' => null ];
		}

		$refused = $guard_xp ? self::budget_refusal( $character_id, $before, $changes ) : null;
		if ( $refused !== null ) {
			Transaction::rollback( $unit );
			return [ 'ok' => false, 'ids' => [], 'refused' => $refused ];
		}

		Transaction::commit( $unit );
		return [ 'ok' => true, 'ids' => $result['ids'], 'refused' => null ];
	}

	/**
	 * A character's unspent XP and the XP its waiting changes hold.
	 *
	 * @return array{unspent:int,held:int}
	 */
	private static function xp_position( int $character_id ): array {
		$character = Character::find( $character_id );
		return [
			'unspent' => (int) ( $character->xp_unspent ?? 0 ),
			'held'    => Change::held_xp( $character_id ),
		];
	}

	/**
	 * What a set of just-written changes costs and what was free before it, when it left the character worse off than
	 * nothing free to spend; null when it is within budget.
	 *
	 * @param array{unspent:int,held:int}    $before  The position before the writes.
	 * @param array<int,array<string,mixed>> $changes The proposed changes.
	 * @return array{cost:int,free:int,held:int}|null
	 */
	private static function budget_refusal( int $character_id, array $before, array $changes ): ?array {
		$cost = 0.0;
		foreach ( $changes as $change ) {
			$price = (float) ( $change['xp_cost'] ?? 0 );
			if ( $price > 0 ) {
				$cost += $price;
			}
		}
		if ( $cost <= 0 ) {
			return null;
		}

		$after       = self::xp_position( $character_id );
		$free_before = $before['unspent'] - $before['held'];
		$free_after  = $after['unspent'] - $after['held'];
		if ( $free_after >= 0 || $free_after >= $free_before ) {
			return null;
		}

		return [ 'cost' => (int) ceil( $cost ), 'free' => max( 0, $free_before ), 'held' => $before['held'] ];
	}

	/**
	 * The XP a character's waiting changes hold once a set of proposed changes is submitted: a proposed change that
	 * names a still-waiting change's own holding replaces it, so what that one held is released.
	 *
	 * @param object                         $character
	 * @param array<int,array<string,mixed>> $changes   Each with `change_type` and `change_data`.
	 */
	public static function held_xp_after( object $character, array $changes ): int {
		$owner_slug = (string) ( $character->owner_slug ?? '' );
		$replaced   = [];
		foreach ( $changes as $change ) {
			$type = (string) ( $change['change_type'] ?? '' );
			$key  = self::pending_duplicate_key( $type, (array) ( $change['change_data'] ?? [] ), $owner_slug );
			if ( $key === null ) {
				continue;
			}
			$existing = self::find_pending_duplicate( (int) $character->id, $type, $key, $owner_slug );
			if ( $existing !== null ) {
				$replaced[] = $existing;
			}
		}
		return Change::held_xp( (int) $character->id, $replaced );
	}

	/**
	 * What approving a waiting change would charge beyond the XP its character has unspent, or null when the charge
	 * can be paid or is not known yet.
	 *
	 * @param object   $change    A change row.
	 * @param int|null $set_cost  The Storyteller's price for a purchase that was waiting for one.
	 * @return array{cost:int,unspent:int,name:string}|null
	 */
	public static function approval_shortfall( object $change, ?int $set_cost = null ): ?array {
		$character = Character::find( (int) $change->character_id );
		if ( ! $character || in_array( $change->change_type, [ 'xp_earn', 'xp_adjust' ], true ) ) {
			return null;
		}

		$cost = (float) ( $change->xp_cost ?? 0 );
		if ( ! empty( $change->change_data['cost_pending'] ) ) {
			if ( $set_cost === null ) {
				return null;
			}
			$priced = self::price_pending( $character, $change, $set_cost );
			$cost   = $priced === null ? 0.0 : (float) $priced['xp'];
		}

		if ( ! self::exceeds_unspent( $character, $change->change_type, $cost ) ) {
			return null;
		}
		return [ 'cost' => (int) ceil( $cost ), 'unspent' => (int) $character->xp_unspent, 'name' => (string) $character->name ];
	}

	/**
	 * Whether charging a change's cost would take more than the character has unspent. XP awards and adjustments are
	 * never a charge.
	 *
	 * @param object $character
	 */
	private static function exceeds_unspent( $character, string $change_type, float $cost ): bool {
		if ( $cost <= 0 || in_array( $change_type, [ 'xp_earn', 'xp_adjust' ], true ) ) {
			return false;
		}
		return $cost > (float) ( $character->xp_unspent ?? 0 );
	}

	/**
	 * Submits a whole set of changes from the editor as one transaction, under one shared submission id. On a chronicle
	 * with `settings.approval_on_removal` on, any change in the set that `catches_removal_rule()` catches forces every
	 * change in the set to wait for a Storyteller, with a shared reason - never decided from what the client sent.
	 *
	 * @param int                      $character_id
	 * @param array<int,array<string,mixed>> $changes Each shaped as submit()'s own `$change_data`.
	 * @param int                      $submitted_by
	 * @param bool                     $guard_xp True for a player: a set whose cost is more than the character has free
	 *                                          to spend is refused whole, with `refused` naming the figures.
	 * @return array{submission_id:?string,change_ids:int[],refused:?array{cost:int,free:int,held:int}} An empty
	 *                                           `change_ids` means the whole set failed and nothing was written.
	 */
	public static function submit_set( int $character_id, array $changes, int $submitted_by, bool $guard_xp = false ): array {
		if ( $changes === [] ) {
			return [ 'submission_id' => null, 'change_ids' => [], 'refused' => null ];
		}

		$character = Character::find( $character_id );
		if ( ! $character ) {
			return [ 'submission_id' => null, 'change_ids' => [], 'refused' => null ];
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
		$written       = self::within_budget(
			$character_id,
			$changes,
			$guard_xp,
			static function () use ( $character_id, $changes, $submitted_by, $submission_id, $any_caught ): array {
				$change_ids = [];
				foreach ( $changes as $change ) {
					$change['submission_id'] = $submission_id;
					if ( $any_caught ) {
						$change['force_level']  = 'st';
						$change['force_reason'] = __( 'Part of a change that removes, lowers or renames something.', 'beyond-elysium' );
					}
					$change_id = self::submit( $character_id, $change, $submitted_by );
					if ( ! $change_id ) {
						return [ 'ok' => false, 'ids' => [] ];
					}
					$change_ids[] = $change_id;
				}
				return [ 'ok' => true, 'ids' => $change_ids ];
			}
		);

		if ( ! $written['ok'] ) {
			return [ 'submission_id' => null, 'change_ids' => [], 'refused' => $written['refused'] ];
		}
		return [ 'submission_id' => $submission_id, 'change_ids' => $written['ids'], 'refused' => null ];
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
	 * The id of another pending change on this character that targets the same holding or field as the given change.
	 *
	 * @param int                 $character_id
	 * @param string              $change_type
	 * @param array<string,mixed> $change_data
	 * @param string              $owner_slug  The character's chronicle.
	 * @param int                 $except_id   A change that never counts as a duplicate of itself.
	 * @return int|null
	 */
	public static function find_other_pending_duplicate( int $character_id, string $change_type, array $change_data, string $owner_slug, int $except_id ): ?int {
		$key = self::pending_duplicate_key( $change_type, $change_data, $owner_slug );
		if ( $key === null ) {
			return null;
		}
		foreach ( Change::for_character( $character_id, [ 'status' => 'pending', 'change_type' => $change_type ] ) as $candidate ) {
			if ( (int) $candidate->id !== $except_id && self::pending_duplicate_key( $change_type, (array) ( $candidate->change_data ?? [] ), $owner_slug ) === $key ) {
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
	public static function block_definition( string $owner_slug, string $block_slug ) {
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
	 * @param bool        $enforce_floor  Whether a charge above the character's unspent XP refuses the approval.
	 * @return bool False when the change is missing, no longer pending, changed since the token was taken, could not be written, is waiting for a price and was given none, or costs more than the character has unspent.
	 */
	public static function approve( int $change_id, int $reviewed_by, $notes, ?string $expected_token = null, ?int $set_cost = null, ?array $secret_choice = null, bool $enforce_floor = true ): bool {
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

		// A permanent spend lowers one pool's permanent rating by one, the change itself costing nothing.
		if ( $change->change_type === 'pool_permanent_spent' ) {
			if ( ! self::apply_permanent_spend( $character, $change ) ) {
				Transaction::rollback( $savepoint );
				return false;
			}
			if ( ! Change::update_status( $change_id, 'approved', $reviewed_by, $notes ) ) {
				Transaction::rollback( $savepoint );
				return false;
			}
			self::keep_undo_record( $change_id, $character, 0, 0 );
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

		// A charge the character cannot pay is never approved: unspent XP does not go below zero.
		if ( $enforce_floor && self::exceeds_unspent( $character, (string) $change->change_type, (float) ( $priced_xp ?? $change->xp_cost ?? 0 ) ) ) {
			Transaction::rollback( $savepoint );
			return false;
		}

		// A homebrew entry a Storyteller ticked joins the chronicle's catalog, and lands on the sheet as that entry.
		$tick = is_array( $change->change_data['st_catalog'] ?? null ) ? $change->change_data['st_catalog'] : null;
		if ( $tick !== null && $change->change_type === 'add_trait' ) {
			$carried = Catalog_Entry::add_from_change( (string) ( $character->owner_slug ?? '' ), (array) $change->change_data, $tick );
			if ( $carried === null ) {
				Transaction::rollback( $savepoint );
				return false;
			}
			$change->change_data = $carried;
		}

		// Apply change to sheet_data.
		$new_sheet = self::apply_to_sheet( $character, $change );
		$written   = Character::update_sheet_data( (int) $character->id, $new_sheet );

		// Handle XP adjustments.
		$xp_cost = (float) ( $priced_xp ?? $change->xp_cost ?? 0 );
		$earned  = 0;
		$unspent = 0;
		if ( $change->change_type === 'xp_earn' || $change->change_type === 'xp_adjust' ) {
			$earned  = (int) ( $change->change_data['amount'] ?? 0 );
			$unspent = $earned;
			$written = $written && Character::update_xp( (int) $character->id, $earned, $unspent );
		} elseif ( $xp_cost > 0 ) {
			$unspent = -(int) $xp_cost;
			$written = $written && Character::update_xp( (int) $character->id, 0, $unspent );
		}

		// The sheet, XP and status change together or not at all.
		if ( ! $written || ! Change::update_status( $change_id, 'approved', $reviewed_by, $notes ) ) {
			Transaction::rollback( $savepoint );
			return false;
		}

		// What the change did is kept, so a Storyteller can undo it.
		if ( in_array( (string) $change->change_type, self::UNDOABLE_TYPES, true ) ) {
			self::keep_undo_record( $change_id, $character, $earned, $unspent );
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

		self::discard_waiting_effects( $change );

		$ok = Change::update_status( $change_id, 'rejected', $reviewed_by, $notes );
		if ( ! $ok ) {
			Transaction::rollback( $savepoint );
			return false;
		}

		Transaction::commit( $savepoint );
		return true;
	}

	/**
	 * Withdraws a change that is still waiting: nothing is applied, and the XP it held is free again.
	 *
	 * @param int $change_id
	 * @param int $withdrawn_by
	 * @return bool False when the change is missing or no longer waiting.
	 */
	public static function withdraw( int $change_id, int $withdrawn_by ): bool {
		$savepoint = Transaction::begin( 'be_change_withdraw' );

		$change = Change::find_for_update( $change_id );
		if ( ! self::reviewable( $change, null ) ) {
			Transaction::rollback( $savepoint );
			return false;
		}

		self::discard_waiting_effects( $change );

		if ( ! Change::update_status( $change_id, 'withdrawn', $withdrawn_by, null ) ) {
			Transaction::rollback( $savepoint );
			return false;
		}

		Transaction::commit( $savepoint );
		return true;
	}

	/**
	 * Withdraws every change of one submission that is still waiting, together or not at all.
	 *
	 * @param int    $character_id
	 * @param string $submission_id
	 * @param int    $withdrawn_by
	 * @return int[] The ids withdrawn; empty when none was waiting.
	 */
	public static function withdraw_waiting( int $character_id, string $submission_id, int $withdrawn_by ): array {
		$unit = Transaction::begin( 'be_change_withdraw_set' );

		$ids = [];
		foreach ( Change::waiting_in_submission( $character_id, $submission_id ) as $change ) {
			if ( ! self::withdraw( (int) $change->id, $withdrawn_by ) ) {
				Transaction::rollback( $unit );
				return [];
			}
			$ids[] = (int) $change->id;
		}

		Transaction::commit( $unit );
		return $ids;
	}

	/**
	 * Undoes what a change wrote when it was submitted, for one that will never be applied: an Immediate-mode secret
	 * pass already wrote an unapproved reveal, which leaving the change unapplied removes.
	 *
	 * @param object $change
	 */
	private static function discard_waiting_effects( $change ): void {
		if ( $change->change_type !== 'pass_secret' ) {
			return;
		}
		$data     = is_array( $change->change_data ) ? $change->change_data : [];
		$existing = Secret_Reveal::find_for( (int) ( $data['secret_id'] ?? 0 ), (int) ( $data['to_character_id'] ?? 0 ) );
		if ( $existing && empty( $existing->approved ) ) {
			Secret_Reveal::delete( (int) $existing->id );
		}
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
	 * What the rules alone decide for a change: 'st' with its reason when an item, level, value, option, block, in- or
	 * out-of-type rule, a custom entry or a bylaw clause says a Storyteller must approve it, and null otherwise. The
	 * chronicle's default never applies, so a chronicle that reviews everything by default gives null for a pick no rule
	 * names.
	 *
	 * @param object               $character
	 * @param object               $change    Plain object with change_type and change_data.
	 * @param array<string,mixed>  $context   Blocks (`blocks`), the creature stack (`stack`) and the game (`game`) already loaded by the caller; filled in as it goes.
	 * @return array{level: string, reason: ?string}|null
	 */
	public static function explicit_approval( $character, $change, array &$context = [] ): ?array {
		$resolved = self::resolve_rule_level( $character, $change, false, $context );
		return $resolved['level'] === 'st' ? [ 'level' => 'st', 'reason' => $resolved['reason'] ] : null;
	}

	/**
	 * The level the rules themselves resolve to: checks per-item and block-level approval rules for the affected trait,
	 * applies any game-level auto-approve setting, and returns the strictest level found, alongside an optional reason
	 * string.
	 *
	 * @param object               $character
	 * @param object               $change        Plain object with change_type, change_data['block_slug'].
	 * @param bool                 $apply_default Whether the chronicle's own default fills in a level no rule decided.
	 * @param array<string,mixed>  $context       Blocks, creature stack and game the caller already loaded.
	 * @return array{level: ?string, reason: ?string}
	 */
	private static function resolve_rule_level( $character, $change, bool $apply_default = true, array &$context = [] ): array {
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
			$block = $context['blocks'][ $block_slug ] ?? Purchase_Scope::widen( Schema_Block::find_for_game( $block_slug, (string) ( $character->owner_slug ?? '' ) ), (string) ( $character->owner_slug ?? '' ) );
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

				// A pool raised past the most the book allows waits for a Storyteller.
				if ( ! empty( $change_data['values'] ) && ! empty( $definition->pools ) ) {
					foreach ( (array) $change_data['values'] as $pool_name => $new_value ) {
						foreach ( $definition->pools as $pool ) {
							if ( ( $pool->name ?? null ) !== $pool_name || ! isset( $pool->book_max ) ) {
								continue;
							}
							$permanent = self::pool_permanent_of( $new_value, null );
							$held      = self::pool_permanent_of( ( (array) ( $character->sheet_data ?? [] ) )[ $block_slug ][ $pool_name ] ?? null, (int) ( $pool->default_start ?? 0 ) );
							if ( $permanent !== null && $permanent > $held && $permanent > (int) $pool->book_max ) {
								$book_reason = sprintf(
									/* translators: 1: a pool's name (Balance), 2: the most the book allows */
									__( "%1\$s is above the book's maximum of %2\$d.", 'beyond-elysium' ),
									(string) $pool_name,
									(int) $pool->book_max
								);
								$reason = $reason !== null ? $reason . "\n" . $book_reason : $book_reason;
								$level  = self::strictest( $level, 'st' );
							}
							break;
						}
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
						if ( ! isset( $context['in_type'][ $block_slug ] ) ) {
							$context['in_type'][ $block_slug ] = Cost_Engine::in_type_check( $character, $block_slug, $context['stack'] ?? null, $context['blocks'] ?? [] );
						}
						$in_type = $trait_name && $context['in_type'][ $block_slug ]( $trait_name );
						$rule    = $in_type ? ( $rules->in_type ?? $rule ) : ( $rules->out_of_type ?? $rule );
					}

					$level = self::strictest( $level, $rule );
				}
			}
		}

		// The chronicle's own reason, if any, comes first; every OWBN Character Bylaw attached to this entry
		// follows, one per line, on a chronicle with the switch on.
		$game = $context['game'] ?? \BeyondElysium\Models\Game::find_by_slug( $character->owner_slug );
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

		// A pool raised to a rating an OWBN Character Bylaw names follows that clause too.
		if ( $block_slug && ! empty( $change_data['values'] ) && $game && ( $game->settings->owbn_bylaws ?? false ) === true ) {
			$axis   = ! empty( $character->is_npc ) ? 'npc' : 'pc';
			$family = Bylaws::family_of_block( $block_slug );
			foreach ( (array) $change_data['values'] as $pool_name => $new_value ) {
				$permanent = self::pool_permanent_of( $new_value, null );
				$held      = self::pool_permanent_of( ( (array) ( $character->sheet_data ?? [] ) )[ $block_slug ][ $pool_name ] ?? null, 0 );
				if ( $permanent === null || $permanent <= (int) $held ) {
					continue;
				}
				$pool_reason = Bylaw_Reason::format_all( Bylaws::rules_for( $family, (string) $pool_name, null, false, $permanent ), $axis, (string) $pool_name );
				if ( $pool_reason !== null ) {
					$reason = $reason !== null ? $reason . "\n" . $pool_reason : $pool_reason;
					$level  = self::strictest( $level, 'st' );
				}
			}
		}

		// Falls back to the chronicle's own default approval setting.
		if ( $apply_default ) {
			$chronicle_default = ( $game && ( $game->settings->auto_approve ?? false ) === true ) ? 'auto' : 'st';
			$level             = $level ?? $chronicle_default;
		}

		return [ 'level' => $level, 'reason' => $reason ];
	}

	/**
	 * A pool value's permanent rating, whether it is held as `{permanent, temporary}` or as a bare number.
	 *
	 * @param mixed    $value
	 * @param int|null $default What an absent value reads as.
	 */
	private static function pool_permanent_of( $value, ?int $default ): ?int {
		if ( is_array( $value ) ) {
			return isset( $value['permanent'] ) && is_numeric( $value['permanent'] ) ? (int) $value['permanent'] : $default;
		}
		return is_numeric( $value ) ? (int) $value : $default;
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
			$character    = Character::find( $character_id );
			if ( ! $character ) {
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
				'undo_state'   => $visit === null ? Undo_Record::build( [], [], $amount, $amount ) : null,
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
			} else {
				Notices::xp_awarded( $character, $amount, $reason, $awarded_by );
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
			'undo_state'   => $visit === null ? Undo_Record::build( [], [], $amount, $amount ) : null,
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

		if ( $visit === null ) {
			Notices::xp_awarded( $character, $amount, $reason, $applied_by );
		}

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
	 * Spends or regains a point of one held resource pool's temporary rating, right now, with no approval - logged
	 * the same way an XP award is: an already-approved Change naming who did it and why, in the same transaction as
	 * the write. The new value is clamped to `[0, permanent]` rather than refused for landing outside it; a pool whose
	 * temporary points build up (`accumulates`) is clamped to `[0, max]` instead, with no upper bound when it has no
	 * `max`, and a pool with no temporary points (`rating_only`) is refused.
	 *
	 * @param int    $character_id
	 * @param string $block_slug
	 * @param string $pool_name
	 * @param int    $delta  A whole number, positive to regain, negative to spend.
	 * @param string $reason
	 * @param int    $by
	 * @return array{applied: bool, code?: string, message?: string, permanent?: int|float, temporary?: int|float}
	 */
	public static function adjust_pool_temporary( int $character_id, string $block_slug, string $pool_name, int $delta, string $reason, int $by ): array {
		$savepoint = Transaction::begin( 'be_adjust_pool_temporary' );

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

		$pool = $character->sheet_data[ $block_slug ][ $pool_name ] ?? null;
		if ( ! is_array( $pool ) || ! array_key_exists( 'permanent', $pool ) ) {
			Transaction::rollback( $savepoint );
			return [
				'applied' => false,
				'code'    => 'pool_not_found',
				'message' => __( "That character doesn't hold that pool.", 'beyond-elysium' ),
			];
		}

		$marker = Pool_Markers::of( $character, $block_slug, $pool_name );
		if ( $marker['kind'] === Pool_Markers::RATING_ONLY ) {
			Transaction::rollback( $savepoint );
			return [
				'applied' => false,
				'code'    => 'rating_only',
				'message' => sprintf(
					/* translators: %s: a pool's name, such as Morality */
					__( '%s has no temporary points.', 'beyond-elysium' ),
					$pool_name
				),
			];
		}

		$permanent = self::pool_number( $pool['permanent'] );
		$current   = self::pool_number( $pool['temporary'] ?? $permanent );
		$ceiling   = $marker['kind'] === Pool_Markers::ACCUMULATES ? $marker['max'] : $permanent;
		$new_value = $current + $delta;
		$new_value = $ceiling === null ? max( 0, $new_value ) : max( 0, min( $ceiling, $new_value ) );

		$change_id = Change::create( [
			'character_id' => $character_id,
			'change_type'  => 'temporary_pool_spend',
			'category'     => $block_slug,
			'change_data'  => [
				'block_slug' => $block_slug,
				'pool_name'  => $pool_name,
				'delta'      => $delta,
				'from'       => $current,
				'to'         => $new_value,
			],
			'xp_cost'      => 0,
			'status'       => 'approved',
			'submitted_by' => $by,
			'notes'        => $reason,
		] );

		if ( ! $change_id || ! Character::set_pool_temporary( $character_id, $block_slug, $pool_name, $new_value ) ) {
			Transaction::rollback( $savepoint );
			return [
				'applied' => false,
				'code'    => 'write_failed',
				'message' => __( 'The change could not be saved.', 'beyond-elysium' ),
			];
		}

		Transaction::commit( $savepoint );

		return [
			'applied'   => true,
			'permanent' => $permanent,
			'temporary' => $new_value,
		];
	}

	/**
	 * A stored pool rating as a number, keeping the fraction of a decimal pool and the whole number of any other.
	 *
	 * @param mixed $value
	 * @return int|float
	 */
	private static function pool_number( $value ) {
		$number = is_numeric( $value ) ? $value + 0 : 0;
		return is_float( $number ) && floor( $number ) === $number ? (int) $number : $number;
	}

	/**
	 * Spends one permanent point of a pool the character's books spend permanently. The change costs no XP and never
	 * refunds any. It lowers the permanent rating, and the temporary rating with it when that was higher, either now
	 * (logged as already approved) or, when `$wait` is set, once a Storyteller approves a pending change.
	 *
	 * @param int    $character_id
	 * @param string $block_slug
	 * @param string $pool_name
	 * @param string $reason
	 * @param int    $by
	 * @param bool   $wait True to leave a pending change for a Storyteller and touch no rating yet.
	 * @return array{applied:bool,waiting?:bool,change_id?:int,permanent?:int,temporary?:int,code?:string,message?:string}
	 */
	public static function spend_pool_permanent( int $character_id, string $block_slug, string $pool_name, string $reason, int $by, bool $wait ): array {
		$savepoint = Transaction::begin( 'be_spend_pool_permanent' );

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

		if ( ! Permanent_Spend::marked( $character, $block_slug, $pool_name ) ) {
			Transaction::rollback( $savepoint );
			return [
				'applied' => false,
				'code'    => 'not_spendable',
				'message' => __( "The books don't spend that pool permanently.", 'beyond-elysium' ),
			];
		}

		$pool = $character->sheet_data[ $block_slug ][ $pool_name ] ?? null;
		if ( ! is_array( $pool ) || ! array_key_exists( 'permanent', $pool ) ) {
			Transaction::rollback( $savepoint );
			return [
				'applied' => false,
				'code'    => 'pool_not_found',
				'message' => __( "That character doesn't hold that pool.", 'beyond-elysium' ),
			];
		}

		$permanent = (int) $pool['permanent'];
		if ( $permanent < 1 ) {
			Transaction::rollback( $savepoint );
			return [
				'applied' => false,
				'code'    => 'nothing_to_spend',
				'message' => __( 'That pool has no permanent rating left to spend.', 'beyond-elysium' ),
			];
		}

		$change_id = Change::create( [
			'character_id' => $character_id,
			'change_type'  => 'pool_permanent_spent',
			'category'     => $block_slug,
			'change_data'  => [
				'block_slug' => $block_slug,
				'pool_name'  => $pool_name,
				'from'       => $permanent,
				'to'         => $permanent - 1,
			],
			'xp_cost'      => 0,
			'status'       => $wait ? 'pending' : 'approved',
			'submitted_by' => $by,
			'notes'        => $reason,
			'reason'       => $wait ? __( 'A permanent spend lowers a rating, and this chronicle approves those.', 'beyond-elysium' ) : null,
		] );
		if ( ! $change_id ) {
			Transaction::rollback( $savepoint );
			return [
				'applied' => false,
				'code'    => 'write_failed',
				'message' => __( 'The change could not be saved.', 'beyond-elysium' ),
			];
		}

		if ( $wait ) {
			Transaction::commit( $savepoint );
			return [ 'applied' => false, 'waiting' => true, 'change_id' => (int) $change_id ];
		}

		$temporary = min( (int) ( $pool['temporary'] ?? $permanent ), $permanent - 1 );
		if ( ! Character::set_pool_ratings( $character_id, $block_slug, $pool_name, $permanent - 1, $temporary ) ) {
			Transaction::rollback( $savepoint );
			return [
				'applied' => false,
				'code'    => 'write_failed',
				'message' => __( 'The change could not be saved.', 'beyond-elysium' ),
			];
		}

		self::keep_undo_record( (int) $change_id, $character, 0, 0 );
		Transaction::commit( $savepoint );
		return [ 'applied' => true, 'waiting' => false, 'permanent' => $permanent - 1, 'temporary' => $temporary ];
	}

	/**
	 * Undoes an approved change: its XP goes back, its sheet changes are put back, and it reads `undone` with who did it
	 * and when. A snapshot of the sheet is taken first. Nothing is undone unless everything the change touched is still
	 * as it left it, and nothing is repriced.
	 *
	 * @param int         $change_id
	 * @param int         $by
	 * @param string|null $note A note for the player.
	 * @return array{undone:bool,ids:int[],code?:string,message?:string}
	 */
	public static function undo( int $change_id, int $by, ?string $note ): array {
		$savepoint = Transaction::begin( 'be_change_undo' );

		$change = Change::find_for_update( $change_id );
		if ( ! $change ) {
			Transaction::rollback( $savepoint );
			return self::undo_refusal( 'not_found', __( 'Change not found.', 'beyond-elysium' ) );
		}

		Character::lock( (int) $change->character_id );
		$refusal = self::undo_one( $change, $by, $note );
		if ( $refusal !== null ) {
			Transaction::rollback( $savepoint );
			return $refusal;
		}

		Transaction::commit( $savepoint );
		return [ 'undone' => true, 'ids' => [ $change_id ] ];
	}

	/**
	 * Undoes every undoable approved change of one submission, newest first, all of them or none.
	 *
	 * @param int         $character_id
	 * @param string      $submission_id
	 * @param int         $by
	 * @param string|null $note
	 * @return array{undone:bool,ids:int[],code?:string,message?:string}
	 */
	public static function undo_set( int $character_id, string $submission_id, int $by, ?string $note ): array {
		$savepoint = Transaction::begin( 'be_change_undo_set' );
		Character::lock( $character_id );

		/** @var array<int,\stdClass> $lines */
		$lines = array_values( array_filter(
			Change::for_character( $character_id, [ 'status' => 'approved', 'order' => 'DESC' ] ),
			static fn( $change ): bool => ( $change->submission_id ?? null ) === $submission_id && ! empty( $change->can_undo )
		) );
		if ( $lines === [] ) {
			Transaction::rollback( $savepoint );
			return self::undo_refusal( 'not_undoable', __( 'Nothing in that set can be undone.', 'beyond-elysium' ) );
		}

		usort( $lines, static fn( $a, $b ): int => (int) $b->id <=> (int) $a->id );
		$ids = [];
		foreach ( $lines as $line ) {
			$locked  = Change::find_for_update( (int) $line->id );
			$refusal = $locked ? self::undo_one( $locked, $by, $note ) : self::undo_refusal( 'not_found', __( 'Change not found.', 'beyond-elysium' ) );
			if ( $refusal !== null ) {
				Transaction::rollback( $savepoint );
				return $refusal;
			}
			$ids[] = (int) $line->id;
		}

		Transaction::commit( $savepoint );
		return [ 'undone' => true, 'ids' => $ids ];
	}

	/**
	 * The checks and the writes of one undo, inside the caller's transaction and character lock.
	 *
	 * @param object $change A change row, locked.
	 * @return array{undone:bool,ids:int[],code:string,message:string}|null The refusal, or null once it is undone.
	 */
	private static function undo_one( $change, int $by, ?string $note ): ?array {
		$record = Change::undo_state( (int) $change->id );
		if ( $change->status !== 'approved' || $record === null ) {
			return self::undo_refusal( 'not_undoable', __( 'This change cannot be undone.', 'beyond-elysium' ) );
		}

		$character = Character::find( (int) $change->character_id );
		if ( ! $character ) {
			return self::undo_refusal( 'not_found', __( 'That character no longer exists.', 'beyond-elysium' ) );
		}

		if ( Keep_Current::inbound_kept_current_visit( (int) $character->id ) !== null ) {
			return self::undo_refusal( 'kept_current', __( "This sheet is kept current from the character's home chronicle. Change the kept-current copy there too.", 'beyond-elysium' ) );
		}

		$sheet     = is_array( $character->sheet_data ) ? $character->sheet_data : [];
		$conflicts = Undo_Record::conflicts( $record, $sheet );
		if ( $conflicts !== [] ) {
			return self::undo_refusal( 'changed_since', self::conflict_message( $conflicts[0], $change ) );
		}

		$xp = Undo_Record::xp( $record );
		if ( $xp['unspent'] > 0 && (int) $character->xp_unspent - $xp['unspent'] < 0 ) {
			return self::undo_refusal( 'xp_spent', __( 'That XP has been spent. Undo what it bought first.', 'beyond-elysium' ) );
		}

		Snapshot::create( (int) $character->id, (int) $change->id );

		$restored = Undo_Record::reverse( $record, $sheet, Pool_Markers::for_character( $character ) );
		$written  = Character::update_sheet_data( (int) $character->id, $restored );
		if ( $xp['earned'] !== 0 || $xp['unspent'] !== 0 ) {
			$written = $written && Character::update_xp( (int) $character->id, -$xp['earned'], -$xp['unspent'] );
		}

		$record['undone'] = [
			'by'   => $by,
			'at'   => current_time( 'mysql' ),
			'note' => $note,
		];
		if ( ! $written || ! Change::mark_undone( (int) $change->id, $record ) ) {
			return self::undo_refusal( 'write_failed', __( 'The change could not be undone.', 'beyond-elysium' ) );
		}
		return null;
	}

	/**
	 * @return array{undone:bool,ids:int[],code:string,message:string}
	 */
	private static function undo_refusal( string $code, string $message ): array {
		return [ 'undone' => false, 'ids' => [], 'code' => $code, 'message' => $message ];
	}

	/**
	 * What stops an undo, in a sentence naming what the sheet holds now and, when a later change did it, that change.
	 *
	 * @param array{block:string,name:string,now:mixed,kind:string} $conflict
	 * @param object                                               $change   The change being undone.
	 */
	private static function conflict_message( array $conflict, $change ): string {
		$name = $conflict['name'];
		$now  = $conflict['now'];

		if ( $conflict['kind'] === 'back' ) {
			/* translators: %s: the name of a trait that is on the sheet again */
			$text = sprintf( __( '%s is back on the sheet.', 'beyond-elysium' ), $name );
		} elseif ( $now === null ) {
			/* translators: %s: the name of a trait that is no longer on the sheet */
			$text = sprintf( __( '%s is no longer on the sheet.', 'beyond-elysium' ), $name );
		} elseif ( is_array( $now ) && isset( $now['count'] ) ) {
			/* translators: 1: the name of a trait, 2: its dots now */
			$text = sprintf( __( '%1$s is x%2$s now.', 'beyond-elysium' ), $name, $now['count'] );
		} elseif ( is_array( $now ) && isset( $now['level'] ) ) {
			/* translators: 1: the name of a power, 2: its level now */
			$text = sprintf( __( '%1$s is level %2$s now.', 'beyond-elysium' ), $name, $now['level'] );
		} elseif ( is_array( $now ) && isset( $now['permanent'] ) ) {
			/* translators: 1: the name of a pool, 2: its rating now */
			$text = sprintf( __( '%1$s is %2$s now.', 'beyond-elysium' ), $name, $now['permanent'] );
		} elseif ( is_scalar( $now ) ) {
			/* translators: 1: the name of a field, 2: its value now */
			$text = sprintf( __( '%1$s is %2$s now.', 'beyond-elysium' ), $name, (string) $now );
		} else {
			/* translators: %s: the name of a trait or field */
			$text = sprintf( __( '%s has changed since.', 'beyond-elysium' ), $name );
		}

		$later = self::later_change_naming( (int) $change->character_id, (int) $change->id, $conflict['block'], $name );
		return $later === null
			? $text . ' ' . __( 'Undo the later change first.', 'beyond-elysium' )
			: $text . ' ' . sprintf(
				/* translators: %s: what the later change did, such as "Occult 4 -> 5" */
				__( 'Undo the later change first: %s.', 'beyond-elysium' ),
				$later
			);
	}

	/**
	 * What the most recent approved change after another that names a trait, pool or field of a block did, or null when
	 * none does.
	 */
	private static function later_change_naming( int $character_id, int $after_id, string $block_slug, string $name ): ?string {
		foreach ( Change::for_character( $character_id, [ 'status' => 'approved', 'order' => 'DESC' ] ) as $change ) {
			if ( (int) $change->id <= $after_id ) {
				continue;
			}
			$data = is_array( $change->change_data ) ? $change->change_data : [];
			if ( ( $data['block_slug'] ?? null ) !== $block_slug ) {
				continue;
			}
			$trait = is_array( $data['trait'] ?? null ) ? $data['trait'] : [];
			if ( ( $trait['name'] ?? null ) === $name || isset( $data['values'][ $name ] ) || isset( $data['fields'][ $name ] ) ) {
				return \BeyondElysium\Services\Display\Change_Description::describe( (string) $change->change_type, $data );
			}
		}
		return null;
	}

	/**
	 * Keeps what an approved change did: the sheet as it was before, as it is now, and the XP it moved.
	 *
	 * @param int    $change_id
	 * @param object $before    The character as it was before the change was applied.
	 * @param int    $earned    The change to XP earned.
	 * @param int    $unspent   The change to XP unspent.
	 */
	private static function keep_undo_record( int $change_id, $before, int $earned, int $unspent ): void {
		$after  = Character::find( (int) $before->id );
		$record = Undo_Record::build(
			is_array( $before->sheet_data ) ? $before->sheet_data : [],
			$after !== null && is_array( $after->sheet_data ) ? $after->sheet_data : [],
			$earned,
			$unspent
		);
		if ( $record !== null ) {
			Change::set_undo_state( $change_id, $record );
		}
	}

	/**
	 * Carries out an approved permanent spend on the sheet as it now stands, refusing one whose pool has no rating
	 * left.
	 *
	 * @param object $character A character row with decoded sheet_data.
	 * @param object $change    A pending `pool_permanent_spent` change.
	 */
	private static function apply_permanent_spend( $character, $change ): bool {
		$data       = is_array( $change->change_data ) ? $change->change_data : [];
		$block_slug = (string) ( $data['block_slug'] ?? '' );
		$pool_name  = (string) ( $data['pool_name'] ?? '' );
		$pool       = $character->sheet_data[ $block_slug ][ $pool_name ] ?? null;
		if ( ! is_array( $pool ) || ! array_key_exists( 'permanent', $pool ) || (int) $pool['permanent'] < 1 ) {
			return false;
		}
		$permanent = (int) $pool['permanent'] - 1;
		return Character::set_pool_ratings( (int) $character->id, $block_slug, $pool_name, $permanent, min( (int) ( $pool['temporary'] ?? $permanent ), $permanent ) );
	}

	/**
	 * Takes or heals damage of one type, right now, with no approval - logged the same way an XP award is. An
	 * attack's armor amount is never subtracted from anything: Beyond Elysium does no armor math of its own, so the
	 * caller folds it into the logged reason as plain text.
	 *
	 * @param int    $character_id
	 * @param string $type   bashing, lethal or aggravated.
	 * @param int    $delta  A whole number, positive to take damage, negative to heal.
	 * @param string $reason
	 * @param int    $by
	 * @return array{applied: bool, code?: string, message?: string, wounds?: array{bashing:int,lethal:int,aggravated:int}}
	 */
	public static function adjust_wound( int $character_id, string $type, int $delta, string $reason, int $by ): array {
		if ( ! in_array( $type, [ 'bashing', 'lethal', 'aggravated' ], true ) ) {
			return [
				'applied' => false,
				'code'    => 'invalid_type',
				'message' => __( 'type must be bashing, lethal or aggravated.', 'beyond-elysium' ),
			];
		}

		$savepoint = Transaction::begin( 'be_adjust_wound' );

		Character::lock( $character_id );
		$state = Character::wound_state( $character_id );
		if ( $state === null ) {
			Transaction::rollback( $savepoint );
			return [
				'applied' => false,
				'code'    => 'not_found',
				'message' => __( 'That character no longer exists.', 'beyond-elysium' ),
			];
		}

		$new_value = max( 0, $state[ $type ] + $delta );

		$change_id = Change::create( [
			'character_id' => $character_id,
			'change_type'  => $delta >= 0 ? 'wound_taken' : 'wound_healed',
			'category'     => $type,
			'change_data'  => [
				'type'  => $type,
				'delta' => $delta,
				'from'  => $state[ $type ],
				'to'    => $new_value,
			],
			'xp_cost'      => 0,
			'status'       => 'approved',
			'submitted_by' => $by,
			'notes'        => $reason,
		] );

		if ( ! $change_id || ! Character::set_wound( $character_id, $type, $new_value ) ) {
			Transaction::rollback( $savepoint );
			return [
				'applied' => false,
				'code'    => 'write_failed',
				'message' => __( 'The change could not be saved.', 'beyond-elysium' ),
			];
		}

		Transaction::commit( $savepoint );

		$state[ $type ] = $new_value;
		return [
			'applied' => true,
			'wounds'  => $state,
		];
	}

	/**
	 * Appends one entry to a character's night losses, logged with a reason the same way a pool spend or a wound is.
	 *
	 * @param array<string,mixed> $entry
	 * @return array{applied:bool,code?:string,message?:string,losses?:array{lost_traits:array<int,array<string,mixed>>,expended_abilities:array<int,array<string,mixed>>}}
	 */
	public static function add_night_loss( int $character_id, string $kind, array $entry, string $reason, int $by ): array {
		$savepoint = Transaction::begin( 'be_add_night_loss' );

		Character::lock( $character_id );
		$losses = Character::night_losses( $character_id );
		if ( $losses === null ) {
			Transaction::rollback( $savepoint );
			return [
				'applied' => false,
				'code'    => 'not_found',
				'message' => __( 'That character no longer exists.', 'beyond-elysium' ),
			];
		}

		$change_id = Change::create( [
			'character_id' => $character_id,
			'change_type'  => $kind === 'ability' ? 'night_loss_ability' : 'night_loss_trait',
			'category'     => $entry['block_slug'] ?? '',
			'change_data'  => $entry,
			'xp_cost'      => 0,
			'status'       => 'approved',
			'submitted_by' => $by,
			'notes'        => $reason,
		] );

		if ( ! $change_id || ! Character::append_night_loss( $character_id, $kind, $entry ) ) {
			Transaction::rollback( $savepoint );
			return [
				'applied' => false,
				'code'    => 'write_failed',
				'message' => __( 'The loss could not be recorded.', 'beyond-elysium' ),
			];
		}

		Transaction::commit( $savepoint );

		$key              = $kind === 'ability' ? 'expended_abilities' : 'lost_traits';
		$losses[ $key ][] = $entry;

		return [
			'applied' => true,
			'losses'  => $losses,
		];
	}

	/**
	 * Clears a character's night losses entirely, logged with a reason - the session close that reaches here already
	 * names the session; a direct clear names whatever the caller gives.
	 *
	 * @return array{applied:bool,code?:string,message?:string,losses?:array{lost_traits:array<int,array<string,mixed>>,expended_abilities:array<int,array<string,mixed>>}}
	 */
	public static function clear_night_losses( int $character_id, string $reason, int $by ): array {
		$savepoint = Transaction::begin( 'be_clear_night_losses' );

		Character::lock( $character_id );
		$losses = Character::night_losses( $character_id );
		if ( $losses === null ) {
			Transaction::rollback( $savepoint );
			return [
				'applied' => false,
				'code'    => 'not_found',
				'message' => __( 'That character no longer exists.', 'beyond-elysium' ),
			];
		}

		if ( $losses['lost_traits'] === [] && $losses['expended_abilities'] === [] ) {
			Transaction::commit( $savepoint );
			return [
				'applied' => true,
				'losses'  => $losses,
			];
		}

		$change_id = Change::create( [
			'character_id' => $character_id,
			'change_type'  => 'night_losses_cleared',
			'category'     => '',
			'change_data'  => $losses,
			'xp_cost'      => 0,
			'status'       => 'approved',
			'submitted_by' => $by,
			'notes'        => $reason,
		] );

		if ( ! $change_id || ! Character::clear_night_losses( $character_id ) ) {
			Transaction::rollback( $savepoint );
			return [
				'applied' => false,
				'code'    => 'write_failed',
				'message' => __( 'The losses could not be cleared.', 'beyond-elysium' ),
			];
		}

		Transaction::commit( $savepoint );

		return [
			'applied' => true,
			'losses'  => [ 'lost_traits' => [], 'expended_abilities' => [] ],
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
