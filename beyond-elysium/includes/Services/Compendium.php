<?php

namespace BeyondElysium\Services;

use BeyondElysium\Models\Creature_Stack;

defined( 'ABSPATH' ) || exit;

/**
 * The chronicle's catalog as a member may read it, a creature type and a section at a time: names, costs, the book an
 * entry comes from and the chronicle's own notes, never any book text.
 */
class Compendium {

	/**
	 * The most names a search returns.
	 */
	public const SEARCH_LIMIT = 50;

	/**
	 * The creature types the chronicle has on and the person may see.
	 *
	 * @return array<int,array{slug:string,name:string}>
	 */
	public static function types( object $game, bool $can_manage ): array {
		return array_map(
			static fn( $stack ) => [ 'slug' => (string) $stack->slug, 'name' => (string) $stack->name ],
			Creature_Stack::all_for_game( (string) $game->slug, [], false, $can_manage )
		);
	}

	/**
	 * The sections of one creature type that hold entries, each with how many.
	 *
	 * @return array{slug:string,name:string,sections:array<int,array<string,mixed>>}|null Null when the type is not on or the person may not see it.
	 */
	public static function sections( object $game, string $stack_slug, bool $can_manage ): ?array {
		$view = self::view( $game, $stack_slug, $can_manage );
		if ( $view === null ) {
			return null;
		}

		$sections = [];
		foreach ( $view['sections'] as $section ) {
			$sections[] = [
				'block_slug' => $section['block']->slug,
				'label'      => $section['label'],
				'kind'       => $section['block']->section_type === 'tiered_power' ? 'powers' : 'items',
				'count'      => count( self::rows( $section['block'] ) ),
			];
		}
		return [ 'slug' => (string) $view['stack']->slug, 'name' => (string) $view['stack']->name, 'sections' => $sections ];
	}

	/**
	 * One section's entries.
	 *
	 * @return array{block_slug:string,label:string,kind:string,entries:array<int,array<string,mixed>>}|null
	 */
	public static function entries( object $game, string $stack_slug, string $block_slug, bool $can_manage ): ?array {
		$view = self::view( $game, $stack_slug, $can_manage );
		if ( $view === null ) {
			return null;
		}
		foreach ( $view['sections'] as $section ) {
			if ( $section['block']->slug !== $block_slug ) {
				continue;
			}
			$powers = $section['block']->section_type === 'tiered_power';
			return [
				'block_slug' => $block_slug,
				'label'      => $section['label'],
				'kind'       => $powers ? 'powers' : 'items',
				'entries'    => array_map(
					static fn( $row ) => $powers ? self::family( $row ) : self::item( $row ),
					self::rows( $section['block'] )
				),
			];
		}
		return null;
	}

	/**
	 * Names across a creature type's sections that hold the words searched for.
	 *
	 * @return array<int,array{block_slug:string,label:string,name:string,name_pt:?string,family:?string}>|null
	 */
	public static function search( object $game, string $stack_slug, string $query, bool $can_manage ): ?array {
		$view = self::view( $game, $stack_slug, $can_manage );
		if ( $view === null ) {
			return null;
		}
		$query = trim( $query );
		if ( mb_strlen( $query ) < 2 ) {
			return [];
		}

		$found = [];
		foreach ( $view['sections'] as $section ) {
			$powers = $section['block']->section_type === 'tiered_power';
			foreach ( self::rows( $section['block'] ) as $row ) {
				if ( self::matches( $row, $query ) ) {
					$found[] = [
						'block_slug' => (string) $section['block']->slug,
						'label'      => $section['label'],
						'name'       => (string) $row->name,
						'name_pt'    => isset( $row->name_pt ) ? (string) $row->name_pt : null,
						'family'     => null,
					];
				}
				if ( $powers ) {
					foreach ( Power_Levels::all( $row ) as $level ) {
						if ( self::matches( $level, $query, 'power_name' ) ) {
							$found[] = [
								'block_slug' => (string) $section['block']->slug,
								'label'      => $section['label'],
								'name'       => (string) $level->power_name,
								'name_pt'    => isset( $level->power_name_pt ) ? (string) $level->power_name_pt : null,
								'family'     => (string) $row->name,
							];
						}
					}
				}
				if ( count( $found ) >= self::SEARCH_LIMIT ) {
					return array_slice( $found, 0, self::SEARCH_LIMIT );
				}
			}
		}
		return $found;
	}

