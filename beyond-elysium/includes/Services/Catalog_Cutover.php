<?php

namespace BeyondElysium\Services;

use BeyondElysium\Database\Manager;
use BeyondElysium\Database\Option_Lock;
use BeyondElysium\Database\Schema;
use BeyondElysium\Database\Seeder;
use BeyondElysium\Database\Transaction;
use BeyondElysium\Models\Change;
use BeyondElysium\Models\Character;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Schema_Block;
use BeyondElysium\Models\Snapshot;
use BeyondElysium\Models\Template;

defined( 'ABSPATH' ) || exit;

/**
 * The move from the shared lists (`met-abilities`, `met-merits`, `met-flaws`) onto each creature type's own lists.
 */
class Catalog_Cutover {

	/**
	 * Option holding `declared` once an install is on the per-creature catalog.
	 */
	const OPTION = 'be_catalog_cutover';

	/**
	 * Option_Lock name guarding `apply()` against concurrent runs.
	 */
	const LOCK = 'be_catalog_cutover_lock';

	/**
	 * Seconds a held lock counts as live.
	 */
	private const LOCK_TTL = 1800;

	/**
	 * Seconds after a claim that saves are still refused.
	 */
	public const WRITE_HOLD = 120;

	/**
	 * Whether this install is on the per-creature catalog.
	 */
	public static function is_declared(): bool {
		return get_option( self::OPTION ) === 'declared';
	}

	/**
	 * Marks an install with no characters as on the per-creature catalog.
	 */
	public static function declare_fresh_install(): bool {
		if ( self::is_declared() || ! static::catalog_available() || self::character_count() > 0 ) {
			return false;
		}
		update_option( self::OPTION, 'declared', true );
		return true;
	}

	/**
	 * Puts an install that has not switched onto the declared catalog and says what happened.
	 *
	 * @param int $actor_id User recorded on each character's history entry; 0 records the move as the upgrade's own.
	 * @return array<string,mixed>
	 */
	public static function ensure_declared( int $actor_id = 0 ): array {
		if ( self::is_declared() ) {
			return [ 'status' => 'already_declared' ];
		}
		if ( self::declare_fresh_install() ) {
			// Rewrites the default templates onto the per-creature blocks.
			self::rewrite_templates();
			return [ 'status' => 'marked' ];
		}

		// Lifts the time limit for the move.
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}

