<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Change;
use BeyondElysium\Models\Character;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Game_Member;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * A player removing a discipline (or any tiered_power/trait_list entry) from their own
 * character goes through the same pending-change pipeline as adding one - `remove_trait` is
 * a real member of `Change_Validator::REST_CHANGE_TYPES`, and `TraitListEditor.tsx`/
 * `TieredPowerEditor.tsx` mark a row `_removed` locally rather than deleting it outright;
 * `computeChanges.ts` turns that into a `remove_trait` entry on submit, never a direct write.
 *
 * This pins the two things that make that safe: the removal sits `pending` and the sheet is
 * completely unaffected until a Storyteller reviews it, and rejecting it leaves the
 * discipline exactly where it was.
 */
class TraitRemovalApprovalThreadTest extends WP_UnitTestCase {

	private string $game_slug = 'thread-trait-removal';
	private int $game_id;
	private int $player_id;
	private int $character_id;
	private int $hst_id;

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );

		$this->game_id = (int) Game::create( [ 'slug' => $this->game_slug, 'name' => 'Trait Removal' ] );

		$this->player_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		Game_Member::set_role( $this->game_id, $this->player_id, 'player' );

		$this->character_id = (int) Character::create( [
			'name' => 'Rabble Rouser', 'stack_slug' => 'vampire', 'owner_type' => 'chronicle',
			'owner_slug' => $this->game_slug, 'wp_user_id' => $this->player_id, 'created_by' => $this->player_id,
			'sheet_data' => [
				'vampire-identity'     => [ 'Clan' => 'Brujah' ],
				'vampire-disciplines'  => [ [ 'name' => 'Celerity', 'level' => 1 ] ],
				'met-merits'           => [ [ 'name' => 'Iron Will' ] ],
			],
		] );

		$this->hst_id = self::factory()->user->create( [ 'role' => 'editor' ] );
		Game_Member::set_role( $this->game_id, $this->hst_id, 'hst' );
	}

	private function dispatch( WP_REST_Request $request ) {
		return rest_get_server()->dispatch( $request );
	}

	private function propose_removal( string $block_slug, array $trait ): int {
		wp_set_current_user( $this->player_id );
		$request = new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/characters/{$this->character_id}/changes" );
		$request->set_param( 'change_type', 'remove_trait' );
		$request->set_param( 'category', $block_slug );
		$request->set_param( 'change_data', [ 'block_slug' => $block_slug, 'trait' => $trait ] );
		return (int) $this->dispatch( $request )->get_data()->id;
	}

	private function discipline_names(): array {
		$sheet = Character::find( $this->character_id )->sheet_data;
		return array_column( $sheet['vampire-disciplines'] ?? [], 'name' );
	}

	public function test_removing_a_discipline_stays_pending_and_leaves_the_sheet_untouched(): void {
		wp_set_current_user( $this->player_id );
		$request = new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/characters/{$this->character_id}/changes" );
		$request->set_param( 'change_type', 'remove_trait' );
		$request->set_param( 'category', 'vampire-disciplines' );
		$request->set_param( 'change_data', [ 'block_slug' => 'vampire-disciplines', 'trait' => [ 'name' => 'Celerity', 'level' => 1 ] ] );
		$response = $this->dispatch( $request );

		$this->assertSame( 201, $response->get_status() );
		$change = (array) $response->get_data();
		$this->assertSame( 'remove_trait', $change['change_type'] );
		$this->assertSame( 'pending', $change['status'] );

		$this->assertSame(
			[ 'Celerity' ],
			$this->discipline_names(),
			'A pending removal must not touch the sheet until a Storyteller approves it.'
		);
	}

	public function test_a_player_cannot_approve_their_own_removal(): void {
		$change_id = $this->propose_removal( 'vampire-disciplines', [ 'name' => 'Celerity', 'level' => 1 ] );

		wp_set_current_user( $this->player_id );
		$request = new WP_REST_Request( 'PUT', "/be/v1/{$this->game_slug}/changes/{$change_id}" );
		$request->set_param( 'status', 'approved' );
		$response = $this->dispatch( $request );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( [ 'Celerity' ], $this->discipline_names() );
	}

	public function test_approving_a_removal_actually_removes_it_from_the_sheet(): void {
		$change_id = $this->propose_removal( 'vampire-disciplines', [ 'name' => 'Celerity', 'level' => 1 ] );

		wp_set_current_user( $this->hst_id );
		$request = new WP_REST_Request( 'PUT', "/be/v1/{$this->game_slug}/changes/{$change_id}" );
		$request->set_param( 'status', 'approved' );
		$response = $this->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( [], $this->discipline_names() );
		$this->assertSame( 'approved', (string) Change::find( $change_id )->status );
	}

	public function test_rejecting_a_removal_leaves_it_exactly_where_it_was(): void {
		$change_id = $this->propose_removal( 'vampire-disciplines', [ 'name' => 'Celerity', 'level' => 1 ] );

		wp_set_current_user( $this->hst_id );
		$request = new WP_REST_Request( 'PUT', "/be/v1/{$this->game_slug}/changes/{$change_id}" );
		$request->set_param( 'status', 'rejected' );
		$request->set_param( 'notes', 'Not yet.' );
		$response = $this->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( [ 'Celerity' ], $this->discipline_names() );
		$this->assertSame( 'rejected', (string) Change::find( $change_id )->status );
	}

	/** The same pipeline for an ordinary trait_list entry, not only a tiered_power. */
	public function test_removing_a_trait_list_entry_also_goes_through_approval(): void {
		$change_id = $this->propose_removal( 'met-merits', [ 'name' => 'Iron Will' ] );

		$sheet_before = Character::find( $this->character_id )->sheet_data;
		$this->assertSame( [ 'Iron Will' ], array_column( $sheet_before['met-merits'], 'name' ) );

		wp_set_current_user( $this->hst_id );
		$request = new WP_REST_Request( 'PUT', "/be/v1/{$this->game_slug}/changes/{$change_id}" );
		$request->set_param( 'status', 'approved' );
		$this->dispatch( $request );

		$sheet_after = Character::find( $this->character_id )->sheet_data;
		$this->assertSame( [], $sheet_after['met-merits'] );
	}
}
