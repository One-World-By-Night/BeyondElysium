<?php

namespace BeyondElysium\Services;

defined( 'ABSPATH' ) || exit;

/**
 * What makes one held `trait_list` row the same holding as another, rather than a second one
 * (1.2.11 D86/D88).
 *
 * A specialization labels ONE holding: `Brawl 5 (Wrestling)` is a single Brawl at 5, and
 * choosing a different focus must never let a player hold a second Brawl. What genuinely makes
 * the label part of a holding's identity is `allow_multiples` - `Retainers x3 (John Doe)` and
 * `Retainers x2 (Sue Smith)` are two real purchases, as are the field-of-study Abilities
 * (Lore, Crafts, Science, Performance, Linguistics, Hobby/Professional/Expert, City Secrets).
 *
 * This class exists for the same reason `Power_Levels` does: every consumer that decides
 * *which* held row a change means reads the rule from one place, so no second copy of it can
 * drift. The consumers are `Change_Validator` (refusing a duplicate), `Change_Engine`
 * (the pending-duplicate key, and applying a change to the sheet) and `Cost_Engine` (pricing a
 * change against what is already held). `src/lib/traitIdentity.ts` is its TypeScript twin.
 *
 * Pure: no database or WordPress calls. Callers hand it a block definition they have already
 * resolved - the chronicle's fork where one exists, never the global catalog by assumption.
 */
class Trait_Identity {

	/** The separator inside a composite identity. It cannot appear in a name or a label. */
	const SEPARATOR = "\0";

	/**
	 * Whether one catalog item may be held more than once, each holding labelled by its own
	 * specialization. The item's own `allow_multiples` wins when it states one; otherwise the
	 * block's flag is the default, and an item the catalog does not list - a custom entry -
	 * takes that block default too.
	 *
	 * @param object|null $definition A decoded `trait_list` block definition.
	 * @param string      $name
	 * @return bool
	 */
	public static function allows_multiples( $definition, string $name ): bool {
		foreach ( ( $definition->items ?? [] ) as $item ) {
			if ( isset( $item->name ) && is_string( $item->name ) && $item->name === $name ) {
				if ( isset( $item->allow_multiples ) ) {
					return (bool) $item->allow_multiples;
				}
				break;
			}
		}
		return ! empty( $definition->allow_multiples );
	}

	/**
	 * The identity of one held row: its `name` alone, or the name and its label joined by a
	 * NUL when the item is multiples-capable. Amends Decision 082, which made it
	 * name+specialization for every non-atomic block.
	 *
	 * @param object|null $definition
	 * @param string      $name
	 * @param string|null $label
	 * @return string
	 */
	public static function of( $definition, string $name, ?string $label ): string {
		return self::allows_multiples( $definition, $name )
			? $name . self::SEPARATOR . ( $label ?? '' )
			: $name;
	}

	/**
	 * The identity of one held `tiered_power` row: the family name alone for a plain numbered
	 * holding (at most one per family), or family + `power_name` for an Elder-and-above pick,
	 * since a family holds several distinct picks at once (Decision 037).
	 *
	 * The same rule `computeChanges.ts`'s `tieredPowerKey()` has always used on the client and
	 * `Cost_Engine::find_held_power()` on the server - and the rule the change engine did not
	 * use, which is D89: removing one pick removed every pick of its family, and two pending
	 * picks of one family collapsed into a single queued change.
	 *
	 * @param string      $name
	 * @param string|null $power_name
	 * @return string
	 */
	public static function of_power( string $name, ?string $power_name ): string {
		return $power_name !== null && $power_name !== '' ? $name . self::SEPARATOR . $power_name : $name;
	}

	/**
	 * The identity of one stored sheet row, or null when the row carries no usable name.
	 *
	 * Covers both section types from one entry point, because the two shapes are disjoint: a
	 * `tiered_power` row carries `power_name` and never a `specialization`, a `trait_list` row
	 * the other way round. A caller applying or pricing a change therefore needs no branch of
	 * its own.
	 *
	 * @param object|null         $definition
	 * @param array<string,mixed> $row
	 * @return string|null
	 */
	public static function of_row( $definition, array $row ): ?string {
		$name = $row['name'] ?? null;
		if ( ! is_string( $name ) || $name === '' ) {
			return null;
		}
		if ( isset( $row['power_name'] ) && is_string( $row['power_name'] ) && $row['power_name'] !== '' ) {
			return self::of_power( $name, $row['power_name'] );
		}
		$label = isset( $row['specialization'] ) && is_string( $row['specialization'] ) ? $row['specialization'] : '';
		return self::of( $definition, $name, $label );
	}

	/**
	 * The index of the first held row with this identity, or null when the character holds
	 * none. This is what "which row does this change mean" resolves to everywhere.
	 *
	 * @param object|null $definition
	 * @param array       $held     `sheet_data[block_slug]`.
	 * @param string      $identity From `of()` or `of_row()`.
	 * @return int|null
	 */
	public static function index_of( $definition, array $held, string $identity ): ?int {
		foreach ( array_values( $held ) as $index => $row ) {
			if ( is_array( $row ) && self::of_row( $definition, $row ) === $identity ) {
				return $index;
			}
		}
		return null;
	}

	/**
	 * Which held row a `modify_trait` or `remove_trait` addresses, as an identity.
	 *
	 * A change names the row it acts on by its label - but a relabel changes exactly that, so
	 * the row is addressed by the label it had before the edit when the client states one.
	 * `previous` is display-only everywhere else (`Change_Validator::with_display_keys()`);
	 * this is the single case where it identifies rather than decorates, because nothing else
	 * in the payload can express "the holding that used to be called X". For an item that is
	 * not multiples-capable the label is not part of the identity at all, so this is just the
	 * name and `previous` changes nothing.
	 *
	 * @param object|null $definition
	 * @param array       $trait    The change's own normalized trait.
	 * @param array|null  $previous The change's `previous` snapshot, when it carries one.
	 * @return string|null
	 */
	public static function target_of( $definition, array $trait, ?array $previous ): ?string {
		$name = $trait['name'] ?? null;
		if ( ! is_string( $name ) || $name === '' ) {
			return null;
		}

		// A tiered_power pick names itself; nothing about it can be relabelled in place.
		if ( isset( $trait['power_name'] ) && is_string( $trait['power_name'] ) && $trait['power_name'] !== '' ) {
			return self::of_power( $name, $trait['power_name'] );
		}

		if ( is_array( $previous )
			&& ( $previous['name'] ?? $name ) === $name
			&& isset( $previous['specialization'] )
			&& is_string( $previous['specialization'] )
		) {
			return self::of( $definition, $name, $previous['specialization'] );
		}

		$label = isset( $trait['specialization'] ) && is_string( $trait['specialization'] ) ? $trait['specialization'] : null;
		return self::of( $definition, $name, $label );
	}
}
