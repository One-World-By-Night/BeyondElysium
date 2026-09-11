<?php

namespace BeyondElysium\Tests\Unit;

use BeyondElysium\Services\St_Filter;
use PHPUnit\Framework\TestCase;

/**
 * Transcribed from `GameClass.STFilter`.
 *
 * `Trim()` only strips leading/trailing whitespace, not whatever is left in the middle
 * once a marked section is cut out - a marker with a space on both sides leaves both
 * spaces behind, doubled up. That is faithful VB behavior, not a bug to smooth over.
 *
 * @see BE_PROCESS/GV-SOURCEMAP.md "ST data filtering (GameClass.STFilter)"
 */
class StFilterTest extends TestCase {

	public function test_strips_a_single_marked_section(): void {
		$this->assertSame(
			'Before  After',
			St_Filter::strip( 'Before [ST]hidden[/ST] After', '[ST]', '[/ST]' )
		);
	}

	public function test_strips_repeatedly_for_multiple_sections(): void {
		$this->assertSame(
			'A  B  C',
			St_Filter::strip( 'A [ST]x[/ST] B [ST]y[/ST] C', '[ST]', '[/ST]' )
		);
	}

	public function test_unterminated_opener_runs_to_end_of_string(): void {
		$this->assertSame(
			'Before',
			St_Filter::strip( 'Before [ST]hidden forever', '[ST]', '[/ST]' )
		);
	}

	public function test_trims_only_the_outer_edges_not_whitespace_left_in_the_middle(): void {
		// 3 spaces before the marker + 3 after it = 6 left behind in the middle; the
		// 2 trailing spaces at the very end are the only ones Trim() removes.
		$this->assertSame(
			'Before' . str_repeat( ' ', 6 ) . 'After',
			St_Filter::strip( 'Before   [ST]hidden[/ST]   After  ', '[ST]', '[/ST]' )
		);
	}

	public function test_empty_start_marker_disables_filtering(): void {
		$text = 'Before [ST]hidden[/ST] After';
		$this->assertSame( $text, St_Filter::strip( $text, '', '[/ST]' ) );
	}

	public function test_empty_end_marker_disables_filtering(): void {
		$text = 'Before [ST]hidden[/ST] After';
		$this->assertSame( $text, St_Filter::strip( $text, '[ST]', '' ) );
	}

	public function test_markers_are_configurable_not_literal(): void {
		$this->assertSame(
			'Before  After',
			St_Filter::strip( 'Before {{secret}}hidden{{/secret}} After', '{{secret}}', '{{/secret}}' )
		);
	}

	public function test_no_markers_present_returns_text_unchanged_after_trim(): void {
		$this->assertSame( 'Plain text', St_Filter::strip( '  Plain text  ', '[ST]', '[/ST]' ) );
	}

	public function test_strip_for_game_uses_defaults_when_settings_have_no_markers(): void {
		$this->assertSame(
			'Before  After',
			St_Filter::strip_for_game( 'Before [ST]hidden[/ST] After', (object) [] )
		);
	}

	public function test_strip_for_game_uses_defaults_when_settings_are_null(): void {
		$this->assertSame(
			'Before  After',
			St_Filter::strip_for_game( 'Before [ST]hidden[/ST] After', null )
		);
	}

	public function test_strip_for_game_honors_custom_markers(): void {
		$settings = (object) [ 'st_comment_start' => '<<HIDE>>', 'st_comment_end' => '<<SHOW>>' ];
		$this->assertSame(
			'Before  After',
			St_Filter::strip_for_game( 'Before <<HIDE>>hidden<<SHOW>> After', $settings )
		);
	}
}
