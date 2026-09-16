<?php

namespace BeyondElysium\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Every free-text column in the schema must have a decided answer to one question: can a
 * player read it, and if so what strips the `[ST]...[/ST]` markers out of it first?
 *
 * `[ST]` handling has now been missed on six controllers and found twice by accident - once
 * on world objects (D44, which then sat in the defects table marked open for three releases
 * after it was quietly fixed), and once on plots, plot entries, chronicles and connections
 * (1.0.1 A1). The point of this guard is the *next* column, not those. Add a `text` or
 * `longtext` column and the build fails here until you have decided which of the three
 * dispositions below it takes.
 *
 * Honest limit, stated so nobody over-trusts it: like `BooleanFlagCoverageTest`, this is
 * static analysis over a known list. It proves a column is accounted for and that the method
 * claiming to filter it actually names it - not that every route calls that method.
 * `StFilterCoverageThreadTest` and `FreeTextLeakThreadTest` cover the real routes.
 *
 * @see BE_PROCESS/releases/1.0.1-design-workflow.md §4, A2
 */
class FreeTextCoverageTest extends TestCase {

	/** Machine data - JSON payloads and stored source documents, never prose anyone writes by hand. */
	private const STRUCTURED = '<structured>';

	/** Never reaches a non-manager: the route requires a management capability, or drops the field. */
	private const MANAGER_ONLY = '<manager-only>';

	/**
	 * Every free-text column, and the `St_Visibility` method that filters it - or the reason
	 * it needs none. Adding a row here is a deliberate act; see the class docblock.
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
			if ( self::STRUCTURED === $method || self::MANAGER_ONLY === $method ) {
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
	 * Every `<table>.<column>` declared as a free-text type in Schema.php's CREATE TABLE
	 * statements. `varchar` is deliberately excluded - it holds names, slugs and enum-ish
	 * values here, not prose a Storyteller writes a `[ST]` block into.
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

	/** The source of one `St_Visibility` method, or null if it has no such method. */
	private function method_body( string $method ): ?string {
		$source = (string) file_get_contents(
			BE_PLUGIN_ROOT . '/beyond-elysium/includes/Services/St_Visibility.php'
		);

		$pattern = '/\n\tpublic static function ' . preg_quote( $method, '/' ) . '\(.*?\n\t\}/s';
		return preg_match( $pattern, $source, $m ) ? $m[0] : null;
	}
}
