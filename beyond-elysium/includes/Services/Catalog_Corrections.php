<?php

namespace BeyondElysium\Services;

use BeyondElysium\Database\Fork_Merge;
use BeyondElysium\Database\Manager;
use BeyondElysium\Models\Creature_Stack;
use BeyondElysium\Models\Schema_Block;
use BeyondElysium\Models\Template;

defined( 'ABSPATH' ) || exit;

/**
 * A chronicle's book corrections: each change it made to a catalog block, a creature type or a sheet template whose
 * value beneath has changed since, derived on every read and never stored.
 */
class Catalog_Corrections {

	/**
	 * The kinds of layer a correction sits in.
	 */
	const KINDS = [ 'block', 'stack', 'template' ];

	/**
	 * Every flagged correction in a chronicle's layers: its blocks, then its creature types, then its templates, each
	 * kind by name.
	 *
	 * @param object $game A games row.
	 * @return array<int,array<string,mixed>>
	 */
	public static function for_game( object $game ): array {
		$corrections = [];
		foreach ( self::layers( $game ) as $layer ) {
			foreach ( Fork_Merge::flags( $layer['book'], $layer['copy'], $layer['changes'] ) as $flag ) {
				$corrections[] = [
					'kind'        => $layer['kind'],
					'target'      => $layer['target'],
					'target_name' => $layer['name'],
					'labels'      => self::labels( $game, $layer, $flag['path'] ),
				] + $flag;
			}
		}
		return $corrections;
	}

	/**
	 * How many corrections a chronicle has to review.
	 *
	 * @param object $game A games row.
	 */
	public static function count( object $game ): int {
		return count( self::for_game( $game ) );
	}

	/**
	 * Keeps the chronicle's value at a path: the book's value now becomes the one its change was made over.
	 *
	 * @param object           $game
	 * @param string           $kind   One of KINDS.
	 * @param string           $target The block's or creature type's slug, or the template's id.
	 * @param array<int,mixed> $path
	 * @return bool|null Null when the chronicle has no such layer.
	 */
	public static function keep( object $game, string $kind, string $target, array $path ): ?bool {
		$layer = self::layer( $game, $kind, $target );
		if ( $layer === null ) {
			return null;
		}
		return self::store( $layer, $layer['copy'], Fork_Merge::keep( $layer['book'], $layer['copy'], $layer['changes'], $path ) );
	}

	/**
	 * Takes the book's value at a path: the chronicle's change there, and every change inside it, is dropped.
	 *
	 * @param object           $game
	 * @param string           $kind   One of KINDS.
	 * @param string           $target The block's or creature type's slug, or the template's id.
	 * @param array<int,mixed> $path
	 * @return bool|null Null when the chronicle has no such layer.
	 */
	public static function take( object $game, string $kind, string $target, array $path ): ?bool {
		$layer = self::layer( $game, $kind, $target );
		if ( $layer === null ) {
			return null;
		}
		$changes = Fork_Merge::take( $layer['changes'], $path );
		return self::store( $layer, Fork_Merge::merge( $layer['book'], $layer['copy'], $changes ), $changes );
	}

	/**
	 * Keeps the chronicle's value at every flagged path, changing no value.
	 *
	 * @param object $game
	 * @return int How many corrections were cleared.
	 */
	public static function keep_all( object $game ): int {
		$cleared = 0;
		foreach ( self::layers( $game ) as $layer ) {
			$flags = Fork_Merge::flags( $layer['book'], $layer['copy'], $layer['changes'] );
			if ( $flags === [] ) {
				continue;
			}
			if ( self::store( $layer, $layer['copy'], Fork_Merge::keep_all( $layer['book'], $layer['copy'], $layer['changes'] ) ) ) {
				$cleared += count( $flags );
			}
		}
		return $cleared;
	}

	/**
	 * Every layer a chronicle has over the book, of one kind or all.
	 *
	 * @param object      $game
	 * @param string|null $kind
	 * @param string|null $target
	 * @return array<int,array<string,mixed>>
	 */
	private static function layers( object $game, ?string $kind = null, ?string $target = null ): array {
		$layers = [];
		if ( $kind === null || $kind === 'block' ) {
			$layers = array_merge( $layers, self::block_layers( $game, $target ) );
		}
		if ( $kind === null || $kind === 'stack' ) {
			$layers = array_merge( $layers, self::stack_layers( $game, $target ) );
		}
		if ( $kind === null || $kind === 'template' ) {
			$layers = array_merge( $layers, self::template_layers( $game, $target ) );
		}
		return $layers;
	}

	/**
	 * One layer, or null.
	 *
	 * @return array<string,mixed>|null
	 */
	private static function layer( object $game, string $kind, string $target ): ?array {
		if ( ! in_array( $kind, self::KINDS, true ) || $target === '' ) {
			return null;
		}
		$layers = self::layers( $game, $kind, $target );
		return $layers[0] ?? null;
	}

