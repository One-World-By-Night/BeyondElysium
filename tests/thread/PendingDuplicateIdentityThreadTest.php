<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Change;
use BeyondElysium\Models\Character;
use BeyondElysium\Models\Creature_Stack;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Game_Member;
use BeyondElysium\Models\Schema_Block;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Through the real change route on a chronicle that queues.
 */
class PendingDuplicateIdentityThreadTest extends WP_UnitTestCase {

	private string $slug = 'thread-pending-identity';
	private int $player;
	private int $character;

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );

		global $wpdb;
		// No auto_approve: every change stays pending.
		$wpdb->insert( $wpdb->prefix . 'be_games', [
			'slug' => $this->slug, 'name' => $this->slug,
			'created_by' => 1, 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ),
		] );
		$game_id = (int) Game::find_by_slug( $this->slug )->id;

		Schema_Block::create( [
			'slug' => 'tpi-backgrounds', 'name' => 'Backgrounds', 'section_type' => 'trait_list', 'is_system' => 0,
			'definition' => [ 'has_specializations' => true, 'items' => [
				[ 'name' => 'Retainers', 'cost' => '1', 'allow_multiples' => true ],
				[ 'name' => 'Generation', 'cost' => '1' ],
			] ],
		] );
		Schema_Block::create( [
			'slug' => 'tpi-disciplines', 'name' => 'Disciplines', 'section_type' => 'tiered_power', 'is_system' => 0,
			'definition' => [ 'powers' => [ [ 'name' => 'Celerity', 'levels' => [
				[ 'level' => 1, 'power_name' => 'Alacrity', 'cost' => '3' ],
				[ 'level' => null, 'power_name' => 'Precision', 'tier' => 'elder', 'cost' => '12' ],
				[ 'level' => null, 'power_name' => 'Zephyr', 'tier' => 'elder', 'cost' => '12' ],
			] ] ] ],
		] );
		Creature_Stack::create( [
			'slug' => 'tpi-stack', 'name' => 'Pending Identity Creature', 'is_system' => 0, 'created_by' => 1,
			'stack_definition' => [ 'sections' => [
				[ 'block_slug' => 'tpi-backgrounds' ], [ 'block_slug' => 'tpi-disciplines' ],
			] ],
		] );

		$this->player = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		Game_Member::set_role( $game_id, $this->player, 'player' );

		$this->character = Character::create( [
			'name' => 'Pending Identity Tester', 'stack_slug' => 'tpi-stack',
			'owner_type' => 'chronicle', 'owner_slug' => $this->slug, 'wp_user_id' => $this->player,
			'sheet_data' => [],
		] );
		Character::update_xp( $this->character, 200, 200 );
	}

	private function submit( array $change ) {
		wp_set_current_user( $this->player );
		$request = new WP_REST_Request( 'POST', "/be/v1/{$this->slug}/characters/{$this->character}/changes" );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( array_merge( [ 'category' => 'test' ], $change ) ) );
		return rest_get_server()->dispatch( $request );
	}

	private function add( string $block, array $trait ) {
		return $this->submit( [
			'change_type' => 'add_trait',
			'change_data' => [ 'block_slug' => $block, 'trait' => $trait ],
		] );
	}

	/** @return array<int,object> This character's pending changes. */
	private function pending(): array {
		return Change::for_character( $this->character, [ 'status' => 'pending' ] );
	}

	public function test_two_labelled_purchases_are_two_pending_changes(): void {
		$first  = $this->add( 'tpi-backgrounds', [ 'name' => 'Retainers', 'count' => 3, 'specialization' => 'John Doe' ] );
		$second = $this->add( 'tpi-backgrounds', [ 'name' => 'Retainers', 'count' => 2, 'specialization' => 'Sue Smith' ] );

		$this->assertSame( 201, $first->get_status() );
		$this->assertSame( 201, $second->get_status() );
		$this->assertNotSame( $first->get_data()->id, $second->get_data()->id, 'The second purchase took over the first change row.' );

		$queue = $this->pending();
		$this->assertCount( 2, $queue );
		$labels = array_map( static fn( $row ) => $row->change_data['trait']['specialization'] ?? '', $queue );
		sort( $labels );
		$this->assertSame( [ 'John Doe', 'Sue Smith' ], $labels );
	}

	public function test_resubmitting_the_same_labelled_purchase_still_overwrites_it(): void {
		$first  = $this->add( 'tpi-backgrounds', [ 'name' => 'Retainers', 'count' => 3, 'specialization' => 'John Doe' ] );
		$second = $this->add( 'tpi-backgrounds', [ 'name' => 'Retainers', 'count' => 4, 'specialization' => 'John Doe' ] );

		$this->assertSame( $first->get_data()->id, $second->get_data()->id );
		$queue = $this->pending();
		$this->assertCount( 1, $queue );
		$this->assertSame( 4, (int) $queue[0]->change_data['trait']['count'] );
	}

	public function test_a_block_with_no_multiples_still_dedupes_by_name(): void {
		$first  = $this->add( 'tpi-backgrounds', [ 'name' => 'Generation', 'count' => 2, 'specialization' => '8th' ] );
		$second = $this->add( 'tpi-backgrounds', [ 'name' => 'Generation', 'count' => 3, 'specialization' => '7th' ] );

		$this->assertSame( $first->get_data()->id, $second->get_data()->id );
		$this->assertCount( 1, $this->pending() );
	}

	public function test_two_elder_picks_of_one_family_are_two_pending_changes(): void {
		$first  = $this->add( 'tpi-disciplines', [ 'name' => 'Celerity', 'power_name' => 'Precision' ] );
		$second = $this->add( 'tpi-disciplines', [ 'name' => 'Celerity', 'power_name' => 'Zephyr' ] );

		$this->assertSame( 201, $first->get_status() );
		$this->assertSame( 201, $second->get_status() );
		$this->assertNotSame( $first->get_data()->id, $second->get_data()->id, 'The second Elder pick took over the first change row.' );
		$this->assertCount( 2, $this->pending() );
	}

	public function test_resubmitting_one_elder_pick_still_overwrites_it(): void {
		$first  = $this->add( 'tpi-disciplines', [ 'name' => 'Celerity', 'power_name' => 'Precision' ] );
		$second = $this->add( 'tpi-disciplines', [ 'name' => 'Celerity', 'power_name' => 'Precision' ] );

		$this->assertSame( $first->get_data()->id, $second->get_data()->id );
		$this->assertCount( 1, $this->pending() );
	}
}
