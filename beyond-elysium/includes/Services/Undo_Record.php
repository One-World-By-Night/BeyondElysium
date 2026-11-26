<?php

namespace BeyondElysium\Services;

defined( 'ABSPATH' ) || exit;

/**
 * What an approved change did to a character, kept so a Storyteller can undo it: for each sheet block it altered, the
 * entries it added and removed (a list) or the fields it changed with their values before and after (a keyed block),
 * and the XP it moved.
 *
 * Undoing applies the record in reverse, and only while everything the change touched is as the change left it.
 */
class Undo_Record {

	/**
	 * What a record looks like, so a later layout can tell an older one apart.
	 */
	public const VERSION = 1;

	/**
	 * Builds the record of a change from the sheet before and after it and the XP it moved.
	 *
	 * @param array<string,mixed> $before   The character's sheet data before the change.
	 * @param array<string,mixed> $after    The sheet data after it.
	 * @param int                 $earned   The change to XP earned.
	 * @param int                 $unspent  The change to XP unspent.
	 * @return array<string,mixed>|null Null when the change altered nothing.
	 */
	public static function build( array $before, array $after, int $earned = 0, int $unspent = 0 ): ?array {
		$blocks = [];
		foreach ( array_unique( array_merge( array_keys( $before ), array_keys( $after ) ) ) as $slug ) {
			$slug = (string) $slug;
			$old  = $before[ $slug ] ?? null;
			$new  = $after[ $slug ] ?? null;
			if ( self::canon( $old ) === self::canon( $new ) ) {
				continue;
			}
			$blocks[ $slug ] = self::block_record( $old, $new, array_key_exists( $slug, $before ) );
		}

		if ( $blocks === [] && $earned === 0 && $unspent === 0 ) {
			return null;
		}

		return [
			'v'      => self::VERSION,
			'blocks' => $blocks,
			'xp'     => [ 'earned' => $earned, 'unspent' => $unspent ],
		];
	}

	/**
	 * The XP a record moved.
	 *
	 * @param array<string,mixed> $record
	 * @return array{earned:int,unspent:int}
	 */
	public static function xp( array $record ): array {
		$xp = is_array( $record['xp'] ?? null ) ? $record['xp'] : [];
		return [ 'earned' => (int) ( $xp['earned'] ?? 0 ), 'unspent' => (int) ( $xp['unspent'] ?? 0 ) ];
	}

	/**
	 * What stops a record being undone against a sheet: each entry the change added that is no longer there as it was
	 * left, each entry it removed that is back, and each field it changed that no longer holds the value it left. A
	 * pool's temporary rating is play state and never stops anything.
	 *
	 * @param array<string,mixed> $record
	 * @param array<string,mixed> $sheet   The character's sheet data now.
	 * @return array<int,array{block:string,name:string,now:mixed,kind:string}> Each with `kind` one of `changed`,
	 *         `missing` or `back`; empty when the record can be undone.
	 */
	public static function conflicts( array $record, array $sheet ): array {
		$conflicts = [];
		foreach ( (array) ( $record['blocks'] ?? [] ) as $slug => $block ) {
			$slug    = (string) $slug;
			$current = $sheet[ $slug ] ?? null;
			if ( ! is_array( $block ) ) {
				continue;
			}
			if ( ( $block['kind'] ?? '' ) === 'list' ) {
				array_push( $conflicts, ...self::list_conflicts( $slug, $block, is_array( $current ) ? array_values( $current ) : [] ) );
			} else {
				array_push( $conflicts, ...self::keyed_conflicts( $slug, $block, is_array( $current ) ? $current : [] ) );
			}
		}
		return $conflicts;
	}

	/**
	 * The sheet with a record applied in reverse. Call `conflicts()` first: this puts things back without checking.
	 *
	 * @param array<string,mixed>                        $record
	 * @param array<string,mixed>                        $sheet The character's sheet data now.
	 * @param array<string,array<string,array{kind:string,max:int|null}>> $kinds Each pool's kind, by block and name, as `Pool_Markers` reads them.
	 * @return array<string,mixed>
	 */
	public static function reverse( array $record, array $sheet, array $kinds = [] ): array {
		foreach ( (array) ( $record['blocks'] ?? [] ) as $slug => $block ) {
			$slug = (string) $slug;
			if ( ! is_array( $block ) ) {
				continue;
			}
			$current = is_array( $sheet[ $slug ] ?? null ) ? $sheet[ $slug ] : [];
			$next    = ( $block['kind'] ?? '' ) === 'list'
				? self::reverse_list( $block, array_values( $current ) )
				: self::reverse_keyed( $block, $current, $kinds[ $slug ] ?? [] );

			if ( $next === [] && ! empty( $block['absent_before'] ) ) {
				unset( $sheet[ $slug ] );
			} else {
				$sheet[ $slug ] = $next;
			}
		}
		return $sheet;
	}

