<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Character;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Game_Member;
use BeyondElysium\Models\Schema_Block;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * The Storyteller-only scope of a chronicle's copy of a block: one chronicle hiding its own copy hides nothing
 * elsewhere, a chronicle that opens its own copy of a hidden block opens it there only, a chronicle's first copy of a
 * hidden block stays hidden, the upgrade keeps an old copy of a hidden block hidden once, and the sheet layout follows
 * the same chronicle scope.
 */
class StorytellerOnlyForkScopeThreadTest extends WP_UnitTestCase {

	private int $player;

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );
		$this->player = self::factory()->user->create( [ 'role' => 'subscriber' ] );

		foreach ( [ 'thread-fork-scope-a', 'thread-fork-scope-b' ] as $slug ) {
			Game_Member::set_role( (int) Game::create( [ 'slug' => $slug, 'name' => $slug ] ), $this->player, 'player' );
		}

		Schema_Block::create( [
			'slug' => 'thread-scope-open', 'name' => 'Open', 'section_type' => 'identity_field', 'is_system' => 0,
			'definition' => [ 'fields' => [ [ 'name' => 'Concept', 'field_type' => 'text', 'required' => false ] ] ],
		] );
		Schema_Block::create( [
			'slug' => 'thread-scope-secret', 'name' => 'Secret', 'section_type' => 'identity_field', 'is_system' => 0, 'storyteller_only' => 1,
			'definition' => [ 'fields' => [ [ 'name' => 'Hook', 'field_type' => 'text', 'required' => false ] ] ],
		] );
	}

	private function players_sheet_in( string $game_slug ): array {
		$id = Character::create( [
			'name' => "Scope {$game_slug}", 'stack_slug' => 'vampire', 'owner_type' => 'chronicle', 'owner_slug' => $game_slug,
			'status' => 'active', 'wp_user_id' => $this->player,
			'sheet_data' => [ 'thread-scope-open' => [ 'Concept' => 'Poet' ], 'thread-scope-secret' => [ 'Hook' => 'Sabbat' ] ],
		] );
		wp_set_current_user( $this->player );
		$response = rest_get_server()->dispatch( new WP_REST_Request( 'GET', "/be/v1/{$game_slug}/characters/{$id}" ) );
		$this->assertSame( 200, $response->get_status() );
		return (array) $response->get_data()->sheet_data;
	}

	private function fork_with_flag( string $slug, string $game_slug, int $flag ): void {
		Schema_Block::find_or_create_fork_for_game( $slug, $game_slug );
		$this->assertTrue( Schema_Block::update( $slug, [ 'storyteller_only' => $flag ], $game_slug ) );
	}

	public function test_one_chronicle_hiding_its_own_copy_hides_nothing_anywhere_else(): void {
		$this->fork_with_flag( 'thread-scope-open', 'thread-fork-scope-a', 1 );

		$this->assertArrayNotHasKey( 'thread-scope-open', $this->players_sheet_in( 'thread-fork-scope-a' ) );
		$this->assertArrayHasKey( 'thread-scope-open', $this->players_sheet_in( 'thread-fork-scope-b' ) );
	}

	public function test_a_chronicle_that_opens_its_own_copy_of_a_hidden_block_opens_it_there_only(): void {
		$this->fork_with_flag( 'thread-scope-secret', 'thread-fork-scope-a', 0 );

		$this->assertArrayHasKey( 'thread-scope-secret', $this->players_sheet_in( 'thread-fork-scope-a' ) );
		$this->assertArrayNotHasKey( 'thread-scope-secret', $this->players_sheet_in( 'thread-fork-scope-b' ) );
	}

	public function test_a_chronicles_first_copy_of_a_hidden_block_stays_hidden(): void {
		$fork = Schema_Block::find_or_create_fork_for_game( 'thread-scope-secret', 'thread-fork-scope-a' );

		$this->assertSame( 1, (int) $fork->storyteller_only );
		$this->assertContains( 'thread-scope-secret', Schema_Block::storyteller_only_slugs( 'thread-fork-scope-a' ) );
	}

	/**
	 * An existing copy starts as not Storyteller-only; the upgrade carries the hidden flag onto it once.
	 */
	public function test_the_upgrade_keeps_an_old_copy_of_a_hidden_block_hidden_once(): void {
		global $wpdb;
		Schema_Block::find_or_create_fork_for_game( 'thread-scope-secret', 'thread-fork-scope-a' );
		$wpdb->update( $wpdb->prefix . 'be_schema_blocks', [ 'storyteller_only' => 0 ], [ 'slug' => 'thread-scope-secret', 'game_slug' => 'thread-fork-scope-a' ] );
		delete_option( 'be_fork_storyteller_only_carried' );

		\BeyondElysium\Database\Schema::carry_storyteller_only_to_forks();
		$this->assertArrayNotHasKey( 'thread-scope-secret', $this->players_sheet_in( 'thread-fork-scope-a' ) );

		// A chronicle that opens its copy afterwards keeps that choice through the next upgrade.
		$this->fork_with_flag( 'thread-scope-secret', 'thread-fork-scope-a', 0 );
		\BeyondElysium\Database\Schema::carry_storyteller_only_to_forks();
		$this->assertArrayHasKey( 'thread-scope-secret', $this->players_sheet_in( 'thread-fork-scope-a' ) );
	}

	public function test_the_sheet_layout_follows_the_same_chronicle_scope(): void {
		$this->fork_with_flag( 'thread-scope-open', 'thread-fork-scope-a', 1 );

		$layout = [ 'version' => 1, 'columns' => 3, 'sections' => [ [ 'block_slug' => 'thread-scope-open', 'column' => 1 ] ] ];
		$this->assertCount( 0, \BeyondElysium\Services\St_Visibility::filter_layout( $layout, false, 'thread-fork-scope-a' )['sections'] );
		$this->assertCount( 1, \BeyondElysium\Services\St_Visibility::filter_layout( $layout, false, 'thread-fork-scope-b' )['sections'] );
	}
}
