<?php

namespace BeyondElysium\Database;

defined( 'ABSPATH' ) || exit;

/**
 * A chronicle's changes over the definition beneath its copy: each change where it sits, with the value that was
 * beneath it when the chronicle made it.
 *
 * A path is a list of steps from the top of a definition. A string or integer step goes into a map by key; a one-item
 * list step, `[id]`, goes into a keyed list by an entry's identity.
 */
class Fork_Merge {

	/**
	 * A copy with no changes of its own.
	 */
	const NO_CHANGES = [ 'changes' => [] ];

	/**
	 * Lists whose entries are matched by identity, by the key the list sits under, with the entry key that names each
	 * entry.
	 */
	const KEYED_LISTS = [
		'items'    => 'name',
		'pools'    => 'name',
		'fields'   => 'name',
		'powers'   => 'name',
		'levels'   => 'power_name',
		'overflow' => 'power_name',
		'sections' => 'block_slug',
		'steps'    => 'id',
	];

	/**
	 * Maps whose every value is a keyed list, by the key the map sits under, with the entry key that names each entry.
	 */
	const KEYED_LIST_MAPS = [
		'elder' => 'power_name',
	];

	/**
	 * Stands in for a value that isn't there.
	 *
	 * @var object|null
	 */
	private static $absent = null;

	/**
	 * Whether a change record holds its changes by path.
	 *
	 * @param mixed $changes
	 */
	public static function is_by_path( $changes ): bool {
		return is_array( $changes ) && isset( $changes['changes'] ) && is_array( $changes['changes'] );
	}

	/**
	 * Records what one save of a copy changed, on top of what was recorded before. A change already recorded at or
	 * above a changed path takes the value beneath as it now stands, and a change that leaves the copy matching what is
	 * beneath is dropped.
	 *
	 * @param array<string,mixed> $stored   The copy as it was.
	 * @param array<string,mixed> $incoming The copy as it's being saved.
	 * @param array<string,mixed> $changes  What was recorded before.
	 * @param array<string,mixed> $under    The definition beneath the copy as it now stands.
	 * @return array<string,mixed>
	 */
	public static function stamp( array $stored, array $incoming, array $changes, array $under ): array {
		$records = self::records( $changes );
		$paths   = [];
		self::diff( $stored, $incoming, [], $paths, true );

		foreach ( $paths as $path ) {
			$covered = false;
			foreach ( $records as $i => $record ) {
				if ( self::is_prefix( $record['path'], $path ) ) {
					$records[ $i ] = self::record_at( $record['path'], $under );
					$covered       = true;
				}
			}
			if ( $covered ) {
				continue;
			}
			$records   = self::outside( $records, $path );
			$records[] = self::record_at( $path, $under );
		}

		return self::pack( self::effective( $records, $incoming, $under ) );
	}

	/**
	 * The changes a copy made before they were recorded, found by comparing it with the definition beneath it. What the
	 * definition beneath holds and the copy lacks counts as added beneath since, unless `$removals` says the copy removed
	 * it.
	 *
	 * @param array<string,mixed> $catalog  The definition beneath the copy.
	 * @param array<string,mixed> $copy     The chronicle's copy.
	 * @param bool                $removals Whether what the copy lacks was removed by the chronicle.
	 * @return array<string,mixed>
	 */
	public static function changes_against( array $catalog, array $copy, bool $removals = false ): array {
		$paths = [];
		self::diff( $catalog, $copy, [], $paths, $removals );

		$records = [];
		foreach ( $paths as $path ) {
			$records[] = self::record_at( $path, $catalog );
		}
		return self::pack( $records );
	}

	/**
	 * Rebuilds a copy from the definition beneath it as it now stands, with the chronicle's recorded changes laid back
	 * over it.
	 *
	 * @param array<string,mixed> $catalog The definition beneath the copy as it now stands.
	 * @param array<string,mixed> $copy    The chronicle's copy.
	 * @param array<string,mixed> $changes What the chronicle changed.
	 * @return array<string,mixed>
	 */
	public static function merge( array $catalog, array $copy, array $changes ): array {
		$result = $catalog;
		foreach ( self::records( $changes ) as $record ) {
			$merged = self::apply( $result, $copy, $record['path'], [] );
			$result = is_array( $merged ) ? $merged : $result;
		}
		return $result;
	}

