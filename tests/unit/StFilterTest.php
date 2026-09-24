<?php

namespace BeyondElysium\Tests\Unit;

use BeyondElysium\Services\St_Filter;
use PHPUnit\Framework\TestCase;

/**
 * Transcribed from `GameClass.STFilter`.
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

	public function test_strip_for_game_never_turns_filtering_off_for_a_blank_stored_marker(): void {
		foreach ( [ [ '', '' ], [ '', '[/ST]' ], [ '[ST]', '' ] ] as [ $start, $end ] ) {
			$settings = (object) [ 'st_comment_start' => $start, 'st_comment_end' => $end ];
			$this->assertSame( 'Before  After', St_Filter::strip_for_game( 'Before [ST]hidden[/ST] After', $settings ), "'{$start}' / '{$end}'" );
		}
	}

	public function test_strip_for_game_honors_custom_markers(): void {
		$settings = (object) [ 'st_comment_start' => '<<HIDE>>', 'st_comment_end' => '<<SHOW>>' ];
		$this->assertSame(
			'Before  After',
			St_Filter::strip_for_game( 'Before <<HIDE>>hidden<<SHOW>> After', $settings )
		);
	}

	// strip_html_for_game() calls wp_kses_post(), a real WordPress function unavailable at this unit layer.
}
