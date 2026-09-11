<?php

namespace BeyondElysium\Tests\Unit;

use BeyondElysium\Models\Template;
use PHPUnit\Framework\TestCase;

/**
 * The template resolution chain (Decision 020): game-scoped -> global -> null.
 *
 * `Template::resolve()` itself needs $wpdb for its two SQL fetches, which has no place in
 * a unit test (TESTING.md: "what does not belong here: anything that needs $wpdb"). The
 * decision logic that actually breaks silently - which of two already-fetched rows wins,
 * and what a corrupt one does to the chain - lives in `resolve_from_rows()` precisely so
 * it can be tested here without a database. The SQL fetch itself, including the
 * game_id-null-vs-zero distinction, is covered by tests/thread/TemplateResolveThreadTest.php
 * against a real table.
 *
 * @see BE_PROCESS/workflow-0.3.md Step 1b, 1j
 */
class TemplateResolveTest extends TestCase {

	private function row( int $id, ?int $game_id, string $layout_json, int $is_system = 0 ): object {
		return (object) [
			'id'        => $id,
			'game_id'   => $game_id,
			'is_system' => $is_system,
			'layout'    => $layout_json,
		];
	}

	private function valid_json( string $marker ): string {
		return json_encode( [ 'version' => 1, 'columns' => 1, 'sections' => [], 'marker' => $marker ] );
	}

	// -------------------------------------------------------------------------
	// resolve_from_rows() - the resolution chain's decision logic
	// -------------------------------------------------------------------------

	public function test_game_override_wins_over_global(): void {
		$game   = $this->row( 1, 5, $this->valid_json( 'game' ) );
		$global = $this->row( 2, null, $this->valid_json( 'global' ) );

		$result = Template::resolve_from_rows( $game, $global );

		$this->assertNotNull( $result );
		$this->assertSame( 1, $result->id );
		$this->assertSame( 'game', $result->layout['marker'] );
	}

	public function test_global_used_when_no_override(): void {
		$global = $this->row( 2, null, $this->valid_json( 'global' ) );

		$result = Template::resolve_from_rows( null, $global );

		$this->assertNotNull( $result );
		$this->assertSame( 2, $result->id );
		$this->assertSame( 'global', $result->layout['marker'] );
	}

	public function test_null_when_neither_exists(): void {
		$this->assertNull( Template::resolve_from_rows( null, null ) );
	}

	public function test_corrupt_game_row_is_treated_as_a_miss_and_falls_through_to_global(): void {
		$corrupt_game = $this->row( 1, 5, '{not valid json' );
		$global       = $this->row( 2, null, $this->valid_json( 'global' ) );

		$result = Template::resolve_from_rows( $corrupt_game, $global );

		$this->assertNotNull( $result );
		$this->assertSame( 2, $result->id );
	}

	public function test_corrupt_global_row_resolves_to_null_when_no_override_exists(): void {
		$corrupt_global = $this->row( 2, null, '{not valid json' );

		$result = Template::resolve_from_rows( null, $corrupt_global );

		$this->assertNull( $result );
	}

	public function test_a_json_literal_null_layout_is_also_treated_as_corrupt(): void {
		// json_decode('null') succeeds and returns PHP null - not a decode error, but not
		// a usable layout array either. The chain must still treat it as a miss.
		$row = $this->row( 1, 5, 'null' );
		$this->assertNull( Template::resolve_from_rows( $row, null ) );
	}

	// -------------------------------------------------------------------------
	// validate_layout() itself is NOT covered here. It checks block_slug against the
	// real be_schema_blocks table (Step 2d), which means it needs $wpdb - the one thing
	// that does not belong in a unit test (TESTING.md). Its structural rules (version,
	// column bounds, duplicate slugs, the 11 display types) are covered against a real
	// database in tests/thread/TemplateValidateLayoutTest.php instead.
	// -------------------------------------------------------------------------

	public function test_display_types_has_exactly_11_modes(): void {
		$this->assertCount( 11, Template::DISPLAY_TYPES );
		$this->assertSame( array_unique( Template::DISPLAY_TYPES ), Template::DISPLAY_TYPES );
	}
}
