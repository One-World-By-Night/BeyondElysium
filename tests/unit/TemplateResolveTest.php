<?php

namespace BeyondElysium\Tests\Unit;

use BeyondElysium\Models\Template;
use PHPUnit\Framework\TestCase;

/**
 * The template resolution chain: game-scoped -> global -> null.
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
		// json_decode('null') succeeds and returns PHP null.
		$row = $this->row( 1, 5, 'null' );
		$this->assertNull( Template::resolve_from_rows( $row, null ) );
	}

	// -------------------------------------------------------------------------
	// validate_layout() itself is NOT covered here
	// -------------------------------------------------------------------------

	public function test_display_types_has_exactly_11_modes(): void {
		$this->assertCount( 11, Template::DISPLAY_TYPES );
		$this->assertSame( array_unique( Template::DISPLAY_TYPES ), Template::DISPLAY_TYPES );
	}
}
