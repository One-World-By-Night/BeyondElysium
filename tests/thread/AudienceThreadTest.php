<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Character;
use BeyondElysium\Models\Connection;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\World_Object;
use BeyondElysium\Services\Audience;
use WP_UnitTestCase;

/**
 * `Services\Audience` is the one place a plot's or world object's "who can see this" is decided.
 */
class AudienceThreadTest extends WP_UnitTestCase {

	private string $game_slug = 'thread-audience';
	private int $game_id;

	public function setUp(): void {
		parent::setUp();

		$this->game_id = (int) Game::create( [ 'slug' => $this->game_slug, 'name' => 'Thread Audience' ] );
	}

	private function character( array $overrides = [] ): int {
		return (int) Character::create( array_merge( [
			'owner_slug' => $this->game_slug,
			'name'       => 'Fixture Character',
			'stack_slug' => 'vampire',
			'created_by' => 1,
		], $overrides ) );
	}

	public function test_everyone_is_visible_to_any_user_with_no_characters_at_all(): void {
		$plot   = (object) [ 'id' => 999, 'audience' => Audience::EVERYONE, 'audience_rules' => null ];
		$player = self::factory()->user->create( [ 'role' => 'subscriber' ] );

		$this->assertTrue( Audience::can_see( $plot, 'plot', $player, $this->game_slug, false ) );
	}

	public function test_storytellers_only_is_invisible_to_a_player_regardless_of_connections(): void {
		$character_id = $this->character();
		$plot_id      = 42;
		Connection::create( [
			'game_id'     => $this->game_id,
			'source_type' => 'plot',
			'source_id'   => $plot_id,
			'target_type' => 'character',
			'target_id'   => $character_id,
			'label'       => 'apr_actor',
			'created_by'  => 1,
		] );

		$plot   = (object) [ 'id' => $plot_id, 'audience' => Audience::STORYTELLERS, 'audience_rules' => null ];
		$player = self::factory()->user->create( [ 'role' => 'subscriber' ] );

		// The character is connected to the plot.
		$this->assertFalse( Audience::can_see( $plot, 'plot', $player, $this->game_slug, false ) );
	}

	public function test_a_manager_always_sees_everything_regardless_of_audience(): void {
		$plot    = (object) [ 'id' => 1, 'audience' => Audience::STORYTELLERS, 'audience_rules' => null ];
		$nobody  = self::factory()->user->create( [ 'role' => 'subscriber' ] );

		$this->assertTrue( Audience::can_see( $plot, 'plot', $nobody, $this->game_slug, true ) );
	}

	public function test_restricted_plot_is_visible_only_through_a_connected_character(): void {
		$owner        = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		$other_player = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		$character_id = $this->character( [ 'wp_user_id' => $owner ] );

		$plot_id = 7;
		Connection::create( [
			'game_id'     => $this->game_id,
			'source_type' => 'plot',
			'source_id'   => $plot_id,
			'target_type' => 'character',
			'target_id'   => $character_id,
			'label'       => 'apr_actor',
			'created_by'  => 1,
		] );

		$plot = (object) [ 'id' => $plot_id, 'audience' => Audience::RESTRICTED, 'audience_rules' => null ];

		$this->assertTrue( Audience::can_see( $plot, 'plot', $owner, $this->game_slug, false ) );
		$this->assertFalse( Audience::can_see( $plot, 'plot', $other_player, $this->game_slug, false ) );
	}

	public function test_restricted_world_object_is_visible_through_a_matching_rule(): void {
		$vampire_identity = $this->seed_vampire_identity_block();

		$tremere_player = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		$brujah_player  = self::factory()->user->create( [ 'role' => 'subscriber' ] );

		$this->character( [
			'wp_user_id' => $tremere_player,
			'sheet_data' => [ $vampire_identity => [ 'Clan' => 'Tremere' ] ],
		] );
		$this->character( [
			'wp_user_id' => $brujah_player,
			'sheet_data' => [ $vampire_identity => [ 'Clan' => 'Brujah' ] ],
		] );

		$object_id = World_Object::create( [
			'game_id'     => $this->game_id,
			'object_type' => 'location',
			'name'        => 'Tremere Chantry',
			'audience'    => Audience::RESTRICTED,
			'audience_rules' => [
				'logic'      => 'AND',
				'conditions' => [ [ 'field' => 'clan', 'operator' => 'equals', 'find' => 'Tremere' ] ],
			],
		] );
		$location = World_Object::find( (int) $object_id );

		$this->assertTrue( Audience::can_see( $location, 'location', $tremere_player, $this->game_slug, false ) );
		$this->assertFalse( Audience::can_see( $location, 'location', $brujah_player, $this->game_slug, false ) );
	}

