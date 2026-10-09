<?php

namespace BeyondElysium\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Loads and indexes the OWBN Character Bylaws: the shipped file, or a site option override once one has been
 * refreshed from council.owbn.net or uploaded.
 */
class Bylaws {

	/**
	 * Site option holding a refreshed or uploaded ruleset, which wins over the shipped file.
	 */
	const OVERRIDE_OPTION = 'be_bylaws_override';

	/**
	 * Per-request cache of the effective ruleset.
	 *
	 * @var array{generated_at:string,rules:array<int,array<string,mixed>>,attachments:array<int,array<string,mixed>>}|null
	 */
	private static ?array $cache = null;

	/**
	 * Clears the per-request cache. Tests and a refresh/upload call this after writing.
	 */
	public static function reset_cache(): void {
		self::$cache = null;
	}

	/**
	 * The shipped file, read once per request.
	 *
	 * @return array{generated_at:string,rules:array<int,array<string,mixed>>,attachments:array<int,array<string,mixed>>}
	 */
	public static function shipped(): array {
		$path = __DIR__ . '/../../data/bylaws/owbn-character-bylaws.json';
		if ( ! is_file( $path ) ) {
			return [ 'generated_at' => '', 'rules' => [], 'attachments' => [] ];
		}
		$decoded = json_decode( (string) file_get_contents( $path ), true );
		return self::normalize_ruleset( is_array( $decoded ) ? $decoded : [] );
	}

	/**
	 * The ruleset actually in force: the site option once refreshed or uploaded, else the shipped file.
	 *
	 * @return array{generated_at:string,rules:array<int,array<string,mixed>>,attachments:array<int,array<string,mixed>>}
	 */
	public static function effective(): array {
		if ( self::$cache !== null ) {
			return self::$cache;
		}
		$override = get_option( self::OVERRIDE_OPTION, null );
		self::$cache = is_array( $override ) ? self::normalize_ruleset( $override ) : self::shipped();
		return self::$cache;
	}

	/**
	 * Every rule attached to one catalog entry: `$family` is a block slug with its creature prefix dropped (so
	 * `merits` reaches every creature's own merit list), `$name` the entry's own name. `$level`/`$is_elder_plus`
	 * narrow a `tiered_power` attachment to the level or Elder-and-above pick it names; `$count` narrows a
	 * trait_list attachment to the `[from, to]` range its own `count_range` names (the new count being bought,
	 * read the way `Change_Engine::find_approval_range()` reads `approval_by_value`). An attachment naming none of
	 * these always matches (a plain trait_list attachment, or a power attached as a whole).
	 *
	 * @return array<int,array<string,mixed>> Rule rows, one per matching attachment's clause.
	 */
	public static function rules_for( string $family, string $name, ?int $level = null, bool $is_elder_plus = false, ?int $count = null ): array {
		$ruleset    = self::effective();
		$rules_by_id = [];
		foreach ( $ruleset['rules'] as $rule ) {
			$rules_by_id[ (int) $rule['clause_id'] ] = $rule;
		}

		$matched = [];
		foreach ( $ruleset['attachments'] as $attachment ) {
			if ( (string) ( $attachment['family'] ?? '' ) !== $family ) {
				continue;
			}
			if ( self::normalize_name( (string) ( $attachment['name'] ?? '' ) ) !== self::normalize_name( $name ) ) {
				continue;
			}
			if ( ! self::level_matches( $attachment, $level, $is_elder_plus ) ) {
				continue;
			}
			if ( ! self::count_matches( $attachment, $count ) ) {
				continue;
			}
			$clause_id = (int) ( $attachment['clause_id'] ?? 0 );
			if ( isset( $rules_by_id[ $clause_id ] ) ) {
				$matched[] = $rules_by_id[ $clause_id ];
			}
		}
		return $matched;
	}

