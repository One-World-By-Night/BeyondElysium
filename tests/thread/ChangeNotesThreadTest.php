<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Database\Schema;
use BeyondElysium\Models\Change;
use BeyondElysium\Models\Character;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Game_Member;
use BeyondElysium\Services\Change_Engine;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * 1.0.0-review F-032: character_changes had one `notes` column for two people. A player's
 * justification was replaced by the Storyteller's review note the moment the change was
 * reviewed, and every auto-approved change - every import's "Imported from ..." included - lost
 * its note the instant it was created. The reviewer now writes `review_notes`.
 */
class ChangeNotesThreadTest extends WP_UnitTestCase {

	private string $slug = 'thread-change-notes';
	private int $player;
	private int $hst;
	private int $character;

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );

		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'be_games', [
			'slug' => $this->slug, 'name' => $this->slug, 'settings' => '{}',
			'created_by' => 1, 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ),
		] );
		$game_id = (int) Game::find_by_slug( $this->slug )->id;
		$this->player = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		Game_Member::set_role( $game_id, $this->player, 'player' );
		$this->hst = self::factory()->user->create( [ 'role' => 'editor' ] );
		Game_Member::set_role( $game_id, $this->hst, 'hst' );

		$this->character = Character::create( [
			'name' => 'Noted', 'stack_slug' => 'vampire',
			'owner_type' => 'chronicle', 'owner_slug' => $this->slug, 'wp_user_id' => $this->player,
		] );
	}

	private function dispatch( string $method, string $route, array $body ) {
		$request = new WP_REST_Request( $method, $route );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( $body ) );
		return rest_get_server()->dispatch( $request );
	}

	public function test_a_storytellers_review_note_never_replaces_the_players_note(): void {
		wp_set_current_user( $this->player );
		$submitted = $this->dispatch( 'POST', "/be/v1/{$this->slug}/characters/{$this->character}/changes", [
			'change_type' => 'xp_earn',
			'category'    => 'experience',
			'change_data' => [ 'amount' => 2 ],
			'notes'       => 'Ran the door all night',
		] );
		$change_id = (int) $submitted->get_data()->id;

		wp_set_current_user( $this->hst );
		$this->dispatch( 'PUT', "/be/v1/{$this->slug}/changes/{$change_id}", [ 'status' => 'rejected', 'notes' => 'Door duty earns 1, resubmit' ] );

		$change = Change::find( $change_id );
		$this->assertSame( 'Ran the door all night', $change->notes );
		$this->assertSame( 'Door duty earns 1, resubmit', $change->review_notes );
	}

	public function test_an_auto_approved_change_keeps_its_note(): void {
		wp_set_current_user( $this->hst );
		$change_id = Change_Engine::submit( $this->character, [
			'change_type' => 'import_note',
			'category'    => 'import',
			'change_data' => [ 'source_file' => 'noted.gex' ],
			'notes'       => 'Imported from noted.gex.',
		], $this->hst );

		$change = Change::find( $change_id );
		$this->assertSame( 'approved', $change->status );
		$this->assertSame( 'Imported from noted.gex.', $change->notes );
	}

	public function test_the_migration_moves_legacy_review_text_once(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'be_character_changes';

		// A row reviewed before the split: the reviewer's text sits in `notes`.
		$wpdb->insert( $table, [
			'character_id' => $this->character, 'change_type' => 'xp_earn', 'change_data' => '{"amount":1}',
			'status' => 'rejected', 'submitted_by' => $this->player, 'reviewed_by' => $this->hst,
			'submitted_at' => current_time( 'mysql' ), 'reviewed_at' => current_time( 'mysql' ), 'notes' => 'Old review note',
		] );
		$legacy = (int) $wpdb->insert_id;
		delete_option( 'be_review_notes_split' );

		Schema::add_review_notes_to_character_changes();

		$this->assertNull( Change::find( $legacy )->notes );
		$this->assertSame( 'Old review note', Change::find( $legacy )->review_notes );

		// A row reviewed after the split, whose reviewer left no note, keeps the player's note on a re-run.
		$wpdb->insert( $table, [
			'character_id' => $this->character, 'change_type' => 'xp_earn', 'change_data' => '{"amount":1}',
			'status' => 'approved', 'submitted_by' => $this->player, 'reviewed_by' => $this->hst,
			'submitted_at' => current_time( 'mysql' ), 'reviewed_at' => current_time( 'mysql' ), 'notes' => 'Player note',
		] );
		$current = (int) $wpdb->insert_id;

		Schema::add_review_notes_to_character_changes();

		$this->assertSame( 'Player note', Change::find( $current )->notes );
	}
}
