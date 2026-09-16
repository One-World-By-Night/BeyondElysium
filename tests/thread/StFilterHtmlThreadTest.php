<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Services\St_Filter;
use WP_UnitTestCase;

/**
 * Decision 111 (rich-text world-object/plot fields, 1.0.0-review "basically all textarea
 * form spaces should be htmlarea"). `St_Filter::strip()` cuts by byte offset with no notion
 * of HTML structure - it was written for plain-text `biography`/`notes` and has been reused
 * unchanged for every field this pass makes rich text. `strip_html_for_game()` is the same
 * cut plus a defensive `wp_kses_post()` pass, kept in its own thread test since that
 * function is real WordPress, unavailable at the unit layer `StFilterTest` runs at.
 */
class StFilterHtmlThreadTest extends WP_UnitTestCase {

	public function test_a_marker_filling_its_own_paragraph_strips_to_well_formed_html(): void {
		$this->assertSame(
			'<p>Public.</p><p></p><p>More public.</p>',
			St_Filter::strip_html_for_game( '<p>Public.</p><p>[ST]Secret.[/ST]</p><p>More public.</p>', null )
		);
	}

	/**
	 * The real risk this method exists for: a marker placed mid-tag, cutting a `<strong>`
	 * open without its matching close. The secret word must still be gone; a dangling tag
	 * left by the cut must not bleed its formatting into every paragraph after it.
	 */
	public function test_a_marker_that_splits_a_tag_leaves_no_dangling_open_tag(): void {
		$result = St_Filter::strip_html_for_game(
			'<p>Public <strong>[ST]Secret[/ST] bold</strong> text.</p>',
			null
		);

		$this->assertStringNotContainsString( 'Secret', $result );
		$this->assertSame(
			substr_count( $result, '<strong' ),
			substr_count( $result, '</strong>' ),
			$result
		);
	}

	public function test_it_still_strips_a_games_own_configured_markers(): void {
		$settings = (object) [ 'st_comment_start' => '<<HIDE>>', 'st_comment_end' => '<<SHOW>>' ];
		$this->assertSame(
			'<p>Before  After</p>',
			St_Filter::strip_html_for_game( '<p>Before <<HIDE>>hidden<<SHOW>> After</p>', $settings )
		);
	}

	public function test_a_disallowed_tag_left_over_from_the_cut_does_not_survive_either(): void {
		$result = St_Filter::strip_html_for_game(
			'<p>Public.</p><script>[ST]alert(1)[/ST]</script><p>More.</p>',
			null
		);

		$this->assertStringNotContainsString( 'alert', $result );
		$this->assertStringNotContainsString( '<script', $result );
	}
}