	/**
	 * Whether an attachment's own `levels`/`picks` restriction (if any) covers this purchase.
	 *
	 * @param array<string,mixed> $attachment
	 */
	private static function level_matches( array $attachment, ?int $level, bool $is_elder_plus ): bool {
		$levels = $attachment['levels'] ?? null;
		$picks  = $attachment['picks'] ?? null;

		if ( $levels === null && $picks === null ) {
			return true;
		}
		if ( $is_elder_plus ) {
			return is_array( $picks ) && in_array( 'all', $picks, true );
		}
		return $level !== null && is_array( $levels ) && in_array( $level, $levels, true );
	}

	/**
	 * Whether an attachment's own `count_range` restriction (if any) covers the new count being bought - the same
	 * `[from, to]` shape `Change_Engine::find_approval_range()` reads on `approval_by_value`, so a "6+" bound
	 * reads as `{from: 6, to: PHP_INT_MAX}`.
	 *
	 * @param array<string,mixed> $attachment
	 */
	private static function count_matches( array $attachment, ?int $count ): bool {
		$range = $attachment['count_range'] ?? null;
		if ( ! is_array( $range ) ) {
			return true;
		}
		if ( $count === null || ! isset( $range['from'], $range['to'] ) ) {
			return false;
		}
		return $count >= (int) $range['from'] && $count <= (int) $range['to'];
	}

	/**
	 * A block slug's bylaw family: its creature-stack prefix dropped (`vampire-merits` reads as `merits`, reaching
	 * every creature's own copy of the list), or the shared `met-` prefix dropped, or the slug itself when neither
	 * prefix matches.
	 */
	public static function family_of_block( string $block_slug ): string {
		foreach ( \BeyondElysium\Models\Creature_Stack::all() as $stack ) {
			$prefix = (string) ( $stack->slug ?? '' );
			if ( $prefix !== '' && str_starts_with( $block_slug, $prefix . '-' ) ) {
				return substr( $block_slug, strlen( $prefix ) + 1 );
			}
		}
		if ( str_starts_with( $block_slug, 'met-' ) ) {
			return substr( $block_slug, 4 );
		}
		return $block_slug;
	}

	/**
	 * Case/whitespace-folded, for comparing an attachment's own entry name against the trait actually held.
	 */
	private static function normalize_name( string $name ): string {
		return strtolower( trim( preg_replace( '/\s+/', ' ', $name ) ?? $name ) );
	}

	/**
	 * A decoded ruleset filled out to its full shape - a missing key reads as empty, never a notice.
	 *
	 * @param array<string,mixed> $decoded
	 * @return array{generated_at:string,rules:array<int,array<string,mixed>>,attachments:array<int,array<string,mixed>>}
	 */
	private static function normalize_ruleset( array $decoded ): array {
		return [
			'generated_at' => (string) ( $decoded['generated_at'] ?? '' ),
			'rules'        => is_array( $decoded['rules'] ?? null ) ? $decoded['rules'] : [],
			'attachments'  => is_array( $decoded['attachments'] ?? null ) ? $decoded['attachments'] : [],
		];
	}

