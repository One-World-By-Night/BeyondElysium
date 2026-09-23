<?php

namespace BeyondElysium\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Validates a declared catalog file against `reference/CATALOG-JSON-FORMAT.md` §7.
 *
 * **A file is rejected, not silently degraded.** This is the gate that makes the declared
 * catalog worth having: under the flat GVM shape a family carrying twelve ladder-rank levels
 * against a real ceiling of five was invisible and simply mis-priced (D67, and D68 behind it).
 * Here it cannot be committed. `bin/validate-catalog` runs this over `data/catalog/**` and
 * `bin/verify` fails the build on any error, so a broken catalog never reaches a seed.
 *
 * **Built before the files it validates** (owner ruling, 2026-09-21), deliberately out of
 * release order. 1.3.0/1.3.1 emit ~118 files and 11,519 rows; generating them against no
 * machine check and validating only at cutover would mean discovering a systematic authoring
 * error 118 files late. Running this continuously as they are generated means a human review
 * is spent on rulings rather than on format errors a script should have caught.
 *
 * Pure: no database, no WordPress, no filesystem. `validate_block()` takes decoded data and
 * returns errors, so 1.3.2's own `Catalog_Reader` can reuse it rather than growing a second
 * copy of the rules that drifts from this one.
 */
class Catalog_Validator {

	/** Section types a block file may declare. */
	private const SECTION_TYPES = [ 'trait_list', 'tiered_power', 'identity_field', 'resource_pool' ];

	/**
	 * Validates one decoded block file.
	 *
	 * @param array<string,mixed> $data Decoded file contents.
	 * @param string              $stem The filename without extension - rule 1 requires `slug` to match it.
	 * @return string[] Human-readable errors, empty when the file is valid.
	 */
	public static function validate_block( array $data, string $stem ): array {
		$errors = [];

		// Rule 1: slug equals the filename stem.
		$slug = (string) ( $data['slug'] ?? '' );
		if ( $slug === '' ) {
			$errors[] = 'missing `slug`';
		} elseif ( $slug !== $stem ) {
			$errors[] = sprintf( '`slug` is "%s" but the file is named "%s.json" - they must match', $slug, $stem );
		}

		// Rule 2: section_type matches the payload shape.
		$section_type = (string) ( $data['section_type'] ?? '' );
		if ( ! in_array( $section_type, self::SECTION_TYPES, true ) ) {
			$errors[] = sprintf( '`section_type` "%s" is not one of: %s', $section_type, implode( ', ', self::SECTION_TYPES ) );
			return $errors; // Nothing below can be checked without knowing the shape.
		}

		$definition = $data['definition'] ?? null;
		if ( ! is_array( $definition ) ) {
			$errors[] = '`definition` must be an object';
			return $errors;
		}

		$required_key = [
			'trait_list'     => 'items',
			'tiered_power'   => 'powers',
			'identity_field' => 'fields',
			'resource_pool'  => 'pools',
		][ $section_type ];

		if ( ! isset( $definition[ $required_key ] ) || ! is_array( $definition[ $required_key ] ) ) {
			$errors[] = sprintf( 'a %s block needs a `definition.%s` array', $section_type, $required_key );
			return $errors;
		}

		if ( $section_type === 'trait_list' ) {
			$errors = array_merge( $errors, self::validate_trait_list( $definition ) );
		} elseif ( $section_type === 'tiered_power' ) {
			$errors = array_merge( $errors, self::validate_tiered_power( $definition ) );
		}

		return $errors;
	}

	/**
	 * Rule 3. Every item carries a `name`; the three faceting fields are present even when
	 * null, so "nobody set this" is distinguishable from "this file predates the field"; and
	 * `cost` stays a string or null, never a bare number - a real cost is free text
	 * (`"1 or 3"`, `"1-7"`) and quietly narrowing it to an integer is how a range becomes its
	 * own floor.
	 *
	 * @param array<string,mixed> $definition
	 * @return string[]
	 */
	private static function validate_trait_list( array $definition ): array {
		$errors = [];
		foreach ( (array) $definition['items'] as $i => $item ) {
			$at = sprintf( 'items[%s]', (string) $i );
			if ( ! is_array( $item ) ) {
				$errors[] = "{$at} is not an object";
				continue;
			}
			if ( ( $item['name'] ?? '' ) === '' ) {
				$errors[] = "{$at} has no `name`";
			}
			foreach ( [ 'tier', 'group', 'subgroup' ] as $facet ) {
				if ( ! array_key_exists( $facet, $item ) ) {
					$errors[] = sprintf( '%s ("%s") is missing `%s` - declare it as null rather than omitting it', $at, (string) ( $item['name'] ?? '?' ), $facet );
				}
			}
			if ( array_key_exists( 'cost', $item ) && $item['cost'] !== null && ! is_string( $item['cost'] ) ) {
				$errors[] = sprintf( '%s ("%s") has a non-string `cost` - a cost is free text ("1 or 3", "1-7"), not a number', $at, (string) ( $item['name'] ?? '?' ) );
			}
		}
		return $errors;
	}

