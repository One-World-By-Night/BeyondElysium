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

		// Every change is created pending.
		$insert = [
			'character_id' => $character_id,
			'change_type'  => $change_data['change_type'],
			'category'     => $change_data['category'] ?? null,
			'change_data'  => $change_data['change_data'] ?? [],
			'xp_cost'      => $change_data['xp_cost'] ?? 0,
			'status'       => 'pending',
			'submitted_by' => $submitted_by,
			'notes'        => $change_data['notes'] ?? null,
			'reason'       => $resolved['reason'],
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
				$identity = Trait_Identity::target_of(
					self::block_definition( $owner_slug, $block_slug ),
					$trait,
					is_array( $inner_data['previous'] ?? null ) ? $inner_data['previous'] : null
				);
				return $identity === null ? null : "{$block_slug}:{$identity}";

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
	 * @return bool False when the change is missing, no longer pending, changed since the token was taken, could not be written, or is waiting for a price and was given none.
	 */
	public static function approve( int $change_id, int $reviewed_by, $notes, ?string $expected_token = null, ?int $set_cost = null ): bool {
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
					$trait      = is_array( $change_data['trait'] ?? null ) ? $change_data['trait'] : $change_data;
					$definition = self::block_definition( (string) ( $character->owner_slug ?? '' ), $block_slug );
					$identity   = Trait_Identity::target_of( $definition, $trait, is_array( $change_data['previous'] ?? null ) ? $change_data['previous'] : null );
					if ( $identity !== null ) {
						$sheet[ $block_slug ] = array_values(
							array_filter(
								$sheet[ $block_slug ],
								static function ( $item ) use ( $definition, $identity ) {
									return ! is_array( $item ) || Trait_Identity::of_row( $definition, $item ) !== $identity;
								}
							)
						);
					}
				}
				break;

			case 'modify_trait':
				if ( $block_slug && isset( $sheet[ $block_slug ] ) && is_array( $sheet[ $block_slug ] ) && is_array( $change_data['trait'] ?? null ) ) {
					$definition = self::block_definition( (string) ( $character->owner_slug ?? '' ), $block_slug );
					$identity   = Trait_Identity::target_of( $definition, $change_data['trait'], is_array( $change_data['previous'] ?? null ) ? $change_data['previous'] : null );
					if ( $identity !== null ) {
						foreach ( $sheet[ $block_slug ] as &$item ) {
							if ( is_array( $item ) && Trait_Identity::of_row( $definition, $item ) === $identity ) {
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
			case 'catalog_rekey':
			case 'catalog_rekey_revert':
				// No sheet_data change.
				break;
		}

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

		if ( $change->change_type === 'propose_faction' ) {
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

		// Falls back to the chronicle's own default approval setting.
		$game = \BeyondElysium\Models\Game::find_by_slug( $character->owner_slug );
		$chronicle_default = ( $game && ( $game->settings->auto_approve ?? false ) === true ) ? 'auto' : 'st';
		$level = $level ?? $chronicle_default;

		return [ 'level' => $level, 'reason' => $reason ];
	}

	/**
	 * Awards XP to multiple characters in one call.
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
			if ( ! Character::find( $character_id ) ) {
				continue;
			}

			$savepoint = Transaction::begin( 'be_bulk_award_xp' );
			$change_id = Change::create( [
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

			if ( ! $change_id || ! Character::update_xp( $character_id, $amount, $amount ) ) {
				Transaction::rollback( $savepoint );
				continue;
			}

			Transaction::commit( $savepoint );
			$count++;
		}
		return $count;
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
