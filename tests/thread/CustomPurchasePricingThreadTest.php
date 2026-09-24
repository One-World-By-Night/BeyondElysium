<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Change;
use BeyondElysium\Models\Character;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Game_Member;
use BeyondElysium\Models\Schema_Block;
use BeyondElysium\Services\Catalog_Cutover;
use BeyondElysium\Services\Change_Engine;
use BeyondElysium\Services\Cost_Engine;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * 1.3.3 E2-E7: a purchase with no catalog price is held for a Storyteller's number instead of
 * being approved at a stored 0. Real dispatch through the REST server, against a real character
 * and the real seeded catalog, for the whole path: a player submits homebrew, it is held with
 * `cost_pending`, the preview says so, the Storyteller prices it at approval, the right total is
 * deducted and the row carries the price the Point Audit reads back.
 *
 * `vampire-backgrounds` is used for the trait list because it is a section of the vampire stack
 * both before and after the catalog cutover; a flaw's slug is resolved through
 * `Catalog_Cutover::live_slug()` for the same reason.
 */
class CustomPurchasePricingThreadTest extends WP_UnitTestCase {

	private string $game_slug = 'thread-test-custom-pricing';
	private int $game_id;
	private int $character_id;
	private int $player_id;
	private int $st_id;

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );

		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'be_games', [
			'slug' => $this->game_slug, 'name' => 'Thread Test Custom Pricing',
			'created_by' => 1, 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ),
		] );
		$this->game_id = (int) $wpdb->insert_id;

		$this->player_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		Game_Member::set_role( $this->game_id, $this->player_id, 'player' );
		$this->st_id = self::factory()->user->create( [ 'role' => 'administrator' ] );

		$this->character_id = Character::create( [
			'name' => 'Pricing Test Character', 'stack_slug' => 'vampire',
			'owner_type' => 'chronicle', 'owner_slug' => $this->game_slug, 'wp_user_id' => $this->player_id,
			'sheet_data' => [ 'vampire-backgrounds' => [] ],
		] );
		Character::update_xp( $this->character_id, 20, 20 );
	}

	private function dispatch( WP_REST_Request $request ) {
		return rest_get_server()->dispatch( $request );
	}

	/** @param array<string,mixed> $trait @param array<string,mixed> $extra */
	private function submit( int $as, string $block, array $trait, array $extra = [] ) {
		wp_set_current_user( $as );
		$request = new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/characters/{$this->character_id}/changes" );
		$request->set_param( 'change_type', 'add_trait' );
		$request->set_param( 'category', $block );
		$request->set_param( 'change_data', [ 'block_slug' => $block, 'trait' => $trait ] + $extra );
		return $this->dispatch( $request );
	}

	/** @param array<int,array<string,mixed>> $changes */
	private function preview( int $as, array $changes ) {
		wp_set_current_user( $as );
		$request = new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/characters/{$this->character_id}/preview-changes" );
		$request->set_param( 'changes', $changes );
		return $this->dispatch( $request );
	}

	private function homebrew( int $count = 3, array $extra = [] ): array {
		return [ 'name' => 'Occult Library', 'count' => $count, 'custom' => true ] + $extra;
	}

	// --- E2: a purchase with no price is held for one ------------------------------------

	public function test_a_players_homebrew_is_held_with_cost_pending_and_costs_nothing_yet(): void {
		$response = $this->submit( $this->player_id, 'vampire-backgrounds', $this->homebrew() );
		$change   = $response->get_data();

		$this->assertSame( 201, $response->get_status() );
		$this->assertSame( 'pending', $change->status );
		$this->assertTrue( $change->change_data['cost_pending'] );
		$this->assertSame( 0.0, (float) $change->xp_cost );
	}

	public function test_a_price_a_player_puts_on_their_own_homebrew_is_dropped(): void {
		$response = $this->submit( $this->player_id, 'vampire-backgrounds', $this->homebrew( 2, [ 'chosen_cost' => 0 ] ) );
		$change   = $response->get_data();

		$this->assertSame( 201, $response->get_status() );
		$this->assertArrayNotHasKey( 'chosen_cost', $change->change_data['trait'], 'a player never prices their own homebrew' );
		$this->assertTrue( $change->change_data['cost_pending'] );
	}

	public function test_a_managers_priced_homebrew_needs_no_prompt(): void {
		$response = $this->submit( $this->st_id, 'vampire-backgrounds', $this->homebrew( 3, [ 'chosen_cost' => 2 ] ) );
		$change   = $response->get_data();

		$this->assertSame( 201, $response->get_status() );
		$this->assertSame( 6.0, (float) $change->xp_cost, '2 XP a dot, three dots' );
		$this->assertArrayNotHasKey( 'cost_pending', $change->change_data );
		$this->assertSame( 2, $change->change_data['trait']['chosen_cost'] );
		$this->assertSame( 'pending', $change->status, 'a custom entry always needs a Storyteller, priced or not' );
	}

	public function test_a_managers_homebrew_with_no_price_is_held_too(): void {
		$change = $this->submit( $this->st_id, 'vampire-backgrounds', $this->homebrew() )->get_data();

		$this->assertTrue( $change->change_data['cost_pending'] );
	}

	public function test_a_client_cannot_set_cost_pending_on_a_purchase_that_has_a_price(): void {
		$response = $this->submit( $this->st_id, 'vampire-backgrounds', $this->homebrew( 1, [ 'chosen_cost' => 4 ] ), [ 'cost_pending' => true ] );

		$this->assertArrayNotHasKey( 'cost_pending', $response->get_data()->change_data );
	}

	public function test_homebrew_is_held_even_on_a_chronicle_that_auto_approves(): void {
		Game::update( $this->game_slug, [ 'settings' => [ 'auto_approve' => true ] ] );

		$change = $this->submit( $this->player_id, 'vampire-backgrounds', $this->homebrew() )->get_data();

		$this->assertSame( 'pending', $change->status );
		$this->assertTrue( $change->change_data['cost_pending'] );
		$this->assertSame( 20, (int) Character::find( $this->character_id )->xp_unspent, 'nothing was deducted' );
	}

	// --- the preview ---------------------------------------------------------------------

	public function test_the_preview_says_a_homebrew_purchase_is_unpriced_rather_than_free(): void {
		$response = $this->preview( $this->player_id, [
			[ 'change_type' => 'add_trait', 'change_data' => [ 'block_slug' => 'vampire-backgrounds', 'trait' => $this->homebrew() ] ],
		] );
		$result = $response->get_data()['results'][0];

		$this->assertSame( 200, $response->get_status() );
		$this->assertFalse( $result['priced'] );
		$this->assertSame( 'custom_no_catalog_entry', $result['unpriced_reason'] );
		$this->assertSame( 0, $result['xp_cost'] );
		$this->assertSame( 20, $response->get_data()['running_xp_unspent'], 'an unpriced purchase does not move the running balance' );
	}

	public function test_the_preview_of_a_catalog_purchase_is_priced_as_before(): void {
		$iron_will = Catalog_Cutover::live_slug( 'vampire', 'met-merits' );
		$results   = $this->preview( $this->player_id, [
			[ 'change_type' => 'add_trait', 'change_data' => [ 'block_slug' => $iron_will, 'trait' => [ 'name' => 'Iron Will' ] ] ],
		] )->get_data()['results'];

		$this->assertTrue( $results[0]['priced'] );
		$this->assertNull( $results[0]['unpriced_reason'] );
		$this->assertGreaterThan( 0, $results[0]['xp_cost'] );
	}

	public function test_the_preview_prices_a_managers_homebrew(): void {
		$result = $this->preview( $this->st_id, [
			[ 'change_type' => 'add_trait', 'change_data' => [ 'block_slug' => 'vampire-backgrounds', 'trait' => $this->homebrew( 3, [ 'chosen_cost' => 2 ] ) ] ],
		] )->get_data()['results'][0];

		$this->assertTrue( $result['priced'] );
		$this->assertSame( 6, $result['xp_cost'] );
	}

	public function test_a_refused_preview_row_keeps_its_shape(): void {
		$result = $this->preview( $this->player_id, [
			[ 'change_type' => 'add_trait', 'change_data' => [ 'block_slug' => 'no-such-block', 'trait' => $this->homebrew() ] ],
		] )->get_data()['results'][0];

		$this->assertArrayHasKey( 'error', $result );
		$this->assertTrue( $result['priced'] );
	}

	// --- E3: the Storyteller sets the price at approval ---------------------------------

	private function pending_homebrew( int $count = 3 ): int {
		$change = $this->submit( $this->player_id, 'vampire-backgrounds', $this->homebrew( $count ) )->get_data();
		$this->assertTrue( $change->change_data['cost_pending'], 'precondition: the purchase is waiting for a price' );
		return (int) $change->id;
	}

	private function unspent(): int {
		return (int) Character::find( $this->character_id )->xp_unspent;
	}

	/** @return array<int,array<string,mixed>> */
	private function held( string $block ): array {
		return (array) ( Character::find( $this->character_id )->sheet_data[ $block ] ?? [] );
	}

	public function test_a_purchase_waiting_for_a_price_is_a_storyteller_decision_even_where_a_rule_would_auto_approve(): void {
		Game::update( $this->game_slug, [ 'settings' => [ 'auto_approve' => true ] ] );
		$character = Character::find( $this->character_id );
		$catalog   = [ 'block_slug' => Catalog_Cutover::live_slug( 'vampire', 'met-merits' ), 'trait' => [ 'name' => 'Iron Will' ] ];

		$plain   = Change_Engine::resolve_approval_level( $character, (object) [ 'change_type' => 'add_trait', 'change_data' => $catalog ] );
		$pending = Change_Engine::resolve_approval_level( $character, (object) [ 'change_type' => 'add_trait', 'change_data' => $catalog + [ 'cost_pending' => true ] ] );

		$this->assertSame( 'auto', $plain['level'], 'precondition: this chronicle approves a catalog purchase on its own' );
		$this->assertSame( 'st', $pending['level'] );
	}

	public function test_approving_without_a_price_is_refused_and_writes_nothing(): void {
		$id = $this->pending_homebrew();

		$this->assertFalse( Change_Engine::approve( $id, $this->st_id, null ) );
		$this->assertFalse( Change_Engine::approve( $id, $this->st_id, null, null, -1 ), 'a price below 0 is not a price' );
		$this->assertFalse( Change_Engine::approve( $id, $this->st_id, null, null, 501 ), 'nor one above the cap' );

		$this->assertSame( 'pending', Change::find( $id )->status );
		$this->assertSame( [], $this->held( 'vampire-backgrounds' ) );
		$this->assertSame( 20, $this->unspent() );
	}

	public function test_approving_with_a_price_deducts_the_total_and_the_row_carries_the_price(): void {
		$id = $this->pending_homebrew( 3 );

		$this->assertTrue( Change_Engine::approve( $id, $this->st_id, null, null, 2 ) );

		$this->assertSame( 14, $this->unspent(), '2 XP a dot, three dots: 6 of the 20' );
		$row = $this->held( 'vampire-backgrounds' )[0];
		$this->assertSame( 2, $row['chosen_cost'] );
		$this->assertSame( 3, $row['count'] );
		$this->assertTrue( $row['custom'] );

		$change = Change::find( $id );
		$this->assertSame( 'approved', $change->status );
		$this->assertSame( 6.0, (float) $change->xp_cost );
		$this->assertSame( 2, $change->change_data['trait']['chosen_cost'], 'the record shows the price that was set' );
		$this->assertArrayNotHasKey( 'cost_pending', $change->change_data );

		$definition = Schema_Block::find_by_slug( 'vampire-backgrounds' )->definition;
		$audit      = Cost_Engine::price_held_trait_list_item( $definition, $row );
		$this->assertSame( 6, $audit['xp'], 'the Point Audit reads back exactly what was charged' );
		$this->assertSame( 'chosen_cost', $audit['basis'] );
	}

	public function test_a_price_of_zero_approves_a_purchase_free_and_still_records_it(): void {
		$id = $this->pending_homebrew();

		$this->assertTrue( Change_Engine::approve( $id, $this->st_id, null, null, 0 ) );

		$this->assertSame( 20, $this->unspent() );
		$this->assertSame( 0, $this->held( 'vampire-backgrounds' )[0]['chosen_cost'], 'free on purpose, and the sheet says so - not the silent zero it used to be' );
		$this->assertSame( 0.0, (float) Change::find( $id )->xp_cost );
	}

	public function test_a_price_set_on_a_change_that_already_has_one_is_ignored(): void {
		$iron_will = Catalog_Cutover::live_slug( 'vampire', 'met-merits' );
		$change    = $this->submit( $this->st_id, $iron_will, [ 'name' => 'Iron Will' ] )->get_data();
		$this->assertArrayNotHasKey( 'cost_pending', $change->change_data );
		$catalog_cost = (int) $change->xp_cost;

		$this->assertTrue( Change_Engine::approve( (int) $change->id, $this->st_id, null, null, 9 ) );

		$this->assertSame( 20 - $catalog_cost, $this->unspent(), 'the catalog price stands; a number offered for a priced change is not read' );
	}

	public function test_a_managers_priced_homebrew_is_approved_at_that_price_with_no_prompt(): void {
		$change = $this->submit( $this->st_id, 'vampire-backgrounds', $this->homebrew( 3, [ 'chosen_cost' => 2 ] ) )->get_data();

		$this->assertTrue( Change_Engine::approve( (int) $change->id, $this->st_id, null ) );

		$this->assertSame( 14, $this->unspent() );
		$this->assertSame( 2, $this->held( 'vampire-backgrounds' )[0]['chosen_cost'] );
	}

	public function test_a_homebrew_flaw_records_its_price_and_grants_nothing(): void {
		$flaws = Catalog_Cutover::live_slug( 'vampire', 'met-flaws' );
		$id    = (int) $this->submit( $this->player_id, $flaws, [ 'name' => 'Odd Curse', 'count' => 2, 'custom' => true ] )->get_data()->id;

		$this->assertTrue( Change_Engine::approve( $id, $this->st_id, null, null, 3 ) );

		$this->assertSame( 20, $this->unspent(), 'a negative block never deducts - and, as today, never refunds' );
		$this->assertSame( 3, $this->held( $flaws )[0]['chosen_cost'] );
		$this->assertSame( -6.0, (float) Change::find( $id )->xp_cost, 'the record keeps the sign a flaw always had' );
	}

	public function test_raising_an_unpriced_custom_row_prices_only_the_new_dots(): void {
		Character::update_sheet_data( $this->character_id, [ 'vampire-backgrounds' => [ [ 'name' => 'Occult Library', 'count' => 3, 'custom' => true ] ] ] );
		$response = $this->modify( 'vampire-backgrounds', [ 'name' => 'Occult Library', 'count' => 5, 'custom' => true ] );
		$change   = $response->get_data();
		$this->assertTrue( $change->change_data['cost_pending'] );

		$this->assertTrue( Change_Engine::approve( (int) $change->id, $this->st_id, null, null, 2 ) );

		$this->assertSame( 16, $this->unspent(), 'two new dots at 2 XP: the three the row already had stay as they were' );
		$row = $this->held( 'vampire-backgrounds' )[0];
		$this->assertSame( 5, $row['count'] );
		$this->assertSame( 2, $row['chosen_cost'] );
	}

	public function test_raising_a_row_that_already_has_a_price_needs_no_prompt(): void {
		Character::update_sheet_data( $this->character_id, [ 'vampire-backgrounds' => [ [ 'name' => 'Occult Library', 'count' => 3, 'custom' => true, 'chosen_cost' => 2 ] ] ] );

		$change = $this->modify( 'vampire-backgrounds', [ 'name' => 'Occult Library', 'count' => 5, 'custom' => true ] )->get_data();

		$this->assertArrayNotHasKey( 'cost_pending', $change->change_data );
		$this->assertSame( 4.0, (float) $change->xp_cost );
		$this->assertTrue( Change_Engine::approve( (int) $change->id, $this->st_id, null ) );
		$this->assertSame( 16, $this->unspent() );
	}

	private function modify( string $block, array $trait ) {
		wp_set_current_user( $this->player_id );
		$request = new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/characters/{$this->character_id}/changes" );
		$request->set_param( 'change_type', 'modify_trait' );
		$request->set_param( 'category', $block );
		$request->set_param( 'change_data', [ 'block_slug' => $block, 'trait' => $trait ] );
		return $this->dispatch( $request );
	}

	public function test_a_custom_power_is_priced_flat_and_its_row_carries_the_price(): void {
		// A custom family: the route refuses a made-up pick under a family the catalog does carry.
		$pick = [ 'name' => 'My Own Path', 'power_name' => 'A Trick Nobody Printed', 'custom' => true ];
		$id   = (int) $this->submit( $this->player_id, 'vampire-disciplines', $pick )->get_data()->id;

		$this->assertTrue( Change_Engine::approve( $id, $this->st_id, null, null, 5 ) );

		$this->assertSame( 15, $this->unspent(), 'a pick is one price, whatever it is called' );
		$row = $this->held( 'vampire-disciplines' )[0];
		$this->assertSame( 5, $row['chosen_cost'] );
		$definition = Schema_Block::find_by_slug( 'vampire-disciplines' )->definition;
		$audit      = Cost_Engine::price_held_tiered_power( $definition, $row, true );
		$this->assertSame( 5, $audit['xp'], 'the Point Audit reads back what was charged' );
	}

	public function test_raising_a_custom_familys_level_adds_the_new_price_to_the_one_the_row_holds(): void {
		Character::update_sheet_data( $this->character_id, [ 'vampire-disciplines' => [ [ 'name' => 'Dur-An-Ki: Path of Spirit', 'level' => 3, 'custom' => true, 'chosen_cost' => 4 ] ] ] );
		$change = $this->modify( 'vampire-disciplines', [ 'name' => 'Dur-An-Ki: Path of Spirit', 'level' => 4, 'custom' => true ] )->get_data();
		$this->assertTrue( $change->change_data['cost_pending'] );

		$this->assertTrue( Change_Engine::approve( (int) $change->id, $this->st_id, null, null, 2 ) );

		$this->assertSame( 18, $this->unspent(), 'only the new purchase is deducted' );
		$row = $this->held( 'vampire-disciplines' )[0];
		$this->assertSame( 4, $row['level'] );
		$this->assertSame( 6, $row['chosen_cost'], 'the row now stands for everything paid on it, so the audit agrees' );
	}

	// --- E4: the review route takes the price ------------------------------------------

	/** @param array<string,mixed> $params */
	private function review( int $change_id, array $params ) {
		wp_set_current_user( $this->st_id );
		$request = new WP_REST_Request( 'PUT', "/be/v1/{$this->game_slug}/changes/{$change_id}" );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		return $this->dispatch( $request );
	}

	public function test_the_route_refuses_an_approval_with_no_price_and_says_what_to_do(): void {
		$id = $this->pending_homebrew();

		$response = $this->review( $id, [ 'status' => 'approved' ] );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'cost_required', $response->as_error()->get_error_code() );
		$this->assertStringContainsString( '0 is allowed', $response->as_error()->get_error_message() );
		$this->assertSame( 'pending', Change::find( $id )->status );
		$this->assertSame( 20, $this->unspent() );
	}

	public function test_the_route_approves_at_the_price_it_is_given(): void {
		$id = $this->pending_homebrew( 3 );

		$response = $this->review( $id, [ 'status' => 'approved', 'xp_cost' => 2 ] );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'approved', $response->get_data()->status );
		$this->assertSame( 6.0, (float) $response->get_data()->xp_cost );
		$this->assertSame( 14, $this->unspent() );
	}

	public function test_a_price_of_zero_is_accepted_by_the_route(): void {
		$id = $this->pending_homebrew();

		$this->assertSame( 200, $this->review( $id, [ 'status' => 'approved', 'xp_cost' => 0 ] )->get_status() );
		$this->assertSame( 20, $this->unspent() );
	}

	/** @return array<string,array{0:mixed,1:string}> */
	public static function unusable_prices(): array {
		return [
			'negative'     => [ -1, 'invalid_param' ],
			'over the cap' => [ 501, 'invalid_param' ],
			'not a number' => [ 'two', 'invalid_param' ],
			'a fraction'   => [ 2.5, 'invalid_param' ],
			'a blank'      => [ '', 'cost_required' ],
		];
	}

	/** @dataProvider unusable_prices */
	public function test_a_price_that_is_not_a_whole_number_from_zero_to_five_hundred_is_refused( $price, string $code ): void {
		$id = $this->pending_homebrew();

		$response = $this->review( $id, [ 'status' => 'approved', 'xp_cost' => $price ] );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( $code, $response->as_error()->get_error_code() );
		$this->assertSame( 'pending', Change::find( $id )->status );
	}

	public function test_the_highest_price_and_a_number_that_arrives_as_text_are_accepted(): void {
		$first  = $this->pending_homebrew( 1 );
		$this->assertSame( 200, $this->review( $first, [ 'status' => 'approved', 'xp_cost' => '3' ] )->get_status(), 'a form field arrives as text' );
		$this->assertSame( 17, $this->unspent() );

		Character::update_xp( $this->character_id, 0, 1000 );
		$second = (int) $this->submit( $this->player_id, 'vampire-backgrounds', [ 'name' => 'Wine Cellar', 'count' => 1, 'custom' => true ] )->get_data()->id;
		$this->assertSame( 200, $this->review( $second, [ 'status' => 'approved', 'xp_cost' => 500 ] )->get_status() );
	}

	public function test_a_price_sent_with_a_rejection_is_ignored(): void {
		$id = $this->pending_homebrew();

		$response = $this->review( $id, [ 'status' => 'rejected', 'notes' => 'No.' ] );

		$this->assertSame( 200, $response->get_status(), 'rejecting never needs a price' );
		$this->assertSame( 'rejected', Change::find( $id )->status );
		$this->assertSame( 20, $this->unspent() );
	}

	public function test_a_price_offered_for_a_change_that_already_has_one_is_ignored_by_the_route(): void {
		$iron_will = Catalog_Cutover::live_slug( 'vampire', 'met-merits' );
		$change    = $this->submit( $this->st_id, $iron_will, [ 'name' => 'Iron Will' ] )->get_data();

		$response = $this->review( (int) $change->id, [ 'status' => 'approved', 'xp_cost' => 9 ] );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( (float) $change->xp_cost, (float) $response->get_data()->xp_cost, 'the catalog price stands' );
	}

	public function test_an_ordinary_approval_asks_nothing_about_price(): void {
		$iron_will = Catalog_Cutover::live_slug( 'vampire', 'met-merits' );
		$change    = $this->submit( $this->player_id, $iron_will, [ 'name' => 'Iron Will' ] )->get_data();

		$response = $this->review( (int) $change->id, [ 'status' => 'approved' ] );

		$this->assertSame( 200, $response->get_status(), 'a price is only ever asked for when there is none' );
	}

	public function test_the_review_token_is_still_honoured_when_a_price_is_given(): void {
		$id = $this->pending_homebrew();

		$response = $this->review( $id, [ 'status' => 'approved', 'xp_cost' => 2, 'review_token' => 'not-the-token' ] );

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'pending', Change::find( $id )->status );
	}

	// --- E5: the queue says what a price covers -----------------------------------------

	/** @return array<int,object> The queue rows, by change id. */
	private function queue(): array {
		wp_set_current_user( $this->st_id );
		$request = new WP_REST_Request( 'GET', "/be/v1/{$this->game_slug}/changes" );
		$rows    = [];
		foreach ( $this->dispatch( $request )->get_data() as $row ) {
			$rows[ (int) $row->id ] = $row;
		}
		return $rows;
	}

	public function test_the_queue_says_a_waiting_trait_list_change_is_priced_per_dot_and_over_how_many(): void {
		$id = $this->pending_homebrew( 3 );

		$row = $this->queue()[ $id ];

		$this->assertSame( [ 'per' => 'dot', 'units' => 3, 'negative' => false ], $row->cost_units );
	}

	public function test_the_queue_counts_only_the_new_dots_of_a_raise(): void {
		Character::update_sheet_data( $this->character_id, [ 'vampire-backgrounds' => [ [ 'name' => 'Occult Library', 'count' => 3, 'custom' => true ] ] ] );
		$id = (int) $this->modify( 'vampire-backgrounds', [ 'name' => 'Occult Library', 'count' => 5, 'custom' => true ] )->get_data()->id;

		$this->assertSame( 2, $this->queue()[ $id ]->cost_units['units'] );
	}

	public function test_the_queue_prices_a_waiting_power_as_one_pick(): void {
		$id = (int) $this->submit( $this->player_id, 'vampire-disciplines', [ 'name' => 'My Own Path', 'level' => 3, 'custom' => true ] )->get_data()->id;

		$this->assertSame( [ 'per' => 'pick', 'units' => 1, 'negative' => false ], $this->queue()[ $id ]->cost_units );
	}

	public function test_the_queue_marks_a_waiting_flaw_as_negative(): void {
		$flaws = Catalog_Cutover::live_slug( 'vampire', 'met-flaws' );
		$id    = (int) $this->submit( $this->player_id, $flaws, [ 'name' => 'Odd Curse', 'count' => 2, 'custom' => true ] )->get_data()->id;

		$this->assertTrue( $this->queue()[ $id ]->cost_units['negative'] );
	}

	public function test_a_change_that_already_has_a_price_carries_no_units(): void {
		$iron_will = Catalog_Cutover::live_slug( 'vampire', 'met-merits' );
		$id        = (int) $this->submit( $this->player_id, $iron_will, [ 'name' => 'Iron Will' ] )->get_data()->id;

		$this->assertNull( $this->queue()[ $id ]->cost_units ?? null );
	}

	// --- the record itself -------------------------------------------------------------

	public function test_pricing_a_change_with_the_value_it_already_holds_still_succeeds(): void {
		$change = $this->submit( $this->st_id, 'vampire-backgrounds', $this->homebrew( 1, [ 'chosen_cost' => 0 ] ) )->get_data();
		$id     = (int) $change->id;

		// MySQL reports an UPDATE that writes identical values as zero rows changed.
		$this->assertTrue( Change::update_xp_cost( $id, 0.0 ) );
		$this->assertTrue( Change::update_xp_cost( $id, 0.0, $change->change_data ) );
	}

	public function test_a_change_that_has_been_reviewed_is_never_repriced(): void {
		$id = $this->pending_homebrew();
		$this->assertTrue( Change_Engine::approve( $id, $this->st_id, null, null, 2 ) );

		$this->assertFalse( Change::update_xp_cost( $id, 99.0 ) );
		$this->assertSame( 6.0, (float) Change::find( $id )->xp_cost );
	}

	public function test_a_change_left_pending_across_the_cutover_is_priced_against_the_block_it_lands_in(): void {
		global $wpdb;
		$legacy = 'met-abilities';
		$live   = 'vampire-abilities';
		$change = $this->submit( $this->player_id, $legacy, $this->homebrew( 2 ) )->get_data();
		$this->assertTrue( $change->change_data['cost_pending'] );

		// The install is switched with the change still in the queue, and - as 1.3.4 will do - the
		// retired block is gone. The change still names it; the price has to be worked out against the
		// block the row lands in, exactly as `apply_to_sheet()` files it.
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . \BeyondElysium\Database\Manager::table( 'schema_blocks' ) . ' WHERE slug = %s', $legacy ) );
		update_option( Catalog_Cutover::OPTION, 'declared' );
		Catalog_Cutover::reset_cache();
		try {
			$this->assertTrue( Change_Engine::approve( (int) $change->id, $this->st_id, null, null, 3 ) );
		} finally {
			delete_option( Catalog_Cutover::OPTION );
			Catalog_Cutover::reset_cache();
		}

		$this->assertSame( 14, $this->unspent(), 'three XP a dot, two dots' );
		$this->assertSame( 3, $this->held( $live )[0]['chosen_cost'], 'and the row is filed under the live block' );
	}

	// --- batch approve -----------------------------------------------------------------

	public function test_a_batch_approval_skips_a_purchase_waiting_for_a_price_and_names_it(): void {
		$waiting   = $this->pending_homebrew();
		$iron_will = Catalog_Cutover::live_slug( 'vampire', 'met-merits' );
		$ready     = (int) $this->submit( $this->st_id, $iron_will, [ 'name' => 'Iron Will', 'custom' => false ] )->get_data()->id;

		wp_set_current_user( $this->st_id );
		$request = new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/changes/batch-approve" );
		$request->set_param( 'change_ids', [ $waiting, $ready ] );
		$result = $this->dispatch( $request )->get_data();

		$this->assertSame( [ $ready ], $result['approved'] );
		$this->assertSame( [ $waiting ], $result['needs_cost'] );
		$this->assertSame( [], $result['skipped'], 'waiting for a price is not the same as missing or already reviewed' );
		$this->assertSame( 'pending', Change::find( $waiting )->status );
		$this->assertSame( [], $this->held( 'vampire-backgrounds' ) );
	}
}