	public function test_restricted_with_no_rules_and_no_connections_is_visible_to_nobody(): void {
		$player = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		$this->character( [ 'wp_user_id' => $player ] );

		$object = (object) [ 'id' => 12345, 'audience' => Audience::RESTRICTED, 'audience_rules' => null ];

		$this->assertFalse( Audience::can_see( $object, 'location', $player, $this->game_slug, false ) );
	}

	public function test_a_character_connected_to_an_item_sees_it_even_under_storytellers_only(): void {
		$owner        = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		$character_id = $this->character( [ 'wp_user_id' => $owner ] );

		$object_id = World_Object::create( [
			'game_id'     => $this->game_id,
			'object_type' => 'item',
			'name'        => 'Ashwood Stake',
			'audience'    => Audience::RESTRICTED,
		] );
		Connection::create( [
			'game_id'     => $this->game_id,
			'source_type' => 'character',
			'source_id'   => $character_id,
			'target_type' => 'world_object',
			'target_id'   => $object_id,
			'label'       => 'owns',
			'created_by'  => 1,
		] );

		$item = World_Object::find( (int) $object_id );
		$this->assertTrue( Audience::can_see( $item, 'item', $owner, $this->game_slug, false ) );
	}

	public function test_filter_narrows_a_list_the_same_way_can_see_would_per_row(): void {
		$player = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		$this->character( [ 'wp_user_id' => $player ] );

		$everyone     = (object) [ 'id' => 1, 'audience' => Audience::EVERYONE, 'audience_rules' => null ];
		$storytellers = (object) [ 'id' => 2, 'audience' => Audience::STORYTELLERS, 'audience_rules' => null ];

		$visible = Audience::filter( [ $everyone, $storytellers ], 'plot', $player, $this->game_slug, false );

		$this->assertCount( 1, $visible );
		$this->assertSame( 1, $visible[0]->id );
	}

	public function test_filter_returns_everything_unfiltered_for_a_manager(): void {
		$storytellers = (object) [ 'id' => 2, 'audience' => Audience::STORYTELLERS, 'audience_rules' => null ];
		$player       = self::factory()->user->create( [ 'role' => 'subscriber' ] );

		$visible = Audience::filter( [ $storytellers ], 'plot', $player, $this->game_slug, true );

		$this->assertCount( 1, $visible );
	}

	public function test_an_unrecognized_stored_value_fails_open_to_everyone(): void {
		$plot   = (object) [ 'id' => 1, 'audience' => 'some-future-value-this-build-does-not-know', 'audience_rules' => null ];
		$player = self::factory()->user->create( [ 'role' => 'subscriber' ] );

		$this->assertTrue( Audience::can_see( $plot, 'plot', $player, $this->game_slug, false ) );
	}

	public function test_visible_character_ids_combines_connections_and_rules_with_or(): void {
		$vampire_identity = $this->seed_vampire_identity_block();

		$tremere_id = $this->character( [ 'sheet_data' => [ $vampire_identity => [ 'Clan' => 'Tremere' ] ] ] );
		$marcus_id  = $this->character( [ 'name' => 'Marcus', 'sheet_data' => [ $vampire_identity => [ 'Clan' => 'Brujah' ] ] ] );

		$object_id = World_Object::create( [
			'game_id'        => $this->game_id,
			'object_type'    => 'location',
			'name'           => 'The Tremere Chantry, Plus Marcus',
			'audience'       => Audience::RESTRICTED,
			'audience_rules' => [
				'logic'      => 'AND',
				'conditions' => [ [ 'field' => 'clan', 'operator' => 'equals', 'find' => 'Tremere' ] ],
			],
		] );
		Connection::create( [
			'game_id'     => $this->game_id,
			'source_type' => 'character',
			'source_id'   => $marcus_id,
			'target_type' => 'world_object',
			'target_id'   => $object_id,
			'label'       => 'invited',
			'created_by'  => 1,
		] );

		$location = World_Object::find( (int) $object_id );
		$ids      = Audience::visible_character_ids( $location, 'location', $this->game_slug );

		$this->assertContains( $tremere_id, $ids, 'the rule match' );
		$this->assertContains( $marcus_id, $ids, 'the direct connection' );
	}

	/**
	 * Seeds a minimal vampire-identity schema block with a Clan field.
	 */
	private function seed_vampire_identity_block(): string {
		global $wpdb;
		$table = $wpdb->prefix . 'be_schema_blocks';
		$slug  = 'vampire-identity';

		if ( ! $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE slug = %s", $slug ) ) ) {
			$wpdb->insert( $table, [
				'slug'         => $slug,
				'name'         => 'Identity',
				'section_type' => 'identity_field',
				'definition'   => wp_json_encode( [ 'fields' => [ [ 'name' => 'Clan', 'field_type' => 'text' ] ] ] ),
				'is_system'    => 1,
				'created_by'   => 1,
				'created_at'   => current_time( 'mysql' ),
				'updated_at'   => current_time( 'mysql' ),
			] );
		}

		return $slug;
	}
}
