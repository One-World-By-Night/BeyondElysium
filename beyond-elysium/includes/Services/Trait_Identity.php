<?php

namespace BeyondElysium\Services;

defined( 'ABSPATH' ) || exit;

/**
 * What makes one held `trait_list` row the same holding as another.
 */
class Trait_Identity {

	/**
	 * The separator inside a composite identity.
	 */
	const SEPARATOR = "\0";

	/**
	 * Whether one catalog item may be held more than once, each holding labelled by its own specialization.
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
	 * The identity of one held row: its `name` alone, or the name and its label joined by a NUL when the item is
	 * multiples-capable.
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
	 * The identity of one held `tiered_power` row: the family name alone for a plain numbered holding (at most one per
	 * family), or family + `power_name` for an Elder-and-above pick.
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
	 * The index of the first held row with this identity, or null when the character holds none.
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
	 * The tradition a change names for the row it addresses: the `previous` snapshot's, which is the row as it stands
	 * now, else the trait's own. Null when neither names one.
	 *
	 * @param array<string,mixed>      $trait    The change's own trait.
	 * @param array<string,mixed>|null $previous The change's `previous` snapshot, when it carries one.
	 * @return string|null
	 */
	public static function tradition_of_target( array $trait, ?array $previous ): ?string {
		foreach ( [ $previous, $trait ] as $source ) {
			if ( is_array( $source ) && isset( $source['tradition'] ) && is_string( $source['tradition'] ) && trim( $source['tradition'] ) !== '' ) {
				return trim( $source['tradition'] );
			}
		}
		return null;
	}

	/**
	 * The positions of the held rows a change addresses: every row with its identity, narrowed to the rows stored under
	 * the tradition the change names. A tradition spelled exactly as stored decides first, then one that matches
	 * ignoring case and punctuation; when no row holds the tradition, or the change names none, every row with the
	 * identity is addressed.
	 *
	 * @param object|null $definition
	 * @param array<int|string,mixed> $held      `sheet_data[block_slug]`.
	 * @param string                  $identity  From `target_of()`.
	 * @param string|null             $tradition From `tradition_of_target()`.
	 * @return int[]
	 */
	public static function addressed_positions( $definition, array $held, string $identity, ?string $tradition ): array {
		$held = array_values( $held );
		$all  = [];
		foreach ( $held as $position => $row ) {
			if ( is_array( $row ) && self::of_row( $definition, $row ) === $identity ) {
				$all[] = $position;
			}
		}
		if ( $tradition === null || count( $all ) < 2 ) {
			return $all;
		}

		$exact = array_values( array_filter( $all, static fn( int $position ): bool => ( $held[ $position ]['tradition'] ?? null ) === $tradition ) );
		if ( $exact !== [] ) {
			return $exact;
		}
		$wanted = Fuzzy_Matcher::normalize( $tradition );
		$loose  = array_values( array_filter( $all, static fn( int $position ): bool => Fuzzy_Matcher::normalize( (string) ( $held[ $position ]['tradition'] ?? '' ) ) === $wanted ) );
		return $loose !== [] ? $loose : $all;
	}

	/**
	 * Which held row a `modify_trait` or `remove_trait` addresses, as an identity.
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
