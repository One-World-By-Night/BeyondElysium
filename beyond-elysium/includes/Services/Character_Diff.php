<?php

namespace BeyondElysium\Services;

defined( 'ABSPATH' ) || exit;

/**
 * What differs between the sheet a chronicle already holds and the same character arriving in an exchange document.
 */
class Character_Diff {

	/**
	 * Parsed keys that are not a detail of the sheet: the document's own identity, the player and narrator names each
	 * site keeps for itself, pool sizes the parser derives from the trait lists, the lists compared on their own, and
	 * boons.
	 */
	private const NOT_DETAILS = [ 'id', 'uuid', 'player', 'narrator', 'last_modified', 'physical_max', 'social_max', 'mental_max', 'experience', 'trait_lists', 'boons' ];

	private const EXCERPT_LENGTH = 120;

	/**
	 * @param array<string,mixed> $arriving A parsed exchange character.
	 * @param object              $here     The character row this chronicle already holds.
	 * @return array<int,array{section:string,entry:string,here:?string,arriving:?string}>
	 */
	public static function against( array $arriving, object $here ): array {
		$parsed = GEX_Xml_Parser::parse_string( Character_Exporter::export( (int) $here->id )['xml'] );
		return self::compare( $parsed['characters'][0] ?? [], $arriving );
	}

	/**
	 * One row per difference.
	 *
	 * @param array<string,mixed> $here
	 * @param array<string,mixed> $arriving
	 * @return array<int,array{section:string,entry:string,here:?string,arriving:?string}>
	 */
	public static function compare( array $here, array $arriving ): array {
		return array_merge(
			self::details( $here, $arriving ),
			self::experience( (array) ( $here['experience'] ?? [] ), (array) ( $arriving['experience'] ?? [] ) ),
			self::trait_lists( (array) ( $here['trait_lists'] ?? [] ), (array) ( $arriving['trait_lists'] ?? [] ) )
		);
	}

	/**
	 * @param array<string,mixed> $here
	 * @param array<string,mixed> $arriving
	 * @return array<int,array{section:string,entry:string,here:?string,arriving:?string}>
	 */
	private static function details( array $here, array $arriving ): array {
		$rows = [];
		foreach ( array_keys( $arriving + $here ) as $key ) {
			$key = (string) $key;
			if ( in_array( $key, self::NOT_DETAILS, true ) ) {
				continue;
			}
			$before = self::text( $here[ $key ] ?? '' );
			$after  = self::text( $arriving[ $key ] ?? '' );
			if ( $before !== $after ) {
				$rows[] = self::row( __( 'Details', 'beyond-elysium' ), self::label( $key ), $before, $after );
			}
		}
		return $rows;
	}

	/**
	 * The two totals only.
	 *
	 * @param array<string,mixed> $here
	 * @param array<string,mixed> $arriving
	 * @return array<int,array{section:string,entry:string,here:?string,arriving:?string}>
	 */
	private static function experience( array $here, array $arriving ): array {
		$rows = [];
		foreach ( [ 'earned' => __( 'Earned', 'beyond-elysium' ), 'unspent' => __( 'Unspent', 'beyond-elysium' ) ] as $key => $label ) {
			$before = self::number( (float) ( $here[ $key ] ?? 0 ) );
			$after  = self::number( (float) ( $arriving[ $key ] ?? 0 ) );
			if ( $before !== $after ) {
				$rows[] = self::row( __( 'Experience', 'beyond-elysium' ), $label, $before, $after );
			}
		}
		return $rows;
	}

	/**
	 * Every trait list either side carries, each trait by name and note.
	 *
	 * @param array<string,mixed> $here
	 * @param array<string,mixed> $arriving
	 * @return array<int,array{section:string,entry:string,here:?string,arriving:?string}>
	 */
	private static function trait_lists( array $here, array $arriving ): array {
		$rows = [];
		foreach ( array_keys( $arriving + $here ) as $name ) {
			$rows = array_merge( $rows, self::entries(
				(string) $name,
				(array) ( $here[ $name ]['traits'] ?? [] ),
				(array) ( $arriving[ $name ]['traits'] ?? [] ),
				static fn( array $trait ): string => ( $trait['note'] ?? '' ) === ''
					? (string) ( $trait['name'] ?? '' )
					: sprintf( '%s (%s)', $trait['name'] ?? '', $trait['note'] ),
				static fn( array $trait ): string => (string) ( $trait['total'] ?? '' )
			) );
		}
		return $rows;
	}

	/**
	 * Compares two lists of entries grouped under a label.
	 *
	 * @param array<int,mixed>                     $here
	 * @param array<int,mixed>                     $arriving
	 * @param callable(array<string,mixed>):string $label
	 * @param callable(array<string,mixed>):string $value
	 * @return array<int,array{section:string,entry:string,here:?string,arriving:?string}>
	 */
	private static function entries( string $section, array $here, array $arriving, callable $label, callable $value ): array {
		$group = static function ( array $list ) use ( $label, $value ): array {
			$grouped = [];
			foreach ( $list as $item ) {
				if ( is_array( $item ) ) {
					$grouped[ $label( $item ) ][] = $value( $item );
				}
			}
			return array_map(
				static function ( array $values ): string {
					sort( $values, SORT_STRING );
					return implode( ', ', $values );
				},
				$grouped
			);
		};

		$before = $group( $here );
		$after  = $group( $arriving );
		$rows   = [];
		foreach ( array_keys( $after + $before ) as $entry ) {
			$was = $before[ $entry ] ?? null;
			$now = $after[ $entry ] ?? null;
			if ( $was !== $now ) {
				$rows[] = self::row( $section, (string) $entry, $was, $now );
			}
		}
		return $rows;
	}

	/**
	 * @return array{section:string,entry:string,here:?string,arriving:?string}
	 */
	private static function row( string $section, string $entry, ?string $here, ?string $arriving ): array {
		return [
			'section'  => $section,
			'entry'    => self::excerpt( $entry ),
			'here'     => $here === null ? null : self::excerpt( $here ),
			'arriving' => $arriving === null ? null : self::excerpt( $arriving ),
		];
	}

	/**
	 * @param mixed $value
	 */
	private static function text( $value ): string {
		if ( is_bool( $value ) ) {
			return $value ? __( 'Yes', 'beyond-elysium' ) : __( 'No', 'beyond-elysium' );
		}
		if ( is_float( $value ) ) {
			return self::number( $value );
		}
		return is_scalar( $value ) ? trim( (string) $value ) : '';
	}

	private static function number( float $value ): string {
		return rtrim( rtrim( number_format( $value, 2, '.', '' ), '0' ), '.' );
	}

	private static function label( string $key ): string {
		$labels = [
			'race'   => __( 'Creature type', 'beyond-elysium' ),
			'is_npc' => __( 'NPC', 'beyond-elysium' ),
		];
		return $labels[ $key ] ?? ucwords( str_replace( '_', ' ', $key ) );
	}

	private static function excerpt( string $text ): string {
		return mb_strlen( $text ) > self::EXCERPT_LENGTH ? mb_substr( $text, 0, self::EXCERPT_LENGTH - 1 ) . "\u{2026}" : $text;
	}
}
