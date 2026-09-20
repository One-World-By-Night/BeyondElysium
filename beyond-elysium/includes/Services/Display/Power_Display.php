<?php

namespace BeyondElysium\Services\Display;

defined( 'ABSPATH' ) || exit;

/**
 * Pure label-formatting helpers for one held entry on a tiered_power schema block
 * (Disciplines, Gifts, Spheres, Arts, Arcanoi, ...) - an exact PHP twin of
 * `src/components/renderers/TieredPowerRenderer.tsx`'s own pure helpers, so the
 * signed-PDF exporter renders the same labels the on-screen character sheet does
 * instead of re-deriving the formatting rules independently. Verified against the
 * TypeScript original by having both read the same JSON fixture and assert the
 * same output; see tests/unit/Display/PowerDisplayParityTest.php and
 * src/components/renderers/TieredPowerRenderer.test.ts.
 *
 * Operates only on plain data passed in as arguments - no WordPress calls, no
 * database access, no dependency on Trait_Mapper. A held power entry is a plain
 * array matching a `sheet_data` tiered_power item exactly, the same shape
 * Cost_Engine::find_held_power() reads: `name` (string), and optionally `level`
 * (int), `power_name` (string), `tier` (string), `tradition` (string). A
 * tiered_power block's `definition` is a decoded object, the same shape
 * Cost_Engine::find_power() reads: `powers`, a list of objects each with `name`
 * and `levels`; `levels` a list of objects each with `level`, `tier`, `power_name`.
 *
 * @see BE_PROCESS/design/signed-pdf-design.md Section 2d, Section 3, SP-3
 */
class Power_Display {

	/**
	 * Prefixes a rendered label with the entry's tradition, when it carries one -
	 * Blood Magic's own display rule (BE_PROCESS/releases/0.99.2-workflow.md: "Tradition:
	 * PathName"). A plain power with no tradition renders exactly as it always has.
	 *
	 * @param array{name:string,level?:int,power_name?:string,tier?:string,tradition?:string} $held
	 */
	public static function with_tradition( array $held, string $label ): string {
		$tradition = $held['tradition'] ?? '';
		return $tradition !== '' ? $tradition . ': ' . $label : $label;
	}

	/**
	 * Builds a named label for an Elder-and-above held power: "Family: Power
	 * (tier)" using the tier looked up from the catalog when the power is found
	 * there, or "Family: Power {level}" when the entry carries its own numbered
	 * level instead.
	 *
	 * @param object                                                                       $definition
	 * @param array{name:string,level?:int,power_name?:string,tier?:string,tradition?:string} $held
	 * @param bool $use_pt Whether the caller has already resolved the viewer's locale to
	 *                     Portuguese - this class makes no WordPress calls of its own (see the
	 *                     class docblock), so the caller (Sheet_Document, which does) decides
	 *                     and passes a plain bool, the same shape TieredPowerRenderer.tsx's own
	 *                     localizedPowerName() import mirrors on the client (1.2.0 §5.4).
	 */
	public static function elder_label( object $definition, array $held, bool $use_pt = false ): string {
		// Computed unconditionally, including the $held['level']-set branch below - found
		// building B12, a real pre-existing PHP/TS divergence: the TS twin already looks this
		// up before either branch, so an Elder pick with a stale/renamed catalog match had
		// never actually matched the on-screen sheet even before translation existed.
		$found      = self::find_by_power_name( self::find_power( $definition, $held['name'] ), $held['power_name'] ?? '' );
		$found_pt   = $use_pt ? ( $found->power_name_pt ?? '' ) : '';
		$power_name = $found_pt !== '' ? $found_pt : ( $held['power_name'] ?? '' );

		if ( isset( $held['level'] ) ) {
			return $held['name'] . ': ' . $power_name . ' ' . $held['level'];
		}

		// Prefers a fresh catalog tier lookup, then the entry's own stored tier, then 'elder'.
		$tier = $found?->tier ?? ( $held['tier'] ?? 'elder' );

		return $held['name'] . ': ' . $power_name . ' (' . $tier . ')';
	}