	/**
	 * Rules 4 and 5, plus the `unknown` ban and the overflow ban.
	 *
	 * @param array<string,mixed> $definition
	 * @return string[]
	 */
	private static function validate_tiered_power( array $definition ): array {
		$errors = [];
		$meta   = is_array( $definition['_meta'] ?? null ) ? $definition['_meta'] : [];

		// An **untiered** track (S7) has no rank vocabulary by design - Changeling Realms are a
		// flat 2 per level and Mage Rotes derive from the Sphere level invoked. Every rule keyed
		// on ranks is therefore inapplicable, not merely satisfiable. Without this branch the
		// validator rejects the very shape S7 exists to express, which would push whoever is
		// authoring these files into inventing fake ranks to get past it - poisoning the data
		// to satisfy the check that was supposed to protect it.
		if ( isset( $meta['untiered'] ) ) {
			return array_merge( $errors, self::validate_untiered( $meta, (array) $definition['powers'] ) );
		}

		$ranks = array_map( 'strval', (array) ( $meta['ranks'] ?? [] ) );
		if ( $ranks === [] ) {
			$errors[] = '`_meta.ranks` is empty - a tiered_power block declares its own rank vocabulary, never a global one. An untiered track declares `_meta.untiered` instead';
			return $errors; // Every rule below is keyed on the vocabulary.
		}
		if ( in_array( 'unknown', $ranks, true ) ) {
			$errors[] = '`_meta.ranks` declares "unknown" - that is not a rank, it is an unclassified note. Resolve it from the source before emitting';
		}

		$ladder = (array) ( $meta['ladder'] ?? [] );
		foreach ( array_keys( $ladder ) as $rank ) {
			if ( ! in_array( (string) $rank, $ranks, true ) ) {
				$errors[] = sprintf( '`_meta.ladder` declares rank "%s", which is not in `_meta.ranks`', (string) $rank );
			}
		}
		$ceiling = 0;
		foreach ( $ladder as $rungs ) {
			$ceiling += (int) $rungs;
		}
		if ( $ceiling < 1 ) {
			$errors[] = '`_meta.ladder` sums to zero - the sum is the ceiling, so a block with no ladder can hold no rating';
		}

		foreach ( (array) $definition['powers'] as $key => $power ) {
			$name = is_array( $power ) ? (string) ( $power['name'] ?? $key ) : (string) $key;
			if ( ! is_array( $power ) ) {
				$errors[] = sprintf( 'powers["%s"] is not an object', $name );
				continue;
			}
			$errors = array_merge( $errors, self::validate_family( $power, $name, $ranks, $ladder, $ceiling ) );
		}

		return $errors;
	}

	/**
	 * An untiered track (S7): no ranks, no ladder, no picks - just numbered levels and a cost
	 * rule. Still strict about the things that remain meaningful.
	 *
	 * `untiered` must state exactly one rule: a flat `cost_per_level`, or a `derived_from`
	 * block plus `per_level`. Both at once is ambiguous and neither is a declaration at all -
	 * which is the state Changeling Realms is in today, reaching the right answer through a
	 * no-tier-vocabulary fallback that explains itself to nobody.
	 *
	 * @param array<string,mixed> $meta
	 * @param array<int,mixed>    $powers
	 * @return string[]
	 */
	private static function validate_untiered( array $meta, array $powers ): array {
		$errors   = [];
		$untiered = is_array( $meta['untiered'] ) ? $meta['untiered'] : [];

		$flat    = array_key_exists( 'cost_per_level', $untiered );
		$derived = array_key_exists( 'derived_from', $untiered );

		if ( ! $flat && ! $derived ) {
			$errors[] = '`_meta.untiered` states no rule - it needs either `cost_per_level` or `derived_from` plus `per_level`';
		}
		if ( $flat && $derived ) {
			$errors[] = '`_meta.untiered` states both `cost_per_level` and `derived_from` - a track has one cost rule, not two';
		}
		if ( $derived && ! array_key_exists( 'per_level', $untiered ) ) {
			$errors[] = '`_meta.untiered.derived_from` needs `per_level` - how much one level of the other block costs here';
		}
		if ( $flat && ! is_int( $untiered['cost_per_level'] ) ) {
			$errors[] = '`_meta.untiered.cost_per_level` must be an integer';
		}
		if ( ! empty( $meta['ranks'] ) ) {
			$errors[] = '`_meta.untiered` and a non-empty `_meta.ranks` contradict each other - a track is ranked or it is not';
		}

		foreach ( $powers as $key => $power ) {
			$name = is_array( $power ) ? (string) ( $power['name'] ?? $key ) : (string) $key;
			if ( ! is_array( $power ) ) {
				continue;
			}
			$levels = (array) ( $power['levels'] ?? [] );
			$seen   = [];
			foreach ( $levels as $level ) {
				if ( is_array( $level ) ) {
					$seen[] = (int) ( $level['level'] ?? 0 );
				}
			}
			if ( $levels !== [] && $seen !== range( 1, count( $levels ) ) ) {
				$errors[] = sprintf( '"%s" numbers its levels [%s] - an untiered track still runs 1..N consecutively', $name, implode( ',', $seen ) );
			}
			if ( ! empty( $power['elder'] ) ) {
				$errors[] = sprintf( '"%s" has picks, but an untiered track has no ranks to file them under', $name );
			}
			if ( ! empty( $power['overflow'] ) ) {
				$errors[] = sprintf( '"%s" has overflow on an untiered track - there is no ladder for a level to overflow past', $name );
			}
		}

		return $errors;
	}