	/**
	 * Every change whose value beneath has changed since the chronicle made it, where the chronicle's value is not what
	 * is beneath now.
	 *
	 * A flag holds `path`, `removed` false, and `was`, `now` and `yours`: the value beneath when the change was made,
	 * the value beneath now and the chronicle's, each with a `_set` key saying whether it is there at all. The changes
	 * inside an entry the definition beneath no longer holds come as one flag on that entry instead, with `removed`
	 * true, `yours` (the chronicle's entry) and `changes`, each change inside it with its own `path`, `was` and `yours`.
	 *
	 * @param array<string,mixed> $catalog The definition beneath the copy as it now stands.
	 * @param array<string,mixed> $copy    The chronicle's copy.
	 * @param array<string,mixed> $changes What the chronicle changed.
	 * @return array<int,array<string,mixed>>
	 */
	public static function flags( array $catalog, array $copy, array $changes ): array {
		$flags   = [];
		$entries = [];
		foreach ( self::records( $changes ) as $record ) {
			$path = $record['path'];
			$was  = array_key_exists( 'under', $record ) ? $record['under'] : self::absent();

			$entry = self::removed_entry( $catalog, $copy, $path );
			if ( $entry !== null ) {
				$key = (string) json_encode( $entry );
				if ( ! isset( $entries[ $key ] ) ) {
					$entries[ $key ] = count( $flags );
					$flags[]         = [
						'path'      => $entry,
						'removed'   => true,
						'now'       => null,
						'now_set'   => false,
						'yours'     => self::lookup( $copy, $entry ),
						'yours_set' => true,
						'changes'   => [],
					];
				}
				$flags[ $entries[ $key ] ]['changes'][] = [ 'path' => $path ] + self::values( $was, self::absent(), self::lookup( $copy, $path ) );
				continue;
			}

			$now = self::lookup( $catalog, $path );
			if ( self::same_value( $was, $now ) ) {
				continue;
			}
			$yours = self::lookup( $copy, $path );
			if ( self::same_value( $yours, $now ) ) {
				continue;
			}
			$flags[] = [ 'path' => $path, 'removed' => false ] + self::values( $was, $now, $yours );
		}
		return $flags;
	}

	/**
	 * Keeps the chronicle's value at a flagged path: what is beneath now becomes the value the change was made over. On
	 * an entry the definition beneath no longer holds, the chronicle's entry becomes its own.
	 *
	 * @param array<string,mixed> $catalog The definition beneath the copy as it now stands.
	 * @param array<string,mixed> $copy    The chronicle's copy.
	 * @param array<string,mixed> $changes What the chronicle changed.
	 * @param array<int,mixed>    $path    The flag's path.
	 * @return array<string,mixed>
	 */
	public static function keep( array $catalog, array $copy, array $changes, array $path ): array {
		$records = self::records( $changes );
		if ( self::is_removed_entry_flag( $catalog, $copy, $records, $path ) ) {
			$records   = self::outside( $records, $path );
			$records[] = [ 'path' => $path ];
			return self::pack( $records );
		}
		foreach ( $records as $i => $record ) {
			if ( self::same_path( $record['path'], $path ) ) {
				$records[ $i ] = self::record_at( $path, $catalog );
			}
		}
		return self::pack( $records );
	}

	/**
	 * Keeps the chronicle's value at every flagged path.
	 *
	 * @param array<string,mixed> $catalog The definition beneath the copy as it now stands.
	 * @param array<string,mixed> $copy    The chronicle's copy.
	 * @param array<string,mixed> $changes What the chronicle changed.
	 * @return array<string,mixed>
	 */
	public static function keep_all( array $catalog, array $copy, array $changes ): array {
		foreach ( self::flags( $catalog, $copy, $changes ) as $flag ) {
			$changes = self::keep( $catalog, $copy, $changes, $flag['path'] );
		}
		return $changes;
	}

