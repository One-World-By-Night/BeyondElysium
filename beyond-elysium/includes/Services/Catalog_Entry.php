<?php

namespace BeyondElysium\Services;

use BeyondElysium\Database\Transaction;
use BeyondElysium\Models\Schema_Block;

defined( 'ABSPATH' ) || exit;

/**
 * Adds, changes and removes entries of a chronicle's own copy of a catalog block: an item on a trait list, a family with
 * its levels and picks on a power list, or a pick under a family on a power list without a ladder. The book's block is
 * never changed.
 */
class Catalog_Entry {

	public const ADDED   = 'added';
	public const PRESENT = 'present';
	public const FAILED  = 'failed';

	public const CHANGED    = 'changed';
	public const REMOVED    = 'removed';
	public const MISSING    = 'missing';
	public const STALE      = 'stale';
	public const EXISTS     = 'exists';
	public const IN_USE     = 'in_use';
	public const BOOK_FIELD = 'book_entry_field';
	public const INVALID    = 'invalid';

	public const ITEM   = 'item';
	public const FAMILY = 'family';
	public const PICK   = 'pick';

	/**
	 * What a block takes from a Storyteller's approval: an item (a priced trait list), a family (a power list with a
	 * ladder), a pick (a power list without one), or null for any other block, an unpriced list, a Blood Magic list or
	 * one spent from a pool.
	 *
	 * @param object|null $block
	 */
	public static function kind( ?object $block ): ?string {
		if ( null !== $block && ! empty( $block->definition->blood_magic ) ) {
			return null;
		}
		return self::section_kind( $block );
	}

	/**
	 * What a block takes on the Catalog screen: as `kind()`, with a Blood Magic list taking a family of its own.
	 *
	 * @param object|null $block
	 */
	public static function section_kind( ?object $block ): ?string {
		if ( null === $block ) {
			return null;
		}
		$definition = $block->definition ?? null;
		if ( ! is_object( $definition ) || isset( $definition->_meta->spent_from ) ) {
			return null;
		}
		if ( 'trait_list' === $block->section_type ) {
			return empty( $definition->unpriced ) ? self::ITEM : null;
		}
		if ( 'tiered_power' === $block->section_type ) {
			return Cost_Engine::meta_ladder( $definition ) !== [] ? self::FAMILY : self::PICK;
		}
		return null;
	}

