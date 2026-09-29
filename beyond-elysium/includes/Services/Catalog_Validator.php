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
	 * Rule 6's shape half: a stack's sections each name a block, a label and an order, and a section's `in_type` is a
	 * list of in-type tests.
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

		$section_slugs = [];
		foreach ( $definition['sections'] as $section ) {
			if ( is_array( $section ) && is_string( $section['block_slug'] ?? null ) ) {
				$section_slugs[] = $section['block_slug'];
			}
		}

		$rules = $definition['creation_rules'] ?? null;
		if ( $rules !== null && ! is_array( $rules ) ) {
			$errors[] = '`creation_rules` must be an object or null';
		} elseif ( is_array( $rules ) ) {
			$errors = array_merge( $errors, self::validate_creation_rules( $rules, $section_slugs ) );
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
				if ( array_key_exists( 'in_type_source', $section ) ) {
					$errors[] = sprintf( '%s ("%s") has `in_type_source`, which is retired - a section states its in-type rule as an `in_type` list', $where, $section['block_slug'] );
				}
				if ( array_key_exists( 'in_type', $section ) ) {
					$errors = array_merge( $errors, self::validate_in_type( $section['in_type'], sprintf( '%s ("%s") `in_type`', $where, $section['block_slug'] ) ) );
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
				if ( is_array( $section['in_type'] ?? null ) ) {
					$refs = array_merge( $refs, self::in_type_blocks( $section['in_type'] ) );
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
	 * A section's `in_type`: a non-empty list of tests.
	 *
	 * @param mixed $tests
	 * @return string[]
	 */
	private static function validate_in_type( $tests, string $at ): array {
		if ( ! is_array( $tests ) || $tests === [] || ! array_is_list( $tests ) ) {
			return [ "{$at} must be a non-empty list of tests; a section with no in-type rule leaves `in_type` out" ];
		}
		$errors = [];
		foreach ( $tests as $i => $test ) {
			$errors = array_merge( $errors, self::validate_in_type_test( $test, sprintf( '%s[%d]', $at, $i ) ) );
		}
		return $errors;
	}

	/**
	 * One in-type test: its `kind`, its `values`, a `facet` for a facet test, the tests an `all` holds, and a `when`.
	 *
	 * @param mixed $test
	 * @return string[]
	 */
	private static function validate_in_type_test( $test, string $at ): array {
		if ( ! is_array( $test ) ) {
			return [ "{$at} is not an object" ];
		}
		$kind = $test['kind'] ?? null;
		if ( ! in_array( $kind, In_Type::KINDS, true ) ) {
			return [ sprintf( '%s has `kind` %s - one of: %s', $at, is_scalar( $kind ) ? '"' . (string) $kind . '"' : gettype( $kind ), implode( ', ', In_Type::KINDS ) ) ];
		}

		$errors = [];
		if ( array_key_exists( 'when', $test ) ) {
			$errors = array_merge( $errors, self::validate_when( $test['when'], "{$at}.when" ) );
		}
		if ( $kind === 'all' ) {
			$inner = $test['tests'] ?? null;
			if ( ! is_array( $inner ) || $inner === [] || ! array_is_list( $inner ) ) {
				return array_merge( $errors, [ "{$at} is `all` and needs a non-empty `tests` list" ] );
			}
			foreach ( $inner as $i => $each ) {
				$errors = array_merge( $errors, self::validate_in_type_test( $each, sprintf( '%s.tests[%d]', $at, $i ) ) );
			}
			return $errors;
		}

		$source = $test['values'] ?? null;
		if ( $kind === 'chosen' ) {
			if ( ! is_array( $source ) || array_keys( $source ) !== [ 'field' ] || ! self::is_field( $source['field'] ) ) {
				$errors[] = "{$at} is `chosen` and reads the character's picks from one field: {\"field\": \"block_slug.key\"}";
			}
			return $errors;
		}
		if ( $kind === 'facet' && ( ! is_string( $test['facet'] ?? null ) || $test['facet'] === '' ) ) {
			$errors[] = "{$at} is `facet` and needs a non-empty `facet` name - a trait_list item's group or subgroup, or one axis of a tiered_power family's category_values";
		}
		return array_merge( $errors, self::validate_in_type_source( $source, "{$at}.values" ) );
	}

	/**
	 * Where a test's values come from: exactly one of `field` (one "block_slug.Field" or a list of them), `map` (a
	 * "block_slug.key" map with the fields it is keyed `by`, in order) or `constant` (a list of values).
	 *
	 * @param mixed $source
	 * @return string[]
	 */
	private static function validate_in_type_source( $source, string $at ): array {
		if ( ! is_array( $source ) ) {
			return [ "{$at} is missing - a test reads values from a `field`, a `map` or a `constant`" ];
		}
		$given = array_values( array_intersect( [ 'field', 'map', 'constant' ], array_keys( $source ) ) );
		if ( count( $given ) !== 1 ) {
			return [ "{$at} names " . ( $given === [] ? 'no source' : implode( ' and ', $given ) ) . ' - a test reads values from exactly one of `field`, `map` or `constant`' ];
		}
		switch ( $given[0] ) {
			case 'field':
				$fields = is_array( $source['field'] ) ? $source['field'] : [ $source['field'] ];
				if ( $fields === [] || array_filter( $fields, static fn( $f ): bool => ! self::is_field( $f ) ) !== [] ) {
					return [ "{$at}.field must be \"block_slug.Field\" or a list of them" ];
				}
				return [];
			case 'map':
				$errors = self::is_field( $source['map'] ) ? [] : [ "{$at}.map must be \"block_slug.key\", a map kept in an identity block" ];
				$by     = $source['by'] ?? null;
				if ( ! is_array( $by ) || $by === [] || array_filter( $by, static fn( $f ): bool => ! is_string( $f ) || $f === '' ) !== [] ) {
					$errors[] = "{$at}.by must list the fields the map is keyed by, in the order they are tried";
				}
				return $errors;
			default:
				$values = $source['constant'];
				if ( ! is_array( $values ) || $values === [] || array_filter( $values, static fn( $v ): bool => ! is_string( $v ) || $v === '' ) !== [] ) {
					return [ "{$at}.constant must be a non-empty list of values" ];
				}
				return [];
		}
	}

	/**
	 * A test's `when`: a "block_slug.Field" and either the values it must hold (`is`) or whether it holds any (`set`).
	 *
	 * @param mixed $when
	 * @return string[]
	 */
	private static function validate_when( $when, string $at ): array {
		if ( ! is_array( $when ) || ! self::is_field( $when['field'] ?? null ) ) {
			return [ "{$at} needs a `field`, \"block_slug.Field\"" ];
		}
		$is  = array_key_exists( 'is', $when );
		$set = array_key_exists( 'set', $when );
		if ( $is === $set ) {
			return [ "{$at} needs exactly one of `is` (the values the field holds) or `set` (whether it holds any)" ];
		}
		if ( $is && ( ! is_array( $when['is'] ) || $when['is'] === [] || array_filter( $when['is'], static fn( $v ): bool => ! is_string( $v ) || $v === '' ) !== [] ) ) {
			return [ "{$at}.is must be a non-empty list of values" ];
		}
		if ( $set && ! is_bool( $when['set'] ) ) {
			return [ "{$at}.set must be true or false" ];
		}
		return [];
	}

	/**
	 * Whether a value is a "block_slug.Name" reference.
	 *
	 * @param mixed $value
	 */
	private static function is_field( $value ): bool {
		return is_string( $value ) && preg_match( '/^[a-z0-9_-]+\.[^.]+$/', $value ) === 1;
	}

	/**
	 * Every block an `in_type` list names, however deep.
	 *
	 * @param array<int,mixed> $tests
	 * @return string[]
	 */
	private static function in_type_blocks( array $tests ): array {
		$slugs = [];
		foreach ( $tests as $test ) {
			if ( ! is_array( $test ) ) {
				continue;
			}
			$source = is_array( $test['values'] ?? null ) ? $test['values'] : [];
			$names  = array_merge( (array) ( $source['field'] ?? [] ), isset( $source['map'] ) ? [ $source['map'] ] : [], isset( $test['when']['field'] ) ? [ $test['when']['field'] ] : [] );
			foreach ( $names as $name ) {
				if ( is_string( $name ) && str_contains( $name, '.' ) ) {
					$slugs[] = explode( '.', $name, 2 )[0];
				}
			}
			if ( is_array( $test['tests'] ?? null ) ) {
				$slugs = array_merge( $slugs, self::in_type_blocks( $test['tests'] ) );
			}
		}
		return $slugs;
	}

	/**
	 * A stack's in-type tests name what their identity blocks declare: every field a test reads or is limited by is one
	 * of its block's fields, and every map is kept in its block's definition. A character's own picks are a record on
	 * the sheet, not a declared field.
	 *
	 * @param array<string,mixed>              $data           The stack's decoded file.
	 * @param array<string,array<string,mixed>> $blocks_by_slug Every block file, by slug.
	 * @return string[]
	 */
	public static function validate_in_type_refs( array $data, array $blocks_by_slug ): array {
		if ( ( $data['kind'] ?? '' ) !== 'stack' ) {
			return [];
		}
		$errors = [];
		foreach ( (array) ( $data['definition']['sections'] ?? [] ) as $section ) {
			if ( is_array( $section ) && is_array( $section['in_type'] ?? null ) ) {
				$errors = array_merge( $errors, self::in_type_refs( $section['in_type'], (string) ( $section['block_slug'] ?? '?' ), $blocks_by_slug ) );
			}
		}
		return $errors;
	}

	/**
	 * @param array<int,mixed>                  $tests
	 * @param array<string,array<string,mixed>> $blocks_by_slug
	 * @return string[]
	 */
	private static function in_type_refs( array $tests, string $section, array $blocks_by_slug ): array {
		$declared = static function ( string $ref ) use ( $blocks_by_slug ): bool {
			[ $slug, $name ] = array_pad( explode( '.', $ref, 2 ), 2, '' );
			foreach ( (array) ( $blocks_by_slug[ $slug ]['definition']['fields'] ?? [] ) as $field ) {
				if ( is_array( $field ) && ( $field['name'] ?? null ) === $name ) {
					return true;
				}
			}
			return false;
		};

		$errors = [];
		foreach ( $tests as $test ) {
			if ( ! is_array( $test ) ) {
				continue;
			}
			$source = is_array( $test['values'] ?? null ) ? $test['values'] : [];
			$reads  = ( $test['kind'] ?? '' ) === 'chosen' ? [] : (array) ( $source['field'] ?? [] );
			if ( isset( $test['when']['field'] ) ) {
				$reads[] = $test['when']['field'];
			}
			if ( is_string( $source['map'] ?? null ) ) {
				[ $slug, $key ] = array_pad( explode( '.', $source['map'], 2 ), 2, '' );
				if ( ! is_array( $blocks_by_slug[ $slug ]['definition'][ $key ] ?? null ) ) {
					$errors[] = sprintf( '"%s" reads the map "%s", which "%s" does not keep', $section, $source['map'], $slug );
				}
				foreach ( (array) ( $source['by'] ?? [] ) as $by ) {
					$reads[] = $slug . '.' . (string) $by;
				}
			}
			foreach ( $reads as $ref ) {
				if ( is_string( $ref ) && ! $declared( $ref ) ) {
					$errors[] = sprintf( '"%s" reads "%s", which is not a declared field', $section, $ref );
				}
			}
			if ( is_array( $test['tests'] ?? null ) ) {
				$errors = array_merge( $errors, self::in_type_refs( $test['tests'], $section, $blocks_by_slug ) );
			}
		}
		return $errors;
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
	 * An `add` variant of a tiered power block carries picks only in a family its base already has: ladder levels there
	 * would not fold in.
	 *
	 * @param array<string,mixed>      $data The variant's decoded file.
	 * @param array<string,mixed>|null $base The base block's decoded file, when it has one.
	 * @return string[]
	 */
	public static function validate_variant( array $data, ?array $base ): array {
		$variant = is_array( $data['variant'] ?? null ) ? $data['variant'] : null;
		if ( $variant === null || ( $variant['mode'] ?? '' ) !== 'add' || $base === null || ( $data['section_type'] ?? '' ) !== 'tiered_power' ) {
			return [];
		}
		$base_families = [];
		foreach ( (array) ( $base['definition']['powers'] ?? [] ) as $power ) {
			if ( is_array( $power ) && is_string( $power['name'] ?? null ) ) {
				$base_families[ $power['name'] ] = true;
			}
		}
		$errors = [];
		foreach ( (array) ( $data['definition']['powers'] ?? [] ) as $power ) {
			if ( ! is_array( $power ) || ! is_string( $power['name'] ?? null ) || ! isset( $base_families[ $power['name'] ] ) ) {
				continue;
			}
			if ( ! empty( $power['levels'] ) ) {
				$errors[] = sprintf( 'adds ladder levels to "%s", a family its base already has; an add variant carries only picks there', $power['name'] );
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
		} elseif ( $section_type === 'resource_pool' ) {
			$errors = array_merge( $errors, self::validate_resource_pool( $definition ) );
		} elseif ( $section_type === 'identity_field' ) {
			$errors = array_merge( $errors, self::validate_identity_field( $definition ) );
		}

		return $errors;
	}

	/**
	 * A block whose `_meta.untiered` derives its costs from another block names real entries of it: each prerequisite in
	 * that block names one of its families, at a level that family has.
	 *
	 * @param array<string,mixed>      $data   The block's decoded file.
	 * @param array<string,mixed>|null $source The decoded file of the block its costs derive from, when there is one.
	 * @return string[]
	 */
	public static function validate_derived( array $data, ?array $source ): array {
		$from = $data['definition']['_meta']['untiered']['derived_from'] ?? null;
		if ( ! is_string( $from ) || $from === '' || ( $data['section_type'] ?? '' ) !== 'trait_list' ) {
			return [];
		}
		if ( $source === null ) {
			return [ sprintf( 'derives its costs from "%s", which the catalog does not have', $from ) ];
		}

		$ceilings = [];
		foreach ( (array) ( $source['definition']['powers'] ?? [] ) as $power ) {
			if ( is_array( $power ) && is_string( $power['name'] ?? null ) ) {
				$ceilings[ $power['name'] ] = count( (array) ( $power['levels'] ?? [] ) );
			}
		}

		$errors = [];
		foreach ( (array) ( $data['definition']['items'] ?? [] ) as $item ) {
			foreach ( is_array( $item ) ? (array) ( $item['prerequisites'] ?? [] ) : [] as $prerequisite ) {
				if ( ! is_array( $prerequisite ) || ( $prerequisite['block_slug'] ?? null ) !== $from ) {
					continue;
				}
				$power = (string) ( $prerequisite['power'] ?? '' );
				$level = $prerequisite['min_level'] ?? null;
				if ( ! isset( $ceilings[ $power ] ) ) {
					$errors[] = sprintf( '"%s" needs "%s", which is not a family of "%s"', (string) ( $item['name'] ?? '?' ), $power, $from );
				} elseif ( ! is_int( $level ) || $level > $ceilings[ $power ] ) {
					$errors[] = sprintf( '"%s" needs %s %s, a level "%s" does not have', (string) ( $item['name'] ?? '?' ), $power, is_scalar( $level ) ? (string) $level : gettype( $level ), $from );
				}
			}
		}
		return $errors;
	}

	/**
	 * Validates a resource_pool block's payload: a pool priced per level states `sliding_cost` as `{"equals_level":
	 * true}`, in place of `cost_per_dot`.
	 *
	 * @param array<string,mixed> $definition
	 * @return string[]
	 */
	private static function validate_resource_pool( array $definition ): array {
		$errors = [];
		foreach ( (array) $definition['pools'] as $i => $pool ) {
			if ( ! is_array( $pool ) ) {
				continue;
			}
			$name = (string) ( $pool['name'] ?? $i );

			if ( array_key_exists( 'sliding_cost', $pool ) && $pool['sliding_cost'] !== null ) {
				if ( ! is_array( $pool['sliding_cost'] ) || ( $pool['sliding_cost']['equals_level'] ?? null ) !== true ) {
					$errors[] = sprintf( 'pool "%s" has a `sliding_cost` that is not {"equals_level": true}', $name );
				}
				if ( isset( $pool['cost_per_dot'] ) ) {
					$errors[] = sprintf( 'pool "%s" states both `sliding_cost` and `cost_per_dot` - a pool has one cost rule, not two', $name );
				}
			}

			if ( ! empty( $pool['buy_down'] ) ) {
				if ( ! isset( $pool['cost_per_dot'] ) ) {
					$errors[] = sprintf( 'pool "%s" is `buy_down` and needs `cost_per_dot` - what lowering it by one costs', $name );
				}
				if ( array_key_exists( 'sliding_cost', $pool ) && $pool['sliding_cost'] !== null ) {
					$errors[] = sprintf( 'pool "%s" states both `buy_down` and `sliding_cost` - a pool prices one way, not two', $name );
				}
				if ( array_key_exists( 'free_dots', $pool ) && $pool['free_dots'] !== null ) {
					$errors[] = sprintf( 'pool "%s" states both `buy_down` and `free_dots` - a buy-down pool has nothing free to raise past', $name );
				}
			}
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
		if ( array_key_exists( 'paid_from', $definition['_meta'] ?? [] ) ) {
			$paid_from = $definition['_meta']['paid_from'];
			$parts     = is_string( $paid_from ) ? explode( '.', $paid_from, 2 ) : [];
			if ( ! is_string( $paid_from ) || count( $parts ) !== 2 || $parts[0] === '' || $parts[1] === '' ) {
				$errors[] = '`_meta.paid_from` must be "block_slug.Pool Name" - the resource pool that pays for this list instead of the character\'s own XP';
			}
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

		return array_merge( $errors, self::validate_list_costs( $definition ) );
	}

	/**
	 * A list's `_meta.untiered` derives its items' costs from another block (`derived_from` and a whole `per_level`), and
	 * then every item has its own `cost` or a prerequisite in that block; every prerequisite names a block, a family and
	 * a whole `min_level` from 1.
	 *
	 * @param array<string,mixed> $definition
	 * @return string[]
	 */
	private static function validate_list_costs( array $definition ): array {
		$errors   = [];
		$untiered = $definition['_meta']['untiered'] ?? null;
		$from     = null;
		if ( $untiered !== null ) {
			if ( ! is_array( $untiered ) || ! is_string( $untiered['derived_from'] ?? null ) || $untiered['derived_from'] === '' ) {
				$errors[] = '`_meta.untiered` on a list needs `derived_from` - a list prices each item by its own cost or by its prerequisites; only a track has a cost per level';
			} else {
				$from = $untiered['derived_from'];
			}
			if ( is_array( $untiered ) && ( ! is_int( $untiered['per_level'] ?? null ) || $untiered['per_level'] < 1 ) ) {
				$errors[] = '`_meta.untiered.per_level` must be a whole number from 1';
			}
			if ( is_array( $untiered ) && array_key_exists( 'cost_per_level', $untiered ) ) {
				$errors[] = '`_meta.untiered.cost_per_level` on a list - a list prices each item by its own cost or its prerequisites; only a track has a cost per level';
			}
		}

		foreach ( (array) $definition['items'] as $i => $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$name          = (string) ( $item['name'] ?? $i );
			$prerequisites = $item['prerequisites'] ?? [];
			if ( ! is_array( $prerequisites ) || ! array_is_list( $prerequisites ) ) {
				$errors[] = sprintf( '"%s" has `prerequisites` that are not a list', $name );
				continue;
			}
			$derives = false;
			foreach ( $prerequisites as $prerequisite ) {
				if ( ! is_array( $prerequisite ) || ! is_string( $prerequisite['block_slug'] ?? null ) || ! is_string( $prerequisite['power'] ?? null ) || ! is_int( $prerequisite['min_level'] ?? null ) || $prerequisite['min_level'] < 1 ) {
					$errors[] = sprintf( '"%s" has a prerequisite that is not {block_slug, power, min_level} with a whole level from 1', $name );
					continue;
				}
				$derives = $derives || $prerequisite['block_slug'] === $from;
			}
			if ( $from !== null && ! $derives && ( ! is_string( $item['cost'] ?? null ) || $item['cost'] === '' ) ) {
				$errors[] = sprintf( '"%s" has no `cost` and no prerequisite in "%s" to derive one from', $name, $from );
			}
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

		if ( array_key_exists( 'out_of_type_cost_modifier', $definition ) ) {
			$errors[] = '`out_of_type_cost_modifier` is retired - a block declares its modifiers per rank, in `_meta.out_of_type` and `_meta.in_type`';
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

		$errors = array_merge( $errors, self::validate_modifiers( $meta, $ranks ) );

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
	 * `_meta.in_type` and `_meta.out_of_type`: each a map from a rank the block declares to a modifier, `+N`, `-N` or
	 * `×N`.
	 *
	 * @param array<string,mixed> $meta
	 * @param string[]            $ranks
	 * @return string[]
	 */
	private static function validate_modifiers( array $meta, array $ranks ): array {
		$errors = [];
		foreach ( [ 'in_type', 'out_of_type' ] as $side ) {
			if ( ! isset( $meta[ $side ] ) ) {
				continue;
			}
			if ( ! is_array( $meta[ $side ] ) || ( $meta[ $side ] !== [] && array_is_list( $meta[ $side ] ) ) ) {
				$errors[] = sprintf( '`_meta.%s` must be a map from rank to modifier', $side );
				continue;
			}
			foreach ( $meta[ $side ] as $rank => $expression ) {
				if ( ! in_array( (string) $rank, $ranks, true ) ) {
					$errors[] = sprintf( '`_meta.%s` gives rank "%s", which is not in `_meta.ranks`', $side, (string) $rank );
				}
				if ( ! is_string( $expression ) || ! Cost_Engine::is_modifier( $expression ) ) {
					$errors[] = sprintf( '`_meta.%s.%s` is %s - a modifier is +N, -N or ×N', $side, (string) $rank, is_scalar( $expression ) ? '"' . (string) $expression . '"' : gettype( $expression ) );
				}
			}
		}
		return $errors;
	}

	/**
	 * An untiered track: no ranks, no ladder, no picks, no modifiers.
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
		if ( $derived ) {
			$errors[] = '`_meta.untiered.derived_from` prices a list\'s items by their prerequisites, and a track has none - a track states `cost_per_level`';
		}
		foreach ( [ 'in_type', 'out_of_type' ] as $side ) {
			if ( isset( $meta[ $side ] ) ) {
				$errors[] = sprintf( '`_meta.%s` on an untiered track - it has no ranks for a modifier to name', $side );
			}
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
				'"%s" has %d overflow level(s) - a declared file must have none: the family is two ladders merged, and each needs its own family before the file can be committed',
				$name,
				count( $overflow )
			);
		}

		return $errors;
	}

	/**
	 * Validates an identity_field block's payload: a `select`/`multiselect` field carries `options` or `options_ref`,
	 * never both, never neither.
	 *
	 * @param array<string,mixed> $definition
	 * @return string[]
	 */
	private static function validate_identity_field( array $definition ): array {
		$errors = [];
		foreach ( (array) $definition['fields'] as $i => $field ) {
			if ( ! is_array( $field ) ) {
				$errors[] = sprintf( 'fields[%s] is not an object', (string) $i );
				continue;
			}
			$name = (string) ( $field['name'] ?? $i );
			if ( $name === '' ) {
				$errors[] = sprintf( 'fields[%s] has no `name`', (string) $i );
			}
			if ( ! in_array( $field['field_type'] ?? null, [ 'select', 'multiselect' ], true ) ) {
				continue;
			}
			$has_options = isset( $field['options'] ) && is_array( $field['options'] ) && $field['options'] !== [];
			$has_ref     = isset( $field['options_ref'] ) && is_string( $field['options_ref'] ) && $field['options_ref'] !== '';
			if ( $has_options === $has_ref ) {
				$errors[] = sprintf( '"%s" needs exactly one of `options` (a non-empty list) or `options_ref` (a block slug)', $name );
			}
		}
		return $errors;
	}

	/**
	 * A stack's `options_ref` fields name a real declared block.
	 *
	 * @param array<string,mixed>              $data           The stack's decoded file.
	 * @param array<string,array<string,mixed>> $blocks_by_slug Every block file, by slug.
	 * @return string[]
	 */
	public static function validate_identity_field_refs( array $data, array $blocks_by_slug ): array {
		if ( ( $data['kind'] ?? '' ) !== 'block' || ( $data['section_type'] ?? '' ) !== 'identity_field' ) {
			return [];
		}
		$errors = [];
		foreach ( (array) ( $data['definition']['fields'] ?? [] ) as $field ) {
			$ref = is_array( $field ) ? ( $field['options_ref'] ?? null ) : null;
			if ( is_string( $ref ) && $ref !== '' && ! isset( $blocks_by_slug[ $ref ] ) ) {
				$errors[] = sprintf( '"%s" has `options_ref` "%s", which the catalog does not have', (string) ( $field['name'] ?? '?' ), $ref );
			}
		}
		return $errors;
	}

	/**
	 * `creation_rules.steps`: a non-empty list, each a known kind with that kind's own fields, checked against this
	 * stack's own declared sections.
	 *
	 * @param array<string,mixed> $rules
	 * @param string[]            $section_slugs
	 * @return string[]
	 */
	private static function validate_creation_rules( array $rules, array $section_slugs ): array {
		$steps = $rules['steps'] ?? null;
		if ( ! is_array( $steps ) || $steps === [] || ! array_is_list( $steps ) ) {
			return [ '`creation_rules.steps` must be a non-empty list' ];
		}
		$errors = [];
		foreach ( $steps as $i => $step ) {
			$errors = array_merge( $errors, self::validate_creation_step( $step, $section_slugs, sprintf( 'creation_rules.steps[%s]', (string) $i ) ) );
		}
		return $errors;
	}

	/**
	 * @param mixed    $step
	 * @param string[] $section_slugs
	 * @return string[]
	 */
	private static function validate_creation_step( $step, array $section_slugs, string $at ): array {
		if ( ! is_array( $step ) ) {
			return [ "{$at} is not an object" ];
		}
		$kind = $step['kind'] ?? null;
		if ( ! in_array( $kind, Creation_Tally::KINDS, true ) ) {
			return [ sprintf( '%s has `kind` %s - one of: %s', $at, is_scalar( $kind ) ? '"' . (string) $kind . '"' : gettype( $kind ), implode( ', ', Creation_Tally::KINDS ) ) ];
		}
		if ( ! is_string( $step['label'] ?? null ) || $step['label'] === '' ) {
			return [ "{$at} has no `label`" ];
		}

		$errors = [];
		if ( array_key_exists( 'when', $step ) ) {
			$errors = array_merge( $errors, self::validate_step_when( $step['when'], "{$at}.when" ) );
		}

		$section = static function ( string $ref ) use ( $section_slugs, $at ): array {
			return in_array( $ref, $section_slugs, true ) ? [] : [ sprintf( '%s names "%s", which is not one of this creature type\'s own sections', $at, $ref ) ];
		};

		switch ( $kind ) {
			case 'prioritized':
				$sections = $step['sections'] ?? null;
				$amounts  = $step['amounts'] ?? null;
				if ( ! is_array( $sections ) || $sections === [] || ! array_is_list( $sections ) ) {
					$errors[] = "{$at}.sections must be a non-empty list";
					break;
				}
				foreach ( $sections as $ref ) {
					$errors = is_string( $ref ) ? array_merge( $errors, $section( $ref ) ) : array_merge( $errors, [ "{$at}.sections must be a list of section slugs" ] );
				}
				if ( ! is_array( $amounts ) || count( $amounts ) !== count( $sections ) || array_filter( $amounts, static fn( $a ): bool => ! is_int( $a ) || $a < 0 ) !== [] ) {
					$errors[] = "{$at}.amounts must be as many non-negative whole numbers as sections";
				} else {
					$sorted = $amounts;
					rsort( $sorted );
					if ( $sorted !== $amounts ) {
						$errors[] = "{$at}.amounts must be largest first";
					}
				}
				break;

			case 'budget':
				if ( ! is_string( $step['section'] ?? null ) || $step['section'] === '' ) {
					$errors[] = "{$at}.section is required";
				} else {
					$errors = array_merge( $errors, $section( $step['section'] ) );
				}
				if ( ! is_int( $step['count'] ?? null ) || $step['count'] < 0 ) {
					$errors[] = "{$at}.count must be a non-negative whole number";
				}
				if ( array_key_exists( 'filter', $step ) ) {
					$filter = $step['filter'];
					if ( ! is_array( $filter ) ) {
						$errors[] = "{$at}.filter must be an object";
					} else {
						if ( array_key_exists( 'in_type', $filter ) && ! is_bool( $filter['in_type'] ) ) {
							$errors[] = "{$at}.filter.in_type must be true or false";
						}
						if ( array_key_exists( 'tier', $filter ) && ( ! is_string( $filter['tier'] ) || $filter['tier'] === '' ) ) {
							$errors[] = "{$at}.filter.tier must be a rank name";
						}
						if ( array_key_exists( 'test', $filter ) ) {
							$errors = array_merge( $errors, self::validate_in_type_test( $filter['test'], "{$at}.filter.test" ) );
						}
					}
				}
				foreach ( self::as_list( $step['quotas'] ?? [] ) as $j => $quota ) {
					if ( ! is_array( $quota ) ) {
						$errors[] = sprintf( '%s.quotas[%s] is not an object', $at, (string) $j );
						continue;
					}
					if ( ! is_string( $quota['label'] ?? null ) || $quota['label'] === '' ) {
						$errors[] = sprintf( '%s.quotas[%s] has no `label`', $at, (string) $j );
					}
					if ( ! is_int( $quota['min'] ?? null ) || $quota['min'] < 1 ) {
						$errors[] = sprintf( '%s.quotas[%s].min must be a whole number from 1', $at, (string) $j );
					}
					$errors = array_merge( $errors, self::validate_in_type_test( $quota['test'] ?? null, sprintf( '%s.quotas[%s].test', $at, (string) $j ) ) );
				}
				break;

			case 'free':
				if ( ! is_string( $step['pool'] ?? null ) || $step['pool'] === '' ) {
					$errors[] = "{$at}.pool is required";
				}
				if ( ! is_int( $step['points'] ?? null ) || $step['points'] < 0 ) {
					$errors[] = "{$at}.points must be a non-negative whole number";
				}
				$rates = $step['rates'] ?? null;
				if ( ! is_array( $rates ) || $rates === [] ) {
					$errors[] = "{$at}.rates must be a non-empty object";
					break;
				}
				foreach ( $rates as $ref => $rate ) {
					$base = explode( '.', (string) $ref, 2 )[0];
					$errors = array_merge( $errors, $section( $base ) );
					if ( $rate !== 'value' && ( ! is_int( $rate ) || $rate < 0 ) ) {
						$errors[] = sprintf( '%s.rates["%s"] must be a non-negative whole number or "value"', $at, (string) $ref );
					}
				}
				break;

			case 'earned':
				if ( ! is_string( $step['pool'] ?? null ) || $step['pool'] === '' ) {
					$errors[] = "{$at}.pool is required";
				}
				$sources = $step['sources'] ?? null;
				if ( ! is_array( $sources ) || $sources === [] || ! array_is_list( $sources ) ) {
					$errors[] = "{$at}.sources must be a non-empty list";
					break;
				}
				foreach ( $sources as $j => $source ) {
					if ( ! is_array( $source ) || ! is_string( $source['section'] ?? null ) || $source['section'] === '' ) {
						$errors[] = sprintf( '%s.sources[%s] needs a `section`', $at, (string) $j );
						continue;
					}
					$errors = array_merge( $errors, $section( $source['section'] ) );
					$rate   = $source['rate'] ?? null;
					if ( $rate !== 'value' && ! is_int( $rate ) ) {
						$errors[] = sprintf( '%s.sources[%s].rate must be a whole number or "value"', $at, (string) $j );
					}
					if ( ! is_int( $source['max'] ?? null ) || $source['max'] < 1 ) {
						$errors[] = sprintf( '%s.sources[%s].max must be a whole number from 1', $at, (string) $j );
					}
				}
				if ( array_key_exists( 'max', $step ) && ( ! is_int( $step['max'] ) || $step['max'] < 1 ) ) {
					$errors[] = "{$at}.max must be a whole number from 1";
				}
				break;

			case 'limit':
				if ( ! is_string( $step['section'] ?? null ) || $step['section'] === '' ) {
					$errors[] = "{$at}.section is required";
				} else {
					$errors = array_merge( $errors, $section( explode( '.', $step['section'], 2 )[0] ) );
				}
				if ( ! array_key_exists( 'max_points', $step ) && ! array_key_exists( 'max_rating', $step ) && ! array_key_exists( 'min_rating', $step ) && ! array_key_exists( 'ceiling', $step ) ) {
					$errors[] = "{$at} needs at least one of `max_points`, `max_rating`, `min_rating` or `ceiling`";
				}
				if ( array_key_exists( 'max_points', $step ) && ( ! is_int( $step['max_points'] ) || $step['max_points'] < 0 ) ) {
					$errors[] = "{$at}.max_points must be a non-negative whole number";
				}
				if ( array_key_exists( 'max_rating', $step ) ) {
					$errors = array_merge( $errors, self::validate_max_rating( $step['max_rating'], "{$at}.max_rating" ) );
				}
				if ( array_key_exists( 'min_rating', $step ) && ! is_numeric( $step['min_rating'] ) ) {
					$errors[] = "{$at}.min_rating must be a number";
				}
				if ( array_key_exists( 'ceiling', $step ) ) {
					$ceiling = $step['ceiling'];
					if ( is_string( $ceiling ) ) {
						if ( ! self::is_field( $ceiling ) ) {
							$errors[] = "{$at}.ceiling must be \"block.Name\" or {\"named_by\": \"block.Field\"}";
						}
					} elseif ( is_array( $ceiling ) ) {
						if ( ! self::is_field( $ceiling['named_by'] ?? null ) ) {
							$errors[] = "{$at}.ceiling.named_by must be \"block.Field\"";
						}
					} else {
						$errors[] = "{$at}.ceiling must be \"block.Name\" or {\"named_by\": \"block.Field\"}";
					}
				}
				break;

			case 'start':
				if ( ! self::is_field( $step['target'] ?? null ) ) {
					$errors[] = "{$at}.target must be \"block.Name\"";
				}
				$given = array_values( array_intersect( [ 'value', 'lookup', 'formula' ], array_keys( $step ) ) );
				if ( count( $given ) !== 1 ) {
					$errors[] = "{$at} names " . ( $given === [] ? 'none' : implode( ' and ', $given ) ) . ' of `value`, `lookup` or `formula` - exactly one';
					break;
				}
				if ( $given[0] === 'value' && ! is_numeric( $step['value'] ) ) {
					$errors[] = "{$at}.value must be a number";
				}
				if ( $given[0] === 'lookup' ) {
					$errors = array_merge( $errors, self::validate_map_ref( $step['lookup'], "{$at}.lookup" ) );
				}
				if ( $given[0] === 'formula' ) {
					if ( ! in_array( $step['formula'], [ 'average_up', 'sum_top_two', 'equal' ], true ) ) {
						$errors[] = "{$at}.formula must be one of: average_up, sum_top_two, equal";
					}
					$of = $step['of'] ?? null;
					if ( ! is_array( $of ) || $of === [] || array_filter( $of, static fn( $r ): bool => ! self::is_field( $r ) ) !== [] ) {
						$errors[] = "{$at}.of must be a non-empty list of \"block.Name\" references";
					} elseif ( $step['formula'] === 'equal' && count( $of ) !== 1 ) {
						$errors[] = "{$at}.of must name exactly one target when `formula` is \"equal\"";
					}
				}
				break;

			case 'grant':
				if ( ! is_string( $step['section'] ?? null ) || $step['section'] === '' ) {
					$errors[] = "{$at}.section is required";
				} else {
					$errors = array_merge( $errors, $section( $step['section'] ) );
				}
				$has_entries = array_key_exists( 'entries', $step );
				$has_from    = array_key_exists( 'from', $step );
				if ( $has_entries === $has_from ) {
					$errors[] = "{$at} needs exactly one of `entries` or `from`";
					break;
				}
				if ( $has_entries ) {
					$entries = $step['entries'];
					if ( ! is_array( $entries ) || $entries === [] || ! array_is_list( $entries ) ) {
						$errors[] = "{$at}.entries must be a non-empty list";
						break;
					}
					foreach ( $entries as $j => $entry ) {
						if ( ! is_array( $entry ) || ! is_string( $entry['name'] ?? null ) || $entry['name'] === '' ) {
							$errors[] = sprintf( '%s.entries[%s] needs a `name`', $at, (string) $j );
						}
					}
				} else {
					$errors = array_merge( $errors, self::validate_map_ref( $step['from'], "{$at}.from" ) );
					if ( ! array_key_exists( 'level', $step ) || ! is_int( $step['level'] ) ) {
						$errors[] = "{$at}.level must be a whole number";
					}
				}
				break;
		}

		return $errors;
	}

	/**
	 * @param mixed $max_rating A number, or `{base, plus?, cap?}`.
	 */
	private static function validate_max_rating( $max_rating, string $at ): array {
		if ( is_numeric( $max_rating ) ) {
			return [];
		}
		if ( ! is_array( $max_rating ) || ! is_numeric( $max_rating['base'] ?? null ) ) {
			return [ "{$at} must be a number or {\"base\": N, ...}" ];
		}
		$errors = [];
		if ( array_key_exists( 'plus', $max_rating ) && ! self::is_field( $max_rating['plus'] ) ) {
			$errors[] = "{$at}.plus must be \"block.Name\"";
		}
		if ( array_key_exists( 'cap', $max_rating ) && ! is_numeric( $max_rating['cap'] ) ) {
			$errors[] = "{$at}.cap must be a number";
		}
		return $errors;
	}

	/**
	 * @param mixed $ref `{map: "block.key", by: [...]}`.
	 */
	private static function validate_map_ref( $ref, string $at ): array {
		if ( ! is_array( $ref ) || ! self::is_field( $ref['map'] ?? null ) ) {
			return [ "{$at}.map must be \"block.key\", a map kept in an identity block" ];
		}
		$by = $ref['by'] ?? null;
		if ( ! is_array( $by ) || $by === [] || array_filter( $by, static fn( $f ): bool => ! is_string( $f ) || $f === '' ) !== [] ) {
			return [ "{$at}.by must list the fields the map is keyed by, in the order they are tried" ];
		}
		return [];
	}

	/**
	 * A step's own `when`: the in-type shape (`is`/`set`) plus `not` (the field holds none of the values), or a
	 * non-empty list of such tests, every one of which must hold.
	 *
	 * @param mixed $when
	 * @return string[]
	 */
	private static function validate_step_when( $when, string $at ): array {
		if ( is_array( $when ) && array_is_list( $when ) ) {
			if ( $when === [] ) {
				return [ "{$at} must be a non-empty list of tests" ];
			}
			$errors = [];
			foreach ( $when as $i => $one ) {
				$errors = array_merge( $errors, self::validate_step_when( $one, sprintf( '%s[%s]', $at, (string) $i ) ) );
			}
			return $errors;
		}
		if ( ! is_array( $when ) || ! self::is_field( $when['field'] ?? null ) ) {
			return [ "{$at} needs a `field`, \"block_slug.Field\"" ];
		}
		$given = array_values( array_intersect( [ 'is', 'not', 'set' ], array_keys( $when ) ) );
		if ( count( $given ) !== 1 ) {
			return [ "{$at} needs exactly one of `is`, `not` or `set`" ];
		}
		if ( $given[0] === 'set' ) {
			return is_bool( $when['set'] ) ? [] : [ "{$at}.set must be true or false" ];
		}
		$values = $when[ $given[0] ];
		return is_array( $values ) && $values !== [] && array_filter( $values, static fn( $v ): bool => ! is_string( $v ) || $v === '' ) === []
			? []
			: [ sprintf( '%s.%s must be a non-empty list of values', $at, $given[0] ) ];
	}

	/**
	 * A stack's `creation_rules` names real sections, and every map a `start.lookup` or `grant.from` reads is one an
	 * identity block actually keeps.
	 *
	 * @param array<string,mixed>              $data           The stack's decoded file.
	 * @param array<string,array<string,mixed>> $blocks_by_slug Every block file, by slug.
	 * @return string[]
	 */
	public static function validate_creation_rules_refs( array $data, array $blocks_by_slug ): array {
		if ( ( $data['kind'] ?? '' ) !== 'stack' ) {
			return [];
		}
		$steps = $data['definition']['creation_rules']['steps'] ?? null;
		if ( ! is_array( $steps ) ) {
			return [];
		}

		$errors = [];
		foreach ( $steps as $i => $step ) {
			if ( ! is_array( $step ) ) {
				continue;
			}
			$at = sprintf( 'creation_rules.steps[%s]', (string) $i );

			if ( is_array( $step['filter']['test'] ?? null ) ) {
				$errors = array_merge( $errors, self::in_type_refs( [ $step['filter']['test'] ], $at, $blocks_by_slug ) );
			}
			foreach ( self::as_list( $step['quotas'] ?? [] ) as $quota ) {
				if ( is_array( $quota ) && is_array( $quota['test'] ?? null ) ) {
					$errors = array_merge( $errors, self::in_type_refs( [ $quota['test'] ], $at, $blocks_by_slug ) );
				}
			}
			foreach ( [ $step['lookup'] ?? null, $step['from'] ?? null ] as $ref ) {
				if ( is_array( $ref ) && is_string( $ref['map'] ?? null ) ) {
					[ $slug, $key ] = array_pad( explode( '.', $ref['map'], 2 ), 2, '' );
					if ( ! is_array( $blocks_by_slug[ $slug ]['definition'][ $key ] ?? null ) ) {
						$errors[] = sprintf( '%s reads the map "%s", which "%s" does not keep', $at, $ref['map'], $slug );
					}
				}
			}
		}
		return $errors;
	}

	/**
	 * @param mixed $value
	 * @return array<int,mixed>
	 */
	private static function as_list( $value ): array {
		return is_array( $value ) ? array_values( $value ) : [];
	}
}
