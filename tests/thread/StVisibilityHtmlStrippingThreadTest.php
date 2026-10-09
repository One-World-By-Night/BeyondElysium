<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Services\St_Visibility;
use WP_UnitTestCase;

/**
 * St_Visibility's three HTML-field `[ST]` strippers, run against real `wp_kses_post()`/`force_balance_tags()` rather
 * than the unit layer's pass-through shims.
 */
class StVisibilityHtmlStrippingThreadTest extends WP_UnitTestCase {

	public function test_a_secrets_content_with_a_marker_straddling_a_tag_reaches_a_player_as_balanced_html(): void {
		$secret = (object) [
			'content' => '<p>Public <strong>bold [ST]secret</strong> plotting[/ST] more</p>',
		];

		St_Visibility::filter_secret( $secret, null, false );

		$this->assertStringNotContainsString( 'secret', $secret->content );
		$this->assertStringNotContainsString( 'plotting', $secret->content );
		$this->assertSame(
			substr_count( $secret->content, '<strong>' ),
			substr_count( $secret->content, '</strong>' ),
			'the straddled <strong> tag must be closed'
		);
	}

	public function test_a_castings_brief_with_a_marker_straddling_a_tag_reaches_a_player_as_balanced_html(): void {
		$casting = (object) [
			'brief' => '<p>Known <em>role [ST]secret twist</em> here[/ST] more</p>',
		];

		St_Visibility::filter_casting( $casting, null, false );

		$this->assertStringNotContainsString( 'twist', $casting->brief );
		$this->assertSame(
			substr_count( $casting->brief, '<em>' ),
			substr_count( $casting->brief, '</em>' ),
			'the straddled <em> tag must be closed'
		);
	}

	public function test_an_after_game_reports_fields_with_a_marker_straddling_a_tag_reach_a_player_as_balanced_html(): void {
		$report = (object) [
			'did'      => '<p>Did <b>this [ST]secretly</b> thing[/ST] more</p>',
			'wants'    => 'plain text, no html',
			'to_staff' => '<p>Normal</p>',
		];

		St_Visibility::filter_report( $report, null, false );

		$this->assertStringNotContainsString( 'secretly', $report->did );
		$this->assertSame(
			substr_count( $report->did, '<b>' ),
			substr_count( $report->did, '</b>' ),
			'the straddled <b> tag must be closed'
		);
		$this->assertSame( 'plain text, no html', $report->wants );
	}
}
