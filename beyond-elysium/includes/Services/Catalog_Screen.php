<?php

namespace BeyondElysium\Services;

use BeyondElysium\Models\Creature_Stack;
use BeyondElysium\Models\Schema_Block;

defined( 'ABSPATH' ) || exit;

/**
 * What the Catalog screen shows for a chronicle: the sections of each enabled creature type that take entries of their
 * own, and a section's entries with where each came from, whether the book has since changed under it, who holds it and
 * the hash a save sends back.
 */
class Catalog_Screen {

	/**
	 * The sections that take entries, grouped by creature type.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function sections( object $game ): array {
		$slug   = (string) $game->slug;
		$groups = [];

		foreach ( Creature_Stack::all_for_game( $slug, [], false, true ) as $stack ) {
			if ( Creature_Stack::allows_any_block( $stack ) ) {
				continue;
			}
			$resolved = Creature_Stack::resolve( (string) $stack->slug, $slug );
			if ( null === $resolved ) {
				continue;
			}
			$closed   = Creature_Stack::closed_blocks( $resolved['stack'] );
			$sections = [];
			foreach ( is_array( $stack->stack_definition->sections ?? null ) ? $stack->stack_definition->sections : [] as $section ) {
				$block_slug = (string) ( $section->block_slug ?? '' );
				$block      = $resolved['blocks'][ $block_slug ] ?? null;
				if ( null === $block || '' === $block_slug || ! empty( $section->hidden ) || in_array( $block_slug, $closed, true ) || ! self::takes_entries( $block ) ) {
					continue;
				}
				$sections[] = self::section( $stack, $section, $block, $resolved['blocks'] );
			}
			if ( [] !== $sections ) {
				$groups[] = [ 'stack' => (string) $stack->slug, 'name' => (string) $stack->name, 'sections' => $sections ];
			}
		}

		return $groups;
	}

	/**
	 * Every in-clan map any creature type's section of a block reads, as "block_slug.key".
	 *
	 * @return string[]
	 */
	public static function maps_for_block( string $game_slug, string $block_slug ): array {
		$maps = [];
		foreach ( Creature_Stack::all_for_game( $game_slug, [], true, true ) as $stack ) {
			foreach ( self::tests( $stack, $block_slug ) as $test ) {
				$map = self::map_of( $test );
				if ( null !== $map ) {
					$maps[ $map ] = true;
				}
			}
		}
		return array_keys( $maps );
	}

	/**
	 * A section's entries, each as the screen reads it, or null when the chronicle has no such section.
	 *
	 * @return array<string,mixed>|null
	 */
	public static function entries( object $game, string $block_slug, string $stack_slug ): ?array {
		$slug  = (string) $game->slug;
		$block = Schema_Block::find_for_game( $block_slug, $slug );
		$kind  = Catalog_Entry::section_kind( $block );
		if ( null === $block || null === $kind || ! self::takes_entries( $block ) ) {
			return null;
		}

		$book    = Schema_Block::under_for_game( $block_slug, $slug );
		$book    = [] === $book ? null : json_decode( (string) wp_json_encode( $book ) );
		$held    = Catalog_Holders::for_block( $slug, $block_slug );
		$flags   = self::flags( $game, $block_slug );
		$stack   = '' === $stack_slug ? null : Creature_Stack::find_for_game( $stack_slug, $slug );
		$maps    = null === $stack ? [] : self::maps_of( $stack, $block_slug );
		$entries = [];

		if ( Catalog_Entry::ITEM === $kind ) {
			foreach ( is_array( $block->definition->items ?? null ) ? $block->definition->items : [] as $item ) {
				$entries[] = self::item_entry( $block->definition, $item, $book, $held, $flags );
			}
		} else {
			foreach ( is_array( $block->definition->powers ?? null ) ? $block->definition->powers : [] as $family ) {
				$entries[] = self::family_entry( $slug, $family, $book, $held, $flags, $maps );
			}
		}

		return [ 'block_slug' => $block_slug, 'kind' => $kind, 'entries' => $entries ];
	}

	/**
	 * Whether a section's block takes entries of the chronicle's own: a priced trait list or a power list that allows
	 * its own entries.
	 */
	private static function takes_entries( ?object $block ): bool {
		return null !== $block && null !== Catalog_Entry::section_kind( $block ) && ! empty( $block->definition->allow_custom );
	}

