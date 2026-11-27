<?php

namespace BeyondElysium\Services;

defined( 'ABSPATH' ) || exit;

/**
 * What a draft build amounts to once it is read as the changes it is made of: which picks the chronicle's rules say a
 * Storyteller must approve, and so whether a new character starts active or pending.
 */
class Creation_Review {

	/**
	 * Every pick in the draft sheet that the rules name for a Storyteller, in the creature type's section order.
	 *
	 * @param object               $stack  The resolved creature stack row.
	 * @param array<string,object> $blocks The stack's blocks by slug.
	 * @param array<string,mixed>  $sheet  The draft sheet.
	 * @param object               $game   A games row.
	 * @param array<string,mixed>|null $tally `Creation_Tally::for_stack()` for the same sheet; pools at their rules' start are not picks.
	 * @return array<int,array{section:string,section_label:string,name:string,reason:?string}>
	 */
	public static function needs_storyteller( object $stack, array $blocks, array $sheet, object $game, ?array $tally = null ): array {
		$character = (object) [
			'owner_slug' => (string) $game->slug,
			'stack_slug' => (string) ( $stack->slug ?? '' ),
			'sheet_data' => $sheet,
			'is_npc'     => 0,
		];
		$context  = [ 'blocks' => $blocks, 'stack' => $stack, 'game' => $game ];
		$sections = (array) ( $stack->stack_definition->sections ?? [] );
		$labels   = Creation_Tally::section_labels( $sections, $blocks );
		$starts   = self::starts_from( $tally );

		$found = [];
		foreach ( $sections as $section ) {
			$slug  = (string) ( $section->block_slug ?? '' );
			$block = $blocks[ $slug ] ?? null;
			if ( $block === null || ! isset( $sheet[ $slug ] ) ) {
				continue;
			}
			$label = $labels[ $slug ] ?? $slug;
			foreach ( self::changes_for( $slug, $block, $sheet[ $slug ], $starts ) as $change ) {
				$verdict = Change_Engine::explicit_approval( $character, $change['change'], $context );
				if ( $verdict === null ) {
					continue;
				}
				$reason = $verdict['reason'];
				if ( ( $reason === null || $reason === '' ) && ! empty( $change['change']->change_data['trait']['custom'] ) ) {
					$reason = __( 'A custom entry is priced by a Storyteller.', 'beyond-elysium' );
				}
				$found[] = [
					'section'       => $slug,
					'section_label' => $label,
					'name'          => $change['name'],
					'reason'        => $reason === '' ? null : $reason,
				];
			}
		}
		return $found;
	}

	/**
	 * Whether a new character starts active or pending, and why.
	 *
	 * @param object                                  $game
	 * @param bool                                    $is_manager Whether the person is a Storyteller in the chronicle.
	 * @param bool                                    $joining    Whether the person has no membership yet.
	 * @param array<int,array<string,mixed>>          $needs      `needs_storyteller()`.
	 * @return array{status:string,reasons:string[]}
	 */
	public static function starts( object $game, bool $is_manager, bool $joining, array $needs ): array {
		if ( $is_manager ) {
			return [ 'status' => 'active', 'reasons' => [] ];
		}
		$reasons = [];
		if ( $joining ) {
			$reasons[] = 'join';
		} elseif ( ! empty( $game->settings->require_new_character_approval ) ) {
			$reasons[] = 'chronicle';
		}
		if ( $needs !== [] ) {
			$reasons[] = 'rules';
		}
		return [ 'status' => $reasons === [] ? 'active' : 'pending', 'reasons' => $reasons ];
	}

	/**
	 * Each start the rules gave a pool, by `section.Pool`.
	 *
	 * @param array<string,mixed>|null $tally
	 * @return array<string,float>
	 */
	private static function starts_from( ?array $tally ): array {
		$starts = [];
		foreach ( (array) ( $tally['steps'] ?? [] ) as $step ) {
			if ( ( $step['kind'] ?? '' ) === 'start' && ! empty( $step['applies'] ) && $step['value'] !== null ) {
				$starts[ (string) $step['target'] ] = (float) $step['value'];
			}
		}
		return $starts;
	}

	/**
	 * The changes one section's held value amounts to.
	 *
	 * @param mixed                $held
	 * @param array<string,float>  $starts
	 * @return array<int,array{name:string,change:object}>
	 */
	private static function changes_for( string $slug, object $block, $held, array $starts ): array {
		$changes = [];
		$type    = (string) ( $block->section_type ?? '' );

		if ( $type === 'trait_list' || $type === 'tiered_power' ) {
			foreach ( is_array( $held ) ? $held : [] as $row ) {
				if ( ! is_array( $row ) || ! is_string( $row['name'] ?? null ) || $row['name'] === '' ) {
					continue;
				}
				$name = isset( $row['power_name'] ) && is_string( $row['power_name'] ) && $row['power_name'] !== ''
					? $row['name'] . ' (' . $row['power_name'] . ')'
					: $row['name'];
				$changes[] = [
					'name'   => $name,
					'change' => (object) [ 'change_type' => 'add_trait', 'change_data' => [ 'block_slug' => $slug, 'trait' => $row ] ],
				];
			}
		} elseif ( $type === 'resource_pool' ) {
			foreach ( is_array( $held ) ? $held : [] as $pool_name => $value ) {
				$permanent = is_array( $value ) ? ( $value['permanent'] ?? null ) : $value;
				if ( ! is_numeric( $permanent ) ) {
					continue;
				}
				$start = $starts[ $slug . '.' . $pool_name ] ?? self::default_start( $block, (string) $pool_name );
				if ( (float) $permanent <= $start ) {
					continue;
				}
				$changes[] = [
					'name'   => (string) $pool_name,
					'change' => (object) [ 'change_type' => 'modify_resource', 'change_data' => [ 'block_slug' => $slug, 'values' => [ $pool_name => $value ] ] ],
				];
			}
		} elseif ( $type === 'identity_field' ) {
			foreach ( is_array( $held ) ? $held : [] as $field_name => $value ) {
				if ( $value === null || $value === '' || $value === [] ) {
					continue;
				}
				$shown     = is_array( $value ) ? implode( ', ', array_map( 'strval', $value ) ) : (string) $value;
				$changes[] = [
					'name'   => $field_name . ': ' . $shown,
					'change' => (object) [ 'change_type' => 'modify_identity', 'change_data' => [ 'block_slug' => $slug, 'fields' => [ $field_name => $value ] ] ],
				];
			}
		}
		return $changes;
	}

	private static function default_start( object $block, string $pool_name ): float {
		foreach ( (array) ( $block->definition->pools ?? [] ) as $pool ) {
			if ( (string) ( $pool->name ?? '' ) === $pool_name ) {
				return (float) ( $pool->default_start ?? 0 );
			}
		}
		return 0.0;
	}
}
