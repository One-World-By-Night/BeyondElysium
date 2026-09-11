<?php

namespace BeyondElysium\Database;

use BeyondElysium\Models\Schema_Block;
use BeyondElysium\Models\Creature_Stack;
use BeyondElysium\Models\Template;
use BeyondElysium\Services\GVM_Parser;
use BeyondElysium\Services\Layout_Generator;
use BeyondElysium\Services\MET_CSV_Parser;

defined( 'ABSPATH' ) || exit;

/**
 * Builds and seeds the plugin's system schema-block and creature-stack
 * catalog from the Grapevine (GVM) menu source, layered with overrides
 * from the MET-Mechanics CSV. Also seeds default character-sheet
 * templates and the demo character set shipped with the plugin.
 *
 * All seeding here is idempotent and limited to system (is_system = 1)
 * rows; anything a chronicle created or customized is never touched.
 */
class Seeder {

	/**
	 * Path to the GVM XML source, relative to this file.
	 *
	 * Reads from data/, not GV301Source/. The full Grapevine source archive is reference
	 * material and is excluded from the deployed artifact by .distignore; data/ holds the
	 * small subset the plugin needs at runtime. See data/README.md.
	 */
	const GVM_PATH = __DIR__ . '/../../data/Grapevine Menus XML.gvm';

	/**
	 * Path to the MET-Mechanics CSV, relative to this file. Layered on top of the GVM-sourced
	 * blocks it covers - see apply_met_csv_overrides() and BE_PROCESS/workflow-0.10.md. Missing
	 * or malformed falls back to exactly today's GVM-only behavior, same as a missing GVM file
	 * falls back to hardcoded_blocks().
	 */
	const MET_CSV_PATH = __DIR__ . '/../../data/met-mechanics.csv';

	// ---------------------------------------------------------------------------
	// PUBLIC ENTRY POINTS
	// ---------------------------------------------------------------------------

	/**
	 * Seed all system schema blocks.
	 *
	 * Idempotent: system blocks are refreshed from the GVM source, custom blocks are
	 * left alone.
	 */
	public static function seed_schema_blocks(): void {
		$blocks = self::get_blocks_to_seed();

		foreach ( $blocks as $block ) {
			$existing = Schema_Block::find_by_slug( $block['slug'] );

			if ( ! $existing ) {
				Schema_Block::create( $block );
				continue;
			}

			// System blocks are refreshed from the GVM source; a chronicle's own edited blocks (is_system = 0) are never touched.
			if ( (int) $existing->is_system === 1 ) {
				Schema_Block::update( $block['slug'], $block );
			}
		}
	}

	/**
	 * Seed all system creature stacks.
	 *
	 * Idempotent: system stacks are refreshed from the definitions in this file, custom
	 * stacks are left alone. Same reasoning as seed_schema_blocks().
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
			}
		}
	}

	// ---------------------------------------------------------------------------
	// GVM PARSING
	// ---------------------------------------------------------------------------

	/**
	 * Parses the GVM XML file and returns an array of [ name => [ items ] ]
	 * maps. Falls back to an empty array, logged, when the source file is
	 * missing or fails to parse.
	 *
	 * @return array Menu name => [ 'items' => string[], 'submenus' => array[], 'category' => int ]
	 */
	public static function parse_gvm(): array {
		$path = self::GVM_PATH;
		if ( ! file_exists( $path ) ) {
			error_log(
				'Beyond Elysium: GVM source not found at ' . $path
				. ' - falling back to hardcoded schema blocks.'
			);
			return [];
		}

		// Delegates to the shared GVM_Parser::parse_xml() front end rather than duplicating XML parsing here.
		try {
			$parsed = GVM_Parser::parse_xml( $path );
		} catch ( \RuntimeException $e ) {
			error_log(
				'Beyond Elysium: GVM source at ' . $path
				. ' failed to parse - falling back to hardcoded schema blocks.'
			);
			return [];
		}

		// Include resolution is a seeder concern; GVM_Parser's front ends return raw, unresolved includes.
		$raw      = $parsed['menus'];
		$resolved = [];
		foreach ( $raw as $name => $data ) {
			$resolved[ $name ] = self::resolve_includes( $name, $data, $raw, [] );
		}

		return $resolved;
	}

	/**
	 * Recursively resolves <include> references for a menu.
	 *
	 * Throws when a menu is revisited during its own resolution, rather
	 * than silently truncating the cycle - a chronicle's edited menu file
	 * with a genuine include cycle would otherwise seed a quietly
	 * incomplete menu with no signal that anything was wrong.
	 *
	 * @param string $name    Current menu name.
	 * @param array  $data    Current menu raw data.
	 * @param array  $all_raw All raw menus.
	 * @param array  $visited Guard against circular includes - the chain from the top-
	 *                        level menu down to (not including) $name.
	 * @return array Resolved data with all included items merged in.
	 * @throws \RuntimeException When $name is already in $visited - a real cycle.
	 */
	private static function resolve_includes( string $name, array $data, array $all_raw, array $visited ): array {
		if ( in_array( $name, $visited, true ) ) {
			throw new \RuntimeException(
				sprintf(
					'Circular <include> reference in the menu set: %s -> %s',
					implode( ' -> ', $visited ),
					$name
				)
			);
		}
		$visited[] = $name;

		$merged_items    = [];
		$merged_submenus = $data['submenus'];

		foreach ( $data['includes'] as $include_name ) {
			if ( isset( $all_raw[ $include_name ] ) ) {
				$included = self::resolve_includes( $include_name, $all_raw[ $include_name ], $all_raw, $visited );
				$merged_items    = array_merge( $merged_items, $included['items'] );
				$merged_submenus = array_merge( $merged_submenus, $included['submenus'] );
			}
		}

		// Own items come after included items (creature-specific extras after base).
		$merged_items = array_merge( $merged_items, $data['items'] );
		$merged_items = self::unique_items( $merged_items );

		return [
			'category'     => $data['category'],
			// A menu's own alphabetize/display are structural properties; they come from $data, not an include.
			'alphabetized' => $data['alphabetized'],
			'display'      => $data['display'],
			'items'        => $merged_items,
			'submenus'     => $merged_submenus,
			'includes'     => [],
		];
	}

	/**
	 * Deduplicate parsed menu items by name, keeping the first occurrence.
	 *
	 * Included items come before the menu's own, so a creature-specific override of a
	 * base item keeps the base definition. That matches Grapevine, where an include
	 * splices the base list in ahead of the local additions.
	 *
	 * @param array[] $items Item arrays with a 'name' key.
	 * @return array[]
	 */
	private static function unique_items( array $items ): array {
		$seen   = [];
		$unique = [];

		foreach ( $items as $item ) {
			if ( isset( $seen[ $item['name'] ] ) ) {
				continue;
			}
			$seen[ $item['name'] ] = true;
			$unique[]              = $item;
		}

		return $unique;
	}

	// ---------------------------------------------------------------------------
	// RECONCILIATION
	// ---------------------------------------------------------------------------

	/**
	 * Verifies that every block referenced by a creature stack actually
	 * exists.
	 *
	 * A stack pointing at a block that was never seeded is a character
	 * sheet with a missing section, invisible until someone opens that
	 * sheet. Runs after seeding so the failure is visible at activation.
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
	// BLOCK MAP
	// ---------------------------------------------------------------------------

	/**
	 * Returns the declared GVM menu -> schema block map, read from
	 * gvm-block-map.php. Used by every GVM-driven block builder to look
	 * up where a given schema block's content comes from.
	 *
	 * @return array<string,array>
	 */
	public static function block_map(): array {
		return require __DIR__ . '/gvm-block-map.php';
	}

	/**
	 * Returns the Subtype-label and Background-routing tables for the
	 * MET-Mechanics CSV overlay, read from met-csv-map.php. Used by
	 * apply_met_csv_overrides() and its helpers.
	 *
	 * @return array{discipline_labels:array<string,string>,ritual_labels:array<string,string>,background_routing:array<string,string[]>}
	 */
	public static function met_csv_map(): array {
		return require __DIR__ . '/met-csv-map.php';
	}

	/**
	 * Normalizes a MET-Mechanics CSV cost string to the shape
	 * Cost_Engine::parse_cost_rule() expects: a positive magnitude, with
	 * a range written using a bare hyphen. Strips a leading sign, since
	 * the CSV stores Flaw costs already negative while this codebase's
	 * trait_list blocks apply the sign via their own `negative` flag.
	 *
	 * @param string $cost Raw CSV Cost value.
	 * @return string
	 */
	public static function normalize_met_cost( string $cost ): string {
		$cost = trim( $cost );

		if ( $cost === '' ) {
			return '';
		}

		// A '-' immediately before a digit is a sign, not a range separator - drop it.
		$cost = preg_replace( '/-(?=\d)/', '', $cost );

		// "1 to 5" -> "1-5", matching parse_cost_rule()'s real range branch.
		$cost = preg_replace( '/\s+to\s+/i', '-', $cost );

		return $cost;
	}

	/**
	 * Resolves one block map entry against the parsed menu set.
	 * Dispatches on the entry's 'source' type (menu, merge, container,
	 * pattern, or none) to collect that block's items or powers.
	 *
	 * @param array  $gvm   Parsed menu map.
	 * @param string $slug  Block slug, for error reporting.
	 * @param array  $entry Map entry.
	 * @return array{items:array[],powers:array[],shape:string,unresolved:string[],alphabetized:bool,display:int}
	 */
	public static function resolve_block_source( array $gvm, string $slug, array $entry ): array {
		$out = [
			'items'        => [],
			'powers'       => [],
			'shape'        => 'named',
			'unresolved'   => [],
			// GVM's own alphabetize flag and ListDisplayType default (-1 = ldDefault, no override).
			'alphabetized' => false,
			'display'      => -1,
		];

		switch ( $entry['source'] ) {

			case 'menu':
				if ( ! isset( $gvm[ $entry['menu'] ] ) ) {
					$out['unresolved'][] = sprintf( "%s: menu '%s' not found", $slug, $entry['menu'] );
					break;
				}
				$out['items']        = $gvm[ $entry['menu'] ]['items'];
				$out['alphabetized'] = ! empty( $gvm[ $entry['menu'] ]['alphabetized'] );
				$out['display']      = (int) ( $gvm[ $entry['menu'] ]['display'] ?? -1 );
				break;

			case 'merge':
				foreach ( $entry['menus'] as $menu_name ) {
					if ( ! isset( $gvm[ $menu_name ] ) ) {
						$out['unresolved'][] = sprintf( "%s: menu '%s' not found", $slug, $menu_name );
					}
				}
				$out['items'] = self::merge_menus( $gvm, $entry['menus'] );
				// An optional 'labels' map renames each merged item to "<Type>: <name> (<tier>)"; 'note_overrides' can override the label per item.
				if ( ! empty( $entry['labels'] ) ) {
					$trim_chars     = " .\t\n\r\0\x0B";
					$note_overrides = $entry['note_overrides'] ?? [];
					$out['items']   = array_map(
						static function ( $item ) use ( $entry, $note_overrides, $trim_chars ) {
							$note  = trim( (string) ( $item['note'] ?? '' ), $trim_chars );
							$label = $entry['labels'][ $item['source'] ] ?? $item['source'];
							$tier  = $note;
							foreach ( preg_split( '/\s+/', $note ) as $word ) {
								$key = strtolower( trim( $word, '.' ) );
								if ( isset( $note_overrides[ $key ] ) ) {
									$label = $note_overrides[ $key ];
									$tier  = trim( str_ireplace( $word, '', $note ), $trim_chars );
									break;
								}
							}
							$item['name'] = $tier !== ''
								? "{$label}: {$item['name']} ({$tier})"
								: "{$label}: {$item['name']}";
							return $item;
						},
						$out['items']
					);
				}
				// Merged menus are conventionally consistent; the first menu present stands in for the group.
				foreach ( $entry['menus'] as $menu_name ) {
					if ( isset( $gvm[ $menu_name ] ) ) {
						$out['alphabetized'] = ! empty( $gvm[ $menu_name ]['alphabetized'] );
						$out['display']      = (int) ( $gvm[ $menu_name ]['display'] ?? -1 );
						break;
					}
				}
				break;

			case 'container':
				$resolved       = self::resolve_container( $gvm, $entry['menu'] );
				$out['powers']  = $resolved['powers'];
				$out['shape']   = $resolved['shape'];
				foreach ( $resolved['unresolved'] as $miss ) {
					$out['unresolved'][] = $slug . ': ' . $miss;
				}
				break;

			case 'pattern':
				$out['items'] = self::aggregate_menus(
					$gvm,
					$entry['pattern'],
					isset( $entry['category'] ) ? (int) $entry['category'] : null
				);
				if ( empty( $out['items'] ) ) {
					$out['unresolved'][] = sprintf( "%s: pattern '%s' matched nothing", $slug, $entry['pattern'] );
				}
				// Optional per-item grouping keyed off the '_container'/'_display' tags aggregate_menus() attaches.
				if ( isset( $entry['label'] ) ) {
					$out['items'] = array_map(
						static function ( $item ) use ( $entry ) {
							$display   = $item['_display'] ?? $item['source'];
							$container = $item['_container'] ?? $item['source'];
							unset( $item['_display'], $item['_container'] );

							$tier = trim( (string) ( $item['note'] ?? '' ) );
							if ( $tier !== '' ) {
								$item['tier'] = $tier;
							}

							if ( $entry['label'] === 'splat' ) {
								// Fera: container is "Gifts, <Breed>"; a distinct display value is the subgroup, else "no sub-faction".
								$group = preg_replace( '/^Gifts,\s*/', '', $container );
								if ( $display === $container ) {
									$group = preg_replace( '/\s+General$/i', '', $group );
								} elseif ( strcasecmp( $display, 'General' ) !== 0 ) {
									$item['subgroup'] = $display;
								}
								$item['group'] = $group;
							} else {
								// Werewolf: every item reaches here via a tribe/rank/camp submenu, so display is always that name.
								$item['group'] = $display;
							}

							return $item;
						},
						$out['items']
					);
				}
				break;

			case 'none':
				break;
		}

		return $out;
	}