	/**
	 * One section as the screen's pickers need it.
	 *
	 * @param array<string,object> $blocks The creature type's blocks by slug.
	 * @return array<string,mixed>
	 */
	private static function section( object $stack, object $section, object $block, array $blocks ): array {
		$definition = $block->definition;
		$kind       = (string) Catalog_Entry::section_kind( $block );
		$ranks      = Cost_Engine::meta_ranks( $definition ) ?? [];
		$costs      = Cost_Engine::block_tier_costs( $definition );
		$out_of     = [];
		foreach ( (array) ( $definition->_meta->out_of_type ?? [] ) as $rank => $modifier ) {
			$out_of[ (string) $rank ] = (string) $modifier;
		}

		$row = [
			'block_slug'  => (string) $block->slug,
			'label'       => (string) ( $section->label ?? $block->name ),
			'kind'        => $kind,
			'priced_by'   => Catalog_Entry::ITEM === $kind ? ( Cost_Engine::rows_are_purchases( $definition ) ? 'pick' : 'dot' ) : 'rank',
			'blood_magic' => ! empty( $definition->blood_magic ),
			'traditions'  => array_values( array_map( 'strval', (array) ( $definition->traditions ?? [] ) ) ),
			'in_type'     => self::in_type( $stack, (string) $block->slug, $blocks ),
		];

		if ( Catalog_Entry::ITEM === $kind ) {
			$row['groups']      = Catalog_Entry::groups( $block );
			$row['name_ranks']  = Catalog_Entry::name_ranks( $definition );
			$row['name_form']   = [] !== $row['name_ranks'];
			return $row;
		}

		$ladder = [];
		for ( $number = 1; null !== ( $tier = Cost_Engine::tier_for_rank( $definition, $number ) ); $number++ ) {
			$ladder[] = [ 'level' => $number, 'tier' => $tier, 'cost' => (int) ( $costs[ $tier ] ?? 0 ) ];
		}
		$row['ranks']       = $ranks;
		$row['costs']       = $costs;
		$row['out_of_type'] = $out_of;
		$row['ladder']      = $ladder;
		$laddered           = array_keys( Cost_Engine::meta_ladder( $definition ) );
		$row['pick_ranks']  = array_values( array_diff( $ranks, $laddered ) );
		return $row;
	}

	/**
	 * What a section's in-type tests let an HST set on a family: the in-clan maps with their keys, the facet axes with
	 * their options, and the tests that need nothing set.
	 *
	 * @param array<string,object> $blocks
	 * @return array<string,mixed>
	 */
	private static function in_type( object $stack, string $block_slug, array $blocks ): array {
		$maps    = [];
		$facets  = [];
		$chosen  = [];
		foreach ( self::tests( $stack, $block_slug ) as $test ) {
			$kind   = (string) ( $test['kind'] ?? '' );
			$values = is_array( $test['values'] ?? null ) ? $test['values'] : [];
			if ( 'names' === $kind && null !== self::map_of( $test ) ) {
				$map    = (string) self::map_of( $test );
				[ $slug, $key ] = array_pad( explode( '.', $map, 2 ), 2, '' );
				$maps[ $map ] = [
					'map'  => $map,
					'by'   => array_values( array_map( 'strval', (array) ( $values['by'] ?? [] ) ) ),
					'keys' => isset( $blocks[ $slug ]->definition->$key ) ? array_map( 'strval', array_keys( (array) $blocks[ $slug ]->definition->$key ) ) : [],
				];
			} elseif ( 'facet' === $kind ) {
				$facet            = (string) ( $test['facet'] ?? '' );
				$facets[ $facet ] = [ 'facet' => $facet, 'options' => self::facet_options( $values, $blocks ) ];
			} elseif ( 'chosen' === $kind ) {
				$when     = is_array( $test['when'] ?? null ) ? $test['when'] : [];
				$chosen[] = [ 'field' => (string) ( $when['field'] ?? '' ), 'is' => array_values( array_map( 'strval', (array) ( $when['is'] ?? [] ) ) ) ];
			}
		}
		return [ 'maps' => array_values( $maps ), 'facets' => array_values( $facets ), 'chosen' => $chosen ];
	}

