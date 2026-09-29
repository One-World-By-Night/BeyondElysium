<?php

namespace BeyondElysium\Database;

use BeyondElysium\Models\Schema_Block;
use BeyondElysium\Models\Creature_Stack;
use BeyondElysium\Models\Template;
use BeyondElysium\Services\Layout_Generator;

defined( 'ABSPATH' ) || exit;

/**
 * Seeds the plugin's system schema blocks, creature stacks, default character-sheet templates and demo characters
 * from the declared catalog.
 */
class Seeder {

	// ---------------------------------------------------------------------------
	// PUBLIC ENTRY POINTS
	// ---------------------------------------------------------------------------

	/**
	 * Seeds every system schema block from the declared catalog, records which variants each base block has, then
	 * rebuilds every chronicle's copy over the book as it now stands.
	 */
	public static function seed_schema_blocks(): void {
		$blocks = self::get_blocks_to_seed();
		update_option( Schema_Block::VARIANTS_OPTION, \BeyondElysium\Services\Catalog_Reader::variants(), false );

		$refresh = [];
		foreach ( $blocks as $block ) {
			$existing = Schema_Block::find_by_slug( $block['slug'] );

			if ( ! $existing ) {
				Schema_Block::create( $block );
				continue;
			}

			if ( (int) $existing->is_system === 1 ) {
				Schema_Block::update( $block['slug'], $block );
				$refresh[] = $block['slug'];
			}
		}

		foreach ( $refresh as $slug ) {
			Schema_Block::refresh_forks( $slug );
		}
	}

	/**
	 * Seeds every system creature stack from the declared catalog.
	 */
	public static function seed_creature_stacks(): void {
		$stacks = self::get_stacks_to_seed();

		foreach ( $stacks as $stack ) {
			$existing = Creature_Stack::find_by_slug( $stack['slug'] );

			if ( ! $existing ) {
				Creature_Stack::create( $stack );
				continue;
			}

			if ( (int) $existing->is_system === 1 ) {
				Creature_Stack::update( $stack['slug'], $stack );
				Creature_Stack::refresh_layers( $stack['slug'] );
			}
		}
	}

	// ---------------------------------------------------------------------------
	// RECONCILIATION
	// ---------------------------------------------------------------------------

	/**
	 * Verifies that every block referenced by a creature stack actually exists.
	 *
	 * @return string[] Unresolved "stack -> block" references; empty when healthy.
	 */
	public static function reconcile_stack_blocks(): array {
		$stacks = Creature_Stack::all();
		$missing = [];

		foreach ( $stacks as $stack ) {
			$definition = is_string( $stack->stack_definition )
				? json_decode( $stack->stack_definition, true )
				: $stack->stack_definition;

			if ( ! is_array( $definition ) || empty( $definition['sections'] ) ) {
				continue;
			}

			foreach ( $definition['sections'] as $section ) {
				foreach ( [ 'block_slug', 'negative_block_slug' ] as $key ) {
					if ( empty( $section[ $key ] ) ) {
						continue;
					}
					if ( ! Schema_Block::find_by_slug( $section[ $key ] ) ) {
						$missing[] = $stack->slug . ' -> ' . $section[ $key ];
					}
				}
			}
		}

		if ( $missing ) {
			error_log(
				'Beyond Elysium: creature stacks reference blocks that were not seeded: '
				. implode( '; ', $missing )
			);
		}

		return $missing;
	}

	// ---------------------------------------------------------------------------
	// BLOCK SEED DATA
	// ---------------------------------------------------------------------------

	/**
	 * Every schema block the plugin seeds: the declared catalog's blocks.
	 *
	 * @return array[]
	 */
	public static function get_blocks_to_seed(): array {
		return array_values( \BeyondElysium\Services\Catalog_Reader::blocks_to_seed() );
	}

