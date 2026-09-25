<?php

namespace BeyondElysium\Services\Display;

defined( 'ABSPATH' ) || exit;

/**
 * Pure grouping/bucketing helpers for a trait_list schema block's held rows (Merits, Backgrounds, a flat Disciplines
 * list,...).
 */
class Trait_Grouping {

	/**
	 * Converts a trait_list block's raw stored `sheet_data` entries into the canonical `{name, total, note}` shape every
	 * other method in this class expects.
	 *
	 * @param mixed $data Raw `sheet_data[block_slug]` value, already array-decoded JSON.
	 * @return array<int,array{name:string,total:mixed,note:?string}>
	 */
	public static function to_traits( mixed $data ): array {
		if ( ! is_array( $data ) || ! array_is_list( $data ) ) {
			return [];
		}

		$traits = [];
		foreach ( $data as $entry ) {
			$entry = is_array( $entry ) ? $entry : [];

			$specialization = $entry['specialization'] ?? null;
			$note           = $entry['note'] ?? null;
			$has_spec       = $specialization !== null && $specialization !== '';
			$has_note       = $note !== null && $note !== '';

			if ( $has_spec && $has_note ) {
				$combined_note = $specialization . ', ' . $note;
			} elseif ( $has_spec ) {
				$combined_note = $specialization;
			} else {
				$combined_note = $note;
			}

			$traits[] = [
				'name'  => (string) ( $entry['name'] ?? '' ),
				'total' => $entry['total'] ?? $entry['count'] ?? null,
				'note'  => $combined_note,
			];
		}

		return $traits;
	}

	/**
	 * Converts a trait_list block's held entries into `{name, total, note}` rows whose total is each entry's points.
	 *
	 * @param mixed  $data       Raw `sheet_data[block_slug]` value, already array-decoded JSON.
	 * @param object $definition Decoded trait_list block definition (`items` catalog).
	 * @return array<int,array{name:string,total:mixed,note:?string}>
	 */
	public static function to_point_traits( mixed $data, object $definition ): array {
		$traits = self::to_traits( $data );
		if ( $traits === [] ) {
			return [];
		}

		$items = (array) ( $definition->items ?? [] );
		foreach ( array_values( (array) $data ) as $index => $entry ) {
			$entry                     = is_array( $entry ) ? $entry : [];
			$traits[ $index ]['total'] = self::points_for( $entry, self::find_item_by_name( $items, (string) ( $entry['name'] ?? '' ) ) );
		}
		return $traits;
	}

	/**
	 * One held entry's points: the cost the character chose, else a held count above 1, else the catalog item's fixed
	 * cost, else the held count, or null when there is none.
	 *
	 * @param array<string,mixed> $entry A held entry.
	 * @param object|null         $item  Its catalog item, when there is one.
	 */
	public static function points_for( array $entry, ?object $item ): ?int {
		if ( isset( $entry['chosen_cost'] ) && is_numeric( $entry['chosen_cost'] ) ) {
			return (int) $entry['chosen_cost'];
		}

		$count = isset( $entry['count'] ) && is_numeric( $entry['count'] ) ? (int) $entry['count'] : null;
		if ( $count !== null && $count > 1 ) {
			return $count;
		}

		$cost = trim( (string) ( $item->cost ?? '' ) );
		if ( preg_match( '/^\d+$/', $cost ) ) {
			return (int) $cost;
		}
		return $count;
	}

	/**
	 * Groups a block's held trait rows into nested group/subgroup buckets, driven by each row's matching catalog item's
	 * `group`/`subgroup` fields.
	 *
	 * @param array<int,array{name:string}> $data       Held trait rows, each with at least a `name`.
	 * @param object                        $definition Decoded trait_list block definition (`items` catalog).
	 * @return array<int,array{group:string,subgroups:array<int,array{subgroup:?string,items:array}>}>|null
	 */
	public static function group_traits_by_field( array $data, object $definition ): ?array {
		$items = (array) ( $definition->items ?? [] );

		$has_group_field = false;
		foreach ( $items as $item ) {
			if ( ! empty( $item->group ) ) {
				$has_group_field = true;
				break;
			}
		}
		if ( ! $has_group_field ) {
			return null;
		}

		$groups = [];

		foreach ( $data as $row ) {
			$catalog_item = self::find_item_by_name( $items, $row['name'] );
			$group        = (string) ( $catalog_item?->group ?? 'Other' );
			$subgroup     = (string) ( $catalog_item?->subgroup ?? '' );

			$groups[ $group ][ $subgroup ][] = $row;
		}

		ksort( $groups, SORT_STRING );

		$out = [];
		foreach ( $groups as $group => $by_subgroup ) {
			ksort( $by_subgroup, SORT_STRING );

			$subgroups = [];
			foreach ( $by_subgroup as $subgroup => $rows ) {
				// A name like "2" comes back from its array key as an integer.
				$subgroup    = (string) $subgroup;
				$subgroups[] = [
					'subgroup' => $subgroup === '' ? null : $subgroup,
					'items'    => $rows,
				];
			}

			$out[] = [
				'group'     => (string) $group,
				'subgroups' => $subgroups,
			];
		}

		return $out;
	}

