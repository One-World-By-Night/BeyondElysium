<?php

namespace BeyondElysium\Services;

use BeyondElysium\Database\Transaction;
use BeyondElysium\Models\Change;
use BeyondElysium\Models\Character;
use BeyondElysium\Models\Creature_Stack;
use BeyondElysium\Models\Schema_Block;

defined( 'ABSPATH' ) || exit;

/**
 * A Storyteller's edit to a pending change: the change's own content, what it costs and a note to the player. An edit
 * never approves or rejects anything.
 */
class Change_Edit {

	/**
	 * The longest note to the player.
	 */
	const NOTE_MAX = 5000;

	/**
	 * Change types a price can be set on.
	 */
	const PURCHASE_TYPES = [ 'add_trait', 'modify_trait', 'modify_resource' ];

	/**
	 * Change types whose content `Change_Validator` checks against the character's sheet.
	 */
	const SHEET_TYPES = [ 'add_trait', 'modify_trait', 'modify_resource', 'modify_identity' ];

	/**
	 * Change types that carry an amount of experience.
	 */
	const XP_TYPES = [ 'xp_earn', 'xp_adjust' ];

	/**
	 * Keys a Storyteller's edit adds beside the change's own content.
	 */
	const REVIEW_KEYS = [ 'cost_pending', 'st_price', 'st_edit' ];

	/**
	 * A reviewer's note as it is stored: plain text, no longer than `NOTE_MAX`. Null stays null.
	 *
	 * @param mixed $raw
	 * @return string|null
	 */
	public static function clean_note( $raw ): ?string {
		if ( $raw === null ) {
			return null;
		}
		return mb_substr( sanitize_textarea_field( (string) $raw ), 0, self::NOTE_MAX );
	}

	/**
	 * The fields an edit may change on a change, named the way a request names them.
	 *
	 * @param string              $change_type
	 * @param array<string,mixed> $change_data
	 * @param string|null         $section_type The section type of the block the change is on.
	 * @return string[]
	 */
	public static function editable_fields( string $change_type, array $change_data, ?string $section_type ): array {
		$trait = is_array( $change_data['trait'] ?? null ) ? $change_data['trait'] : [];

		switch ( $change_type ) {
			case 'add_trait':
			case 'modify_trait':
				$movable = $change_type === 'add_trait' && ! empty( $trait['custom'] );
				if ( $section_type === 'trait_list' ) {
					$fields = $change_type === 'add_trait' ? [ 'name' ] : [];
					if ( $movable ) {
						array_unshift( $fields, 'block_slug' );
					}
					foreach ( [ 'count', 'specialization' ] as $key ) {
						if ( array_key_exists( $key, $trait ) ) {
							$fields[] = $key;
						}
					}
					$fields[] = 'note';
					return $fields;
				}
				if ( $section_type === 'tiered_power' ) {
					$fields = $movable ? [ 'block_slug', 'name', 'power_name' ] : [];
					if ( isset( $trait['level'] ) ) {
						$fields[] = 'level';
					}
					return $fields;
				}
				return [];

			case 'modify_resource':
				$values = is_array( $change_data['values'] ?? null ) ? $change_data['values'] : [];
				if ( count( $values ) !== 1 ) {
					return [];
				}
				$value = reset( $values );
				return is_array( $value ) && isset( $value['raised_cost'] ) ? [] : [ 'permanent' ];

			case 'modify_identity':
				$fields = is_array( $change_data['fields'] ?? null ) ? $change_data['fields'] : [];
				return count( $fields ) === 1 && is_scalar( reset( $fields ) ) ? [ 'value' ] : [];

			case 'xp_earn':
			case 'xp_adjust':
				return [ 'amount', 'reason' ];

			default:
				return [];
		}
	}

	/**
	 * What the queue shows beside a change for a Storyteller to edit it: the fields an edit may change and, for a
	 * purchase, what a price would cover.
	 *
	 * @param object|null $character
	 * @param object      $change    A decoded change row.
	 * @return array{editable_fields:string[],price_basis:array<string,mixed>|null,block_name:string|null}
	 */
	public static function review_extras( ?object $character, object $change ): array {
		$data  = is_array( $change->change_data ) ? $change->change_data : [];
		$block = $character ? self::block( $character, $data ) : null;

		return [
			'editable_fields' => self::editable_fields( (string) $change->change_type, $data, $block->section_type ?? null ),
			'price_basis'     => $character && $block ? self::price_basis( $character, $change, $block ) : null,
			'block_name'      => $block ? (string) ( $block->name ?? '' ) : null,
		];
	}

