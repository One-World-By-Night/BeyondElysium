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

	/** Envelope `format` versions this validator understands (format §3). */
	private const FORMATS = [ 1 ];

	/** Record kinds a catalog file may carry (format §3). */
	private const KINDS = [ 'block', 'stack', 'template', 'preset' ];

	/** Template types, the second half of a template's `<stack>.<type>` slug (format §6). */
	private const TEMPLATE_TYPES = [ 'sheet_full', 'npc_full', 'npc_quick' ];

	/**
	 * Validates one decoded file of any kind: the shared envelope (rule 1 plus format §3's
	 * required keys), then the payload for its kind. Cross-file references (rules 6 and 7) need
	 * every file at once, so they are {@see validate_references()}'s job, not this one's.
	 *
	 * @param array<string,mixed> $data Decoded file contents.
	 * @param string              $stem The filename without extension.
	 * @return string[]
	 */
	public static function validate_file( array $data, string $stem ): array {
		$errors = [];
		if ( ! in_array( $data['format'] ?? null, self::FORMATS, true ) ) {
			$errors[] = sprintf( '`format` must be one of: %s', implode( ', ', self::FORMATS ) );
		}
		if ( ! is_string( $data['name'] ?? null ) || $data['name'] === '' ) {
			$errors[] = 'missing `name`';
		}
		if ( ! is_array( $data['provenance'] ?? null ) || empty( $data['provenance']['sources'] ) ) {
			$errors[] = '`provenance.sources` is empty - a reviewer must be able to see where the content came from';
		}
		$kind = (string) ( $data['kind'] ?? '' );
		if ( ! in_array( $kind, self::KINDS, true ) ) {
			$errors[] = sprintf( '`kind` "%s" is not one of: %s', $kind, implode( ', ', self::KINDS ) );
			return $errors;
		}
		if ( $kind === 'block' ) {
			return array_merge( $errors, self::validate_block( $data, $stem ) );
		}

		$slug = (string) ( $data['slug'] ?? '' );
		if ( $slug !== $stem ) {
			$errors[] = sprintf( '`slug` is "%s" but the file is named "%s.json" - they must match', $slug, $stem );
		}
		$definition = $data['definition'] ?? null;
		if ( ! is_array( $definition ) || $definition === [] ) {
			$errors[] = '`definition` must be a non-empty object or list';
			return $errors;
		}
		if ( $kind === 'stack' ) {
			$errors = array_merge( $errors, self::validate_stack( $definition ) );
		} elseif ( $kind === 'template' ) {
			if ( ! preg_match( '/^[a-z0-9-]+\\.(' . implode( '|', self::TEMPLATE_TYPES ) . ')$/', $slug ) ) {
				$errors[] = sprintf( 'a template slug is "<stack>.<type>" with type one of %s - "%s" is not', implode( ', ', self::TEMPLATE_TYPES ), $slug );
			}
			$errors = array_merge( $errors, self::validate_sections( (array) ( $definition['sections'] ?? [] ), 'sections', false ) );
			if ( ! isset( $definition['sections'] ) || ! is_array( $definition['sections'] ) || $definition['sections'] === [] ) {
				$errors[] = 'a template lays out a non-empty `definition.sections` list';
			}
		}
		// A preset is a flat list (or a map of lists) of free text - nothing further to check.
		return $errors;
	}

	/**
	 * Rule 6's shape half: a stack's sections each name a block, a label and an order, and an
	 * `in_type_source` is a `"block_slug.Field"` join.
	 *
	 * @param array<string,mixed> $definition
	 * @return string[]
	 */
	private static function validate_stack( array $definition ): array {
		$errors = [];
		if ( ! is_array( $definition['sections'] ?? null ) || $definition['sections'] === [] ) {
			return [ 'a stack declares a non-empty `definition.sections` list' ];
		}
		$errors = self::validate_sections( $definition['sections'], 'sections', true );
		$rules  = $definition['creation_rules'] ?? null;
		if ( $rules !== null && ! is_array( $rules ) ) {
			$errors[] = '`creation_rules` must be an object or null';
		}
		return $errors;
	}

	/**
	 * @param array<int|string,mixed> $sections
	 * @return string[]
	 */
	private static function validate_sections( array $sections, string $at, bool $is_stack ): array {
		$errors = [];
		foreach ( $sections as $i => $section ) {
			$where = sprintf( '%s[%s]', $at, (string) $i );
			if ( ! is_array( $section ) || ! is_string( $section['block_slug'] ?? null ) || $section['block_slug'] === '' ) {
				$errors[] = "{$where} names no `block_slug`";
				continue;
			}
			if ( $is_stack ) {
				if ( ! is_string( $section['label'] ?? null ) || $section['label'] === '' ) {
					$errors[] = sprintf( '%s ("%s") has no `label`', $where, $section['block_slug'] );
				}
				if ( ! is_int( $section['display_order'] ?? null ) ) {
					$errors[] = sprintf( '%s ("%s") needs an integer `display_order`', $where, $section['block_slug'] );
				}
				$join = $section['in_type_source'] ?? null;
				if ( $join !== null && ( ! is_string( $join ) || ! preg_match( '/^[a-z0-9_-]+\\.[^.]+$/', $join ) ) ) {
					$errors[] = sprintf( '%s ("%s") has `in_type_source` "%s" - it is a "block_slug.Field" join', $where, $section['block_slug'], is_string( $join ) ? $join : gettype( $join ) );
				}
			}
		}
		return $errors;
	}

	/**
	 * The block slugs a stack or template file points at - its sections, a stack's negative
	 * blocks, its `in_type_source` joins and its `creation_rules` steps - plus a variant's base.
	 *
	 * @param array<string,mixed> $data
	 * @return string[]
	 */
	public static function referenced_blocks( array $data ): array {
		$refs       = [];
		$definition = is_array( $data['definition'] ?? null ) ? $data['definition'] : [];
		$kind       = (string) ( $data['kind'] ?? '' );
		if ( $kind === 'block' && is_array( $data['variant'] ?? null ) ) {
			$refs[] = (string) ( $data['variant']['of'] ?? '' );
		}
		if ( $kind === 'stack' || $kind === 'template' ) {
			foreach ( (array) ( $definition['sections'] ?? [] ) as $section ) {
				if ( ! is_array( $section ) ) {
					continue;
				}
				foreach ( [ 'block_slug', 'negative_block_slug' ] as $key ) {
					if ( is_string( $section[ $key ] ?? null ) && $section[ $key ] !== '' ) {
						$refs[] = $section[ $key ];
					}
				}
				if ( is_string( $section['in_type_source'] ?? null ) ) {
					$refs[] = explode( '.', $section['in_type_source'] )[0];
				}
			}
			$rules = $definition['creation_rules'] ?? null;
			foreach ( is_array( $rules ) ? (array) ( $rules['steps'] ?? [] ) : [] as $step ) {
				foreach ( is_array( $step ) ? (array) ( $step['sections'] ?? [] ) : [] as $slug ) {
					$refs[] = (string) $slug;
				}
			}
		}
		return array_values( array_unique( $refs ) );
	}

	/**
	 * Rules 6 and 7: every slug a file references resolves to a real file. A stack whose block
	 * has no file would seed a section that renders nothing; a template for a stack that does
	 * not exist is unreachable. Both fail rather than degrade.
	 *
	 * @param array<string,mixed> $data
	 * @param string[]            $block_slugs Every block file's slug in the catalog.
	 * @param string[]            $stack_slugs Every stack file's slug in the catalog.
	 * @return string[]
	 */
	public static function validate_references( array $data, string $stem, array $block_slugs, array $stack_slugs ): array {
		$errors = [];
		foreach ( self::referenced_blocks( $data ) as $slug ) {
			if ( ! in_array( $slug, $block_slugs, true ) ) {
				$errors[] = sprintf( 'references block "%s", which has no file in the catalog', $slug );
			}
		}
		if ( ( $data['kind'] ?? '' ) === 'template' ) {
			$stack = explode( '.', $stem )[0];
			if ( ! in_array( $stack, $stack_slugs, true ) ) {
				$errors[] = sprintf( 'is a template for stack "%s", which has no file in the catalog', $stack );
			}
		}
		return $errors;
	}

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

		// The runtime list shape is the only accepted one (owner ruling, 2026-09-22): it is what
		// the engine and `TieredPowerDefinition.powers: TieredPower[]` read. 1.3.0 and 1.3.1 once
		// wrote two shapes - a map keyed by family name, bare-string picks, `name` on a rung -
		// and this validator accepted both, so the disagreement surfaced only when a consumer
		// indexed a key that one shape did not have. Nothing below can be trusted until the
		// shape itself is right, so a map stops here.
		if ( ! array_is_list( (array) $definition['powers'] ) ) {
			$errors[] = '`definition.powers` must be a list of family objects, each carrying its own `name` - not a map keyed by family name';
			return $errors;
		}
		foreach ( (array) $definition['powers'] as $i => $power ) {
			if ( is_array( $power ) && ( ! isset( $power['name'] ) || ! is_string( $power['name'] ) || $power['name'] === '' ) ) {
				$errors[] = sprintf( 'powers[%s] has no string `name` - a family is identified by name, and the list shape carries it on the family', (string) $i );
			}
		}

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
		// A **pick-only** track (Werewolf and Fera Gifts) has ranks but no rating at all: every
		// Gift is bought by name, and holding six Intermediate Gifts with no Basic ones is legal
		// (1.2.10 §B). It says so by declaring `ladder` as an explicit empty object. An omitted
		// ladder is still an error - "nobody declared this" must not read as "this has no rungs".
		$pick_only = array_key_exists( 'ladder', $meta ) && $ladder === [];
		if ( $ceiling < 1 && ! $pick_only ) {
			$errors[] = '`_meta.ladder` sums to zero - the sum is the ceiling, so a block with no ladder can hold no rating. A pick-only track declares `"ladder": {}` explicitly';
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
			foreach ( $levels as $i => $level ) {
				if ( is_array( $level ) ) {
					$seen[] = (int) ( $level['level'] ?? 0 );
					$errors = array_merge( $errors, self::rung_power_name( $level, $name, $i ) );
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
	 * A rung names its power in `power_name`, the key `PowerLevel` and every consumer read. A
	 * rung carrying only `name` is the retired authoring shape and would render as a blank rung.
	 *
	 * @param array<string,mixed> $level
	 * @param int|string          $i
	 * @return string[]
	 */
	private static function rung_power_name( array $level, string $name, $i ): array {
		if ( ! isset( $level['power_name'] ) || ! is_string( $level['power_name'] ) || $level['power_name'] === '' ) {
			return [ sprintf( '"%s" levels[%s] has no `power_name` - a rung names its power in `power_name`, not `name`', $name, (string) $i ) ];
		}
		return [];
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
			$errors = array_merge( $errors, self::rung_power_name( $level, $name, $i ) );
			$tier   = (string) ( $level['tier'] ?? '' );
			if ( $tier !== '' && ! in_array( $tier, $ranks, true ) ) {
				$errors[] = sprintf( '"%s" levels[%s] has tier "%s", which is not in `_meta.ranks`', $name, (string) $i, $tier );
			}
			if ( $tier !== '' && ! array_key_exists( $tier, $ladder ) ) {
				$errors[] = sprintf( '"%s" levels[%s] has tier "%s", which contributes no rungs to `_meta.ladder` - a rung must be a ladder rank', $name, (string) $i, $tier );
			}
		}
		$expected = $ceiling > 0 ? range( 1, $ceiling ) : [];
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
				if ( ! is_array( $pick ) ) {
					$errors[] = sprintf( '"%s" elder.%s[%s] is not an object - a pick is `{level: null, tier, power_name, ...}`, never a bare name', $name, $rank, (string) $j );
					continue;
				}
				if ( ( $pick['power_name'] ?? '' ) === '' ) {
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