	/**
	 * Whether the block's catalog already holds the entry: an item by name, a family by name, a pick by its name within
	 * its family.
	 *
	 * @param object $block
	 */
	public static function holds( object $block, string $kind, string $name, string $power_name = '' ): bool {
		$definition = $block->definition ?? null;
		if ( ! is_object( $definition ) ) {
			return false;
		}
		if ( self::ITEM === $kind ) {
			return self::item_in( $definition, $name );
		}
		$family = self::family_in( $definition, $name );
		if ( self::FAMILY === $kind || null === $family ) {
			return null !== $family;
		}
		foreach ( Power_Levels::all( $family ) as $level ) {
			if ( 0 === strcasecmp( (string) ( $level->power_name ?? '' ), $power_name ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The groups a trait list files its items under, in catalog order.
	 *
	 * @param object $block
	 * @return string[]
	 */
	public static function groups( object $block ): array {
		$groups = [];
		foreach ( is_array( $block->definition->items ?? null ) ? $block->definition->items : [] as $item ) {
			$group = trim( (string) ( $item->group ?? '' ) );
			if ( '' !== $group && ! in_array( $group, $groups, true ) ) {
				$groups[] = $group;
			}
		}
		return $groups;
	}

	/**
	 * The longest group whose name an item's name starts with, followed by a colon ("Necromancy: Mortis" starts with
	 * Necromancy), without regard to case; null when none does.
	 *
	 * @param string[] $groups
	 */
	public static function suggest_group( string $name, array $groups ): ?string {
		$found = null;
		foreach ( $groups as $group ) {
			$length = strlen( $group ) + 1;
			if ( 0 === strncasecmp( $name, $group . ':', $length ) && ( null === $found || strlen( $group ) > strlen( $found ) ) ) {
				$found = $group;
			}
		}
		return $found;
	}

	/**
	 * How many levels a power list's ladder has.
	 */
	public static function rung_count( object $definition ): int {
		return count( self::rungs( $definition ) );
	}

	/**
	 * Carries out a Storyteller's tick on an approved homebrew add: the entry joins the chronicle's catalog, and the
	 * change's trait becomes the catalog entry it now is (no `custom`, no price of its own, a family's level without
	 * its power name, a pick without a level). Returns the change's data to put on the sheet, unchanged when the entry
	 * was already in the catalog or the tick no longer fits, or null when the catalog could not be written.
	 *
	 * @param string              $game_slug
	 * @param array<string,mixed> $change_data
	 * @param array<string,mixed> $tick        `group` for an item, `rank` for a pick.
	 * @return array<string,mixed>|null
	 */
	public static function add_from_change( string $game_slug, array $change_data, array $tick ): ?array {
		$slug  = (string) ( $change_data['block_slug'] ?? '' );
		$trait = is_array( $change_data['trait'] ?? null ) ? $change_data['trait'] : [];
		$name  = (string) ( $trait['name'] ?? '' );
		$block = '' !== $game_slug && '' !== $slug ? Schema_Block::find_for_game( $slug, $game_slug ) : null;
		$kind  = self::kind( $block );
		if ( null === $kind || '' === $name || empty( $trait['custom'] ) ) {
			return $change_data;
		}

		if ( self::ITEM === $kind ) {
			$outcome = self::add_item( $slug, $game_slug, $name, isset( $trait['chosen_cost'] ) ? (int) $trait['chosen_cost'] : null, (string) ( $tick['group'] ?? '' ) );
		} elseif ( self::FAMILY === $kind ) {
			$outcome = self::add_family( $slug, $game_slug, $name, (int) ( $trait['level'] ?? 0 ), (string) ( $trait['power_name'] ?? '' ) );
			unset( $trait['power_name'] );
		} else {
			$outcome = self::add_pick( $slug, $game_slug, $name, (string) ( $trait['power_name'] ?? '' ), (string) ( $tick['rank'] ?? '' ) );
			unset( $trait['level'] );
		}

		if ( self::FAILED === $outcome ) {
			return null;
		}
		if ( self::PRESENT === $outcome ) {
			return $change_data;
		}
		unset( $trait['custom'], $trait['chosen_cost'] );
		$change_data['trait'] = $trait;
		return $change_data;
	}

	/**
	 * Adds an item to a trait list, priced when `$cost` is given and filed under `$group` when one is named.
	 */
	public static function add_item( string $block_slug, string $game_slug, string $name, ?int $cost = null, string $group = '' ): string {
		$view = self::view( $block_slug, $game_slug, 'trait_list' );
		if ( null === $view ) {
			return self::FAILED;
		}
		if ( self::item_in( $view->definition, $name ) ) {
			return self::PRESENT;
		}

		$block = self::copy( $block_slug, $game_slug );
		if ( null === $block ) {
			return self::FAILED;
		}
		$definition = $block->definition;
		$items      = is_array( $definition->items ?? null ) ? $definition->items : [];
		$item       = [ 'name' => $name ];
		if ( null !== $cost ) {
			$item['cost'] = (string) $cost;
		}
		if ( '' !== $group ) {
			$item['group'] = $group;
		}
		$items[]           = (object) $item;
		$definition->items = $items;
		return self::save( $block_slug, $game_slug, $definition );
	}

	/**
	 * Adds a family to a power list with a ladder: one level for each rung, at the section's cost for that rank, with
	 * `$level` named `$power_name`. A family already there only has an unnamed `$level` named.
	 */
	public static function add_family( string $block_slug, string $game_slug, string $family, int $level, string $power_name = '' ): string {
		$view = self::view( $block_slug, $game_slug, 'tiered_power' );
		if ( null === $view || self::FAMILY !== self::kind( $view ) ) {
			return self::FAILED;
		}
		$rungs = self::rungs( $view->definition );
		if ( ! isset( $rungs[ $level ] ) ) {
			return self::FAILED;
		}

		$existing = self::family_in( $view->definition, $family );
		if ( null !== $existing && ( '' === $power_name || ! self::level_is_unnamed( $existing, $level ) ) ) {
			return self::PRESENT;
		}

		$block = self::copy( $block_slug, $game_slug );
		if ( null === $block ) {
			return self::FAILED;
		}
		$definition = $block->definition;
		$powers     = is_array( $definition->powers ?? null ) ? $definition->powers : [];
		$target     = self::family_in( $definition, $family );

		if ( null !== $target ) {
			foreach ( $target->levels as $row ) {
				if ( (int) ( $row->level ?? 0 ) === $level ) {
					$row->power_name = $power_name;
				}
			}
		} else {
			$costs  = Cost_Engine::block_tier_costs( $definition );
			$levels = [];
			foreach ( $rungs as $number => $tier ) {
				$levels[] = (object) [
					'cost'       => (string) ( $costs[ $tier ] ?? 0 ),
					'tier'       => $tier,
					'level'      => $number,
					'power_name' => $number === $level ? $power_name : '',
				];
			}
			$powers[] = (object) [
				'name'   => $family,
				'elder'  => [],
				'levels' => $levels,
			];
		}

		$definition->powers = $powers;
		return self::save( $block_slug, $game_slug, $definition );
	}

	/**
	 * Adds a family to a power list with a ladder at the first rung of `$tier` that is not yet named in that family, or
	 * is not yet there; fails when the ladder has no such open rung.
	 */
	public static function add_family_at_tier( string $block_slug, string $game_slug, string $family, string $tier, string $power_name = '' ): string {
		$view = self::view( $block_slug, $game_slug, 'tiered_power' );
		if ( null === $view || self::FAMILY !== self::kind( $view ) ) {
			return self::FAILED;
		}
		$existing = self::family_in( $view->definition, $family );
		foreach ( self::rungs( $view->definition ) as $number => $rung_tier ) {
			if ( $rung_tier === $tier && ( null === $existing || self::level_is_unnamed( $existing, $number ) ) ) {
				return self::add_family( $block_slug, $game_slug, $family, $number, $power_name );
			}
		}
		return self::FAILED;
	}

	/**
	 * Adds a pick to a power list without a ladder, under `$family` (created when it is new) at `$rank`, at the
	 * section's cost for that rank.
	 */
	public static function add_pick( string $block_slug, string $game_slug, string $family, string $power_name, string $rank ): string {
		$view = self::view( $block_slug, $game_slug, 'tiered_power' );
		if ( null === $view || self::PICK !== self::kind( $view ) ) {
			return self::FAILED;
		}
		$ranks = Cost_Engine::meta_ranks( $view->definition );
		if ( null !== $ranks && ! in_array( $rank, $ranks, true ) ) {
			return self::FAILED;
		}

		$existing = self::family_in( $view->definition, $family );
		if ( null !== $existing ) {
			foreach ( Power_Levels::all( $existing ) as $level ) {
				if ( 0 === strcasecmp( (string) ( $level->power_name ?? '' ), $power_name ) ) {
					return self::PRESENT;
				}
			}
		}

		$block = self::copy( $block_slug, $game_slug );
		if ( null === $block ) {
			return self::FAILED;
		}
		$definition = $block->definition;
		$powers     = is_array( $definition->powers ?? null ) ? $definition->powers : [];
		$target     = self::family_in( $definition, $family );
		if ( null === $target ) {
			$target   = (object) [
				'name'   => $family,
				'elder'  => [],
				'levels' => [],
			];
			$powers[] = $target;
		}

		$costs  = Cost_Engine::block_tier_costs( $definition );
		$elder  = (object) (array) ( $target->elder ?? [] );
		$picks  = is_array( $elder->$rank ?? null ) ? $elder->$rank : [];
		$picks[] = (object) [
			'cost'       => (string) ( $costs[ $rank ] ?? 0 ),
			'tier'       => $rank,
			'level'      => null,
			'source'     => $family,
			'power_name' => $power_name,
		];
		$elder->$rank  = $picks;
		$target->elder = $elder;

		$definition->powers = $powers;
		return self::save( $block_slug, $game_slug, $definition );
	}

	/**
	 * The hash of an entry as stored, which a save sends back so that a save over a newer version is refused.
	 *
	 * @param object|array<string,mixed> $entry
	 */
	public static function etag( $entry ): string {
		$plain = json_decode( (string) wp_json_encode( $entry ) );
		return md5( (string) wp_json_encode( is_object( $plain ) ? Catalog_Translator::strip( $plain ) : $plain ) );
	}

	/**
	 * A price in the catalog's own grammar, tidied: a whole number, a range ("1-3") or a choice ("1 or 3"), each number
	 * from 0 to the most a price may be. Null for anything else.
	 */
	public static function normalize_price( string $price ): ?string {
		$price = trim( $price );
		if ( preg_match( '/^\d+$/', $price ) ) {
			$numbers = [ (int) $price ];
			$tidy    = (string) (int) $price;
		} elseif ( preg_match( '/^\d+(?:\s+or\s+\d+)+$/i', $price ) ) {
			$numbers = array_map( 'intval', preg_split( '/\s+or\s+/i', $price ) ?: [] );
			$tidy    = implode( ' or ', $numbers );
		} elseif ( preg_match( '/^(\d+)\s*-\s*(\d+)$/', $price, $m ) && (int) $m[1] <= (int) $m[2] ) {
			$numbers = [ (int) $m[1], (int) $m[2] ];
			$tidy    = $numbers[0] . '-' . $numbers[1];
		} else {
			return null;
		}
		foreach ( $numbers as $number ) {
			if ( $number > Cost_Engine::MAX_CUSTOM_PRICE ) {
				return null;
			}
		}
		return $tidy;
	}

	/**
	 * What a trait list's `name_canonicalization` makes of the parts of a name, the form "Group: Name (rank)", or the
	 * name alone on a list that declares no form.
	 */
	public static function compose_name( object $definition, string $base, string $group, string $tier ): string {
		if ( 'group_name_tier' !== ( $definition->name_canonicalization->form ?? null ) ) {
			return $base;
		}
		return ( '' !== $group ? $group . ': ' : '' ) . $base . ( '' !== $tier ? ' (' . $tier . ')' : '' );
	}

	/**
	 * The ranks a list with a name form lets an entry carry, from the lowest price up, as they read in a name.
	 *
	 * @return string[]
	 */
	public static function name_ranks( object $definition ): array {
		if ( 'group_name_tier' !== ( $definition->name_canonicalization->form ?? null ) ) {
			return [];
		}
		$ranks = [];
		foreach ( (array) ( $definition->name_canonicalization->tier_words ?? [] ) as $word ) {
			$ranks[ strtolower( (string) $word ) ] = true;
		}
		$known = array_keys( Cost_Engine::TIER_COSTS );
		$names = array_keys( $ranks );
		$place = static fn( string $name ): int => false === ( $at = array_search( $name, $known, true ) ) ? PHP_INT_MAX : (int) $at;
		usort( $names, static fn( $a, $b ) => $place( $a ) <=> $place( $b ) );
		return $names;
	}

	/**
	 * The group, name and rank an item on a list with a name form was stored as.
	 *
	 * @return array{group:string,name:string,tier:string}
	 */
	public static function name_parts( object $definition, object $item ): array {
		$name = (string) ( $item->name ?? '' );
		if ( 'group_name_tier' === ( $definition->name_canonicalization->form ?? null ) && preg_match( '/^\s*([^:]+?)\s*:\s*(.+?)\s*\(\s*([^()]+?)\s*\)\s*$/u', $name, $m ) ) {
			return [ 'group' => $m[1], 'name' => $m[2], 'tier' => strtolower( $m[3] ) ];
		}
		return [ 'group' => (string) ( $item->group ?? '' ), 'name' => $name, 'tier' => strtolower( (string) ( $item->tier ?? '' ) ) ];
	}

	/**
	 * Adds or changes one item on a trait list in the chronicle's own copy. `$name` is the stored name of the item being
	 * changed, null for a new one; `$fields` holds only what changes: `name`, `group`, `tier`, `cost`, `description`. A
	 * book item takes only `cost` and `description`. Saving over a newer version, a name already there, a rename of an
	 * item characters hold and a price outside the catalog's grammar are refused.
	 *
	 * @param array<string,mixed> $fields
	 * @return array{status:string,detail?:mixed,entry?:object}
	 */
	public static function save_item( string $block_slug, string $game_slug, ?string $name, array $fields, ?string $etag ): array {
		$view = self::view( $block_slug, $game_slug, 'trait_list' );
		if ( null === $view || self::ITEM !== self::section_kind( $view ) ) {
			return self::outcome( self::FAILED );
		}

		$current = null === $name ? null : self::item_named( $view->definition, $name );
		if ( null !== $name && null === $current ) {
			return self::outcome( self::MISSING );
		}
		if ( null !== $current && $etag !== self::etag( $current ) ) {
			return self::outcome( self::STALE );
		}

		$book      = self::book_definition( $block_slug, $game_slug );
		$book_item = null !== $current && null !== $book ? self::item_named( $book, (string) $current->name ) : null;
		if ( null !== $book_item ) {
			foreach ( [ 'name', 'group', 'tier' ] as $locked ) {
				if ( array_key_exists( $locked, $fields ) ) {
					return self::outcome( self::BOOK_FIELD, $locked );
				}
			}
		}

		$price = null;
		if ( array_key_exists( 'cost', $fields ) || null === $current ) {
			$price = self::normalize_price( (string) ( $fields['cost'] ?? '' ) );
			if ( null === $price ) {
				return self::outcome( self::INVALID, 'invalid_price' );
			}
		}

		$parts = null === $current
			? [ 'group' => '', 'name' => '', 'tier' => '' ]
			: self::name_parts( $view->definition, $current );
		foreach ( [ 'name', 'group', 'tier' ] as $part ) {
			if ( array_key_exists( $part, $fields ) ) {
				$parts[ $part ] = trim( (string) $fields[ $part ] );
			}
		}
		if ( '' === $parts['name'] ) {
			return self::outcome( self::INVALID, 'name_required' );
		}
		$ranks = self::name_ranks( $view->definition );
		if ( [] !== $ranks && '' !== $parts['tier'] && ! in_array( $parts['tier'], $ranks, true ) ) {
			return self::outcome( self::INVALID, 'invalid_rank' );
		}

		$renaming = null === $current || array_intersect_key( $fields, [ 'name' => 1, 'group' => 1, 'tier' => 1 ] ) !== [];
		$final    = $renaming ? self::compose_name( $view->definition, $parts['name'], $parts['group'], $parts['tier'] ) : (string) $current->name;
		if ( null === $current || 0 !== strcasecmp( $final, (string) $current->name ) ) {
			$other = self::item_named( $view->definition, $final );
			if ( null !== $other && $other !== $current ) {
				return self::outcome( self::EXISTS );
			}
		}
		if ( null !== $current && $final !== (string) $current->name ) {
			$held = Catalog_Holders::of( Catalog_Holders::for_block( $game_slug, $block_slug ), (string) $current->name );
			if ( null !== Catalog_Holders::message( $held ) ) {
				return self::outcome( self::IN_USE, $held );
			}
		}

		$block = self::copy( $block_slug, $game_slug );
		if ( null === $block ) {
			return self::outcome( self::FAILED );
		}
		$definition = $block->definition;
		$items      = is_array( $definition->items ?? null ) ? $definition->items : [];
		$target     = null === $current ? new \stdClass() : null;
		foreach ( $items as $item ) {
			if ( null !== $current && 0 === strcasecmp( (string) ( $item->name ?? '' ), (string) $current->name ) ) {
				$target = $item;
			}
		}
		if ( null === $target ) {
			return self::outcome( self::MISSING );
		}

		$target->name = $final;
		if ( null !== $price ) {
			$target->cost = $price;
		}
		if ( null === $book_item ) {
			foreach ( [ 'group' => $parts['group'], 'tier' => $parts['tier'] ] as $key => $value ) {
				if ( '' === $value ) {
					unset( $target->$key );
				} else {
					$target->$key = $value;
				}
			}
		}
		if ( array_key_exists( 'description', $fields ) ) {
			self::put_description( $target, $fields['description'] );
		}
		if ( null === $current ) {
			$items[] = $target;
		}
		$definition->items = $items;

		return self::finish( $block_slug, $game_slug, $definition, $target, null === $current ? self::ADDED : self::CHANGED );
	}

	/**
	 * Adds or changes one family on a power list in the chronicle's own copy. `$name` is the stored name of the family
	 * being changed, null for a new one; `$fields` holds only what changes: `name`, `description`, `levels` (each with
	 * `level`, `power_name`, `description`), `picks` (a list of picks by rank, each with `power_name`, `cost`,
	 * `description`) and `traditions` on a Blood Magic list. A book family takes only descriptions and its picks'
	 * prices; its names, levels and picks stay. A rename, or a pick taken away, that characters hold is refused.
	 *
	 * @param array<string,mixed> $fields
	 * @return array{status:string,detail?:mixed,entry?:object}
	 */
	public static function save_family( string $block_slug, string $game_slug, ?string $name, array $fields, ?string $etag ): array {
		$view = self::view( $block_slug, $game_slug, 'tiered_power' );
		$kind = null === $view ? null : self::section_kind( $view );
		if ( null === $view || ( self::FAMILY !== $kind && self::PICK !== $kind ) ) {
			return self::outcome( self::FAILED );
		}

		$current = null === $name ? null : self::family_in( $view->definition, $name );
		if ( null !== $name && null === $current ) {
			return self::outcome( self::MISSING );
		}
		if ( null !== $current && $etag !== self::etag( $current ) ) {
			return self::outcome( self::STALE );
		}

		$book        = self::book_definition( $block_slug, $game_slug );
		$book_family = null !== $current && null !== $book ? self::family_in( $book, (string) $current->name ) : null;
		$is_book     = null !== $book_family;

		$final = null === $current ? '' : (string) $current->name;
		if ( array_key_exists( 'name', $fields ) ) {
			$final = trim( (string) $fields['name'] );
			if ( null !== $current && $is_book && $final !== (string) $current->name ) {
				return self::outcome( self::BOOK_FIELD, 'name' );
			}
		}
		if ( '' === $final ) {
			return self::outcome( self::INVALID, 'name_required' );
		}
		if ( null === $current || 0 !== strcasecmp( $final, (string) $current->name ) ) {
			$other = self::family_in( $view->definition, $final );
			if ( null !== $other && $other !== $current ) {
				return self::outcome( self::EXISTS );
			}
		}

		$held = Catalog_Holders::for_block( $game_slug, $block_slug );
		if ( null !== $current && $final !== (string) $current->name && null !== Catalog_Holders::message( Catalog_Holders::of( $held, (string) $current->name ) ) ) {
			return self::outcome( self::IN_USE, Catalog_Holders::of( $held, (string) $current->name ) );
		}

		$block = self::copy( $block_slug, $game_slug );
		if ( null === $block ) {
			return self::outcome( self::FAILED );
		}
		$definition = $block->definition;
		$powers     = is_array( $definition->powers ?? null ) ? $definition->powers : [];
		$target     = null === $current ? self::new_family( $definition, $final ) : self::family_in( $definition, (string) $current->name );
		if ( null === $target ) {
			return self::outcome( self::MISSING );
		}
		$target->name = $final;

		if ( array_key_exists( 'description', $fields ) ) {
			self::put_description( $target, $fields['description'] );
		}

		if ( array_key_exists( 'levels', $fields ) ) {
			$refusal = self::apply_levels( $target, $book_family, $definition, $fields['levels'] );
			if ( null !== $refusal ) {
				return $refusal;
			}
		}

		if ( array_key_exists( 'picks', $fields ) ) {
			$refusal = self::apply_picks( $target, $book_family, $definition, $fields['picks'], $held );
			if ( null !== $refusal ) {
				return $refusal;
			}
		}

		if ( array_key_exists( 'traditions', $fields ) ) {
			if ( $is_book || empty( $definition->blood_magic ) ) {
				return self::outcome( self::BOOK_FIELD, 'traditions' );
			}
			$refusal = self::apply_traditions( $target, $definition, $fields['traditions'] );
			if ( null !== $refusal ) {
				return $refusal;
			}
		}

		if ( ! self::names_are_unique( $target ) ) {
			return self::outcome( self::EXISTS, 'power_name' );
		}

		if ( null === $current ) {
			$powers[] = $target;
		}
		$definition->powers = $powers;

		if ( null !== $current && $final !== (string) $current->name ) {
			self::rename_in_maps( $game_slug, $block_slug, (string) $current->name, $final );
		}

		return self::finish( $block_slug, $game_slug, $definition, $target, null === $current ? self::ADDED : self::CHANGED );
	}

	/**
	 * Takes one entry the chronicle added out of its own copy of the block: an item, or a family with its picks and its
	 * place in every in-clan list. A book entry stays (an approval rule is what disallows one), and an entry characters
	 * hold or a waiting change names stays too.
	 *
	 * @return array{status:string,detail?:mixed,entry?:object}
	 */
	public static function remove_entry( string $block_slug, string $game_slug, string $name, ?string $etag ): array {
		$view = self::view( $block_slug, $game_slug, '' );
		$kind = null === $view ? null : self::section_kind( $view );
		if ( null === $view || null === $kind ) {
			return self::outcome( self::FAILED );
		}
		$is_item = self::ITEM === $kind;
		$current = $is_item ? self::item_named( $view->definition, $name ) : self::family_in( $view->definition, $name );
		if ( null === $current ) {
			return self::outcome( self::MISSING );
		}
		if ( $etag !== self::etag( $current ) ) {
			return self::outcome( self::STALE );
		}

		$book = self::book_definition( $block_slug, $game_slug );
		$in_book = null !== $book && ( $is_item ? null !== self::item_named( $book, $name ) : null !== self::family_in( $book, $name ) );
		if ( $in_book ) {
			return self::outcome( self::BOOK_FIELD, 'remove' );
		}

		$held = Catalog_Holders::of( Catalog_Holders::for_block( $game_slug, $block_slug ), $name );
		if ( null !== Catalog_Holders::message( $held ) ) {
			return self::outcome( self::IN_USE, $held );
		}

		$block = self::copy( $block_slug, $game_slug );
		if ( null === $block ) {
			return self::outcome( self::FAILED );
		}
		$definition = $block->definition;
		$list       = $is_item ? 'items' : 'powers';
		$kept       = [];
		foreach ( is_array( $definition->$list ?? null ) ? $definition->$list : [] as $entry ) {
			if ( 0 !== strcasecmp( (string) ( $entry->name ?? '' ), $name ) ) {
				$kept[] = $entry;
			}
		}
		$definition->$list = $kept;

		if ( ! $is_item ) {
			self::rename_in_maps( $game_slug, $block_slug, $name, null );
		}

		return self::finish( $block_slug, $game_slug, $definition, $current, self::REMOVED );
	}

	/**
	 * Puts a book entry's price and description back as the book has them, dropping the chronicle's change at those
	 * places. A family gets its own description, its levels' descriptions and its book picks' prices and descriptions
	 * back; what the chronicle added stays.
	 *
	 * @return array{status:string,detail?:mixed,entry?:object}
	 */
	public static function revert_entry( string $block_slug, string $game_slug, string $name ): array {
		$view = self::view( $block_slug, $game_slug, '' );
		$kind = null === $view ? null : self::section_kind( $view );
		$book = self::book_definition( $block_slug, $game_slug );
		if ( null === $view || null === $kind || null === $book ) {
			return self::outcome( self::FAILED );
		}
		$is_item   = self::ITEM === $kind;
		$book_side = $is_item ? self::item_named( $book, $name ) : self::family_in( $book, $name );
		if ( null === $book_side ) {
			return self::outcome( self::BOOK_FIELD, 'revert' );
		}

		$block = self::copy( $block_slug, $game_slug );
		if ( null === $block ) {
			return self::outcome( self::FAILED );
		}
		$definition = $block->definition;
		$target     = $is_item ? self::item_named( $definition, $name ) : self::family_in( $definition, $name );
		if ( null === $target ) {
			return self::outcome( self::MISSING );
		}

		self::copy_field( $book_side, $target, 'description' );
		if ( $is_item ) {
			self::copy_field( $book_side, $target, 'cost' );
		} else {
			foreach ( Power_Levels::all( $target ) as $level ) {
				$original = self::level_of( $book_side, $level );
				if ( null !== $original ) {
					self::copy_field( $original, $level, 'description' );
					self::copy_field( $original, $level, 'cost' );
				}
			}
		}

		return self::finish( $block_slug, $game_slug, $definition, $target, self::CHANGED );
	}

	/**
	 * Files an own family under in-clan keys and facet values. `$in_clan` lists, by the map a test reads
	 * ("vampire-identity.clan_disciplines"), the keys that hold the family; `$categories` gives the family's value for
	 * each facet axis a test compares. Only a family the chronicle added takes either.
	 *
	 * @param array<string,string[]>|null $in_clan
	 * @param array<string,string>|null   $categories
	 * @return array{status:string,detail?:mixed,entry?:object}
	 */
	public static function set_in_type( string $block_slug, string $game_slug, string $name, ?array $in_clan, ?array $categories, ?string $etag ): array {
		$view = self::view( $block_slug, $game_slug, 'tiered_power' );
		if ( null === $view ) {
			return self::outcome( self::FAILED );
		}
		$current = self::family_in( $view->definition, $name );
		if ( null === $current ) {
			return self::outcome( self::MISSING );
		}
		if ( $etag !== self::etag( $current ) ) {
			return self::outcome( self::STALE );
		}
		$book = self::book_definition( $block_slug, $game_slug );
		if ( null !== $book && null !== self::family_in( $book, $name ) ) {
			return self::outcome( self::BOOK_FIELD, 'in_type' );
		}

		$unit = Transaction::begin( 'be_catalog_in_type' );
		foreach ( $in_clan ?? [] as $map => $keys ) {
			$written = self::write_map( $game_slug, (string) $map, (string) $current->name, array_map( 'strval', $keys ) );
			if ( null === $written || self::FAILED === $written ) {
				Transaction::rollback( $unit );
				return self::outcome( null === $written ? self::INVALID : self::FAILED, null === $written ? 'invalid_in_clan' : null );
			}
		}

		$block = self::copy( $block_slug, $game_slug );
		$target = null === $block ? null : self::family_in( $block->definition, $name );
		if ( null === $block || null === $target ) {
			Transaction::rollback( $unit );
			return self::outcome( null === $block ? self::FAILED : self::MISSING );
		}
		$definition = $block->definition;
		if ( null !== $categories ) {
			$values = is_object( $target->category_values ?? null ) ? $target->category_values : new \stdClass();
			foreach ( $categories as $axis => $value ) {
				if ( '' === trim( (string) $value ) ) {
					unset( $values->$axis );
				} else {
					$values->$axis = trim( (string) $value );
				}
			}
			if ( [] === (array) $values ) {
				unset( $target->category_values );
			} else {
				$target->category_values = $values;
			}
		}

		$saved = self::finish( $block_slug, $game_slug, $definition, $target, self::CHANGED );
		if ( self::CHANGED === $saved['status'] ) {
			Transaction::commit( $unit );
		} else {
			Transaction::rollback( $unit );
		}
		return $saved;
	}

	/**
	 * The keys of an in-clan map that hold a family, read from the chronicle's view of the identity block the map sits
	 * in ("vampire-identity.clan_disciplines").
	 *
	 * @return string[]|null Null when the map is not there.
	 */
	public static function map_keys_holding( string $game_slug, string $map, string $family ): ?array {
		[ $slug, $key ] = array_pad( explode( '.', $map, 2 ), 2, '' );
		$block = '' === $slug || '' === $key ? null : Schema_Block::find_for_game( $slug, $game_slug );
		$value = $block && is_object( $block->definition ?? null ) ? ( $block->definition->$key ?? null ) : null;
		if ( ! is_object( $value ) && ! is_array( $value ) ) {
			return null;
		}
		$held = [];
		foreach ( (array) $value as $each => $names ) {
			foreach ( (array) $names as $listed ) {
				if ( 0 === strcasecmp( (string) $listed, $family ) ) {
					$held[] = (string) $each;
				}
			}
		}
		return $held;
	}

	/**
	 * Every key of an in-clan map.
	 *
	 * @return string[]
	 */
	public static function map_keys( string $game_slug, string $map ): array {
		[ $slug, $key ] = array_pad( explode( '.', $map, 2 ), 2, '' );
		$block = '' === $slug || '' === $key ? null : Schema_Block::find_for_game( $slug, $game_slug );
		$value = $block && is_object( $block->definition ?? null ) ? ( $block->definition->$key ?? null ) : null;
		return is_object( $value ) || is_array( $value ) ? array_map( 'strval', array_keys( (array) $value ) ) : [];
	}

	/**
	 * Makes a family listed under exactly the given keys of an in-clan map, in the chronicle's own copy of the identity
	 * block. Null when the map or a key is not there.
	 *
	 * @param string[] $keys
	 */
	private static function write_map( string $game_slug, string $map, string $family, array $keys ): ?string {
		[ $slug, $key ] = array_pad( explode( '.', $map, 2 ), 2, '' );
		$known = self::map_keys( $game_slug, $map );
		if ( '' === $slug || '' === $key || array_diff( $keys, $known ) !== [] ) {
			return null;
		}
		$block = self::copy( $slug, $game_slug );
		if ( null === $block || ! is_object( $block->definition->$key ?? null ) ) {
			return null;
		}
		$changed = false;
		foreach ( $block->definition->$key as $each => $names ) {
			$names = array_values( (array) $names );
			$has   = false;
			foreach ( $names as $listed ) {
				$has = $has || 0 === strcasecmp( (string) $listed, $family );
			}
			if ( in_array( (string) $each, $keys, true ) && ! $has ) {
				$names[] = $family;
				$changed = true;
			} elseif ( ! in_array( (string) $each, $keys, true ) && $has ) {
				$names   = array_values( array_filter( $names, static fn( $listed ) => 0 !== strcasecmp( (string) $listed, $family ) ) );
				$changed = true;
			}
			$block->definition->$key->$each = $names;
		}
		return $changed ? self::save( $slug, $game_slug, $block->definition ) : self::PRESENT;
	}

	/**
	 * Renames a family in every in-clan map the sections of the block's creature types read, or takes it out of them
	 * when `$to` is null.
	 */
	private static function rename_in_maps( string $game_slug, string $block_slug, string $from, ?string $to ): void {
		foreach ( Catalog_Screen::maps_for_block( $game_slug, $block_slug ) as $map ) {
			$keys = self::map_keys_holding( $game_slug, $map, $from );
			if ( null === $keys || [] === $keys ) {
				continue;
			}
			[ $slug, $key ] = array_pad( explode( '.', $map, 2 ), 2, '' );
			$block = self::copy( $slug, $game_slug );
			if ( null === $block || ! is_object( $block->definition->$key ?? null ) ) {
				continue;
			}
			foreach ( $block->definition->$key as $each => $names ) {
				$next = [];
				foreach ( (array) $names as $listed ) {
					if ( 0 === strcasecmp( (string) $listed, $from ) ) {
						if ( null !== $to ) {
							$next[] = $to;
						}
					} else {
						$next[] = $listed;
					}
				}
				$block->definition->$key->$each = $next;
			}
			self::save( $slug, $game_slug, $block->definition );
		}
	}

	/**
	 * A new family on a power list: every rung of the ladder unnamed at the section's cost for its rank, no picks.
	 */
	private static function new_family( object $definition, string $name ): object {
		$costs  = Cost_Engine::block_tier_costs( $definition );
		$levels = [];
		foreach ( self::rungs( $definition ) as $number => $tier ) {
			$levels[] = (object) [
				'cost'       => (string) ( $costs[ $tier ] ?? 0 ),
				'tier'       => $tier,
				'level'      => $number,
				'power_name' => '',
			];
		}
		return (object) [ 'name' => $name, 'elder' => [], 'levels' => $levels ];
	}

	/**
	 * Names and describes a family's ladder levels. A book family's names stay; the rungs are the section's.
	 *
	 * @param mixed $levels
	 * @return array{status:string,detail?:mixed}|null A refusal, or null when applied.
	 */
	private static function apply_levels( object $target, ?object $book_family, object $definition, $levels ): ?array {
		if ( ! is_array( $levels ) ) {
			return self::outcome( self::INVALID, 'invalid_levels' );
		}
		foreach ( $levels as $given ) {
			$given = (object) (array) $given;
			$row   = null;
			foreach ( is_array( $target->levels ?? null ) ? $target->levels : [] as $level ) {
				if ( (int) ( $level->level ?? 0 ) === (int) ( $given->level ?? -1 ) ) {
					$row = $level;
				}
			}
			if ( null === $row ) {
				return self::outcome( self::INVALID, 'invalid_level' );
			}
			if ( property_exists( $given, 'power_name' ) ) {
				$named = trim( (string) $given->power_name );
				if ( null !== $book_family && $named !== (string) ( $row->power_name ?? '' ) ) {
					return self::outcome( self::BOOK_FIELD, 'power_name' );
				}
				$row->power_name = $named;
			}
			if ( property_exists( $given, 'description' ) ) {
				self::put_description( $row, $given->description );
			}
		}
		return null;
	}

	/**
	 * Replaces a family's picks, by rank. A book pick keeps its name and rank and takes a new price and description; a
	 * pick the chronicle added can be changed or taken away unless characters hold it.
	 *
	 * @param mixed                                           $picks A list by rank: each entry has `rank`, `power_name`, `cost`, `description`.
	 * @param array<string,array{characters:int,changes:int}> $held
	 * @return array{status:string,detail?:mixed}|null
	 */
	private static function apply_picks( object $target, ?object $book_family, object $definition, $picks, array $held ): ?array {
		if ( ! is_array( $picks ) ) {
			return self::outcome( self::INVALID, 'invalid_picks' );
		}
		$ranks  = Cost_Engine::meta_ranks( $definition ) ?? [];
		$ladder = array_keys( Cost_Engine::meta_ladder( $definition ) );
		$costs  = Cost_Engine::block_tier_costs( $definition );
		$family = (string) $target->name;

		$before = [];
		foreach ( (array) ( $target->elder ?? [] ) as $rank => $list ) {
			foreach ( (array) $list as $pick ) {
				$before[ strtolower( (string) $pick->power_name ) ] = [ 'rank' => (string) $rank, 'pick' => $pick ];
			}
		}

		$after  = [];
		$seen   = [];
		foreach ( $picks as $given ) {
			$given = (object) (array) $given;
			$rank  = trim( (string) ( $given->rank ?? '' ) );
			$named = trim( (string) ( $given->power_name ?? '' ) );
			if ( '' === $named || ( [] !== $ranks && ! in_array( $rank, $ranks, true ) ) || in_array( $rank, $ladder, true ) ) {
				return self::outcome( self::INVALID, 'invalid_pick' );
			}
			$key = strtolower( $named );
			if ( isset( $seen[ $key ] ) ) {
				return self::outcome( self::EXISTS, 'power_name' );
			}
			$seen[ $key ] = true;

			$price = trim( (string) ( $given->cost ?? '' ) );
			$price = '' === $price ? (string) ( $costs[ $rank ] ?? 0 ) : $price;
			$price = self::normalize_price( $price );
			if ( null === $price ) {
				return self::outcome( self::INVALID, 'invalid_price' );
			}

			$existing = $before[ $key ] ?? null;
			if ( null !== $existing && $existing['rank'] !== $rank ) {
				$existing = null;
			}
			$pick = null !== $existing ? $existing['pick'] : (object) [ 'cost' => $price, 'tier' => $rank, 'level' => null, 'source' => $family, 'power_name' => $named ];
			if ( null !== $existing && null !== $book_family && self::book_pick( $book_family, $rank, (string) $pick->power_name ) !== null ) {
				$pick->power_name = (string) $existing['pick']->power_name;
			} else {
				$pick->power_name = $named;
			}
			$pick->cost = $price;
			if ( property_exists( $given, 'description' ) ) {
				self::put_description( $pick, $given->description );
			}
			$after[ $rank ][] = $pick;
		}

		foreach ( $before as $key => $was ) {
			$kept = false;
			foreach ( $after[ $was['rank'] ] ?? [] as $pick ) {
				$kept = $kept || strtolower( (string) $pick->power_name ) === $key;
			}
			if ( $kept ) {
				continue;
			}
			if ( null !== $book_family && null !== self::book_pick( $book_family, $was['rank'], (string) $was['pick']->power_name ) ) {
				return self::outcome( self::BOOK_FIELD, 'remove_pick' );
			}
			$count = Catalog_Holders::of( $held, $family, (string) $was['pick']->power_name );
			if ( null !== Catalog_Holders::message( $count ) ) {
				return self::outcome( self::IN_USE, $count );
			}
		}

		$elder = new \stdClass();
		foreach ( $ranks as $rank ) {
			if ( isset( $after[ $rank ] ) ) {
				$elder->$rank = $after[ $rank ];
			}
		}
		$target->elder = [] === (array) $elder ? [] : $elder;
		return null;
	}

	/**
	 * Sets a Blood Magic path's tradition names: a tradition the block lists, with the name the tradition gives the
	 * path, or null for the path's own.
	 *
	 * @param mixed $traditions
	 * @return array{status:string,detail?:mixed}|null
	 */
	private static function apply_traditions( object $target, object $definition, $traditions ): ?array {
		if ( ! is_array( $traditions ) && ! is_object( $traditions ) ) {
			return self::outcome( self::INVALID, 'invalid_traditions' );
		}
		$known = array_map( 'strval', (array) ( $definition->traditions ?? [] ) );
		$next  = new \stdClass();
		foreach ( (array) $traditions as $tradition => $alias ) {
			if ( ! in_array( (string) $tradition, $known, true ) ) {
				return self::outcome( self::INVALID, 'invalid_tradition' );
			}
			$alias = null === $alias ? '' : trim( (string) $alias );
			$next->$tradition = '' === $alias ? null : $alias;
		}
		$target->traditions = $next;
		return null;
	}

	/**
	 * Whether no name in a family, a level's or a pick's, repeats in it, without regard to case.
	 */
	private static function names_are_unique( object $family ): bool {
		$seen = [];
		foreach ( Power_Levels::all( $family ) as $level ) {
			$named = strtolower( trim( (string) ( $level->power_name ?? '' ) ) );
			if ( '' === $named ) {
				continue;
			}
			if ( isset( $seen[ $named ] ) ) {
				return false;
			}
			$seen[ $named ] = true;
		}
		return true;
	}

	/**
	 * The book's pick under a rank, by name, or null.
	 */
	private static function book_pick( object $book_family, string $rank, string $power_name ): ?object {
		foreach ( (array) ( ( (array) ( $book_family->elder ?? [] ) )[ $rank ] ?? [] ) as $pick ) {
			if ( 0 === strcasecmp( (string) ( $pick->power_name ?? '' ), $power_name ) ) {
				return $pick;
			}
		}
		return null;
	}

	/**
	 * The book family's level or pick a held-side level stands for: a ladder level by number, a pick by name.
	 */
	private static function level_of( object $book_family, object $level ): ?object {
		if ( null !== ( $level->level ?? null ) ) {
			foreach ( Power_Levels::ladder( $book_family ) as $row ) {
				if ( (int) ( $row->level ?? 0 ) === (int) $level->level ) {
					return $row;
				}
			}
			return null;
		}
		return self::book_pick( $book_family, (string) ( $level->tier ?? '' ), (string) ( $level->power_name ?? '' ) );
	}

	/**
	 * Sets or clears a `description` on an entry from the three sections the editor sends, sanitized as the book's own
	 * are.
	 *
	 * @param mixed $value
	 */
	private static function put_description( object $entry, $value ): void {
		$sections = Rich_Text_Sanitizer::sanitize_sections( $value );
		if ( [] === $sections ) {
			unset( $entry->description );
		} else {
			$entry->description = (object) $sections;
		}
	}

	/**
	 * Copies one field from one object onto another, or removes it from the second when the first lacks it.
	 */
	private static function copy_field( object $from, object $to, string $field ): void {
		if ( property_exists( $from, $field ) ) {
			$to->$field = $from->$field;
		} else {
			unset( $to->$field );
		}
	}

	/**
	 * Saves the block and answers with the entry as it now reads.
	 *
	 * @return array{status:string,detail?:mixed,entry?:object}
	 */
	private static function finish( string $block_slug, string $game_slug, object $definition, object $entry, string $status ): array {
		if ( self::FAILED === self::save( $block_slug, $game_slug, $definition ) ) {
			return self::outcome( self::FAILED );
		}
		return [ 'status' => $status, 'entry' => $entry ];
	}

	/**
	 * @param mixed $detail
	 * @return array{status:string,detail?:mixed}
	 */
	private static function outcome( string $status, $detail = null ): array {
		return null === $detail ? [ 'status' => $status ] : [ 'status' => $status, 'detail' => $detail ];
	}

	/**
	 * The book's definition of a block as the chronicle sees it beneath its own copy, or null when the block has no
	 * book entry (a chronicle's own section).
	 */
	private static function book_definition( string $block_slug, string $game_slug ): ?object {
		$under = Schema_Block::under_for_game( $block_slug, $game_slug );
		if ( [] === $under ) {
			return null;
		}
		$decoded = json_decode( (string) wp_json_encode( $under ) );
		return is_object( $decoded ) ? $decoded : null;
	}

	/**
	 * A trait list's item by name, without regard to case.
	 */
	private static function item_named( object $definition, string $name ): ?object {
		foreach ( is_array( $definition->items ?? null ) ? $definition->items : [] as $item ) {
			if ( 0 === strcasecmp( (string) ( $item->name ?? '' ), $name ) ) {
				return $item;
			}
		}
		return null;
	}

	/**
	 * The block as the chronicle sees it (its own copy, else the book's), when it is of the given section type.
	 */
	private static function view( string $block_slug, string $game_slug, string $section_type ): ?object {
		if ( '' === $game_slug ) {
			return null;
		}
		$block = Schema_Block::find_for_game( $block_slug, $game_slug );
		$fits  = $block && ( '' === $section_type ? in_array( $block->section_type, [ 'trait_list', 'tiered_power' ], true ) : $section_type === $block->section_type );
		return $fits && is_object( $block->definition ?? null ) ? $block : null;
	}

	/**
	 * The chronicle's own copy of a block, made from the book's when it has none.
	 */
	private static function copy( string $block_slug, string $game_slug ): ?object {
		$block = Schema_Block::find_or_create_fork_for_game( $block_slug, $game_slug );
		return $block && is_object( $block->definition ?? null ) ? $block : null;
	}

	private static function save( string $block_slug, string $game_slug, object $definition ): string {
		return Schema_Block::update( $block_slug, [ 'definition' => $definition ], $game_slug ) ? self::ADDED : self::FAILED;
	}

	private static function item_in( object $definition, string $name ): bool {
		foreach ( is_array( $definition->items ?? null ) ? $definition->items : [] as $item ) {
			if ( 0 === strcasecmp( (string) ( $item->name ?? '' ), $name ) ) {
				return true;
			}
		}
		return false;
	}

	private static function family_in( object $definition, string $name ): ?object {
		foreach ( is_array( $definition->powers ?? null ) ? $definition->powers : [] as $power ) {
			if ( 0 === strcasecmp( (string) ( $power->name ?? '' ), $name ) ) {
				return $power;
			}
		}
		return null;
	}

	private static function level_is_unnamed( object $family, int $level ): bool {
		foreach ( is_array( $family->levels ?? null ) ? $family->levels : [] as $row ) {
			if ( (int) ( $row->level ?? 0 ) === $level ) {
				return '' === ( $row->power_name ?? '' );
			}
		}
		return false;
	}

	/**
	 * Each rung of the section's ladder, by level number, with its tier.
	 *
	 * @return array<int,string>
	 */
	private static function rungs( object $definition ): array {
		$rungs = [];
		for ( $number = 1; null !== ( $tier = Cost_Engine::tier_for_rank( $definition, $number ) ); $number++ ) {
			$rungs[ $number ] = $tier;
		}
		return $rungs;
	}
}
