<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Game_Session;
use BeyondElysium\REST\Import_Controller;
use WP_UnitTestCase;

/**
 * A full game file's own calendar.entries become real Game_Session rows on import.
 */
class GameImportCalendarThreadTest extends WP_UnitTestCase {

	private string $game_slug = 'thread-import-calendar';
	private int $game_id;

	public function setUp(): void {
		parent::setUp();

		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'be_games', [
			'slug'       => $this->game_slug,
			'name'       => 'Thread Import Calendar',
			'created_by' => 1,
			'created_at' => current_time( 'mysql' ),
			'updated_at' => current_time( 'mysql' ),
			'settings'   => wp_json_encode( [] ),
		] );
		$this->game_id = (int) $wpdb->insert_id;
	}

	/**
	 * A minimal, shape-complete $parsed array.
	 */
	private function synthetic_parsed( array $calendar_entries ): array {
		return [
			'players'    => [],
			'characters' => [],
			'items'      => [],
			'locations'  => [],
			'rotes'      => [],
			'actions'    => [],
			'plots'      => [],
			'rumors'     => [],
			'queries'    => [],
			'calendar'   => [ 'entries' => $calendar_entries ],
		];
	}

	public function test_calendar_entries_are_imported_as_real_sessions(): void {
		$parsed = $this->synthetic_parsed( [
			[ 'date' => '2026-10-02 00:00:00', 'time' => '7pm', 'place' => "Marcy's Diner", 'notes' => 'Bring snacks.' ],
			[ 'date' => '2026-10-09 00:00:00', 'time' => '', 'place' => '', 'notes' => '' ],
		] );

		$created = Import_Controller::apply_import( $this->game_id, $this->game_slug, $parsed, 'synthetic.gv3', [] );

		$this->assertSame( [ 'imported' => 2, 'skipped' => 0 ], $created['calendar_entries'] );

		$first = Game_Session::find_by_date( $this->game_id, '2026-10-02' );
		$this->assertNotNull( $first );
		$this->assertSame( "Marcy's Diner", $first->place );

		$second = Game_Session::find_by_date( $this->game_id, '2026-10-09' );
		$this->assertNotNull( $second );
		$this->assertNull( $second->place, 'an empty string field imports as null, not a stored empty string' );
	}

	public function test_a_date_that_already_exists_is_skipped_not_overwritten(): void {
		Game_Session::create( [
			'game_id'    => $this->game_id,
			'game_date'  => '2026-10-02',
			'place'      => 'The Real Place',
			'created_by' => 1,
		] );

		$parsed = $this->synthetic_parsed( [
			[ 'date' => '2026-10-02 00:00:00', 'time' => '', 'place' => 'An Imported Place', 'notes' => '' ],
		] );
		$created = Import_Controller::apply_import( $this->game_id, $this->game_slug, $parsed, 'synthetic.gv3', [] );

		$this->assertSame( [ 'imported' => 0, 'skipped' => 1 ], $created['calendar_entries'] );

		$session = Game_Session::find_by_date( $this->game_id, '2026-10-02' );
		$this->assertSame( 'The Real Place', $session->place, 'the existing session must never be overwritten' );
	}

	public function test_no_calendar_key_at_all_is_the_ordinary_single_character_import_case(): void {
		$parsed = [
			'players'    => [],
			'characters' => [],
			'items'      => [],
			'locations'  => [],
			'rotes'      => [],
			'actions'    => [],
			'plots'      => [],
			'rumors'     => [],
			'queries'    => [],
		];

		$created = Import_Controller::apply_import( $this->game_id, $this->game_slug, $parsed, 'synthetic.gex', [] );

		$this->assertArrayNotHasKey( 'calendar_entries', $created );
	}
}