	/**
	 * Resolves the human-readable label for a block slug.
	 *
	 * @param string $slug
	 * @return string
	 */
	private static function block_label( string $slug ): string {
		$labels = [
			'met-physical-traits'       => 'Physical Traits (Positive)',
			'met-physical-traits-neg'   => 'Physical Traits (Negative)',
			'met-social-traits'         => 'Social Traits (Positive)',
			'met-social-traits-neg'     => 'Social Traits (Negative)',
			'met-mental-traits'         => 'Mental Traits (Positive)',
			'met-mental-traits-neg'     => 'Mental Traits (Negative)',
			'met-derangements'          => 'Derangements',
			'vampire-disciplines'       => 'Disciplines',
			'vampire-rituals'           => 'Rituals',
			'vampire-combo-disciplines' => 'Combo Disciplines',
			'vampire-ritae'             => 'Ritae',
			'vampire-statuses'          => 'Vampire Status',
			'werewolf-gifts'            => 'Werewolf Gifts',
			'fera-gifts'                => 'Gifts',
			'werewolf-rites'            => 'Werewolf Rites',
			'mage-spheres'              => 'Mage Spheres',
			'mage-rotes'                => 'Mage Rotes',
			'changeling-arts'           => 'Changeling Arts',
			'changeling-realms'         => 'Changeling Realms',
			'wraith-arcanoi'            => 'Wraith Arcanoi',
			'mummy-hekau'               => 'Mummy Hekau',
			'kueijin-disciplines'       => 'Kuei-Jin Disciplines',
			'met-archetypes'            => 'Archetypes',
			'vampire-identity'          => 'Identity',
			'vampire-resources'         => 'Resources',
			'vampire-virtues'           => 'Virtues',
			'werewolf-identity'         => 'Identity',
			'werewolf-resources'        => 'Resources',
			'werewolf-renown'           => 'Renown',
			'mage-identity'             => 'Identity',
			'mage-resources'            => 'Resources',
			'changeling-identity'       => 'Identity',
			'changeling-resources'      => 'Resources',
			'wraith-identity'           => 'Identity',
			'wraith-resources'          => 'Resources',
			'demon-identity'            => 'Identity',
			'demon-resources'           => 'Resources',
			'mummy-identity'            => 'Identity',
			'mummy-resources'           => 'Resources',
			'kueijin-identity'          => 'Identity',
			'kueijin-resources'         => 'Resources',
			'mortal-identity'           => 'Identity',
			'mortal-resources'          => 'Resources',
			'fera-identity'             => 'Identity',
		];

		return $labels[ $slug ] ?? ucwords( str_replace( '-', ' ', $slug ) );
	}

	// ---------------------------------------------------------------------------
	// CREATURE STACK SEED DATA
	// ---------------------------------------------------------------------------

	/**
	 * Builds the insert array for every system creature stack.
	 *
	 * @return array[]
	 */
	private static function get_stacks_to_seed(): array {
		return array_values( \BeyondElysium\Services\Catalog_Reader::stacks_to_seed() );
	}

	// ---------------------------------------------------------------------------
	// BLOCK FACTORY HELPERS
	// ---------------------------------------------------------------------------

	/**
	 * Per-block declared mechanics, emitted as `definition._meta`.
	 */
	const TIERED_POWER_META = [
		'vampire-disciplines' => [
			'ranks'          => [ 'basic', 'intermediate', 'advanced', 'elder', 'master', 'ascended', 'methuselah' ],
			'ladder'         => [ 'basic' => 2, 'intermediate' => 2, 'advanced' => 1 ],
			'out_of_type'    => [ 'basic' => '+1', 'intermediate' => '+1', 'advanced' => '+1', 'elder' => '+1', 'master' => '+1', 'ascended' => '+1', 'methuselah' => '+1' ],
		],
		'vampire-blood-magic' => [
			'ranks'  => [ 'basic', 'intermediate', 'advanced' ],
			'ladder' => [ 'basic' => 2, 'intermediate' => 2, 'advanced' => 1 ],
		],
		'wraith-arcanoi' => [
			// `innate` is in `ranks` but not in `ladder`: a pick below the ladder.
			'ranks'   => [ 'innate', 'basic', 'intermediate', 'advanced' ],
			'ladder'  => [ 'basic' => 2, 'intermediate' => 2, 'advanced' => 1 ],
			// The Guild's own discount on its own Arcanos, and Innate's exemption from it as `+0`.
			'in_type' => [ 'innate' => '+0', 'basic' => '-1', 'intermediate' => '-1', 'advanced' => '-1' ],
		],
		'mage-spheres' => [
			'ranks'       => [ 'basic', 'intermediate', 'advanced' ],
			'ladder'      => [ 'basic' => 2, 'intermediate' => 2, 'advanced' => 1 ],
			'out_of_type' => [ 'basic' => '+1', 'intermediate' => '+2', 'advanced' => '+3' ],
		],
		'changeling-arts' => [
			'ranks'  => [ 'basic', 'intermediate', 'advanced' ],
			'ladder' => [ 'basic' => 2, 'intermediate' => 2, 'advanced' => 1 ],
		],
		'mummy-hekau' => [
			'ranks'       => [ 'basic', 'intermediate', 'advanced', 'master' ],
			'ladder'      => [ 'basic' => 2, 'intermediate' => 2, 'advanced' => 1 ],
			'out_of_type' => [ 'basic' => '+1', 'intermediate' => '+1', 'advanced' => '+1', 'master' => '+1' ],
		],
		'kueijin-disciplines' => [
			// No out-of-type modifier.
			'ranks'  => [ 'basic', 'intermediate', 'advanced' ],
			'ladder' => [ 'basic' => 2, 'intermediate' => 2, 'advanced' => 1 ],
		],
		'changeling-realms' => [
			// Realms carry no tier vocabulary at all.
			'ranks'  => [ 'basic', 'intermediate', 'advanced' ],
			'ladder' => [ 'basic' => 2, 'intermediate' => 2, 'advanced' => 1 ],
		],
	];

