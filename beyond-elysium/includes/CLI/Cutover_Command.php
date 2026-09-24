<?php

namespace BeyondElysium\CLI;

use BeyondElysium\Services\Catalog_Cutover;

defined( 'ABSPATH' ) || exit;

/**
 * Runs the catalog cutover: plan it, apply it, roll it back, ask where it stands.
 *
 * Everything of substance is `Services\Catalog_Cutover`. This class reads the arguments, refuses to
 * write without a confirmation and a named user, and prints what came back; the report builders are
 * plain functions so the wording of a report can be tested without a shell.
 */
class Cutover_Command {

	/** Retention gaps printed in full before the rest are summarized; each one refuses `apply`, so the first few say why. */
	private const GAPS_SHOWN = 25;

	/**
	 * Reads what the cutover would do and changes nothing.
	 *
	 * Every character is judged against the catalog its own chronicle sees. Retention gaps - a catalog
	 * row the declared block does not carry - are listed in full, because `apply` refuses the whole
	 * install while any exist.
	 *
	 * ## OPTIONS
	 *
	 * [--game=<slug>]
	 * : One chronicle instead of every chronicle on the install.
	 *
	 * [--format=<format>]
	 * : `table` (default) or `json`.
	 *
	 * [--csv=<path>]
	 * : Also write one line per custom entry - what it was, what it would become, why - to this file. It holds character and player names: keep it on the server or with the owner, never in a repository.
	 *
	 * ## EXAMPLES
	 *
	 *     wp be cutover plan
	 *     wp be cutover plan --game=kony --csv=/tmp/kony-plan.csv
	 *     wp be cutover plan --format=json
	 *
	 * @param string[]             $args       Unused.
	 * @param array<string,string> $assoc_args Flags.
	 */
	public function plan( $args, $assoc_args ): void {
		$game   = isset( $assoc_args['game'] ) ? (string) $assoc_args['game'] : null;
		$format = (string) ( $assoc_args['format'] ?? 'table' );
		$csv    = isset( $assoc_args['csv'] ) ? (string) $assoc_args['csv'] : null;
		if ( ! in_array( $format, [ 'table', 'json' ], true ) ) {
			\WP_CLI::error( 'Format must be table or json.' );
		}

		$plan = Catalog_Cutover::plan( $game, [ 'suggestions' => $csv !== null, 'rows' => $csv !== null ] );
		if ( ! $plan['available'] ) {
			\WP_CLI::error( 'This build carries no declared catalog to cut over to.' );
		}

		if ( $csv !== null ) {
			$written = self::write_csv( $csv, (array) $plan['rows'] );
			\WP_CLI::log( sprintf( 'Wrote %1$d lines to %2$s. It holds character and player names: keep it off any repository.', $written, $csv ) );
		}
		unset( $plan['rows'] );

		if ( $format === 'json' ) {
			\WP_CLI::print_value( $plan, [ 'format' => 'json' ] );
			return;
		}

		if ( $plan['declared'] ) {
			\WP_CLI::warning( 'This install is already on the declared catalog; the numbers below are what is left to re-key.' );
		}
		\WP_CLI\Utils\format_items( 'table', self::summary_rows( $plan ), [ 'what', 'count' ] );
		if ( count( $plan['by_game'] ) > 1 ) {
			\WP_CLI::log( '' );
			\WP_CLI\Utils\format_items( 'table', self::chronicle_rows( $plan ), [ 'chronicle', 'characters', 'changed', 'matched', 'kept_custom', 'untouched' ] );
		}

		$gaps = (array) $plan['retention_gaps'];
		if ( $gaps !== [] ) {
			\WP_CLI::log( '' );
			\WP_CLI::warning( sprintf( '%d catalog rows would be lost. `apply` will refuse until they are dealt with:', count( $gaps ) ) );
			foreach ( array_slice( $gaps, 0, self::GAPS_SHOWN ) as $gap ) {
				\WP_CLI::log( sprintf( '  #%1$d %2$s (%3$s): %4$s -> %5$s, "%6$s" (%7$s)', $gap['character_id'], $gap['character'], $gap['game'], $gap['block_from'], $gap['block_to'], $gap['name'] ?? '', $gap['reason'] ?? '' ) );
			}
			if ( count( $gaps ) > self::GAPS_SHOWN ) {
				\WP_CLI::log( sprintf( '  ...and %d more (use --format=json for all of them).', count( $gaps ) - self::GAPS_SHOWN ) );
			}
		}
	}

