<?php

namespace BeyondElysium\Services;

use BeyondElysium\Database\Manager;
use BeyondElysium\Database\Option_Lock;
use BeyondElysium\Database\Schema;
use BeyondElysium\Database\Seeder;
use BeyondElysium\Database\Transaction;
use BeyondElysium\Models\Change;
use BeyondElysium\Models\Character;
use BeyondElysium\Models\Creature_Stack;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Schema_Block;
use BeyondElysium\Models\Snapshot;
use BeyondElysium\Models\Template;

defined( 'ABSPATH' ) || exit;

/**
 * The one switch and the one resolver for the declared-catalog cutover (§3.2).
 *
 * `OPTION` absent means legacy - every stack, template and slug resolves exactly as it
 * does today. Once `apply()` (built in R5) sets it to `declared`, `live_slug()` becomes
 * the single function every slug-naming consumer calls to translate a retired GVM-era
 * slug (`met-abilities`) to the declared block that replaced it for that stack
 * (`vampire-abilities`) - in legacy it is the identity function, so wiring it into a
 * call site changes nothing until an install is actually cut over.
 *
 * `plan()` is the read-only half of the re-key: what `apply()` would do, per character,
 * changing nothing. `apply()` does it, and `rollback()` undoes it.
 */
class Catalog_Cutover {

	/** 'declared' once apply() has run; absent (get_option()'s own default, false) means legacy. */
	const OPTION = 'be_catalog_cutover';

	/** What apply() changed, for rollback(). Written by R5, read by R6. */
	const RECORD_OPTION = 'be_catalog_cutover_record';

	/** Option_Lock name guarding apply()/rollback() against concurrent runs. */
	const LOCK = 'be_catalog_cutover_lock';

	/** Seconds a held lock counts as live: a whole-install re-key runs in well under this, and a run that died holding it must not block the next one for good. */
	private const LOCK_TTL = 1800;

	/** Seconds after a claim that saves are still refused: a run takes seconds, and one that died holding the lock must not freeze every save for its whole 30 minute claim. */
	public const WRITE_HOLD = 120;

	/**
	 * Whether this install has been cut over to the declared catalog.
	 */
	public static function is_declared(): bool {
		return get_option( self::OPTION ) === 'declared';
	}

	/**
	 * Puts a brand-new install straight onto the declared catalog (1.3.4). Activation calls it once,
	 * before anything is seeded, so the stacks, the default templates and the demo characters are all
	 * built on the per-creature blocks and there is nothing to re-key. It never touches an install that
	 * already exists: one already declared, one that holds a character, and a build with no declared
	 * catalog are all left as they are, and the rest stay on the shared lists until `apply()`.
	 */
	public static function declare_fresh_install(): bool {
		if ( self::is_declared() || ! static::catalog_available() || self::character_count() > 0 ) {
			return false;
		}
		update_option( self::OPTION, 'declared', true );
		update_option( self::RECORD_OPTION, [
			'applied_at'     => gmdate( 'c' ),
			'actor'          => 0,
			'plugin_version' => defined( 'BE_VERSION' ) ? BE_VERSION : null,
			'fresh_install'  => true,
		], false );
		return true;
	}

