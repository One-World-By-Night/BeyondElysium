<?php

namespace BeyondElysium\Services\Display;

defined( 'ABSPATH' ) || exit;

/**
 * Pure grouping/bucketing helpers for a trait_list schema block's held rows (Merits,
 * Backgrounds, a flat Disciplines list, ...) - an exact PHP twin of
 * `src/lib/groupTraitsByField.ts` and the pure helpers inside
 * `src/components/renderers/TraitListRenderer.tsx`, so the signed-PDF exporter groups,
 * orders, and resolves display mode identically to the on-screen character sheet
 * instead of re-deriving these rules independently. Also carries `to_traits()`, ported
 * from `src/components/renderers/BlockRenderer.tsx` - the raw-`sheet_data`-to-canonical
 * shape bridge at the center of Defect D25 (see that method's own doc comment).
 * Verified against the TypeScript originals by having both read the same JSON fixture
 * and assert the same output; see tests/unit/Display/TraitGroupingParityTest.php and
 * `groupTraitsByField.test.ts` / `TraitListRenderer.test.ts` / `BlockRenderer.test.ts`.
 *
 * Operates only on plain data passed in as arguments - no WordPress calls, no database
 * access. A held trait row - both `to_traits()`'s raw input and every other method's
 * `Trait` shape - is a plain array: `name` (string) and, once bridged, `total`
 * (int|float|string|null) and `note` (?string), matching the array convention
 * `Power_Display`'s `$held` and `Cross_Block_Ref`'s `$sheet_data` already use for
 * sheet_data-derived shapes. A trait_list block's `definition` is a decoded object,
 * the same shape `Trait_Mapper` and `Power_Display` read: `items`, a list of objects
 * each optionally carrying `group`, `subgroup`, and `category`.
 *
 * @see BE_PROCESS/signed-pdf-design.md Section 2d, Section 3, SP-3
 */
class Trait_Grouping {

	/**
	 * Converts a trait_list block's raw stored `sheet_data` entries into the canonical
	 * `{name, total, note}` shape every other method in this class expects. This bridge
	 * exists because of Defect D25: the character editor writes a trait's numeric value
	 * under the key `count`, while the display layer reads `total`, so every raw entry
	 * must cross through here rather than being read directly. Reads `total` first -
	 * an already-correct value should win - falling back to `count` only when `total`
	 * itself is absent. Also folds a separate `specialization` field into `note`
	 * (comma-joined when both are present) rather than dropping it, the same shape of
	 * silent data loss D25 was.
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
	 * Groups a block's held trait rows into nested group/subgroup buckets, driven by
	 * each row's matching catalog item's `group`/`subgroup` fields rather than a fixed
	 * category list. A row whose catalog item declares no group (or has no catalog
	 * item at all) falls back to an "Other" group. Returns null when no catalog item
	 * declares a `group` at all, signaling the caller to fall back to its own default
	 * grouping (see group_by_category()). Groups and subgroups are each sorted
	 * alphabetically in the result.
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

		// group name => subgroup name => rows. Insertion order does not matter - both
		// levels are explicitly re-sorted alphabetically below.
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
	 * Resolves the effective display mode for a trait_list section: a template
	 * section's own override, then the block's own default, then 'simple' when
	 * neither is set.
	 */
	public static function resolve_display( ?string $section_display, ?string $block_display ): string {
		return $section_display ?? $block_display ?? 'simple';
	}

	/**
	 * Groups held traits by `definition->categories`, in that array's declared order,
	 * by looking each trait's catalog entry up in `definition->items` for its
	 * `category`. Uncategorized or unrecognized-category traits land in a trailing
	 * "Other" bucket rather than vanishing. A block with no `categories` renders flat,
	 * as a single unlabeled group.
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
			// Hardcoded to match the untranslated default of __( 'Other', 'beyond-elysium' ) -
			// this class makes zero WordPress calls by design.
			$groups[] = [ 'label' => 'Other', 'traits' => $other ];
		}

		return $groups;
	}

	/**
	 * Sorts held traits alphabetically by name when `$alphabetize` is true; otherwise
	 * returns them in their original order, unchanged.
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
	 * Finds a catalog item by exact name match. Linear search rather than a
	 * name-keyed array, matching Trait_Mapper::find_by_name() - avoids PHP silently
	 * coercing a purely-numeric trait name into an integer array key.
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
