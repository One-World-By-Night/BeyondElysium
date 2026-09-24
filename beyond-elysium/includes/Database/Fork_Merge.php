<?php

namespace BeyondElysium\Database;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps a chronicle's copy of a catalog block current.
 */
class Fork_Merge {

	/**
	 * A copy nothing has changed yet.
	 */
	const NO_CHANGES = [ 'keys' => [], 'lists' => [], 'removed' => [] ];

	/**
	 * The definition lists whose entries are merged one by one.
	 */
	const LISTS = [ 'items', 'pools', 'fields', 'powers' ];

	/**
	 * Records what one save of a copy changed, on top of what was recorded before.
	 *
	 * @param array<string,mixed> $stored   The copy as it was.
	 * @param array<string,mixed> $incoming The copy as it's being saved.
	 * @param array<string,mixed> $changes  What was recorded before.
	 * @return array<string,mixed>
	 */
	public static function stamp( array $stored, array $incoming, array $changes ): array {
		return self::record( $stored, $incoming, self::normalize( $changes ), true );
	}

	/**
	 * The changes a copy made before changes were recorded, found by comparing it with its catalog block.
	 *
	 * @param array<string,mixed> $catalog The catalog definition.
	 * @param array<string,mixed> $copy    The chronicle's copy.
	 * @return array<string,mixed>
	 */
	public static function changes_against( array $catalog, array $copy ): array {
		return self::record( $catalog, $copy, self::NO_CHANGES, false );
	}

	/**
	 * Rebuilds a copy from the catalog's current definition, with the chronicle's recorded changes laid back over it.
	 *
	 * @param array<string,mixed> $catalog The catalog definition as it now stands.
	 * @param array<string,mixed> $copy    The chronicle's copy.
	 * @param array<string,mixed> $changes What the chronicle changed.
	 * @return array<string,mixed>
	 */
	public static function merge( array $catalog, array $copy, array $changes ): array {
		$changes = self::normalize( $changes );
		$result  = $catalog;

		foreach ( $changes['keys'] as $key ) {
			if ( array_key_exists( $key, $copy ) ) {
				$result[ $key ] = $copy[ $key ];
			} else {
				unset( $result[ $key ] );
			}
		}

		foreach ( self::LISTS as $list ) {
			if ( ! array_key_exists( $list, $catalog ) && ! array_key_exists( $list, $copy ) ) {
				continue;
			}
			$result[ $list ] = self::merge_entries(
				is_array( $catalog[ $list ] ?? null ) ? $catalog[ $list ] : [],
				is_array( $copy[ $list ] ?? null ) ? $copy[ $list ] : [],
				(array) ( $changes['lists'][ $list ] ?? [] ),
				(array) ( $changes['removed'][ $list ] ?? [] ),
				$list === 'powers'
			);
		}

		return $result;
	}

	/**
	 * @param array<string,mixed> $before
	 * @param array<string,mixed> $after
	 * @param array<string,mixed> $changes
	 * @return array<string,mixed>
	 */
	private static function record( array $before, array $after, array $changes, bool $removals ): array {
		foreach ( array_unique( array_merge( array_keys( $before ), array_keys( $after ) ) ) as $key ) {
			if ( in_array( $key, self::LISTS, true ) || in_array( $key, $changes['keys'], true ) || self::added_since( $before, $after, $key, $removals ) ) {
				continue;
			}
			if ( ! self::same( $before[ $key ] ?? null, $after[ $key ] ?? null ) || array_key_exists( $key, $before ) !== array_key_exists( $key, $after ) ) {
				$changes['keys'][] = (string) $key;
			}
		}

		foreach ( self::LISTS as $list ) {
			if ( ! array_key_exists( $list, $before ) && ! array_key_exists( $list, $after ) ) {
				continue;
			}
			[ $entries, $removed ] = self::record_entries(
				is_array( $before[ $list ] ?? null ) ? $before[ $list ] : [],
				is_array( $after[ $list ] ?? null ) ? $after[ $list ] : [],
				(array) ( $changes['lists'][ $list ] ?? [] ),
				(array) ( $changes['removed'][ $list ] ?? [] ),
				$list === 'powers',
				$removals
			);
			$changes['lists'][ $list ]   = $entries;
			$changes['removed'][ $list ] = $removed;
		}

		return self::tidy( $changes );
	}

