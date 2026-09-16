<?php

namespace BeyondElysium\Services;

use BeyondElysium\Models\World_Object;

defined( 'ABSPATH' ) || exit;

/**
 * Validates and normalizes a submitted character change before anything prices,
 * routes, or applies it.
 *
 * The engines trust the shape of `change_data`: a trait name that misses the
 * catalog by one letter priced at 0 XP and was stored anyway, `allow_custom`
 * was enforced only when the client volunteered `custom: true`, approval rules
 * read only the first pool or field of a multi-key change while every key was
 * applied, and a level sent as a string slipped past per-level rules
 * (1.0.0-review F-030). Every check here closes one of those.
 *
 * Pure: no database or WordPress calls, so it is unit-tested directly. The REST
 * layer supplies the character's resolved stack blocks (a chronicle's own
 * forks in place of the global catalog), the character's current sheet, and
 * whether the submitter is a Storyteller of this chronicle.
 */
class Change_Validator {

	/** Change types the REST route accepts. `import_note` is written by the importer itself, never submitted. */
	const REST_CHANGE_TYPES = [ 'add_trait', 'remove_trait', 'modify_trait', 'modify_resource', 'modify_identity', 'xp_earn', 'xp_adjust', 'propose_world_object' ];

	/** Keys a trait_list entry may carry. */
	const TRAIT_LIST_KEYS = [ 'name', 'count', 'specialization', 'note', 'custom', 'chosen_cost' ];

	/** Keys a tiered_power entry may carry. */
	const TIERED_POWER_KEYS = [ 'name', 'level', 'power_name', 'tradition', 'custom' ];

	/** Upper bounds that keep a stored value sane; far above any real sheet. */
	const MAX_COUNT = 999;
	const MAX_LEVEL = 99;
	const MAX_POOL  = 999;
	const MAX_TEXT  = 5000;

	/**
	 * Validates one change and returns it normalized: unknown keys dropped,
	 * names set to the catalog's exact spelling, numbers cast to integers, and
	 * `custom` set only where the block genuinely allows a custom entry.
	 *
	 * @param array                $change             `change_type` and `change_data`.
	 * @param array<string,object> $blocks             The character's stack blocks keyed by slug, each with `section_type` and a decoded `definition`.
	 * @param array                $sheet_data         The character's current sheet_data.
	 * @param bool                 $is_manager         Whether the submitter is a Storyteller of this chronicle.
	 * @param string[]             $protected_fields   "block_slug.Field" identity fields a non-Storyteller may not clear - the ones Discipline pricing reads (in_type_source).
	 * @return array{ok:bool,change_data?:array,code?:string,message?:string}
	 */
	public static function validate( array $change, array $blocks, array $sheet_data, bool $is_manager, array $protected_fields = [] ): array {
		$type = $change['change_type'] ?? '';
		if ( ! is_string( $type ) || ! in_array( $type, self::REST_CHANGE_TYPES, true ) ) {
			return self::fail( 'invalid_change_type', 'That kind of change cannot be submitted.' );
		}

		$data = $change['change_data'] ?? null;
		if ( ! is_array( $data ) ) {
			return self::fail( 'invalid_param', 'change_data must be an object.' );
		}

		if ( $type === 'xp_earn' || $type === 'xp_adjust' ) {
			return self::validate_xp( $data );
		}

		// A proposed catalog item is not sheet data, so it names no block (1.0.1 D3).
		if ( $type === 'propose_world_object' ) {
			return self::validate_proposed_object( $data );
		}

		$block_slug = $data['block_slug'] ?? null;
		if ( ! is_string( $block_slug ) || ! isset( $blocks[ $block_slug ] ) ) {
			return self::fail( 'unknown_block', "That section isn't part of this character's sheet." );
		}
		$block      = $blocks[ $block_slug ];
		$definition = $block->definition ?? new \stdClass();
		$held       = $sheet_data[ $block_slug ] ?? [];

		switch ( $type ) {
			case 'add_trait':
			case 'remove_trait':
			case 'modify_trait':
				if ( $block->section_type === 'trait_list' ) {
					return self::validate_trait_list( $type, $block_slug, $definition, is_array( $held ) ? $held : [], $data, $is_manager );
				}
				if ( $block->section_type === 'tiered_power' ) {
					return self::validate_tiered_power( $type, $block_slug, $definition, is_array( $held ) ? $held : [], $data, $is_manager );
				}
				return self::fail( 'wrong_section_type', 'That change does not fit this section.' );

			case 'modify_resource':
				if ( $block->section_type !== 'resource_pool' ) {
					return self::fail( 'wrong_section_type', 'That change does not fit this section.' );
				}
				return self::validate_resource( $block_slug, $definition, is_array( $held ) ? $held : [], $data, $is_manager );

			default: // modify_identity - the only block-based type left after the allowlist check.
				if ( $block->section_type !== 'identity_field' ) {
					return self::fail( 'wrong_section_type', 'That change does not fit this section.' );
				}
				return self::validate_identity( $block_slug, $definition, $data, $is_manager, $protected_fields );
		}
	}

