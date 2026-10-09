<?php

namespace BeyondElysium\Services;

use BeyondElysium\Models\Creature_Stack;
use BeyondElysium\Models\Schema_Block;

defined( 'ABSPATH' ) || exit;

/**
 * Whether a purchase is in-type for a character: the `in_type` tests on the section of the character's creature type
 * that holds the block, any one passing. A test reads values from an identity field, from a map kept in an identity
 * block, or from a constant, and passes when the family's name is one of them (`names`), when the family's own value
 * for a named facet is (`facet` - a trait_list item's `group`/`subgroup`, or one axis of a tiered_power family's
 * `category_values`), or when the family is one of the character's own picks (`chosen`); `all` passes when every test
 * it holds does, and `when` limits a test to characters with a given identity value.
 */
class In_Type {

	/**
	 * The kinds of test.
	 */
	const KINDS = [ 'names', 'facet', 'chosen', 'all' ];

	/**
	 * Whether each family of a block is in-type for a character. Every family is where the block's section declares no
	 * test.
	 *
	 * @param object               $character A character row with decoded `sheet_data`, `stack_slug` and `owner_slug`.
	 * @param object|null          $stack     The character's creature type, when already loaded.
	 * @param array<string,object> $blocks    The character's chronicle's blocks by slug, fork-aware, when already loaded.
	 * @return callable(string):bool
	 */
	public static function check( $character, string $block_slug, ?object $stack = null, array $blocks = [] ): callable {
		$game  = (string) ( $character->owner_slug ?? '' );
		$stack = $stack ?? Creature_Stack::find_for_game( (string) ( $character->stack_slug ?? '' ), $game );
		$tests = $stack ? self::tests_for( $stack, $block_slug ) : [];
		if ( $tests === [] ) {
			return static fn( string $name ): bool => true;
		}

		$sheet  = is_array( $character->sheet_data ?? null ) ? $character->sheet_data : [];
		$loaded = $blocks;
		$block  = static function ( string $slug ) use ( &$loaded, $game ): ?object {
			if ( ! array_key_exists( $slug, $loaded ) ) {
				$loaded[ $slug ] = Schema_Block::find_for_game( $slug, $game );
			}
			return is_object( $loaded[ $slug ] ) ? $loaded[ $slug ] : null;
		};
		$facets = self::uses_facet( $tests );

		return static function ( string $name ) use ( $tests, $sheet, $block, $block_slug, $facets ): bool {
			$family = $facets ? self::family( $block( $block_slug ), $name ) : null;
			return self::passes_any( $tests, $name, $family, $sheet, $block );
		};
	}