	/**
	 * A power's rank number by tier name.
	 */
	const TIER_RANKS = [
		'basic'        => 1,
		'intermediate' => 2,
		'advanced'     => 3,
		'elder'        => 4,
		'master'       => 5,
		'ascended'     => 6,
		'methuselah'   => 7,
	];

	/**
	 * Splits one family's flat level list into the three declared containers.
	 *
	 * @param string $slug
	 * @param array  $levels
	 * @return array{levels:array,elder:array,overflow:array}
	 */
	/**
	 * Re-splits one already-stored `tiered_power` definition into the three containers and gives it a `_meta`.
	 *
	 * @param string              $slug       The block's own slug - picks the declared ladder.
	 * @param array<string,mixed> $definition A decoded tiered_power definition.
	 * @return array<string,mixed>|null The rewritten definition, or null when nothing changed.
	 */
	public static function split_stored_definition( string $slug, array $definition ): ?array {
		if ( isset( $definition['_meta'] ) ) {
			return null;
		}
		if ( ! isset( $definition['powers'] ) || ! is_array( $definition['powers'] ) ) {
			return null;
		}

		$powers = [];
		foreach ( $definition['powers'] as $power ) {
			if ( ! is_array( $power ) || ! isset( $power['levels'] ) || ! is_array( $power['levels'] ) ) {
				$powers[] = $power;
				continue;
			}
			// A fork's own levels, not the global's.
			$split = self::split_levels( $slug, $power['levels'] );

			$power['levels'] = $split['levels'];
			unset( $power['elder'], $power['overflow'] );
			if ( $split['elder'] !== [] ) {
				$power['elder'] = $split['elder'];
			}
			if ( $split['overflow'] !== [] ) {
				$power['overflow'] = $split['overflow'];
			}
			$powers[] = $power;
		}

		$definition['powers'] = $powers;
		$definition['_meta']  = self::meta_for( $slug, $powers );

		return $definition;
	}