	/**
	 * The sections an added entry could be filed in instead: every visible section of the character's creature type
	 * that takes the same kind of entry as the one it is on and accepts entries of its own, the current one included.
	 * Empty for a change that cannot move.
	 *
	 * @param object              $character
	 * @param string              $change_type
	 * @param array<string,mixed> $change_data
	 * @return array<string,string> Block slug => section name.
	 */
	public static function section_choices( object $character, string $change_type, array $change_data ): array {
		$trait = is_array( $change_data['trait'] ?? null ) ? $change_data['trait'] : [];
		$block = self::block( $character, $change_data );
		if ( $change_type !== 'add_trait' || empty( $trait['custom'] ) || ! $block || ! in_array( $block->section_type ?? '', [ 'trait_list', 'tiered_power' ], true ) ) {
			return [];
		}

		$resolved = Creature_Stack::resolve( (string) $character->stack_slug, (string) $character->owner_slug, self::slugs_in_play( $character, $change_data ) );
		$closed   = Creature_Stack::closed_blocks( $resolved['stack'] ?? null );
		$choices  = [];
		foreach ( ( $resolved['blocks'] ?? [] ) as $slug => $candidate ) {
			$takes_own = ! empty( $candidate->definition->allow_custom ) || (string) $slug === (string) ( $change_data['block_slug'] ?? '' );
			if ( ( $candidate->section_type ?? '' ) === $block->section_type && $takes_own && ! in_array( $slug, $closed, true ) ) {
				$choices[ (string) $slug ] = (string) ( $candidate->name ?? $slug );
			}
		}
		asort( $choices, SORT_NATURAL | SORT_FLAG_CASE );
		return $choices;
	}

	/**
	 * The block slugs to resolve a character's creature type with: the sections the sheet holds and the one the
	 * change names.
	 *
	 * @param object              $character
	 * @param array<string,mixed> $change_data
	 * @return string[]
	 */
	private static function slugs_in_play( object $character, array $change_data ): array {
		$sheet = is_array( $character->sheet_data ) ? $character->sheet_data : [];
		$held  = array_map( 'strval', array_keys( $sheet ) );
		return array_values( array_unique( array_merge( $held, is_string( $change_data['block_slug'] ?? null ) ? [ $change_data['block_slug'] ] : [] ) ) );
	}

	/**
	 * What a price would cover for a purchase: whether the book has any price for it, what one price is for (a dot,
	 * a pick or the whole change), how many of those there are, what the book charges and what a Storyteller has set.
	 * Null for a change that is not a priced purchase.
	 *
	 * @param object $character
	 * @param object $change    A decoded change row.
	 * @param object $block     The block the change is on.
	 * @return array{homebrew:bool,per:string,units:int,negative:bool,book_xp:int|null,current:int|null}|null
	 */
	public static function price_basis( object $character, object $change, object $block ): ?array {
		$type       = (string) $change->change_type;
		$definition = $block->definition ?? null;
		if ( ! in_array( $type, self::PURCHASE_TYPES, true ) || ! is_object( $definition ) || ! empty( $definition->unpriced ) ) {
			return null;
		}

		$stored = is_array( $change->change_data ) ? $change->change_data : [];
		$data   = array_diff_key( $stored, array_flip( self::REVIEW_KEYS ) );
		$quote  = Cost_Engine::quote_for_change( $character, [ 'change_type' => $type, 'change_data' => $data ], false );
		$units  = self::units( $character, $block, $type, $data );

		$typed   = is_array( $stored['st_price'] ?? null ) ? ( $stored['st_price']['amount'] ?? null ) : null;
		$stamped = is_array( $stored['trait'] ?? null ) ? ( $stored['trait']['chosen_cost'] ?? null ) : null;
		$current = $typed ?? $stamped;

		return [
			'homebrew' => ! $quote['priced'],
			'per'      => $units['per'],
			'units'    => $units['units'],
			'negative' => ! empty( $definition->negative ),
			'book_xp'  => $quote['priced'] ? $quote['xp'] : null,
			'current'  => $current === null ? null : (int) $current,
		];
	}