	/**
	 * Drops the chronicle's change at a path and every change inside it, so the definition beneath shows there.
	 *
	 * @param array<string,mixed> $changes What the chronicle changed.
	 * @param array<int,mixed>    $path
	 * @return array<string,mixed>
	 */
	public static function take( array $changes, array $path ): array {
		return self::pack( self::outside( self::records( $changes ), $path ) );
	}

	/**
	 * The name of each entry a path steps into, found in the chronicle's copy or else beneath it, and null for the path's
	 * other steps: an entry's `label`, `title`, `name` or `power_name`, or else its identity.
	 *
	 * @param array<string,mixed> $copy  The chronicle's copy.
	 * @param array<string,mixed> $under The definition beneath it.
	 * @param array<int,mixed>    $path
	 * @return array<int,string|null>
	 */
	public static function labels( array $copy, array $under, array $path ): array {
		$labels = [];
		foreach ( array_values( $path ) as $i => $step ) {
			if ( ! is_array( $step ) ) {
				$labels[] = null;
				continue;
			}
			$prefix = array_slice( $path, 0, $i + 1 );
			$entry  = self::lookup( $copy, $prefix );
			if ( self::is_absent( $entry ) ) {
				$entry = self::lookup( $under, $prefix );
			}
			$name = self::step_id( $step );
			if ( is_array( $entry ) ) {
				foreach ( [ 'label', 'title', 'name', 'power_name' ] as $key ) {
					if ( isset( $entry[ $key ] ) && is_scalar( $entry[ $key ] ) && (string) $entry[ $key ] !== '' ) {
						$name = (string) $entry[ $key ];
						break;
					}
				}
			}
			$labels[] = $name;
		}
		return $labels;
	}

	/**
	 * Adds every path at which two values differ, going into the maps and keyed lists both sides hold.
	 *
	 * @param mixed                       $before
	 * @param mixed                       $after
	 * @param array<int,mixed>            $path
	 * @param array<int,array<int,mixed>> $paths
	 * @param bool                        $removals Whether a value only `$before` holds was removed from `$after`.
	 */
	private static function diff( $before, $after, array $path, array &$paths, bool $removals ): void {
		if ( self::same_value( $before, $after ) ) {
			return;
		}
		$had = ! self::is_absent( $before );
		if ( self::is_absent( $after ) ) {
			if ( $removals ) {
				$paths[] = $path;
			}
			return;
		}
		if ( ! $had && $after === [] ) {
			$paths[] = $path;
			return;
		}

		$id_key = self::keyed_list_key( $path );
		if ( $id_key !== null && self::is_entry_list( $before ) && self::is_entry_list( $after ) ) {
			self::diff_entries( $had ? (array) $before : [], (array) $after, $path, $id_key, $paths, $removals );
			return;
		}

		if ( self::is_map( $before ) && self::is_map( $after ) ) {
			$before = $had ? (array) $before : [];
			$after  = (array) $after;
			foreach ( array_keys( $before + $after ) as $key ) {
				self::diff(
					array_key_exists( $key, $before ) ? $before[ $key ] : self::absent(),
					array_key_exists( $key, $after ) ? $after[ $key ] : self::absent(),
					array_merge( $path, [ $key ] ),
					$paths,
					$removals
				);
			}
			return;
		}

		$paths[] = $path;
	}

	/**
	 * Adds every path at which two keyed lists differ: an entry only one side holds, or a difference inside an entry
	 * both hold.
	 *
	 * @param array<int,mixed>            $before
	 * @param array<int,mixed>            $after
	 * @param array<int,mixed>            $path
	 * @param array<int,array<int,mixed>> $paths
	 */
	private static function diff_entries( array $before, array $after, array $path, string $id_key, array &$paths, bool $removals ): void {
		$before_by_id = self::by_id( $before, $id_key );
		$after_by_id  = self::by_id( $after, $id_key );

		foreach ( $after_by_id as $id => $entry ) {
			$entry_path = array_merge( $path, [ [ (string) $id ] ] );
			if ( ! array_key_exists( $id, $before_by_id ) ) {
				$paths[] = $entry_path;
				continue;
			}
			self::diff( $before_by_id[ $id ], $entry, $entry_path, $paths, $removals );
		}

		if ( ! $removals ) {
			return;
		}
		foreach ( array_keys( $before_by_id ) as $id ) {
			if ( ! array_key_exists( $id, $after_by_id ) ) {
				$paths[] = array_merge( $path, [ [ (string) $id ] ] );
			}
		}
	}