	private static function split_levels( string $slug, array $levels ): array {
		$meta     = self::TIERED_POWER_META[ $slug ] ?? null;
		$ladder   = $meta['ladder'] ?? [ 'basic' => 2, 'intermediate' => 2, 'advanced' => 1 ];
		$ceiling  = array_sum( $ladder );
		$is_rung  = static fn( string $tier ): bool => isset( $ladder[ $tier ] ) || 'unknown' === $tier;

		$rungs    = [];
		$elder    = [];
		$overflow = [];

		foreach ( $levels as $level ) {
			$tier = (string) ( $level['tier'] ?? 'unknown' );
			if ( ! $is_rung( $tier ) ) {
				// A pick, keyed by its own rank.
				$level['level']   = null;
				$elder[ $tier ][] = $level;
				continue;
			}
			$rungs[] = $level;
		}

		// Assigns each level to a rung of its rank, or to overflow.
		$by_rank = [];
		foreach ( $rungs as $level ) {
			$by_rank[ (string) ( $level['tier'] ?? 'unknown' ) ][] = $level;
		}

		$final  = [];
		$number = 0;
		foreach ( $ladder as $rank => $quota ) {
			$available = $by_rank[ (string) $rank ] ?? [];
			foreach ( $available as $position => $level ) {
				if ( $position < (int) $quota ) {
					// The rung's number is its place on the declared ladder. Nothing infers it.
					$level['level'] = ++$number;
					$final[]        = $level;
					continue;
				}
				$level['level'] = null;
				$overflow[]     = $level;
			}
			unset( $by_rank[ (string) $rank ] );
		}

		// `unknown` - a family whose source states no tier at all (Changeling Realms' flat per-dot track).
		foreach ( $by_rank as $leftover ) {
			foreach ( $leftover as $level ) {
				if ( $number < $ceiling ) {
					$level['level'] = ++$number;
					$final[]        = $level;
					continue;
				}
				$level['level'] = null;
				$overflow[]     = $level;
			}
		}

		return [ 'levels' => $final, 'elder' => $elder, 'overflow' => $overflow ];
	}

	/**
	 * Builds a block's `_meta`.
	 *
	 * @param string $slug
	 * @param array  $powers
	 * @return array
	 */
	private static function meta_for( string $slug, array $powers ): array {
		$declared = self::TIERED_POWER_META[ $slug ] ?? [
			'ranks'  => array_keys( self::TIER_RANKS ),
			'ladder' => [ 'basic' => 2, 'intermediate' => 2, 'advanced' => 1 ],
		];

		$allowed = array_flip( array_merge( $declared['ranks'], [ 'unknown' ] ) );
		$costs   = array_intersect_key( self::meta_costs_for( $powers ), $allowed );
		if ( $costs !== [] ) {
			$declared['costs'] = $costs;
		}
		return $declared;
	}

	/**
	 * The per-rank cost a block's own seeded data actually uses, by plurality across every level carrying one.
	 *
	 * @param array $powers
	 * @return array<string,int>
	 */
	private static function meta_costs_for( array $powers ): array {
		$seen = [];
		foreach ( $powers as $power ) {
			$containers = [ $power['levels'] ?? [], $power['overflow'] ?? [] ];
			foreach ( ( $power['elder'] ?? [] ) as $picks ) {
				$containers[] = $picks;
			}
			foreach ( $containers as $levels ) {
				foreach ( $levels as $level ) {
					$tier = (string) ( $level['tier'] ?? '' );
					$cost = $level['cost'] ?? null;
					if ( '' === $tier || null === $cost || ! is_numeric( trim( (string) $cost ) ) ) {
						continue;
					}
					$value = (int) trim( (string) $cost );
					$seen[ $tier ][ $value ] = ( $seen[ $tier ][ $value ] ?? 0 ) + 1;
				}
			}
		}

		$costs = [];
		foreach ( $seen as $tier => $tally ) {
			arsort( $tally );
			$costs[ $tier ] = (int) array_key_first( $tally );
		}
		return $costs;
	}

	// ---------------------------------------------------------------------------
	// DEFAULT TEMPLATES
	// ---------------------------------------------------------------------------

	/**
	 * Seeds one `sheet_full` global template per creature stack.
	 */
	public static function seed_default_templates(): void {
		$ported = self::default_template_sections();

		foreach ( Creature_Stack::all() as $stack ) {
			$existing = Template::globals( [ 'stack_slug' => $stack->slug, 'template_type' => 'sheet_full' ] );
			if ( ! empty( $existing ) ) {
				continue;
			}

			if ( isset( $ported[ $stack->slug ] ) ) {
				$layout = [
					'version'  => 1,
					'columns'  => 6,
					'sections' => self::build_layout_sections( $ported[ $stack->slug ] ),
				];
			} else {
				$layout = Layout_Generator::generate_for_stack( $stack->slug );
			}

			if ( ! $layout ) {
				continue;
			}

			Template::create( [
				'stack_slug'    => $stack->slug,
				'name'          => $stack->name . ' Sheet',
				'template_type' => 'sheet_full',
				'layout'        => $layout,
				'is_system'     => 1,
				'created_by'    => 0,
			] );
		}
	}