	/**
	 * The values a facet test compares a family's value with: the options of the identity field it reads, or every value
	 * a map it reads lists.
	 *
	 * @param array<string,mixed>  $values A test's `values`.
	 * @param array<string,object> $blocks
	 * @return string[]
	 */
	private static function facet_options( array $values, array $blocks ): array {
		$options = [];
		foreach ( (array) ( $values['field'] ?? [] ) as $field ) {
			[ $slug, $name ] = array_pad( explode( '.', (string) $field, 2 ), 2, '' );
			foreach ( is_array( $blocks[ $slug ]->definition->fields ?? null ) ? $blocks[ $slug ]->definition->fields : [] as $candidate ) {
				if ( (string) ( $candidate->name ?? '' ) === $name ) {
					$options = array_merge( $options, array_map( 'strval', (array) ( $candidate->options ?? [] ) ) );
				}
			}
		}
		if ( isset( $values['map'] ) ) {
			[ $slug, $key ] = array_pad( explode( '.', (string) $values['map'], 2 ), 2, '' );
			foreach ( (array) ( $blocks[ $slug ]->definition->$key ?? [] ) as $listed ) {
				$options = array_merge( $options, array_map( 'strval', (array) $listed ) );
			}
		}
		return array_values( array_unique( $options ) );
	}

	/**
	 * The in-type tests on a creature type's section for one block, each as an array.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function tests( object $stack, string $block_slug ): array {
		foreach ( is_array( $stack->stack_definition->sections ?? null ) ? $stack->stack_definition->sections : [] as $section ) {
			if ( ( $section->block_slug ?? null ) === $block_slug ) {
				$tests = json_decode( (string) wp_json_encode( $section->in_type ?? [] ), true );
				return is_array( $tests ) ? array_values( array_filter( $tests, 'is_array' ) ) : [];
			}
		}
		return [];
	}

	/**
	 * The map a `names` test reads, as "block_slug.key", or null for any other test.
	 *
	 * @param array<string,mixed> $test
	 */
	private static function map_of( array $test ): ?string {
		$map = is_array( $test['values'] ?? null ) ? ( $test['values']['map'] ?? null ) : null;
		return 'names' === ( $test['kind'] ?? '' ) && is_string( $map ) && '' !== $map ? $map : null;
	}

	/**
	 * The maps a creature type's section for one block reads.
	 *
	 * @return string[]
	 */
	private static function maps_of( object $stack, string $block_slug ): array {
		$maps = [];
		foreach ( self::tests( $stack, $block_slug ) as $test ) {
			$map = self::map_of( $test );
			if ( null !== $map ) {
				$maps[ $map ] = true;
			}
		}
		return array_keys( $maps );
	}

	/**
	 * The book corrections of one block, by the name of the entry each sits in.
	 *
	 * @return array<string,array<int,array<string,mixed>>>
	 */
	private static function flags( object $game, string $block_slug ): array {
		$by_entry = [];
		foreach ( Catalog_Corrections::for_game( $game ) as $correction ) {
			if ( 'block' !== $correction['kind'] || $block_slug !== $correction['target'] ) {
				continue;
			}
			$path  = is_array( $correction['path'] ) ? $correction['path'] : [];
			$entry = $path[1] ?? null;
			if ( ! in_array( $path[0] ?? null, [ 'items', 'powers' ], true ) || ! is_array( $entry ) || ! isset( $entry[0] ) ) {
				continue;
			}
			$by_entry[ strtolower( (string) $entry[0] ) ][] = [
				'path'      => $path,
				'removed'   => ! empty( $correction['removed'] ),
				'labels'    => $correction['labels'] ?? [],
				'was'       => $correction['was'] ?? null,
				'now'       => $correction['now'] ?? null,
				'yours'     => $correction['yours'] ?? null,
				'was_set'   => $correction['was_set'] ?? null,
				'now_set'   => $correction['now_set'] ?? null,
				'yours_set' => $correction['yours_set'] ?? null,
			];
		}
		return $by_entry;
	}

	/**
	 * Where an entry came from: `added` when the book has no entry by that name, `changed` when the chronicle's entry
	 * differs from the book's, else `book`.
	 */
	private static function origin( object $entry, ?object $book_entry ): string {
		if ( null === $book_entry ) {
			return 'added';
		}
		return Catalog_Entry::etag( $entry ) === Catalog_Entry::etag( $book_entry ) ? 'book' : 'changed';
	}

