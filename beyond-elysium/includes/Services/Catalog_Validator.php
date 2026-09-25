<?php

namespace BeyondElysium\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Validates a declared catalog file. A file is rejected, not silently degraded.
 */
class Catalog_Validator {

	/**
	 * Section types a block file may declare.
	 */
	private const SECTION_TYPES = [ 'trait_list', 'tiered_power', 'identity_field', 'resource_pool' ];

	/**
	 * Envelope `format` versions this validator understands (format).
	 */
	private const FORMATS = [ 1 ];

	/**
	 * Record kinds a catalog file may carry (format).
	 */
	private const KINDS = [ 'block', 'stack', 'template', 'preset' ];

	/**
	 * Template types, the second half of a template's `<stack>.<type>` slug (format).
	 */
	private const TEMPLATE_TYPES = [ 'sheet_full', 'npc_full', 'npc_quick' ];

	/**
	 * Validates one decoded file of any kind: the shared envelope (rule 1 plus the format's required keys).
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
	 * Rule 6's shape half: a stack's sections each name a block, a label and an order, and an `in_type_source` is a
	 * `"block_slug.Field"` join.
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
		$errors        = [];
		$own_slugs     = [];
		$replaced_here = [];
		foreach ( $sections as $section ) {
			if ( is_array( $section ) && is_string( $section['block_slug'] ?? null ) ) {
				$own_slugs[] = $section['block_slug'];
			}
		}
		foreach ( $sections as $i => $section ) {
			$where = sprintf( '%s[%s]', $at, (string) $i );
			if ( ! is_array( $section ) || ! is_string( $section['block_slug'] ?? null ) || $section['block_slug'] === '' ) {
				$errors[] = "{$where} names no `block_slug`";
				continue;
			}
			if ( ! $is_stack && array_key_exists( 'former_titles', $section ) ) {
				$former = $section['former_titles'];
				if ( ! is_array( $former ) || $former === [] || ! array_is_list( $former ) || array_filter( $former, static fn( $title ): bool => ! is_string( $title ) || $title === '' ) !== [] ) {
					$errors[] = sprintf( '%s ("%s") `former_titles` must be a non-empty list of titles', $where, $section['block_slug'] );
				}
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
				if ( array_key_exists( 'replaces', $section ) ) {
					$replaces = $section['replaces'];
					if ( ! is_array( $replaces ) || $replaces === [] || array_is_list( $replaces ) === false ) {
						$errors[] = sprintf( '%s ("%s") `replaces` must be a non-empty list of slugs', $where, $section['block_slug'] );
					} else {
						foreach ( $replaces as $old ) {
							if ( ! is_string( $old ) || $old === '' ) {
								$errors[] = sprintf( '%s ("%s") `replaces` entries must be non-empty slug strings', $where, $section['block_slug'] );
								continue;
							}
							if ( in_array( $old, $own_slugs, true ) ) {
								$errors[] = sprintf( '%s ("%s") `replaces` names "%s", which this stack still declares as a section', $where, $section['block_slug'], $old );
							}
							if ( isset( $replaced_here[ $old ] ) ) {
								$errors[] = sprintf( '%s ("%s") `replaces` names "%s", already claimed by section "%s" in this stack', $where, $section['block_slug'], $old, $replaced_here[ $old ] );
							}
							$replaced_here[ $old ] = $section['block_slug'];
						}
					}
				}
			}
		}
		return $errors;
	}

	/**
	 * The block slugs a stack or template file points at.
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
	 * Rules 6 and 7: every slug a file references resolves to a real file.
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
	 * Validates a trait_list block's payload.
	 *
	 * @param array<string,mixed> $definition
	 * @return string[]
	 */
	private static function validate_trait_list( array $definition ): array {
		$errors = [];
		if ( array_key_exists( 'print_rings', $definition ) && ! is_bool( $definition['print_rings'] ) ) {
			$errors[] = '`print_rings` must be true or false';
		}
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

		if ( array_key_exists( 'name_canonicalization', $definition ) ) {
			$errors = array_merge( $errors, self::validate_name_canonicalization( $definition['name_canonicalization'] ) );
		}

		return $errors;
	}

	/**
	 * Validates a trait_list block's own rule for canonicalizing a custom row's raw name against its real items.
	 *
	 * @param mixed $rule
	 * @return string[]
	 */
	private static function validate_name_canonicalization( $rule ): array {
		if ( ! is_array( $rule ) ) {
			return [ '`name_canonicalization` must be an object' ];
		}

		$errors = [];
		$form   = $rule['form'] ?? null;
		if ( ! in_array( $form, [ 'group_name_tier' ], true ) ) {
			$errors[] = sprintf( '`name_canonicalization.form` "%s" is not one of: group_name_tier', is_scalar( $form ) ? (string) $form : gettype( $form ) );
		}

		foreach ( [ 'tier_words', 'group_aliases' ] as $map_key ) {
			if ( ! array_key_exists( $map_key, $rule ) ) {
				continue;
			}
			if ( ! is_array( $rule[ $map_key ] ) ) {
				$errors[] = sprintf( '`name_canonicalization.%s` must be an object', $map_key );
				continue;
			}
			foreach ( $rule[ $map_key ] as $from => $to ) {
				if ( ! is_string( $to ) || $to === '' ) {
					$errors[] = sprintf( '`name_canonicalization.%s["%s"]` must be a non-empty string', $map_key, (string) $from );
				}
			}
		}

		if ( $form === 'group_name_tier' && ! array_key_exists( 'tier_words', $rule ) ) {
			$errors[] = '`name_canonicalization` with form "group_name_tier" needs `tier_words`';
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

		if ( ! array_is_list( (array) $definition['powers'] ) ) {
			$errors[] = '`definition.powers` must be a list of family objects, each carrying its own `name` - not a map keyed by family name';
			return $errors;
		}
		foreach ( (array) $definition['powers'] as $i => $power ) {
			if ( is_array( $power ) && ( ! isset( $power['name'] ) || ! is_string( $power['name'] ) || $power['name'] === '' ) ) {
				$errors[] = sprintf( 'powers[%s] has no string `name` - a family is identified by name, and the list shape carries it on the family', (string) $i );
			}
		}

		// An **untiered** track has no rank vocabulary by design.
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
	 * An untiered track: no ranks, no ladder, no picks.
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
	 * A rung names its power in `power_name`, the key `PowerLevel` and every consumer read.
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
	 * One family: the ladder's length and numbering, the pick ranks, and the two states a declared file may not be.
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

		// Rule 5: a declared file carries no overflow.
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