	/**
	 * The result with the chronicle's value laid over one path. Where the result lacks an entry on the way, the
	 * chronicle's whole entry goes in.
	 *
	 * @param mixed            $node  The result where the path begins.
	 * @param mixed            $own   The chronicle's copy where the path begins.
	 * @param array<int,mixed> $path  The path from here.
	 * @param array<int,mixed> $above The path to here.
	 * @return mixed
	 */
	private static function apply( $node, $own, array $path, array $above ) {
		if ( $path === [] ) {
			return $node;
		}
		$step = array_shift( $path );
		$here = array_merge( $above, [ $step ] );

		if ( is_array( $step ) ) {
			$list   = is_array( $node ) && array_is_list( $node ) ? $node : [];
			$id_key = self::id_key_at( $above );
			$id     = self::step_id( $step );
			$mine   = self::entry_in( $own, $id, $id_key );
			$at     = self::entry_indexes( $list, $id, $id_key );

			if ( $path === [] && self::is_absent( $mine ) ) {
				foreach ( $at as $index ) {
					unset( $list[ $index ] );
				}
				return array_values( $list );
			}
			if ( self::is_absent( $mine ) ) {
				return $list;
			}
			if ( $at === [] ) {
				$list[] = $mine;
				return $list;
			}
			foreach ( $at as $index ) {
				$list[ $index ] = $path === [] ? $mine : self::apply( $list[ $index ], $mine, $path, $here );
			}
			return $list;
		}

		$map  = is_array( $node ) ? $node : [];
		$mine = is_array( $own ) && array_key_exists( $step, $own ) ? $own[ $step ] : self::absent();
		if ( $path === [] ) {
			if ( self::is_absent( $mine ) ) {
				unset( $map[ $step ] );
			} else {
				$map[ $step ] = $mine;
			}
			return $map;
		}
		if ( self::is_absent( $mine ) ) {
			return $node;
		}
		$map[ $step ] = self::apply( array_key_exists( $step, $map ) ? $map[ $step ] : [], $mine, $path, $here );
		return $map;
	}

	/**
	 * The value at a path, or the absent marker.
	 *
	 * @param mixed            $node
	 * @param array<int,mixed> $path
	 * @return mixed
	 */
	private static function lookup( $node, array $path ) {
		$above = [];
		foreach ( $path as $step ) {
			if ( is_array( $step ) ) {
				$node = self::entry_in( $node, self::step_id( $step ), self::id_key_at( $above ) );
			} elseif ( is_array( $node ) && array_key_exists( $step, $node ) ) {
				$node = $node[ $step ];
			} else {
				return self::absent();
			}
			if ( self::is_absent( $node ) ) {
				return $node;
			}
			$above[] = $step;
		}
		return $node;
	}

	/**
	 * The outermost entry on a path, above its last step, that the definition beneath no longer holds and the copy
	 * does.
	 *
	 * @param array<string,mixed> $catalog
	 * @param array<string,mixed> $copy
	 * @param array<int,mixed>    $path
	 * @return array<int,mixed>|null
	 */
	private static function removed_entry( array $catalog, array $copy, array $path ): ?array {
		for ( $length = 1; $length < count( $path ); $length++ ) {
			if ( ! is_array( $path[ $length - 1 ] ) ) {
				continue;
			}
			$prefix = array_slice( $path, 0, $length );
			if ( self::is_absent( self::lookup( $catalog, $prefix ) ) ) {
				return self::is_absent( self::lookup( $copy, $prefix ) ) ? null : $prefix;
			}
		}
		return null;
	}

