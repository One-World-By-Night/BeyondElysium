<?php

namespace BeyondElysium\Services;

use BeyondElysium\Database\Manager;
use BeyondElysium\Models\Translation;
use BeyondElysium\Models\Translation_String;

defined( 'ABSPATH' ) || exit;

/**
 * Runtime and maintenance operations for catalog term translation.
 */
class Catalog_Translator {

	private const TRANSIENT_PREFIX = 'be_translations_';
	private const VERSION_OPTION   = 'be_translations_version';

	/**
	 * The four keys decorate() ever adds, and the only keys strip() ever removes.
	 */
	private const PT_KEYS = [ 'name_pt', 'power_name_pt', 'options_pt', 'label_pt' ];

	/**
	 * The Portuguese (Brazil) draft for each catalog name the plugin ships: a `name,translation` CSV.
	 */
	const PT_BR_NAMES_PATH = __DIR__ . '/../../data/translations/pt_BR.csv';

	/**
	 * The whole locale's dictionary, source_key => translation, transient-cached.
	 *
	 * @param string $locale
	 * @return array<string,string> source_key => translation.
	 */
	public static function map( string $locale ): array {
		$key    = self::transient_key( $locale );
		$cached = get_transient( $key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$map = Translation::map_for_locale( $locale );
		set_transient( $key, $map, DAY_IN_SECONDS );
		return $map;
	}

	/**
	 * Bumps the version option.
	 */
	public static function bust_cache(): void {
		update_option( self::VERSION_OPTION, (int) get_option( self::VERSION_OPTION, 1 ) + 1, false );
	}

	/**
	 * Adds display translations to a resolved schema block's definition, in place, and returns the same object.
	 *
	 * @param object $block A schema_blocks row with ->section_type and ->definition already
	 *                       decoded (Schema_Block::decode_row()'s own shape).
	 * @param string $locale
	 * @return object The same $block, decorated.
	 */
	public static function decorate( object $block, string $locale ): object {
		$map = self::map( $locale );
		if ( ! $map ) {
			return $block;
		}

		$definition = $block->definition ?? null;
		if ( ! is_object( $definition ) ) {
			return $block;
		}

		switch ( $block->section_type ?? '' ) {
			case 'trait_list':
				self::decorate_trait_list( $definition, $map );
				break;
			case 'tiered_power':
				self::decorate_tiered_power( $definition, $map );
				break;
			case 'identity_field':
				self::decorate_identity_field( $definition, $map );
				break;
			case 'resource_pool':
				self::decorate_resource_pool( $definition, $map );
				break;
		}

		return $block;
	}

	/**
	 * @param object               $definition
	 * @param array<string,string> $map
	 */
	private static function decorate_trait_list( object $definition, array $map ): void {
		foreach ( $definition->items ?? [] as $item ) {
			self::set_pt( $item, 'name', 'name_pt', $map );
		}
	}

	/**
	 * @param object               $definition
	 * @param array<string,string> $map
	 */
	private static function decorate_tiered_power( object $definition, array $map ): void {
		foreach ( $definition->powers ?? [] as $power ) {
			self::set_pt( $power, 'name', 'name_pt', $map );
			foreach ( Power_Levels::all( $power ) as $level ) {
				self::set_pt( $level, 'power_name', 'power_name_pt', $map );
			}
		}
	}

	/**
	 * `label_pt` translates the field's own name.
	 *
	 * @param object               $definition
	 * @param array<string,string> $map
	 */
	private static function decorate_identity_field( object $definition, array $map ): void {
		foreach ( $definition->fields ?? [] as $field ) {
			self::set_pt( $field, 'name', 'label_pt', $map );

			if ( empty( $field->options ) || ! is_array( $field->options ) ) {
				continue;
			}

			$options_pt = [];
			foreach ( $field->options as $option ) {
				$key = Name_Key::for( (string) $option );
				if ( isset( $map[ $key ] ) ) {
					$options_pt[ $option ] = $map[ $key ];
				}
			}
			if ( $options_pt ) {
				self::set_dynamic( $field, 'options_pt', $options_pt );
			}
		}
	}

	/**
	 * @param object               $definition
	 * @param array<string,string> $map
	 */
	private static function decorate_resource_pool( object $definition, array $map ): void {
		foreach ( $definition->pools ?? [] as $pool ) {
			self::set_pt( $pool, 'name', 'label_pt', $map );
		}
	}

	/**
	 * Looks $item->$source_field up in the map and sets $item->$pt_field when found.
	 *
	 * @param object               $item
	 * @param string               $source_field
	 * @param string               $pt_field
	 * @param array<string,string> $map
	 */
	private static function set_pt( object $item, string $source_field, string $pt_field, array $map ): void {
		$text = $item->$source_field ?? '';
		if ( '' === $text ) {
			return;
		}
		$key = Name_Key::for( (string) $text );
		if ( isset( $map[ $key ] ) ) {
			self::set_dynamic( $item, $pt_field, $map[ $key ] );
		}
	}

	/**
	 * Sets a property this codebase's decorated objects genuinely do not declare.
	 *
	 * @param object $item
	 * @param string $property
	 * @param mixed  $value
	 */
	private static function set_dynamic( object $item, string $property, $value ): void {
		$item->$property = $value;
	}

	/**
	 * The exact inverse of decorate(): removes every PT_KEYS key, at any depth, from an array|object definition.
	 *
	 * @param array|object $definition
	 * @return array|object The same type it was given.
	 */
	public static function strip( array|object $definition ): array|object {
		if ( is_object( $definition ) ) {
			foreach ( self::PT_KEYS as $key ) {
				unset( $definition->$key );
			}
			foreach ( $definition as $value ) {
				if ( is_array( $value ) || is_object( $value ) ) {
					self::strip( $value );
				}
			}
			return $definition;
		}

		foreach ( self::PT_KEYS as $key ) {
			unset( $definition[ $key ] );
		}
		foreach ( $definition as $k => $value ) {
			if ( is_array( $value ) || is_object( $value ) ) {
				$definition[ $k ] = self::strip( $value );
			}
		}
		return $definition;
	}

	/**
	 * Walks every schema_blocks row.
	 *
	 * @return array{added:int,updated:int,orphaned:int}
	 */
	public static function rescan(): array {
		global $wpdb;
		// Microsecond precision.
		$scan_started_at = Translation_String::now_micro();

		$existing_keys = array_flip( $wpdb->get_col(
			'SELECT source_key FROM ' . Manager::table( 'translation_strings' )
		) );

		$usage_by_key = [];
		$rows         = $wpdb->get_results(
			'SELECT slug, game_slug, section_type, definition FROM ' . Manager::table( 'schema_blocks' )
		);

		foreach ( $rows as $row ) {
			$definition = json_decode( (string) $row->definition );
			if ( ! $definition ) {
				continue;
			}
			foreach ( self::extract_terms( (string) $row->section_type, $definition ) as [ $text, $role ] ) {
				$key = Name_Key::for( $text );
				if ( '' === $key ) {
					continue;
				}
				$usage_by_key[ $key ]['text']      ??= $text;
				$usage_by_key[ $key ]['used_in'][] = [
					'block'        => $row->slug,
					'game_slug'    => $row->game_slug,
					'section_type' => $row->section_type,
					'role'         => $role,
				];
			}
		}

		$added   = 0;
		$updated = 0;
		foreach ( $usage_by_key as $key => $entry ) {
			Translation_String::upsert_from_scan( $entry['text'], $entry['used_in'] );
			if ( isset( $existing_keys[ $key ] ) ) {
				++$updated;
			} else {
				++$added;
			}
		}

		$orphaned = (int) $wpdb->get_var( $wpdb->prepare(
			'SELECT COUNT(*) FROM ' . Manager::table( 'translation_strings' ) . ' WHERE last_seen < %s',
			$scan_started_at
		) );

		return [ 'added' => $added, 'updated' => $updated, 'orphaned' => $orphaned ];
	}

	/**
	 * The shipped Portuguese (Brazil) drafts, one pair per row of the file in file order.
	 *
	 * @return array<int,array{0:string,1:string}> [name, translation]. Empty when the file is absent or unreadable.
	 */
	public static function shipped_pt_pairs( string $path = self::PT_BR_NAMES_PATH ): array {
		$handle = is_readable( $path ) ? fopen( $path, 'r' ) : false;
		if ( ! $handle ) {
			return [];
		}

		$pairs = [];
		fgetcsv( $handle, 0, ',', '"', '\\' );
		while ( ( $row = fgetcsv( $handle, 0, ',', '"', '\\' ) ) !== false ) {
			$name        = trim( (string) ( $row[0] ?? '' ) );
			$translation = trim( (string) ( $row[1] ?? '' ) );
			if ( '' !== $name && '' !== $translation ) {
				$pairs[] = [ $name, $translation ];
			}
		}
		fclose( $handle );

		return $pairs;
	}

	/**
	 * Every `(name, name_pt)` / `(power_name, power_name_pt)` pair already baked into the seeded catalog.
	 *
	 * @return array<int,array{0:string,1:string}> [source_text, existing_pt].
	 */
	public static function harvest_existing_pt_pairs(): array {
		global $wpdb;
		$pairs = [];

		$rows = $wpdb->get_results(
			'SELECT section_type, definition FROM ' . Manager::table( 'schema_blocks' )
		);

		foreach ( $rows as $row ) {
			$definition = json_decode( (string) $row->definition );
			if ( ! $definition ) {
				continue;
			}

			if ( 'trait_list' === $row->section_type ) {
				foreach ( $definition->items ?? [] as $item ) {
					if ( ! empty( $item->name ) && ! empty( $item->name_pt ) ) {
						$pairs[] = [ $item->name, $item->name_pt ];
					}
				}
			} elseif ( 'tiered_power' === $row->section_type ) {
				foreach ( $definition->powers ?? [] as $power ) {
					if ( ! empty( $power->name ) && ! empty( $power->name_pt ) ) {
						$pairs[] = [ $power->name, $power->name_pt ];
					}
					foreach ( Power_Levels::all( $power ) as $level ) {
						if ( ! empty( $level->power_name ) && ! empty( $level->power_name_pt ) ) {
							$pairs[] = [ $level->power_name, $level->power_name_pt ];
						}
					}
				}
			}
		}

		return $pairs;
	}

	/**
	 * Every distinct term a decoded definition carries, by section_type, each as `[$source_text, $role]`.
	 *
	 * @param string $section_type
	 * @param object $definition
	 * @return array<int,array{0:string,1:string}>
	 */
	private static function extract_terms( string $section_type, object $definition ): array {
		$terms = [];

		switch ( $section_type ) {
			case 'trait_list':
				foreach ( $definition->items ?? [] as $item ) {
					if ( ! empty( $item->name ) ) {
						$terms[] = [ $item->name, 'item' ];
					}
				}
				break;

			case 'tiered_power':
				foreach ( $definition->powers ?? [] as $power ) {
					if ( ! empty( $power->name ) ) {
						$terms[] = [ $power->name, 'family' ];
					}
					foreach ( Power_Levels::all( $power ) as $level ) {
						if ( ! empty( $level->power_name ) ) {
							$terms[] = [ $level->power_name, 'level' ];
						}
					}
				}
				break;

			case 'identity_field':
				foreach ( $definition->fields ?? [] as $field ) {
					if ( ! empty( $field->name ) ) {
						$terms[] = [ $field->name, 'field_label' ];
					}
					foreach ( $field->options ?? [] as $option ) {
						if ( '' !== (string) $option ) {
							$terms[] = [ (string) $option, 'option' ];
						}
					}
				}
				break;

			case 'resource_pool':
				foreach ( $definition->pools ?? [] as $pool ) {
					if ( ! empty( $pool->name ) ) {
						$terms[] = [ $pool->name, 'pool_label' ];
					}
				}
				break;
		}

		return $terms;
	}

	/**
	 * @param string $locale
	 * @return string
	 */
	private static function transient_key( string $locale ): string {
		$version = (int) get_option( self::VERSION_OPTION, 1 );
		return self::TRANSIENT_PREFIX . $locale . '_' . $version;
	}
}
