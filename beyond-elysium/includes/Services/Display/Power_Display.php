<?php

namespace BeyondElysium\Services\Display;

use BeyondElysium\Services\Power_Levels;

defined( 'ABSPATH' ) || exit;

/**
 * Pure label-formatting helpers for one held entry on a tiered_power schema block (Disciplines, Gifts, Spheres, Arts,
 * Arcanoi,...).
 */
class Power_Display {

	/**
	 * Prefixes a rendered label with the entry's tradition, when it carries one.
	 *
	 * @param array{name:string,level?:int,power_name?:string,tier?:string,tradition?:string} $held
	 */
	public static function with_tradition( array $held, string $label ): string {
		$tradition = $held['tradition'] ?? '';
		return $tradition !== '' ? $tradition . ': ' . $label : $label;
	}

	/**
	 * Builds a named label for an Elder-and-above held power: "Family: Power (tier)" when the tier is known, from the
	 * catalog or the entry itself, "Family: Power" when it is not, or "Family: Power {level}" when the entry carries its
	 * own numbered level instead.
	 *
	 * @param object                                                                       $definition
	 * @param array{name:string,level?:int,power_name?:string,tier?:string,tradition?:string} $held
	 * @param bool $use_pt Whether the caller has already resolved the viewer's locale to
	 *                     Portuguese - this class makes no WordPress calls of its own (see the
	 *                     class docblock), so the caller (Sheet_Document, which does) decides
	 *                     and passes a plain bool, the same shape TieredPowerRenderer.tsx's own
	 *                     localizedPowerName() import mirrors on the client.
	 */
	public static function elder_label( object $definition, array $held, bool $use_pt = false ): string {
		$power      = self::find_power( $definition, $held['name'] );
		$found      = self::find_by_power_name( $power, $held['power_name'] ?? '' );
		$found_pt   = $use_pt ? ( $found->power_name_pt ?? '' ) : '';
		$power_name = $found_pt !== '' ? $found_pt : ( $held['power_name'] ?? '' );

		$stem = ( ( $held['power_name'] ?? null ) === $held['name'] )
			? $held['name']
			: $held['name'] . ': ' . $power_name;

		if ( isset( $held['level'] ) ) {
			return $stem . ' ' . $held['level'];
		}

		// Prefers a fresh catalog tier lookup.
		$tier = self::displayable_tier( $found->tier ?? null )
			?? self::pick_rank( $power, (string) ( $held['power_name'] ?? '' ) )
			?? self::displayable_tier( $held['tier'] ?? null );

		$parts = array_values( array_filter( [ $tier, self::seam_qualifier( $power, $found ) ], static fn( $part ): bool => null !== $part ) );

		return [] === $parts ? $stem : $stem . ' (' . implode( ' · ', $parts ) . ')';
	}

	/**
	 * The rank a pick is filed under in its family's `elder` container, or null when it is not filed there.
	 */
	private static function pick_rank( ?object $power, string $power_name ): ?string {
		if ( ! $power ) {
			return null;
		}
		foreach ( (array) ( $power->elder ?? [] ) as $rank => $picks ) {
			foreach ( (array) $picks as $pick ) {
				if ( ( ( (object) $pick )->power_name ?? null ) === $power_name ) {
					return self::displayable_tier( (string) $rank );
				}
			}
		}
		return null;
	}

	/**
	 * The tier vocabulary, mirroring `Seeder::normalize_tier()`'s own map.
	 *
	 * @return string[]
	 */
	private static function tier_needles(): array {
		return [
			'innate',
			'basic',
			'intermediate',
			'int',
			'advanced',
			'adv',
			'elder',
			'master',
			'ascended',
			'asc',
			'methuselah',
			'meth',
		];
	}

