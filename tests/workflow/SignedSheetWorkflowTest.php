<?php

namespace BeyondElysium\Tests\Workflow;

require_once __DIR__ . '/../support/PdfSigningTestFixture.php';

use BeyondElysium\Models\Character;
use BeyondElysium\Models\Creature_Stack;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Schema_Block;
use BeyondElysium\Models\Template;
use BeyondElysium\Tests\Support\PdfSigningTestFixture;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * The scenario a person would describe (TESTING.md's own standard for this
 * layer): a Storyteller sets up a chronicle and a character for their
 * player; the player prints their own signed sheet and gets a real,
 * verifiable PDF back; a second, unrelated player who tries the same
 * character is denied. The first workflow-layer test this project has -
 * `tests/workflow/` held only a `.gitkeep` before this.
 *
 * @see BE_PROCESS/signed-pdf-design.md Section 9, SP-12
 */
class SignedSheetWorkflowTest extends WP_UnitTestCase {

	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		PdfSigningTestFixture::ensure();
	}

	public function test_a_player_prints_their_own_sheet_and_a_stranger_is_denied(): void {
		// The Storyteller sets up the chronicle: a schema block, a creature stack built
		// from it, and a layout to print through.
		$hst_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $hst_id );

		Game::create( [
			'slug'       => 'signed-sheet-workflow',
			'name'       => 'Signed Sheet Workflow Test',
			'created_by' => $hst_id,
		] );

		Schema_Block::create( [
			'slug'         => 'ssw-identity',
			'name'         => 'Identity',
			'section_type' => 'identity_field',
			'definition'   => [ 'fields' => [ [ 'name' => 'Clan', 'field_type' => 'text', 'required' => false ] ] ],
			'is_system'    => 0,
		] );

		Creature_Stack::create( [
			'slug'             => 'ssw-stack',
			'name'             => 'Vampire',
			'stack_definition' => [ 'sections' => [ [ 'block_slug' => 'ssw-identity' ] ] ],
			'is_system'        => 0,
			'created_by'       => $hst_id,
		] );

		Template::create( [
			'stack_slug'    => 'ssw-stack',
			'name'          => 'Signed Sheet Workflow Layout',
			'template_type' => 'sheet_full',
			'layout'        => [
				'version'  => 1,
				'columns'  => 3,
				'sections' => [
					[ 'block_slug' => 'ssw-identity', 'column' => 1, 'order' => 1, 'title' => 'Identity', 'display' => null, 'collapsed' => false ],
				],
			],
			'is_system'     => 0,
			'created_by'    => $hst_id,
		] );

		// The player's own character - Character::create() grants this player real
		// chronicle membership as a side effect, the ordinary path to holding a sheet.
		$player_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );

		$character_id = Character::create( [
			'name'       => 'Wrenna Ashgrove',
			'owner_slug' => 'signed-sheet-workflow',
			'stack_slug' => 'ssw-stack',
			'wp_user_id' => $player_id,
			'sheet_data' => [ 'ssw-identity' => [ 'Clan' => 'Toreador' ] ],
			'created_by' => $hst_id,
		] );

		// The player prints their own sheet.
		wp_set_current_user( $player_id );
		$request = new WP_REST_Request( 'GET', '/be/v1/signed-sheet-workflow/sheets/pdf' );
		$request->set_url_params( [ 'game_slug' => 'signed-sheet-workflow' ] );
		$request->set_param( 'character_ids', (string) $character_id );
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 200, $response->get_status(), 'the owning player must receive their sheet' );
		$bytes = $response->get_data()['bytes'];
		$this->assertStringStartsWith( '%PDF-', $bytes );
		$this->assertStringContainsString( '/Sig', $bytes, 'the sheet the player receives must be signed, not a plain PDF' );

		if ( shell_exec( 'command -v pdfsig' ) ) {
			$path = sys_get_temp_dir() . '/be-signed-sheet-workflow.pdf';
			file_put_contents( $path, $bytes );
			$report = (string) shell_exec( 'pdfsig ' . escapeshellarg( $path ) . ' 2>/dev/null' );
			unlink( $path );
			$this->assertStringContainsString( 'Signature Validation: Signature is Valid.', $report );
		}

		// A second, unrelated player tries the same character and is refused - the sheet
		// is Wrenna's own player's to print, not any logged-in chronicle member's.
		$stranger_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		\BeyondElysium\Models\Game_Member::ensure_player( (int) Game::find_by_slug( 'signed-sheet-workflow' )->id, $stranger_id );

		wp_set_current_user( $stranger_id );
		$denied = rest_get_server()->dispatch( $request );

		$this->assertSame( 403, $denied->get_status(), 'a stranger must never receive another player\'s signed sheet' );
		$this->assertSame( 'ownership_denied', $denied->as_error()->get_error_code() );
	}
}
