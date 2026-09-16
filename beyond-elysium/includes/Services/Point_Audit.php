<?php

namespace BeyondElysium\Services;

use BeyondElysium\Models\Character;
use BeyondElysium\Models\Creature_Stack;
use BeyondElysium\Models\Schema_Block;

defined( 'ABSPATH' ) || exit;

/**
 * The itemised point audit (point-calculator-design.md). Resolves blocks,
 * walks every held entry, and calls `Cost_Engine`'s held-state pricing
 * functions - zero arithmetic of its own (§5.1). "Pricing lives in exactly
 * one place" (`Cost_Engine`'s own class docblock): every number this class
 * prints came out of `Cost_Engine`, so a previewed purchase and an audit
 * line can never disagree.
 *
 * A full-sheet total can never be presented as complete (§0, §4.5) - 71.8%
 * of real held lines could not be priced at the time this was measured.
 * `complete` is hardcoded `false` with no branch that can produce `true`.
 *
 * Callers must gate this on `be_manage_characters`, never a narrower
 * capability (§5.5): this class reads the character's full, unredacted
 * `sheet_data`, including Storyteller-only blocks, and a total computed
 * across them leaks their values arithmetically to anyone who can difference
 * the result against the public catalog. `Reports_Controller`-style REST
 * gating is the control, not a filter inside this class.
 *
 * @see BE_PROCESS/design/point-calculator-design.md
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
		$blocks        = Schema_Block::find_by_slugs_for_game( $union_slugs, (string) ( $character->owner_slug ?? '' ) );

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
	 * §5.3's union walk: the stack's own declared sections (in display_order,
	 * a negative_block_slug walked immediately after its own section), then
	 * any held-but-undeclared slug last, flagged `undeclared` - the §3.2
	 * detector. Returns walk order only; block lookup happens once, in bulk,
	 * by the caller.
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
				return self::trait_list_lines( $definition, $entry, is_array( $held ) ? $held : [] );
			case 'tiered_power':
				$in_type = Cost_Engine::in_type_check( $character, $block->slug ?? $entry['slug'], $stack, $blocks );
				return self::tiered_power_lines( $in_type, $definition, $entry, is_array( $held ) ? $held : [] );
			case 'resource_pool':
				return self::resource_pool_lines( $definition, $entry, is_array( $held ) ? $held : [] );
			case 'identity_field':
				return self::identity_field_lines( $definition, $entry, is_array( $held ) ? $held : [] );
			default:
				return [];
		}
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	private static function trait_list_lines( object $definition, array $entry, array $held_list ): array {
		$lines = [];
		foreach ( $held_list as $held ) {
			$held  = (array) $held;
			$price = Cost_Engine::price_held_trait_list_item( $definition, $held );
			$name  = (string) ( $held['name'] ?? '?' );
			$count = (int) ( $held['count'] ?? 1 );

			$lines[] = [
				'block_slug'          => $entry['slug'],
				'section_label'       => $entry['label'],
				'section_type'        => 'trait_list',
				'label'               => $count > 1 ? sprintf( '%s ×%d', $name, $count ) : $name,
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
	 * @param callable(string):bool $is_in_type The block's in-type check, looked up once for every held power (F-087).
	 * @return array<int,array<string,mixed>>
	 */
	private static function tiered_power_lines( callable $is_in_type, object $definition, array $entry, array $held_list ): array {
		$lines = [];
		foreach ( $held_list as $held ) {
			$held       = (array) $held;
			$trait_name = (string) ( $held['name'] ?? '' );
			$in_type    = $trait_name !== '' ? $is_in_type( $trait_name ) : true;
			$price      = Cost_Engine::price_held_tiered_power( $definition, $held, $in_type );

			$label = $trait_name;
			if ( ! empty( $held['power_name'] ) ) {
				$label = $trait_name . ': ' . $held['power_name'];
			} elseif ( ! empty( $held['level'] ) ) {
				$label = $trait_name . ' ' . (int) $held['level'];
			}
			// Blood Magic's own display rule (0.99.2-workflow.md: "Tradition: PathName"),
			// matching TieredPowerRenderer.tsx's withTradition() exactly so a blood-magic
			// path reads the same on the audit as on the sheet (§5.6).
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
	 * Every declared pool gets its own line, held or not (§4.3) - an unheld
	 * pool still exists on the character at its `default_start`.
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
	 * Every declared field is `unpriced` - never `0`, never omitted (§4.4).
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
	 * An unpriced line's reason in words - the same words `PointAudit.tsx` shows beside each
	 * line, so one translation serves the line and the summary (1.0.0-review F-085). A reason
	 * with no label yet reads as its key with spaces.
	 */
	public static function reason_label( string $reason ): string {
		$labels = [
			'catalog_item_has_no_cost'       => __( 'catalog item has no cost', 'beyond-elysium' ),
			'name_not_in_catalog'            => __( 'name not in catalog', 'beyond-elysium' ),
			'family_not_in_catalog'          => __( 'family not in catalog', 'beyond-elysium' ),
			'level_has_no_cost'              => __( 'level has no cost', 'beyond-elysium' ),
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
			// Unreachable against any real character today (§0) - kept honest rather than
			// asserting completeness even in the theoretical case every line prices.
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
