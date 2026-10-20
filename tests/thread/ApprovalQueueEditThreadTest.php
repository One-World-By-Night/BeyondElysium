<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Change;
use BeyondElysium\Models\Character;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Game_Member;
use BeyondElysium\Models\Schema_Block;
use BeyondElysium\Services\Cost_Engine;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * A Storyteller edits a change that is waiting in the Approval Queue: its content, its price, the section it is
 * filed in and a note to the player. An edit never approves anything.
 */
class ApprovalQueueEditThreadTest extends WP_UnitTestCase {

	private string $slug = 'thread-queue-edit';
	private int $game_id;
	private int $player;
	private int $st;
	private int $character_id;

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );

		$this->game_id = (int) Game::create( [ 'slug' => $this->slug, 'name' => 'Queue Edit by Night' ] );
		$this->player  = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		Game_Member::set_role( $this->game_id, $this->player, 'player' );
		$this->st = self::factory()->user->create( [ 'role' => 'administrator', 'display_name' => 'Head Storyteller' ] );

		$this->character_id = (int) Character::create( [
			'name' => 'Queue Edit Carrier', 'stack_slug' => 'vampire', 'owner_type' => 'chronicle',
			'owner_slug' => $this->slug, 'wp_user_id' => $this->player,
			'sheet_data' => [ 'vampire-abilities' => [ [ 'name' => 'Occult', 'count' => 3 ] ] ],
		] );
		Character::update_xp( $this->character_id, 30, 30 );
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/** @param array<string,mixed> $body */
	private function call( int $as, string $method, string $route, array $body = [] ) {
		wp_set_current_user( $as );
		$request = new WP_REST_Request( $method, $route );
		if ( $body ) {
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( wp_json_encode( $body ) );
		}
		$response = rest_get_server()->dispatch( $request );
		wp_set_current_user( 0 );
		return $response;
	}

	/**
	 * What the player's editor sends when it saves several changes at once.
	 *
	 * @param array<int,array<string,mixed>> $changes
	 * @return object[] The saved changes.
	 */
	private function save_set( array $changes ): array {
		$response = $this->call( $this->player, 'POST', "/be/v1/{$this->slug}/characters/{$this->character_id}/changes/submit", [ 'changes' => $changes ] );
		$this->assertSame( 201, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$saved = $response->get_data()['changes'];
		foreach ( $saved as $change ) {
			$this->assertSame( 'pending', $change->status );
		}
		return $saved;
	}

	/** @return array<string,mixed> */
	private function ritual( string $name, string $note = 'R1' ): array {
		return [
			'change_type' => 'add_trait', 'category' => 'vampire-rituals',
			'change_data' => [ 'block_slug' => 'vampire-rituals', 'trait' => [ 'name' => $name, 'note' => $note, 'count' => 1, 'custom' => true ] ],
		];
	}

	/** @return array<string,mixed> */
	private function ability( string $name, int $count = 1 ): array {
		return [
			'change_type' => 'add_trait', 'category' => 'vampire-abilities',
			'change_data' => [ 'block_slug' => 'vampire-abilities', 'trait' => [ 'name' => $name, 'count' => $count ] ],
		];
	}

	private function catalog_name( string $block_slug, string $not = '' ): string {
		foreach ( Schema_Block::find_by_slug( $block_slug )->definition->items as $item ) {
			if ( $item->name !== $not ) {
				return $item->name;
			}
		}
		$this->fail( "{$block_slug} has no catalog entry" );
	}

	private function token( int $id ): string {
		return Change::review_token( Change::find( $id ) );
	}

	/** @param array<string,mixed> $body */
	private function edit( int $id, array $body = [], ?string $token = null, ?int $as = null ) {
		return $this->call( $as ?? $this->st, 'PATCH', "/be/v1/{$this->slug}/changes/{$id}", [ 'review_token' => $token ?? $this->token( $id ) ] + $body );
	}

	/** @param int[] $ids */
	private function approve_all( array $ids ) {
		$tokens = [];
		foreach ( $ids as $id ) {
			$tokens[ $id ] = $this->token( $id );
		}
		return $this->call( $this->st, 'POST', "/be/v1/{$this->slug}/changes/batch-approve", [ 'change_ids' => $ids, 'review_tokens' => $tokens ] );
	}

	/** @return array<string,mixed> */
	private function sheet(): array {
		return Character::find( $this->character_id )->sheet_data;
	}

	private function unspent(): int {
		return (int) Character::find( $this->character_id )->xp_unspent;
	}

	/** @return array<string,mixed> A change row as stored, for comparing before and after a refusal. */
	private function stored( int $id ): array {
		$row = (array) Change::find( $id );
		return [ 'change_data' => $row['change_data'], 'xp_cost' => $row['xp_cost'], 'review_notes' => $row['review_notes'], 'category' => $row['category'], 'submitted_at' => $row['submitted_at'] ];
	}

	// -------------------------------------------------------------------------
	// A set of homebrew rituals that all wait for a price
	// -------------------------------------------------------------------------

	public function test_approve_all_cannot_apply_a_set_whose_members_all_need_a_price(): void {
		$saved = $this->save_set( [ $this->ritual( 'Wax Ward' ), $this->ritual( 'Candle Memory' ) ] );
		$ids   = array_map( static fn( $c ) => (int) $c->id, $saved );

		$result = $this->approve_all( $ids )->get_data();

		$this->assertSame( [], $result['approved'] );
		$this->assertEqualsCanonicalizing( $ids, $result['needs_cost'] );
		$this->assertSame( 30, $this->unspent() );
		$this->assertArrayNotHasKey( 'vampire-rituals', $this->sheet() );
	}

	public function test_pricing_each_member_lets_approve_all_apply_the_set(): void {
		$saved = $this->save_set( [ $this->ritual( 'Wax Ward' ), $this->ritual( 'Candle Memory' ) ] );
		[ $free, $paid ] = array_map( static fn( $c ) => (int) $c->id, $saved );
		$before = [ $free => Change::find( $free ), $paid => Change::find( $paid ) ];

		$first  = $this->edit( $free, [ 'price' => 0 ] );
		$second = $this->edit( $paid, [ 'price' => 4 ] );

		$this->assertSame( 200, $first->get_status(), wp_json_encode( $first->get_data() ) );
		$this->assertSame( 200, $second->get_status() );
		foreach ( [ $free => 0, $paid => 4 ] as $id => $price ) {
			$row = Change::find( $id );
			$this->assertSame( 'pending', $row->status );
			$this->assertArrayNotHasKey( 'cost_pending', $row->change_data );
			$this->assertSame( (float) $price, (float) $row->xp_cost );
			$this->assertSame( $price, $row->change_data['trait']['chosen_cost'] );
			$this->assertEquals( [ 'amount' => $price, 'per' => 'pick' ], $row->change_data['st_price'] );
			$this->assertSame( $this->st, (int) $row->change_data['st_edit']['by'] );
			$this->assertSame( $before[ $id ]->submitted_at, $row->submitted_at, 'the queue keeps its order' );
			$this->assertSame( $before[ $id ]->submission_id, $row->submission_id );
			$this->assertSame( (int) $before[ $id ]->submitted_by, (int) $row->submitted_by );
			$this->assertNotSame( Change::review_token( $before[ $id ] ), Change::review_token( $row ), 'an edit issues a new token' );
		}

		$result = $this->approve_all( [ $free, $paid ] )->get_data();

		$this->assertEqualsCanonicalizing( [ $free, $paid ], $result['approved'] );
		$rows = $this->sheet()['vampire-rituals'];
		$this->assertSame( [ 0, 4 ], array_values( array_map( static fn( $r ) => $r['chosen_cost'], $rows ) ) );
		foreach ( $rows as $row ) {
			$this->assertArrayNotHasKey( 'st_price', $row );
			$this->assertArrayNotHasKey( 'st_edit', $row );
		}
		$this->assertSame( 26, $this->unspent() );
	}

	public function test_approve_all_holds_a_whole_set_while_one_member_still_needs_a_price(): void {
		$name  = $this->catalog_name( 'vampire-abilities', 'Occult' );
		$saved = $this->save_set( [ $this->ability( $name ), $this->ritual( 'Wax Ward' ) ] );
		[ $book, $homebrew ] = array_map( static fn( $c ) => (int) $c->id, $saved );

		$result = $this->approve_all( [ $book, $homebrew ] )->get_data();

		$this->assertSame( [], $result['approved'] );
		$this->assertSame( [ $homebrew ], $result['needs_cost'] );
		$this->assertSame( [ $book ], $result['held'], 'the priced member waits with its set' );
		$this->assertSame( 30, $this->unspent() );
		$this->assertSame( 'pending', Change::find( $book )->status );
	}

	public function test_a_set_applies_whole_once_every_member_can(): void {
		$name  = $this->catalog_name( 'vampire-abilities', 'Occult' );
		$saved = $this->save_set( [ $this->ability( $name ), $this->ritual( 'Wax Ward' ) ] );
		[ $book, $homebrew ] = array_map( static fn( $c ) => (int) $c->id, $saved );
		$this->edit( $homebrew, [ 'price' => 2 ] );

		$result = $this->approve_all( [ $book, $homebrew ] )->get_data();

		$this->assertEqualsCanonicalizing( [ $book, $homebrew ], $result['approved'] );
		$this->assertSame( [], $result['held'] );
	}

	// -------------------------------------------------------------------------
	// A price that exists
	// -------------------------------------------------------------------------

	public function test_a_storyteller_can_set_the_price_of_a_change_the_book_prices_and_clear_it_again(): void {
		$name = $this->catalog_name( 'vampire-abilities', 'Occult' );
		$id   = (int) $this->save_set( [ $this->ability( $name, 2 ) ] )[0]->id;
		$character = Character::find( $this->character_id );
		$book      = Cost_Engine::quote_for_change( $character, [ 'change_type' => 'add_trait', 'change_data' => Change::find( $id )->change_data ], false );
		$this->assertTrue( $book['priced'] );
		$this->assertGreaterThan( 0, $book['xp'] );
		$this->assertSame( (float) $book['xp'], (float) Change::find( $id )->xp_cost );

		$this->edit( $id, [ 'price' => 1 ] );

		$row = Change::find( $id );
		$this->assertSame( 1.0, (float) $row->xp_cost );
		$this->assertEquals( [ 'amount' => 1, 'per' => 'change' ], $row->change_data['st_price'] );
		$this->assertArrayNotHasKey( 'chosen_cost', $row->change_data['trait'], 'nothing is stamped on a trait the book prices' );

		$this->edit( $id, [ 'price' => null ] );

		$row = Change::find( $id );
		$this->assertSame( (float) $book['xp'], (float) $row->xp_cost );
		$this->assertArrayNotHasKey( 'st_price', $row->change_data );
	}

	public function test_fewer_dots_are_priced_again(): void {
		$name = $this->catalog_name( 'vampire-abilities', 'Occult' );
		$id   = (int) $this->save_set( [ $this->ability( $name, 3 ) ] )[0]->id;
		$cost = (float) Change::find( $id )->xp_cost;

		$this->assertSame( 200, $this->edit( $id, [ 'fields' => [ 'count' => 1 ] ] )->get_status() );

		$row       = Change::find( $id );
		$character = Character::find( $this->character_id );
		$expected  = Cost_Engine::quote_for_change( $character, [ 'change_type' => 'add_trait', 'change_data' => $row->change_data ], false )['xp'];
		$this->assertSame( 1, $row->change_data['trait']['count'] );
		$this->assertSame( (float) $expected, (float) $row->xp_cost );
		$this->assertLessThan( $cost, (float) $row->xp_cost );
	}

	public function test_a_homebrew_entry_renamed_to_a_catalog_name_is_priced_by_the_book(): void {
		$id   = (int) $this->save_set( [ $this->ritual( 'Wax Ward' ) ] )[0]->id;
		$name = $this->catalog_name( 'vampire-rituals' );

		$response = $this->edit( $id, [ 'fields' => [ 'name' => $name ] ] );

		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$row = Change::find( $id );
		$this->assertSame( $name, $row->change_data['trait']['name'] );
		$this->assertArrayNotHasKey( 'custom', $row->change_data['trait'] );
		$this->assertArrayNotHasKey( 'cost_pending', $row->change_data );
	}

	public function test_a_price_in_the_wrong_unit_is_refused_rather_than_misread(): void {
		$id   = (int) $this->save_set( [ $this->ritual( 'Wax Ward' ) ] )[0]->id;
		$name = $this->catalog_name( 'vampire-rituals' );
		$before = $this->stored( $id );

		$response = $this->edit( $id, [ 'fields' => [ 'name' => $name ], 'price' => 4, 'price_per' => 'pick' ] );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'price_unit_changed', $response->get_data()['code'] );
		$this->assertSame( $before, $this->stored( $id ) );
	}

	// -------------------------------------------------------------------------
	// Refusals write nothing
	// -------------------------------------------------------------------------

	public function test_refusals_leave_the_change_exactly_as_it_was(): void {
		$ids  = array_map( static fn( $c ) => (int) $c->id, $this->save_set( [ $this->ritual( 'Wax Ward' ), $this->ritual( 'Candle Memory' ) ] ) );
		$xp   = (int) $this->call( $this->player, 'POST', "/be/v1/{$this->slug}/characters/{$this->character_id}/changes", [
			'change_type' => 'xp_earn', 'category' => 'experience',
			'change_data' => [ 'amount' => 5, 'request' => [ 'where' => 'Door duty' ] ],
		] )->get_data()->id;
		$id   = $ids[0];
		$before = $this->stored( $id );

		$cases = [
			'a stale token'                   => [ $this->edit( $id, [ 'price' => 1 ], 'stale-token' ), 409, 'change_changed' ],
			'no token'                        => [ $this->call( $this->st, 'PATCH', "/be/v1/{$this->slug}/changes/{$id}", [ 'price' => 1 ] ), 400, 'invalid_param' ],
			'a player'                        => [ $this->edit( $id, [ 'price' => 1 ], null, $this->player ), 403, null ],
			'a count that is not a number'    => [ $this->edit( $id, [ 'fields' => [ 'count' => 'many' ] ] ), 400, 'invalid_param' ],
			'a field the change does not have' => [ $this->edit( $id, [ 'fields' => [ 'level' => 3 ] ] ), 400, 'field_not_editable' ],
			'a price above the most allowed'  => [ $this->edit( $id, [ 'price' => 501 ] ), 400, 'invalid_param' ],
			'a price that is not a number'    => [ $this->edit( $id, [ 'price' => 'free' ] ), 400, 'invalid_param' ],
			'another waiting change\'s entry' => [ $this->edit( $id, [ 'fields' => [ 'name' => 'Candle Memory' ] ] ), 400, 'duplicate_pending' ],
			'a price on an experience request' => [ $this->edit( $xp, [ 'price' => 1 ] ), 400, 'price_not_applicable' ],
		];

		foreach ( $cases as $label => [ $response, $status, $code ] ) {
			$this->assertSame( $status, $response->get_status(), $label );
			if ( $code !== null ) {
				$this->assertSame( $code, $response->get_data()['code'], $label );
			}
		}
		$this->assertSame( $before, $this->stored( $id ) );
	}

	public function test_a_change_that_has_been_decided_cannot_be_edited(): void {
		$id = (int) $this->save_set( [ $this->ritual( 'Wax Ward' ) ] )[0]->id;
		$this->edit( $id, [ 'price' => 1 ] );
		$token = $this->token( $id );
		$this->call( $this->st, 'PUT', "/be/v1/{$this->slug}/changes/{$id}", [ 'status' => 'approved', 'review_token' => $token ] );
		$this->assertSame( 'approved', Change::find( $id )->status );

		$response = $this->edit( $id, [ 'price' => 2 ], $token );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'change_not_pending', $response->get_data()['code'] );
	}

	public function test_a_player_resubmitting_while_the_editor_is_open_makes_the_save_stale(): void {
		$id    = (int) $this->save_set( [ $this->ritual( 'Wax Ward' ) ] )[0]->id;
		$token = $this->token( $id );

		$this->save_set( [ $this->ritual( 'Wax Ward', 'R1, rewritten' ) ] );

		$response = $this->edit( $id, [ 'price' => 1 ], $token );

		$this->assertSame( 409, $response->get_status() );
		$this->assertArrayNotHasKey( 'st_price', Change::find( $id )->change_data );
	}

	// -------------------------------------------------------------------------
	// An experience request
	// -------------------------------------------------------------------------

	public function test_an_experience_request_edited_down_grants_the_edited_amount(): void {
		$id = (int) $this->call( $this->player, 'POST', "/be/v1/{$this->slug}/characters/{$this->character_id}/changes", [
			'change_type' => 'xp_earn', 'category' => 'experience',
			'change_data' => [ 'amount' => 5, 'request' => [ 'where' => 'Door duty', 'note' => 'Ran it all night' ] ],
		] )->get_data()->id;

		$this->assertSame( 200, $this->edit( $id, [ 'fields' => [ 'amount' => 3 ] ] )->get_status() );

		$row = Change::find( $id );
		$this->assertSame( 3, $row->change_data['amount'] );
		$this->assertSame( 'Door duty', $row->change_data['request']['where'], 'the player\'s details stay' );
		$this->assertSame( 200, $this->call( $this->st, 'PUT', "/be/v1/{$this->slug}/changes/{$id}", [ 'status' => 'approved', 'review_token' => $this->token( $id ) ] )->get_status() );
		$character = Character::find( $this->character_id );
		$this->assertSame( 33, (int) $character->xp_unspent );
		$this->assertSame( 33, (int) $character->xp_earned );
	}

	public function test_an_edited_identity_change_puts_only_its_field_on_the_sheet(): void {
		$response = $this->call( $this->player, 'POST', "/be/v1/{$this->slug}/characters/{$this->character_id}/changes", [
			'change_type' => 'modify_identity', 'category' => 'vampire-identity',
			'change_data' => [ 'block_slug' => 'vampire-identity', 'fields' => [ 'Title' => 'Regent' ] ],
		] );
		$this->assertSame( 201, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$id = (int) $response->get_data()->id;
		$this->assertSame( 'pending', Change::find( $id )->status );

		$this->assertSame( 200, $this->edit( $id, [ 'fields' => [ 'value' => 'Regent of the Chantry' ], 'review_notes' => 'Fits the story.' ] )->get_status() );
		$this->assertSame( 200, $this->call( $this->st, 'PUT', "/be/v1/{$this->slug}/changes/{$id}", [ 'status' => 'approved', 'review_token' => $this->token( $id ) ] )->get_status() );

		$this->assertSame( [ 'Title' => 'Regent of the Chantry' ], $this->sheet()['vampire-identity'] );
	}

	// -------------------------------------------------------------------------
	// The note to the player
	// -------------------------------------------------------------------------

	public function test_a_note_is_kept_through_approval_replaced_by_a_rejection_and_cleared_by_an_empty_one(): void {
		$ids = array_map( static fn( $c ) => (int) $c->id, $this->save_set( [ $this->ritual( 'Wax Ward' ), $this->ritual( 'Candle Memory' ) ] ) );
		[ $kept, $refused ] = $ids;
		$this->edit( $kept, [ 'price' => 1, 'review_notes' => 'Priced as a first-level ritual.' ] );
		$this->edit( $refused, [ 'price' => 1, 'review_notes' => 'Not sure about this one.' ] );

		$this->assertSame( 'Priced as a first-level ritual.', Change::find( $kept )->review_notes );

		$this->call( $this->st, 'PUT', "/be/v1/{$this->slug}/changes/{$kept}", [ 'status' => 'approved', 'review_token' => $this->token( $kept ) ] );
		$this->assertSame( 'Priced as a first-level ritual.', Change::find( $kept )->review_notes, 'approving with no note keeps the saved one' );

		$this->call( $this->st, 'PUT', "/be/v1/{$this->slug}/changes/{$refused}", [ 'status' => 'rejected', 'notes' => 'Already on your sheet.', 'review_token' => $this->token( $refused ) ] );
		$this->assertSame( 'Already on your sheet.', Change::find( $refused )->review_notes, 'a rejection replaces it' );

		$other = (int) $this->save_set( [ $this->ritual( 'Third Rite' ) ] )[0]->id;
		$this->edit( $other, [ 'review_notes' => 'First thought.' ] );
		$this->edit( $other, [ 'review_notes' => '' ] );
		$this->assertNull( Change::find( $other )->review_notes );
	}

	public function test_a_note_is_plain_text_and_staff_only_asides_stay_staff_only(): void {
		$id = (int) $this->save_set( [ $this->ritual( 'Wax Ward' ) ] )[0]->id;

		$this->edit( $id, [ 'review_notes' => '<b>Check</b><script>alert(1)</script> the source. [ST]Ask Sam about it.[/ST]' ] );

		$stored = Change::find( $id )->review_notes;
		$this->assertSame( 'Check the source. [ST]Ask Sam about it.[/ST]', $stored );

		$seen = $this->call( $this->player, 'GET', "/be/v1/{$this->slug}/characters/{$this->character_id}/changes" )->get_data();
		$mine = array_values( array_filter( $seen, static fn( $c ) => (int) $c->id === $id ) )[0];
		$this->assertStringNotContainsString( 'Ask Sam', (string) $mine->review_notes );
		$this->assertStringContainsString( 'Check the source.', (string) $mine->review_notes );
	}

	public function test_approval_and_rejection_notes_are_stripped_of_markup_too(): void {
		$ids = array_map( static fn( $c ) => (int) $c->id, $this->save_set( [ $this->ritual( 'Wax Ward' ), $this->ritual( 'Candle Memory' ) ] ) );
		$this->edit( $ids[0], [ 'price' => 1 ] );

		$this->call( $this->st, 'PUT', "/be/v1/{$this->slug}/changes/{$ids[0]}", [ 'status' => 'approved', 'notes' => '<i>Fine</i>', 'review_token' => $this->token( $ids[0] ) ] );
		$this->call( $this->st, 'PUT', "/be/v1/{$this->slug}/changes/{$ids[1]}", [ 'status' => 'rejected', 'notes' => '<u>No</u><script>x()</script>', 'review_token' => $this->token( $ids[1] ) ] );

		$this->assertSame( 'Fine', Change::find( $ids[0] )->review_notes );
		$this->assertSame( 'No', Change::find( $ids[1] )->review_notes );
	}

	// -------------------------------------------------------------------------
	// Filing an entry in the right section
	// -------------------------------------------------------------------------

	public function test_the_choices_are_the_visible_sections_that_take_the_same_kind_of_entry(): void {
		$id = (int) $this->save_set( [ $this->ritual( 'Wax Ward' ) ] )[0]->id;

		$response = $this->call( $this->st, 'GET', "/be/v1/{$this->slug}/changes/{$id}/sections" );
		$slugs    = array_column( $response->get_data(), 'slug' );

		$this->assertSame( 200, $response->get_status() );
		$this->assertContains( 'vampire-rituals', $slugs, 'the section it is on' );
		$this->assertContains( 'vampire-combo-disciplines', $slugs );
		$this->assertContains( 'vampire-merits', $slugs );
		$this->assertNotContains( 'vampire-disciplines', $slugs, 'a power list takes another kind of entry' );
		$this->assertNotContains( 'vampire-identity', $slugs );
		$this->assertNotContains( 'vampire-health', $slugs, 'a closed list takes no entry of a player\'s own' );
	}

	public function test_a_change_that_cannot_move_has_no_choices(): void {
		$occult = (int) $this->save_set( [ [
			'change_type' => 'modify_trait', 'category' => 'vampire-abilities',
			'change_data' => [ 'block_slug' => 'vampire-abilities', 'trait' => [ 'name' => 'Occult', 'count' => 4 ] ],
		] ] )[0]->id;
		$book = (int) $this->save_set( [ $this->ability( $this->catalog_name( 'vampire-abilities', 'Occult' ) ) ] )[0]->id;

		$this->assertSame( [], $this->call( $this->st, 'GET', "/be/v1/{$this->slug}/changes/{$occult}/sections" )->get_data() );
		$this->assertSame( [], $this->call( $this->st, 'GET', "/be/v1/{$this->slug}/changes/{$book}/sections" )->get_data(), 'an entry the book already knows is in its right place' );
		$this->assertSame( 403, $this->call( $this->player, 'GET', "/be/v1/{$this->slug}/changes/{$occult}/sections" )->get_status() );
	}

	public function test_a_ritual_filed_in_combo_disciplines_lands_there_when_approved(): void {
		$id = (int) $this->save_set( [ $this->ritual( 'Fettered Minion' ) ] )[0]->id;

		$response = $this->edit( $id, [ 'fields' => [ 'block_slug' => 'vampire-combo-disciplines' ] ] );

		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$row = Change::find( $id );
		$this->assertSame( 'vampire-combo-disciplines', $row->change_data['block_slug'] );
		$this->assertSame( 'vampire-combo-disciplines', $row->category );
		$this->assertTrue( $row->change_data['cost_pending'], 'still waiting for a price in its new section' );
		$this->assertSame( 'pending', $row->status );

		$this->call( $this->st, 'PUT', "/be/v1/{$this->slug}/changes/{$id}", [ 'status' => 'approved', 'xp_cost' => 4, 'review_token' => $this->token( $id ) ] );

		$sheet = $this->sheet();
		$this->assertSame( 'Fettered Minion', $sheet['vampire-combo-disciplines'][0]['name'] );
		$this->assertArrayNotHasKey( 'vampire-rituals', $sheet );
	}

	public function test_a_combination_discipline_is_priced_per_pick_and_its_number_becomes_its_price(): void {
		$id = (int) $this->save_set( [ $this->ritual( 'Fettered Minion' ) ] )[0]->id;
		$this->edit( $id, [ 'fields' => [ 'block_slug' => 'vampire-combo-disciplines' ] ] );

		$items = $this->call( $this->st, 'GET', "/be/v1/{$this->slug}/changes" )->get_data();
		$basis = array_values( array_filter( $items, static fn( $i ) => (int) $i->id === $id ) )[0]->price_basis;
		$this->assertSame( 'pick', $basis['per'], 'one price for the whole pick' );
		$this->assertSame( 1, $basis['units'] );

		$this->assertSame( 200, $this->edit( $id, [ 'price' => 4, 'price_per' => 'pick' ] )->get_status() );
		$row = Change::find( $id );
		$this->assertSame( 4, $row->change_data['trait']['chosen_cost'] );
		$this->assertSame( 4, $row->change_data['trait']['count'] );
		$this->assertSame( 4.0, (float) $row->xp_cost );

		$this->call( $this->st, 'PUT', "/be/v1/{$this->slug}/changes/{$id}", [ 'status' => 'approved', 'review_token' => $this->token( $id ) ] );

		$held       = $this->sheet()['vampire-combo-disciplines'][0];
		$definition = Schema_Block::find_by_slug( 'vampire-combo-disciplines' )->definition;
		$this->assertSame( 4, Cost_Engine::price_held_trait_list_item( $definition, $held )['xp'], 'the point audit charges it once' );
		$this->assertSame( 26, $this->unspent() );
	}

	public function test_an_entry_the_new_section_knows_becomes_that_catalog_entry(): void {
		$name = $this->catalog_name( 'vampire-combo-disciplines' );
		$id   = (int) $this->save_set( [ $this->ritual( $name ) ] )[0]->id;

		$this->assertSame( 200, $this->edit( $id, [ 'fields' => [ 'block_slug' => 'vampire-combo-disciplines' ] ] )->get_status() );

		$row = Change::find( $id );
		$this->assertSame( $name, $row->change_data['trait']['name'] );
		$this->assertArrayNotHasKey( 'custom', $row->change_data['trait'] );
		$this->assertArrayNotHasKey( 'cost_pending', $row->change_data );
	}

	public function test_a_move_is_refused_with_a_price_or_to_a_section_that_cannot_take_it(): void {
		$id     = (int) $this->save_set( [ $this->ritual( 'Fettered Minion' ) ] )[0]->id;
		$before = $this->stored( $id );

		$with_price = $this->edit( $id, [ 'fields' => [ 'block_slug' => 'vampire-combo-disciplines' ], 'price' => 2 ] );
		$to_powers  = $this->edit( $id, [ 'fields' => [ 'block_slug' => 'vampire-disciplines' ] ] );
		$nowhere    = $this->edit( $id, [ 'fields' => [ 'block_slug' => 'no-such-block' ] ] );

		$this->assertSame( 'price_with_move', $with_price->get_data()['code'] );
		$this->assertSame( 'invalid_section', $to_powers->get_data()['code'] );
		$this->assertSame( 'invalid_section', $nowhere->get_data()['code'] );
		$this->assertSame( $before, $this->stored( $id ) );
	}

	// -------------------------------------------------------------------------
	// What the queue shows
	// -------------------------------------------------------------------------

	public function test_the_queue_item_carries_what_the_editor_needs(): void {
		$ritual = (int) $this->save_set( [ $this->ritual( 'Wax Ward' ) ] )[0]->id;
		$xp     = (int) $this->call( $this->player, 'POST', "/be/v1/{$this->slug}/characters/{$this->character_id}/changes", [
			'change_type' => 'xp_earn', 'category' => 'experience',
			'change_data' => [ 'amount' => 5, 'request' => [ 'where' => 'Door duty' ] ],
		] )->get_data()->id;

		$find = function () use ( $ritual, $xp ) {
			$items = $this->call( $this->st, 'GET', "/be/v1/{$this->slug}/changes" )->get_data();
			$by_id = [];
			foreach ( $items as $item ) {
				$by_id[ (int) $item->id ] = $item;
			}
			return [ $by_id[ $ritual ], $by_id[ $xp ] ];
		};

		[ $item, $request ] = $find();
		$this->assertSame( 'Rituals', $item->block_name );
		$this->assertSame( [ 'block_slug', 'name', 'count', 'note' ], $item->editable_fields );
		$this->assertSame( [ 'homebrew' => true, 'per' => 'pick', 'units' => 1, 'negative' => false, 'book_xp' => null, 'current' => null ], $item->price_basis );
		$this->assertNull( $item->edited_by_name );
		$this->assertNull( $request->price_basis );
		$this->assertSame( [ 'amount', 'reason' ], $request->editable_fields );

		$this->edit( $ritual, [ 'price' => 4 ] );

		[ $item ] = $find();
		$this->assertSame( 4, $item->price_basis['current'] );
		$this->assertTrue( $item->price_basis['homebrew'], 'still no price in the book' );
		$this->assertSame( 'Head Storyteller', $item->edited_by_name );
	}

	public function test_the_queue_item_gives_the_book_price_when_there_is_one(): void {
		$name = $this->catalog_name( 'vampire-abilities', 'Occult' );
		$id   = (int) $this->save_set( [ $this->ability( $name, 2 ) ] )[0]->id;

		$items = $this->call( $this->st, 'GET', "/be/v1/{$this->slug}/changes" )->get_data();
		$item  = array_values( array_filter( $items, static fn( $i ) => (int) $i->id === $id ) )[0];

		$this->assertFalse( $item->price_basis['homebrew'] );
		$this->assertSame( 'dot', $item->price_basis['per'] );
		$this->assertSame( 2, $item->price_basis['units'] );
		$this->assertSame( (int) Change::find( $id )->xp_cost, $item->price_basis['book_xp'] );
	}
}
