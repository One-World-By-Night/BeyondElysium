<?php

namespace BeyondElysium\Services;

use BeyondElysium\Models\Character;
use BeyondElysium\Models\Creature_Stack;
use BeyondElysium\Models\Schema_Block;

defined( 'ABSPATH' ) || exit;

/**
 * The itemised point audit.
 */
class Point_Audit {

	/**
	 * @return array<string,mixed>|null Null when the character does not resolve.
	 */
	public static function for_character( int $character_id ): ?array {
		$character = Character::find( $character_id );
		if ( $character === null ) {
			return null;
		}

		$stack = Creature_Stack::find_by_slug( $character->stack_slug );
		if ( $stack === null ) {
			return null;
		}

		$sheet_data = is_array( $character->sheet_data ) ? $character->sheet_data : [];

		$walk_entries  = self::build_walk_entries( $stack->stack_definition->sections ?? [], array_keys( $sheet_data ) );
		$union_slugs   = array_column( $walk_entries, 'slug' );
		$blocks        = Purchase_Scope::widen_blocks( Schema_Block::find_by_slugs_for_game( $union_slugs, (string) ( $character->owner_slug ?? '' ) ), (string) ( $character->owner_slug ?? '' ) );

		$lines = [];
		foreach ( $walk_entries as $entry ) {
			$block = $blocks[ $entry['slug'] ] ?? null;
			$held  = $sheet_data[ $entry['slug'] ] ?? null;

			if ( $block === null ) {
				if ( $held === null || $held === [] ) {
					continue; // Declared but never held and no block to speak of - nothing to report.
				}
				$lines[] = [
					'block_slug'      => $entry['slug'],
					'section_label'   => $entry['label'],
					'section_type'    => null,
					'label'           => $entry['label'],
					'xp'              => null,
					'direction'       => 'spent',
					'basis'           => null,
					'unpriced_reason' => 'held_block_not_in_catalog',
					'modifier'        => null,
					'undeclared_by_stack' => $entry['undeclared'],
				];
				continue;
			}

			foreach ( self::lines_for_block( $character, $block, $entry, $held, $stack, $blocks ) as $line ) {
				$lines[] = $line;
			}
		}

		return self::build_envelope( $character, $lines );
	}

	/**
	 * The union walk: the stack's own declared sections in display_order, with a negative_block_slug walked right after
	 * its own section, then any held-but-undeclared slug last, flagged `undeclared`. Returns walk order only.
	 *
	 * @param array<int,object> $sections
	 * @param string[]          $held_slugs
	 * @return array<int,array{slug:string,label:string,undeclared:bool}>
	 */
	private static function build_walk_entries( array $sections, array $held_slugs ): array {
		$declared = [];
		foreach ( $sections as $section ) {
			$order              = (int) ( $section->display_order ?? 0 );
			$block_slug         = (string) ( $section->block_slug ?? '' );
			$negative_block_slug = (string) ( $section->negative_block_slug ?? '' );
			$section_label      = (string) ( $section->label ?? $block_slug );

			if ( $block_slug !== '' ) {
				$declared[] = [ 'slug' => $block_slug, 'label' => $section_label, 'order' => $order, 'undeclared' => false ];
			}
			if ( $negative_block_slug !== '' ) {
				$declared[] = [ 'slug' => $negative_block_slug, 'label' => $section_label . ' (negative)', 'order' => $order, 'undeclared' => false ];
			}
		}
		usort( $declared, static fn( $a, $b ) => $a['order'] <=> $b['order'] );

		$declared_slugs = array_column( $declared, 'slug' );
		$undeclared     = [];
		foreach ( $held_slugs as $slug ) {
			if ( ! in_array( $slug, $declared_slugs, true ) ) {
				$undeclared[] = [ 'slug' => $slug, 'label' => $slug, 'order' => PHP_INT_MAX, 'undeclared' => true ];
			}
		}

		return array_merge( $declared, $undeclared );
	}

	/**
	 * @param array{slug:string,label:string,undeclared:bool} $entry
	 * @param array<string,object>                           $blocks The character's blocks by slug, already loaded.
	 * @return array<int,array<string,mixed>>
	 */
	private static function lines_for_block( object $character, object $block, array $entry, $held, object $stack, array $blocks ): array {
		$definition = is_object( $block->definition ?? null ) ? $block->definition : (object) [];

		switch ( $block->section_type ) {
			case 'trait_list':
				return self::trait_list_lines( $definition, $entry, is_array( $held ) ? $held : [], $blocks );
			case 'tiered_power':
				$in_type = Cost_Engine::in_type_check( $character, $block->slug ?? $entry['slug'], $stack, $blocks );
				return self::tiered_power_lines( $in_type, $definition, $entry, is_array( $held ) ? $held : [], $blocks );
			case 'resource_pool':
				return self::resource_pool_lines( $definition, $entry, is_array( $held ) ? $held : [] );
			case 'identity_field':
				return self::identity_field_lines( $definition, $entry, is_array( $held ) ? $held : [] );
			default:
				return [];
		}
	}