	/**
	 * Re-keys every character and switches the install to the declared catalog.
	 *
	 * Refuses, changing nothing, if any catalog row would be lost or the declared blocks are not in the
	 * database. Otherwise each character is re-keyed in its own transaction and only when all of them
	 * succeed does the install switch; a partial run leaves it on the old catalog and can be run again.
	 * Take a snapshot first. `wp be cutover rollback` undoes it.
	 *
	 * ## OPTIONS
	 *
	 * [--yes]
	 * : Do not ask for confirmation.
	 *
	 * ## EXAMPLES
	 *
	 *     wp be cutover apply --user=owner --yes
	 *
	 * @param string[]             $args       Unused.
	 * @param array<string,string> $assoc_args Flags.
	 */
	public function apply( $args, $assoc_args ): void {
		$actor = self::actor();
		\WP_CLI::confirm( 'Re-key every character and switch this install to the declared catalog?', $assoc_args );

		$result = Catalog_Cutover::apply( $actor );
		// WP_CLI::error() exits, so a case that ends in it needs no return or break.
		switch ( $result['status'] ) {
			case 'applied':
				\WP_CLI::success( sprintf(
					'Switched to the declared catalog. %1$d characters re-keyed (%2$d rows moved, %3$d custom entries matched, %7$d names corrected to the catalog spelling), %4$d already fine, %5$d pending changes rewritten, %6$d templates updated.',
					$result['characters_rekeyed'],
					$result['rows_moved'],
					$result['rows_rekeyed'],
					$result['characters_unchanged'],
					$result['pending_rewritten'],
					$result['templates_changed'],
					$result['rows_respelled'] ?? 0
				) );
				if ( ! empty( $result['characters_vanished'] ) ) {
					\WP_CLI::log( sprintf( '%d characters were deleted while it ran and were skipped.', $result['characters_vanished'] ) );
				}
				return;
			case 'already_declared':
				\WP_CLI::success( sprintf( 'Already on the declared catalog. Repaired %1$d templates and %2$d pending changes.', $result['templates_rewritten'], $result['pending_rewritten'] ) );
				return;
			case 'locked':
				\WP_CLI::error( 'Another cutover run holds the lock. If none is running it clears itself after 30 minutes.' );
			case 'partial':
				\WP_CLI::error( sprintf(
					'%1$d characters could not be re-keyed (ids: %2$s). The install was NOT switched and nothing else was touched; fix them and run apply again.',
					count( $result['failed'] ),
					implode( ', ', array_keys( $result['failed'] ) )
				) );
			default:
				\WP_CLI::error( self::refusal( $result ) );
		}
	}

	/**
	 * Undoes `apply`: restores every re-keyed character from its snapshot and returns the install to the old catalog.
	 *
	 * A character whose sheet has changed since the cutover is never restored silently - that would
	 * erase what was approved after it. The whole rollback stops and names them; `--force` restores
	 * them anyway and records that it did.
	 *
	 * ## OPTIONS
	 *
	 * [--force]
	 * : Restore characters that changed since the cutover, losing those changes.
	 *
	 * [--yes]
	 * : Do not ask for confirmation.
	 *
	 * ## EXAMPLES
	 *
	 *     wp be cutover rollback --user=owner --yes
	 *
	 * @param string[]             $args       Unused.
	 * @param array<string,string> $assoc_args Flags.
	 */
	public function rollback( $args, $assoc_args ): void {
		$actor = self::actor();
		$force = ! empty( $assoc_args['force'] );
		\WP_CLI::confirm( $force ? 'Roll back the cutover, restoring changed characters too and losing their later changes?' : 'Roll back the cutover?', $assoc_args );

		$result = Catalog_Cutover::rollback( $actor, $force );
		// WP_CLI::error() exits, so a case that ends in it needs no return or break.
		switch ( $result['status'] ) {
			case 'rolled_back':
				\WP_CLI::success( sprintf(
					'Back on the old catalog. %1$d characters restored, %2$d templates restored, %3$d pending changes moved back.',
					$result['characters_reverted'],
					$result['templates_restored'],
					$result['pending_restored']
				) );
				foreach ( (array) $result['templates_skipped'] as $skipped ) {
					$repointed = in_array( $skipped['template_id'], (array) ( $result['templates_repointed'] ?? [] ), true );
					\WP_CLI::warning( sprintf(
						$repointed
							? 'Template #%d changed since the cutover. Its changes were kept and its sections were pointed back at the old blocks.'
							: 'Template #%d changed since the cutover and was left as it is.',
						$skipped['template_id']
					) );
				}
				if ( ! empty( $result['characters_forced'] ) ) {
					\WP_CLI::warning( sprintf( 'Restored despite later changes (forced): ids %s.', implode( ', ', $result['characters_forced'] ) ) );
				}
				return;
			case 'nothing_to_roll_back':
				\WP_CLI::success( 'Nothing to roll back: this install is not on the declared catalog and holds no cutover records.' );
				return;
			case 'started_declared':
				\WP_CLI::success( 'Nothing to roll back: this install started on the declared catalog, so there is no earlier state to return to.' );
				return;
			case 'locked':
				\WP_CLI::error( 'Another cutover run holds the lock. If none is running it clears itself after 30 minutes.' );
			case 'blocked':
				\WP_CLI::error( sprintf(
					'Nothing was changed. %1$d characters have changed since the cutover (ids: %2$s); restoring them would erase those changes. Leave them, or run again with --force.',
					count( $result['skipped'] ),
					implode( ', ', array_column( $result['skipped'], 'character_id' ) )
				) );
			default:
				\WP_CLI::error( sprintf(
					'%1$d characters could not be restored (ids: %2$s). The install stays on the declared catalog; run rollback again.',
					count( (array) ( $result['failed'] ?? [] ) ),
					implode( ', ', array_keys( (array) ( $result['failed'] ?? [] ) ) )
				) );
		}
	}