	/**
	 * A chronicle's copies of the book's blocks.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function block_layers( object $game, ?string $slug ): array {
		$table = Manager::table( 'schema_blocks' );
		$sql   = "SELECT copy.id, copy.slug, copy.name, copy.definition AS copy_document, copy.fork_changes
			FROM {$table} copy
			INNER JOIN {$table} book ON book.slug = copy.slug AND book.game_slug = ''
			WHERE copy.game_slug = %s";
		$rows  = $slug === null
			? Manager::get_results( $sql, $game->slug )
			: Manager::get_results( $sql . ' AND copy.slug = %s', $game->slug, $slug );
		$names = [];
		foreach ( $rows as $row ) {
			$names[ (string) $row->slug ] = (string) $row->name;
		}
		$labels = Schema_Block::names_with_creature( $names );
		foreach ( $rows as $row ) {
			$row->name          = $labels[ (string) $row->slug ];
			$row->book_document = Schema_Block::under_for_game( (string) $row->slug, (string) $game->slug );
		}
		return self::shape( 'block', $rows, static fn( object $row ): string => (string) $row->slug, false );
	}

	/**
	 * A chronicle's layers over the book's creature types.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function stack_layers( object $game, ?string $slug ): array {
		$table = Manager::table( 'creature_stacks' );
		$sql   = "SELECT own.id, own.slug, own.name, own.stack_definition, own.creation_rules, own.fork_changes,
				book.stack_definition AS book_stack_definition, book.creation_rules AS book_creation_rules
			FROM {$table} own
			INNER JOIN {$table} book ON book.slug = own.slug AND book.game_slug = ''
			WHERE own.game_slug = %s";
		$rows  = $slug === null
			? Manager::get_results( $sql, $game->slug )
			: Manager::get_results( $sql . ' AND own.slug = %s', $game->slug, $slug );
		foreach ( $rows as $row ) {
			$row->copy_document = [ 'stack_definition' => self::decode( $row->stack_definition ), 'creation_rules' => self::decode( $row->creation_rules ) ];
			$row->book_document = [ 'stack_definition' => self::decode( $row->book_stack_definition ), 'creation_rules' => self::decode( $row->book_creation_rules ) ];
		}
		return self::shape( 'stack', $rows, static fn( object $row ): string => (string) $row->slug, false );
	}

	/**
	 * A chronicle's templates of a creature type and kind the book also has.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function template_layers( object $game, ?string $id ): array {
		$table = Manager::table( 'templates' );
		$sql   = "SELECT own.id, own.name, own.layout AS copy_document, own.fork_changes, site.layout AS book_document
			FROM {$table} own
			INNER JOIN {$table} site ON site.game_id IS NULL AND site.stack_slug = own.stack_slug AND site.template_type = own.template_type
			WHERE own.game_id = %d";
		$rows  = $id === null
			? Manager::get_results( $sql, (int) $game->id )
			: Manager::get_results( $sql . ' AND own.id = %d', (int) $game->id, (int) $id );
		return self::shape( 'template', $rows, static fn( object $row ): string => (string) (int) $row->id, true );
	}

	/**
	 * Layer rows as layers: each with its kind, target, name, id, the book's document, the chronicle's and its changes,
	 * by name.
	 *
	 * @param array<int,object>        $rows
	 * @param callable(object):string  $target
	 * @param bool                     $removals Whether a layer with no changes recorded counts what it lacks as left out.
	 * @return array<int,array<string,mixed>>
	 */
	private static function shape( string $kind, array $rows, callable $target, bool $removals ): array {
		$layers = [];
		foreach ( $rows as $row ) {
			$book    = self::decode( $row->book_document );
			$copy    = self::decode( $row->copy_document );
			$changes = self::decode( $row->fork_changes );
			$layers[] = [
				'kind'    => $kind,
				'target'  => $target( $row ),
				'name'    => (string) $row->name,
				'id'      => (int) $row->id,
				'book'    => $book,
				'copy'    => $copy,
				'changes' => Fork_Merge::is_by_path( $changes ) ? $changes : Fork_Merge::changes_against( $book, $copy, $removals ),
			];
		}
		usort( $layers, static fn( array $a, array $b ): int => strcasecmp( $a['name'], $b['name'] ) );
		return $layers;
	}

	/**
	 * Writes a layer's document and changes back.
	 *
	 * @param array<string,mixed> $layer
	 * @param array<string,mixed> $document
	 * @param array<string,mixed> $changes
	 */
	private static function store( array $layer, array $document, array $changes ): bool {
		switch ( $layer['kind'] ) {
			case 'block':
				return Schema_Block::store_copy( (int) $layer['id'], $document, $changes );
			case 'stack':
				return Creature_Stack::store_layer( (int) $layer['id'], $document, $changes );
			default:
				return Template::store_layer( (int) $layer['id'], $document, $changes );
		}
	}

	/**
	 * The name of each entry a path steps into, and null for its other steps: a template section's block by its name.
	 *
	 * @param object              $game
	 * @param array<string,mixed> $layer
	 * @param array<int,mixed>    $path
	 * @return array<int,string|null>
	 */
	private static function labels( object $game, array $layer, array $path ): array {
		$labels = Fork_Merge::labels( $layer['copy'], $layer['book'], $path );
		if ( $layer['kind'] === 'template' ) {
			foreach ( $path as $i => $step ) {
				if ( is_array( $step ) && ( $labels[ $i ] ?? null ) === (string) ( $step[0] ?? '' ) ) {
					$block        = Schema_Block::find_for_game( (string) $step[0], (string) $game->slug );
					$labels[ $i ] = $block ? (string) $block->name : $labels[ $i ];
				}
			}
		}
		return $labels;
	}

	/**
	 * A stored JSON document as a plain array.
	 *
	 * @param mixed $value
	 * @return array<string,mixed>
	 */
	private static function decode( $value ): array {
		if ( is_string( $value ) ) {
			$value = json_decode( $value, true );
		}
		return is_array( $value ) ? $value : [];
	}
}
