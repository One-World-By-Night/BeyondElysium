<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Character;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Game_Member;
use BeyondElysium\Models\Plot_Entry;
use BeyondElysium\Services\Action_Allocator;
use BeyondElysium\Services\Background_Ledger;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * A Storyteller cannot adjudicate, rewrite or clear another chronicle's background use; their own chronicle's
 * background use still takes a result.
 */
class CrossChronicleLedgerEntryThreadTest extends WP_UnitTestCase {

	private string $home = 'thread-ledger-home';
	private string $other = 'thread-ledger-other';
	private int $storyteller;
	private int $home_entry;
	private int $other_entry;

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );

		Game::create( [ 'slug' => $this->home, 'name' => 'Ledger Home' ] );
		Game::create( [ 'slug' => $this->other, 'name' => 'Ledger Other' ] );
		$this->storyteller = self::factory()->user->create( [ 'role' => 'editor' ] );
		Game_Member::set_role( (int) Game::find_by_slug( $this->home )->id, $this->storyteller, 'hst' );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$this->home_entry  = $this->use_for( $this->home, 'Home Neonate', 'Scouting the docks' );
		$this->other_entry = $this->use_for( $this->other, 'Other Neonate', 'Meeting the Prince in secret' );
		wp_set_current_user( $this->storyteller );
	}

	private function use_for( string $slug, string $name, string $text ): int {
		$character = Character::create( [ 'name' => $name, 'stack_slug' => 'vampire', 'owner_type' => 'chronicle', 'owner_slug' => $slug, 'status' => 'active' ] );
		return (int) Background_Ledger::record( $character, '2026-10-01', [ 'name' => Action_Allocator::PERSONAL_NAME, 'text' => $text, 'cost' => 1 ] )['id'];
	}

	private function send( string $method, string $slug, int $id, array $body = [] ) {
		$request = new WP_REST_Request( $method, "/be/v1/{$slug}/background-uses/{$id}" );
		if ( $body ) {
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( wp_json_encode( $body ) );
		}
		return rest_get_server()->dispatch( $request );
	}

	private function content_of( int $entry_id ): array {
		return json_decode( (string) Plot_Entry::find( $entry_id )->content, true );
	}

	public function test_a_storyteller_cannot_adjudicate_or_rewrite_another_chronicles_background_use(): void {
		$response = $this->send( 'PUT', $this->home, $this->other_entry, [ 'result' => 'Failed - forged', 'text' => 'Rewritten', 'cost' => 9 ] );

		$this->assertSame( 404, $response->get_status() );
		$content = $this->content_of( $this->other_entry );
		$this->assertSame( '', $content['result'] );
		$this->assertSame( 'Meeting the Prince in secret', $content['text'] );
		$this->assertSame( 1, $content['cost'] );
	}

	public function test_a_storyteller_cannot_clear_another_chronicles_background_use(): void {
		$response = $this->send( 'DELETE', $this->home, $this->other_entry );

		$this->assertSame( 404, $response->get_status() );
		$this->assertNotNull( Plot_Entry::find( $this->other_entry ) );
	}

	public function test_their_own_chronicles_background_use_still_takes_a_result(): void {
		$response = $this->send( 'PUT', $this->home, $this->home_entry, [ 'result' => 'Found the smugglers' ] );

		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$this->assertSame( 'Found the smugglers', $this->content_of( $this->home_entry )['result'] );
	}
}
