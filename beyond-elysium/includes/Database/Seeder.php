<?php

namespace BeyondElysium\Database;

use BeyondElysium\Models\Schema_Block;
use BeyondElysium\Models\Creature_Stack;
use BeyondElysium\Models\Template;
use BeyondElysium\Services\GVM_Parser;
use BeyondElysium\Services\GEX_Xml_Parser;
use BeyondElysium\Services\Layout_Generator;
use BeyondElysium\Services\MET_CSV_Parser;
use BeyondElysium\Services\Grimoire_CSV_Parser;

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
	 * blocks it covers - see apply_met_csv_overrides() and BE_PROCESS/releases/workflow-0.10.md. Missing
	 * or malformed falls back to exactly today's GVM-only behavior, same as a missing GVM file
	 * falls back to hardcoded_blocks().
	 */
	const MET_CSV_PATH = __DIR__ . '/../../data/met-mechanics.csv';

	/**
	 * Path to the real Mage Rotes exchange file, relative to this file. Closes the
	 * BE_PROCESS/releases/0.99.2-workflow.md "`mage-rotes` ships as an empty catalog" defect - the
	 * data (201 real rotes) and the reader (Services\GEX_Xml_Parser, already tested against
	 * this exact file) both already existed; nothing had ever joined them. Missing or
	 * malformed falls back to the pre-existing empty catalog, same graceful-degradation
	 * style as a missing GVM/CSV file.
	 */
	const MAGE_ROTES_PATH = __DIR__ . '/../../data/Rotes.gex';

	/**
	 * Path to the extracted Enlightened Grimoire rotes CSV (670 rows: 603 net-new rotes
	 * plus category data for the 67 that match the existing 201 GEX rotes, as measured in
	 * Decision 093), relative to this file. Generated once, offline, by `tools/grimoire/` (repo root, outside this
	 * shippable subfolder) - never a runtime PDF read. See
	 * BE_PROCESS/design/mage-rotes-grimoire-design.md §8. Missing or malformed falls back to the
	 * pre-existing base catalog unchanged, same graceful-degradation style as every other
	 * seeded source file.
	 */
	const GRIMOIRE_ROTES_PATH = __DIR__ . '/../../data/grimoire-rotes.csv';

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
				$block = self::preserve_admin_edits( $block, $existing->definition );
				Schema_Block::update( $block['slug'], $block );
				// And every chronicle's copy of it, keeping what each chronicle changed (1.0.0-review F-034).
				Schema_Block::refresh_forks( $block['slug'] );
			}
		}
	}

	/**
	 * Keys on a catalog entry that only ever come from a site administrator -
	 * the seed data never writes them - by the definition list they live in.
	 * `levels` are a tiered_power family's own levels.
	 */
	const ADMIN_OWNED_ENTRY_KEYS = [
		'items'  => [ 'description', 'approval', 'reason', 'approval_by_value' ],
		'powers' => [ 'description', 'approval_override' ],
		'levels' => [ 'description', 'approval', 'reason' ],
		'pools'  => [ 'description', 'approval_by_value' ],
		'fields' => [ 'description', 'approval_by_option' ],
	];

	/** Definition-wide keys only an administrator sets. */
	const ADMIN_OWNED_BLOCK_KEYS = [ 'approval_rules' ];

	/**
	 * Carries a site administrator's own edits to a system block across a
	 * reseed (1.0.0-review F-011; owner ruling 2026-09-14: an update refreshes
	 * only what the seed data owns). `Schema_Block::update()` replaces a system
	 * block's whole `definition` on every version bump, which is right for
	 * everything sourced from GVM/CSV data - names, costs, notes, translations -
	 * and wrong for the keys in ADMIN_OWNED_ENTRY_KEYS / ADMIN_OWNED_BLOCK_KEYS,
	 * which exist only because an administrator set them. Those are copied from
	 * the stored entry onto the fresh one, matched by name (a level by its
	 * `power_name`, or its number when it has none). An entry the administrator
	 * added (`admin_added`, stamped by `mark_admin_additions()`) that the seed
	 * data does not have is kept; any other entry the seed data dropped goes.
	 *
	 * A renamed source entry legitimately loses its old edits rather than the
	 * reseed guessing which new entry they belong to (Decision 043's tie rule).
	 *
	 * @param array<string,mixed> $new_block
	 * @param object              $old_definition
	 * @return array<string,mixed>
	 */
	private static function preserve_admin_edits( array $new_block, object $old_definition ): array {
		$old = json_decode( (string) wp_json_encode( $old_definition ), true );
		if ( ! is_array( $old ) || ! isset( $new_block['definition'] ) || ! is_array( $new_block['definition'] ) ) {
			return $new_block;
		}
		$new = $new_block['definition'];

		foreach ( self::ADMIN_OWNED_BLOCK_KEYS as $key ) {
			if ( array_key_exists( $key, $old ) && ! array_key_exists( $key, $new ) ) {
				$new[ $key ] = $old[ $key ];
			}
		}

		// `_meta` cannot use the rule above: the seeder emits it on every run, so it is never
		// absent and that branch could never fire. Only the keys an administrator actually
		// changed are carried forward - everything else takes the fresh value, which is what
		// keeps 1.3.0's catalog corrections arriving (1.2.10 E5).
		if ( isset( $old['_meta'] ) && is_array( $old['_meta'] ) && isset( $new['_meta'] ) && is_array( $new['_meta'] ) ) {
			$new['_meta'] = self::apply_admin_meta( $new['_meta'], $old['_meta'] );
		}

		foreach ( [ 'items', 'pools', 'fields' ] as $list ) {
			if ( isset( $old[ $list ] ) && is_array( $old[ $list ] ) ) {
				$new[ $list ] = self::merge_admin_entries( (array) ( $new[ $list ] ?? [] ), $old[ $list ], self::ADMIN_OWNED_ENTRY_KEYS[ $list ], 'name' );
			}
		}

		if ( isset( $old['powers'] ) && is_array( $old['powers'] ) ) {
			$new['powers'] = self::merge_admin_entries(
				(array) ( $new['powers'] ?? [] ),
				$old['powers'],
				self::ADMIN_OWNED_ENTRY_KEYS['powers'],
				'name',
				static function ( array $new_power, array $old_power ): array {
					$new_power['levels'] = self::merge_admin_entries(
						(array) ( $new_power['levels'] ?? [] ),
						(array) ( $old_power['levels'] ?? [] ),
						self::ADMIN_OWNED_ENTRY_KEYS['levels'],
						'power_name'
					);
					return $new_power;
				}
			);
		}

		$new_block['definition'] = $new;
		return $new_block;
	}

	/**
	 * Merges one definition list: each fresh entry takes the stored entry's
	 * admin-owned keys, and stored entries an administrator added that the
	 * fresh list lacks are appended.
	 *
	 * @param array<int,mixed>      $new_entries
	 * @param array<int,mixed>      $old_entries
	 * @param array<int,string>     $keys
	 * @param string                $id_key
	 * @param callable|null         $nested Merges an entry's own nested list, given (new entry, old entry).
	 * @return array<int,mixed>
	 */
	private static function merge_admin_entries( array $new_entries, array $old_entries, array $keys, string $id_key, ?callable $nested = null ): array {
		$old_by_id = [];
		foreach ( $old_entries as $entry ) {
			if ( is_array( $entry ) ) {
				$old_by_id[ self::entry_id( $entry, $id_key ) ] = $entry;
			}
		}

		$present = [];
		foreach ( $new_entries as &$entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			$id             = self::entry_id( $entry, $id_key );
			$present[ $id ] = true;
			if ( ! isset( $old_by_id[ $id ] ) ) {
				continue;
			}
			foreach ( $keys as $key ) {
				if ( array_key_exists( $key, $old_by_id[ $id ] ) ) {
					$entry[ $key ] = $old_by_id[ $id ][ $key ];
				}
			}
			if ( $nested !== null ) {
				$entry = $nested( $entry, $old_by_id[ $id ] );
			}
		}
		unset( $entry );

		foreach ( $old_by_id as $id => $old_entry ) {
			if ( ! isset( $present[ $id ] ) && ! empty( $old_entry['admin_added'] ) ) {
				$new_entries[] = $old_entry;
			}
		}

		return $new_entries;
	}

	/**
	 * An entry's identity within its list: its `$id_key` value, or for a level
	 * with no power name, its level number.
	 *
	 * @param array<string,mixed> $entry
	 * @param string              $id_key
	 */
	private static function entry_id( array $entry, string $id_key ): string {
		if ( isset( $entry[ $id_key ] ) && (string) $entry[ $id_key ] !== '' ) {
			return (string) $entry[ $id_key ];
		}
		return '#' . (string) ( $entry['level'] ?? '' );
	}

	/**
	 * Stamps `admin_added` on every entry a site administrator's edit adds to a
	 * system block - an item, power, level, pool, or field whose name the
	 * stored definition does not have - so the next reseed keeps it rather than
	 * treating it as something the seed data dropped (F-011). Entries already
	 * marked keep their mark.
	 *
	 * @param object              $stored   The block's stored definition.
	 * @param array<string,mixed> $incoming The definition being saved.
	 * @return array<string,mixed>
	 */
	public static function mark_admin_additions( object $stored, array $incoming ): array {
		$old = json_decode( (string) wp_json_encode( $stored ), true );
		$old = is_array( $old ) ? $old : [];

		foreach ( [ 'items', 'pools', 'fields', 'powers' ] as $list ) {
			if ( ! isset( $incoming[ $list ] ) || ! is_array( $incoming[ $list ] ) ) {
				continue;
			}
			$old_by_id = [];
			foreach ( (array) ( $old[ $list ] ?? [] ) as $entry ) {
				if ( is_array( $entry ) ) {
					$old_by_id[ self::entry_id( $entry, 'name' ) ] = $entry;
				}
			}
			foreach ( $incoming[ $list ] as &$entry ) {
				if ( ! is_array( $entry ) ) {
					continue;
				}
				$id = self::entry_id( $entry, 'name' );
				if ( ! isset( $old_by_id[ $id ] ) ) {
					$entry['admin_added'] = true;
				} elseif ( $list === 'powers' && isset( $entry['levels'] ) && is_array( $entry['levels'] ) ) {
					$known_levels = [];
					foreach ( (array) ( $old_by_id[ $id ]['levels'] ?? [] ) as $level ) {
						if ( is_array( $level ) ) {
							$known_levels[ self::entry_id( $level, 'power_name' ) ] = true;
						}
					}
					foreach ( $entry['levels'] as &$level ) {
						if ( is_array( $level ) && ! isset( $known_levels[ self::entry_id( $level, 'power_name' ) ] ) ) {
							$level['admin_added'] = true;
						}
					}
					unset( $level );
				}
			}
			unset( $entry );
		}

		$incoming = self::stamp_admin_meta( (array) ( $old['_meta'] ?? [] ), $incoming );

		return $incoming;
	}

	/**
	 * Records which `_meta` keys a human actually changed, so a reseed can keep those and
	 * still deliver every correction to the ones they did not (1.2.10 E5; owner ruling,
	 * 2026-09-22: *"per-key stamp - record which keys the admin set"*).
	 *
	 * **Derived server-side by diffing, never supplied by the client** - the same shape
	 * `mark_admin_additions()` already uses for `admin_added`, and the reason E5's editor and
	 * its persistence turned out not to be coupled after all: the screen sends an edited
	 * definition exactly as it always has, and this notices what moved.
	 *
	 * **Why per *path* and not per top-level key.** `costs`, `ladder`, `out_of_type` and
	 * `levels` are themselves maps. Stamping the whole `costs` map because a chronicle
	 * house-ruled `basic` would freeze `elder` too - so 1.3.0's D69/D70 corrections would
	 * stop arriving for every rank in that block, which is exactly the failure this stamp
	 * exists to avoid. Paths go one level in: `costs.basic`, `untiered.cost_per_level`.
	 * `ranks` and `categories` are ordered lists where a single changed element changes the
	 * meaning of the rest, so those stamp whole.
	 *
	 * @param array<string,mixed> $old_meta
	 * @param array<string,mixed> $incoming
	 * @return array<string,mixed>
	 */
	public static function stamp_admin_meta( array $old_meta, array $incoming ): array {
		if ( ! isset( $incoming['_meta'] ) || ! is_array( $incoming['_meta'] ) ) {
			return $incoming;
		}
		$new_meta = $incoming['_meta'];

		$stamped = array_values( array_filter(
			(array) ( $old_meta['_admin_set'] ?? [] ),
			'is_string'
		) );

		foreach ( self::admin_meta_paths( $old_meta, $new_meta ) as $path ) {
			if ( self::meta_at( $old_meta, $path ) !== self::meta_at( $new_meta, $path ) && ! in_array( $path, $stamped, true ) ) {
				$stamped[] = $path;
			}
		}

		sort( $stamped );
		if ( $stamped !== [] ) {
			$new_meta['_admin_set'] = $stamped;
		}
		$incoming['_meta'] = $new_meta;
		return $incoming;
	}

	/**
	 * Every comparable path across the old and new meta - top-level keys, plus one level
	 * into the map-valued ones. A key present on only one side still yields a path, so
	 * adding or clearing a value counts as an edit.
	 *
	 * @param array<string,mixed> $old_meta
	 * @param array<string,mixed> $new_meta
	 * @return string[]
	 */
	private static function admin_meta_paths( array $old_meta, array $new_meta ): array {
		$whole = [ 'ranks', 'categories' ];
		$paths = [];

		foreach ( array_unique( array_merge( array_keys( $old_meta ), array_keys( $new_meta ) ) ) as $key ) {
			$key = (string) $key;
			if ( $key === '_admin_set' ) {
				continue;
			}
			$old_value = $old_meta[ $key ] ?? null;
			$new_value = $new_meta[ $key ] ?? null;
			$nested    = ! in_array( $key, $whole, true )
				&& ( self::is_string_keyed( $old_value ) || self::is_string_keyed( $new_value ) );

			if ( ! $nested ) {
				$paths[] = $key;
				continue;
			}
			$sub = array_unique( array_merge(
				array_keys( is_array( $old_value ) ? $old_value : [] ),
				array_keys( is_array( $new_value ) ? $new_value : [] )
			) );
			foreach ( $sub as $sub_key ) {
				$paths[] = $key . '.' . (string) $sub_key;
			}
		}
		return $paths;
	}

	/** True for a map (string keys), false for a list or a scalar. */
	private static function is_string_keyed( $value ): bool {
		if ( ! is_array( $value ) || $value === [] ) {
			return false;
		}
		foreach ( array_keys( $value ) as $k ) {
			if ( is_string( $k ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Reads a dotted path out of a meta array; null when any step is absent.
	 *
	 * @param array<string,mixed> $meta
	 */
	private static function meta_at( array $meta, string $path ) {
		$parts = explode( '.', $path, 2 );
		$head  = $meta[ $parts[0] ] ?? null;
		if ( count( $parts ) === 1 ) {
			return $head;
		}
		return is_array( $head ) ? ( $head[ $parts[1] ] ?? null ) : null;
	}

	/**
	 * Carries an administrator's own `_meta` values across a reseed - and **only** those,
	 * so every key they never touched still takes the fresh seeded value (1.2.10 E5).
	 *
	 * This is the half that makes the whole thing safe. Keeping a stored `_meta` wholesale
	 * would mean 1.3.0's Wraith (D69) and Mage (D70) cost corrections could never reach any
	 * installed site, since every install has a stored `_meta` after its first seed.
	 *
	 * @param array<string,mixed> $fresh_meta  What the seeder just built.
	 * @param array<string,mixed> $stored_meta What the site currently holds.
	 * @return array<string,mixed>
	 */
	public static function apply_admin_meta( array $fresh_meta, array $stored_meta ): array {
		$stamped = array_values( array_filter(
			(array) ( $stored_meta['_admin_set'] ?? [] ),
			'is_string'
		) );
		if ( $stamped === [] ) {
			return $fresh_meta;
		}

		foreach ( $stamped as $path ) {
			$parts = explode( '.', $path, 2 );
			$value = self::meta_at( $stored_meta, $path );

			if ( count( $parts ) === 1 ) {
				if ( $value === null ) {
					unset( $fresh_meta[ $parts[0] ] );
				} else {
					$fresh_meta[ $parts[0] ] = $value;
				}
				continue;
			}
			if ( ! isset( $fresh_meta[ $parts[0] ] ) || ! is_array( $fresh_meta[ $parts[0] ] ) ) {
				$fresh_meta[ $parts[0] ] = [];
			}
			if ( $value === null ) {
				unset( $fresh_meta[ $parts[0] ][ $parts[1] ] );
			} else {
				$fresh_meta[ $parts[0] ][ $parts[1] ] = $value;
			}
		}

		$fresh_meta['_admin_set'] = $stamped;
		return $fresh_meta;
	}

	/**
	 * Seed all system creature stacks.
	 *
	 * Idempotent: system stacks are refreshed from the definitions in this file, custom
	 * stacks are left alone. Same reasoning as seed_schema_blocks(), and the same
	 * rule for an administrator's additions (preserve_admin_sections()).
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
				Creature_Stack::update( $stack['slug'], self::preserve_admin_sections( $stack, $existing->stack_definition ) );
			}
		}
	}

	/**
	 * Keeps the sections a site administrator added to a system creature stack
	 * (`admin_added`, stamped by `mark_admin_stack_sections()`) across a reseed,
	 * when the seed data does not list that block itself (F-011). Everything
	 * else about the stack - its name, its own sections, creation rules - is
	 * the seed data's.
	 *
	 * @param array<string,mixed> $stack          The fresh stack from the seed data.
	 * @param mixed               $old_definition The stored stack_definition.
	 * @return array<string,mixed>
	 */
	private static function preserve_admin_sections( array $stack, $old_definition ): array {
		$old = json_decode( (string) wp_json_encode( $old_definition ), true );
		if ( ! is_array( $old ) || empty( $old['sections'] ) || ! isset( $stack['stack_definition']['sections'] ) ) {
			return $stack;
		}

		$seeded = array_column( $stack['stack_definition']['sections'], 'block_slug' );
		foreach ( $old['sections'] as $section ) {
			if ( is_array( $section ) && ! empty( $section['admin_added'] ) && ! in_array( $section['block_slug'] ?? null, $seeded, true ) ) {
				$stack['stack_definition']['sections'][] = $section;
			}
		}
		return $stack;
	}

	/**
	 * Stamps `admin_added` on each section a site administrator's edit adds to
	 * a system creature stack - one whose block the stored stack does not list.
	 *
	 * @param mixed               $stored   The stack's stored stack_definition.
	 * @param array<string,mixed> $incoming The stack_definition being saved.
	 * @return array<string,mixed>
	 */
	public static function mark_admin_stack_sections( $stored, array $incoming ): array {
		$old   = json_decode( (string) wp_json_encode( $stored ), true );
		$known = is_array( $old ) ? array_column( (array) ( $old['sections'] ?? [] ), 'block_slug' ) : [];

		foreach ( (array) ( $incoming['sections'] ?? [] ) as $i => $section ) {
			if ( is_array( $section ) && ! in_array( $section['block_slug'] ?? null, $known, true ) ) {
				$incoming['sections'][ $i ]['admin_added'] = true;
			}
		}
		return $incoming;
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
	 * Returns the Subtype-label, Background-routing, and Blood Magic
	 * tradition-configuration tables for the MET-Mechanics CSV overlay, read
	 * from met-csv-map.php. Used by apply_met_csv_overrides() and its helpers.
	 *
	 * @return array{discipline_caste_variant_subtypes:string[],discipline_labels:array<string,string>,ritual_labels:array<string,string>,background_routing:array<string,string[]>,blood_magic:array{excluded_subtypes:string[],restriction_keywords:string[]}}
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
		$cost = (string) preg_replace( '/-(?=\d)/', '', $cost );

		// "1 to 5" -> "1-5", matching parse_cost_rule()'s real range branch.
		$cost = (string) preg_replace( '/\s+to\s+/i', '-', $cost );

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
							foreach ( preg_split( '/\s+/', $note ) ?: [] as $word ) {
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
	 * Builds vampire-disciplines (tiered_power), vampire-combo-disciplines
	 * (trait_list), and vampire-blood-magic (tiered_power) from the CSV's
	 * Discipline rows.
	 *
	 * "Combination" rows go to vampire-combo-disciplines as a flat list. Every
	 * other row with an empty Group is an ordinary discipline (Celerity,
	 * Thaumaturgy, Necromancy, ...); every row with a real Group value is a
	 * Blood Magic path (Path of Blood, Lure of Flames, ...) and is routed to
	 * vampire-blood-magic instead - see build_met_blood_magic_powers() and
	 * BE_PROCESS/releases/0.99.2-workflow.md's "Blood magic" section for why paths
	 * live apart from disciplines rather than staying prefixed by tradition.
	 *
	 * @param array $csv MET_CSV_Parser::parse_file()'s return.
	 * @param array $map Seeder::met_csv_map()'s return.
	 * @param array $gvm Parsed GVM menus - vampire-disciplines merges against GVM's own
	 *                   existing resolution rather than replacing it outright.
	 * @return array{"vampire-disciplines":array,"vampire-combo-disciplines":array,"vampire-blood-magic":array}
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

		// A non-empty Group usually means a Blood Magic path (Path of Blood, Lure of
		// Flames, ...), routed to vampire-blood-magic below - but met-csv-map.php's own
		// discipline_caste_variant_subtypes already knows some Group values are really just
		// a caste/bloodline variant name for an ordinary Discipline (Quietus, Sorcerer /
		// Quietus, Cruscitus / Warrior / ...), not a path. Before this list also counted
		// here, those rows fell into neither bucket: kept out of blood magic correctly (via
		// the same list, also part of blood_magic.excluded_subtypes) but never reaching
		// vampire-disciplines either, so the ladder's only real levels ever came from GVM's
		// own (here, incomplete) definition with no CSV backfill possible - the exact shape
		// of a silently-missing top level on Quietus's four caste variants, found live
		// (owner report, an unbuyable 5th level). "Hermetic" is excluded from blood magic
		// for an unrelated reason (duplicate data - see met-csv-map.php) and must NOT be
		// routed here too, so it is deliberately not part of this list.
		// A caste-variant row's own Group column repeats its Subtype - but only on the
		// family's first (lowest-level) CSV row, sparse-filled blank on every row after
		// it (the source spreadsheet's own convention for "still the same group as
		// above"). build_met_discipline_powers() groups by Subtype+Group together, so
		// left as-is this splits one real five-level family into two: a one-item family
		// keyed by the real Group text, and a separate family keyed by the blank Group
		// carrying the remaining levels - neither of which is the plain, Subtype-keyed
		// shape every other ordinary Discipline already groups by. Blanking Group here
		// (Subtype alone already fully identifies the family for these rows) restores
		// that shape rather than teaching the grouping key a second, sparse-fill rule.
		$caste_variants = self::met_csv_map()['discipline_caste_variant_subtypes'];
		$powers         = array_map(
			static function ( $row ) use ( $caste_variants ) {
				if ( in_array( $row['Subtype'], $caste_variants, true ) ) {
					$row['Group'] = '';
				}
				return $row;
			},
			$powers
		);
		$ordinary   = array_values( array_filter(
			$powers,
			static fn( $row ) => $row['Group'] === '' || in_array( $row['Subtype'], $caste_variants, true )
		) );
		$blood_rows = array_values( array_filter(
			$powers,
			static fn( $row ) => $row['Group'] !== '' && ! in_array( $row['Subtype'], $caste_variants, true )
		) );

		$gvm_families = self::resolve_block_source( $gvm, 'vampire-disciplines', self::block_map()['vampire-disciplines'] )['powers'];

		// The "Disciplines" container's own submenus are not all real, leveled
		// Disciplines - ten clan-named ones (Assamite, Brujah, Lasombra, Tremere, ...)
		// are really each that clan's own signature combination powers, a flat list of
		// two-discipline recipes with a cost, structurally identical to "Combination
		// Discipline" (which is why GVM even nests one, "Awakening of the Steel," under
		// "Assamite" as a genuine sub-discipline while "Assamite" itself carries two
		// combo items directly). Nothing here ever told them apart from a real
		// Discipline, so every one of them seeded as a fake 1-4-"level" discipline
		// named after the clan - found live (owner report) as an unbuyable, missing top
		// level on four Quietus variants, then found systemic auditing every other
		// short "discipline" the same way. Split them out before they ever reach
		// build_met_discipline_powers().
		$gvm_split    = self::split_gvm_clan_combo_powers( $gvm_families );
		$gvm_families = $gvm_split['disciplines'];
		$blood_magic  = self::build_met_blood_magic_powers( $blood_rows, $gvm_families );

		return [
			'vampire-disciplines'       => self::build_met_discipline_powers(
				$ordinary,
				$map['discipline_labels'],
				$gvm_families,
				$blood_magic['excluded_gvm_names']
			),
			'vampire-combo-disciplines' => self::build_met_combo_disciplines( $combos, $gvm_split['combo_items'] ),
			'vampire-blood-magic'       => $blood_magic['block'],
		];
	}

	/**
	 * The clans whose own "Disciplines, <Clan>" GVM menu is really that clan's flat
	 * list of signature combination powers, not a leveled Discipline - verified
	 * 2026-09-17 against the real GVM source, one by one. Named explicitly rather
	 * than detected generically (e.g. "any family whose note contains a '+'"):
	 * "Disciplines, Long Night Combo" has the exact same shape but is not a clan at
	 * all - a genuine multi-clan combo menu whose items already resolve correctly
	 * under their own bare CSV "Combination" row and must not also be renamed here.
	 */
	const CLAN_SIGNATURE_COMBO_MENUS = [
		'Assamite', 'Brujah', 'Einherjar', 'Followers of Set', 'Gangrel', 'Lasombra',
		'Ravnos', 'Toreador', 'Tremere', 'Tzimisce', 'Ventrue',
	];

	/**
	 * Splits the "Disciplines" container's own resolved families into real, leveled
	 * Disciplines and the clan-signature combination powers named in
	 * CLAN_SIGNATURE_COMBO_MENUS, wrongly shaped like a Discipline by everything
	 * upstream of this method. A matched clan's items are moved unconditionally,
	 * confirmed real by resolve_container() giving each clan family its own name
	 * (the menu's own submenu name) rather than by re-detecting the shape here -
	 * a family's own note text isn't a safe general signal (a combo recipe
	 * routinely *names* a prerequisite at a real tier, "int. auspex + basic
	 * chimerstry" or "elder vicissitude + elder animalism," which would false-
	 * positive normalize_tier()'s own substring tier-word search).
	 *
	 * @param array<int,array{name:string,source:string,items:array}> $gvm_families
	 * @return array{disciplines:array,combo_items:array<int,array{name:string,cost:string,note:string,source:string,bare_name:string}>}
	 */
	private static function split_gvm_clan_combo_powers( array $gvm_families ): array {
		$disciplines = [];
		$combo_items = [];
		foreach ( $gvm_families as $family ) {
			if ( ! in_array( $family['name'], self::CLAN_SIGNATURE_COMBO_MENUS, true ) ) {
				$disciplines[] = $family;
				continue;
			}

			foreach ( $family['items'] as $item ) {
				$recipe = implode(
					' + ',
					array_map(
						static fn( $segment ) => ucwords( trim( $segment ) ),
						explode( '+', (string) ( $item['note'] ?? '' ) )
					)
				);
				$combo_items[] = [
					'name'   => $family['name'] . ': ' . $item['name'],
					'cost'   => (string) ( $item['cost'] ?? '' ),
					'note'   => $recipe,
					'source' => ( $item['source'] ?? '' ) !== '' ? $item['source'] : $family['source'],
					// The plain, unprefixed name a CSV "Combination" row already carries
					// this same power under, so the merge can supersede it rather than
					// keep both a prefixed and an unprefixed copy of the same power.
					'bare_name' => $item['name'],
				];
			}
		}

		return [ 'disciplines' => $disciplines, 'combo_items' => $combo_items ];
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
	 * Deduplicates a blood-magic path's rows by (Name, lNum) rather than
	 * Name alone. Verified 2026-09-11 against the real catalog: seven paths
	 * (e.g. Judicium's "Path of Mercury", five levels costing 3/3/6/6/9) give
	 * every level the identical Name text, reserving the distinct name for
	 * the path itself rather than each rung of it - dedupe_met_rows_by_name()
	 * would collapse all five into one, discarding four real levels. This
	 * pattern does not occur in any ordinary (Group-less) discipline family,
	 * so this stays local to blood magic rather than changing the shared
	 * helper's behavior for its many other, unaffected callers.
	 *
	 * @param array<int,array<string,string>> $rows
	 * @return array<int,array<string,string>>
	 */
	private static function dedupe_met_rows_for_ladder( array $rows ): array {
		$by_key = [];
		foreach ( $rows as $row ) {
			$key = $row['Name'] . "\x1f" . $row['lNum'];
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
		return \BeyondElysium\Services\Name_Key::for( $name );
	}

	/**
	 * Merges the CSV's ordinary-discipline rows (empty Group) into GVM's own
	 * existing vampire-disciplines resolution, rather than replacing it.
	 *
	 * Matches each GVM power family to its CSV equivalent by comparing a
	 * candidate CSV family's Subtype or Group against the GVM family name,
	 * then picking the candidate with the most overlapping item names. A
	 * matched family keeps every GVM item as-is and appends any CSV item
	 * not already present by name. An unmatched CSV family becomes a new
	 * power family; an unmatched GVM family passes through unchanged.
	 *
	 * @param array<int,array<string,string>> $rows              Non-Combination Discipline rows with an empty Group.
	 * @param array<string,string>            $labels            Subtype -> pretty display label.
	 * @param array                           $gvm_families      resolve_block_source()'s 'powers' for vampire-disciplines.
	 * @param string[]                        $exclude_gvm_names GVM family names build_met_blood_magic_powers() has
	 *                                                            already claimed (a bare duplicate of a real path,
	 *                                                            e.g. "Path of Blood") - must not also pass through here.
	 * @return array
	 */
	private static function build_met_discipline_powers( array $rows, array $labels, array $gvm_families, array $exclude_gvm_names = [] ): array {
		if ( $exclude_gvm_names !== [] ) {
			$gvm_families = array_values( array_filter(
				$gvm_families,
				static fn( $family ) => ! in_array( $family['name'], $exclude_gvm_names, true )
			) );
		}

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
				$have = array_map( [ self::class, 'met_name_comparison_key' ], array_column( $items, 'name' ) );
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
	 * Builds vampire-blood-magic from the CSV's tradition/path Discipline
	 * rows (every non-Combination row with a real Group value), collapsing
	 * each canonical path to a single power regardless of how many
	 * traditions teach it.
	 *
	 * A raw Group value is "Canonical", "Canonical / Alternate", or
	 * "Canonical / Alternate / Restriction" - the trailing segment is a
	 * restriction only when it matches a configured caste/covenant keyword
	 * (met_csv_map()'s 'blood_magic'.'restriction_keywords'), never an
	 * alternate name. Two Group values fold to the same canonical path once
	 * a curly apostrophe is straightened and a leading "a/an/the" is
	 * stripped - see blood_magic_path_key().
	 *
	 * See BE_PROCESS/releases/0.99.2-workflow.md's "Blood magic" section and
	 * BE_PROCESS/design/blood-magic-paradigm.md for the design this implements and
	 * the measurements behind every rule here.
	 *
	 * @param array<int,array<string,string>> $rows         Non-Combination Discipline rows with a non-empty Group.
	 * @param array                           $gvm_families resolve_block_source()'s 'powers' for vampire-disciplines -
	 *                                                       a handful of paths exist there too as empty, unlabelled
	 *                                                       duplicate families (verified 2026-09-11: every one found
	 *                                                       was empty), which must not survive as a second copy in
	 *                                                       vampire-disciplines once this method has claimed the path.
	 * @return array{block:array,excluded_gvm_names:string[]}
	 */
	private static function build_met_blood_magic_powers( array $rows, array $gvm_families ): array {
		$config            = self::met_csv_map()['blood_magic'];
		$excluded_subtypes = $config['excluded_subtypes'];
		$restriction_words = array_map( 'strtolower', $config['restriction_keywords'] );
		$labels            = self::met_csv_map()['discipline_labels'];

		$rows = array_values( array_filter(
			$rows,
			static fn( $row ) => ! in_array( $row['Subtype'], $excluded_subtypes, true )
		) );

		// Group into (Subtype, Group) families first, exactly like the ordinary-discipline
		// merge does, since the same bare Group text recurs identically across traditions.
		$families = [];
		foreach ( $rows as $row ) {
			$key                          = $row['Subtype'] . "\x1f" . $row['Group'];
			$families[ $key ]['subtype'] = $row['Subtype'];
			$families[ $key ]['group']   = $row['Group'];
			$families[ $key ]['rows'][]  = $row;
		}

		$paths = [];
		foreach ( $families as $family ) {
			$label    = $labels[ $family['subtype'] ] ?? $family['subtype'];
			$segments = array_map(
				static fn( $segment ) => self::straighten_blood_magic_text( $segment ),
				explode( ' / ', $family['group'] )
			);

			$restriction = null;
			if ( count( $segments ) > 1 && in_array( strtolower( end( $segments ) ), $restriction_words, true ) ) {
				$restriction = array_pop( $segments );
			}
			$canonical = $segments[0];
			$alternate = $segments[1] ?? null;
			$path_key  = self::blood_magic_path_key( $canonical );

			$items = self::dedupe_met_rows_for_ladder( $family['rows'] );
			usort(
				$items,
				static function ( $a, $b ) {
					return self::met_lnum_sort_key( $a['lNum'] ) <=> self::met_lnum_sort_key( $b['lNum'] );
				}
			);
			// A single row whose own Name is just the path's name again carries no real
			// level data (e.g. Mortis's one-row "Mastery of the Mortal Shell") - the
			// tradition still offers the path, but this row must not seed a fake level.
			// This only fires for a genuine one-row family: dedupe_met_rows_for_ladder()
			// keeps every distinct level even when several share that same Name text
			// (e.g. Judicium's real five-level "Path of Mercury").
			$is_stub = count( $items ) === 1 && self::blood_magic_path_key( $items[0]['Name'] ) === $path_key;

			if ( ! isset( $paths[ $path_key ] ) ) {
				// A leading "A/An/The" is stripped from the display name unconditionally, not
				// only on a collision between two spellings - a real .gex import (Chase
				// Ashford, 2026-09-11) carries "Dur-An-Ki: Hunter's Wind" with no article at
				// all, matching neither "The Hunter's Wind" nor "Hunter's Wind" being treated
				// as two different things would leave. One consistent article-free spelling
				// means the same match logic that already prefers this for a genuine
				// two-spelling collision (e.g. "Snake Inside" / "The Snake Inside") applies
				// uniformly, and the importer never has to guess which form to try first.
				$paths[ $path_key ] = [
					'display'     => (string) preg_replace( '/^(a|an|the)\s+/i', '', $canonical ),
					'traditions'  => [],
					'restriction' => null,
					'candidates'  => [],
				];
			}

			$paths[ $path_key ]['traditions'][ $label ] = $alternate;
			if ( $restriction !== null ) {
				$paths[ $path_key ]['restriction'] = $restriction;
			}
			if ( ! $is_stub ) {
				$paths[ $path_key ]['candidates'][] = [
					'items' => array_map(
						static function ( $row ) {
							$item = [
								'name' => $row['Name'],
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
		}

		// Fold in any GVM family that duplicates a canonical path under no tradition at
		// all, and claim its name so build_met_discipline_powers() excludes it - verified
		// 2026-09-11 that every such family is empty, but a future GVM update contributing
		// real items must still be picked up here rather than silently duplicated there.
		$excluded_gvm_names = [];
		foreach ( $gvm_families as $gvm_family ) {
			$path_key = self::blood_magic_path_key( $gvm_family['name'] );
			if ( ! isset( $paths[ $path_key ] ) ) {
				continue;
			}
			$excluded_gvm_names[] = $gvm_family['name'];
			if ( ! empty( $gvm_family['items'] ) ) {
				$paths[ $path_key ]['candidates'][] = [ 'items' => $gvm_family['items'] ];
			}
		}

		$powers = [];
		foreach ( $paths as $path ) {
			$power = [
				'name'       => $path['display'],
				'items'      => self::pick_blood_magic_ladder( $path['candidates'] ),
				'traditions' => $path['traditions'],
			];
			if ( $path['restriction'] !== null ) {
				$power['restriction'] = $path['restriction'];
			}
			$powers[] = $power;
		}
		usort( $powers, static fn( $a, $b ) => strcasecmp( $a['name'], $b['name'] ) );

		$traditions = [];
		foreach ( $powers as $power ) {
			foreach ( array_keys( $power['traditions'] ) as $label ) {
				$traditions[ $label ] = true;
			}
		}
		$traditions = array_keys( $traditions );
		sort( $traditions );

		return [
			'block'              => self::make_tiered_power_block(
				'vampire-blood-magic',
				'Blood Magic',
				$powers,
				[
					'atomic'       => true,
					'blood_magic'  => true,
					'traditions'   => $traditions,
					// 1.1.0 D4: held paths display and reorder in the player's own array order.
					'player_order' => true,
				]
			),
			'excluded_gvm_names' => $excluded_gvm_names,
		];
	}

	/**
	 * Picks the most complete ladder among a canonical path's candidate item
	 * lists (one per non-stub offering tradition, plus a possible GVM-sourced
	 * list with no tradition of its own), then merges in any other
	 * candidate's items not already present by name - the same "protected
	 * base, net-new only" rule Decision 043 established for merging GVM
	 * against the CSV, applied here across traditions instead.
	 *
	 * @param array<int,array{items:array[]}> $candidates
	 * @return array[]
	 */
	private static function pick_blood_magic_ladder( array $candidates ): array {
		if ( $candidates === [] ) {
			return [];
		}

		// Only an "informative" candidate (every level named distinctly) ever merges
		// with another - see is_blood_magic_candidate_informative() for why a
		// placeholder candidate must be used alone or not at all, never merged.
		$informative = array_values( array_filter( $candidates, [ self::class, 'is_blood_magic_candidate_informative' ] ) );
		$pool        = $informative !== [] ? $informative : $candidates;

		usort( $pool, static fn( $a, $b ) => count( $b['items'] ) <=> count( $a['items'] ) );

		$items = $pool[0]['items'];

		// A pool of only placeholder candidates (no informative one exists for this
		// path) has nothing merge can use to tell one candidate's levels apart from
		// another's - take the fullest one alone, matching what a single candidate
		// (e.g. Judicium's own "Path of Mercury") already does by definition.
		if ( $pool === $informative ) {
			$have = array_map( [ self::class, 'met_name_comparison_key' ], array_column( $items, 'name' ) );
			foreach ( array_slice( $pool, 1 ) as $candidate ) {
				foreach ( $candidate['items'] as $item ) {
					$item_key = self::met_name_comparison_key( $item['name'] );
					if ( ! in_array( $item_key, $have, true ) ) {
						$items[] = $item;
						$have[]  = $item_key;
					}
				}
			}
		}
		return $items;
	}

	/**
	 * An "informative" candidate names every level distinctly. A "placeholder"
	 * candidate repeats the same name across two or more levels (e.g.
	 * Judicium's five-level "Path of Mercury", or Sadhana's five-level "Path
	 * of Blood Nectar", both real ladders whose source material never gave
	 * the individual rungs their own names).
	 *
	 * Merging a placeholder candidate by name is wrong in both directions:
	 * verified 2026-09-11 against two real paths in the seeded catalog.
	 * "Path of Blood Nectar" (Sadhana's five identically-named levels plus
	 * Hermetic Anarch's five identically-named levels, same 3/3/6/6/9 cost
	 * progression in both) collapsed to only one merged extra level instead
	 * of the intended five, because every one of the second candidate's
	 * repeated-name items looked identical to name-comparison after the
	 * first was merged in. "Path of Woe" (Wanga names all five levels;
	 * Sadhana repeats "Path of Woe" for all five, same costs) went the other
	 * way - none of Wanga's real names matched Sadhana's placeholder text, so
	 * naive merging inflated the result to ten levels instead of five.
	 *
	 * @param array{items:array[]} $candidate
	 */
	private static function is_blood_magic_candidate_informative( array $candidate ): bool {
		$names = array_column( $candidate['items'], 'name' );
		return count( array_unique( $names ) ) === count( $names );
	}

	/**
	 * Comparison-only key for a blood-magic path or power name: straightens
	 * a curly apostrophe and applies the same leading-article strip
	 * met_name_comparison_key() uses, so "The Green Path" (a bare GVM family
	 * name) and "Green Path" (the CSV's own canonical spelling) resolve to
	 * the same path. Never used for display.
	 */
	private static function blood_magic_path_key( string $name ): string {
		return self::met_name_comparison_key( self::straighten_blood_magic_text( $name ) );
	}

	/**
	 * Folds curly quote characters to their straight ASCII equivalent and
	 * collapses whitespace - the MET-Mechanics CSV mixes both apostrophe
	 * forms for what is otherwise the same name (e.g. "Neptune's Might").
	 */
	private static function straighten_blood_magic_text( string $text ): string {
		return trim( (string) preg_replace( '/\s+/', ' ', str_replace( [ "\u{2019}", "\u{2018}" ], "'", $text ) ) );
	}

	/**
	 * Builds the vampire-combo-disciplines trait_list block from the CSV's
	 * "Combination"-subtype Discipline rows, plus GVM's own clan-signature combo
	 * powers split out of "Disciplines, <Clan>" by split_gvm_clan_combo_powers()
	 * (1.1.0, owner report - see that method's own docblock). A GVM combo item
	 * whose bare name already has a CSV row (e.g. "Shroud of Absence") supersedes
	 * it outright, carrying GVM's own real cost/recipe under the clan-prefixed
	 * name ("Lasombra: Shroud of Absence") rather than leaving both the bare and
	 * the prefixed form in the catalog side by side.
	 *
	 * @param array<int,array<string,string>> $rows            "Combination"-subtype Discipline rows.
	 * @param array<int,array{name:string,cost:string,note:string,source:string,bare_name:string}> $gvm_combo_items
	 * @return array
	 */
	private static function build_met_combo_disciplines( array $rows, array $gvm_combo_items = [] ): array {
		$items = self::dedupe_met_rows_by_name( $rows );

		$superseded = array_map( static fn( $item ) => $item['bare_name'], $gvm_combo_items );
		$items      = array_values( array_filter( $items, static fn( $row ) => ! in_array( $row['Name'], $superseded, true ) ) );

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

		foreach ( $gvm_combo_items as $gvm_item ) {
			$item = [ 'name' => $gvm_item['name'] ];
			if ( $gvm_item['cost'] !== '' ) {
				$item['cost'] = $gvm_item['cost'];
			}
			if ( $gvm_item['note'] !== '' ) {
				$item['note'] = $gvm_item['note'];
			}
			if ( $gvm_item['source'] !== '' ) {
				$item['source'] = $gvm_item['source'];
			}
			$built[] = $item;
		}

		// 1.1.0 D3: a held combo's stored count is its flat XP cost, not a rating -
		// a flag, not a slug check, so any future block could opt into the same rule.
		return self::make_trait_list_block( 'vampire-combo-disciplines', 'Combination Disciplines', $built, [ 'count_is_cost' => true ] );
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

		// resolve_block_source()'s own 'merge' case below applies this identical label/tier
		// composition to the same $gvm_raw_items - $gvm_keys is the resulting (label, name)
		// membership set, checked below so a CSV row GVM already offers is never duplicated.
		$base = self::resolve_block_source( $gvm, 'vampire-rituals', $entry )['items'];

		$gvm_keys = [];
		foreach ( $gvm_raw_items as $i => $item ) {
			$note  = trim( (string) ( $item['note'] ?? '' ), $trim_chars );
			$label = $entry['labels'][ $item['source'] ] ?? $item['source'];
			foreach ( preg_split( '/\s+/', $note ) ?: [] as $word ) {
				$override_key = strtolower( trim( $word, '.' ) );
				if ( isset( $note_overrides[ $override_key ] ) ) {
					$label = $note_overrides[ $override_key ];
					break;
				}
			}
			$gvm_keys[ strtolower( $label ) . "\x1f" . self::met_name_comparison_key( $item['name'] ) ] = $i;
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

		$merged = self::dedupe_built_items_by_name( array_merge( $base, $new_items ) );

		// 1.1.0 D4: held rituals display and reorder in the player's own array order.
		return self::make_trait_list_block( 'vampire-rituals', 'Rituals', $merged, [ 'atomic' => true, 'player_order' => true ] );
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

		$existing_keys     = array_map( [ self::class, 'met_name_comparison_key' ], array_column( $base, 'name' ) );
		$base_index_by_key = array_flip( $existing_keys );

		$rows      = self::dedupe_met_rows_by_name( $csv['by_type'][ $csv_type ] ?? [] );
		$new_items = [];
		foreach ( $rows as $row ) {
			$key  = self::met_name_comparison_key( $row['Name'] );
			$cost = self::normalize_met_cost( $row['Cost'] );

			if ( isset( $base_index_by_key[ $key ] ) ) {
				// The GVM base already has this name (`met_name_comparison_key()` matched) -
				// backfill the CSV's own cost onto it when it has none of its own (PC-3:
				// point-calculator-design.md §3.3), and never overwrite a cost the base
				// already carries.
				$base_index = $base_index_by_key[ $key ];
				if ( $cost !== '' && empty( $base[ $base_index ]['cost'] ) ) {
					$base[ $base_index ]['cost'] = $cost;
				}
				continue;
			}

			$item = [ 'name' => $row['Name'] ];
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
			$base              = $by_slug[ $slug ]['definition']['items'] ?? [];
			$existing_keys     = array_map( [ self::class, 'met_name_comparison_key' ], array_column( $base, 'name' ) );
			$base_index_by_key = array_flip( $existing_keys );

			$rows      = self::dedupe_met_rows_by_name( $rows );
			$new_items = [];
			foreach ( $rows as $row ) {
				$key  = self::met_name_comparison_key( $row['Name'] );
				$cost = self::normalize_met_cost( $row['Cost'] );

				if ( isset( $base_index_by_key[ $key ] ) ) {
					// Same PC-3 backfill rule as build_met_merge_trait_list(): fill a missing
					// cost, never overwrite an existing one.
					$base_index = $base_index_by_key[ $key ];
					if ( $cost !== '' && empty( $base[ $base_index ]['cost'] ) ) {
						$base[ $base_index ]['cost'] = $cost;
					}
					continue;
				}

				$item = [ 'name' => $row['Name'] ];
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
				// Levels add up (owner ruling, 1.0.0-review F-040): raising a power costs every level
				// passed through, named ladders (Disciplines, Arcanoi, ...) included, not only shared ones.
				$extra['sequential'] = true;
				$blocks[] = self::make_tiered_power_block( $slug, $label, $resolved['powers'], $extra );
				continue;
			}

			// No longer deferred (BE_PROCESS/releases/0.99.2-workflow.md) - the block map still marks
			// this 'source' => 'none', but a real source now exists outside the GVM menu set.
			if ( $slug === 'mage-rotes' ) {
				unset( $extra['deferred_to'] );
				$blocks[] = self::make_trait_list_block( $slug, $label, self::merge_grimoire_rotes( self::build_mage_rotes_items() ), $extra );
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
	 * Builds mage-rotes' real catalog from data/Rotes.gex - 201 real Mage rotes, each with
	 * its own sphere prerequisites and duration. Missing or malformed falls back to an empty
	 * list, the same graceful-degradation style parse_gvm()/parse_met_csv() already use for
	 * their own source files, so a corrupt copy degrades to the pre-existing empty catalog
	 * rather than fataling the whole seed.
	 *
	 * The catalog does not model sphere prerequisites as a real dependency - the note just
	 * states them, matching how this codebase already treats a Merit's or Discipline's own
	 * requirement text elsewhere (readable, not enforced). Cost is never set: MET rotes are
	 * not separately priced - the sphere levels they require are what actually cost XP.
	 *
	 * `Rotes.gex` also carries, per rote, a `sphere_list` of `{Sphere}: {Rank}` trait names
	 * and a `description` that is actually a source citation (`"Laws of Ascension, p. 166"`,
	 * `"Laws of Ascension Companion p. 137"`) - both already parsed by `GEX_Xml_Parser` and
	 * both previously discarded here despite this docblock's own claim that the catalog
	 * carries "its own sphere prerequisites." Appended to `note`/`source` below. `source` is
	 * validated against the real citation shape before being written, so a future edit that
	 * grows a real prose description on this field can't silently ship as though it were a
	 * citation.
	 *
	 * @return array<int,array{name:string,note:string,source?:string}>
	 */
	private static function build_mage_rotes_items(): array {
		if ( ! file_exists( self::MAGE_ROTES_PATH ) ) {
			error_log( 'Beyond Elysium: Mage Rotes source not found at ' . self::MAGE_ROTES_PATH . ' - mage-rotes seeded empty.' );
			return [];
		}

		try {
			$data = GEX_Xml_Parser::parse_file( self::MAGE_ROTES_PATH );
		} catch ( \Throwable $e ) {
			error_log( 'Beyond Elysium: Mage Rotes source failed to parse - mage-rotes seeded empty. ' . $e->getMessage() );
			return [];
		}

		$items = [];
		foreach ( $data['rotes'] ?? [] as $rote ) {
			$name = (string) ( $rote['name'] ?? '' );
			if ( $name === '' ) {
				continue;
			}

			$level    = $rote['level'] ?? null;
			$duration = trim( (string) ( $rote['duration'] ?? '' ) );
			$note     = $level !== null ? "Level {$level}" : '';
			if ( $duration !== '' ) {
				$note = $note !== '' ? "{$note}, {$duration}" : $duration;
			}

			// Sphere prerequisites: real trait names ("Correspondence: Initiate"), kept in
			// source order (Rotes.gex orders them meaningfully) and never deduped within a
			// rote - a rote legitimately usable at either of two ranks lists both.
			$sphere_names = [];
			foreach ( $rote['sphere_list']['traits'] ?? [] as $trait ) {
				$sphere_name = trim( (string) ( $trait['name'] ?? '' ) );
				if ( $sphere_name !== '' ) {
					$sphere_names[] = $sphere_name;
				}
			}
			if ( ! empty( $sphere_names ) ) {
				$spheres = implode( ', ', $sphere_names );
				$note    = $note !== '' ? "{$note} — {$spheres}" : $spheres;
			}

			$item = [ 'name' => $name ];
			if ( $note !== '' ) {
				$item['note'] = $note;
			}

			// The <description> element is actually a source citation on every real rote,
			// never prose - checked against the real shape rather than trusted, so a
			// malformed or grown value degrades to "omitted" rather than shipping as though
			// it were a citation.
			$source = trim( (string) ( $rote['description'] ?? '' ) );
			if ( $source !== '' && preg_match( '/^Laws of Ascension( Companion)?,? p\.? ?\d+$/', $source ) === 1 ) {
				$item['source'] = $source;
			}

			$items[] = $item;
		}

		return self::dedupe_built_items_by_name( $items );
	}

	/**
	 * Every comparison key a rote name can plausibly match under, per
	 * mage-rotes-grimoire-design.md §5.3: met_name_comparison_key() as the base,
	 * K1 (a bare trailing-s plural on the final word alone - "Ball of Abysmal
	 * Flame" vs "...Flames" are the same rote) and K2 (a slash-joined dual name -
	 * "Blight/Farmer's Favor" - yields one key per side, plus the whole string,
	 * since the Grimoire writes dual names the GEX writes as one).
	 *
	 * @return array<int,string>
	 */
	private static function grimoire_match_keys( string $name ): array {
		$variants = [ $name ];
		if ( strpos( $name, '/' ) !== false ) {
			foreach ( explode( '/', $name ) as $side ) {
				$side = trim( $side );
				if ( $side !== '' ) {
					$variants[] = $side;
				}
			}
		}

		$keys = [];
		foreach ( $variants as $variant ) {
			$key    = self::met_name_comparison_key( $variant );
			$keys[] = $key;

			// K1: trailing-s equivalence, the final word only.
			$words = explode( ' ', $key );
			$last  = array_pop( $words );
			if ( $last === '' ) {
				continue;
			}
			if ( strlen( $last ) > 1 && substr( $last, -1 ) === 's' ) {
				$keys[] = implode( ' ', array_merge( $words, [ substr( $last, 0, -1 ) ] ) );
			} else {
				$keys[] = implode( ' ', array_merge( $words, [ $last . 's' ] ) );
			}
		}

		return array_values( array_unique( $keys ) );
	}

	/**
	 * Merges data/grimoire-rotes.csv (603 net-new rotes, plus category data for
	 * the 67 that match the existing 201 GEX rotes - Decision 093) into $base -
	 * build_mage_rotes_items()'s output, the protected base per Decision 043.
	 * $base is never altered except to backfill `group`/`subgroup` onto a
	 * matched item that has none; sphere/citation stay whichever system
	 * originally supplied that item, never overwritten by the other (§6.2 - the
	 * two rules systems' sphere numbers are not interchangeable). A genuine tie
	 * (a Grimoire name matching more than one base item under §5.3's key set)
	 * is left unmerged and logged, never guessed - the same tie rule Decision
	 * 043 already established for the MET CSV merge.
	 *
	 * Missing or malformed CSV -> error_log and return $base unchanged, the same
	 * graceful-degradation style build_mage_rotes_items()/parse_gvm() use.
	 *
	 * @param array<int,array<string,mixed>> $base
	 * @return array<int,array<string,mixed>>
	 */
	private static function merge_grimoire_rotes( array $base ): array {
		if ( ! file_exists( self::GRIMOIRE_ROTES_PATH ) ) {
			error_log( 'Beyond Elysium: Grimoire rotes source not found at ' . self::GRIMOIRE_ROTES_PATH . ' - mage-rotes seeded from Rotes.gex only.' );
			return $base;
		}

		try {
			$rows = Grimoire_CSV_Parser::parse_file( self::GRIMOIRE_ROTES_PATH );
		} catch ( \Throwable $e ) {
			error_log( 'Beyond Elysium: Grimoire rotes CSV failed to parse - mage-rotes seeded from Rotes.gex only. ' . $e->getMessage() );
			return $base;
		}

		return self::merge_grimoire_rows( $base, $rows );
	}

	/**
	 * The pure merge core behind merge_grimoire_rotes(), split out so it can
	 * be exercised directly (MageRotesMergeTest) against constructed rows
	 * without a real CSV file on disk. See merge_grimoire_rotes()'s own
	 * docblock for the merge rules; this method assumes $rows is already
	 * parsed and valid.
	 *
	 * @param array<int,array<string,mixed>>                                        $base
	 * @param array<int,array{name:string,note:string,source:string,group:string,subgroup:string}> $rows
	 * @return array<int,array<string,mixed>>
	 */
	private static function merge_grimoire_rows( array $base, array $rows ): array {
		$merged     = $base;
		$key_to_idx = [];
		foreach ( $merged as $i => $item ) {
			foreach ( self::grimoire_match_keys( $item['name'] ) as $key ) {
				$key_to_idx[ $key ][] = $i;
			}
		}

		$added           = 0;
		$skipped_matched = 0;
		$ambiguous       = 0;
		$categorized     = 0;

		foreach ( $rows as $row ) {
			$keys = self::grimoire_match_keys( $row['name'] );

			$hit_indexes = [];
			foreach ( $keys as $key ) {
				foreach ( $key_to_idx[ $key ] ?? [] as $idx ) {
					$hit_indexes[ $idx ] = true;
				}
			}
			$hit_indexes = array_keys( $hit_indexes );

			if ( count( $hit_indexes ) > 1 ) {
				// Decision 043's tie rule: a genuine tie is left unmerged, never guessed.
				$ambiguous++;
				continue;
			}

			if ( count( $hit_indexes ) === 1 ) {
				$idx = $hit_indexes[0];
				$skipped_matched++;
				if ( empty( $merged[ $idx ]['group'] ) && $row['group'] !== '' ) {
					$merged[ $idx ]['group'] = $row['group'];
					if ( $row['subgroup'] !== '' ) {
						$merged[ $idx ]['subgroup'] = $row['subgroup'];
					}
					$categorized++;
				}
				continue;
			}

			// Net-new.
			$item = [
				'name'   => $row['name'],
				'note'   => $row['note'],
				'source' => $row['source'],
			];
			if ( $row['group'] !== '' ) {
				$item['group'] = $row['group'];
			}
			if ( $row['subgroup'] !== '' ) {
				$item['subgroup'] = $row['subgroup'];
			}

			$new_idx    = count( $merged );
			$merged[]   = $item;
			foreach ( $keys as $key ) {
				// So a second Grimoire row that normalizes the same way lands on
				// this one instead of being added again.
				$key_to_idx[ $key ][] = $new_idx;
			}
			$added++;
		}

		// Only a genuine tie is worth logging on every ordinary seed - matching
		// build_mapped_blocks()'s own "$unresolved" convention (log anomalies, not
		// routine successful counts). Decision 043's tie rule means an ambiguous row
		// is real, silently-dropped information an admin should be able to notice.
		if ( $ambiguous > 0 ) {
			error_log( sprintf(
				'Beyond Elysium: Grimoire rotes merge - %d added, %d matched (%d newly categorized), %d ambiguous (left unmerged)',
				$added,
				$skipped_matched,
				$categorized,
				$ambiguous
			) );
		}

		return self::dedupe_built_items_by_name( $merged );
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

		// A quick NPC's condensed sheet (1.1.0 §3.7), shared by every stack - never storyteller_only.
		$blocks[] = self::make_npc_quick_stats_block();

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
			// Blood is never a priced pool (PC-10 owner ruling, 2026-09-13): Grapevine's own
			// point estimator (VampireClass.cls's CompleteEstimateList) has no
			// Estimate.Append qkBlood line at all - it scales with Generation, not a
			// purchased dot. Left with no cost_per_dot, deliberately.
			[ 'name' => 'Blood',     'value_type' => 'integer', 'default_start' => 10, 'max' => 20 ],
			// VampireClass.cls:258 - Estimate.Append qkWillpower, "3", "2".
			[ 'name' => 'Willpower', 'value_type' => 'integer', 'default_start' => 3,  'max' => 20, 'cost_per_dot' => 3, 'free_dots' => 2 ],
			// Morality has no Grapevine estimator citation at all - unpriced.
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

		$blocks[] = self::make_health_block( 'vampire', self::health_composition( 'Torpor' ) );

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
			// WerewolfClass.cls:249-251 - Estimate.Append qkRage/qkWillpower/qkGnosis, all "3", "3".
			[ 'name' => 'Rage',      'value_type' => 'integer', 'default_start' => 1, 'max' => 10, 'cost_per_dot' => 3, 'free_dots' => 3 ],
			[ 'name' => 'Gnosis',    'value_type' => 'integer', 'default_start' => 1, 'max' => 10, 'cost_per_dot' => 3, 'free_dots' => 3 ],
			[ 'name' => 'Willpower', 'value_type' => 'integer', 'default_start' => 3, 'max' => 20, 'cost_per_dot' => 3, 'free_dots' => 3 ],
		] );

		$blocks[] = self::make_resource_block( 'werewolf-renown', 'Werewolf Renown', [
			[ 'name' => 'Honor',  'value_type' => 'decimal', 'step' => 0.1, 'default_start' => 0 ],
			[ 'name' => 'Glory',  'value_type' => 'decimal', 'step' => 0.1, 'default_start' => 0 ],
			[ 'name' => 'Wisdom', 'value_type' => 'decimal', 'step' => 0.1, 'default_start' => 0 ],
		] );

		$blocks[] = self::make_health_block( 'werewolf', self::health_composition( 'Mortally Wounded' ) );

		// --- Mage ---
		$blocks[] = self::make_identity_block( 'mage-identity', 'Mage Identity', [
			[ 'name' => 'Tradition', 'field_type' => 'select', 'required' => true,  'options' => self::resolve_identity_options( $gvm, 'Tradition, Mage', true ) ],
			[ 'name' => 'Essence',   'field_type' => 'select', 'required' => false, 'options' => self::resolve_identity_options( $gvm, 'Essence', true ) ],
			[ 'name' => 'Faction',   'field_type' => 'text',   'required' => false ],
			[ 'name' => 'Cabal',     'field_type' => 'text',   'required' => false ],
			[ 'name' => 'Rank',      'field_type' => 'number', 'required' => false, 'min' => 0, 'max' => 5, 'default' => 1 ],
		] );

		$blocks[] = self::make_resource_block( 'mage-resources', 'Mage Resources', [
			// MageClass.cls:223-224 - Estimate.Append qkArete, "4", "1"; qkWillpower, "3", "5".
			[ 'name' => 'Arete',       'value_type' => 'integer', 'default_start' => 1, 'max' => 10, 'cost_per_dot' => 4, 'free_dots' => 1 ],
			[ 'name' => 'Quintessence','value_type' => 'integer', 'default_start' => 1, 'max' => 20 ],
			[ 'name' => 'Paradox',     'value_type' => 'integer', 'default_start' => 0, 'max' => 20 ],
			[ 'name' => 'Willpower',   'value_type' => 'integer', 'default_start' => 3, 'max' => 20, 'cost_per_dot' => 3, 'free_dots' => 5 ],
		] );

		$blocks[] = self::make_health_block( 'mage', self::health_composition( 'Mortally Wounded' ) );

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
			// ChangelingClass.cls:215-216 - Estimate.Append qkGlamour, "3", "4"; qkWillpower, "3", "3".
			[ 'name' => 'Glamour',   'value_type' => 'integer', 'default_start' => 3, 'max' => 10, 'cost_per_dot' => 3, 'free_dots' => 4 ],
			// Banality rises as a penalty, never bought (ChangelingClass.cls:217's own
			// self-cancelling allotment) - unpriced.
			[ 'name' => 'Banality',  'value_type' => 'integer', 'default_start' => 3, 'max' => 10 ],
			[ 'name' => 'Willpower', 'value_type' => 'integer', 'default_start' => 3, 'max' => 20, 'cost_per_dot' => 3, 'free_dots' => 3 ],
		] );

		$blocks[] = self::make_health_block( 'changeling', self::health_composition( 'Mortally Wounded' ) );

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

		$blocks[] = self::make_health_block( 'demon', self::health_composition( 'Mortally Wounded' ) );

		// --- Mummy ---
		$blocks[] = self::make_identity_block( 'mummy-identity', 'Mummy Identity', [
			[ 'name' => 'Amenti', 'field_type' => 'text', 'required' => false ],
		] );

		$blocks[] = self::make_resource_block( 'mummy-resources', 'Mummy Resources', [
			[ 'name' => 'Sekhem',    'value_type' => 'integer', 'default_start' => 3, 'max' => 10 ],
			[ 'name' => 'Balance',   'value_type' => 'integer', 'default_start' => 5, 'max' => 10 ],
			[ 'name' => 'Willpower', 'value_type' => 'integer', 'default_start' => 3, 'max' => 20 ],
		] );

		$blocks[] = self::make_health_block( 'mummy', self::health_composition( 'Mortally Wounded', [ [ 'name' => 'Dead', 'count' => 2 ] ] ) );

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

		$blocks[] = self::make_health_block( 'kueijin', self::health_composition( 'Torpor' ) );

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

		$blocks[] = self::make_health_block( 'mortal', self::health_composition( 'Mortally Wounded' ) );

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
			self::make_trait_list_block(   'vampire-combo-disciplines', 'Combo Disciplines',     [], [ 'count_is_cost' => true ] ),
			self::make_trait_list_block(   'vampire-rituals',           'Rituals',               [], [ 'atomic' => true, 'player_order' => true ] ),
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
			self::make_health_block( 'vampire', self::health_composition( 'Torpor' ) ),
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
			self::make_health_block( 'werewolf', self::health_composition( 'Mortally Wounded' ) ),
			self::make_identity_block(    'mage-identity',    'Mage Identity', [
				[ 'name' => 'Tradition', 'field_type' => 'select', 'required' => true  ],
				[ 'name' => 'Essence',   'field_type' => 'select', 'required' => false ],
			] ),
			self::make_tiered_power_block( 'mage-spheres',    'Mage Spheres',  [], [ 'sequential' => true, 'atomic' => true ] ),
			// Real catalog even on this total-GVM-failure fallback path - build_mage_rotes_items()
			// reads data/Rotes.gex directly and has no dependency on $gvm.
			self::make_trait_list_block(   'mage-rotes',      'Mage Rotes',    self::merge_grimoire_rotes( self::build_mage_rotes_items() ), [ 'atomic' => true ] ),
			self::make_resource_block( 'mage-resources', 'Mage Resources', [
				[ 'name' => 'Arete',        'value_type' => 'integer', 'default_start' => 1, 'max' => 10 ],
				[ 'name' => 'Quintessence', 'value_type' => 'integer', 'default_start' => 1, 'max' => 20 ],
				[ 'name' => 'Paradox',      'value_type' => 'integer', 'default_start' => 0, 'max' => 20 ],
				[ 'name' => 'Willpower',    'value_type' => 'integer', 'default_start' => 3, 'max' => 20 ],
			] ),
			self::make_health_block( 'mage', self::health_composition( 'Mortally Wounded' ) ),
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
			self::make_health_block( 'changeling', self::health_composition( 'Mortally Wounded' ) ),
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
			self::make_health_block( 'demon', self::health_composition( 'Mortally Wounded' ) ),
			self::make_identity_block( 'mummy-identity', 'Mummy Identity', [
				[ 'name' => 'Amenti', 'field_type' => 'text', 'required' => false ],
			] ),
			self::make_tiered_power_block( 'mummy-hekau', 'Mummy Hekau', [], [ 'sequential' => true, 'atomic' => true ] ),
			self::make_resource_block( 'mummy-resources', 'Mummy Resources', [
				[ 'name' => 'Sekhem',    'value_type' => 'integer', 'default_start' => 3, 'max' => 10 ],
				[ 'name' => 'Balance',   'value_type' => 'integer', 'default_start' => 5, 'max' => 10 ],
				[ 'name' => 'Willpower', 'value_type' => 'integer', 'default_start' => 3, 'max' => 20 ],
			] ),
			self::make_health_block( 'mummy', self::health_composition( 'Mortally Wounded', [ [ 'name' => 'Dead', 'count' => 2 ] ] ) ),
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
			self::make_health_block( 'kueijin', self::health_composition( 'Torpor' ) ),
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
			self::make_health_block( 'mortal', self::health_composition( 'Mortally Wounded' ) ),
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
							[ 'block_slug' => 'vampire-disciplines',       'label' => 'Disciplines',      'display_order' => 60, 'required' => true,  'in_type_source' => 'vampire-identity.Clan' ],
							// PC-4 (point-calculator-design.md §3.2): BM-9 added this block to the
							// sheet_full/npc_full *templates* but never to the stack's own sections -
							// resolve() never returned it, so a resolve()-only walk (the point audit
							// included) silently priced every held blood-magic path at zero, and
							// create_item() 400'd on a hand-created character carrying it at all.
							[ 'block_slug' => 'vampire-blood-magic',       'label' => 'Blood Magic',      'display_order' => 61, 'required' => false ],
							[ 'block_slug' => 'vampire-combo-disciplines', 'label' => 'Combo Disciplines','display_order' => 62, 'required' => false ],
							[ 'block_slug' => 'vampire-rituals',           'label' => 'Rituals',          'display_order' => 63, 'required' => false ],
							[ 'block_slug' => 'vampire-ritae',             'label' => 'Ritae',            'display_order' => 64, 'required' => false ],
							[ 'block_slug' => 'vampire-statuses',          'label' => 'Status',           'display_order' => 70, 'required' => false ],
							[ 'block_slug' => 'vampire-resources',         'label' => 'Resources',        'display_order' => 80, 'required' => true  ],
							[ 'block_slug' => 'vampire-virtues',           'label' => 'Virtues',          'display_order' => 81, 'required' => true  ],
							[ 'block_slug' => 'vampire-health',            'label' => 'Health',           'display_order' => 82, 'required' => false ],
						]
					),
					'display_preferences' => [ 'discipline_display' => 'named' ],
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
							[ 'block_slug' => 'werewolf-health',    'label' => 'Health',    'display_order' => 82, 'required' => false ],
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
							[ 'block_slug' => 'mage-health',     'label' => 'Health',     'display_order' => 82, 'required' => false ],
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
							[ 'block_slug' => 'changeling-health',    'label' => 'Health',    'display_order' => 82, 'required' => false ],
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
							[ 'block_slug' => 'demon-health',    'label' => 'Health',    'display_order' => 82, 'required' => false ],
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
							[ 'block_slug' => 'mummy-health',    'label' => 'Health',    'display_order' => 82, 'required' => false ],
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
							[ 'block_slug' => 'kueijin-health',      'label' => 'Health',      'display_order' => 82, 'required' => false ],
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
							[ 'block_slug' => 'mortal-health',    'label' => 'Health',    'display_order' => 82, 'required' => false ],
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
							[ 'block_slug' => 'werewolf-health',    'label' => 'Health',    'display_order' => 82, 'required' => false ],
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
							[ 'block_slug' => 'werewolf-health',    'label' => 'Health',    'display_order' => 82, 'required' => false ],
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
	 * Real Grapevine "extended" Health Levels composition (every *Class.cls's
	 * HealthList.Initialize/.Append sequence: hlStdHealth0..3 are identical
	 * across every race that has one - Healthy(2), Bruised(3), Wounded(2),
	 * Incapacitated(1) - only the terminal box(es) differ). Wraith has no
	 * HealthList at all (uses its own Corpus resource pool, already modeled);
	 * every other stack gets one of these three shapes. Old/standard Health is
	 * deliberately not offered - Grapevine chronicles play Extended.
	 *
	 * @param string $terminal       Name of the final box (`Mortally Wounded` or `Torpor`).
	 * @param array  $extra_terminal Additional trailing boxes past the terminal one (Mummy's two `Dead`).
	 * @return array<int,array{name:string,count:int}>
	 */
	private static function health_composition( string $terminal, array $extra_terminal = [] ): array {
		return array_merge(
			[
				[ 'name' => 'Healthy',       'count' => 2 ],
				[ 'name' => 'Bruised',       'count' => 3 ],
				[ 'name' => 'Wounded',       'count' => 2 ],
				[ 'name' => 'Incapacitated', 'count' => 1 ],
				[ 'name' => $terminal,       'count' => 1 ],
			],
			$extra_terminal
		);
	}

	/**
	 * Builds a stack's `{stack}-health` block: an ordinary, unpriced `trait_list`
	 * (Grapevine's own HealthList is a plain LinkedTraitList, same construct as
	 * Merits - confirmed against pp-samples/*-pc-print.html and a real .gex export).
	 * `default_held` is read by `Characters_Controller::create_item()` to populate
	 * a new character's sheet_data automatically; it is never priced (no `cost` key
	 * on any item, matching Grapevine's own estimator having no Health entry at all).
	 *
	 * @param string $stack_slug
	 * @param array  $composition health_composition()'s return value.
	 * @return array
	 */
	private static function make_health_block( string $stack_slug, array $composition ): array {
		return self::make_trait_list_block(
			"{$stack_slug}-health",
			'Health',
			array_map( static fn( array $c ): string => $c['name'], $composition ),
			[
				'allow_custom' => false,
				'default_held' => $composition,
			]
		);
	}

	/**
	 * A power's real rank, by tier - matches `Cost_Engine::TIER_COSTS`'s own vocabulary
	 * and order exactly (innate excluded: free/automatic, never a numbered level).
	 * `make_tiered_power_block()` (1.2.5-design-workflow.md §A, A1) assigns a level from
	 * this map only when a family has exactly one item at that tier; two or more share
	 * the rank and both get level=null, the same named-pool shape Decision 037 already
	 * uses for Elder-and-above. Retires `NAMED_POWER_NUMBERED_LEVELS`'s old flat cap,
	 * which nulled everything past position 10 regardless of whether a real tie existed
	 * there - real ties inside the 1-5 range (a family's own basic/intermediate tiers
	 * each carrying more than one named option, not just Elder+) were the actual defect.
	 */
	/**
	 * Per-block declared mechanics, emitted as `definition._meta` (1.2.10 §A/S3b).
	 *
	 * **This table is the GVM bridge, and it is deliberately temporary.** The GVM states no
	 * mechanics at all - it is a menu dump - so while it remains the content source something
	 * has to declare the ladder split, the rank vocabulary and the out-of-type modifier. At
	 * 1.3.2 the shipped JSON carries all of it and this constant is deleted.
	 *
	 * `ranks` is per block because genre vocabularies genuinely differ: Wraith needs `innate`
	 * below basic, Kuei-Jin uses Vampire's three words at its own prices. A single global
	 * TIER_RANKS cannot express either, which is why that constant stops being a ranking
	 * authority here.
	 *
	 * `ladder` names only the ranks that contribute **numbered rungs**. A rank in `ranks` but
	 * absent from `ladder` is a **pick**, keyed by its own rank inside `elder` - which covers
	 * above-ladder ranks (elder, master, ascended, methuselah) and equally Wraith's `innate`,
	 * which `reference/MET-POWER-ACQUISITION.md` establishes is "neither a rung nor an
	 * above-ladder pick... it sits below the ladder, is never counted in the rating."
	 *
	 * `out_of_type` is an **expression per rank**, never a number. That is what absorbs the
	 * two cases a scalar broke on - Mage scales (+1/+2/+3) and Demon doubles - and it retires
	 * Wraith's Innate exemption, which is simply `+0`.
	 *
	 * **No cost is declared here, deliberately.** `costs` is derived from each block's own
	 * seeded level data (see meta_costs_for()), so 1.2.10 changes no price on any sheet. The
	 * known-wrong values - Wraith's 94 mispriced levels (D69), Mage's non-specialty base
	 * (D70) - are corrected in 1.3.0 against the real books, not smuggled in here.
	 */
	const TIERED_POWER_META = [
		'vampire-disciplines' => [
			'ranks'          => [ 'basic', 'intermediate', 'advanced', 'elder', 'master', 'ascended', 'methuselah' ],
			'ladder'         => [ 'basic' => 2, 'intermediate' => 2, 'advanced' => 1 ],
			'out_of_type'    => [ 'basic' => '+1', 'intermediate' => '+1', 'advanced' => '+1', 'elder' => '+1', 'master' => '+1', 'ascended' => '+1', 'methuselah' => '+1' ],
			'in_type_source' => 'vampire-identity.Clan',
		],
		'vampire-blood-magic' => [
			'ranks'  => [ 'basic', 'intermediate', 'advanced' ],
			'ladder' => [ 'basic' => 2, 'intermediate' => 2, 'advanced' => 1 ],
		],
		'wraith-arcanoi' => [
			// `innate` is in `ranks` but not in `ladder`: a pick below the ladder.
			'ranks'       => [ 'innate', 'basic', 'intermediate', 'advanced' ],
			'ladder'      => [ 'basic' => 2, 'intermediate' => 2, 'advanced' => 1 ],
			// The Guild's apprenticeship discount, and Innate's exemption from it as `+0`.
			'out_of_type' => [ 'innate' => '+0', 'basic' => '-1', 'intermediate' => '-1', 'advanced' => '-1' ],
		],
		'mage-spheres' => [
			'ranks'       => [ 'basic', 'intermediate', 'advanced' ],
			'ladder'      => [ 'basic' => 2, 'intermediate' => 2, 'advanced' => 1 ],
			// Non-specialty scales rather than adding a flat surcharge: 4/8/12 -> 5/10/15.
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
			// No modifier in the chart at all, and OWBN's own 4/7/10 overrides any book cost
			// (owner ruling, 2026-09-21) - so `out_of_type` is genuinely absent, not empty.
			'ranks'  => [ 'basic', 'intermediate', 'advanced' ],
			'ladder' => [ 'basic' => 2, 'intermediate' => 2, 'advanced' => 1 ],
		],
		'mortal-numina' => [
			'ranks'  => [ 'basic', 'intermediate', 'advanced', 'elder', 'master', 'ascended', 'methuselah' ],
			'ladder' => [ 'basic' => 2, 'intermediate' => 2, 'advanced' => 1 ],
		],
		'changeling-realms' => [
			// Realms carry no tier vocabulary at all - a flat per-dot track. S7 declares that
			// properly; until then every level reads as `unknown` and fills the ladder in
			// source order, exactly as it did before this change.
			'ranks'  => [ 'basic', 'intermediate', 'advanced' ],
			'ladder' => [ 'basic' => 2, 'intermediate' => 2, 'advanced' => 1 ],
		],
	];

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
	 * Converts each resolved power into a `levels` list, deriving each item's rank from
	 * its tier (self::TIER_RANKS) rather than its position in the source list - a tier
	 * with exactly one item gets that real rank number, a tier with several (several
	 * powers legitimately sharing one rank) gets level=null on all of them, an unordered
	 * named pool identified by power_name instead.
	 *
	 * @param string $slug
	 * @param string $name
	 * @param array  $powers_in Resolved powers: each with 'name', 'source' and 'items',
	 *                          or a bare name string from the hardcoded fallback. Any
	 *                          other key (e.g. blood magic's per-power 'traditions'
	 *                          map or 'restriction') rides through onto the built
	 *                          power unchanged - this function has no opinion on
	 *                          what a power carries beyond its own level data.
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

			// Pass 1: normalize each item's tier and count how many items this family has
			// at each tier - the rank fix (1.2.5-design-workflow.md §A, A1) needs this
			// before it can decide whether a tier gets a real numbered level.
			$raw_items       = [];
			$counts_per_tier = [];
			foreach ( $power['items'] as $item ) {
				$note = is_array( $item ) ? (string) ( $item['note'] ?? '' ) : '';
				$tier = self::normalize_tier( $note );
				$raw_items[] = [ 'item' => $item, 'note' => $note, 'tier' => $tier ];
				// 'unknown' (no real MET tier wording in the note at all - e.g. Changeling
				// Realms' flat per-dot cost items) never counts toward a tie; Pass 2 falls
				// back to list-position numbering for these instead, since there is no real
				// tier signal to derive a rank from.
				if ( $tier !== 'innate' && $tier !== 'unknown' ) {
					$counts_per_tier[ $tier ] = ( $counts_per_tier[ $tier ] ?? 0 ) + 1;
				}
			}

			// Pass 2: build each level. A tier with exactly one item in this family gets
			// its real, fixed rank number (self::TIER_RANKS); a tier with more than one -
			// several powers legitimately sharing one rank, the owner's own Garou/Numina
			// example - gets level=null on every one of them, the same named-pool shape
			// Decision 037 already established for Elder-and-above. An 'unknown'-tier item
			// (no real tier wording at all) gets a real numbered level from its own position
			// among the family's other unknown-tier items, exactly as this function always
			// numbered every item before the D66 tier-rank fix - the only signal available
			// for a family whose own source data carries no tier vocabulary. `sequential`
			// families (vampire-disciplines, wraith-arcanoi, ...) still price a real numbered
			// tier as a cumulative step and a tied tier as a flat per-pick cost - both paths
			// already exist in Cost_Engine, unchanged by this fix.
			$levels        = [];
			$unknown_index = 0;
			foreach ( $raw_items as $raw ) {
				$item = $raw['item'];
				$note = $raw['note'];
				$tier = $raw['tier'];

				if ( $tier === 'unknown' ) {
					++$unknown_index;
					$level = [
						'level'      => $unknown_index,
						'power_name' => is_array( $item ) ? $item['name'] : (string) $item,
						'tier'       => $tier,
					];
				} else {
					$tied  = ( $counts_per_tier[ $tier ] ?? 0 ) > 1;
					$level = [
						'level'      => ( $tier !== 'innate' && ! $tied ) ? ( self::TIER_RANKS[ $tier ] ?? null ) : null,
						'power_name' => is_array( $item ) ? $item['name'] : (string) $item,
						'tier'       => $tier,
					];
				}

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

			// 1.2.10 S2/S2b: the ladder, the picks and the overflow become three containers
			// rather than one array. This is the D68 fix - the stepper reads `levels` and the
			// pick list reads `elder`, and they cannot be confused because they are not the
			// same array. The flat `levels` built above is still the input; `split_levels()`
			// is the only thing that decides where each entry belongs.
			$split = self::split_levels( $slug, $levels );

			$built = [
				'name'   => $power['name'],
				'source' => $power['source'] ?? $power['name'],
				'levels' => $split['levels'],
			];
			if ( $split['elder'] !== [] ) {
				$built['elder'] = $split['elder'];
			}
			if ( $split['overflow'] !== [] ) {
				$built['overflow'] = $split['overflow'];
			}
			// Pass through any additional per-power field unchanged (blood magic's
			// 'traditions' map and 'restriction' are the only current users).
			$passthrough = array_diff_key( $power, [ 'name' => true, 'source' => true, 'items' => true ] );
			if ( $passthrough !== [] ) {
				$built += $passthrough;
			}
			$powers[] = $built;
		}
		return [
			'slug'         => $slug,
			'name'         => $name,
			'section_type' => 'tiered_power',
			'definition'   => array_merge( [
				'powers'                    => $powers,
				'_meta'                     => self::meta_for( $slug, $powers ),
				// Levels add up (F-040); a chronicle can still switch its own copy to flat pricing.
				'sequential'                => true,
				'out_of_type_cost_modifier' => 1,
				// Same default as make_trait_list_block(); a chronicle's own homebrew power isn't blocked.
				'allow_custom'              => true,
			], $extra ),
			'is_system'    => 1,
			'created_by'   => 0,
		];
	}

	/**
	 * Splits one family's flat level list into the three declared containers (1.2.10 §A/§A1b).
	 *
	 * The rule is entirely positional against the block's own declared `ladder`, with no
	 * inference left in it:
	 *
	 * - A level whose rank **contributes rungs** fills the ladder, in rank order then source
	 *   order, and is renumbered 1..n where n is the ladder sum.
	 * - A level whose rank is in `ranks` but **not** in `ladder` is a **pick**, filed under
	 *   its own rank inside `elder`. That covers above-ladder ranks and equally Wraith's
	 *   `innate`, which `reference/MET-POWER-ACQUISITION.md` establishes "sits below the
	 *   ladder, is never counted in the rating."
	 * - A ladder-rank level **beyond** the ladder sum goes to `overflow`. 262 of these exist
	 *   across 69 families on the first run (`Animalism` 12, `Protean` 13). Nothing is
	 *   discarded and the problem is visible per family. **D67 empties it** - a non-empty
	 *   overflow is exactly the signal that a family still needs its human ruling, which is
	 *   1.3.1's worklist.
	 *
	 * `unknown` counts as a ladder rank here: a family whose source carries no tier wording
	 * at all (Changeling Realms' flat per-dot track) numbered positionally before this change
	 * and still does. S7 declares that track properly.
	 *
	 * @param string $slug
	 * @param array  $levels
	 * @return array{levels:array,elder:array,overflow:array}
	 */
	/**
	 * Re-splits one already-stored `tiered_power` definition into the three containers and
	 * gives it a `_meta`, using **the same `split_levels()`/`meta_for()` the seeder uses** -
	 * never a second copy of the rule that could drift from it.
	 *
	 * **Why this exists (1.2.10, found pre-deploy 2026-09-22).** `seed_schema_blocks()`
	 * refreshes `is_system = 1` rows only, which is correct - a chronicle's own edited block
	 * must never be overwritten by a reseed. But it means a **fork keeps the flat pre-1.2.10
	 * shape forever**, and a flat block has no `_meta`, so `Cost_Engine` falls through to the
	 * pre-1.2.10 one-tier-per-rank fallback. Measured against the real definition: a forked
	 * block prices a Discipline at 5 as **45 XP** where the reseeded global prices **27**.
	 * Two prices for the same Discipline on one install, on exactly the chronicles engaged
	 * enough to have house rules - and it never self-heals.
	 *
	 * Idempotent: a definition that already declares `_meta` is returned untouched, so this
	 * is safe to run on every upgrade.
	 *
	 * @param string              $slug       The block's own slug - picks the declared ladder.
	 * @param array<string,mixed> $definition A decoded tiered_power definition.
	 * @return array<string,mixed>|null The rewritten definition, or null when nothing changed.
	 */
	public static function split_stored_definition( string $slug, array $definition ): ?array {
		if ( isset( $definition['_meta'] ) ) {
			return null; // Already declared - a later reseed or an earlier run of this.
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
			// A fork's own levels, not the global's - the whole point of a fork is that they
			// differ. `split_levels()` is positional against the declared ladder, so it reads
			// any family's own list.
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
				// A pick, keyed by its own rank - never flattened into one list, because
				// merging the two depths is what recreated D68.
				//
				// And **never a rung number**. The pre-split shape numbered some
				// above-ladder powers positionally (Celerity's `Zephyr` carried 6), and
				// carrying that through would leave a pick claiming a place on a ladder it
				// is not on - exactly the ambiguity this release removes.
				$level['level']   = null;
				$elder[ $tier ][] = $level;
				continue;
			}
			$rungs[] = $level;
		}

		// Each rank fills **its own declared quota**, in rank order, and every level past that
		// rank's quota is overflow - never promoted into a neighbouring rank's rungs.
		//
		// Filling the ladder positionally instead was wrong, and measurably so: Animalism
		// carries more than two basic items, so all five rungs came out basic and a level-5
		// holding priced 3+3+3+3+3 instead of 3+3+6+6+9. The declared ladder is a quota per
		// rank, not just a total.
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
				// This rank is full. Not a rung, so it carries no rung number.
				$level['level'] = null;
				$overflow[]     = $level;
			}
			unset( $by_rank[ (string) $rank ] );
		}

		// `unknown` - a family whose source states no tier at all (Changeling Realms' flat
		// per-dot track) - has no declared quota to fill, so it takes whatever the ladder has
		// left in source order. S7 declares that track properly and this branch retires.
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
	 * Builds a block's `_meta` (1.2.10 §A/S1).
	 *
	 * Structure comes from self::TIERED_POWER_META, which is declared. **Costs do not** -
	 * they are read back out of the block's own just-built levels, so this release changes no
	 * price on any sheet. The known-wrong values (D69's 94 mispriced Wraith levels, D70's Mage
	 * base) are corrected in 1.3.0 against the real books; smuggling them in here would make
	 * a schema release silently reprice live characters.
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

		// Only ranks this block actually declares. Without the filter, D72's corrupted
		// `mortal-numina` tiers - roughly twenty combo-Discipline names leaked into the tier
		// field, plus `legend` - would be emitted as though they were real ranks with real
		// prices, which is the structure inventing vocabulary rather than declaring it.
		// `unknown` survives because it is a sentinel the engine already understands and is
		// the only cost `changeling-realms` has until S7 declares its untiered track.
		// Both branches above always set `ranks`, so no null-coalesce here - PHPStan reads the
		// constant's literal shape and flags a `??` on it as dead code, correctly.
		$allowed = array_flip( array_merge( $declared['ranks'], [ 'unknown' ] ) );
		$costs   = array_intersect_key( self::meta_costs_for( $powers ), $allowed );
		if ( $costs !== [] ) {
			$declared['costs'] = $costs;
		}
		return $declared;
	}

	/**
	 * The per-rank cost a block's own seeded data actually uses, by plurality across every
	 * level carrying one - the same "read the block's real scale, never one hardcoded table"
	 * approach 1.2.5 established, since Mage's 5/10/15 and Vampire's 3/6/9/12 are both real.
	 *
	 * A rank whose levels carry no cost at all is omitted rather than defaulted to 0: absent
	 * means "this block never says", which is true and useful, where 0 would read as free.
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
	 * Eleven prose fields describing how an NPC is played, carried on the NPC sheet in
	 * addition to the ordinary character sheet - the three agenda fields (1.1.0 §3.7 item 2:
	 * Wants, Knows, Will Do If Unopposed) first, then the original eight. Flagged
	 * storyteller_only, so neither its section nor its stored values reach a viewer without
	 * be_manage_characters. A held value is keyed by field name (identity_field's own storage
	 * shape), so adding fields here on a reseed never disturbs an existing NPC's own answers
	 * to the original eight.
	 *
	 * @return array
	 */
	private static function make_npc_roleplaying_notes_block(): array {
		$fields = [];
		foreach ( [
			'Wants',
			'Knows',
			'Will Do If Unopposed',
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

	/**
	 * Builds the npc-quick-stats block (1.1.0 §3.7 item 1) - a quick NPC's condensed sheet,
	 * one shared shape for every creature stack rather than a per-stack variant. Not
	 * storyteller_only: a player cast to play the NPC (§3.8) needs to read it, unlike
	 * npc-roleplaying-notes.
	 *
	 * @return array
	 */
	private static function make_npc_quick_stats_block(): array {
		$fields = [
			[ 'name' => 'Physical', 'field_type' => 'number', 'required' => false ],
			[ 'name' => 'Social', 'field_type' => 'number', 'required' => false ],
			[ 'name' => 'Mental', 'field_type' => 'number', 'required' => false ],
			[ 'name' => 'Willpower', 'field_type' => 'number', 'required' => false ],
			[ 'name' => 'Health', 'field_type' => 'text', 'required' => false ],
			[ 'name' => 'Key Abilities', 'field_type' => 'textarea', 'required' => false ],
			[ 'name' => 'Powers', 'field_type' => 'textarea', 'required' => false ],
			[ 'name' => 'Equipment', 'field_type' => 'textarea', 'required' => false ],
			[ 'name' => 'Notes', 'field_type' => 'textarea', 'required' => false ],
		];

		return self::make_identity_block( 'npc-quick-stats', 'NPC Quick Stats', $fields );
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
	 * @see BE_PROCESS/releases/workflow-0.3.md Step 8
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

		self::seed_npc_quick_templates();
	}

	/**
	 * Seeds one `npc_quick` global template per creature stack (1.1.0 §3.7 item 1): the
	 * stack's own identity section, then npc-quick-stats, then npc-roleplaying-notes - a
	 * condensed sheet for an NPC that doesn't need the full character sheet, and the one a
	 * cast player (§3.8) actually reads. Idempotent per stack, matching seed_npc_templates()'s
	 * own guard shape.
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
					// width must be null, 'third', 'half', or 'full' - Template::validate_layout()'s
					// own allowlist (the same defect class as 1.0.0-review F-077: an unknown width
					// once stopped every signed sheet on the stack).
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
			[ 'vampire-blood-magic', 'full', null ],
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
	 * Option set once the demo chronicle has had its one chance to be seeded.
	 */
	const DEMO_SEEDED_OPTION = 'be_demo_seeded';

	/**
	 * Seeds 22 demo characters (2 per creature stack, all 11 types) into a
	 * dedicated `be-demo` game, created first if it does not exist yet -
	 * never into a real chronicle's own game.
	 *
	 * Runs on a fresh install only, once. Every activation and version upgrade
	 * calls this, and it used to re-create a deleted demo chronicle each time -
	 * adopting whatever a row-only delete had left under its slug (1.0.0-review
	 * F-036). An install upgrading from before this flag existed is not fresh,
	 * so its demo stays exactly as it is, deleted or not.
	 *
	 * Idempotent per character, checked by name + owner_slug. Never throws:
	 * a fixture entry referencing a block that does not resolve is logged
	 * and skipped rather than failing the whole activation.
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
