<?php

namespace BeyondElysium\Services;

use BeyondElysium\Models\Change;
use BeyondElysium\Models\Character;

defined( 'ABSPATH' ) || exit;

/**
 * Who holds a catalog entry in one chronicle: the characters whose sheets hold a row naming it and the waiting changes
 * that name it. A row names its entry by name, a power by its family and, for a pick, the pick's own name.
 */
class Catalog_Holders {

	/**
	 * How many characters hold each entry of one block and how many waiting changes name it, by lowercased key (see
	 * `key()`). A family counts under its own name for every row of it, and a pick again under its family and its own
	 * name.
	 *
	 * @return array<string,array{characters:int,changes:int}>
	 */
	public static function for_block( string $game_slug, string $block_slug ): array {
		$held = [];

		foreach ( Character::all_for_game( $game_slug ) as $character ) {
			$sheet = is_array( $character->sheet_data ?? null ) ? $character->sheet_data : [];
			$seen  = [];
			foreach ( is_array( $sheet[ $block_slug ] ?? null ) ? $sheet[ $block_slug ] : [] as $row ) {
				foreach ( self::keys_of( is_array( $row ) ? $row : [] ) as $key ) {
					$seen[ $key ] = true;
				}
			}
			foreach ( array_keys( $seen ) as $key ) {
				$held[ $key ] = self::bump( $held[ $key ] ?? null, 'characters' );
			}
		}

		foreach ( Change::for_game( $game_slug, [ 'status' => 'pending' ] ) as $change ) {
			$data = is_array( $change->change_data ?? null ) ? $change->change_data : [];
			if ( ( $data['block_slug'] ?? null ) !== $block_slug ) {
				continue;
			}
			foreach ( self::keys_of( is_array( $data['trait'] ?? null ) ? $data['trait'] : [] ) as $key ) {
				$held[ $key ] = self::bump( $held[ $key ] ?? null, 'changes' );
			}
		}

		return $held;
	}

	/**
	 * The key an entry is counted under: its name, with a pick's own name after a bar.
	 */
	public static function key( string $name, string $power_name = '' ): string {
		$key = strtolower( trim( $name ) );
		return '' === $power_name ? $key : $key . '|' . strtolower( trim( $power_name ) );
	}

	/**
	 * What holds one entry, from `for_block()`'s answer.
	 *
	 * @param array<string,array{characters:int,changes:int}> $held
	 * @return array{characters:int,changes:int}
	 */
	public static function of( array $held, string $name, string $power_name = '' ): array {
		return $held[ self::key( $name, $power_name ) ] ?? [ 'characters' => 0, 'changes' => 0 ];
	}

	/**
	 * The sentence that says what holds an entry, or null when nothing does.
	 *
	 * @param array{characters:int,changes:int} $count
	 */
	public static function message( array $count ): ?string {
		$characters = (int) $count['characters'];
		$changes    = (int) $count['changes'];
		if ( 0 === $characters && 0 === $changes ) {
			return null;
		}

		$held = sprintf(
			/* translators: %d: how many characters hold an entry */
			_n( '%d character holds this', '%d characters hold this', $characters, 'beyond-elysium' ),
			$characters
		);
		$named = sprintf(
			/* translators: %d: how many waiting changes name an entry */
			_n( '%d change waiting names it', '%d changes waiting name it', $changes, 'beyond-elysium' ),
			$changes
		);

		if ( 0 === $changes ) {
			return $held . '.';
		}
		if ( 0 === $characters ) {
			return $named . '.';
		}
		/* translators: 1: "4 characters hold this", 2: "1 change waiting names it" */
		return sprintf( __( '%1$s, and %2$s.', 'beyond-elysium' ), $held, $named );
	}

	/**
	 * The keys one row or trait counts under: its name, and with a power name its pick as well.
	 *
	 * @param array<string,mixed> $row
	 * @return string[]
	 */
	private static function keys_of( array $row ): array {
		$name = isset( $row['name'] ) && is_scalar( $row['name'] ) ? trim( (string) $row['name'] ) : '';
		if ( '' === $name ) {
			return [];
		}
		$keys       = [ self::key( $name ) ];
		$power_name = isset( $row['power_name'] ) && is_scalar( $row['power_name'] ) ? trim( (string) $row['power_name'] ) : '';
		if ( '' !== $power_name ) {
			$keys[] = self::key( $name, $power_name );
		}
		return $keys;
	}

	/**
	 * @param array{characters:int,changes:int}|null $count
	 * @return array{characters:int,changes:int}
	 */
	private static function bump( ?array $count, string $field ): array {
		$count          = $count ?? [ 'characters' => 0, 'changes' => 0 ];
		$count[ $field ]++;
		return $count;
	}
}