	/**
	 * @param array<int,mixed>                    $before
	 * @param array<int,mixed>                    $after
	 * @param array<string,array<string,mixed>>   $entries Recorded changes by entry id.
	 * @param array<int,string>                   $removed Recorded removed ids.
	 * @return array{0:array<string,array<string,mixed>>,1:array<int,string>}
	 */
	private static function record_entries( array $before, array $after, array $entries, array $removed, bool $has_levels, bool $removals ): array {
		$before_by_id = self::by_id( $before, 'name' );
		$after_by_id  = self::by_id( $after, 'name' );

		foreach ( $after_by_id as $id => $entry ) {
			$record = self::entry_record( $entries[ $id ] ?? [] );

			if ( ! isset( $before_by_id[ $id ] ) ) {
				$record['added'] = true;
				$removed         = array_values( array_diff( $removed, [ $id ] ) );
			} elseif ( ! $record['added'] ) {
				$previous = $before_by_id[ $id ];
				foreach ( array_unique( array_merge( array_keys( $previous ), array_keys( $entry ) ) ) as $key ) {
					if ( ( $has_levels && $key === 'levels' ) || in_array( $key, $record['keys'], true ) || self::added_since( $previous, $entry, $key, $removals ) ) {
						continue;
					}
					if ( array_key_exists( $key, $previous ) !== array_key_exists( $key, $entry ) || ! self::same( $previous[ $key ] ?? null, $entry[ $key ] ?? null ) ) {
						$record['keys'][] = (string) $key;
					}
				}
				if ( $has_levels ) {
					[ $record['levels'], $record['levels_removed'] ] = self::record_levels(
						is_array( $previous['levels'] ?? null ) ? $previous['levels'] : [],
						is_array( $entry['levels'] ?? null ) ? $entry['levels'] : [],
						$record['levels'],
						$record['levels_removed'],
						$removals
					);
				}
			}

			$entries[ $id ] = $record;
		}

		foreach ( $before_by_id as $id => $entry ) {
			if ( isset( $after_by_id[ $id ] ) ) {
				continue;
			}
			$was_added = ! empty( $entries[ $id ]['added'] );
			unset( $entries[ $id ] );
			if ( $removals && ! $was_added && ! in_array( $id, $removed, true ) ) {
				$removed[] = $id;
			}
		}

		return [ $entries, $removed ];
	}

	/**
	 * @param array<int,mixed>                  $before
	 * @param array<int,mixed>                  $after
	 * @param array<string,array<string,mixed>> $levels
	 * @param array<int,string>                 $removed
	 * @return array{0:array<string,array<string,mixed>>,1:array<int,string>}
	 */
	private static function record_levels( array $before, array $after, array $levels, array $removed, bool $removals ): array {
		$before_by_id = self::by_id( $before, 'power_name' );
		$after_by_id  = self::by_id( $after, 'power_name' );

		foreach ( $after_by_id as $id => $level ) {
			$record = self::entry_record( $levels[ $id ] ?? [] );
			if ( ! isset( $before_by_id[ $id ] ) ) {
				$record['added'] = true;
				$removed         = array_values( array_diff( $removed, [ $id ] ) );
			} elseif ( ! $record['added'] ) {
				$previous = $before_by_id[ $id ];
				foreach ( array_unique( array_merge( array_keys( $previous ), array_keys( $level ) ) ) as $key ) {
					if ( in_array( $key, $record['keys'], true ) || self::added_since( $previous, $level, $key, $removals ) ) {
						continue;
					}
					if ( array_key_exists( $key, $previous ) !== array_key_exists( $key, $level ) || ! self::same( $previous[ $key ] ?? null, $level[ $key ] ?? null ) ) {
						$record['keys'][] = (string) $key;
					}
				}
			}
			$levels[ $id ] = [ 'added' => $record['added'], 'keys' => $record['keys'] ];
		}

		foreach ( $before_by_id as $id => $level ) {
			if ( isset( $after_by_id[ $id ] ) ) {
				continue;
			}
			$was_added = ! empty( $levels[ $id ]['added'] );
			unset( $levels[ $id ] );
			if ( $removals && ! $was_added && ! in_array( $id, $removed, true ) ) {
				$removed[] = $id;
			}
		}

		return [ $levels, $removed ];
	}

