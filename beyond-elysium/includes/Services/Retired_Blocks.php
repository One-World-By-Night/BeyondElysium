<?php

namespace BeyondElysium\Services;

use BeyondElysium\Database\Manager;
use BeyondElysium\Models\Template;

defined( 'ABSPATH' ) || exit;

/**
 * The shared blocks the per-creature catalog replaced (`met-abilities`, `met-merits`, `met-flaws`) and the two blocks
 * no stack lists any more (`demon-lores`, `mortal-numina`). `remove_unused()` deletes each one nothing on the install
 * names and logs what keeps the others.
 */
class Retired_Blocks {

	/**
	 * Block slugs an upgrade removes once nothing names them.
	 */
	const SLUGS = [ 'met-abilities', 'met-merits', 'met-flaws', 'demon-lores', 'mortal-numina' ];

	/**
	 * The retired blocks no stack lists and no block replaced.
	 */
	const UNREPLACED = [ 'demon-lores', 'mortal-numina' ];

	/**
	 * Deletes every retired block that nothing names.
	 *
	 * @return array{removed:string[],kept:array<string,string[]>} The slugs deleted, and for each slug kept the lines
	 *         naming what still uses it.
	 */
	public static function remove_unused(): array {
		$removed = [];
		$kept    = [];

		foreach ( self::SLUGS as $slug ) {
			if ( ! self::exists( $slug ) ) {
				continue;
			}

			$references = self::references( $slug );
			if ( $references !== [] ) {
				$kept[ $slug ] = $references;
				error_log( "Beyond Elysium: kept the retired block {$slug}: " . implode( '; ', $references ) );
				continue;
			}

			if ( Manager::delete( 'schema_blocks', [ 'slug' => $slug, 'game_slug' => '' ] ) !== false ) {
				$removed[] = $slug;
				error_log( "Beyond Elysium: removed the retired block {$slug}" );
			}
		}

		return [ 'removed' => $removed, 'kept' => $kept ];
	}

	/**
	 * Removes the sections naming an unreplaced retired block from every system template that is not a chronicle's own.
	 *
	 * @return int The number of templates changed.
	 */
	public static function drop_from_system_templates(): int {
		$changed = 0;

		foreach ( Template::globals() as $template ) {
			/** @var object{id:int,is_system:int,layout:array<string,mixed>} $template */
			if ( empty( $template->is_system ) ) {
				continue;
			}

			$layout   = $template->layout;
			$sections = (array) ( $layout['sections'] ?? [] );
			$kept     = array_values( array_filter(
				$sections,
				static fn( $section ): bool => ! in_array( $section['block_slug'] ?? '', self::UNREPLACED, true )
			) );
			if ( count( $kept ) === count( $sections ) ) {
				continue;
			}

			$layout['sections'] = $kept;
			if ( Template::update( (int) $template->id, [ 'layout' => $layout ] ) ) {
				$changed++;
			}
		}

		return $changed;
	}

	/**
	 * What on this install names a block, one line per kind of reference: a creature stack's sections, a template's
	 * sections, a character holding entries under it, a chronicle's copy of the block, another block's definition, a
	 * change still pending on it and a translation keyed on it.
	 *
	 * @return string[] Empty when nothing names it.
	 */
	public static function references( string $slug ): array {
		$lines = [];

		foreach ( self::named_in( 'creature_stacks', 'slug', [ 'stack_definition', 'creation_rules' ], $slug ) as $stack ) {
			$lines[] = "stack {$stack}";
		}
		foreach ( self::named_in( 'templates', 'id', [ 'layout' ], $slug ) as $template ) {
			$lines[] = "template {$template}";
		}
		foreach ( self::named_in( 'schema_blocks', 'slug', [ 'definition' ], $slug, true ) as $block ) {
			$lines[] = "block {$block}";
		}

		$characters = (int) Manager::get_var(
			'SELECT COUNT(*) FROM ' . Manager::table( 'characters' ) . ' WHERE JSON_LENGTH( JSON_EXTRACT( sheet_data, CONCAT( \'$."\', %s, \'"\' ) ) ) > 0',
			$slug
		);
		if ( $characters > 0 ) {
			$lines[] = "{$characters} character(s) holding entries under it";
		}

		$copies = (int) Manager::get_var(
			'SELECT COUNT(*) FROM ' . Manager::table( 'schema_blocks' ) . " WHERE slug = %s AND game_slug <> ''",
			$slug
		);
		if ( $copies > 0 ) {
			$lines[] = "{$copies} chronicle copy(ies)";
		}

		$pending = (int) Manager::get_var(
			'SELECT COUNT(*) FROM ' . Manager::table( 'character_changes' ) . " WHERE status = 'pending' AND JSON_UNQUOTE( JSON_EXTRACT( change_data, '$.block_slug' ) ) = %s",
			$slug
		);
		if ( $pending > 0 ) {
			$lines[] = "{$pending} pending change(s)";
		}

		$translations = (int) Manager::get_var(
			'SELECT COUNT(*) FROM ' . Manager::table( 'translations' ) . ' WHERE context = %s',
			$slug
		);
		if ( $translations > 0 ) {
			$lines[] = "{$translations} translation(s) keyed on it";
		}

		return $lines;
	}

	private static function exists( string $slug ): bool {
		return (int) Manager::get_var(
			'SELECT COUNT(*) FROM ' . Manager::table( 'schema_blocks' ) . " WHERE slug = %s AND game_slug = ''",
			$slug
		) > 0;
	}

	/**
	 * The keys of the rows in one table whose JSON columns hold `$slug` as a value anywhere.
	 *
	 * @param string[] $columns      JSON columns to search.
	 * @param bool     $exclude_self Leave out a row whose own `slug` is `$slug`.
	 * @return string[]
	 */
	private static function named_in( string $table, string $key, array $columns, string $slug, bool $exclude_self = false ): array {
		$search = implode( ' OR ', array_map( static fn( string $column ): string => "JSON_SEARCH( {$column}, 'one', %s ) IS NOT NULL", $columns ) );
		$args   = array_fill( 0, count( $columns ), $slug );
		$where  = "({$search})";
		if ( $exclude_self ) {
			$where .= ' AND slug <> %s';
			$args[] = $slug;
		}

		$rows = Manager::get_results( "SELECT {$key} AS name FROM " . Manager::table( $table ) . " WHERE {$where}", ...$args );

		return array_map( static fn( $row ): string => (string) $row->name, $rows );
	}
}