	/**
	 * Says where this install stands.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : `table` (default) or `json`.
	 *
	 * ## EXAMPLES
	 *
	 *     wp be cutover status
	 *
	 * @param string[]             $args       Unused.
	 * @param array<string,string> $assoc_args Flags.
	 */
	public function status( $args, $assoc_args ): void {
		$status = Catalog_Cutover::status();
		if ( ( $assoc_args['format'] ?? 'table' ) === 'json' ) {
			\WP_CLI::print_value( $status, [ 'format' => 'json' ] );
			return;
		}
		$rows = [];
		foreach ( $status as $key => $value ) {
			$rows[] = [ 'what' => $key, 'value' => is_bool( $value ) ? ( $value ? 'yes' : 'no' ) : (string) ( $value ?? '-' ) ];
		}
		\WP_CLI\Utils\format_items( 'table', $rows, [ 'what', 'value' ] );
	}

	/** The user the change records are attributed to: `--user=<id or login>`, which WP-CLI has already made current. */
	private static function actor(): int {
		$actor = get_current_user_id();
		if ( $actor === 0 ) {
			\WP_CLI::error( 'Say who is running this: pass --user=<id or login>. Every re-keyed character gets a history entry in that name.' );
		}
		return $actor;
	}

	/**
	 * @param array<string,mixed> $result An `apply()` refusal.
	 */
	public static function refusal( array $result ): string {
		switch ( $result['reason'] ?? '' ) {
			case 'no_declared_catalog':
				return 'This build carries no declared catalog to cut over to. Nothing was changed.';
			case 'declared_blocks_missing':
				return sprintf( 'The database is missing blocks the declared stacks name (%s). Deploy the update and let it finish upgrading first. Nothing was changed.', implode( '; ', (array) ( $result['missing'] ?? [] ) ) );
			case 'retention_gaps':
				return sprintf( '%d catalog rows would be lost by the switch. Nothing was changed. Run `wp be cutover plan` for the list.', count( (array) ( $result['retention_gaps'] ?? [] ) ) );
			case 'characters_outside_any_chronicle':
				return sprintf( '%1$d characters exist but only %2$d belong to a chronicle. The rest would be left behind. Nothing was changed.', $result['on_install'] ?? 0, $result['planned'] ?? 0 );
			default:
				return 'The cutover was refused. Nothing was changed.';
		}
	}