	// ---------------------------------------------------------------------------
	// CONTAINER RESOLUTION
	// ---------------------------------------------------------------------------

	/**
	 * Resolves a submenu reference to the menu name it targets.
	 * An empty link means the submenu's own name is the target menu name,
	 * rather than a separate linked menu.
	 *
	 * @param array $submenu Submenu with 'name' and 'link' keys.
	 * @return string Target menu name.
	 */
	private static function submenu_target( array $submenu ): string {
		return $submenu['link'] !== '' ? $submenu['link'] : $submenu['name'];
	}

	/**
	 * Resolve a container menu into its powers.
	 *
	 * Container menus hold no items of their own; each submenu points at a menu holding
	 * that power's contents. Two shapes exist and the shape is derived, not declared, so
	 * a chronicle's custom menu set resolves correctly too:
	 *
	 *   named          each submenu resolves to its own menu of individually named
	 *                  powers (Disciplines, Thaumaturgy, Arcanoi, Arts, Numina)
	 *   shared_levels  every submenu links to one shared level menu
	 *                  (Spheres -> "Sphere Levels", Hekau, Realms)
	 *
	 * @param array  $gvm       Parsed menu map.
	 * @param string $container Container menu name.
	 * @param array $visited Container names already entered on this call chain - guards the
	 *                        nested-submenu recursion below against a cyclical custom menu
	 *                        set (A's submenu links to B, B's links back to A), the same
	 *                        concern D28 fixed for <include> resolution. Never passed by an
	 *                        external caller; only resolve_container() re-enters itself with it.
	 * @return array{shape:string,powers:array[],levels:array[],unresolved:string[]}
	 */
	private static function resolve_container( array $gvm, string $container, array $visited = [] ): array {
		$result = [ 'shape' => 'named', 'powers' => [], 'levels' => [], 'unresolved' => [] ];

		if ( ! isset( $gvm[ $container ] ) ) {
			$result['unresolved'][] = $container;
			return $result;
		}

		if ( in_array( $container, $visited, true ) ) {
			$result['unresolved'][] = $container . ' (cycle)';
			return $result;
		}
		$visited[] = $container;

		$submenus = $gvm[ $container ]['submenus'];
		$targets  = [];

		foreach ( $submenus as $submenu ) {
			$targets[] = self::submenu_target( $submenu );
		}

		$distinct = array_values( array_unique( $targets ) );

		// Every submenu pointing at one menu means that menu is a shared level list.
		if ( count( $submenus ) > 1 && count( $distinct ) === 1 && isset( $gvm[ $distinct[0] ] ) ) {
			$result['shape']  = 'shared_levels';
			$result['levels'] = $gvm[ $distinct[0] ]['items'];
		}

		foreach ( $submenus as $submenu ) {
			$target = self::submenu_target( $submenu );

			if ( ! isset( $gvm[ $target ] ) ) {
				$result['unresolved'][] = $container . ' -> ' . $submenu['name'];
				continue;
			}

			$items = $result['shape'] === 'shared_levels' ? $result['levels'] : $gvm[ $target ]['items'];

			// A container's own submenu can itself be another container; both its items and nested submenus are kept.
			if ( ! empty( $items ) ) {
				$result['powers'][] = [
					'name'   => $submenu['name'],
					'source' => $target,
					'items'  => $items,
				];
			}

			if ( $result['shape'] !== 'shared_levels' && ! empty( $gvm[ $target ]['submenus'] ) ) {
				$nested = self::resolve_container( $gvm, $target, $visited );
				array_push( $result['powers'], ...$nested['powers'] );
				array_push( $result['unresolved'], ...$nested['unresolved'] );
			}
		}

		return $result;
	}

	/**
	 * Collect items from every menu whose name matches a pattern.
	 *
	 * Gifts and Rites have no single menu in Grapevine; both are organised per tribe,
	 * camp and breed. Matching by pattern rather than a fixed list means a chronicle's
	 * additions are picked up automatically. Container menus found by the pattern are
	 * resolved, and each item keeps its source menu so a Gift can be attributed.
	 *
	 * @param array       $gvm      Parsed menu map.
	 * @param string      $pattern  PCRE matched against the menu name.
	 * @param int|null    $category Optional RaceType category filter.
	 * @return array[] Item arrays with an added 'source' key.
	 */
	private static function aggregate_menus( array $gvm, string $pattern, ?int $category = null ): array {
		$collected = [];

		foreach ( $gvm as $name => $menu ) {
			if ( ! preg_match( $pattern, $name ) ) {
				continue;
			}
			if ( $category !== null && (int) $menu['category'] !== $category ) {
				continue;
			}

			foreach ( $menu['items'] as $item ) {
				$item['source']     = $name;
				// Transient: consumed only by resolve_block_source()'s 'label' transform, stripped before storage.
				$item['_container'] = $name;
				$item['_display']   = $name;
				$collected[]        = $item;
			}

			// A matched menu may itself be a container of per-camp or per-breed menus.
			// Skip targets that match the pattern themselves: they are visited on their
			// own pass, and following them here would double-count. "Gifts, Ananasi" is a
			// container pointing at "Gifts, Ananasi General", and both match /^Gifts, /.
			foreach ( $menu['submenus'] as $submenu ) {
				$target = self::submenu_target( $submenu );
				if ( ! isset( $gvm[ $target ] ) || preg_match( $pattern, $target ) ) {
					continue;
				}
				foreach ( $gvm[ $target ]['items'] as $item ) {
					$item['source']     = $target;
					$item['_container'] = $name;
					$item['_display']   = $submenu['name'];
					$collected[]        = $item;
				}
			}
		}

		// The same power can be reachable by more than one route; keep the first.
		return self::unique_items( $collected );
	}

	/**
	 * Merges the items of several menus into one list.
	 * Concatenates each named menu's items in the given order and skips
	 * any menu name that does not exist in the parsed menu map.
	 *
	 * @param array    $gvm   Parsed menu map.
	 * @param string[] $names Menu names to merge, in order.
	 * @return array[] Item arrays with an added 'source' key.
	 */
	private static function merge_menus( array $gvm, array $names ): array {
		$merged = [];

		foreach ( $names as $name ) {
			if ( ! isset( $gvm[ $name ] ) ) {
				continue;
			}
			foreach ( $gvm[ $name ]['items'] as $item ) {
				$item['source'] = $name;
				$merged[]       = $item;
			}
		}

		return $merged;
	}

	// ---------------------------------------------------------------------------
	// BLOCK BUILDING
	// ---------------------------------------------------------------------------

	/**
	 * Get the full list of schema blocks to seed.
	 * Tries the GVM parser first; falls back to hardcoded minimal set.
	 *
	 * Public so tests can use it as ground truth for what will actually exist in the
	 * database - see tests/unit/FieldRegistryTest.php.
	 *
	 * @return array[]
	 */
	public static function get_blocks_to_seed(): array {
		$gvm = self::parse_gvm();

		$blocks = ! empty( $gvm ) ? self::build_blocks_from_gvm( $gvm ) : self::hardcoded_blocks();

		$csv = self::parse_met_csv();
		if ( ! empty( $csv['rows'] ) ) {
			$blocks = self::apply_met_csv_overrides( $blocks, $csv, $gvm );
		}

		return $blocks;
	}

	/**
	 * Parse the MET-Mechanics CSV. Missing or malformed falls back to leaving $blocks exactly
	 * as the GVM/hardcoded path already built them - same layered-degradation style
	 * parse_gvm() already uses for a missing GVM file.
	 *
	 * @return array{rows?:array[],by_type?:array<string,array[]>}
	 */
	private static function parse_met_csv(): array {
		$path = self::MET_CSV_PATH;

		if ( ! file_exists( $path ) ) {
			error_log(
				'Beyond Elysium: MET-Mechanics CSV not found at ' . $path
				. ' - skipping its overlay, GVM-sourced blocks stand as-is.'
			);
			return [];
		}

		try {
			return MET_CSV_Parser::parse_file( $path );
		} catch ( \RuntimeException $e ) {
			error_log( 'Beyond Elysium: MET-Mechanics CSV failed to parse - skipping its overlay. ' . $e->getMessage() );
			return [];
		}
	}

	/**
	 * Layers the MET-Mechanics CSV on top of the GVM/hardcoded-built
	 * blocks: REPLACE-target slugs get their content wholesale-overwritten,
	 * MERGE-target slugs get CSV items unioned in. Blocks the CSV has no
	 * data for at all pass through untouched.
	 *
	 * @param array[] $blocks Blocks built from GVM/hardcoded data.
	 * @param array   $csv    MET_CSV_Parser::parse_file()'s return.
	 * @param array   $gvm    Parsed GVM menus (Disciplines needs to merge against GVM's own
	 *                        richer existing data, not just overwrite it - see
	 *                        build_met_discipline_powers()).
	 * @return array[]
	 */
	private static function apply_met_csv_overrides( array $blocks, array $csv, array $gvm ): array {
		$by_slug = [];
		foreach ( $blocks as $i => $block ) {
			$by_slug[ $block['slug'] ] = $i;
		}
		$map = self::met_csv_map();

		$disciplines = self::build_met_disciplines( $csv, $map, $gvm );
		foreach ( $disciplines as $slug => $block ) {
			if ( isset( $by_slug[ $slug ] ) ) {
				$blocks[ $by_slug[ $slug ] ] = $block;
			} else {
				$blocks[] = $block;
			}
		}

		$rituals = self::build_met_rituals( $csv, $map, $gvm );
		if ( isset( $by_slug['vampire-rituals'] ) ) {
			$blocks[ $by_slug['vampire-rituals'] ] = $rituals;
		} else {
			$blocks[] = $rituals;
		}

		$archetypes = self::build_met_archetypes( $csv, $gvm );
		if ( isset( $by_slug['met-archetypes'] ) ) {
			$blocks[ $by_slug['met-archetypes'] ] = $archetypes;
		} else {
			$blocks[] = $archetypes;
		}

		if ( isset( $by_slug['vampire-identity'] ) ) {
			$blocks[ $by_slug['vampire-identity'] ] = self::apply_met_csv_to_vampire_identity(
				$blocks[ $by_slug['vampire-identity'] ],
				$csv,
				$gvm
			);
		}

		if ( isset( $by_slug['mortal-identity'] ) ) {
			$revenant_names = self::merge_met_option_names( array_column( $csv['by_type']['Revenant'] ?? [], 'Name' ) );
			$block          = $blocks[ $by_slug['mortal-identity'] ];
			foreach ( $block['definition']['fields'] as &$field ) {
				if ( $field['name'] === 'Revenant Family' ) {
					$field['options'] = $revenant_names;
				}
			}
			unset( $field );
			$blocks[ $by_slug['mortal-identity'] ] = $block;
		}

		foreach ( [
			'met-abilities' => [ 'Ability', [ 'has_specializations' => true ] ],
			'met-merits'    => [ 'Merit', [ 'atomic' => true ] ],
			'met-flaws'     => [ 'Flaw', [ 'negative' => true, 'atomic' => true ] ],
		] as $slug => [ $csv_type, $extra ] ) {
			if ( ! isset( $by_slug[ $slug ] ) ) {
				continue;
			}
			$blocks[ $by_slug[ $slug ] ] = self::build_met_merge_trait_list( $slug, $csv_type, $csv, $gvm, $extra );
		}

		foreach ( self::build_met_backgrounds( $blocks, $csv, $map, $gvm ) as $slug => $block ) {
			if ( isset( $by_slug[ $slug ] ) ) {
				$blocks[ $by_slug[ $slug ] ] = $block;
			}
		}

		return $blocks;
	}

	/**
	 * Builds vampire-disciplines (tiered_power) and vampire-combo-disciplines
	 * (trait_list) from the CSV's Discipline rows.
	 *
	 * "Combination" rows go to vampire-combo-disciplines as a flat list.
	 * Every other row is grouped into one power family per Subtype, or per
	 * (Subtype, Group) pair for tradition/path disciplines, since the bare
	 * Group value alone can repeat identically across several traditions.
	 *
	 * @param array $csv MET_CSV_Parser::parse_file()'s return.
	 * @param array $map Seeder::met_csv_map()'s return.
	 * @param array $gvm Parsed GVM menus - vampire-disciplines merges against GVM's own
	 *                   existing resolution rather than replacing it outright.
	 * @return array{"vampire-disciplines":array,"vampire-combo-disciplines":array}
	 */
	private static function build_met_disciplines( array $csv, array $map, array $gvm ): array {
		$rows = array_values( array_filter(
			$csv['by_type']['Discipline'] ?? [],
			static function ( $row ) {
				return ! self::is_met_ref_placeholder( $row );
			}
		) );

		$combos = array_values( array_filter( $rows, static fn( $row ) => $row['Subtype'] === 'Combination' ) );
		$powers = array_values( array_filter( $rows, static fn( $row ) => $row['Subtype'] !== 'Combination' ) );

		return [
			'vampire-disciplines'       => self::build_met_discipline_powers( $powers, $map['discipline_labels'], $gvm ),
			'vampire-combo-disciplines' => self::build_met_combo_disciplines( $combos ),
		];
	}