	/**
	 * @param array<int,mixed>                  $catalog
	 * @param array<int,mixed>                  $copy
	 * @param array<string,array<string,mixed>> $entries
	 * @param array<int,string>                 $removed
	 * @return array<int,mixed>
	 */
	private static function merge_entries( array $catalog, array $copy, array $entries, array $removed, bool $has_levels ): array {
		$copy_by_id = self::by_id( $copy, 'name' );
		$merged     = [];
		$seen       = [];

		foreach ( $catalog as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			$id          = self::entry_id( $entry, 'name' );
			$seen[ $id ] = true;
			if ( in_array( $id, $removed, true ) ) {
				continue;
			}
			if ( ! isset( $copy_by_id[ $id ] ) ) {
				$merged[] = $entry;
				continue;
			}

			$record = self::entry_record( $entries[ $id ] ?? [] );
			$own    = $copy_by_id[ $id ];
			if ( $record['added'] ) {
				$merged[] = $own;
				continue;
			}
			foreach ( $record['keys'] as $key ) {
				if ( array_key_exists( $key, $own ) ) {
					$entry[ $key ] = $own[ $key ];
				} else {
					unset( $entry[ $key ] );
				}
			}
			if ( $has_levels ) {
				$entry['levels'] = self::merge_levels(
					is_array( $entry['levels'] ?? null ) ? $entry['levels'] : [],
					is_array( $own['levels'] ?? null ) ? $own['levels'] : [],
					(array) $record['levels'],
					(array) $record['levels_removed']
				);
			}
			$merged[] = $entry;
		}

		foreach ( $copy_by_id as $id => $own ) {
			if ( isset( $seen[ $id ] ) ) {
				continue;
			}
			// Gone from the catalog: kept only when it's the chronicle's own, or the chronicle changed it.
			$record = self::entry_record( $entries[ $id ] ?? [] );
			if ( $record['added'] || $record['keys'] !== [] || $record['levels'] !== [] ) {
				$merged[] = $own;
			}
		}

		return $merged;
	}

	/**
	 * @param array<int,mixed>                  $catalog
	 * @param array<int,mixed>                  $copy
	 * @param array<string,array<string,mixed>> $levels
	 * @param array<int,string>                 $removed
	 * @return array<int,mixed>
	 */
	private static function merge_levels( array $catalog, array $copy, array $levels, array $removed ): array {
		$copy_by_id = self::by_id( $copy, 'power_name' );
		$merged     = [];
		$seen       = [];

		foreach ( $catalog as $level ) {
			if ( ! is_array( $level ) ) {
				continue;
			}
			$id          = self::entry_id( $level, 'power_name' );
			$seen[ $id ] = true;
			if ( in_array( $id, $removed, true ) ) {
				continue;
			}
			$record = self::entry_record( $levels[ $id ] ?? [] );
			if ( isset( $copy_by_id[ $id ] ) ) {
				$own = $copy_by_id[ $id ];
				if ( $record['added'] ) {
					$level = $own;
				} else {
					foreach ( $record['keys'] as $key ) {
						if ( array_key_exists( $key, $own ) ) {
							$level[ $key ] = $own[ $key ];
						} else {
							unset( $level[ $key ] );
						}
					}
				}
			}
			$merged[] = $level;
		}

		foreach ( $copy_by_id as $id => $own ) {
			$record = self::entry_record( $levels[ $id ] ?? [] );
			if ( ! isset( $seen[ $id ] ) && ( $record['added'] || $record['keys'] !== [] ) ) {
				$merged[] = $own;
			}
		}

		return $merged;
	}

	/**
	 * Whether a value the catalog has and an old copy lacks was added to the catalog since the copy was made.
	 *
	 * @param array<string,mixed> $before
	 * @param array<string,mixed> $after
	 * @param int|string          $key
	 */
	private static function added_since( array $before, array $after, $key, bool $exact ): bool {
		return ! $exact && array_key_exists( $key, $before ) && ! array_key_exists( $key, $after );
	}