	/**
	 * One block's record.
	 *
	 * @param mixed $old
	 * @param mixed $new
	 * @return array<string,mixed>
	 */
	private static function block_record( $old, $new, bool $held_before ): array {
		$old_array = is_array( $old ) ? $old : [];
		$new_array = is_array( $new ) ? $new : [];
		$record    = $held_before ? [] : [ 'absent_before' => true ];

		if ( array_is_list( $old_array ) && array_is_list( $new_array ) ) {
			return $record + self::list_record( $old_array, $new_array );
		}

		$fields = [];
		foreach ( array_unique( array_merge( array_keys( $old_array ), array_keys( $new_array ) ) ) as $field ) {
			$before = $old_array[ $field ] ?? null;
			$after  = $new_array[ $field ] ?? null;
			if ( self::canon( $before ) !== self::canon( $after ) ) {
				$fields[ (string) $field ] = [ 'before' => $before, 'after' => $after ];
			}
		}
		return $record + [ 'kind' => 'keyed', 'fields' => $fields ];
	}

	/**
	 * The one region of a list a change replaced: what stood there before and what stands there after.
	 *
	 * @param array<int,mixed> $old
	 * @param array<int,mixed> $new
	 * @return array<string,mixed>
	 */
	private static function list_record( array $old, array $new ): array {
		$shortest = min( count( $old ), count( $new ) );
		$at       = 0;
		while ( $at < $shortest && self::canon( $old[ $at ] ) === self::canon( $new[ $at ] ) ) {
			++$at;
		}
		$tail = 0;
		while (
			$tail < $shortest - $at
			&& self::canon( $old[ count( $old ) - 1 - $tail ] ) === self::canon( $new[ count( $new ) - 1 - $tail ] )
		) {
			++$tail;
		}

		return [
			'kind'    => 'list',
			'at'      => $at,
			'removed' => array_slice( $old, $at, count( $old ) - $at - $tail ),
			'added'   => array_slice( $new, $at, count( $new ) - $at - $tail ),
		];
	}

	/**
	 * @param array<string,mixed> $block
	 * @param array<int,mixed>    $current
	 * @return array<int,array{block:string,name:string,now:mixed,kind:string}>
	 */
	private static function list_conflicts( string $slug, array $block, array $current ): array {
		$conflicts = [];
		$left      = $current;

		foreach ( (array) ( $block['added'] ?? [] ) as $row ) {
			$at = self::find_row( $left, $row );
			if ( $at !== null ) {
				array_splice( $left, $at, 1 );
				continue;
			}
			$name        = self::row_name( $row );
			$conflicts[] = [
				'block' => $slug,
				'name'  => $name,
				'now'   => self::row_called( $current, $name ),
				'kind'  => 'missing',
			];
		}

		foreach ( (array) ( $block['removed'] ?? [] ) as $row ) {
			if ( self::find_row( $current, $row ) !== null ) {
				$conflicts[] = [ 'block' => $slug, 'name' => self::row_name( $row ), 'now' => $row, 'kind' => 'back' ];
			}
		}
		return $conflicts;
	}

	/**
	 * @param array<string,mixed> $block
	 * @param array<string,mixed> $current
	 * @return array<int,array{block:string,name:string,now:mixed,kind:string}>
	 */
	private static function keyed_conflicts( string $slug, array $block, array $current ): array {
		$conflicts = [];
		foreach ( (array) ( $block['fields'] ?? [] ) as $field => $change ) {
			$field = (string) $field;
			$now   = $current[ $field ] ?? null;
			if ( self::canon( self::steady( $now ) ) !== self::canon( self::steady( is_array( $change ) ? ( $change['after'] ?? null ) : null ) ) ) {
				$conflicts[] = [ 'block' => $slug, 'name' => $field, 'now' => $now, 'kind' => 'changed' ];
			}
		}
		return $conflicts;
	}

	/**
	 * @param array<string,mixed> $block
	 * @param array<int,mixed>    $rows
	 * @return array<int,mixed>
	 */
	private static function reverse_list( array $block, array $rows ): array {
		$added   = array_values( (array) ( $block['added'] ?? [] ) );
		$removed = array_values( (array) ( $block['removed'] ?? [] ) );
		$at      = (int) ( $block['at'] ?? 0 );

		$places = [];
		foreach ( $added as $row ) {
			$found = self::find_row( $rows, $row );
			if ( $found !== null ) {
				$places[] = $found;
				$rows[ $found ] = [ '__undo_taken' => true ];
			}
		}

		// A row replaced by another keeps its place, wherever later changes have moved it.
		if ( count( $added ) === count( $removed ) && count( $places ) === count( $added ) ) {
			foreach ( $places as $i => $place ) {
				$rows[ $place ] = $removed[ $i ];
			}
			return array_values( $rows );
		}

		$rows = array_values( array_filter( $rows, static fn( $row ): bool => ! ( is_array( $row ) && ( $row['__undo_taken'] ?? false ) === true ) ) );
		array_splice( $rows, min( $at, count( $rows ) ), 0, $removed );
		return $rows;
	}

