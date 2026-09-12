<?php

namespace BeyondElysium\Services;

use BeyondElysium\Models\Character;
use BeyondElysium\Models\Change;
use BeyondElysium\Models\Snapshot;
use BeyondElysium\Models\Schema_Block;
use BeyondElysium\Models\Creature_Stack;

defined( 'ABSPATH' ) || exit;

/**
 * Change Engine — submit, approve, reject, and apply changes to characters.
 *
 * Approval levels: auto < st < coordinator
 * Auto-approved changes are applied immediately on submit.
 *
 * Snapshot threshold: every 25 approved changes a new snapshot is auto-created.
 */
class Change_Engine {

	/** Number of approved changes between automatic snapshots. */
	const SNAPSHOT_THRESHOLD = 25;

	/**
	 * Submits a change for a character. Determines the required approval
	 * level for the change and, when it qualifies for automatic approval,
	 * applies it immediately instead of leaving it pending.
	 *
	 * @param int   $character_id
	 * @param array $change_data  Must include: change_type, category, change_data, xp_cost (optional).
	 * @param int   $submitted_by
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

		$status = ( $level === 'auto' ) ? 'approved' : 'pending';

		$insert = [
			'character_id' => $character_id,
			'change_type'  => $change_data['change_type'],
			'category'     => $change_data['category'] ?? null,
			'change_data'  => $change_data['change_data'] ?? [],
			'xp_cost'      => $change_data['xp_cost'] ?? 0,
			'status'       => $status,
			'submitted_by' => $submitted_by,
			'notes'        => $change_data['notes'] ?? null,
			'reason'       => $resolved['reason'],
		];

		// A still-pending resubmission of the same trait/field overwrites the one existing
		// row instead of leaving a second, indistinguishable one in the queue (BE_PROCESS/
		// 0.99.2-workflow.md, "Resubmitting creates duplicate pending changes"). Only applies
		// when this submission would itself be pending - an auto-approved change is already a
		// done deal, never a "duplicate pending" concern.
		if ( $status === 'pending' ) {
			$duplicate_key = self::pending_duplicate_key( $change_data['change_type'], (array) ( $change_data['change_data'] ?? [] ) );
			if ( $duplicate_key !== null ) {
				$existing_id = self::find_pending_duplicate( $character_id, $change_data['change_type'], $duplicate_key );
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
	 * The field(s) that identify WHICH trait/resource/identity-field a change targets,
	 * joined into one comparison key - two submissions with the same key are the same
	 * submission resubmitted, not two different edits. Returns null for a change_type this
	 * guard deliberately never applies to: `xp_earn`/`xp_adjust` (an ST awarding XP twice may
	 * be entirely intentional) and `import_note` (each import is its own real event).
	 *
	 * @param string               $change_type
	 * @param array<string,mixed>  $inner_data change_data's own nested payload (block_slug plus a trait/values/fields key).
	 */
	private static function pending_duplicate_key( string $change_type, array $inner_data ): ?string {
		$block_slug = $inner_data['block_slug'] ?? null;
		if ( ! is_string( $block_slug ) || $block_slug === '' ) {
			return null;
		}

		switch ( $change_type ) {
			case 'add_trait':
			case 'remove_trait':
			case 'modify_trait':
				$name = $inner_data['trait']['name'] ?? null;
				return is_string( $name ) && $name !== '' ? "{$block_slug}:{$name}" : null;

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
	 * Finds this character's own existing pending change targeting the same
	 * block/trait-or-field, if one exists. Scoped to the same change_type first (a cheap
	 * database filter) and the exact identity key second (computed the same way for the
	 * candidate as for the incoming submission, in PHP - change_data has no index to filter
	 * this by directly).
	 *
	 * @return int|null The existing change's id, or null when there is no duplicate.
	 */
	private static function find_pending_duplicate( int $character_id, string $change_type, string $duplicate_key ): ?int {
		foreach ( Change::for_character( $character_id, [ 'status' => 'pending', 'change_type' => $change_type ] ) as $candidate ) {
			$candidate_key = self::pending_duplicate_key( $change_type, (array) ( $candidate->change_data ?? [] ) );
			if ( $candidate_key === $duplicate_key ) {
				return (int) $candidate->id;
			}
		}
		return null;
	}

	/**
	 * Approves a pending change and applies it to the character's sheet.
	 * Marks the change approved, merges its effect into sheet_data,
	 * adjusts XP counters for XP-related change types, and creates a
	 * snapshot when the approved-change count crosses the threshold.
	 *
	 * @param int      $change_id
	 * @param int      $reviewed_by
	 * @param string|null $notes
	 * @return bool
	 */
	public static function approve( int $change_id, int $reviewed_by, $notes ): bool {
		$change = Change::find( $change_id );
		if ( ! $change ) {
			return false;
		}

		// Allow internal calls on already-auto-approved records.
		if ( $change->status !== 'pending' && $change->status !== 'approved' ) {
			return false;
		}

		// Mark as approved.
		Change::update_status( $change_id, 'approved', $reviewed_by, $notes );

		$character = Character::find( (int) $change->character_id );
		if ( ! $character ) {
			return false;
		}

		// Apply change to sheet_data.
		$new_sheet = self::apply_to_sheet( $character, $change );
		Character::update_sheet_data( (int) $character->id, $new_sheet );

		// Handle XP adjustments.
		$xp_cost = (float) ( $change->xp_cost ?? 0 );
		if ( $change->change_type === 'xp_earn' ) {
			$amount = (int) ( $change->change_data['amount'] ?? 0 );
			Character::update_xp( (int) $character->id, $amount, $amount );
		} elseif ( $change->change_type === 'xp_adjust' ) {
			$amount = (int) ( $change->change_data['amount'] ?? 0 );
			Character::update_xp( (int) $character->id, $amount, $amount );
		} elseif ( $xp_cost > 0 ) {
			Character::update_xp( (int) $character->id, 0, -(int) $xp_cost );
		}

		// Auto-snapshot every N approved changes.
		$approved_count = Change::count_for_character( (int) $character->id, [ 'status' => 'approved' ] );
		if ( $approved_count % self::SNAPSHOT_THRESHOLD === 0 ) {
			Snapshot::create( (int) $character->id, $change_id );
		}

		return true;
	}

	/**
	 * Rejects a pending change. Marks the change as rejected and leaves
	 * the character's sheet untouched; only a change in `pending` status
	 * can be rejected.
	 *
	 * @param int      $change_id
	 * @param int      $reviewed_by
	 * @param string|null $notes
	 * @return bool
	 */
	public static function reject( int $change_id, int $reviewed_by, $notes ): bool {
		$change = Change::find( $change_id );
		if ( ! $change ) {
			return false;
		}

		if ( $change->status !== 'pending' ) {
			return false;
		}

		return Change::update_status( $change_id, 'rejected', $reviewed_by, $notes );
	}

	/**
	 * Applies a change to a character's sheet_data and returns the
	 * updated array. Dispatches on the change type to add, remove, or
	 * modify a trait, merge resource or identity field values, or, for
	 * XP-only change types, leave the sheet unchanged.
	 *
	 * @param object $character Character row with decoded sheet_data.
	 * @param object $change    Change row with decoded change_data.
	 * @return array Updated sheet_data.
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
					$sheet[ $block_slug ][] = $change_data['trait'] ?? $change_data;
				}
				break;

			case 'remove_trait':
				if ( $block_slug && isset( $sheet[ $block_slug ] ) && is_array( $sheet[ $block_slug ] ) ) {
					$remove_name = $change_data['trait']['name'] ?? $change_data['name'] ?? null;
					if ( $remove_name !== null ) {
						$sheet[ $block_slug ] = array_values(
							array_filter(
								$sheet[ $block_slug ],
								function ( $item ) use ( $remove_name ) {
									return ( $item['name'] ?? null ) !== $remove_name;
								}
							)
						);
					}
				}
				break;

			case 'modify_trait':
				if ( $block_slug && isset( $sheet[ $block_slug ] ) && is_array( $sheet[ $block_slug ] ) ) {
					$target_name = $change_data['trait']['name'] ?? null;
					if ( $target_name !== null ) {
						foreach ( $sheet[ $block_slug ] as &$item ) {
							if ( ( $item['name'] ?? null ) === $target_name ) {
								$item = array_merge( $item, $change_data['trait'] );
								break;
							}
						}
						unset( $item );
					}
				}
				break;

			case 'modify_resource':
				if ( $block_slug ) {
					if ( ! isset( $sheet[ $block_slug ] ) || ! is_array( $sheet[ $block_slug ] ) ) {
						$sheet[ $block_slug ] = [];
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

			case 'xp_earn':
			case 'xp_adjust':
			case 'import_note':
				// No sheet_data change; handled elsewhere.
				break;
		}

		return $sheet;
	}

	/**
	 * Determines the approval level required for a change, and any
	 * citation explaining why. Checks per-item and block-level approval
	 * rules for the affected trait, applies any game-level auto-approve
	 * setting, and returns the strictest level found among 'auto', 'st',
	 * or 'coordinator' alongside an optional reason string.
	 *
	 * @param object $character
	 * @param object $change    Plain object with change_type, change_data['block_slug'].
	 * @return array{level: string, reason: ?string} level is 'auto' | 'st' | 'coordinator'.
	 */
	public static function resolve_approval_level( $character, $change ): array {
		$change_data = is_array( $change->change_data ) ? $change->change_data : [];
		$block_slug  = $change_data['block_slug'] ?? null;

		// XP earn/adjust submitted by a narrator/ST defaults to auto.
		if ( in_array( $change->change_type, [ 'xp_earn', 'xp_adjust', 'import_note' ], true ) ) {
			if ( current_user_can( 'be_manage_characters' ) ) {
				return [ 'level' => 'auto', 'reason' => null ];
			}
			return [ 'level' => 'st', 'reason' => null ];
		}

		$level  = 'st'; // Safe default.
		$reason = null;

		if ( $block_slug ) {
			// Prefer this character's chronicle-specific fork of the block, if one exists.
			$block = Schema_Block::find_for_game( $block_slug, (string) ( $character->owner_slug ?? '' ) );
			// $block->definition decodes as a plain object, not an associative array.
			if ( $block && $block->definition ) {
				$definition = $block->definition;
				$trait_name = $change_data['trait']['name'] ?? null;

				// Check per-item override first (trait_list blocks: Merits, Backgrounds, Abilities...).
				if ( $trait_name && ! empty( $definition->items ) ) {
					foreach ( $definition->items as $item ) {
						if ( ( $item->name ?? null ) === $trait_name ) {
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

				// Check the matched power's own level ladder (tiered_power blocks: Disciplines,
				// Gifts, Arcanoi...) - a separate catalog shape items[] never covers.
				if ( $trait_name && ! empty( $definition->powers ) ) {
					$held_level = $change_data['trait']['level'] ?? null;
					foreach ( $definition->powers as $power ) {
						if ( ( $power->name ?? null ) !== $trait_name ) {
							continue;
						}
						if ( isset( $power->approval_override ) ) {
							$level = self::strictest( $level, $power->approval_override );
						}
						foreach ( $power->levels ?? [] as $rung ) {
							if ( ( $rung->level ?? null ) === $held_level && ! empty( $rung->reason ) ) {
								$reason = $rung->reason;
								$level  = self::strictest( $level, 'st' );
							}
						}
						break;
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

		// Check game-level auto-approve settings. Never used to wave through a change
		// carrying a real-world approval citation, regardless of what level it resolved to.
		$game = \BeyondElysium\Models\Game::find_by_slug( $character->owner_slug );
		if ( $game && isset( $game->settings->auto_approve ) && $game->settings->auto_approve === true ) {
			if ( $level === 'st' && $reason === null ) {
				$level = 'auto';
			}
		}

		return [ 'level' => $level, 'reason' => $reason ];
	}

	/**
	 * Awards XP to multiple characters in one call. Creates an approved
	 * Change record and updates the XP counters for each character in the
	 * given list, skipping any character whose Change record fails to
	 * create.
	 *
	 * @param array  $character_ids
	 * @param int    $amount
	 * @param string $reason
	 * @param int    $awarded_by
	 * @return int Number of awards successfully created.
	 */
	public static function bulk_award_xp( array $character_ids, int $amount, string $reason, int $awarded_by ): int {
		$count = 0;
		foreach ( $character_ids as $character_id ) {
			$character_id = (int) $character_id;
			$change_id    = Change::create( [
				'character_id' => $character_id,
				'change_type'  => 'xp_earn',
				'category'     => 'experience',
				'change_data'  => [
					'amount' => $amount,
					'reason' => $reason,
				],
				'xp_cost'      => 0,
				'status'       => 'approved',
				'submitted_by' => $awarded_by,
				'notes'        => $reason,
			] );

			if ( $change_id ) {
				Character::update_xp( $character_id, $amount, $amount );
				$count++;
			}
		}
		return $count;
	}

	/**
	 * Returns the stricter of two approval levels. Orders levels as
	 * auto < st < coordinator and returns whichever of the two inputs
	 * ranks at least as strict as the other, treating an unrecognized
	 * level as equivalent to 'st'.
	 *
	 * @param string $a
	 * @param string $b
	 * @return string
	 */
	private static function strictest( string $a, string $b ): string {
		$order = [ 'auto' => 0, 'st' => 1, 'coordinator' => 2 ];
		$a_val = $order[ $a ] ?? 1;
		$b_val = $order[ $b ] ?? 1;
		return $a_val >= $b_val ? $a : $b;
	}
}
