<?php

namespace BeyondElysium\Tests\Unit;

use BeyondElysium\Services\GV_Binary_Reader;
use BeyondElysium\Services\Game_File_Parser;
use PHPUnit\Framework\TestCase;

/**
 * GVBG binary game-file parsing (workflow-0.8.md Step 9f), against the one real `.gv3`
 * sample this repo has - `data-samples/personal-chron.gv3` (21,074 bytes, `Version = 3.0`).
 *
 * `data-samples/` is `.distignore`d (excluded from the shipped artifact), so this fixture
 * is real but not guaranteed present in every checkout - matching `GexParserTest`'s own
 * discipline for `GV301Source/Code/` fixtures.
 *
 * @see BE_PROCESS/GV-SOURCEMAP.md "GVBG binary game-file shape - verified 2026-09-10"
 * @see BE_PROCESS/workflow-0.8.md Step 9f
 */
class GameFileParserTest extends TestCase {

	private function path( string $relative ): string {
		return BE_PLUGIN_ROOT . '/' . $relative;
	}

	/** @return array<string,mixed> */
	private function real_sample(): array {
		return Game_File_Parser::parse_file( $this->path( 'data-samples/personal-chron.gv3' ) );
	}

	public function test_the_real_sample_parses_to_exactly_eof_with_the_right_entity_count(): void {
		$data = $this->real_sample();

		$this->assertEqualsWithDelta( 3.0, $data['version'], 0.0001 );
		// GameClass.EntityCount() - the sourcemap's own confirmed 1+1+18+48+0+1+0+0+0 = 69,
		// matching the file's own `Size` header field read separately below.
		$this->assertSame( 69, $data['size'] );

		$walked = count( $data['players'] ) + count( $data['characters'] ) + count( $data['queries'] )
			+ count( $data['items'] ) + count( $data['rotes'] ) + count( $data['locations'] )
			+ count( $data['actions'] ) + count( $data['plots'] ) + count( $data['rumors'] );
		$this->assertSame( 69, $walked, 'the walked entity count must match the file\'s own Size field exactly' );
	}

	public function test_the_real_sample_counts_per_section(): void {
		$data = $this->real_sample();

		$this->assertCount( 1, $data['players'] );
		$this->assertCount( 1, $data['characters'] );
		$this->assertCount( 18, $data['queries'] );
		$this->assertCount( 48, $data['items'] );
		$this->assertCount( 0, $data['rotes'] );
		$this->assertCount( 1, $data['locations'] );
		$this->assertCount( 0, $data['actions'] );
		$this->assertCount( 0, $data['plots'] );
		$this->assertCount( 0, $data['rumors'] );
		$this->assertCount( 10, $data['experience_awards'] );
		$this->assertCount( 31, $data['templates'] );
	}

	public function test_chronicle_metadata_reads_correctly(): void {
		$data = $this->real_sample();

		$this->assertSame( 'Personal', $data['chronicle_title'] );
		$this->assertSame( 'Grapevine Menus.gvm', $data['menu_file_name'] );
		$this->assertTrue( $data['extended_health'] );
		$this->assertTrue( $data['enforce_history'] );
	}

	public function test_the_calendar_is_read_unconditionally_with_no_presence_flag(): void {
		// The divergence this class's own doc comment calls the nastiest one - if this
		// were wrongly gated behind an outer count check (GVBE's shape), the whole rest
		// of the file would desynchronize and the EOF assertion above would already have
		// failed. This test exists so a future "simplification" toward GVBE's shape fails
		// here first, with a clear message, rather than as an opaque desync elsewhere.
		$data = $this->real_sample();

		$this->assertNotNull( $data['calendar'] );
		$this->assertArrayHasKey( 'last_modified', $data['calendar'] );
		$this->assertArrayHasKey( 'entries', $data['calendar'] );
	}

	public function test_apr_engine_is_present_at_version_3_with_no_presence_flag(): void {
		$data = $this->real_sample();

		$this->assertNotNull( $data['apr_engine'] );
		$this->assertArrayHasKey( 'background_actions', $data['apr_engine'] );
		$this->assertArrayHasKey( 'actions_per_level', $data['apr_engine'] );
	}

	public function test_the_one_real_character_is_a_vampire_via_the_shared_race_dispatch(): void {
		$data      = $this->real_sample();
		$character = $data['characters'][0];

		$this->assertSame( 'vampire', $character['race'] );
		$this->assertSame( 'Ian Kincaid II', $character['name'] );
	}

	public function test_the_one_real_location_reads_correctly(): void {
		$data     = $this->real_sample();
		$location = $data['locations'][0];

		$this->assertSame( "Ian Kincaid's Haven", $location['name'] );
		$this->assertSame( 'Haven', $location['loc_type'] );
		$this->assertSame( 4, $location['level'] );
	}

	// -------------------------------------------------------------------------
	// Synthetic buffers - no real fixture covers these
	// -------------------------------------------------------------------------

	public function test_rejects_a_non_grapevine_game_file(): void {
		$reader = new GV_Binary_Reader( "\x04\x00GVBE", 'not-a-game-file.gv3' );

		$this->expectException( \RuntimeException::class );
		Game_File_Parser::parse_binary( $reader );
	}

	public function test_a_desynchronized_stream_is_refused_not_silently_misread(): void {
		// A well-formed header/version/size but truncated immediately after - guarantees
		// EOF is reached before the container finishes reading, exercising the same
		// "did we land exactly on EOF" guard GexParserTest's own equivalent test does.
		$put_str = static function ( string $s ): string {
			return pack( 'v', strlen( $s ) ) . $s;
		};
		$truncated = $put_str( 'GVBG' ) . pack( 'd', 3.0 ) . pack( 'v', 0 );

		$reader = new GV_Binary_Reader( $truncated, 'truncated.gv3' );

		$this->expectException( \RuntimeException::class );
		Game_File_Parser::parse_binary( $reader );
	}
}