	/**
	 * The identity fields a stack's Discipline pricing reads, as "block_slug.Field" -
	 * each section's `in_type_source` (for example "vampire-identity.Clan").
	 *
	 * @param object|null $stack A decoded creature stack row.
	 * @return string[]
	 */
	public static function protected_fields( $stack ): array {
		$fields = [];
		foreach ( ( $stack->stack_definition->sections ?? [] ) as $section ) {
			if ( ! empty( $section->in_type_source ) && is_string( $section->in_type_source ) ) {
				$fields[] = $section->in_type_source;
			}
		}
		return array_values( array_unique( $fields ) );
	}

	/**
	 * @param array $data
	 * @return array
	 */
	/**
	 * A player's proposed catalog item, location or rote (1.0.1 D3).
	 *
	 * Validated here, on the way in, rather than trusted at approval time - a Storyteller
	 * approving from the queue should be approving something already known to be well-formed,
	 * not discovering at write time that the object type was invented. The per-type property
	 * rules are `World_Object`'s own, never a second copy of them.
	 *
	 * @param array $data
	 * @return array
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

		// Validated here, sanitized at write time by `World_Object::create()` - which every
		// catalog write already goes through, so there is no second copy of those rules here.
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

	private static function validate_xp( array $data ): array {
		$amount = self::to_int( $data['amount'] ?? null );
		if ( $amount === null || $amount === 0 ) {
			return self::fail( 'invalid_param', 'amount must be a whole number other than zero.' );
		}
		$normalized = [ 'amount' => $amount ];
		if ( isset( $data['reason'] ) && is_string( $data['reason'] ) ) {
			$normalized['reason'] = self::text( $data['reason'] );
		}
		return [ 'ok' => true, 'change_data' => $normalized ];
	}

	/**
	 * @param string $type
	 * @param string $block_slug
	 * @param object $definition
	 * @param array  $held
	 * @param array  $data
	 * @param bool   $is_manager A Storyteller may add a custom entry to any section; a player only where the section allows one.
	 * @return array
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
		if ( $resolved !== null ) {
			// A catalog name is priced from the catalog - never as a free custom entry.
			$trait['name'] = $resolved;
			unset( $trait['custom'] );
		} elseif ( $type !== 'add_trait' && in_array( $trait['name'], $held_names, true ) ) {
			// Changing or removing something the character already holds, even if the catalog
			// has since dropped it.
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
		foreach ( [ 'specialization', 'note' ] as $text_key ) {
			if ( array_key_exists( $text_key, $trait ) ) {
				$trait[ $text_key ] = is_string( $trait[ $text_key ] ) ? self::text( $trait[ $text_key ] ) : '';
			}
		}
		if ( empty( $trait['custom'] ) ) {
			unset( $trait['custom'] );
		}

		return [ 'ok' => true, 'change_data' => self::with_display_keys( $data, [ 'block_slug' => $block_slug, 'trait' => $trait ] ) ];
	}

	/**
	 * @param string $type
	 * @param string $block_slug
	 * @param object $definition
	 * @param array  $held
	 * @param array  $data
	 * @param bool   $is_manager A Storyteller may add a custom power to any section; a player only where the section allows one.
	 * @return array
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
		if ( $family !== null ) {
			$trait['name'] = $family;
			unset( $trait['custom'] );

			if ( $power_name !== null ) {
				$picks = [];
				foreach ( ( $families[ $family ]->levels ?? [] ) as $rung ) {
					if ( isset( $rung->power_name ) && is_string( $rung->power_name ) && $rung->power_name !== '' ) {
						$picks[] = $rung->power_name;
					}
				}
				$pick = self::resolve_name( $power_name, $picks );
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
		}
		if ( empty( $trait['custom'] ) ) {
			unset( $trait['custom'] );
		}

		return [ 'ok' => true, 'change_data' => self::with_display_keys( $data, [ 'block_slug' => $block_slug, 'trait' => $trait ] ) ];
	}

	/**
	 * @param string $block_slug
	 * @param object $definition
	 * @param array  $held
	 * @param array  $data
	 * @param bool   $is_manager
	 * @return array
	 */
	private static function validate_resource( string $block_slug, $definition, array $held, array $data, bool $is_manager ): array {
		$values = $data['values'] ?? null;
		// One pool per change - what the editor sends - so approval can never judge one pool
		// while applying another.
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

		// A pool with no XP price is awarded, not bought (Renown), or follows from something
		// else (Blood) - a player may spend and regain its temporary points, never set its
		// permanent rating themselves.
		if ( ! $is_manager && ! isset( $pools[ $name ]->cost_per_dot ) ) {
			$new_permanent = is_array( $value ) ? ( $value['permanent'] ?? null ) : $value;
			$old           = $held[ $name ] ?? null;
			$old_permanent = is_array( $old ) ? ( $old['permanent'] ?? null ) : $old;
			if ( $new_permanent !== null && (int) $new_permanent !== (int) ( $old_permanent ?? ( $pools[ $name ]->default_start ?? 0 ) ) ) {
				return self::fail( 'pool_not_purchasable', "%s's permanent rating is set by a Storyteller.", [ $name ] );
			}
		}

		return [ 'ok' => true, 'change_data' => [ 'block_slug' => $block_slug, 'values' => [ $name => $value ] ] ];
	}

	/**
	 * @param string   $block_slug
	 * @param object   $definition
	 * @param array    $data
	 * @param bool     $is_manager
	 * @param string[] $protected_fields
	 * @return array
	 */
	private static function validate_identity( string $block_slug, $definition, array $data, bool $is_manager, array $protected_fields ): array {
		$fields = $data['fields'] ?? null;
		// One field per change, for the same reason as one pool per change.
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
				// The one identity field type that is long-form prose rather than a value, so
				// the one that gets rich text (1.0.1 D1). `self::text()` runs strip_tags(), which
				// is why an NPC's roleplaying notes could never hold so much as a line break's
				// worth of markup before this. Same allowlist as biography/notes and every plot
				// free-text field; the length cap is unchanged.
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
	 * Returns the candidate matching `$name` exactly, or else the single
	 * candidate matching it case- and surrounding-space-insensitively, or null.
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
	 * Keeps the editor's display-only `previous` snapshot alongside the
	 * validated keys; it is never applied or priced.
	 *
	 * @param array $data
	 * @param array $normalized
	 * @return array
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
	 * The REST layer translates the codes a player can meet; `message` is the
	 * English fallback with the arguments filled in.
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
