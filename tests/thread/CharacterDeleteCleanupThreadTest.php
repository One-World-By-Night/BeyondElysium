<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Attestation;
use BeyondElysium\Models\Character;
use BeyondElysium\Models\Connection;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Game_Member;
use BeyondElysium\Models\Plot;
use BeyondElysium\Models\Plot_Entry;
use BeyondElysium\Models\Transfer;
use BeyondElysium\Services\Character_Exporter;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * 1.0.0-review F-014. What deleting a character leaves behind.
 *
 * A character's action allocation plot is hidden from other players only by its link to the
 * character, and deleting the character deleted that link: the plot - titled with the character's
 * name, its entries holding the character's exact Background ratings - turned up for every player
 * in the chronicle. A transfer still in motion stayed open forever, and a pending offer's code
 * still verified, so the host could accept a character its home chronicle had deleted.
 *
 * Decided here: a printed or exported sheet's verification code survives as history - it still
 * confirms the chronicle issued that document, and says the sheet no longer matches.
 */
class CharacterDeleteCleanupThreadTest extends WP_UnitTestCase {

	private string $slug = 'thread-delete-cleanup';
	private int $game_id;

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );
		$this->game_id = (int) Game::create( [ 'slug' => $this->slug, 'name' => 'Delete Cleanup' ] );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
	}

	private function character( string $name = 'Doomed Neonate', ?int $owner = null ): int {
		return Character::create( [
			'name' => $name, 'stack_slug' => 'vampire', 'owner_type' => 'chronicle',
			'owner_slug' => $this->slug, 'status' => 'active', 'wp_user_id' => $owner,
		] );
	}

	private function outbound( int $character_id, string $state ): object {
		$export = Character_Exporter::export( $character_id, [ 'as_transfer' => true ] );
		$id     = Transfer::create( [
			'character_uuid' => Character::find( $character_id )->uuid, 'character_id' => $character_id,
			'direction' => 'outbound', 'state' => $state, 'home_slug' => $this->slug, 'home_site' => home_url(),
			'home_chronicle' => 'Delete Cleanup', 'attestation_id' => $export['attestation_id'],
			'payload_hash' => hash( 'sha256', $export['xml'] ), 'initiated_by' => get_current_user_id(),
		] );
		return Transfer::find( $id );
	}

	public function test_a_pending_transfer_closes_and_its_code_stops_verifying(): void {
		$id       = $this->character();
		$transfer = $this->outbound( $id, 'pending' );

		Character::delete( $id );

		$this->assertSame( 'declined', Transfer::find( (int) $transfer->id )->state );
		$this->assertNotNull( Attestation::find( (int) $transfer->attestation_id )->revoked_at, 'a host can no longer accept the offer' );
	}

	public function test_a_character_abroad_is_released_from_home(): void {
		$id       = $this->character();
		$transfer = $this->outbound( $id, 'abroad' );

		Character::delete( $id );

		$this->assertSame( 'released', Transfer::find( (int) $transfer->id )->state );
		$this->assertNull( Transfer::find_open( (string) $transfer->character_uuid, 'outbound' ) );
	}

	public function test_deleting_a_visiting_copy_ends_the_visit(): void {
		$id    = $this->character( 'Visiting Copy' );
		$visit = Transfer::create( [
			'character_uuid' => Character::find( $id )->uuid, 'character_id' => $id, 'direction' => 'inbound',
			'state' => 'visiting', 'home_slug' => 'elsewhere', 'home_site' => 'https://elsewhere.example',
			'home_chronicle' => 'Elsewhere', 'host_slug' => $this->slug, 'host_site' => home_url(),
			'payload_hash' => str_repeat( 'a', 64 ), 'initiated_by' => get_current_user_id(),
		] );

		Character::delete( $id );

		$this->assertSame( 'sent_home', Transfer::find( $visit )->state );
	}

	public function test_a_deleted_characters_allocation_plot_is_not_left_for_other_players_to_read(): void {
		$doomed = $this->character( 'Doomed Neonate', self::factory()->user->create() );
		$plot   = (int) Plot::create( [ 'game_id' => $this->game_id, 'title' => 'Action Allocation - Doomed Neonate', 'initiated_by' => 'player' ] );
		Connection::create( [ 'game_id' => $this->game_id, 'source_type' => 'plot', 'source_id' => $plot, 'target_type' => 'character', 'target_id' => $doomed, 'label' => 'apr_actor' ] );
		Plot_Entry::create( [ 'plot_id' => $plot, 'author_id' => $doomed, 'entry_type' => 'action', 'content' => 'Herd 3, Resources 2' ] );
		$bystander = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		Game_Member::set_role( $this->game_id, $bystander, 'player' );

		Character::delete( $doomed );

		$this->assertNull( Plot::find( $plot ) );
		wp_set_current_user( $bystander );
		$list = rest_get_server()->dispatch( new WP_REST_Request( 'GET', "/be/v1/{$this->slug}/plots" ) );
		$this->assertStringNotContainsString( 'Doomed Neonate', wp_json_encode( $list->get_data() ) );
	}

	public function test_a_printed_sheets_code_still_confirms_the_document_was_issued(): void {
		$id   = $this->character();
		$code = Character_Exporter::export( $id, [ 'verify' => true ] )['short_code'];

		Character::delete( $id );

		$response = rest_get_server()->dispatch( new WP_REST_Request( 'GET', "/be/v1/verify/{$code}" ) );
		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $response->get_data()['valid'] );
		$this->assertFalse( $response->get_data()['still_matches']['sheet'] );
	}
}