	/**
	 * Seeds one `npc_full` global template per creature stack.
	 */
	public static function seed_npc_templates(): void {
		foreach ( Creature_Stack::all() as $stack ) {
			$existing = Template::globals( [ 'stack_slug' => $stack->slug, 'template_type' => 'npc_full' ] );
			if ( ! empty( $existing ) ) {
				continue;
			}

			$base = Template::resolve( $stack->slug, 'sheet_full', null );
			$layout = $base->layout ?? Layout_Generator::generate_for_stack( $stack->slug );
			if ( ! $layout || ! isset( $layout['sections'] ) || ! is_array( $layout['sections'] ) ) {
				continue;
			}

			$orders  = array_column( $layout['sections'], 'order' );
			$columns = array_column( $layout['sections'], 'column' );

			$layout['sections'][] = [
				'block_slug' => 'npc-roleplaying-notes',
				'column'     => $columns ? max( $columns ) : 1,
				'order'      => $orders ? max( $orders ) + 1 : 1,
				'title'      => 'Roleplaying Notes',
				'display'    => null,
				'collapsed'  => false,
				'width'      => 'full',
			];

			Template::create( [
				'stack_slug'    => $stack->slug,
				'name'          => $stack->name . ' NPC Sheet',
				'template_type' => 'npc_full',
				'layout'        => $layout,
				'is_system'     => 1,
				'created_by'    => 0,
			] );
		}

		self::seed_npc_quick_templates();
	}

	/**
	 * Seeds one `npc_quick` global template per creature stack: the stack's own identity section.
	 */
	private static function seed_npc_quick_templates(): void {
		foreach ( Creature_Stack::all() as $stack ) {
			$existing = Template::globals( [ 'stack_slug' => $stack->slug, 'template_type' => 'npc_quick' ] );
			if ( ! empty( $existing ) ) {
				continue;
			}

			$identity_slug = "{$stack->slug}-identity";
			if ( ! Schema_Block::find_by_slug( $identity_slug ) ) {
				continue;
			}

			$layout = [
				'version'  => 1,
				'columns'  => 2,
				'sections' => [
					[ 'block_slug' => $identity_slug, 'column' => 1, 'order' => 1, 'title' => null, 'display' => null, 'collapsed' => false, 'width' => 'half' ],
					[ 'block_slug' => 'npc-quick-stats', 'column' => 1, 'order' => 2, 'title' => 'Quick Stats', 'display' => null, 'collapsed' => false, 'width' => 'half' ],
					[ 'block_slug' => 'npc-roleplaying-notes', 'column' => 2, 'order' => 1, 'title' => 'Roleplaying Notes', 'display' => null, 'collapsed' => false, 'width' => 'half' ],
				],
			];

			Template::create( [
				'stack_slug'    => $stack->slug,
				'name'          => $stack->name . ' NPC Sheet (Quick)',
				'template_type' => 'npc_quick',
				'layout'        => $layout,
				'is_system'     => 1,
				'created_by'    => 0,
			] );
		}
	}

	/**
	 * Numbers a flat [block_slug, column, display] entry list into full layout sections, assigning a single sequential
	 * `order` down the whole list.
	 *
	 * @param array $entries Each: [ string $block_slug, int $column, string|null $display ].
	 * @return array[]
	 */
	private static function build_layout_sections( array $entries ): array {
		$sections = [];
		$order    = 0;

		// A single flowing sequence; the front end's grid auto-flow decides row placement from (column, order).
		foreach ( $entries as $entry ) {
			[ $slug, $width, $display ] = $entry;
			++$order;
			$section = [
				'block_slug' => $slug,
				'column'     => 1,
				'order'      => $order,
				'title'      => self::block_label( $slug ),
				'display'    => $display,
				'collapsed'  => false,
				'width'      => $width,
			];
			// Optional 4th tuple element: cross-block title references, e.g. Virtues reading Morality Path + rating.
			if ( isset( $entry[3] ) ) {
				$section['title_refs'] = $entry[3];
			}
			$sections[] = $section;
		}

		return $sections;
	}

