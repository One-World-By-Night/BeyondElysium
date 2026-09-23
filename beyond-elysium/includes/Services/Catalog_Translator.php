<?php

namespace BeyondElysium\Services;

use BeyondElysium\Database\Manager;
use BeyondElysium\Models\Translation;
use BeyondElysium\Models\Translation_String;

defined( 'ABSPATH' ) || exit;

/**
 * Runtime and maintenance operations for catalog term translation (1.2.0).
 *
 * map() is the one query the render path pays for, transient-cached and version-busted.
 * decorate() adds display translations to a resolved schema block; strip() is its exact
 * inverse, removing them before a definition is ever persisted (§5.5's round-trip hazard).
 * rescan() keeps the string index honest against the real, current catalog.
 *
 * @see BE_PROCESS/releases/1.2.0-design-workflow.md §5
 */
class Catalog_Translator {

	private const TRANSIENT_PREFIX = 'be_translations_';
	private const VERSION_OPTION   = 'be_translations_version';

	/** The four keys decorate() ever adds, and the only keys strip() ever removes. */
	private const PT_KEYS = [ 'name_pt', 'power_name_pt', 'options_pt', 'label_pt' ];

	/**
	 * The whole locale's dictionary, source_key => translation, transient-cached.
	 *
	 * An English install (or any locale with zero rows) pays one transient read - a plain
	 * `wp_options` row on a site with no persistent object cache - and gets an empty array
	 * back; no special-cased fast path is needed because the emptiness itself is what gets
	 * cached (§5.1). Busting is `bust_cache()` bumping VERSION_OPTION, which changes every
	 * transient key derived from it, orphaning the old entries rather than deleting them - the
	 * same discipline already used elsewhere in this codebase.
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
	 * Bumps the version option so every locale's cached map is invalidated at once - callers
	 * do not need to know which locale a write touched. One `update_option`, per §5.1.
	 */
	public static function bust_cache(): void {
		update_option( self::VERSION_OPTION, (int) get_option( self::VERSION_OPTION, 1 ) + 1, false );
	}

	/**
	 * Adds display translations to a resolved schema block's definition, in place, and
	 * returns the same object. Never touches the canonical `name`/`options`/`items` a block
	 * already carries - only adds the `_pt` fields in PT_KEYS, and only where the locale's
	 * map actually has an entry.
	 *
	 * Deliberately overwrites any `name_pt` a block's definition already carries (the pre-1.2.0
	 * CSV-sourced value B9 retires) rather than leaving a pre-existing one alone: the table
	 * this reads from is the new source of truth, and a stale baked-in value must not survive
	 * a native speaker's correction here just because it happened to be set first.
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
	 * §5.6: `label_pt` translates the field's own name; `options_pt` is a
	 * `{canonical_option: translated}` map, added only when at least one option actually has a
	 * translation - never an empty map sitting on every field whether it has data or not.
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
	 * Looks $item->$source_field up in the map and sets $item->$pt_field when found. A missing
	 * or empty source field, or no matching translation, leaves $item untouched - the absence
	 * of the _pt key is what every consumer's English-fallback rule already checks for.
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
	 * Sets a property this codebase's decorated objects genuinely do not declare - every one
	 * of PT_KEYS is additive metadata a plain schema_blocks definition has no static shape
	 * for. Routing the write through its own function, with the property name arriving as a
	 * parameter rather than a same-scope literal, is what keeps PHPStan from re-deriving a
	 * concrete property name and flagging it as undefined - the same reason set_pt()'s own
	 * three call sites (each passing a literal 'name_pt'/'power_name_pt'/'label_pt') are
	 * already clean. Not a suppression: PHPStan genuinely cannot verify a name across a call
	 * boundary, which is the honest state of affairs for an intentionally dynamic property.
	 *
	 * @param object $item
	 * @param string $property
	 * @param mixed  $value
	 */
	private static function set_dynamic( object $item, string $property, $value ): void {
		$item->$property = $value;
	}

	/**
	 * The exact inverse of decorate(): removes every PT_KEYS key, at any depth, from an
	 * array|object definition. Structure-agnostic on purpose - unlike decorate(), which must
	 * know each section_type's shape to know where to add a translation, removal only needs
	 * to know the four key names, so one recursive walk covers every section_type without a
	 * matching switch statement to keep in sync as new shapes are added.
	 *
	 * Called from Schema_Block::create()/::update() (B5) before wp_json_encode(), so a
	 * decorated definition read back through a GET-edit-PUT round trip in an admin editor can
	 * never persist a `_pt` key into the definition it does not belong in (§5.5).
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

		// The array|object parameter type makes this exhaustive - is_object() above and
		// is_array() here cover every value the type system allows in.
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
	 * Walks every schema_blocks row - system blocks and every chronicle fork alike, matching
	 * §1.2's own "across all blocks including the three live chronicle forks" measurement -
	 * collects every distinct term, and upserts be_translation_strings once per term with its
	 * full aggregated usage. Usage is aggregated across the whole catalog before any upsert
	 * runs: Translation_String::upsert_from_scan() replaces used_in rather than merging it, so
	 * calling it once per raw occurrence would leave only whichever block was walked last.
	 *
	 * A string this walk does not find keeps its last_seen unchanged - "orphaned" is never a
	 * flag or a delete, only a last_seen that fell behind, so a translation already made for a
	 * term is never lost when a catalog is temporarily narrowed (§5.3).
	 *
	 * @return array{added:int,updated:int,orphaned:int}
	 */
	public static function rescan(): array {
		global $wpdb;
		// Microsecond precision, not current_time('mysql') - a full rescan is fast enough
		// that two calls landing in the same wall-clock second is the ordinary case, and
		// second-granularity made a genuinely orphaned row indistinguishable from one this
		// scan just touched (confirmed live, see Translation_String::now_micro()'s docblock).
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
	 * Every `(name, name_pt)` / `(power_name, power_name_pt)` pair already baked into the
	 * seeded catalog - the pre-1.2.0 CSV-sourced mechanism §8's migration recovers into the
	 * table. Only `trait_list` and `tiered_power` ever carried this (the CSV mechanism never
	 * touched `identity_field`/`resource_pool`), so this walks only those two shapes, unlike
	 * `extract_terms()`'s full four-shape dispatch. A pair with an empty pt value is skipped -
	 * nothing to recover.
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
	 * Every distinct term a decoded definition carries, by section_type, each as
	 * `[$source_text, $role]`. `$role` is what a future usage-display groups by; it is not
	 * used for matching. A term with no meaningful text (empty name, blank option) is skipped
	 * rather than producing a translatable "" row.
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