	/**
	 * Builds a numeric-mode label for one held power: delegates to elder_label()
	 * for a named Elder-and-above pick, or renders "Family {level}" for a plain
	 * numeric holding.
	 *
	 * @param object                                                                       $definition
	 * @param array{name:string,level?:int,power_name?:string,tier?:string,tradition?:string} $held
	 */
	public static function numeric_label( object $definition, array $held, bool $use_pt = false ): string {
		if ( ! empty( $held['power_name'] ) ) {
			return self::elder_label( $definition, $held, $use_pt );
		}
		return isset( $held['level'] ) ? $held['name'] . ' ' . $held['level'] : $held['name'] . ' ?';
	}

	/**
	 * The named label for one held power - a numeric level 1-5 looked up by
	 * number, or an Elder-and-above power looked up by its own name, tagged with
	 * its real tier. Falls back to whatever identifying text is available rather
	 * than ever rendering nothing.
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
	 * Builds the label list "named" mode shows for one held power: the single
	 * label for an Elder-and-above pick (Decision 037) - it's already the one
	 * specific power chosen, there is no stack beneath it to expand - or one label
	 * per rung from 1 up to the held level for a plain numbered holding. Decision
	 * 037 governs pricing cumulativeness, not display: a player who wants every
	 * named rung listed sees the whole stack either way, sequential block or not.
	 *
	 * D66 (1.2.5-design-workflow.md §A2, owner: "anything where more than one power
	 * exist on the same level... always show all") - a rung tied between several
	 * named alternatives (`level: null` on all of them) pushes every one of their
	 * names, never rolled up to one entry; twin of
	 * `TieredPowerRenderer.tsx`'s own `namedModeRows()`.
	 *
	 * @param object                                                                       $definition
	 * @param array{name:string,level?:int,power_name?:string,tier?:string,tradition?:string} $held
	 * @return string[]
	 */
	public static function named_mode_rows( object $definition, array $held, bool $use_pt = false ): array {
		if ( ! empty( $held['power_name'] ) ) {
			return [ self::named_label( $definition, $held, $held['level'] ?? null, $use_pt ) ];
		}

		$power     = self::find_power( $definition, $held['name'] );
		$rows      = [];
		$max_level = $held['level'] ?? 0;

		for ( $level = 1; $level <= $max_level; $level++ ) {
			$at_rank = self::find_levels_at_rank( $power, $level );
			if ( empty( $at_rank ) ) {
				$rows[] = self::numeric_label( $definition, $held, $use_pt );
				continue;
			}
			foreach ( $at_rank as $entry ) {
				$name_pt = $use_pt ? ( $entry->power_name_pt ?? '' ) : '';
				$rows[]  = $name_pt !== '' ? $name_pt : ( $entry->power_name ?? '' );
			}
		}

		return $rows;
	}

	/**
	 * Finds a power by name within a tiered_power block definition's catalog.
	 * Twin of TieredPowerRenderer.tsx's own module-private `findPower()`. Returns
	 * null when no power in the catalog has that name.
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
	 * Finds a numbered rung on a power's ladder by its level number. Twin of
	 * TieredPowerRenderer.tsx's own module-private `findLevel()`. Returns null
	 * when the power itself is unknown or has no rung at that level.
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
	 * Elder-and-above lookup: by the specific power's own name, not a number.
	 * Twin of TieredPowerRenderer.tsx's own module-private `findByPowerName()`.
	 * Returns null when the power itself is unknown or has no rung by that name.
	 */
	private static function find_by_power_name( ?object $power, string $power_name ): ?object {
		if ( ! $power ) {
			return null;
		}
		foreach ( ( $power->levels ?? [] ) as $entry ) {
			if ( ( $entry->power_name ?? null ) === $power_name ) {
				return $entry;
			}
		}
		return null;
	}

	/**
	 * Maps a numbered rank (1=basic, 2=intermediate, ...) to its tier name. Mirrors
	 * `Cost_Engine::tier_for_rank()`/`Database\Seeder::TIER_RANKS` and
	 * `TieredPowerRenderer.tsx`'s own `TIER_FOR_RANK`, duplicated per-file rather
	 * than shared, matching this codebase's established precedent for this lookup.
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
	 * Every real power at a given rank on a family's ladder - normally the single
	 * item whose own `level` matches exactly, but D66 leaves `level: null` on
	 * every item when several share one tier, so falls back to matching by the
	 * rank's tier instead. Twin of `TieredPowerRenderer.tsx`'s own
	 * `findLevelsAtRank()`. Never rolled up to one entry.
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
