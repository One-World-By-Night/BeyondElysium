<?php

namespace BeyondElysium\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Every free-text column in the schema must have a decided answer to one question: can a player read it, and if so
 * what strips the `[ST]...[/ST]` markers out of it first?
 */
class FreeTextCoverageTest extends TestCase {

	/**
	 * Machine data - JSON payloads and stored source documents.
	 */
	private const STRUCTURED = '<structured>';

	/**
	 * Never reaches a non-manager: the route requires a management capability, or drops the field.
	 */
	private const MANAGER_ONLY = '<manager-only>';

	/**
	 * Real, human-authored prose that every viewer sees, always.
	 */
	private const PUBLIC_TERM = '<public-term>';

	/**
	 * Every free-text column, and the `St_Visibility` method that filters it.
	 */
	private const KNOWN = [
		'games.description'                         => 'filter_game',
		'schema_blocks.fork_changes'                => self::STRUCTURED,
		'characters.biography'                      => 'filter_character',
		'characters.notes'                          => 'filter_character',
		'characters.rp_notes'                       => self::MANAGER_ONLY,
		'character_changes.notes'                   => 'filter_change',
		'character_changes.review_notes'            => 'filter_change',
		'character_changes.reason'                  => 'filter_change',
		'character_transfers.notes'                 => self::MANAGER_ONLY,
		'character_transfers.payload'               => self::MANAGER_ONLY,
		'character_submissions.parsed'              => self::STRUCTURED,
		'character_submissions.verification_source' => self::STRUCTURED,
		'character_submissions.answer_note'         => 'filter_submission',
		'plots.description'                         => 'filter_plot',
		'plots.cliffhanger'                         => 'filter_plot',
		'plots.resolution_details'                  => 'filter_plot',
		'plots.resolution_impact'                   => 'filter_plot',
		'plots.st_notes'                            => self::MANAGER_ONLY,
		'plot_entries.content'                      => 'filter_entry',
		'connections.notes'                         => 'filter_connection',
		'world_objects.description'                 => 'filter_world_object',
		'world_objects.limitations'                 => 'filter_world_object',
		'game_sessions.notes'                       => 'filter_session',
		// Digest payload only - Notifications::send_daily_digests() reads and emails it.
		'notification_queue.payload'                => self::STRUCTURED,
		'characters.public_description'             => 'filter_npc_profile',
		'npc_castings.brief'                         => 'filter_casting',
		'secrets.content'                            => 'filter_secret',
		// A Storyteller's own annotation about a reveal, never surfaced through /my/secrets.
		'secret_reveals.note'                        => self::MANAGER_ONLY,
		// GET.../world-objects/{id}/events is be_manage_world_objects-only.
		'item_events.note'                           => self::MANAGER_ONLY,
		// A player's own after-game report.
		'after_game_reports.did'                     => 'filter_report',
		'after_game_reports.wants'                   => 'filter_report',
		'after_game_reports.to_staff'                => 'filter_report',
		'factions.description'                       => 'filter_faction',
		'factions.goals'                              => 'filter_faction',
		'positions.notes'                             => self::MANAGER_ONLY,
		// The Portuguese (or any locale's) translated name.
		'translations.translation'                    => self::PUBLIC_TERM,
		'translations.note'                           => self::MANAGER_ONLY,
	];

	public function test_every_free_text_column_in_the_schema_is_accounted_for(): void {
		$this->assertSame(
			[],
			array_values( array_diff( $this->columns_in_schema(), array_keys( self::KNOWN ) ) ),
			"A free-text column exists that this guard does not know about.\n"
			. "Decide whether a player can read it. If they can, filter it through an\n"
			. "St_Visibility method and cover the route in a thread test. Then add it to KNOWN."
		);
	}

	public function test_the_guard_has_not_gone_stale(): void {
		$this->assertSame(
			[],
			array_values( array_diff( array_keys( self::KNOWN ), $this->columns_in_schema() ) ),
			'This guard lists a free-text column the schema no longer has - remove it.'
		);
	}

	public function test_each_filtered_column_is_named_by_the_method_that_claims_it(): void {
		$missing = [];

		foreach ( self::KNOWN as $qualified => $method ) {
			if ( self::STRUCTURED === $method || self::MANAGER_ONLY === $method || self::PUBLIC_TERM === $method ) {
				continue;
			}

			[ , $column ] = explode( '.', $qualified );
			$body         = $this->method_body( $method );

			if ( null === $body ) {
				$missing[] = "{$qualified} -> St_Visibility::{$method}() does not exist";
				continue;
			}
			if ( ! str_contains( $body, "'{$column}'" ) && ! str_contains( $body, "->{$column}" ) ) {
				$missing[] = "{$qualified} -> St_Visibility::{$method}() never touches '{$column}'";
			}
		}

		$this->assertSame(
			[],
			$missing,
			"A column is mapped to a filter that does not actually strip it:\n" . implode( "\n", $missing )
		);
	}

	/**
	 * Every `<table>.<column>` declared as a free-text type in Schema.php's CREATE TABLE statements.
	 *
	 * @return string[]
	 */
	private function columns_in_schema(): array {
		$schema = (string) file_get_contents( BE_PLUGIN_ROOT . '/beyond-elysium/includes/Database/Schema.php' );
		$found  = [];
		$table  = null;

		foreach ( explode( "\n", $schema ) as $line ) {
			if ( preg_match( '/CREATE TABLE \{\$prefix\}([a-z_]+)/', $line, $m ) ) {
				$table = $m[1];
			}
			if ( $table && preg_match( '/^\s*([a-z_]+)\s+(?:long|medium)?text\b/', $line, $m ) ) {
				$found[] = "{$table}.{$m[1]}";
			}
		}

		sort( $found );
		return $found;
	}

	/**
	 * The source of one `St_Visibility` method, or null if it has no such method.
	 */
	private function method_body( string $method ): ?string {
		$source = (string) file_get_contents(
			BE_PLUGIN_ROOT . '/beyond-elysium/includes/Services/St_Visibility.php'
		);

		$pattern = '/\n\tpublic static function ' . preg_quote( $method, '/' ) . '\(.*?\n\t\}/s';
		return preg_match( $pattern, $source, $m ) ? $m[0] : null;
	}
}
