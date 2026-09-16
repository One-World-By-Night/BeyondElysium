<?php

namespace BeyondElysium\Tests\Unit;

use BeyondElysium\Services\Field_Registry;
use PHPUnit\Framework\TestCase;

/**
 * Structural integrity of `report-registry.php` - the 19 GV301 reports
 * (reports-cards-batch-design.md §3.1). Every `field`-sourced column must
 * resolve against `Field_Registry` for its report's own entity; every shape
 * must be one of the five `Report_Writer` actually draws.
 *
 * @see BE_PROCESS/reports-cards-batch-design.md
 */
class ReportRegistryTest extends TestCase {

	private const KNOWN_SHAPES = [ 'table', 'card', 'statistics', 'narrative', 'calendar', 'house_rules' ];

	/** @var array<string,array<string,mixed>> */
	private static array $registry;

	public static function setUpBeforeClass(): void {
		self::$registry = include BE_PLUGIN_DIR . 'includes/Database/report-registry.php';
	}

	public function test_registry_has_exactly_twenty_reports(): void {
		$this->assertCount(
			20,
			self::$registry,
			'GV-SOURCEMAP.md counts 19 GV301 reports besides the 12 character sheets; House Rules (v0.99.19, Decision 094) is the first report with no Grapevine counterpart at all.'
		);
	}

	public function test_every_report_has_a_known_shape(): void {
		foreach ( self::$registry as $key => $report ) {
			$this->assertContains(
				$report['shape'],
				self::KNOWN_SHAPES,
				"Report \"{$key}\" has shape \"{$report['shape']}\", not one of Report_Writer's five."
			);
		}
	}

	public function test_every_report_has_a_title(): void {
		foreach ( self::$registry as $key => $report ) {
			$this->assertNotEmpty( $report['title'] ?? '', "Report \"{$key}\" has no title." );
		}
	}

	/**
	 * A `field`-sourced column against a `char`/`item`/`loc`/`rote` entity must
	 * resolve through Field_Registry - the same guarantee FieldRegistryTest
	 * already holds the char inventory to, extended to every report's own
	 * declared entity.
	 */
	public function test_field_sourced_columns_resolve_against_field_registry(): void {
		foreach ( self::$registry as $key => $report ) {
			if ( ! in_array( $report['entity'] ?? '', Field_Registry::QUERYABLE_INVENTORIES, true ) ) {
				continue;
			}
			foreach ( ( $report['columns'] ?? [] ) as [ $label, $field_key, $source ] ) {
				if ( $source !== 'field' ) {
					continue;
				}
				$this->assertTrue(
					Field_Registry::is_mapped( $field_key, $report['entity'] ),
					"Report \"{$key}\" column \"{$label}\" ({$field_key}) does not resolve for inventory \"{$report['entity']}\"."
				);
			}
		}
	}

	/**
	 * Every column tuple is exactly `[ label, key, source ]` - a malformed row
	 * would silently misbehave in Report_Document rather than failing loudly.
	 */
	public function test_every_column_is_a_three_element_tuple(): void {
		foreach ( self::$registry as $key => $report ) {
			foreach ( ( $report['columns'] ?? [] ) as $i => $column ) {
				$this->assertCount( 3, $column, "Report \"{$key}\" column index {$i} is not a [label, key, source] tuple." );
			}
		}
	}

	public function test_column_sources_are_recognized(): void {
		$known = [ 'field', 'special', 'ledger', 'player', 'plot', 'boons', 'equipment', 'unmapped' ];
		foreach ( self::$registry as $key => $report ) {
			foreach ( ( $report['columns'] ?? [] ) as [ $label, $field_key, $source ] ) {
				$this->assertContains( $source, $known, "Report \"{$key}\" column \"{$label}\" has unrecognized source \"{$source}\"." );
			}
		}
	}

	/**
	 * 1.0.0-review F-072. Vampire Status Report's rows are characters, and its Date column read a
	 * change's submission date through the ledger resolver - so it was blank for every row, with
	 * nothing to say why. Each source reads one kind of row; a column may only use a source that
	 * fits the rows its report builds.
	 */
	public function test_each_column_source_fits_the_rows_its_report_builds(): void {
		foreach ( self::$registry as $key => $report ) {
			$rows = ( $report['rows_from'] ?? null ) === 'ledger' ? 'change' : ( $report['entity'] ?? '' );
			$fits = [
				'change' => [ 'ledger', 'special', 'unmapped' ],
				'char'   => [ 'field', 'special', 'boons', 'equipment', 'unmapped' ],
				'player' => [ 'player', 'special', 'unmapped' ],
				'plot'   => [ 'plot', 'unmapped' ],
			][ $rows ] ?? [ 'field', 'special', 'unmapped' ];

			foreach ( ( $report['columns'] ?? [] ) as [ $label, $field_key, $source ] ) {
				$this->assertContains( $source, $fits, "Report \"{$key}\" builds {$rows} rows, but column \"{$label}\" reads them as \"{$source}\"." );
			}
		}
	}

	public function test_card_and_table_reports_declare_columns(): void {
		foreach ( self::$registry as $key => $report ) {
			if ( in_array( $report['shape'], [ 'table', 'card' ], true ) && ! ( $report['rows_from'] ?? null ) ) {
				$this->assertNotEmpty( $report['columns'] ?? [], "Report \"{$key}\" (shape {$report['shape']}) has no columns." );
			}
		}
	}

	public function test_statistics_reports_declare_stattype_or_defer_to_the_request(): void {
		foreach ( self::$registry as $key => $report ) {
			if ( $report['shape'] !== 'statistics' ) {
				continue;
			}
			$this->assertArrayHasKey( 'stattype', $report, "Statistics report \"{$key}\" has no stattype key." );
			if ( $report['stattype'] !== null ) {
				$this->assertContains(
					$report['stattype'],
					[ 'distribution', 'distinct_distribution', 'specific_distribution', 'maxima', 'sums' ],
					"Report \"{$key}\" stattype \"{$report['stattype']}\" is not a real Query_Engine::STATISTIC_TYPES value."
				);
			}
		}
	}

	public function test_expected_report_keys_are_present(): void {
		$expected = [
			'character-roster', 'player-roster', 'sign-in-sheet', 'experience-history',
			'player-point-history', 'item-cards', 'rote-cards', 'location-cards',
			'game-calendar', 'plot-report', 'master-action-report', 'master-rumor-report',
			'action-and-rumor-report', 'search-report', 'statistics-report',
			'vampire-status-report', 'merits-and-flaws-report', 'influence-report',
			'character-equipment', 'house-rules',
		];
		foreach ( $expected as $key ) {
			$this->assertArrayHasKey( $key, self::$registry, "Expected report \"{$key}\" is missing from the registry." );
		}
	}
}