	/**
	 * @param array<string,array{characters:int,changes:int}> $held
	 * @param array<string,array<int,array<string,mixed>>>    $flags
	 * @return array<string,mixed>
	 */
	private static function item_entry( object $definition, object $item, ?object $book, array $held, array $flags ): array {
		$name      = (string) ( $item->name ?? '' );
		$book_item = null === $book ? null : self::named( is_array( $book->items ?? null ) ? $book->items : [], $name );
		$origin    = self::origin( $item, $book_item );
		$count     = Catalog_Holders::of( $held, $name );
		$parts     = Catalog_Entry::name_parts( $definition, $item );

		return [
			'name'        => $name,
			'name_pt'     => isset( $item->name_pt ) ? (string) $item->name_pt : null,
			'cost'        => isset( $item->cost ) ? (string) $item->cost : '',
			'group'       => $parts['group'],
			'tier'        => $parts['tier'],
			'base_name'   => $parts['name'],
			'description' => isset( $item->description ) ? $item->description : null,
			'origin'      => $origin,
			'flagged'     => isset( $flags[ strtolower( $name ) ] ),
			'corrections' => $flags[ strtolower( $name ) ] ?? [],
			'etag'        => Catalog_Entry::etag( $item ),
			'in_use'      => $count,
			'in_use_note' => Catalog_Holders::message( $count ),
		];
	}

	/**
	 * @param array<string,array{characters:int,changes:int}> $held
	 * @param array<string,array<int,array<string,mixed>>>    $flags
	 * @param string[]                                        $maps
	 * @return array<string,mixed>
	 */
	private static function family_entry( string $game_slug, object $family, ?object $book, array $held, array $flags, array $maps ): array {
		$name        = (string) ( $family->name ?? '' );
		$book_family = null === $book ? null : self::named( is_array( $book->powers ?? null ) ? $book->powers : [], $name );
		$count       = Catalog_Holders::of( $held, $name );

		$levels = [];
		foreach ( Power_Levels::ladder( $family ) as $level ) {
			$levels[] = [
				'level'       => (int) ( $level->level ?? 0 ),
				'tier'        => (string) ( $level->tier ?? '' ),
				'cost'        => isset( $level->cost ) ? (string) $level->cost : '',
				'power_name'  => (string) ( $level->power_name ?? '' ),
				'description' => $level->description ?? null,
			];
		}

		$picks = [];
		foreach ( (array) ( $family->elder ?? [] ) as $rank => $list ) {
			foreach ( (array) $list as $pick ) {
				$pick_name = (string) ( $pick->power_name ?? '' );
				$in_book   = null !== $book_family && null !== self::pick_named( $book_family, (string) $rank, $pick_name );
				$picks[]   = [
					'rank'        => (string) $rank,
					'power_name'  => $pick_name,
					'name_pt'     => isset( $pick->power_name_pt ) ? (string) $pick->power_name_pt : null,
					'cost'        => isset( $pick->cost ) ? (string) $pick->cost : '',
					'description' => $pick->description ?? null,
					'origin'      => $in_book ? 'book' : 'added',
					'in_use'      => Catalog_Holders::of( $held, $name, $pick_name ),
				];
			}
		}

		$in_clan = [];
		foreach ( $maps as $map ) {
			$in_clan[ $map ] = Catalog_Entry::map_keys_holding( $game_slug, $map, $name ) ?? [];
		}

		return [
			'name'            => $name,
			'name_pt'         => isset( $family->name_pt ) ? (string) $family->name_pt : null,
			'description'     => $family->description ?? null,
			'levels'          => $levels,
			'picks'           => $picks,
			'category_values' => isset( $family->category_values ) ? (object) (array) $family->category_values : new \stdClass(),
			'traditions'      => isset( $family->traditions ) ? (object) (array) $family->traditions : new \stdClass(),
			'in_clan'         => (object) $in_clan,
			'origin'          => self::origin( $family, $book_family ),
			'flagged'         => isset( $flags[ strtolower( $name ) ] ),
			'corrections'     => $flags[ strtolower( $name ) ] ?? [],
			'etag'            => Catalog_Entry::etag( $family ),
			'in_use'          => $count,
			'in_use_note'     => Catalog_Holders::message( $count ),
		];
	}

	/**
	 * An entry of a list by name, without regard to case.
	 *
	 * @param array<int,mixed> $list
	 */
	private static function named( array $list, string $name ): ?object {
		foreach ( $list as $entry ) {
			if ( is_object( $entry ) && 0 === strcasecmp( (string) ( $entry->name ?? '' ), $name ) ) {
				return $entry;
			}
		}
		return null;
	}

	private static function pick_named( object $family, string $rank, string $power_name ): ?object {
		foreach ( (array) ( ( (array) ( $family->elder ?? [] ) )[ $rank ] ?? [] ) as $pick ) {
			if ( 0 === strcasecmp( (string) ( $pick->power_name ?? '' ), $power_name ) ) {
				return $pick;
			}
		}
		return null;
	}
}