	/**
	 * Resolves the effective display mode for a trait_list section: a template section's own override.
	 */
	public static function resolve_display( ?string $section_display, ?string $block_display ): string {
		return $section_display ?? $block_display ?? 'simple';
	}

	/**
	 * Resolves the display mode a whole trait_list section renders at.
	 *
	 * @param object      $definition      The block's decoded definition.
	 * @param string|null $section_display The template section's own override.
	 * @param bool|null   $show_cost       The viewer's preference; null means unset, and unset shows it.
	 */
	public static function resolve_mode( object $definition, ?string $section_display, ?bool $show_cost = null ): string {
		if ( empty( $definition->count_is_cost ) ) {
			return self::resolve_display( $section_display, $definition->display ?? null );
		}
		return false === $show_cost ? 'note_only' : 'cost_xp';
	}

	/**
	 * Groups held traits by `definition->categories`, in that array's declared order, by looking each trait's catalog
	 * entry up in `definition->items` for its `category`.
	 *
	 * @param array<int,array{name:string}> $data
	 * @param object                        $definition
	 * @return array<int,array{label:?string,traits:array}>
	 */
	public static function group_by_category( array $data, object $definition ): array {
		$categories = (array) ( $definition->categories ?? [] );
		if ( empty( $categories ) ) {
			return [ [ 'label' => null, 'traits' => $data ] ];
		}

		$items = (array) ( $definition->items ?? [] );

		$buckets = [];
		$other   = [];

		foreach ( $data as $trait ) {
			$catalog_item = self::find_item_by_name( $items, $trait['name'] );
			$category     = $catalog_item?->category ?? null;

			if ( $category !== null && $category !== '' && in_array( $category, $categories, true ) ) {
				$buckets[ $category ][] = $trait;
			} else {
				$other[] = $trait;
			}
		}

		$groups = [];
		foreach ( $categories as $category ) {
			if ( isset( $buckets[ $category ] ) ) {
				$groups[] = [ 'label' => $category, 'traits' => $buckets[ $category ] ];
			}
		}

		if ( ! empty( $other ) ) {
			// Hardcoded to match the untranslated default of __('Other', 'beyond-elysium').
			$groups[] = [ 'label' => 'Other', 'traits' => $other ];
		}

		return $groups;
	}

	/**
	 * Sorts held traits alphabetically by name when `$alphabetize` is true.
	 *
	 * @param array<int,array{name:string}> $traits
	 * @return array<int,array{name:string}>
	 */
	public static function sort_if_alphabetized( array $traits, ?bool $alphabetize = false ): array {
		if ( ! $alphabetize ) {
			return $traits;
		}

		usort( $traits, static function ( $a, $b ): int {
			return strcmp( $a['name'], $b['name'] );
		} );

		return $traits;
	}

	/**
	 * Sums a trait_list section's held entries into one total, shown after the section title.
	 *
	 * @param array<int,array{name:string,total:mixed,note:?string}> $traits Already bridged via to_traits().
	 * @return int|null
	 */
	public static function section_total( array $traits ): ?int {
		if ( empty( $traits ) ) {
			return null;
		}

		$sum = 0;
		foreach ( $traits as $trait ) {
			$total = $trait['total'] ?? null;
			if ( ! self::is_numeric_total( $total ) ) {
				return null;
			}
			$sum += Trait_Display::parse_total( $total );
		}
		return $sum;
	}

	/**
	 * The number shown after a trait_list section's title: the sum of every entry's total, or, for a block whose count is
	 * a price, how many entries are held.
	 *
	 * @param array<int,array{name:string,total:mixed,note:?string}> $traits Already bridged via to_traits().
	 * @return int|null
	 */
	public static function section_count( array $traits, bool $count_is_cost = false ): ?int {
		if ( $count_is_cost ) {
			return $traits === [] ? null : count( $traits );
		}
		return self::section_total( $traits );
	}

	/** @param mixed $total */
	private static function is_numeric_total( $total ): bool {
		if ( is_int( $total ) ) {
			return true;
		}
		if ( is_float( $total ) ) {
			return is_finite( $total );
		}
		if ( is_string( $total ) ) {
			return (bool) preg_match( '/^-?\d+$/', trim( $total ) );
		}
		return false;
	}

	/**
	 * Finds a catalog item by exact name match.
	 *
	 * @param object[] $items
	 */
	private static function find_item_by_name( array $items, string $name ): ?object {
		foreach ( $items as $item ) {
			if ( ( $item->name ?? null ) === $name ) {
				return $item;
			}
		}
		return null;
	}
}