	/**
	 * Per-stack [block_slug, width, display] lists defining each creature stack's default sheet layout.
	 *
	 * @return array<string,array>
	 */
	private static function default_template_sections(): array {
		$dot = 'multiplier_dot';

		$vampire = [
			// Resources pairs with Virtues.
			[ 'vampire-identity', 'half', null ], [ 'met-archetypes', 'half', null ],
			[ 'vampire-resources', 'half', null ], [ 'vampire-virtues', 'half', null, [
				[ 'block_slug' => 'vampire-identity', 'field' => 'Morality Path' ],
				[ 'block_slug' => 'vampire-resources', 'field' => 'Morality' ],
			] ],
			[ 'met-physical-traits', 'third', $dot ], [ 'met-social-traits', 'third', $dot ], [ 'met-mental-traits', 'third', $dot ],
			[ 'met-physical-traits-neg', 'third', null ], [ 'met-social-traits-neg', 'third', null ], [ 'met-mental-traits-neg', 'third', null ],
			[ 'vampire-abilities', 'half', $dot ], [ 'vampire-backgrounds', 'half', $dot ],
			[ 'vampire-disciplines', 'full', null ],
			[ 'vampire-blood-magic', 'full', null ],
			[ 'vampire-combo-disciplines', 'full', null ],
			[ 'vampire-rituals', 'half', null ], [ 'vampire-ritae', 'half', null ],
			[ 'vampire-statuses', 'full', $dot ],
			[ 'vampire-merits', 'third', null ], [ 'vampire-flaws', 'third', null ], [ 'met-derangements', 'third', null ],
		];

		// Werewolf and Fera share the rites-adjacent resource and renown blocks.
		$werewolf_like = static function ( string $family ) use ( $dot ): array {
			return [
				[ "{$family}-identity", 'half', null ], [ 'werewolf-renown', 'half', null ],
				[ 'werewolf-resources', 'half', null ], [ 'met-archetypes', 'half', null ],
				[ 'met-physical-traits', 'third', $dot ], [ 'met-social-traits', 'third', $dot ], [ 'met-mental-traits', 'third', $dot ],
				[ 'met-physical-traits-neg', 'third', null ], [ 'met-social-traits-neg', 'third', null ], [ 'met-mental-traits-neg', 'third', null ],
				[ "{$family}-abilities", 'half', $dot ], [ "{$family}-backgrounds", 'half', $dot ],
				[ "{$family}-gifts", 'full', null ],
				[ "{$family}-rites", 'full', null ],
				[ "{$family}-merits", 'half', null ], [ "{$family}-flaws", 'half', null ],
			];
		};

		// The layout the remaining creature types share.
		$standard = static function ( string $stack, array $powers, array $closing ) use ( $dot ): array {
			return array_merge(
				[
					[ "{$stack}-identity", 'half', null ], [ 'met-archetypes', 'half', null ],
					[ "{$stack}-resources", 'full', null ],
					[ 'met-physical-traits', 'third', $dot ], [ 'met-social-traits', 'third', $dot ], [ 'met-mental-traits', 'third', $dot ],
					[ 'met-physical-traits-neg', 'third', null ], [ 'met-social-traits-neg', 'third', null ], [ 'met-mental-traits-neg', 'third', null ],
					[ "{$stack}-abilities", 'half', $dot ], [ "{$stack}-backgrounds", 'half', $dot ],
				],
				array_map( static fn( string $slug ): array => [ $slug, 'full', null ], $powers ),
				$closing
			);
		};
		$merits_and_flaws = static fn( string $stack ): array => [ [ "{$stack}-merits", 'half', null ], [ "{$stack}-flaws", 'half', null ] ];

		$mortal = $standard(
			'mortal',
			[
				'mortal-psychic', 'mortal-hedge-magic', 'mortal-hedge-magic-formulae', 'mortal-theurgy', 'mortal-martial-arts',
				'mortal-fomori', 'mortal-bioenhancements', 'vampire-disciplines', 'werewolf-gifts', 'fera-gifts',
				'changeling-arts', 'changeling-realms',
			],
			[ [ 'mortal-merits', 'third', null ], [ 'mortal-flaws', 'third', null ], [ 'met-derangements', 'third', null ] ]
		);

		$fera = $werewolf_like( 'fera' );

		return [
			'vampire'    => $vampire,
			'werewolf'   => $werewolf_like( 'werewolf' ),
			'mage'       => $standard( 'mage', [ 'mage-spheres', 'mage-rotes' ], $merits_and_flaws( 'mage' ) ),
			'changeling' => $standard( 'changeling', [ 'changeling-arts', 'changeling-realms' ], $merits_and_flaws( 'changeling' ) ),
			'wraith'     => $standard( 'wraith', [ 'wraith-arcanoi' ], $merits_and_flaws( 'wraith' ) ),
			'demon'      => $standard( 'demon', [ 'demon-evocations', 'demon-rituals' ], $merits_and_flaws( 'demon' ) ),
			'mortal'     => $mortal,
			'mummy'      => $standard( 'mummy', [ 'mummy-hekau' ], $merits_and_flaws( 'mummy' ) ),
			'kueijin'    => $standard( 'kueijin', [ 'kueijin-disciplines' ], $merits_and_flaws( 'kueijin' ) ),
			'fera'       => $fera,
			'bete'       => $fera,
		];
	}

