<?php

namespace BeyondElysium\Services;

use BeyondElysium\Models\Faction;
use BeyondElysium\Models\World_Object;

defined( 'ABSPATH' ) || exit;

/**
 * Validates and normalizes a submitted character change before anything prices, routes, or applies it.
 *
 * @phpstan-type Validation array{ok:bool,change_data?:array<string,mixed>,code?:string,message?:string,args?:array<int,string>}
 */
class Change_Validator {

	/**
	 * Change types the REST route accepts.
	 */
	const REST_CHANGE_TYPES = [ 'add_trait', 'remove_trait', 'modify_trait', 'modify_resource', 'modify_identity', 'xp_earn', 'xp_adjust', 'propose_world_object', 'propose_faction' ];

	/**
	 * Keys a trait_list entry may carry.
	 */
	const TRAIT_LIST_KEYS = [ 'name', 'count', 'specialization', 'note', 'custom', 'chosen_cost' ];

	/**
	 * Keys a tiered_power entry may carry.
	 */
	const TIERED_POWER_KEYS = [ 'name', 'level', 'power_name', 'tradition', 'custom' ];

	/**
	 * Upper bounds that keep a stored value sane.
	 */
	const MAX_COUNT = 999;
	const MAX_LEVEL = 99;
	const MAX_POOL  = 999;
	const MAX_TEXT  = 5000;

	/**
	 * Bounds on a player's own XP request, submitted with no Storyteller capability.
	 */
	const XP_REQUEST_MAX       = 10000;
	const XP_REQUEST_WHERE_MAX = 200;
	const XP_REQUEST_NOTE_MAX  = 2000;

	/**
	 * Validates one change and returns it normalized: unknown keys dropped, names set to the catalog's exact spelling,
	 * numbers cast to integers, and `custom` set only where the block genuinely allows a custom entry.
	 *
	 * @param array<string,mixed>  $change             `change_type` and `change_data`.
	 * @param array<string,object> $blocks             The character's stack blocks keyed by slug, each with `section_type` and a decoded `definition`.
	 * @param array<string,mixed>  $sheet_data         The character's current sheet_data.
	 * @param bool                 $is_manager         Whether the submitter is a Storyteller of this chronicle.
	 * @param string[]             $protected_fields   "block_slug.Field" identity fields a non-Storyteller may not clear - the ones in-type pricing reads.
	 * @param string[]             $closed_blocks      Blocks the chronicle's hidden sections show, where nothing new is bought.
	 * @return Validation
	 */
	public static function validate( array $change, array $blocks, array $sheet_data, bool $is_manager, array $protected_fields = [], array $closed_blocks = [] ): array {
		$type = $change['change_type'] ?? '';
		if ( ! is_string( $type ) || ! in_array( $type, self::REST_CHANGE_TYPES, true ) ) {
			return self::fail( 'invalid_change_type', 'That kind of change cannot be submitted.' );
		}

		$data = $change['change_data'] ?? null;
		if ( ! is_array( $data ) ) {
			return self::fail( 'invalid_param', 'change_data must be an object.' );
		}

		if ( $type === 'xp_earn' || $type === 'xp_adjust' ) {
			if ( ! $is_manager && $type === 'xp_adjust' ) {
				return self::fail( 'invalid_change_type', 'Only a Storyteller can adjust experience.' );
			}
			return self::validate_xp( $data, $is_manager );
		}

		// A proposed catalog item is not sheet data.
		if ( $type === 'propose_world_object' ) {
			return self::validate_proposed_object( $data );
		}

		// A proposed faction is not sheet data either.
		if ( $type === 'propose_faction' ) {
			return self::validate_proposed_faction( $data );
		}

		$block_slug = $data['block_slug'] ?? null;
		if ( ! is_string( $block_slug ) || ! isset( $blocks[ $block_slug ] ) ) {
			return self::fail( 'unknown_block', "That section isn't part of this character's sheet." );
		}
		$block      = $blocks[ $block_slug ];
		$definition = $block->definition ?? new \stdClass();
		$held       = $sheet_data[ $block_slug ] ?? [];

		$held = is_array( $held ) ? $held : [];
		switch ( $type ) {
			case 'add_trait':
			case 'remove_trait':
			case 'modify_trait':
				if ( $block->section_type === 'trait_list' ) {
					$result = self::validate_trait_list( $type, $block_slug, $definition, $held, $data, $is_manager );
				} elseif ( $block->section_type === 'tiered_power' ) {
					$result = self::validate_tiered_power( $type, $block_slug, $definition, $held, $data, $is_manager );
					if ( $result['ok'] && $type !== 'modify_trait' ) {
						$spent = self::validate_spent_from_purchase( $definition, $sheet_data, (array) $result['change_data'], $type );
						if ( ! $spent['ok'] ) {
							return $spent;
						}
						$result = $spent;
					}
				} else {
					return self::fail( 'wrong_section_type', 'That change does not fit this section.' );
				}
				break;

			case 'modify_resource':
				if ( $block->section_type !== 'resource_pool' ) {
					return self::fail( 'wrong_section_type', 'That change does not fit this section.' );
				}
				$result = self::validate_resource( $block_slug, $definition, $held, $data, $is_manager );
				if ( $result['ok'] ) {
					$raised = self::validate_raised_by( $definition, $sheet_data, (array) $result['change_data'], $is_manager );
					if ( ! $raised['ok'] ) {
						return $raised;
					}
					$result = $raised;
				}
				break;

			default: // modify_identity - the only block-based type left after the allowlist check.
				if ( $block->section_type !== 'identity_field' ) {
					return self::fail( 'wrong_section_type', 'That change does not fit this section.' );
				}
				return self::validate_identity( $block_slug, $definition, $data, $is_manager, $protected_fields );
		}

		if ( $result['ok'] && in_array( $block_slug, $closed_blocks, true ) && self::buys_something( $type, $block, $held, (array) $result['change_data'] ) ) {
			return self::fail( 'section_hidden', '%s is hidden in this chronicle, so nothing new can be bought in it.', [ (string) ( $block->name ?? $block_slug ) ] );
		}
		return $result;
	}