	/**
	 * Whether any of a section's tests passes for one family.
	 *
	 * @param array<int,mixed>          $tests
	 * @param string                    $name   The family's name.
	 * @param object|null               $family The family or item itself, for a `facet` test.
	 * @param array<string,mixed>       $sheet  The character's sheet data.
	 * @param callable(string):?object  $block  An identity block by slug, for a map.
	 */
	public static function passes_any( array $tests, string $name, ?object $family, array $sheet, callable $block ): bool {
		foreach ( $tests as $test ) {
			if ( self::passes( self::as_array( $test ), $name, $family, $sheet, $block ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Every identity field a creature type's tests read, as "block_slug.Field".
	 *
	 * @param object|null $stack A decoded creature stack row.
	 * @return string[]
	 */
	public static function fields( $stack ): array {
		$fields = [];
		foreach ( ( $stack->stack_definition->sections ?? [] ) as $section ) {
			foreach ( self::as_list( $section->in_type ?? [] ) as $test ) {
				$fields = array_merge( $fields, self::test_fields( self::as_array( $test ) ) );
			}
		}
		return array_values( array_unique( $fields ) );
	}

	/**
	 * Whether any creature type in the given chronicle declares an in_type test for the given block.
	 *
	 * @param string $block_slug
	 * @param string $game_slug
	 * @return bool
	 */
	public static function has_test_for_block( string $block_slug, string $game_slug ): bool {
		foreach ( Creature_Stack::all_for_game( $game_slug ) as $stack ) {
			if ( self::tests_for( $stack, $block_slug ) !== [] ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The tests on a creature type's section for one block.
	 *
	 * @return array<int,mixed>
	 */
	private static function tests_for( object $stack, string $block_slug ): array {
		foreach ( ( $stack->stack_definition->sections ?? [] ) as $section ) {
			if ( ( $section->block_slug ?? null ) === $block_slug ) {
				return self::as_list( $section->in_type ?? [] );
			}
		}
		return [];
	}

	/**
	 * Whether one test passes for one family.
	 *
	 * @param array<string,mixed> $test
	 * @param array<string,mixed> $sheet
	 */
	private static function passes( array $test, string $name, ?object $family, array $sheet, callable $block ): bool {
		if ( isset( $test['when'] ) && ! self::applies( self::as_array( $test['when'] ), $sheet ) ) {
			return false;
		}
		$source = self::as_array( $test['values'] ?? [] );

		switch ( (string) ( $test['kind'] ?? '' ) ) {
			case 'names':
				$values = self::values( $source, $sheet, $block );
				return $values === null || in_array( $name, $values, true );

			case 'facet':
				$facet = (string) ( $test['facet'] ?? 'group' );
				$own   = self::facet_value( $family, $facet );
				if ( $own === '' && $facet === 'subgroup' ) {
					return true;
				}
				$values = self::values( $source, $sheet, $block );
				return $values === null || ( $own !== '' && in_array( $own, $values, true ) );

			case 'chosen':
				return in_array( $name, self::field_values( (string) ( $source['field'] ?? '' ), $sheet ), true );

			case 'all':
				$inner = self::as_list( $test['tests'] ?? [] );
				if ( $inner === [] ) {
					return false;
				}
				foreach ( $inner as $each ) {
					if ( ! self::passes( self::as_array( $each ), $name, $family, $sheet, $block ) ) {
						return false;
					}
				}
				return true;
		}
		return false;
	}

	/**
	 * The values a test's source gives for a character, or null when every identity field it reads is unset: nothing on
	 * the sheet then says the purchase is out of type.
	 *
	 * @param array<string,mixed> $source
	 * @param array<string,mixed> $sheet
	 * @return string[]|null
	 */
	private static function values( array $source, array $sheet, callable $block ): ?array {
		if ( array_key_exists( 'constant', $source ) ) {
			return array_values( array_map( 'strval', (array) $source['constant'] ) );
		}

		if ( array_key_exists( 'field', $source ) ) {
			$values = [];
			foreach ( (array) $source['field'] as $field ) {
				$values = array_merge( $values, self::field_values( (string) $field, $sheet ) );
			}
			return $values === [] ? null : array_values( array_unique( $values ) );
		}

		if ( array_key_exists( 'map', $source ) ) {
			[ $slug, $key ] = array_pad( explode( '.', (string) $source['map'], 2 ), 2, '' );
			$identity       = $block( $slug );
			$map            = $identity !== null ? self::as_array( $identity->definition->$key ?? [] ) : [];
			$known          = false;
			foreach ( (array) ( $source['by'] ?? [] ) as $field ) {
				foreach ( self::field_values( $slug . '.' . (string) $field, $sheet ) as $value ) {
					$known = true;
					if ( isset( $map[ $value ] ) ) {
						return array_values( array_map( 'strval', (array) $map[ $value ] ) );
					}
				}
			}
			return $known ? [] : null;
		}

		return [];
	}

	/**
	 * Whether a test's `when` holds for a character: its field has one of the values in `is`, or `set` says whether the
	 * field has any value.
	 *
	 * @param array<string,mixed> $when
	 * @param array<string,mixed> $sheet
	 */
	private static function applies( array $when, array $sheet ): bool {
		$values = self::field_values( (string) ( $when['field'] ?? '' ), $sheet );
		if ( array_key_exists( 'set', $when ) ) {
			return ( $values !== [] ) === (bool) $when['set'];
		}
		return array_intersect( $values, array_map( 'strval', (array) ( $when['is'] ?? [] ) ) ) !== [];
	}

	/**
	 * The values a character's sheet holds for one "block_slug.Field": a list's non-empty values, or a single non-empty
	 * value.
	 *
	 * @param array<string,mixed> $sheet
	 * @return string[]
	 */
	private static function field_values( string $field, array $sheet ): array {
		[ $slug, $name ] = array_pad( explode( '.', $field, 2 ), 2, '' );
		$value           = is_array( $sheet[ $slug ] ?? null ) ? ( $sheet[ $slug ][ $name ] ?? null ) : null;
		$values          = is_array( $value ) ? $value : [ $value ];
		return array_values( array_filter( array_map( static fn( $v ): string => is_scalar( $v ) ? trim( (string) $v ) : '', $values ), static fn( string $v ): bool => $v !== '' ) );
	}

	/**
	 * Every identity field one test reads.
	 *
	 * @param array<string,mixed> $test
	 * @return string[]
	 */
	private static function test_fields( array $test ): array {
		$fields = [];
		if ( isset( $test['when'] ) ) {
			$fields[] = (string) ( self::as_array( $test['when'] )['field'] ?? '' );
		}
		$source = self::as_array( $test['values'] ?? [] );
		foreach ( (array) ( $source['field'] ?? [] ) as $field ) {
			$fields[] = (string) $field;
		}
		if ( isset( $source['map'] ) ) {
			$slug = explode( '.', (string) $source['map'], 2 )[0];
			foreach ( (array) ( $source['by'] ?? [] ) as $field ) {
				$fields[] = $slug . '.' . (string) $field;
			}
		}
		foreach ( self::as_list( $test['tests'] ?? [] ) as $each ) {
			$fields = array_merge( $fields, self::test_fields( self::as_array( $each ) ) );
		}
		return array_filter( $fields, static fn( string $f ): bool => $f !== '' );
	}

	/**
	 * Whether any test, however deep, compares a family's group or subgroup.
	 *
	 * @param array<int,mixed> $tests
	 */
	private static function uses_facet( array $tests ): bool {
		foreach ( $tests as $test ) {
			$test = self::as_array( $test );
			if ( ( $test['kind'] ?? '' ) === 'facet' || self::uses_facet( self::as_list( $test['tests'] ?? [] ) ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * A block's family or item by name or alias.
	 */
	private static function family( ?object $block, string $name ): ?object {
		if ( $block === null ) {
			return null;
		}
		$definition = $block->definition ?? null;
		if ( isset( $definition->powers ) ) {
			return Trait_Alias_Resolver::find_power_by_name( (array) $definition->powers, $name );
		}
		return Trait_Alias_Resolver::find_item_by_name( (array) ( $definition->items ?? [] ), $name );
	}

	/**
	 * A family or item's own value for a named facet: a tiered_power family's `category_values` entry for that axis, or
	 * a trait_list item's own like-named property (`group`/`subgroup`).
	 */
	private static function facet_value( ?object $family, string $facet ): string {
		if ( $family === null ) {
			return '';
		}
		$categories = $family->category_values ?? null;
		if ( is_object( $categories ) && isset( $categories->$facet ) ) {
			return (string) $categories->$facet;
		}
		return (string) ( $family->$facet ?? '' );
	}

	/**
	 * An object or array as an array.
	 *
	 * @param mixed $value
	 * @return array<string,mixed>
	 */
	private static function as_array( $value ): array {
		if ( is_object( $value ) ) {
			return json_decode( (string) wp_json_encode( $value ), true ) ?: [];
		}
		return is_array( $value ) ? $value : [];
	}

	/**
	 * A list, or an empty one for anything else.
	 *
	 * @param mixed $value
	 * @return array<int,mixed>
	 */
	private static function as_list( $value ): array {
		return is_array( $value ) ? array_values( $value ) : [];
	}
}