	/**
	 * Whatever a level's free-text note says beyond its tier word.
	 *
	 * @param string|null $note
	 * @return string|null
	 */
	public static function level_qualifier( ?string $note ): ?string {
		$raw = trim( (string) $note );
		if ( '' === $raw ) {
			return null;
		}

		$lower = strtolower( $raw );
		$at    = false;
		$len   = 0;
		foreach ( self::tier_needles() as $needle ) {
			$found = self::standalone_position( $lower, $needle );
			if ( false !== $found ) {
				$at  = $found;
				$len = strlen( $needle );
				break;
			}
		}
		if ( false === $at ) {
			return null;
		}

		$rest = substr( $raw, 0, $at ) . substr( $raw, $at + $len );
		$rest = trim( (string) preg_replace( '/^[\s.,;:-]+|[\s.,;:-]+$/', '', $rest ) );
		$rest = trim( (string) preg_replace( '/^\((.*)\)$/', '$1', $rest ) );

		return '' === $rest ? null : $rest;
	}

	/**
	 * Where a word stands on its own in a lower-cased note: not inside a longer word ("winter") or a hyphenated one
	 * ("master-level"), or false.
	 *
	 * @return int|false
	 */
	private static function standalone_position( string $haystack, string $needle ) {
		$from = 0;
		while ( false !== ( $at = strpos( $haystack, $needle, $from ) ) ) {
			$before = $at > 0 ? $haystack[ $at - 1 ] : '';
			$after  = $haystack[ $at + strlen( $needle ) ] ?? '';
			if ( ! preg_match( '/[a-z0-9-]/', $before ) && ! preg_match( '/[a-z0-9-]/', $after ) ) {
				return $at;
			}
			$from = $at + 1;
		}
		return false;
	}

	/**
	 * The qualifier to show beside one level, or null when there is nothing useful to say.
	 *
	 * @param object|null $power
	 * @param object|null $level
	 * @return string|null
	 */
	public static function seam_qualifier( ?object $power, ?object $level ): ?string {
		if ( null === $power || null === $level ) {
			return null;
		}

		// Every container. Twin of levelQualifier.ts.
		$seen = [];
		foreach ( Power_Levels::all( $power ) as $entry ) {
			$seen[ self::level_qualifier( $entry->note ?? null ) ?? '' ] = true;
			if ( count( $seen ) > 1 ) {
				return self::level_qualifier( $level->note ?? null );
			}
		}

		return null;
	}

	/**
	 * Returns a tier safe to show a player, or null when it is a parser placeholder.
	 *
	 * @param string|null $tier
	 * @return string|null
	 */
	public static function displayable_tier( ?string $tier ): ?string {
		$trimmed = trim( (string) $tier );
		return in_array( strtolower( $trimmed ), [ '***', '', 'unknown' ], true )
			? null
			: $trimmed;
	}

	/**
	 * Builds a numeric-mode label for one held power: delegates to elder_label() for a named Elder-and-above pick, or
	 * renders "Family {level}" for a plain numeric holding, and the family alone when it holds no level.
	 *
	 * @param object                                                                       $definition
	 * @param array{name:string,level?:int,power_name?:string,tier?:string,tradition?:string} $held
	 */
	public static function numeric_label( object $definition, array $held, bool $use_pt = false ): string {
		if ( ! empty( $held['power_name'] ) ) {
			return self::elder_label( $definition, $held, $use_pt );
		}
		return isset( $held['level'] ) ? $held['name'] . ' ' . $held['level'] : $held['name'];
	}

	/**
	 * The named label for one held power.
	 *
	 * @param object                                                                       $definition
	 * @param array{name:string,level?:int,power_name?:string,tier?:string,tradition?:string} $held
	 */
	public static function named_label( object $definition, array $held, ?int $level = null, bool $use_pt = false ): string {
		if ( ! empty( $held['power_name'] ) ) {
			return self::elder_label( $definition, $held, $use_pt );
		}

		$power_level = $level !== null ? self::find_level( self::find_power( $definition, $held['name'] ), $level ) : null;
		if ( $power_level === null ) {
			return self::numeric_label( $definition, $held, $use_pt );
		}
		$power_name_pt = $use_pt ? ( $power_level->power_name_pt ?? '' ) : '';
		return $power_name_pt !== '' ? $power_name_pt : ( $power_level->power_name ?? '' );
	}

