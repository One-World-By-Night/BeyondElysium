<?php

namespace BeyondElysium\Services;

use BeyondElysium\Database\Fork_Merge;
use BeyondElysium\Database\Manager;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Schema_Block;

defined( 'ABSPATH' ) || exit;

/**
 * The book variants a chronicle chooses for a base block: which it has chosen, which held entries a choice would leave
 * unmatched, and choosing.
 */
class Catalog_Variants {

	/**
	 * Every base block that has variants, by name, with each variant's id, label and mode and the ids the chronicle
	 * chose.
	 *
	 * @param object $game A games row.
	 * @return array<int,array{base:string,base_name:string,variants:array<int,array{id:string,label:string,mode:string}>,chosen:array<int,string>}>
	 */
	public static function for_game( object $game ): array {
		$all = get_option( Schema_Block::VARIANTS_OPTION, [] );
		if ( ! is_array( $all ) || $all === [] ) {
			return [];
		}
		$names = Schema_Block::names_with_creature( self::book_names( array_map( 'strval', array_keys( $all ) ) ) );

		$bases = [];
		foreach ( $all as $base => $variants ) {
			$base = (string) $base;
			if ( ! isset( $names[ $base ] ) ) {
				continue;
			}
			$bases[] = [
				'base'      => $base,
				'base_name' => $names[ $base ],
				'variants'  => array_map(
					static fn( array $variant ): array => [ 'id' => (string) $variant['id'], 'label' => (string) $variant['label'], 'mode' => (string) $variant['mode'] ],
					array_values( (array) $variants )
				),
				'chosen'    => array_column( Schema_Block::chosen_variants( $base, (string) $game->slug ), 'id' ),
			];
		}
		usort( $bases, static fn( array $a, array $b ): int => strcasecmp( $a['base_name'], $b['base_name'] ) );
		return $bases;
	}

	/**
	 * Why a choice of variants for a base block can't be made, or null when it can: every id one of the base's variants,
	 * and one replacing variant at most.
	 *
	 * @param string            $base
	 * @param array<int,string> $ids
	 */
	public static function refusal( string $base, array $ids ): ?string {
		$known = [];
		foreach ( Schema_Block::variants_of( $base ) as $variant ) {
			$known[ $variant['id'] ] = $variant['mode'];
		}
		if ( $known === [] ) {
			return 'unknown_base';
		}
		$replacing = 0;
		foreach ( $ids as $id ) {
			if ( ! isset( $known[ $id ] ) ) {
				return 'unknown_variant';
			}
			$replacing += $known[ $id ] === 'replace' ? 1 : 0;
		}
		return $replacing > 1 ? 'one_replacement' : null;
	}

	/**
	 * The entries a chronicle's characters hold in a base block that match it as it stands and would not with these
	 * variants chosen: each character's id and name, and the entry's name and pick.
	 *
	 * @param object            $game
	 * @param string            $base
	 * @param array<int,string> $ids
	 * @return array<int,array{character_id:int,character:string,name:string,power_name:string|null}>
	 */
	public static function unmatched( object $game, string $base, array $ids ): array {
		$section_type = (string) Manager::get_var( 'SELECT section_type FROM ' . Manager::table( 'schema_blocks' ) . " WHERE slug = %s AND game_slug = ''", $base );
		$now          = self::as_objects( self::resolved( $game, $base, Schema_Block::chosen_variants( $base, (string) $game->slug ) ) );
		$next         = self::as_objects( self::resolved( $game, $base, self::descriptors( $base, $ids ) ) );

		$lost = [];
		foreach ( self::holders( $game, $base ) as $holder ) {
			foreach ( $holder['rows'] as $row ) {
				if ( ! is_array( $row ) || ! self::holds( $section_type, $now, $row ) || self::holds( $section_type, $next, $row ) ) {
					continue;
				}
				$lost[] = [
					'character_id' => $holder['id'],
					'character'    => $holder['name'],
					'name'         => (string) ( $row['name'] ?? '' ),
					'power_name'   => isset( $row['power_name'] ) && $row['power_name'] !== '' ? (string) $row['power_name'] : null,
				];
			}
		}
		return $lost;
	}

	/**
	 * Chooses a chronicle's variants for a base block, then rebuilds its copy of the block over the book with them. A
	 * copy left with no variant and no change of its own is removed.
	 *
	 * @param object            $game
	 * @param string            $base
	 * @param array<int,string> $ids In the order they are folded in.
	 * @return bool
	 */
	public static function choose( object $game, string $base, array $ids ): bool {
		$settings = json_decode( (string) wp_json_encode( $game->settings ?? new \stdClass() ), true );
		$settings = is_array( $settings ) ? $settings : [];
		$chosen   = (array) ( $settings['catalog_variants'] ?? [] );
		if ( $ids === [] ) {
			unset( $chosen[ $base ] );
		} else {
			$chosen[ $base ] = array_values( $ids );
		}
		if ( $chosen === [] ) {
			unset( $settings['catalog_variants'] );
		} else {
			$settings['catalog_variants'] = $chosen;
		}
		if ( ! Game::update( (string) $game->slug, [ 'settings' => $settings ] ) ) {
			return false;
		}

		$copy = Schema_Block::copy_for_game( $base, (string) $game->slug );
		if ( $copy === null ) {
			return $ids === [] || Schema_Block::find_or_create_fork_for_game( $base, (string) $game->slug ) !== null;
		}
		if ( $ids === [] && $copy['changes']['changes'] === [] ) {
			return Manager::delete( 'schema_blocks', [ 'id' => $copy['id'] ] ) !== false;
		}
		return Schema_Block::refresh_fork_for_game( $base, (string) $game->slug );
	}

