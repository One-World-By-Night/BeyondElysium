<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Game;
use BeyondElysium\Models\Game_Member;
use BeyondElysium\Models\Plot;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * A report's title, headings and plot words print in the site's language.
 */
class ReportWordsThreadTest extends WP_UnitTestCase {

	private string $slug = 'thread-report-words';
	private int $hst;

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );

		$game_id   = (int) Game::create( [ 'slug' => $this->slug, 'name' => 'Report Words' ] );
		$this->hst = self::factory()->user->create( [ 'role' => 'editor' ] );
		Game_Member::set_role( $game_id, $this->hst, 'hst' );
		Plot::create( [ 'game_id' => $game_id, 'title' => 'Words Plot', 'status' => 'active' ] );

		add_filter( 'gettext', [ $this, 'mark' ], 10, 3 );
		switch_to_locale( 'pt_BR' );
	}

	public function tearDown(): void {
		restore_previous_locale();
		remove_filter( 'gettext', [ $this, 'mark' ], 10 );
		parent::tearDown();
	}

	/**
	 * Stands in for a translation: prefixes every word the plugin asks for.
	 */
	public function mark( $translation, $text, $domain ) {
		return $domain === 'beyond-elysium' ? 'PT ' . $text : $translation;
	}

	private function get( string $route ) {
		wp_set_current_user( $this->hst );
		return rest_get_server()->dispatch( new WP_REST_Request( 'GET', $route ) );
	}

	public function test_the_report_list_carries_translated_titles(): void {
		$titles = array_column( $this->get( "/be/v1/{$this->slug}/reports" )->get_data(), 'title' );

		$this->assertCount( 21, $titles );
		foreach ( $titles as $title ) {
			$this->assertStringStartsWith( 'PT ', $title );
		}
	}

	public function test_the_report_list_carries_translated_summaries(): void {
		$rows = $this->get( "/be/v1/{$this->slug}/reports" )->get_data();

		foreach ( $rows as $row ) {
			$this->assertStringStartsWith( 'PT ', $row['summary'], $row['key'] );
		}
	}

	public function test_a_table_report_carries_a_translated_title_and_headings(): void {
		$document = $this->get( "/be/v1/{$this->slug}/reports/character-roster" )->get_data();

		$this->assertSame( 'PT Character Roster', $document['title'] );
		foreach ( $document['columns'] as $heading ) {
			$this->assertStringStartsWith( 'PT ', $heading );
		}
	}

	public function test_the_plot_report_translates_the_status_word(): void {
		$document = $this->get( "/be/v1/{$this->slug}/reports/plot-report" )->get_data();

		$block = current( array_filter( $document['blocks'], static fn( $b ) => str_contains( $b['title'], 'Words Plot' ) ) );
		$this->assertNotFalse( $block );
		$this->assertSame( 'PT Active', $block['status'] );
		$this->assertSame( 'PT Initiated by: st', $block['lines'][0] );
	}

	public function test_the_calendar_note_is_translated(): void {
		$document = $this->get( "/be/v1/{$this->slug}/reports/game-calendar" )->get_data();

		$this->assertSame( "PT No game nights are on this chronicle's calendar.", $document['note'] );
	}

	public function test_an_unknown_word_comes_back_as_given(): void {
		$this->assertSame( 'Not In The File', \BeyondElysium\Services\Report_Document::words( 'Not In The File' ) );
	}
}
