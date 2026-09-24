<?php

namespace BeyondElysium\Services;

use BeyondElysium\Models\Creature_Stack;

defined( 'ABSPATH' ) || exit;

/**
 * Builds a default section layout for a creature stack that has no authored template.
 */
class Layout_Generator {

	/**
	 * Generates a layout for a creature stack.
	 *
	 * @param string $stack_slug
	 * @return array|null Layout array, or null if the stack does not exist.
	 */
	public static function generate_for_stack( string $stack_slug ): ?array {
		$resolved = Creature_Stack::resolve( $stack_slug );
		if ( ! $resolved ) {
			return null;
		}

		return self::generate( $resolved['stack'], $resolved['blocks'] );
	}

	/**
	 * Builds a layout from an already-resolved stack and its schema blocks.
	 *
	 * @param object $stack  A creature_stacks row with `stack_definition` decoded.
	 * @param array  $blocks Schema block rows keyed by slug (Schema_Block::find_by_slugs()).
	 * @return array
	 */
	public static function generate( object $stack, array $blocks ): array {
		$sections = $stack->stack_definition->sections ?? [];

		// Sort sections by display_order.
		usort( $sections, static function ( $a, $b ): int {
			return ( $a->display_order ?? 0 ) <=> ( $b->display_order ?? 0 );
		} );

		$identity      = [];
		$pools         = [];
		$distributable = [];

		foreach ( $sections as $section ) {
			$slug = $section->block_slug ?? null;
			if ( ! $slug || ! isset( $blocks[ $slug ] ) ) {
				continue;
			}

			$block = $blocks[ $slug ];
			$entry = [ 'section' => $section, 'block' => $block ];

			switch ( $block->section_type ) {
				case 'identity_field':
					$identity[] = $entry;
					break;
				case 'resource_pool':
					$pools[] = $entry;
					break;
				default:
					$distributable[] = $entry;
			}
		}

		$out_sections = [];
		$order        = 1;

		foreach ( array_merge( $identity, $pools ) as $entry ) {
			$out_sections[] = self::build_section( $entry, 1, $order++ );
		}

		// Balance columns 2 and 3 by running item count, assigning in stack section order.
		$col_order = [ 2 => 1, 3 => 1 ];
		$col_load  = [ 2 => 0, 3 => 0 ];

		foreach ( $distributable as $entry ) {
			$column = $col_load[2] <= $col_load[3] ? 2 : 3;

			$out_sections[] = self::build_section( $entry, $column, $col_order[ $column ]++ );
			$col_load[ $column ] += self::item_count( $entry['block'] );
		}

		return [
			'version'  => 1,
			'columns'  => 3,
			'sections' => $out_sections,
		];
	}

	/**
	 * Builds one output layout section entry for a given column and order position.
	 *
	 * @param array{section:object,block:object} $entry
	 */
	private static function build_section( array $entry, int $column, int $order ): array {
		return [
			'block_slug' => $entry['block']->slug,
			'column'     => $column,
			'order'      => $order,
			'title'      => $entry['section']->label ?? $entry['block']->name,
			'display'    => null,
			'collapsed'  => false,
		];
	}

	/**
	 * Counts the items held by a block's definition, checking both the `items` key (trait_list blocks) and the `powers`
	 * key (tiered_power blocks).
	 *
	 * @param object $block
	 * @return int
	 */
	private static function item_count( object $block ): int {
		$definition = $block->definition ?? null;
		if ( ! $definition ) {
			return 0;
		}
		if ( isset( $definition->items ) ) {
			return count( (array) $definition->items );
		}
		if ( isset( $definition->powers ) ) {
			return count( (array) $definition->powers );
		}
		return 0;
	}
}