	/**
	 * @param array<int,mixed> $entries
	 * @return array<string,array<string,mixed>>
	 */
	private static function by_id( array $entries, string $id_key ): array {
		$by_id = [];
		foreach ( $entries as $entry ) {
			if ( is_array( $entry ) ) {
				$by_id[ self::entry_id( $entry, $id_key ) ] = $entry;
			}
		}
		return $by_id;
	}

	/**
	 * An entry's identity within its list: its `$id_key` value, or its level number when that's empty.
	 *
	 * @param array<string,mixed> $entry
	 */
	private static function entry_id( array $entry, string $id_key ): string {
		if ( isset( $entry[ $id_key ] ) && (string) $entry[ $id_key ] !== '' ) {
			return (string) $entry[ $id_key ];
		}
		return '#' . (string) ( $entry['level'] ?? '' );
	}

	/**
	 * @param array<string,mixed> $record
	 * @return array{added:bool,keys:array<int,string>,levels:array<string,mixed>,levels_removed:array<int,string>}
	 */
	private static function entry_record( array $record ): array {
		return [
			'added'          => ! empty( $record['added'] ),
			'keys'           => array_values( array_map( 'strval', (array) ( $record['keys'] ?? [] ) ) ),
			'levels'         => (array) ( $record['levels'] ?? [] ),
			'levels_removed' => array_values( array_map( 'strval', (array) ( $record['levels_removed'] ?? [] ) ) ),
		];
	}

	/**
	 * @param array<string,mixed> $changes
	 * @return array{keys:array<int,string>,lists:array<string,mixed>,removed:array<string,mixed>}
	 */
	private static function normalize( array $changes ): array {
		return [
			'keys'    => array_values( array_map( 'strval', (array) ( $changes['keys'] ?? [] ) ) ),
			'lists'   => (array) ( $changes['lists'] ?? [] ),
			'removed' => (array) ( $changes['removed'] ?? [] ),
		];
	}

	/**
	 * Drops empty records, so a copy nothing has changed records nothing.
	 *
	 * @param array<string,mixed> $changes
	 * @return array<string,mixed>
	 */
	private static function tidy( array $changes ): array {
		foreach ( $changes['lists'] as $list => $entries ) {
			foreach ( $entries as $id => $record ) {
				$record = self::entry_record( $record );
				foreach ( $record['levels'] as $level_id => $level ) {
					if ( empty( $level['added'] ) && empty( $level['keys'] ) ) {
						unset( $record['levels'][ $level_id ] );
					}
				}
				if ( ! $record['added'] && $record['keys'] === [] && $record['levels'] === [] && $record['levels_removed'] === [] ) {
					unset( $changes['lists'][ $list ][ $id ] );
					continue;
				}
				$kept = [ 'added' => $record['added'], 'keys' => $record['keys'] ];
				if ( $record['levels'] !== [] ) {
					$kept['levels'] = $record['levels'];
				}
				if ( $record['levels_removed'] !== [] ) {
					$kept['levels_removed'] = $record['levels_removed'];
				}
				$changes['lists'][ $list ][ $id ] = $kept;
			}
			if ( $changes['lists'][ $list ] === [] ) {
				unset( $changes['lists'][ $list ] );
			}
		}
		foreach ( $changes['removed'] as $list => $ids ) {
			if ( $ids === [] ) {
				unset( $changes['removed'][ $list ] );
			}
		}
		return $changes;
	}

	/**
	 * Whether two decoded JSON values are the same, whatever order an object's keys were stored in.
	 *
	 * @param mixed $a
	 * @param mixed $b
	 */
	private static function same( $a, $b ): bool {
		return self::canonical( $a ) === self::canonical( $b );
	}

	/**
	 * @param mixed $value
	 * @return mixed
	 */
	private static function canonical( $value ) {
		if ( is_object( $value ) ) {
			$value = (array) $value;
		}
		if ( ! is_array( $value ) ) {
			return $value;
		}
		$value = array_map( [ self::class, 'canonical' ], $value );
		if ( ! array_is_list( $value ) ) {
			ksort( $value );
		}
		return $value;
	}
}
