<?php

namespace BeyondElysium\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Every English word a report prints has a line in the strings file that translates it.
 */
class ReportStringsTest extends TestCase {

	/** @var array<string,array<string,mixed>> */
	private static array $registry;

	/** @var array<string,string> */
	private static array $strings;

	public static function setUpBeforeClass(): void {
		self::$registry = include BE_PLUGIN_DIR . 'includes/Database/report-registry.php';
		self::$strings  = include BE_PLUGIN_DIR . 'includes/Database/report-strings.php';
	}

	public function test_every_report_title_has_a_line(): void {
		foreach ( self::$registry as $key => $report ) {
			$this->assertArrayHasKey( $report['title'], self::$strings, "Report \"{$key}\" prints \"{$report['title']}\" with no translation line." );
		}
	}

	public function test_every_column_heading_has_a_line(): void {
		foreach ( self::$registry as $key => $report ) {
			foreach ( $report['columns'] ?? [] as [ $heading ] ) {
				$this->assertArrayHasKey( $heading, self::$strings, "Report \"{$key}\" prints the heading \"{$heading}\" with no translation line." );
			}
		}
	}

	public function test_every_summary_has_a_line(): void {
		foreach ( self::$registry as $key => $report ) {
			$this->assertNotEmpty( $report['summary'] ?? '', "Report \"{$key}\" has no summary." );
			$this->assertArrayHasKey( $report['summary'], self::$strings, "Report \"{$key}\" prints a summary with no translation line." );
		}
	}

	public function test_every_empty_note_has_a_line(): void {
		foreach ( self::$registry as $key => $report ) {
			if ( isset( $report['empty_note'] ) ) {
				$this->assertArrayHasKey( $report['empty_note'], self::$strings, "Report \"{$key}\" prints an empty note with no translation line." );
			}
		}
	}

	public function test_the_words_the_documents_add_have_a_line(): void {
		foreach ( [ 'Note', 'Source', 'Active', 'Resolved', 'Archived', 'Initiated by' ] as $word ) {
			$this->assertArrayHasKey( $word, self::$strings );
		}
	}

	public function test_a_line_translates_the_word_it_is_keyed_by(): void {
		foreach ( self::$strings as $english => $translated ) {
			$this->assertSame( $english, $translated, 'The unit shim returns its input, so a key and its value must match.' );
		}
	}
}