	/**
	 * Builds the audit lines for a trait_list section.
	 *
	 * @param array<string,object> $blocks The character's blocks by slug, already loaded - threaded through `moved_from` cross-block resolution.
	 * @return array<int,array<string,mixed>>
	 */
	private static function trait_list_lines( object $definition, array $entry, array $held_list, array $blocks = [] ): array {
		$lines = [];
		foreach ( $held_list as $held ) {
			$held  = (array) $held;
			$price = Cost_Engine::price_held_trait_list_item( $definition, $held, $entry['slug'], $blocks );
			$name  = (string) ( $held['name'] ?? '?' );
			$count = (int) ( $held['count'] ?? 1 );

			$lines[] = [
				'block_slug'          => $entry['slug'],
				'section_label'       => $entry['label'],
				'section_type'        => 'trait_list',
				'label'               => ! empty( $definition->count_is_cost )
					? sprintf( '%s (%d XP)', $name, $count )
					: ( $count > 1 ? sprintf( '%s ×%d', $name, $count ) : $name ),
				'xp'                  => $price['xp'],
				'direction'           => ! empty( $definition->negative ) ? 'earned' : 'spent',
				'basis'               => $price['basis'],
				'unpriced_reason'     => $price['unpriced_reason'],
				'modifier'            => null,
				'undeclared_by_stack' => $entry['undeclared'],
			];
		}
		return $lines;
	}

	/**
	 * Builds the audit lines for a tiered_power section.
	 *
	 * @param callable(string):bool $is_in_type The block's in-type check, looked up once for every held power.
	 * @param array<string,object> $blocks The character's blocks by slug, already loaded - threaded through `moved_from` cross-block resolution.
	 * @return array<int,array<string,mixed>>
	 */
	private static function tiered_power_lines( callable $is_in_type, object $definition, array $entry, array $held_list, array $blocks = [] ): array {
		$lines = [];
		foreach ( $held_list as $held ) {
			$held       = (array) $held;
			$trait_name = (string) ( $held['name'] ?? '' );
			$in_type    = $trait_name !== '' ? $is_in_type( $trait_name ) : true;
			$price      = Cost_Engine::price_held_tiered_power( $definition, $held, $in_type, $entry['slug'], $blocks );

			$label = $trait_name;
			if ( ! empty( $held['power_name'] ) ) {
				$label = $trait_name . ': ' . $held['power_name'];
			} elseif ( ! empty( $held['level'] ) ) {
				$label = $trait_name . ' ' . (int) $held['level'];
			}
			// Blood Magic reads as "Tradition: PathName", as on the sheet.
			if ( ! empty( $held['tradition'] ) ) {
				$label = $held['tradition'] . ': ' . $label;
			}

			$modifier = null;
			if ( ! $in_type && $price['xp'] !== null && in_array( $price['basis'], [ 'flat_level', 'sequential_sum', 'elder_pick', 'tier_fallback' ], true ) ) {
				$modifier = (int) ( $definition->out_of_type_cost_modifier ?? 0 );
			}

			$lines[] = [
				'block_slug'          => $entry['slug'],
				'section_label'       => $entry['label'],
				'section_type'        => 'tiered_power',
				'label'               => $label,
				'xp'                  => $price['xp'],
				'direction'           => 'spent',
				'basis'               => $price['basis'],
				'unpriced_reason'     => $price['unpriced_reason'],
				'modifier'            => $modifier,
				'undeclared_by_stack' => $entry['undeclared'],
			];
		}
		return $lines;
	}