	/**
	 * A creature type the person may see, with the sections of it they may read, their blocks cleared of Storyteller
	 * text.
	 *
	 * @return array{stack:object,sections:array<int,array{label:string,block:object}>}|null
	 */
	private static function view( object $game, string $stack_slug, bool $can_manage ): ?array {
		$allowed = array_column( self::types( $game, $can_manage ), 'slug' );
		if ( ! in_array( $stack_slug, $allowed, true ) ) {
			return null;
		}
		$resolved = Creature_Stack::resolve( $stack_slug, (string) $game->slug );
		if ( $resolved === null ) {
			return null;
		}

		St_Visibility::filter_catalog_blocks_for_viewer( $resolved['blocks'], (string) $game->slug );

		$sections = [];
		foreach ( (array) ( $resolved['stack']->stack_definition->sections ?? [] ) as $section ) {
			$block = $resolved['blocks'][ (string) ( $section->block_slug ?? '' ) ] ?? null;
			if ( $block === null || ! in_array( $block->section_type, [ 'trait_list', 'tiered_power' ], true ) ) {
				continue;
			}
			if ( ! empty( $section->hidden ) && ! $can_manage ) {
				continue;
			}
			if ( ! empty( $block->storyteller_only ) && ! $can_manage ) {
				continue;
			}
			if ( self::rows( $block ) === [] ) {
				continue;
			}
			$sections[] = [ 'label' => Catalog_Labels::word( (string) ( $section->label ?? $block->name ) ), 'block' => $block ];
		}
		return [ 'stack' => $resolved['stack'], 'sections' => $sections ];
	}

	/**
	 * A block's items, or a power block's families.
	 *
	 * @return array<int,object>
	 */
	private static function rows( object $block ): array {
		$definition = $block->definition ?? null;
		if ( ! is_object( $definition ) ) {
			return [];
		}
		$rows = $block->section_type === 'tiered_power' ? ( $definition->powers ?? [] ) : ( $definition->items ?? [] );
		return is_array( $rows ) ? array_values( array_filter( $rows, 'is_object' ) ) : [];
	}

	/**
	 * Whether a name holds the words searched for, in any case.
	 */
	private static function matches( object $row, string $query, string $key = 'name' ): bool {
		foreach ( [ $key, $key . '_pt' ] as $field ) {
			if ( isset( $row->$field ) && is_string( $row->$field ) && mb_stripos( $row->$field, $query ) !== false ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * One list item as the compendium shows it.
	 *
	 * @return array<string,mixed>
	 */
	private static function item( object $item ): array {
		return [
			'name'          => (string) $item->name,
			'name_pt'       => isset( $item->name_pt ) ? (string) $item->name_pt : null,
			'group'         => $item->group ?? null,
			'subgroup'      => $item->subgroup ?? null,
			'tier'          => $item->tier ?? null,
			'cost'          => isset( $item->cost ) ? (string) $item->cost : null,
			'book'          => Catalog_Citation::book_of( $item->source ?? null ),
			'description'   => self::description( $item ),
			'citation'      => self::citation( $item ),
			'prerequisites' => is_array( $item->prerequisites ?? null ) ? $item->prerequisites : [],
		];
	}

	/**
	 * One power family with every level it holds.
	 *
	 * @return array<string,mixed>
	 */
	private static function family( object $family ): array {
		return [
			'name'        => (string) $family->name,
			'name_pt'     => isset( $family->name_pt ) ? (string) $family->name_pt : null,
			'group'       => $family->group ?? null,
			'book'        => Catalog_Citation::book_of( $family->source ?? null ),
			'description' => self::description( $family ),
			'citation'    => self::citation( $family ),
			'levels'      => array_map(
				static fn( $level ) => [
					'level'         => $level->level ?? null,
					'tier'          => $level->tier ?? null,
					'power_name'    => (string) ( $level->power_name ?? '' ),
					'power_name_pt' => isset( $level->power_name_pt ) ? (string) $level->power_name_pt : null,
					'cost'          => isset( $level->cost ) ? (string) $level->cost : null,
					'description'   => self::description( $level ),
				],
				Power_Levels::all( $family )
			),
		];
	}

	/**
	 * The chronicle's own note on an entry, as html, or null.
	 */
	private static function description( object $row ): ?string {
		$text = is_object( $row->description ?? null ) ? ( $row->description->description ?? null ) : null;
		return is_string( $text ) && $text !== '' ? $text : null;
	}

	/**
	 * Where the chronicle's note says an entry is described: its source and reference as entered.
	 */
	private static function citation( object $row ): string {
		$note = is_object( $row->description ?? null ) ? $row->description : null;
		if ( $note === null ) {
			return '';
		}
		return implode( ', ', array_filter( [ (string) ( $note->source ?? '' ), (string) ( $note->reference ?? '' ) ] ) );
	}
}