	/**
	 * Whether a validated change buys something: an added entry, a higher count or level than the one held, or a higher
	 * permanent rating.
	 *
	 * @param string                  $type
	 * @param object                  $block
	 * @param array<int|string,mixed> $held
	 * @param array<string,mixed>     $data The validated change_data.
	 */
	private static function buys_something( string $type, object $block, array $held, array $data ): bool {
		if ( $type === 'add_trait' ) {
			return true;
		}

		if ( $type === 'modify_resource' ) {
			$values = (array) ( $data['values'] ?? [] );
			$name   = (string) array_key_first( $values );
			$value  = $values[ $name ] ?? null;
			$new    = is_array( $value ) ? ( $value['permanent'] ?? null ) : $value;
			if ( $new === null ) {
				return false;
			}
			$old = $held[ $name ] ?? null;
			$old = is_array( $old ) ? ( $old['permanent'] ?? null ) : $old;
			if ( $old === null ) {
				foreach ( ( $block->definition->pools ?? [] ) as $pool ) {
					if ( ( $pool->name ?? null ) === $name ) {
						$old = $pool->default_start ?? 0;
					}
				}
			}
			return (int) $new > (int) ( $old ?? 0 );
		}

		if ( $type !== 'modify_trait' ) {
			return false;
		}
		$trait = (array) ( $data['trait'] ?? [] );
		$name  = (string) ( $trait['name'] ?? '' );

		if ( $block->section_type === 'trait_list' ) {
			if ( ! array_key_exists( 'count', $trait ) ) {
				return false;
			}
			$target = Trait_Identity::target_of( $block->definition, $trait, self::previous_snapshot( $data ) );
			foreach ( $held as $row ) {
				if ( is_array( $row ) && ( $row['name'] ?? null ) === $name && ( $target === null || Trait_Identity::of_row( $block->definition, $row ) === $target ) ) {
					return (int) $trait['count'] > (int) ( $row['count'] ?? 0 );
				}
			}
			return true;
		}

		if ( ! array_key_exists( 'level', $trait ) || $trait['level'] === null ) {
			return false;
		}
		$pick = ( $trait['power_name'] ?? '' ) !== '' ? $trait['power_name'] : null;
		foreach ( $held as $row ) {
			if ( is_array( $row ) && ( $row['name'] ?? null ) === $name && ( ( $row['power_name'] ?? '' ) !== '' ? $row['power_name'] : null ) === $pick ) {
				return (int) $trait['level'] > (int) ( $row['level'] ?? 0 );
			}
		}
		return true;
	}

	/**
	 * The identity fields a stack's in-type tests read, as "block_slug.Field".
	 *
	 * @param object|null $stack A decoded creature stack row.
	 * @return string[]
	 */
	public static function protected_fields( $stack ): array {
		return In_Type::fields( $stack );
	}

	/**
	 * @param array $data
	 * @return array
	 */
	/**
	 * A player's proposed catalog item, location or rote.
	 *
	 * @param array<string,mixed> $data
	 * @return Validation
	 */
	private static function validate_proposed_object( array $data ): array {
		$object_type = $data['object_type'] ?? '';
		if ( ! is_string( $object_type ) || ! in_array( $object_type, World_Object::valid_types(), true ) ) {
			return self::fail( 'invalid_param', 'Choose what kind of thing this is.' );
		}

		$name = is_string( $data['name'] ?? null ) ? self::text( $data['name'], 200 ) : '';
		if ( $name === '' ) {
			return self::fail( 'invalid_param', 'Give it a name.' );
		}

		$properties = $data['properties'] ?? [];
		if ( ! is_array( $properties ) ) {
			return self::fail( 'invalid_param', 'properties must be an object.' );
		}

		$problem = World_Object::validate_properties( $object_type, $properties );
		if ( $problem !== null ) {
			return self::fail( 'invalid_param', $problem );
		}

		// Validated here, sanitized at write time by `World_Object::create()`.
		$normalized = [
			'object_type' => $object_type,
			'name'        => $name,
			'properties'  => $properties,
		];

		// Description is rich text, the same as the catalog editor's own field.
		foreach ( [ 'description', 'limitations' ] as $rich ) {
			if ( isset( $data[ $rich ] ) && is_string( $data[ $rich ] ) ) {
				$normalized[ $rich ] = mb_substr( trim( wp_kses_post( $data[ $rich ] ) ), 0, self::MAX_TEXT );
			}
		}
		foreach ( [ 'rarity', 'cost' ] as $plain ) {
			if ( isset( $data[ $plain ] ) && is_string( $data[ $plain ] ) ) {
				$normalized[ $plain ] = self::text( $data[ $plain ], 100 );
			}
		}

		return [ 'ok' => true, 'change_data' => $normalized ];
	}

	/**
	 * A player's proposed faction.
	 *
	 * @param array<string,mixed> $data
	 * @return Validation
	 */
	private static function validate_proposed_faction( array $data ): array {
		$faction_type = (string) ( $data['faction_type'] ?? '' );
		if ( ! in_array( $faction_type, Faction::PLAYER_PROPOSABLE_TYPES, true ) ) {
			return self::fail( 'invalid_param', 'Choose what kind of group this is.' );
		}

		$name = is_string( $data['name'] ?? null ) ? self::text( $data['name'], 200 ) : '';
		if ( $name === '' ) {
			return self::fail( 'invalid_param', 'Give it a name.' );
		}

		$normalized = [
			'faction_type' => $faction_type,
			'name'         => $name,
		];

		foreach ( [ 'description', 'goals' ] as $rich ) {
			if ( isset( $data[ $rich ] ) && is_string( $data[ $rich ] ) ) {
				$normalized[ $rich ] = mb_substr( trim( wp_kses_post( $data[ $rich ] ) ), 0, self::MAX_TEXT );
			}
		}

		return [ 'ok' => true, 'change_data' => $normalized ];
	}