	/**
	 * The power names "named" mode lists beneath a held power's own line: every named power from rank 1 up to the held
	 * level, each once, or nothing for an Elder-and-above pick or a family with no named levels.
	 *
	 * @param object                                                                       $definition
	 * @param array{name:string,level?:int,power_name?:string,tier?:string,tradition?:string} $held
	 * @return string[]
	 */
	public static function named_mode_rows( object $definition, array $held, bool $use_pt = false ): array {
		if ( ! empty( $held['power_name'] ) ) {
			return [];
		}

		$power     = self::find_power( $definition, $held['name'] );
		$rows      = [];
		$max_level = (int) ( $held['level'] ?? 0 );

		for ( $level = 1; $level <= $max_level; $level++ ) {
			foreach ( self::find_levels_at_rank( $power, $level ) as $entry ) {
				$name_pt = $use_pt ? ( $entry->power_name_pt ?? '' ) : '';
				$label   = $name_pt !== '' ? $name_pt : (string) ( $entry->power_name ?? '' );
				if ( $label === '' ) {
					continue;
				}
				$qualifier = self::seam_qualifier( $power, $entry );
				$rows[]    = null !== $qualifier ? $label . ' (' . $qualifier . ')' : $label;
			}
		}

		return array_values( array_unique( $rows ) );
	}

	/**
	 * Finds a power by name within a tiered_power block definition's catalog.
	 */
	private static function find_power( object $definition, string $name ): ?object {
		foreach ( ( $definition->powers ?? [] ) as $power ) {
			if ( ( $power->name ?? null ) === $name ) {
				return $power;
			}
		}
		return null;
	}

	/**
	 * Finds a numbered rung on a power's ladder by its level number.
	 */
	private static function find_level( ?object $power, int $level ): ?object {
		if ( ! $power ) {
			return null;
		}
		foreach ( ( $power->levels ?? [] ) as $entry ) {
			if ( ( $entry->level ?? null ) === $level ) {
				return $entry;
			}
		}
		return null;
	}

	/**
	 * Elder-and-above lookup: by the specific power's own name.
	 */
	private static function find_by_power_name( ?object $power, string $power_name ): ?object {
		if ( ! $power ) {
			return null;
		}
		// All three containers.
		foreach ( Power_Levels::all( $power ) as $entry ) {
			if ( ( $entry->power_name ?? null ) === $power_name ) {
				return $entry;
			}
		}
		return null;
	}

	/**
	 * Maps a numbered rank (1=basic, 2=intermediate,...) to its tier name.
	 *
	 * @return array<int,string>
	 */
	private static function tier_for_rank_map(): array {
		return [
			1 => 'basic',
			2 => 'intermediate',
			3 => 'advanced',
			4 => 'elder',
			5 => 'master',
			6 => 'ascended',
			7 => 'methuselah',
		];
	}

	/**
	 * Every real power at a given rank on a family's ladder.
	 *
	 * @return object[]
	 */
	private static function find_levels_at_rank( ?object $power, int $rank ): array {
		if ( ! $power ) {
			return [];
		}
		$exact = array_values( array_filter(
			(array) ( $power->levels ?? [] ),
			static fn( $entry ): bool => ( $entry->level ?? null ) === $rank
		) );
		if ( ! empty( $exact ) ) {
			return $exact;
		}
		$tier = self::tier_for_rank_map()[ $rank ] ?? null;
		if ( $tier === null ) {
			return [];
		}
		return array_values( array_filter(
			(array) ( $power->levels ?? [] ),
			static fn( $entry ): bool => ( $entry->tier ?? null ) === $tier
		) );
	}
}