	/**
	 * One family: the ladder's length and numbering, the pick ranks, and the two states a
	 * declared file may not be in - a non-empty `overflow`, or a tier outside the vocabulary.
	 *
	 * @param array<string,mixed>  $power
	 * @param string[]             $ranks
	 * @param array<string,mixed>  $ladder
	 * @return string[]
	 */
	private static function validate_family( array $power, string $name, array $ranks, array $ladder, int $ceiling ): array {
		$errors = [];
		$levels = (array) ( $power['levels'] ?? [] );

		// Rule 4: sum(ladder) equals every family's `levels` length.
		if ( count( $levels ) !== $ceiling ) {
			$errors[] = sprintf(
				'"%s" has %d ladder level(s) against a declared ceiling of %d - a family must fill its own ladder exactly',
				$name,
				count( $levels ),
				$ceiling
			);
		}

		// Rule 4: `levels[].level` is 1..N consecutive.
		$seen = [];
		foreach ( $levels as $i => $level ) {
			if ( ! is_array( $level ) ) {
				$errors[] = sprintf( '"%s" levels[%s] is not an object', $name, (string) $i );
				continue;
			}
			$seen[] = (int) ( $level['level'] ?? 0 );
			$tier   = (string) ( $level['tier'] ?? '' );
			if ( $tier !== '' && ! in_array( $tier, $ranks, true ) ) {
				$errors[] = sprintf( '"%s" levels[%s] has tier "%s", which is not in `_meta.ranks`', $name, (string) $i, $tier );
			}
			if ( $tier !== '' && ! array_key_exists( $tier, $ladder ) ) {
				$errors[] = sprintf( '"%s" levels[%s] has tier "%s", which contributes no rungs to `_meta.ladder` - a rung must be a ladder rank', $name, (string) $i, $tier );
			}
		}
		$expected = range( 1, max( 1, $ceiling ) );
		if ( $levels !== [] && $seen !== $expected ) {
			$errors[] = sprintf( '"%s" numbers its rungs %s - they must run 1..%d consecutively', $name, '[' . implode( ',', $seen ) . ']', $ceiling );
		}

		// Rule 4: `elder` keys are ranks, and disjoint from ladder ranks.
		foreach ( (array) ( $power['elder'] ?? [] ) as $rank => $picks ) {
			$rank = (string) $rank;
			if ( ! in_array( $rank, $ranks, true ) ) {
				$errors[] = sprintf( '"%s" files picks under "%s", which is not in `_meta.ranks`', $name, $rank );
			}
			if ( array_key_exists( $rank, $ladder ) ) {
				$errors[] = sprintf( '"%s" files picks under "%s", which is a ladder rank - a rank is rungs or picks, never both', $name, $rank );
			}
			foreach ( (array) $picks as $j => $pick ) {
				if ( is_array( $pick ) && ( $pick['power_name'] ?? '' ) === '' ) {
					$errors[] = sprintf( '"%s" elder.%s[%s] has no `power_name` - a pick is identified by name, never by a number', $name, $rank, (string) $j );
				}
			}
		}

		// Rule 5, in its declared-file form. `overflow` exists so the GVM path can emit
		// without losing data (1.2.10 §A1b) and is 1.3.1's worklist; an authored file has
		// resolved that already, so a non-empty one here is D67 uncommitted, not data.
		$overflow = (array) ( $power['overflow'] ?? [] );
		if ( $overflow !== [] ) {
			$errors[] = sprintf(
				'"%s" has %d overflow level(s) - a declared file must have none. This is D67: the family is two ladders merged and needs its ruling before it can be committed',
				$name,
				count( $overflow )
			);
		}

		return $errors;
	}
}