		return self::apply( $actor_id );
	}

	/**
	 * The sentence that says why `apply()` or `ensure_declared()` stopped and what to do about it.
	 *
	 * @param array<string,mixed> $result A result whose status is not `applied`, `already_declared` or `marked`.
	 */
	public static function refusal_message( array $result ): string {
		if ( ( $result['status'] ?? '' ) === 'locked' ) {
			return 'A move to the per-creature lists is already running. The upgrade tries again after it.';
		}
		if ( ( $result['status'] ?? '' ) === 'partial' ) {
			$failed = [];
			foreach ( (array) ( $result['failed'] ?? [] ) as $id => $failure ) {
				$failed[] = "character {$id}: " . (string) ( $failure['reason'] ?? 'not moved' );
			}
			return sprintf(
				'%d character(s) could not be moved (%s). The others are moved and recorded in their history; the move continues from there next time.',
				count( $failed ),
				implode( '; ', array_slice( $failed, 0, 5 ) )
			);
		}

		switch ( $result['reason'] ?? '' ) {
			case 'no_declared_catalog':
				return 'The catalog files are missing from this plugin, so characters cannot be moved to their creature type\'s lists. Reinstall the plugin.';
			case 'declared_blocks_missing':
				return sprintf( 'The database lacks blocks the creature stacks name (%s). The next attempt seeds them again.', implode( '; ', array_slice( (array) ( $result['missing'] ?? [] ), 0, 5 ) ) );
			case 'retention_gaps':
				$gaps  = (array) ( $result['retention_gaps'] ?? [] );
				$named = array_map(
					static fn( $gap ): string => sprintf( '%1$s (%2$s): %3$s is not in %4$s', $gap['character'] ?? '?', $gap['game'] ?? '?', $gap['name'] ?? '?', $gap['block_to'] ?? '?' ),
					array_slice( $gaps, 0, 5 )
				);
				return sprintf(
					'%1$d held entries would be lost by the move to per-creature lists: %2$s%3$s. Add each entry to that block in Schema Blocks, or remove it from the character. The upgrade tries again by itself.',
					count( $gaps ),
					implode( '; ', $named ),
					count( $gaps ) > count( $named ) ? '; and ' . ( count( $gaps ) - count( $named ) ) . ' more' : ''
				);
			case 'characters_outside_any_chronicle':
				return sprintf( '%1$d characters exist but only %2$d belong to a chronicle, so the rest cannot be moved. Assign each to a chronicle or delete it. The upgrade tries again by itself.', $result['on_install'] ?? 0, $result['planned'] ?? 0 );
			default:
				return 'The move to per-creature lists was refused.';
		}
	}

	private static function character_count(): int {
		global $wpdb;
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Manager::table( 'characters' ) );
	}

	/**
	 * Whether a move is running right now.
	 */
	public static function switching(): bool {
		$held = Option_Lock::held_since( self::LOCK );
		return $held > 0 && ( time() - $held ) < self::WRITE_HOLD;
	}

	/**
	 * The shared block each of this stack's blocks replaced, as `shared block => the stack's block`.
	 *
	 * @return array<string,string>
	 */
	private static function replacement_map( string $stack_slug ): array {
		return Catalog_Reader::replacement_maps()[ $stack_slug ] ?? [];
	}

	/**
	 * Rewrites every template's shared block slugs, and every `title_refs[].block_slug`, onto the stack's own blocks in
	 * place.
	 *
	 * @return array<int,array{id:int,stack_slug:string,before:array,after:array}> One entry per template changed.
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
	 * Maps one layout's `sections[].block_slug` and `sections[].title_refs[].block_slug` through `$map`, leaving every
	 * other field untouched.
	 *
	 * @param array<string,string> $map Shared slug => the stack's slug.
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
	 * What `apply()` would do, read-only: how many characters there are, how many a move would change, and every held
	 * catalog row the per-creature block does not carry.
	 *
	 * @param string|null $game_slug One chronicle, or every one on the install.
	 * @return array{available:bool,characters:int,characters_changed:int,retention_gaps:array<int,array<string,mixed>>}
	 */
	public static function plan( ?string $game_slug = null ): array {
		$report = [
			'available'          => static::catalog_available(),
			'characters'         => 0,
			'characters_changed' => 0,
			'retention_gaps'     => [],
		];
		if ( ! $report['available'] ) {
			return $report;
		}

		$games = $game_slug !== null
			? [ $game_slug ]
			: array_map( static fn( $game ) => (string) $game->slug, Game::all() );
		$cache = [];

		foreach ( $games as $slug ) {
			foreach ( Character::all_for_game( $slug ) as $character ) {
				$result = self::plan_character( $character, $cache, false );
				$report['characters']++;
				if ( $result['changed'] ) {
					$report['characters_changed']++;
				}
				foreach ( $result['retention_gaps'] as $gap ) {
					$report['retention_gaps'][] = [ 'character_id' => (int) $character->id, 'character' => (string) $character->name, 'game' => $slug ] + $gap;
				}
			}
		}

		return $report;
	}

	/**
	 * Whether a declared catalog ships with this install.
	 */
	protected static function catalog_available(): bool {
		return Catalog_Reader::available();
	}

	/**
	 * One character's plan from the character as it is now: what moves, what is re-keyed, and what would be lost.
	 *
	 * @param object              $character A row with a decoded (array) `sheet_data`.
	 * @param array<string,mixed> $cache     Block rows per chronicle, shared across one run by reference.
	 * @return array<string,mixed> `Custom_Rekey::plan_character()`'s result.
	 */
	private static function plan_character( object $character, array &$cache, bool $suggest = true ): array {
		$owner = (string) ( $character->owner_slug ?? '' );
		$map   = self::replacement_map( (string) ( $character->stack_slug ?? '' ) );
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

		return Custom_Rekey::plan_character( $sheet, $blocks, $map, [ 'suggestions' => $suggest ] );
	}

	/**
	 * The pending changes that name a shared block for their character's stack, each with the block it moves to.
	 *
	 * @return array<int,array{id:int,character_id:int,from:string,to:string}>
	 */
	private static function pending_moves(): array {
		$rows = Manager::get_results(
			'SELECT ch.id, ch.character_id, ch.change_data, c.stack_slug FROM ' . Manager::table( 'character_changes' ) . ' ch'
			. ' JOIN ' . Manager::table( 'characters' ) . " c ON c.id = ch.character_id WHERE ch.status = 'pending'"
		);

		$moves = [];
		foreach ( $rows as $row ) {
			$data = json_decode( (string) $row->change_data, true );
			$from = is_array( $data ) && is_string( $data['block_slug'] ?? null ) ? $data['block_slug'] : null;
			$to   = $from !== null ? ( self::replacement_map( (string) $row->stack_slug )[ $from ] ?? null ) : null;
			if ( $from !== null && $to !== null ) {
				$moves[] = [ 'id' => (int) $row->id, 'character_id' => (int) $row->character_id, 'from' => $from, 'to' => $to ];
			}
		}
		return $moves;
	}

	/**
	 * Moves every character onto its creature type's lists, rewrites pending changes and templates, and reseeds the
	 * stacks from the declared catalog, all under one lock.
	 *
	 * @param int                           $actor_id User recorded as submitter of each `catalog_rekey` change.
	 * @param array{on_character?:callable} $options  `on_character( int $character_id )` runs after the install-wide plan
	 *                                                and before that character's own transaction re-plans it.
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
	 * The repairs that are safe to repeat on an install already moved: templates and pending changes still naming a
	 * shared block.
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
		$missing = self::declared_blocks_missing();
		if ( $missing !== [] ) {
			return [ 'status' => 'refused', 'reason' => 'declared_blocks_missing', 'missing' => $missing ];
		}

		$plan = self::plan();
		if ( $plan['retention_gaps'] !== [] ) {
			return [ 'status' => 'refused', 'reason' => 'retention_gaps', 'retention_gaps' => $plan['retention_gaps'] ];
		}
		// A plan walks chronicle by chronicle.
		$on_install = (int) Manager::get_var( 'SELECT COUNT(*) FROM ' . Manager::table( 'characters' ) );
		if ( $plan['characters'] !== $on_install ) {
			return [ 'status' => 'refused', 'reason' => 'characters_outside_any_chronicle', 'planned' => $plan['characters'], 'on_install' => $on_install ];
		}

		// Each character moves in its own transaction, re-planned from a fresh read under a row lock.
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
					$vanished++;
				} else {
					$failed[ $id ] = $outcome;
				}
			}
		}
		if ( $failed !== [] ) {
			return [ 'status' => 'partial', 'characters_rekeyed' => count( $rekeyed ), 'failed' => $failed ];
		}

		$pending = self::rewrite_pending();

		update_option( self::OPTION, 'declared', true );
		self::rewrite_templates();
		Seeder::seed_creature_stacks();
		Seeder::reconcile_stack_blocks();
		Schema::complete_full_sheet_templates();

		return [
			'status'               => 'applied',
			'characters_rekeyed'   => count( $rekeyed ),
			'characters_unchanged' => $unchanged,
			'characters_vanished'  => $vanished,
			'rows_rekeyed'         => array_sum( array_column( $rekeyed, 'rekeyed' ) ),
			'rows_respelled'       => array_sum( array_column( $rekeyed, 'respelled' ) ),
			'rows_moved'           => array_sum( array_column( $rekeyed, 'moved' ) ),
			'pending_rewritten'    => count( $pending ),
		];
	}

	/**
	 * One character, all or nothing: under a row lock, re-read and re-plan it.
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

			$result = self::plan_character( $character, $cache, false );
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
	 * Points every pending change that names a shared block at the stack's own block.
	 *
	 * @return array<int,array{id:int,from:string,to:string}> What was rewritten.
	 */
	private static function rewrite_pending(): array {
		$rewritten = [];
		foreach ( self::pending_moves() as $pending ) {
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
	 * The blocks the declared stacks name that the database does not have.
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
	 * A stable fingerprint of a sheet or layout: sha256 of its JSON with object keys sorted and list order kept.
	 *
	 * @param mixed $value
	 */
	public static function hash_value( $value ): string {
		return hash( 'sha256', (string) wp_json_encode( self::canonical( $value ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
	}

	/**
	 * `$value` with the keys of every object sorted.
	 *
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
}