	/**
	 * Re-pulls every Character Bylaw rule from council.owbn.net and writes it as the override, keeping every
	 * existing attachment whose clause still exists; one gone from council is dropped along with it. A new clause
	 * lists unattached. Returns the written ruleset plus `added`/`removed`/`changed` clause-id counts, or a
	 * `WP_Error` on a network or parse failure, writing nothing.
	 *
	 * @return array{ruleset:array<string,mixed>,added:int,removed:int,changed:int}|\WP_Error
	 */
	public static function refresh_from_council() {
		$previous    = self::effective();
		$previous_by = [];
		foreach ( $previous['rules'] as $rule ) {
			$previous_by[ (int) $rule['clause_id'] ] = $rule;
		}

		$clauses = self::fetch_all_clauses();
		if ( is_wp_error( $clauses ) ) {
			return $clauses;
		}

		$rules = Bylaw_Source::build_rules( $clauses );
		if ( $rules === [] ) {
			return new \WP_Error( 'bylaws_empty_source', __( "council.owbn.net returned no Character Bylaw rules - nothing was changed.", 'beyond-elysium' ) );
		}

		$new_ids = array_column( $rules, 'clause_id' );
		$kept_attachments = array_values( array_filter(
			$previous['attachments'],
			static fn( $a ) => in_array( (int) ( $a['clause_id'] ?? 0 ), $new_ids, true )
		) );

		$added   = count( array_diff( $new_ids, array_keys( $previous_by ) ) );
		$removed = count( array_diff( array_keys( $previous_by ), $new_ids ) );
		$changed = 0;
		foreach ( $rules as $rule ) {
			$old = $previous_by[ (int) $rule['clause_id'] ] ?? null;
			if ( $old && ( $old['subject'] ?? null ) !== $rule['subject'] ) {
				++$changed;
			}
		}

		$ruleset = [
			'generated_at' => gmdate( 'c' ),
			'rules'        => $rules,
			'attachments'  => $kept_attachments,
		];

		update_option( self::OVERRIDE_OPTION, $ruleset, false );
		self::reset_cache();

		return [ 'ruleset' => $ruleset, 'added' => $added, 'removed' => $removed, 'changed' => $changed ];
	}

	/**
	 * Writes an uploaded, already-built ruleset as the override - the same shape `build.php` writes. Refuses a
	 * malformed file, naming what was wrong, and writes nothing.
	 *
	 * @param mixed $decoded
	 * @return true|\WP_Error
	 */
	public static function upload( $decoded ) {
		if ( ! is_array( $decoded ) || ! isset( $decoded['rules'] ) || ! is_array( $decoded['rules'] ) ) {
			return new \WP_Error( 'bylaws_malformed', __( "This file has no \"rules\" array - it doesn't look like a bylaws file.", 'beyond-elysium' ) );
		}
		if ( ! isset( $decoded['attachments'] ) || ! is_array( $decoded['attachments'] ) ) {
			return new \WP_Error( 'bylaws_malformed', __( 'This file has no "attachments" array.', 'beyond-elysium' ) );
		}
		foreach ( $decoded['rules'] as $i => $rule ) {
			if ( ! is_array( $rule ) || ! isset( $rule['clause_id'], $rule['path'], $rule['subject'] ) ) {
				return new \WP_Error( 'bylaws_malformed', sprintf(
					/* translators: %d: the index of the first malformed rule in the uploaded file */
					__( 'Rule %d in this file is missing a clause id, path, or subject.', 'beyond-elysium' ),
					$i
				) );
			}
		}

		update_option( self::OVERRIDE_OPTION, self::normalize_ruleset( $decoded ), false );
		self::reset_cache();
		return true;
	}

	/**
	 * Every clause council's REST API holds, paginated, through `wp_remote_get()`.
	 *
	 * @return array<int,array<string,mixed>>|\WP_Error
	 */
	private static function fetch_all_clauses() {
		$all  = [];
		$page = 1;
		do {
			$url      = 'https://council.owbn.net/wp-json/wp/v2/bylaw_clause?per_page=100&page=' . $page
				. '&_fields=id,slug,link,content,modified';
			$response = wp_remote_get( $url, [ 'timeout' => 30 ] );
			if ( is_wp_error( $response ) ) {
				return $response;
			}
			if ( (int) wp_remote_retrieve_response_code( $response ) !== 200 ) {
				return new \WP_Error( 'bylaws_fetch_failed', sprintf(
					/* translators: %d: the HTTP status council.owbn.net returned */
					__( 'council.owbn.net returned HTTP %d.', 'beyond-elysium' ),
					wp_remote_retrieve_response_code( $response )
				) );
			}
			$batch = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( ! is_array( $batch ) ) {
				return new \WP_Error( 'bylaws_fetch_failed', __( "council.owbn.net's response did not decode as JSON.", 'beyond-elysium' ) );
			}
			$all = array_merge( $all, $batch );
			++$page;
		} while ( count( $batch ) === 100 );

		return $all;
	}
}