	/**
	 * Checks whether a CSV row is a cross-reference placeholder rather
	 * than a real catalog entry - one where Rtg, lNum, lName and Cost are
	 * all the literal string 'Ref', pointing at a writeup elsewhere
	 * instead of carrying real level/cost data of its own.
	 *
	 * @param array<string,string> $row
	 */
	private static function is_met_ref_placeholder( array $row ): bool {
		foreach ( [ 'Rtg', 'lNum', 'lName', 'Cost' ] as $column ) {
			if ( ( $row[ $column ] ?? '' ) !== 'Ref' ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Deduplicates MET-Mechanics rows by Name, since exact-duplicate rows
	 * exist in the CSV. Keeps the row with a non-empty Cost when
	 * duplicates disagree; otherwise keeps the first occurrence.
	 *
	 * @param array<int,array<string,string>> $rows
	 * @return array<int,array<string,string>>
	 */
	private static function dedupe_met_rows_by_name( array $rows ): array {
		$by_name = [];
		foreach ( $rows as $row ) {
			$name = $row['Name'];
			if ( ! isset( $by_name[ $name ] ) ) {
				$by_name[ $name ] = $row;
				continue;
			}
			if ( $by_name[ $name ]['Cost'] === '' && $row['Cost'] !== '' ) {
				$by_name[ $name ] = $row;
			}
		}
		return array_values( $by_name );
	}

	/**
	 * Deduplicates an already-built, already-labeled item list by its
	 * final `name` string. Unlike the row-level dedupe_met_rows_by_*()
	 * helpers, this catches duplicates that only become visible once two
	 * different sources land on the same final display string. Keeps the
	 * item with a non-empty cost when duplicates disagree.
	 *
	 * @param array<int,array<string,mixed>> $items
	 * @return array<int,array<string,mixed>>
	 */
	private static function dedupe_built_items_by_name( array $items ): array {
		$by_name = [];
		foreach ( $items as $item ) {
			$name = $item['name'];
			if ( ! isset( $by_name[ $name ] ) ) {
				$by_name[ $name ] = $item;
				continue;
			}
			if ( empty( $by_name[ $name ]['cost'] ) && ! empty( $item['cost'] ) ) {
				$by_name[ $name ] = $item;
			}
		}
		return array_values( $by_name );
	}

	/**
	 * Deduplicates MET-Mechanics rows by (Subtype, Name) instead of Name
	 * alone, since the same ritual or power name legitimately recurs under
	 * different Subtypes. Keeps the row with a non-empty Cost when
	 * duplicates disagree; otherwise keeps the first occurrence.
	 *
	 * @param array<int,array<string,string>> $rows
	 * @return array<int,array<string,string>>
	 */
	private static function dedupe_met_rows_by_subtype_and_name( array $rows ): array {
		$by_key = [];
		foreach ( $rows as $row ) {
			$key = $row['Subtype'] . "\x1f" . $row['Name'];
			if ( ! isset( $by_key[ $key ] ) ) {
				$by_key[ $key ] = $row;
				continue;
			}
			if ( $by_key[ $key ]['Cost'] === '' && $row['Cost'] !== '' ) {
				$by_key[ $key ] = $row;
			}
		}
		return array_values( $by_key );
	}

	/**
	 * Sort key for a row's lNum column: real numeric levels sort first in ascending
	 * order; non-numeric values ('-', 'Ref', '') sort after all of them, in their
	 * original relative order (PHP 8's usort() is stable).
	 *
	 * @return array{0:int,1:int}
	 */
	private static function met_lnum_sort_key( string $lnum ): array {
		return is_numeric( $lnum ) ? [ 0, (int) $lnum ] : [ 1, 0 ];
	}

	/**
	 * Comparison-only key for a power/item name: strips a leading
	 * "A"/"An"/"The" and lowercases. Used only to decide whether two names
	 * refer to the same power, never for display.
	 */
	private static function met_name_comparison_key( string $name ): string {
		return strtolower( (string) preg_replace( '/^(a|an|the)\s+/i', '', trim( $name ) ) );
	}

	/**
	 * Merges the CSV's Discipline rows into GVM's own existing
	 * vampire-disciplines resolution, rather than replacing it.
	 *
	 * Matches each GVM power family to its CSV equivalent by comparing a
	 * candidate CSV family's Subtype or Group against the GVM family name,
	 * then picking the candidate with the most overlapping item names. A
	 * matched family keeps every GVM item as-is and appends any CSV item
	 * not already present by name. An unmatched CSV family becomes a new
	 * power family; an unmatched GVM family passes through unchanged.
	 *
	 * @param array<int,array<string,string>> $rows   Non-Combination Discipline rows.
	 * @param array<string,string>            $labels Subtype -> pretty display label.
	 * @param array                           $gvm    Parsed GVM menus.
	 * @return array
	 */
	private static function build_met_discipline_powers( array $rows, array $labels, array $gvm ): array {
		$gvm_families = self::resolve_block_source( $gvm, 'vampire-disciplines', self::block_map()['vampire-disciplines'] )['powers'];

		$by_key = [];
		foreach ( $rows as $row ) {
			$key = $row['Subtype'] . "\x1f" . $row['Group'];
			$by_key[ $key ]['subtype'] = $row['Subtype'];
			$by_key[ $key ]['group']   = $row['Group'];
			$by_key[ $key ]['rows'][]  = $row;
		}

		$csv_families = [];
		foreach ( $by_key as $key => $family ) {
			$label = $labels[ $family['subtype'] ] ?? $family['subtype'];
			$name  = $family['group'] !== '' ? "{$label}: {$family['group']}" : $label;

			$items = self::dedupe_met_rows_by_name( $family['rows'] );
			usort(
				$items,
				static function ( $a, $b ) {
					return self::met_lnum_sort_key( $a['lNum'] ) <=> self::met_lnum_sort_key( $b['lNum'] );
				}
			);

			$csv_families[ $key ] = [
				'subtype' => $family['subtype'],
				'group'   => $family['group'],
				'name'    => $name,
				'items'   => array_map(
					static function ( $row ) {
						$item = [
							'name' => $row['Name'],
							// Control (a clan/bloodline restriction) rides alongside the tier rather than replacing it.
							'note' => $row['Control'] !== '' ? "{$row['lName']} ({$row['Control']})" : $row['lName'],
						];
						$cost = Seeder::normalize_met_cost( $row['Cost'] );
						if ( $cost !== '' ) {
							$item['cost'] = $cost;
						}
						return $item;
					},
					$items
				),
			];
		}

		$matched    = [];
		$powers_in  = [];
		foreach ( $gvm_families as $gvm_family ) {
			$gvm_item_keys = array_map( [ self::class, 'met_name_comparison_key' ], array_column( $gvm_family['items'], 'name' ) );

			// A tie between candidates means the same Group is offered by several traditions; only merge on a single unambiguous winner.
			$best_key      = null;
			$best_overlap  = -1;
			$tied_at_best  = 0;
			foreach ( $csv_families as $key => $candidate ) {
				if ( in_array( $key, $matched, true ) ) {
					continue;
				}
				if ( $candidate['subtype'] !== $gvm_family['name'] && $candidate['group'] !== $gvm_family['name'] ) {
					continue;
				}
				$candidate_keys = array_map( [ self::class, 'met_name_comparison_key' ], array_column( $candidate['items'], 'name' ) );
				$overlap        = count( array_intersect( $gvm_item_keys, $candidate_keys ) );
				if ( $overlap > $best_overlap ) {
					$best_overlap = $overlap;
					$best_key     = $key;
					$tied_at_best = 1;
				} elseif ( $overlap === $best_overlap ) {
					$tied_at_best++;
				}
			}
			if ( $tied_at_best > 1 ) {
				$best_key = null;
			}

			$items = $gvm_family['items'];
			if ( $best_key !== null ) {
				$matched[] = $best_key;
				$have      = array_map( [ self::class, 'met_name_comparison_key' ], array_column( $items, 'name' ) );
				foreach ( $csv_families[ $best_key ]['items'] as $csv_item ) {
					if ( ! in_array( self::met_name_comparison_key( $csv_item['name'] ), $have, true ) ) {
						$items[] = $csv_item;
						$have[]  = self::met_name_comparison_key( $csv_item['name'] );
					}
				}
			}

			$powers_in[] = [
				'name'   => $gvm_family['name'],
				'source' => $gvm_family['source'] ?? $gvm_family['name'],
				'items'  => $items,
			];
		}

		foreach ( $csv_families as $key => $family ) {
			if ( in_array( $key, $matched, true ) ) {
				continue;
			}
			$powers_in[] = [
				'name'   => $family['name'],
				'source' => $family['subtype'],
				'items'  => $family['items'],
			];
		}

		return self::make_tiered_power_block( 'vampire-disciplines', 'Disciplines', $powers_in, [ 'atomic' => true ] );
	}

	/**
	 * Builds the vampire-combo-disciplines trait_list block from the CSV's
	 * "Combination"-subtype Discipline rows. Combines each row's Control
	 * and Prerequsites into one note field.
	 *
	 * @param array<int,array<string,string>> $rows "Combination"-subtype Discipline rows.
	 * @return array
	 */
	private static function build_met_combo_disciplines( array $rows ): array {
		$items = self::dedupe_met_rows_by_name( $rows );

		$built = array_map(
			static function ( $row ) {
				$item = [ 'name' => $row['Name'] ];

				$cost = Seeder::normalize_met_cost( $row['Cost'] );
				if ( $cost !== '' ) {
					$item['cost'] = $cost;
				}

				// Control and Prerequsites both describe requirements; combined into one note field.
				$note_parts = array_values( array_filter( [ $row['Control'], $row['Prerequsites'] ], static fn( $v ) => $v !== '' ) );
				if ( ! empty( $note_parts ) ) {
					$item['note'] = implode( '; ', $note_parts );
				}

				if ( $row['Source'] !== '' ) {
					$item['source'] = $row['Source'];
				}

				return $item;
			},
			$items
		);

		return self::make_trait_list_block( 'vampire-combo-disciplines', 'Combination Disciplines', $built );
	}

	/**
	 * Merges the CSV's Ritual rows into GVM's own existing vampire-rituals
	 * resolution, rather than replacing it.
	 *
	 * GVM's existing, already-labeled items are kept as the base; any CSV
	 * row whose (label, ritual name) pair is not already present is
	 * appended. Deduplicated by (Subtype, Name), not Name alone, since the
	 * same ritual name can legitimately recur under different Subtypes.
	 *
	 * @param array $csv MET_CSV_Parser::parse_file()'s return.
	 * @param array $map Seeder::met_csv_map()'s return.
	 * @param array $gvm Parsed GVM menus.
	 * @return array
	 */
	private static function build_met_rituals( array $csv, array $map, array $gvm ): array {
		$entry          = self::block_map()['vampire-rituals'];
		$gvm_raw_items  = self::merge_menus( $gvm, $entry['menus'] );
		$trim_chars     = " .\t\n\r\0\x0B";
		$note_overrides = $entry['note_overrides'] ?? [];

		$gvm_keys = [];
		foreach ( $gvm_raw_items as $item ) {
			$note  = trim( (string) ( $item['note'] ?? '' ), $trim_chars );
			$label = $entry['labels'][ $item['source'] ] ?? $item['source'];
			foreach ( preg_split( '/\s+/', $note ) as $word ) {
				$override_key = strtolower( trim( $word, '.' ) );
				if ( isset( $note_overrides[ $override_key ] ) ) {
					$label = $note_overrides[ $override_key ];
					break;
				}
			}
			$gvm_keys[ strtolower( $label ) . "\x1f" . self::met_name_comparison_key( $item['name'] ) ] = true;
		}

		$rows  = array_values( array_filter(
			$csv['by_type']['Ritual'] ?? [],
			static function ( $row ) {
				return ! self::is_met_ref_placeholder( $row );
			}
		) );
		$rows  = self::dedupe_met_rows_by_subtype_and_name( $rows );

		$new_items = [];
		foreach ( $rows as $row ) {
			$label = $map['ritual_labels'][ $row['Subtype'] ] ?? $row['Subtype'];
			$key   = strtolower( $label ) . "\x1f" . self::met_name_comparison_key( $row['Name'] );

			if ( isset( $gvm_keys[ $key ] ) ) {
				continue;
			}

			$tier = $row['lName'];
			$item = [
				'name' => $tier !== '' ? "{$label}: {$row['Name']} ({$tier})" : "{$label}: {$row['Name']}",
			];
			$cost = Seeder::normalize_met_cost( $row['Cost'] );
			if ( $cost !== '' ) {
				$item['cost'] = $cost;
			}
			$new_items[] = $item;
		}

		$base = self::resolve_block_source( $gvm, 'vampire-rituals', $entry )['items'];

		$merged = self::dedupe_built_items_by_name( array_merge( $base, $new_items ) );

		return self::make_trait_list_block( 'vampire-rituals', 'Rituals', $merged, [ 'atomic' => true ] );
	}

	/**
	 * Merges the CSV's Archetype names into met-archetypes' Nature/Demeanor
	 * option lists. Unions GVM and CSV names rather than replacing either,
	 * deduplicating case-insensitively and alphabetizing the result.
	 *
	 * @param array $csv MET_CSV_Parser::parse_file()'s return.
	 * @param array $gvm Parsed GVM menus.
	 * @return array
	 */
	private static function build_met_archetypes( array $csv, array $gvm ): array {
		$names = array_column( $gvm['Archetypes']['items'] ?? [], 'name' );

		foreach ( $csv['by_type']['Archetype'] ?? [] as $row ) {
			$names[] = $row['Name'];
		}

		$names = self::merge_met_option_names( $names );

		return self::make_identity_block(
			'met-archetypes',
			'Archetypes',
			[
				[ 'name' => 'Nature', 'field_type' => 'select', 'required' => false, 'options' => $names ],
				[ 'name' => 'Demeanor', 'field_type' => 'select', 'required' => false, 'options' => $names ],
			]
		);
	}

	/**
	 * Deduplicates a flat identity-field option list case-insensitively
	 * and returns it alphabetized. Shared by every caller that unions
	 * option names assembled from more than one source (GVM and the CSV).
	 *
	 * @param string[] $names
	 * @return string[]
	 */
	private static function merge_met_option_names( array $names ): array {
		$by_lower = [];
		foreach ( $names as $name ) {
			$name = trim( $name );
			if ( $name !== '' ) {
				$by_lower[ strtolower( $name ) ] = $name;
			}
		}
		$names = array_values( $by_lower );
		sort( $names );
		return $names;
	}

	/**
	 * Merges the CSV's Clan/Bloodline names into vampire-identity's Clan
	 * field, and the CSV's Morality Path names into its Morality Path
	 * field. Unions GVM and CSV names rather than replacing either.
	 *
	 * @param array $csv MET_CSV_Parser::parse_file()'s return.
	 * @param array $gvm Parsed GVM menus.
	 * @return array vampire-identity, with Clan and Morality Path options replaced in place.
	 */
	private static function apply_met_csv_to_vampire_identity( array $block, array $csv, array $gvm ): array {
		$clan_names = array_column( $gvm['Clan']['items'] ?? [], 'name' );
		foreach ( $csv['by_type']['Clan / Bloodline'] ?? [] as $row ) {
			$clan_names[] = $row['Name'];
		}
		$clan_names = self::merge_met_option_names( $clan_names );

		$path_names = array_column( $gvm['Path']['items'] ?? [], 'name' );
		foreach ( $csv['by_type']['Paths'] ?? [] as $row ) {
			$path_names[] = $row['Name'];
		}
		$path_names = self::merge_met_option_names( $path_names );

		foreach ( $block['definition']['fields'] as &$field ) {
			if ( $field['name'] === 'Clan' ) {
				$field['options'] = $clan_names;
			} elseif ( $field['name'] === 'Morality Path' ) {
				$field['options'] = $path_names;
			}
		}
		unset( $field );

		return $block;
	}

	/**
	 * Merges the CSV's Merit/Flaw/Ability rows into their shared,
	 * cross-stack trait_list block. Unions the CSV rows onto the GVM-built
	 * base rather than replacing it.
	 *
	 * @param string $slug     Target block slug (met-merits, met-flaws, or met-abilities).
	 * @param string $csv_type The CSV Type value this slug's rows carry (Merit, Flaw, Ability).
	 * @param array  $csv      MET_CSV_Parser::parse_file()'s return.
	 * @param array  $gvm      Parsed GVM menus.
	 * @param array  $extra    Extra definition keys the GVM-only path already sets for this
	 *                         slug (e.g. 'atomic', 'has_specializations') - preserved as-is.
	 * @return array
	 */
	private static function build_met_merge_trait_list( string $slug, string $csv_type, array $csv, array $gvm, array $extra ): array {
		$entry = self::block_map()[ $slug ];
		$base  = self::resolve_block_source( $gvm, $slug, $entry )['items'];

		$existing_keys = array_map( [ self::class, 'met_name_comparison_key' ], array_column( $base, 'name' ) );

		$rows      = self::dedupe_met_rows_by_name( $csv['by_type'][ $csv_type ] ?? [] );
		$new_items = [];
		foreach ( $rows as $row ) {
			$key = self::met_name_comparison_key( $row['Name'] );
			if ( in_array( $key, $existing_keys, true ) ) {
				continue;
			}
			$item = [ 'name' => $row['Name'] ];
			$cost = self::normalize_met_cost( $row['Cost'] );
			if ( $cost !== '' ) {
				$item['cost'] = $cost;
			}
			$new_items[]     = $item;
			$existing_keys[] = $key;
		}

		$merged = self::dedupe_built_items_by_name( array_merge( $base, $new_items ) );

		return self::make_trait_list_block( $slug, self::block_label( $slug ), $merged, $extra );
	}

	/**
	 * Merges the CSV's Background rows into every stack's own
	 * '{stack}-backgrounds' block, routed by Subtype per met_csv_map()'s
	 * background_routing table.
	 *
	 * @param array[] $blocks Blocks built so far (GVM/hardcoded + everything already merged).
	 * @param array   $csv    MET_CSV_Parser::parse_file()'s return.
	 * @param array   $map    Seeder::met_csv_map()'s return.
	 * @param array   $gvm    Parsed GVM menus.
	 * @return array<string,array> slug => rebuilt block, for every '{stack}-backgrounds' slug
	 *                              the CSV has at least one row routed to.
	 */
	private static function build_met_backgrounds( array $blocks, array $csv, array $map, array $gvm ): array {
		$by_slug = [];
		foreach ( $blocks as $block ) {
			$by_slug[ $block['slug'] ] = $block;
		}

		$rows_by_target = [];
		foreach ( $csv['by_type']['Background'] ?? [] as $row ) {
			foreach ( $map['background_routing'][ $row['Subtype'] ] ?? [] as $target_slug ) {
				$rows_by_target[ $target_slug ][] = $row;
			}
		}

		$rebuilt = [];
		foreach ( $rows_by_target as $slug => $rows ) {
			if ( ! isset( $by_slug[ $slug ] ) ) {
				continue;
			}
			$base          = $by_slug[ $slug ]['definition']['items'] ?? [];
			$existing_keys = array_map( [ self::class, 'met_name_comparison_key' ], array_column( $base, 'name' ) );

			$rows      = self::dedupe_met_rows_by_name( $rows );
			$new_items = [];
			foreach ( $rows as $row ) {
				$key = self::met_name_comparison_key( $row['Name'] );
				if ( in_array( $key, $existing_keys, true ) ) {
					continue;
				}
				$item = [ 'name' => $row['Name'] ];
				$cost = self::normalize_met_cost( $row['Cost'] );
				if ( $cost !== '' ) {
					$item['cost'] = $cost;
				}
				$new_items[]     = $item;
				$existing_keys[] = $key;
			}

			$merged           = self::dedupe_built_items_by_name( array_merge( $base, $new_items ) );
			$block            = $by_slug[ $slug ];
			$block['definition']['items'] = $merged;
			$rebuilt[ $slug ] = $block;
		}

		return $rebuilt;
	}

	/**
	 * Converts parsed GVM data into schema block insert arrays.
	 * Combines every menu-mapped block from build_mapped_blocks() with
	 * the hand-built identity and resource blocks from static_blocks().
	 *
	 * @param array $gvm Resolved menu map.
	 * @return array[]
	 */
	private static function build_blocks_from_gvm( array $gvm ): array {
		return array_merge(
			self::build_mapped_blocks( $gvm ),
			self::static_blocks( $gvm )
		);
	}

	/**
	 * Builds every schema block that has a GVM menu behind it, driven by
	 * block_map(). Logs any block whose source menu could not be resolved
	 * rather than seeding it with missing content silently.
	 *
	 * @param array $gvm Parsed menu map.
	 * @return array[]
	 */
	private static function build_mapped_blocks( array $gvm ): array {
		$blocks     = [];
		$unresolved = [];

		foreach ( self::block_map() as $slug => $entry ) {
			$resolved   = self::resolve_block_source( $gvm, $slug, $entry );
			$unresolved = array_merge( $unresolved, $resolved['unresolved'] );

			$label = self::block_label( $slug );
			$extra = [];

			if ( ! empty( $entry['negative'] ) ) {
				// Grapevine inverts cost for a negative list: adding a Flaw grants points.
				$extra['negative'] = true;
			}
			if ( ! empty( $entry['partial'] ) ) {
				$extra['partial_source'] = true;
			}
			if ( ! empty( $entry['deferred_to'] ) ) {
				$extra['deferred_to'] = $entry['deferred_to'];
			}
			if ( ! empty( $entry['atomic'] ) ) {
				// 'atomic' is a per-creature-class constant, hand-curated in the block map like 'negative'.
				$extra['atomic'] = true;
			}

			if ( $entry['source'] === 'container' ) {
				$extra['shape']      = $resolved['shape'];
				$extra['sequential'] = ( $resolved['shape'] === 'shared_levels' );
				$blocks[] = self::make_tiered_power_block( $slug, $label, $resolved['powers'], $extra );
				continue;
			}

			if ( $slug === 'met-abilities' ) {
				$extra['has_specializations'] = true;
			}

			if ( $resolved['alphabetized'] ) {
				$extra['alphabetize'] = true;
			}
			if ( $resolved['display'] >= 0 && $resolved['display'] <= 10 ) {
				$extra['display'] = \BeyondElysium\Models\Template::DISPLAY_TYPES[ $resolved['display'] ];
			}

			$blocks[] = self::make_trait_list_block( $slug, $label, $resolved['items'], $extra );
		}

		if ( $unresolved ) {
			// A block that resolves to nothing is a broken character sheet; log it at seed time.
			error_log(
				'Beyond Elysium: unresolved GVM block sources: ' . implode( '; ', $unresolved )
			);
		}

		return $blocks;
	}

	/**
	 * Resolves the human-readable label for a block slug.
	 * Looks up a hand-maintained slug => label map first, falling back
	 * to a title-cased version of the slug for anything not listed.
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
			'met-abilities'             => 'Abilities',
			'met-merits'                => 'Merits',
			'met-flaws'                 => 'Flaws',
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
			'demon-lores'               => 'Demon Lores',
			'mummy-hekau'               => 'Mummy Hekau',
			'kueijin-disciplines'       => 'Kuei-Jin Disciplines',
			'mortal-numina'             => 'Mortal Numina',
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

	/**
	 * Flattens a GVM menu into a plain option-name list for an identity
	 * select field. Resolves a `<submenu>` cross-link (e.g. Kith's
	 * Inanimae/Nunnehi/Thallain lists) into the same flat list. Preserves
	 * the source menu's own item order unless $alphabetize is set, since
	 * some menus encode a deliberate non-alphabetical order.
	 *
	 * @param array  $gvm
	 * @param string $menu_name    The real, already race-resolved GVM menu name.
	 * @param bool   $alphabetize
	 * @return string[]
	 */
	private static function resolve_identity_options( array $gvm, string $menu_name, bool $alphabetize = false ): array {
		if ( ! isset( $gvm[ $menu_name ] ) ) {
			return [];
		}

		$options = array_column( $gvm[ $menu_name ]['items'], 'name' );

		foreach ( $gvm[ $menu_name ]['submenus'] as $submenu ) {
			$target = self::submenu_target( $submenu );
			if ( isset( $gvm[ $target ] ) ) {
				$options = array_merge( $options, array_column( $gvm[ $target ]['items'], 'name' ) );
			}
		}

		if ( $alphabetize ) {
			sort( $options );
		}

		return $options;
	}

	/**
	 * Builds every schema block with no GVM menu behind it: identity
	 * fields and resource pools for all eleven creature stacks. Identity
	 * select options are resolved from GVM via resolve_identity_options().
	 *
	 * @param array $gvm Parsed menu map (met-archetypes sources its options from it).
	 * @return array[]
	 */
	private static function static_blocks( array $gvm ): array {
		$blocks = [];

		// Storyteller-only NPC prose, shared by every stack - no creature-specific variant.
		$blocks[] = self::make_npc_roleplaying_notes_block();

		if ( isset( $gvm['Archetypes'] ) ) {
			// IdentityField.options is a plain string list; raw item cost/note fields must not leak in.
			$archetype_names = array_column( $gvm['Archetypes']['items'], 'name' );
			$blocks[]        = self::make_identity_block(
				'met-archetypes',
				'Archetypes',
				[ [ 'name' => 'Nature', 'field_type' => 'select', 'required' => false, 'options' => $archetype_names ],
				  [ 'name' => 'Demeanor', 'field_type' => 'select', 'required' => false, 'options' => $archetype_names ] ]
			);
		}

		// --- Vampire ---
		$blocks[] = self::make_identity_block( 'vampire-identity', 'Vampire Identity', [
			[ 'name' => 'Clan',          'field_type' => 'select',  'required' => true,  'allow_custom' => true, 'options' => self::resolve_identity_options( $gvm, 'Clan' ) ],
			[ 'name' => 'Sect',          'field_type' => 'select',  'required' => false, 'options' => self::resolve_identity_options( $gvm, 'Sect', true ) ],
			[ 'name' => 'Generation',    'field_type' => 'number',  'required' => true,  'min' => 3, 'max' => 15, 'default' => 13 ],
			[ 'name' => 'Sire',          'field_type' => 'text',    'required' => false ],
			[ 'name' => 'Title',         'field_type' => 'text',    'required' => false ],
			[ 'name' => 'Morality Path', 'field_type' => 'select',  'required' => true,  'allow_custom' => true, 'options' => self::resolve_identity_options( $gvm, 'Path', true ) ],
			// Every Path is a binary choice on two independent axes; the player picks directly, left unset for Humanity.
			[ 'name' => 'Conscience or Conviction', 'field_type' => 'select', 'required' => false, 'options' => [ 'Conscience', 'Conviction' ] ],
			[ 'name' => 'Self-Control or Instinct', 'field_type' => 'select', 'required' => false, 'options' => [ 'Self-Control', 'Instinct' ] ],
		], [ 'clan_disciplines' => require __DIR__ . '/vampire-clan-disciplines.php' ] );

		$blocks[] = self::make_resource_block( 'vampire-resources', 'Vampire Resources', [
			[ 'name' => 'Blood',     'value_type' => 'integer', 'default_start' => 10, 'max' => 20 ],
			[ 'name' => 'Willpower', 'value_type' => 'integer', 'default_start' => 3,  'max' => 20 ],
			[ 'name' => 'Morality',  'value_type' => 'integer', 'default_start' => 7,  'max' => 10 ],
		] );

		$blocks[] = self::make_resource_block( 'vampire-virtues', 'Vampire Virtues', array_map(
			[ self::class, 'with_virtue_name_lookup' ],
			[
				[ 'name' => 'Conscience',    'value_type' => 'integer', 'default_start' => 1, 'max' => 5 ],
				[ 'name' => 'Self-Control',  'value_type' => 'integer', 'default_start' => 1, 'max' => 5 ],
				[ 'name' => 'Courage',       'value_type' => 'integer', 'default_start' => 1, 'max' => 5 ],
			]
		) );

		// --- Werewolf ---
		$blocks[] = self::make_identity_block( 'werewolf-identity', 'Werewolf Identity', [
			[ 'name' => 'Tribe',   'field_type' => 'select', 'required' => true,  'options' => self::resolve_identity_options( $gvm, 'Tribe' ) ],
			[ 'name' => 'Breed',   'field_type' => 'select', 'required' => true,  'options' => self::resolve_identity_options( $gvm, 'Breed', true ) ],
			[ 'name' => 'Auspice', 'field_type' => 'select', 'required' => true,  'options' => self::resolve_identity_options( $gvm, 'Auspice' ) ],
			[ 'name' => 'Rank',    'field_type' => 'number', 'required' => false, 'min' => 0, 'max' => 5, 'default' => 1 ],
			[ 'name' => 'Pack',    'field_type' => 'text',   'required' => false ],
			[ 'name' => 'Totem',   'field_type' => 'text',   'required' => false ],
		] );

		$blocks[] = self::make_resource_block( 'werewolf-resources', 'Werewolf Resources', [
			[ 'name' => 'Rage',      'value_type' => 'integer', 'default_start' => 1, 'max' => 10 ],
			[ 'name' => 'Gnosis',    'value_type' => 'integer', 'default_start' => 1, 'max' => 10 ],
			[ 'name' => 'Willpower', 'value_type' => 'integer', 'default_start' => 3, 'max' => 20 ],
		] );

		$blocks[] = self::make_resource_block( 'werewolf-renown', 'Werewolf Renown', [
			[ 'name' => 'Honor',  'value_type' => 'decimal', 'step' => 0.1, 'default_start' => 0 ],
			[ 'name' => 'Glory',  'value_type' => 'decimal', 'step' => 0.1, 'default_start' => 0 ],
			[ 'name' => 'Wisdom', 'value_type' => 'decimal', 'step' => 0.1, 'default_start' => 0 ],
		] );

		// --- Mage ---
		$blocks[] = self::make_identity_block( 'mage-identity', 'Mage Identity', [
			[ 'name' => 'Tradition', 'field_type' => 'select', 'required' => true,  'options' => self::resolve_identity_options( $gvm, 'Tradition, Mage', true ) ],
			[ 'name' => 'Essence',   'field_type' => 'select', 'required' => false, 'options' => self::resolve_identity_options( $gvm, 'Essence', true ) ],
			[ 'name' => 'Faction',   'field_type' => 'text',   'required' => false ],
			[ 'name' => 'Cabal',     'field_type' => 'text',   'required' => false ],
			[ 'name' => 'Rank',      'field_type' => 'number', 'required' => false, 'min' => 0, 'max' => 5, 'default' => 1 ],
		] );

		$blocks[] = self::make_resource_block( 'mage-resources', 'Mage Resources', [
			[ 'name' => 'Arete',       'value_type' => 'integer', 'default_start' => 1, 'max' => 10 ],
			[ 'name' => 'Quintessence','value_type' => 'integer', 'default_start' => 1, 'max' => 20 ],
			[ 'name' => 'Paradox',     'value_type' => 'integer', 'default_start' => 0, 'max' => 20 ],
			[ 'name' => 'Willpower',   'value_type' => 'integer', 'default_start' => 3, 'max' => 20 ],
		] );

		// --- Changeling ---
		$blocks[] = self::make_identity_block( 'changeling-identity', 'Changeling Identity', [
			[ 'name' => 'Kith',    'field_type' => 'select', 'required' => true,  'options' => self::resolve_identity_options( $gvm, 'Kith', true ) ],
			[ 'name' => 'Seeming', 'field_type' => 'select', 'required' => true,  'options' => self::resolve_identity_options( $gvm, 'Seeming' ) ],
			[ 'name' => 'Court',   'field_type' => 'select', 'required' => false, 'options' => self::resolve_identity_options( $gvm, 'Court' ) ],
			[ 'name' => 'House',   'field_type' => 'text',   'required' => false ],
			[ 'name' => 'Seelie Legacy',   'field_type' => 'text', 'required' => false ],
			[ 'name' => 'Unseelie Legacy', 'field_type' => 'text', 'required' => false ],
		] );

		$blocks[] = self::make_resource_block( 'changeling-resources', 'Changeling Resources', [
			[ 'name' => 'Glamour',   'value_type' => 'integer', 'default_start' => 3, 'max' => 10 ],
			[ 'name' => 'Banality',  'value_type' => 'integer', 'default_start' => 3, 'max' => 10 ],
			[ 'name' => 'Willpower', 'value_type' => 'integer', 'default_start' => 3, 'max' => 20 ],
		] );

		// --- Wraith ---
		$blocks[] = self::make_identity_block( 'wraith-identity', 'Wraith Identity', [
			// Not GVM-menu-backed; a hardcoded 3-value enum, unlike every other identity select here.
			[ 'name' => 'Ethnos',  'field_type' => 'select', 'required' => false, 'options' => [ 'Wraith', 'Risen', 'Spectre' ] ],
			[ 'name' => 'Guild',   'field_type' => 'select', 'required' => false, 'options' => self::resolve_identity_options( $gvm, 'Guild', true ) ],
			[ 'name' => 'Faction', 'field_type' => 'text',   'required' => false ],
			[ 'name' => 'Legion',  'field_type' => 'text',   'required' => false ],
			[ 'name' => 'Shadow',  'field_type' => 'text',   'required' => false ],
		] );

		$blocks[] = self::make_resource_block( 'wraith-resources', 'Wraith Resources', [
			[ 'name' => 'Pathos',    'value_type' => 'integer', 'default_start' => 3, 'max' => 10 ],
			[ 'name' => 'Corpus',    'value_type' => 'integer', 'default_start' => 7, 'max' => 10 ],
			[ 'name' => 'Willpower', 'value_type' => 'integer', 'default_start' => 3, 'max' => 20 ],
			[ 'name' => 'Angst',     'value_type' => 'integer', 'default_start' => 0, 'max' => 10 ],
		] );

		// --- Demon ---
		$blocks[] = self::make_identity_block( 'demon-identity', 'Demon Identity', [
			[ 'name' => 'House',   'field_type' => 'select', 'required' => true,  'options' => self::resolve_identity_options( $gvm, 'House, Demon', true ) ],
			[ 'name' => 'Faction', 'field_type' => 'select', 'required' => false, 'options' => self::resolve_identity_options( $gvm, 'Faction, Demon', true ) ],
		] );

		$blocks[] = self::make_resource_block( 'demon-resources', 'Demon Resources', [
			[ 'name' => 'Faith',     'value_type' => 'integer', 'default_start' => 3, 'max' => 10 ],
			[ 'name' => 'Torment',   'value_type' => 'integer', 'default_start' => 0, 'max' => 10 ],
			[ 'name' => 'Willpower', 'value_type' => 'integer', 'default_start' => 3, 'max' => 20 ],
		] );

		// --- Mummy ---
		$blocks[] = self::make_identity_block( 'mummy-identity', 'Mummy Identity', [
			[ 'name' => 'Amenti', 'field_type' => 'text', 'required' => false ],
		] );

		$blocks[] = self::make_resource_block( 'mummy-resources', 'Mummy Resources', [
			[ 'name' => 'Sekhem',    'value_type' => 'integer', 'default_start' => 3, 'max' => 10 ],
			[ 'name' => 'Balance',   'value_type' => 'integer', 'default_start' => 5, 'max' => 10 ],
			[ 'name' => 'Willpower', 'value_type' => 'integer', 'default_start' => 3, 'max' => 20 ],
		] );

		// --- Kuei-Jin ---
		$blocks[] = self::make_identity_block( 'kueijin-identity', 'Kuei-Jin Identity', [
			[ 'name' => 'Dharma',    'field_type' => 'select', 'required' => true,  'options' => self::resolve_identity_options( $gvm, 'Dharma' ) ],
			[ 'name' => 'Direction', 'field_type' => 'select', 'required' => false, 'options' => self::resolve_identity_options( $gvm, 'Direction', true ) ],
			[ 'name' => 'Balance',   'field_type' => 'text',   'required' => false ],
			[ 'name' => 'Station',   'field_type' => 'text',   'required' => false ],
		] );

		$blocks[] = self::make_resource_block( 'kueijin-resources', 'Kuei-Jin Resources', [
			[ 'name' => 'Hun',       'value_type' => 'integer', 'default_start' => 3, 'max' => 10 ],
			[ 'name' => 'Po',        'value_type' => 'integer', 'default_start' => 3, 'max' => 10 ],
			[ 'name' => 'Yin Chi',   'value_type' => 'integer', 'default_start' => 3, 'max' => 10 ],
			[ 'name' => 'Yang Chi',  'value_type' => 'integer', 'default_start' => 3, 'max' => 10 ],
			[ 'name' => 'Demon Chi', 'value_type' => 'integer', 'default_start' => 0, 'max' => 10 ],
		] );

		// --- Mortal ---
		$blocks[] = self::make_identity_block( 'mortal-identity', 'Mortal Identity', [
			[ 'name' => 'Motivation',  'field_type' => 'text', 'required' => false ],
			[ 'name' => 'Association', 'field_type' => 'text', 'required' => false ],
			[ 'name' => 'Regnant',     'field_type' => 'text', 'required' => false ],
			// Populated by the MET-Mechanics CSV overlay; options start empty until that overlay runs.
			[ 'name' => 'Revenant Family', 'field_type' => 'select', 'required' => false, 'allow_custom' => true, 'options' => [] ],
		] );

		$blocks[] = self::make_resource_block( 'mortal-resources', 'Mortal Resources', [
			[ 'name' => 'Willpower',  'value_type' => 'integer', 'default_start' => 3, 'max' => 20 ],
			[ 'name' => 'True Faith', 'value_type' => 'integer', 'default_start' => 0, 'max' => 10 ],
			[ 'name' => 'Humanity',   'value_type' => 'integer', 'default_start' => 7, 'max' => 10 ],
		] );

		// --- Fera (same structure as werewolf but distinct identity) ---
		$blocks[] = self::make_identity_block( 'fera-identity', 'Fera Identity', [
			[ 'name' => 'Fera Type', 'field_type' => 'select', 'required' => true,  'options' => self::resolve_identity_options( $gvm, 'Fera', true ) ],
			// Fera's own Breed list is a superset of werewolf-identity's, not the same list.
			[ 'name' => 'Breed',     'field_type' => 'select', 'required' => true,  'options' => self::resolve_identity_options( $gvm, 'Breed, Fera', true ) ],
			[ 'name' => 'Auspice',   'field_type' => 'text',   'required' => false ],
			[ 'name' => 'Rank',      'field_type' => 'number', 'required' => false, 'min' => 0, 'max' => 5, 'default' => 1 ],
			[ 'name' => 'Pack',      'field_type' => 'text',   'required' => false ],
			[ 'name' => 'Totem',     'field_type' => 'text',   'required' => false ],
		] );


		return $blocks;
	}

	// ---------------------------------------------------------------------------
	// HARDCODED FALLBACK
	// ---------------------------------------------------------------------------

	/**
	 * Builds the minimal hardcoded schema blocks used when GVM parsing is
	 * unavailable. Covers every block slug with empty option/item lists so
	 * the plugin remains usable without the GVM source file.
	 *
	 * @return array[]
	 */
	private static function hardcoded_blocks(): array {
		return [
			self::make_trait_list_block( 'met-physical-traits',     'Physical Traits (Positive)',  [] ),
			self::make_trait_list_block( 'met-physical-traits-neg', 'Physical Traits (Negative)',  [] ),
			self::make_trait_list_block( 'met-social-traits',       'Social Traits (Positive)',    [] ),
			self::make_trait_list_block( 'met-social-traits-neg',   'Social Traits (Negative)',    [] ),
			self::make_trait_list_block( 'met-mental-traits',       'Mental Traits (Positive)',    [] ),
			self::make_trait_list_block( 'met-mental-traits-neg',   'Mental Traits (Negative)',    [] ),
			self::make_trait_list_block( 'met-abilities',           'Abilities',                   [], [ 'has_specializations' => true ] ),
			self::make_trait_list_block( 'met-merits',              'Merits',                      [], [ 'atomic' => true ] ),
			self::make_trait_list_block( 'met-flaws',               'Flaws',                       [], [ 'atomic' => true ] ),
			self::make_trait_list_block( 'met-derangements',        'Derangements',                [], [ 'atomic' => true ] ),
			self::make_identity_block(   'met-archetypes',          'Archetypes',                  [
				[ 'name' => 'Nature',  'field_type' => 'select', 'required' => false, 'options' => [] ],
				[ 'name' => 'Demeanor','field_type' => 'select', 'required' => false, 'options' => [] ],
			] ),
			self::make_identity_block(   'vampire-identity',        'Vampire Identity', [
				[ 'name' => 'Clan',          'field_type' => 'select', 'required' => true,  'allow_custom' => true ],
				[ 'name' => 'Sect',          'field_type' => 'select', 'required' => false ],
				[ 'name' => 'Generation',    'field_type' => 'number', 'required' => true, 'min' => 3, 'max' => 15, 'default' => 13 ],
				[ 'name' => 'Sire',          'field_type' => 'text',   'required' => false ],
				[ 'name' => 'Morality Path', 'field_type' => 'select', 'required' => true,  'allow_custom' => true ],
			] ),
			self::make_tiered_power_block( 'vampire-disciplines',       'Vampire Disciplines',   [], [ 'sequential' => true, 'atomic' => true ] ),
			self::make_trait_list_block(   'vampire-combo-disciplines', 'Combo Disciplines',     [] ),
			self::make_trait_list_block(   'vampire-rituals',           'Rituals',               [], [ 'atomic' => true ] ),
			self::make_trait_list_block(   'vampire-ritae',             'Ritae',                 [] ),
			self::make_trait_list_block(   'vampire-statuses',          'Vampire Status',        [] ),
			self::make_resource_block( 'vampire-resources', 'Vampire Resources', [
				[ 'name' => 'Blood',     'value_type' => 'integer', 'default_start' => 10, 'max' => 20 ],
				[ 'name' => 'Willpower', 'value_type' => 'integer', 'default_start' => 3,  'max' => 20 ],
				[ 'name' => 'Morality',  'value_type' => 'integer', 'default_start' => 7,  'max' => 10 ],
			] ),
			self::make_resource_block( 'vampire-virtues', 'Vampire Virtues', [
				[ 'name' => 'Conscience',   'value_type' => 'integer', 'default_start' => 1, 'max' => 5 ],
				[ 'name' => 'Self-Control', 'value_type' => 'integer', 'default_start' => 1, 'max' => 5 ],
				[ 'name' => 'Courage',      'value_type' => 'integer', 'default_start' => 1, 'max' => 5 ],
			] ),
			self::make_identity_block(    'werewolf-identity',    'Werewolf Identity', [
				[ 'name' => 'Tribe',   'field_type' => 'select', 'required' => true  ],
				[ 'name' => 'Breed',   'field_type' => 'select', 'required' => true  ],
				[ 'name' => 'Auspice', 'field_type' => 'select', 'required' => true  ],
				[ 'name' => 'Rank',    'field_type' => 'number', 'required' => false, 'default' => 1 ],
			] ),
			self::make_tiered_power_block( 'werewolf-gifts',    'Werewolf Gifts', [], [ 'atomic' => true ] ),
			self::make_trait_list_block(   'werewolf-rites',    'Werewolf Rites', [], [ 'atomic' => true ] ),
			self::make_resource_block( 'werewolf-resources', 'Werewolf Resources', [
				[ 'name' => 'Rage',      'value_type' => 'integer', 'default_start' => 1, 'max' => 10 ],
				[ 'name' => 'Gnosis',    'value_type' => 'integer', 'default_start' => 1, 'max' => 10 ],
				[ 'name' => 'Willpower', 'value_type' => 'integer', 'default_start' => 3, 'max' => 20 ],
			] ),
			self::make_resource_block( 'werewolf-renown', 'Werewolf Renown', [
				[ 'name' => 'Honor',  'value_type' => 'decimal', 'step' => 0.1, 'default_start' => 0 ],
				[ 'name' => 'Glory',  'value_type' => 'decimal', 'step' => 0.1, 'default_start' => 0 ],
				[ 'name' => 'Wisdom', 'value_type' => 'decimal', 'step' => 0.1, 'default_start' => 0 ],
			] ),
			self::make_identity_block(    'mage-identity',    'Mage Identity', [
				[ 'name' => 'Tradition', 'field_type' => 'select', 'required' => true  ],
				[ 'name' => 'Essence',   'field_type' => 'select', 'required' => false ],
			] ),
			self::make_tiered_power_block( 'mage-spheres',    'Mage Spheres',  [], [ 'sequential' => true, 'atomic' => true ] ),
			self::make_trait_list_block(   'mage-rotes',      'Mage Rotes',    [], [ 'atomic' => true ] ),
			self::make_resource_block( 'mage-resources', 'Mage Resources', [
				[ 'name' => 'Arete',        'value_type' => 'integer', 'default_start' => 1, 'max' => 10 ],
				[ 'name' => 'Quintessence', 'value_type' => 'integer', 'default_start' => 1, 'max' => 20 ],
				[ 'name' => 'Paradox',      'value_type' => 'integer', 'default_start' => 0, 'max' => 20 ],
				[ 'name' => 'Willpower',    'value_type' => 'integer', 'default_start' => 3, 'max' => 20 ],
			] ),
			self::make_identity_block(    'changeling-identity', 'Changeling Identity', [
				[ 'name' => 'Kith',    'field_type' => 'select', 'required' => true  ],
				[ 'name' => 'Seeming', 'field_type' => 'select', 'required' => true  ],
				[ 'name' => 'Court',   'field_type' => 'select', 'required' => false ],
			] ),
			self::make_tiered_power_block( 'changeling-arts',   'Changeling Arts',   [], [ 'sequential' => true, 'atomic' => true ] ),
			self::make_tiered_power_block( 'changeling-realms', 'Changeling Realms', [], [ 'sequential' => true, 'atomic' => true ] ),
			self::make_resource_block( 'changeling-resources', 'Changeling Resources', [
				[ 'name' => 'Glamour',   'value_type' => 'integer', 'default_start' => 3, 'max' => 10 ],
				[ 'name' => 'Banality',  'value_type' => 'integer', 'default_start' => 3, 'max' => 10 ],
				[ 'name' => 'Willpower', 'value_type' => 'integer', 'default_start' => 3, 'max' => 20 ],
			] ),
			self::make_identity_block( 'wraith-identity', 'Wraith Identity', [
				[ 'name' => 'Guild',   'field_type' => 'select', 'required' => false ],
				[ 'name' => 'Legion',  'field_type' => 'text',   'required' => false ],
				[ 'name' => 'Shadow',  'field_type' => 'text',   'required' => false ],
			] ),
			self::make_tiered_power_block( 'wraith-arcanoi', 'Wraith Arcanoi', [], [ 'sequential' => true, 'atomic' => true ] ),
			self::make_resource_block( 'wraith-resources', 'Wraith Resources', [
				[ 'name' => 'Pathos',    'value_type' => 'integer', 'default_start' => 3, 'max' => 10 ],
				[ 'name' => 'Corpus',    'value_type' => 'integer', 'default_start' => 7, 'max' => 10 ],
				[ 'name' => 'Willpower', 'value_type' => 'integer', 'default_start' => 3, 'max' => 20 ],
				[ 'name' => 'Angst',     'value_type' => 'integer', 'default_start' => 0, 'max' => 10 ],
			] ),
			self::make_identity_block( 'demon-identity', 'Demon Identity', [
				[ 'name' => 'House',   'field_type' => 'select', 'required' => true  ],
				[ 'name' => 'Faction', 'field_type' => 'select', 'required' => false ],
			] ),
			self::make_tiered_power_block( 'demon-lores', 'Demon Lores', [], [ 'sequential' => true, 'atomic' => true ] ),
			self::make_resource_block( 'demon-resources', 'Demon Resources', [
				[ 'name' => 'Faith',     'value_type' => 'integer', 'default_start' => 3, 'max' => 10 ],
				[ 'name' => 'Torment',   'value_type' => 'integer', 'default_start' => 0, 'max' => 10 ],
				[ 'name' => 'Willpower', 'value_type' => 'integer', 'default_start' => 3, 'max' => 20 ],
			] ),
			self::make_identity_block( 'mummy-identity', 'Mummy Identity', [
				[ 'name' => 'Amenti', 'field_type' => 'text', 'required' => false ],
			] ),
			self::make_tiered_power_block( 'mummy-hekau', 'Mummy Hekau', [], [ 'sequential' => true, 'atomic' => true ] ),
			self::make_resource_block( 'mummy-resources', 'Mummy Resources', [
				[ 'name' => 'Sekhem',    'value_type' => 'integer', 'default_start' => 3, 'max' => 10 ],
				[ 'name' => 'Balance',   'value_type' => 'integer', 'default_start' => 5, 'max' => 10 ],
				[ 'name' => 'Willpower', 'value_type' => 'integer', 'default_start' => 3, 'max' => 20 ],
			] ),
			self::make_identity_block( 'kueijin-identity', 'Kuei-Jin Identity', [
				[ 'name' => 'Dharma',  'field_type' => 'select', 'required' => true  ],
				[ 'name' => 'Station', 'field_type' => 'text',   'required' => false ],
			] ),
			self::make_tiered_power_block( 'kueijin-disciplines', 'Kuei-Jin Disciplines', [], [ 'sequential' => true, 'atomic' => true ] ),
			self::make_resource_block( 'kueijin-resources', 'Kuei-Jin Resources', [
				[ 'name' => 'Hun',       'value_type' => 'integer', 'default_start' => 3, 'max' => 10 ],
				[ 'name' => 'Po',        'value_type' => 'integer', 'default_start' => 3, 'max' => 10 ],
				[ 'name' => 'Yin Chi',   'value_type' => 'integer', 'default_start' => 3, 'max' => 10 ],
				[ 'name' => 'Yang Chi',  'value_type' => 'integer', 'default_start' => 3, 'max' => 10 ],
				[ 'name' => 'Demon Chi', 'value_type' => 'integer', 'default_start' => 0, 'max' => 10 ],
			] ),
			self::make_identity_block( 'mortal-identity', 'Mortal Identity', [
				[ 'name' => 'Motivation',  'field_type' => 'text', 'required' => false ],
				[ 'name' => 'Association', 'field_type' => 'text', 'required' => false ],
				[ 'name' => 'Revenant Family', 'field_type' => 'select', 'required' => false, 'allow_custom' => true, 'options' => [] ],
			] ),
			self::make_tiered_power_block( 'mortal-numina',     'Mortal Numina',     [], [ 'atomic' => true ] ),
			self::make_resource_block( 'mortal-resources', 'Mortal Resources', [
				[ 'name' => 'Willpower',  'value_type' => 'integer', 'default_start' => 3, 'max' => 20 ],
				[ 'name' => 'True Faith', 'value_type' => 'integer', 'default_start' => 0, 'max' => 10 ],
				[ 'name' => 'Humanity',   'value_type' => 'integer', 'default_start' => 7, 'max' => 10 ],
			] ),
			self::make_identity_block( 'fera-identity', 'Fera Identity', [
				[ 'name' => 'Fera Type', 'field_type' => 'select', 'required' => true  ],
				[ 'name' => 'Breed',     'field_type' => 'select', 'required' => true  ],
				[ 'name' => 'Rank',      'field_type' => 'number', 'required' => false, 'default' => 1 ],
			] ),
		];
	}

	// ---------------------------------------------------------------------------
	// CREATURE STACK SEED DATA
	// ---------------------------------------------------------------------------

	/**
	 * Builds the insert array for every system creature stack (Vampire,
	 * Werewolf, Mage, and so on), each listing the schema blocks that make
	 * up its character sheet and its own creation-step rules.
	 *
	 * @return array[]
	 */
	private static function get_stacks_to_seed(): array {
		$shared_sections = [
			[ 'block_slug' => 'met-archetypes',  'label' => 'Archetypes',      'display_order' => 20, 'required' => false ],
			[ 'block_slug' => 'met-abilities',   'label' => 'Abilities',       'display_order' => 30, 'required' => true  ],
			// Backgrounds is deliberately not here; each stack references its own {stack}-backgrounds block.
			[ 'block_slug' => 'met-merits',      'label' => 'Merits',          'display_order' => 90, 'required' => false ],
			[ 'block_slug' => 'met-flaws',       'label' => 'Flaws',           'display_order' => 91, 'required' => false ],
			[ 'block_slug' => 'met-derangements','label' => 'Derangements',    'display_order' => 92, 'required' => false ],
		];

		$shared_traits = [
			[ 'block_slug' => 'met-physical-traits', 'label' => 'Physical Traits', 'display_order' => 50, 'required' => true, 'negative_block_slug' => 'met-physical-traits-neg' ],
			[ 'block_slug' => 'met-social-traits',   'label' => 'Social Traits',   'display_order' => 51, 'required' => true, 'negative_block_slug' => 'met-social-traits-neg'   ],
			[ 'block_slug' => 'met-mental-traits',   'label' => 'Mental Traits',   'display_order' => 52, 'required' => true, 'negative_block_slug' => 'met-mental-traits-neg'   ],
		];

		return [
			// Vampire
			[
				'slug'      => 'vampire',
				'name'      => 'Vampire',
				'game_line' => 'met',
				'is_system' => 1,
				'stack_definition' => [
					'sections' => array_merge(
						[ [ 'block_slug' => 'vampire-identity', 'label' => 'Identity', 'display_order' => 1, 'required' => true ] ],
						$shared_traits,
						$shared_sections,
						[
							[ 'block_slug' => 'vampire-backgrounds',       'label' => 'Backgrounds',      'display_order' => 40, 'required' => false ],
							[ 'block_slug' => 'vampire-disciplines',       'label' => 'Disciplines',      'display_order' => 60, 'required' => true,  'in_type_source' => 'identity.Clan' ],
							[ 'block_slug' => 'vampire-combo-disciplines', 'label' => 'Combo Disciplines','display_order' => 61, 'required' => false ],
							[ 'block_slug' => 'vampire-rituals',           'label' => 'Rituals',          'display_order' => 62, 'required' => false ],
							[ 'block_slug' => 'vampire-ritae',             'label' => 'Ritae',            'display_order' => 63, 'required' => false ],
							[ 'block_slug' => 'vampire-statuses',          'label' => 'Status',           'display_order' => 70, 'required' => false ],
							[ 'block_slug' => 'vampire-resources',         'label' => 'Resources',        'display_order' => 80, 'required' => true  ],
							[ 'block_slug' => 'vampire-virtues',           'label' => 'Virtues',          'display_order' => 81, 'required' => true  ],
						]
					),
					'display_preferences' => [ 'discipline_display' => 'named', 'health_levels' => 7 ],
				],
				'creation_rules' => [
					'steps' => [
						[ 'step' => 1, 'label' => 'Inspiration',  'sections' => [ 'vampire-identity' ] ],
						[ 'step' => 2, 'label' => 'Attributes',   'sections' => [ 'met-physical-traits', 'met-social-traits', 'met-mental-traits' ], 'budget' => [ 'primary' => 7, 'secondary' => 5, 'tertiary' => 3 ], 'prioritize' => true ],
						[ 'step' => 3, 'label' => 'Advantages',   'sections' => [ 'met-abilities', 'vampire-disciplines', 'vampire-backgrounds' ], 'budgets' => [ 'met-abilities' => 5, 'vampire-disciplines' => 3, 'vampire-backgrounds' => 5 ] ],
						[ 'step' => 4, 'label' => 'Last Touches', 'sections' => [ 'vampire-resources', 'vampire-virtues', 'met-merits', 'met-flaws', 'met-derangements' ], 'free_traits' => 5 ],
					],
				],
			],

			// Werewolf
			[
				'slug'      => 'werewolf',
				'name'      => 'Werewolf',
				'game_line' => 'met',
				'is_system' => 1,
				'stack_definition' => [
					'sections' => array_merge(
						[ [ 'block_slug' => 'werewolf-identity', 'label' => 'Identity', 'display_order' => 1, 'required' => true ] ],
						$shared_traits, $shared_sections,
						[
							[ 'block_slug' => 'werewolf-backgrounds', 'label' => 'Backgrounds', 'display_order' => 40, 'required' => false ],
							[ 'block_slug' => 'werewolf-gifts',     'label' => 'Gifts',     'display_order' => 60, 'required' => true  ],
							[ 'block_slug' => 'werewolf-rites',     'label' => 'Rites',     'display_order' => 61, 'required' => false ],
							[ 'block_slug' => 'werewolf-resources', 'label' => 'Resources', 'display_order' => 80, 'required' => true  ],
							[ 'block_slug' => 'werewolf-renown',    'label' => 'Renown',    'display_order' => 81, 'required' => true  ],
						]
					),
				],
				'creation_rules' => [],
			],

			// Mage
			[
				'slug'      => 'mage',
				'name'      => 'Mage',
				'game_line' => 'met',
				'is_system' => 1,
				'stack_definition' => [
					'sections' => array_merge(
						[ [ 'block_slug' => 'mage-identity', 'label' => 'Identity', 'display_order' => 1, 'required' => true ] ],
						$shared_traits, $shared_sections,
						[
							[ 'block_slug' => 'mage-backgrounds', 'label' => 'Backgrounds', 'display_order' => 40, 'required' => false ],
							[ 'block_slug' => 'mage-spheres',    'label' => 'Spheres',    'display_order' => 60, 'required' => true  ],
							[ 'block_slug' => 'mage-rotes',      'label' => 'Rotes',      'display_order' => 61, 'required' => false ],
							[ 'block_slug' => 'mage-resources',  'label' => 'Resources',  'display_order' => 80, 'required' => true  ],
						]
					),
				],
				'creation_rules' => [],
			],

			// Changeling
			[
				'slug'      => 'changeling',
				'name'      => 'Changeling',
				'game_line' => 'met',
				'is_system' => 1,
				'stack_definition' => [
					'sections' => array_merge(
						[ [ 'block_slug' => 'changeling-identity', 'label' => 'Identity', 'display_order' => 1, 'required' => true ] ],
						$shared_traits, $shared_sections,
						[
							[ 'block_slug' => 'changeling-backgrounds', 'label' => 'Backgrounds', 'display_order' => 40, 'required' => false ],
							[ 'block_slug' => 'changeling-arts',      'label' => 'Arts',      'display_order' => 60, 'required' => true  ],
							[ 'block_slug' => 'changeling-realms',    'label' => 'Realms',    'display_order' => 61, 'required' => true  ],
							[ 'block_slug' => 'changeling-resources', 'label' => 'Resources', 'display_order' => 80, 'required' => true  ],
						]
					),
				],
				'creation_rules' => [],
			],

			// Wraith
			[
				'slug'      => 'wraith',
				'name'      => 'Wraith',
				'game_line' => 'met',
				'is_system' => 1,
				'stack_definition' => [
					'sections' => array_merge(
						[ [ 'block_slug' => 'wraith-identity', 'label' => 'Identity', 'display_order' => 1, 'required' => true ] ],
						$shared_traits, $shared_sections,
						[
							[ 'block_slug' => 'wraith-backgrounds', 'label' => 'Backgrounds', 'display_order' => 40, 'required' => false ],
							[ 'block_slug' => 'wraith-arcanoi',   'label' => 'Arcanoi',   'display_order' => 60, 'required' => true  ],
							[ 'block_slug' => 'wraith-resources', 'label' => 'Resources', 'display_order' => 80, 'required' => true  ],
						]
					),
				],
				'creation_rules' => [],
			],

			// Demon
			[
				'slug'      => 'demon',
				'name'      => 'Demon',
				'game_line' => 'met',
				'is_system' => 1,
				'stack_definition' => [
					'sections' => array_merge(
						[ [ 'block_slug' => 'demon-identity', 'label' => 'Identity', 'display_order' => 1, 'required' => true ] ],
						$shared_traits, $shared_sections,
						[
							[ 'block_slug' => 'demon-backgrounds', 'label' => 'Backgrounds', 'display_order' => 40, 'required' => false ],
							[ 'block_slug' => 'demon-lores',     'label' => 'Lores',     'display_order' => 60, 'required' => true  ],
							[ 'block_slug' => 'demon-resources', 'label' => 'Resources', 'display_order' => 80, 'required' => true  ],
						]
					),
				],
				'creation_rules' => [],
			],

			// Mummy
			[
				'slug'      => 'mummy',
				'name'      => 'Mummy',
				'game_line' => 'met',
				'is_system' => 1,
				'stack_definition' => [
					'sections' => array_merge(
						[ [ 'block_slug' => 'mummy-identity', 'label' => 'Identity', 'display_order' => 1, 'required' => true ] ],
						$shared_traits, $shared_sections,
						[
							[ 'block_slug' => 'mummy-backgrounds', 'label' => 'Backgrounds', 'display_order' => 40, 'required' => false ],
							[ 'block_slug' => 'mummy-hekau',     'label' => 'Hekau',     'display_order' => 60, 'required' => true  ],
							[ 'block_slug' => 'mummy-resources', 'label' => 'Resources', 'display_order' => 80, 'required' => true  ],
						]
					),
				],
				'creation_rules' => [],
			],

			// Kuei-Jin
			[
				'slug'      => 'kueijin',
				'name'      => 'Kuei-Jin',
				'game_line' => 'met',
				'is_system' => 1,
				'stack_definition' => [
					'sections' => array_merge(
						[ [ 'block_slug' => 'kueijin-identity', 'label' => 'Identity', 'display_order' => 1, 'required' => true ] ],
						$shared_traits, $shared_sections,
						[
							[ 'block_slug' => 'kueijin-backgrounds', 'label' => 'Backgrounds', 'display_order' => 40, 'required' => false ],
							[ 'block_slug' => 'kueijin-disciplines', 'label' => 'Disciplines', 'display_order' => 60, 'required' => true  ],
							[ 'block_slug' => 'kueijin-resources',   'label' => 'Resources',   'display_order' => 80, 'required' => true  ],
						]
					),
				],
				'creation_rules' => [],
			],

			// Mortal
			[
				'slug'      => 'mortal',
				'name'      => 'Mortal',
				'game_line' => 'met',
				'is_system' => 1,
				'stack_definition' => [
					'sections' => array_merge(
						[ [ 'block_slug' => 'mortal-identity', 'label' => 'Identity', 'display_order' => 1, 'required' => false ] ],
						$shared_traits, $shared_sections,
						[
							[ 'block_slug' => 'mortal-backgrounds', 'label' => 'Backgrounds', 'display_order' => 40, 'required' => false ],
							[ 'block_slug' => 'mortal-numina',    'label' => 'Numina',    'display_order' => 60, 'required' => false ],
							[ 'block_slug' => 'mortal-resources', 'label' => 'Resources', 'display_order' => 80, 'required' => true  ],
						]
					),
				],
				'creation_rules' => [],
			],

			// Fera
			[
				'slug'      => 'fera',
				'name'      => 'Fera',
				'game_line' => 'met',
				'is_system' => 1,
				'stack_definition' => [
					'sections' => array_merge(
						[ [ 'block_slug' => 'fera-identity', 'label' => 'Identity', 'display_order' => 1, 'required' => true ] ],
						$shared_traits, $shared_sections,
						[
							[ 'block_slug' => 'fera-backgrounds',   'label' => 'Backgrounds', 'display_order' => 40, 'required' => false ],
							[ 'block_slug' => 'fera-gifts',         'label' => 'Gifts',     'display_order' => 60, 'required' => true  ],
							[ 'block_slug' => 'werewolf-rites',     'label' => 'Rites',     'display_order' => 61, 'required' => false ],
							[ 'block_slug' => 'werewolf-resources', 'label' => 'Resources', 'display_order' => 80, 'required' => true  ],
							[ 'block_slug' => 'werewolf-renown',    'label' => 'Renown',    'display_order' => 81, 'required' => true  ],
						]
					),
				],
				'creation_rules' => [],
			],

			// Bete (legacy alias for Fera)
			[
				'slug'      => 'bete',
				'name'      => 'Bête',
				'game_line' => 'met',
				'is_system' => 1,
				'stack_definition' => [
					'sections' => array_merge(
						[ [ 'block_slug' => 'fera-identity', 'label' => 'Identity', 'display_order' => 1, 'required' => true ] ],
						$shared_traits, $shared_sections,
						[
							[ 'block_slug' => 'fera-backgrounds',   'label' => 'Backgrounds', 'display_order' => 40, 'required' => false ],
							[ 'block_slug' => 'fera-gifts',         'label' => 'Gifts',     'display_order' => 60, 'required' => true  ],
							[ 'block_slug' => 'werewolf-rites',     'label' => 'Rites',     'display_order' => 61, 'required' => false ],
							[ 'block_slug' => 'werewolf-resources', 'label' => 'Resources', 'display_order' => 80, 'required' => true  ],
							[ 'block_slug' => 'werewolf-renown',    'label' => 'Renown',    'display_order' => 81, 'required' => true  ],
						]
					),
				],
				'creation_rules' => [],
			],
		];
	}

	// ---------------------------------------------------------------------------
	// BLOCK FACTORY HELPERS
	// ---------------------------------------------------------------------------

	/**
	 * Builds a trait_list schema block insert array from a list of items.
	 * Normalizes each item to a plain name plus whichever of cost, note,
	 * source, group, subgroup, tier and description are actually present.
	 *
	 * @param string $slug
	 * @param string $name
	 * @param array  $items  Array of item name strings.
	 * @param array  $extra  Extra definition keys.
	 * @return array
	 */
	private static function make_trait_list_block( string $slug, string $name, array $items, array $extra = [] ): array {
		$item_objects = [];
		foreach ( $items as $item ) {
			// Accept either a bare name (hardcoded fallback) or a parsed GVM item.
			if ( ! is_array( $item ) ) {
				$item_objects[] = [ 'name' => (string) $item ];
				continue;
			}

			$object = [ 'name' => $item['name'] ];

			// Cost is free-text ('1', '1 or 3', '1-7'), never an integer.
			if ( ! empty( $item['cost'] ) ) {
				$object['cost'] = $item['cost'];
			}
			if ( ! empty( $item['note'] ) ) {
				$object['note'] = $item['note'];
			}
			// Which menu it came from. A Gift is meaningless without knowing whose it is.
			if ( ! empty( $item['source'] ) ) {
				$object['source'] = $item['source'];
			}
			// group/subgroup/tier are produced by resolve_block_source()'s 'pattern'+'label' transform.
			if ( ! empty( $item['group'] ) ) {
				$object['group'] = $item['group'];
			}
			if ( ! empty( $item['subgroup'] ) ) {
				$object['subgroup'] = $item['subgroup'];
			}
			if ( ! empty( $item['tier'] ) ) {
				$object['tier'] = $item['tier'];
			}
			// No seeder populates this; left as a real, empty, editable field for a chronicle admin.
			if ( ! empty( $item['description'] ) ) {
				$object['description'] = $item['description'];
			}

			$item_objects[] = $object;
		}
		return [
			'slug'         => $slug,
			'name'         => $name,
			'section_type' => 'trait_list',
			'definition'   => array_merge( [
				'items'           => $item_objects,
				'allow_multiples' => false,
				'allow_custom'    => true,
			], $extra ),
			'is_system'    => 1,
			'created_by'   => 0,
		];
	}

	/**
	 * Number of real dot-levels a "named" power family can own as a plain
	 * numeric total before Elder-and-above becomes an unordered pool of
	 * individually held named powers. A flat, generous cap rather than a
	 * per-family uniqueness detector.
	 */
	const NAMED_POWER_NUMBERED_LEVELS = 10;

	/**
	 * Normalizes a GVM item's free-text `note` ("basic", "elder assamite", "master
	 * brujah", "2nd ed.") into one of the real MET tier labels. Matched by substring,
	 * not equality - a clan/tribe-restricted variant or an edition tag rides along in
	 * the same note field and must not prevent the tier itself from being recognized.
	 *
	 * @param string $note
	 * @return string
	 */
	private static function normalize_tier( string $note ): string {
		$note = strtolower( $note );
		$map  = [
			'innate'       => 'innate',
			'basic'        => 'basic',
			'int'          => 'intermediate',
			'adv'          => 'advanced',
			'elder'        => 'elder',
			'master'       => 'master',
			'asc'          => 'ascended',
			'meth'         => 'methuselah',
		];
		foreach ( $map as $needle => $tier ) {
			if ( str_contains( $note, $needle ) ) {
				return $tier;
			}
		}
		return $note !== '' ? $note : 'unknown';
	}

	/**
	 * Builds a tiered_power block insert array.
	 * Converts each resolved power into a `levels` list, assigning a
	 * sequential numeric level to the first NAMED_POWER_NUMBERED_LEVELS
	 * non-innate items and leaving the rest as an unordered named pool.
	 *
	 * @param string $slug
	 * @param string $name
	 * @param array  $powers_in Resolved powers: each with 'name', 'source' and 'items',
	 *                          or a bare name string from the hardcoded fallback.
	 * @param array  $extra     Extra definition keys.
	 * @return array
	 */
	private static function make_tiered_power_block( string $slug, string $name, array $powers_in, array $extra = [] ): array {
		$powers = [];

		foreach ( $powers_in as $power ) {
			// A bare string is the hardcoded fallback shape: no level data available.
			if ( ! is_array( $power ) ) {
				$powers[] = [ 'name' => (string) $power, 'levels' => [] ];
				continue;
			}

			$levels = [];
			$index  = 0;

			foreach ( $power['items'] as $item ) {
				$note = is_array( $item ) ? (string) ( $item['note'] ?? '' ) : '';
				$tier = self::normalize_tier( $note );

				// Innate items (free, automatically known) don't consume a numbered-level slot.
				$numbered = $tier !== 'innate';
				if ( $numbered ) {
					$index++;
				}

				$level = [
					// Only the first NAMED_POWER_NUMBERED_LEVELS non-innate items get a numeric level; the rest are a named pool.
					'level'      => ( $numbered && $index <= self::NAMED_POWER_NUMBERED_LEVELS ) ? $index : null,
					'power_name' => is_array( $item ) ? $item['name'] : (string) $item,
					'tier'       => $tier,
				];

				// Real cost from the menu, kept as the free-text string it is.
				if ( is_array( $item ) && ! empty( $item['cost'] ) ) {
					$level['cost'] = $item['cost'];
				}
				if ( $note !== '' ) {
					$level['note'] = $note;
				}
				// No seeder populates this; left as a real, empty, editable field for a chronicle admin.
				if ( is_array( $item ) && ! empty( $item['description'] ) ) {
					$level['description'] = $item['description'];
				}

				$levels[] = $level;
			}

			$powers[] = [
				'name'   => $power['name'],
				'source' => $power['source'] ?? $power['name'],
				'levels' => $levels,
			];
		}
		return [
			'slug'         => $slug,
			'name'         => $name,
			'section_type' => 'tiered_power',
			'definition'   => array_merge( [
				'powers'                    => $powers,
				'sequential'                => false,
				'out_of_type_cost_modifier' => 1,
				// Same default as make_trait_list_block(); a chronicle's own homebrew power isn't blocked.
				'allow_custom'              => true,
			], $extra ),
			'is_system'    => 1,
			'created_by'   => 0,
		];
	}

	/**
	 * Adds a `name_lookup` to a Conscience or Self-Control resource pool,
	 * cross-referencing the matching per-axis identity field (Conscience
	 * or Conviction; Self-Control or Instinct) so the pool displays under
	 * whichever name the player chose. Leaves any other pool unchanged.
	 *
	 * @param array $pool One pool definition array (as passed to make_resource_block()).
	 * @return array
	 */
	private static function with_virtue_name_lookup( array $pool ): array {
		$axis_field = [
			'Conscience'   => 'Conscience or Conviction',
			'Self-Control' => 'Self-Control or Instinct',
		][ $pool['name'] ] ?? null;

		if ( $axis_field === null ) {
			return $pool;
		}

		// The field's own non-default option value IS the real virtue name; no naming table needed.
		$non_default_name = [
			'Conscience'   => 'Conviction',
			'Self-Control' => 'Instinct',
		][ $pool['name'] ];

		$pool['name_lookup'] = [
			'keyed_by' => [ 'block_slug' => 'vampire-identity', 'field' => $axis_field ],
			'table'    => [ $non_default_name => $non_default_name ],
		];

		return $pool;
	}

	/**
	 * Builds a resource_pool schema block insert array.
	 * Wraps the given pool definitions (e.g. Blood, Willpower, Morality
	 * for Vampire) as-is in the block's definition.
	 *
	 * @param string $slug
	 * @param string $name
	 * @param array  $pools  Pool definition arrays.
	 * @return array
	 */
	private static function make_resource_block( string $slug, string $name, array $pools ): array {
		return [
			'slug'         => $slug,
			'name'         => $name,
			'section_type' => 'resource_pool',
			'definition'   => [ 'pools' => $pools ],
			'is_system'    => 1,
			'created_by'   => 0,
		];
	}

	/**
	 * Builds an identity_field schema block insert array.
	 * Wraps the given field definitions (e.g. Clan, Sect, Generation for
	 * Vampire) as-is in the block's definition, merged with any extra keys.
	 *
	 * @param string $slug
	 * @param string $name
	 * @param array  $fields  Field definition arrays.
	 * @return array
	 */
	private static function make_identity_block( string $slug, string $name, array $fields, array $extra = [] ): array {
		return [
			'slug'         => $slug,
			'name'         => $name,
			'section_type' => 'identity_field',
			'definition'   => array_merge( [ 'fields' => $fields ], $extra ),
			'is_system'    => 1,
			'created_by'   => 0,
		];
	}

	/**
	 * Builds the Storyteller-only NPC roleplaying-notes block.
	 *
	 * Eight prose fields describing how an NPC is played, carried on the
	 * NPC sheet in addition to the ordinary character sheet. Flagged
	 * storyteller_only, so neither its section nor its stored values reach
	 * a viewer without be_manage_characters.
	 *
	 * @return array
	 */
	private static function make_npc_roleplaying_notes_block(): array {
		$fields = [];
		foreach ( [
			'Voice & Tone',
			'Emotional Range',
			'Posture & Movement',
			'Public Behavior',
			'Private Behavior',
			'Combat Style',
			'Philosophy & Beliefs',
			'Theme Statement',
		] as $name ) {
			$fields[] = [ 'name' => $name, 'field_type' => 'textarea', 'required' => false ];
		}

		$block = self::make_identity_block( 'npc-roleplaying-notes', 'NPC Roleplaying Notes', $fields );
		$block['storyteller_only'] = 1;

		return $block;
	}

	// ---------------------------------------------------------------------------
	// DEFAULT TEMPLATES (Step 8)
	// ---------------------------------------------------------------------------

	/**
	 * Seeds one `sheet_full` global template per creature stack.
	 *
	 * A stack with a shipped Grapevine HTML sheet gets that sheet ported
	 * into layout JSON; a stack with no shipped equivalent falls back to
	 * Layout_Generator. Idempotent: skipped per stack when a global
	 * `sheet_full` template already exists, so a chronicle's customized
	 * default is never clobbered on upgrade.
	 *
	 * @see BE_PROCESS/workflow-0.3.md Step 8
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
	 *
	 * An NPC sheet is that stack's own full sheet plus the Storyteller-only
	 * roleplaying-notes section appended in the last column. Idempotent and
	 * skipped per stack when a global `npc_full` template already exists, so
	 * a chronicle's customized NPC sheet is never clobbered on upgrade.
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
	}

	/**
	 * Numbers a flat [block_slug, column, display] entry list into full
	 * layout sections, assigning a single sequential `order` down the
	 * whole list. Accepts an optional 4th tuple element naming a
	 * cross-block `title_refs` reference for that section.
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
	 * Per-stack [block_slug, width, display] lists defining each creature
	 * stack's default sheet layout. `width` is 'third' (three per row),
	 * 'half' (two per row), or 'full' (one per row); build_layout_sections()
	 * assigns a single flowing `order` down the list, and the front end's
	 * grid auto-wraps a fresh row once a row's widths sum to 6 - so list
	 * order here is row order, with no explicit row numbers needed.
	 *
	 * @return array<string,array>
	 */
	private static function default_template_sections(): array {
		$dot = 'multiplier_dot';

		$vampire = [
			// Resources pairs with Virtues, not Identity, since Morality lives inside vampire-resources.
			[ 'vampire-identity', 'half', null ], [ 'met-archetypes', 'half', null ],
			[ 'vampire-resources', 'half', null ], [ 'vampire-virtues', 'half', null, [
				[ 'block_slug' => 'vampire-identity', 'field' => 'Morality Path' ],
				[ 'block_slug' => 'vampire-resources', 'field' => 'Morality' ],
			] ],
			[ 'met-physical-traits', 'third', $dot ], [ 'met-social-traits', 'third', $dot ], [ 'met-mental-traits', 'third', $dot ],
			[ 'met-physical-traits-neg', 'third', null ], [ 'met-social-traits-neg', 'third', null ], [ 'met-mental-traits-neg', 'third', null ],
			[ 'met-abilities', 'half', $dot ], [ 'vampire-backgrounds', 'half', $dot ],
			[ 'vampire-disciplines', 'full', null ],
			[ 'vampire-combo-disciplines', 'full', null ],
			[ 'vampire-rituals', 'half', null ], [ 'vampire-ritae', 'half', null ],
			[ 'vampire-statuses', 'full', $dot ],
			[ 'met-merits', 'third', null ], [ 'met-flaws', 'third', null ], [ 'met-derangements', 'third', null ],
		];

		// Fera reuses Werewolf's rite/resource/renown blocks but has its own Backgrounds and Gifts blocks.
		$werewolf_like = static function ( string $identity_slug, string $backgrounds_slug, string $gifts_slug ): array {
			return [
				[ $identity_slug, 'half', null ], [ 'werewolf-renown', 'half', null ],
				[ 'werewolf-resources', 'half', null ], [ 'met-archetypes', 'half', null ],
				[ 'met-physical-traits', 'third', 'multiplier_dot' ], [ 'met-social-traits', 'third', 'multiplier_dot' ], [ 'met-mental-traits', 'third', 'multiplier_dot' ],
				[ 'met-physical-traits-neg', 'third', null ], [ 'met-social-traits-neg', 'third', null ], [ 'met-mental-traits-neg', 'third', null ],
				[ 'met-abilities', 'half', 'multiplier_dot' ], [ $backgrounds_slug, 'half', 'multiplier_dot' ],
				[ $gifts_slug, 'full', null ],
				[ 'werewolf-rites', 'full', null ],
				[ 'met-merits', 'half', null ], [ 'met-flaws', 'half', null ],
			];
		};

		$mage = [
			[ 'mage-identity', 'half', null ], [ 'met-archetypes', 'half', null ],
			[ 'mage-resources', 'full', null ],
			[ 'met-physical-traits', 'third', $dot ], [ 'met-social-traits', 'third', $dot ], [ 'met-mental-traits', 'third', $dot ],
			[ 'met-physical-traits-neg', 'third', null ], [ 'met-social-traits-neg', 'third', null ], [ 'met-mental-traits-neg', 'third', null ],
			[ 'met-abilities', 'half', $dot ], [ 'mage-backgrounds', 'half', $dot ],
			[ 'mage-spheres', 'full', null ],
			[ 'mage-rotes', 'full', null ],
			[ 'met-merits', 'half', null ], [ 'met-flaws', 'half', null ],
		];

		$changeling = [
			[ 'changeling-identity', 'half', null ], [ 'met-archetypes', 'half', null ],
			[ 'changeling-resources', 'full', null ],
			[ 'met-physical-traits', 'third', $dot ], [ 'met-social-traits', 'third', $dot ], [ 'met-mental-traits', 'third', $dot ],
			[ 'met-physical-traits-neg', 'third', null ], [ 'met-social-traits-neg', 'third', null ], [ 'met-mental-traits-neg', 'third', null ],
			[ 'met-abilities', 'half', $dot ], [ 'changeling-backgrounds', 'half', $dot ],
			[ 'changeling-arts', 'full', null ],
			[ 'changeling-realms', 'full', null ],
			[ 'met-merits', 'half', null ], [ 'met-flaws', 'half', null ],
		];

		$wraith = [
			[ 'wraith-identity', 'half', null ], [ 'met-archetypes', 'half', null ],
			[ 'wraith-resources', 'full', null ],
			[ 'met-physical-traits', 'third', $dot ], [ 'met-social-traits', 'third', $dot ], [ 'met-mental-traits', 'third', $dot ],
			[ 'met-physical-traits-neg', 'third', null ], [ 'met-social-traits-neg', 'third', null ], [ 'met-mental-traits-neg', 'third', null ],
			[ 'met-abilities', 'half', $dot ], [ 'wraith-backgrounds', 'half', $dot ],
			[ 'wraith-arcanoi', 'full', null ],
			[ 'met-merits', 'half', null ], [ 'met-flaws', 'half', null ],
		];

		$demon = [
			[ 'demon-identity', 'half', null ], [ 'met-archetypes', 'half', null ],
			[ 'demon-resources', 'full', null ],
			[ 'met-physical-traits', 'third', $dot ], [ 'met-social-traits', 'third', $dot ], [ 'met-mental-traits', 'third', $dot ],
			[ 'met-physical-traits-neg', 'third', null ], [ 'met-social-traits-neg', 'third', null ], [ 'met-mental-traits-neg', 'third', null ],
			[ 'met-abilities', 'half', $dot ], [ 'demon-backgrounds', 'half', $dot ],
			[ 'demon-lores', 'full', null ],
			[ 'met-merits', 'half', null ], [ 'met-flaws', 'half', null ],
		];

		$mortal = [
			[ 'mortal-identity', 'half', null ], [ 'met-archetypes', 'half', null ],
			[ 'mortal-resources', 'full', null ],
			[ 'met-physical-traits', 'third', $dot ], [ 'met-social-traits', 'third', $dot ], [ 'met-mental-traits', 'third', $dot ],
			[ 'met-physical-traits-neg', 'third', null ], [ 'met-social-traits-neg', 'third', null ], [ 'met-mental-traits-neg', 'third', null ],
			[ 'met-abilities', 'half', $dot ], [ 'mortal-backgrounds', 'half', $dot ],
			[ 'mortal-numina', 'full', null ],
			[ 'met-merits', 'third', null ], [ 'met-flaws', 'third', null ], [ 'met-derangements', 'third', null ],
		];

		$mummy = [
			[ 'mummy-identity', 'half', null ], [ 'met-archetypes', 'half', null ],
			[ 'mummy-resources', 'full', null ],
			[ 'met-physical-traits', 'third', $dot ], [ 'met-social-traits', 'third', $dot ], [ 'met-mental-traits', 'third', $dot ],
			[ 'met-physical-traits-neg', 'third', null ], [ 'met-social-traits-neg', 'third', null ], [ 'met-mental-traits-neg', 'third', null ],
			[ 'met-abilities', 'half', $dot ], [ 'mummy-backgrounds', 'half', $dot ],
			[ 'mummy-hekau', 'full', null ],
			[ 'met-merits', 'half', null ], [ 'met-flaws', 'half', null ],
		];

		$kueijin = [
			[ 'kueijin-identity', 'half', null ], [ 'met-archetypes', 'half', null ],
			[ 'kueijin-resources', 'full', null ],
			[ 'met-physical-traits', 'third', $dot ], [ 'met-social-traits', 'third', $dot ], [ 'met-mental-traits', 'third', $dot ],
			[ 'met-physical-traits-neg', 'third', null ], [ 'met-social-traits-neg', 'third', null ], [ 'met-mental-traits-neg', 'third', null ],
			[ 'met-abilities', 'half', $dot ], [ 'kueijin-backgrounds', 'half', $dot ],
			[ 'kueijin-disciplines', 'full', null ],
			[ 'met-merits', 'half', null ], [ 'met-flaws', 'half', null ],
		];

		$fera = $werewolf_like( 'fera-identity', 'fera-backgrounds', 'fera-gifts' );

		return [
			'vampire'    => $vampire,
			'werewolf'   => $werewolf_like( 'werewolf-identity', 'werewolf-backgrounds', 'werewolf-gifts' ),
			'mage'       => $mage,
			'changeling' => $changeling,
			'wraith'     => $wraith,
			'demon'      => $demon,
			'mortal'     => $mortal,
			'mummy'      => $mummy,
			'kueijin'    => $kueijin,
			'fera'       => $fera,
			'bete'       => $fera,
		];
	}

	/**
	 * The current correct `sheet_full` layout for one stack, in the same shape
	 * `seed_default_templates()` writes for a fresh install. Used by
	 * `Schema::repair_stale_default_layouts()` to bring an already-seeded template up to
	 * date without re-running the "skip if it already exists" seeder.
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
	 * Seeds 22 demo characters (2 per creature stack, all 11 types) into a
	 * dedicated `be-demo` game, created first if it does not exist yet -
	 * never into a real chronicle's own game.
	 *
	 * Idempotent per character, checked by name + owner_slug. Never throws:
	 * a fixture entry referencing a block that does not resolve is logged
	 * and skipped rather than failing the whole activation.
	 */
	public static function seed_demo_characters(): void {
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
		}

		$fixtures = require __DIR__ . '/demo-characters.php';

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