	/**
	 * A Storyteller's own `xp_earn`/`xp_adjust`: any non-zero whole number, an optional reason. A player's submission
	 * is a request, never a direct adjustment: a positive amount only, a required "where you earned it", an optional
	 * date no later than today, and optional details - the server builds the stored reason itself.
	 *
	 * @param array<string,mixed> $data
	 * @param bool                $is_manager
	 * @return Validation
	 */
	private static function validate_xp( array $data, bool $is_manager ): array {
		$amount = self::to_int( $data['amount'] ?? null );

		if ( $is_manager ) {
			if ( $amount === null || $amount === 0 ) {
				return self::fail( 'invalid_param', 'amount must be a whole number other than zero.' );
			}
			$normalized = [ 'amount' => $amount ];
			if ( isset( $data['reason'] ) && is_string( $data['reason'] ) ) {
				$normalized['reason'] = self::text( $data['reason'] );
			}
			return [ 'ok' => true, 'change_data' => $normalized ];
		}

		if ( $amount === null || $amount < 1 || $amount > self::XP_REQUEST_MAX ) {
			return self::fail( 'invalid_param', 'amount must be a whole number from 1 to %s.', [ (string) self::XP_REQUEST_MAX ] );
		}

		$request = $data['request'] ?? null;
		if ( ! is_array( $request ) ) {
			return self::fail( 'invalid_param', 'A request needs to say where the XP was earned.' );
		}

		$where_raw = $request['where'] ?? null;
		if (
			! is_string( $where_raw ) ||
			trim( $where_raw ) === '' ||
			mb_strlen( trim( $where_raw ) ) > self::XP_REQUEST_WHERE_MAX
		) {
			return self::fail( 'invalid_param', 'A request needs to say where the XP was earned, up to %s characters.', [ (string) self::XP_REQUEST_WHERE_MAX ] );
		}
		$where = self::text( $where_raw, self::XP_REQUEST_WHERE_MAX );

		$date = null;
		if ( ! empty( $request['date'] ) ) {
			if ( ! is_string( $request['date'] ) || ! self::is_real_past_date( $request['date'] ) ) {
				return self::fail( 'invalid_param', 'date must be a real date no later than today.' );
			}
			$date = $request['date'];
		}

		$note = null;
		if ( ! empty( $request['note'] ) ) {
			if ( ! is_string( $request['note'] ) || mb_strlen( $request['note'] ) > self::XP_REQUEST_NOTE_MAX ) {
				return self::fail( 'invalid_param', 'Details can run up to %s characters.', [ (string) self::XP_REQUEST_NOTE_MAX ] );
			}
			$note = self::text( $request['note'], self::XP_REQUEST_NOTE_MAX );
		}

		$reason = $date
			? sprintf( __( 'Requested: %1$s, %2$s', 'beyond-elysium' ), $where, $date )
			: sprintf( __( 'Requested: %s', 'beyond-elysium' ), $where );

		return [
			'ok'          => true,
			'change_data' => [
				'amount'  => $amount,
				'reason'  => $reason,
				'request' => [ 'where' => $where, 'date' => $date, 'note' => $note ],
			],
		];
	}