	/**
	 * Applies a Storyteller's edit to a pending change, in one transaction.
	 *
	 * @param int                 $change_id
	 * @param int                 $reviewer_id
	 * @param string              $token        The review token the reviewer was shown.
	 * @param array<string,mixed> $fields       New values, by the names `editable_fields()` gives.
	 * @param mixed               $price        A whole number of XP, or blank for none.
	 * @param string|null         $price_per    What the typed price is for, as the reviewer saw it; null skips the check.
	 * @param string|null         $review_notes The new note to the player; null keeps the stored one, an empty string clears it.
	 * @return array{ok:true}|array{ok:false,validation:array<string,mixed>}|\WP_Error
	 */
	public static function apply( int $change_id, int $reviewer_id, string $token, array $fields, $price, ?string $price_per, ?string $review_notes ) {
		$unit = Transaction::begin( 'be_change_edit' );

		$change = Change::find_for_update( $change_id );
		if ( ! $change || $change->status !== 'pending' ) {
			return self::refuse( $unit, 'change_not_pending', __( 'This change has already been reviewed.', 'beyond-elysium' ), 400 );
		}
		if ( ! hash_equals( Change::review_token( $change ), $token ) ) {
			return self::refuse( $unit, 'change_changed', __( 'This change was edited after you opened it. Reload the queue and review it again.', 'beyond-elysium' ), 409 );
		}

		Character::lock( (int) $change->character_id );
		$character = Character::find( (int) $change->character_id );
		if ( ! $character ) {
			return self::refuse( $unit, 'not_found', __( 'Change not found.', 'beyond-elysium' ), 404 );
		}

		$typed = null;
		if ( $price !== null && $price !== '' ) {
			$typed = Cost_Engine::usable_price( $price );
			if ( $typed === null ) {
				/* translators: %d: the most XP one price may be, 500 */
				return self::refuse( $unit, 'invalid_param', sprintf( __( 'The price must be a whole number of XP from 0 to %d.', 'beyond-elysium' ), Cost_Engine::MAX_CUSTOM_PRICE ), 400 );
			}
		}

		$type = (string) $change->change_type;
		$data = array_diff_key( is_array( $change->change_data ) ? $change->change_data : [], array_flip( self::REVIEW_KEYS ) );
		if ( is_array( $data['trait'] ?? null ) ) {
			unset( $data['trait']['chosen_cost'] );
		}

		$block        = self::block( $character, $data );
		$section_type = $block->section_type ?? null;
		$editable     = self::editable_fields( $type, $data, $section_type );
		foreach ( array_keys( $fields ) as $key ) {
			if ( ! in_array( $key, $editable, true ) ) {
				/* translators: %s: the name of a field */
				return self::refuse( $unit, 'field_not_editable', sprintf( __( 'That field cannot be edited on this change: %s', 'beyond-elysium' ), (string) $key ), 400 );
			}
		}

		$moved = null;
		if ( array_key_exists( 'block_slug', $fields ) ) {
			$target = is_string( $fields['block_slug'] ) ? $fields['block_slug'] : '';
			unset( $fields['block_slug'] );
			if ( $target !== ( $data['block_slug'] ?? '' ) ) {
				if ( $typed !== null ) {
					return self::refuse( $unit, 'price_with_move', __( 'Save the move first, then set the price.', 'beyond-elysium' ), 400 );
				}
				if ( ! isset( self::section_choices( $character, $type, $data )[ $target ] ) ) {
					return self::refuse( $unit, 'invalid_section', __( 'That section cannot take this entry.', 'beyond-elysium' ), 400 );
				}
				$data['block_slug'] = $target;
				$moved              = $target;
				$block              = self::block( $character, $data );
			}
		}

		if ( $fields !== [] || $moved !== null ) {
			$data = self::with_fields( $type, $data, $fields );

			if ( in_array( $type, self::XP_TYPES, true ) ) {
				$checked = self::check_xp( $type, $data );
				if ( is_wp_error( $checked ) ) {
					Transaction::rollback( $unit );
					return $checked;
				}
				$data = $checked;
			} elseif ( in_array( $type, self::SHEET_TYPES, true ) ) {
				$result = self::validated( $character, $type, $data );
				if ( ! $result['ok'] ) {
					Transaction::rollback( $unit );
					return [ 'ok' => false, 'validation' => $result ];
				}
				$data = (array) $result['change_data'];
			}

			$duplicate = in_array( $type, self::SHEET_TYPES, true )
				? Change_Engine::find_other_pending_duplicate( (int) $character->id, $type, $data, (string) $character->owner_slug, $change_id )
				: null;
			if ( $duplicate !== null ) {
				return self::refuse( $unit, 'duplicate_pending', __( 'Another waiting change already covers this. Review or refuse that one first.', 'beyond-elysium' ), 400 );
			}
		}

		$priced = self::priced( $character, $type, $data, $block, $typed, $price_per, (float) $change->xp_cost );
		if ( is_wp_error( $priced ) ) {
			Transaction::rollback( $unit );
			return $priced;
		}
		$data = $priced['change_data'];

		$data['st_edit'] = [ 'by' => $reviewer_id, 'at' => current_time( 'mysql' ) ];

		$note = self::clean_note( $review_notes );
		if ( ! Change::update_by_reviewer( $change_id, $data, $priced['xp'], $review_notes !== null, $note, $moved ) ) {
			return self::refuse( $unit, 'update_failed', __( 'Failed to update change status.', 'beyond-elysium' ), 500 );
		}

		Transaction::commit( $unit );
		return [ 'ok' => true ];
	}