	/**
	 * Every declared pool gets its own line, held or not.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function resource_pool_lines( object $definition, array $entry, array $held_map ): array {
		$lines = [];
		foreach ( ( $definition->pools ?? [] ) as $pool ) {
			$name  = (string) ( $pool->name ?? '' );
			$value = $held_map[ $name ] ?? ( $pool->default_start ?? 0 );
			$price = Cost_Engine::price_held_resource_pool( $definition, $name, $value );

			$permanent = is_array( $value ) ? (int) ( $value['permanent'] ?? 0 ) : (int) $value;

			$lines[] = [
				'block_slug'          => $entry['slug'],
				'section_label'       => $entry['label'],
				'section_type'        => 'resource_pool',
				'label'               => sprintf( '%s (%d)', $name, $permanent ),
				'xp'                  => $price['xp'],
				'direction'           => 'spent',
				'basis'               => $price['basis'],
				'unpriced_reason'     => $price['unpriced_reason'],
				'modifier'            => null,
				'undeclared_by_stack' => $entry['undeclared'],
			];
		}
		return $lines;
	}

	/**
	 * Every declared field is `unpriced`.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function identity_field_lines( object $definition, array $entry, array $held_map ): array {
		$lines = [];
		foreach ( ( $definition->fields ?? [] ) as $field ) {
			$name  = (string) ( $field->name ?? '' );
			$value = $held_map[ $name ] ?? null;

			$lines[] = [
				'block_slug'          => $entry['slug'],
				'section_label'       => $entry['label'],
				'section_type'        => 'identity_field',
				'label'               => $name . ': ' . ( ( $value === null || $value === '' ) ? '—' : (string) $value ),
				'xp'                  => null,
				'direction'           => 'spent',
				'basis'               => null,
				'unpriced_reason'     => 'identity_field_no_catalog_cost',
				'modifier'            => null,
				'undeclared_by_stack' => $entry['undeclared'],
			];
		}
		return $lines;
	}

	/**
	 * @param array<int,array<string,mixed>> $lines
	 * @return array<string,mixed>
	 */
	private static function build_envelope( object $character, array $lines ): array {
		$spent_total   = 0;
		$earned_total  = 0;
		$priced_lines  = 0;
		$unpriced_by_reason = [];

		foreach ( $lines as $line ) {
			if ( $line['xp'] === null ) {
				$reason = $line['unpriced_reason'] ?? 'unknown';
				$unpriced_by_reason[ $reason ] = ( $unpriced_by_reason[ $reason ] ?? 0 ) + 1;
				continue;
			}

			$priced_lines++;
			if ( $line['direction'] === 'earned' ) {
				$earned_total += abs( $line['xp'] );
			} else {
				$spent_total += $line['xp'];
			}
		}

		$net_total          = $spent_total - $earned_total;
		$xp_spent_of_record = (int) ( $character->xp_earned ?? 0 ) - (int) ( $character->xp_unspent ?? 0 );
		$variance           = $net_total - $xp_spent_of_record;
		$unpriced_lines     = count( $lines ) - $priced_lines;

		return [
			'character_id'    => (int) $character->id,
			'lines'           => $lines,
			'spent_total'     => $spent_total,
			'earned_total'    => $earned_total,
			'net_total'       => $net_total,
			'coverage'        => [
				'priced_lines'       => $priced_lines,
				'unpriced_lines'     => $unpriced_lines,
				'unpriced_by_reason' => $unpriced_by_reason,
			],
			'xp_spent_of_record' => $xp_spent_of_record,
			'variance'           => $variance,
			'complete'           => false,
			'caveat'             => self::caveat( $unpriced_lines, $unpriced_by_reason ),
		];
	}

	/**
	 * An unpriced line's reason in words.
	 */
	public static function reason_label( string $reason ): string {
		$labels = [
			'catalog_item_has_no_cost'       => __( 'catalog item has no cost', 'beyond-elysium' ),
			'name_not_in_catalog'            => __( 'name not in catalog', 'beyond-elysium' ),
			'family_not_in_catalog'          => __( 'family not in catalog', 'beyond-elysium' ),
			'level_has_no_cost'              => __( 'level has no cost', 'beyond-elysium' ),
			'level_above_ceiling_no_pick_rank' => __( 'held above the ladder, no priced rank above it yet', 'beyond-elysium' ),
			'custom_no_catalog_entry'        => __( 'custom, no catalog entry', 'beyond-elysium' ),
			'identity_field_no_catalog_cost' => __( 'identity field, no catalog cost', 'beyond-elysium' ),
			'resource_pool_no_pricing_rule'  => __( 'resource pool has no pricing rule yet', 'beyond-elysium' ),
			'held_block_not_in_catalog'      => __( 'held block not in catalog', 'beyond-elysium' ),
		];
		return $labels[ $reason ] ?? str_replace( '_', ' ', $reason );
	}

	/**
	 * @param array<string,int> $unpriced_by_reason
	 */
	private static function caveat( int $unpriced_lines, array $unpriced_by_reason ): string {
		if ( $unpriced_lines === 0 ) {
			return __( 'Every line on this sheet priced - the total is still not a bill.', 'beyond-elysium' );
		}

		arsort( $unpriced_by_reason );
		$top_reason = array_key_first( $unpriced_by_reason );

		return sprintf(
			/* translators: 1: number of unpriced lines, 2: the single most common reason */
			__( '%1$d line(s) could not be priced (most commonly: %2$s). This total is not a bill.', 'beyond-elysium' ),
			$unpriced_lines,
			self::reason_label( (string) $top_reason )
		);
	}
}