	/**
	 * A base block as a chronicle would have it with these variants: the book with them applied, its own changes laid
	 * over.
	 *
	 * @param object                                                         $game
	 * @param string                                                         $base
	 * @param array<int,array{slug:string,id:string,label:string,mode:string}> $variants
	 * @return array<string,mixed>
	 */
	private static function resolved( object $game, string $base, array $variants ): array {
		$under = Schema_Block::under_with( $base, $variants );
		$copy  = Schema_Block::copy_for_game( $base, (string) $game->slug );
		return $copy === null ? $under : Fork_Merge::merge( $under, $copy['definition'], $copy['changes'] );
	}

	/**
	 * The descriptors of the variants these ids name, the replacing one first.
	 *
	 * @param string            $base
	 * @param array<int,string> $ids
	 * @return array<int,array{slug:string,id:string,label:string,mode:string}>
	 */
	private static function descriptors( string $base, array $ids ): array {
		$by_id = array_column( Schema_Block::variants_of( $base ), null, 'id' );
		$chosen = [];
		foreach ( $ids as $id ) {
			if ( isset( $by_id[ $id ] ) ) {
				$chosen[] = $by_id[ $id ];
			}
		}
		usort( $chosen, static fn( array $a, array $b ): int => ( $a['mode'] === 'replace' ? 0 : 1 ) <=> ( $b['mode'] === 'replace' ? 0 : 1 ) );
		return $chosen;
	}

	/**
	 * Whether a block's definition still answers to a held row: its catalog entry by name or alias, and its pick by
	 * power name or alias. A custom row is the chronicle's own and always does.
	 *
	 * @param string              $section_type
	 * @param object              $definition
	 * @param array<string,mixed> $row
	 */
	private static function holds( string $section_type, object $definition, array $row ): bool {
		if ( ! empty( $row['custom'] ) ) {
			return true;
		}
		$name = (string) ( $row['name'] ?? '' );
		if ( $section_type === 'trait_list' ) {
			return Trait_Alias_Resolver::find_item_by_name( (array) ( $definition->items ?? [] ), $name ) !== null;
		}
		$family = Trait_Alias_Resolver::find_power_by_name( (array) ( $definition->powers ?? [] ), $name );
		if ( $family === null ) {
			return false;
		}
		$pick = (string) ( $row['power_name'] ?? '' );
		return $pick === '' || Trait_Alias_Resolver::find_level_by_name( Power_Levels::all( $family ), $pick ) !== null;
	}

	/**
	 * The chronicle's characters holding anything in a block, each with its id, name and held rows.
	 *
	 * @param object $game
	 * @param string $base
	 * @return array<int,array{id:int,name:string,rows:array<int,mixed>}>
	 */
	private static function holders( object $game, string $base ): array {
		global $wpdb;
		$rows = Manager::get_results(
			'SELECT id, name, sheet_data FROM ' . Manager::table( 'characters' ) . " WHERE owner_type = 'chronicle' AND owner_slug = %s AND sheet_data LIKE %s",
			(string) $game->slug,
			'%' . $wpdb->esc_like( '"' . $base . '":' ) . '%'
		);
		$holders = [];
		foreach ( $rows as $row ) {
			$sheet = json_decode( (string) $row->sheet_data, true );
			$held  = is_array( $sheet ) ? ( $sheet[ $base ] ?? null ) : null;
			if ( is_array( $held ) && $held !== [] ) {
				$holders[] = [ 'id' => (int) $row->id, 'name' => (string) $row->name, 'rows' => array_values( $held ) ];
			}
		}
		return $holders;
	}

	/**
	 * The book's names of these blocks, by slug.
	 *
	 * @param array<int,string> $slugs
	 * @return array<string,string>
	 */
	private static function book_names( array $slugs ): array {
		if ( $slugs === [] ) {
			return [];
		}
		global $wpdb;
		$placeholders = implode( ',', array_fill( 0, count( $slugs ), '%s' ) );
		$rows         = $wpdb->get_results( $wpdb->prepare(
			'SELECT slug, name FROM ' . Manager::table( 'schema_blocks' ) . " WHERE game_slug = '' AND slug IN ({$placeholders})",
			$slugs
		) ) ?: [];
		$names = [];
		foreach ( $rows as $row ) {
			$names[ (string) $row->slug ] = (string) $row->name;
		}
		return $names;
	}

	/**
	 * A definition as nested objects, the shape the alias resolver reads.
	 *
	 * @param array<string,mixed> $definition
	 */
	private static function as_objects( array $definition ): object {
		$decoded = json_decode( (string) wp_json_encode( $definition ) );
		return is_object( $decoded ) ? $decoded : new \stdClass();
	}
}