	/**
	 * Rolls the transaction back and returns the refusal.
	 *
	 * @param mixed  $unit
	 * @param string $code
	 * @param string $message
	 * @param int    $status
	 * @return \WP_Error
	 */
	private static function refuse( $unit, string $code, string $message, int $status ): \WP_Error {
		Transaction::rollback( $unit );
		return new \WP_Error( $code, $message, [ 'status' => $status ] );
	}

	/**
	 * The block a change is on for this character's chronicle, widened by the chronicle's purchase lists.
	 *
	 * @param object              $character
	 * @param array<string,mixed> $data      The change's data.
	 * @return object|null
	 */
	private static function block( object $character, array $data ): ?object {
		$slug = $data['block_slug'] ?? null;
		if ( ! is_string( $slug ) || $slug === '' ) {
			return null;
		}
		$owner = (string) ( $character->owner_slug ?? '' );
		return Purchase_Scope::widen( Schema_Block::find_for_game( $slug, $owner ), $owner );
	}

	/**
	 * What one price covers for a change.
	 *
	 * @param object              $character
	 * @param object              $block
	 * @param string              $type
	 * @param array<string,mixed> $data
	 * @return array{per:string,units:int}
	 */
	private static function units( object $character, object $block, string $type, array $data ): array {
		if ( ! in_array( $block->section_type ?? '', [ 'trait_list', 'tiered_power' ], true ) ) {
			return [ 'per' => 'change', 'units' => 1 ];
		}
		$sheet = is_array( $character->sheet_data ) ? $character->sheet_data : [];
		return Cost_Engine::price_units( $sheet, $block->definition, (string) ( $data['block_slug'] ?? '' ), $type, $data );
	}

	/**
	 * The change's data with the edited fields put where the change keeps them.
	 *
	 * @param string              $type
	 * @param array<string,mixed> $data
	 * @param array<string,mixed> $fields
	 * @return array<string,mixed>
	 */
	private static function with_fields( string $type, array $data, array $fields ): array {
		foreach ( [ 'name', 'count', 'specialization', 'note', 'level', 'power_name' ] as $key ) {
			if ( array_key_exists( $key, $fields ) ) {
				$data['trait'][ $key ] = $fields[ $key ];
			}
		}

		if ( array_key_exists( 'permanent', $fields ) ) {
			$pool  = (string) array_key_first( $data['values'] );
			$value = $data['values'][ $pool ];
			if ( is_array( $value ) ) {
				$value['permanent'] = $fields['permanent'];
			} else {
				$value = $fields['permanent'];
			}
			$data['values'][ $pool ] = $value;
		}

		if ( array_key_exists( 'value', $fields ) ) {
			$field                    = (string) array_key_first( $data['fields'] );
			$data['fields'][ $field ] = $fields['value'];
		}

		foreach ( [ 'amount', 'reason' ] as $key ) {
			if ( array_key_exists( $key, $fields ) && in_array( $type, self::XP_TYPES, true ) ) {
				$data[ $key ] = $fields[ $key ];
			}
		}

		return $data;
	}