	/**
	 * The current correct `sheet_full` layout for one stack, in the same shape `seed_default_templates()` writes for a
	 * fresh install.
	 *
	 * @param string $stack_slug
	 * @return array|null Null if this stack has no shipped-sheet layout (falls back to
	 *                     Layout_Generator instead, which repair_stale_default_layouts()
	 *                     does not need since only vampire's shipped layout is affected).
	 */
	public static function rebuild_default_layout_for_stack( string $stack_slug ): ?array {
		$ported = self::default_template_sections();
		if ( ! isset( $ported[ $stack_slug ] ) ) {
			return null;
		}

		return [
			'version'  => 1,
			'columns'  => 6,
			'sections' => self::build_layout_sections( $ported[ $stack_slug ] ),
		];
	}

	/**
	 * Option set once the demo chronicle has had its one chance to be seeded.
	 */
	const DEMO_SEEDED_OPTION = 'be_demo_seeded';

	/**
	 * The demo character fixtures: 22 characters, two per creature type, in `demo-characters.php`.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function demo_fixtures(): array {
		return require __DIR__ . '/demo-characters.php';
	}

	/**
	 * Seeds the demo characters into a dedicated `be-demo` game, created first if it does not exist.
	 *
	 * @param bool $fresh_install True only when no schema version had been recorded before this run.
	 */
	public static function seed_demo_characters( bool $fresh_install = false ): void {
		if ( get_option( self::DEMO_SEEDED_OPTION ) ) {
			return;
		}
		update_option( self::DEMO_SEEDED_OPTION, 1 );
		if ( ! $fresh_install ) {
			return;
		}

		$game = \BeyondElysium\Models\Game::find_by_slug( 'be-demo' );
		if ( ! $game ) {
			$game_id = \BeyondElysium\Models\Game::create( [
				'name'        => 'Beyond Elysium Demo',
				'slug'        => 'be-demo',
				'game_type'   => 'met',
				'description' => 'Ships with the plugin so you can see it working immediately - 22 real characters across every supported creature type. Safe to delete once you have your own game running.',
			] );
			if ( ! $game_id ) {
				return;
			}
			$game = \BeyondElysium\Models\Game::find( $game_id );
			if ( ! $game ) {
				return;
			}
		}

		$fixtures = self::demo_fixtures();

		foreach ( $fixtures as $f ) {
			$existing = Manager::get_row(
				'SELECT id FROM ' . Manager::table( 'characters' ) . ' WHERE name = %s AND owner_slug = %s',
				$f['name'],
				$game->slug
			);
			if ( $existing ) {
				continue;
			}

			$resolved = Creature_Stack::resolve( $f['stack_slug'] );
			if ( ! $resolved ) {
				error_log( "Beyond Elysium: demo character '{$f['name']}' skipped - stack '{$f['stack_slug']}' does not resolve." );
				continue;
			}

			$id = \BeyondElysium\Models\Character::create( [
				'name'        => $f['name'],
				'stack_slug'  => $f['stack_slug'],
				'owner_type'  => 'chronicle',
				'owner_slug'  => $game->slug,
				'is_npc'      => 0,
				'status'      => 'active',
				'player_name' => $f['player_name'],
				'sheet_data'  => $f['sheet_data'],
			] );
			if ( ! $id ) {
				error_log( "Beyond Elysium: failed to create demo character '{$f['name']}'." );
				continue;
			}

			\BeyondElysium\Models\Character::update_xp( $id, $f['xp_earned'], $f['xp_unspent'] );
		}
	}
}