	/**
	 * @param array<string,mixed> $plan A `Catalog_Cutover::plan()` report.
	 * @return array<int,array{what:string,count:string}>
	 */
	public static function summary_rows( array $plan ): array {
		$totals = (array) $plan['totals'];
		$rows   = [
			[ 'what' => 'Characters', 'count' => (string) $plan['characters'] ],
			[ 'what' => 'Characters that would change', 'count' => (string) $plan['characters_changed'] ],
			[ 'what' => 'Rows moved to their new blocks', 'count' => (string) $totals['moved_rows'] ],
			[ 'what' => 'Custom entries matched to the catalog', 'count' => (string) $totals['rekeyed'] ],
			[ 'what' => 'Catalog rows renamed to the declared spelling', 'count' => (string) ( $totals['respelled'] ?? 0 ) ],
			[ 'what' => 'Custom entries left custom', 'count' => (string) $totals['kept_custom'] ],
		];
		foreach ( (array) $totals['by_reason'] as $reason => $n ) {
			$rows[] = [ 'what' => '  ' . $reason, 'count' => (string) $n ];
		}
		$rows[] = [ 'what' => 'Custom powers (left as they are)', 'count' => (string) $totals['tiered_custom'] ];
		$rows[] = [ 'what' => 'Retention gaps (apply refuses while any exist)', 'count' => (string) count( (array) $plan['retention_gaps'] ) ];
		$rows[] = [ 'what' => 'Identities already doubled before anything moves', 'count' => (string) count( (array) $plan['duplicates'] ) ];
		$rows[] = [ 'what' => 'Rows held under a retired block with no new home', 'count' => (string) count( (array) $plan['unmapped_retired_data'] ) ];
		$rows[] = [ 'what' => 'Pending changes that would be rewritten', 'count' => (string) count( (array) $plan['pending_changes'] ) ];

		$untouched = (array) ( $plan['untouched'] ?? [] );
		$rows[]    = [
			'what'  => 'Exactly as imported, nothing attached (could be re-imported instead)',
			'count' => sprintf( '%1$d (%2$d with a player assigned)', $untouched['characters'] ?? 0, $untouched['with_player'] ?? 0 ),
		];
		return $rows;
	}

	/**
	 * @param array<string,mixed> $plan A `Catalog_Cutover::plan()` report.
	 * @return array<int,array<string,int|string>>
	 */
	public static function chronicle_rows( array $plan ): array {
		$rows = [];
		foreach ( (array) $plan['by_game'] as $slug => $game ) {
			$rows[] = [
				'chronicle'   => (string) $slug,
				'characters'  => (int) $game['characters'],
				'changed'     => (int) $game['changed'],
				'matched'     => (int) $game['rekeyed'],
				'kept_custom' => (int) $game['kept_custom'],
				'untouched'   => (int) ( $game['untouched'] ?? 0 ),
			];
		}
		return $rows;
	}

	/**
	 * One CSV line per custom entry's outcome, under a header, and one per catalog row renamed to the declared
	 * spelling (`entry_kind` says which).
	 *
	 * @param array<int,array<string,mixed>> $rows `Catalog_Cutover::plan()['rows']`.
	 * @return array<int,array<int,string>>
	 */
	public static function csv_lines( array $rows ): array {
		$lines = [ [ 'character_id', 'character', 'chronicle', 'from_block', 'to_block', 'entry', 'outcome', 'becomes', 'why', 'label_goes_to', 'label', 'suggestions', 'entry_kind' ] ];
		foreach ( $rows as $row ) {
			$rekeyed = ( $row['outcome'] ?? '' ) === 'rekeyed';
			$lines[] = [
				(string) $row['character_id'],
				(string) $row['character'],
				(string) $row['game'],
				(string) $row['block_from'],
				(string) $row['block_to'],
				(string) $row['from'],
				(string) $row['outcome'],
				(string) ( $rekeyed ? $row['to'] : ( $row['would_be'] ?? '' ) ),
				(string) ( $rekeyed ? $row['tier'] : ( $row['reason'] ?? '' ) ),
				(string) ( $row['home'] ?? '' ),
				(string) ( $row['label'] ?? '' ),
				implode( ' | ', array_map( 'strval', (array) ( $row['suggestions'] ?? [] ) ) ),
				empty( $row['catalog'] ) ? 'custom' : 'catalog',
			];
		}
		return $lines;
	}

	/**
	 * @param array<int,array<string,mixed>> $rows
	 * @return int Lines written, header excluded.
	 */
	private static function write_csv( string $path, array $rows ): int {
		$handle = fopen( $path, 'wb' );
		if ( $handle === false ) {
			\WP_CLI::error( sprintf( 'Cannot write %s.', $path ) );
		}
		$lines = self::csv_lines( $rows );
		foreach ( $lines as $line ) {
			fputcsv( $handle, $line, ',', '"', '\\' );
		}
		fclose( $handle );
		return count( $lines ) - 1;
	}
}