	/**
	 * Whether a string is a real `YYYY-MM-DD` calendar date no later than today, in the site's own time zone.
	 *
	 * @param string $value
	 * @return bool
	 */
	private static function is_real_past_date( string $value ): bool {
		if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m ) ) {
			return false;
		}
		if ( ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
			return false;
		}
		return $value <= current_time( 'Y-m-d' );
	}

	/**
	 * @param string                  $type
	 * @param string                  $block_slug
	 * @param object                  $definition
	 * @param array<int|string,mixed> $held
	 * @param array<string,mixed>     $data
	 * @param bool                    $is_manager A Storyteller may add a custom entry to any section; a player only where the section allows one.
	 * @return Validation
	 */
	private static function validate_trait_list( string $type, string $block_slug, $definition, array $held, array $data, bool $is_manager ): array {
		$trait = $data['trait'] ?? null;
		if ( ! is_array( $trait ) || ! is_string( $trait['name'] ?? null ) || trim( $trait['name'] ) === '' ) {
			return self::fail( 'invalid_param', 'A trait needs a name.' );
		}
		$trait = array_intersect_key( $trait, array_flip( self::TRAIT_LIST_KEYS ) );

		$catalog_names = [];
		foreach ( ( $definition->items ?? [] ) as $item ) {
			if ( isset( $item->name ) && is_string( $item->name ) ) {
				$catalog_names[] = $item->name;
			}
		}
		$held_names = array_values( array_filter( array_map( static fn( $row ) => is_array( $row ) ? ( $row['name'] ?? null ) : null, $held ), 'is_string' ) );

		$resolved = self::resolve_name( $trait['name'], $catalog_names );
		if ( $resolved === null && ! in_array( $trait['name'], $held_names, true ) ) {
			$aliased = Trait_Alias_Resolver::find_item_by_name( (array) ( $definition->items ?? [] ), $trait['name'] );
			if ( $aliased !== null && isset( $aliased->name ) && is_string( $aliased->name ) ) {
				$resolved = $aliased->name;
			}
		}
		if ( $resolved !== null ) {
			// A catalog name is priced from the catalog - never as a free custom entry.
			$trait['name'] = $resolved;
			unset( $trait['custom'] );
		} elseif ( $type !== 'add_trait' && in_array( $trait['name'], $held_names, true ) ) {
			// Changing or removing something the character already holds.
			$trait['custom'] = ! empty( $trait['custom'] );
		} elseif ( $type === 'add_trait' && ( $is_manager || ! empty( $definition->allow_custom ) ) ) {
			$trait['name']   = self::text( $trait['name'], 200 );
			$trait['custom'] = true;
		} else {
			return self::fail( 'unknown_trait', '"%s" is not in this section\'s catalog.', [ self::text( $trait['name'], 200 ) ] );
		}

		if ( array_key_exists( 'count', $trait ) ) {
			$count = self::to_int( $trait['count'] );
			if ( $count === null || $count < 0 || $count > self::MAX_COUNT ) {
				return self::fail( 'invalid_param', 'count must be a whole number from 0 to ' . self::MAX_COUNT . '.' );
			}
			$trait['count'] = $count;
		}
		if ( array_key_exists( 'chosen_cost', $trait ) && $trait['chosen_cost'] !== null ) {
			$cost = self::to_int( $trait['chosen_cost'] );
			if ( $cost === null ) {
				return self::fail( 'invalid_param', 'chosen_cost must be a whole number.' );
			}
			$trait['chosen_cost'] = $cost;
		}
		// A player never prices their own homebrew.
		if ( $resolved === null && ! $is_manager ) {
			unset( $trait['chosen_cost'] );
		}
		foreach ( [ 'specialization', 'note' ] as $text_key ) {
			if ( array_key_exists( $text_key, $trait ) ) {
				$trait[ $text_key ] = is_string( $trait[ $text_key ] ) ? self::text( $trait[ $text_key ] ) : '';
			}
		}
		if ( empty( $trait['custom'] ) ) {
			unset( $trait['custom'] );
		}

		$previous = self::previous_snapshot( $data );

		$conflict = self::trait_row_conflict( $type, $definition, $held, $trait, $previous );
		if ( $conflict !== null ) {
			return $conflict;
		}

		$normalized = self::with_display_keys( $data, [ 'block_slug' => $block_slug, 'trait' => $trait ] );
		// The engine addresses a relabelled row by this one key.
		if ( isset( $previous['specialization'], $normalized['previous'] ) && is_array( $normalized['previous'] ) ) {
			$normalized['previous']['specialization'] = $previous['specialization'];
		}

		return [ 'ok' => true, 'change_data' => $normalized ];
	}

	/**
	 * The `previous` snapshot narrowed to the one field that identifies.
	 *
	 * @param array<string,mixed> $data
	 * @return array<string,mixed>|null
	 */
	private static function previous_snapshot( array $data ): ?array {
		$previous = $data['previous'] ?? null;
		if ( ! is_array( $previous ) || ! isset( $previous['specialization'] ) || ! is_string( $previous['specialization'] ) ) {
			return null;
		}
		return [ 'specialization' => self::text( $previous['specialization'] ) ];
	}

	/**
	 * Refuses a change that would leave two held rows the sheet cannot tell apart.
	 *
	 * @param string                   $type
	 * @param object                   $definition
	 * @param array<int|string,mixed>  $held
	 * @param array<string,mixed>      $trait    The normalized trait.
	 * @param array<string,mixed>|null $previous The change's `previous` snapshot, which names the row a relabel addresses.
	 * @return Validation|null A failure, or null when the change is fine.
	 */
	private static function trait_row_conflict( string $type, $definition, array $held, array $trait, ?array $previous = null ): ?array {
		if ( ! empty( $definition->atomic ) || ! in_array( $type, [ 'add_trait', 'modify_trait' ], true ) ) {
			return null;
		}

		$name  = (string) $trait['name'];
		$label = array_key_exists( 'specialization', $trait ) && is_string( $trait['specialization'] ) ? $trait['specialization'] : null;

		// Only rows sharing this name can ever collide.
		$before = [];
		foreach ( $held as $row ) {
			if ( ! is_array( $row ) || ( $row['name'] ?? null ) !== $name ) {
				continue;
			}
			$before[] = (string) Trait_Identity::of_row( $definition, $row );
		}

		$after = $before;
		if ( $type === 'add_trait' ) {
			$after[] = Trait_Identity::of( $definition, $name, $label ?? '' );
		} else {
			// Which row the engine will land on, and what it will become.
			$target = Trait_Identity::target_of( $definition, $trait, $previous );
			$at     = $target === null ? false : array_search( $target, $before, true );
			if ( $at === false ) {
				if ( Trait_Identity::allows_multiples( $definition, $name ) ) {
					return self::fail( 'trait_not_held', '"%s" is not on this sheet - add it instead of changing it.', [ self::display_name( $definition, $name, $label ) ] );
				}
				return null;
			}
			$after[ $at ] = Trait_Identity::of( $definition, $name, $label ?? '' );
		}

		if ( ( count( $after ) - count( array_unique( $after ) ) ) <= ( count( $before ) - count( array_unique( $before ) ) ) ) {
			return null;
		}

		return self::fail( 'trait_already_held', '"%s" is already on this sheet - change the entry you hold rather than adding a second one.', [ self::display_name( $definition, $name, $label ) ] );
	}

	/**
	 * How a holding is named back to the player: its label included only where the label is part of what identifies it.
	 *
	 * @param object      $definition
	 * @param string      $name
	 * @param string|null $label
	 * @return string
	 */
	private static function display_name( $definition, string $name, ?string $label ): string {
		return Trait_Identity::allows_multiples( $definition, $name ) && ( $label ?? '' ) !== ''
			? "{$name} ({$label})"
			: $name;
	}

	/**
	 * @param string                  $type
	 * @param string                  $block_slug
	 * @param object                  $definition
	 * @param array<int|string,mixed> $held
	 * @param array<string,mixed>     $data
	 * @param bool                    $is_manager A Storyteller may add a custom power to any section; a player only where the section allows one.
	 * @return Validation
	 */
	private static function validate_tiered_power( string $type, string $block_slug, $definition, array $held, array $data, bool $is_manager ): array {
		$trait = $data['trait'] ?? null;
		if ( ! is_array( $trait ) || ! is_string( $trait['name'] ?? null ) || trim( $trait['name'] ) === '' ) {
			return self::fail( 'invalid_param', 'A power needs a name.' );
		}
		$trait = array_intersect_key( $trait, array_flip( self::TIERED_POWER_KEYS ) );

		$families = [];
		foreach ( ( $definition->powers ?? [] ) as $power ) {
			if ( isset( $power->name ) && is_string( $power->name ) ) {
				$families[ $power->name ] = $power;
			}
		}

		$held_rows = array_values( array_filter( $held, 'is_array' ) );
		$held_pick = static function ( string $name, ?string $power_name ) use ( $held_rows ): bool {
			foreach ( $held_rows as $row ) {
				$row_pick = ( $row['power_name'] ?? '' ) !== '' ? $row['power_name'] : null;
				if ( ( $row['name'] ?? null ) === $name && $row_pick === $power_name ) {
					return true;
				}
			}
			return false;
		};

		$power_name = isset( $trait['power_name'] ) && is_string( $trait['power_name'] ) && $trait['power_name'] !== '' ? $trait['power_name'] : null;

		$family = self::resolve_name( $trait['name'], array_keys( $families ) );
		if ( $family === null && ! $held_pick( $trait['name'], $power_name ) ) {
			$aliased = Trait_Alias_Resolver::find_power_by_name( (array) ( $definition->powers ?? [] ), $trait['name'] );
			if ( $aliased !== null && isset( $aliased->name ) && is_string( $aliased->name ) ) {
				$family = $aliased->name;
			}
		}
		if ( $family !== null ) {
			$trait['name'] = $family;
			unset( $trait['custom'] );

			if ( $power_name !== null ) {
				// Every container, not the ladder alone.
				$picks = [];
				foreach ( \BeyondElysium\Services\Power_Levels::all( $families[ $family ] ) as $rung ) {
					if ( isset( $rung->power_name ) && is_string( $rung->power_name ) && $rung->power_name !== '' ) {
						$picks[] = $rung->power_name;
					}
				}
				$pick = self::resolve_name( $power_name, $picks );
				if ( $pick === null && ! $held_pick( $family, $power_name ) ) {
					$aliased_level = Trait_Alias_Resolver::find_level_by_name(
						\BeyondElysium\Services\Power_Levels::all( $families[ $family ] ),
						$power_name
					);
					if ( $aliased_level !== null && isset( $aliased_level->power_name ) && is_string( $aliased_level->power_name ) ) {
						$pick = $aliased_level->power_name;
					}
				}
				if ( $pick !== null ) {
					$trait['power_name'] = $pick;
				} elseif ( $type !== 'add_trait' && $held_pick( $family, $power_name ) ) {
					$trait['power_name'] = $power_name;
				} else {
					return self::fail( 'unknown_power_pick', '"%1$s" is not a power of %2$s.', [ self::text( $power_name, 200 ), $family ] );
				}
			}
		} elseif ( $type !== 'add_trait' && $held_pick( $trait['name'], $power_name ) ) {
			$trait['custom'] = ! empty( $trait['custom'] );
		} elseif ( $type === 'add_trait' && ( $is_manager || ! empty( $definition->allow_custom ) ) ) {
			$trait['name']   = self::text( $trait['name'], 200 );
			$trait['custom'] = true;
			if ( $power_name !== null ) {
				$trait['power_name'] = self::text( $power_name, 200 );
			}
		} else {
			return self::fail( 'unknown_power', '"%s" is not in this section\'s catalog.', [ self::text( $trait['name'], 200 ) ] );
		}

		if ( array_key_exists( 'level', $trait ) && $trait['level'] !== null ) {
			$level = self::to_int( $trait['level'] );
			if ( $level === null || $level < 0 || $level > self::MAX_LEVEL ) {
				return self::fail( 'invalid_param', 'level must be a whole number.' );
			}
			$trait['level'] = $level;
		}
		if ( array_key_exists( 'tradition', $trait ) ) {
			$trait['tradition'] = is_string( $trait['tradition'] ) ? self::text( $trait['tradition'], 200 ) : '';
			// A removal names the row that is leaving as it is stored; any other change names what the row becomes.
			if ( $type !== 'remove_trait' && ! empty( $definition->blood_magic ) && $trait['tradition'] !== '' ) {
				$allowed = self::blood_magic_traditions( $definition );
				$typed   = $trait['tradition'];
				$trait['tradition'] = Blood_Magic_Refile::canonical_tradition( $typed, $allowed, (array) ( $definition->tradition_aliases ?? [] ) );
				if ( ! in_array( $trait['tradition'], $allowed, true ) ) {
					// A tradition the row already carries is left as stored; only a new one has to be listed.
					if ( $type !== 'modify_trait' || ! self::row_holds_tradition( $definition, $held, $trait, $data, $typed ) ) {
						return self::fail( 'unknown_tradition', '"%s" does not teach any power in this section.', [ self::text( $trait['tradition'], 200 ) ] );
					}
					$trait['tradition'] = $typed;
				}
			}
			if ( $type === 'modify_trait' && ! empty( $definition->blood_magic ) ) {
				$taken = self::retradition_conflict( $definition, $held, $trait, $data );
				if ( $taken !== null ) {
					return $taken;
				}
			}
		}
		if ( empty( $trait['custom'] ) ) {
			unset( $trait['custom'] );
		}

		return [ 'ok' => true, 'change_data' => self::with_display_keys( $data, [ 'block_slug' => $block_slug, 'trait' => $trait ] ) ];
	}

	/**
	 * Whether the held row a change addresses is already stored under this tradition, spelled exactly so.
	 *
	 * @param object                   $definition
	 * @param array<int|string,mixed>  $held
	 * @param array<string,mixed>      $trait
	 * @param array<string,mixed>      $data
	 */
	private static function row_holds_tradition( $definition, array $held, array $trait, array $data, string $tradition ): bool {
		$previous = is_array( $data['previous'] ?? null ) ? $data['previous'] : null;
		$identity = Trait_Identity::target_of( $definition, $trait, $previous );
		if ( $identity === null ) {
			return false;
		}
		$rows = array_values( $held );
		foreach ( Trait_Identity::addressed_positions( $definition, $rows, $identity, Trait_Identity::tradition_of_target( $trait, $previous ) ) as $position ) {
			if ( ( $rows[ $position ]['tradition'] ?? null ) === $tradition ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Refuses a change that would respell or move a held Blood Magic row into a path and tradition another held row
	 * already carries, which would leave the sheet holding it twice.
	 *
	 * @param object                   $definition
	 * @param array<int|string,mixed>  $held  The character's rows in this block.
	 * @param array<string,mixed>      $trait The normalized trait, carrying the tradition the row will have.
	 * @param array<string,mixed>      $data  The change's own data, whose `previous` names the row as it stands.
	 * @return array<string,mixed>|null A failure, or null when the change is fine.
	 */
	private static function retradition_conflict( $definition, array $held, array $trait, array $data ): ?array {
		$previous = is_array( $data['previous'] ?? null ) ? $data['previous'] : null;
		$identity = Trait_Identity::target_of( $definition, $trait, $previous );
		if ( $identity === null ) {
			return null;
		}

		$rows      = array_values( $held );
		$addressed = Trait_Identity::addressed_positions( $definition, $rows, $identity, Trait_Identity::tradition_of_target( $trait, $previous ) );
		if ( $addressed === [] ) {
			return null;
		}
		$target = $addressed[0];
		$stored = (string) ( $rows[ $target ]['tradition'] ?? '' );
		if ( (string) $trait['tradition'] === $stored ) {
			return null;
		}

		$wanted = Fuzzy_Matcher::normalize( (string) $trait['tradition'] );
		foreach ( Trait_Identity::addressed_positions( $definition, $rows, $identity, null ) as $position ) {
			if ( $position !== $target && Fuzzy_Matcher::normalize( (string) ( $rows[ $position ]['tradition'] ?? '' ) ) === $wanted ) {
				return self::fail( 'already_held', '%1$s (%2$s) is already on this sheet. Remove the other entry instead.', [ self::text( (string) $trait['name'], 200 ), (string) $trait['tradition'] ] );
			}
		}
		return null;
	}

	/**
	 * The whole set of traditions a blood_magic block offers: its own declared list when set.
	 *
	 * @param object $definition
	 * @return string[]
	 */
	private static function blood_magic_traditions( object $definition ): array {
		if ( ! empty( $definition->traditions ) && is_array( $definition->traditions ) ) {
			return $definition->traditions;
		}

		$traditions = [];
		foreach ( ( $definition->powers ?? [] ) as $power ) {
			if ( ! isset( $power->name ) || ! is_string( $power->name ) ) {
				continue;
			}
			$separator = strpos( $power->name, ': ' );
			if ( $separator !== false ) {
				$traditions[] = substr( $power->name, 0, $separator );
			}
		}
		$traditions = array_values( array_unique( $traditions ) );
		sort( $traditions );
		return $traditions;
	}

	/**
	 * Refuses a `spent_from` block's purchase without enough unspent dots in its named Virtue (or similar) pool;
	 * refuses a purchase of a creed-restricted family (`creed_restricted_to`) for a character of any other creed;
	 * refuses a rank bought without already holding the same family's own next-lower rank (`rank_not_unlocked`,
	 * skipped for a family's first rank); and, when `creed_check` is declared, refuses a non-primary family's
	 * purchase once it would hold more picks than the family named for the character's own creed holds
	 * (`outranks_primary_path`, the creed name plus " Path").
	 *
	 * @param object              $definition
	 * @param array<string,mixed> $sheet_data The character's whole sheet, to read the pool and the creed check from.
	 * @param array<string,mixed> $data       The already-resolved `change_data` (real family/pick names).
	 * @param string              $type       `add_trait` or `remove_trait` - `modify_trait` never reaches here.
	 * @return array{ok:bool,code?:string,message?:string,args?:array<int,mixed>}
	 */
	private static function validate_spent_from_purchase( $definition, array $sheet_data, array $data, string $type ): array {
		$spent_from = $definition->_meta->spent_from ?? null;
		if ( $spent_from === null || $type !== 'add_trait' ) {
			return [ 'ok' => true, 'change_data' => $data ];
		}

		$trait      = is_array( $data['trait'] ?? null ) ? $data['trait'] : [];
		$family_name = (string) ( $trait['name'] ?? '' );
		$power_name  = (string) ( $trait['power_name'] ?? '' );
		$field       = (string) ( $spent_from->by_family_field ?? '' );

		$family = null;
		foreach ( ( $definition->powers ?? [] ) as $power ) {
			if ( isset( $power->name ) && $power->name === $family_name ) {
				$family = $power;
				break;
			}
		}
		if ( $family === null || $power_name === '' ) {
			return [ 'ok' => true, 'change_data' => $data ]; // Resolved elsewhere; nothing more to check here.
		}

		$creed_check   = (string) ( $spent_from->creed_check ?? '' );
		$current_creed = null;
		if ( $creed_check !== '' ) {
			[ $creed_block, $creed_field ] = array_pad( explode( '.', $creed_check, 2 ), 2, '' );
			$current_creed = isset( $sheet_data[ $creed_block ][ $creed_field ] ) ? (string) $sheet_data[ $creed_block ][ $creed_field ] : null;
		}
		if ( isset( $family->creed_restricted_to ) && is_array( $family->creed_restricted_to ) ) {
			if ( ! in_array( $current_creed, $family->creed_restricted_to, true ) ) {
				return self::fail( 'creed_restricted', '%s is restricted to %s.', [ $family_name, implode( ' or ', $family->creed_restricted_to ) ] );
			}
		}

		$rank = null;
		foreach ( (array) ( $family->elder ?? [] ) as $rank_name => $picks ) {
			foreach ( (array) $picks as $pick ) {
				if ( isset( $pick->power_name ) && $pick->power_name === $power_name ) {
					$rank = (string) $rank_name;
					break 2;
				}
			}
		}
		$cost = $rank !== null ? (int) ( $spent_from->rank_cost->{$rank} ?? 0 ) : 0;
		if ( $cost < 1 ) {
			return [ 'ok' => true, 'change_data' => $data ]; // Unknown rank - nothing this check can price.
		}

		$block_slug = (string) ( $data['block_slug'] ?? '' );
		$held       = array_values( array_filter( (array) ( $sheet_data[ $block_slug ] ?? [] ), 'is_array' ) );
		$ranks      = array_map( 'strval', (array) ( $definition->_meta->ranks ?? [] ) );
		$rank_index = array_search( $rank, $ranks, true );

		if ( $rank_index !== false && $rank_index > 0 ) {
			$previous_rank = $ranks[ $rank_index - 1 ];
			$has_previous  = false;
			foreach ( $held as $row ) {
				if ( ( $row['name'] ?? null ) === $family_name && ( $row['spent_rank'] ?? null ) === $previous_rank ) {
					$has_previous = true;
					break;
				}
			}
			if ( ! $has_previous ) {
				return self::fail( 'rank_not_unlocked', '%s needs a %s edge in %s before a %s one.', [ $power_name, (string) $previous_rank, $family_name, (string) $rank ] );
			}
		}

		if ( $current_creed !== null && $current_creed !== '' ) {
			$primary_path = $current_creed . ' Path';
			if ( $family_name !== $primary_path ) {
				$in_family  = count( array_filter( $held, static fn( $row ): bool => ( $row['name'] ?? null ) === $family_name ) );
				$in_primary = count( array_filter( $held, static fn( $row ): bool => ( $row['name'] ?? null ) === $primary_path ) );
				if ( $in_family + 1 > $in_primary ) {
					return self::fail( 'outranks_primary_path', '%s would give %s more edges than %s, this hunter\'s own primary path.', [ $power_name, $family_name, $primary_path ] );
				}
			}
		}

		$pool_block = (string) ( $spent_from->pool_block ?? '' );
		$pool_field = is_string( $family->{$field} ?? null ) ? $family->{$field} : '';
		$pool_value = $sheet_data[ $pool_block ][ $pool_field ] ?? null;
		$permanent  = is_array( $pool_value ) ? (int) ( $pool_value['permanent'] ?? 0 ) : (int) $pool_value;
		$spent      = is_array( $pool_value ) ? (int) ( $pool_value['spent'] ?? 0 ) : 0;
		$unspent    = $permanent - $spent;

		if ( $unspent < $cost ) {
			return self::fail( 'not_enough_unspent', '%s needs %d unspent %s Trait(s) - this hunter has %d.', [ $power_name, $cost, $pool_field, max( 0, $unspent ) ] );
		}

		// Stamped for display only (describeChange.ts / Change_Description.php); never read back for pricing or
		// mutation.
		$data['trait']['spent_rank'] = $rank;
		$data['trait']['spent_cost'] = $cost;
		$data['trait']['spent_pool'] = $pool_field;

		return [ 'ok' => true, 'change_data' => $data ];
	}

	/**
	 * Refuses raising a `raised_by` pool by more than one dot at a time, and refuses it without enough temporary
	 * points in the pool it converts from. A Storyteller bypasses both - the same `is_manager` exception every other
	 * rule in this validator already gives (trait_list's allow_custom, tiered_power, identity's protected fields, XP).
	 *
	 * @param object              $definition
	 * @param array<string,mixed> $sheet_data
	 * @param array<string,mixed> $data       The already-resolved `change_data` (`values`, one pool).
	 * @param bool                $is_manager
	 * @return array{ok:bool,code?:string,message?:string,args?:array<int,mixed>}
	 */
	private static function validate_raised_by( $definition, array $sheet_data, array $data, bool $is_manager ): array {
		if ( $is_manager ) {
			return [ 'ok' => true, 'change_data' => $data ];
		}
		$values = is_array( $data['values'] ?? null ) ? $data['values'] : [];
		foreach ( $values as $pool_name => $new_value ) {
			$pool_def = null;
			foreach ( ( $definition->pools ?? [] ) as $pool ) {
				if ( isset( $pool->name ) && $pool->name === $pool_name ) {
					$pool_def = $pool;
					break;
				}
			}
			if ( $pool_def === null || ! isset( $pool_def->raised_by ) ) {
				continue;
			}

			$block_slug    = (string) ( $data['block_slug'] ?? '' );
			$old_value     = $sheet_data[ $block_slug ][ $pool_name ] ?? null;
			$old_permanent = is_array( $old_value ) ? (int) ( $old_value['permanent'] ?? ( $pool_def->default_start ?? 0 ) ) : (int) ( $old_value ?? ( $pool_def->default_start ?? 0 ) );
			$new_permanent = is_array( $new_value ) ? (int) ( $new_value['permanent'] ?? $old_permanent ) : (int) $new_value;

			if ( $new_permanent <= $old_permanent ) {
				continue; // Lowering or unchanged - not a raise, nothing to convert.
			}
			if ( $new_permanent - $old_permanent !== 1 ) {
				return self::fail( 'invalid_param', '%s can only be raised one dot at a time.', [ (string) $pool_name ] );
			}

			$raised_by  = $pool_def->raised_by;
			[ $from_block, $from_field ] = array_pad( explode( '.', (string) ( $raised_by->from ?? '' ), 2 ), 2, '' );
			$from_value = $sheet_data[ $from_block ][ $from_field ] ?? null;
			$temporary  = is_array( $from_value ) ? (int) ( $from_value['temporary'] ?? 0 ) : 0;
			$needed     = (int) ( $raised_by->temporary ?? 0 );

			if ( $temporary < $needed ) {
				return self::fail( 'not_enough_temporary', 'Raising %s needs %d temporary %s Trait(s) - this hunter has %d.', [ (string) $pool_name, $needed, $from_field, $temporary ] );
			}

			// Stamped for display only (describeChange.ts / Change_Description.php).
			if ( is_array( $data['values'][ $pool_name ] ) ) {
				$data['values'][ $pool_name ]['raised_cost'] = $needed;
				$data['values'][ $pool_name ]['raised_from'] = $from_field;
			}
		}
		return [ 'ok' => true, 'change_data' => $data ];
	}

	/**
	 * @param string                  $block_slug
	 * @param object                  $definition
	 * @param array<int|string,mixed> $held
	 * @param array<string,mixed>     $data
	 * @param bool                    $is_manager
	 * @return Validation
	 */
	private static function validate_resource( string $block_slug, $definition, array $held, array $data, bool $is_manager ): array {
		$values = $data['values'] ?? null;
		// One pool per change - what the editor sends.
		if ( ! is_array( $values ) || count( $values ) !== 1 ) {
			return self::fail( 'invalid_param', 'A resource change must name exactly one pool.' );
		}

		$pools = [];
		foreach ( ( $definition->pools ?? [] ) as $pool ) {
			if ( isset( $pool->name ) && is_string( $pool->name ) ) {
				$pools[ $pool->name ] = $pool;
			}
		}

		$requested = (string) array_key_first( $values );
		$name      = self::resolve_name( $requested, array_keys( $pools ) );
		if ( $name === null ) {
			return self::fail( 'unknown_pool', '"%s" is not a pool on this sheet.', [ self::text( $requested, 200 ) ] );
		}

		$raw = $values[ $requested ];
		if ( is_array( $raw ) ) {
			$value = [];
			foreach ( [ 'permanent', 'temporary' ] as $part ) {
				if ( array_key_exists( $part, $raw ) ) {
					$number = self::to_int( $raw[ $part ] );
					if ( $number === null || $number < 0 || $number > self::MAX_POOL ) {
						return self::fail( 'invalid_param', "{$part} must be a whole number from 0 to " . self::MAX_POOL . '.' );
					}
					$value[ $part ] = $number;
				}
			}
			if ( $value === [] ) {
				return self::fail( 'invalid_param', 'A pool value needs a permanent or temporary rating.' );
			}
		} else {
			$value = self::to_int( $raw );
			if ( $value === null || $value < 0 || $value > self::MAX_POOL ) {
				return self::fail( 'invalid_param', 'A pool rating must be a whole number from 0 to ' . self::MAX_POOL . '.' );
			}
		}

		// A pool with no XP price is awarded - unless it converts another pool's temporary points instead.
		if ( ! $is_manager && ! isset( $pools[ $name ]->cost_per_dot ) && ! isset( $pools[ $name ]->sliding_cost ) && ! isset( $pools[ $name ]->raised_by ) ) {
			$new_permanent = is_array( $value ) ? ( $value['permanent'] ?? null ) : $value;
			$old           = $held[ $name ] ?? null;
			$old_permanent = is_array( $old ) ? ( $old['permanent'] ?? null ) : $old;
			if ( $new_permanent !== null && (int) $new_permanent !== (int) ( $old_permanent ?? ( $pools[ $name ]->default_start ?? 0 ) ) ) {
				return self::fail( 'pool_not_purchasable', "%s's permanent rating is set by a Storyteller.", [ $name ] );
			}
		}

		// A player cannot raise a pool past its own maximum; a Storyteller sets any rating.
		if ( ! $is_manager && isset( $pools[ $name ]->max ) && is_numeric( $pools[ $name ]->max ) ) {
			$new_permanent = is_array( $value ) ? ( $value['permanent'] ?? null ) : $value;
			$old           = $held[ $name ] ?? null;
			$old_permanent = (int) ( is_array( $old ) ? ( $old['permanent'] ?? ( $pools[ $name ]->default_start ?? 0 ) ) : ( $old ?? ( $pools[ $name ]->default_start ?? 0 ) ) );
			if ( $new_permanent !== null && (int) $new_permanent > (int) $pools[ $name ]->max && (int) $new_permanent > $old_permanent ) {
				return self::fail( 'pool_above_maximum', "%s can't be raised above %d.", [ $name, (int) $pools[ $name ]->max ] );
			}
		}

		return [ 'ok' => true, 'change_data' => [ 'block_slug' => $block_slug, 'values' => [ $name => $value ] ] ];
	}

	/**
	 * @param string              $block_slug
	 * @param object              $definition
	 * @param array<string,mixed> $data
	 * @param bool                $is_manager
	 * @param string[]            $protected_fields
	 * @return Validation
	 */
	private static function validate_identity( string $block_slug, $definition, array $data, bool $is_manager, array $protected_fields ): array {
		$fields = $data['fields'] ?? null;
		if ( ! is_array( $fields ) || count( $fields ) !== 1 ) {
			return self::fail( 'invalid_param', 'An identity change must name exactly one field.' );
		}

		$defined = [];
		foreach ( ( $definition->fields ?? [] ) as $field ) {
			if ( isset( $field->name ) && is_string( $field->name ) ) {
				$defined[ $field->name ] = $field;
			}
		}

		$requested = (string) array_key_first( $fields );
		$name      = self::resolve_name( $requested, array_keys( $defined ) );
		if ( $name === null ) {
			return self::fail( 'unknown_field', '"%s" is not a field on this sheet.', [ self::text( $requested, 200 ) ] );
		}

		$field   = $defined[ $name ];
		$raw     = $fields[ $requested ];
		$options = array_values( array_filter( (array) ( $field->options ?? [] ), 'is_string' ) );
		$strict  = $options !== [] && empty( $field->allow_custom );
		$type    = (string) ( $field->field_type ?? 'text' );

		if ( $type === 'multiselect' ) {
			if ( ! is_array( $raw ) ) {
				return self::fail( 'invalid_param', "{$name} takes a list of choices." );
			}
			$value = [];
			foreach ( $raw as $choice ) {
				if ( ! is_string( $choice ) ) {
					return self::fail( 'invalid_param', "{$name} takes a list of choices." );
				}
				$resolved = $strict ? self::resolve_name( $choice, $options ) : self::text( $choice, 200 );
				if ( $resolved === null ) {
					return self::fail( 'unknown_option', '"%1$s" is not a choice for %2$s.', [ self::text( $choice, 200 ), $name ] );
				}
				$value[] = $resolved;
			}
		} elseif ( $type === 'number' ) {
			$value = $raw === '' || $raw === null ? '' : self::to_int( $raw );
			if ( $value === null ) {
				return self::fail( 'invalid_param', "{$name} must be a whole number." );
			}
		} else {
			if ( ! is_string( $raw ) && ! is_int( $raw ) ) {
				return self::fail( 'invalid_param', "{$name} must be text." );
			}
			$raw = (string) $raw;
			if ( $strict && $raw !== '' ) {
				$value = self::resolve_name( $raw, $options );
				if ( $value === null ) {
					return self::fail( 'unknown_option', '"%1$s" is not a choice for %2$s.', [ self::text( $raw, 200 ), $name ] );
				}
			} elseif ( $type === 'textarea' ) {
				// The one identity field type that is long-form prose.
				$value = mb_substr( trim( wp_kses_post( $raw ) ), 0, self::MAX_TEXT );
			} else {
				$value = self::text( $raw );
			}
		}

		if ( ! $is_manager && ( $value === '' || $value === [] ) && in_array( "{$block_slug}.{$name}", $protected_fields, true ) ) {
			return self::fail( 'field_required', '%s cannot be cleared - ask a Storyteller.', [ $name ] );
		}

		return [ 'ok' => true, 'change_data' => [ 'block_slug' => $block_slug, 'fields' => [ $name => $value ] ] ];
	}

	/**
	 * Returns the candidate matching `$name` exactly, or else the single candidate matching it case- and
	 * surrounding-space-insensitively, or null.
	 *
	 * @param string   $name
	 * @param string[] $candidates
	 * @return string|null
	 */
	public static function resolve_name( string $name, array $candidates ): ?string {
		if ( in_array( $name, $candidates, true ) ) {
			return $name;
		}
		$wanted  = mb_strtolower( trim( $name ) );
		$matches = array_values( array_filter( $candidates, static fn( $candidate ) => mb_strtolower( trim( $candidate ) ) === $wanted ) );
		return count( $matches ) === 1 ? $matches[0] : null;
	}

	/**
	 * Keeps the editor's display-only `previous` snapshot alongside the validated keys.
	 *
	 * @param array<string,mixed> $data
	 * @param array<string,mixed> $normalized
	 * @return array<string,mixed>
	 */
	private static function with_display_keys( array $data, array $normalized ): array {
		if ( isset( $data['previous'] ) && is_array( $data['previous'] ) ) {
			$normalized['previous'] = $data['previous'];
		}
		return $normalized;
	}

	/**
	 * An integer from an int or a digit string (optionally negative), or null.
	 *
	 * @param mixed $value
	 * @return int|null
	 */
	private static function to_int( $value ): ?int {
		if ( is_int( $value ) ) {
			return $value;
		}
		if ( is_float( $value ) && floor( $value ) === $value ) {
			return (int) $value;
		}
		if ( is_string( $value ) && preg_match( '/^-?\d+$/', trim( $value ) ) ) {
			return (int) trim( $value );
		}
		return null;
	}

	/**
	 * Plain text: tags stripped, trimmed, capped.
	 *
	 * @param string $value
	 * @param int    $max
	 * @return string
	 */
	private static function text( string $value, int $max = self::MAX_TEXT ): string {
		return mb_substr( trim( strip_tags( $value ) ), 0, $max );
	}

	/**
	 * A failure: a machine code, an English sprintf format, and its arguments.
	 *
	 * @param string   $code
	 * @param string   $format
	 * @param string[] $args
	 * @return array{ok:false,code:string,message:string,args:string[]}
	 */
	private static function fail( string $code, string $format, array $args = [] ): array {
		return [ 'ok' => false, 'code' => $code, 'message' => $args ? vsprintf( $format, $args ) : $format, 'args' => $args ];
	}
}