	/**
	 * @param array<string,mixed>                          $block
	 * @param array<string,mixed>                          $current
	 * @param array<string,array{kind:string,max:int|null}> $kinds
	 * @return array<string,mixed>
	 */
	private static function reverse_keyed( array $block, array $current, array $kinds ): array {
		foreach ( (array) ( $block['fields'] ?? [] ) as $field => $change ) {
			$field  = (string) $field;
			$change = is_array( $change ) ? $change : [];
			$before = $change['before'] ?? null;
			$after  = $change['after'] ?? null;

			if ( $before === null ) {
				unset( $current[ $field ] );
			} elseif ( is_array( $before ) && array_key_exists( 'permanent', $before ) ) {
				$now               = is_array( $current[ $field ] ?? null ) ? $current[ $field ] : [];
				$current[ $field ] = self::reverse_pool( $before, is_array( $after ) ? $after : [], $now, $kinds[ $field ]['kind'] ?? Pool_Markers::ORDINARY );
			} else {
				$current[ $field ] = $before;
			}
		}
		return $current;
	}

	/**
	 * A pool put back: its rating and what the change marked on it come back as they were, and the temporary rating
	 * moves by what the change itself moved it, so play since is kept. An ordinary pool's temporary rating stays within
	 * its rating, and a pool with no temporary points stays level with it.
	 *
	 * @param array<string,mixed> $before
	 * @param array<string,mixed> $after
	 * @param array<string,mixed> $now
	 * @return array<string,mixed>
	 */
	private static function reverse_pool( array $before, array $after, array $now, string $kind ): array {
		$pool      = $before;
		$permanent = $before['permanent'];
		$moved     = (float) ( $before['temporary'] ?? $permanent ) - (float) ( $after['temporary'] ?? $permanent );
		$temporary = (float) ( $now['temporary'] ?? $now['permanent'] ?? $permanent ) + $moved;
		$temporary = max( 0.0, $temporary );

		if ( $kind === Pool_Markers::RATING_ONLY ) {
			$temporary = (float) $permanent;
		} elseif ( $kind !== Pool_Markers::ACCUMULATES ) {
			$temporary = min( $temporary, (float) $permanent );
		}

		$pool['temporary'] = floor( $temporary ) === $temporary ? (int) $temporary : $temporary;
		return $pool;
	}

	/**
	 * A pool without its temporary rating, which play moves and an undo never waits on.
	 *
	 * @param mixed $value
	 * @return mixed
	 */
	private static function steady( $value ) {
		if ( is_array( $value ) && array_key_exists( 'permanent', $value ) ) {
			unset( $value['temporary'] );
		}
		return $value;
	}

	/**
	 * The position of the first row equal to another, or null.
	 *
	 * @param array<int,mixed> $rows
	 * @param mixed            $wanted
	 */
	private static function find_row( array $rows, $wanted ): ?int {
		$key = self::canon( $wanted );
		foreach ( $rows as $at => $row ) {
			if ( self::canon( $row ) === $key ) {
				return (int) $at;
			}
		}
		return null;
	}

	/**
	 * The row of a list that goes by a name, or null.
	 *
	 * @param array<int,mixed> $rows
	 * @return mixed
	 */
	private static function row_called( array $rows, string $name ) {
		foreach ( $rows as $row ) {
			if ( self::row_name( $row ) === $name ) {
				return $row;
			}
		}
		return null;
	}

	/**
	 * @param mixed $row
	 */
	private static function row_name( $row ): string {
		return is_array( $row ) && is_string( $row['name'] ?? null ) ? $row['name'] : '';
	}

	/**
	 * A value as text that does not depend on key order, so two values compare by what they hold.
	 *
	 * @param mixed $value
	 */
	private static function canon( $value ): string {
		return (string) json_encode( self::sorted( $value ) );
	}

	/**
	 * @param mixed $value
	 * @return mixed
	 */
	private static function sorted( $value ) {
		if ( ! is_array( $value ) ) {
			return $value;
		}
		$value = array_map( [ self::class, 'sorted' ], $value );
		if ( ! array_is_list( $value ) ) {
			ksort( $value );
		}
		return $value;
	}
}