	private static function character_count(): int {
		global $wpdb;
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Manager::table( 'characters' ) );
	}

	/**
	 * Whether an `apply()` or `rollback()` run is in flight right now. The REST write guard asks it: a
	 * save that lands between a character's re-key and the site's flip is written to the retired block
	 * of a sheet that has already moved, and `live_slug()` cannot map it until the flip.
	 */
	public static function switching(): bool {
		$held = Option_Lock::held_since( self::LOCK );
		return $held > 0 && ( time() - $held ) < self::WRITE_HOLD;
	}

	/**
	 * Where this install stands, for `wp be cutover status` and the subsite runner: whether it is on
	 * the declared catalog, when and by whom it was applied, what a rollback would undo, and whether a
	 * run holds the lock right now.
	 *
	 * @return array<string,mixed>
	 */
	public static function status(): array {
		$record = get_option( self::RECORD_OPTION );
		$record = is_array( $record ) ? $record : [];
		$held   = (int) get_option( self::LOCK, 0 );

		return [
			'available'          => static::catalog_available(),
			'declared'           => self::is_declared(),
			'fresh_install'      => ! empty( $record['fresh_install'] ),
			'locked'             => $held > 0 && ( time() - $held ) < self::LOCK_TTL,
			'applied_at'         => $record['applied_at'] ?? null,
			'actor'              => isset( $record['actor'] ) ? (int) $record['actor'] : null,
			'plugin_version'     => $record['plugin_version'] ?? null,
			'characters_rekeyed' => count( self::standing_rekeys() ),
			'templates_changed'  => count( (array) ( $record['templates'] ?? [] ) ),
			'pending_rewritten'  => count( (array) ( $record['pending'] ?? [] ) ),
		];
	}

	/**
	 * The declared catalog's own retirement map for one stack - retired slug => the
	 * declared block that replaced it - regardless of whether this install has actually
	 * cut over yet. Source: `Catalog_Reader::replacement_maps()`, which already memoizes
	 * per catalog root; nothing further is cached here so a test's `reset_cache()` never
	 * has two layers to keep in sync.
	 *
	 * @return array<string,string>
	 */
	public static function replacement_map( string $stack_slug ): array {
		$maps = Catalog_Reader::replacement_maps();
		return $maps[ $stack_slug ] ?? [];
	}

	/**
	 * The slug a consumer should actually use for `$slug` on `$stack_slug`: the
	 * replacement once this install is declared, unchanged otherwise. The one function
	 * every slug-naming call site (C7) calls instead of trusting its own stored slug.
	 */
	public static function live_slug( string $stack_slug, string $slug ): string {
		if ( ! self::is_declared() ) {
			return $slug;
		}
		$map = self::replacement_map( $stack_slug );
		return $map[ $slug ] ?? $slug;
	}

	/**
	 * Test isolation only. `is_declared()` reads `get_option()` live (WordPress's own
	 * option cache already invalidates on `update_option()`/`delete_option()`, so there is
	 * nothing of this class's own to reset there); `replacement_map()` has no cache of its
	 * own to clear either, but resetting `Catalog_Reader`'s keeps a test from leaking a
	 * fixture root into the next one via that shared cache.
	 */
	public static function reset_cache(): void {
		Catalog_Reader::reset_cache();
	}

	/**
	 * Rewrites every template's own retired slugs onto their live replacements, in place -
	 * position, width, title and display untouched. Every template row, global and
	 * chronicle-owned alike (`Template::all()`, not `globals()`): a chronicle's own forked
	 * template left on a retired slug renders an empty section exactly like a global one
	 * would, and this is a rename of a block, not a rearrangement a fork should have to
	 * redo by hand.
	 *
	 * Callers, not this method, decide when it should run - it applies whatever
	 * `replacement_map()` states regardless of `is_declared()`, so `apply()` (R5) can call
	 * it in the same step that sets `OPTION`, and `run_upgrade()` (C6) can call it on every
	 * later upgrade of an already-declared install.
	 *
	 * Idempotent by construction, not by a separate check: a section already on its live
	 * slug is not a key in `replacement_map()` (only a retired slug is), so a second run
	 * finds nothing to change and writes nothing.
	 *
	 * @return array<int,array{id:int,stack_slug:string,before:array,after:array}> one entry
	 *         per template actually changed, carrying both layouts - the pre-image R5/R6
	 *         will need to record for rollback.
	 */
	public static function rewrite_templates(): array {
		$rewritten = [];

		foreach ( Template::all() as $template ) {
			$map = self::replacement_map( (string) $template->stack_slug );
			if ( empty( $map ) ) {
				continue;
			}

			$before = $template->layout;
			$after  = self::rewrite_layout( $before, $map );

			if ( $after === $before ) {
				continue;
			}

			Template::update( (int) $template->id, [ 'layout' => $after ] );
			$rewritten[] = [
				'id'         => (int) $template->id,
				'stack_slug' => (string) $template->stack_slug,
				'before'     => $before,
				'after'      => $after,
			];
		}

		return $rewritten;
	}

	/**
	 * Maps one layout's `sections[].block_slug` and `sections[].title_refs[].block_slug`
	 * through `$map`, leaving every other field - width, title, display, column, order -
	 * untouched. A slug the map does not name (already live, or never retired) passes
	 * through unchanged.
	 *
	 * @param array<string,string> $map retired slug => live slug, one stack's own.
	 */
	private static function rewrite_layout( array $layout, array $map ): array {
		$layout['sections'] = array_map(
			static function ( array $section ) use ( $map ) {
				if ( isset( $section['block_slug'], $map[ $section['block_slug'] ] ) ) {
					$section['block_slug'] = $map[ $section['block_slug'] ];
				}
				if ( ! empty( $section['title_refs'] ) && is_array( $section['title_refs'] ) ) {
					$section['title_refs'] = array_map(
						static function ( array $ref ) use ( $map ) {
							if ( isset( $ref['block_slug'], $map[ $ref['block_slug'] ] ) ) {
								$ref['block_slug'] = $map[ $ref['block_slug'] ];
							}
							return $ref;
						},
						$section['title_refs']
					);
				}
				return $section;
			},
			$layout['sections'] ?? []
		);

		return $layout;
	}

	/**
	 * The read-only cutover plan (§3.5, §3.8): what `apply()` would do to every character, and
	 * what it would refuse over, changing nothing anywhere.
	 *
	 * One `Custom_Rekey::plan_character()` per character, each against the blocks *that
	 * character's chronicle* sees (`Schema_Block::find_by_slugs_for_game()`, so a fork is honored -
	 * none exist in production, and this does not assume that), summed into one report:
	 *
	 * - `totals` / `by_game`: rows moved, re-keyed and left custom, by tier and by reason. `respelled` (totals
	 *   only) is the catalog rows renamed to the declared spelling; the others count custom entries.
	 * - `retention_gaps`: a catalog row the replacement block does not carry. **`apply()` refuses
	 *   the whole install while any exist.**
	 * - `duplicates`: identities already doubled on a sheet before anything moved. Reported only.
	 * - `unmapped_retired_data`: rows a character holds under a legacy block the declared stack no
	 *   longer lists and that has no replacement (`demon-lores`, the old `mortal-numina`) - they
	 *   are not lost, but the declared sheet does not show them. Only meaningful before the
	 *   cutover: it is computed against the stack as currently stored.
	 * - `pending_changes`: pending changes naming a retired slug, which `apply()` rewrites.
	 * - `untouched` / `by_game[..].untouched`: characters exactly as they were imported with nothing of
	 *   the site's own attached (`Untouched_Characters`) - the ones a delete and re-import could replace
	 *   instead of a re-key. Informational; nothing here deletes anything.
	 * - `rows`: every custom row's outcome with its character, the source of the plan CSV.
	 *
	 * @param string|null                          $game_slug One chronicle, or every one on the install.
	 * @param array{suggestions?:bool,rows?:bool}  $options   `suggestions` (default true): `Fuzzy_Matcher`
	 *                                                        candidates on a row that stays custom. `rows`
	 *                                                        (default true): include the per-row list.
	 * @return array<string,mixed>
	 */
	public static function plan( ?string $game_slug = null, array $options = [] ): array {
		$suggest   = $options['suggestions'] ?? true;
		$with_rows = $options['rows'] ?? true;

		$report = [
			'available'             => static::catalog_available(),
			'declared'              => self::is_declared(),
			'game'                  => $game_slug,
			'characters'            => 0,
			'characters_changed'    => 0,
			'totals'                => [ 'moved_rows' => 0, 'rekeyed' => 0, 'respelled' => 0, 'kept_custom' => 0, 'tiered_custom' => 0, 'by_tier' => [], 'by_reason' => [] ],
			'by_game'               => [],
			'retention_gaps'        => [],
			'duplicates'            => [],
			'unmapped_retired_data' => [],
			'pending_changes'       => [],
			'untouched'             => [ 'characters' => 0, 'with_player' => 0 ],
			'rows'                  => [],
		];
		if ( ! $report['available'] ) {
			return $report;
		}

		$untouched = Untouched_Characters::find( $game_slug );

		$games = $game_slug !== null
			? [ $game_slug ]
			: array_map( static fn( $game ) => (string) $game->slug, Game::all() );
		$cache = [];

		foreach ( $games as $slug ) {
			$report['by_game'][ $slug ] = [ 'characters' => 0, 'changed' => 0, 'rekeyed' => 0, 'kept_custom' => 0, 'untouched' => 0 ];

			foreach ( Character::all_for_game( $slug ) as $character ) {
				$planned = self::plan_character_row( $character, $cache, $suggest );
				$result  = $planned['result'];
				$who     = [ 'character_id' => (int) $character->id, 'character' => (string) $character->name, 'game' => $slug ];

				$report['characters']++;
				$report['by_game'][ $slug ]['characters']++;
				if ( $result['changed'] ) {
					$report['characters_changed']++;
					$report['by_game'][ $slug ]['changed']++;
				}
				if ( isset( $untouched[ (int) $character->id ] ) ) {
					$report['untouched']['characters']++;
					$report['by_game'][ $slug ]['untouched']++;
					if ( $untouched[ (int) $character->id ] ) {
						$report['untouched']['with_player']++;
					}
				}

				foreach ( [ 'moved_rows', 'rekeyed', 'respelled', 'kept_custom', 'tiered_custom' ] as $counter ) {
					$report['totals'][ $counter ] += $result['counts'][ $counter ];
				}
				foreach ( [ 'by_tier', 'by_reason' ] as $breakdown ) {
					foreach ( $result['counts'][ $breakdown ] as $label => $n ) {
						$report['totals'][ $breakdown ][ $label ] = ( $report['totals'][ $breakdown ][ $label ] ?? 0 ) + $n;
					}
				}
				$report['by_game'][ $slug ]['rekeyed']     += $result['counts']['rekeyed'];
				$report['by_game'][ $slug ]['kept_custom'] += $result['counts']['kept_custom'];

				foreach ( $result['retention_gaps'] as $gap ) {
					$report['retention_gaps'][] = $who + $gap;
				}
				foreach ( $result['duplicates'] as $duplicate ) {
					$report['duplicates'][] = $who + $duplicate;
				}
				foreach ( $planned['unmapped'] as $orphan ) {
					$report['unmapped_retired_data'][] = $who + $orphan;
				}
				if ( $with_rows ) {
					foreach ( $result['records'] as $record ) {
						$report['rows'][] = $who + $record;
					}
				}
			}
		}

		$report['pending_changes'] = self::pending_rewrites( $game_slug );

		return $report;
	}

	/**
	 * Whether a declared catalog ships with this install. A seam, called as `static::` so a test
	 * can stand in an install without one - `plan()` reports it and `apply()` (R5) refuses on it,
	 * since flipping `OPTION` with no catalog to seed from would leave a declared install running
	 * legacy stacks.
	 */
	protected static function catalog_available(): bool {
		return Catalog_Reader::available();
	}

	/**
	 * One character's plan, from the character as it is *now*. `plan()` calls it read-only;
	 * `apply()` (R5) calls it again from a fresh read inside the character's own transaction,
	 * because the sheet may have changed since the install-wide plan was made.
	 *
	 * @param object                    $character A row with a decoded (array) `sheet_data`.
	 * @param array<string,mixed>       $cache     Shared across one run, by reference: block rows per
	 *                                             chronicle, each stack's stored and declared sections.
	 * @return array{result:array<string,mixed>,unmapped:array<int,array<string,mixed>>}
	 */
	public static function plan_character_row( object $character, array &$cache, bool $suggest = true ): array {
		$owner = (string) ( $character->owner_slug ?? '' );
		$stack = (string) ( $character->stack_slug ?? '' );
		$map   = self::replacement_map( $stack );
		$sheet = is_array( $character->sheet_data ?? null ) ? $character->sheet_data : [];

		// Every list this sheet holds, and every block a row may move to.
		$needed  = array_values( array_unique( array_merge(
			array_keys( array_filter( $sheet, 'is_array' ) ),
			array_values( $map )
		) ) );
		$known   = $cache['blocks'][ $owner ] ?? [];
		$missing = array_values( array_diff( $needed, array_keys( $known ) ) );
		if ( $missing ) {
			$fetched = Schema_Block::find_by_slugs_for_game( $missing, $owner );
			foreach ( $missing as $slug ) {
				$known[ $slug ] = $fetched[ $slug ] ?? false; // false: asked for, not there.
			}
			$cache['blocks'][ $owner ] = $known;
		}
		$blocks = [];
		foreach ( $needed as $slug ) {
			$row = $known[ $slug ] ?? false;
			if ( $row && is_object( $row->definition ?? null ) && in_array( $row->section_type ?? '', [ 'trait_list', 'tiered_power' ], true ) ) {
				$blocks[ $slug ] = $row->definition;
			}
		}

		$result = Custom_Rekey::plan_character( $sheet, $blocks, $map, [ 'suggestions' => $suggest ] );

		return [ 'result' => $result, 'unmapped' => self::unmapped_retired_data( $stack, $sheet, $map, $cache ) ];
	}

	/**
	 * Rows a character holds under a legacy block its stack lists today, its declared stack
	 * does not, and no replacement covers.
	 *
	 * @param array<string,mixed>  $sheet
	 * @param array<string,string> $map
	 * @param array<string,mixed>  $cache
	 * @return array<int,array{block:string,rows:int}>
	 */
	private static function unmapped_retired_data( string $stack, array $sheet, array $map, array &$cache ): array {
		if ( self::is_declared() ) {
			return []; // The stored stack is already the declared one - there is no "legacy" to compare against.
		}
		if ( ! isset( $cache['declared_sections'] ) ) {
			$cache['declared_sections'] = [];
			foreach ( Catalog_Reader::stacks_to_seed() as $declared_stack => $definition ) {
				$cache['declared_sections'][ $declared_stack ] = array_column( (array) ( $definition['stack_definition']['sections'] ?? [] ), 'block_slug' );
			}
		}
		if ( ! isset( $cache['stored_sections'][ $stack ] ) ) {
			$stored                             = Creature_Stack::find_by_slug( $stack );
			$cache['stored_sections'][ $stack ] = $stored
				? array_map( static fn( $section ) => (string) ( $section->block_slug ?? '' ), (array) ( $stored->stack_definition->sections ?? [] ) )
				: [];
		}

		$retired = array_diff( $cache['stored_sections'][ $stack ], $cache['declared_sections'][ $stack ] ?? [], array_keys( $map ) );
		$orphans = [];
		foreach ( $retired as $slug ) {
			if ( isset( $sheet[ $slug ] ) && is_array( $sheet[ $slug ] ) && $sheet[ $slug ] !== [] ) {
				$orphans[] = [ 'block' => $slug, 'rows' => count( $sheet[ $slug ] ) ];
			}
		}
		return $orphans;
	}

	/**
	 * Pending changes whose `block_slug` a cutover retires for their character's stack -
	 * `apply()` rewrites them in place (§3.6 step 5) so a change queued on `met-merits` is
	 * still a change to that character's Merits after the flip.
	 *
	 * @return array<int,array{id:int,character_id:int,from:string,to:string}>
	 */
	public static function pending_rewrites( ?string $game_slug = null ): array {
		return self::pending_moves( true, $game_slug );
	}

	/**
	 * The pending changes a slug move applies to, in either direction: `$forward` takes a retired
	 * slug to its replacement (`apply()`), the reverse takes a replacement back (`rollback()`).
	 *
	 * @return array<int,array{id:int,character_id:int,from:string,to:string}>
	 */
	private static function pending_moves( bool $forward, ?string $game_slug ): array {
		$sql = 'SELECT ch.id, ch.character_id, ch.change_data, c.stack_slug FROM ' . Manager::table( 'character_changes' ) . ' ch'
			. ' JOIN ' . Manager::table( 'characters' ) . " c ON c.id = ch.character_id WHERE ch.status = 'pending'";
		$rows = $game_slug === null
			? Manager::get_results( $sql )
			: Manager::get_results( $sql . ' AND c.owner_slug = %s', $game_slug );

		$moves = [];
		foreach ( $rows as $row ) {
			$data   = json_decode( (string) $row->change_data, true );
			$from   = is_array( $data ) && is_string( $data['block_slug'] ?? null ) ? $data['block_slug'] : null;
			$lookup = self::replacement_map( (string) $row->stack_slug );
			$lookup = $forward ? $lookup : array_flip( $lookup );
			$to     = $from !== null ? ( $lookup[ $from ] ?? null ) : null;
			if ( $from !== null && $to !== null ) {
				$moves[] = [ 'id' => (int) $row->id, 'character_id' => (int) $row->character_id, 'from' => $from, 'to' => $to ];
			}
		}
		return $moves;
	}

	/**
	 * Applies the cutover (§3.6): re-keys every character, rewrites pending changes, flips the
	 * install to the declared catalog and records everything `rollback()` needs.
	 *
	 * Returns `status`:
	 * - `applied` - done. The declared catalog is live.
	 * - `already_declared` - nothing to re-key; only the idempotent repairs ran.
	 * - `locked` - another `apply()`/`rollback()` holds the lock.
	 * - `refused` - nothing was written: no declared catalog ships, a catalog row would be lost
	 *   (`retention_gaps`), or a declared stack names a block the database does not have.
	 * - `partial` - at least one character could not be re-keyed. Those that could are done (each
	 *   has its own `catalog_rekey` record) but **the install is not flipped**: a character left on
	 *   the retired keys would show its Abilities as empty on a declared sheet. Fix what `failed`
	 *   names and run `apply()` again - a re-key is idempotent, so it picks up where it stopped.
	 *
	 * XP columns are never read for writing and never written.
	 *
	 * @param int                           $actor_id WP user recorded as submitter of each `catalog_rekey` change.
	 * @param array{on_character?:callable} $options  `on_character( int $character_id )` runs after the
	 *                                                install-wide plan and before that character's own
	 *                                                transaction re-plans it - the seam for "changed
	 *                                                between plan and apply".
	 * @return array<string,mixed>
	 */
	public static function apply( int $actor_id, array $options = [] ): array {
		if ( ! static::catalog_available() ) {
			return [ 'status' => 'refused', 'reason' => 'no_declared_catalog' ];
		}
		if ( ! Option_Lock::claim( self::LOCK, self::LOCK_TTL ) ) {
			return [ 'status' => 'locked' ];
		}

		try {
			return self::is_declared() ? self::apply_repairs() : self::apply_cutover( $actor_id, $options );
		} finally {
			Option_Lock::release( self::LOCK );
		}
	}

	/**
	 * A declared install has nothing left to re-key; run only the repairs that are safe to repeat
	 * (§3.6 step 2), so a template restored from an old export or a change queued by an older path
	 * is corrected too.
	 *
	 * @return array<string,mixed>
	 */
	private static function apply_repairs(): array {
		return [
			'status'              => 'already_declared',
			'templates_rewritten' => count( self::rewrite_templates() ),
			'pending_rewritten'   => count( self::rewrite_pending() ),
		];
	}

	/**
	 * @param array{on_character?:callable} $options
	 * @return array<string,mixed>
	 */
	private static function apply_cutover( int $actor_id, array $options ): array {
		// Is the declared catalog in the database at all? Checked before planning: with a block
		// missing, every character holding rows for it would be reported as a gap instead.
		$missing = self::declared_blocks_missing();
		if ( $missing !== [] ) {
			return [ 'status' => 'refused', 'reason' => 'declared_blocks_missing', 'missing' => $missing ];
		}

		// 3. Plan the whole install first: one row that would be lost refuses all of it.
		$plan = self::plan( null, [ 'rows' => false, 'suggestions' => false ] );
		if ( $plan['retention_gaps'] !== [] ) {
			return [ 'status' => 'refused', 'reason' => 'retention_gaps', 'retention_gaps' => $plan['retention_gaps'] ];
		}
		// A plan walks chronicle by chronicle. A character in none - its chronicle deleted out from
		// under it - would be flipped past and left on retired keys, so it is refused, not skipped.
		$on_install = (int) Manager::get_var( 'SELECT COUNT(*) FROM ' . Manager::table( 'characters' ) );
		if ( $plan['characters'] !== $on_install ) {
			return [ 'status' => 'refused', 'reason' => 'characters_outside_any_chronicle', 'planned' => $plan['characters'], 'on_install' => $on_install ];
		}

		// 4. Each character in its own transaction, re-planned from a fresh read under a row lock.
		$cache     = [];
		$rekeyed   = [];
		$failed    = [];
		$unchanged = 0;
		$vanished  = 0;
		foreach ( Game::all() as $game ) {
			foreach ( Character::all_for_game( (string) $game->slug ) as $listed ) {
				$id = (int) $listed->id;
				if ( isset( $options['on_character'] ) ) {
					( $options['on_character'] )( $id );
				}
				$outcome = self::rekey_character( $id, $actor_id, $cache );
				if ( $outcome['outcome'] === 'changed' ) {
					$rekeyed[ $id ] = array_diff_key( $outcome, [ 'outcome' => 1 ] );
				} elseif ( $outcome['outcome'] === 'unchanged' ) {
					$unchanged++;
				} elseif ( $outcome['outcome'] === 'missing' ) {
					$vanished++; // Deleted since it was listed - nothing to re-key, nothing wrong.
				} else {
					$failed[ $id ] = $outcome;
				}
			}
		}
		if ( $failed !== [] ) {
			return [ 'status' => 'partial', 'characters_rekeyed' => count( $rekeyed ), 'failed' => $failed ];
		}

		// 5-6. Pre-images before anything install-wide changes.
		$pending          = self::rewrite_pending();
		$templates_before = [];
		foreach ( Template::all() as $template ) {
			$templates_before[ (int) $template->id ] = $template->layout;
		}
		$stacks_before = [];
		foreach ( Creature_Stack::all() as $stack ) {
			$stacks_before[ (string) $stack->slug ] = json_decode( (string) wp_json_encode( [
				'stack_definition' => $stack->stack_definition,
				'creation_rules'   => $stack->creation_rules,
			] ), true );
		}

		// 7. Flip, then bring templates and stacks onto the declared catalog.
		update_option( self::OPTION, 'declared', true );
		self::rewrite_templates();
		Seeder::seed_creature_stacks();
		Seeder::reconcile_stack_blocks();
		Schema::complete_full_sheet_templates();

		$templates = [];
		foreach ( Template::all() as $template ) {
			$id = (int) $template->id;
			if ( ! isset( $templates_before[ $id ] ) || $templates_before[ $id ] !== $template->layout ) {
				$templates[ $id ] = [
					'before'     => $templates_before[ $id ] ?? null,
					'after_hash' => self::hash_value( $template->layout ),
				];
			}
		}

		// 8. Everything rollback() needs.
		update_option( self::RECORD_OPTION, [
			'applied_at'     => gmdate( 'c' ),
			'actor'          => $actor_id,
			'plugin_version' => defined( 'BE_VERSION' ) ? BE_VERSION : null,
			'characters'     => $rekeyed,
			'pending'        => $pending,
			'templates'      => $templates,
			'stacks'         => $stacks_before,
		], false );

		return [
			'status'               => 'applied',
			'characters_rekeyed'   => count( $rekeyed ),
			'characters_unchanged' => $unchanged,
			'characters_vanished'  => $vanished,
			'rows_rekeyed'         => array_sum( array_column( $rekeyed, 'rekeyed' ) ),
			'rows_respelled'       => array_sum( array_column( $rekeyed, 'respelled' ) ),
			'rows_moved'           => array_sum( array_column( $rekeyed, 'moved' ) ),
			'pending_rewritten'    => count( $pending ),
			'templates_changed'    => count( $templates ),
		];
	}

	/**
	 * One character, all or nothing (§3.6 step 4): under a row lock, re-read and re-plan it -
	 * it may have changed since the install-wide plan - then record a `catalog_rekey` change, take
	 * the snapshot **before** the write, and write. Anything wrong rolls back this character only.
	 *
	 * @param array<string,mixed> $cache Shared across the run, by reference.
	 * @return array<string,mixed> `outcome` is `changed`, `unchanged`, `missing` or `failed`.
	 */
	private static function rekey_character( int $id, int $actor_id, array &$cache ): array {
		$savepoint = Transaction::begin( 'be_catalog_rekey' );
		try {
			Character::lock( $id );
			$character = Character::find( $id );
			if ( ! $character ) {
				Transaction::rollback( $savepoint );
				return [ 'outcome' => 'missing' ];
			}

			$result = self::plan_character_row( $character, $cache, false )['result'];
			if ( $result['retention_gaps'] !== [] ) {
				Transaction::rollback( $savepoint );
				return [ 'outcome' => 'failed', 'reason' => 'retention_gap', 'gaps' => $result['retention_gaps'] ];
			}
			if ( ! $result['changed'] ) {
				Transaction::commit( $savepoint );
				return [ 'outcome' => 'unchanged' ];
			}

			$before_hash = self::hash_value( $character->sheet_data );
			$after_hash  = self::hash_value( $result['sheet_data'] );
			$change_id   = Change::create( [
				'character_id' => $id,
				'change_type'  => 'catalog_rekey',
				'category'     => 'catalog',
				'change_data'  => [
					'counts'      => $result['counts'],
					'records'     => $result['records'],
					'before_hash' => $before_hash,
					'after_hash'  => $after_hash,
				],
				'xp_cost'      => 0,
				'status'       => 'approved',
				'submitted_by' => $actor_id,
				'notes'        => 'Catalog cutover: rows moved to the declared blocks.',
			] );
			if ( ! $change_id ) {
				throw new \RuntimeException( 'the catalog_rekey change could not be recorded' );
			}
			Change::update_status( $change_id, 'approved', $actor_id, null );
			Snapshot::create( $id, $change_id );
			if ( ! Character::update_sheet_data( $id, $result['sheet_data'] ) ) {
				throw new \RuntimeException( 'the re-keyed sheet could not be written' );
			}

			Transaction::commit( $savepoint );
			return [
				'outcome'     => 'changed',
				'change_id'   => $change_id,
				'before_hash' => $before_hash,
				'after_hash'  => $after_hash,
				'moved'       => $result['counts']['moved_rows'],
				'rekeyed'     => $result['counts']['rekeyed'],
				'respelled'   => $result['counts']['respelled'],
			];
		} catch ( \Throwable $e ) {
			Transaction::rollback( $savepoint );
			return [ 'outcome' => 'failed', 'reason' => $e->getMessage() ];
		}
	}

	/**
	 * §3.6 step 5: every pending change naming a retired slug now names its live replacement, so a
	 * change queued on `met-merits` is still a change to that character's Merits after the flip.
	 * Idempotent - a change already on its live slug is not in `pending_rewrites()`.
	 *
	 * @return array<int,array{id:int,from:string,to:string}> What was rewritten, for the pre-image.
	 */
	private static function rewrite_pending(): array {
		$rewritten = [];
		foreach ( self::pending_moves( true, null ) as $pending ) {
			$change = Change::find( $pending['id'] );
			if ( ! $change || ! is_array( $change->change_data ) ) {
				continue;
			}
			$data               = $change->change_data;
			$data['block_slug'] = $pending['to'];
			Manager::update( 'character_changes', [ 'change_data' => wp_json_encode( $data ) ], [ 'id' => $pending['id'], 'status' => 'pending' ] );
			$rewritten[] = [ 'id' => $pending['id'], 'from' => $pending['from'], 'to' => $pending['to'] ];
		}
		return $rewritten;
	}

	/**
	 * Blocks the declared stacks name that the database does not have. A declared stack pointing at
	 * a block that was never seeded is a sheet with a missing section, invisible until someone opens
	 * it - so the flip refuses rather than discovering it afterwards.
	 *
	 * @return string[] `stack -> block` pairs.
	 */
	private static function declared_blocks_missing(): array {
		$missing = [];
		foreach ( Catalog_Reader::stacks_to_seed() as $stack => $definition ) {
			foreach ( (array) ( $definition['stack_definition']['sections'] ?? [] ) as $section ) {
				foreach ( [ 'block_slug', 'negative_block_slug' ] as $key ) {
					if ( ! empty( $section[ $key ] ) && ! Schema_Block::find_by_slug( $section[ $key ] ) ) {
						$missing[] = $stack . ' -> ' . $section[ $key ];
					}
				}
			}
		}
		return $missing;
	}

	/**
	 * A stable fingerprint of a sheet or layout: sha256 of its JSON with object keys sorted and list
	 * order kept. MySQL's JSON column re-orders object keys, so hashing the array as read back would
	 * differ from the one written; sorting makes "the sheet is still exactly what apply() wrote"
	 * checkable by `rollback()`.
	 *
	 * @param mixed $value
	 */
	public static function hash_value( $value ): string {
		return hash( 'sha256', (string) wp_json_encode( self::canonical( $value ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
	}

	/**
	 * @param mixed $value
	 * @return mixed
	 */
	private static function canonical( $value ) {
		if ( ! is_array( $value ) ) {
			return $value;
		}
		if ( ! array_is_list( $value ) ) {
			ksort( $value, SORT_STRING );
		}
		return array_map( [ self::class, 'canonical' ], $value );
	}

	/**
	 * Undoes an `apply()` (§3.7): every re-keyed character goes back to the sheet its snapshot
	 * holds, the templates and pending changes go back, and the install returns to the GVM catalog.
	 *
	 * A character is restored only if its sheet still hashes to the `after_hash` `apply()` recorded -
	 * anything approved since would be erased by restoring the old one. So the run is two-phase:
	 * first, with **no writes**, every character is checked; if any changed and `$force` is off, the
	 * whole rollback is `blocked` and names them. Only then does anything change, one character per
	 * transaction, each writing an approved zero-XP `catalog_rekey_revert` change and a snapshot of
	 * the state it replaces - so a rollback can itself be undone by hand.
	 *
	 * `$force` restores a changed character anyway (its later changes are lost, and its revert
	 * record says `forced`).
	 *
	 * A template gets its pre-image back only if it still hashes to what `apply()` left. One that
	 * changed since (`templates_skipped`) keeps its layout and has its blocks pointed back at the
	 * old slugs instead (`templates_repointed`), so it reads what the restored sheets hold.
	 *
	 * Returns `status`: `rolled_back`, `blocked` (with `skipped`), `partial` (a character failed
	 * mid-run; the install stays declared, run again), `locked` or `nothing_to_roll_back`.
	 *
	 * @param array{on_character?:callable} $options `on_character( int $character_id )` runs after the
	 *                                               whole-install check and before that character's own
	 *                                               transaction re-checks it - the seam for "changed
	 *                                               between the check and the write".
	 * @return array<string,mixed>
	 */
	public static function rollback( int $actor_id, bool $force = false, array $options = [] ): array {
		if ( ! Option_Lock::claim( self::LOCK, self::LOCK_TTL ) ) {
			return [ 'status' => 'locked' ];
		}

		try {
			return self::rollback_locked( $actor_id, $force, $options );
		} finally {
			Option_Lock::release( self::LOCK );
		}
	}

	/**
	 * @param array{on_character?:callable} $options
	 * @return array<string,mixed>
	 */
	private static function rollback_locked( int $actor_id, bool $force, array $options ): array {
		$standing = self::standing_rekeys();
		$record   = get_option( self::RECORD_OPTION );
		if ( $standing === [] && ! is_array( $record ) && ! self::is_declared() ) {
			return [ 'status' => 'nothing_to_roll_back' ];
		}
		// An install created on the declared catalog was never on the shared lists, so there is nothing
		// to return it to, and flipping the switch off would strand stacks that were seeded declared.
		if ( $standing === [] && is_array( $record ) && ! empty( $record['fresh_install'] ) ) {
			return [ 'status' => 'started_declared' ];
		}

		// Phase 1, no writes: is every character still exactly what apply() wrote?
		if ( ! $force ) {
			$changed = [];
			foreach ( $standing as $id => $rekey ) {
				$character = Character::find( $id );
				if ( $character && self::hash_value( $character->sheet_data ) !== ( $rekey->change_data['after_hash'] ?? null ) ) {
					$changed[] = [ 'character_id' => $id, 'reason' => 'changed_since_cutover' ];
				}
			}
			if ( $changed !== [] ) {
				return [ 'status' => 'blocked', 'skipped' => $changed ];
			}
		}

		// Phase 2: each character in its own transaction, re-checked under the row lock.
		$reverted = [];
		$forced   = [];
		$failed   = [];
		foreach ( $standing as $id => $rekey ) {
			if ( isset( $options['on_character'] ) ) {
				( $options['on_character'] )( $id );
			}
			$outcome = self::revert_character( $rekey, $actor_id, $force );
			if ( $outcome['outcome'] === 'reverted' ) {
				$reverted[ $id ] = $outcome['change_id'];
				if ( $outcome['forced'] ) {
					$forced[] = $id;
				}
			} elseif ( $outcome['outcome'] !== 'missing' ) {
				$failed[ $id ] = $outcome;
			}
		}
		if ( $failed !== [] ) {
			return [ 'status' => 'partial', 'characters_reverted' => count( $reverted ), 'failed' => $failed ];
		}

		// Templates: one still exactly as apply() left it goes back to its pre-image. One that changed
		// since - edited by a person, or rewritten by a plugin update - keeps its layout, and only has its
		// blocks pointed back at the old slugs: left on the declared ones it would read blocks the
		// restored sheets no longer hold, and render empty.
		$templates_restored  = 0;
		$templates_skipped   = [];
		$templates_repointed = [];
		foreach ( (array) ( is_array( $record ) ? ( $record['templates'] ?? [] ) : [] ) as $template_id => $entry ) {
			$template = Template::find( (int) $template_id );
			if ( ! $template || ! is_array( $entry['before'] ?? null ) ) {
				continue;
			}
			if ( self::hash_value( $template->layout ) !== ( $entry['after_hash'] ?? null ) ) {
				$templates_skipped[] = [ 'template_id' => (int) $template_id, 'reason' => 'edited_since_cutover' ];
				$repointed           = self::rewrite_layout( $template->layout, array_flip( self::replacement_map( (string) $template->stack_slug ) ) );
				if ( $repointed !== $template->layout && Template::update( (int) $template_id, [ 'layout' => $repointed ] ) ) {
					$templates_repointed[] = (int) $template_id;
				}
				continue;
			}
			if ( Template::update( (int) $template_id, [ 'layout' => $entry['before'] ] ) ) {
				$templates_restored++;
			}
		}

		$pending_restored = count( self::restore_pending() );

		delete_option( self::OPTION );
		Seeder::seed_creature_stacks();
		Seeder::reconcile_stack_blocks();
		delete_option( self::RECORD_OPTION );

		return [
			'status'              => 'rolled_back',
			'characters_reverted' => count( $reverted ),
			'characters_forced'   => $forced,
			'templates_restored'  => $templates_restored,
			'templates_skipped'   => $templates_skipped,
			'templates_repointed' => $templates_repointed,
			'pending_restored'    => $pending_restored,
		];
	}

	/**
	 * One character back to its pre-apply sheet, all or nothing: under a row lock, confirm it is
	 * still what apply() wrote (unless forced), record the revert, snapshot what is being
	 * replaced, write the snapshot's sheet.
	 *
	 * @return array<string,mixed> `outcome`: `reverted` (with `change_id`, `forced`), `changed_since_cutover`, `missing` or `failed`.
	 */
	private static function revert_character( object $rekey, int $actor_id, bool $force ): array {
		$id        = (int) $rekey->character_id;
		$savepoint = Transaction::begin( 'be_catalog_revert' );
		try {
			Character::lock( $id );
			$character = Character::find( $id );
			if ( ! $character ) {
				Transaction::rollback( $savepoint );
				return [ 'outcome' => 'missing' ];
			}

			$current = self::hash_value( $character->sheet_data );
			$forced  = $current !== ( $rekey->change_data['after_hash'] ?? null );
			if ( $forced && ! $force ) {
				Transaction::rollback( $savepoint );
				return [ 'outcome' => 'changed_since_cutover' ];
			}

			$pre = self::snapshot_sheet( $id, (int) $rekey->id );
			if ( $pre === null ) {
				Transaction::rollback( $savepoint );
				return [ 'outcome' => 'failed', 'reason' => 'no_snapshot' ];
			}

			$change_id = Change::create( [
				'character_id' => $id,
				'change_type'  => 'catalog_rekey_revert',
				'category'     => 'catalog',
				'change_data'  => [
					'reverts'     => (int) $rekey->id,
					'before_hash' => $current,
					'after_hash'  => self::hash_value( $pre ),
					'forced'      => $forced,
				],
				'xp_cost'      => 0,
				'status'       => 'approved',
				'submitted_by' => $actor_id,
				'notes'        => 'Catalog cutover rolled back: rows returned to the shared blocks.',
			] );
			if ( ! $change_id ) {
				throw new \RuntimeException( 'the catalog_rekey_revert change could not be recorded' );
			}
			Change::update_status( $change_id, 'approved', $actor_id, null );
			Snapshot::create( $id, $change_id );
			if ( ! Character::update_sheet_data( $id, $pre ) ) {
				throw new \RuntimeException( 'the restored sheet could not be written' );
			}

			Transaction::commit( $savepoint );
			return [ 'outcome' => 'reverted', 'change_id' => $change_id, 'forced' => $forced ];
		} catch ( \Throwable $e ) {
			Transaction::rollback( $savepoint );
			return [ 'outcome' => 'failed', 'reason' => $e->getMessage() ];
		}
	}

	/**
	 * The `catalog_rekey` changes still standing: per character the latest one no
	 * `catalog_rekey_revert` has undone (a cutover applied, rolled back and applied again leaves
	 * one revert and two rekeys; only the second stands).
	 *
	 * @return array<int,object> character id => change row, `change_data` decoded.
	 */
	private static function standing_rekeys(): array {
		$rows = Manager::get_results(
			'SELECT * FROM ' . Manager::table( 'character_changes' ) . " WHERE change_type IN ( 'catalog_rekey', 'catalog_rekey_revert' ) ORDER BY id ASC"
		);

		$reverted = [];
		$latest   = [];
		foreach ( $rows as $row ) {
			$data = json_decode( (string) $row->change_data, true );
			$data = is_array( $data ) ? $data : [];
			if ( $row->change_type === 'catalog_rekey_revert' ) {
				$reverted[ (int) ( $data['reverts'] ?? 0 ) ] = true;
				continue;
			}
			$row->change_data                   = $data;
			$latest[ (int) $row->character_id ] = $row;
		}
		foreach ( $latest as $character_id => $row ) {
			if ( isset( $reverted[ (int) $row->id ] ) ) {
				unset( $latest[ $character_id ] );
			}
		}
		return $latest;
	}

	/**
	 * The sheet a `catalog_rekey` change snapshotted before it wrote - the pre-image.
	 *
	 * @return array<string,mixed>|null
	 */
	private static function snapshot_sheet( int $character_id, int $change_id ): ?array {
		$raw = Manager::get_var(
			'SELECT snapshot_data FROM ' . Manager::table( 'character_snapshots' ) . ' WHERE character_id = %d AND change_id = %d ORDER BY id DESC LIMIT 1',
			$character_id,
			$change_id
		);
		$sheet = is_string( $raw ) ? json_decode( $raw, true ) : null;
		return is_array( $sheet ) ? $sheet : null;
	}

	/**
	 * Every pending change naming a live replacement slug goes back to the retired one - the ones
	 * `apply()` rewrote, and any queued since on the declared block, which a legacy sheet would not
	 * show. Returns what was moved.
	 *
	 * @return array<int,array{id:int,from:string,to:string}>
	 */
	private static function restore_pending(): array {
		$restored = [];
		foreach ( self::pending_moves( false, null ) as $move ) {
			$change = Change::find( $move['id'] );
			if ( ! $change || ! is_array( $change->change_data ) ) {
				continue;
			}
			$data               = $change->change_data;
			$data['block_slug'] = $move['to'];
			Manager::update( 'character_changes', [ 'change_data' => wp_json_encode( $data ) ], [ 'id' => $move['id'], 'status' => 'pending' ] );
			$restored[] = [ 'id' => $move['id'], 'from' => $move['from'], 'to' => $move['to'] ];
		}
		return $restored;
	}
}