	/**
	 * Whether a path names an entry the definition beneath no longer holds, which the copy holds with changes inside it.
	 *
	 * @param array<string,mixed>            $catalog
	 * @param array<string,mixed>            $copy
	 * @param array<int,array<string,mixed>> $records
	 * @param array<int,mixed>               $path
	 */
	private static function is_removed_entry_flag( array $catalog, array $copy, array $records, array $path ): bool {
		if ( $path === [] || ! is_array( $path[ count( $path ) - 1 ] ) ) {
			return false;
		}
		if ( ! self::is_absent( self::lookup( $catalog, $path ) ) || self::is_absent( self::lookup( $copy, $path ) ) ) {
			return false;
		}
		foreach ( $records as $record ) {
			if ( count( $record['path'] ) > count( $path ) && self::is_prefix( $path, $record['path'] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * A change at a path, with the value beneath it there.
	 *
	 * @param array<int,mixed>    $path
	 * @param array<string,mixed> $under
	 * @return array<string,mixed>
	 */
	private static function record_at( array $path, array $under ): array {
		$value = self::lookup( $under, $path );
		return self::is_absent( $value ) ? [ 'path' => $path ] : [ 'path' => $path, 'under' => $value ];
	}

	/**
	 * The changes that still make the copy differ from what is beneath it.
	 *
	 * @param array<int,array<string,mixed>> $records
	 * @param array<string,mixed>            $copy
	 * @param array<string,mixed>            $under
	 * @return array<int,array<string,mixed>>
	 */
	private static function effective( array $records, array $copy, array $under ): array {
		return array_values( array_filter(
			$records,
			static fn( array $record ): bool => ! self::same_value( self::lookup( $copy, $record['path'] ), self::lookup( $under, $record['path'] ) )
		) );
	}

	/**
	 * The changes outside a path.
	 *
	 * @param array<int,array<string,mixed>> $records
	 * @param array<int,mixed>               $path
	 * @return array<int,array<string,mixed>>
	 */
	private static function outside( array $records, array $path ): array {
		return array_values( array_filter( $records, static fn( array $record ): bool => ! self::is_prefix( $path, $record['path'] ) ) );
	}

	/**
	 * A change record's changes.
	 *
	 * @param array<string,mixed> $changes
	 * @return array<int,array<string,mixed>>
	 */
	private static function records( array $changes ): array {
		$records = [];
		foreach ( (array) ( $changes['changes'] ?? [] ) as $record ) {
			if ( is_array( $record ) && isset( $record['path'] ) && is_array( $record['path'] ) ) {
				$record['path'] = array_values( $record['path'] );
				$records[]      = $record;
			}
		}
		return $records;
	}

	/**
	 * @param array<int,array<string,mixed>> $records
	 * @return array<string,mixed>
	 */
	private static function pack( array $records ): array {
		return [ 'changes' => array_values( $records ) ];
	}

	/**
	 * A flag's three values, each with whether it is there.
	 *
	 * @param mixed $was
	 * @param mixed $now
	 * @param mixed $yours
	 * @return array<string,mixed>
	 */
	private static function values( $was, $now, $yours ): array {
		return [
			'was'       => self::is_absent( $was ) ? null : $was,
			'was_set'   => ! self::is_absent( $was ),
			'now'       => self::is_absent( $now ) ? null : $now,
			'now_set'   => ! self::is_absent( $now ),
			'yours'     => self::is_absent( $yours ) ? null : $yours,
			'yours_set' => ! self::is_absent( $yours ),
		];
	}

	/**
	 * The identity key of the keyed list at a path, or null when the path holds no keyed list.
	 *
	 * @param array<int,mixed> $path
	 */
	private static function keyed_list_key( array $path ): ?string {
		$count = count( $path );
		if ( $count === 0 || is_array( $path[ $count - 1 ] ) ) {
			return null;
		}
		$key = (string) $path[ $count - 1 ];
		if ( isset( self::KEYED_LISTS[ $key ] ) ) {
			return self::KEYED_LISTS[ $key ];
		}
		if ( $count >= 2 && ! is_array( $path[ $count - 2 ] ) && isset( self::KEYED_LIST_MAPS[ (string) $path[ $count - 2 ] ] ) ) {
			return self::KEYED_LIST_MAPS[ (string) $path[ $count - 2 ] ];
		}
		return null;
	}

	/**
	 * The identity key of the entries in the list at a path.
	 *
	 * @param array<int,mixed> $path
	 */
	private static function id_key_at( array $path ): string {
		return self::keyed_list_key( $path ) ?? 'name';
	}

	/**
	 * Whether a value is a list of entries, or nothing.
	 *
	 * @param mixed $value
	 */
	private static function is_entry_list( $value ): bool {
		if ( self::is_absent( $value ) ) {
			return true;
		}
		if ( ! is_array( $value ) || ! array_is_list( $value ) ) {
			return false;
		}
		foreach ( $value as $entry ) {
			if ( ! is_array( $entry ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Whether a value is a map, an empty array or nothing.
	 *
	 * @param mixed $value
	 */
	private static function is_map( $value ): bool {
		return self::is_absent( $value ) || ( is_array( $value ) && ( $value === [] || ! array_is_list( $value ) ) );
	}

	/**
	 * @param array<int,mixed> $entries
	 * @return array<array-key,array<string,mixed>>
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
	 * The last entry of a list with an identity, or the absent marker.
	 *
	 * @param mixed $list
	 * @return mixed
	 */
	private static function entry_in( $list, string $id, string $id_key ) {
		$found = self::absent();
		if ( ! is_array( $list ) || ! array_is_list( $list ) ) {
			return $found;
		}
		foreach ( $list as $entry ) {
			if ( is_array( $entry ) && self::entry_id( $entry, $id_key ) === $id ) {
				$found = $entry;
			}
		}
		return $found;
	}

	/**
	 * The positions of a list's entries with an identity.
	 *
	 * @param array<int,mixed> $list
	 * @return array<int,int>
	 */
	private static function entry_indexes( array $list, string $id, string $id_key ): array {
		$at = [];
		foreach ( $list as $index => $entry ) {
			if ( is_array( $entry ) && self::entry_id( $entry, $id_key ) === $id ) {
				$at[] = (int) $index;
			}
		}
		return $at;
	}

	/**
	 * An entry's identity within its list: its `$id_key` value, or its level or step number when that's empty.
	 *
	 * @param array<string,mixed> $entry
	 */
	private static function entry_id( array $entry, string $id_key ): string {
		if ( isset( $entry[ $id_key ] ) && ! is_array( $entry[ $id_key ] ) && (string) $entry[ $id_key ] !== '' ) {
			return (string) $entry[ $id_key ];
		}
		return '#' . (string) ( $entry['level'] ?? $entry['step'] ?? '' );
	}

	/**
	 * The identity a list step names.
	 *
	 * @param array<int,mixed> $step
	 */
	private static function step_id( array $step ): string {
		$id = $step[0] ?? '';
		return is_scalar( $id ) ? (string) $id : '';
	}

	/**
	 * Whether one path begins with another, or is it.
	 *
	 * @param array<int,mixed> $prefix
	 * @param array<int,mixed> $path
	 */
	private static function is_prefix( array $prefix, array $path ): bool {
		if ( count( $prefix ) > count( $path ) ) {
			return false;
		}
		foreach ( array_values( $prefix ) as $i => $step ) {
			if ( ! self::same_step( $step, $path[ $i ] ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * @param array<int,mixed> $a
	 * @param array<int,mixed> $b
	 */
	private static function same_path( array $a, array $b ): bool {
		return count( $a ) === count( $b ) && self::is_prefix( $a, $b );
	}

	/**
	 * @param mixed $a
	 * @param mixed $b
	 */
	private static function same_step( $a, $b ): bool {
		if ( is_array( $a ) || is_array( $b ) ) {
			return is_array( $a ) && is_array( $b ) && self::step_id( $a ) === self::step_id( $b );
		}
		return is_scalar( $a ) && is_scalar( $b ) && (string) $a === (string) $b;
	}

	/**
	 * Whether two values are the same, both absent, or the same decoded JSON whatever order a map's keys were stored in.
	 *
	 * @param mixed $a
	 * @param mixed $b
	 */
	private static function same_value( $a, $b ): bool {
		if ( self::is_absent( $a ) || self::is_absent( $b ) ) {
			return self::is_absent( $a ) && self::is_absent( $b );
		}
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

	private static function absent(): object {
		if ( self::$absent === null ) {
			self::$absent = new \stdClass();
		}
		return self::$absent;
	}

	/**
	 * @param mixed $value
	 */
	private static function is_absent( $value ): bool {
		return $value === self::absent();
	}
}