	/**
	 * An experience change's edited amount and reason, checked the way a Storyteller's own are, with the rest of the
	 * change - a player's request among it - kept.
	 *
	 * @param string              $type
	 * @param array<string,mixed> $data
	 * @return array<string,mixed>|\WP_Error
	 */
	private static function check_xp( string $type, array $data ) {
		$payload = [ 'amount' => $data['amount'] ?? null ];
		if ( isset( $data['reason'] ) ) {
			$payload['reason'] = $data['reason'];
		}
		$result = Change_Validator::validate( [ 'change_type' => $type, 'change_data' => $payload ], [], [], true );
		if ( ! $result['ok'] ) {
			return new \WP_Error( $result['code'] ?? 'invalid_param', $result['message'] ?? '', [ 'status' => 400 ] );
		}
		$checked = (array) $result['change_data'];
		if ( isset( $data['request'] ) && (int) $checked['amount'] < 1 ) {
			return new \WP_Error( 'invalid_param', __( 'A request is for a positive amount of experience.', 'beyond-elysium' ), [ 'status' => 400 ] );
		}
		return array_merge( $data, $checked );
	}

	/**
	 * A change's data checked against the character's sheet the way a Storyteller's own submission is.
	 *
	 * @param object              $character
	 * @param string              $type
	 * @param array<string,mixed> $data
	 * @return array<string,mixed> A `Change_Validator` result.
	 */
	private static function validated( object $character, string $type, array $data ): array {
		$sheet = is_array( $character->sheet_data ) ? $character->sheet_data : [];
		$stack = Creature_Stack::resolve( (string) $character->stack_slug, (string) $character->owner_slug, self::slugs_in_play( $character, $data ) );

		return Change_Validator::validate(
			[ 'change_type' => $type, 'change_data' => $data ],
			$stack['blocks'] ?? [],
			$sheet,
			true,
			Change_Validator::protected_fields( $stack['stack'] ?? null ),
			Creature_Stack::closed_blocks( $stack['stack'] ?? null )
		);
	}

	/**
	 * The change's data and cost once a price is applied: stamped on a homebrew trait, set for the whole change over a
	 * price that exists, or left waiting when there is none to apply.
	 *
	 * @param object              $character
	 * @param string              $type
	 * @param array<string,mixed> $data
	 * @param object|null         $block
	 * @param int|null            $typed     The price the reviewer typed.
	 * @param string|null         $price_per What the typed price is for, as the reviewer saw it.
	 * @param float               $xp        The cost the change carries now.
	 * @return array{change_data:array<string,mixed>,xp:float}|\WP_Error
	 */
	private static function priced( object $character, string $type, array $data, ?object $block, ?int $typed, ?string $price_per, float $xp ) {
		$definition = $block->definition ?? null;
		if ( $block === null || ! in_array( $type, self::PURCHASE_TYPES, true ) || ! is_object( $definition ) || ! empty( $definition->unpriced ) ) {
			if ( $typed !== null ) {
				return new \WP_Error( 'price_not_applicable', __( 'This change has no price to set.', 'beyond-elysium' ), [ 'status' => 400 ] );
			}
			return [ 'change_data' => $data, 'xp' => $xp ];
		}

		$quote    = Cost_Engine::quote_for_change( $character, [ 'change_type' => $type, 'change_data' => $data ], false );
		$units    = self::units( $character, $block, $type, $data );
		$per      = $quote['priced'] ? 'change' : $units['per'];
		$negative = ! empty( $definition->negative );

		if ( $typed !== null && $price_per !== null && $price_per !== $per ) {
			return new \WP_Error( 'price_unit_changed', __( 'This change is priced differently now. Check the price and save again.', 'beyond-elysium' ), [ 'status' => 400 ] );
		}

		if ( $quote['priced'] ) {
			if ( $typed === null ) {
				return [ 'change_data' => $data, 'xp' => (float) $quote['xp'] ];
			}
			$data['st_price'] = [ 'amount' => $typed, 'per' => 'change' ];
			return [ 'change_data' => $data, 'xp' => (float) ( $negative ? -$typed : $typed ) ];
		}

		if ( $typed === null ) {
			$data['cost_pending'] = true;
			return [ 'change_data' => $data, 'xp' => 0.0 ];
		}

		$sheet            = is_array( $character->sheet_data ) ? $character->sheet_data : [];
		$set              = Cost_Engine::apply_set_price( $sheet, $definition, (string) ( $data['block_slug'] ?? '' ), $type, $data, $typed );
		$data             = $set['change_data'];
		$data['st_price'] = [ 'amount' => $typed, 'per' => $units['per'] ];
		return [ 'change_data' => $data, 'xp' => (float) $set['xp'] ];
	}
}
